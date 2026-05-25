<?php
declare(strict_types=1);

function identityDigits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function identityFormatCpf(string $value): string
{
    $digits = identityDigits($value);
    if (strlen($digits) !== 11) return $value;
    return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
}

function identityTableExists($conn, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    try {
        $rs = $conn->query("SHOW TABLES LIKE '" . $table . "'");
        return (bool)($rs && $rs->fetch_assoc());
    } catch (Throwable $e) {
        return false;
    }
}

function identityTableColumns($conn, string $table): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return [];
    $cols = [];
    try {
        $rs = $conn->query('SHOW COLUMNS FROM ' . $table);
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $field = strtolower((string)($row['Field'] ?? ''));
                if ($field !== '') $cols[] = $field;
            }
        }
    } catch (Throwable $e) {}
    return array_values(array_unique($cols));
}

function userCpfJaExiste($conn, string $cpfRaw, int $ignoreUserId = 0): bool
{
    $digits = identityDigits($cpfRaw);
    if (strlen($digits) !== 11) return false;

    $userCols = identityTableColumns($conn, 'users');
    foreach (['cpf', 'documento'] as $col) {
        if (!in_array($col, $userCols, true)) continue;
        try {
            $st = $conn->prepare("SELECT id FROM users WHERE regexp_replace(COALESCE({$col}, ''), '[^0-9]', '', 'g') = ? AND id <> ? LIMIT 1");
            $st->execute([$digits, $ignoreUserId]);
            if ($st->get_result()->fetch_assoc()) return true;
        } catch (Throwable $e) {}
    }

    if (identityTableExists($conn, 'user_verifications')) {
        $verCols = identityTableColumns($conn, 'user_verifications');
        if (in_array('dados', $verCols, true) && in_array('tipo', $verCols, true) && in_array('user_id', $verCols, true)) {
            try {
                $st = $conn->prepare("SELECT user_id FROM user_verifications WHERE tipo = 'dados' AND user_id <> ? AND regexp_replace(COALESCE(dados, ''), '[^0-9]', '', 'g') LIKE ? LIMIT 1");
                $st->execute([$ignoreUserId, '%' . $digits . '%']);
                if ($st->get_result()->fetch_assoc()) return true;
            } catch (Throwable $e) {}
        }
    }

    return false;
}