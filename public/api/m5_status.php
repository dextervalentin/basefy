<?php
/**
 * GET /api/m5_status?order_id=N
 *
 * Polling de status PIX via M5 (/secret/pix/transaction/{id}).
 * Atualiza payment_transactions e marca order como 'pago' quando confirmed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/m5_api.php';
require_once __DIR__ . '/../../src/wallet_escrow.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método não permitido']);
    exit;
}

if (!usuarioLogado()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Não autenticado']);
    exit;
}

$conn    = (new Database())->connect();
$buyerId = (int)($_SESSION['user_id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0 || $buyerId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'msg' => 'Parâmetros inválidos']);
    exit;
}

$st = $conn->prepare('SELECT id, user_id, status FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
$st->bind_param('ii', $orderId, $buyerId);
$st->execute();
$order = $st->get_result()->fetch_assoc();
if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Pedido não encontrado']);
    exit;
}

// Se webhook já marcou como pago, retorna imediato (atalho rápido)
$currentStatusOrder = strtolower((string)$order['status']);
if (in_array($currentStatusOrder, ['pago', 'paid', 'enviado', 'entregue'], true)) {
    echo json_encode([
        'ok'            => true,
        'paymentStatus' => 'PAID',
        'orderStatus'   => $currentStatusOrder,
    ]);
    exit;
}

$stTx = $conn->prepare("SELECT id, provider_transaction_id, status FROM payment_transactions WHERE provider='m5' AND order_id=? ORDER BY id DESC LIMIT 1");
$stTx->bind_param('i', $orderId);
$stTx->execute();
$tx = $stTx->get_result()->fetch_assoc();
if (!$tx || trim((string)$tx['provider_transaction_id']) === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Transação M5 não encontrada']);
    exit;
}

$providerTransactionId = (string)$tx['provider_transaction_id'];
[$ok, $resp] = m5GetTransaction($providerTransactionId);
if (!$ok) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'msg' => (string)($resp['message'] ?? 'Falha ao consultar status'), 'error' => $resp]);
    exit;
}

$data        = $resp['data'] ?? [];
$m5Status    = strtolower((string)($data['status'] ?? 'pending'));
$isFinal     = (bool)($data['status_final'] ?? false);
$isConfirmed = ($m5Status === 'confirmed');
$status      = $isConfirmed ? 'PAID' : strtoupper($m5Status);
$raw         = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$upTx = $conn->prepare("UPDATE payment_transactions
    SET status = ?, paid_at = IF(?='PAID', NOW(), paid_at), raw_response = ?, updated_at = CURRENT_TIMESTAMP
    WHERE id = ?");
$upTx->bind_param('sssi', $status, $status, $raw, $tx['id']);
$upTx->execute();

if ($isConfirmed) {
    // Idempotente: só marca pago se ainda não estava
    $upOrder = $conn->prepare("UPDATE orders SET status='pago' WHERE id = ? AND status NOT IN ('pago','enviado','entregue')");
    $upOrder->bind_param('i', $orderId);
    $upOrder->execute();
    if ($upOrder->affected_rows > 0) {
        escrowInitializeOrderItems($conn, (int)$orderId);
    }
}

$currentOrderStatus = $isConfirmed ? 'pago' : (string)$order['status'];

echo json_encode([
    'ok'            => true,
    'transactionId' => $providerTransactionId,
    'paymentStatus' => $status,
    'orderStatus'   => $currentOrderStatus,
    'isFinal'       => $isFinal,
]);
