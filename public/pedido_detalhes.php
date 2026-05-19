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

// ─────────────────────────────────────────────────────────────
// Phase 2 — Commit A: Enrichments for the new 2-col layout
// ─────────────────────────────────────────────────────────────

// Buyer info (avatar + nome)
$buyerInfo = ['nome' => 'Comprador', 'avatar' => ''];
$stBuyer = $conn->prepare("SELECT nome, COALESCE(avatar, '') AS avatar FROM users WHERE id = ? LIMIT 1");
if ($stBuyer) {
    $stBuyer->bind_param('i', $userId);
    $stBuyer->execute();
    $rowBuyer = $stBuyer->get_result()->fetch_assoc();
    if ($rowBuyer) { $buyerInfo = $rowBuyer; }
    $stBuyer->close();
}

// Vendor avatars (uma query única para todos os vendedores únicos do pedido)
$vendorAvatars = [];
$uniqueVendorIds = [];
foreach ($items as $it) {
    $vid = (int)($it['vendedor_id'] ?? 0);
    if ($vid > 0 && !in_array($vid, $uniqueVendorIds, true)) $uniqueVendorIds[] = $vid;
}
if ($uniqueVendorIds) {
    $ph = implode(',', array_fill(0, count($uniqueVendorIds), '?'));
    $types = str_repeat('i', count($uniqueVendorIds));
    $stv = $conn->prepare("SELECT id, COALESCE(avatar, '') AS avatar FROM users WHERE id IN ($ph)");
    if ($stv) {
        $bind = array_merge([$types], $uniqueVendorIds);
        $refs = [];
        foreach ($bind as $k => $v) { $refs[$k] = &$bind[$k]; }
        call_user_func_array([$stv, 'bind_param'], $refs);
        $stv->execute();
        foreach ($stv->get_result()->fetch_all(MYSQLI_ASSOC) as $vr) {
            $vendorAvatars[(int)$vr['id']] = (string)$vr['avatar'];
        }
        $stv->close();
    }
}

// Status normalizado
$orderStatusLower = strtolower(trim((string)$order['status']));

// Timeline step (1=Aguardando pagamento, 2=Confirmação, 3=Concluído)
if (in_array($orderStatusLower, ['aguardando_pagamento','pendente','pending'], true)) {
    $timelineStep = 1;
} elseif (in_array($orderStatusLower, ['entregue','concluido'], true)) {
    $timelineStep = 3;
} else {
    $timelineStep = 2; // pago, paid, enviado
}

// Item principal e flags
$primaryItem            = $items[0] ?? null;
$primaryVendorId        = (int)($primaryItem['vendedor_id'] ?? 0);
$primaryProductId       = (int)($primaryItem['product_id'] ?? 0);
$primaryVendorAvatar    = $vendorAvatars[$primaryVendorId] ?? '';
$buyerAvatarUrl         = ((string)$buyerInfo['avatar'] !== '') ? mediaResolveUrl((string)$buyerInfo['avatar'], '') : '';
$primaryVendorAvatarUrl = ($primaryVendorAvatar !== '') ? mediaResolveUrl($primaryVendorAvatar, '') : '';

// Pagamento
$grossTotal   = (float)($order['gross_total'] ?? 0);
$walletUsed   = (float)($order['wallet_used'] ?? 0);
$displayTotal = $grossTotal > 0 ? $grossTotal : (float)$order['total'];
$pixPortion   = max(0, $displayTotal - $walletUsed);

if ($walletUsed > 0 && $pixPortion > 0)      { $payMethod = 'Wallet + PIX'; }
elseif ($walletUsed > 0)                      { $payMethod = 'Saldo Wallet'; }
else                                          { $payMethod = 'PIX'; }

// Flags
$canResumePayment  = in_array($orderStatusLower, ['pendente','pending','aguardando_pagamento'], true) && $pixPortion > 0;
$showDeliveryCode  = in_array($orderStatusLower, ['pago','paid','enviado'], true);
$deliveryCode      = $showDeliveryCode ? escrowGetDeliveryCode($conn, $orderId) : null;
$canConfirmReceive = in_array($orderStatusLower, ['pago','paid','enviado'], true);
$canShowReview     = in_array($orderStatusLower, ['pago','entregue','concluido'], true);

// ID amigável da transação
$txId = sprintf('TX-%06d-%s', (int)$order['id'], strtoupper(substr(md5('basefy-tx-' . $order['id']), 0, 6)));

// Conversa de chat com o vendedor principal (pré-criada)
$primaryChatConvId = 0;
if ($primaryVendorId > 0 && in_array($orderStatusLower, ['pago','paid','enviado','entregue','concluido'], true)) {
    require_once $ROOT . '/src/chat.php';
    try {
        $convRow = chatGetOrCreateConversation($conn, $userId, $primaryVendorId, $primaryProductId > 0 ? $primaryProductId : null);
        if ($convRow) { $primaryChatConvId = (int)$convRow['id']; }
    } catch (\Throwable $e) { /* ignore */ }
}

// Helper inicial p/ avatar
$avatarInitial = static function (string $name): string {
    $name = trim($name);
    if ($name === '') return '?';
    return strtoupper(mb_substr($name, 0, 1, 'UTF-8'));
};
?>

<style>
  /* ── Pedido Detalhes — Premium 2-col Layout (Phase 2 Commit A) ── */
  .pd-wrap { max-width: 1280px; margin: 0 auto; }
  .pd-back { display:inline-flex; align-items:center; gap:.5rem; padding:.45rem .75rem; border-radius:.6rem; border:1px solid #1f1f23; font-size:.75rem; color:#d1d1d6; transition: all .15s; }
  .pd-back:hover { border-color:#8800E4; color:#fff; background: rgba(136,0,228,.06); }
  .pd-hero { display:flex; flex-wrap:wrap; align-items:center; gap:.6rem 1.25rem; margin-bottom:1.25rem; }
  .pd-hero h1 { font-size:1.5rem; font-weight:700; color:#fff; letter-spacing:-.01em; }
  .pd-hero h1 span { color:#71717a; font-weight:500; margin-left:.35rem; }
  .pd-hero-meta { display:flex; flex-wrap:wrap; gap:.4rem .9rem; font-size:.72rem; color:#a1a1aa; align-items:center; }
  .pd-hero-meta b { color:#e4e4e7; font-weight:600; }
  .pd-status-pill { padding:.22rem .65rem; border-radius:9999px; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }

  .pd-grid { display:grid; grid-template-columns: 1fr; gap:1.1rem; }
  @media (min-width: 1024px) {
    .pd-grid { grid-template-columns: 380px minmax(0,1fr); align-items:start; gap:1.25rem; }
    .pd-left { position:sticky; top:1.25rem; }
  }
  .pd-left, .pd-right { display:flex; flex-direction:column; gap:1rem; min-width:0; }
  .pd-right { max-width: 820px; }

  .pd-card { background: linear-gradient(180deg, rgba(28,28,32,.92), rgba(20,20,22,.92)); border:1px solid #1f1f23; border-radius:1rem; padding:1.05rem 1.1rem; }
  .pd-card-title { font-size:.95rem; font-weight:700; color:#fff; margin-bottom:.85rem; display:flex; align-items:center; gap:.5rem; }
  .pd-card-title i { color:#8800E4; }
  .pd-prod-img { width:100%; aspect-ratio: 4/3; object-fit: cover; border-radius:.75rem; border:1px solid #1f1f23; background:#0e0e10; display:block; }

  .pd-field { margin-top:.85rem; }
  .pd-field-label { font-size:.62rem; letter-spacing:.08em; font-weight:700; color:#71717a; text-transform:uppercase; margin-bottom:.3rem; }
  .pd-field-value { font-size:.875rem; color:#f4f4f5; line-height:1.4; }
  .pd-value-big { font-size:1.5rem; font-weight:700; color:#c084fc; letter-spacing:-.01em; }

  .pd-badge-auto { display:inline-flex; align-items:center; gap:.4rem; padding:.32rem .7rem; border-radius:9999px; font-size:.7rem; font-weight:600; background: rgba(136,0,228,.12); border:1px solid rgba(136,0,228,.35); color:#c084fc; }

  .pd-user-row { display:flex; align-items:center; gap:.6rem; }
  .pd-avatar { width:2rem; height:2rem; border-radius:9999px; background: linear-gradient(135deg, #8800E4, #c084fc); display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; font-size:.78rem; flex-shrink:0; overflow:hidden; }
  .pd-avatar img { width:100%; height:100%; object-fit:cover; }

  .pd-action-warn { display:flex; gap:.7rem; padding:.85rem; border-radius:.75rem; background: rgba(251,146,60,.06); border:1px solid rgba(251,146,60,.25); margin-bottom:.85rem; }
  .pd-action-warn-icon { width:2rem; height:2rem; border-radius:.55rem; background:rgba(251,146,60,.15); border:1px solid rgba(251,146,60,.3); display:flex; align-items:center; justify-content:center; flex-shrink:0; color:#fb923c; }
  .pd-action-warn-title { font-size:.78rem; font-weight:700; color:#fb923c; }
  .pd-action-warn-text { font-size:.72rem; color:#a1a1aa; margin-top:.15rem; line-height:1.45; }

  .pd-btn-primary { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; width:100%; padding:.7rem 1rem; border-radius:.7rem; background: linear-gradient(135deg, #8800E4, #c084fc); color:#fff; font-weight:700; font-size:.82rem; box-shadow: 0 6px 16px rgba(136,0,228,.25); transition: transform .15s, box-shadow .15s; border:none; cursor:pointer; }
  .pd-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(136,0,228,.35); }
  .pd-btn-ghost { display:inline-flex; align-items:center; justify-content:center; gap:.4rem; padding:.5rem .75rem; border-radius:.55rem; background: rgba(255,255,255,.04); border:1px solid #2a2a2f; color:#e4e4e7; font-size:.72rem; font-weight:600; transition: all .15s; cursor:pointer; }
  .pd-btn-ghost:hover { border-color: #8800E4; color:#fff; background: rgba(136,0,228,.08); }
  .pd-btn-danger { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; width:100%; padding:.6rem .8rem; border-radius:.6rem; background: rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.3); color:#fca5a5; font-size:.78rem; font-weight:600; transition: all .15s; cursor:pointer; }
  .pd-btn-danger:hover { background: rgba(239,68,68,.15); color:#fff; }

  .pd-id-row { display:flex; align-items:center; gap:.45rem; }
  .pd-id-input { flex:1; min-width:0; padding:.55rem .7rem; border-radius:.55rem; background:#0e0e10; border:1px solid #1f1f23; color:#a1a1aa; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.72rem; }
  .pd-help-text { font-size:.72rem; color:#a1a1aa; line-height:1.5; margin-bottom:.75rem; }

  /* Timeline */
  .pd-tl { display:flex; align-items:flex-start; gap:.5rem; padding-top:.25rem; }
  .pd-tl-step { display:flex; flex-direction:column; align-items:center; flex:0 0 auto; gap:.45rem; min-width:88px; }
  .pd-tl-bubble { width:3rem; height:3rem; border-radius:9999px; display:flex; align-items:center; justify-content:center; border:1.5px solid #2a2a2f; background:#0e0e10; color:#52525b; transition: all .25s; }
  .pd-tl-step.done .pd-tl-bubble { background: linear-gradient(135deg, #8800E4, #c084fc); border-color: transparent; color:#fff; box-shadow: 0 4px 14px rgba(136,0,228,.4); }
  .pd-tl-step.active .pd-tl-bubble { background: rgba(136,0,228,.12); border-color: #8800E4; color:#c084fc; }
  .pd-tl-label { font-size:.7rem; font-weight:600; color:#71717a; text-align:center; line-height:1.3; }
  .pd-tl-step.done .pd-tl-label, .pd-tl-step.active .pd-tl-label { color:#f4f4f5; }
  .pd-tl-line { flex:1; height:2px; margin-top:1.45rem; background:#27272a; border-radius:9999px; min-width:20px; }
  .pd-tl-line.done { background: linear-gradient(90deg, #8800E4, #c084fc); }

  /* Chat block */
  .pd-chat-sub { font-size:.72rem; color:#a1a1aa; margin-top:-.4rem; margin-bottom:.85rem; }
  .pd-policy { background:#0e0e10; border:1px solid #1f1f23; border-radius:.75rem; margin-bottom:.85rem; overflow:hidden; }
  .pd-policy > summary { padding:.7rem .85rem; cursor:pointer; font-size:.78rem; font-weight:600; color:#e4e4e7; display:flex; align-items:center; gap:.5rem; list-style:none; }
  .pd-policy > summary::-webkit-details-marker { display:none; }
  .pd-policy[open] > summary { border-bottom:1px solid #1f1f23; }
  .pd-policy ul { padding:.7rem 1.1rem .9rem 2.2rem; font-size:.72rem; color:#a1a1aa; line-height:1.55; margin:0; }
  .pd-policy ul li { margin-bottom:.3rem; list-style: disc; }

  /* Item list compact */
  .pd-item-row { display:flex; gap:.85rem; padding:.7rem; border-radius:.7rem; background:#0e0e10; border:1px solid #1f1f23; align-items:center; }
  .pd-item-row + .pd-item-row { margin-top:.5rem; }
  .pd-item-thumb { width:3rem; height:3rem; border-radius:.55rem; object-fit:cover; border:1px solid #1f1f23; background:#0a0a0a; flex-shrink:0; }
  .pd-item-name { font-size:.83rem; font-weight:600; color:#f4f4f5; line-height:1.3; }
  .pd-item-meta { font-size:.68rem; color:#71717a; margin-top:.15rem; }
  .pd-item-sub { color:#c084fc; font-weight:700; font-size:.85rem; flex-shrink:0; white-space:nowrap; }

  /* Delivery code block */
  .pd-code-block { margin-top:.85rem; padding:.85rem; border-radius:.75rem; background: linear-gradient(180deg, rgba(136,0,228,.06), rgba(136,0,228,.02)); border:1px solid rgba(136,0,228,.25); }
  .pd-code-title { font-size:.78rem; font-weight:700; color:#c084fc; display:flex; align-items:center; gap:.4rem; margin-bottom:.4rem; }
  .pd-code-text { font-size:.68rem; color:#a1a1aa; margin-bottom:.6rem; line-height:1.45; }
  .pd-code-boxes { display:flex; gap:.4rem; align-items:center; flex-wrap:wrap; }
  .pd-code-box { width:2.4rem; height:2.85rem; border-radius:.5rem; background:#0e0e10; border:1px solid rgba(136,0,228,.35); display:flex; align-items:center; justify-content:center; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:1.05rem; font-weight:700; color:#c084fc; }

  /* Resume payment */
  .pd-resume { padding:.95rem; border-radius:.8rem; background: linear-gradient(135deg, rgba(234,179,8,.07), rgba(249,115,22,.04)); border:1px solid rgba(234,179,8,.3); display:flex; gap:.75rem; align-items:flex-start; margin-bottom:1.1rem; }
  .pd-resume-icon { width:2.4rem; height:2.4rem; border-radius:.65rem; background: rgba(234,179,8,.15); border:1px solid rgba(234,179,8,.3); display:flex; align-items:center; justify-content:center; color:#facc15; flex-shrink:0; }

  /* Review */
  .pd-rev-item { padding:.95rem; border-radius:.75rem; background:#0e0e10; border:1px solid #1f1f23; }
  .pd-rev-item + .pd-rev-item { margin-top:.7rem; }

  /* Mobile ordering: timeline → produto → chat → ações → tx → ajuda → reviews */
  @media (max-width: 1023px) {
    .pd-left, .pd-right { display:contents; }
    .pd-card-timeline { order: 1; }
    .pd-card-prod     { order: 2; }
    .pd-card-chat     { order: 3; }
    .pd-card-actions  { order: 4; }
    .pd-card-items    { order: 5; }
    .pd-card-tx       { order: 6; }
    .pd-card-help     { order: 7; }
    .pd-card-reviews  { order: 8; }
  }
</style>

<div class="pd-wrap">
  <div class="mb-4">
    <a href="<?= BASE_PATH ?>/meus_pedidos" class="pd-back">
      <i data-lucide="arrow-left" class="w-4 h-4"></i>
      Voltar
    </a>
  </div>

  <div class="pd-hero">
    <h1>Detalhes do pedido <span>#<?= (int)$order['id'] ?></span></h1>
    <div class="pd-hero-meta">
      <span><i data-lucide="calendar" class="w-3.5 h-3.5 inline -mt-0.5"></i> <b><?= fmtDate((string)$order['criado_em']) ?></b></span>
      <span>Status: <span class="pd-status-pill <?= $orderStatusBadge((string)$order['status']) ?>"><?= htmlspecialchars((string)$order['status'], ENT_QUOTES, 'UTF-8') ?></span></span>
      <span>Pagamento: <b><?= $payMethod ?></b></span>
      <span>Total: <b>R$ <?= number_format($displayTotal, 2, ',', '.') ?></b></span>
      <span>Itens: <b><?= count($items) ?></b></span>
    </div>
  </div>

  <?php if ($msg): ?><div class="mb-4 rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="mb-4 rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <?php if ($canResumePayment): ?>
  <div class="pd-resume">
    <div class="pd-resume-icon"><i data-lucide="qr-code" class="w-5 h-5"></i></div>
    <div class="flex-1 min-w-0">
      <p class="text-sm font-bold" style="color:#facc15">Pagamento pendente</p>
      <p class="text-xs text-zinc-400 mt-0.5">Você ainda não concluiu o pagamento. Continue de onde parou — o mesmo QR Code será reaberto.</p>
      <div class="mt-3 flex flex-wrap gap-2 items-center">
        <a href="<?= BASE_PATH ?>/checkout_pix?order_id=<?= (int)$order['id'] ?>" class="pd-btn-primary" style="width:auto; padding:.55rem 1rem">
          <i data-lucide="arrow-right-circle" class="w-4 h-4"></i> Continuar pagamento
        </a>
        <span class="text-[11px] text-zinc-500">Valor restante: <b class="text-zinc-200">R$ <?= number_format($pixPortion, 2, ',', '.') ?></b></span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="pd-grid">

    <!-- ════════════════════ LEFT COLUMN ════════════════════ -->
    <div class="pd-left">

      <!-- Detalhes do Produto -->
      <div class="pd-card pd-card-prod">
        <h3 class="pd-card-title"><i data-lucide="package" class="w-4 h-4"></i> Detalhes do Produto</h3>
        <?php if ($primaryItem):
          $primaryImg = mediaResolveUrl((string)($primaryItem['produto_imagem'] ?? ''), 'https://placehold.co/600x450/0e0e10/3f3f46?text=Sem+imagem');
        ?>
        <div class="pd-field" style="margin-top:0">
          <p class="pd-field-label">Imagens do Produto</p>
          <a href="<?= sfProductUrl(['id'=>(int)$primaryItem['product_id'],'slug'=>(string)($primaryItem['produto_slug']??'')]) ?>">
            <img src="<?= htmlspecialchars($primaryImg, ENT_QUOTES, 'UTF-8') ?>" alt="" class="pd-prod-img">
          </a>
        </div>
        <div class="pd-field">
          <p class="pd-field-label">Produto</p>
          <p class="pd-field-value font-semibold"><?= htmlspecialchars((string)($primaryItem['produto_nome'] ?? 'Produto'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="pd-field">
          <p class="pd-field-label">Valor</p>
          <p class="pd-value-big">R$ <?= number_format($displayTotal, 2, ',', '.') ?></p>
        </div>
        <div class="pd-field">
          <p class="pd-field-label">Tipo de Entrega</p>
          <span class="pd-badge-auto"><i data-lucide="zap" class="w-3.5 h-3.5"></i> Automática</span>
        </div>
        <div class="pd-field">
          <p class="pd-field-label">Vendedor</p>
          <div class="pd-user-row">
            <div class="pd-avatar">
              <?php if ($primaryVendorAvatarUrl): ?><img src="<?= htmlspecialchars($primaryVendorAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= $avatarInitial((string)($primaryItem['vendedor_nome'] ?? '')) ?><?php endif; ?>
            </div>
            <div class="pd-field-value"><?= htmlspecialchars((string)($primaryItem['vendedor_nome'] ?? 'Vendedor'), ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
        <div class="pd-field">
          <p class="pd-field-label">Comprador</p>
          <div class="pd-user-row">
            <div class="pd-avatar">
              <?php if ($buyerAvatarUrl): ?><img src="<?= htmlspecialchars($buyerAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= $avatarInitial((string)$buyerInfo['nome']) ?><?php endif; ?>
            </div>
            <div class="pd-field-value"><?= htmlspecialchars((string)$buyerInfo['nome'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Ações -->
      <?php if ($canConfirmReceive): ?>
      <div class="pd-card pd-card-actions">
        <h3 class="pd-card-title"><i data-lucide="zap" class="w-4 h-4"></i> Ações</h3>
        <div class="pd-action-warn">
          <div class="pd-action-warn-icon"><i data-lucide="package-check" class="w-4 h-4"></i></div>
          <div>
            <p class="pd-action-warn-title">Produto enviado!</p>
            <p class="pd-action-warn-text">Após receber e testar, confirme a entrega.</p>
          </div>
        </div>
        <form method="POST" onsubmit="return confirm('Confirmar o recebimento? Isso libera o pagamento ao vendedor.');">
          <input type="hidden" name="action" value="confirm_delivery">
          <button type="submit" class="pd-btn-primary">
            <i data-lucide="check-circle-2" class="w-4 h-4"></i> Confirmar Recebimento
          </button>
        </form>
      </div>
      <?php endif; ?>

      <!-- ID DA TRANSAÇÃO -->
      <div class="pd-card pd-card-tx">
        <p class="pd-field-label" style="margin-bottom:.5rem">ID da Transação</p>
        <div class="pd-id-row">
          <input type="text" class="pd-id-input" readonly value="<?= htmlspecialchars($txId, ENT_QUOTES, 'UTF-8') ?>" id="pdTxId">
          <button type="button" class="pd-btn-ghost" onclick="(function(b){navigator.clipboard.writeText(document.getElementById('pdTxId').value);b.innerHTML='<i data-lucide=\'check\' class=\'w-3.5 h-3.5\'></i> Copiado';if(window.lucide)lucide.createIcons();setTimeout(function(){b.innerHTML='<i data-lucide=\'copy\' class=\'w-3.5 h-3.5\'></i> Copiar ID';if(window.lucide)lucide.createIcons();},2000);})(this)">
            <i data-lucide="copy" class="w-3.5 h-3.5"></i> Copiar ID
          </button>
        </div>
      </div>

      <!-- Precisa de ajuda? -->
      <div class="pd-card pd-card-help">
        <h3 class="pd-card-title"><i data-lucide="life-buoy" class="w-4 h-4"></i> Precisa de ajuda?</h3>
        <p class="pd-help-text">Nossa equipe monitora todas as transações para garantir sua segurança. Reporte qualquer problema imediatamente.</p>
        <?php if ($primaryChatConvId > 0): ?>
        <button type="button" class="pd-btn-danger" onclick="if(window.openUserChat){window.openUserChat(<?= $primaryChatConvId ?>);} else { alert('Abra o chat pelo menu para reportar.'); }">
          <i data-lucide="alert-triangle" class="w-4 h-4"></i> Reportar Problema
        </button>
        <?php else: ?>
        <button type="button" class="pd-btn-danger" onclick="alert('Aguarde a confirmação do pedido para reportar um problema.');">
          <i data-lucide="alert-triangle" class="w-4 h-4"></i> Reportar Problema
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- ════════════════════ RIGHT COLUMN ════════════════════ -->
    <div class="pd-right">

      <!-- Status da Transação -->
      <div class="pd-card pd-card-timeline">
        <h3 class="pd-card-title"><i data-lucide="git-commit-horizontal" class="w-4 h-4"></i> Status da Transação</h3>
        <div class="pd-tl">
          <div class="pd-tl-step <?= $timelineStep >= 1 ? 'done' : '' ?>">
            <div class="pd-tl-bubble"><i data-lucide="<?= $timelineStep > 1 ? 'check' : 'wallet' ?>" class="w-5 h-5"></i></div>
            <div class="pd-tl-label">Aguardando<br>pagamento</div>
          </div>
          <div class="pd-tl-line <?= $timelineStep > 1 ? 'done' : '' ?>"></div>
          <div class="pd-tl-step <?= $timelineStep === 2 ? 'active' : ($timelineStep > 2 ? 'done' : '') ?>">
            <div class="pd-tl-bubble"><i data-lucide="<?= $timelineStep > 2 ? 'check' : 'shield-check' ?>" class="w-5 h-5"></i></div>
            <div class="pd-tl-label">Confirmação</div>
          </div>
          <div class="pd-tl-line <?= $timelineStep > 2 ? 'done' : '' ?>"></div>
          <div class="pd-tl-step <?= $timelineStep >= 3 ? 'done' : '' ?>">
            <div class="pd-tl-bubble"><i data-lucide="<?= $timelineStep >= 3 ? 'check' : 'flag' ?>" class="w-5 h-5"></i></div>
            <div class="pd-tl-label">Concluído</div>
          </div>
        </div>
      </div>

      <!-- Chat da Transação (Commit A = shortcut; Commit B = inline polling) -->
      <div class="pd-card pd-card-chat">
        <h3 class="pd-card-title"><i data-lucide="message-circle" class="w-4 h-4"></i> Chat da Transação</h3>
        <p class="pd-chat-sub">Comunique-se com segurança através da plataforma.</p>
        <details class="pd-policy">
          <summary><i data-lucide="clipboard-list" class="w-4 h-4"></i> Políticas de confirmação e disputas</summary>
          <ul>
            <li>Confirme o recebimento somente após testar o produto recebido.</li>
            <li>Em caso de problema, abra disputa em até 7 dias após a entrega.</li>
            <li>Toda comunicação ocorre por aqui — não negocie fora da plataforma.</li>
            <li>O pagamento ao vendedor só é liberado após sua confirmação.</li>
          </ul>
        </details>

        <?php if ($primaryChatConvId > 0): ?>
        <button type="button" class="pd-btn-primary" onclick="if(window.openUserChat){window.openUserChat(<?= $primaryChatConvId ?>);}">
          <i data-lucide="message-circle" class="w-4 h-4"></i> Abrir chat com <?= htmlspecialchars((string)($primaryItem['vendedor_nome'] ?? 'vendedor'), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <p class="text-[11px] text-zinc-500 mt-2 text-center">As instruções, conteúdo entregue e código de entrega aparecem no chat.</p>
        <?php else: ?>
        <div class="p-3 rounded-lg" style="background:#0e0e10; border:1px solid #1f1f23; text-align:center; color:#71717a; font-size:.72rem;">
          O chat será disponibilizado após o pagamento ser confirmado.
        </div>
        <?php endif; ?>

        <?php if ($deliveryCode): ?>
        <div class="pd-code-block">
          <p class="pd-code-title"><i data-lucide="key-round" class="w-4 h-4"></i> Código de entrega</p>
          <p class="pd-code-text">Forneça este código ao vendedor no chat para liberar o pagamento.</p>
          <div class="pd-code-boxes">
            <?php for ($ci = 0; $ci < strlen($deliveryCode); $ci++): ?>
            <span class="pd-code-box"><?= htmlspecialchars($deliveryCode[$ci], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endfor; ?>
            <button type="button" class="pd-btn-ghost" style="margin-left:.5rem" onclick="(function(b){navigator.clipboard.writeText('<?= htmlspecialchars($deliveryCode, ENT_QUOTES, 'UTF-8') ?>');b.innerHTML='<i data-lucide=\'check\' class=\'w-3.5 h-3.5\'></i> Copiado';if(window.lucide)lucide.createIcons();setTimeout(function(){b.innerHTML='<i data-lucide=\'copy\' class=\'w-3.5 h-3.5\'></i> Copiar';if(window.lucide)lucide.createIcons();},2000);})(this)">
              <i data-lucide="copy" class="w-3.5 h-3.5"></i> Copiar
            </button>
          </div>
        </div>
        <?php elseif (in_array($orderStatusLower, ['entregue','concluido'], true)): ?>
        <div class="pd-code-block" style="background: rgba(34,197,94,.05); border-color: rgba(34,197,94,.3);">
          <p class="pd-code-title" style="color:#86efac"><i data-lucide="check-circle-2" class="w-4 h-4"></i> Entrega confirmada</p>
          <p class="pd-code-text" style="margin-bottom:0">O código já foi utilizado para liberar o pagamento.</p>
        </div>
        <?php endif; ?>
      </div>

      <!-- Itens do pedido -->
      <?php if ($items): ?>
      <div class="pd-card pd-card-items">
        <h3 class="pd-card-title"><i data-lucide="list" class="w-4 h-4"></i> Itens do pedido <span class="text-zinc-500 font-normal text-xs ml-1">(<?= count($items) ?>)</span></h3>
        <?php foreach ($items as $it):
          $imgUrl = mediaResolveUrl((string)($it['produto_imagem'] ?? ''), 'https://placehold.co/80x80/0e0e10/3f3f46?text=•');
          $prodNome = htmlspecialchars((string)($it['produto_nome'] ?? 'Produto'), ENT_QUOTES, 'UTF-8');
          $vendorNome = htmlspecialchars((string)($it['vendedor_nome'] ?? 'Vendedor'), ENT_QUOTES, 'UTF-8');
          $deliveryContent = trim((string)($it['delivery_content'] ?? ''));
          $deliveredAt = (string)($it['delivered_at'] ?? '');
          $orderIsPaid = in_array($orderStatusLower, ['pago','entregue','concluido'], true);
        ?>
        <div class="pd-item-row">
          <a href="<?= sfProductUrl(['id'=>(int)$it['product_id'],'slug'=>(string)($it['produto_slug']??'')]) ?>" class="flex-shrink-0">
            <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="pd-item-thumb">
          </a>
          <div class="flex-1 min-w-0">
            <p class="pd-item-name truncate"><?= $prodNome ?></p>
            <p class="pd-item-meta">Vendedor: <?= $vendorNome ?> · Qtd <?= (int)$it['quantidade'] ?> · Unit R$ <?= number_format((float)$it['preco_unit'], 2, ',', '.') ?></p>
            <?php if ($deliveryContent !== '' && $orderIsPaid):
              $isUrl = (bool)preg_match('#^https?://#i', $deliveryContent);
            ?>
            <div class="mt-2 p-2 rounded-lg" style="background:rgba(34,197,94,.05); border:1px solid rgba(34,197,94,.25);">
              <div class="flex items-center gap-1.5 mb-1">
                <i data-lucide="download" class="w-3.5 h-3.5" style="color:#86efac"></i>
                <span class="text-[11px] font-bold" style="color:#86efac">Entrega digital recebida</span>
                <?php if ($deliveredAt !== ''): ?><span class="text-[10px] text-zinc-500 ml-auto"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($deliveredAt)), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
              </div>
              <?php if ($isUrl): ?>
              <a href="<?= htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="text-xs text-greenx hover:text-white break-all"><?= htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8') ?></a>
              <?php else: ?>
              <p class="text-xs text-zinc-300 break-all whitespace-pre-wrap"><?= htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="pd-item-sub">R$ <?= number_format((float)$it['subtotal'], 2, ',', '.') ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Avaliações -->
      <?php
      if ($canShowReview && $items):
        try { reviewEnsureTable($conn); } catch (\Throwable $e) {}
      ?>
      <div class="pd-card pd-card-reviews" id="avaliacoes">
        <h3 class="pd-card-title"><i data-lucide="star" class="w-4 h-4" style="color:#facc15"></i> Avaliações</h3>
        <p class="pd-chat-sub">Sua avaliação ajuda outros compradores a confiar no vendedor.</p>
        <div>
          <?php foreach ($items as $it):
            $prodId = (int)$it['product_id'];
            $prodNome = htmlspecialchars((string)($it['produto_nome'] ?? 'Produto'), ENT_QUOTES, 'UTF-8');
            $imgUrl = mediaResolveUrl((string)($it['produto_imagem'] ?? ''), '');
            try { $canRev = reviewCanUserReview($conn, $userId, $prodId); }
            catch (\Throwable $e) { $canRev = ['can' => false, 'reason' => 'Erro ao verificar.']; }
          ?>
          <div class="pd-rev-item">
            <div class="flex items-center gap-3 mb-3">
              <?php if ($imgUrl): ?><img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="w-10 h-10 rounded-lg object-cover border border-blackx3"><?php endif; ?>
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-sm truncate"><?= $prodNome ?></p>
                <?php if (!$canRev['can']): ?><p class="text-xs text-zinc-500"><?= htmlspecialchars($canRev['reason'] ?? '', ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
              </div>
            </div>
            <?php if ($canRev['can']): ?>
            <div class="space-y-3" id="reviewBox<?= $prodId ?>">
              <div>
                <label class="text-xs text-zinc-400 mb-1 block">Sua nota</label>
                <div class="flex items-center gap-1" id="starSel<?= $prodId ?>">
                  <?php for ($s = 1; $s <= 5; $s++): ?>
                  <button type="button" onclick="setRevStar(<?= $prodId ?>,<?= $s ?>)" class="p-1 rounded-lg hover:bg-white/[0.06] transition-all">
                    <svg class="w-6 h-6 text-zinc-600 fill-current rev-star-<?= $prodId ?>" data-v="<?= $s ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
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
              <button type="button" onclick="submitRev(<?= $prodId ?>)" id="revBtn<?= $prodId ?>" class="pd-btn-primary" style="width:auto; padding:.55rem 1rem">
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
            body: JSON.stringify({ action:'submit', product_id:pid, rating:revRatings[pid],
              titulo: document.getElementById('revTit'+pid).value,
              comentario: document.getElementById('revCom'+pid).value })
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

    </div>
  </div>
</div>

<?php
include $ROOT . '/views/partials/user_layout_end.php';
include $ROOT . '/views/partials/footer.php';