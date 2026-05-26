<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/storefront.php';
require_once __DIR__ . '/../src/affiliates.php';

$userId     = (int)($_SESSION['user_id'] ?? 0);
$userRole   = (string)($_SESSION['user']['role'] ?? 'usuario');
$isLoggedIn = $userId > 0;

$conn = (new Database())->connect();
affHandleReferral($conn);

$vendorId = (int)($_GET['id'] ?? 0);
$vendorSlug = trim((string)($_GET['vendor_slug'] ?? ''));

// Resolve vendor slug to ID
if ($vendorSlug !== '' && $vendorId === 0) {
    $vRow = sfGetVendorBySlug($conn, $vendorSlug);
    if ($vRow) {
        $vendorId = (int)$vRow['id'];
    }
}

$vendor = sfGetVendorProfile($conn, $vendorId);

if (!$vendor) {
    header('Location: ' . BASE_PATH . '/categorias');
    exit;
}

// If accessed via ?id=X and vendor has a slug, redirect to /loja/slug (SEO canonical)
$vSlugReal = trim((string)($vendor['slug'] ?? ''));
if ($vendorId > 0 && $vendorSlug === '' && $vSlugReal !== '') {
    $qs = $_GET;
    unset($qs['id']);
    $extra = $qs ? '?' . http_build_query($qs) : '';
    header('Location: /loja/' . $vSlugReal . $extra, true, 301);
    exit;
}

// Feedback for add-to-cart
$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'add_cart') {
    $varPost = trim((string)($_POST['variante'] ?? ''));
    sfCartAdd((int)($_POST['product_id'] ?? 0), max(1, (int)($_POST['qty'] ?? 1)), $varPost !== '' ? $varPost : null);
    $feedback = 'Produto adicionado ao carrinho!';
}

// Vendor data
$storeName     = trim((string)($vendor['nome_loja'] ?? '')) ?: (string)$vendor['nome'];
$vendorBio     = trim((string)($vendor['bio'] ?? ''));
$vendorAvatar  = sfAvatarUrl($vendor['avatar'] ?? null);
$vendorKycVerified = !empty($vendor['kyc_verificado']);
$vendorSince   = '';
if (!empty($vendor['criado_em'])) {
    try {
        $dt = new DateTime((string)$vendor['criado_em']);
        $vendorSince = $dt->format('M Y');
    } catch (Throwable $e) {
        $vendorSince = '';
    }
}

$productCount = sfVendorProductCount($conn, $vendorId);
$salesCount   = sfVendorSalesCount($conn, $vendorId);

// Vendor products
$q = trim((string)($_GET['q'] ?? ''));
$categoryId = (int)($_GET['categoria_id'] ?? 0);

$cols = sfProductColumns($conn);
$vendorCol = $cols['vendor'] ?? 'vendedor_id';

// Build product list using sfListProducts with vendor filter workaround
// We need to get all vendor products, so we query directly
$products = sfListProducts($conn, [
    'q' => $q,
    'category_id' => $categoryId,
    'limit' => 48,
]);
// Filter to only this vendor's products
$products = array_values(array_filter($products, static fn(array $p): bool => (int)($p['vendedor_id'] ?? 0) === $vendorId));

$categorias = sfListCategories($conn);
$cartCount  = sfCartCount();

$currentPage = '';
$pageTitle   = $storeName . ' — Loja';
include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<div class="min-h-screen bg-blackx">
    <!-- Vendor profile header -->
    <section class="relative overflow-hidden border-b border-white/[0.04]">
        <!-- Background gradient -->
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_80%_50%_at_50%_-20%,rgba(var(--t-accent-rgb),0.08),transparent)]"></div>
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[400px] h-[400px] bg-greenx/[0.03] rounded-full blur-[100px] pointer-events-none"></div>

        <div class="relative max-w-[1600px] mx-auto px-4 sm:px-6 py-10 sm:py-14">
            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6 animate-fade-in-up">
                <!-- Avatar -->
                <div class="relative flex-shrink-0">
                    <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-3xl overflow-hidden border-2 border-white/[0.08] bg-blackx2 shadow-2xl shadow-black/40">
                        <img src="<?= htmlspecialchars($vendorAvatar, ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>"
                             class="w-full h-full object-cover">
                    </div>
                    <?php if ($vendorKycVerified): ?>
                    <div class="absolute -bottom-1 -right-1 w-8 h-8 rounded-xl bg-gradient-to-br from-greenx to-greenxd flex items-center justify-center shadow-lg shadow-greenx/30 border-2 border-blackx">
                        <i data-lucide="check" class="w-4 h-4 text-white"></i>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div class="text-center sm:text-left flex-1 min-w-0">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                        <h1 class="text-2xl sm:text-3xl font-black tracking-tight"><?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?></h1>
                        <?php if ($vendorKycVerified): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-greenx/10 border border-greenx/20 text-greenx text-xs font-medium w-fit mx-auto sm:mx-0">
                            <i data-lucide="badge-check" class="w-3 h-3"></i>
                            Vendedor verificado
                        </span>
                        <?php endif; ?>

                    </div>

                    <?php if ($vendorBio !== ''): ?>
                    <p class="mt-1 text-zinc-400 text-sm leading-relaxed max-w-2xl"><?= nl2br(htmlspecialchars($vendorBio, ENT_QUOTES, 'UTF-8')) ?></p>
                    <?php endif; ?>

                    <!-- Stats -->
                    <div class="mt-1 flex flex-wrap items-center justify-center sm:justify-start gap-4 sm:gap-6">
                        <div class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-xl bg-white/[0.04] border border-white/[0.06] flex items-center justify-center">
                                <i data-lucide="package" class="w-4 h-4 text-greenx"></i>
                            </div>
                            <div>
                                <p class="text-lg font-bold leading-tight"><?= $productCount ?></p>
                                <p class="text-[11px] text-zinc-500">Produto<?= $productCount !== 1 ? 's' : '' ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-xl bg-white/[0.04] border border-white/[0.06] flex items-center justify-center">
                                <i data-lucide="shopping-bag" class="w-4 h-4 text-purple-400"></i>
                            </div>
                            <div>
                                <p class="text-lg font-bold leading-tight"><?= $salesCount ?></p>
                                <p class="text-[11px] text-zinc-500">Venda<?= $salesCount !== 1 ? 's' : '' ?></p>
                            </div>
                        </div>
                        <?php if ($vendorSince !== ''): ?>
                        <div class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-xl bg-white/[0.04] border border-white/[0.06] flex items-center justify-center">
                                <i data-lucide="calendar" class="w-4 h-4 text-purple-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-bold leading-tight"><?= htmlspecialchars($vendorSince, ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-[11px] text-zinc-500">Membro desde</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if ($feedback !== ''): ?>
    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 mt-4 animate-scale-in">
        <div class="flex items-center gap-3 rounded-2xl border border-greenx/30 bg-greenx/[0.06] px-5 py-3.5">
            <div class="w-8 h-8 rounded-full bg-greenx/20 flex items-center justify-center flex-shrink-0"><i data-lucide="check" class="w-4 h-4 text-greenx"></i></div>
            <p class="text-sm text-greenx"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Vendor products -->
    <main class="max-w-[1600px] mx-auto px-4 sm:px-6 py-8">
        <!-- Filters -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 animate-fade-in-up">
            <div>
                <h2 class="text-xl font-bold">Produtos de <?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="text-sm text-zinc-500 mt-0.5"><?= count($products) ?> produto(s) disponíve<?= count($products) === 1 ? 'l' : 'is' ?></p>
            </div>
            <form method="get" action="<?= sfVendorUrl($vendor) ?>" class="flex gap-2">
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-500 pointer-events-none"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Buscar nesta loja..."
                           class="pl-10 pr-4 py-2.5 rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm placeholder:text-zinc-500 focus:outline-none focus:border-greenx/50 focus:ring-1 focus:ring-greenx/20 transition-all w-56">
                </div>
                <button class="rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-5 py-2.5 text-sm transition-all">Buscar</button>
            </form>
        </div>

        <?php if (!$products): ?>
        <div class="rounded-3xl border border-white/[0.06] bg-white/[0.02] p-16 text-center animate-fade-in-up">
            <div class="w-16 h-16 rounded-2xl bg-white/[0.04] border border-white/[0.06] flex items-center justify-center mx-auto mb-4">
                <i data-lucide="package-open" class="w-7 h-7 text-zinc-600"></i>
            </div>
            <h3 class="text-lg font-semibold text-zinc-300">Nenhum produto encontrado</h3>
            <p class="text-sm text-zinc-500 mt-2 max-w-sm mx-auto">
                <?= $q !== '' ? 'Não encontramos resultados para "' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '" nesta loja.' : 'Este vendedor ainda não publicou produtos.' ?>
            </p>
            <?php if ($q !== ''): ?>
            <a href="<?= sfVendorUrl(['id' => $vendorId, 'slug' => $vendor['slug'] ?? '']) ?>"
               class="inline-flex mt-5 items-center gap-2 rounded-xl bg-white/[0.06] border border-white/[0.08] px-5 py-2.5 text-sm text-zinc-300 hover:text-white hover:border-white/[0.15] transition-all">
                <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                Limpar filtro
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3 sm:gap-4">
            <?php foreach ($products as $i => $p): ?>
            <article class="product-card group bg-blackx2 border border-white/[0.06] rounded-2xl overflow-hidden flex flex-col animate-fade-in-up stagger-<?= min($i + 1, 8) ?>">
                <a href="<?= sfProductUrl($p) ?>" class="block relative overflow-hidden">
                    <div class="aspect-[4/3] overflow-hidden bg-blackx">
                        <img src="<?= htmlspecialchars(sfImageUrl((string)($p['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                    </div>
                    <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md text-[9px] font-bold uppercase tracking-wide bg-greenx/90 text-white backdrop-blur-sm">
                        <?= htmlspecialchars((string)($p['categoria_nome'] ?? 'Geral'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>
                <button type="button" class="fav-btn absolute top-2 right-2 w-7 h-7 rounded-full bg-black/50 backdrop-blur-sm border border-white/10 flex items-center justify-center text-zinc-400 hover:text-red-400 hover:border-red-400/40 transition-all z-10" data-product-id="<?= (int)$p['id'] ?>" title="Favoritar">
                    <i data-lucide="heart" class="w-3.5 h-3.5"></i>
                </button>
                <div class="p-3 flex flex-col flex-1 text-center">
                    <a href="<?= sfProductUrl($p) ?>"
                       class="font-semibold text-xs sm:text-sm line-clamp-2 min-h-[2rem] leading-snug hover:text-greenx transition-colors block">
                        <?= htmlspecialchars((string)$p['nome'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <div class="flex-1"></div>
                    <div class="inline-flex items-center self-center px-2.5 py-1 rounded-lg border border-greenx/40 bg-greenx/[0.06] mt-1.5">
                        <span class="text-sm sm:text-base font-bold text-greenx"><?= sfDisplayPrice($p) ?></span>
                    </div>
                    <?php
                    $canBuyStore = sfProductCanPurchase($conn, $p);
                    $hv = (($p['tipo'] ?? '') === 'dinamico' && !empty($p['variantes']));
                    $vj = $hv ? htmlspecialchars(is_string($p['variantes']) ? $p['variantes'] : json_encode($p['variantes']), ENT_QUOTES, 'UTF-8') : '';
                    ?>
                    <form method="post" class="mt-1.5"
                        <?= $hv ? 'data-variants="' . $vj . '" data-product-name="' . htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8') . '" data-product-image="' . htmlspecialchars(sfImageUrl((string)($p['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                        <input type="hidden" name="action" value="add_cart">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="qty" value="1">
                        <button class="w-full flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-3 py-2 text-xs shadow-lg shadow-greenx/10 hover:shadow-greenx/20 transition-all <?= !$canBuyStore ? 'opacity-50 cursor-not-allowed' : '' ?>"
                                <?= !$canBuyStore ? 'disabled' : '' ?>>
                            <i data-lucide="shopping-bag" class="w-3 h-3"></i>
                            <?= $canBuyStore ? 'Comprar' : 'Esgotado' ?>
                        </button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Back to catalog -->
        <div class="text-center mt-10">
            <a href="<?= BASE_PATH ?>/categorias"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-white/[0.04] border border-white/[0.06] text-sm font-medium text-zinc-300 hover:text-white hover:bg-white/[0.08] hover:border-white/[0.12] transition-all">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Voltar ao catálogo
            </a>
        </div>
    </main>
</div>

<?php
// Chat widget removed — chat only accessible after purchase

include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
