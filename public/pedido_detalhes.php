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
  @media (max-width: 1023px) { .pd-wrap { max-width: 100%; overflow-x: hidden; } body { overflow-x: hidden; } }
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
  .pd-item-row { padding:.7rem; border-radius:.7rem; background:#0e0e10; border:1px solid #1f1f23; }
  .pd-item-row + .pd-item-row { margin-top:.5rem; }
  .pd-item-head { display:flex; gap:.85rem; align-items:center; }
  .pd-item-head > a { flex-shrink:0; }
  .pd-item-body { flex:1; min-width:0; }
  .pd-item-sub-m { display:none; }
  .pd-delivery-full { margin-top:.65rem; padding:.6rem .75rem; border-radius:.65rem; background:rgba(34,197,94,.05); border:1px solid rgba(34,197,94,.25); }
  .pd-delivery-full .pd-delivery-head { display:flex; align-items:center; gap:.4rem; margin-bottom:.35rem; }
  .pd-delivery-full .pd-delivery-head .pd-delivery-time { margin-left:auto; font-size:10px; color:#71717a; }
  .pd-delivery-content { font-size:.78rem; color:#e4e4e7; word-break:break-all; white-space:pre-wrap; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; }
  .pd-delivery-content a { color:#86efac; }
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

  /* ── Inline chat embed (Commit B) ── */
  .pd-chat-embed { margin-top:.15rem; border:1px solid #1f1f23; border-radius:.85rem; background:#0e0e10; overflow:hidden; display:flex; flex-direction:column; }
  .pd-chat-head { display:flex; align-items:center; gap:.6rem; padding:.65rem .8rem; border-bottom:1px solid #1f1f23; background: linear-gradient(180deg, rgba(136,0,228,.05), transparent); }
  .pd-chat-head-name { font-size:.82rem; font-weight:700; color:#f4f4f5; line-height:1.2; }
  .pd-chat-head-sub { font-size:.62rem; color:#a1a1aa; display:flex; align-items:center; gap:.35rem; margin-top:.1rem; }
  .pd-online-dot { width:.45rem; height:.45rem; background:#22c55e; border-radius:9999px; box-shadow:0 0 0 3px rgba(34,197,94,.15); display:inline-block; }
  .pd-msg-list { height:340px; overflow-y:auto; padding:.85rem; display:flex; flex-direction:column; gap:.35rem; scroll-behavior:smooth; background: radial-gradient(circle at 0% 0%, rgba(136,0,228,.03), transparent 40%); }
  .pd-msg-list::-webkit-scrollbar { width:6px; }
  .pd-msg-list::-webkit-scrollbar-thumb { background:#2a2a2f; border-radius:9999px; }
  .pd-msg-loading, .pd-msg-empty { color:#71717a; font-size:.72rem; text-align:center; padding:1rem; display:flex; align-items:center; justify-content:center; gap:.4rem; flex:1; }
  .pd-msg-day { font-size:.58rem; font-weight:700; color:#71717a; text-align:center; text-transform:uppercase; letter-spacing:.08em; margin:.6rem 0 .25rem; padding:.18rem .5rem; align-self:center; background:rgba(255,255,255,.03); border-radius:9999px; }
  .pd-msg { max-width:80%; padding:.55rem .75rem; border-radius:.75rem; font-size:.78rem; line-height:1.42; word-wrap:break-word; word-break:break-word; }
  .pd-msg-time { font-size:.55rem; color:#71717a; margin-top:.2rem; opacity:.85; }
  .pd-msg.mine { background: linear-gradient(135deg, #8800E4, #c084fc); color:#fff; align-self:flex-end; border-bottom-right-radius:.22rem; box-shadow: 0 2px 8px rgba(136,0,228,.18); }
  .pd-msg.mine .pd-msg-time { color: rgba(255,255,255,.78); text-align:right; }
  .pd-msg.theirs { background:#18181b; color:#e4e4e7; align-self:flex-start; border:1px solid #27272a; border-bottom-left-radius:.22rem; }
  .pd-msg.sys { background: linear-gradient(180deg, rgba(136,0,228,.08), rgba(136,0,228,.03)); border:1px dashed rgba(136,0,228,.32); color:#d4d4d8; align-self:stretch; max-width:100%; padding:.65rem .8rem; }
  .pd-msg.sys .pd-sys-hd { font-size:.62rem; font-weight:700; color:#c084fc; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.25rem; display:flex; align-items:center; gap:.35rem; }
  .pd-msg.sys .pd-sys-body { font-size:.74rem; color:#d4d4d8; white-space:pre-wrap; }
  .pd-msg.sys.t-dlvr { background: linear-gradient(180deg, rgba(34,197,94,.07), rgba(34,197,94,.02)); border-color: rgba(34,197,94,.32); }
  .pd-msg.sys.t-dlvr .pd-sys-hd { color:#86efac; }
  .pd-msg.sys.t-code { background: linear-gradient(180deg, rgba(250,204,21,.07), rgba(250,204,21,.02)); border-color: rgba(250,204,21,.32); }
  .pd-msg.sys.t-code .pd-sys-hd { color:#facc15; }
  .pd-sys-box { margin-top:.45rem; padding:.5rem .65rem; background:#0a0a0a; border:1px solid #27272a; border-radius:.5rem; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.78rem; color:#fff; display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
  .pd-sys-box button { background: rgba(136,0,228,.15); border:1px solid rgba(136,0,228,.35); color:#c084fc; padding:.25rem .55rem; border-radius:.35rem; font-size:.62rem; font-weight:600; cursor:pointer; }
  .pd-chat-form { display:flex; gap:.4rem; padding:.55rem; border-top:1px solid #1f1f23; background:#0a0a0a; align-items:flex-end; }
  .pd-chat-input { flex:1; min-width:0; background:#18181b; border:1px solid #27272a; border-radius:.55rem; padding:.55rem .7rem; color:#f4f4f5; font-size:.78rem; resize:none; max-height:90px; min-height:2.3rem; transition: border-color .15s; font-family: inherit; }
  .pd-chat-input:focus { outline:none; border-color:#8800E4; }
  .pd-chat-send { width:2.4rem; height:2.4rem; border-radius:.55rem; background: linear-gradient(135deg, #8800E4, #c084fc); color:#fff; display:flex; align-items:center; justify-content:center; border:none; cursor:pointer; transition: transform .15s, box-shadow .15s; flex-shrink:0; }
  .pd-chat-send:hover { transform: scale(1.05); box-shadow: 0 4px 12px rgba(136,0,228,.4); }
  .pd-chat-send:disabled { opacity:.5; cursor:not-allowed; }

  /* Paste-to-chat 6-box (Commit B) */
  .pd-paste-block { margin-top:.85rem; padding:.95rem; border-radius:.85rem; background: linear-gradient(180deg, rgba(136,0,228,.06), rgba(136,0,228,.02)); border:1px solid rgba(136,0,228,.25); }
  .pd-paste-row { display:flex; gap:.4rem; justify-content:flex-start; flex-wrap:wrap; margin:.6rem 0; }
  .pd-paste-input { width:2.6rem; height:3rem; border-radius:.55rem; background:#0e0e10; border:1px solid rgba(136,0,228,.35); color:#c084fc; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size:1.15rem; font-weight:700; text-align:center; text-transform:uppercase; transition: all .15s; padding:0; }
  .pd-paste-input:focus { outline:none; border-color:#c084fc; box-shadow: 0 0 0 3px rgba(192,132,252,.18); }
  .pd-paste-input.filled { background: rgba(136,0,228,.1); border-color: #c084fc; }
  .pd-paste-actions { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-top:.4rem; }
  .pd-paste-msg { font-size:.7rem; margin-top:.55rem; min-height:1rem; }
  .pd-paste-msg.ok { color:#86efac; }
  .pd-paste-msg.err { color:#fca5a5; }

  /* Mobile ordering: timeline → produto → chat → ações → tx → ajuda → reviews */
  @media (max-width: 1023px) {
    .pd-left, .pd-right { display:contents; }
    .pd-card-prod     { order: 1; }
    .pd-card-actions  { order: 2; }
    .pd-card-tx       { order: 3; }
    .pd-card-help     { order: 4; }
    .pd-card-timeline { order: 5; }
    .pd-card-chat     { order: 6; }
    .pd-card-items    { order: 7; }
    .pd-card-reviews  { order: 8; }
    .pd-card { max-width: 100%; overflow: hidden; }
    .pd-tl { gap: .35rem; }
    .pd-tl-bubble { width: 2.4rem; height: 2.4rem; }
    .pd-tl-label { font-size: .62rem; }
    .pd-paste-row { justify-content: center; gap: .3rem; }
    .pd-paste-input { width: 2.3rem; height: 2.7rem; font-size: 1rem; }
    .pd-msg-list { height: 280px; }
    .pd-msg { max-width: 88%; }
    .pd-sys-box { font-size: .68rem; word-break: break-all; }
    .pd-item-head { align-items: flex-start; }
    .pd-item-head > .pd-item-sub { display: none; }
    .pd-item-sub-m { display:block; margin-top:.25rem; font-size:.95rem; color:#c084fc; font-weight:700; }
    .pd-delivery-full { padding:.55rem .65rem; }
    .pd-delivery-content { font-size:.72rem; }
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

      <!-- Chat da Transação (inline embed + polling — Commit B) -->
      <div class="pd-card pd-card-chat">
        <h3 class="pd-card-title"><i data-lucide="message-circle" class="w-4 h-4"></i> Chat da Transação</h3>
        <p class="pd-chat-sub">Comunique-se com segurança pela plataforma. Atualiza em tempo real.</p>
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
        <div class="pd-chat-embed" id="pdChatEmbed" data-conv="<?= (int)$primaryChatConvId ?>" data-uid="<?= (int)$userId ?>">
          <div class="pd-chat-head">
            <div class="pd-avatar" style="width:2.2rem;height:2.2rem">
              <?php if ($primaryVendorAvatarUrl): ?><img src="<?= htmlspecialchars($primaryVendorAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><?= $avatarInitial((string)($primaryItem['vendedor_nome'] ?? '')) ?><?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
              <p class="pd-chat-head-name"><?= htmlspecialchars((string)($primaryItem['vendedor_nome'] ?? 'Vendedor'), ENT_QUOTES, 'UTF-8') ?></p>
              <p class="pd-chat-head-sub"><span class="pd-online-dot"></span> Conversa segura — monitorada</p>
            </div>
            <button type="button" class="pd-btn-ghost" onclick="if(window.openUserChat){window.openUserChat(<?= (int)$primaryChatConvId ?>);}" title="Expandir no painel">
              <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
            </button>
          </div>
          <div class="pd-msg-list" id="pdMsgList">
            <div class="pd-msg-loading"><i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Carregando mensagens...</div>
          </div>
          <form class="pd-chat-form" id="pdChatForm" onsubmit="return pdChatSend(event)">
            <textarea id="pdChatInput" class="pd-chat-input" placeholder="Escreva sua mensagem..." rows="1" maxlength="2000"></textarea>
            <button type="submit" class="pd-chat-send" id="pdChatSendBtn" title="Enviar">
              <i data-lucide="send" class="w-4 h-4"></i>
            </button>
          </form>
        </div>
        <?php else: ?>
        <div class="p-3 rounded-lg" style="background:#0e0e10; border:1px solid #1f1f23; text-align:center; color:#71717a; font-size:.72rem;">
          O chat será disponibilizado após o pagamento ser confirmado.
        </div>
        <?php endif; ?>

        <?php if ($deliveryCode && $primaryChatConvId > 0): ?>
        <?php $deliveryCodeClean = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$deliveryCode)), 0, 6); ?>
        <div class="pd-paste-block" id="pdPasteBlock">
          <p class="pd-code-title"><i data-lucide="key-round" class="w-4 h-4"></i> Código de entrega — envie pelo chat</p>
          <p class="pd-code-text">O código já está preenchido nas 6 caixas abaixo. Clique em enviar para mandar ao vendedor pelo chat.</p>
          <div class="pd-paste-row" id="pdPasteRow">
            <?php for ($pi = 0; $pi < 6; $pi++): ?>
            <?php $digit = $deliveryCodeClean[$pi] ?? ''; ?>
            <input type="text" class="pd-paste-input <?= $digit !== '' ? 'filled' : '' ?>" maxlength="1" data-pi="<?= $pi ?>" autocomplete="off" inputmode="text" aria-label="Dígito <?= $pi + 1 ?>" value="<?= htmlspecialchars($digit, ENT_QUOTES, 'UTF-8') ?>" readonly>
            <?php endfor; ?>
          </div>
          <div class="pd-paste-actions">
            <button type="button" class="pd-btn-primary" id="pdPasteSend" data-code="<?= htmlspecialchars($deliveryCodeClean, ENT_QUOTES, 'UTF-8') ?>" style="width:auto;padding:.55rem 1rem;font-size:.78rem;">
              <i data-lucide="send" class="w-4 h-4"></i> Enviar pelo chat
            </button>
          </div>
          <p class="pd-paste-msg" id="pdPasteMsg"></p>
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
          <div class="pd-item-head">
            <a href="<?= sfProductUrl(['id'=>(int)$it['product_id'],'slug'=>(string)($it['produto_slug']??'')]) ?>" class="flex-shrink-0">
              <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="pd-item-thumb">
            </a>
            <div class="pd-item-body">
              <p class="pd-item-name truncate"><?= $prodNome ?></p>
              <p class="pd-item-meta">Vendedor: <?= $vendorNome ?> &middot; Qtd <?= (int)$it['quantidade'] ?> &middot; Unit R$ <?= number_format((float)$it['preco_unit'], 2, ',', '.') ?></p>
              <div class="pd-item-sub-m">R$ <?= number_format((float)$it['subtotal'], 2, ',', '.') ?></div>
            </div>
            <div class="pd-item-sub">R$ <?= number_format((float)$it['subtotal'], 2, ',', '.') ?></div>
          </div>
          <?php if ($deliveryContent !== '' && $orderIsPaid):
            $isUrl = (bool)preg_match('#^https?://#i', $deliveryContent);
          ?>
          <div class="pd-delivery-full">
            <div class="pd-delivery-head">
              <i data-lucide="download" class="w-3.5 h-3.5" style="color:#86efac"></i>
              <span class="text-[11px] font-bold" style="color:#86efac">Entrega digital recebida</span>
              <?php if ($deliveredAt !== ''): ?><span class="pd-delivery-time"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($deliveredAt)), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            </div>
            <?php if ($isUrl): ?>
            <div class="pd-delivery-content"><a href="<?= htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8') ?></a></div>
            <?php else: ?>
            <div class="pd-delivery-content"><?= htmlspecialchars($deliveryContent, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
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

<?php if ($primaryChatConvId > 0): ?>
<script>
(function(){
  'use strict';
  var API = '<?= BASE_PATH ?>/api/chat.php';
  var CONV = <?= (int)$primaryChatConvId ?>;
  var UID  = <?= (int)$userId ?>;
  var list = document.getElementById('pdMsgList');
  var inp  = document.getElementById('pdChatInput');
  var form = document.getElementById('pdChatForm');
  var sndB = document.getElementById('pdChatSendBtn');
  if (!list || !inp || !form) return;

  var lastId = 0, lastDay = '', pollTmr = null, sending = false;

  function esc(s){ var d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
  function fmtTime(ts){ if(!ts) return ''; var d=new Date(String(ts).replace(' ','T')); return isNaN(d)?'':d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}); }
  function fmtDay(ts){
    if(!ts) return '';
    var d = new Date(String(ts).replace(' ','T')); if(isNaN(d)) return '';
    var today=new Date(); var y=new Date(); y.setDate(today.getDate()-1);
    var sameDay=function(a,b){return a.toDateString()===b.toDateString();};
    if(sameDay(d,today)) return 'Hoje';
    if(sameDay(d,y)) return 'Ontem';
    return d.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric'});
  }
  function refreshIcons(){ if(window.lucide && lucide.createIcons) lucide.createIcons(); }
  function scrollEnd(){ list.scrollTop = list.scrollHeight; }

  function buildSysMsg(type, body, time){
    var meta = {
      INSTRUCOES_VENDA: { cls:'t-inst', label:'Instruções de venda', icon:'clipboard-list' },
      ENTREGA_AUTO:     { cls:'t-dlvr', label:'Produto entregue',    icon:'package-check' },
      CODIGO_ENTREGA:   { cls:'t-code', label:'Código de entrega',   icon:'key-round' },
      SISTEMA:          { cls:'t-sys',  label:'Sistema',             icon:'info' }
    }[type] || { cls:'t-sys', label:'Sistema', icon:'info' };
    var el = document.createElement('div');
    el.className = 'pd-msg sys ' + meta.cls;
    // Header
    var hd = document.createElement('div');
    hd.className = 'pd-sys-hd';
    hd.innerHTML = '<i data-lucide="'+meta.icon+'" class="w-3.5 h-3.5"></i> '+esc(meta.label);
    el.appendChild(hd);
    // Body — render box if there's a ━━━ delimiter
    var boxMatch = body.match(/━+\n([\s\S]*?)\n━+/);
    if (boxMatch){
      var before = body.substring(0, body.indexOf('━')).trim();
      var boxTxt = boxMatch[1].trim();
      var after  = body.substring(body.lastIndexOf('━')+1).trim();
      if (before){ var bf=document.createElement('div'); bf.className='pd-sys-body'; bf.textContent=before; el.appendChild(bf); }
      var bx = document.createElement('div'); bx.className='pd-sys-box';
      bx.innerHTML = '<span>'+esc(boxTxt)+'</span><button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent.trim());this.textContent=\'Copiado\';setTimeout(()=>this.textContent=\'Copiar\',1800)">Copiar</button>';
      el.appendChild(bx);
      if (after){ var af=document.createElement('div'); af.className='pd-sys-body'; af.textContent=after; el.appendChild(af); }
    } else {
      var bd=document.createElement('div'); bd.className='pd-sys-body'; bd.textContent=body; el.appendChild(bd);
    }
    var tm=document.createElement('div'); tm.className='pd-msg-time'; tm.textContent=fmtTime(time); el.appendChild(tm);
    return el;
  }

  function buildMsg(m){
    var txt = m.message || '';
    var sm = txt.match(/^\[(INSTRUCOES_VENDA|ENTREGA_AUTO|CODIGO_ENTREGA|SISTEMA)\]\n/);
    if (sm) return buildSysMsg(sm[1], txt.substring(sm[0].length), m.criado_em);
    var el = document.createElement('div');
    el.className = 'pd-msg ' + (m.is_mine ? 'mine' : 'theirs');
    var body = document.createElement('div'); body.textContent = txt; el.appendChild(body);
    var tm = document.createElement('div'); tm.className='pd-msg-time'; tm.textContent = fmtTime(m.criado_em); el.appendChild(tm);
    return el;
  }

  function appendMsg(m){
    var day = fmtDay(m.criado_em);
    if (day && day !== lastDay){
      lastDay = day;
      var de = document.createElement('div'); de.className='pd-msg-day'; de.textContent=day; list.appendChild(de);
    }
    list.appendChild(buildMsg(m));
    if (m.id && m.id > lastId) lastId = m.id;
  }

  function loadMsgs(){
    fetch(API + '?action=messages&conversation_id=' + CONV, { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        list.innerHTML = '';
        lastDay = ''; lastId = 0;
        if (!j.ok || !j.messages || !j.messages.length){
          list.innerHTML = '<div class="pd-msg-empty"><i data-lucide="message-circle" class="w-4 h-4"></i> Sem mensagens ainda. Diga oi!</div>';
          refreshIcons();
          return;
        }
        j.messages.forEach(appendMsg);
        refreshIcons();
        scrollEnd();
      })
      .catch(function(e){ console.error('pdChat load', e); });
  }

  function poll(){
    fetch(API + '?action=poll&conversation_id=' + CONV + '&after_id=' + lastId, { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok || !j.messages || !j.messages.length) return;
        var empty = list.querySelector('.pd-msg-empty'); if (empty) empty.remove();
        j.messages.forEach(appendMsg);
        refreshIcons();
        scrollEnd();
      })
      .catch(function(){});
  }

  window.pdChatSend = function(e){
    e && e.preventDefault();
    if (sending) return false;
    var txt = inp.value.trim(); if (!txt) return false;
    sending = true; sndB.disabled = true;
    var fd = new FormData();
    fd.append('conversation_id', CONV);
    fd.append('message', txt);
    fetch(API + '?action=send', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.ok && j.msg){
          var empty = list.querySelector('.pd-msg-empty'); if (empty) empty.remove();
          appendMsg(j.msg);
          refreshIcons();
          scrollEnd();
          inp.value = '';
          inp.style.height = 'auto';
        }
      })
      .catch(function(){})
      .finally(function(){ sending=false; sndB.disabled=false; inp.focus(); });
    return false;
  };

  inp.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); window.pdChatSend(e); }
  });
  inp.addEventListener('input', function(){
    inp.style.height = 'auto';
    inp.style.height = Math.min(inp.scrollHeight, 90) + 'px';
  });

  // Initial load + polling
  loadMsgs();
  pollTmr = setInterval(poll, 3500);
  document.addEventListener('visibilitychange', function(){
    if (document.hidden){ if (pollTmr){ clearInterval(pollTmr); pollTmr=null; } }
    else if (!pollTmr){ poll(); pollTmr = setInterval(poll, 3500); }
  });

  // ── Paste-to-chat 6 boxes ──
  var pasteRow  = document.getElementById('pdPasteRow');
  var pasteMsg  = document.getElementById('pdPasteMsg');
  var pasteSend = document.getElementById('pdPasteSend');
  var pasteFill = document.getElementById('pdPasteFill');
  if (pasteRow){
    var boxes = Array.prototype.slice.call(pasteRow.querySelectorAll('.pd-paste-input'));
    var pastePreset = (pasteSend && pasteSend.getAttribute('data-code')) || (pasteFill && pasteFill.getAttribute('data-code')) || '';
    function setBoxes(code){
      var s = String(code||'').toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,6);
      boxes.forEach(function(b,i){
        b.value = s[i] || '';
        b.classList.toggle('filled', !!s[i]);
      });
      return s;
    }
    function readCode(){
      return boxes.map(function(b){ return (b.value||'').toUpperCase(); }).join('');
    }
    boxes.forEach(function(b, idx){
      b.addEventListener('input', function(){
        var v = (b.value||'').toUpperCase().replace(/[^A-Z0-9]/g,'');
        b.value = v.slice(0,1);
        b.classList.toggle('filled', !!b.value);
        if (b.value && idx < boxes.length-1) boxes[idx+1].focus();
      });
      b.addEventListener('keydown', function(e){
        if (e.key === 'Backspace' && !b.value && idx > 0){ boxes[idx-1].focus(); }
        if (e.key === 'ArrowLeft' && idx > 0) boxes[idx-1].focus();
        if (e.key === 'ArrowRight' && idx < boxes.length-1) boxes[idx+1].focus();
      });
      b.addEventListener('paste', function(e){
        e.preventDefault();
        var txt = (e.clipboardData || window.clipboardData).getData('text') || '';
        setBoxes(txt);
        var s = readCode();
        if (s.length >= 6) boxes[5].focus(); else boxes[Math.min(s.length, 5)].focus();
      });
    });
    if (pastePreset) setBoxes(pastePreset);
    if (pasteFill){
      pasteFill.addEventListener('click', function(){
        var code = pasteFill.getAttribute('data-code') || '';
        setBoxes(code);
        boxes[5].focus();
      });
    }
    if (pasteSend){
      pasteSend.addEventListener('click', function(){
        var code = readCode();
        if (code.length < 6 && pastePreset) code = setBoxes(pastePreset);
        if (code.length < 6){
          pasteMsg.className = 'pd-paste-msg err';
          pasteMsg.textContent = 'Preencha as 6 caixas com o código.';
          return;
        }
        pasteSend.disabled = true;
        var fd = new FormData();
        fd.append('conversation_id', CONV);
        fd.append('message', '🔑 Código de entrega: ' + code);
        fetch(API + '?action=send', { method:'POST', body:fd, credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (j.ok && j.msg){
              var empty = list.querySelector('.pd-msg-empty'); if (empty) empty.remove();
              appendMsg(j.msg); refreshIcons(); scrollEnd();
              pasteMsg.className = 'pd-paste-msg ok';
              pasteMsg.textContent = 'Código enviado no chat.';
            } else {
              pasteMsg.className = 'pd-paste-msg err';
              pasteMsg.textContent = (j && j.msg) ? j.msg : 'Erro ao enviar.';
            }
          })
          .catch(function(){
            pasteMsg.className = 'pd-paste-msg err';
            pasteMsg.textContent = 'Erro de rede ao enviar.';
          })
          .finally(function(){ pasteSend.disabled = false; });
      });
    }
  }
})();
</script>
<?php endif; ?>

<?php
include $ROOT . '/views/partials/user_layout_end.php';
include $ROOT . '/views/partials/footer.php';