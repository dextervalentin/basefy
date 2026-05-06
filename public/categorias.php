<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/storefront.php';
require_once __DIR__ . '/../src/affiliates.php';
require_once __DIR__ . '/../src/media.php';

$userId     = (int)($_SESSION['user_id'] ?? 0);
$userRole   = (string)($_SESSION['user']['role'] ?? 'usuario');
$isLoggedIn = $userId > 0;

$conn = (new Database())->connect();
affHandleReferral($conn);

$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'add_cart') {
    $varPost = trim((string)($_POST['variante'] ?? ''));
    sfCartAdd((int)($_POST['product_id'] ?? 0), max(1, (int)($_POST['qty'] ?? 1)), $varPost !== '' ? $varPost : null);
    $feedback = 'Produto adicionado ao carrinho!';
}

$categoryId = (int)($_GET['categoria_id'] ?? 0);
$catSlug = trim((string)($_GET['cat_slug'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$categorias = sfListCategories($conn);
// Exclude blog-type categories from the store catalog
$categorias = array_values(array_filter($categorias, fn($c) => strtolower(trim((string)($c['tipo'] ?? ''))) !== 'blog'));

// Resolve slug to ID
if ($catSlug !== '' && $categoryId === 0) {
    $catRow = sfGetCategoryBySlug($conn, $catSlug);
    if ($catRow) {
        $categoryId = (int)$catRow['id'];
    }
}

$showCategoryGrid = ($categoryId === 0 && $q === '');

$produtos = [];
if (!$showCategoryGrid) {
    $produtos = sfListProducts($conn, ['category_id' => $categoryId, 'q' => $q, 'limit' => 48]);
}
$cartCount = sfCartCount();

// Count products per category (for grid view)
$catCounts = [];
if ($showCategoryGrid) {
    $rs = $conn->query("SELECT categoria_id, COUNT(*) AS total FROM products WHERE ativo = 1 GROUP BY categoria_id");
    while ($rs && ($r = $rs->fetch_assoc())) {
        $catCounts[(int)$r['categoria_id']] = (int)$r['total'];
    }
}

$activeCatName = $showCategoryGrid ? 'Categorias' : 'Todos os produtos';
if ($categoryId > 0) {
    foreach ($categorias as $c) {
        if ((int)$c['id'] === $categoryId) { $activeCatName = (string)$c['nome']; break; }
    }
}

$currentPage = 'categorias';
$pageTitle = ($categoryId > 0 ? $activeCatName . ' — ' : '') . 'Catálogo';
include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<div class="min-h-screen bg-blackx">
    <!-- Page header -->
    <div class="border-b border-white/[0.04] bg-white/[0.01]">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 py-6">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div class="animate-fade-in-up">
                    <div class="flex items-center gap-2 text-sm text-zinc-500 mb-2">
                        <a href="<?= BASE_PATH ?>/" class="hover:text-greenx transition-colors">Início</a>
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                        <span class="text-zinc-300">Catálogo</span>
                        <?php if ($categoryId > 0): ?>
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                        <span class="text-white font-medium"><?= htmlspecialchars($activeCatName, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-bold"><?= htmlspecialchars($activeCatName, ENT_QUOTES, 'UTF-8') ?></h1>
                    <?php if (!$showCategoryGrid): ?>
                    <p class="text-sm text-zinc-500 mt-1"><?= count($produtos) ?> produto(s) encontrado(s)</p>
                    <?php else: ?>
                    <p class="text-sm text-zinc-500 mt-1">Escolha uma categoria para explorar</p>
                    <?php endif; ?>
                </div>

                <!-- Search + filter -->
                <?php if (!$showCategoryGrid): ?>
                <form method="get" class="flex flex-col sm:flex-row gap-2 animate-fade-in-up stagger-1">
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-500 pointer-events-none"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Buscar produtos..."
                               class="w-full sm:w-64 pl-10 pr-4 py-2.5 rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm placeholder:text-zinc-500 focus:outline-none focus:border-greenx/50 focus:ring-1 focus:ring-greenx/20 transition-all">
                    </div>
                    <select name="categoria_id"
                            onchange="const opt=this.options[this.selectedIndex];const s=opt.dataset.slug;if(s){window.location='/c/'+s;return false;}this.form.submit()"
                            class="rounded-xl bg-white/[0.04] border border-white/[0.08] px-4 py-2.5 text-sm text-zinc-300 focus:outline-none focus:border-greenx/50">
                        <option value="0">Todas as categorias</option>
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" data-slug="<?= htmlspecialchars((string)($cat['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= (int)$cat['id'] === $categoryId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$cat['nome'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-5 py-2.5 text-sm transition-all">Filtrar</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($feedback !== ''): ?>
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 mt-4 animate-scale-in">
        <div class="flex items-center gap-3 rounded-2xl border border-greenx/30 bg-greenx/[0.06] px-5 py-3.5">
            <div class="w-8 h-8 rounded-full bg-greenx/20 flex items-center justify-center flex-shrink-0"><i data-lucide="check" class="w-4 h-4 text-greenx"></i></div>
            <p class="text-sm text-greenx"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 py-6">
        <?php if ($showCategoryGrid): ?>
        <!-- ── CATEGORY GRID (GGMax style) ── -->
        <?php if (!$categorias): ?>
        <div class="rounded-3xl border border-white/[0.06] bg-white/[0.02] p-16 text-center animate-fade-in-up">
            <div class="w-16 h-16 rounded-2xl bg-white/[0.04] border border-white/[0.06] flex items-center justify-center mx-auto mb-4">
                <i data-lucide="folder-x" class="w-7 h-7 text-zinc-600"></i>
            </div>
            <h3 class="text-lg font-semibold text-zinc-300">Nenhuma categoria cadastrada</h3>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 sm:gap-4">
            <?php
            $catGradients = [
                'from-greenx/40 to-purple-900/40',
                'from-greenx/40 to-purple-900/40',
                'from-purple-600/40 to-purple-900/40',
                'from-rose-600/40 to-rose-900/40',
                'from-amber-600/40 to-amber-900/40',
                'from-cyan-600/40 to-cyan-900/40',
                'from-indigo-600/40 to-indigo-900/40',
                'from-teal-600/40 to-teal-900/40',
                'from-orange-600/40 to-orange-900/40',
                'from-pink-600/40 to-pink-900/40',
            ];
            foreach ($categorias as $ci => $cat):
                $catCount = $catCounts[(int)$cat['id']] ?? 0;
                $catCountLabel = $catCount !== 1 ? 'produtos' : 'produto';
                $catLink = sfCategoryUrl($cat);
                $grad = $catGradients[$ci % count($catGradients)];
            ?>
            <a href="<?= $catLink ?>"
               class="group relative bg-blackx2 border border-white/[0.06] rounded-2xl overflow-hidden hover:border-greenx/30 transition-all animate-fade-in-up stagger-<?= min($ci + 1, 8) ?>">
                <div class="aspect-square flex flex-col items-center justify-center gap-3 p-4 bg-gradient-to-br <?= $grad ?> group-hover:scale-105 transition-transform duration-300 relative">
                    <?php $catImg = sfImageUrl((string)($cat['imagem'] ?? '')); if ($catImg !== '' && !str_contains($catImg, 'placehold.co')): ?>
                    <img src="<?= htmlspecialchars($catImg, ENT_QUOTES, 'UTF-8') ?>" alt="" class="absolute inset-0 w-full h-full object-cover opacity-60 group-hover:opacity-80 transition-opacity duration-300">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent"></div>
                    <?php endif; ?>
                    <div class="relative z-10 text-center">
                        <p class="font-bold text-sm sm:text-base text-white line-clamp-2"><?= htmlspecialchars((string)$cat['nome'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-[10px] sm:text-xs text-white/60 mt-0.5"><?= $catCount ?> <?= $catCountLabel ?></p>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ── PRODUCT GRID ── -->
        <!-- Category pills -->
        <?php if ($categorias): ?>
        <div class="flex flex-wrap gap-2 mb-6 animate-fade-in">
            <a href="<?= BASE_PATH ?>/categorias<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>"
               class="group inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-sm transition-all bg-white/[0.03] border border-white/[0.06] text-zinc-400 hover:border-greenx/30 hover:text-white">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                Todas as categorias
            </a>
            <?php foreach ($categorias as $cat): ?>
            <a href="<?= sfCategoryUrl($cat) ?><?= $q !== '' ? '?q=' . urlencode($q) : '' ?>"
               class="group inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-sm transition-all <?= (int)$cat['id'] === $categoryId ? 'bg-greenx/10 border border-greenx/30 text-greenx font-medium' : 'bg-white/[0.03] border border-white/[0.06] text-zinc-400 hover:border-greenx/30 hover:text-white' ?>">
                <span class="w-1.5 h-1.5 rounded-full <?= (int)$cat['id'] === $categoryId ? 'bg-greenx' : 'bg-zinc-600 group-hover:bg-greenx/60' ?> transition-colors"></span>
                <?= htmlspecialchars((string)$cat['nome'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Product grid -->
        <?php if (!$produtos): ?>
        <div class="rounded-3xl border border-white/[0.06] bg-white/[0.02] p-16 text-center animate-fade-in-up">
            <div class="w-16 h-16 rounded-2xl bg-white/[0.04] border border-white/[0.06] flex items-center justify-center mx-auto mb-4">
                <i data-lucide="search-x" class="w-7 h-7 text-zinc-600"></i>
            </div>
            <h3 class="text-lg font-semibold text-zinc-300">Nenhum produto encontrado</h3>
            <p class="text-sm text-zinc-500 mt-2 max-w-sm mx-auto">Tente alterar os filtros ou buscar por outros termos.</p>
            <a href="<?= BASE_PATH ?>/categorias" class="inline-flex mt-5 items-center gap-2 rounded-xl bg-white/[0.06] border border-white/[0.08] px-5 py-2.5 text-sm text-zinc-300 hover:text-white hover:border-white/[0.15] transition-all">
                <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                Limpar filtros
            </a>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3 sm:gap-4">
            <?php foreach ($produtos as $i => $p): ?>
            <article class="product-card group bg-blackx2 border border-white/[0.06] rounded-2xl overflow-hidden flex flex-col animate-fade-in-up stagger-<?= min($i + 1, 8) ?>">
                <div class="block relative overflow-hidden">
                    <a href="<?= sfProductUrl($p) ?>" class="block">
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
                </div>
                <div class="p-3 flex flex-col flex-1 text-center">
                    <a href="<?= sfProductUrl($p) ?>"
                       class="font-semibold text-xs sm:text-sm line-clamp-2 min-h-[2rem] leading-snug hover:text-greenx transition-colors block">
                        <?= htmlspecialchars((string)$p['nome'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <div class="flex-1"></div>
                    <?php if (!empty($p['vendedor_nome'])): ?>
                    <div class="flex items-center justify-center gap-1.5 text-[10px] text-zinc-500 mt-1.5">
                        <i data-lucide="shield-check" class="w-3 h-3 text-greenx shrink-0"></i>
                        <span class="truncate"><?= htmlspecialchars((string)$p['vendedor_nome'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="inline-flex items-center self-center px-2.5 py-1 rounded-lg border border-greenx/40 bg-greenx/[0.06] mt-1.5">
                        <span class="text-sm sm:text-base font-bold text-greenx"><?= sfDisplayPrice($p) ?>
                    </div>
                    <?php
                    $hv = (($p['tipo'] ?? '') === 'dinamico' && !empty($p['variantes']));
                    $vj = $hv ? htmlspecialchars(is_string($p['variantes']) ? $p['variantes'] : json_encode($p['variantes']), ENT_QUOTES, 'UTF-8') : '';
                    ?>
                    <form method="post" class="mt-1.5"
                        <?= $hv ? 'data-variants="' . $vj . '" data-product-name="' . htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                        <input type="hidden" name="action" value="add_cart">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="qty" value="1">
                        <button class="w-full flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-3 py-2 text-xs shadow-lg shadow-greenx/10 hover:shadow-greenx/20 transition-all">
                            <i data-lucide="shopping-bag" class="w-3 h-3"></i>
                            Comprar
                        </button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; /* showCategoryGrid else */ ?>
    </div>
</div>

<?php
include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
