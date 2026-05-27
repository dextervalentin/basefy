<?php
/**
 * Auto-Delivery System
 *
 * Automatically delivers digital products to buyers when payment is confirmed.
 * Uses the product_stock_items table (per-item tracking) as the primary source,
 * with legacy auto_delivery_items JSON pool as fallback for backward compatibility.
 *
 * Called from escrowInitializeOrderItems() after payment confirmation.
 */
declare(strict_types=1);
require_once __DIR__ . '/stock_items.php';
require_once __DIR__ . '/notifications.php';

/**
 * Process auto-delivery for an order.
 * For each order_item whose product has auto_delivery_enabled=true,
 * consume one item from stock_items table (or legacy JSON pool as fallback).
 *
 * @param mixed $conn  Database connection (PgCompatConnection/mysqli)
 * @param int   $orderId  The order ID
 * @return int  Number of items auto-delivered
 */
function autoDeliveryProcessOrder($conn, int $orderId): int
{
    if ($orderId <= 0) return 0;

    // Ensure columns exist on products
    try { $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_enabled BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $e) {}
    try { $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_items TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_intro TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_conclusion TEXT DEFAULT NULL"); } catch (\Throwable $e) {}

    // Ensure variante_nome column on order_items
    try { $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS variante_nome VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}

    stockEnsureTables($conn);

    // Get order items with product auto-delivery info
    $st = $conn->prepare("
        SELECT oi.id AS item_id, oi.product_id, oi.delivery_content, oi.variante_nome,
               p.auto_delivery_enabled, p.auto_delivery_items, p.nome AS product_name,
               p.auto_delivery_intro, p.auto_delivery_conclusion, p.tipo
        FROM order_items oi
        INNER JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    if (!$st) return 0;

    $st->bind_param('i', $orderId);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if (empty($items)) return 0;

    $delivered = 0;

    foreach ($items as $item) {
        // Skip if already delivered
        if (!empty($item['delivery_content'])) continue;

        // Skip if auto-delivery not enabled for this product
        if (!stockBoolValue($item['auto_delivery_enabled'] ?? false)) continue;

        $productId = (int)($item['product_id'] ?? 0);
        $itemId = (int)$item['item_id'];
        $varianteNome = !empty($item['variante_nome']) ? (string)$item['variante_nome'] : null;
        $intro = trim((string)($item['auto_delivery_intro'] ?? ''));
        $conclusion = trim((string)($item['auto_delivery_conclusion'] ?? ''));

        $deliveryContent = null;

        // Produtos dinâmicos EXIGEM variante selecionada — nunca cair no pool legado
        // (pool legado não é escopado por variante e causaria entrega cruzada)
        $tipoProd = (string)($item['tipo'] ?? 'produto');
        if ($tipoProd === 'dinamico' && ($varianteNome === null || $varianteNome === '')) {
            error_log("[AutoDelivery] Order #{$orderId}, Item #{$itemId}: produto dinâmico sem variante — entrega bloqueada");
            continue;
        }

        // Try new stock_items table first
        $consumed = stockConsumeItem($conn, $productId, $varianteNome, $itemId);
        if ($consumed) {
            $rawContent = (string)$consumed['conteudo'];
            // Build formatted delivery message
            $parts = [];
            if ($intro !== '') $parts[] = $intro;
            $parts[] = $rawContent;
            if ($conclusion !== '') $parts[] = $conclusion;
            $deliveryContent = implode("\n\n", $parts);
        } elseif ($tipoProd === 'dinamico') {
            // Variante esgotada em produto dinâmico — NÃO cair no pool legado (não é variant-scoped)
            error_log("[AutoDelivery] Order #{$orderId}, Item #{$itemId}: variante \"{$varianteNome}\" esgotada para produto #{$productId}");
            continue;
        } else {
            // Fallback: legacy JSON pool (apenas para produtos simples sem variantes)
            $adItemsJson = (string)($item['auto_delivery_items'] ?? '');
            $adItems = $adItemsJson !== '' ? json_decode($adItemsJson, true) : [];
            if (is_array($adItems) && count($adItems) > 0) {
                $rawContent = array_shift($adItems);
                if (!empty($rawContent)) {
                    $parts = [];
                    if ($intro !== '') $parts[] = $intro;
                    $parts[] = $rawContent;
                    if ($conclusion !== '') $parts[] = $conclusion;
                    $deliveryContent = implode("\n\n", $parts);

                    // Update remaining JSON pool
                    $remainingJson = count($adItems) > 0 ? json_encode($adItems, JSON_UNESCAPED_UNICODE) : null;
                    $upProd = $conn->prepare("UPDATE products SET auto_delivery_items = ? WHERE id = ?");
                    if ($upProd) {
                        $upProd->bind_param('si', $remainingJson, $productId);
                        $upProd->execute();
                        $upProd->close();
                    }

                    // Disable auto-delivery if pool is exhausted AND stock table is also empty
                    if (count($adItems) === 0 && stockCountAvailable($conn, $productId, $varianteNome) <= 0) {
                        try {
                            $disableAd = $conn->prepare("UPDATE products SET auto_delivery_enabled = FALSE WHERE id = ?");
                            if ($disableAd) { $disableAd->bind_param('i', $productId); $disableAd->execute(); $disableAd->close(); }
                        } catch (\Throwable $e) {}
                    }
                }
            }
        }

        if ($deliveryContent === null) continue;

        // Update order_item with delivery content
        $up = $conn->prepare("UPDATE order_items SET delivery_content = ?, delivered_at = NOW() WHERE id = ?");
        if (!$up) continue;
        $up->bind_param('si', $deliveryContent, $itemId);
        $up->execute();
        $up->close();

        $delivered++;

        // Notify buyer about auto-delivery
        try {
            require_once __DIR__ . '/notifications.php';
            $stBuyerAd = $conn->prepare("SELECT user_id FROM orders WHERE id = ? LIMIT 1");
            if ($stBuyerAd) {
                $stBuyerAd->bind_param('i', $orderId);
                $stBuyerAd->execute();
                $buyerAdRow = $stBuyerAd->get_result()->fetch_assoc();
                $stBuyerAd->close();
                if ($buyerAdRow && (int)$buyerAdRow['user_id'] > 0) {
                    $pName = (string)($item['product_name'] ?? 'Produto');
                    notificationsCreate($conn, (int)$buyerAdRow['user_id'], 'venda', 'Entrega automática realizada!', 'O produto "' . $pName . '" foi entregue automaticamente. Acesse seu pedido #' . $orderId . ' para ver o conteúdo.', '/pedido_detalhes?id=' . $orderId);
                }
            }
        } catch (\Throwable $e) { error_log('[AutoDelivery] Notification error: ' . $e->getMessage()); }

        error_log("[AutoDelivery] Order #{$orderId}, Item #{$itemId}: Auto-delivered for product #{$productId} ({$item['product_name']}). Variant: " . ($varianteNome ?? 'none'));
    }

    return $delivered;
}
