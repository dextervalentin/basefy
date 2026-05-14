<?php
// filepath: c:\xampp\htdocs\mercado_admin\views\partials\unified_layout_start.php
declare(strict_types=1);

/**
 * Unified layout — single sidebar for all users (buy + sell).
 * Replaces both user_layout_start.php and vendor_layout_start.php.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pageTitle  = (string)($pageTitle ?? 'Painel');
$activeMenu = (string)($activeMenu ?? '');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/src/db.php';
require_once $ROOT . '/src/upload_paths.php';
require_once $ROOT . '/src/media.php';

function _uniPickCol(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array(strtolower($c), $cols, true)) return $c;
    }
    return null;
}

$userNome  = (string)($_SESSION['user']['nome'] ?? '');
$userEmail = (string)($_SESSION['user']['email'] ?? '');
$userFoto  = (string)($_SESSION['user']['foto'] ?? $_SESSION['user']['avatar'] ?? '');
$uid       = (int)($_SESSION['user_id'] ?? 0);
$walletSaldo = 0.0;

if ($uid > 0) {
    $conn = (new Database())->connect();
    $cols = [];
    $rs = $conn->query("SHOW COLUMNS FROM users");
    if ($rs) while ($r = $rs->fetch_assoc()) $cols[] = strtolower((string)$r['Field']);

    $nameCol  = _uniPickCol($cols, ['nome', 'name', 'username']);
    $emailCol = _uniPickCol($cols, ['email', 'mail']);
    $fotoCol  = _uniPickCol($cols, ['foto_perfil', 'foto', 'avatar', 'profile_photo']);

    $select = ['id'];
    if ($nameCol)  $select[] = "`{$nameCol}` AS nome";
    if ($emailCol) $select[] = "`{$emailCol}` AS email";
    if ($fotoCol)  $select[] = "`{$fotoCol}` AS foto";

    $sql = "SELECT " . implode(', ', $select) . " FROM users WHERE id = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('i', $uid);
        $st->execute();
        $u = $st->get_result()->fetch_assoc() ?: [];
        $st->close();
        $userNome  = (string)($u['nome'] ?? $userNome ?: 'Usuário');
        $userEmail = (string)($u['email'] ?? $userEmail);
        $userFoto  = (string)($u['foto'] ?? $userFoto);
    }

    $_SESSION['user']['nome']  = $userNome;
    $_SESSION['user']['email'] = $userEmail;
    $_SESSION['user']['foto']  = $userFoto;

    // Wallet balance
    $stSaldo = $conn->prepare("SELECT wallet_saldo FROM users WHERE id = ? LIMIT 1");
    if ($stSaldo) {
        $stSaldo->bind_param('i', $uid);
        $stSaldo->execute();
        $saldoRow = $stSaldo->get_result()->fetch_assoc() ?: [];
        $walletSaldo = (float)($saldoRow['wallet_saldo'] ?? 0);
        $stSaldo->close();
    }
}

$userFoto = str_replace('\\', '/', $userFoto);
$userFoto = mediaResolveUrl($userFoto, 'https://placehold.co/120x120/111827/9ca3af?text=Foto');

// Check if user has products (to show "seller" indicators)
$_hasProducts = false;
if ($uid > 0) {
    $connP = $conn ?? (new Database())->connect();
    try {
        $rp = $connP->query("SHOW TABLES LIKE 'products'");
        if ($rp && $rp->fetch_assoc()) {
            // Detect vendedor_id vs user_id column
            $pCols = [];
            $rcol = $connP->query("SHOW COLUMNS FROM products");
            if ($rcol) while ($r = $rcol->fetch_assoc()) $pCols[] = strtolower((string)$r['Field']);
            $ownerCol = in_array('vendedor_id', $pCols) ? 'vendedor_id' : (in_array('user_id', $pCols) ? 'user_id' : null);
            if ($ownerCol) {
                $stP = $connP->prepare("SELECT COUNT(*) AS qtd FROM products WHERE {$ownerCol} = ?");
                if ($stP) {
                    $stP->bind_param('i', $uid);
                    $stP->execute();
                    $_hasProducts = ((int)(($stP->get_result()->fetch_assoc())['qtd'] ?? 0)) > 0;
                    $stP->close();
                }
            }
        }
    } catch (\Throwable $e) {}
}
?>

<div class="min-h-screen bg-blackx text-white">
  <div class="flex">
    <div id="uniSidebarOverlay" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-30 hidden md:hidden"></div>

    <aside id="uniSidebar" class="fixed md:static z-40 top-0 right-0 md:left-0 h-screen w-72 bg-blackx2 border-l md:border-l-0 md:border-r border-blackx3 transform translate-x-full md:translate-x-0 transition-transform duration-300 ease-out md:min-h-screen md:sticky md:top-0">
      <div class="h-16 px-4 flex items-center justify-between border-b border-blackx3">
        <img src="<?= BASE_PATH ?>/assets/img/logo22.png" alt="Basefy" class="h-8 w-auto object-contain">
        <button id="btnUniCloseSidebar" class="md:hidden w-8 h-8 rounded-lg border border-blackx3 flex items-center justify-center text-zinc-400 hover:text-white transition">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <div class="p-4 border-b border-blackx3 flex items-center gap-3">
        <img src="<?= htmlspecialchars($userFoto, ENT_QUOTES, 'UTF-8') ?>" class="w-12 h-12 rounded-full object-cover border border-blackx3" alt="Avatar">
        <div class="min-w-0">
          <p class="font-semibold truncate"><?= htmlspecialchars($userNome !== '' ? $userNome : 'Usuário', ENT_QUOTES, 'UTF-8') ?></p>
          <p class="text-xs text-zinc-400 truncate"><?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      </div>

      <nav class="p-3 space-y-4 overflow-y-auto" style="max-height:calc(100vh - 10rem)">
        <div class="mb-2">
          <a href="<?= BASE_PATH ?>/" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i><span>Voltar à Loja</span>
          </a>
        </div>

        <!-- ── Compras ── -->
        <div>
          <p class="px-2 pb-2 text-[11px] uppercase tracking-wider text-zinc-500 font-semibold">Compras</p>
          <div class="space-y-1">
            <a href="<?= BASE_PATH ?>/dashboard" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'dashboard' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>"><i data-lucide="layout-dashboard" class="w-4 h-4"></i><span>Dashboard</span></a>
            <a href="<?= BASE_PATH ?>/meus_pedidos" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'pedidos' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>"><i data-lucide="package-check" class="w-4 h-4"></i><span>Meus pedidos</span></a>
          </div>
        </div>

        <!-- ── Vendas ── -->
        <div>
          <p class="px-2 pb-2 text-[11px] uppercase tracking-wider text-zinc-500 font-semibold">Vendas</p>
          <div class="space-y-1">
            <a href="<?= BASE_PATH ?>/vendedor/produtos" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'produtos' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>"><i data-lucide="package" class="w-4 h-4"></i><span>Meus produtos</span></a>
            <a href="<?= BASE_PATH ?>/vendedor/vendas_aprovadas" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'vendas' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>"><i data-lucide="badge-dollar-sign" class="w-4 h-4"></i><span>Vendas</span></a>
            <a href="<?= BASE_PATH ?>/vendedor/perguntas" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'perguntas' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>">
              <i data-lucide="help-circle" class="w-4 h-4"></i><span>Perguntas</span>
              <?php
              if (($uid ?? 0) > 0) {
                  require_once dirname(__DIR__, 2) . '/src/questions.php';
                  $connQa = (new Database())->connect();
                  $qaUnread = questionsUnansweredCount($connQa, (int)$uid);
                  if ($qaUnread > 0):
              ?>
              <span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-orange-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $qaUnread ?></span>
              <?php endif; } ?>
            </a>
          </div>
        </div>

        <!-- ── Financeiro ── -->
        <div>
          <p class="px-2 pb-2 text-[11px] uppercase tracking-wider text-zinc-500 font-semibold">Financeiro</p>
          <div class="space-y-1">
            <a href="<?= BASE_PATH ?>/wallet" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'financeiro' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>"><i data-lucide="landmark" class="w-4 h-4"></i><span>Financeiro</span></a>
            <a href="<?= BASE_PATH ?>/afiliados" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'afiliados' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>"><i data-lucide="share-2" class="w-4 h-4"></i><span>Afiliados</span></a>
          </div>
        </div>

        <!-- ── Comunicação ── -->
        <div>
          <p class="px-2 pb-2 text-[11px] uppercase tracking-wider text-zinc-500 font-semibold">Comunicação</p>
          <div class="space-y-1">
            <a href="<?= BASE_PATH ?>/chat" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'comunicacao' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>">
              <i data-lucide="messages-square" class="w-4 h-4"></i><span>Comunicação</span>
              <?php
              if (($uid ?? 0) > 0) {
                  require_once dirname(__DIR__, 2) . '/src/chat.php';
                  $connChat2 = (new Database())->connect();
                  $chatUnread2 = chatUnreadCount($connChat2, $uid);
                  if ($chatUnread2 > 0):
              ?>
              <span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $chatUnread2 ?></span>
              <?php endif; } ?>
            </a>
          </div>
        </div>

        <!-- ── Conta ── -->
        <div>
          <p class="px-2 pb-2 text-[11px] uppercase tracking-wider text-zinc-500 font-semibold">Conta</p>
          <div class="space-y-1">
            <a href="<?= BASE_PATH ?>/minha_conta" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= $activeMenu === 'minha_conta' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>"><i data-lucide="user-cog" class="w-4 h-4"></i><span>Minha Conta</span></a>
            <a href="<?= BASE_PATH ?>/verificacao" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm border transition <?= ($activeMenu ?? '') === 'verificacao' ? 'bg-white/[0.05] border-white/[0.08] text-white' : 'border-transparent text-zinc-400 hover:bg-white/[0.05] hover:border-white/[0.08] hover:text-white' ?>"><i data-lucide="shield-check" class="w-4 h-4"></i><span>Verificação</span></a>
          </div>
        </div>
      </nav>
    </aside>

    <div class="flex-1 min-w-0">
      <header class="h-16 sticky top-0 z-[80] w-full max-w-full bg-blackx/90 backdrop-blur border-b border-blackx3 px-3 sm:px-4 md:px-6 flex items-center gap-2 sm:gap-3">
        <button id="btnUniOpenSidebar" class="md:hidden shrink-0 rounded-lg border border-blackx3 bg-blackx2 px-2.5 py-1.5 text-zinc-400 hover:text-white transition">
          <i data-lucide="menu" class="w-4 h-4"></i>
        </button>
        <h1 class="min-w-0 flex-1 text-sm sm:text-base md:text-lg font-semibold truncate"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="ml-auto hidden sm:flex shrink-0 text-xs md:text-sm border border-blackx3 rounded-xl px-2 md:px-3 py-1.5 bg-blackx2 items-center gap-1.5">
          <i data-lucide="wallet" class="w-4 h-4 text-zinc-300 hidden sm:block"></i>
          <span class="hidden sm:inline">Saldo:</span> <span class="text-greenx font-semibold">R$ <?= number_format($walletSaldo, 2, ',', '.') ?></span>
        </div>
        <?php
          require_once dirname(__DIR__, 2) . '/src/notifications.php';
          $_uniNotifCount = notificationsUnreadCount((new Database())->connect(), (int)$uid);
        ?>
        <div class="relative" x-data="{openNotif:false}" @click.away="openNotif=false">
          <button @click="openNotif=!openNotif; if(openNotif) $dispatch('notif-open')"
                  class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border transition <?= $_uniNotifCount > 0 ? 'border-yellow-400/30 bg-yellow-500/[0.06] text-yellow-400' : 'border-blackx3 text-zinc-400 hover:text-white' ?>" title="Notificações" id="notifBellBtn">
            <i data-lucide="bell" class="w-4 h-4"></i>
            <span id="notifBadge" class="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-[9px] font-bold text-white flex items-center justify-center <?= $_uniNotifCount > 0 ? '' : 'hidden' ?>"><?= $_uniNotifCount > 99 ? '99+' : $_uniNotifCount ?></span>
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
        <a href="<?= BASE_PATH ?>/minha_conta" class="shrink-0 text-sm border border-blackx3 rounded-xl w-9 h-9 sm:w-auto sm:h-auto sm:px-3 sm:py-1.5 bg-blackx2 inline-flex items-center justify-center gap-2 hover:border-greenx transition"><i data-lucide="user-circle-2" class="w-4 h-4"></i><span class="hidden lg:inline">Minha conta</span></a>
        <a href="<?= BASE_PATH ?>/logout" class="shrink-0 text-sm border border-blackx3 rounded-xl w-9 h-9 sm:w-auto sm:h-auto sm:px-3 sm:py-1.5 bg-blackx2 inline-flex items-center justify-center gap-2 hover:border-red-500 transition"><i data-lucide="log-out" class="w-4 h-4"></i><span class="hidden lg:inline">Sair</span></a>
      </header>
      <main class="p-4 md:p-6">
