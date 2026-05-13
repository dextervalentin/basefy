<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Debug log helper — writes to /storage/logs/checkout_debug.log
function _checkoutLog(string $step, array $data = []): void
{
    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    $line = date('Y-m-d H:i:s') . " [{$step}] " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($logDir . '/checkout_debug.log', $line, FILE_APPEND | LOCK_EX);
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    require_once __DIR__ . '/../../src/db.php';
    require_once __DIR__ . '/../../src/auth.php';
    require_once __DIR__ . '/../../src/config.php';
    require_once __DIR__ . '/../../src/storefront.php';
    require_once __DIR__ . '/../../src/m5_api.php';

    _checkoutLog('START', ['method' => $_SERVER['REQUEST_METHOD'], 'session_user' => $_SESSION['user_id'] ?? null]);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => 'POST only', 'error' => 'POST only']);
        exit;
    }

    if (!usuarioLogado()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'msg' => 'Não autenticado', 'error' => 'Não autenticado']);
        exit;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'msg' => 'User ID inválido', 'error' => 'User ID inválido']);
        exit;
    }

    $conn = (new Database())->connect();
    $useWallet = ((string)($_POST['use_wallet'] ?? '0')) === '1';

    _checkoutLog('CREATE_ORDER', ['userId' => $userId, 'useWallet' => $useWallet]);

    [$ok, $msg, $result] = sfCreateOrderFromCart($conn, $userId, $useWallet);

    _checkoutLog('ORDER_RESULT', ['ok' => $ok, 'msg' => $msg, 'result' => $result]);

    if (!$ok) {
        echo json_encode(['ok' => false, 'msg' => $msg, 'error' => $msg]);
        exit;
    }

    $orderId  = (int)($result['order_id'] ?? 0);
    $pixTotal = (float)($result['pix_total'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Pedido criado sem ID.', 'error' => 'Pedido criado sem ID.']);
        exit;
    }

    // Fully paid with wallet — no PIX needed
    if ($pixTotal <= 0) {
        _checkoutLog('WALLET_FULL', ['orderId' => $orderId]);

        // Get the chat conversation ID (created by escrowInitializeOrderItems → chatAutoOpenAfterPurchase)
        $chatConvId = 0;
        try {
            require_once __DIR__ . '/../../src/chat.php';
            $stConvItem = $conn->prepare("SELECT oi.vendedor_id, oi.product_id FROM order_items oi WHERE oi.order_id = ? AND oi.vendedor_id IS NOT NULL AND oi.vendedor_id > 0 ORDER BY oi.id ASC LIMIT 1");
            if ($stConvItem) {
                $stConvItem->bind_param('i', $orderId);
                $stConvItem->execute();
                $convItemRow = $stConvItem->get_result()->fetch_assoc();
                $stConvItem->close();
                if ($convItemRow) {
                    $convVendor = (int)$convItemRow['vendedor_id'];
                    $convProduct = (int)($convItemRow['product_id'] ?? 0);
                    $convCheck = chatGetOrCreateConversation($conn, $userId, $convVendor, $convProduct > 0 ? $convProduct : null);
                    if ($convCheck) $chatConvId = (int)$convCheck['id'];
                }
            }
        } catch (\Throwable $e) {}

        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        $redirectUrl = $basePath . '/pedido_detalhes?id=' . $orderId
            . ($chatConvId > 0 ? '&open_chat=' . $chatConvId : '');

        echo json_encode([
            'ok' => true,
            'pix' => false,
            'orderId' => $orderId,
            'chatConvId' => $chatConvId,
            'redirect' => $redirectUrl,
            'message' => 'Pedido pago com saldo da carteira!',
        ]);
        exit;
    }

    // ----- Generate PIX -----
    _checkoutLog('PIX_START', ['orderId' => $orderId, 'pixTotal' => $pixTotal]);

    $stU = $conn->prepare('SELECT nome, email FROM users WHERE id=? LIMIT 1');
    $stU->bind_param('i', $userId);
    $stU->execute();
    $buyer = $stU->get_result()->fetch_assoc() ?: ['nome' => 'Cliente', 'email' => 'c@l.com'];
    $stU->close();

    $amountCentavos = (int)round($pixTotal * 100);
    $externalRef = 'order:' . $orderId;

    $host       = $_SERVER['HTTP_HOST'] ?? 'basefy.io';
    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $webhookUrl = $scheme . '://' . $host . '/webhooks/m5';
    $description = 'Pedido #' . $orderId . ' - Basefy';

    [$okApi, $resp] = m5CreatePixQrCode($amountCentavos, $description, $webhookUrl, null);

    _checkoutLog('M5_RESP', [
        'okApi' => $okApi,
        'respKeys' => array_keys($resp ?? []),
        'dataKeys' => array_keys($resp['data'] ?? []),
        'paymentDataKeys' => array_keys($resp['data']['paymentData'] ?? []),
    ]);

    if (!$okApi) {
        _checkoutLog('M5_FAIL', ['resp' => $resp]);
        $errMsg = 'Erro M5: ' . (string)($resp['message'] ?? 'Desconhecido');
        echo json_encode([
            'ok' => false,
            'msg' => $errMsg,
            'error' => $errMsg,
            'orderId' => $orderId,
            'debug' => ['respKeys' => array_keys($resp ?? [])],
        ]);
        exit;
    }

    $data   = $resp['data'] ?? [];
    $provId = (string)($data['transactionId'] ?? '');
    $st     = 'PENDING';
    $rawJ   = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Store in DB (non-fatal if fails)
    try {
        $ins = $conn->prepare("INSERT INTO payment_transactions
            (provider, order_id, user_id, external_ref, provider_transaction_id, status, payment_method, amount_centavos, raw_response)
            VALUES ('m5', ?, ?, ?, ?, ?, 'pix', ?, ?)
            ON DUPLICATE KEY UPDATE status=VALUES(status), raw_response=VALUES(raw_response), updated_at=CURRENT_TIMESTAMP");
        $ins->bind_param('iisssis', $orderId, $userId, $externalRef, $provId, $st, $amountCentavos, $rawJ);
        $ins->execute();
    } catch (Throwable $dbErr) {
        _checkoutLog('DB_INSERT_ERR', ['msg' => $dbErr->getMessage()]);
    }

    // Extract QR data — M5 returns paymentData = { qrCode, qrCodeBase64, copyPaste }
    $pd  = (array)($data['paymentData'] ?? []);
    $b64 = (string)($pd['qrCodeBase64'] ?? '');
    $qrUrl = (string)($pd['qrCodeUrl'] ?? '');
    $copyPaste = (string)($pd['copyPaste'] ?? $pd['qrCode'] ?? $pd['pixCode'] ?? '');

    $qrImage = '';
    if ($b64 !== '') {
        $qrImage = str_starts_with($b64, 'data:') ? $b64 : ('data:image/png;base64,' . $b64);
    } elseif ($qrUrl !== '') {
        $qrImage = $qrUrl;
    }

    _checkoutLog('PIX_EXTRACTED', [
        'b64len' => strlen($b64),
        'qrUrl' => $qrUrl,
        'copyPasteLen' => strlen($copyPaste),
        'qrImageLen' => strlen($qrImage),
        'pdKeys' => array_keys($pd),
    ]);

    if ($qrImage === '') {
        echo json_encode([
            'ok' => false,
            'msg' => 'PIX gerado mas sem QR Code.',
            'error' => 'PIX gerado mas sem QR Code.',
            'orderId' => $orderId,
            'debug' => [
                'pdKeys' => array_keys($pd),
                'dataKeys' => array_keys($data),
                'b64empty' => $b64 === '',
                'qrUrlEmpty' => $qrUrl === '',
            ],
        ]);
        exit;
    }

    _checkoutLog('SUCCESS', ['orderId' => $orderId, 'qrImageLen' => strlen($qrImage)]);

    echo json_encode([
        'ok' => true,
        'pix' => true,
        'orderId' => $orderId,
        'valor' => $pixTotal,
        'qrImage' => $qrImage,
        'copyPaste' => $copyPaste,
    ]);

} catch (Throwable $e) {
    _checkoutLog('FATAL', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    $errFatal = 'Erro interno: ' . $e->getMessage();
    echo json_encode([
        'ok' => false,
        'msg' => $errFatal,
        'error' => $errFatal,
        'debug' => ['file' => basename($e->getFile()), 'line' => $e->getLine()],
    ]);
}
