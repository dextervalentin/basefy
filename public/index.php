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
$populares  = sfListProducts($conn, ['limit' => 5, 'order' => 'best_sellers']);
$categorias = array_values(array_filter(
    sfListCategories($conn),
    fn($cat) => strtolower(trim((string)($cat['tipo'] ?? ''))) !== 'blog'
));
$homeCategorias = array_slice($categorias, 0, 12);

// === Catalog (Commit D): sidebar all-categories + horizontal list cards + live search ===
require_once dirname(__DIR__) . '/src/stock_items.php';

$catalogCatSlug = trim((string)($_GET['cat'] ?? ''));
$catalogCatId = 0;
$catalogActiveCategory = null;
if ($catalogCatSlug !== '') {
    foreach ($categorias as $catItem) {
        if (mb_strtolower(trim((string)($catItem['slug'] ?? ''))) === mb_strtolower($catalogCatSlug)) {
            $catalogActiveCategory = $catItem;
            $catalogCatId = (int)($catItem['id'] ?? 0);
            break;
        }
    }
}

// Counts per category — dynamic column names via sfProductColumns
$catalogCounts = [];
try {
    $_pcols = sfProductColumns($conn);
    $_catCol = $_pcols['category'] ?? 'categoria_id';
    $_actCol = $_pcols['active'] ?? null;
    $_apprCol = $_pcols['approval_status'] ?? null;
    $_w = [];
    if ($_actCol)  $_w[] = "COALESCE($_actCol,1)=1";
    if ($_apprCol) $_w[] = "COALESCE($_apprCol,'aprovado')='aprovado'";
    $_whereSql = $_w ? ('WHERE ' . implode(' AND ', $_w)) : '';
    $_sql = "SELECT $_catCol AS cat_id, COUNT(*) AS total FROM products $_whereSql GROUP BY $_catCol";
    if ($res = $conn->query($_sql)) {
        while ($r = $res->fetch_assoc()) { $catalogCounts[(int)$r['cat_id']] = (int)$r['total']; }
    }
} catch (Throwable $e) { $catalogCounts = []; }

// Catalog sections
$catalogSections = []; // [ ['key'=>..., 'label'=>..., 'icon'=>..., 'sub'=>..., 'products'=>[...], 'category'=>?, 'href'=>?] ]
if ($q !== '') {
    $catalogSections[] = [
        'key' => 'busca', 'label' => 'Resultados para "' . $q . '"', 'icon' => 'search',
        'sub' => '', 'products' => sfListProducts($conn, ['limit' => 40, 'q' => $q]),
    ];
} elseif ($catalogActiveCategory) {
    $catalogSections[] = [
        'key' => 'cat-' . (int)$catalogActiveCategory['id'],
        'label' => (string)$catalogActiveCategory['nome'],
        'icon' => 'layout-grid', 'sub' => 'Produtos desta categoria',
        'products' => sfListProducts($conn, ['limit' => 40, 'category_id' => (int)$catalogActiveCategory['id']]),
        'category' => $catalogActiveCategory,
    ];
} else {
    // Novidades (recém-adicionados, ORDER BY id DESC já é default)
    $catalogSections[] = [
        'key' => 'novidades', 'label' => 'Novidades', 'icon' => 'rocket',
        'sub' => 'Recém-adicionados',
        'products' => sfListProducts($conn, ['limit' => 8]),
    ];
    // Mais vendidos
    $catalogSections[] = [
        'key' => 'mais-vendidos', 'label' => 'Mais vendidos', 'icon' => 'flame',
        'sub' => 'Os favoritos da galera',
        'products' => sfListProducts($conn, ['limit' => 8, 'order' => 'best_sellers']),
    ];
    // Per-category sections (skip empty)
    foreach ($categorias as $catItem) {
        $_cid = (int)($catItem['id'] ?? 0);
        if ($_cid <= 0 || (($catalogCounts[$_cid] ?? 0) === 0)) continue;
        $_prods = sfListProducts($conn, ['limit' => 8, 'category_id' => $_cid]);
        if (!$_prods) continue;
        $catalogSections[] = [
            'key' => 'cat-' . $_cid, 'label' => (string)$catItem['nome'], 'icon' => 'tag',
            'sub' => '(' . ($catalogCounts[$_cid] ?? count($_prods)) . ')',
            'products' => $_prods, 'category' => $catItem,
        ];
    }
}

// Batch resolve real stock for auto-delivery products in all sections
$catalogStockMap = [];
$_autoIds = [];
foreach ($catalogSections as $sec) {
    foreach ($sec['products'] as $pp) {
        if (!empty($pp['auto_delivery_enabled'])) $_autoIds[(int)$pp['id']] = true;
    }
}
if ($_autoIds) {
    $_ids = array_keys($_autoIds);
    $_in = implode(',', array_fill(0, count($_ids), '?'));
    $_types = str_repeat('i', count($_ids));
    $_sql = "SELECT product_id, COUNT(*) AS c FROM product_stock_items WHERE status='disponivel' AND product_id IN ($_in) GROUP BY product_id";
    if ($st = $conn->prepare($_sql)) {
        $_bind = array_merge([$_types], $_ids);
        $_refs = [];
        foreach ($_bind as $k => $v) { $_refs[$k] = &$_bind[$k]; }
        call_user_func_array([$st, 'bind_param'], $_refs);
        if ($st->execute()) {
            $rr = $st->get_result();
            while ($row = $rr->fetch_assoc()) { $catalogStockMap[(int)$row['product_id']] = (int)$row['c']; }
        }
        $st->close();
    }
}
// Helper to compute display stock for a product
$catalogStockOf = function(array $p) use ($catalogStockMap): array {
    $auto = !empty($p['auto_delivery_enabled']);
    $pid  = (int)($p['id'] ?? 0);
    $qty  = $auto ? ($catalogStockMap[$pid] ?? 0) : (int)($p['quantidade'] ?? 0);
    return ['qty' => $qty, 'auto' => $auto];
};

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
                        <span class="inline-flex items-center gap-1.5"><i data-lucide="badge-check" class="w-3.5 h-3.5 text-violet-400"></i> Vendedores verificados</span>
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
                ['icon' => 'badge-check',    'label' => 'Vendedores verificados', 'tone' => 'violet'],
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
        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-xl bg-greenx/15 border border-greenx/30 flex items-center justify-center shrink-0">
                <i data-lucide="badge-check" class="w-5 h-5 text-greenx"></i>
            </div>
            <div class="min-w-0">
                <h1 class="text-xl sm:text-2xl font-bold leading-tight">Loja Oficial</h1>
                <p class="text-[11px] sm:text-xs text-zinc-500 leading-tight">Produtos exclusivos da comunidade</p>
            </div>
        </div>

        <div class="catalog-shell grid gap-4 sm:gap-5 lg:grid-cols-[200px_minmax(0,1fr)] items-start" data-catalog>

            <!-- ============ SIDEBAR (desktop fixed / mobile = popup overlay) ============ -->
            <aside id="catalogSidebar" class="catalog-sidebar rounded-2xl border border-white/[0.06] bg-blackx2/70 backdrop-blur-sm p-2.5 self-start lg:sticky lg:top-24">
                <div class="flex items-center justify-between px-1.5 mb-2">
                    <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.08em] text-zinc-400">
                        <i data-lucide="sliders-horizontal" class="w-3 h-3 text-greenx"></i> Categorias
                    </div>
                    <button type="button" class="lg:hidden text-zinc-500 hover:text-white" onclick="catalogClosePopup()" aria-label="Fechar">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
                <ul class="catalog-cat-list flex flex-col gap-0.5 max-h-[70vh] overflow-y-auto pr-0.5">
                    <li>
                        <a href="<?= BASE_PATH ?>/"
                           class="cat-filter-link flex items-center justify-between gap-2 px-2.5 py-2 rounded-lg text-[12px] font-medium transition-all <?= (!$catalogActiveCategory && $q === '') ? 'bg-greenx/15 text-greenx border border-greenx/30 shadow-[0_0_18px_rgba(136,0,228,0.18)]' : 'text-zinc-300 hover:bg-white/[0.04] hover:text-white border border-transparent' ?>">
                            <span class="flex items-center gap-1.5 truncate">
                                <i data-lucide="package" class="w-3.5 h-3.5 shrink-0"></i>
                                <span class="truncate">Todos</span>
                            </span>
                            <span class="text-[10px] font-bold text-zinc-500 shrink-0"><?= array_sum($catalogCounts) ?></span>
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
                        <a href="<?= BASE_PATH ?>/?cat=<?= htmlspecialchars($_cslug, ENT_QUOTES, 'UTF-8') ?>#catalogo"
                           class="cat-filter-link flex items-center justify-between gap-2 px-2.5 py-2 rounded-lg text-[12px] font-medium transition-all <?= $_isActive ? 'bg-greenx/15 text-greenx border border-greenx/30 shadow-[0_0_18px_rgba(136,0,228,0.18)]' : 'text-zinc-300 hover:bg-white/[0.04] hover:text-white border border-transparent' ?>">
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
                    <input type="text" id="catalogSearch" placeholder="Buscar produtos..."
                           class="w-full bg-blackx2/70 border border-white/[0.06] rounded-xl pl-10 pr-10 py-2.5 sm:py-3 text-[13px] sm:text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-greenx/50 transition-colors">
                    <button type="button" id="catalogSearchClear" class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-white" aria-label="Limpar">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>

                <!-- Sections container -->
                <div id="catalogSections" class="flex flex-col gap-6">
                    <?php foreach ($catalogSections as $sec):
                        $secKey = (string)$sec['key'];
                        $secProducts = $sec['products'] ?? [];
                        if (!$secProducts) continue;
                    ?>
                    <section class="catalog-section" data-section-key="<?= htmlspecialchars($secKey, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="flex items-center gap-1.5 px-2 py-1 rounded-md bg-greenx/10 text-greenx border border-greenx/20">
                                <i data-lucide="<?= htmlspecialchars((string)($sec['icon'] ?? 'tag'), ENT_QUOTES, 'UTF-8') ?>" class="w-3.5 h-3.5"></i>
                                <h2 class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wider"><?= htmlspecialchars((string)$sec['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                            <?php if (!empty($sec['sub'])): ?>
                            <span class="text-[11px] text-zinc-500 truncate"><?= htmlspecialchars((string)$sec['sub'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <div class="flex-1 h-px bg-white/[0.06]"></div>
                            <?php if (!empty($sec['category'])): ?>
                            <a href="<?= BASE_PATH ?>/?cat=<?= htmlspecialchars((string)$sec['category']['slug'], ENT_QUOTES, 'UTF-8') ?>#catalogo" class="text-[11px] font-semibold text-greenx hover:underline shrink-0 hidden sm:inline">Ver mais</a>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2">
                            <?php foreach ($secProducts as $p):
                                $st = $catalogStockOf($p);
                                $pStock = (int)$st['qty'];
                                $pAuto = (bool)$st['auto'];
                                $pName = (string)($p['nome'] ?? 'Produto');
                                $pVendor = (string)($p['vendedor_nome'] ?? '');
                                $pUrl = sfProductUrl($p);
                                $pImg = sfImageUrl((string)($p['imagem'] ?? ''));
                                // stock badge — last few / muted
                                if ($pStock <= 0 && !$pAuto) {
                                    $stockTxt = 'Sem estoque'; $stockCls = 'text-red-400/80';
                                } elseif ($pAuto && $pStock <= 0) {
                                    $stockTxt = 'Digital'; $stockCls = 'text-zinc-500';
                                } elseif ($pStock > 0 && $pStock <= 5) {
                                    $stockTxt = 'Últimas ' . $pStock . 'un'; $stockCls = 'text-amber-400 font-medium';
                                } else {
                                    $stockTxt = $pStock . ' em estoque'; $stockCls = 'text-zinc-500';
                                }
                            ?>
                            <a href="<?= htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8') ?>"
                               class="catalog-product group flex items-center gap-2.5 bg-blackx2 border border-white/[0.06] rounded-xl p-2 hover:border-greenx/40 hover:shadow-[0_8px_24px_rgba(136,0,228,0.08)] transition-all duration-200"
                               data-name="<?= htmlspecialchars(mb_strtolower($pName), ENT_QUOTES, 'UTF-8') ?>"
                               data-vendor="<?= htmlspecialchars(mb_strtolower($pVendor), ENT_QUOTES, 'UTF-8') ?>"
                               title="<?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="relative shrink-0 w-12 h-12 rounded-md overflow-hidden bg-blackx">
                                    <img src="<?= htmlspecialchars($pImg, ENT_QUOTES, 'UTF-8') ?>" alt=""
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-[12px] sm:text-[13px] font-semibold text-zinc-100 line-clamp-1 leading-tight group-hover:text-greenx transition-colors">
                                        <?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8') ?>
                                    </h3>
                                    <div class="flex items-baseline gap-1.5 mt-0.5">
                                        <span class="text-[12px] sm:text-[13px] font-bold text-white"><?= sfDisplayPrice($p) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 mt-1">
                                        <?php if ($pVendor !== ''): ?>
                                        <span class="flex items-center gap-1 min-w-0 text-[10px] text-zinc-500">
                                            <i data-lucide="store" class="w-2.5 h-2.5 text-greenx/80 shrink-0"></i>
                                            <span class="truncate"><?= htmlspecialchars($pVendor, ENT_QUOTES, 'UTF-8') ?></span>
                                        </span>
                                        <?php else: ?><span></span><?php endif; ?>
                                        <span class="flex items-center gap-1 shrink-0 text-[10px] <?= $stockCls ?>">
                                            <i data-lucide="package" class="w-2.5 h-2.5"></i>
                                            <?= htmlspecialchars($stockTxt, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endforeach; ?>

                    <!-- Empty state -->
                    <?php if (!$catalogSections || (count($catalogSections) === 1 && empty($catalogSections[0]['products']))): ?>
                    <div class="rounded-2xl border border-white/[0.06] bg-blackx2 p-10 sm:p-14 text-center">
                        <div class="w-14 h-14 rounded-2xl bg-white/[0.04] border border-white/[0.06] flex items-center justify-center mx-auto mb-4">
                            <i data-lucide="package-open" class="w-6 h-6 text-zinc-600"></i>
                        </div>
                        <h3 class="text-base font-semibold text-zinc-300">Nenhum produto encontrado</h3>
                        <p class="text-sm text-zinc-500 mt-2">Tente outra categoria ou limpe a busca.</p>
                        <a href="<?= BASE_PATH ?>/" class="inline-flex mt-5 rounded-xl bg-white/[0.06] border border-white/[0.08] px-5 py-2.5 text-sm text-zinc-300 hover:text-white hover:border-greenx/40 transition-all">Ver tudo</a>
                    </div>
                    <?php endif; ?>

                    <!-- No results from live search -->
                    <div id="catalogNoResults" class="hidden rounded-2xl border border-white/[0.06] bg-blackx2 p-8 text-center">
                        <i data-lucide="search-x" class="w-6 h-6 text-zinc-600 mx-auto mb-2"></i>
                        <p class="text-sm text-zinc-400">Nada encontrado com este termo.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Floating button (mobile) — opens sidebar popup -->
        <button type="button" id="catalogSidebarToggle" onclick="catalogOpenPopup()" class="lg:hidden fixed left-0 top-1/2 -translate-y-1/2 z-40 w-9 h-16 rounded-r-xl bg-greenx/90 backdrop-blur text-white shadow-lg shadow-greenx/40 flex items-center justify-center border border-l-0 border-greenx/60" aria-label="Abrir categorias">
            <i data-lucide="chevrons-right" class="w-5 h-5"></i>
        </button>

        <!-- Backdrop for mobile popup -->
        <div id="catalogSidebarBackdrop" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-40 lg:hidden" onclick="catalogClosePopup()"></div>
    </section>

    <style>
      /* Sidebar popup animation (mobile only) */
      @media (max-width: 1023px) {
        .catalog-sidebar { position: fixed; left: 12px; top: 50%; transform: translate(-110%, -50%) scale(.92); opacity: 0; width: min(260px, 78vw); max-height: 80vh; z-index: 50; transition: transform .28s cubic-bezier(.2,.9,.3,1.2), opacity .22s ease; box-shadow: 0 24px 60px -12px rgba(0,0,0,.6), 0 0 0 1px rgba(136,0,228,.2); pointer-events: none; }
        .catalog-sidebar.is-open { transform: translate(0, -50%) scale(1); opacity: 1; pointer-events: auto; }
        #catalogSidebarBackdrop.is-open { display: block; animation: catBackdropIn .25s ease; }
        @keyframes catBackdropIn { from { opacity: 0; } to { opacity: 1; } }
      }
      .catalog-product.is-hidden { display: none; }
      .catalog-section.is-empty { display: none; }
    </style>

    <script>
    (function(){
      'use strict';
      var input = document.getElementById('catalogSearch');
      var clearBtn = document.getElementById('catalogSearchClear');
      var noRes  = document.getElementById('catalogNoResults');
      var sidebar = document.getElementById('catalogSidebar');
      var backdrop = document.getElementById('catalogSidebarBackdrop');
      if (!input) return;

      function applyFilter(){
        var q = (input.value || '').toLowerCase().trim();
        clearBtn.classList.toggle('hidden', !q);
        var sections = document.querySelectorAll('.catalog-section');
        var totalShown = 0;
        sections.forEach(function(sec){
          var cards = sec.querySelectorAll('.catalog-product');
          var shown = 0;
          cards.forEach(function(c){
            var n = c.getAttribute('data-name') || '';
            var v = c.getAttribute('data-vendor') || '';
            var match = !q || n.indexOf(q) >= 0 || v.indexOf(q) >= 0;
            c.classList.toggle('is-hidden', !match);
            if (match) shown++;
          });
          sec.classList.toggle('is-empty', shown === 0);
          totalShown += shown;
        });
        if (noRes) noRes.classList.toggle('hidden', totalShown > 0 || !q);
      }

      input.addEventListener('input', applyFilter);
      clearBtn.addEventListener('click', function(){ input.value = ''; applyFilter(); input.focus(); });

      // Mobile sidebar popup
      window.catalogOpenPopup = function(){
        if (!sidebar) return;
        sidebar.classList.add('is-open');
        if (backdrop){ backdrop.classList.remove('hidden'); backdrop.classList.add('is-open'); }
        document.body.style.overflow = 'hidden';
      };
      window.catalogClosePopup = function(){
        if (!sidebar) return;
        sidebar.classList.remove('is-open');
        if (backdrop){ backdrop.classList.remove('is-open'); setTimeout(function(){ backdrop.classList.add('hidden'); }, 240); }
        document.body.style.overflow = '';
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
                        <p class="mt-1 text-xs text-zinc-500">Vendedor verificado</p>
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

    <!-- =========== MAIS POPULARES =========== -->
    <?php if ($populares && $q === ''): ?>
    <section class="max-w-[1440px] mx-auto px-4 sm:px-6 py-10 sm:py-14">
        <div class="flex items-center justify-between mb-8 sm:mb-10">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-greenx/10 border border-greenx/20 text-greenx text-[11px] font-bold uppercase tracking-wider mb-3">
                    <i data-lucide="trending-up" class="w-3 h-3"></i>
                    Popular
                </div>
                <h2 class="text-2xl sm:text-3xl font-bold">Mais populares</h2>
                <p class="text-sm text-zinc-500 mt-1">Produtos mais vendidos da plataforma</p>
            </div>
            <a href="<?= BASE_PATH ?>/categorias" class="hidden sm:inline-flex items-center gap-1.5 text-xs text-greenx hover:underline font-semibold">Ver todos <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i></a>
        </div>
        <div class="flex gap-3 overflow-x-auto pb-3 scrollbar-hide snap-x snap-mandatory sm:mx-0 sm:px-0 sm:grid sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 sm:gap-5 sm:overflow-visible">
            <?php foreach ($populares as $i => $p): ?>
            <article class="product-card group bg-blackx2 border border-white/[0.06] rounded-2xl overflow-hidden flex flex-col hover:border-greenx/20 hover:shadow-2xl hover:shadow-greenx/[0.06] hover:-translate-y-1 transition-all duration-400 animate-fade-in-up stagger-<?= min($i + 1, 6) ?> min-w-[150px] sm:min-w-0 snap-start <?= $i === 0 ? 'ml-4 sm:ml-0' : '' ?><?= $i === count($populares) - 1 ? ' mr-4 sm:mr-0' : '' ?>">
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
                        <button class="w-full flex items-center justify-center gap-1 sm:gap-1.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-2 py-2 sm:px-3 sm:py-2.5 text-[10px] sm:text-xs shadow-lg shadow-greenx/15 hover:shadow-greenx/30 hover:scale-[1.02] active:scale-[0.98] transition-all">
                            <i data-lucide="shopping-bag" class="w-3 h-3 sm:w-3.5 sm:h-3.5"></i>
                            Comprar
                        </button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
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
