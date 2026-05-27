<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/wallet_escrow.php';
require_once __DIR__ . '/upload_paths.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/stock_items.php';

function sfAutoDeliveryItemsList(?string $raw): array
{
    $value = trim((string)$raw);
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    $source = is_array($decoded) ? $decoded : preg_split('/\r\n|\r|\n/', $value);
    if (!is_array($source)) {
        return [];
    }

    $items = [];
    foreach ($source as $item) {
        $text = trim((string)$item);
        if ($text !== '') {
            $items[] = $text;
        }
    }

    return $items;
}

function sfAutoDeliveryItemsText(?string $raw): string
{
    return implode("\n", sfAutoDeliveryItemsList($raw));
}

function sfAutoDeliveryItemsJson(?string $raw): ?string
{
    $items = sfAutoDeliveryItemsList($raw);
    return $items ? json_encode($items, JSON_UNESCAPED_UNICODE) : null;
}

function sfAutoDeliveryConfiguredCount($conn, int $productId, ?string $rawItems = null): int
{
    $legacyCount = count(sfAutoDeliveryItemsList($rawItems));
    $stockCount = $productId > 0 ? stockCountAll($conn, $productId) : 0;
    return $stockCount + $legacyCount;
}

function sfAutoDeliveryAvailableCount($conn, int $productId, string $type = 'produto', ?string $variant = null, ?string $rawItems = null): int
{
    $typeKey = mb_strtolower(trim($type));
    if ($typeKey === 'dinamico') {
        if ($productId <= 0) {
            return 0;
        }
        if ($variant !== null && $variant !== '') {
            return stockCountAvailable($conn, $productId, $variant);
        }
        return stockCountAll($conn, $productId, null, 'disponivel');
    }

    $stockCount = $productId > 0 ? stockCountAvailable($conn, $productId, $variant) : 0;
    if ($variant !== null && $variant !== '') {
        return $stockCount;
    }

    return $stockCount + count(sfAutoDeliveryItemsList($rawItems));
}

function sfProductAutoDeliveryEnabledEffective($conn, array $product): bool
{
    if (!stockBoolValue($product['auto_delivery_enabled'] ?? false)) {
        return false;
    }

    return sfAutoDeliveryConfiguredCount(
        $conn,
        (int)($product['id'] ?? 0),
        (string)($product['auto_delivery_items'] ?? '')
    ) > 0;
}

function sfProductAvailableQuantity($conn, array $product): int
{
    $typeKey = mb_strtolower(trim((string)($product['tipo'] ?? 'produto')));
    if ($typeKey === 'servico' || $typeKey === 'serviço') {
        return 1;
    }

    if (stockBoolValue($product['auto_delivery_enabled'] ?? false)) {
        return sfAutoDeliveryAvailableCount(
            $conn,
            (int)($product['id'] ?? 0),
            $typeKey,
            null,
            (string)($product['auto_delivery_items'] ?? '')
        );
    }

    if ($typeKey === 'dinamico') {
        $variantsRaw = $product['variantes'] ?? null;
        $variants = is_string($variantsRaw)
            ? (json_decode($variantsRaw, true) ?: [])
            : (is_array($variantsRaw) ? $variantsRaw : []);

        $quantity = 0;
        foreach ($variants as $variant) {
            if (is_array($variant)) {
                $quantity += max(0, (int)($variant['quantidade'] ?? 0));
            }
        }

        if ($quantity > 0) {
            return $quantity;
        }
    }

    return max(0, (int)($product['quantidade'] ?? 0));
}

function sfProductCanPurchase($conn, array $product): bool
{
    return sfProductAvailableQuantity($conn, $product) > 0;
}

/* ── Slug helpers ── */

/* ── Vendor slug helpers ── */
function _sfEnsureOrderWalletColumns($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS gross_total NUMERIC(12,2) NOT NULL DEFAULT 0.00");
        $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS wallet_used NUMERIC(12,2) NOT NULL DEFAULT 0.00");
        $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS buyer_fee NUMERIC(12,2) NOT NULL DEFAULT 0.00");
    } catch (\Throwable $e) {}
}

/**
 * Auto-add delivery_content + delivered_at columns to order_items for digital delivery.
 */
function _sfEnsureDeliveryColumns($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS delivery_content TEXT DEFAULT NULL");
        $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMP DEFAULT NULL");
    } catch (\Throwable $e) {}
}

function _sfEnsureVendorSlugColumn($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS slug VARCHAR(191)");
        @$conn->query("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_slug ON users(slug)");
    } catch (\Throwable $e) {}
}

function _sfBackfillVendorSlugs($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    _sfEnsureVendorSlugColumn($conn);

    // 1) Backfill empty slugs — use nome_loja when available
    $rs = $conn->query("SELECT u.id, COALESCE(NULLIF(TRIM(sp.nome_loja), ''), u.nome) AS label
                        FROM users u
                        LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                        WHERE (u.slug IS NULL OR u.slug = '')
                          AND (u.is_vendedor = true OR u.status_vendedor = 'aprovado')
                        ORDER BY u.id ASC");
    if ($rs) {
        $rows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
        foreach ($rows as $row) {
            $slug = sfCreateUniqueVendorSlug($conn, (string)$row['label'], (int)$row['id']);
            $up = $conn->prepare("UPDATE users SET slug = ? WHERE id = ?");
            if ($up) { $up->bind_param('si', $slug, $row['id']); $up->execute(); $up->close(); }
        }
    }

    // 2) Re-slug vendors that have nome_loja but slug still reflects users.nome
    $rs2 = $conn->query("SELECT u.id, u.slug, sp.nome_loja
                         FROM users u
                         INNER JOIN seller_profiles sp ON sp.user_id = u.id
                         WHERE u.slug IS NOT NULL AND u.slug != ''
                           AND sp.nome_loja IS NOT NULL AND TRIM(sp.nome_loja) != ''
                           AND (u.is_vendedor = true OR u.status_vendedor = 'aprovado')
                         ORDER BY u.id ASC");
    if ($rs2) {
        $rows2 = $rs2->fetch_all(MYSQLI_ASSOC) ?: [];
        foreach ($rows2 as $row) {
            $expected = sfGenerateSlug((string)$row['nome_loja']);
            $current = (string)$row['slug'];
            // Only re-slug if current slug doesn't start with the expected base
            if ($expected !== '' && strpos($current, $expected) !== 0) {
                $slug = sfCreateUniqueVendorSlug($conn, (string)$row['nome_loja'], (int)$row['id']);
                $up = $conn->prepare("UPDATE users SET slug = ? WHERE id = ?");
                if ($up) { $up->bind_param('si', $slug, $row['id']); $up->execute(); $up->close(); }
            }
        }
    }
}

function sfVendorUrl(array $vendor): string
{
    $slug = (string)($vendor['slug'] ?? $vendor['vendedor_slug'] ?? '');
    if ($slug !== '') {
        return '/loja/' . $slug;
    }
    return BASE_PATH . '/loja?id=' . (int)($vendor['id'] ?? $vendor['vendedor_id'] ?? 0);
}

function sfGetVendorBySlug($conn, string $slug): ?array
{
    _sfBackfillVendorSlugs($conn);
    $st = $conn->prepare("SELECT id FROM users WHERE slug = ? AND ativo = 1 LIMIT 1");
    if (!$st) return null;
    $st->bind_param('s', $slug);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

/* ── Category slug helpers ── */
function _sfEnsureCategorySlugColumn($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS slug VARCHAR(191)");
        @$conn->query("CREATE UNIQUE INDEX IF NOT EXISTS idx_categories_slug ON categories(slug)");
        $conn->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS imagem TEXT DEFAULT NULL");
        $conn->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS destaque BOOLEAN NOT NULL DEFAULT FALSE");
    } catch (\Throwable $e) {}
}

function _sfBackfillCategorySlugs($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    _sfEnsureCategorySlugColumn($conn);

    $rs = $conn->query("SELECT id, nome FROM categories WHERE slug IS NULL OR slug = '' ORDER BY id ASC");
    if (!$rs) return;
    $rows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
    if (!$rows) return;

    foreach ($rows as $row) {
        $base = sfGenerateSlug((string)$row['nome']);
        $slug = $base;
        $suffix = 1;
        while (true) {
            $chk = $conn->prepare("SELECT id FROM categories WHERE slug = ? AND id != ? LIMIT 1");
            if (!$chk) break;
            $chk->bind_param('si', $slug, $row['id']);
            $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) { $chk->close(); break; }
            $chk->close();
            $slug = $base . '-' . (++$suffix);
        }
        $up = $conn->prepare("UPDATE categories SET slug = ? WHERE id = ?");
        if ($up) { $up->bind_param('si', $slug, $row['id']); $up->execute(); $up->close(); }
    }
}

function sfCategoryUrl(array $cat): string
{
    $slug = (string)($cat['slug'] ?? '');
    if ($slug !== '') {
        return '/c/' . $slug;
    }
    return BASE_PATH . '/categorias?categoria_id=' . (int)($cat['id'] ?? 0);
}

function sfGetCategoryBySlug($conn, string $slug): ?array
{
    _sfBackfillCategorySlugs($conn);
    $st = $conn->prepare("SELECT id, nome, tipo, slug FROM categories WHERE slug = ? AND ativo = 1 LIMIT 1");
    if (!$st) return null;
    $st->bind_param('s', $slug);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

/* ── Product slug helpers ── */

function _sfEnsureSlugColumn($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS slug VARCHAR(191)");
        // create unique index — ignore error if already exists
        @$conn->query("CREATE UNIQUE INDEX IF NOT EXISTS idx_products_slug ON products(slug)");
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS destaque BOOLEAN NOT NULL DEFAULT FALSE");
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_enabled BOOLEAN NOT NULL DEFAULT FALSE");
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_items TEXT DEFAULT NULL");
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS prazo_entrega_dias INT DEFAULT NULL");
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS data_entrega DATE DEFAULT NULL");
    } catch (\Throwable $e) {}
}

function sfGenerateSlug(string $name): string
{
    $map = [
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ñ'=>'n','ç'=>'c','ý'=>'y','ÿ'=>'y',
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A',
        'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
        'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
        'Ñ'=>'N','Ç'=>'C','Ý'=>'Y',
    ];
    $s = strtr($name, $map);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s === '' ? 'produto' : $s;
}

function _sfBackfillSlugs($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    _sfEnsureSlugColumn($conn);

    $rs = $conn->query("SELECT id, nome FROM products WHERE slug IS NULL OR slug = '' ORDER BY id ASC");
    if (!$rs) return;
    $rows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
    if (!$rows) return;

    foreach ($rows as $row) {
        $base = sfGenerateSlug((string)$row['nome']);
        $slug = $base;
        $suffix = 1;
        // ensure unique
        while (true) {
            $chk = $conn->prepare("SELECT id FROM products WHERE slug = ? AND id != ? LIMIT 1");
            if (!$chk) break;
            $pid = (int)$row['id'];
            $chk->bind_param('si', $slug, $pid);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$exists) break;
            $suffix++;
            $slug = $base . '-' . $suffix;
        }
        $up = $conn->prepare("UPDATE products SET slug = ? WHERE id = ?");
        if ($up) {
            $pid = (int)$row['id'];
            $up->bind_param('si', $slug, $pid);
            $up->execute();
            $up->close();
        }
    }
}

function sfGetProductBySlug($conn, string $slug): ?array
{
    _sfBackfillSlugs($conn);
    _sfEnsureCategorySlugColumn($conn);
    _sfEnsureVendorSlugColumn($conn);
    if ($slug === '') return null;

    $cols = sfProductColumns($conn);
    if ($cols['vendor'] === null || $cols['category'] === null) return null;

    $imageExpr = $cols['image'] ? ('p.' . $cols['image']) : "''";
    $whereActive = $cols['active'] !== null ? (' AND p.' . $cols['active'] . ' = 1') : '';
    if ($cols['approval_status'] !== null) {
        $whereActive .= " AND COALESCE(p." . $cols['approval_status'] . ", 'aprovado') = 'aprovado'";
    }
    $externalAutoDeliveryExpr = sfExternalAutoDeliverySelect($conn);

    $sql = "SELECT p.id, p.nome, p.descricao, p.preco, {$imageExpr} AS imagem,
                   p.slug, p.variantes,
                   c.id AS categoria_id, c.nome AS categoria_nome, c.tipo AS categoria_tipo, c.slug AS categoria_slug,
                   p." . $cols['vendor'] . " AS vendedor_id, u.nome AS vendedor_nome, u.slug AS vendedor_slug,
                   COALESCE(p.tipo, 'produto') AS tipo,
                   COALESCE(p.quantidade, 0) AS quantidade,
                   p.prazo_entrega_dias, p.data_entrega,
                 COALESCE(p.auto_delivery_enabled, FALSE) AS auto_delivery_enabled,
                p.auto_delivery_items,
                {$externalAutoDeliveryExpr}
            FROM products p
            LEFT JOIN categories c ON c.id = p." . $cols['category'] . "
            LEFT JOIN users u ON u.id = p." . $cols['vendor'] . "
            WHERE p.slug = ?{$whereActive}
            LIMIT 1";

    $st = $conn->prepare($sql);
    if (!$st) return null;
    $st->bind_param('s', $slug);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
        $row['gallery'] = mediaListByEntity('product_gallery', (int)$row['id']);
    }
    return $row ?: null;
}

function sfProductUrl(array $product): string
{
    $slug = (string)($product['slug'] ?? '');
    if ($slug !== '') {
        return '/p/' . $slug;
    }
    return BASE_PATH . '/produto?id=' . (int)($product['id'] ?? 0);
}

function sfCreateUniqueSlug($conn, string $name, int $excludeId = 0): string
{
    _sfEnsureSlugColumn($conn);
    $base = sfGenerateSlug($name);
    $slug = $base;
    $suffix = 1;
    while (true) {
        $chk = $conn->prepare("SELECT id FROM products WHERE slug = ? AND id != ? LIMIT 1");
        if (!$chk) break;
        $chk->bind_param('si', $slug, $excludeId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$exists) break;
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
    return $slug;
}

/**
 * Create a unique slug for vendors (users table).
 */
function sfCreateUniqueVendorSlug($conn, string $name, int $excludeId = 0): string
{
    _sfEnsureVendorSlugColumn($conn);
    $base = sfGenerateSlug($name);
    $slug = $base;
    $suffix = 1;
    while (true) {
        $chk = $conn->prepare("SELECT id FROM users WHERE slug = ? AND id != ? LIMIT 1");
        if (!$chk) break;
        $chk->bind_param('si', $slug, $excludeId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$exists) break;
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
    return $slug;
}

/**
 * Create a unique slug for categories.
 */
function sfCreateUniqueCategorySlug($conn, string $name, int $excludeId = 0): string
{
    _sfEnsureCategorySlugColumn($conn);
    $base = sfGenerateSlug($name);
    $slug = $base;
    $suffix = 1;
    while (true) {
        $chk = $conn->prepare("SELECT id FROM categories WHERE slug = ? AND id != ? LIMIT 1");
        if (!$chk) break;
        $chk->bind_param('si', $slug, $excludeId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$exists) break;
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
    return $slug;
}

/**
 * Check if a slug is available in a given table.
 * Returns ['available' => bool, 'suggestion' => string]
 */
function sfCheckSlugAvailable($conn, string $type, string $slug, int $excludeId = 0): array
{
    $slug = sfGenerateSlug($slug);
    if ($slug === '' || $slug === 'produto') {
        return ['available' => false, 'slug' => $slug, 'suggestion' => ''];
    }

    switch ($type) {
        case 'product':
            _sfEnsureSlugColumn($conn);
            $table = 'products';
            break;
        case 'category':
            _sfEnsureCategorySlugColumn($conn);
            $table = 'categories';
            break;
        case 'vendor':
            _sfEnsureVendorSlugColumn($conn);
            $table = 'users';
            break;
        default:
            return ['available' => false, 'slug' => $slug, 'suggestion' => ''];
    }

    $chk = $conn->prepare("SELECT id FROM {$table} WHERE slug = ? AND id != ? LIMIT 1");
    if (!$chk) return ['available' => false, 'slug' => $slug, 'suggestion' => ''];
    $chk->bind_param('si', $slug, $excludeId);
    $chk->execute();
    $exists = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$exists) {
        return ['available' => true, 'slug' => $slug, 'suggestion' => ''];
    }

    // Find a suggestion
    $base = $slug;
    $suffix = 2;
    while ($suffix <= 100) {
        $candidate = $base . '-' . $suffix;
        $chk = $conn->prepare("SELECT id FROM {$table} WHERE slug = ? AND id != ? LIMIT 1");
        if (!$chk) break;
        $chk->bind_param('si', $candidate, $excludeId);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            $chk->close();
            return ['available' => false, 'slug' => $slug, 'suggestion' => $candidate];
        }
        $chk->close();
        $suffix++;
    }
    return ['available' => false, 'slug' => $slug, 'suggestion' => ''];
}

function sfStartSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function sfImageUrl(string $raw): string
{
    return mediaResolveUrl($raw, 'https://placehold.co/1200x800/111827/9ca3af?text=Produto');
}

function sfHomeSettingGet($conn, string $key, string $default = ''): string
{
    $fullKey = 'home.' . $key;
    $st = $conn->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
    if (!$st) return $default;
    $st->bind_param('s', $fullKey);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ? (string)$row['setting_value'] : $default;
}

function sfHomeSettingSet($conn, string $key, string $value): void
{
    $fullKey = 'home.' . $key;
    $st = $conn->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
                          ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value");
    if ($st) {
        $st->bind_param('ss', $fullKey, $value);
        $st->execute();
        $st->close();
    }
}

function sfGetCart(): array
{
    sfStartSession();
    $cart = $_SESSION['store_cart'] ?? [];
    if (!is_array($cart)) {
        return [];
    }

    $clean = [];
    foreach ($cart as $key => $qty) {
        // Keys can be "productId" or "productId:varianteName"
        $q = (int)$qty;
        if ($q <= 0) continue;
        [$id, ] = sfCartParseKey((string)$key);
        if ($id > 0) {
            $clean[(string)$key] = min(99, $q);
        }
    }

    $_SESSION['store_cart'] = $clean;
    return $clean;
}

/**
 * Build a composite cart key: "productId" or "productId:varianteName".
 */
function sfCartKey(int $productId, ?string $variante = null): string
{
    if ($variante !== null && $variante !== '') {
        return $productId . ':' . $variante;
    }
    return (string)$productId;
}

/**
 * Parse a composite cart key into [productId, varianteName|null].
 */
function sfCartParseKey(string $key): array
{
    if (str_contains($key, ':')) {
        [$id, $var] = explode(':', $key, 2);
        return [(int)$id, $var];
    }
    return [(int)$key, null];
}

function sfCartCount(): int
{
    return array_sum(sfGetCart());
}

function sfCartAdd(int $productId, int $qty = 1, ?string $variante = null): void
{
    if ($productId <= 0 || $qty <= 0) {
        return;
    }

    $cart = sfGetCart();
    $key = sfCartKey($productId, $variante);
    $cart[$key] = min(99, (int)($cart[$key] ?? 0) + $qty);
    $_SESSION['store_cart'] = $cart;
}

function sfCartSetQty(int $productId, int $qty, ?string $variante = null): void
{
    $cart = sfGetCart();
    if ($productId <= 0) {
        return;
    }

    $key = sfCartKey($productId, $variante);
    if ($qty <= 0) {
        unset($cart[$key]);
    } else {
        $cart[$key] = min(99, $qty);
    }

    $_SESSION['store_cart'] = $cart;
}

function sfCartRemove(int $productId, ?string $variante = null): void
{
    $cart = sfGetCart();
    $key = sfCartKey($productId, $variante);
    unset($cart[$key]);
    $_SESSION['store_cart'] = $cart;
}

function sfCartRemoveByKey(string $key): void
{
    $cart = sfGetCart();
    unset($cart[$key]);
    $_SESSION['store_cart'] = $cart;
}

function sfCartClear(): void
{
    sfStartSession();
    $_SESSION['store_cart'] = [];
}

function sfPickColumn(array $cols, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array(strtolower($candidate), $cols, true)) {
            return $candidate;
        }
    }
    return null;
}

function sfTableColumns($conn, string $table): array
{
    $columns = [];
    $rs = $conn->query('SHOW COLUMNS FROM ' . $table);
    if (!$rs) {
        return [];
    }

    while ($row = $rs->fetch_assoc()) {
        $columns[] = strtolower((string)($row['Field'] ?? ''));
    }

    return $columns;
}

function sfVerificationStatusApproved(string $status): bool
{
    return in_array(strtolower(trim($status)), ['verificado', 'aprovado'], true);
}

function sfUserVerificationStatuses($conn, int $userId): array
{
    static $cache = [];
    $defaults = ['dados' => 'pendente', 'email' => 'pendente', 'documentos' => 'pendente'];
    if ($userId <= 0) return $defaults;
    if (isset($cache[$userId])) return $cache[$userId];

    $statuses = $defaults;
    try {
        $st = $conn->prepare("SELECT tipo, status FROM user_verifications WHERE user_id = ? AND tipo IN ('dados', 'email', 'documentos')");
        if (!$st) return $cache[$userId] = $statuses;
        $st->bind_param('i', $userId);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $st->close();
        foreach ($rows as $row) {
            $type = strtolower((string)($row['tipo'] ?? ''));
            if (array_key_exists($type, $statuses)) {
                $statuses[$type] = strtolower((string)($row['status'] ?? 'pendente'));
            }
        }
    } catch (\Throwable) {
        return $cache[$userId] = $statuses;
    }

    return $cache[$userId] = $statuses;
}

function sfUserKycVerified($conn, int $userId): bool
{
    $statuses = sfUserVerificationStatuses($conn, $userId);
    foreach (['dados', 'email', 'documentos'] as $requiredType) {
        if (!sfVerificationStatusApproved((string)($statuses[$requiredType] ?? ''))) {
            return false;
        }
    }
    return true;
}

function sfProductColumns($conn): array
{
    $cols = sfTableColumns($conn, 'products');

    $vendor = sfPickColumn($cols, ['vendedor_id', 'user_id']);
    $category = sfPickColumn($cols, ['categoria_id', 'category_id']);
    $image = sfPickColumn($cols, ['imagem', 'image']);
    $active = sfPickColumn($cols, ['ativo', 'active']);
    $approvalStatus = sfPickColumn($cols, ['status_aprovacao']);
    $featured = sfPickColumn($cols, ['destaque', 'featured', 'is_featured']);

    return [
        'vendor' => $vendor,
        'category' => $category,
        'image' => $image,
        'active' => $active,
        'approval_status' => $approvalStatus,
        'featured' => $featured,
    ];
}

function sfExternalAutoDeliverySelect($conn): string
{
    $mappingCols = sfTableColumns($conn, 'external_product_mappings');
    if (!in_array('product_id', $mappingCols, true) || !in_array('provider', $mappingCols, true) || !in_array('external_payload', $mappingCols, true)) {
        return 'FALSE AS external_auto_delivery';
    }
    return "CASE WHEN EXISTS (SELECT 1 FROM external_product_mappings em WHERE em.product_id = p.id AND em.provider = 'alup' AND COALESCE(em.external_payload, '') LIKE '%8b80f8fd-9f02-48da-9ee1-d3bae135f96c%') THEN TRUE ELSE FALSE END AS external_auto_delivery";
}

function sfListCategories($conn, string $tipo = '', bool $featuredOnly = false, int $limit = 0): array
{
    _sfBackfillCategorySlugs($conn);
    $cols = sfTableColumns($conn, 'categories');
    $hasFeatured = in_array('destaque', $cols, true);
    $featuredExpr = $hasFeatured ? 'COALESCE(destaque, FALSE) AS destaque' : 'FALSE AS destaque';
    $where = ['ativo = 1'];
    $types = '';
    $params = [];

    if ($tipo !== '') {
        $where[] = 'tipo = ?';
        $types .= 's';
        $params[] = $tipo;
    }
    if ($featuredOnly) {
        if (!$hasFeatured) return [];
        $where[] = 'destaque = TRUE';
    }

    $sql = "SELECT id, nome, tipo, slug, imagem, {$featuredExpr}
            FROM categories
            WHERE " . implode(' AND ', $where) . "
            ORDER BY " . ($hasFeatured ? 'COALESCE(destaque, FALSE) DESC, ' : '') . "id DESC";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . max(1, min(100, $limit));
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') {
        $stmt->bind_param('s', $tipo);
    }
    $stmt->execute();
    $rs = $stmt->get_result();
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function sfProductFilterParts(array $filters, array $cols): array
{
    $where = [];
    $types = '';
    $params = [];

    if ($cols['active'] !== null) {
        $where[] = 'p.' . $cols['active'] . ' = 1';
    }

    if ($cols['approval_status'] !== null) {
        $where[] = "COALESCE(p." . $cols['approval_status'] . ", 'aprovado') = 'aprovado'";
    }

    $productId = (int)($filters['product_id'] ?? 0);
    if ($productId > 0) {
        $where[] = 'p.id = ?';
        $types .= 'i';
        $params[] = $productId;
    }

    $queryText = trim((string)($filters['q'] ?? ''));
    if ($queryText !== '') {
        $where[] = '(p.nome LIKE ? OR p.descricao LIKE ?)';
        $types .= 'ss';
        $likeQuery = '%' . $queryText . '%';
        $params[] = $likeQuery;
        $params[] = $likeQuery;
    }

    $categoryId = (int)($filters['category_id'] ?? 0);
    if ($categoryId > 0) {
        $where[] = 'p.' . $cols['category'] . ' = ?';
        $types .= 'i';
        $params[] = $categoryId;
    }

    $vendorId = (int)($filters['vendor_id'] ?? 0);
    if ($vendorId > 0) {
        $where[] = 'p.' . $cols['vendor'] . ' = ?';
        $types .= 'i';
        $params[] = $vendorId;
    }

    if (!empty($filters['featured_only'])) {
        if ($cols['featured'] === null) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'p.' . $cols['featured'] . ' = TRUE';
        }
    }

    return [$where, $types, $params];
}

function sfCountProducts($conn, array $filters = []): int
{
    _sfBackfillSlugs($conn);
    _sfEnsureCategorySlugColumn($conn);
    _sfEnsureVendorSlugColumn($conn);

    $cols = sfProductColumns($conn);
    if ($cols['vendor'] === null || $cols['category'] === null) {
        return 0;
    }

    [$where, $types, $params] = sfProductFilterParts($filters, $cols);
    $sql = 'SELECT COUNT(*) AS total FROM products p';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    if ($types !== '') {
        $bind = array_merge([$types], $params);
        $refs = [];
        foreach ($bind as $index => $value) {
            $refs[$index] = &$bind[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

function sfListProducts($conn, array $filters = []): array
{
    _sfBackfillSlugs($conn);
    _sfEnsureCategorySlugColumn($conn);
    _sfEnsureVendorSlugColumn($conn);

    $cols = sfProductColumns($conn);
    if ($cols['vendor'] === null || $cols['category'] === null) {
        return [];
    }

    $imageExpr = $cols['image'] ? ('p.' . $cols['image']) : "''";
    $featuredExpr = $cols['featured'] ? ('COALESCE(p.' . $cols['featured'] . ', FALSE)') : 'FALSE';
    $externalAutoDeliveryExpr = sfExternalAutoDeliverySelect($conn);
    [$where, $types, $params] = sfProductFilterParts($filters, $cols);
    $limit = max(1, min(100, (int)($filters['limit'] ?? 24)));
    $offset = max(0, (int)($filters['offset'] ?? 0));
    $order = (string)($filters['order'] ?? 'default');
    $salesJoin = '';
    $salesSelect = '';
    $salesOrder = '';
    if ($order === 'best_sellers') {
        $orderItemCols = sfTableColumns($conn, 'order_items');
        if (in_array('product_id', $orderItemCols, true)) {
            $moderationWhere = in_array('moderation_status', $orderItemCols, true)
                ? "WHERE COALESCE(moderation_status, 'aprovada') = 'aprovada'"
                : '';
            $subtotalExpr = in_array('subtotal', $orderItemCols, true) ? 'COALESCE(SUM(subtotal), 0)' : '0';
            $salesJoin = "
            LEFT JOIN (
                SELECT product_id, COUNT(*) AS sales_total, {$subtotalExpr} AS sales_volume
                FROM order_items
                {$moderationWhere}
                GROUP BY product_id
            ) psales ON psales.product_id = p.id";
            $salesSelect = ', COALESCE(psales.sales_total, 0) AS sales_total, COALESCE(psales.sales_volume, 0) AS sales_volume';
            $salesOrder = 'COALESCE(psales.sales_total, 0) DESC, COALESCE(psales.sales_volume, 0) DESC, ';
        }
    }

    $sql = "SELECT p.id, p.nome, p.descricao, p.preco, {$imageExpr} AS imagem,
                   p.slug, p.variantes, {$featuredExpr} AS destaque,
                   c.id AS categoria_id, c.nome AS categoria_nome, c.tipo AS categoria_tipo, c.slug AS categoria_slug,
                   p." . $cols['vendor'] . " AS vendedor_id, u.nome AS vendedor_nome, u.slug AS vendedor_slug,
                   COALESCE(p.tipo, 'produto') AS tipo,
                   COALESCE(p.quantidade, 0) AS quantidade,
                   p.prazo_entrega_dias, p.data_entrega,
                 COALESCE(p.auto_delivery_enabled, FALSE) AS auto_delivery_enabled,
                  p.auto_delivery_items,
                  {$externalAutoDeliveryExpr}{$salesSelect}
            FROM products p
            LEFT JOIN categories c ON c.id = p." . $cols['category'] . "
            LEFT JOIN users u ON u.id = p." . $cols['vendor'] . $salesJoin;

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY ' . $salesOrder . ($cols['featured'] ? ('COALESCE(p.' . $cols['featured'] . ', FALSE) DESC, ') : '') . 'p.id DESC LIMIT ' . $limit;
    if ($offset > 0) {
        $sql .= ' OFFSET ' . $offset;
    }

    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }

    if ($types !== '') {
        $bind = array_merge([$types], $params);
        $refs = [];
        foreach ($bind as $k => $v) {
            $refs[$k] = &$bind[$k];
        }
        call_user_func_array([$st, 'bind_param'], $refs);
    }

    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();

    return $rows;
}

function sfGetProductById($conn, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }

    _sfEnsureCategorySlugColumn($conn);
    _sfEnsureVendorSlugColumn($conn);

    $rows = sfListProducts($conn, ['limit' => 1, 'product_id' => $productId]);
    if ($rows) {
        $first = $rows[0];
        if ((int)$first['id'] === $productId) {
            // Attach gallery images from media_files (was missing — caused slider to never show)
            $first['gallery'] = mediaListByEntity('product_gallery', (int)$first['id']);
            return $first;
        }
    }

    $cols = sfProductColumns($conn);
    if ($cols['vendor'] === null || $cols['category'] === null) {
        return null;
    }

    $imageExpr = $cols['image'] ? ('p.' . $cols['image']) : "''";
    $featuredExpr = $cols['featured'] ? ('COALESCE(p.' . $cols['featured'] . ', FALSE)') : 'FALSE';
    $whereActive = $cols['active'] !== null ? (' AND p.' . $cols['active'] . ' = 1') : '';
    if ($cols['approval_status'] !== null) {
        $whereActive .= " AND COALESCE(p." . $cols['approval_status'] . ", 'aprovado') = 'aprovado'";
    }
    $externalAutoDeliveryExpr = sfExternalAutoDeliverySelect($conn);

    $sql = "SELECT p.id, p.nome, p.descricao, p.preco, {$imageExpr} AS imagem,
                   p.slug, p.variantes, {$featuredExpr} AS destaque,
                   c.id AS categoria_id, c.nome AS categoria_nome, c.tipo AS categoria_tipo, c.slug AS categoria_slug,
                   p." . $cols['vendor'] . " AS vendedor_id, u.nome AS vendedor_nome, u.slug AS vendedor_slug,
                   COALESCE(p.tipo, 'produto') AS tipo,
                   COALESCE(p.quantidade, 0) AS quantidade,
                   p.prazo_entrega_dias, p.data_entrega,
                 COALESCE(p.auto_delivery_enabled, FALSE) AS auto_delivery_enabled,
                p.auto_delivery_items,
                {$externalAutoDeliveryExpr}
            FROM products p
            LEFT JOIN categories c ON c.id = p." . $cols['category'] . "
            LEFT JOIN users u ON u.id = p." . $cols['vendor'] . "
            WHERE p.id = ?{$whereActive}
            LIMIT 1";

    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }

    $st->bind_param('i', $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
        // Attach gallery images from media_files
        $row['gallery'] = mediaListByEntity('product_gallery', (int)$row['id']);
    }

    return $row ?: null;
}

function sfCartSummary($conn): array
{
    $cart = sfGetCart();
    if (!$cart) {
        return ['items' => [], 'total' => 0.0, 'count' => 0];
    }

    _sfEnsureCategorySlugColumn($conn);
    _sfEnsureVendorSlugColumn($conn);

    // Extract unique product IDs from composite keys
    $idSet = [];
    foreach ($cart as $key => $qty) {
        [$id, ] = sfCartParseKey((string)$key);
        $idSet[$id] = true;
    }
    $ids = array_keys($idSet);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $cols = sfProductColumns($conn);
    if ($cols['vendor'] === null || $cols['category'] === null) {
        return ['items' => [], 'total' => 0.0, 'count' => 0];
    }

    $imageExpr = $cols['image'] ? ('p.' . $cols['image']) : "''";
    $whereActive = $cols['active'] !== null ? (' AND p.' . $cols['active'] . ' = 1') : '';

    $sql = "SELECT p.id, p.nome, p.descricao, p.preco, {$imageExpr} AS imagem,
                   p.slug, p.variantes,
                   COALESCE(p.tipo, 'produto') AS tipo,
                   COALESCE(p.quantidade, 0) AS quantidade,
                 COALESCE(p.auto_delivery_enabled, FALSE) AS auto_delivery_enabled,
                 p.auto_delivery_items,
                   c.id AS categoria_id, c.nome AS categoria_nome, c.slug AS categoria_slug,
                   p." . $cols['vendor'] . " AS vendedor_id, u.nome AS vendedor_nome, u.slug AS vendedor_slug
            FROM products p
            LEFT JOIN categories c ON c.id = p." . $cols['category'] . "
            LEFT JOIN users u ON u.id = p." . $cols['vendor'] . "
            WHERE p.id IN ({$placeholders}){$whereActive}";

    $st = $conn->prepare($sql);
    if (!$st) {
        return ['items' => [], 'total' => 0.0, 'count' => 0];
    }

    $bind = array_merge([$types], $ids);
    $refs = [];
    foreach ($bind as $k => $v) {
        $refs[$k] = &$bind[$k];
    }
    call_user_func_array([$st, 'bind_param'], $refs);

    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();

    // Index by product ID for quick lookup
    $productMap = [];
    foreach ($rows as $row) {
        $productMap[(int)$row['id']] = $row;
    }

    $items = [];
    $total = 0.0;
    $count = 0;

    foreach ($cart as $key => $qty) {
        $qty = (int)$qty;
        if ($qty <= 0) continue;

        [$id, $varianteName] = sfCartParseKey((string)$key);
        if (!isset($productMap[$id])) continue;

        $row = $productMap[$id]; // copy
        $unit = (float)$row['preco'];

        // For dynamic (variant) products, resolve the selected variant's price
        $tipo = (string)($row['tipo'] ?? 'produto');
        if ($tipo === 'dinamico' && !empty($row['variantes'])) {
            $vars = is_string($row['variantes']) ? json_decode($row['variantes'], true) : $row['variantes'];
            if (is_array($vars) && count($vars) > 0) {
                if ($varianteName !== null && $varianteName !== '') {
                    foreach ($vars as $v) {
                        if (isset($v['nome']) && (string)$v['nome'] === $varianteName && isset($v['preco'])) {
                            $unit = (float)$v['preco'];
                            break;
                        }
                    }
                }
                // Fallback: if no variant matched, use first variant price
                if ($unit <= 0) {
                    $unit = (float)($vars[0]['preco'] ?? 0);
                    if ($varianteName === null || $varianteName === '') {
                        $varianteName = (string)($vars[0]['nome'] ?? '');
                    }
                }
            }
        }

        $line = round($unit * $qty, 2);
        $count += $qty;
        $total += $line;

        $row['preco'] = $unit; // Store resolved price
        $row['qty'] = $qty;
        $row['line_total'] = $line;
        $row['variante_nome'] = $varianteName;
        $row['cart_key'] = (string)$key;
        $items[] = $row;
    }

    usort($items, static fn(array $a, array $b): int => ((int)$a['id'] <=> (int)$b['id']) * -1);

    return [
        'items' => $items,
        'total' => round($total, 2),
        'count' => $count,
    ];
}

function sfWalletSaldo($conn, int $userId): float
{
    if ($userId <= 0) {
        return 0.0;
    }

    $userCols = sfTableColumns($conn, 'users');
    $walletFrozenSelect = in_array('wallet_frozen', $userCols, true) ? ', wallet_frozen' : '';
    $st = $conn->prepare('SELECT wallet_saldo' . $walletFrozenSelect . ' FROM users WHERE id = ? LIMIT 1');
    if (!$st) {
        return 0.0;
    }

    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    if ((string)($row['wallet_frozen'] ?? '0') === '1' || (string)($row['wallet_frozen'] ?? '') === 't') {
        return 0.0;
    }

    return (float)($row['wallet_saldo'] ?? 0);
}

function sfCreateOrderFromCart($conn, int $userId, bool $useWallet): array
{
    if ($userId <= 0) {
        return [false, 'Usuário inválido.', []];
    }

    $summary = sfCartSummary($conn);
    $items = $summary['items'];
    $grossTotal = (float)$summary['total'];

    if (!$items || $grossTotal <= 0) {
        return [false, 'Carrinho vazio.', []];
    }

    // Validação server-side de estoque (evita bypass do frontend)
    foreach ($items as $ck) {
        $tipoCk = (string)($ck['tipo'] ?? 'produto');
        $qtyCk  = (int)($ck['qty'] ?? 1);
        if ($tipoCk === 'servico') continue;

        if ($tipoCk === 'dinamico') {
            $varNomeCk = (string)($ck['variante_nome'] ?? '');
            if ($varNomeCk === '') {
                return [false, 'Selecione uma opção para o produto dinâmico "' . (string)($ck['nome'] ?? '') . '".', []];
            }
            $vars = is_string($ck['variantes'] ?? null) ? json_decode((string)$ck['variantes'], true) : ($ck['variantes'] ?? null);
            $varQtd = 0;
            if (is_array($vars)) {
                foreach ($vars as $v) {
                    if ((string)($v['nome'] ?? '') === $varNomeCk) { $varQtd = (int)($v['quantidade'] ?? 0); break; }
                }
            }
            if ($varQtd < $qtyCk) {
                return [false, 'A opção "' . $varNomeCk . '" de "' . (string)($ck['nome'] ?? '') . '" está esgotada.', []];
            }
        } else {
            $qtdDisp = (int)($ck['quantidade'] ?? 0);
            if ($qtdDisp < $qtyCk) {
                return [false, 'O produto "' . (string)($ck['nome'] ?? '') . '" está esgotado.', []];
            }
        }

        // Extra: produtos com auto-delivery precisam de itens realmente configurados
        // e disponíveis, seja no estoque automático ou no pool legado JSON.
        try {
            $pidCk = (int)($ck['id'] ?? 0);
            if ($pidCk > 0 && stockBoolValue($ck['auto_delivery_enabled'] ?? false)) {
                $varForStock = $tipoCk === 'dinamico' ? (string)($ck['variante_nome'] ?? '') : null;
                $avail = sfAutoDeliveryAvailableCount(
                    $conn,
                    $pidCk,
                    $tipoCk,
                    $varForStock,
                    (string)($ck['auto_delivery_items'] ?? '')
                );
                if ($avail < $qtyCk) {
                    $rotulo = $tipoCk === 'dinamico'
                        ? ('"' . (string)($ck['variante_nome'] ?? '') . '" de "' . (string)($ck['nome'] ?? '') . '"')
                        : ('"' . (string)($ck['nome'] ?? '') . '"');
                    return [false, 'Estoque digital insuficiente para ' . $rotulo . '. Aguarde reposição.', []];
                }
            }
        } catch (\Throwable $eAd) {
            error_log('[sfCreateOrderFromCart] auto-delivery stock check failed: ' . $eAd->getMessage());
        }
    }

    // Buyer service fee (former lead_fee, now charged to buyer) + taxa fixa por pedido
    require_once __DIR__ . '/seller_levels.php';
    $buyerFeePct  = buyerServiceFeePercent($conn);
    $buyerFeeFlat = buyerFlatFeePerOrder($conn);
    $buyerFeeAmt  = round(($grossTotal * ($buyerFeePct / 100)) + $buyerFeeFlat, 2);
    $totalComTaxa = round($grossTotal + $buyerFeeAmt, 2);

    $walletUsed = 0.0;
    $pixTotal = $totalComTaxa;

    $conn->begin_transaction();
    try {
        $userCols = sfTableColumns($conn, 'users');
        $walletFrozenSelect = in_array('wallet_frozen', $userCols, true) ? ', wallet_frozen' : '';
        $stUser = $conn->prepare('SELECT wallet_saldo' . $walletFrozenSelect . ' FROM users WHERE id = ? FOR UPDATE');
        $stUser->bind_param('i', $userId);
        $stUser->execute();
        $user = $stUser->get_result()->fetch_assoc();
        if (!$user) {
            $conn->rollback();
            return [false, 'Usuário não encontrado.', []];
        }

        $walletFrozen = (string)($user['wallet_frozen'] ?? '0') === '1' || (string)($user['wallet_frozen'] ?? '') === 't';
        $walletSaldo = $walletFrozen ? 0.0 : (float)($user['wallet_saldo'] ?? 0);
        if ($useWallet && $walletSaldo > 0) {
            $walletUsed = min($walletSaldo, $totalComTaxa);
            $walletUsed = round($walletUsed, 2);
        }

        $pixTotal = round(max(0.0, $totalComTaxa - $walletUsed), 2);
        $orderStatus = $pixTotal > 0 ? 'pendente' : 'pago';

        // Ensure orders table has wallet columns (auto-migrate)
        _sfEnsureOrderWalletColumns($conn);

        $stOrder = $conn->prepare('INSERT INTO orders (user_id, status, total, gross_total, wallet_used, buyer_fee) VALUES (?, ?, ?, ?, ?, ?)');
        $stOrder->bind_param('isdddd', $userId, $orderStatus, $pixTotal, $grossTotal, $walletUsed, $buyerFeeAmt);
        $stOrder->execute();

        $orderId = (int)$conn->insert_id;
        if ($orderId <= 0) {
            $stFind = $conn->prepare('SELECT id FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1');
            $stFind->bind_param('i', $userId);
            $stFind->execute();
            $found = $stFind->get_result()->fetch_assoc() ?: [];
            $orderId = (int)($found['id'] ?? 0);
        }

        if ($orderId <= 0) {
            throw new RuntimeException('Falha ao criar pedido.');
        }

        $orderItemCols = sfTableColumns($conn, 'order_items');
        $hasModerationStatus = in_array('moderation_status', $orderItemCols, true);

        // Ensure variante_nome column exists
        try { $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS variante_nome VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}

        foreach ($items as $item) {
            $productId = (int)$item['id'];
            $sellerId = (int)($item['vendedor_id'] ?? 0);
            $qty = (int)($item['qty'] ?? 1);
            $unit = (float)($item['preco'] ?? 0);
            $line = round($unit * $qty, 2);
            $varNome = !empty($item['variante_nome']) ? (string)$item['variante_nome'] : null;
            $moderationStatus = 'pendente';

            if ($productId <= 0 || $sellerId <= 0 || $qty <= 0 || $unit < 0) {
                throw new RuntimeException('Item inválido no carrinho.');
            }

            if ($hasModerationStatus) {
                $stItem = $conn->prepare('INSERT INTO order_items (order_id, product_id, vendedor_id, quantidade, preco_unit, subtotal, moderation_status, variante_nome) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stItem->bind_param('iiiiddss', $orderId, $productId, $sellerId, $qty, $unit, $line, $moderationStatus, $varNome);
            } else {
                $stItem = $conn->prepare('INSERT INTO order_items (order_id, product_id, vendedor_id, quantidade, preco_unit, subtotal, variante_nome) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stItem->bind_param('iiiidds', $orderId, $productId, $sellerId, $qty, $unit, $line, $varNome);
            }
            $stItem->execute();
            $stItem->close();
        }

        // Only debit wallet immediately when order is FULLY paid by wallet (no PIX)
        // When PIX is involved, wallet is debited AFTER PIX payment is confirmed (webhook)
        if ($walletUsed > 0 && $pixTotal <= 0) {
            $stDebit = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo - ? WHERE id = ?');
            $stDebit->bind_param('di', $walletUsed, $userId);
            $stDebit->execute();

            $walletTxCols = sfTableColumns($conn, 'wallet_transactions');
            if ($walletTxCols) {
                $txDesc = 'Uso de saldo wallet no checkout do pedido #' . $orderId;
                $stTx = $conn->prepare("INSERT INTO wallet_transactions (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao) VALUES (?, 'debito', 'checkout_wallet', 'order', ?, ?, ?)");
                if ($stTx) {
                    $stTx->bind_param('iids', $userId, $orderId, $walletUsed, $txDesc);
                    $stTx->execute();
                }
            }

            // Credit admin with buyer service fee
            if ($buyerFeeAmt > 0) {
                $adminReceiver = escrowResolveAdminReceiver($conn);
                if ($adminReceiver > 0) {
                    $upAdmin = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?');
                    $upAdmin->bind_param('di', $buyerFeeAmt, $adminReceiver);
                    $upAdmin->execute();

                    $walletTxCols2 = sfTableColumns($conn, 'wallet_transactions');
                    if ($walletTxCols2) {
                        $txDescAdmin = 'Taxa de serviço (comprador) do pedido #' . $orderId;
                        $stTxAdmin = $conn->prepare("INSERT INTO wallet_transactions (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao) VALUES (?, 'credito', 'buyer_service_fee', 'order', ?, ?, ?)");
                        if ($stTxAdmin) {
                            $stTxAdmin->bind_param('iids', $adminReceiver, $orderId, $buyerFeeAmt, $txDescAdmin);
                            $stTxAdmin->execute();
                        }
                    }
                }
            }

            escrowInitializeOrderItems($conn, (int)$orderId);
        }

        $conn->commit();
        sfCartClear();

        // Affiliate conversion attribution
        if (function_exists('affAttributeConversion')) {
            try { affAttributeConversion($conn, $orderId, $userId, $grossTotal); } catch (\Throwable $e) { /* non-blocking */ }
        }

        return [true, 'Pedido criado com sucesso.', [
            'order_id' => $orderId,
            'gross_total' => $grossTotal,
            'buyer_fee' => $buyerFeeAmt,
            'buyer_fee_percent' => $buyerFeePct,
            'wallet_used' => $walletUsed,
            'pix_total' => $pixTotal,
            'status' => $orderStatus,
        ]];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Não foi possível finalizar seu pedido. ' . $e->getMessage(), []];
    }
}

/* ========================================================================
 *  VENDOR / SELLER PROFILE (public-facing)
 * ======================================================================== */

/**
 * Fetch vendor public profile data by user id.
 * Combines users + seller_profiles tables.
 */
function sfGetVendorProfile($conn, int $vendorId): ?array
{
    if ($vendorId <= 0) return null;

    _sfEnsureVendorSlugColumn($conn);

    $sql = "SELECT u.id, u.nome, u.email, u.avatar, u.slug, u.is_vendedor, u.status_vendedor, u.criado_em, u.last_seen_at,
                   sp.nome_loja, sp.bio, sp.telefone
            FROM users u
            LEFT JOIN seller_profiles sp ON sp.user_id = u.id
            WHERE u.id = ? AND u.ativo = 1
            LIMIT 1";

    $st = $conn->prepare($sql);
    if (!$st) return null;

    $st->bind_param('i', $vendorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) return null;

    // Allow profile for anyone who is a seller OR has products listed (some vendors
    // may not have is_vendedor/status_vendedor set yet)
    $isVendor = ((int)($row['is_vendedor'] ?? 0) === 1)
             || strtolower((string)($row['status_vendedor'] ?? '')) === 'aprovado';

    if (!$isVendor) {
        // Check if they have any products (they are effectively a vendor)
        $stProd = $conn->prepare("SELECT 1 FROM products WHERE vendedor_id = ? LIMIT 1");
        if ($stProd) {
            $stProd->bind_param('i', $vendorId);
            $stProd->execute();
            $hasProd = $stProd->get_result()->fetch_assoc();
            $stProd->close();
            if (!$hasProd) return null;
        }
    }

    $row['kyc_verificado'] = sfUserKycVerified($conn, $vendorId);
    return $row;
}

/**
 * Count total products by vendor.
 */
function sfVendorProductCount($conn, int $vendorId): int
{
    $cols = sfProductColumns($conn);
    if ($cols['vendor'] === null) return 0;

    $activeClause = $cols['active'] !== null ? (' AND ' . $cols['active'] . ' = 1') : '';
    $sql = "SELECT COUNT(*) AS cnt FROM products WHERE " . $cols['vendor'] . " = ?" . $activeClause;

    $st = $conn->prepare($sql);
    if (!$st) return 0;

    $st->bind_param('i', $vendorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return (int)($row['cnt'] ?? 0);
}

/**
 * Count completed sales for a vendor.
 */
function sfVendorSalesCount($conn, int $vendorId): int
{
    $sql = "SELECT COUNT(*) AS cnt FROM order_items WHERE vendedor_id = ? AND moderation_status != 'rejeitado'";

    $st = $conn->prepare($sql);
    if (!$st) return 0;

    $st->bind_param('i', $vendorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return (int)($row['cnt'] ?? 0);
}

/**
 * List featured sellers for the storefront ranking.
 */
function sfListTopVendors($conn, int $limit = 5): array
{
    $limit = max(1, min(10, $limit));
    _sfBackfillVendorSlugs($conn);

    $sql = "SELECT u.id, u.nome, u.avatar, u.slug,
                   COALESCE(NULLIF(TRIM(sp.nome_loja), ''), u.nome) AS nome_loja,
                   COALESCE(ps.produtos_ativos, 0) AS produtos_ativos,
                   COALESCE(os.vendas_total, 0) AS vendas_total,
                   COALESCE(os.volume_total, 0) AS volume_total
            FROM users u
            LEFT JOIN seller_profiles sp ON sp.user_id = u.id
            LEFT JOIN (
                SELECT vendedor_id, COUNT(*) AS produtos_ativos
                FROM products
                WHERE ativo = 1
                GROUP BY vendedor_id
            ) ps ON ps.vendedor_id = u.id
            LEFT JOIN (
                SELECT vendedor_id,
                       COUNT(*) AS vendas_total,
                       COALESCE(SUM(subtotal), 0) AS volume_total
                FROM order_items
                WHERE moderation_status = 'aprovada'
                GROUP BY vendedor_id
            ) os ON os.vendedor_id = u.id
            WHERE u.ativo = 1
                        AND LOWER(COALESCE(u.email, '')) NOT IN ('karlosenrique368@gmail.com')
                            AND (
                                    u.role = 'vendedor'
                                    OR u.is_vendedor = true
                                    OR u.status_vendedor = 'aprovado'
                                    OR COALESCE(ps.produtos_ativos, 0) > 0
                                    OR COALESCE(os.vendas_total, 0) > 0
                            )
            ORDER BY COALESCE(os.vendas_total, 0) DESC,
                     COALESCE(os.volume_total, 0) DESC,
                     COALESCE(ps.produtos_ativos, 0) DESC,
                     u.id DESC
            LIMIT $limit";

    try {
        $rs = $conn->query($sql);
        return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Avatar URL resolver for vendor.
 */
function sfAvatarUrl(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return 'https://placehold.co/200x200/111827/9ca3af?text=V';
    }
    return uploadsPublicUrl((string)$raw, 'https://placehold.co/200x200/111827/9ca3af?text=V');
}

/**
 * Get the display price HTML for a product card.
 * For dynamic (variant) products, shows "A partir de R$ X,XX" using the lowest variant price.
 * For normal products, shows "R$ X,XX".
 */
function sfDisplayPrice(array $p): string
{
    $tipo = (string)($p['tipo'] ?? 'produto');
    if ($tipo === 'dinamico') {
        if (!empty($p['variantes'])) {
            $vars = is_string($p['variantes']) ? json_decode($p['variantes'], true) : $p['variantes'];
            if (is_array($vars) && count($vars) > 0) {
                $prices = array_map(fn($v) => (float)($v['preco'] ?? 0), $vars);
                $prices = array_filter($prices, fn($pr) => $pr > 0);
                if ($prices) {
                    $uniquePrices = array_values(array_unique(array_map(static fn($pr) => number_format((float)$pr, 2, '.', ''), $prices)));
                    $min = min($prices);
                    $price = 'R$&nbsp;' . number_format($min, 2, ',', '.');
                    if (count($prices) === 1 || count($uniquePrices) === 1) {
                        return $price;
                    }
                    return '<span class="sf-price-prefix">A partir de </span>' . $price;
                }
            }
        }
        return '<span class="text-amber-400">Consultar</span>';
    }
    return 'R$&nbsp;' . number_format((float)($p['preco'] ?? 0), 2, ',', '.');
}
