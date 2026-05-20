<?php
declare(strict_types=1);
/**
 * Shared storefront navigation bar.
 * Expects: $cartCount (int), $isLoggedIn (bool), $userId (int), $userRole (string)
 * Optional: $currentPage (string) – 'home'|'categorias'|'carrinho'|'checkout'|'conta'
 */
$cartCount   = (int)($cartCount ?? 0);
$isLoggedIn  = (bool)($isLoggedIn ?? false);
$userId      = (int)($userId ?? 0);
$userRole    = (string)($userRole ?? 'usuario');
$currentPage = (string)($currentPage ?? '');
if (!function_exists('sfPainelUrl')) {
    function sfPainelUrl(int $uid, string $role): string {
        if ($uid <= 0) return BASE_PATH . '/login';
        $r = strtolower(trim($role));
        if ($r === 'admin' || $r === 'administrador') return BASE_PATH . '/admin/dashboard';
        return BASE_PATH . '/dashboard';
    }
}
$_isPendingVendor = false;
?>
<header class="storefront-header fixed left-1/2 top-5 z-50 w-[calc(100%-2rem)] max-w-[1440px] -translate-x-1/2 rounded-[30px] border border-white/[0.14] bg-[#080012]/72 backdrop-blur-2xl shadow-2xl shadow-black/25">
    <div class="px-5 sm:px-7">
        <!-- Desktop nav -->
        <div class="flex items-center justify-between h-16">
            <!-- Logo -->
            <a href="<?= BASE_PATH ?>/" class="flex items-center gap-3 group shrink-0" aria-label="Basefy">
                <img src="<?= BASE_PATH ?>/assets/img/logo22.png" alt="Basefy" class="h-8 w-auto object-contain">
            </a>

            <!-- Center nav links (desktop) -->
            <nav class="hidden md:flex items-center gap-1">
                <a href="<?= BASE_PATH ?>/"
                   class="px-3.5 py-2 rounded-xl text-sm font-medium transition-all <?= $currentPage === 'home' ? 'bg-white/[0.08] text-white' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]' ?>">
                    Início
                </a>
                <a href="<?= BASE_PATH ?>/#catalogo"
                   class="px-3.5 py-2 rounded-xl text-sm font-medium transition-all <?= $currentPage === 'categorias' ? 'bg-white/[0.08] text-white' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]' ?>">
                    Catálogo
                </a>
                <a href="<?= BASE_PATH ?>/como_funciona"
                   class="px-3.5 py-2 rounded-xl text-sm font-medium transition-all <?= $currentPage === 'como_funciona' ? 'bg-white/[0.08] text-white' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]' ?>">
                    Como funciona
                </a>
                <?php
                    // Show blog link if enabled for role
                    $_blogNavShow = false;
                    try {
                        require_once __DIR__ . '/../../src/blog.php';
                        $_blogNavRole = $isLoggedIn ? ($userRole ?: 'usuario') : 'public';
                        $_blogNavShow = blogIsVisibleForRole($conn, $_blogNavRole);
                    } catch (\Throwable $e) {}
                    if ($_blogNavShow):
                ?>
                <a href="<?= BASE_PATH ?>/blog"
                   class="px-3.5 py-2 rounded-xl text-sm font-medium transition-all <?= ($currentPage ?? '') === 'blog' ? 'bg-white/[0.08] text-white' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]' ?>">
                    Blog
                </a>
                <?php endif; ?>
                <?php if ($isLoggedIn && !$_isPendingVendor): ?>
                <a href="<?= BASE_PATH ?>/meus_pedidos"
                   class="px-3.5 py-2 rounded-xl text-sm font-medium transition-all <?= ($currentPage ?? '') === 'meus_pedidos' ? 'bg-white/[0.08] text-white' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]' ?>">
                    Meus Pedidos
                </a>
                <?php endif; ?>
            </nav>

            <!-- Right side -->
            <div class="flex shrink-0 items-center gap-1 sm:gap-1.5">
                <?php
                    // Wallet balance for logged-in users
                    $_navWalletBalance = 0;
                    if ($isLoggedIn && function_exists('sfWalletSaldo')) {
                        try { $_navWalletBalance = sfWalletSaldo($conn, $userId); } catch (\Throwable $e) {}
                    }
                ?>
                <!-- Search toggle (desktop) -->
                <button onclick="document.getElementById('sf-search-bar').classList.toggle('hidden')"
                        class="hidden md:flex nav-icon-btn relative h-9 w-9 rounded-xl border border-white/[0.08] items-center justify-center text-zinc-400 hover:text-white hover:border-white/[0.15] transition-colors"
                        title="Buscar" aria-label="Buscar">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </button>

                <?php if ($isLoggedIn && !$_isPendingVendor): ?>
                <!-- Wallet -->
                <a href="<?= BASE_PATH ?>/wallet"
                   class="hidden md:flex nav-icon-btn relative h-9 w-9 rounded-xl border items-center justify-center transition-colors <?= $_navWalletBalance > 0 ? 'border-greenx/30 bg-greenx/[0.06] text-greenx' : 'border-white/[0.08] text-zinc-400 hover:text-white hover:border-white/[0.15]' ?>"
                   title="Carteira&nbsp;·&nbsp;R$ <?= number_format($_navWalletBalance, 2, ',', '.') ?>" aria-label="Carteira">
                    <i data-lucide="wallet" class="w-4 h-4"></i>
                    <?php if ($_navWalletBalance > 0): ?>
                    <span class="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-greenx animate-pulse pointer-events-none z-10"></span>
                    <?php endif; ?>
                </a>

                <!-- Chat -->
                <?php
                    $sfChatUnread = 0;
                    try {
                        require_once __DIR__ . '/../../src/chat.php';
                        $sfChatUnread = chatUnreadCount($conn, $userId);
                    } catch (\Throwable $e) {}
                ?>
                <a href="<?= BASE_PATH ?>/chat"
                   class="hidden md:flex nav-icon-btn relative h-9 w-9 rounded-xl border items-center justify-center transition-colors <?= $currentPage === 'chat' ? 'border-greenx/40 bg-greenx/[0.08] text-greenx' : 'border-white/[0.08] text-zinc-400 hover:text-white hover:border-white/[0.15]' ?>"
                   title="Chat" aria-label="Chat">
                    <i data-lucide="message-circle" class="w-4 h-4"></i>
                    <?php if ($sfChatUnread > 0): ?>
                    <span class="absolute -top-1.5 -right-1.5 min-w-[20px] h-[20px] flex items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white px-1 ring-2 ring-blackx pointer-events-none z-10"><?= $sfChatUnread > 99 ? '99+' : $sfChatUnread ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <!-- Notifications Bell -->
                <?php if ($isLoggedIn):
                    $_notifCount = 0;
                    try {
                        require_once __DIR__ . '/../../src/notifications.php';
                        $_notifCount = notificationsUnreadCount($conn, (int)$userId);
                    } catch (\Throwable $e) {}
                ?>
                <div class="relative shrink-0" x-data="{openNotif:false}" @click.away="openNotif=false">
                    <button @click="openNotif=!openNotif; if(openNotif) $dispatch('notif-open')"
                            class="flex nav-icon-btn relative h-9 w-9 rounded-xl border items-center justify-center transition-colors <?= $_notifCount > 0 ? 'border-yellow-400/30 bg-yellow-500/[0.06] text-yellow-400' : 'border-white/[0.08] text-zinc-400 hover:text-white hover:border-white/[0.15]' ?>"
                            title="Notificações" aria-label="Notificações" id="notifBellBtn">
                        <i data-lucide="bell" class="w-4 h-4"></i>
                        <?php if ($_notifCount > 0): ?>
                        <span id="notifBadge" class="absolute -top-1.5 -right-1.5 min-w-[20px] h-[20px] flex items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white px-1 ring-2 ring-blackx pointer-events-none z-10"><?= $_notifCount > 99 ? '99+' : $_notifCount ?></span>
                        <?php else: ?>
                        <span id="notifBadge" class="absolute -top-1.5 -right-1.5 min-w-[20px] h-[20px] flex items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white px-1 ring-2 ring-blackx pointer-events-none z-10 hidden">0</span>
                        <?php endif; ?>
                    </button>

                    <!-- Notification dropdown -->
                    <div x-show="openNotif" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-1" class="fixed inset-x-0 top-[5.5rem] mx-2 max-w-[calc(100vw-1rem)] sm:absolute sm:inset-auto sm:right-0 sm:top-auto sm:mx-0 sm:mt-2 sm:w-[420px] sm:max-w-[420px] bg-blackx2 border border-white/[0.08] rounded-2xl shadow-2xl shadow-black/40 overflow-hidden z-[9999]" style="display:none;max-height:calc(100vh - 6rem);max-height:calc(100dvh - 6rem)">
                        <div class="p-3 border-b border-white/[0.06] flex items-center justify-between">
                            <h3 class="font-semibold text-sm">Notificações</h3>
                            <div class="flex items-center gap-2">
                                <!-- Sound toggle -->
                                <button onclick="toggleNotifSound()" class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors flex items-center gap-1" title="Som de notificação" id="notifSoundBtn">
                                    <i data-lucide="volume-2" class="w-3.5 h-3.5 notif-sound-on"></i>
                                    <i data-lucide="volume-x" class="w-3.5 h-3.5 notif-sound-off hidden"></i>
                                </button>
                                <button onclick="markAllNotifRead()" class="text-xs text-greenx hover:underline">Marcar tudo lido</button>
                            </div>
                        </div>
                        <div class="flex flex-col sm:flex-row">
                            <!-- Category tabs — horizontal on mobile, vertical sidebar on desktop -->
                            <div class="flex sm:flex-col sm:w-[52px] shrink-0 border-b sm:border-b-0 sm:border-r border-white/[0.06] py-1 sm:py-2 px-1 sm:px-0 gap-0 sm:gap-1 bg-white/[0.02] overflow-x-auto">
                                <button onclick="filterNotif('all')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="all" title="Todos">
                                    <i data-lucide="inbox" class="w-4 h-4"></i>
                                    <span class="text-[8px] font-semibold leading-none">Todos</span>
                                </button>
                                <button onclick="filterNotif('anuncio')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="anuncio" title="Anúncios">
                                    <i data-lucide="megaphone" class="w-4 h-4"></i>
                                    <span class="text-[8px] font-semibold leading-none">Anúnc.</span>
                                </button>
                                <button onclick="filterNotif('venda')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="venda" title="Vendas">
                                    <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                                    <span class="text-[8px] font-semibold leading-none">Vendas</span>
                                </button>
                                <button onclick="filterNotif('chat')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="chat" title="Chats">
                                    <i data-lucide="message-circle" class="w-4 h-4"></i>
                                    <span class="text-[8px] font-semibold leading-none">Chats</span>
                                </button>
                                <button onclick="filterNotif('ticket')" class="notif-tab flex flex-col items-center gap-0.5 px-2 sm:px-1 py-1.5 sm:py-2 rounded-lg sm:mx-1 transition-all text-zinc-400 hover:text-white hover:bg-white/[0.06] shrink-0" data-tab="ticket" title="Tickets">
                                    <i data-lucide="flag" class="w-4 h-4"></i>
                                    <span class="text-[8px] font-semibold leading-none">Tickets</span>
                                </button>
                            </div>
                            <!-- Notification list -->
                            <div id="notifList" class="flex-1 max-h-[calc(100vh-14rem)] sm:max-h-80 overflow-y-auto divide-y divide-white/[0.04]">
                                <div class="p-6 text-center text-zinc-500 text-sm">Carregando...</div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Favorites -->
                <?php if ($isLoggedIn): ?>
                <a href="<?= BASE_PATH ?>/favoritos"
                   class="relative flex nav-icon-btn h-9 w-9 rounded-xl border transition-colors items-center justify-center <?= ($currentPage ?? '') === 'favoritos' ? 'border-red-400/40 bg-red-500/[0.08] text-red-400' : 'border-white/[0.08] text-zinc-400 hover:text-red-400 hover:border-red-400/30' ?>"
                   title="Favoritos" aria-label="Favoritos">
                    <i data-lucide="heart" class="w-4 h-4"></i>
                </a>
                <?php endif; ?>

                <!-- Cart -->
                <a href="<?= BASE_PATH ?>/carrinho"
                   class="relative flex nav-icon-btn h-9 w-9 rounded-xl border transition-colors items-center justify-center <?= $currentPage === 'carrinho' ? 'border-greenx/40 bg-greenx/[0.08] text-greenx' : 'border-white/[0.08] text-zinc-400 hover:text-white hover:border-white/[0.15]' ?>"
                   title="Carrinho" aria-label="Carrinho">
                    <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                    <?php if ($cartCount > 0): ?>
                    <span class="absolute -top-2 -right-2 min-w-[20px] h-[20px] flex items-center justify-center rounded-full bg-greenx text-[10px] font-bold text-white px-1 shadow-lg shadow-greenx/30 ring-2 ring-blackx pointer-events-none z-10" data-cart-count><?= (int)$cartCount ?></span>
                    <?php endif; ?>
                </a>

                <?php if ($isLoggedIn): ?>
                    <a href="<?= htmlspecialchars(sfPainelUrl($userId, $userRole), ENT_QUOTES, 'UTF-8') ?>"
                       class="hidden sm:flex h-9 px-4 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold text-sm items-center gap-2 shadow-lg shadow-greenx/20 hover:shadow-greenx/30 transition-all">
                        <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i>
                        Painel
                    </a>
                    <a href="<?= BASE_PATH ?>/logout"
                       class="hidden sm:flex w-9 h-9 rounded-xl border border-white/[0.08] items-center justify-center text-zinc-400 hover:text-red-400 hover:border-red-400/30 transition-all"
                       title="Sair">
                        <i data-lucide="log-out" class="w-4 h-4"></i>
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_PATH ?>/login"
                       class="hidden sm:flex h-9 px-3.5 rounded-xl border border-white/[0.08] text-sm text-zinc-300 hover:text-white hover:border-white/[0.15] items-center transition-all">
                        Entrar
                    </a>
                    <a href="<?= BASE_PATH ?>/register"
                       class="hidden sm:flex h-9 px-4 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold text-sm items-center shadow-lg shadow-greenx/20 hover:shadow-greenx/30 transition-all">
                        Criar conta
                    </a>
                <?php endif; ?>

                <!-- Mobile hamburger -->
                <button id="sfMobileMenuBtn"
                        class="md:hidden flex w-9 h-9 rounded-xl border border-white/[0.08] items-center justify-center text-zinc-400 hover:text-white transition-all">
                    <i data-lucide="menu" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        <!-- Global search bar (toggled) -->
        <div id="sf-search-bar" class="hidden pb-3">
            <form method="get" action="<?= BASE_PATH ?>/categorias" class="flex gap-2">
                <div class="relative flex-1">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-500 pointer-events-none"></i>
                    <input type="text" name="q" placeholder="Buscar produtos, categorias, vendedores..."
                           class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-white placeholder:text-zinc-500 focus:outline-none focus:border-greenx/50 focus:ring-1 focus:ring-greenx/20 transition-all">
                </div>
                <button class="rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-5 py-2.5 text-sm transition-colors">Buscar</button>
            </form>
        </div>
    </div>
</header>
<?php if ($currentPage !== 'home'): ?>
<div class="h-24 shrink-0" aria-hidden="true"></div>
<?php endif; ?>

<!-- Off-canvas mobile menu (slides from right) -->
<div id="sfMobileOverlay" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[60] hidden transition-opacity duration-300 md:hidden" style="opacity:0"></div>
<div id="sfMobileDrawer" class="fixed top-0 right-0 h-full w-[300px] max-w-[85vw] bg-blackx2 border-l border-white/[0.06] z-[61] transform translate-x-full transition-transform duration-300 ease-out md:hidden flex flex-col shadow-2xl shadow-black/40">
    <!-- Drawer header -->
    <div class="h-16 px-5 flex items-center justify-between border-b border-white/[0.06] shrink-0">
        <img src="<?= BASE_PATH ?>/assets/img/logo22.png" alt="Basefy" class="h-8 w-auto object-contain">
        <button id="sfMobileMenuClose" class="w-9 h-9 rounded-xl border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-white hover:border-white/[0.15] transition-all">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>

    <!-- Drawer search -->
    <div class="px-4 pt-4 pb-2 shrink-0">
        <form method="get" action="<?= BASE_PATH ?>/categorias" class="flex gap-2">
            <div class="relative flex-1">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-500 pointer-events-none"></i>
                <input type="text" name="q" placeholder="Buscar..."
                       class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-white placeholder:text-zinc-500 focus:outline-none focus:border-greenx/50">
            </div>
            <button class="rounded-xl bg-greenx text-white font-semibold px-4 py-2.5 text-sm">
                <i data-lucide="search" class="w-4 h-4"></i>
            </button>
        </form>
    </div>

    <!-- Drawer links -->
    <nav class="flex-1 overflow-y-auto px-3 py-2 space-y-1">
        <a href="<?= BASE_PATH ?>/"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-all <?= $currentPage === 'home' ? 'bg-greenx/[0.12] text-greenx border border-greenx/30' : 'text-zinc-300 hover:bg-white/[0.04] border border-transparent' ?>">
            <i data-lucide="home" class="w-4 h-4"></i> Início
        </a>
        <a href="<?= BASE_PATH ?>/#catalogo"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-all <?= $currentPage === 'categorias' ? 'bg-greenx/[0.12] text-greenx border border-greenx/30' : 'text-zinc-300 hover:bg-white/[0.04] border border-transparent' ?>">
            <i data-lucide="grid-3x3" class="w-4 h-4"></i> Catálogo
        </a>
        <a href="<?= BASE_PATH ?>/como_funciona"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-all <?= $currentPage === 'como_funciona' ? 'bg-greenx/[0.12] text-greenx border border-greenx/30' : 'text-zinc-300 hover:bg-white/[0.04] border border-transparent' ?>">
            <i data-lucide="help-circle" class="w-4 h-4"></i> Como Funciona
        </a>
        <?php
            $_blogMobileShow = false;
            try { $_blogMobileShow = blogIsVisibleForRole($conn, $_blogNavRole ?? 'public'); } catch (\Throwable $e) {}
            if ($_blogMobileShow):
        ?>
        <a href="<?= BASE_PATH ?>/blog"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-all <?= ($currentPage ?? '') === 'blog' ? 'bg-greenx/[0.12] text-greenx border border-greenx/30' : 'text-zinc-300 hover:bg-white/[0.04] border border-transparent' ?>">
            <i data-lucide="book-open" class="w-4 h-4"></i> Blog
        </a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/carrinho"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-all <?= $currentPage === 'carrinho' ? 'bg-greenx/[0.12] text-greenx border border-greenx/30' : 'text-zinc-300 hover:bg-white/[0.04] border border-transparent' ?>">
            <i data-lucide="shopping-bag" class="w-4 h-4"></i> Carrinho
            <?php if ($cartCount > 0): ?>
            <span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-greenx text-[10px] font-bold text-white flex items-center justify-center"><?= (int)$cartCount ?></span>
            <?php endif; ?>
        </a>
        <?php if ($isLoggedIn): ?>
        <a href="<?= BASE_PATH ?>/favoritos"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-all <?= ($currentPage ?? '') === 'favoritos' ? 'bg-red-500/[0.12] text-red-400 border border-red-400/30' : 'text-zinc-300 hover:bg-white/[0.04] border border-transparent' ?>">
            <i data-lucide="heart" class="w-4 h-4"></i> Favoritos
        </a>
        <?php endif; ?>

        <?php if ($isLoggedIn): ?>
        <?php if ($_isPendingVendor): ?>
        <div class="pt-2 pb-1 px-2"><p class="text-[10px] uppercase tracking-widest text-zinc-500 font-semibold">Conta em análise</p></div>
        <a href="<?= BASE_PATH ?>/vendedor/aprovacao"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium text-yellow-400 bg-yellow-500/[0.08] border border-yellow-400/20 transition-all">
            <i data-lucide="clock" class="w-4 h-4"></i> Aguardando Aprovação
        </a>
        <?php else: ?>
        <div class="pt-2 pb-1 px-2"><p class="text-[10px] uppercase tracking-widest text-zinc-500 font-semibold">Minha conta</p></div>
        <a href="<?= BASE_PATH ?>/wallet"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium border border-transparent transition-all text-zinc-300 hover:bg-white/[0.04]">
            <i data-lucide="wallet" class="w-4 h-4 <?= $_navWalletBalance > 0 ? 'text-greenx' : '' ?>"></i> Carteira
            <span class="ml-auto text-xs font-bold <?= $_navWalletBalance > 0 ? 'text-greenx' : 'text-zinc-500' ?>">R$ <?= number_format($_navWalletBalance, 2, ',', '.') ?></span>
        </a>
        <a href="<?= BASE_PATH ?>/meus_pedidos"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium border border-transparent transition-all <?= ($currentPage ?? '') === 'meus_pedidos' ? 'bg-white/[0.08] text-white border-white/[0.08]' : 'text-zinc-300 hover:bg-white/[0.04]' ?>">
            <i data-lucide="package-check" class="w-4 h-4"></i> Meus Pedidos
        </a>
        <a href="<?= BASE_PATH ?>/chat"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium border border-transparent transition-all <?= ($currentPage ?? '') === 'chat' ? 'bg-white/[0.08] text-white border-white/[0.08]' : 'text-zinc-300 hover:bg-white/[0.04]' ?>">
            <i data-lucide="message-circle" class="w-4 h-4"></i> Chat
            <?php if (isset($sfChatUnread) && $sfChatUnread > 0): ?>
            <span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $sfChatUnread > 99 ? '99+' : $sfChatUnread ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(sfPainelUrl($userId, $userRole), ENT_QUOTES, 'UTF-8') ?>"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium text-greenx hover:bg-greenx/[0.08] border border-transparent transition-all">
            <i data-lucide="layout-dashboard" class="w-4 h-4"></i> <?= $_isPendingVendor ? 'Minha Solicitação' : 'Ir para Painel' ?>
        </a>
        <?php endif; ?>
    </nav>

    <!-- Drawer footer -->
    <div class="px-3 py-4 border-t border-white/[0.06] space-y-2 shrink-0">
        <?php if ($isLoggedIn): ?>
        <a href="<?= BASE_PATH ?>/logout"
           class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl border border-red-500/30 text-red-400 text-sm font-medium hover:bg-red-500/10 transition-all">
            <i data-lucide="log-out" class="w-4 h-4"></i> Sair
        </a>
        <?php else: ?>
        <a href="<?= BASE_PATH ?>/login"
           class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl border border-white/[0.1] text-zinc-300 text-sm font-medium hover:bg-white/[0.04] transition-all">
            <i data-lucide="log-in" class="w-4 h-4"></i> Entrar
        </a>
        <a href="<?= BASE_PATH ?>/register"
           class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white text-sm font-semibold shadow-lg shadow-greenx/20 transition-all">
            <i data-lucide="user-plus" class="w-4 h-4"></i> Criar conta
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
  const btn     = document.getElementById('sfMobileMenuBtn');
  const close   = document.getElementById('sfMobileMenuClose');
  const drawer  = document.getElementById('sfMobileDrawer');
  const overlay = document.getElementById('sfMobileOverlay');
  if (!btn || !drawer || !overlay) return;

  const open = () => {
    overlay.classList.remove('hidden');
    requestAnimationFrame(() => {
      overlay.style.opacity = '1';
      drawer.classList.remove('translate-x-full');
    });
    document.body.style.overflow = 'hidden';
  };

  const shut = () => {
    overlay.style.opacity = '0';
    drawer.classList.add('translate-x-full');
    document.body.style.overflow = '';
    setTimeout(() => overlay.classList.add('hidden'), 300);
  };

  btn.addEventListener('click', open);
  if (close) close.addEventListener('click', shut);
  overlay.addEventListener('click', shut);
    drawer.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', (event) => {
            // If link points to #catalogo and that section exists on the current page, scroll smoothly.
            try {
                const href = link.getAttribute('href') || '';
                if (href.indexOf('#catalogo') !== -1) {
                    const target = document.getElementById('catalogo');
                    if (target) {
                        event.preventDefault();
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        if (history.replaceState) history.replaceState(null, '', window.location.pathname + '#catalogo');
                    }
                }
            } catch (_) { /* fall through to default navigation */ }
            shut();
        });
    });

  // Close on resize to desktop
  window.addEventListener('resize', () => {
    if (window.innerWidth >= 768) shut();
  });

  // Re-init Lucide icons inside drawer
  if (window.lucide) setTimeout(() => lucide.createIcons(), 50);
})();
</script>
