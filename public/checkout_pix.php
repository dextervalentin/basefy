<?php
/**
 * Checkout PIX — Server-side generation (same approach as wallet.php)
 *
 * Flow: load page → call blackcatCreatePixSale in PHP → render QR inline
 *       JavaScript only polls status + copy button.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/db.php';
require_once $ROOT . '/src/auth.php';
require_once $ROOT . '/src/config.php';
require_once $ROOT . '/src/media.php';
require_once $ROOT . '/src/storefront.php';
require_once $ROOT . '/src/m5_api.php';

if (!usuarioLogado()) {
    header('Location: ' . BASE_PATH . '/login');
    exit;
}

$conn    = (new Database())->connect();
$uid     = (int)($_SESSION['user_id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    header('Location: ' . BASE_PATH . '/meus_pedidos');
    exit;
}

/* ── Get order ── */
$stO = $conn->prepare('SELECT id, user_id, status, total FROM orders WHERE id=? AND user_id=? LIMIT 1');
$stO->bind_param('ii', $orderId, $uid);
$stO->execute();
$order = $stO->get_result()->fetch_assoc();
$stO->close();

if (!$order) {
    header('Location: ' . BASE_PATH . '/meus_pedidos');
    exit;
}

$alreadyPaid = in_array(strtolower((string)($order['status'] ?? '')), ['pago','paid','enviado','entregue'], true);
$pixError    = '';
$qrCodeImage = '';
$pixCode     = '';
$debugInfo   = '';

try {

/* ── Check for existing payment_transaction ── */
$stTx = $conn->prepare("SELECT id, raw_response, status FROM payment_transactions WHERE provider IN ('m5','blackcat') AND order_id=? ORDER BY id DESC LIMIT 1");
$stTx->bind_param('i', $orderId);
$stTx->execute();
$existingTx = $stTx->get_result()->fetch_assoc();
$stTx->close();

if ($existingTx && !empty($existingTx['raw_response'])) {
    $decoded = json_decode((string)$existingTx['raw_response'], true);
    $pd = (array)($decoded['data']['paymentData'] ?? []);
    $b64 = (string)($pd['qrCodeBase64'] ?? '');
    if ($b64 !== '') {
        $qrCodeImage = str_starts_with($b64, 'data:') ? $b64 : ('data:image/png;base64,' . $b64);
        $pixCode = (string)($pd['copyPaste'] ?? $pd['qrCode'] ?? $pd['pixCode'] ?? '');
    }
}

/* ── Generate PIX if needed (server-side, exactly like walletCriarRecargaPix) ── */
if (!$alreadyPaid && $qrCodeImage === '') {

    $stU = $conn->prepare('SELECT nome, email FROM users WHERE id=? LIMIT 1');
    $stU->bind_param('i', $uid);
    $stU->execute();
    $buyer = $stU->get_result()->fetch_assoc() ?: ['nome' => 'Cliente', 'email' => 'c@l.com'];
    $stU->close();

    $amountCentavos = (int)round(((float)$order['total']) * 100);
    $externalRef = 'order:' . $orderId;

    // Webhook URL absoluto (a M5 só aceita http/https)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') $scheme = 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? parse_url(APP_URL, PHP_URL_HOST) ?? 'localhost');
    $webhookUrl = $scheme . '://' . $host . BASE_PATH . '/webhooks/m5';

    $description = 'Pedido #' . $orderId . ' - Basefy';

    // M5 exige UUID em external_id; não passamos — guardamos $externalRef internamente.
    [$okApi, $resp] = m5CreatePixQrCode($amountCentavos, $description, $webhookUrl, null);

    if ($okApi) {
        $data   = $resp['data'] ?? [];
        $provId = (string)($data['transactionId'] ?? '');
        $st     = strtoupper((string)($data['status'] ?? 'PENDING'));
        $rawJ   = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Simple INSERT — no nullable int columns to avoid PgCompat issues
        $ins = $conn->prepare("INSERT INTO payment_transactions
            (provider, order_id, user_id, external_ref, provider_transaction_id, status, payment_method, amount_centavos, raw_response)
            VALUES ('m5', ?, ?, ?, ?, ?, 'pix', ?, ?)
            ON DUPLICATE KEY UPDATE
              status = VALUES(status),
              raw_response = VALUES(raw_response),
              updated_at = CURRENT_TIMESTAMP");
        $ins->bind_param('iisssis', $orderId, $uid, $externalRef, $provId, $st, $amountCentavos, $rawJ);
        $ins->execute();

        // Extract QR data — M5 retorna paymentData.qrCode / qrCodeBase64 / copyPaste
        $pd  = (array)($data['paymentData'] ?? []);
        $b64 = (string)($pd['qrCodeBase64'] ?? '');
        if ($b64 !== '') {
            $qrCodeImage = str_starts_with($b64, 'data:') ? $b64 : ('data:image/png;base64,' . $b64);
            $pixCode = (string)($pd['copyPaste'] ?? $pd['qrCode'] ?? $pd['pixCode'] ?? '');
        } else {
            $pixError = 'API retornou sucesso mas sem dados de QR Code.';
            $debugInfo = htmlspecialchars(substr($rawJ, 0, 1000), ENT_QUOTES, 'UTF-8');
        }
    } else {
        $errMsg = (string)($resp['message'] ?? 'Erro desconhecido');
        $pixError = 'Erro M5: ' . $errMsg;
        $debugInfo = htmlspecialchars(json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
    }
}

} catch (Throwable $e) {
    $pixError = 'Exceção: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
}

$hasPixData = ($qrCodeImage !== '');

/* ── Order items for summary ── */
$stI = $conn->prepare("SELECT oi.quantidade, oi.preco_unit, oi.subtotal, p.nome AS produto_nome, p.imagem AS produto_imagem FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?");
$stI->bind_param('i', $orderId);
$stI->execute();
$orderItems = $stI->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stI->close();

/* ── Nav vars ── */
$isLoggedIn  = true;
$userId      = $uid;
$cartCount   = sfCartCount();
$currentPage = 'checkout';
$pageTitle   = 'Pagamento PIX';

include $ROOT . '/views/partials/header.php';
include $ROOT . '/views/partials/storefront_nav.php';
?>

<style>
@keyframes pixPulse{0%,100%{opacity:1}50%{opacity:.6}}
@keyframes pixSuccess{0%{transform:scale(0) rotate(-45deg);opacity:0}50%{transform:scale(1.2) rotate(0);opacity:1}100%{transform:scale(1) rotate(0);opacity:1}}
.pix-pulse{animation:pixPulse 2s ease-in-out infinite}
.pix-success-icon{animation:pixSuccess .6s ease-out both}
.pix-qr-glow{box-shadow:0 0 40px rgba(var(--t-accent-rgb),.15),0 0 80px rgba(var(--t-accent-rgb),.05)}
.pix-timer{font-variant-numeric:tabular-nums}
.pix-copy-field{background:rgba(255,255,255,.02);border:1px dashed rgba(255,255,255,.1);border-radius:12px;padding:12px 16px;font-family:'Courier New',monospace;font-size:11px;line-height:1.5;word-break:break-all;color:#d4d4d8;max-height:120px;overflow-y:auto}
</style>

<div class="min-h-[calc(100vh-4rem)] flex items-start justify-center px-4 py-8 sm:py-12">
<div class="w-full max-w-3xl">

    <!-- Header -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-greenx/10 border border-greenx/20 mb-4">
            <i data-lucide="qr-code" class="w-8 h-8 text-greenx"></i>
        </div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight">Pagamento PIX</h1>
        <p class="text-zinc-400 text-sm mt-2">Pedido <span class="text-white font-semibold">#<?= (int)$order['id'] ?></span> &mdash; R$&nbsp;<?= number_format((float)$order['total'], 2, ',', '.') ?></p>
    </div>

    <?php if ($pixError !== ''): ?>
    <!-- Error -->
    <div class="bg-blackx2 border border-red-500/30 rounded-3xl p-6 sm:p-8 text-center mb-6">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-red-500/10 border border-red-500/30 mb-4">
            <i data-lucide="alert-triangle" class="w-7 h-7 text-red-400"></i>
        </div>
        <p class="text-red-300 font-medium mb-2"><?= htmlspecialchars($pixError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($debugInfo !== ''): ?>
        <details class="mt-3 text-left">
            <summary class="text-xs text-zinc-500 cursor-pointer hover:text-zinc-300">Detalhes técnicos</summary>
            <pre class="mt-2 text-[10px] text-zinc-500 bg-black/30 rounded-xl p-3 overflow-x-auto max-h-40"><?= $debugInfo ?></pre>
        </details>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/checkout_pix?order_id=<?= (int)$order['id'] ?>"
           class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white/[0.06] border border-white/[0.08] text-sm text-zinc-300 hover:text-white transition-all">
            <i data-lucide="refresh-cw" class="w-4 h-4"></i> Tentar novamente
        </a>
    </div>
    <?php endif; ?>

    <!-- Success state -->
    <div id="pixSuccessState" class="<?= $alreadyPaid ? '' : 'hidden' ?>">
        <div class="bg-blackx2 border border-greenx/30 rounded-3xl p-8 sm:p-10 text-center">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-greenx/10 border-2 border-greenx mb-5 pix-success-icon">
                <i data-lucide="check" class="w-10 h-10 text-greenx"></i>
            </div>
            <h2 class="text-2xl font-bold text-greenx mb-2">Pagamento confirmado!</h2>
            <p class="text-zinc-400 mb-2">Seu pedido #<?= (int)$order['id'] ?> está sendo processado.</p>
            <p class="text-zinc-500 text-xs mb-6">As instruções e o produto foram enviados no chat. Redirecionando para os detalhes do pedido...</p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a id="btnGoChat" href="<?= BASE_PATH ?>/pedido_detalhes?id=<?= (int)$order['id'] ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold text-sm shadow-lg shadow-greenx/20 hover:shadow-greenx/30 transition-all">
                    <i data-lucide="eye" class="w-4 h-4"></i> Ver pedido
                </a>
                <a href="<?= BASE_PATH ?>/" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl border border-white/[0.08] text-zinc-300 text-sm hover:text-white hover:border-white/[0.15] transition-all">
                    <i data-lucide="store" class="w-4 h-4"></i> Continuar comprando
                </a>
            </div>
        </div>
    </div>

    <?php if (!$alreadyPaid && $hasPixData): ?>
    <!-- Payment state -->
    <div id="pixPaymentState">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            <!-- Left: QR Code (3/5) -->
            <div class="lg:col-span-3 space-y-5">
                <div id="pixStatusBar" class="flex items-center gap-3 px-4 py-3 rounded-2xl bg-greenx/[0.06] border border-greenx/20">
                    <div class="w-2.5 h-2.5 rounded-full bg-greenx pix-pulse"></div>
                    <span class="text-sm text-greenx font-medium" id="pixStatusText">PIX gerado — aguardando pagamento</span>
                    <span class="ml-auto text-xs text-zinc-500 pix-timer" id="pixTimer"></span>
                </div>

                <div class="bg-blackx2 border border-white/[0.06] rounded-3xl p-6 sm:p-8">
                    <div class="flex justify-center mb-6">
                        <div class="rounded-2xl bg-white p-4 pix-qr-glow">
                            <img src="<?= htmlspecialchars($qrCodeImage, ENT_QUOTES, 'UTF-8') ?>" alt="QR Code PIX" class="w-48 h-48 sm:w-56 sm:h-56">
                        </div>
                    </div>
                    <p class="text-center text-sm text-zinc-400 mb-4">Escaneie o QR Code com o app do seu banco</p>
                    <div class="space-y-3">
                        <label class="text-xs text-zinc-500 uppercase tracking-wider font-semibold">Pix Copia e Cola</label>
                        <div class="pix-copy-field" id="pixCopyPaste"><?= htmlspecialchars($pixCode, ENT_QUOTES, 'UTF-8') ?></div>
                        <button id="btnCopy" type="button" class="w-full flex items-center justify-center gap-2 py-3 rounded-xl bg-greenx/10 border border-greenx/25 text-greenx font-semibold text-sm hover:bg-greenx/20 transition-all">
                            <i data-lucide="copy" class="w-4 h-4"></i>
                            <span id="copyLabel">Copiar código PIX</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right: Order summary (2/5) -->
            <div class="lg:col-span-2 space-y-5">
                <div class="bg-blackx2 border border-white/[0.06] rounded-3xl p-5">
                    <h3 class="text-sm font-semibold mb-4 flex items-center gap-2">
                        <i data-lucide="shopping-bag" class="w-4 h-4 text-greenx"></i> Resumo do pedido
                    </h3>
                    <div class="space-y-3 mb-4">
                    <?php foreach ($orderItems as $item):
                        $imgUrl = mediaResolveUrl((string)($item['produto_imagem'] ?? ''), 'https://placehold.co/60x60/111827/9ca3af?text=Foto');
                    ?>
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-xl overflow-hidden bg-blackx border border-white/[0.06] flex-shrink-0">
                                <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" class="w-full h-full object-cover" alt="">
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium truncate"><?= htmlspecialchars((string)($item['produto_nome'] ?? 'Produto'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-xs text-zinc-500"><?= (int)$item['quantidade'] ?>x R$&nbsp;<?= number_format((float)$item['preco_unit'], 2, ',', '.') ?></p>
                            </div>
                            <span class="text-sm font-semibold text-zinc-300">R$&nbsp;<?= number_format((float)$item['subtotal'], 2, ',', '.') ?></span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <div class="border-t border-white/[0.06] pt-3 flex items-center justify-between">
                        <span class="text-sm text-zinc-400">Total</span>
                        <span class="text-lg font-bold text-greenx">R$&nbsp;<?= number_format((float)$order['total'], 2, ',', '.') ?></span>
                    </div>
                </div>

                <div class="bg-blackx2 border border-white/[0.06] rounded-3xl p-5 space-y-3">
                    <h3 class="text-sm font-semibold flex items-center gap-2">
                        <i data-lucide="shield-check" class="w-4 h-4 text-purple-400"></i> Pagamento seguro
                    </h3>
                    <div class="space-y-2.5 text-xs text-zinc-400">
                        <div class="flex items-start gap-2.5"><i data-lucide="clock" class="w-3.5 h-3.5 text-zinc-500 mt-0.5 shrink-0"></i><span>PIX válido por <strong class="text-zinc-300">24 horas</strong></span></div>
                        <div class="flex items-start gap-2.5"><i data-lucide="zap" class="w-3.5 h-3.5 text-zinc-500 mt-0.5 shrink-0"></i><span>Confirmação <strong class="text-zinc-300">automática</strong></span></div>
                        <div class="flex items-start gap-2.5"><i data-lucide="lock" class="w-3.5 h-3.5 text-zinc-500 mt-0.5 shrink-0"></i><span>Dados protegidos com criptografia</span></div>
                    </div>
                </div>

                <div class="space-y-2">
                    <a href="<?= BASE_PATH ?>/pedido_detalhes?id=<?= (int)$order['id'] ?>" class="flex items-center justify-center gap-2 w-full py-3 rounded-xl border border-white/[0.08] text-zinc-400 text-sm hover:text-white hover:border-white/[0.15] transition-all">
                        <i data-lucide="file-text" class="w-4 h-4"></i> Detalhes do pedido
                    </a>
                    <a href="<?= BASE_PATH ?>/meus_pedidos" class="flex items-center justify-center gap-2 w-full py-3 rounded-xl border border-white/[0.08] text-zinc-400 text-sm hover:text-white hover:border-white/[0.15] transition-all">
                        <i data-lucide="list" class="w-4 h-4"></i> Meus pedidos
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$alreadyPaid && !$hasPixData && $pixError === ''): ?>
    <!-- Fallback: no QR, no error — should not happen but just in case -->
    <div class="bg-blackx2 border border-orange-500/30 rounded-3xl p-8 text-center">
        <p class="text-orange-300 mb-4">Nenhum dado de pagamento disponível.</p>
        <a href="<?= BASE_PATH ?>/checkout_pix?order_id=<?= (int)$order['id'] ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-greenx/10 border border-greenx/25 text-greenx font-semibold text-sm hover:bg-greenx/20 transition-all">
            <i data-lucide="refresh-cw" class="w-4 h-4"></i> Gerar PIX
        </a>
    </div>
    <?php endif; ?>

</div>
</div>

<script>
(function(){
  var OID=<?=(int)$orderId?>, PAID=<?=$alreadyPaid?'1':'0'?>, HAS=<?=$hasPixData?'1':'0'?>;
  var ps=document.getElementById('pixPaymentState'),
      ss=document.getElementById('pixSuccessState'),
      ti=document.getElementById('pixTimer'),
      cp=document.getElementById('btnCopy'),
      cl=document.getElementById('copyLabel'),
      ct=document.getElementById('pixCopyPaste');
  var pi,ci;

  function ok(){
    if(ps)ps.classList.add('hidden');
    if(ss)ss.classList.remove('hidden');
    clearInterval(pi);clearInterval(ci);
    if(window.lucide)lucide.createIcons();

    // After payment confirmed, fetch chat conversation and redirect to pedido_detalhes with floating chat
    fetch('<?= BASE_PATH ?>/api/chat?action=order_chat&order_id='+OID,{credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(d){
        var url = '<?= BASE_PATH ?>/pedido_detalhes?id='+OID;
        if(d.ok && d.conversation_id){
          url += '&open_chat='+d.conversation_id;
          var btnChat = document.getElementById('btnGoChat');
          if(btnChat) btnChat.href = url;
        }
        setTimeout(function(){ window.location.href = url; }, 2500);
      }).catch(function(){
        setTimeout(function(){ window.location.href = '<?= BASE_PATH ?>/pedido_detalhes?id='+OID; }, 2500);
      });
  }

  if(PAID){ok();return;}
  if(!HAS)return;

  // Timer
  var rem=86400;
  ci=setInterval(function(){
    if(--rem<=0){ti.textContent='Expirado';clearInterval(ci);return;}
    var h=Math.floor(rem/3600),m=Math.floor(rem%3600/60),s=rem%60;
    ti.textContent=(h?h+'h ':'')+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
  },1000);

  // Poll
  pi=setInterval(function(){
    fetch('<?= BASE_PATH ?>/api/m5_status?order_id='+OID,{credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(d){if(d.ok&&['pago','paid','enviado','entregue'].indexOf((d.orderStatus||'').toLowerCase())>=0)ok();})
      .catch(function(){});
  },8000);

  // Copy
  if(cp)cp.onclick=function(){
    var t=ct?ct.textContent:'';if(!t)return;
    navigator.clipboard.writeText(t).then(function(){
      cl.textContent='Copiado!';
      setTimeout(function(){cl.textContent='Copiar código PIX';},2500);
    }).catch(function(){});
  };
})();
</script>

<?php
include $ROOT . '/views/partials/storefront_footer.php';
include $ROOT . '/views/partials/footer.php';
