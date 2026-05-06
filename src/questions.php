<?php
declare(strict_types=1);
/**
 * Product Questions (Q&A) — backend logic
 */

require_once __DIR__ . '/db.php';

function questionsEnsureTable($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS product_questions (
                id SERIAL PRIMARY KEY,
                product_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                user_nome VARCHAR(191) NOT NULL DEFAULT '',
                pergunta TEXT NOT NULL,
                resposta TEXT DEFAULT NULL,
                respondido_por INTEGER DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ativo',
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                respondido_em TIMESTAMP DEFAULT NULL
            )
        ");
    } catch (\Throwable $e) {}
}

/**
 * List questions for a product with pagination
 */
function questionsListByProduct($conn, int $productId, int $limit = 5, int $offset = 0): array
{
    questionsEnsureTable($conn);
    $stmt = $conn->prepare(
        "SELECT q.*, u.nome AS user_nome, u.avatar AS user_avatar
         FROM product_questions q
         LEFT JOIN users u ON u.id = q.user_id
         WHERE q.product_id = ? AND q.status = 'ativo'
         ORDER BY q.criado_em DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('iii', $productId, $limit, $offset);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
}

/**
 * Count questions for a product
 */
function questionsCountByProduct($conn, int $productId): int
{
    questionsEnsureTable($conn);
    $stmt = $conn->prepare("SELECT COUNT(*) total FROM product_questions WHERE product_id = ? AND status = 'ativo'");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
}

/**
 * Submit a new question
 */
function questionsAsk($conn, int $productId, int $userId, string $pergunta): array
{
    questionsEnsureTable($conn);
    $pergunta = trim($pergunta);
    if ($pergunta === '') return [false, 'A pergunta não pode estar vazia.'];
    if (mb_strlen($pergunta) > 1000) return [false, 'Pergunta muito longa (máximo 1000 caracteres).'];

    // Mask contact info
    try {
        require_once __DIR__ . '/helpers.php';
        if (hasContactInfo($pergunta)) {
            $pergunta = '[ Pergunta filtrada pelo moderador ® ]';
        }
    } catch (\Throwable $e) {}

    // Get user name
    $uStmt = $conn->prepare("SELECT nome FROM users WHERE id = ? LIMIT 1");
    $uStmt->bind_param('i', $userId);
    $uStmt->execute();
    $uRow = $uStmt->get_result()->fetch_assoc();
    $nome = $uRow ? (string)$uRow['nome'] : 'Usuário';

    $stmt = $conn->prepare(
        "INSERT INTO product_questions (product_id, user_id, user_nome, pergunta) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('iiss', $productId, $userId, $nome, $pergunta);
    $stmt->execute();

    return [true, 'Pergunta enviada com sucesso!'];
}

/**
 * Answer a question (vendor or admin)
 */
function questionsAnswer($conn, int $questionId, int $respondidoPor, string $resposta): array
{
    $resposta = trim($resposta);
    if ($resposta === '') return [false, 'A resposta não pode estar vazia.'];

    // Mask contact info
    try {
        require_once __DIR__ . '/helpers.php';
        if (hasContactInfo($resposta)) {
            $resposta = '***';
        }
    } catch (\Throwable $e) {}

    $stmt = $conn->prepare(
        "UPDATE product_questions SET resposta = ?, respondido_por = ?, respondido_em = CURRENT_TIMESTAMP WHERE id = ?"
    );
    $stmt->bind_param('sii', $resposta, $respondidoPor, $questionId);
    $stmt->execute();

    if ($stmt->affected_rows < 1) return [false, 'Pergunta não encontrada.'];
    return [true, 'Resposta enviada.'];
}

/**
 * Get question by ID
 */
function questionsGetById($conn, int $id): ?array
{
    questionsEnsureTable($conn);
    $stmt = $conn->prepare("SELECT * FROM product_questions WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Format relative time in Portuguese
 */
function questionsTimeAgo(string $datetime): string
{
    try {
        if (trim($datetime) === '') return '';

        $tz = new \DateTimeZone('America/Sao_Paulo');
        $date = new \DateTime($datetime, $tz);
        $date->setTimezone($tz);
        $now = new \DateTime('now', $tz);
        $seconds = $now->getTimestamp() - $date->getTimestamp();

        if ($seconds <= 0) return 'agora';
        if ($seconds < 60) return 'agora';
        if ($seconds < 3600) {
            $minutes = (int)floor($seconds / 60);
            return 'há ' . $minutes . ($minutes > 1 ? ' minutos' : ' minuto');
        }
        if ($seconds < 86400) {
            $hours = (int)floor($seconds / 3600);
            return 'há ' . $hours . ($hours > 1 ? ' horas' : ' hora');
        }
        if ($seconds < 604800) {
            $days = (int)floor($seconds / 86400);
            return 'há ' . $days . ($days > 1 ? ' dias' : ' dia');
        }
        if ($seconds < 2592000) {
            $weeks = (int)floor($seconds / 604800);
            return 'há ' . $weeks . ($weeks > 1 ? ' semanas' : ' semana');
        }

        return $date->format('d/m/Y H:i');
    } catch (\Throwable $e) {
        return 'agora';
    }
}

/**
 * List questions for a vendor (all their products) with pagination
 */
function questionsListByVendor($conn, int $vendorId, array $filters = [], int $page = 1, int $pp = 10): array
{
    questionsEnsureTable($conn);
    $where  = 'p.vendedor_id = ?';
    $params = [$vendorId];
    $types  = 'i';

    $status = (string)($filters['status'] ?? '');
    $q      = trim((string)($filters['q'] ?? ''));
    $answered = (string)($filters['answered'] ?? '');

    if ($status === 'ativo' || $status === 'inativo') {
        $where .= ' AND q.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($q !== '') {
        $where .= ' AND (q.pergunta ILIKE ? OR q.user_nome ILIKE ? OR p.nome ILIKE ?)';
        $types .= 'sss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($answered === 'yes') {
        $where .= ' AND q.resposta IS NOT NULL';
    } elseif ($answered === 'no') {
        $where .= ' AND q.resposta IS NULL';
    }

    // Count
    $countSql = "SELECT COUNT(*) total FROM product_questions q LEFT JOIN products p ON p.id = q.product_id WHERE $where";
    $countStmt = $conn->prepare($countSql);
    if ($types && $params) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalPaginas = max(1, (int)ceil($total / $pp));
    $page = min($page, $totalPaginas);
    $offset = ($page - 1) * $pp;

    $sql = "SELECT q.*, p.nome AS produto_nome, p.imagem AS produto_imagem, u.nome AS user_nome_full, u.avatar AS user_avatar
            FROM product_questions q
            LEFT JOIN products p ON p.id = q.product_id
            LEFT JOIN users u ON u.id = q.user_id
            WHERE $where
            ORDER BY q.resposta IS NULL DESC, q.criado_em DESC
            LIMIT ? OFFSET ?";
    $types2 = $types . 'ii';
    $params2 = array_merge($params, [$pp, $offset]);

    $stmt = $conn->prepare($sql);
    if ($types2 && $params2) {
        $stmt->bind_param($types2, ...$params2);
    }
    $stmt->execute();
    $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    return [
        'itens' => $itens,
        'total' => $total,
        'pagina' => $page,
        'total_paginas' => $totalPaginas,
        'por_pagina' => $pp,
    ];
}

/**
 * Count unanswered questions for a vendor
 */
function questionsUnansweredCount($conn, int $vendorId): int
{
    questionsEnsureTable($conn);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM product_questions q LEFT JOIN products p ON p.id = q.product_id WHERE p.vendedor_id = ? AND q.resposta IS NULL AND q.status = 'ativo'"
    );
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $result = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $result;
}

/**
 * List questions ASKED BY a user (on any product) with pagination
 */
function questionsListByUser($conn, int $userId, array $filters = [], int $page = 1, int $pp = 10): array
{
    questionsEnsureTable($conn);
    $where  = 'q.user_id = ?';
    $params = [$userId];
    $types  = 'i';

    $q = trim((string)($filters['q'] ?? ''));
    $answered = (string)($filters['answered'] ?? '');

    if ($q !== '') {
        $where .= ' AND (q.pergunta LIKE ? OR p.nome LIKE ?)';
        $types .= 'ss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if ($answered === 'yes') {
        $where .= ' AND q.resposta IS NOT NULL';
    } elseif ($answered === 'no') {
        $where .= ' AND q.resposta IS NULL';
    }

    $where .= " AND q.status = 'ativo'";

    // Count
    $countSql = "SELECT COUNT(*) total FROM product_questions q LEFT JOIN products p ON p.id = q.product_id WHERE $where";
    $countStmt = $conn->prepare($countSql);
    if ($types && $params) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalPaginas = max(1, (int)ceil($total / $pp));
    $page = min($page, $totalPaginas);
    $offset = ($page - 1) * $pp;

    $sql = "SELECT q.*, p.nome AS produto_nome, p.imagem AS produto_imagem,
                   u_resp.nome AS respondido_por_nome
            FROM product_questions q
            LEFT JOIN products p ON p.id = q.product_id
            LEFT JOIN users u_resp ON u_resp.id = q.respondido_por
            WHERE $where
            ORDER BY q.criado_em DESC
            LIMIT ? OFFSET ?";
    $types2 = $types . 'ii';
    $params2 = array_merge($params, [$pp, $offset]);

    $stmt = $conn->prepare($sql);
    if ($types2 && $params2) {
        $stmt->bind_param($types2, ...$params2);
    }
    $stmt->execute();
    $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    return [
        'itens' => $itens,
        'total' => $total,
        'pagina' => $page,
        'total_paginas' => $totalPaginas,
        'por_pagina' => $pp,
    ];
}

/**
 * Count questions asked by a user that have been answered
 */
function questionsAnsweredCountByUser($conn, int $userId): int
{
    questionsEnsureTable($conn);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM product_questions WHERE user_id = ? AND resposta IS NOT NULL AND status = 'ativo'"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $result;
}

/**
 * Count total questions asked by a user
 */
function questionsTotalByUser($conn, int $userId): int
{
    questionsEnsureTable($conn);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM product_questions WHERE user_id = ? AND status = 'ativo'"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $result;
}
