<?php
// filepath: views/partials/financeiro_tabs.php
$finTab = $finTab ?? 'wallet';
$tabs = [
    'wallet'    => ['label' => 'Carteira',  'href' => BASE_PATH . '/wallet',    'icon' => 'wallet-cards'],
    'depositos' => ['label' => 'Depósitos', 'href' => BASE_PATH . '/depositos', 'icon' => 'banknote'],
    'saques'    => ['label' => 'Saques',    'href' => BASE_PATH . '/saques',    'icon' => 'arrow-down-up'],
];
?>
<div class="bg-blackx2 border border-blackx3 rounded-2xl p-1.5 flex gap-1 overflow-x-auto no-scrollbar">
  <?php foreach ($tabs as $key => $t):
    $active = $finTab === $key;
  ?>
    <a href="<?= htmlspecialchars($t['href'], ENT_QUOTES, 'UTF-8') ?>"
       class="flex-1 min-w-[120px] flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition whitespace-nowrap
              <?= $active ? 'bg-greenx text-blackx shadow-sm' : 'text-zinc-400 hover:bg-blackx hover:text-white' ?>">
      <i data-lucide="<?= $t['icon'] ?>" class="w-4 h-4"></i>
      <span><?= $t['label'] ?></span>
    </a>
  <?php endforeach; ?>
</div>
