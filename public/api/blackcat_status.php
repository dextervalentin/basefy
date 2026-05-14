<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/blackcat_api.php';
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

$conn = (new Database())->connect();
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

$stTx = $conn->prepare("SELECT id, provider_transaction_id, status FROM payment_transactions WHERE provider='blackcat' AND order_id=? ORDER BY id DESC LIMIT 1");
$stTx->bind_param('i', $orderId);
$stTx->execute();
$tx = $stTx->get_result()->fetch_assoc();
if (!$tx || trim((string)$tx['provider_transaction_id']) === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Transação não encontrada']);
    exit;
}

$providerTransactionId = (string)$tx['provider_transaction_id'];
[$ok, $resp] = blackcatGetSaleStatus($providerTransactionId);
if (!$ok) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'msg' => (string)($resp['message'] ?? 'Falha ao consultar status'), 'error' => $resp]);
    exit;
}

$data = $resp['data'] ?? [];
$status = strtoupper((string)($data['status'] ?? 'PENDING'));
$net = isset($data['netAmount']) ? (int)$data['netAmount'] : null;
$fees = isset($data['fees']) ? (int)$data['fees'] : null;
$raw = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$upTx = $conn->prepare("UPDATE payment_transactions
    SET status = ?, net_centavos = ?, fees_centavos = ?, paid_at = IF(?='PAID', NOW(), paid_at), raw_response = ?, updated_at = CURRENT_TIMESTAMP
    WHERE id = ?");
$upTx->bind_param('siissi', $status, $net, $fees, $status, $raw, $tx['id']);
$upTx->execute();

if ($status === 'PAID') {
    // Master transaction: garante atomicidade de update do pedido + escrow init
    // (que dispara decremento de estoque com SELECT FOR UPDATE — precisa de tx).
    $conn->begin_transaction();
    try {
        $upOrder = $conn->prepare("UPDATE orders SET status='pago' WHERE id = ?");
        $upOrder->bind_param('i', $orderId);
        $upOrder->execute();
        escrowInitializeOrderItems($conn, (int)$orderId);
        $conn->commit();
    } catch (\Throwable $txErr) {
        $conn->rollback();
        error_log('[blackcat_status] PAID tx failed for order #' . $orderId . ': ' . $txErr->getMessage());
        throw $txErr;
    }
}

$currentOrderStatus = $status === 'PAID' ? 'pago' : (string)$order['status'];

echo json_encode([
    'ok' => true,
    'transactionId' => $providerTransactionId,
    'paymentStatus' => $status,
    'orderStatus' => $currentOrderStatus,
]);
