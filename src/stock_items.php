<?php
/**
 * Stock Items Management
 *
 * Manages individual stock items for automatic delivery.
 * Each item is a row in product_stock_items, tied to a product
 * and optionally to a variant (for dynamic products).
 *
 * Similar to GGMAX's "Estoque Automático" system.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function stockBoolValue(mixed $value): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return ((int)$value) === 1;
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on'], true);
}

function stockEnsureTables(object $conn): void
{
    static $done = false;
    if ($done) return;

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS product_stock_items (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            product_id BIGINT NOT NULL,
            variante_nome VARCHAR(100) DEFAULT NULL,
            conteudo TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'disponivel',
            order_item_id BIGINT DEFAULT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            entregue_em TIMESTAMP DEFAULT NULL
        )");
    } catch (\Throwable $e) {}

    try {
        $conn->query("CREATE INDEX IF NOT EXISTS idx_psi_product ON product_stock_items(product_id)");
    } catch (\Throwable $e) {}
    try {
        $conn->query("CREATE INDEX IF NOT EXISTS idx_psi_status ON product_stock_items(status)");
    } catch (\Throwable $e) {}

    // Auto-delivery config columns on products
    try { $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_intro TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_conclusion TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_enabled BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $e) {}

    $done = true;
}

/**
 * Count available stock items for a product, optionally filtered by variant.
 */
function stockCountAvailable(object $conn, mixed $productId, ?string $variante = null): int
{
    $productId = (int)$productId;
    stockEnsureTables($conn);

    if ($variante !== null && $variante !== '') {
        $st = $conn->prepare("SELECT COUNT(*) AS c FROM product_stock_items WHERE product_id = ? AND variante_nome = ? AND status = 'disponivel'");
        $st->bind_param('is', $productId, $variante);
    } else {
        $st = $conn->prepare("SELECT COUNT(*) AS c FROM product_stock_items WHERE product_id = ? AND (variante_nome IS NULL OR variante_nome = '') AND status = 'disponivel'");
        $st->bind_param('i', $productId);
    }
    $st->execute();
    $count = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    $st->close();
    return $count;
}

/**
 * Count all stock items (any status) for a product.
 */
function stockCountAll(object $conn, mixed $productId, ?string $variante = null, string $statusFilter = ''): int
{
    $productId = (int)$productId;
    stockEnsureTables($conn);

    $where = "product_id = ?";
    $types = 'i';
    $params = [$productId];

    if ($variante !== null && $variante !== '') {
        $where .= " AND variante_nome = ?";
        $types .= 's';
        $params[] = $variante;
    }

    if ($statusFilter !== '') {
        $where .= " AND status = ?";
        $types .= 's';
        $params[] = $statusFilter;
    }

    $st = $conn->prepare("SELECT COUNT(*) AS c FROM product_stock_items WHERE {$where}");
    $st->bind_param($types, ...$params);
    $st->execute();
    $count = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    $st->close();
    return $count;
}

/**
 * List stock items for a product with pagination.
 */
function stockListItems(object $conn, mixed $productId, ?string $variante = null, string $statusFilter = '', int $page = 1, int $pp = 20): array
{
    $productId = (int)$productId;
    stockEnsureTables($conn);

    $where = "product_id = ?";
    $types = 'i';
    $params = [$productId];

    if ($variante !== null && $variante !== '') {
        $where .= " AND variante_nome = ?";
        $types .= 's';
        $params[] = $variante;
    }

    if ($statusFilter !== '') {
        $where .= " AND status = ?";
        $types .= 's';
        $params[] = $statusFilter;
    }

    // Count
    $countSql = "SELECT COUNT(*) AS c FROM product_stock_items WHERE {$where}";
    $stc = $conn->prepare($countSql);
    $stc->bind_param($types, ...$params);
    $stc->execute();
    $total = (int)($stc->get_result()->fetch_assoc()['c'] ?? 0);
    $stc->close();

    // List
    $offset = ($page - 1) * $pp;
    $sql = "SELECT * FROM product_stock_items WHERE {$where} ORDER BY id DESC LIMIT ? OFFSET ?";
    $types2 = $types . 'ii';
    $params2 = [...$params, $pp, $offset];

    $st = $conn->prepare($sql);
    $st->bind_param($types2, ...$params2);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => max(1, (int)ceil($total / $pp)),
    ];
}

/**
 * Add a single stock item.
 */
function stockAddItem(object $conn, mixed $productId, string $conteudo, ?string $variante = null): bool
{
    $productId = (int)$productId;
    stockEnsureTables($conn);

    $conteudo = trim($conteudo);
    if ($conteudo === '') return false;

    $st = $conn->prepare("INSERT INTO product_stock_items (product_id, variante_nome, conteudo, status) VALUES (?, ?, ?, 'disponivel')");
    $st->bind_param('iss', $productId, $variante, $conteudo);
    $ok = $st->execute();
    $st->close();
    return $ok;
}

/**
 * Add multiple stock items at once (bulk).
 * $lines is an array of content strings.
 * Returns number of items added.
 */
function stockAddBulk(object $conn, mixed $productId, array $lines, ?string $variante = null): int
{
    $productId = (int)$productId;
    stockEnsureTables($conn);
    $added = 0;

    $st = $conn->prepare("INSERT INTO product_stock_items (product_id, variante_nome, conteudo, status) VALUES (?, ?, ?, 'disponivel')");

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $st->bind_param('iss', $productId, $variante, $line);
        if ($st->execute()) $added++;
    }
    $st->close();
    return $added;
}

/**
 * Delete a stock item (only if available — not yet delivered).
 */
function stockDeleteItem(object $conn, mixed $itemId, mixed $productId): bool
{
    $itemId = (int)$itemId;
    $productId = (int)$productId;
    $st = $conn->prepare("DELETE FROM product_stock_items WHERE id = ? AND product_id = ? AND status = 'disponivel'");
    $st->bind_param('ii', $itemId, $productId);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();
    return $ok;
}

/**
 * Edit a stock item's content (only if available).
 */
function stockEditItem(object $conn, mixed $itemId, mixed $productId, string $newContent): bool
{
    $itemId = (int)$itemId;
    $productId = (int)$productId;
    $newContent = trim($newContent);
    if ($newContent === '') return false;

    $st = $conn->prepare("UPDATE product_stock_items SET conteudo = ? WHERE id = ? AND product_id = ? AND status = 'disponivel'");
    $st->bind_param('sii', $newContent, $itemId, $productId);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();
    return $ok;
}

/**
 * Consume one stock item for auto-delivery.
 * Returns the item content, or null if no items available.
 *
 * Atômico: usa UPDATE ... WHERE id = (SELECT ... FOR UPDATE SKIP LOCKED) RETURNING
 * para evitar race condition (dois pedidos pegando o mesmo item).
 */
function stockConsumeItem(object $conn, mixed $productId, ?string $variante, mixed $orderItemId): ?array
{
    $productId = (int)$productId;
    $orderItemId = (int)$orderItemId;
    stockEnsureTables($conn);

    // Single atomic statement: lock the oldest available row (skip rows locked by other tx),
    // mark sold, and return content. Concurrent workers get disjoint rows.
    if ($variante !== null && $variante !== '') {
        $sql = "UPDATE product_stock_items
                SET status = 'vendido', order_item_id = ?, entregue_em = CURRENT_TIMESTAMP
                WHERE id = (
                    SELECT id FROM product_stock_items
                    WHERE product_id = ? AND variante_nome = ? AND status = 'disponivel'
                    ORDER BY id ASC LIMIT 1
                    FOR UPDATE SKIP LOCKED
                )
                RETURNING id, conteudo";
        $st = $conn->prepare($sql);
        if (!$st) return null;
        $st->bind_param('iis', $orderItemId, $productId, $variante);
    } else {
        $sql = "UPDATE product_stock_items
                SET status = 'vendido', order_item_id = ?, entregue_em = CURRENT_TIMESTAMP
                WHERE id = (
                    SELECT id FROM product_stock_items
                    WHERE product_id = ? AND (variante_nome IS NULL OR variante_nome = '') AND status = 'disponivel'
                    ORDER BY id ASC LIMIT 1
                    FOR UPDATE SKIP LOCKED
                )
                RETURNING id, conteudo";
        $st = $conn->prepare($sql);
        if (!$st) return null;
        $st->bind_param('ii', $orderItemId, $productId);
    }

    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) return null;
    return ['id' => (int)$row['id'], 'conteudo' => (string)$row['conteudo']];
}

/**
 * Get auto-delivery config for a product.
 */
function stockGetDeliveryConfig(object $conn, mixed $productId): array
{
    $productId = (int)$productId;
    stockEnsureTables($conn);

    $st = $conn->prepare("SELECT auto_delivery_enabled, auto_delivery_intro, auto_delivery_conclusion FROM products WHERE id = ? LIMIT 1");
    $st->bind_param('i', $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return [
        'enabled' => stockBoolValue($row['auto_delivery_enabled'] ?? false),
        'intro' => (string)($row['auto_delivery_intro'] ?? ''),
        'conclusion' => (string)($row['auto_delivery_conclusion'] ?? ''),
    ];
}

/**
 * Save auto-delivery config for a product.
 */
function stockSaveDeliveryConfig(object $conn, mixed $productId, bool $enabled, string $intro, string $conclusion): bool
{
    $productId = (int)$productId;
    stockEnsureTables($conn);

    $enabledInt = $enabled ? 1 : 0;
    $st = $conn->prepare("UPDATE products SET auto_delivery_enabled = ?, auto_delivery_intro = ?, auto_delivery_conclusion = ? WHERE id = ?");
    // Use 'i' for boolean since PgCompat might cast it
    $st->bind_param('issi', $enabledInt, $intro, $conclusion, $productId);
    $st->execute();
    $ok = $st->affected_rows >= 0;
    $st->close();
    return $ok;
}

/**
 * Get total available stock per variant for a product.
 */
function stockSummaryByVariant(object $conn, mixed $productId): array
{
    $productId = (int)$productId;
    stockEnsureTables($conn);

    $st = $conn->prepare("SELECT COALESCE(variante_nome, '') AS variante, status, COUNT(*) AS qty FROM product_stock_items WHERE product_id = ? GROUP BY variante_nome, status ORDER BY variante_nome");
    $st->bind_param('i', $productId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $summary = [];
    foreach ($rows as $r) {
        $v = $r['variante'] ?: '(geral)';
        if (!isset($summary[$v])) $summary[$v] = ['disponivel' => 0, 'vendido' => 0, 'total' => 0];
        $summary[$v][$r['status']] = (int)$r['qty'];
        $summary[$v]['total'] += (int)$r['qty'];
    }
    return $summary;
}
