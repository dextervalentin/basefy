<?php
declare(strict_types=1);
/**
 * Product Reports (Denúncias) — backend logic
 */

require_once __DIR__ . '/db.php';

function reportsEnsureTable($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS product_reports (
                id SERIAL PRIMARY KEY,
                product_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                motivo VARCHAR(255) NOT NULL,
                mensagem TEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pendente',
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (\Throwable $e) {}
}

/**
 * Submit a report
 */
function reportSubmit($conn, int $productId, int $userId, string $motivo, string $mensagem): array
{
    reportsEnsureTable($conn);
    $motivo   = trim($motivo);
    $mensagem = trim($mensagem);

    if ($motivo === '') return [false, 'Selecione um motivo para a denúncia.'];
    if ($productId < 1) return [false, 'Produto inválido.'];

    // Check duplicate (same user + product + 24h)
    $dupStmt = $conn->prepare(
        "SELECT COUNT(*) total FROM product_reports WHERE user_id = ? AND product_id = ? AND criado_em > NOW() - INTERVAL '24 hours'"
    );
    $dupStmt->bind_param('ii', $userId, $productId);
    $dupStmt->execute();
    $dup = (int)($dupStmt->get_result()->fetch_assoc()['total'] ?? 0);
    if ($dup > 0) return [false, 'Você já denunciou este anúncio recentemente. Aguarde 24h.'];

    $stmt = $conn->prepare(
        "INSERT INTO product_reports (product_id, user_id, motivo, mensagem) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('iiss', $productId, $userId, $motivo, $mensagem);
    $stmt->execute();

    return [true, 'Denúncia enviada com sucesso! Analisaremos o mais rápido possível.'];
}

/**
 * List reports (admin) with pagination
 */
function reportsList($conn, array $filters = [], int $page = 1, int $pp = 10): array
{
    reportsEnsureTable($conn);
    $where  = '1=1';
    $params = [];
    $types  = '';

    $status = (string)($filters['status'] ?? '');
    $q      = trim((string)($filters['q'] ?? ''));

    if (in_array($status, ['pendente', 'analisando', 'resolvido', 'rejeitado'], true)) {
        $where .= ' AND r.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    // Filter by reporting user
    $filterUserId = (int)($filters['user_id'] ?? 0);
    if ($filterUserId > 0) {
        $where .= ' AND r.user_id = ?';
        $types .= 'i';
        $params[] = $filterUserId;
    }

    // Filter by vendor (product owner)
    $filterVendorId = (int)($filters['vendedor_id'] ?? 0);
    if ($filterVendorId > 0) {
        $where .= ' AND p.vendedor_id = ?';
        $types .= 'i';
        $params[] = $filterVendorId;
    }

    if ($q !== '') {
        $where .= ' AND (p.nome ILIKE ? OR u.nome ILIKE ? OR r.motivo ILIKE ?)';
        $types .= 'sss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // Count
    $countSql = "SELECT COUNT(*) total FROM product_reports r LEFT JOIN products p ON p.id = r.product_id LEFT JOIN users u ON u.id = r.user_id WHERE $where";
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

        $sql = "SELECT r.*, p.nome AS produto_nome, p.imagem AS produto_imagem, u.nome AS user_nome, u.email AS user_email,
               COALESCE(NULLIF(u.telefone, ''), NULLIF(sp.telefone, '')) AS user_whatsapp
            FROM product_reports r
            LEFT JOIN products p ON p.id = r.product_id
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN seller_profiles sp ON sp.user_id = u.id
            WHERE $where
            ORDER BY r.criado_em DESC
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
 * Get single report by ID
 */
function reportsGetById($conn, int $id): ?array
{
    reportsEnsureTable($conn);
    $stmt = $conn->prepare(
        "SELECT r.*, p.nome AS produto_nome, p.imagem AS produto_imagem, u.nome AS user_nome, u.email AS user_email,
            COALESCE(NULLIF(u.telefone, ''), NULLIF(sp.telefone, '')) AS user_whatsapp
         FROM product_reports r
         LEFT JOIN products p ON p.id = r.product_id
         LEFT JOIN users u ON u.id = r.user_id
         LEFT JOIN seller_profiles sp ON sp.user_id = u.id
         WHERE r.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Update report status
 */
function reportsUpdateStatus($conn, int $id, string $newStatus): array
{
    $allowed = ['pendente', 'analisando', 'resolvido', 'rejeitado'];
    if (!in_array($newStatus, $allowed, true)) return [false, 'Status inválido.'];
    $stmt = $conn->prepare("UPDATE product_reports SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $newStatus, $id);
    $stmt->execute();
    if ($stmt->affected_rows < 1) return [false, 'Denúncia não encontrada.'];
    $stmt->close();
    return [true, 'Status atualizado.'];
}

/**
 * Count reports by status (for dashboard stats)
 */
function reportsCountByStatus($conn): array
{
    reportsEnsureTable($conn);
    $stmt = $conn->query("SELECT status, COUNT(*) qtd FROM product_reports GROUP BY status");
    $result = [];
    if ($stmt) {
        while ($row = $stmt->fetch_assoc()) {
            $result[(string)$row['status']] = (int)$row['qtd'];
        }
    }
    return $result;
}
