<?php
$adminCatalogTab = $adminCatalogTab ?? 'produtos';
$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/src/admin_alerts.php';
$adminCatalogCounts = isset($conn) ? adminAlertsCounts($conn, (int)($_SESSION['user_id'] ?? 0)) : ['products_pending' => 0];
$tabs = [
    'produtos' => ['label' => 'Produtos', 'href' => BASE_PATH . '/admin/produtos', 'icon' => 'package'],
    'categorias' => ['label' => 'Categorias', 'href' => BASE_PATH . '/admin/categorias', 'icon' => 'tags'],
    'produtos_aprovados' => ['label' => 'Produtos aprovados', 'href' => BASE_PATH . '/admin/solicitacoes_produto?status=aprovado', 'icon' => 'check-circle-2'],
  'produtos_editados' => ['label' => 'Produtos editados', 'href' => BASE_PATH . '/admin/solicitacoes_produto?status=pendente', 'icon' => 'pencil-line', 'count' => (int)$adminCatalogCounts['products_pending']],
  'solicitacoes_produto' => ['label' => 'Solicitações', 'href' => BASE_PATH . '/admin/solicitacoes_produto?status=', 'icon' => 'package-check', 'count' => (int)$adminCatalogCounts['products_pending']],
];
?>
<div class="mb-4 bg-blackx2 border border-blackx3 rounded-2xl p-1.5 flex gap-1 overflow-x-auto no-scrollbar">
  <?php foreach ($tabs as $key => $t):
    $active = $adminCatalogTab === $key;
  ?>
    <a href="<?= htmlspecialchars($t['href'], ENT_QUOTES, 'UTF-8') ?>"
       class="flex-1 min-w-[120px] flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition whitespace-nowrap
              <?= $active ? 'bg-greenx text-blackx shadow-sm' : 'text-zinc-400 hover:bg-blackx hover:text-white' ?>">
      <i data-lucide="<?= $t['icon'] ?>" class="w-4 h-4"></i>
      <span><?= $t['label'] ?></span>
      <?= adminAlertsBadgeHtml((int)($t['count'] ?? 0), 'ml-1') ?>
    </a>
  <?php endforeach; ?>
</div>
