<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ── Build version marker (change whenever deploying) ──
define('CHECKOUT_VERSION', 'v7-ajax-' . date('Ymd'));

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/storefront.php';
require_once __DIR__ . '/../src/seller_levels.php';

// Allow any logged-in user (usuario, vendedor, admin) to checkout
if (!usuarioLogado()) {
    header('Location: ' . BASE_PATH . '/login?redirect=checkout');
    exit;
}

$userId     = (int)($_SESSION['user_id'] ?? 0);
$userRole   = (string)($_SESSION['user']['role'] ?? 'usuario');
$isLoggedIn = true;

$conn = (new Database())->connect();

$summary     = sfCartSummary($conn);
$items       = $summary['items'];
$totalBruto  = (float)$summary['total'];
$count       = (int)$summary['count'];
$walletSaldo = sfWalletSaldo($conn, (int)$userId);
$cartCount   = sfCartCount();

$useWalletDefault = false;
$feedback = '';

// PIX modal is now handled 100% via AJAX (no server-side POST handler for PIX)
$showPixModal = false;
$pixOrderId   = 0;
$pixQrImage   = '';
$pixCopyPaste = '';
$pixValor     = 0.0;
$pixError     = '';

if (!$items) {
    header('Location: ' . BASE_PATH . '/carrinho');
    exit;
}

// Buyer service fee (4.99%)
$buyerFeePct  = buyerServiceFeePercent($conn);
$buyerFeeAmt  = round($totalBruto * ($buyerFeePct / 100), 2);
$totalComTaxa = round($totalBruto + $buyerFeeAmt, 2);

$walletAplicavel = min($walletSaldo, $totalComTaxa);
$walletAplicadoInicial = $useWalletDefault ? $walletAplicavel : 0.0;
$pixEstimado     = max(0, $totalComTaxa - $walletAplicadoInicial);

$currentPage = 'checkout';
$pageTitle   = 'Checkout';
include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<div class="min-h-screen bg-blackx">
    <!-- Progress steps -->
    <div class="border-b border-white/[0.04] bg-white/[0.01]">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5">
            <div class="flex items-center justify-center gap-0">
                <!-- Step 1 - Cart -->
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-greenx/20 border border-greenx/40 flex items-center justify-center">
                        <i data-lucide="check" class="w-4 h-4 text-greenx"></i>
                    </div>
                    <span class="text-sm text-greenx font-medium hidden sm:inline">Carrinho</span>
                </div>
                <div class="w-12 sm:w-20 h-px bg-greenx/40 mx-2"></div>
                <!-- Step 2 - Checkout -->
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-greenx flex items-center justify-center pulse-green">
                        <span class="text-xs font-bold text-black">2</span>
                    </div>
                    <span class="text-sm text-white font-semibold hidden sm:inline">Checkout</span>
                </div>
                <div class="w-12 sm:w-20 h-px bg-white/[0.08] mx-2"></div>
                <!-- Step 3 - Payment -->
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full border border-white/[0.1] bg-white/[0.03] flex items-center justify-center">
                        <span class="text-xs font-bold text-zinc-500">3</span>
                    </div>
                    <span class="text-sm text-zinc-500 hidden sm:inline">Pagamento</span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($feedback !== ''): ?>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 mt-4 animate-scale-in">
        <div class="flex items-center gap-3 rounded-2xl border border-red-500/30 bg-red-500/[0.06] px-5 py-3.5">
            <div class="w-8 h-8 rounded-full bg-red-500/20 flex items-center justify-center flex-shrink-0"><i data-lucide="alert-circle" class="w-4 h-4 text-red-400"></i></div>
            <p class="text-sm text-red-300"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
    <?php endif; ?>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
        <h1 class="text-2xl font-bold mb-6 animate-fade-in-up">Finalizar pedido</h1>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <!-- Order items (3/5) -->
            <section class="lg:col-span-3 animate-fade-in-up stagger-1">
                <div class="rounded-3xl border border-white/[0.06] bg-blackx2 p-5 sm:p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <i data-lucide="package" class="w-5 h-5 text-greenx"></i>
                        <h2 class="font-semibold">Itens do pedido</h2>
                        <span class="ml-auto text-xs text-zinc-500"><?= $count ?> <?= $count === 1 ? 'item' : 'itens' ?></span>
                    </div>
                    <div class="space-y-3">
                        <?php foreach ($items as $item): ?>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white/[0.02] border border-white/[0.04]">
                            <div class="w-14 h-14 rounded-xl overflow-hidden border border-white/[0.06] flex-shrink-0 bg-blackx">
                                <img src="<?= htmlspecialchars(sfImageUrl((string)($item['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                     alt="" class="w-full h-full object-cover">
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-sm truncate"><?= htmlspecialchars((string)$item['nome'], ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if (!empty($item['variante_nome'])): ?>
                                <p class="text-xs text-zinc-400 mt-0.5"><?= htmlspecialchars((string)$item['variante_nome'], ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-zinc-500 mt-0.5"><?= (int)$item['qty'] ?> &times; R$&nbsp;<?= number_format((float)$item['preco'], 2, ',', '.') ?></p>
                            </div>
                            <p class="font-bold text-sm text-greenx flex-shrink-0">R$&nbsp;<?= number_format((float)$item['line_total'], 2, ',', '.') ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 pt-4 border-t border-white/[0.04]">
                        <a href="<?= BASE_PATH ?>/carrinho" class="inline-flex items-center gap-1.5 text-xs text-zinc-500 hover:text-greenx transition-colors">
                            <i data-lucide="arrow-left" class="w-3 h-3"></i>
                            Voltar ao carrinho
                        </a>
                    </div>
                </div>
            </section>

            <!-- Payment panel (2/5) -->
            <aside class="lg:col-span-2 animate-fade-in-up stagger-2">
                <div class="sticky top-20 rounded-3xl border border-white/[0.06] bg-blackx2 p-5 sm:p-6 space-y-5">
                    <div class="flex items-center gap-2">
                        <i data-lucide="credit-card" class="w-5 h-5 text-greenx"></i>
                        <h2 class="font-semibold">Pagamento</h2>
                    </div>

                    <!-- Totals breakdown -->
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-zinc-400">Subtotal</span>
                            <span class="font-medium">R$&nbsp;<?= number_format($totalBruto, 2, ',', '.') ?></span>
                        </div>

                        <?php if ($buyerFeeAmt > 0): ?>
                        <div class="flex justify-between">
                            <span class="text-zinc-400">Taxa de serviço (<?= number_format($buyerFeePct, 2, ',', '.') ?>%)</span>
                            <span class="font-medium">R$&nbsp;<?= number_format($buyerFeeAmt, 2, ',', '.') ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($walletSaldo > 0): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-zinc-400">Saldo carteira</span>
                            <span class="font-medium flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-purple-400"></span>
                                R$&nbsp;<?= number_format($walletSaldo, 2, ',', '.') ?>
                            </span>
                        </div>
                        <div class="flex justify-between text-greenx">
                            <span>Desconto wallet</span>
                            <span class="font-bold" id="wallet-discount">- R$&nbsp;<?= number_format($walletAplicadoInicial, 2, ',', '.') ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="border-t border-white/[0.06] pt-3 flex justify-between items-end">
                            <span class="font-semibold">PIX a pagar</span>
                            <span class="text-2xl font-black text-greenx" id="pix-total">
                                R$&nbsp;<?= number_format($pixEstimado, 2, ',', '.') ?>
                            </span>
                        </div>

                        <?php if ($walletSaldo >= $totalComTaxa): ?>
                        <div id="wallet-full-paid-badge" class="<?= $pixEstimado <= 0 ? '' : 'hidden ' ?>flex items-center gap-2 px-3 py-2 rounded-xl bg-greenx/[0.08] border border-greenx/20">
                            <i data-lucide="sparkles" class="w-4 h-4 text-greenx"></i>
                            <span class="text-xs text-greenx">Pago 100% com saldo da carteira!</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Place order form -->
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="action" value="place_order">

                        <?php if ($walletSaldo > 0): ?>
                        <label class="flex items-start gap-3 p-3.5 rounded-2xl border border-white/[0.06] bg-white/[0.02] hover:border-greenx/30 transition-all cursor-pointer group">
                            <input type="checkbox" name="use_wallet" id="use-wallet-checkbox" value="1" <?= $useWalletDefault ? 'checked' : '' ?>
                                   class="mt-0.5 w-4 h-4 rounded border-white/20 bg-white/[0.06] text-greenx focus:ring-greenx/30 focus:ring-offset-0">
                            <div>
                                <span class="text-sm font-semibold group-hover:text-greenx transition-colors">Usar saldo da carteira</span>
                                <p class="text-xs text-zinc-500 mt-0.5">Abater R$&nbsp;<?= number_format($walletAplicavel, 2, ',', '.') ?> do total. PIX será gerado apenas para o valor restante.</p>
                            </div>
                        </label>
                        <?php endif; ?>

                    <button id="btnPlaceOrder" type="submit" class="w-full flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-6 py-4 text-sm shadow-lg shadow-greenx/25 hover:shadow-greenx/35 transition-all">
                            <i data-lucide="lock" class="w-4 h-4"></i>
                            Finalizar pedido
                        </button>
                    </form>

                    <p class="text-[11px] text-zinc-600 text-center leading-relaxed">
                        Ao finalizar, <?= $pixEstimado > 0 ? 'um QR Code PIX será gerado para pagamento imediato.' : 'o pedido será processado com seu saldo da carteira.' ?>
                    </p>

                    <!-- Trust -->
                    <div class="pt-3 border-t border-white/[0.06] flex items-center justify-center gap-4">
                        <div class="flex items-center gap-1.5 text-[10px] text-zinc-600">
                            <i data-lucide="shield-check" class="w-3.5 h-3.5 text-greenx/60"></i>
                            Escrow
                        </div>
                        <div class="flex items-center gap-1.5 text-[10px] text-zinc-600">
                            <i data-lucide="lock" class="w-3.5 h-3.5 text-greenx/60"></i>
                            Seguro
                        </div>
                        <div class="flex items-center gap-1.5 text-[10px] text-zinc-600">
                            <i data-lucide="qr-code" class="w-3.5 h-3.5 text-greenx/60"></i>
                            PIX
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </main>
</div>

<?php if ($showPixModal || $pixError !== ''): ?>
<!-- fallback: should never render in AJAX flow -->
<div style="display:none" id="phpFallbackDebug"
     data-show="<?= $showPixModal ? '1' : '0' ?>"
     data-error="<?= htmlspecialchars($pixError, ENT_QUOTES, 'UTF-8') ?>"
     data-order="<?= $pixOrderId ?>"></div>
<?php endif; ?>

<!-- ═══ PIX MODAL (AJAX-rendered) ═══ -->
<div id="pixModal" class="fixed inset-0 flex items-center justify-center bg-black/60 backdrop-blur-sm px-4" style="display:none;z-index:2147483000" onclick="if(event.target===this)closePixModal()">
    <div class="w-full max-w-lg bg-blackx2 border border-white/[0.08] rounded-3xl p-6 sm:p-8 space-y-5 max-h-[90vh] overflow-y-auto relative">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center">
                    <i data-lucide="qr-code" class="w-5 h-5 text-greenx"></i>
                </div>
                <div>
                    <h3 class="font-bold text-lg">Pagamento PIX</h3>
                    <p class="text-xs text-zinc-500" id="pmOrderLabel">Pedido #--</p>
                </div>
            </div>
            <button type="button" onclick="closePixModal()" class="w-9 h-9 rounded-xl border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-white hover:border-white/[0.15] transition-all" title="Fechar">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <div id="pmStatusBar" class="flex items-center gap-3 px-4 py-2.5 rounded-xl bg-greenx/[0.06] border border-greenx/20">
            <div class="w-2 h-2 rounded-full bg-greenx" style="animation:pixPulse 2s ease-in-out infinite"></div>
            <span class="text-sm text-greenx font-medium" id="pmStatusText">Aguardando pagamento</span>
            <span class="ml-auto text-xs text-zinc-500" id="pmTimer" style="font-variant-numeric:tabular-nums"></span>
        </div>
        <div class="text-center">
            <p class="text-xs text-zinc-500 uppercase tracking-wide mb-1">Valor a pagar</p>
            <p class="text-3xl font-black text-greenx" id="pmValor">R$&nbsp;0,00</p>
        </div>
        <!-- Error box (hidden by default) -->
        <div id="pmErrorBox" class="hidden rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm"></div>
        <!-- QR section -->
        <div id="pmQrSection" class="hidden">
            <div class="flex justify-center">
                <div class="rounded-2xl bg-white p-3" style="box-shadow:0 0 40px rgba(var(--t-accent-rgb),.15)">
                    <img id="pmQrImg" src="" alt="QR Code PIX" class="w-48 h-48 sm:w-56 sm:h-56">
                </div>
            </div>
            <p class="text-center text-sm text-zinc-400 mt-3">Escaneie com o app do seu banco</p>
            <div class="space-y-2 mt-4">
                <label class="text-xs text-zinc-500 uppercase tracking-wider font-semibold">Pix Copia e Cola</label>
                <div id="pmCopyText" class="bg-white/[0.02] border border-dashed border-white/[0.1] rounded-xl p-3 font-mono text-[11px] text-zinc-400 break-all max-h-24 overflow-y-auto"></div>
                <button id="pmCopyBtn" type="button" class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl bg-greenx/10 border border-greenx/25 text-greenx font-semibold text-sm hover:bg-greenx/20 transition-all">
                    <i data-lucide="copy" class="w-4 h-4"></i>
                    <span id="pmCopyLabel">Copiar código PIX</span>
                </button>
            </div>
        </div>
        <!-- Paid state -->
        <div id="pmPaidBox" class="hidden rounded-xl bg-greenx/20 border border-greenx text-greenx px-4 py-3 text-sm text-center font-semibold">
            ✅ Pagamento confirmado! Redirecionando...
        </div>
    </div>
</div>
<style>@keyframes pixPulse{0%,100%{opacity:1}50%{opacity:.5}}</style>

<script>
console.log('[checkout] page loaded, version: <?= CHECKOUT_VERSION ?>');

(function() {
    var BP = '<?= defined("BASE_PATH") ? BASE_PATH : "" ?>';
    var form = document.querySelector('form[method="post"]');
    var btn = document.getElementById('btnPlaceOrder');
    if (!form || !btn) { console.error('[checkout] form or button not found!'); return; }

    var modal      = document.getElementById('pixModal');
    var pmOrder    = document.getElementById('pmOrderLabel');
    var pmValor    = document.getElementById('pmValor');
    var pmQrImg    = document.getElementById('pmQrImg');
    var pmQrSec    = document.getElementById('pmQrSection');
    var pmCopyText = document.getElementById('pmCopyText');
    var pmCopyBtn  = document.getElementById('pmCopyBtn');
    var pmCopyLbl  = document.getElementById('pmCopyLabel');
    var pmError    = document.getElementById('pmErrorBox');
    var pmStatus   = document.getElementById('pmStatusText');
    var pmTimer    = document.getElementById('pmTimer');
    var pmPaid     = document.getElementById('pmPaidBox');
    var pollId, timerId;

    if (modal && modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }

    // Close modal function (exposed globally for onclick)
    window.closePixModal = function() {
        if (modal) modal.style.display = 'none';
        // Don't stop polling — payment can still complete in background
    };

    function formatBRL(v) {
        return v.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    function showModal(data) {
        console.log('[checkout] showModal', data);
        pmOrder.textContent = 'Pedido #' + data.orderId;
        pmValor.textContent = 'R$\u00A0' + formatBRL(data.valor);

        if (pmError) pmError.classList.add('hidden');
        if (pmPaid) pmPaid.classList.add('hidden');
        if (pmQrSec) pmQrSec.classList.add('hidden');
        if (pmQrImg) pmQrImg.removeAttribute('src');

        if (data.qrImage) {
            pmQrImg.src = data.qrImage;
            pmCopyText.textContent = data.copyPaste || '';
            pmQrSec.classList.remove('hidden');
        }

        modal.style.display = 'flex';

        // Re-init Lucide icons in modal
        if (window.lucide) lucide.createIcons();

        // Start polling
        startPolling(data.orderId);
        startTimer();
    }

    function showError(msg, orderId) {
        console.error('[checkout] showError:', msg);
        if (orderId) {
            pmOrder.textContent = 'Pedido #' + orderId;
        }
        pmError.textContent = msg;
        pmError.classList.remove('hidden');
        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }

    function startPolling(orderId) {
        pollId = setInterval(function() {
            fetch(BP + '/api/m5_status?order_id=' + orderId, {credentials:'same-origin'})
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    console.log('[checkout] poll:', d);
                    if (d.ok && ['pago','paid','enviado','entregue'].indexOf((d.orderStatus||'').toLowerCase()) >= 0) {
                        clearInterval(pollId); clearInterval(timerId);
                        if (pmStatus) { pmStatus.textContent = 'Pagamento confirmado!'; }
                        if (pmPaid) pmPaid.classList.remove('hidden');
                        // Fetch conversation id for auto-open chat, then redirect
                        fetch(BP + '/api/chat?action=order_chat&order_id=' + orderId, {credentials:'same-origin'})
                            .then(function(cr){ return cr.json(); })
                            .then(function(cj){
                                var convId = (cj && cj.ok && cj.conversation_id) ? cj.conversation_id : '';
                                var url = BP + '/pedido_detalhes?id=' + orderId + (convId ? '&open_chat=' + convId : '');
                                setTimeout(function(){ window.location.href = url; }, 2000);
                            })
                            .catch(function(){
                                setTimeout(function(){ window.location.href = BP + '/pedido_detalhes?id=' + orderId; }, 2000);
                            });
                    }
                }).catch(function(e) { console.warn('[checkout] poll error:', e); });
        }, 6000);
    }

    function startTimer() {
        var rem = 86400;
        timerId = setInterval(function() {
            if (--rem <= 0) { if (pmTimer) pmTimer.textContent = 'Expirado'; clearInterval(timerId); return; }
            var h = Math.floor(rem/3600), m = Math.floor(rem%3600/60), s = rem%60;
            if (pmTimer) pmTimer.textContent = (h ? h+'h ' : '') + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }, 1000);
    }

    // Copy button
    if (pmCopyBtn) {
        pmCopyBtn.onclick = function() {
            var t = pmCopyText ? pmCopyText.textContent : '';
            if (!t) return;
            navigator.clipboard.writeText(t).then(function() {
                if (pmCopyLbl) pmCopyLbl.textContent = 'Copiado!';
                setTimeout(function() { if (pmCopyLbl) pmCopyLbl.textContent = 'Copiar código PIX'; }, 2500);
            }).catch(function() {});
        };
    }

    // ── AJAX form submission ──
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        console.log('[checkout] form submit intercepted');

        btn.disabled = true;
        btn.innerHTML = '<svg class="animate-spin w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg> Processando...';

        var formData = new FormData(form);
        console.log('[checkout] POST /api/place_order.php', {use_wallet: formData.get('use_wallet')});

        fetch(BP + '/api/place_order', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
        .then(function(response) {
            console.log('[checkout] response status:', response.status, 'type:', response.headers.get('content-type'));
            return response.text();
        })
        .then(function(text) {
            console.log('[checkout] raw response (' + text.length + ' chars):', text.substring(0, 500));

            var data;
            try {
                data = JSON.parse(text);
            } catch(parseErr) {
                console.error('[checkout] JSON parse failed! Full response:', text);
                showError('Resposta inválida do servidor. Verifique console (F12).', 0);
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="lock" class="w-4 h-4"></i> Finalizar pedido';
                if (window.lucide) lucide.createIcons();
                return;
            }

            console.log('[checkout] parsed response:', data);

            if (!data.ok) {
                console.error('[checkout] API error:', data.error, 'debug:', data.debug);
                showError(data.error || 'Erro desconhecido', data.orderId || 0);
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="lock" class="w-4 h-4"></i> Finalizar pedido';
                if (window.lucide) lucide.createIcons();
                return;
            }

            // Wallet-only payment → redirect with open_chat
            if (!data.pix) {
                console.log('[checkout] wallet-only, redirecting to:', data.redirect);
                var walletRedirect = data.redirect || (BP + '/pedido_detalhes?id=' + data.orderId);
                // Try to get conversation id for auto-open
                fetch(BP + '/api/chat?action=order_chat&order_id=' + data.orderId, {credentials:'same-origin'})
                    .then(function(cr){ return cr.json(); })
                    .then(function(cj){
                        if(cj && cj.ok && cj.conversation_id){
                            var sep = walletRedirect.indexOf('?') >= 0 ? '&' : '?';
                            window.location.href = walletRedirect + sep + 'open_chat=' + cj.conversation_id;
                        } else {
                            window.location.href = walletRedirect;
                        }
                    })
                    .catch(function(){ window.location.href = walletRedirect; });
                return;
            }

            // PIX payment → show modal
            showModal(data);
        })
        .catch(function(err) {
            console.error('[checkout] fetch error:', err);
            showError('Erro de rede: ' + err.message, 0);
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="lock" class="w-4 h-4"></i> Finalizar pedido';
            if (window.lucide) lucide.createIcons();
        });
    });

    console.log('[checkout] AJAX handler attached to form');
})();
</script>

<?php
include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';

if ($walletSaldo > 0 && !$showPixModal):
?>
<script>
    (function () {
        const checkbox = document.getElementById('use-wallet-checkbox');
        const walletDiscountEl = document.getElementById('wallet-discount');
        const pixTotalEl = document.getElementById('pix-total');
        const badgeEl = document.getElementById('wallet-full-paid-badge');
        if (!checkbox || !walletDiscountEl || !pixTotalEl) return;

        const total = <?= json_encode((float)$totalComTaxa) ?>;
        const walletAplicavel = <?= json_encode((float)$walletAplicavel) ?>;

        function formatBRL(value) {
            return value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalc() {
            const walletUsed = checkbox.checked ? Math.min(walletAplicavel, total) : 0;
            const pix = Math.max(0, total - walletUsed);
            walletDiscountEl.textContent = '- R$\u00A0' + formatBRL(walletUsed);
            pixTotalEl.textContent = 'R$\u00A0' + formatBRL(pix);
            if (badgeEl) {
                badgeEl.classList.toggle('hidden', pix > 0);
            }
        }

        checkbox.addEventListener('change', recalc);
        recalc();
    })();
</script>
<?php endif; ?>
