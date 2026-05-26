<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\favoritos.php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/storefront.php';
require_once __DIR__ . '/../src/favorites.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_PATH . '/login');
    exit;
}

$db = new Database();
$conn = $db->connect();

$userId = (int)$_SESSION['user_id'];
$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array((int)($_GET['pp'] ?? 12), [5, 10, 20], true) ? (int)($_GET['pp'] ?? 12) : 12;

$lista = favoritesList($conn, $userId, $pagina, $pp);

$_sfPage = 'favoritos';
$currentPage = 'favoritos';
$pageTitle = 'Meus Favoritos';

$isLoggedIn = true;
$userRole = (string)($_SESSION['user']['role'] ?? 'usuario');
$cartCount = function_exists('sfCartCount') ? sfCartCount() : 0;

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<div class="min-h-screen py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <div class="w-10 h-10 rounded-xl bg-red-500/10 border border-red-400 flex items-center justify-center" style="border-color:rgba(248,113,113,0.4)">
            <i data-lucide="heart" class="w-5 h-5 text-red-400"></i>
        </div>
        <div>
            <h1 class="text-xl font-bold">Meus Favoritos</h1>
            <p class="text-sm text-zinc-500"><?= (int)$lista['total'] ?> produto(s) favoritado(s)</p>
        </div>
    </div>

    <?php if (!$lista['itens']): ?>
    <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-12 text-center">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 border border-red-400 flex items-center justify-center" style="border-color:rgba(248,113,113,0.4)">
            <i data-lucide="heart" class="w-8 h-8 text-red-400/50"></i>
        </div>
        <p class="text-lg font-semibold text-zinc-300 mb-1">Nenhum favorito ainda</p>
        <p class="text-sm text-zinc-500 mb-4">Clique no coração nos produtos para adicioná-los aqui!</p>
        <a href="<?= BASE_PATH ?>/categorias" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-5 py-2.5 text-sm transition-all">
            <i data-lucide="search" class="w-4 h-4"></i> Explorar produtos
        </a>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 gap-3 sm:gap-4">
        <?php foreach ($lista['itens'] as $i => $p): ?>
        <?php if (empty($p['id'])) continue; // product was deleted ?>
        <article class="product-card group bg-blackx2 border border-white/[0.06] rounded-2xl overflow-hidden flex flex-col animate-fade-in-up stagger-<?= min($i + 1, 8) ?>">
            <div class="block relative overflow-hidden">
                <a href="<?= sfProductUrl($p) ?>" class="block">
                    <div class="aspect-[4/3] overflow-hidden bg-blackx">
                        <img src="<?= htmlspecialchars(sfImageUrl((string)($p['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                    </div>
                </a>
                <button type="button" class="fav-btn absolute top-2 right-2 w-7 h-7 rounded-full bg-black/50 backdrop-blur-sm border border-red-400/40 flex items-center justify-center text-red-400 hover:text-red-300 transition-all z-10 fav-active" data-product-id="<?= (int)$p['id'] ?>" title="Remover dos favoritos">
                    <i data-lucide="heart" class="w-3.5 h-3.5" style="fill:currentColor"></i>
                </button>
            </div>
            <div class="p-3 flex flex-col flex-1 text-center">
                <a href="<?= sfProductUrl($p) ?>"
                   class="font-semibold text-xs sm:text-sm line-clamp-2 min-h-[2rem] leading-snug hover:text-greenx transition-colors block">
                    <?= htmlspecialchars((string)($p['nome'] ?? 'Produto removido'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <div class="flex-1"></div>
                <?php if (!empty($p['vendedor_nome'])): ?>
                <div class="flex items-center justify-center gap-1.5 text-[10px] text-zinc-500 mt-1.5">
                    <i data-lucide="shield-check" class="w-3 h-3 text-greenx shrink-0"></i>
                    <span class="truncate"><?= htmlspecialchars((string)$p['vendedor_nome'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>
                <div class="inline-flex items-center self-center px-2.5 py-1 rounded-lg border border-greenx/40 bg-greenx/[0.06] mt-1.5">
                    <span class="text-sm sm:text-base font-bold text-greenx"><?= sfDisplayPrice($p) ?></span>
                </div>
                <?php
                $canBuyFavorite = sfProductCanPurchase($conn, $p);
                $hv = (($p['tipo'] ?? '') === 'dinamico' && !empty($p['variantes']));
                $vj = $hv ? htmlspecialchars(is_string($p['variantes']) ? $p['variantes'] : json_encode($p['variantes']), ENT_QUOTES, 'UTF-8') : '';
                ?>
                <form method="post" action="<?= BASE_PATH ?>/categorias" class="mt-1.5"
                    <?= $hv ? 'data-variants="' . $vj . '" data-product-name="' . htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8') . '" data-product-image="' . htmlspecialchars(sfImageUrl((string)($p['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                    <input type="hidden" name="action" value="add_cart">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="qty" value="1">
                    <button class="w-full flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-3 py-2 text-xs shadow-lg shadow-greenx/10 hover:shadow-greenx/20 transition-all <?= !$canBuyFavorite ? 'opacity-50 cursor-not-allowed' : '' ?>"
                            <?= !$canBuyFavorite ? 'disabled' : '' ?>>
                        <i data-lucide="shopping-bag" class="w-3 h-3"></i>
                        <?= $canBuyFavorite ? 'Comprar' : 'Esgotado' ?>
                    </button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
    </div>

    <?php if ($lista['total_paginas'] > 1): ?>
    <div class="mt-6">
        <?php
        $paginaAtual  = (int)$lista['pagina'];
        $totalPaginas = (int)$lista['total_paginas'];
        include __DIR__ . '/../views/partials/pagination.php';
        ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
?>
