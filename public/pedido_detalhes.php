<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\pedido_detalhes.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/db.php';
require_once $ROOT . '/src/auth.php';
require_once $ROOT . '/src/wallet_escrow.php';
require_once $ROOT . '/src/media.php';
require_once $ROOT . '/src/storefront.php';
require_once $ROOT . '/src/reviews.php';

exigirLogin();

$conn    = (new Database())->connect();
$userId  = (int)($_SESSION['user_id'] ?? 0);
$orderId = (int)($_GET['id'] ?? 0);

_sfEnsureDeliveryColumns($conn);

if ($orderId <= 0) {
    header('Location: ' . BASE_PATH . '/meus_pedidos');
    exit;
}

$pageTitle  = 'Detalhes do pedido';
$activeMenu = 'pedidos';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'confirm_delivery') {
  [$okDelivery, $deliveryMsg] = escrowConfirmDeliveryByBuyer($conn, $orderId, $userId);
  if ($okDelivery) {
    $msg = $deliveryMsg;
  } else {
    $err = $deliveryMsg;
  }
}

$order = null;
$st = $conn->prepare("SELECT id, user_id, status, total, gross_total, wallet_used, criado_em FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
if ($st) {
    $st->bind_param('ii', $orderId, $userId);
    $st->execute();
    $order = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}

if (!$order) {
    header('Location: ' . BASE_PATH . '/meus_pedidos');
    exit;
}

$items = [];
$st = $conn->prepare("
    SELECT oi.id, oi.product_id, oi.vendedor_id, oi.quantidade, oi.preco_unit, oi.subtotal, oi.moderation_status,
           oi.delivery_content, oi.delivered_at,
           p.nome AS produto_nome, p.imagem AS produto_imagem, p.slug AS produto_slug,
           u.nome AS vendedor_nome
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    LEFT JOIN users u ON u.id = oi.vendedor_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");
if ($st) {
    $st->bind_param('i', $orderId);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();
}

include $ROOT . '/views/partials/header.php';
include $ROOT . '/views/partials/user_layout_start.php';

$orderStatusBadge = static function (string $status): string {
  $s = strtolower(trim($status));
  if (in_array($s, ['pago', 'paid', 'entregue'], true)) return 'bg-greenx/15 border border-greenx/40 text-greenx';
  if (in_array($s, ['pendente', 'pending', 'enviado', 'aguardando_pagamento'], true)) return 'bg-orange-500/15 border border-orange-400/40 text-orange-300';
  if (in_array($s, ['cancelado', 'recusado'], true)) return 'bg-red-500/15 border border-red-400/40 text-red-300';
  return 'bg-blackx border border-blackx3 text-zinc-300';
};
?>

<div class="mb-4">
  <a href="<?= BASE_PATH ?>/meus_pedidos" class="inline-flex rounded-lg border border-blackx3 px-3 py-1.5 text-xs hover:border-greenx">
    ← Voltar
  </a>
</div>

<div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5 mb-4">
  <h2 class="text-lg font-semibold mb-3">Pedido #<?= (int)$order['id'] ?></h2>
  <?php if ($msg): ?><div class="mb-3 rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="mb-3 rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-sm">
    <div><span class="text-zinc-400">Data:</span> <?= fmtDate((string)$order['criado_em']) ?></div>
    <div><span class="text-zinc-400">Status:</span> <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $orderStatusBadge((string)$order['status']) ?>"><?= htmlspecialchars((string)$order['status'], ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php
      $grossTotal = (float)($order['gross_total'] ?? 0);
      $walletUsed = (float)($order['wallet_used'] ?? 0);
      $displayTotal = $grossTotal > 0 ? $grossTotal : (float)$order['total'];
      $pixPortion = max(0, $displayTotal - $walletUsed);
      if ($walletUsed > 0 && $pixPortion > 0) {
          $payMethod = 'Wallet + PIX';
          $payClass = 'bg-purple-500/15 border border-purple-400/40 text-purple-300';
      } elseif ($walletUsed > 0 && $pixPortion <= 0) {
          $payMethod = 'Saldo Wallet';
          $payClass = 'bg-greenx/15 border border-greenx/40 text-greenx';
      } else {
          $payMethod = 'PIX';
          $payClass = 'bg-greenx/15 border border-greenx/40 text-purple-300';
      }
    ?>
    <div><span class="text-zinc-400">Total:</span> R$ <?= number_format($displayTotal, 2, ',', '.') ?></div>
    <div><span class="text-zinc-400">Itens:</span> <?= count($items) ?></div>
  </div>

  <!-- Payment method breakdown -->
  <div class="mt-3 p-3 rounded-xl bg-blackx border border-blackx3">
    <div class="flex items-center gap-3 text-xs flex-wrap">
      <span class="text-zinc-400 font-medium">Pagamento:</span>
      <span class="px-2.5 py-1 rounded-full font-medium <?= $payClass ?>"><?= $payMethod ?></span>
      <?php if ($walletUsed > 0): ?>
        <span class="text-zinc-500">Wallet: <b class="text-zinc-200">R$ <?= number_format($walletUsed, 2, ',', '.') ?></b></span>
      <?php endif; ?>
      <?php if ($pixPortion > 0): ?>
        <span class="text-zinc-500">PIX: <b class="text-zinc-200">R$ <?= number_format($pixPortion, 2, ',', '.') ?></b></span>
      <?php endif; ?>
      <span class="text-zinc-500">Total: <b class="text-zinc-200">R$ <?= number_format($displayTotal, 2, ',', '.') ?></b></span>
    </div>
  </div>

  <?php
    // Show delivery code for paid orders (buyer needs to give code to vendor)
    $orderStatusLower = strtolower(trim((string)$order['status']));
    $showDeliveryCode = in_array($orderStatusLower, ['pago', 'paid', 'enviado'], true);
    $deliveryCode = $showDeliveryCode ? escrowGetDeliveryCode($conn, $orderId) : null;

    // Retomar pagamento: se pedido pendente/aguardando e há porção PIX > 0, oferece link de continuar pagamento.
    // O checkout_pix.php já reaproveita transação PENDENTE existente (raw_response) — só falta um atalho aqui.
    $canResumePayment = false;
    if (in_array($orderStatusLower, ['pendente', 'pending', 'aguardando_pagamento'], true) && $pixPortion > 0) {
        $canResumePayment = true;
    }
  ?>

  <?php if ($canResumePayment): ?>
  <div class="mt-4 p-4 rounded-xl bg-gradient-to-r from-yellow-500/[0.08] to-orange-500/[0.05] border border-yellow-500/30">
    <div class="flex items-start gap-3">
      <div class="w-10 h-10 rounded-xl bg-yellow-500/15 border border-yellow-500/30 flex items-center justify-center shrink-0">
        <i data-lucide="qr-code" class="w-5 h-5 text-yellow-400"></i>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-bold text-yellow-300">Pagamento pendente</p>
        <p class="text-xs text-zinc-400 mt-0.5">Você ainda não concluiu o pagamento deste pedido. Continue de onde parou — o mesmo QR Code será reaberto, sem precisar gerar um novo pedido.</p>
        <div class="mt-3 flex flex-wrap gap-2">
          <a href="<?= BASE_PATH ?>/checkout_pix?order_id=<?= (int)$order['id'] ?>"
             class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-4 py-2 text-sm">
            <i data-lucide="arrow-right-circle" class="w-4 h-4"></i>
            Continuar pagamento
          </a>
          <span class="inline-flex items-center text-[11px] text-zinc-500">Valor restante: <b class="text-zinc-200 ml-1">R$ <?= number_format($pixPortion, 2, ',', '.') ?></b></span>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($deliveryCode): ?>
  <div class="mt-4 p-4 rounded-xl bg-gradient-to-r from-greenx/[0.06] to-greenxd/[0.03] border border-greenx/25">
    <div class="flex items-center gap-2 mb-1">
      <i data-lucide="shield-check" class="w-5 h-5 text-purple-400"></i>
      <span class="text-sm font-bold text-white">Código de entrega</span>
    </div>
    <p class="text-xs text-zinc-400 mb-3">Forneça este código ao vendedor para confirmar que você recebeu o produto. O vendedor precisará inserir este código para liberar o pagamento.</p>
    <div class="flex items-center gap-3">
      <div class="flex gap-1.5">
        <?php for ($ci = 0; $ci < strlen($deliveryCode); $ci++): ?>
        <span class="w-10 h-12 rounded-lg bg-greenx/[0.08] border border-greenx/30 flex items-center justify-center text-lg font-mono font-bold text-purple-400"><?= $deliveryCode[$ci] ?></span>
        <?php endfor; ?>
      </div>
      <button onclick="navigator.clipboard.writeText('<?= $deliveryCode ?>');this.innerHTML='<i data-lucide=\'check\' class=\'w-4 h-4\'></i>';setTimeout(()=>{this.innerHTML='<i data-lucide=\'copy\' class=\'w-4 h-4\'></i>';lucide.createIcons()},2000);lucide.createIcons()"
              class="rounded-lg border border-greenx/25 bg-greenx/[0.06] px-3 py-2.5 text-purple-400 hover:bg-greenx/15 transition text-sm" title="Copiar código">
        <i data-lucide="copy" class="w-4 h-4"></i>
      </button>
    </div>
  </div>
  <?php elseif (in_array($orderStatusLower, ['entregue', 'concluido'], true)): ?>
  <div class="mt-4 p-4 rounded-xl bg-gradient-to-r from-greenx/[0.06] to-greenxd/[0.03] border border-greenx/25">
    <div class="flex items-center gap-2">
      <i data-lucide="check-circle-2" class="w-5 h-5 text-greenx"></i>
      <span class="text-sm font-bold text-greenx">Entrega confirmada</span>
      <span class="text-xs text-zinc-500 ml-auto">Código já utilizado</span>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php
// ── Chat shortcut: find conversation for this order ──
$orderIsPaidForChat = in_array(strtolower(trim((string)$order['status'])), ['pago', 'entregue', 'concluido'], true);
if ($orderIsPaidForChat && !empty($items)):
    require_once $ROOT . '/src/chat.php';
    $chatConvIds = [];
    foreach ($items as $it) {
        $vendorIdChat = (int)($it['vendedor_id'] ?? 0);
        $productIdChat = (int)($it['product_id'] ?? 0);
        if ($vendorIdChat > 0) {
            try {
                $chatConv = chatGetOrCreateConversation($conn, $userId, $vendorIdChat, $productIdChat > 0 ? $productIdChat : null);
                if ($chatConv && !isset($chatConvIds[$vendorIdChat])) {
                    $chatConvIds[$vendorIdChat] = [
                        'conv_id' => (int)$chatConv['id'],
                        'vendor_name' => $it['vendedor_nome'] ?? 'Vendedor',
                    ];
                }
            } catch (\Throwable $e) {}
        }
    }
    if (!empty($chatConvIds)):
?>
<div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5 mb-4">
  <h3 class="text-base font-semibold mb-3 flex items-center gap-2">
    <i data-lucide="message-circle" class="w-5 h-5 text-greenx"></i>
    Chat com o vendedor
  </h3>
  <p class="text-xs text-zinc-400 mb-3">As instruções, conteúdo entregue e código de entrega foram enviados automaticamente no chat. Acesse para verificar e conversar com o vendedor.</p>
  <div class="flex flex-wrap gap-2">
    <?php foreach ($chatConvIds as $vId => $chatInfo): ?>
    <button onclick="(function(id){if(window.openUserChat){window.openUserChat(id);}else if(window.openVendorChat){window.openVendorChat(id);}else{(window.__openChatQueue=window.__openChatQueue||[]).push(id);setTimeout(function(){if(window.openUserChat)window.openUserChat(id);else if(window.openVendorChat)window.openVendorChat(id);},600);}})(<?= (int)$chatInfo['conv_id'] ?>); return false;"
       class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-bold px-5 py-2.5 text-sm shadow-lg shadow-greenx/20 hover:shadow-greenx/30 transition-all hover:scale-[1.02] cursor-pointer border-none">
      <i data-lucide="message-circle" class="w-4 h-4"></i>
      Abrir chat — <?= htmlspecialchars($chatInfo['vendor_name'], ENT_QUOTES, 'UTF-8') ?>
    </button>
    <?php endforeach; ?>
  </div>
</div>
<?php
    endif;
endif;
?>

<div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5">
  <h3 class="text-base font-semibold mb-4">Itens do pedido</h3>

  <?php if (!$items): ?>
    <p class="text-zinc-500 text-sm py-4">Sem itens neste pedido.</p>
  <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($items as $it):
        $imgUrl = mediaResolveUrl((string)($it['produto_imagem'] ?? ''), 'https://placehold.co/80x80/1a1a1a/555?text=Sem+foto');
        $prodNome = htmlspecialchars((string)($it['produto_nome'] ?? 'Produto #' . $it['product_id']), ENT_QUOTES, 'UTF-8');
        $vendorNome = htmlspecialchars((string)($it['vendedor_nome'] ?? 'Vendedor #' . $it['vendedor_id']), ENT_QUOTES, 'UTF-8');
        $modStatus = strtolower(trim((string)($it['moderation_status'] ?? '')));
        $modLabel = match($modStatus) {
            'aprovado', 'aprovada', 'approved' => 'Aprovado',
            'pendente', 'pending' => 'Pendente',
            'em_analise', 'aguardando' => 'Em análise',
            'entregue' => 'Entregue',
            'rejeitado', 'rejeitada', 'rejected' => 'Rejeitado',
            'cancelado' => 'Cancelado',
            default => $it['moderation_status'] ?? '—',
        };
        $modBadge = match(true) {
            in_array($modStatus, ['aprovado', 'aprovada', 'approved', 'entregue'], true)
                => 'bg-greenx/15 border border-greenx/40 text-greenx',
            in_array($modStatus, ['pendente', 'pending', 'em_analise', 'aguardando'], true)
                => 'bg-orange-500/15 border border-orange-400/40 text-orange-300',
            in_array($modStatus, ['rejeitado', 'rejeitada', 'rejected', 'cancelado'], true)
                => 'bg-red-500/15 border border-red-400/40 text-red-300',
            default => 'bg-blackx border border-blackx3 text-zinc-300',
        };
        $modIcon = match(true) {
            in_array($modStatus, ['aprovado', 'aprovada', 'approved', 'entregue'], true)
                => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="inline mr-0.5"><polyline points="20 6 9 17 4 12"/></svg>',
            in_array($modStatus, ['pendente', 'pending', 'em_analise', 'aguardando'], true)
                => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="inline mr-0.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            in_array($modStatus, ['rejeitado', 'rejeitada', 'rejected', 'cancelado'], true)
                => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="inline mr-0.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
            default => '',
        };
      ?>
      <div class="flex items-center gap-4 p-3 rounded-xl bg-blackx/50 border border-blackx3/60 hover:border-blackx3 transition">
        <!-- Product Image -->
        <a href="<?= sfProductUrl(['id' => (int)$it['product_id'], 'slug' => (string)($it['produto_slug'] ?? '')]) ?>" class="flex-shrink-0">
          <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $prodNome ?>"
               class="w-16 h-16 md:w-20 md:h-20 rounded-xl object-cover border border-blackx3">
        </a>
        <!-- Product Info -->
        <div class="flex-1 min-w-0">
          <div class="flex items-start justify-between gap-2 mb-1">
            <a href="<?= sfProductUrl(['id' => (int)$it['product_id'], 'slug' => (string)($it['produto_slug'] ?? '')]) ?>" class="font-semibold text-sm hover:text-greenx transition-colors truncate max-w-[200px]"><?= $prodNome ?></a>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold whitespace-nowrap <?= $modBadge ?>"><?= $modIcon ?><?= htmlspecialchars($modLabel, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <p class="text-xs text-zinc-500 mb-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline mr-0.5"><path d="M3 9h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="m3 9 2.45-4.9A2 2 0 0 1 7.24 3h9.52a2 2 0 0 1 1.8 1.1L21 9"/><path d="M12 3v6"/></svg>
            <?= $vendorNome ?>
          </p>
          <div class="flex items-center gap-4 text-xs text-zinc-400">
            <span>Qtd: <strong class="text-zinc-200"><?= (int)$it['quantidade'] ?></strong></span>
            <span>Unit: <strong class="text-zinc-200">R$ <?= number_format((float)$it['preco_unit'], 2, ',', '.') ?></strong></span>
            <span class="text-greenx font-bold text-sm">R$ <?= number_format((float)$it['subtotal'], 2, ',', '.') ?></span>
          </div>

          <?php
            // ── Digital Delivery Section ──
            $deliveryContent = trim((string)($it['delivery_content'] ?? ''));
            $deliveredAt     = (string)($it['delivered_at'] ?? '');
            $orderIsPaid     = in_array(strtolower(trim((string)$order['status'])), ['pago', 'entregue'], true);
          ?>
          <?php if ($deliveryContent !== '' && $orderIsPaid): ?>
          <!-- Digital delivery content -->
          <div class="mt-3 p-3 rounded-xl bg-gradient-to-r from-greenx/10 to-greenxd/5 border border-greenx/30">
            <div class="flex items-center gap-1.5 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-greenx flex-shrink-0"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              <span class="text-xs font-bold text-greenx">Entrega digital recebida</span>
              <?php if ($deliveredAt !== ''): ?>
              <span class="text-[10px] text-zinc-500 ml-auto"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($deliveredAt)), ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </div>
            <?php
              // If it looks like a URL, make it clickable
              $isUrl = (bool)preg_match('#^https?://#i', $deliveryContent);
            ?>
            <?php if ($isUrl): ?>
            <a href="<?= htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-2 w-full p-2.5 rounded-lg bg-black/40 border border-greenx/20 text-sm text-greenx hover:text-white hover:bg-greenx/10 transition-all break-all">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              <?= htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php else: ?>
            <div class="p-2.5 rounded-lg bg-black/40 border border-greenx/20 text-sm text-zinc-300 break-all whitespace-pre-wrap">
              <?= nl2br(htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8')) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php elseif ($orderIsPaid && $deliveryContent === ''): ?>
          <!-- Waiting for vendor delivery -->
          <div class="mt-3 p-2.5 rounded-xl bg-orange-500/5 border border-orange-400/20">
            <div class="flex items-center gap-1.5">
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-orange-400 flex-shrink-0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <span class="text-xs text-orange-300">Aguardando entrega digital do vendedor</span>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php
// ── Review Section for delivered/approved orders ──
$canShowReview = in_array(strtolower(trim((string)$order['status'])), ['pago', 'entregue', 'concluido'], true);
if ($canShowReview && $items):
    try { reviewEnsureTable($conn); } catch (\Throwable $e) {}
?>
<div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5 mt-4" id="avaliacoes">
  <h3 class="text-base font-semibold mb-4 flex items-center gap-2">
    <i data-lucide="star" class="w-5 h-5 text-yellow-400"></i>
    Avaliar produtos deste pedido
  </h3>
  <div class="space-y-4">
    <?php foreach ($items as $it):
      $prodId = (int)$it['product_id'];
      $prodNome = htmlspecialchars((string)($it['produto_nome'] ?? 'Produto'), ENT_QUOTES, 'UTF-8');
      $imgUrl = mediaResolveUrl((string)($it['produto_imagem'] ?? ''), '');
      try {
        $canRev = reviewCanUserReview($conn, $userId, $prodId);
      } catch (\Throwable $e) {
        error_log("[pedido_detalhes] reviewCanUserReview exception for user=$userId product=$prodId: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        $canRev = ['can' => false, 'reason' => 'Erro ao verificar avaliação. Detalhes no log.'];
      }
    ?>
    <div class="p-4 rounded-xl bg-blackx/50 border border-blackx3/60">
      <div class="flex items-center gap-3 mb-3">
        <?php if ($imgUrl): ?>
        <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="w-12 h-12 rounded-lg object-cover border border-blackx3">
        <?php endif; ?>
        <div>
          <p class="font-semibold text-sm"><?= $prodNome ?></p>
          <?php if (!$canRev['can']): ?>
          <p class="text-xs text-zinc-500"><?= htmlspecialchars($canRev['reason'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($canRev['can']): ?>
      <div class="space-y-3" id="reviewBox<?= $prodId ?>">
        <div>
          <label class="text-xs text-zinc-400 mb-1 block">Sua nota</label>
          <div class="flex items-center gap-1" id="starSel<?= $prodId ?>">
            <?php for ($s = 1; $s <= 5; $s++): ?>
            <button type="button" onclick="setRevStar(<?= $prodId ?>,<?= $s ?>)" class="p-1 rounded-lg hover:bg-white/[0.06] transition-all">
              <svg class="w-6 h-6 text-zinc-600 fill-current rev-star-<?= $prodId ?>" data-v="<?= $s ?>" viewBox="0 0 20 20">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
              </svg>
            </button>
            <?php endfor; ?>
          </div>
        </div>
        <div>
          <label class="text-xs text-zinc-400 mb-1 block">Título (opcional)</label>
          <input type="text" id="revTit<?= $prodId ?>" maxlength="160" placeholder="Resumo da experiência" class="w-full rounded-lg bg-white/[0.03] border border-white/[0.08] px-3 py-2 text-sm focus:border-greenx/50 focus:outline-none transition">
        </div>
        <div>
          <label class="text-xs text-zinc-400 mb-1 block">Comentário (opcional)</label>
          <textarea id="revCom<?= $prodId ?>" rows="2" maxlength="1000" placeholder="Conte sobre sua experiência..." class="w-full rounded-lg bg-white/[0.03] border border-white/[0.08] px-3 py-2 text-sm focus:border-greenx/50 focus:outline-none transition resize-none"></textarea>
        </div>
        <button type="button" onclick="submitRev(<?= $prodId ?>)" id="revBtn<?= $prodId ?>"
                class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-bold px-4 py-2 text-sm shadow-lg shadow-greenx/20 transition-all">
          <i data-lucide="send" class="w-4 h-4"></i> Enviar avaliação
        </button>
        <p id="revMsg<?= $prodId ?>" class="text-sm hidden"></p>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
var revRatings = {};
function setRevStar(pid, n) {
    revRatings[pid] = n;
    document.querySelectorAll('.rev-star-'+pid).forEach(function(svg){
        var v = parseInt(svg.getAttribute('data-v'));
        svg.classList.toggle('text-yellow-400', v <= n);
        svg.classList.toggle('text-zinc-600', v > n);
    });
}
function submitRev(pid) {
    if (!revRatings[pid] || revRatings[pid] < 1) { alert('Selecione uma nota.'); return; }
    var btn = document.getElementById('revBtn'+pid);
    btn.disabled = true; btn.textContent = 'Enviando...';
    fetch('<?= BASE_PATH ?>/api/reviews.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            action:'submit', product_id:pid, rating:revRatings[pid],
            titulo: document.getElementById('revTit'+pid).value,
            comentario: document.getElementById('revCom'+pid).value
        })
    }).then(r=>r.json()).then(function(data){
        var msg = document.getElementById('revMsg'+pid);
        msg.classList.remove('hidden');
        if (data.ok) {
            msg.className='text-sm text-greenx';
            msg.textContent='Avaliação enviada!';
            document.getElementById('reviewBox'+pid).innerHTML='<p class="text-sm text-greenx flex items-center gap-2"><i data-lucide="check-circle" class="w-4 h-4"></i> Avaliação enviada com sucesso!</p>';
            if(typeof lucide!=='undefined')lucide.createIcons();
        } else {
            msg.className='text-sm text-red-400';
            msg.textContent=data.error||'Erro ao enviar.';
            btn.disabled=false; btn.textContent='Enviar avaliação';
        }
    }).catch(function(){ btn.disabled=false; btn.textContent='Enviar avaliação'; });
}
</script>
<?php endif; ?>

<?php
include $ROOT . '/views/partials/user_layout_end.php';
include $ROOT . '/views/partials/footer.php';