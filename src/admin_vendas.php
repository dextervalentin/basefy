<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\admin_vendas.php
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';

function _ensureEscrowColumns($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // order_items escrow columns
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS moderation_at TIMESTAMP DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS moderation_by BIGINT DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS delivered_by_buyer_at TIMESTAMP DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS auto_release_at TIMESTAMP DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS released_at TIMESTAMP DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS release_trigger VARCHAR(30) DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS escrow_fee_percent NUMERIC(5,2) DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS escrow_fee_amount NUMERIC(12,2) DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS escrow_net_amount NUMERIC(12,2) DEFAULT NULL");
        // orders wallet columns
        $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS gross_total NUMERIC(12,2) NOT NULL DEFAULT 0.00");
        $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS wallet_used NUMERIC(12,2) NOT NULL DEFAULT 0.00");
        // platform_settings table
        $conn->query("CREATE TABLE IF NOT EXISTS platform_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    } catch (\Throwable $e) {}
}

function listarVendas($conn, array $f = [], int $pagina = 1, int $pp = 20): array
{
    _ensureEscrowColumns($conn);
    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $pp;

    $q = '%' . trim((string)($f['q'] ?? '')) . '%';
    $statusPedido = (string)($f['status_pedido'] ?? '');
    $statusModeracao = (string)($f['status_moderacao'] ?? 'pendente');
    $de = (string)($f['de'] ?? '');
    $ate = (string)($f['ate'] ?? '');

    $where = ["(b.nome LIKE ? OR b.email LIKE ? OR s.nome LIKE ? OR s.email LIKE ? OR p.nome LIKE ?)"];
    $types = "sssss";
    $params = [$q, $q, $q, $q, $q];

    if ($statusPedido !== '') { $where[] = "o.status = ?"; $types .= "s"; $params[] = $statusPedido; }
    if ($statusModeracao !== '') { $where[] = "oi.moderation_status = ?"; $types .= "s"; $params[] = $statusModeracao; }
    if ($de !== '') { $where[] = "DATE(o.criado_em) >= ?"; $types .= "s"; $params[] = $de; }
    if ($ate !== '') { $where[] = "DATE(o.criado_em) <= ?"; $types .= "s"; $params[] = $ate; }

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT
              oi.id AS venda_id,
              o.id AS pedido_id,
              o.status AS pedido_status,
              o.criado_em,
              o.total AS total_pedido,
              o.gross_total,
              o.wallet_used,
              b.id AS comprador_id, b.nome AS comprador_nome, b.email AS comprador_email,
              s.id AS vendedor_id, s.nome AS vendedor_nome, s.email AS vendedor_email,
              p.nome AS produto_nome,
              oi.quantidade, oi.preco_unit, oi.subtotal,
              oi.moderation_status, oi.moderation_motivo
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN users b ON b.id = o.user_id
            INNER JOIN users s ON s.id = oi.vendedor_id
            INNER JOIN products p ON p.id = oi.product_id
            WHERE $whereSql
            ORDER BY oi.id DESC
            LIMIT ?, ?";

    $types2 = $types . "ii";
    $params2 = [...$params, $offset, $pp];

    $st = $conn->prepare($sql);
    $st->bind_param($types2, ...$params2);
    $st->execute();

    // count total
    $countSql = "SELECT COUNT(*) AS t FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 INNER JOIN users b ON b.id = o.user_id
                 INNER JOIN users s ON s.id = oi.vendedor_id
                 INNER JOIN products p ON p.id = oi.product_id
                 WHERE $whereSql";
    $stc = $conn->prepare($countSql);
    $stc->bind_param($types, ...$params);
    $stc->execute();
    $total = (int)($stc->get_result()->fetch_assoc()['t'] ?? 0);

    return [
        'itens' => $st->get_result()->fetch_all(MYSQLI_ASSOC),
        'pagina' => $pagina,
        'total_paginas' => max(1, (int)ceil($total / $pp)),
    ];
}

function decidirVenda($conn, int $vendaId, int $adminId, string $acao, string $motivo = ''): array
{
    require_once __DIR__ . '/wallet_escrow.php';
    require_once __DIR__ . '/seller_levels.php';

    if ($vendaId <= 0 || !in_array($acao, ['aprovar','recusar'], true)) return [false, 'Parâmetros inválidos.'];
    if ($acao === 'recusar' && trim($motivo) === '') return [false, 'Informe o motivo da recusa.'];

    _ensureEscrowColumns($conn);

    // Pre-fetch escrow rules and admin receiver BEFORE the transaction
    // to avoid UPSERTs inside the PG transaction (which taint it on failure)
    $preRules = escrowRules($conn);
    $preAdminReceiver = intval(escrowResolveAdminReceiver($conn));

    $conn->begin_transaction();
    try {
        $sql = "SELECT oi.id, oi.order_id, oi.product_id, oi.subtotal, oi.quantidade, oi.preco_unit, oi.moderation_status,
                       oi.vendedor_id, o.user_id AS comprador_id
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE oi.id = ?
                FOR UPDATE";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $vendaId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();

        if (!$row) { $conn->rollback(); return [false, 'Venda não encontrada.']; }
        if ($row['moderation_status'] !== 'pendente') { $conn->rollback(); return [false, 'Venda já processada.']; }

        $gross = (float)$row['subtotal'];
        if ($gross <= 0) $gross = (float)$row['quantidade'] * (float)$row['preco_unit'];

        if ($acao === 'aprovar') {
            $uid = (int)$row['vendedor_id'];
            $orderId = (int)$row['order_id'];

            // Use seller-level-based fee calculation
            $feeInfo    = sellerFeeCalc($conn, $uid, $gross, (int)$row['product_id']);
            $feePercent = $feeInfo['total_fee_percent'];
            $feeAmount  = $feeInfo['total_fee_amount'];
            $netAmount  = $feeInfo['net_amount'];
            $levelLabel = $feeInfo['label'];

            // Credit vendor with net amount (after fee)
            $u1 = $conn->prepare("UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?");
            $u1->bind_param('di', $netAmount, $uid);
            $u1->execute();

            $tx = $conn->prepare("INSERT INTO wallet_transactions
                (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao)
                VALUES (?, 'credito', 'venda_aprovada', 'order_item', ?, ?, ?)");
            $desc = "Crédito da venda #{$vendaId} (líquido após taxa {$feePercent}% — {$levelLabel})";
            $tx->bind_param('iids', $uid, $vendaId, $netAmount, $desc);
            $tx->execute();

            // Credit admin with platform fee
            if ($feeAmount > 0) {
                $adminReceiver = $preAdminReceiver;
                if ($adminReceiver > 0) {
                    $uAdmin = $conn->prepare("UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?");
                    $uAdmin->bind_param('di', $feeAmount, $adminReceiver);
                    $uAdmin->execute();

                    $txAdmin = $conn->prepare("INSERT INTO wallet_transactions
                        (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao)
                        VALUES (?, 'credito', 'platform_fee', 'order_item', ?, ?, ?)");
                    $descAdmin = "Taxa da plataforma ({$feePercent}%) da venda #{$vendaId}";
                    $txAdmin->bind_param('iids', $adminReceiver, $vendaId, $feeAmount, $descAdmin);
                    $txAdmin->execute();
                }
            }

            $up = $conn->prepare("UPDATE order_items
                                  SET moderation_status='aprovada', moderation_motivo=NULL, moderation_at=NOW(), moderation_by=?,
                                      released_at=NOW(), release_trigger='admin_approved',
                                      escrow_fee_percent=?, escrow_fee_amount=?, escrow_net_amount=?
                                  WHERE id=?");
            $up->bind_param('idddi', $adminId, $feePercent, $feeAmount, $netAmount, $vendaId);
            $up->execute();

            // Stock already decremented at payment time (escrowInitializeOrderItems),
            // so no duplicate decrement here.

            // Check if all items on this order are done
            $stPending = $conn->prepare("SELECT COUNT(*) AS c FROM order_items WHERE order_id = ? AND moderation_status = 'pendente'");
            $stPending->bind_param('i', $orderId);
            $stPending->execute();
            $pending = (int)($stPending->get_result()->fetch_assoc()['c'] ?? 0);
            if ($pending === 0) {
                $upOrder = $conn->prepare("UPDATE orders SET status='entregue' WHERE id = ?");
                $upOrder->bind_param('i', $orderId);
                $upOrder->execute();
            }

            $conn->commit();

            // Notify vendor + buyer about approval
            try {
                require_once __DIR__ . '/notifications.php';
                notificationsCreate($conn, $uid, 'venda', 'Venda aprovada!', 'Sua venda #' . $vendaId . ' foi aprovada. R$ ' . number_format($netAmount, 2, ',', '.') . ' creditado na sua carteira.', '/vendedor/saques');
                notificationsCreate($conn, (int)$row['comprador_id'], 'venda', 'Pedido aprovado', 'O item #' . $vendaId . ' do seu pedido foi aprovado e está sendo processado.', '/pedido_detalhes?id=' . $orderId);
            } catch (\Throwable $e) { error_log('[AdminVendas] Notification error (approve): ' . $e->getMessage()); }

            return [true, "Venda aprovada! Vendedor recebeu R$ " . number_format($netAmount, 2, ',', '.') . " (taxa {$feePercent}%: R$ " . number_format($feeAmount, 2, ',', '.') . ")."];
        }

        $uid = (int)$row['comprador_id'];

        $u1 = $conn->prepare("UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?");
        $u1->bind_param('di', $gross, $uid);
        $u1->execute();

        $tx = $conn->prepare("INSERT INTO wallet_transactions
            (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao)
            VALUES (?, 'credito', 'venda_recusada', 'order_item', ?, ?, ?)");
        $desc = "Devolução da venda #{$vendaId}";
        $tx->bind_param('iids', $uid, $vendaId, $gross, $desc);
        $tx->execute();

        $m = trim($motivo);
        $up = $conn->prepare("UPDATE order_items
                              SET moderation_status='recusada', moderation_motivo=?, moderation_at=NOW(), moderation_by=?
                              WHERE id=?");
        $up->bind_param('sii', $m, $adminId, $vendaId);
        $up->execute();

        $conn->commit();

        // Notify buyer about rejection + refund
        try {
            require_once __DIR__ . '/notifications.php';
            $oidReject = (int)$row['order_id'];
            notificationsCreate($conn, $uid, 'venda', 'Venda recusada — valor devolvido', 'A venda #' . $vendaId . ' foi recusada. R$ ' . number_format($gross, 2, ',', '.') . ' devolvido à sua carteira.', '/pedido_detalhes?id=' . $oidReject);
        } catch (\Throwable $e) { error_log('[AdminVendas] Notification error (reject): ' . $e->getMessage()); }

        return [true, 'Venda recusada e valor devolvido ao comprador.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Erro ao processar venda: ' . $e->getMessage()];
    }
}