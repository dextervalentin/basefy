<?php
// filepath: c:\xampp\htdocs\mercado_admin\views\partials\admin_layout_start.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/src/db.php';

$activeMenu  = (string)($activeMenu ?? '');
$pageTitle   = (string)($pageTitle ?? 'Admin');
$topActions  = is_array($topActions ?? null) ? $topActions : [];
$subnavItems = is_array($subnavItems ?? null) ? $subnavItems : [];
$adminWalletSaldo = 0.0;

$adminUid = (int)($_SESSION['user_id'] ?? 0);
if ($adminUid > 0) {
  $connSaldo = (new Database())->connect();
  $stSaldo = $connSaldo->prepare("SELECT wallet_saldo FROM users WHERE id = ? LIMIT 1");
  if ($stSaldo) {
    $stSaldo->bind_param('i', $adminUid);
    $stSaldo->execute();
    $saldoRow = $stSaldo->get_result()->fetch_assoc() ?: [];
    $adminWalletSaldo = (float)($saldoRow['wallet_saldo'] ?? 0);
    $stSaldo->close();
  }
}

// Dashboard is always visible (no dropdown)
$menuFixed = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard', 'icon' => 'layout-dashboard'],
];

$menuGroups = [
    ['label' => 'Catálogo', 'icon' => 'shopping-bag', 'items' => [
      ['key' => 'catalogo', 'label' => 'Catálogo', 'href' => 'produtos', 'icon' => 'shopping-bag'],
    ]],
    ['label' => 'Pessoas', 'icon' => 'users', 'items' => [
        ['key' => 'admins', 'label' => 'Administradores', 'href' => 'admins', 'icon' => 'shield-check'],
        ['key' => 'usuarios', 'label' => 'Usuários', 'href' => 'usuarios', 'icon' => 'users'],
        ['key' => 'verificacoes', 'label' => 'Verificações', 'href' => 'verificacoes', 'icon' => 'shield-check'],
        ['key' => 'solicitacoes_produto', 'label' => 'Solic. Produto', 'href' => 'solicitacoes_produto', 'icon' => 'package-check'],
    ]],
    ['label' => 'Financeiro', 'icon' => 'landmark', 'items' => [
      ['key' => 'financeiro', 'label' => 'Financeiro', 'href' => 'vendas', 'icon' => 'landmark'],
    ]],
    ['label' => 'Suporte', 'icon' => 'headphones', 'items' => [
      ['key' => 'suporte', 'label' => 'Suporte', 'href' => 'chat', 'icon' => 'headphones'],
    ]],
    ['label' => 'Afiliados', 'icon' => 'share-2', 'items' => [
        ['key' => 'afiliados', 'label' => 'Gerenciar', 'href' => 'afiliados', 'icon' => 'share-2'],
        ['key' => 'afiliados_config', 'label' => 'Configurações', 'href' => 'afiliados_config', 'icon' => 'settings-2'],
    ]],
    ['label' => 'Conteúdo', 'icon' => 'newspaper', 'items' => [
      ['key' => 'conteudo', 'label' => 'Conteúdo', 'href' => 'blog', 'icon' => 'newspaper'],
    ]],
    ['label' => 'Sistema', 'icon' => 'settings', 'items' => [
        ['key' => 'google_oauth', 'label' => 'Google OAuth', 'href' => 'google_oauth', 'icon' => 'key-round'],
        ['key' => 'smtp', 'label' => 'SMTP (E-mail)', 'href' => 'smtp', 'icon' => 'mail'],
        ['key' => 'email_templates', 'label' => 'Templates E-mail', 'href' => 'email_templates', 'icon' => 'file-text'],
        ['key' => 'push_config', 'label' => 'Web Push', 'href' => 'push_config', 'icon' => 'bell-ring'],
        ['key' => 'documentacao', 'label' => 'Documentação', 'href' => 'documentacao', 'icon' => 'book-open'],
    ]],
];
?>
<div class="min-h-screen bg-blackx text-white">
  <div class="flex">
    <!-- Mobile sidebar overlay -->
    <div id="adminSidebarOverlay" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-30 hidden md:hidden"></div>

    <aside id="adminSidebar" class="fixed md:static z-40 top-0 right-0 h-screen w-72 md:w-64 bg-blackx2 border-l md:border-l-0 md:border-r border-blackx3 transform translate-x-full md:translate-x-0 transition-transform duration-300 ease-out md:min-h-screen md:sticky md:top-0">
      <div class="h-16 px-4 flex items-center justify-between border-b border-blackx3">
        <img src="<?= BASE_PATH ?>/assets/img/logo22.png" alt="Basefy" class="h-8 w-auto object-contain">
        <button id="btnAdminCloseSidebar" class="md:hidden w-8 h-8 rounded-lg border border-blackx3 flex items-center justify-center text-zinc-400 hover:text-white transition">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <nav class="p-3 space-y-2 overflow-y-auto" style="max-height:calc(100vh - 4rem)">
        <div class="mb-2">
          <a href="<?= BASE_PATH ?>/" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border border-transparent hover:bg-greenx/10 hover:border-greenx/30 text-zinc-300 hover:text-greenx transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i><span>Voltar à Loja</span>
          </a>
        </div>
        <!-- Dashboard (always visible) -->
        <div class="space-y-1">
          <?php foreach ($menuFixed as $item): ?>
            <?php $isActive = $activeMenu === (string)$item['key']; ?>
            <a href="<?= htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8') ?>"
               class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border-l-2 transition <?= $isActive ? 'bg-greenx/15 border-greenx text-greenx' : 'border-transparent hover:bg-blackx hover:border-greenx text-zinc-200' ?>">
              <i data-lucide="<?= htmlspecialchars((string)$item['icon'], ENT_QUOTES, 'UTF-8') ?>" class="w-4 h-4"></i>
              <span><?= htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <!-- Collapsible groups -->
        <?php foreach ($menuGroups as $gIdx => $group):
          // Auto-open if active item is in this group
          $groupHasActive = false;
          foreach ($group['items'] as $item) {
              if ($activeMenu === (string)$item['key']) { $groupHasActive = true; break; }
          }
        ?>
          <div x-data="{ open: <?= $groupHasActive ? 'true' : 'false' ?> }" class="border-t border-white/[0.04] pt-1">
            <button @click="open = !open" type="button"
                    class="w-full flex items-center gap-2 rounded-lg px-3 py-2 text-[11px] uppercase tracking-wider font-semibold transition-colors"
                    :class="open ? 'text-greenx' : 'text-zinc-500 hover:text-zinc-300'">
              <i data-lucide="<?= htmlspecialchars((string)($group['icon'] ?? 'folder'), ENT_QUOTES, 'UTF-8') ?>" class="w-3.5 h-3.5"></i>
              <span class="flex-1 text-left"><?= htmlspecialchars((string)$group['label'], ENT_QUOTES, 'UTF-8') ?></span>
              <i data-lucide="chevron-down" class="w-3.5 h-3.5 transition-transform duration-200" :class="open ? 'rotate-180' : ''"></i>
            </button>
            <div x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="space-y-0.5 mt-0.5">
              <?php foreach ($group['items'] as $item): ?>
                <?php $isActive = $activeMenu === (string)$item['key']; ?>
                 <a href="<?= htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8') ?>"
                   class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border-l-2 transition <?= $isActive ? 'bg-greenx/15 border-greenx text-greenx' : 'border-transparent hover:bg-blackx hover:border-greenx text-zinc-200' ?>">
                  <i data-lucide="<?= htmlspecialchars((string)$item['icon'], ENT_QUOTES, 'UTF-8') ?>" class="w-4 h-4"></i>
                  <span><?= htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </nav>
    </aside>

    <div class="flex-1 min-w-0">
      <header class="h-16 sticky top-0 z-[80] w-full max-w-full bg-blackx/90 backdrop-blur border-b border-blackx3 px-3 sm:px-4 md:px-5 flex items-center gap-2 sm:gap-3">
        <div class="min-w-0 flex flex-1 items-center gap-2 sm:gap-3">
          <button id="btnAdminOpenSidebar" class="md:hidden shrink-0 rounded-lg border border-blackx3 bg-blackx2 px-2.5 py-1.5 text-zinc-400 hover:text-white transition">
            <i data-lucide="menu" class="w-4 h-4"></i>
          </button>
          <h1 class="min-w-0 flex-1 text-sm sm:text-base md:text-xl font-semibold truncate"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <div class="flex shrink-0 items-center gap-1 sm:gap-2">
          <span class="hidden sm:inline-flex shrink-0 border border-blackx3 rounded-xl px-2 md:px-3 py-2 text-xs md:text-sm bg-blackx2 items-center gap-1.5"><i data-lucide="wallet" class="w-4 h-4 text-zinc-300 hidden sm:block"></i><span class="hidden sm:inline">Saldo:</span> <span class="text-greenx font-semibold">R$ <?= number_format($adminWalletSaldo, 2, ',', '.') ?></span></span>
          <?php foreach ($topActions as $a): ?>
            <a class="hidden md:inline-flex px-3 py-2 rounded-lg bg-blackx2 border border-blackx3 hover:bg-blackx3 text-sm"
               href="<?= htmlspecialchars((string)($a['href'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars((string)($a['label'] ?? 'Ação'), ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php endforeach; ?>
          <?php
            require_once dirname(__DIR__, 2) . '/src/notifications.php';
            $_adminNotifCount = notificationsUnreadCount((new Database())->connect(), (int)$adminUid);
          ?>
          <div class="relative" x-data="{openNotif:false}" @click.away="openNotif=false">
            <button @click="openNotif=!openNotif; if(openNotif) $dispatch('notif-open')"
                    class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border transition <?= $_adminNotifCount > 0 ? 'border-yellow-400/30 bg-yellow-500/[0.06] text-yellow-400' : 'border-blackx3 text-zinc-400 hover:text-white' ?>" title="Notificações" id="notifBellBtn">
              <i data-lucide="bell" class="w-4 h-4"></i>
              <span id="notifBadge" class="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-[9px] font-bold text-white flex items-center justify-center <?= $_adminNotifCount > 0 ? '' : 'hidden' ?>"><?= $_adminNotifCount > 99 ? '99+' : $_adminNotifCount ?></span>
            </button>
            <div x-show="openNotif" x-transition class="fixed left-2 right-2 top-[4.5rem] sm:left-auto sm:right-4 sm:w-[400px] sm:max-w-[calc(100vw-2rem)] bg-blackx2 border border-blackx3 rounded-2xl shadow-2xl overflow-hidden z-[9999]" style="display:none;max-height:calc(100vh - 5rem);max-height:calc(100dvh - 5rem)">
              <div class="p-3 border-b border-blackx3 flex items-center justify-between">
                <h3 class="font-semibold text-sm">Notificações</h3>
                <div class="flex items-center gap-2">
                  <button onclick="toggleNotifSound()" class="text-xs text-zinc-500 hover:text-zinc-300" id="notifSoundBtn"><i data-lucide="volume-2" class="w-3.5 h-3.5 notif-sound-on"></i><i data-lucide="volume-x" class="w-3.5 h-3.5 notif-sound-off hidden"></i></button>
                  <button onclick="markAllNotifRead()" class="text-xs text-greenx hover:underline">Marcar tudo lido</button>
                </div>
              </div>
              <div class="flex flex-col sm:flex-row">
                <div class="flex sm:flex-col sm:w-[50px] shrink-0 border-b sm:border-b-0 sm:border-r border-blackx3 py-1 sm:py-2 px-1 sm:px-0 gap-0 sm:gap-1 bg-blackx/40 overflow-x-auto">
                  <button onclick="filterNotif('all')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="all" title="Todos"><i data-lucide="inbox" class="w-4 h-4"></i><span class="text-[8px] font-semibold">Todos</span></button>
                  <button onclick="filterNotif('anuncio')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="anuncio" title="Anúncios"><i data-lucide="megaphone" class="w-4 h-4"></i><span class="text-[8px] font-semibold">Anúnc.</span></button>
                  <button onclick="filterNotif('venda')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="venda" title="Vendas"><i data-lucide="shopping-bag" class="w-4 h-4"></i><span class="text-[8px] font-semibold">Vendas</span></button>
                  <button onclick="filterNotif('chat')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="chat" title="Chats"><i data-lucide="message-circle" class="w-4 h-4"></i><span class="text-[8px] font-semibold">Chats</span></button>
                  <button onclick="filterNotif('ticket')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="ticket" title="Tickets"><i data-lucide="flag" class="w-4 h-4"></i><span class="text-[8px] font-semibold">Tickets</span></button>
                </div>
                <div id="notifList" class="flex-1 max-h-[calc(100vh-12rem)] sm:max-h-72 overflow-y-auto divide-y divide-white/[0.04]"><div class="p-6 text-center text-zinc-500 text-sm">Carregando...</div></div>
              </div>
            </div>
          </div>
          <a href="<?= BASE_PATH ?>/admin/minha_conta" class="shrink-0 border border-blackx3 rounded-xl w-9 h-9 sm:w-auto sm:h-auto sm:px-3 sm:py-2 hover:border-greenx inline-flex items-center justify-center gap-2 text-sm"><i data-lucide="user-circle-2" class="w-4 h-4"></i><span class="hidden lg:inline">Minha conta</span></a>
          <a href="<?= BASE_PATH ?>/logout" class="shrink-0 border border-blackx3 rounded-xl w-9 h-9 sm:w-auto sm:h-auto sm:px-3 sm:py-2 hover:border-red-500 inline-flex items-center justify-center gap-2 text-sm"><i data-lucide="log-out" class="w-4 h-4"></i><span class="hidden lg:inline">Sair</span></a>
        </div>
      </header>

      <main class="p-5">
<style>
main table th:first-child, main table td:first-child { padding-left: 1.25rem; }
main table th:last-child, main table td:last-child { padding-right: 1.25rem; }
</style>