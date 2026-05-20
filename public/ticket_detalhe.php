<?php
declare(strict_types=1);
/**
 * Ticket detail — storefront
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
$pageTitle   = 'Ticket de suporte';

$ticketId = (int)($_GET['id'] ?? 0);
$ticket   = null;
$messages = [];
$msgError = '';
$msgOk    = false;

if ($isLoggedIn && $ticketId) {
    $ticket = ticketGetById($conn, $ticketId);
    // Ensure user can only view their own tickets
    if ($ticket && (int)$ticket['user_id'] !== $userId) {
        $ticket = null;
    }
    if ($ticket) {
        $messages = ticketGetMessages($conn, $ticketId);

        // Handle reply
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)$ticket['status'] !== 'fechado') {
            $reply = trim((string)($_POST['mensagem'] ?? ''));
            if (strlen($reply) < 3) {
                $msgError = 'A mensagem deve ter ao menos 3 caracteres.';
            } else {
                ticketAddMessage($conn, $ticketId, $userId, $reply, false);
                $msgOk = true;
                $messages = ticketGetMessages($conn, $ticketId);
                $ticket   = ticketGetById($conn, $ticketId);
            }
        }
    }
}

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<div class="min-h-screen bg-blackx">
    <!-- Breadcrumb -->
    <div class="max-w-3xl mx-auto px-4 sm:px-6 pt-6">
        <nav class="flex items-center gap-2 text-sm text-zinc-500 animate-fade-in">
            <a href="/" class="hover:text-greenx transition-colors">Início</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <a href="/central_ajuda" class="hover:text-greenx transition-colors">Central de ajuda</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <a href="/tickets" class="hover:text-greenx transition-colors">Tickets</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <span class="text-zinc-300">#<?= $ticketId ?></span>
        </nav>
    </div>

    <?php if (!$isLoggedIn): ?>
    <div class="max-w-2xl mx-auto text-center py-16 px-4">
        <p class="text-zinc-400 mb-4">Você precisa estar logado para ver seus tickets.</p>
        <a href="/login?return_to=<?= urlencode('/ticket_detalhe?id=' . $ticketId) ?>" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-greenx text-white font-bold text-sm hover:bg-greenx2 transition-all">
            <i data-lucide="log-in" class="w-4 h-4"></i> Fazer login
        </a>
    </div>

    <?php elseif (!$ticket): ?>
    <div class="max-w-2xl mx-auto text-center py-16 px-4">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 border border-red-400/30 flex items-center justify-center">
            <i data-lucide="alert-circle" class="w-8 h-8 text-red-400"></i>
        </div>
        <p class="text-zinc-400 mb-4">Ticket não encontrado ou sem permissão.</p>
        <a href="<?= BASE_PATH ?>/tickets" class="text-greenx hover:underline text-sm">← Voltar para Tickets</a>
    </div>

    <?php else: ?>
    <section class="max-w-3xl mx-auto px-4 sm:px-6 pt-8 pb-16 animate-fade-in-up">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4 mb-6">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap mb-2">
                    <span class="text-xs px-2.5 py-0.5 rounded-lg <?= ticketStatusBadge((string)$ticket['status']) ?> font-bold uppercase">
                        <?= ticketStatusLabel((string)$ticket['status']) ?>
                    </span>
                    <span class="text-xs text-zinc-500">#<?= (int)$ticket['id'] ?></span>
                </div>
                <h1 class="text-xl md:text-2xl font-black"><?= htmlspecialchars((string)$ticket['titulo']) ?></h1>
            </div>
            <a href="<?= BASE_PATH ?>/tickets" class="shrink-0 inline-flex items-center gap-1.5 text-sm text-zinc-400 hover:text-greenx transition">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Voltar
            </a>
        </div>

        <!-- Info card -->
        <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-4 mb-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <div>
                    <span class="text-zinc-500 text-xs block">Categoria</span>
                    <span class="font-medium"><?= htmlspecialchars((string)(ticketCategories()[$ticket['categoria']]['label'] ?? $ticket['categoria'])) ?></span>
                </div>
                <?php if (!empty($ticket['motivo'])): ?>
                <div>
                    <span class="text-zinc-500 text-xs block">Motivo</span>
                    <span class="font-medium"><?= htmlspecialchars((string)$ticket['motivo']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($ticket['resolution_due_at'])): ?>
                <div>
                    <span class="text-zinc-500 text-xs block">Prazo de resolução</span>
                    <span class="font-medium text-orange-300"><?= fmtDate((string)$ticket['resolution_due_at']) ?></span>
                </div>
                <?php endif; ?>
                <div>
                    <span class="text-zinc-500 text-xs block">Data de criação</span>
                    <span class="font-medium"><?= fmtDate((string)$ticket['criado_em']) ?></span>
                </div>
                <?php if ($ticket['order_id']): ?>
                <div>
                    <span class="text-zinc-500 text-xs block">Pedido relacionado</span>
                    <a href="<?= BASE_PATH ?>/pedido_detalhes?id=<?= (int)$ticket['order_id'] ?>" class="font-medium text-greenx hover:underline">#<?= (int)$ticket['order_id'] ?></a>
                </div>
                <?php endif; ?>
                <div>
                    <span class="text-zinc-500 text-xs block">Última atualização</span>
                    <span class="font-medium"><?= fmtDate((string)$ticket['atualizado_em']) ?></span>
                </div>
            </div>
        </div>

        <!-- Original message -->
        <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-4 mb-4">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-8 h-8 rounded-full bg-greenx/15 border border-greenx/30 flex items-center justify-center text-xs font-bold text-purple-400">
                    <?= strtoupper(mb_substr((string)($ticket['user_nome'] ?? 'U'), 0, 1)) ?>
                </div>
                <div>
                    <span class="text-sm font-semibold"><?= htmlspecialchars((string)($ticket['user_nome'] ?? 'Você')) ?></span>
                    <span class="text-xs text-zinc-500 ml-2"><?= fmtDate((string)$ticket['criado_em']) ?></span>
                </div>
            </div>
            <p class="text-sm text-zinc-300 leading-relaxed whitespace-pre-wrap break-words" style="overflow-wrap:anywhere"><?= htmlspecialchars((string)$ticket['mensagem']) ?></p>
        </div>

        <!-- Thread -->
        <?php if (!empty($messages)): ?>
        <div class="space-y-3 mb-6">
            <?php foreach ($messages as $msg): ?>
            <?php $isAdmin = (bool)$msg['is_admin']; ?>
            <div class="bg-blackx2 border <?= $isAdmin ? 'border-greenx/20' : 'border-white/[0.06]' ?> rounded-xl p-4">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-7 h-7 rounded-full <?= $isAdmin ? 'bg-greenx/15 border-greenx/30 text-greenx' : 'bg-greenx/15 border-greenx/30 text-purple-400' ?> border flex items-center justify-center text-xs font-bold">
                        <?php if ($isAdmin): ?>
                            <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                        <?php else: ?>
                            <?= strtoupper(mb_substr((string)($ticket['user_nome'] ?? 'U'), 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="text-sm font-semibold <?= $isAdmin ? 'text-greenx' : '' ?>">
                            <?= $isAdmin ? 'Suporte Basefy' : htmlspecialchars((string)($ticket['user_nome'] ?? 'Você')) ?>
                        </span>
                        <span class="text-xs text-zinc-500 ml-2"><?= fmtDate((string)$msg['criado_em']) ?></span>
                    </div>
                </div>
                <p class="text-sm text-zinc-300 leading-relaxed whitespace-pre-wrap"><?= htmlspecialchars((string)$msg['mensagem']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Reply form -->
        <?php if ((string)$ticket['status'] !== 'fechado'): ?>
        <?php if ($msgOk): ?>
        <div class="bg-greenx/10 border border-greenx/30 rounded-xl p-3 mb-4 text-sm text-greenx flex items-center gap-2">
            <i data-lucide="check-circle" class="w-4 h-4 shrink-0"></i> Mensagem enviada com sucesso!
        </div>
        <?php endif; ?>
        <?php if ($msgError): ?>
        <div class="bg-red-500/10 border border-red-400/30 rounded-xl p-3 mb-4 text-sm text-red-400 flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i> <?= htmlspecialchars($msgError) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="bg-blackx2 border border-white/[0.06] rounded-xl p-4">
            <label class="block text-sm font-semibold mb-2 text-zinc-300">Responder</label>
            <textarea name="mensagem" required minlength="3" rows="4"
                      placeholder="Digite sua resposta..."
                      class="w-full bg-blackx border border-white/[0.08] rounded-xl p-3 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-greenx/60 focus:ring-1 focus:ring-greenx/30 outline-none transition resize-y mb-3"></textarea>
            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-greenx text-white font-bold text-sm hover:bg-greenx2 transition-all">
                    <i data-lucide="send" class="w-4 h-4"></i> Enviar resposta
                </button>
            </div>
        </form>
        <?php else: ?>
        <div class="bg-zinc-500/10 border border-zinc-400/20 rounded-xl p-4 text-center text-sm text-zinc-400">
            <i data-lucide="lock" class="w-4 h-4 inline -mt-0.5"></i> Este ticket foi fechado e não aceita novas respostas.
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
?>
