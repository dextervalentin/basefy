<?php
declare(strict_types=1);
/**
 * Tickets listing page — storefront
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/storefront.php';
require_once __DIR__ . '/../src/tickets.php';

$userId     = (int)($_SESSION['user_id'] ?? 0);
$isLoggedIn = $userId > 0;
$conn       = (new Database())->connect();
$cartCount  = sfCartCount();

$currentPage = 'tickets';
$pageTitle   = 'Meus Tickets de suporte';

// Ticket categories for the confirmation modal
$ticketCats = ticketCategories();

// If logged in, load user's tickets
$ticketList = [];
$statusCounts = [];
if ($isLoggedIn) {
    $f = [
        'user_id' => $userId,
        'status'  => (string)($_GET['status'] ?? ''),
        'q'       => (string)($_GET['q'] ?? ''),
    ];
    $pagina = max(1, (int)($_GET['p'] ?? 1));
    $ticketList = ticketsList($conn, $f, $pagina, 10);
    $statusCounts = ticketsCountByStatus($conn, $userId);
}

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<div class="min-h-screen bg-blackx">
    <!-- Breadcrumb -->
    <div class="max-w-5xl mx-auto px-4 sm:px-6 pt-6">
        <nav class="flex items-center gap-2 text-sm text-zinc-500 animate-fade-in">
            <a href="/" class="hover:text-greenx transition-colors">Início</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <a href="/central_ajuda" class="hover:text-greenx transition-colors">Central de ajuda</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <span class="text-zinc-300">Tickets</span>
        </nav>
    </div>

    <section class="max-w-5xl mx-auto px-4 sm:px-6 pt-8 pb-4 text-center animate-fade-in-up">
        <h1 class="text-3xl md:text-4xl font-black mb-4">Meus Tickets de suporte</h1>
        <p class="text-zinc-400 max-w-2xl mx-auto leading-relaxed">
            Nós acreditamos que todo o suporte e atenção aos nossos usuários é importante.<br>
            Separamos abaixo alguns dos nossos meios de contato para que você possa fazer compras e vendas com toda a segurança!
        </p>
    </section>

    <?php if (!$isLoggedIn): ?>
    <div class="max-w-2xl mx-auto text-center py-12 px-4">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-greenx/10 border border-greenx/30 flex items-center justify-center">
            <i data-lucide="ticket" class="w-8 h-8 text-purple-400"></i>
        </div>
        <p class="text-zinc-400 mb-4">Você precisa estar logado para acessar seus tickets de suporte.</p>
        <a href="/login?return_to=<?= urlencode('/tickets') ?>" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-greenx text-white font-bold text-sm hover:bg-greenx2 transition-all">
            <i data-lucide="log-in" class="w-4 h-4"></i> Fazer login
        </a>
    </div>
    <?php else: ?>

    <section class="max-w-5xl mx-auto px-4 sm:px-6 pb-16">
        <!-- Create button -->
        <div class="flex justify-center mb-8" x-data="{showConfirm:false}">
            <button @click="showConfirm=true"
                    class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-greenx hover:bg-greenx2 text-white font-bold text-sm transition-all shadow-lg shadow-greenx/20">
                <i data-lucide="plus" class="w-4 h-4"></i> Criar novo Ticket
            </button>

            <!-- Confirmation Modal -->
            <div x-show="showConfirm" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" style="display:none" @click.self="showConfirm=false">
                <div class="bg-blackx2 border border-white/[0.08] rounded-2xl p-6 max-w-md w-full mx-4 shadow-2xl" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold">Você tem certeza?</h3>
                        <button @click="showConfirm=false" class="w-8 h-8 rounded-lg border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-white transition">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <p class="text-sm text-zinc-400 leading-relaxed mb-4">
                        Antes de criar o ticket, você deve conferir abaixo o prazo para nossos protocolos. Caso <strong>não</strong> tenha passado do prazo ainda, <strong>NÃO</strong> crie um ticket sobre o assunto, apenas aguarde o prazo estipulado.
                    </p>
                    <ul class="text-sm text-zinc-300 space-y-1.5 mb-6">
                        <li class="flex items-center gap-2"><span class="text-zinc-500">•</span> Reembolsos: <strong>Até 2 dias úteis</strong></li>
                        <li class="flex items-center gap-2"><span class="text-zinc-500">•</span> Intervenções: <strong>Até 2 dias úteis</strong></li>
                        <li class="flex items-center gap-2"><span class="text-zinc-500">•</span> Retiradas: <strong>Até 1 dia útil</strong></li>
                        <li class="flex items-center gap-2"><span class="text-zinc-500">•</span> Documentos: <strong>Até 1 dia útil</strong></li>
                        <li class="flex items-center gap-2"><span class="text-zinc-500">•</span> Tickets: <strong>Até 1 dia útil</strong></li>
                        <li class="flex items-center gap-2"><span class="text-zinc-500">•</span> Anúncios: <strong>Até 12 horas</strong></li>
                    </ul>
                    <a href="<?= BASE_PATH ?>/tickets_novo"
                       class="w-full flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-greenx hover:bg-greenx2 text-white font-bold text-sm transition-all">
                        Continuar com a criação do Ticket
                    </a>
                </div>
            </div>
        </div>

        <?php if (empty($ticketList['itens'])): ?>
        <!-- Empty state -->
        <div class="text-center py-12">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-zinc-500/10 border border-zinc-400/30 flex items-center justify-center">
                <i data-lucide="ticket" class="w-8 h-8 text-zinc-500"></i>
            </div>
            <p class="text-zinc-400 mb-1">Você ainda não possui ticket de suporte.</p>
            <p class="text-zinc-500 text-sm">Você pode criar o seu primeiro ticket <a href="<?= BASE_PATH ?>/tickets_novo" class="text-greenx hover:underline">clicando aqui</a>.</p>
        </div>
        <?php else: ?>

        <!-- Tickets list -->
        <div class="space-y-3">
            <?php foreach ($ticketList['itens'] as $ticket): ?>
            <a href="<?= BASE_PATH ?>/ticket_detalhe?id=<?= (int)$ticket['id'] ?>"
               class="block bg-blackx2 border border-white/[0.06] rounded-xl p-4 hover:border-greenx/30 transition-all group">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                            <span class="text-xs px-2 py-0.5 rounded-lg <?= ticketStatusBadge((string)$ticket['status']) ?> font-bold uppercase">
                                <?= ticketStatusLabel((string)$ticket['status']) ?>
                            </span>
                            <span class="text-xs text-zinc-500">#<?= (int)$ticket['id'] ?></span>
                        </div>
                        <h3 class="font-semibold text-sm group-hover:text-greenx transition-colors truncate"><?= htmlspecialchars((string)$ticket['titulo']) ?></h3>
                        <p class="text-xs text-zinc-500 mt-1">
                            <?= htmlspecialchars((string)(ticketCategories()[$ticket['categoria']]['label'] ?? $ticket['categoria'])) ?>
                            &middot; <?= fmtDate((string)$ticket['criado_em']) ?>
                        </p>
                        <?php if (!empty($ticket['motivo']) || !empty($ticket['resolution_due_at'])): ?>
                        <p class="text-xs text-zinc-400 mt-1">
                            <?php if (!empty($ticket['motivo'])): ?>Motivo: <?= htmlspecialchars((string)$ticket['motivo']) ?><?php endif; ?>
                            <?php if (!empty($ticket['resolution_due_at'])): ?><?= !empty($ticket['motivo']) ? ' · ' : '' ?>Prazo: <?= fmtDate((string)$ticket['resolution_due_at']) ?><?php endif; ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-zinc-600 group-hover:text-greenx transition-colors mt-1"></i>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ((int)$ticketList['total_paginas'] > 1): ?>
        <div class="flex justify-center gap-2 mt-6">
            <?php for ($pg = 1; $pg <= (int)$ticketList['total_paginas']; $pg++): ?>
            <a href="?p=<?= $pg ?><?= isset($f['status']) && $f['status'] ? '&status=' . urlencode($f['status']) : '' ?>"
               class="px-3 py-1.5 rounded-lg text-sm border transition <?= $pg === (int)$ticketList['pagina'] ? 'bg-greenx/15 border-greenx text-greenx font-bold' : 'border-white/[0.08] text-zinc-400 hover:border-greenx/30' ?>">
                <?= $pg ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- Bottom section -->
        <div class="border-t border-white/[0.06] mt-10 pt-8 text-center">
            <h3 class="text-base font-bold mb-3">Antes de criar um Ticket</h3>
            <p class="text-sm text-zinc-400">
                Você pode tirar suas dúvidas diretamente em nossa comunidade no<br>
                <a href="#" class="text-greenx hover:underline"><i data-lucide="external-link" class="w-3 h-3 inline"></i> Discord</a>
                ou verificar nossas <a href="<?= BASE_PATH ?>/faq" class="text-greenx hover:underline">Perguntas frequentes</a>.
            </p>
        </div>
    </section>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
?>
