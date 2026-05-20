<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

function escrowSettingsDefaults(): array
{
    return [
        'wallet.auto_release_days' => '3',
        'wallet.confirmed_release_hours' => '24',
        'wallet.platform_fee_percent' => '5.00',
        'wallet.auto_release_enabled' => '1',
        'wallet.platform_admin_user_id' => '0',
        'wallet.withdraw_auto_enabled' => '0',
    ];
}

function escrowSettingGet($conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (string)($row['setting_value'] ?? $default);
}

function escrowSettingSet($conn, string $key, string $value): void
{
    $stmt = $conn->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
}

function escrowEnsureDefaults($conn): void
{
    foreach (escrowSettingsDefaults() as $key => $value) {
        escrowSettingSet($conn, $key, escrowSettingGet($conn, $key, $value));
    }

    $policyVersion = escrowSettingGet($conn, 'wallet.policy_defaults_version', '');
    if ($policyVersion !== '2026-05-dispute-rules') {
        if (escrowSettingGet($conn, 'wallet.auto_release_days', '7') === '7') {
            escrowSettingSet($conn, 'wallet.auto_release_days', '3');
        }
        escrowSettingSet($conn, 'wallet.confirmed_release_hours', escrowSettingGet($conn, 'wallet.confirmed_release_hours', '24'));
        escrowSettingSet($conn, 'wallet.policy_defaults_version', '2026-05-dispute-rules');
    }
}

function escrowRules($conn): array
{
    escrowEnsureDefaults($conn);

    return [
        'auto_release_days' => max(1, (int)escrowSettingGet($conn, 'wallet.auto_release_days', '3')),
        'confirmed_release_hours' => max(1, (int)escrowSettingGet($conn, 'wallet.confirmed_release_hours', '24')),
        'platform_fee_percent' => max(0.0, min(100.0, (float)escrowSettingGet($conn, 'wallet.platform_fee_percent', '5.00'))),
        'auto_release_enabled' => escrowSettingGet($conn, 'wallet.auto_release_enabled', '1') === '1',
        'platform_admin_user_id' => (int)escrowSettingGet($conn, 'wallet.platform_admin_user_id', '0'),
        'withdraw_auto_enabled' => escrowSettingGet($conn, 'wallet.withdraw_auto_enabled', '0') === '1',
    ];
}

function escrowResolveAdminReceiver($conn)
{
    $rules = escrowRules($conn);
    $configId = intval($rules['platform_admin_user_id'] ?? 0);
    if ($configId > 0) {
        $st = $conn->prepare("SELECT id FROM users WHERE id = ? AND role IN ('admin','administrador') LIMIT 1");
        $st->bind_param('i', $configId);
        $st->execute();
        if ($st->get_result()->fetch_assoc()) {
            return $configId;
        }
    }

    $q = $conn->query("SELECT id FROM users WHERE role IN ('admin','administrador') AND ativo = 1 ORDER BY id ASC LIMIT 1");
    $row = $q ? $q->fetch_assoc() : null;
    if ($row && isset($row['id'])) {
        return intval($row['id']);
    }
    // Nenhum admin disponivel — fee da plataforma nao sera creditada.
    error_log('[Escrow] WARN: nenhum admin ativo encontrado em escrowResolveAdminReceiver — platform fees ficarao sem destinatario');
    return 0;
}

function escrowInitializeOrderItems($conn, int|string $orderId): void
{
    $orderId = (int)$orderId;

    // ── 1. Set escrow auto-release dates (non-blocking) ──
    try {
        $stCols = $conn->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?');
        if ($stCols) {
            $table = 'order_items';
            $stCols->bind_param('s', $table);
            $stCols->execute();
            $colsRows = $stCols->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

            $cols = [];
            foreach ($colsRows as $row) {
                $name = strtolower((string)($row['column_name'] ?? $row['COLUMN_NAME'] ?? ''));
                if ($name !== '') $cols[$name] = true;
            }

            if (isset($cols['auto_release_at'])) {
                $baseDateColumn = null;
                foreach (['criado_em', 'created_at', 'data_criacao'] as $candidate) {
                    if (isset($cols[$candidate])) { $baseDateColumn = $candidate; break; }
                }
                if ($baseDateColumn !== null) {
                    $rules = escrowRules($conn);
                    $days = (int)$rules['auto_release_days'];
                    $sql = "UPDATE order_items SET auto_release_at = IFNULL(auto_release_at, DATE_ADD(" . $baseDateColumn . ", INTERVAL ? DAY)) WHERE order_id = ?";
                    $st = $conn->prepare($sql);
                    if ($st) {
                        $oid = (int)$orderId;
                        $st->bind_param('ii', $days, $oid);
                        $st->execute();
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[Escrow] Auto-release setup failed for order #' . $orderId . ': ' . $e->getMessage());
    }

    // ── 2. Generate delivery code ──
    escrowAssignDeliveryCode($conn, (int)$orderId);

    // ── Decrement product stock (quantidade) for each order item ──
    try {
        $stItems = $conn->prepare("SELECT oi.product_id, oi.quantidade, oi.variante_nome, p.tipo, p.variantes
                                   FROM order_items oi
                                   INNER JOIN products p ON p.id = oi.product_id
                                   WHERE oi.order_id = ?");
        if ($stItems) {
            $oidStock = (int)$orderId;
            $stItems->bind_param('i', $oidStock);
            $stItems->execute();
            $stockRows = $stItems->get_result()->fetch_all(MYSQLI_ASSOC);
            $stItems->close();

            error_log('[Escrow] Stock decrement: order #' . $orderId . ' has ' . count($stockRows) . ' item(s)');

            foreach ($stockRows as $sRow) {
                $prodId = (int)$sRow['product_id'];
                $qty    = (int)$sRow['quantidade'];
                $tipo   = (string)($sRow['tipo'] ?? 'produto');
                $varNome = !empty($sRow['variante_nome']) ? (string)$sRow['variante_nome'] : null;

                if ($tipo === 'dinamico' && $varNome !== null) {
                    // FOR UPDATE bloqueia a linha do produto até o commit da tx externa
                    // (webhook/api blackcat envolvem esta chamada num begin_transaction),
                    // garantindo serialização do read-modify-write sobre o JSON `variantes`.
                    $stCur = $conn->prepare("SELECT variantes FROM products WHERE id = ? LIMIT 1 FOR UPDATE");
                    $variantes = null;
                    if ($stCur) {
                        $stCur->bind_param('i', $prodId);
                        $stCur->execute();
                        $curRow = $stCur->get_result()->fetch_assoc();
                        $stCur->close();
                        $variantes = json_decode((string)($curRow['variantes'] ?? ''), true);
                    }
                    if (is_array($variantes)) {
                        $updated = false;
                        foreach ($variantes as &$v) {
                            if (($v['nome'] ?? '') === $varNome) {
                                $v['quantidade'] = max(0, (int)($v['quantidade'] ?? 0) - $qty);
                                $updated = true;
                                break;
                            }
                        }
                        unset($v);
                        if ($updated) {
                            $newJson = json_encode($variantes, JSON_UNESCAPED_UNICODE);
                            $totalQtd = array_sum(array_column($variantes, 'quantidade'));
                            $stUpVar = $conn->prepare("UPDATE products SET variantes = ?, quantidade = ? WHERE id = ?");
                            if ($stUpVar) {
                                $stUpVar->bind_param('sii', $newJson, $totalQtd, $prodId);
                                $stUpVar->execute();
                                error_log('[Escrow] Stock decremented (variant): product #' . $prodId . ' variant "' . $varNome . '" -' . $qty . ', new total=' . $totalQtd);
                                $stUpVar->close();
                            }
                        }
                    }
                } else {
                    // Standard product: decrement quantidade directly
                    $stDec = $conn->prepare("UPDATE products SET quantidade = GREATEST(0, quantidade - ?) WHERE id = ?");
                    if ($stDec) {
                        $stDec->bind_param('ii', $qty, $prodId);
                        $stDec->execute();
                        error_log('[Escrow] Stock decremented: product #' . $prodId . ' -' . $qty . ' (affected=' . $stDec->affected_rows . ')');
                        $stDec->close();
                    } else {
                        error_log('[Escrow] Stock decrement FAILED prepare for product #' . $prodId);
                    }
                }
            }
        } else {
            error_log('[Escrow] Stock decrement: failed to prepare item query for order #' . $orderId);
        }
    } catch (\Throwable $e) {
        error_log('[Escrow] Stock decrement failed for order #' . $orderId . ': ' . $e->getMessage());
    }

    // Auto-deliver products with auto_delivery_enabled
    try {
        require_once __DIR__ . '/auto_delivery.php';
        autoDeliveryProcessOrder($conn, (int)$orderId);
    } catch (\Throwable $e) {
        error_log('[Escrow] Auto-delivery failed for order #' . $orderId . ': ' . $e->getMessage());
    }

    // Notify each vendor that they have a new sale
    try {
        require_once __DIR__ . '/notifications.php';
        $stVendors = $conn->prepare("SELECT DISTINCT vendedor_id FROM order_items WHERE order_id = ? AND vendedor_id IS NOT NULL AND vendedor_id > 0");
        if ($stVendors) {
            $oidNotif = (int)$orderId;
            $stVendors->bind_param('i', $oidNotif);
            $stVendors->execute();
            $vendors = $stVendors->get_result()->fetch_all(MYSQLI_ASSOC);
            $stVendors->close();
            foreach ($vendors as $vRow) {
                notificationsCreate($conn, (int)$vRow['vendedor_id'], 'venda', 'Nova venda recebida!', 'Você recebeu um novo pedido #' . $oidNotif . '. Acesse suas vendas para detalhes.', '/vendedor/vendas_analise');
            }
        }
    } catch (\Throwable $e) { error_log('[Escrow] Notification error (new sale): ' . $e->getMessage()); }

    // Auto-open chat with vendor: send instructions, delivery content, and delivery code
    try {
        require_once __DIR__ . '/chat.php';
        chatAutoOpenAfterPurchase($conn, (int)$orderId);
    } catch (\Throwable $e) {
        error_log('[Escrow] Chat auto-open failed for order #' . $orderId . ': ' . $e->getMessage());
    }
}

function escrowReleaseOrderItem($conn, int $orderItemId, string $trigger, int $actorUserId = 0): array
{
    $allowedTriggers = ['buyer_confirmed', 'auto_timeout'];
    if (!in_array($trigger, $allowedTriggers, true)) {
        return [false, 'Trigger inválido.'];
    }

    require_once __DIR__ . '/seller_levels.php';

    $rules = escrowRules($conn);
    $adminReceiver = intval(escrowResolveAdminReceiver($conn));

    $conn->begin_transaction();
    try {
        $sql = "SELECT oi.id, oi.order_id, oi.vendedor_id, oi.subtotal, oi.quantidade, oi.preco_unit,
                       oi.moderation_status, oi.released_at, o.user_id AS comprador_id
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE oi.id = ?
                FOR UPDATE";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $orderItemId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();

        if (!$row) {
            $conn->rollback();
            return [false, 'Item de pedido não encontrado.'];
        }

        if ($row['released_at'] !== null || (string)$row['moderation_status'] === 'aprovada') {
            $conn->rollback();
            return [false, 'Item já liberado.'];
        }

        if ((string)$row['moderation_status'] === 'recusada') {
            $conn->rollback();
            return [false, 'Item recusado, não pode liberar.'];
        }

        $gross = (float)$row['subtotal'];
        if ($gross <= 0) {
            $gross = (float)$row['quantidade'] * (float)$row['preco_unit'];
        }

        $sellerId = (int)$row['vendedor_id'];
        $orderId  = (int)$row['order_id'];
        $buyerId  = (int)$row['comprador_id'];

        // Use seller-level-based fee calculation
        $feeInfo   = sellerFeeCalc($conn, $sellerId, $gross);
        $feePercent = $feeInfo['total_fee_percent'];
        $feeAmount  = $feeInfo['total_fee_amount'];
        $netAmount  = $feeInfo['net_amount'];

        $upSeller = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?');
        $upSeller->bind_param('di', $netAmount, $sellerId);
        $upSeller->execute();

        $txSeller = $conn->prepare("INSERT INTO wallet_transactions
            (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao)
            VALUES (?, 'credito', 'escrow_release', 'order_item', ?, ?, ?)");
        $descSeller = 'Liberação de escrow do item #' . $orderItemId;
        $txSeller->bind_param('iids', $sellerId, $orderItemId, $netAmount, $descSeller);
        $txSeller->execute();

        if ($feeAmount > 0 && $adminReceiver > 0) {
            $upAdmin = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?');
            $upAdmin->bind_param('di', $feeAmount, $adminReceiver);
            $upAdmin->execute();

            $txAdmin = $conn->prepare("INSERT INTO wallet_transactions
                (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao)
                VALUES (?, 'credito', 'platform_fee', 'order_item', ?, ?, ?)");
            $descAdmin = 'Taxa da plataforma do item #' . $orderItemId;
            $txAdmin->bind_param('iids', $adminReceiver, $orderItemId, $feeAmount, $descAdmin);
            $txAdmin->execute();
        }

        $moderationBy = $actorUserId > 0 ? $actorUserId : null;
        $upItem = $conn->prepare("UPDATE order_items
            SET moderation_status='aprovada',
                moderation_motivo = IF(?='buyer_confirmed','Liberada por confirmação do comprador','Liberada automaticamente por prazo'),
                moderation_at = NOW(),
                moderation_by = ?,
                released_at = NOW(),
                release_trigger = ?,
                escrow_fee_percent = ?,
                escrow_fee_amount = ?,
                escrow_net_amount = ?,
                delivered_by_buyer_at = IF(?='buyer_confirmed', NOW(), delivered_by_buyer_at)
            WHERE id = ?");
        $upItem->bind_param('sissddsi', $trigger, $moderationBy, $trigger, $feePercent, $feeAmount, $netAmount, $trigger, $orderItemId);
        $upItem->execute();

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

        // Notify vendor: payment released
        try {
            require_once __DIR__ . '/notifications.php';
            $sellerId = (int)$row['vendedor_id'];
            $buyerId = (int)$row['comprador_id'];
            $oidN = (int)$row['order_id'];
            notificationsCreate($conn, $sellerId, 'venda', 'Pagamento liberado!', 'R$ ' . number_format($netAmount, 2, ',', '.') . ' do pedido #' . $oidN . ' foi liberado para sua carteira.', '/vendedor/saques');
            notificationsCreate($conn, $buyerId, 'venda', 'Pedido entregue', 'O item do seu pedido #' . $oidN . ' foi marcado como entregue.', '/pedido_detalhes?id=' . $oidN);
        } catch (\Throwable $e) { error_log('[Escrow] Notification error (release): ' . $e->getMessage()); }

        return [true, 'Valor liberado com sucesso.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Falha ao liberar escrow: ' . $e->getMessage()];
    }
}

function escrowConfirmDeliveryByBuyer($conn, int $orderId, int $buyerId): array
{
    if ($orderId <= 0 || $buyerId <= 0) {
        return [false, 'Parâmetros inválidos.'];
    }

    $st = $conn->prepare('SELECT id FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
    $st->bind_param('ii', $orderId, $buyerId);
    $st->execute();
    if (!$st->get_result()->fetch_assoc()) {
        return [false, 'Pedido não encontrado para este usuário.'];
    }

    $stItems = $conn->prepare("SELECT id FROM order_items WHERE order_id = ? AND moderation_status = 'pendente' ORDER BY id ASC");
    $stItems->bind_param('i', $orderId);
    $stItems->execute();
    $items = $stItems->get_result()->fetch_all(MYSQLI_ASSOC);

    if (!$items) {
        return [false, 'Não há itens pendentes para confirmar.'];
    }

    $okCount = 0;
    foreach ($items as $item) {
        [$ok, ] = escrowReleaseOrderItem($conn, (int)$item['id'], 'buyer_confirmed', $buyerId);
        if ($ok) {
            $okCount++;
        }
    }

    if ($okCount === 0) {
        return [false, 'Nenhum item foi liberado.'];
    }

    return [true, 'Entrega confirmada. ' . $okCount . ' item(ns) liberado(s).'];
}

function escrowProcessAutoReleases($conn, int $limit = 100): array
{
    $rules = escrowRules($conn);
    if (!$rules['auto_release_enabled']) {
        return [true, 'Auto-liberação desativada.', 0];
    }

    $limit = max(1, min(500, $limit));

    $sql = "SELECT oi.id
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.moderation_status = 'pendente'
              AND oi.released_at IS NULL
              AND oi.auto_release_at IS NOT NULL
              AND oi.auto_release_at <= NOW()
              AND o.status IN ('pago', 'enviado', 'entregue')
            ORDER BY oi.auto_release_at ASC
            LIMIT $limit";
    $q = $conn->query($sql);
    $rows = $q ? $q->fetch_all(MYSQLI_ASSOC) : [];

    $released = 0;
    foreach ($rows as $row) {
        [$ok, ] = escrowReleaseOrderItem($conn, (int)$row['id'], 'auto_timeout', 0);
        if ($ok) {
            $released++;
        }
    }

    return [true, 'Auto-liberação executada.', $released];
}
/* ========================================================================
 *  DELIVERY CODE SYSTEM  (iFood-style verification)
 * ======================================================================== */

/**
 * Ensure the delivery_code column exists on orders table.
 */
function escrowEnsureDeliveryCodeColumn($conn): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $st = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'orders' AND column_name = 'delivery_code' LIMIT 1");
    if (!$st) return;
    $st->execute();
    $exists = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$exists) {
        $conn->query("ALTER TABLE orders ADD COLUMN delivery_code VARCHAR(6) DEFAULT NULL");
        $conn->query("CREATE INDEX IF NOT EXISTS idx_orders_delivery_code ON orders(delivery_code)");
    }
}

/**
 * Generate a 6-character alphanumeric delivery code.
 */
function escrowGenerateDeliveryCode(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // avoid confusing chars: 0/O, 1/I
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

/**
 * Assign a delivery code to an order (called when order is paid).
 */
function escrowAssignDeliveryCode($conn, int|string $orderId): string
{
    $orderId = (int)$orderId;
    escrowEnsureDeliveryCodeColumn($conn);

    // Check if already has a code
    $st = $conn->prepare("SELECT delivery_code FROM orders WHERE id = ? LIMIT 1");
    $st->bind_param('i', $orderId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row && !empty($row['delivery_code'])) {
        return (string)$row['delivery_code'];
    }

    // Generate unique code
    $code = escrowGenerateDeliveryCode();
    $up = $conn->prepare("UPDATE orders SET delivery_code = ? WHERE id = ?");
    $up->bind_param('si', $code, $orderId);
    $up->execute();
    $up->close();

    return $code;
}

/**
 * Get the delivery code for an order.
 */
function escrowGetDeliveryCode($conn, int|string $orderId): ?string
{
    $orderId = (int)$orderId;
    escrowEnsureDeliveryCodeColumn($conn);

    $st = $conn->prepare("SELECT delivery_code FROM orders WHERE id = ? LIMIT 1");
    $st->bind_param('i', $orderId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return ($row && !empty($row['delivery_code'])) ? (string)$row['delivery_code'] : null;
}

/**
 * Vendor confirms delivery by entering the buyer's code.
 * Releases escrow for all pending items if code matches.
 */
function escrowConfirmDeliveryByCode($conn, int|string $orderId, int|string $vendorId, string $inputCode): array
{
    $orderId = (int)$orderId;
    $vendorId = (int)$vendorId;
    escrowEnsureDeliveryCodeColumn($conn);

    if ($orderId <= 0 || $vendorId <= 0) {
        return [false, 'Parâmetros inválidos.'];
    }

    $inputCode = strtoupper(trim($inputCode));
    if (strlen($inputCode) !== 6) {
        return [false, 'O código deve ter 6 caracteres.'];
    }

    // Get the order and verify code
    $st = $conn->prepare("SELECT id, delivery_code, user_id, status FROM orders WHERE id = ? LIMIT 1");
    $st->bind_param('i', $orderId);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$order) {
        return [false, 'Pedido não encontrado.'];
    }

    if (empty($order['delivery_code'])) {
        return [false, 'Este pedido não possui código de entrega.'];
    }

    if (strtoupper(trim((string)$order['delivery_code'])) !== $inputCode) {
        return [false, 'Código de entrega incorreto.'];
    }

    // Check vendor has items in this order
    $stItems = $conn->prepare("SELECT id FROM order_items WHERE order_id = ? AND vendedor_id = ? AND moderation_status = 'pendente' ORDER BY id ASC");
    $stItems->bind_param('ii', $orderId, $vendorId);
    $stItems->execute();
    $items = $stItems->get_result()->fetch_all(MYSQLI_ASSOC);
    $stItems->close();

    if (!$items) {
        return [false, 'Não há itens pendentes seus neste pedido.'];
    }

    $okCount = 0;
    foreach ($items as $item) {
        [$ok, ] = escrowReleaseOrderItem($conn, (int)$item['id'], 'buyer_confirmed', (int)$vendorId);
        if ($ok) {
            $okCount++;
        }
    }

    if ($okCount === 0) {
        return [false, 'Nenhum item foi liberado.'];
    }

    return [true, 'Código verificado! Entrega confirmada. ' . $okCount . ' item(ns) liberado(s).'];
}