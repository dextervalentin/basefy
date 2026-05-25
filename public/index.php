<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/storefront.php';
require_once __DIR__ . '/../src/affiliates.php';
require_once __DIR__ . '/../src/upload_paths.php';
require_once __DIR__ . '/../src/media.php';

function homeProductVendorRows($conn, array $filters, int $limit = 40): array
{
    $cols = sfProductColumns($conn);
    if ($cols['vendor'] === null || $cols['category'] === null) return [];

    $where = [];
    if ($cols['active'] !== null) {
        $where[] = 'p.' . $cols['active'] . ' = 1';
    }
    if ($cols['approval_status'] !== null) {
        $where[] = "COALESCE(p." . $cols['approval_status'] . ", 'aprovado') = 'aprovado'";
    }
    $categoryId = (int)($filters['category_id'] ?? 0);
    if ($categoryId > 0) {
        $where[] = 'p.' . $cols['category'] . ' = ' . $categoryId;
    }
    if (!empty($filters['featured_only'])) {
        if ($cols['featured'] === null) return [];
        $where[] = 'p.' . $cols['featured'] . ' = TRUE';
    }

    $whereSql = $where ? implode(' AND ', $where) : '1=1';
    $sql = "SELECT p." . $cols['vendor'] . " AS vendedor_id, MAX(p.id) AS newest_id, COUNT(*) AS total
            FROM products p
            WHERE " . $whereSql . "
            GROUP BY p." . $cols['vendor'] . "
            ORDER BY newest_id DESC
            LIMIT " . max(1, min(100, $limit));
    $rs = $conn->query($sql);
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function homeFindVendorByKeywords($conn, array $keywords): array
{
    foreach ($keywords as $keyword) {
        $keyword = mb_strtolower(trim((string)$keyword));
        if ($keyword === '') continue;
        try {
            $like = '%' . $keyword . '%';
            $st = $conn->prepare(
                "SELECT u.id, COALESCE(NULLIF(TRIM(sp.nome_loja), ''), u.nome) AS display_name
                 FROM users u
                 LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                 WHERE LOWER(COALESCE(sp.nome_loja, '')) LIKE ?
                    OR LOWER(COALESCE(u.nome, '')) LIKE ?
                    OR LOWER(COALESCE(u.email, '')) LIKE ?
                 ORDER BY u.id ASC
                 LIMIT 1"
            );
            if (!$st) continue;
            $st->bind_param('sss', $like, $like, $like);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                return ['id' => (int)$row['id'], 'name' => (string)($row['display_name'] ?? 'LevelUp')];
            }
        } catch (\Throwable $e) {}
    }
    return ['id' => 0, 'name' => 'LevelUp'];
}

function homeLevelUpOfficialBestSellerRules(): array
{
    return [
        ['title' => 'US Perfil Envelhecido de 2023~2025 / 2FA + Cookies / Para Aquecimento / Sem Garantia', 'query' => 'US Perfil Envelhecido de 2023~2025', 'tokens' => ['us', 'perfil', 'envelhecido', '2023', '2025', '2fa', 'cookies', 'aquecimento', 'sem', 'garantia']],
        ['title' => 'Email Outlook/Hotmail/ 2020~2025/ 2FA + Email verificação', 'query' => 'Email Outlook/Hotmail', 'tokens' => ['email', 'outlook', 'hotmail', '2020', '2025', '2fa', 'email', 'verificacao']],
        ['title' => 'Perfil Mix antigo / Sem Fanpages / Com 0~5.000 Amigos / 2FA+Cookies / Não paga imposto meta 13% / Sem Garantia', 'query' => 'Perfil Mix antigo / Sem Fanpages', 'tokens' => ['perfil', 'mix', 'antigo', 'sem', 'fanpages', '5000', 'amigos', '2fa', 'cookies', 'imposto', 'meta', 'sem', 'garantia']],
        ['title' => 'BM0+3 Contas de Anuncio / Fica ilimitada / Acesso via link de convite', 'query' => 'BM0+3 Contas de Anuncio', 'tokens' => ['bm0', '3', 'contas', 'anuncio', 'fica', 'ilimitada', 'acesso', 'link', 'convite']],
        ['title' => 'Perfil Tiktok MIX/ Criado em 2025/ Email Outlook/ Ads disponível', 'query' => 'Perfil Tiktok MIX', 'tokens' => ['perfil', 'tiktok', 'mix', 'criado', '2025', 'email', 'outlook', 'ads', 'disponivel']],
        ['title' => 'US Perfil Envelhecido de 2012 / 300~2K Seguidores / Aquecimento em Publicações / 2FA - Cookies - Proxy 90 Dias', 'query' => 'US Perfil Envelhecido de 2012', 'tokens' => ['us', 'perfil', 'envelhecido', '2012', '300', '2k', 'seguidores', 'aquecimento', 'publicacoes', '2fa', 'cookies', 'proxy', '90', 'dias']],
        ['title' => 'BR Proxy Fixa / Https / Duração até 10 Dias / 24h garantia', 'query' => 'BR Proxy Fixa / Https', 'tokens' => ['br', 'proxy', 'fixa', 'https', 'duracao', '10', 'dias', '24h', 'garantia']],
    ];
}

function homeProductSearchKey(array $product): string
{
    return sfGenerateSlug(trim((string)($product['slug'] ?? '') . ' ' . (string)($product['nome'] ?? '')));
}

function homeProductMatchesRule(array $product, array $rule): bool
{
    $key = homeProductSearchKey($product);
    if ($key === '') return false;

    $ruleSlug = sfGenerateSlug((string)($rule['title'] ?? ''));
    if ($ruleSlug !== '' && str_contains('-' . $key . '-', '-' . $ruleSlug . '-')) {
        return true;
    }

    foreach (($rule['tokens'] ?? []) as $token) {
        if (!str_contains($key, (string)$token)) return false;
    }
    return true;
}

function homeSearchLevelUpRuleProducts($conn, array $rule, int $vendorId = 0): array
{
    $query = trim((string)($rule['query'] ?? $rule['title'] ?? ''));
    if ($query === '') return [];

    $filters = ['limit' => 20, 'q' => $query];
    if ($vendorId > 0) $filters['vendor_id'] = $vendorId;
    return sfListProducts($conn, $filters);
}

function homeListLevelUpBestSellers($conn, int $vendorId, int $limit = 10): array
{
    $vendorProducts = $vendorId > 0 ? sfListProducts($conn, ['limit' => 100, 'vendor_id' => $vendorId]) : [];

    $selected = [];
    $seen = [];
    foreach (homeLevelUpOfficialBestSellerRules() as $rule) {
        $pools = [$vendorProducts, homeSearchLevelUpRuleProducts($conn, $rule, $vendorId)];
        if ($vendorId > 0) $pools[] = homeSearchLevelUpRuleProducts($conn, $rule, 0);

        foreach ($pools as $pool) {
            foreach ($pool as $product) {
                $id = (int)($product['id'] ?? 0);
                if ($id <= 0 || isset($seen[$id]) || !homeProductMatchesRule($product, $rule)) continue;
                $selected[] = $product;
                $seen[$id] = true;
                if ($vendorId <= 0) {
                    $vendorId = (int)($product['vendedor_id'] ?? 0);
                    $vendorProducts = $vendorId > 0 ? sfListProducts($conn, ['limit' => 100, 'vendor_id' => $vendorId]) : [];
                }
                break 2;
            }
        }
        if (count($selected) >= $limit) return $selected;
    }

    if ($vendorId <= 0 || !$vendorProducts) return $selected;

    $fallbackPools = [
        sfListProducts($conn, ['limit' => 100, 'vendor_id' => $vendorId, 'order' => 'best_sellers']),
        $vendorProducts,
    ];
    foreach ($fallbackPools as $pool) {
        foreach ($pool as $product) {
            $id = (int)($product['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) continue;
            $selected[] = $product;
            $seen[$id] = true;
            if (count($selected) >= $limit) return $selected;
        }
    }

    return $selected;
}

function homeListProductsByVendorRounds($conn, array $filters, int $limit, int $maxPerVendor = 2): array
{
    $buckets = [];
    foreach (homeProductVendorRows($conn, $filters, 60) as $vendor) {
        $vendorId = (int)($vendor['vendedor_id'] ?? 0);
        if ($vendorId <= 0) continue;
        $vendorFilters = $filters;
        $vendorFilters['vendor_id'] = $vendorId;
        $vendorFilters['limit'] = $maxPerVendor;
        $rows = sfListProducts($conn, $vendorFilters);
        if ($rows) $buckets[] = $rows;
    }

    $result = [];
    $seen = [];
    for ($round = 0; $round < $maxPerVendor; $round++) {
        foreach ($buckets as $bucket) {
            if (!isset($bucket[$round])) continue;
            $id = (int)($bucket[$round]['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) continue;
            $result[] = $bucket[$round];
            $seen[$id] = true;
            if (count($result) >= $limit) return $result;
        }
    }

    foreach (sfListProducts($conn, array_merge($filters, ['limit' => 100])) as $product) {
        $id = (int)($product['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) continue;
        $result[] = $product;
        $seen[$id] = true;
        if (count($result) >= $limit) return $result;
    }
    return $result;
}

function homeCatalogResolveCategory(array $categories, string $slug): ?array
{
    $wanted = mb_strtolower(trim($slug));
    if ($wanted === '') return null;
    foreach ($categories as $category) {
        if (mb_strtolower(trim((string)($category['slug'] ?? ''))) === $wanted) {
            return $category;
        }
    }
    return null;
}

function homeCatalogStockMap($conn, array $products): array
{
    $autoIds = [];
    foreach ($products as $product) {
        if (!empty($product['auto_delivery_enabled'])) {
            $autoIds[(int)$product['id']] = true;
        }
    }
    if (!$autoIds) return [];

    $ids = array_keys($autoIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT product_id, COUNT(*) AS c FROM product_stock_items WHERE status='disponivel' AND product_id IN ($placeholders) GROUP BY product_id";
    $stockMap = [];
    if ($stmt = $conn->prepare($sql)) {
        $bind = array_merge([$types], $ids);
        $refs = [];
        foreach ($bind as $index => $value) { $refs[$index] = &$bind[$index]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $stockMap[(int)$row['product_id']] = (int)$row['c'];
            }
        }
        $stmt->close();
    }
    return $stockMap;
}

function homeCatalogStockOf(array $product, array $stockMap): array
{
    $autoDelivery = !empty($product['auto_delivery_enabled']);
    $productId = (int)($product['id'] ?? 0);
    if ($autoDelivery) {
        $quantity = $stockMap[$productId] ?? 0;
    } else {
        $variantsRaw = $product['variantes'] ?? null;
        $variants = is_string($variantsRaw) ? (json_decode($variantsRaw, true) ?: []) : (is_array($variantsRaw) ? $variantsRaw : []);
        $variantStock = 0;
        foreach ($variants as $variant) {
            if (is_array($variant)) $variantStock += (int)($variant['quantidade'] ?? 0);
        }
        $quantity = $variantStock > 0 ? $variantStock : (int)($product['quantidade'] ?? 0);
    }
    return ['qty' => (int)$quantity, 'auto' => $autoDelivery];
}

function homeRenderCatalogProductCard(array $product, callable $stockResolver): string
{
    $stock = $stockResolver($product);
    $productStock = (int)$stock['qty'];
    $autoDelivery = (bool)$stock['auto'];
    $productName = (string)($product['nome'] ?? 'Produto');
    $vendorName = (string)($product['vendedor_nome'] ?? '');
    $productUrl = sfProductUrl($product);
    $productImage = sfImageUrl((string)($product['imagem'] ?? ''));
    $sortPrice = (float)($product['preco'] ?? 0);
    $variantsRaw = $product['variantes'] ?? null;
    $variants = is_string($variantsRaw) ? (json_decode($variantsRaw, true) ?: []) : (is_array($variantsRaw) ? $variantsRaw : []);
    if ($sortPrice <= 0 && $variants) {
        $variantPrices = [];
        foreach ($variants as $variant) {
            if (is_array($variant) && (float)($variant['preco'] ?? 0) > 0) $variantPrices[] = (float)$variant['preco'];
        }
        if ($variantPrices) $sortPrice = min($variantPrices);
    }
    $createdSort = strtotime((string)($product['criado_em'] ?? $product['created_at'] ?? '')) ?: (int)($product['id'] ?? 0);
    $salesSort = (int)($product['sales_total'] ?? $product['vendas_total'] ?? $product['vendas'] ?? $product['sales'] ?? 0);
    $productType = mb_strtolower(trim((string)($product['tipo'] ?? 'produto')));
    $isService = in_array($productType, ['servico', 'serviço', 'service'], true);
    if ($productStock <= 0 && $isService) {
        $stockText = 'Serviço';
        $stockClass = 'text-purple-300/90';
    } elseif ($productStock <= 0 && !$autoDelivery) {
        $stockText = 'Sem estoque';
        $stockClass = 'text-red-400/80';
    } elseif ($autoDelivery && $productStock <= 0) {
        $stockText = 'Digital';
        $stockClass = 'text-zinc-500';
    } elseif ($productStock > 0 && $productStock <= 5) {
        $stockText = 'Últimas ' . $productStock . 'un';
        $stockClass = 'text-amber-400 font-medium';
    } else {
        $stockText = $productStock . ' em estoque';
        $stockClass = 'text-zinc-500';
    }

    ob_start();
    ?>
    <a href="<?= htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8') ?>"
       class="catalog-product group flex items-center gap-2.5 bg-blackx2 border border-white/[0.06] rounded-xl p-2 hover:border-greenx/40 hover:shadow-[0_8px_24px_rgba(136,0,228,0.08)] transition-all duration-200"
       data-name="<?= htmlspecialchars(mb_strtolower($productName), ENT_QUOTES, 'UTF-8') ?>"
       data-vendor="<?= htmlspecialchars(mb_strtolower($vendorName), ENT_QUOTES, 'UTF-8') ?>"
       data-price="<?= htmlspecialchars((string)$sortPrice, ENT_QUOTES, 'UTF-8') ?>"
       data-created="<?= htmlspecialchars((string)$createdSort, ENT_QUOTES, 'UTF-8') ?>"
       data-sales="<?= htmlspecialchars((string)$salesSort, ENT_QUOTES, 'UTF-8') ?>"
       title="<?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>">
        <div class="relative shrink-0 w-12 h-12 rounded-md overflow-hidden bg-blackx">
            <img src="<?= htmlspecialchars($productImage, ENT_QUOTES, 'UTF-8') ?>" alt=""
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
        </div>
        <div class="flex-1 min-w-0">
            <h3 class="text-[12px] sm:text-[13px] font-semibold text-zinc-100 line-clamp-1 leading-tight group-hover:text-greenx transition-colors">
                <?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <div class="flex items-baseline gap-1.5 mt-0.5">
                <span class="text-[12px] sm:text-[13px] font-bold text-white"><?= sfDisplayPrice($product) ?></span>
            </div>
            <div class="flex items-center justify-between gap-2 mt-1">
                <?php if ($vendorName !== ''): ?>
                <span class="flex items-center gap-1 min-w-0 text-[10px] text-zinc-500">
                    <i data-lucide="store" class="w-2.5 h-2.5 text-greenx/80 shrink-0"></i>
                    <span class="truncate"><?= htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8') ?></span>
                </span>
                <?php else: ?><span></span><?php endif; ?>
                <span class="flex items-center gap-1 shrink-0 text-[10px] <?= $stockClass ?>">
                    <i data-lucide="package" class="w-2.5 h-2.5"></i>
                    <?= htmlspecialchars($stockText, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </div>
    </a>
    <?php
    return trim((string)ob_get_clean());
}

function homeBuildCatalogPayload($conn, array $categories, string $catSlug, string $query, int $page, int $perPage): array
{
    $activeCategory = homeCatalogResolveCategory($categories, $catSlug);
    $filters = [];
    $label = 'Todos os produtos';
    $icon = 'layout-grid';
    if ($query !== '') {
        $filters['q'] = $query;
        $label = 'Resultados para "' . $query . '"';
        $icon = 'search';
    } elseif ($activeCategory) {
        $filters['category_id'] = (int)$activeCategory['id'];
        $label = (string)$activeCategory['nome'];
    }

    $total = sfCountProducts($conn, $filters);
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    $products = sfListProducts($conn, array_merge($filters, ['limit' => $perPage, 'offset' => $offset, 'order' => 'best_sellers']));
    $stockMap = homeCatalogStockMap($conn, $products);
    $stockResolver = static fn(array $product): array => homeCatalogStockOf($product, $stockMap);
    $html = '';
    foreach ($products as $product) {
        $html .= homeRenderCatalogProductCard($product, $stockResolver);
    }

    return [
        'ok' => true,
        'cat' => $activeCategory ? (string)$activeCategory['slug'] : '',
        'label' => $label,
        'icon' => $icon,
        'sub' => $total . ' produtos disponíveis',
        'html' => $html,
        'page' => $page,
        'perPage' => $perPage,
        'shown' => min($total, $page * $perPage),
        'total' => $total,
        'hasMore' => ($page * $perPage) < $total,
    ];
}

$userId     = (int)($_SESSION['user_id'] ?? 0);
$userRole   = (string)($_SESSION['user']['role'] ?? 'usuario');
$isLoggedIn = $userId > 0;

$conn = (new Database())->connect();

// Affiliate referral tracking
affHandleReferral($conn);

$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'add_cart') {
    $varPost = trim((string)($_POST['variante'] ?? ''));
    sfCartAdd((int)($_POST['product_id'] ?? 0), max(1, (int)($_POST['qty'] ?? 1)), $varPost !== '' ? $varPost : null);
    if ((string)($_POST['redirect_to'] ?? '') === 'carrinho') {
        header('Location: ' . BASE_PATH . '/carrinho');
        exit;
    }
    $feedback = 'Produto adicionado ao carrinho!';
}

$q          = trim((string)($_GET['q'] ?? ''));
$destaques = [];
if ($q !== '') {
    $destaques = sfListProducts($conn, ['limit' => 10, 'q' => $q]);
} else {
    $destaques = homeListProductsByVendorRounds($conn, ['featured_only' => true], 10, 2);
    if (!$destaques) {
        $destaques = homeListProductsByVendorRounds($conn, [], 10, 2);
    }
}
$populares  = [];
$spotlightVendor = homeFindVendorByKeywords($conn, ['levelup', 'level up']);
$spotlightVendorId = (int)$spotlightVendor['id'];
$spotlightVendorName = (string)$spotlightVendor['name'];
$populares = homeListLevelUpBestSellers($conn, $spotlightVendorId, 10);
$spotlightSubtitle = $spotlightVendorId > 0
    ? 'Mais vendidos da ' . $spotlightVendorName
    : 'Produtos da LevelUp';
$categorias = array_values(array_filter(
    sfListCategories($conn),
    fn($cat) => strtolower(trim((string)($cat['tipo'] ?? ''))) !== 'blog'
));
$homeCategorias = array_slice($categorias, 0, 12);

// === Catalog (Commit D): sidebar all-categories + horizontal list cards + live search ===
require_once dirname(__DIR__) . '/src/stock_items.php';

$catalogCatSlug = trim((string)($_GET['cat'] ?? ''));
$catalogQuery = trim((string)($_GET['q'] ?? ''));
$catalogPage = max(1, (int)($_GET['page'] ?? 1));
$catalogPerPage = 24;
$catalogActiveCategory = homeCatalogResolveCategory($categorias, $catalogCatSlug);

// Counts per category use the same storefront filters as the visible list.
$catalogCounts = [];
$catalogTotalCount = sfCountProducts($conn);
foreach ($categorias as $catItem) {
    $_cid = (int)($catItem['id'] ?? 0);
    if ($_cid <= 0) continue;
    $_n = sfCountProducts($conn, ['category_id' => $_cid]);
    if ($_n > 0) $catalogCounts[$_cid] = $_n;
}

$catalogPayload = homeBuildCatalogPayload($conn, $categorias, $catalogCatSlug, $catalogQuery, $catalogPage, $catalogPerPage);
if ((string)($_GET['ajax'] ?? '') === 'catalog') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($catalogPayload, JSON_UNESCAPED_UNICODE);
    exit;
}

$homeFeaturedCategorySetting = sfHomeSettingGet($conn, 'featured_category_id', '');
$homeFeaturedCategory = null;
if ($homeFeaturedCategorySetting !== '') {
    $wantedCategoryId = (int)$homeFeaturedCategorySetting;
    foreach ($categorias as $cat) {
        if ((int)($cat['id'] ?? 0) === $wantedCategoryId) {
            $homeFeaturedCategory = $cat;
            break;
        }
    }
} else {
    foreach ($categorias as $cat) {
        $catName = mb_strtolower(trim((string)($cat['nome'] ?? '')));
        $catSlug = mb_strtolower(trim((string)($cat['slug'] ?? '')));
        if ($catSlug === 'ativos-meta' || $catName === 'ativos meta') {
            $homeFeaturedCategory = $cat;
            break;
        }
    }
}
$homeFeaturedProducts = $homeFeaturedCategory
    ? homeListProductsByVendorRounds($conn, ['category_id' => (int)$homeFeaturedCategory['id']], 5, 2)
    : [];
$topVendedores = sfListTopVendors($conn, 5);
$homeBannerPcPath = __DIR__ . '/assets/img/home-banner-pc-20260511.png';
$homeBannerMobilePath = __DIR__ . '/assets/img/home-banner-mobile-20260511.png';
$homeBannerPcUrl = BASE_PATH . '/assets/img/home-banner-pc-20260511.png' . (is_file($homeBannerPcPath) ? '?v=' . filemtime($homeBannerPcPath) : '');
$homeBannerMobileUrl = BASE_PATH . '/assets/img/home-banner-mobile-20260511.png' . (is_file($homeBannerMobilePath) ? '?v=' . filemtime($homeBannerMobilePath) : '');
$cartCount  = sfCartCount();

$currentPage = 'home';
$pageTitle   = 'Basefy — Marketplace Digital';

// Affiliate rules for CTA section
$affRulesHome = affRules($conn);

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<style>
/* ============================================================
   HERO — fluid responsive (single source of truth via clamp)
   No more breakpoint cliffs, no more !important wars.
   ============================================================ */
.hero-section {
    padding-top: clamp(96px, 13vh, 150px);
    padding-bottom: clamp(20px, 10vh, 120px);
    min-height: clamp(620px, 96svh, 920px);
    display: flex;
    align-items: center;
}
.hero-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: clamp(20px, 3vw, 40px);
    align-items: center;
}
.hero-text-col { max-width: 760px; position: relative; z-index: 10; }

.hero-badge {
    font-size: clamp(13px, 1.05vw, 22px) !important;
    padding: clamp(7px, 0.7vw, 12px) clamp(14px, 1.2vw, 22px) !important;
    line-height: 1.1 !important;
}
.hero-title {
    font-size: clamp(34px, 4.4vw, 60px) !important;
    line-height: 1.06 !important;
    font-weight: 600 !important;
}
.hero-copy {
    font-size: clamp(15px, 1.6vw, 28px) !important;
    line-height: 1.18 !important;
}

/* Logo container — keep image bounded so it never overflows 768px viewports */
.hero-logo-wrap {
    position: relative;
    aspect-ratio: 1 / 1;
    width: 100%;
    max-height: clamp(360px, 70vh, 920px);
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    overflow: visible;
}
.hero-logo-img {
    width: 170% !important;
    height: 170% !important;
    max-width: clamp(380px, 60vw, 1100px) !important;
    max-height: clamp(380px, 70vh, 1100px) !important;
    object-fit: contain;
    margin: 0 !important;  /* override the lg:-ml-[95px] etc. tailwind utilities */
    transform: translateZ(0);
    /* Glow aplicado via CSS no logo12.png (PNG sem filtro embarcado) */
    filter: drop-shadow(0 0 28px rgba(165, 33, 254, 0.55)) drop-shadow(0 0 60px rgba(217, 70, 239, 0.32)) drop-shadow(-22px 18px 38px rgba(165, 33, 254, 0.30));
}

/* Desktop layout: text + logo side-by-side (Figma proportions) */
@media (min-width: 1024px) {
    .hero-grid {
        grid-template-columns: minmax(380px, 1.3fr) 1fr;
        gap: 0px;
    }
    .hero-logo-wrap {
        justify-content: flex-start;
        /* Logo intentionally taller than the text card (~150px overflow top+bottom, Figma) */
        max-height: clamp(760px, 110vh, 1280px);
        margin-top: clamp(-160px, -12vh, -90px);
        margin-bottom: clamp(-160px, -12vh, -90px);
    }
    .hero-logo-img {
        max-width: clamp(760px, 66vw, 1320px) !important;
        max-height: clamp(760px, 110vh, 1320px) !important;
        margin-left: clamp(-160px, -7vw, -32px) !important;
    }
}

/* Mobile-specific tweaks */
@media (max-width: 1023px) {
    .hero-section { min-height: auto; padding-top: 108px !important; padding-bottom: 0 !important; margin-bottom: -36px; display: block; }
    .hero-grid { display: flex; flex-direction: column; gap: 0; }
    .hero-badge { font-size: 16px !important; }
    .hero-title { font-size: 44px !important; line-height: 112% !important; }
    .hero-copy { font-size: 22px !important; line-height: 118% !important; }
    .hero-logo-wrap { min-height: 0 !important; height: clamp(280px, 52vw, 430px); margin-top: -28px; overflow: hidden; justify-content: center !important; }
    .hero-logo-img { width: clamp(440px, 118vw, 620px) !important; height: clamp(440px, 118vw, 620px) !important; transform: translateX(12%); }
}
@media (max-width: 480px) {
    .hero-section { padding-top: 140px !important; margin-bottom: -50px; }
    .hero-badge { font-size: 14px !important; padding: 8px 14px !important; }
    .hero-title { font-size: 27px !important; line-height: 108% !important; }
    .hero-copy { font-size: 14px !important; line-height: 116% !important; }
    .hero-logo-wrap { height: 500px; margin-top: -385px; margin-left: 158px; opacity: 0.5; }
    .hero-logo-img { width: 430px !important; height: 430px !important; margin-top: -170px !important; margin-left: 50px !important; transform: translateX(11%); }
    .hero-actions { flex-wrap: nowrap !important; gap: 8px !important; }
    .hero-actions a { min-width: 0; padding: 10px 12px !important; font-size: 11px !important; white-space: nowrap; }
    .hero-actions i { width: 14px !important; height: 14px !important; }
    .hero-trust-strip { flex-wrap: nowrap !important; overflow: hidden; }
    .hero-trust-strip.text-\[12px\] { font-size: 10px !important; }
    .hero-trust-strip.gap-x-5 { column-gap: 14px !important; }
    .hero-trust-strip span { white-space: nowrap; }
}

.home-banner-frame {
    display: block;
    width: 100%;
    overflow: hidden;
    border-radius: 1.25rem;
}
.home-banner-picture {
    display: block;
    width: 100%;
    overflow: hidden;
    border-radius: inherit;
}
.home-banner-image {
    display: block;
    width: 100%;
    height: auto;
}
</style>

<div class="min-h-screen bg-blackx">

    <!-- =========== HERO — BASEFY PREMIUM =========== -->
    <section class="hero-section relative overflow-hidden">
        <!-- Layered atmospheric backdrop -->
        <div class="absolute inset-0 bg-[#07000f]"></div>
        <!-- Gradient mesh -->
        <div class="absolute inset-0 pointer-events-none opacity-90" style="background:
            radial-gradient(900px 600px at 78% 22%, rgba(165,33,254,.28), transparent 60%),
            radial-gradient(700px 500px at 12% 78%, rgba(217,70,239,.18), transparent 65%),
            radial-gradient(500px 380px at 55% 50%, rgba(56,189,248,.10), transparent 70%);"></div>
        <!-- Subtle grid -->
        <div class="absolute inset-0 opacity-[0.06] pointer-events-none" style="background-image:linear-gradient(rgba(168,85,247,.35) 1px,transparent 1px),linear-gradient(90deg,rgba(168,85,247,.35) 1px,transparent 1px);background-size:64px 64px;mask-image:radial-gradient(ellipse 80% 60% at 50% 50%, #000 30%, transparent 100%)"></div>
        <!-- Grain overlay (SVG noise, base64) -->
        <div class="absolute inset-0 opacity-[0.045] pointer-events-none mix-blend-overlay" style="background-image:url(&quot;data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='240' height='240'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='3' stitchTiles='stitch'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>&quot;)"></div>
        <!-- Floating orbs -->
        <div class="hero-orb-1 absolute top-[24%] right-[14%] w-[420px] h-[420px] bg-purple-600/22 blur-[120px] pointer-events-none rounded-full"></div>
        <div class="hero-orb-2 absolute bottom-[18%] left-[8%] w-[320px] h-[320px] bg-fuchsia-500/14 blur-[110px] pointer-events-none rounded-full"></div>
        <!-- Bottom fade -->
        <div class="absolute bottom-0 left-0 right-0 h-28 bg-gradient-to-t from-blackx to-transparent pointer-events-none"></div>

        <div class="relative w-full max-w-[1440px] mx-auto px-4 sm:px-6">
            <div class="hero-grid">
                <div class="hero-text-col hero-reveal">
                    <div class="hero-badge inline-flex items-center rounded-full bg-purple-500/10 text-zinc-200 shadow-lg shadow-purple-500/10" style="font-family:Gotham,Montserrat,sans-serif;font-weight:300;letter-spacing:0;border:0.85px solid #BE5DFF;backdrop-filter:blur(8px);">
                        Marketplace nº 1 de ativos digitais
                    </div>

                    <h1 class="hero-title mt-5 text-white" style="font-family:Gotham,Montserrat,sans-serif;letter-spacing:-0.01em;">
                        <span class="block">Compre e venda <span style="color:#A521FE">ativos</span></span>
                        <span class="block"><span style="color:#A521FE">digitais</span> com pagamento</span>
                        <span class="block">protegido</span>
                    </h1>

                    <p class="hero-copy mt-5 text-zinc-400" style="font-family:Gotham,Montserrat,sans-serif;font-weight:325;letter-spacing:0;max-width: 39ch;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                        Contas, serviços e muito mais. Pagamento via escrow, liberado só após confirmação da entrega.
                    </p>

                    <div class="hero-actions mt-7 flex flex-wrap items-center gap-3">
                        <a href="<?= BASE_PATH ?>/categorias" class="group inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-purple-500 to-fuchsia-500 px-6 py-3.5 text-sm sm:text-base font-bold text-white shadow-xl shadow-purple-600/30 hover:shadow-purple-600/50 hover:scale-[1.02] active:scale-[0.98] transition-all">
                            Buscar produtos
                            <i data-lucide="arrow-right" class="w-4 h-4 sm:w-5 sm:h-5 transition-transform group-hover:translate-x-0.5"></i>
                        </a>
                        <a href="<?= BASE_PATH ?>/categorias" class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.04] backdrop-blur-md px-5 py-3.5 text-sm font-semibold text-zinc-200 hover:bg-white/[0.08] hover:border-white/25 transition-all">
                            <i data-lucide="layout-grid" class="w-4 h-4"></i>
                            Explorar categorias
                        </a>
                    </div>

                    <!-- Inline trust strip (visible above the fold) -->
                    <div class="hero-trust-strip mt-8 flex flex-wrap items-center gap-x-5 gap-y-2 text-[12px] text-zinc-400">
                        <span class="inline-flex items-center gap-1.5"><i data-lucide="shield-check" class="w-3.5 h-3.5 text-emerald-400"></i> Escrow ativo</span>
                        <span class="inline-flex items-center gap-1.5"><i data-lucide="zap" class="w-3.5 h-3.5 text-amber-400"></i> PIX instantâneo</span>
                        <span class="inline-flex items-center gap-1.5"><i data-lucide="badge-check" class="w-3.5 h-3.5 text-violet-400"></i> KYC obrigatório</span>
                    </div>
                </div>

                <div class="hero-logo-wrap">
                    <img src="<?= BASE_PATH ?>/assets/img/logo12.png" alt="" class="hero-logo-img hero-reveal" style="animation-delay:.18s;">
                </div>
            </div>
        </div>
    </section>

    <!-- =========== TRUST MARQUEE =========== -->
    <section class="trust-marquee-section relative overflow-hidden border-y border-white/[0.05]">
        <!-- Edge gradient masks -->
        <div class="trust-mask-l pointer-events-none absolute inset-y-0 left-0 z-10 w-24 sm:w-40"></div>
        <div class="trust-mask-r pointer-events-none absolute inset-y-0 right-0 z-10 w-24 sm:w-40"></div>
        <div class="trust-marquee flex items-center gap-12 sm:gap-20 whitespace-nowrap" style="padding:10px 0;">
            <?php
            $trustItems = [
                ['icon' => 'shield-check',   'label' => 'Dados criptografados',   'tone' => 'emerald'],
                ['icon' => 'undo-2',         'label' => 'Reembolso garantido',    'tone' => 'sky'],
                ['icon' => 'badge-check',    'label' => 'KYC obrigatório',        'tone' => 'violet'],
                ['icon' => 'zap',            'label' => 'Entrega imediata',       'tone' => 'amber'],
                ['icon' => 'lock',           'label' => 'Escrow seguro',          'tone' => 'emerald'],
                ['icon' => 'wallet',         'label' => 'PIX instantâneo',        'tone' => 'fuchsia'],
                ['icon' => 'headset',        'label' => 'Suporte 24h',            'tone' => 'rose'],
                ['icon' => 'sparkles',       'label' => 'Ofertas em tempo real',  'tone' => 'sky'],
            ];
            ?>
            <?php for ($ti = 0; $ti < 3; $ti++): ?>
            <div class="flex items-center gap-12 sm:gap-20 shrink-0 trust-marquee-track" aria-hidden="<?= $ti > 0 ? 'true' : 'false' ?>">
                <?php foreach ($trustItems as $tItem): ?>
                <div class="marquee-item tone-<?= $tItem['tone'] ?> flex items-center gap-2.5 text-[13px] sm:text-sm font-medium">
                    <span class="marquee-icon-wrap">
                        <i data-lucide="<?= $tItem['icon'] ?>" class="w-4 h-4"></i>
                    </span>
                    <span><?= htmlspecialchars($tItem['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endfor; ?>
        </div>
    </section>

    <?php if ($feedback !== ''): ?>
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 mt-8 mb-6 relative z-10 animate-scale-in">
        <div class="flex items-center gap-3 rounded-2xl border border-greenx/30 bg-greenx/[0.06] backdrop-blur-sm px-5 py-3.5">
            <div class="w-8 h-8 rounded-full bg-greenx/20 flex items-center justify-center flex-shrink-0">
                <i data-lucide="check" class="w-4 h-4 text-greenx"></i>
            </div>
            <p class="text-sm text-greenx"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========== CATALOGO — Sidebar todas categorias + horizontal cards + busca live (Commit D) =========== -->
    <section id="catalogo" class="max-w-[1440px] mx-auto px-3 sm:px-6 py-8 sm:py-12">
        <div class="catalog-shell grid gap-4 sm:gap-5 lg:grid-cols-[200px_minmax(0,1fr)] items-start" data-catalog data-active-cat="<?= htmlspecialchars((string)($catalogPayload['cat'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

            <!-- ============ SIDEBAR (desktop fixed / mobile = popup overlay) ============ -->
            <aside id="catalogSidebar" class="catalog-sidebar hidden lg:block rounded-2xl border border-white/[0.06] bg-blackx2/70 backdrop-blur-sm p-2.5 self-start lg:sticky lg:top-24">
                <div class="flex items-center justify-between px-1.5 mb-3">
                    <p class="text-sm font-bold text-white leading-tight">Categorias</p>
                    <button type="button" class="lg:hidden text-zinc-500 hover:text-white" onclick="catalogClosePopup()" aria-label="Fechar">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
                <ul class="catalog-cat-list flex flex-col gap-0.5 max-h-[70vh] overflow-y-auto pr-0.5">
                    <li>
                                <a href="<?= BASE_PATH ?>/#catalogo" data-catalog-cat=""
                                    class="cat-filter-link <?= (!$catalogActiveCategory && $catalogQuery === '') ? 'is-active' : '' ?> flex items-center justify-between gap-2 px-2.5 py-2 rounded-lg text-[12px] font-medium transition-all text-zinc-300 hover:bg-white/[0.04] hover:text-white border border-transparent">
                            <span class="flex items-center gap-1.5 truncate">
                                <i data-lucide="package" class="w-3.5 h-3.5 shrink-0"></i>
                                <span class="truncate">Todos</span>
                            </span>
                            <span class="text-[10px] font-bold text-zinc-500 shrink-0"><?= (int)$catalogTotalCount ?></span>
                        </a>
                    </li>
                    <?php
                    // Map known category names to lucide icons
                    $catIconMap = [
                        'meta' => 'facebook', 'facebook' => 'facebook',
                        'google' => 'chrome', 'google ads' => 'chrome', 'g-ads' => 'chrome',
                        'tiktok' => 'music', 'tik tok' => 'music',
                        'gmail' => 'mail', 'email' => 'mail', 'outlook' => 'mail', 'mail' => 'mail',
                        'instagram' => 'instagram',
                        'proxy' => 'globe', 'vpn' => 'globe',
                        'shopify' => 'shopping-bag',
                        'ia' => 'sparkles', 'inteligencia artificial' => 'sparkles',
                        'youtube' => 'youtube',
                        'whatsapp' => 'message-circle',
                    ];
                    foreach ($categorias as $catItem):
                        $_cid = (int)($catItem['id'] ?? 0);
                        $_cslug = trim((string)($catItem['slug'] ?? ''));
                        $_cname = (string)($catItem['nome'] ?? '');
                        if ($_cid <= 0 || $_cslug === '') continue;
                        $_count = $catalogCounts[$_cid] ?? 0;
                        $_isActive = ($catalogActiveCategory && (int)$catalogActiveCategory['id'] === $_cid);
                        $_iconKey = mb_strtolower(trim($_cname));
                        $_icon = $catIconMap[$_iconKey] ?? 'tag';
                        if ($_icon === 'tag') {
                            foreach ($catIconMap as $kk => $vv) {
                                if ($kk && str_contains($_iconKey, $kk)) { $_icon = $vv; break; }
                            }
                        }
                    ?>
                    <li>
                                <a href="<?= BASE_PATH ?>/?cat=<?= htmlspecialchars($_cslug, ENT_QUOTES, 'UTF-8') ?>#catalogo" data-catalog-cat="<?= htmlspecialchars($_cslug, ENT_QUOTES, 'UTF-8') ?>"
                                    class="cat-filter-link <?= $_isActive ? 'is-active' : '' ?> flex items-center justify-between gap-2 px-2.5 py-2 rounded-lg text-[12px] font-medium transition-all text-zinc-300 hover:bg-white/[0.04] hover:text-white border border-transparent">
                            <span class="flex items-center gap-1.5 min-w-0">
                                <i data-lucide="<?= $_icon ?>" class="w-3.5 h-3.5 shrink-0"></i>
                                <span class="truncate"><?= htmlspecialchars($_cname, ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                            <span class="text-[10px] font-bold text-zinc-500 shrink-0"><?= $_count ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </aside>

            <!-- ============ RIGHT COLUMN: search + sections ============ -->
            <div class="min-w-0">
                <!-- Search input (live filter) -->
                <div class="relative mb-4">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500 pointer-events-none"></i>
                          <input type="text" id="catalogSearch" placeholder="Buscar produtos..." value="<?= htmlspecialchars($catalogQuery, ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full bg-blackx2/70 border border-white/[0.06] rounded-xl pl-10 pr-10 py-2.5 sm:py-3 text-[13px] sm:text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-greenx/50 transition-colors">
                    <button type="button" id="catalogSearchClear" class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-white" aria-label="Limpar">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>

                <div class="catalog-mobile-filter lg:hidden flex items-center gap-2 mb-4">
                    <label class="relative flex-1 min-w-0">
                        <i data-lucide="filter" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-300 pointer-events-none"></i>
                        <select id="catalogMobileSort" class="w-full h-11 rounded-xl bg-blackx2 border border-white/[0.08] pl-10 pr-9 text-[13px] font-medium text-zinc-200 outline-none focus:border-greenx/45 appearance-none">
                            <option value="recentes">Mais Recentes</option>
                            <option value="vendidos">Mais Vendidos</option>
                            <option value="menor">Menor Preço</option>
                            <option value="maior">Maior Preço</option>
                        </select>
                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 pointer-events-none"></i>
                    </label>
                    <button type="button" onclick="catalogOpenPopup()" class="w-11 h-11 rounded-xl bg-blackx2 border border-white/[0.08] text-zinc-300 flex items-center justify-center hover:border-greenx/40 hover:text-white transition-all" aria-label="Abrir categorias">
                        <i data-lucide="menu" class="w-5 h-5"></i>
                    </button>
                </div>

                <!-- Sections container -->
                <div id="catalogSections" class="flex flex-col gap-6">
                    <section class="catalog-section" data-section-key="catalog">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="flex items-center gap-1.5 px-2 py-1 rounded-md bg-greenx/10 text-greenx border border-greenx/20">
                                <i id="catalogSectionIcon" data-lucide="<?= htmlspecialchars((string)($catalogPayload['icon'] ?? 'layout-grid'), ENT_QUOTES, 'UTF-8') ?>" class="w-3.5 h-3.5"></i>
                                <h2 id="catalogSectionTitle" class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider"><?= htmlspecialchars((string)($catalogPayload['label'] ?? 'Todos os produtos'), ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                            <span id="catalogSectionSub" class="text-[11px] text-zinc-500 truncate"><?= htmlspecialchars((string)($catalogPayload['sub'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <div class="flex-1 h-px bg-white/[0.06]"></div>
                        </div>

                        <div class="catalog-grid-frame relative">
                            <div id="catalogProductsGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2" data-products-grid>
                                <?= $catalogPayload['html'] ?? '' ?>
                            </div>
                            <div class="catalog-loading-overlay" aria-hidden="true">
                                <div class="catalog-loading-pill">
                                    <span class="catalog-loading-spinner"></span>
                                    <span>Atualizando produtos</span>
                                </div>
                            </div>
                        </div>

                        <div id="catalogPager" class="<?= !empty($catalogPayload['hasMore']) ? '' : 'hidden' ?> mt-5 flex flex-col sm:flex-row items-center justify-between gap-3 rounded-2xl border border-white/[0.06] bg-blackx2/70 px-4 py-3">
                            <span id="catalogPageInfo" class="text-xs text-zinc-500"><?= (int)($catalogPayload['shown'] ?? 0) ?> de <?= (int)($catalogPayload['total'] ?? 0) ?> produtos</span>
                            <button type="button" id="catalogLoadMore" class="inline-flex items-center justify-center gap-2 rounded-xl bg-greenx hover:bg-greenx2 text-white font-bold px-4 py-2.5 text-xs transition-colors">
                                Mostrar mais produtos
                                <i data-lucide="chevron-down" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </section>

                    <div id="catalogEmptyState" class="<?= (int)($catalogPayload['total'] ?? 0) > 0 ? 'hidden' : '' ?> rounded-2xl border border-white/[0.06] bg-blackx2 p-10 sm:p-14 text-center">
                        <div class="w-14 h-14 rounded-2xl bg-white/[0.04] border border-white/[0.06] flex items-center justify-center mx-auto mb-4">
                            <i data-lucide="package-open" class="w-6 h-6 text-zinc-600"></i>
                        </div>
                        <h3 class="text-base font-semibold text-zinc-300">Nenhum produto encontrado</h3>
                        <p class="text-sm text-zinc-500 mt-2">Tente outra categoria ou limpe a busca.</p>
                    </div>

                    <!-- No results from live search -->
                    <div id="catalogNoResults" class="hidden rounded-2xl border border-white/[0.06] bg-blackx2 p-8 text-center">
                        <i data-lucide="search-x" class="w-6 h-6 text-zinc-600 mx-auto mb-2"></i>
                        <p class="text-sm text-zinc-400">Nada encontrado com este termo.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile category drawer + backdrop (same pattern as top nav) -->
                <div id="catalogSidebarBackdrop" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm lg:hidden transition-opacity duration-300" style="opacity:0" onclick="catalogClosePopup()"></div>
    </section>

    <style>
      .cat-filter-link.is-active { background: rgba(168, 85, 247, .18); color: #d8b4fe; border-color: rgba(168, 85, 247, .35); box-shadow: 0 0 18px rgba(136,0,228,.18); }
            .catalog-grid-frame { min-height: 220px; }
            .catalog-shell.is-loading #catalogProductsGrid { opacity: .34; transform: scale(.995); transition: opacity .18s ease, transform .18s ease; }
            .catalog-loading-overlay { position: absolute; inset: -.25rem; z-index: 5; display: flex; align-items: center; justify-content: center; border-radius: 1rem; background: rgba(4, 1, 10, .50); backdrop-filter: blur(5px); opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .18s ease, visibility .18s ease; }
            .catalog-shell.is-loading .catalog-loading-overlay { opacity: 1; visibility: visible; pointer-events: auto; }
            .catalog-loading-pill { display: inline-flex; align-items: center; gap: .65rem; border: 1px solid rgba(168, 85, 247, .35); background: rgba(12, 4, 24, .92); color: #f5f3ff; border-radius: 999px; padding: .7rem .95rem; font-size: .75rem; font-weight: 800; box-shadow: 0 18px 45px rgba(0, 0, 0, .42); }
            .catalog-loading-spinner { width: 1rem; height: 1rem; border-radius: 999px; border: 2px solid rgba(255,255,255,.22); border-top-color: #a855f7; animation: catalogSpin .7s linear infinite; }
            #catalogSidebarBackdrop { z-index: 1000000; }
            @keyframes catalogSpin { to { transform: rotate(360deg); } }
      @media (max-width: 1023px) {
        .catalog-sidebar {
                    position: fixed !important; top: 0 !important; right: 0; bottom: 0 !important;
          width: 320px; max-width: 88vw; height: 100vh; height: 100dvh; max-height: none;
                    z-index: 1000001; border-radius: 0; padding: 1rem; overflow-y: auto;
          background: #0b0414; border-left: 1px solid rgba(255,255,255,.06);
          box-shadow: -16px 0 40px rgba(0,0,0,.55);
          transform: translateX(100%); transition: transform .3s ease-out;
          opacity: 1; visibility: visible; pointer-events: none;
        }
        .catalog-sidebar.is-open { display: block !important; transform: translateX(0); pointer-events: auto; }
        .catalog-sidebar .catalog-cat-list { max-height: calc(100dvh - 76px); }
                .catalog-loading-overlay {
                    position: fixed; left: 0; right: 0; top: calc(env(safe-area-inset-top, 0px) + 92px); bottom: auto;
                    z-index: 1000002; height: 0; border-radius: 0;
                    align-items: flex-start; background: transparent; backdrop-filter: none; pointer-events: none !important;
                }
                .catalog-loading-pill {
                    position: relative; left: auto; top: auto; transform: none;
                    white-space: nowrap; max-width: calc(100vw - 2rem);
                    animation: catalogLoadingDrop .22s cubic-bezier(.22,1,.36,1);
                }
                @keyframes catalogLoadingDrop { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
      }
      .catalog-product.is-hidden { display: none; }
      .catalog-section.is-empty { display: none; }
    </style>

    <script>
    (function(){
      'use strict';
            var root = document.querySelector('[data-catalog]');
            var input = document.getElementById('catalogSearch');
            var clearBtn = document.getElementById('catalogSearchClear');
            var sortSelect = document.getElementById('catalogMobileSort');
            var noRes  = document.getElementById('catalogNoResults');
      var sidebar = document.getElementById('catalogSidebar');
      var backdrop = document.getElementById('catalogSidebarBackdrop');
            var grid = document.getElementById('catalogProductsGrid');
            var title = document.getElementById('catalogSectionTitle');
            var subtitle = document.getElementById('catalogSectionSub');
            var icon = document.getElementById('catalogSectionIcon');
            var pager = document.getElementById('catalogPager');
            var pageInfo = document.getElementById('catalogPageInfo');
            var loadMore = document.getElementById('catalogLoadMore');
            var emptyState = document.getElementById('catalogEmptyState');
      if (!input) return;
            var basePath = <?= json_encode(BASE_PATH, JSON_UNESCAPED_SLASHES) ?>;
            var state = { cat: root ? (root.getAttribute('data-active-cat') || '') : '', page: 1, query: input.value.trim() };
            var loading = false;
            var searchTimer = null;
            var closeTimer = null;
            var sidebarParent = sidebar ? sidebar.parentNode : null;
            var sidebarNext = sidebar ? sidebar.nextSibling : null;
            var backdropParent = backdrop ? backdrop.parentNode : null;
            var backdropNext = backdrop ? backdrop.nextSibling : null;

            function lockPageScroll(){
                document.body.style.overflow = 'hidden';
                document.documentElement.style.overflow = 'hidden';
            }

            function unlockPageScroll(){
                document.body.style.overflow = '';
                document.documentElement.style.overflow = '';
            }

            function moveDrawerToBody(){
                if (sidebar && sidebar.parentNode !== document.body) document.body.appendChild(sidebar);
                if (backdrop && backdrop.parentNode !== document.body) document.body.appendChild(backdrop);
            }

            function restoreDrawerPosition(){
                if (sidebar && sidebarParent && sidebar.parentNode === document.body) sidebarParent.insertBefore(sidebar, sidebarNext);
                if (backdrop && backdropParent && backdrop.parentNode === document.body) backdropParent.insertBefore(backdrop, backdropNext);
            }

            function sortCatalog(){
                if (!sortSelect || !grid) return;
                var mode = sortSelect.value || 'recentes';
                var cards = Array.prototype.slice.call(grid.querySelectorAll('.catalog-product'));
                cards.sort(function(first, second){
                    var firstPrice = parseFloat(first.getAttribute('data-price') || '0');
                    var secondPrice = parseFloat(second.getAttribute('data-price') || '0');
                    var firstCreated = parseFloat(first.getAttribute('data-created') || '0');
                    var secondCreated = parseFloat(second.getAttribute('data-created') || '0');
                    var firstSales = parseFloat(first.getAttribute('data-sales') || '0');
                    var secondSales = parseFloat(second.getAttribute('data-sales') || '0');
                    if (mode === 'menor') return firstPrice - secondPrice;
                    if (mode === 'maior') return secondPrice - firstPrice;
                    if (mode === 'vendidos') return secondSales - firstSales;
                    return secondCreated - firstCreated;
                });
                cards.forEach(function(card){ grid.appendChild(card); });
            }

            function setActiveCategory(cat){
                document.querySelectorAll('[data-catalog-cat]').forEach(function(link){
                    link.classList.toggle('is-active', (link.getAttribute('data-catalog-cat') || '') === cat);
                });
            }

            function catalogUrl(){
                var params = new URLSearchParams();
                if (state.cat) params.set('cat', state.cat);
                if (state.query) params.set('q', state.query);
                var query = params.toString();
                return basePath + '/' + (query ? '?' + query : '') + '#catalogo';
            }

            function updatePager(data){
                if (pageInfo) pageInfo.textContent = data.shown + ' de ' + data.total + ' produtos';
                if (pager) pager.classList.toggle('hidden', !data.hasMore);
                if (loadMore) loadMore.disabled = !data.hasMore;
            }

            function applyCatalog(data, append){
                state.cat = data.cat || '';
                state.page = parseInt(data.page || '1', 10) || 1;
                if (title) title.textContent = data.label || 'Todos os produtos';
                if (subtitle) subtitle.textContent = data.sub || '';
                if (icon) icon.setAttribute('data-lucide', data.icon || 'layout-grid');
                if (grid) {
                    if (append) grid.insertAdjacentHTML('beforeend', data.html || '');
                    else grid.innerHTML = data.html || '';
                }
                if (emptyState) emptyState.classList.toggle('hidden', parseInt(data.total || '0', 10) > 0);
                if (noRes) noRes.classList.add('hidden');
                setActiveCategory(state.query ? '__none__' : state.cat);
                updatePager(data);
                sortCatalog();
                if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
            }

            function fetchCatalog(options){
                options = options || {};
                if (loading || !grid) return;
                var append = !!options.append;
                if (typeof options.cat === 'string') state.cat = options.cat;
                if (typeof options.query === 'string') state.query = options.query;
                state.page = append ? state.page + 1 : 1;
                clearBtn.classList.toggle('hidden', !state.query);
                var params = new URLSearchParams();
                params.set('ajax', 'catalog');
                params.set('page', String(state.page));
                if (state.cat) params.set('cat', state.cat);
                if (state.query) params.set('q', state.query);
                loading = true;
                if (root) root.classList.add('is-loading');
                if (loadMore) loadMore.disabled = true;
                fetch(basePath + '/?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(response){ return response.json(); })
                    .then(function(data){
                        if (!data || !data.ok) throw new Error('catalog');
                        applyCatalog(data, append);
                        if (options.updateUrl && window.history && window.history.replaceState) window.history.replaceState(null, '', catalogUrl());
                    })
                    .catch(function(){ if (noRes) noRes.classList.remove('hidden'); })
                    .finally(function(){
                        loading = false;
                        if (root) root.classList.remove('is-loading');
                        if (loadMore) loadMore.disabled = false;
                    });
            }

            document.addEventListener('click', function(event){
                var link = event.target.closest && event.target.closest('[data-catalog-cat]');
                if (!link) return;
                event.preventDefault();
                event.stopPropagation();
                input.value = '';
                fetchCatalog({ cat: link.getAttribute('data-catalog-cat') || '', query: '', updateUrl: true });
                window.catalogClosePopup();
                var anchor = document.getElementById('catalogo');
                if (anchor) anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, true);
            input.addEventListener('input', function(){
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function(){ fetchCatalog({ query: input.value.trim(), updateUrl: true }); }, 260);
            });
            clearBtn.addEventListener('click', function(){ input.value = ''; fetchCatalog({ query: '', updateUrl: true }); input.focus(); });
            if (loadMore) loadMore.addEventListener('click', function(){ fetchCatalog({ append: true, updateUrl: false }); });
            if (sortSelect) sortSelect.value = 'vendidos';
            if (sortSelect) sortSelect.addEventListener('change', sortCatalog);

            window.catalogOpenPopup = function(){
                if (!sidebar) return;
                window.clearTimeout(closeTimer);
                moveDrawerToBody();
                sidebar.classList.remove('hidden');
                if (backdrop){ backdrop.classList.remove('hidden'); }
                lockPageScroll();
                window.requestAnimationFrame(function(){
                    sidebar.classList.add('is-open');
                    if (backdrop) backdrop.style.opacity = '1';
                });
            };
            window.catalogClosePopup = function(){
                if (!sidebar) return;
                window.clearTimeout(closeTimer);
                sidebar.classList.remove('is-open');
                unlockPageScroll();
                if (backdrop){ backdrop.style.opacity = '0'; }
                closeTimer = window.setTimeout(function(){
                    if (backdrop) backdrop.classList.add('hidden');
                    if (!sidebar.classList.contains('is-open') && window.innerWidth < 1024) sidebar.classList.add('hidden');
                    restoreDrawerPosition();
                }, 300);
            };
            document.addEventListener('keydown', function(e){ if (e.key === 'Escape') window.catalogClosePopup(); });
    })();
    </script>


    <?php if ($q === ''): ?>
    <!-- =========== HOME BANNER =========== -->
    <section class="max-w-[1440px] mx-auto px-4 sm:px-6 py-6 sm:py-8">
        <a href="<?= BASE_PATH ?>/categorias" class="home-banner-frame group border border-white/[0.06] bg-blackx2 shadow-lg shadow-black/20">
            <picture class="home-banner-picture">
                <source media="(max-width: 639px)" srcset="<?= htmlspecialchars($homeBannerMobileUrl, ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($homeBannerPcUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Banner promocional da Basefy" class="home-banner-image transition-transform duration-700 group-hover:scale-[1.01]" decoding="async" fetchpriority="high">
            </picture>
        </a>
    </section>
    <?php endif; ?>

    <?php if ($q === ''): ?>
    <!-- =========== COMUNIDADE BASEFY =========== -->
    <section class="max-w-[1440px] mx-auto px-4 sm:px-6 pb-6 sm:pb-8">
        <a href="https://chat.whatsapp.com/J71bKVW5CG4Ht1tH51diah?mode=gi_t" target="_blank" rel="noopener" class="promo-banner group relative overflow-hidden rounded-2xl border border-emerald-500/25 bg-gradient-to-r from-emerald-500/[0.08] via-blackx2 to-blackx2 p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6 hover:border-emerald-400/50 transition-all duration-300 hover:-translate-y-0.5">
            <div class="absolute -left-20 top-1/2 -translate-y-1/2 w-72 h-72 bg-emerald-500/[0.08] rounded-full blur-3xl pointer-events-none"></div>
            <div class="shrink-0 w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-emerald-500/20 border border-emerald-400/40 flex items-center justify-center">
                <i data-lucide="message-circle" class="w-7 h-7 text-emerald-300"></i>
            </div>
            <div class="relative min-w-0 flex-1">
                <h3 class="text-lg sm:text-xl font-bold text-white leading-tight">Comunidade <span class="text-emerald-300">Basefy</span></h3>
                <p class="text-[13px] sm:text-sm text-zinc-400 mt-1 leading-relaxed max-w-3xl">Entre para o grupo oficial da Basefy e conecte-se com pessoas<br class="hidden lg:block">do mercado de tráfego, performance e ativos digitais.</p>
            </div>
            <span class="shrink-0 inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500/90 hover:bg-emerald-400 text-white font-bold px-5 py-2.5 text-[13px] shadow-lg shadow-emerald-500/30 transition-all">
                Acessar grupo <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </span>
        </a>
    </section>
    <?php endif; ?>

    <?php if ($topVendedores && $q === ''): ?>
    <!-- =========== RANKING DE VENDEDORES =========== -->
    <section class="max-w-[1440px] mx-auto px-4 sm:px-6 py-10 sm:py-14">
        <div class="flex items-center justify-between mb-8 sm:mb-10">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-greenx/10 border border-greenx/20 text-greenx text-[11px] font-bold uppercase tracking-wider mb-3">
                    <i data-lucide="trophy" class="w-3 h-3"></i>
                    Ranking
                </div>
                <h2 class="text-2xl sm:text-3xl font-bold">Vendedores em destaque</h2>
                <p class="text-sm text-zinc-500 mt-1">Top 5 lojas com melhor desempenho na Basefy</p>
            </div>
            <a href="<?= BASE_PATH ?>/categorias" class="hidden sm:inline-flex items-center gap-1.5 text-xs text-greenx hover:underline font-semibold">Explorar lojas <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i></a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">
            <?php foreach ($topVendedores as $i => $vendor):
                $rank = $i + 1;
                $vendorName = trim((string)($vendor['nome_loja'] ?? $vendor['nome'] ?? 'Vendedor'));
                $salesTotal = (int)($vendor['vendas_total'] ?? 0);
                $productTotal = (int)($vendor['produtos_ativos'] ?? 0);
            ?>
            <a href="<?= htmlspecialchars(sfVendorUrl($vendor), ENT_QUOTES, 'UTF-8') ?>" class="group relative overflow-hidden rounded-2xl border border-white/[0.06] bg-blackx2 p-4 transition-all duration-300 hover:-translate-y-1 hover:border-greenx/30 hover:shadow-2xl hover:shadow-greenx/[0.06]">
                <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500" style="background:radial-gradient(420px 220px at 50% 0%, rgba(var(--t-accent-rgb),0.12), transparent 65%);"></div>
                <div class="relative flex items-start justify-between gap-3">
                    <span class="inline-flex h-8 min-w-8 items-center justify-center rounded-xl <?= $rank === 1 ? 'bg-amber-400 text-black' : 'bg-white/[0.06] text-zinc-300 border border-white/[0.08]' ?> text-xs font-black">#<?= $rank ?></span>
                    <?php if ($rank === 1): ?>
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-400/10 border border-amber-400/20 px-2 py-1 text-[10px] font-bold text-amber-300"><i data-lucide="crown" class="w-3 h-3"></i> Top</span>
                    <?php endif; ?>
                </div>
                <div class="relative mt-5 flex items-center gap-3">
                    <img src="<?= htmlspecialchars(sfAvatarUrl((string)($vendor['avatar'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" alt="" class="h-12 w-12 rounded-2xl border border-white/[0.08] object-cover bg-blackx" loading="lazy">
                    <div class="min-w-0">
                        <h3 class="truncate text-sm font-bold text-white group-hover:text-greenx transition-colors"><?= htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="mt-1 text-xs text-zinc-500"><?= sfUserKycVerified($conn, (int)($vendor['id'] ?? 0)) ? 'Vendedor verificado' : 'Vendedor' ?></p>
                    </div>
                </div>
                <div class="relative mt-5 grid grid-cols-2 gap-2 text-center">
                    <div class="rounded-xl border border-white/[0.06] bg-blackx/60 px-2 py-2">
                        <p class="text-sm font-black text-greenx"><?= $salesTotal ?></p>
                        <p class="text-[10px] text-zinc-500">vendas</p>
                    </div>
                    <div class="rounded-xl border border-white/[0.06] bg-blackx/60 px-2 py-2">
                        <p class="text-sm font-black text-zinc-200"><?= $productTotal ?></p>
                        <p class="text-[10px] text-zinc-500">produtos</p>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- =========== COMO FUNCIONA — Timeline flow =========== -->
    <?php if ($q === ''): ?>
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_80%_50%_at_50%_50%,rgba(var(--t-accent-rgb),0.05),transparent)]"></div>
        <div class="relative max-w-[1440px] mx-auto px-4 sm:px-6 py-16 sm:py-24">
            <div class="text-center mb-12 sm:mb-16">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-greenx/10 border border-greenx/20 text-greenx text-[11px] font-bold uppercase tracking-wider mb-4">
                    <i data-lucide="info" class="w-3 h-3"></i>
                    Simples e rápido
                </div>
                <h2 class="text-2xl sm:text-4xl font-black">Como funciona?</h2>
                <p class="text-sm sm:text-base text-zinc-500 mt-3 max-w-lg mx-auto">Três passos simples para comprar com total segurança</p>
            </div>

            <div class="relative max-w-5xl mx-auto">
                <!-- Connecting line (desktop) -->
                <div class="hidden sm:block absolute top-[60px] left-[16.5%] right-[16.5%] h-px bg-gradient-to-r from-transparent via-greenx/20 to-transparent"></div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 sm:gap-8">
                    <div class="relative group text-center">
                        <div class="como-icon relative z-10 w-[72px] h-[72px] rounded-2xl bg-greenx/10 border-2 border-greenx/30 flex items-center justify-center mx-auto mb-6 group-hover:bg-greenx group-hover:border-greenx group-hover:scale-110 transition-all duration-300 shadow-lg shadow-greenx/10">
                            <i data-lucide="search" class="w-7 h-7 text-greenx como-icon-svg transition-colors"></i>
                            <div class="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-greenx text-white flex items-center justify-center text-[11px] font-black shadow-lg shadow-greenx/40">1</div>
                        </div>
                        <h3 class="text-lg font-bold mb-2">Escolha o produto</h3>
                        <p class="text-sm text-zinc-500 leading-relaxed max-w-[240px] mx-auto">Navegue pelo catálogo e encontre exatamente o que precisa</p>
                    </div>
                    <div class="relative group text-center">
                        <div class="como-icon relative z-10 w-[72px] h-[72px] rounded-2xl bg-greenx/10 border-2 border-greenx/30 flex items-center justify-center mx-auto mb-6 group-hover:bg-greenx group-hover:border-greenx group-hover:scale-110 transition-all duration-300 shadow-lg shadow-greenx/10">
                            <i data-lucide="qr-code" class="w-7 h-7 text-greenx como-icon-svg transition-colors"></i>
                            <div class="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-greenx text-white flex items-center justify-center text-[11px] font-black shadow-lg shadow-greenx/40">2</div>
                        </div>
                        <h3 class="text-lg font-bold mb-2">Pague via PIX</h3>
                        <p class="text-sm text-zinc-500 leading-relaxed max-w-[240px] mx-auto">Confirmação automática e valor protegido por Escrow</p>
                    </div>
                    <div class="relative group text-center">
                        <div class="como-icon relative z-10 w-[72px] h-[72px] rounded-2xl bg-greenx/10 border-2 border-greenx/30 flex items-center justify-center mx-auto mb-6 group-hover:bg-greenx group-hover:border-greenx group-hover:scale-110 transition-all duration-300 shadow-lg shadow-greenx/10">
                            <i data-lucide="package-check" class="w-7 h-7 text-greenx como-icon-svg transition-colors"></i>
                            <div class="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-greenx text-white flex items-center justify-center text-[11px] font-black shadow-lg shadow-greenx/40">3</div>
                        </div>
                        <h3 class="text-lg font-bold mb-2">Receba seu produto</h3>
                        <p class="text-sm text-zinc-500 leading-relaxed max-w-[240px] mx-auto">Entrega digital automática com garantia total da plataforma</p>
                    </div>
                </div>
            </div>

            <div class="text-center mt-12">
                <a href="<?= BASE_PATH ?>/como_funciona"
                   class="inline-flex items-center gap-2 px-7 py-3.5 rounded-xl bg-greenx/10 border border-greenx/20 text-greenx font-bold text-sm hover:bg-greenx/20 hover:scale-[1.02] transition-all">
                    <i data-lucide="play-circle" class="w-4.5 h-4.5"></i>
                    Saiba mais
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- =========== PRODUTOS EM DESTAQUE =========== -->
    <?php if ($populares && $q === ''): ?>
    <section class="max-w-[1440px] mx-auto px-4 sm:px-6 py-10 sm:py-14">
        <div class="flex items-center justify-between mb-8 sm:mb-10">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-greenx/10 border border-greenx/25 text-greenx text-[11px] font-bold uppercase tracking-wider mb-3">
                    <i data-lucide="sparkles" class="w-3 h-3"></i>
                    Destaques
                </div>
                <h2 class="text-2xl sm:text-3xl font-bold">Produtos em destaque</h2>
                <p class="text-sm text-zinc-500 mt-1"><?= htmlspecialchars($spotlightSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="<?= BASE_PATH ?>/#catalogo" class="hidden sm:inline-flex items-center gap-1.5 text-xs text-greenx hover:underline font-semibold">Ver catálogo <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i></a>
        </div>
        <div class="premium-slider" data-premium-slider>
                    <button type="button" class="premium-arrow premium-arrow-prev" data-premium-prev aria-label="Produtos anteriores" title="Produtos anteriores">
                        <i data-lucide="chevron-left" class="w-5 h-5"></i>
                    </button>
          <div class="premium-track" data-premium-track>
            <?php foreach ($populares as $i => $p): ?>
                    <article class="premium-card product-card group bg-blackx2 border border-white/[0.06] rounded-2xl overflow-hidden flex flex-col hover:border-greenx/30 hover:shadow-2xl hover:shadow-greenx/[0.06] hover:-translate-y-1 transition-all duration-400 animate-fade-in-up stagger-<?= min($i + 1, 6) ?> snap-start">
                <a href="<?= sfProductUrl($p) ?>" class="block relative overflow-hidden">
                    <div class="aspect-square overflow-hidden bg-blackx">
                        <img src="<?= htmlspecialchars(sfImageUrl((string)($p['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                             class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700" loading="lazy">
                    </div>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <span class="absolute top-2 left-2 sm:top-2.5 sm:left-2.5 px-2 py-0.5 sm:px-2.5 sm:py-1 rounded-lg text-[8px] sm:text-[9px] font-bold uppercase tracking-wide bg-greenx text-white shadow-md">
                        <?= htmlspecialchars((string)($p['categoria_nome'] ?? 'Geral'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>
                <button type="button" class="fav-btn absolute top-2 right-2 sm:top-2.5 sm:right-2.5 w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-black/50 backdrop-blur-sm border border-white/10 flex items-center justify-center text-zinc-400 hover:text-red-400 hover:border-red-400/40 hover:bg-red-500/10 transition-all z-10" data-product-id="<?= (int)$p['id'] ?>" title="Favoritar">
                    <i data-lucide="heart" class="w-3 h-3 sm:w-3.5 sm:h-3.5"></i>
                </button>
                <div class="p-1.5 sm:p-4 flex flex-col flex-1">
                    <a href="<?= sfProductUrl($p) ?>" class="font-bold text-[10px] sm:text-sm line-clamp-2 min-h-[2rem] sm:min-h-[2.4rem] leading-snug hover:text-greenx transition-colors block">
                        <?= htmlspecialchars((string)($p['nome'] ?? 'Produto'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php if (!empty($p['vendedor_nome'])): ?>
                    <div class="hidden sm:flex items-center gap-1.5 text-[10px] text-zinc-500 mt-2">
                        <i data-lucide="store" class="w-3 h-3 text-greenx/70 shrink-0"></i>
                        <span class="truncate"><?= htmlspecialchars((string)$p['vendedor_nome'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="mt-auto pt-2">
                        <span class="text-xs sm:text-base font-bold text-greenx whitespace-nowrap"><?= sfDisplayPrice($p) ?></span>
                    </div>
                    <?php
                    $hasVariants2 = (($p['tipo'] ?? '') === 'dinamico' && !empty($p['variantes']));
                    $varsJson2 = $hasVariants2 ? htmlspecialchars(is_string($p['variantes']) ? $p['variantes'] : json_encode($p['variantes']), ENT_QUOTES, 'UTF-8') : '';
                    ?>
                    <form method="post" class="mt-2 sm:mt-3"
                        <?= $hasVariants2 ? 'data-variants="' . $varsJson2 . '" data-product-name="' . htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8') . '" data-product-image="' . htmlspecialchars(sfImageUrl((string)($p['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                        <input type="hidden" name="action" value="add_cart">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="qty" value="1">
                        <input type="hidden" name="redirect_to" value="carrinho">
                        <button class="w-full flex items-center justify-center gap-1 sm:gap-1.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-2 py-2 sm:px-3 sm:py-2.5 text-[10px] sm:text-xs shadow-lg shadow-greenx/15 hover:shadow-greenx/30 hover:scale-[1.02] active:scale-[0.98] transition-all">
                            <i data-lucide="shopping-bag" class="w-3 h-3 sm:w-3.5 sm:h-3.5"></i>
                            Comprar
                        </button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
                    </div>
                    <button type="button" class="premium-arrow premium-arrow-next" data-premium-next aria-label="Próximos produtos" title="Próximos produtos">
                      <i data-lucide="chevron-right" class="w-5 h-5"></i>
                    </button>
                    <div class="premium-dots" data-premium-dots aria-label="Navegação dos produtos em destaque"></div>
        </div>
                <style>
                    .premium-slider { position:relative; overflow:hidden; padding:.15rem .05rem .25rem; }
                    .premium-track { display:flex; gap:1rem; will-change:transform; transform:translate3d(0,0,0); transition:transform .55s cubic-bezier(.22,1,.36,1); touch-action:pan-y; cursor:grab; user-select:none; }
                    .premium-track.is-dragging { transition:none; cursor:grabbing; }
                    .premium-card { flex:0 0 calc((100% - 4rem) / 5); min-width:0; touch-action:pan-y; }
                    .premium-card * { touch-action:pan-y; }
                    .premium-card img, .premium-card a { -webkit-user-drag:none; user-drag:none; }
                    .premium-dots { display:flex; justify-content:center; align-items:center; gap:.45rem; margin-top:1rem; }
                    .premium-dot { width:.5rem; height:.5rem; border-radius:999px; background:rgba(255,255,255,.16); border:0; padding:0; cursor:pointer; transition:width .25s ease, background .25s ease; }
                    .premium-dot.is-active { width:1.5rem; background:var(--t-accent,#8800E4); }
                    .premium-arrow { position:absolute; top:42%; z-index:4; width:2.5rem; height:2.5rem; border-radius:999px; display:flex; align-items:center; justify-content:center; color:#f4f4f5; background:rgba(15,10,25,.78); border:1px solid rgba(255,255,255,.12); box-shadow:0 10px 30px rgba(0,0,0,.28); backdrop-filter:blur(10px); transition:opacity .2s ease, transform .2s ease, border-color .2s ease; }
                    .premium-arrow:hover { border-color:rgba(136,0,228,.55); transform:translateY(-1px); }
                    .premium-arrow:disabled { opacity:.25; cursor:not-allowed; transform:none; }
                    .premium-arrow-prev { left:.35rem; }
                    .premium-arrow-next { right:.35rem; }
                    @media (max-width:1023px){ .premium-card { flex-basis:calc((100% - 2rem) / 3); } }
                    @media (max-width:639px){ .premium-track{ gap:.75rem; } .premium-card { flex-basis:calc((100% - .75rem) / 2); } .premium-arrow{width:2.25rem;height:2.25rem;top:38%;} }
                </style>
                <script>
                (function(){
                    var root = document.querySelector('[data-premium-slider]');
                    if (!root) return;
                    var track = root.querySelector('[data-premium-track]');
                    var dots = root.querySelector('[data-premium-dots]');
                    var prev = root.querySelector('[data-premium-prev]');
                    var next = root.querySelector('[data-premium-next]');
                    var cards = Array.prototype.slice.call(track.querySelectorAll('.premium-card'));
                    if (!track || !dots || !cards.length) return;
                    track.addEventListener('dragstart', function(e){ e.preventDefault(); });
                    track.querySelectorAll('img, a').forEach(function(el){ el.setAttribute('draggable', 'false'); });

                    var index = 0;
                    var dragOffset = 0;

                    function visibleCount(){
                        var width = root.getBoundingClientRect().width;
                        if (width >= 1024) return 5;
                        if (width >= 640) return 3;
                        return 2;
                    }
                    function maxIndex(){ return Math.max(0, cards.length - visibleCount()); }
                    function step(){
                        var gap = parseFloat(getComputedStyle(track).gap || '0') || 0;
                        return (cards[0] ? cards[0].getBoundingClientRect().width : 0) + gap;
                    }
                    function applyTransform(extra){
                        var offset = -(index * step()) + (extra || 0);
                        track.style.transform = 'translate3d(' + offset + 'px, 0, 0)';
                    }
                    function goTo(target, animated){
                        index = Math.max(0, Math.min(maxIndex(), Math.round(target)));
                        if (animated === false) track.style.transition = 'none';
                        applyTransform(0);
                        if (animated === false) {
                            // Force reflow to commit the no-transition state before re-enabling easing.
                            void track.offsetWidth;
                            track.style.transition = '';
                        }
                        updateDots();
                    }
                    function updateDots(){
                        dots.querySelectorAll('.premium-dot').forEach(function(dot, idx){ dot.classList.toggle('is-active', idx === index); });
                        var last = maxIndex();
                        if (prev) prev.disabled = last <= 0 || index <= 0;
                        if (next) next.disabled = last <= 0 || index >= last;
                    }
                    function buildDots(){
                        dots.innerHTML = '';
                        var total = maxIndex() + 1;
                        dots.style.display = total > 1 ? 'flex' : 'none';
                        for (var i = 0; i < total; i++) {
                            (function(page){
                                var dot = document.createElement('button');
                                dot.type = 'button';
                                dot.className = 'premium-dot' + (page === index ? ' is-active' : '');
                                dot.setAttribute('aria-label', 'Ir para slide ' + (page + 1));
                                dot.addEventListener('click', function(){ goTo(page, true); });
                                dots.appendChild(dot);
                            })(i);
                        }
                    }

                    if (prev) prev.addEventListener('click', function(){ goTo(index - 1, true); });
                    if (next) next.addEventListener('click', function(){ goTo(index + 1, true); });

                    var dragging = false, startX = 0, startY = 0, lockedAxis = null, moved = false;
                    track.addEventListener('pointerdown', function(e){
                        if (e.pointerType === 'mouse' && e.button !== 0) return;
                        dragging = true; moved = false; lockedAxis = null;
                        startX = e.clientX; startY = e.clientY; dragOffset = 0;
                        track.classList.add('is-dragging');
                        try { track.setPointerCapture(e.pointerId); } catch (_) {}
                    });
                    track.addEventListener('pointermove', function(e){
                        if (!dragging) return;
                        var dx = e.clientX - startX;
                        var dy = e.clientY - startY;
                        if (!lockedAxis) {
                            if (Math.abs(dx) > 8 || Math.abs(dy) > 8) lockedAxis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
                        }
                        if (lockedAxis !== 'x') return;
                        if (e.cancelable) e.preventDefault();
                        moved = true;
                        dragOffset = dx;
                        applyTransform(dx);
                    });
                    function endDrag(e){
                        if (!dragging) return;
                        dragging = false;
                        track.classList.remove('is-dragging');
                        var threshold = step() * 0.18;
                        if (lockedAxis === 'x' && Math.abs(dragOffset) > threshold) {
                            goTo(index + (dragOffset < 0 ? 1 : -1), true);
                        } else {
                            goTo(index, true);
                        }
                        dragOffset = 0;
                    }
                    track.addEventListener('pointerup', endDrag);
                    track.addEventListener('pointercancel', endDrag);
                    track.addEventListener('click', function(e){ if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);

                    var resizeTimer = null;
                    window.addEventListener('resize', function(){
                        window.clearTimeout(resizeTimer);
                        resizeTimer = window.setTimeout(function(){ buildDots(); goTo(Math.min(index, maxIndex()), false); }, 120);
                    });

                    buildDots();
                    goTo(0, false);
                })();
                </script>
    </section>
    <?php endif; ?>

    <!-- =========== AFFILIATE PROGRAM CTA =========== -->
    <?php if ($affRulesHome['program_enabled'] && $q === ''): ?>
    <section class="max-w-[1440px] mx-auto px-4 sm:px-6 py-8 sm:py-12">
        <div class="relative overflow-hidden rounded-3xl border border-greenx/20">
            <div class="absolute inset-0 bg-gradient-to-br from-greenx/[0.10] via-blackx2 to-greenxd/[0.10]"></div>
            <div class="absolute -top-20 -right-20 w-80 h-80 bg-greenx/[0.12] rounded-full blur-[120px] pointer-events-none"></div>
            <div class="absolute -bottom-20 -left-20 w-64 h-64 bg-greenxd/[0.10] rounded-full blur-[100px] pointer-events-none"></div>

            <div class="relative p-8 sm:p-10 md:p-12 lg:p-16">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-14 items-center">
                    <div>
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-greenx/10 border border-greenx/20 text-greenx text-xs font-bold mb-6">
                            <i data-lucide="share-2" class="w-3.5 h-3.5"></i>
                            Programa de Afiliados
                        </div>
                        <h2 class="text-3xl sm:text-4xl font-black leading-tight mb-5">
                            Indique e ganhe <span class="text-greenx">dinheiro</span> com cada venda
                        </h2>
                        <p class="text-zinc-400 text-sm sm:text-base leading-relaxed mb-8 max-w-md">
                            Compartilhe seu link exclusivo e receba comissão por cada compra realizada através da sua indicação.
                        </p>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <?php if ($isLoggedIn): ?>
                            <a href="<?= BASE_PATH ?>/afiliados"
                               class="flex items-center justify-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold text-sm shadow-lg shadow-greenx/25 hover:shadow-greenx/40 hover:scale-[1.02] transition-all">
                                <i data-lucide="rocket" class="w-3.5 h-3.5 mr-1.5 shrink-0"></i> Ser Afiliado
                            </a>
                            <?php else: ?>
                            <a href="<?= BASE_PATH ?>/register"
                               class="flex items-center justify-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold text-sm shadow-lg shadow-greenx/25 hover:shadow-greenx/40 hover:scale-[1.02] transition-all">
                                <i data-lucide="user-plus" class="w-3.5 h-3.5 mr-1.5 shrink-0"></i> Criar conta
                            </a>
                            <?php endif; ?>
                            <a href="<?= BASE_PATH ?>/afiliados"
                               class="flex items-center justify-center px-5 py-2.5 rounded-xl bg-white/[0.06] border border-white/[0.08] text-sm font-medium text-zinc-300 hover:text-white hover:border-white/[0.15] transition-all">
                                Saiba mais
                            </a>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 sm:gap-5">
                        <div class="text-center p-2 sm:p-5 rounded-xl sm:rounded-2xl bg-white/[0.04] border border-white/[0.06] hover:border-greenx/20 transition-all">
                            <div class="w-8 h-8 sm:w-12 sm:h-12 rounded-lg sm:rounded-xl bg-greenx/15 flex items-center justify-center mx-auto mb-1.5 sm:mb-3">
                                <span class="text-greenx font-black text-sm sm:text-lg">1</span>
                            </div>
                            <p class="text-[10px] sm:text-sm font-bold leading-tight">Cadastre-se</p>
                            <p class="text-[8px] sm:text-[10px] text-zinc-500 mt-0.5 sm:mt-1">Grátis e rápido</p>
                        </div>
                        <div class="text-center p-2 sm:p-5 rounded-xl sm:rounded-2xl bg-white/[0.04] border border-white/[0.06] hover:border-greenx/20 transition-all">
                            <div class="w-8 h-8 sm:w-12 sm:h-12 rounded-lg sm:rounded-xl bg-greenx/15 flex items-center justify-center mx-auto mb-1.5 sm:mb-3">
                                <span class="text-greenx font-black text-sm sm:text-lg">2</span>
                            </div>
                            <p class="text-[10px] sm:text-sm font-bold leading-tight">Compartilhe</p>
                            <p class="text-[8px] sm:text-[10px] text-zinc-500 mt-0.5 sm:mt-1">Seu link</p>
                        </div>
                        <div class="text-center p-2 sm:p-5 rounded-xl sm:rounded-2xl bg-white/[0.04] border border-white/[0.06] hover:border-greenx/20 transition-all">
                            <div class="w-8 h-8 sm:w-12 sm:h-12 rounded-lg sm:rounded-xl bg-greenx/15 flex items-center justify-center mx-auto mb-1.5 sm:mb-3">
                                <span class="text-greenx font-black text-sm sm:text-lg">3</span>
                            </div>
                            <p class="text-[10px] sm:text-sm font-bold leading-tight">Receba</p>
                            <p class="text-[8px] sm:text-[10px] text-zinc-500 mt-0.5 sm:mt-1">Via PIX</p>
                        </div>
                        <div class="col-span-3 grid grid-cols-3 gap-2 sm:gap-3 mt-2">
                            <div class="text-center p-2.5 sm:p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.04]">
                                <div class="text-sm sm:text-base font-bold text-greenx">PIX</div>
                                <div class="text-[8px] sm:text-[9px] text-zinc-500 uppercase tracking-wide">Pagamento</div>
                            </div>
                            <div class="text-center p-2.5 sm:p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.04]">
                                <div class="text-sm sm:text-base font-bold text-greenx"><?= (int)$affRulesHome['cookie_days'] ?>d</div>
                                <div class="text-[8px] sm:text-[9px] text-zinc-500 uppercase tracking-wide">Cookie</div>
                            </div>
                            <div class="text-center p-2.5 sm:p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.04]">
                                <div class="text-sm sm:text-base font-bold text-greenx">Grátis</div>
                                <div class="text-[8px] sm:text-[9px] text-zinc-500 uppercase tracking-wide">Cadastro</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- =========== FINAL CTA =========== -->
    <?php if (!$isLoggedIn && $q === ''): ?>
    <section class="max-w-[1440px] mx-auto px-4 sm:px-6 pb-16 sm:pb-20">
        <div class="relative overflow-hidden rounded-3xl border border-white/[0.06] bg-blackx2">
            <div class="absolute top-0 right-0 w-[400px] h-[400px] bg-greenx/[0.10] rounded-full blur-[140px] pointer-events-none"></div>
            <div class="absolute bottom-0 left-0 w-80 h-80 bg-greenxd/[0.06] rounded-full blur-[120px] pointer-events-none"></div>
            <div class="relative p-10 sm:p-12 md:p-16 flex flex-col md:flex-row items-center justify-between gap-10">
                <div>
                    <h2 class="text-3xl sm:text-4xl font-black">Pronto para começar?</h2>
                    <p class="text-zinc-400 mt-3 text-sm sm:text-base leading-relaxed max-w-lg">Crie sua conta gratuita e acesse carteira digital, pagamentos PIX instantâneos e proteção Escrow em cada transação.</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-2 shrink-0 w-full md:w-auto">
                    <a href="<?= BASE_PATH ?>/register"
                       class="flex-1 md:flex-none flex items-center justify-center gap-1.5 px-5 py-2.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-bold text-sm shadow-lg shadow-greenx/25 hover:shadow-greenx/40 hover:scale-[1.02] transition-all">
                        <i data-lucide="rocket" class="w-3.5 h-3.5"></i>
                        Criar conta
                    </a>
                    <a href="<?= BASE_PATH ?>/login"
                       class="flex-1 md:flex-none flex items-center justify-center gap-1.5 px-5 py-2.5 rounded-xl bg-white/[0.06] border border-white/[0.08] text-sm font-medium text-zinc-300 hover:text-white hover:border-white/[0.15] transition-all">
                        Já tenho conta
                    </a>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>
</div>

<!-- Home page custom styles & animations -->
<style>
/* Hero reveal animation */
.hero-reveal {
    opacity: 0;
    transform: translateY(30px);
    animation: heroReveal 0.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes heroReveal {
    to { opacity: 1; transform: translateY(0); }
}

/* Accent text with glow */
.hero-accent-text {
    color: var(--t-accent);
    text-shadow: 0 0 60px rgba(var(--t-accent-rgb), 0.3), 0 0 120px rgba(var(--t-accent-rgb), 0.1);
}

/* Animated orbs */
.hero-orb-1 { animation: orbFloat1 8s ease-in-out infinite; }
.hero-orb-2 { animation: orbFloat2 10s ease-in-out infinite; }
.hero-orb-3 { animation: orbFloat3 12s ease-in-out infinite; }
@keyframes orbFloat1 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(30px, -20px) scale(1.1); }
}
@keyframes orbFloat2 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(-25px, 15px) scale(1.05); }
}
@keyframes orbFloat3 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(20px, 25px) scale(0.95); }
}

/* Scroll indicator */
.scroll-dot {
    animation: scrollDot 2s ease-in-out infinite;
}
@keyframes scrollDot {
    0%, 100% { transform: translateY(0); opacity: 1; }
    50% { transform: translateY(8px); opacity: 0.3; }
}

/* ===== Premium scroll reveal — fade + lift + blur ease-out-expo ===== */
.reveal-init {
    opacity: 0;
    transform: translate3d(0, 18px, 0);
    filter: blur(6px);
    transition:
        opacity .9s cubic-bezier(0.16, 1, 0.3, 1),
        transform .9s cubic-bezier(0.16, 1, 0.3, 1),
        filter .7s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: opacity, transform, filter;
}
.reveal-in {
    opacity: 1;
    transform: translate3d(0, 0, 0);
    filter: blur(0);
}
@media (prefers-reduced-motion: reduce) {
    .reveal-init { opacity: 1; transform: none; filter: none; transition: none; }
}

/* Trust marquee — premium seamless loop with edge masks, pause on hover, varied icons */
.trust-marquee-section {
    background:
        radial-gradient(1200px 60px at 50% 50%, rgba(168,85,247,.05), transparent 70%),
        linear-gradient(to right, rgba(255,255,255,.012), rgba(255,255,255,.025), rgba(255,255,255,.012));
}
.trust-marquee { overflow: hidden; }
.trust-marquee-track {
    animation: marqueeScroll 38s linear infinite;
    will-change: transform;
}
.trust-marquee:hover .trust-marquee-track { animation-play-state: paused; }
@keyframes marqueeScroll {
    0%   { transform: translate3d(0, 0, 0); }
    100% { transform: translate3d(-100%, 0, 0); }
}

/* Edge fade masks */
.trust-mask-l { background: linear-gradient(to right, var(--blackx, #050008) 0%, rgba(5,0,8,.85) 40%, transparent 100%); }
.trust-mask-r { background: linear-gradient(to left,  var(--blackx, #050008) 0%, rgba(5,0,8,.85) 40%, transparent 100%); }
html.light-mode .trust-mask-l { background: linear-gradient(to right, #fafafa 0%, rgba(250,250,250,.85) 40%, transparent 100%); }
html.light-mode .trust-mask-r { background: linear-gradient(to left,  #fafafa 0%, rgba(250,250,250,.85) 40%, transparent 100%); }

/* Marquee item: subtle pill, varied tones, neon glow on icon */
.marquee-item {
    color: rgba(228, 228, 231, .82);
    transition: color .25s ease;
}
html.light-mode .marquee-item { color: #4b5563; }
.marquee-icon-wrap {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px;
    border-radius: 9px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.06);
    transition: background .25s ease, border-color .25s ease, box-shadow .25s ease, transform .25s ease;
}
.marquee-item:hover .marquee-icon-wrap { transform: translateY(-1px); }
.marquee-item:hover { color: #fff; }
.tone-emerald .marquee-icon-wrap { color: #10b981; box-shadow: 0 0 0 1px rgba(16,185,129,.18) inset, 0 0 18px rgba(16,185,129,.22); }
.tone-violet  .marquee-icon-wrap { color: #a855f7; box-shadow: 0 0 0 1px rgba(168,85,247,.20) inset, 0 0 18px rgba(168,85,247,.22); }
.tone-fuchsia .marquee-icon-wrap { color: #d946ef; box-shadow: 0 0 0 1px rgba(217,70,239,.20) inset, 0 0 18px rgba(217,70,239,.22); }
.tone-sky     .marquee-icon-wrap { color: #38bdf8; box-shadow: 0 0 0 1px rgba(56,189,248,.20) inset, 0 0 18px rgba(56,189,248,.22); }
.tone-amber   .marquee-icon-wrap { color: #f59e0b; box-shadow: 0 0 0 1px rgba(245,158,11,.20) inset, 0 0 18px rgba(245,158,11,.22); }
.tone-rose    .marquee-icon-wrap { color: #fb7185; box-shadow: 0 0 0 1px rgba(251,113,133,.20) inset, 0 0 18px rgba(251,113,133,.22); }

@media (prefers-reduced-motion: reduce) {
    .trust-marquee-track { animation: none; }
}

/* Explorar text — visible in dark mode */
.home-explorar-text { color: #a1a1aa; }
html.light-mode .home-explorar-text { color: #4b5563; }

/* Como funciona icon — white SVG on hover */
.group:hover .como-icon .como-icon-svg { color: #fff !important; }
html.light-mode .group:hover .como-icon .como-icon-svg { color: #fff !important; }

/* Category card hover glow */
.cat-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    opacity: 0;
    transition: opacity 0.5s;
    box-shadow: inset 0 0 60px rgba(var(--t-accent-rgb), 0.08);
    pointer-events: none;
    z-index: 1;
}
.cat-card:hover::before { opacity: 1; }

/* Category name pill — always high contrast */
.cat-name-pill {
    text-shadow: 0 1px 8px rgba(0,0,0,0.8);
}

/* Counter animation */
[data-counter] {
    font-variant-numeric: tabular-nums;
}

/* Hide "A partir de" prefix on small screens to keep price on 1 line */
@media (max-width: 639px) {
    .sf-price-prefix { display: none; }
}
</style>

<script>
// Animated counters
document.addEventListener('DOMContentLoaded', function() {
    const counters = document.querySelectorAll('[data-counter]');
    if (!counters.length) return;
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (!entry.isIntersecting) return;
            const el = entry.target;
            const target = parseInt(el.dataset.counter, 10);
            const duration = 2000;
            const start = performance.now();
            function update(now) {
                const elapsed = now - start;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                el.textContent = Math.floor(eased * target).toLocaleString('pt-BR') + '+';
                if (progress < 1) requestAnimationFrame(update);
            }
            requestAnimationFrame(update);
            observer.unobserve(el);
        });
    }, { threshold: 0.3 });
    counters.forEach(function(c) { observer.observe(c); });
});

// Scroll reveal — premium fade+lift+blur as elements enter the viewport.
// Auto-targets section headings, cards, and any [data-reveal] element.
(function() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const targets = new Set();
    document.querySelectorAll('section h2, section .cat-card, section article.product-card, section [data-reveal], .como-icon').forEach(el => targets.add(el));
    if (!targets.size) return;
    targets.forEach(el => el.classList.add('reveal-init'));
    const io = new IntersectionObserver((entries) => {
        entries.forEach((e, idx) => {
            if (!e.isIntersecting) return;
            const el = e.target;
            // Stagger siblings inside the same parent grid/flex
            const sibs = Array.from(el.parentElement?.children || []);
            const i = Math.max(0, sibs.indexOf(el));
            el.style.transitionDelay = Math.min(i, 8) * 60 + 'ms';
            el.classList.add('reveal-in');
            io.unobserve(el);
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
    targets.forEach(el => io.observe(el));
})();
</script>

<?php
include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
