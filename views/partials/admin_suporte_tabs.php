<?php
$adminSupportTab = $adminSupportTab ?? 'chat';
$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/src/admin_alerts.php';
$adminSupportCounts = isset($conn) ? adminAlertsCounts($conn, (int)($_SESSION['user_id'] ?? 0)) : ['support_unread' => 0, 'tickets_pending' => 0, 'reports_pending' => 0];
$tabs = [
  'chat'      => ['label' => 'Chat',      'href' => BASE_PATH . '/admin/chat',      'icon' => 'message-circle', 'count' => (int)$adminSupportCounts['support_unread']],
  'denuncias' => ['label' => 'Denúncias', 'href' => BASE_PATH . '/admin/denuncias', 'icon' => 'flag', 'count' => (int)$adminSupportCounts['reports_pending']],
  'tickets'   => ['label' => 'Tickets',   'href' => BASE_PATH . '/admin/tickets',   'icon' => 'ticket', 'count' => (int)$adminSupportCounts['tickets_pending']],
];
?>
<div class="mb-4 bg-blackx2 border border-blackx3 rounded-2xl p-1.5 flex gap-1 overflow-x-auto no-scrollbar">
  <?php foreach ($tabs as $key => $t):
    $active = $adminSupportTab === $key;
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
