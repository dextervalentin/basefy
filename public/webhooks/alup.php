<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/alup_api.php';

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input') ?: '{}';
$db = new Database();
$conn = $db->connect();
$cfg = alupConfig($conn);

$eventHeader = (string)($_SERVER['HTTP_X_ALUP_EVENT'] ?? '');
$deliveryHeader = (string)($_SERVER['HTTP_X_ALUP_DELIVERY'] ?? '');
$timestamp = (string)($_SERVER['HTTP_X_ALUP_TIMESTAMP'] ?? '');
$signature = (string)($_SERVER['HTTP_X_ALUP_SIGNATURE'] ?? '');

if ((string)$cfg['webhook_secret'] === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => 'Webhook AlUp sem secret configurado.']);
    exit;
}

if (!alupWebhookSignatureValid((string)$cfg['webhook_secret'], $timestamp, $signature, $rawBody)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Assinatura inválida.']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Payload inválido.']);
    exit;
}

$event = $eventHeader !== '' ? $eventHeader : (string)($payload['event'] ?? '');
$deliveryId = $deliveryHeader !== '' ? $deliveryHeader : (string)($payload['delivery_id'] ?? '');
if ($event === '' || $deliveryId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Evento ou delivery_id ausente.']);
    exit;
}

$idempotencyKey = sha1('alup|' . $deliveryId);

try {
    $st = $conn->prepare("INSERT INTO webhook_events (provider, event_name, idempotency_key, payload, status)
                          VALUES ('alup', ?, ?, ?, 'received')");
    if (!$st) throw new RuntimeException('Falha ao preparar registro do webhook.');
    $st->bind_param('sss', $event, $idempotencyKey, $rawBody);
    $st->execute();
    $st->close();
} catch (Throwable $e) {
    if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
        echo json_encode(['ok' => true, 'msg' => 'Evento já processado.']);
        exit;
    }
    error_log('[webhook/alup] erro ao registrar evento: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Erro ao registrar evento.']);
    exit;
}

try {
    alupTryApplyWebhookToFulfillment($conn, $event, $payload, $rawBody);

    $status = in_array($event, ['order.delivered', 'order.cancelled', 'order.failed'], true) ? 'processed' : 'received';
    $up = $conn->prepare("UPDATE webhook_events SET status=?, processed_at=CURRENT_TIMESTAMP WHERE idempotency_key=?");
    if ($up) {
        $up->bind_param('ss', $status, $idempotencyKey);
        $up->execute();
        $up->close();
    }

    echo json_encode(['ok' => true, 'msg' => 'Evento recebido.']);
} catch (Throwable $e) {
    error_log('[webhook/alup] erro ao processar evento: ' . $e->getMessage());
    $up = $conn->prepare("UPDATE webhook_events SET status='error', processed_at=CURRENT_TIMESTAMP WHERE idempotency_key=?");
    if ($up) {
        $up->bind_param('s', $idempotencyKey);
        $up->execute();
        $up->close();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Erro ao processar evento.']);
}
