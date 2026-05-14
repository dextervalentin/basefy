<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\favoritos.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/favorites.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();

$f = [
    'q' => (string)($_GET['q'] ?? ''),
];

$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array((int)($_GET['pp'] ?? 10), [5, 10, 20], true) ? (int)($_GET['pp'] ?? 10) : 10;

$lista = favoritesAdminList($conn, $f, $pagina, $pp);
$topProducts = favoritesTopProducts($conn, 5);

$pageTitle = 'Conteúdo';
$activeMenu = 'conteudo';
$adminContentTab = 'favoritos';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div>
    <?php include __DIR__ . '/../../views/partials/admin_conteudo_tabs.php'; ?>
    <!-- Top favorited products -->
    <?php if ($topProducts): ?>
    <div class="mb-5 bg-blackx2 border border-blackx3 rounded-2xl p-5">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-400 mb-3">
            <i data-lucide="trophy" class="w-4 h-4 inline-block mr-1 text-yellow-400"></i>
            Produtos mais favoritados
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
            <?php foreach ($topProducts as $rank => $tp): ?>
            <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx/50 border border-blackx3/60">
                <span class="text-lg font-bold text-zinc-500 w-6">#<?= $rank + 1 ?></span>
                <?php if (!empty($tp['imagem'])): ?>
                <img src="<?= htmlspecialchars(sfImageUrl((string)$tp['imagem'])) ?>" alt="" class="w-10 h-10 rounded-lg object-cover border border-blackx3">
                <?php endif; ?>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium truncate"><?= htmlspecialchars((string)($tp['nome'] ?? '-')) ?></p>
                    <p class="text-xs text-red-400 font-semibold"><i data-lucide="heart" class="w-3 h-3 inline-block mr-0.5" style="fill:currentColor"></i><?= (int)$tp['total_favs'] ?> favs</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
        <!-- Stats -->
        <div class="flex items-center gap-3 mb-4">
            <span class="px-3 py-1 rounded-full bg-red-500/15 border border-red-400/30 text-red-400 text-sm font-semibold">
                <?= (int)$lista['total'] ?> favoritos
            </span>
        </div>

        <!-- Filter -->
        <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
            <div class="flex flex-col md:flex-row md:items-end gap-3">
                <div class="md:flex-1">
                    <label class="block text-xs text-zinc-500 mb-1">Busca</label>
                    <input name="q" value="<?= htmlspecialchars($f['q']) ?>" placeholder="Produto ou usuário" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
                </div>
                <div class="flex items-center gap-2 md:ml-auto">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
                        <i data-lucide="search" class="w-4 h-4"></i> Buscar
                    </button>
                    <a href="favoritos" title="Limpar" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
                        <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                    </a>
                </div>
            </div>
        </form>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-zinc-400 border-b border-blackx3">
                    <th class="text-left py-3 pr-3">Produto</th>
                    <th class="text-left py-3 pr-3">Preço</th>
                    <th class="text-left py-3 pr-3">Usuário</th>
                    <th class="text-left py-3">Data</th>
                </tr></thead>
                <tbody>
                <?php foreach ($lista['itens'] as $row): ?>
                <tr class="border-b border-blackx3/50 hover:bg-blackx/40 transition">
                    <td class="py-3 pr-3">
                        <div class="flex items-center gap-2">
                            <?php if (!empty($row['produto_imagem'])): ?>
                            <img src="<?= htmlspecialchars(sfImageUrl((string)$row['produto_imagem'])) ?>" alt="" class="w-8 h-8 rounded-lg object-cover border border-blackx3 flex-shrink-0">
                            <?php endif; ?>
                            <span class="truncate max-w-[200px]"><?= htmlspecialchars((string)($row['produto_nome'] ?? 'Removido')) ?></span>
                        </div>
                    </td>
                    <td class="py-3 pr-3 text-greenx font-semibold">R$ <?= number_format((float)($row['preco'] ?? 0), 2, ',', '.') ?></td>
                    <td class="py-3 pr-3">
                        <?= htmlspecialchars((string)($row['user_nome'] ?? '-')) ?>
                        <?php if (!empty($row['user_email'])): ?>
                        <br><span class="text-xs text-zinc-500"><?= htmlspecialchars($row['user_email']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 text-xs text-zinc-400"><?= date('d/m/Y H:i', strtotime((string)$row['fav_criado_em'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$lista['itens']): ?><tr><td colspan="4" class="py-6 text-zinc-500">Nenhum favorito encontrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $paginaAtual  = (int)($lista['pagina'] ?? $pagina);
        $totalPaginas = (int)($lista['total_paginas'] ?? 1);
        include __DIR__ . '/../../views/partials/pagination.php';
        ?>
    </div>
</div>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>
