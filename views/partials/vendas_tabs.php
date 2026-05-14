<?php
// filepath: views/partials/vendas_tabs.php
// Tabs unificadas das páginas de Vendas (vendedor).
// Espera $vendasTab definido pelo include: 'analise' | 'aprovadas' | 'canceladas'
$vendasTab = $vendasTab ?? 'aprovadas';
$tabs = [
    'aprovadas'  => ['label' => 'Concluídas',   'href' => BASE_PATH . '/vendedor/vendas_aprovadas', 'icon' => 'badge-check'],
    'analise'    => ['label' => 'Em análise',   'href' => BASE_PATH . '/vendedor/vendas_analise',   'icon' => 'hourglass'],
    'canceladas' => ['label' => 'Canceladas',   'href' => BASE_PATH . '/vendedor/vendas_canceladas','icon' => 'x-circle'],
];
?>
<div class="bg-blackx2 border border-blackx3 rounded-2xl p-1.5 flex gap-1 overflow-x-auto no-scrollbar">
  <?php foreach ($tabs as $key => $t):
    $active = $vendasTab === $key;
  ?>
    <a href="<?= htmlspecialchars($t['href'], ENT_QUOTES, 'UTF-8') ?>"
       class="flex-1 min-w-[120px] flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition whitespace-nowrap
              <?= $active ? 'bg-greenx text-blackx shadow-sm' : 'text-zinc-400 hover:bg-blackx hover:text-white' ?>">
      <i data-lucide="<?= $t['icon'] ?>" class="w-4 h-4"></i>
      <span><?= $t['label'] ?></span>
    </a>
  <?php endforeach; ?>
</div>
