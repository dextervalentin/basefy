<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\db.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}
if (!defined('MYSQLI_NUM')) {
    define('MYSQLI_NUM', 2);
}

final class PgCompatResult
{
    private array $rows;
    private int $cursor = 0;

    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }

    public function fetch_assoc(): ?array
    {
        if (!isset($this->rows[$this->cursor])) {
            return null;
        }
        return $this->rows[$this->cursor++];
    }

    public function fetch_row(): ?array
    {
        $assoc = $this->fetch_assoc();
        if ($assoc === null) {
            return null;
        }
        return array_values($assoc);
    }

    public function fetch_all(int $mode = MYSQLI_ASSOC): array
    {
        if ($mode === MYSQLI_NUM) {
            return array_map(static fn(array $row): array => array_values($row), $this->rows);
        }
        return $this->rows;
    }
}

final class PgCompatStatement
{
    private PgCompatConnection $conn;
    private PDOStatement $stmt;
    private array $boundValues = [];
    private ?PgCompatResult $result = null;
    private bool $isInsert;
    public int $affected_rows = 0;

    public function __construct(PgCompatConnection $conn, PDOStatement $stmt, bool $isInsert = false)
    {
        $this->conn = $conn;
        $this->stmt = $stmt;
        $this->isInsert = $isInsert;
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        // CRITICAL: copy values explicitly to break reference links.
        // Without this, PDO::execute converts values to strings through
        // the reference chain, corrupting the caller's original variables
        // (e.g. turning int $userId into string '2', which then fails
        // strict_types checks on subsequent function calls).
        $this->boundValues = [];
        foreach ($vars as $v) {
            $copy = $v;          // scalar copy — breaks the reference
            $this->boundValues[] = $copy;
        }
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $values = [];
        if (is_array($params)) {
            $values = array_values($params);
        } else {
            $values = $this->boundValues;
        }

        try {
            $ok = $this->stmt->execute($values);
            $this->affected_rows = $this->stmt->rowCount();

            // columnCount() > 0 means this is a SELECT-type query with a result set.
            // DML (INSERT/UPDATE/DELETE) returns columnCount() === 0 — never call
            // fetchAll() on DML because it corrupts the PostgreSQL PDO connection
            // state and breaks subsequent queries in the same request.
            if ($this->stmt->columnCount() > 0) {
                $rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
                $this->result = new PgCompatResult($rows ?: []);
            } else {
                $this->result = new PgCompatResult([]);
            }

            if ($this->isInsert) {
                $this->conn->refreshInsertId();
            }
            return $ok;
        } catch (\Throwable $e) {
            error_log('[PgCompatStatement] execute error: ' . $e->getMessage());
            $this->result = new PgCompatResult([]);
            throw $e;
        }
    }

    public function get_result(): PgCompatResult
    {
        return $this->result ?? new PgCompatResult([]);
    }

    public function close(): bool
    {
        try {
            $this->stmt->closeCursor();
        } catch (\Throwable) {
            // closeCursor may fail after DML on some PostgreSQL PDO versions
        }
        return true;
    }
}

final class PgCompatConnection
{
    private PDO $pdo;
    public int $insert_id = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Expose underlying PDO for raw operations (migrations, exec, etc.) */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function prepare(string $sql): PgCompatStatement|false
    {
        try {
            $prepared = $this->pdo->prepare($this->translateSql($sql, false));
            if (!$prepared) {
                return false;
            }
            $isInsert = (bool)preg_match('/^\s*INSERT\b/i', $sql);
            return new PgCompatStatement($this, $prepared, $isInsert);
        } catch (Throwable) {
            return false;
        }
    }

    public function query(string $sql): PgCompatResult|false
    {
        $translated = $this->translateSql($sql, true);

        $showColumnsMatch = [];
        if (preg_match('/^\s*SHOW\s+COLUMNS\s+FROM\s+`?([a-zA-Z0-9_]+)`?\s*$/i', $sql, $showColumnsMatch)) {
            try {
                $table = $showColumnsMatch[1];
                $stmt = $this->pdo->prepare('SELECT column_name AS "Field" FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position');
                $stmt->execute([$table]);
                return new PgCompatResult($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            } catch (\Throwable) {
                return false;
            }
        }

        $showTablesLikeMatch = [];
        if (preg_match('/^\s*SHOW\s+TABLES\s+LIKE\s+[\'\"]([^\'\"]+)[\'\"]\s*$/i', $sql, $showTablesLikeMatch)) {
            try {
                $table = $showTablesLikeMatch[1];
                $stmt = $this->pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1');
                $stmt->execute([$table]);
                return new PgCompatResult($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            } catch (\Throwable) {
                return false;
            }
        }

        try {
            $stmt = $this->pdo->query($translated);
            if ($stmt === false) {
                return false;
            }
            // columnCount() > 0 = SELECT with result set; 0 = DML/DDL
            if ($stmt->columnCount() > 0) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = [];
            }
            if (preg_match('/^\s*INSERT\b/i', $sql)) {
                $this->refreshInsertId();
            }
            return new PgCompatResult($rows ?: []);
        } catch (Throwable) {
            return false;
        }
    }

    public function begin_transaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    public function real_escape_string(string $value): string
    {
        $quoted = $this->pdo->quote($value);
        return substr($quoted, 1, -1);
    }

    public function set_charset(string $charset): bool
    {
        return true;
    }

    public function refreshInsertId(): void
    {
        try {
            $id = $this->pdo->lastInsertId();
            $this->insert_id = $id !== false ? (int)$id : 0;
        } catch (\Throwable) {
            $this->insert_id = 0;
        }
    }

    private function translateSql(string $sql, bool $replaceQuestionMarks = false): string
    {
        $translated = str_replace('`', '"', $sql);
        $translated = preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $translated) ?? $translated;
        $translated = preg_replace('/\bNOW\s*\(\s*\)/i', 'CURRENT_TIMESTAMP', $translated) ?? $translated;
        $translated = preg_replace('/TABLE_SCHEMA\s*=\s*DATABASE\(\)/i', 'table_schema = current_schema()', $translated) ?? $translated;

        $translated = preg_replace('/\bLIMIT\s*\?\s*,\s*\?/i', 'OFFSET ? LIMIT ?', $translated) ?? $translated;

        $translated = preg_replace('/DATE_ADD\s*\(\s*([a-zA-Z0-9_\.\"]+)\s*,\s*INTERVAL\s*\?\s*DAY\s*\)/i', "($1 + (? * INTERVAL '1 day'))", $translated) ?? $translated;

        $translated = str_replace("IF(status='PAID','PAID', ?)", "CASE WHEN status='PAID' THEN 'PAID' ELSE ? END", $translated);
        $translated = str_replace("IF(?='PAID', CURRENT_TIMESTAMP, paid_at)", "CASE WHEN ?='PAID' THEN CURRENT_TIMESTAMP ELSE paid_at END", $translated);
        $translated = str_replace("IF(?='buyer_confirmed', CURRENT_TIMESTAMP, delivered_by_buyer_at)", "CASE WHEN ?='buyer_confirmed' THEN CURRENT_TIMESTAMP ELSE delivered_by_buyer_at END", $translated);
        $translated = str_replace("IF(?='buyer_confirmed','Liberada por confirmação do comprador','Liberada automaticamente por prazo')", "CASE WHEN ?='buyer_confirmed' THEN 'Liberada por confirmação do comprador' ELSE 'Liberada automaticamente por prazo' END", $translated);
        $translated = str_replace("CONCAT(COALESCE(observacao,''), ' | recusado: saldo insuficiente na aprovação')", "COALESCE(observacao,'') || ' | recusado: saldo insuficiente na aprovação'", $translated);

        $translated = preg_replace('/\b(ativo|is_vendedor)\s*=\s*1\b/i', '$1 = TRUE', $translated) ?? $translated;
        $translated = preg_replace('/\b(ativo|is_vendedor)\s*=\s*0\b/i', '$1 = FALSE', $translated) ?? $translated;
        $translated = preg_replace('/\b(UPDATE|DELETE)\b([\s\S]*?)\bLIMIT\s+\d+\s*;?\s*$/i', '$1$2', $translated) ?? $translated;

        $translated = $this->translateOnDuplicateKey($translated);
        if ($replaceQuestionMarks) {
            $translated = $this->replaceQuestionMarksWithPgParams($translated);
        }
        return $translated;
    }

    private function translateOnDuplicateKey(string $sql): string
    {
        if (!str_contains(strtoupper($sql), 'ON DUPLICATE KEY UPDATE')) {
            return $sql;
        }

        $conflictTarget = null;
        if (preg_match('/INSERT\s+INTO\s+(?:[a-zA-Z0-9_]+\.)?"?platform_settings"?/i', $sql)) {
            $conflictTarget = '(setting_key)';
        } elseif (preg_match('/INSERT\s+INTO\s+(?:[a-zA-Z0-9_]+\.)?"?seller_profiles"?/i', $sql)) {
            $conflictTarget = '(user_id)';
        } elseif (preg_match('/INSERT\s+INTO\s+(?:[a-zA-Z0-9_]+\.)?"?payment_transactions"?/i', $sql)) {
            $conflictTarget = '(provider, external_ref)';
        }

        if ($conflictTarget === null) {
            return $sql;
        }

        $converted = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', 'ON CONFLICT ' . $conflictTarget . ' DO UPDATE SET', $sql, 1);
        if ($converted === null) {
            return $sql;
        }

        $converted = preg_replace('/VALUES\s*\(\s*([a-zA-Z0-9_\"]+)\s*\)/i', 'EXCLUDED.$1', $converted) ?? $converted;
        return $converted;
    }

    private function replaceQuestionMarksWithPgParams(string $sql): string
    {
        $len = strlen($sql);
        $index = 1;
        $out = '';
        $inSingle = false;
        $inDouble = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $out .= $ch;
                continue;
            }

            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $out .= $ch;
                continue;
            }

            if ($ch === '?' && !$inSingle && !$inDouble) {
                $out .= '$' . $index;
                $index++;
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }
}

final class Database
{
    private mixed $conn = null;
    private static ?PgCompatConnection $shared = null;

    public function connect(): mixed
    {
        // Return shared connection if already opened in this request
        if (self::$shared !== null) {
            $this->conn = self::$shared;
            return $this->conn;
        }
        if ($this->conn !== null) {
            return $this->conn;
        }

        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASS;
        $db = DB_NAME;
        $port = DB_PORT;

        if (DB_DRIVER !== 'pgsql') {
            throw new RuntimeException('Este projeto está configurado para PostgreSQL (DB_DRIVER=pgsql).');
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $db);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
        ]);
        $this->conn = new PgCompatConnection($pdo);
        self::$shared = $this->conn;
        $pdo->exec("SET timezone = 'America/Sao_Paulo'");

        return $this->conn;
    }
}