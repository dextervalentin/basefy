<?php
/**
 * Webhook M5 Open PIX.
 *
 * Events:
 *  - pix_in.update            (PIX recebido)            → marca order como 'pago'
 *  - pix_out.update           (PIX enviado/saque)       → marca withdrawal como 'pago' ou 'recusado'
 *  - pix_reversal_in.update   (estorno recebido)        → log + reverte order
 *  - pix_reversal_out.update  (estorno enviado)         → log
 *
 * Payload:
 *  { event, pix: { id, external_id, end_to_end_id, status, provider_status, direction, amount, description, ... }, timestamp }
 *  - pix.status ∈ { confirmed, error, reversed } sempre é final.
 *  - pix.amount em centavos.
 *
 * Idempotência: sha1(event|pix.id|pix.status) — webhook M5 retentaria com mesmo payload.
 *
 * Endpoint público URL: https://basefy.io/webhooks/m5
 */
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/m5_api.php';
require_once __DIR__ . '/../../src/wallet_escrow.php';
require_once __DIR__ . '/../../src/wallet_portal.php';

header('Content-Type: application/json; charset=utf-8');

$payloadRaw = file_get_contents('php://input') ?: '{}';

// HMAC opcional — só verifica se M5_WEBHOOK_SECRET estiver configurada
$sigHeader = (string)(
    $_SERVER['HTTP_X_SIGNATURE']
    ?? $_SERVER['HTTP_X_M5_SIGNATURE']
    ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
    ?? ''
);
if (!m5WebhookVerifySignature($payloadRaw, $sigHeader)) {
    error_log('[webhook/m5] HMAC inválida — payload rejeitado');
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Assinatura inválida']);
    exit;
}

$data = json_decode($payloadRaw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Payload inválido']);
    exit;
}

$event = (string)($data['event'] ?? '');
$pix   = (array)($data['pix'] ?? []);

if ($event === '' || !$pix) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Evento ausente']);
    exit;
}

$pixId         = (string)($pix['id'] ?? '');
$externalId    = (string)($pix['external_id'] ?? '');
$endToEndId    = (string)($pix['end_to_end_id'] ?? '');
$pixStatus     = strtolower((string)($pix['status'] ?? ''));
$providerStat  = strtolower((string)($pix['provider_status'] ?? ''));
$direction     = strtolower((string)($pix['direction'] ?? ''));
$amount        = (int)($pix['amount'] ?? 0);
$description   = (string)($pix['description'] ?? '');

$db   = new Database();
$conn = $db->connect();

$idempotencyKey = sha1($event . '|' . $pixId . '|' . $pixStatus);

try {
    $ins = $conn->prepare("INSERT INTO webhook_events (provider, event_name, idempotency_key, payload, status)
                           VALUES ('m5', ?, ?, ?, 'received')");
    $ins->bind_param('sss', $event, $idempotencyKey, $payloadRaw);
    $ins->execute();
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'duplicate key')) {
        // Retentativa — já processamos.
        http_response_code(200);
        echo json_encode(['ok' => true, 'msg' => 'Evento já processado']);
        exit;
    }
    error_log('[webhook/m5] insert webhook_events erro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Erro ao registrar evento']);
    exit;
}

try {
    if (in_array($event, ['pix_in.update', 'pix_reversal_in.update'], true)) {
        // ── PIX recebido (cliente paga checkout) ──
        $stTx = $conn->prepare("SELECT id, order_id, user_id, external_ref, status, amount_centavos FROM payment_transactions WHERE provider='m5' AND (provider_transaction_id = ? OR provider_transaction_id = ?) ORDER BY id DESC LIMIT 1");
        $stTx->bind_param('ss', $pixId, $externalId);
        $stTx->execute();
        $tx = $stTx->get_result()->fetch_assoc();
        $stTx->close();

        // Fallback: tentar achar por description "Pedido #N" ou "[wallet_topup:N:...]"
        if (!$tx && $description !== '' && preg_match('/Pedido\s*#(\d+)/i', $description, $m)) {
            $orderRef = (int)$m[1];
            $stTx2 = $conn->prepare("SELECT id, order_id, user_id, external_ref, status, amount_centavos FROM payment_transactions WHERE provider='m5' AND order_id = ? ORDER BY id DESC LIMIT 1");
            $stTx2->bind_param('i', $orderRef);
            $stTx2->execute();
            $tx = $stTx2->get_result()->fetch_assoc();
            $stTx2->close();
        }
        if (!$tx && $description !== '' && preg_match('/\[(wallet_topup:[^\]]+)\]/', $description, $m)) {
            $extRef = $m[1];
            $stTx3 = $conn->prepare("SELECT id, order_id, user_id, external_ref, status, amount_centavos FROM payment_transactions WHERE provider='m5' AND external_ref = ? ORDER BY id DESC LIMIT 1");
            $stTx3->bind_param('s', $extRef);
            $stTx3->execute();
            $tx = $stTx3->get_result()->fetch_assoc();
            $stTx3->close();
        }

        if (!$tx) {
            error_log('[webhook/m5] pix_in não encontrou payment_transaction: pix_id=' . $pixId . ' ext=' . $externalId . ' desc=' . $description);
            echo json_encode(['ok' => true, 'msg' => 'PIX recebido sem transação correspondente — ignorado']);
            $up = $conn->prepare("UPDATE webhook_events SET status='ignored', processed_at=NOW() WHERE idempotency_key = ?");
            $up->bind_param('s', $idempotencyKey);
            $up->execute();
            exit;
        }

        $orderId = (int)$tx['order_id'];
        $status  = ($pixStatus === 'confirmed') ? 'PAID' : strtoupper($pixStatus);
        $raw     = $payloadRaw;
        $txExtRef = (string)($tx['external_ref'] ?? '');
        $isWalletTopup = str_starts_with($txExtRef, 'wallet_topup:');

        $conn->begin_transaction();
        try {
            // Update payment_transaction
            $up = $conn->prepare("UPDATE payment_transactions
                                 SET status = ?, paid_at = IF(?='PAID', NOW(), paid_at), raw_response = ?, updated_at = CURRENT_TIMESTAMP
                                 WHERE id = ?");
            $txId = (int)$tx['id'];
            $up->bind_param('sssi', $status, $status, $raw, $txId);
            $up->execute();

            if ($event === 'pix_in.update' && $pixStatus === 'confirmed' && $isWalletTopup) {
                // Recarga de wallet — credita saldo do usuário
                $conn->commit();
                [$okC, $msgC] = walletAplicarCreditoRecargaSeNecessario($conn, $txId);
                $upE = $conn->prepare("UPDATE webhook_events SET status=?, processed_at=NOW() WHERE idempotency_key = ?");
                $finalSt = $okC ? 'processed' : 'error';
                $upE->bind_param('ss', $finalSt, $idempotencyKey);
                $upE->execute();
                echo json_encode(['ok' => $okC, 'msg' => $msgC]);
                exit;
            }

            if ($event === 'pix_in.update' && $pixStatus === 'confirmed' && $orderId > 0) {
                // Marca order como pago apenas se ainda não foi
                $upOrder = $conn->prepare("UPDATE orders SET status='pago' WHERE id = ? AND status NOT IN ('pago','enviado','entregue')");
                $upOrder->bind_param('i', $orderId);
                $upOrder->execute();
                $changed = $upOrder->affected_rows > 0;

                if ($changed) {
                    // Replicar lógica do BlackCat webhook: buyer_fee, wallet_used, escrow
                    $stFee = $conn->prepare("SELECT buyer_fee, wallet_used, user_id FROM orders WHERE id = ? LIMIT 1");
                    $stFee->bind_param('i', $orderId);
                    $stFee->execute();
                    $feeRow = $stFee->get_result()->fetch_assoc() ?: [];
                    $stFee->close();

                    $buyerFee    = (float)($feeRow['buyer_fee']   ?? 0);
                    $walletUsed  = (float)($feeRow['wallet_used'] ?? 0);
                    $buyerUserId = (int)  ($feeRow['user_id']     ?? 0);

                    if ($buyerFee > 0) {
                        $adminReceiver = escrowResolveAdminReceiver($conn);
                        if ($adminReceiver > 0) {
                            $uAdmin = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?');
                            $uAdmin->bind_param('di', $buyerFee, $adminReceiver);
                            $uAdmin->execute();

                            $descAdm = 'Taxa de serviço (comprador) do pedido #' . $orderId;
                            $txAdm = $conn->prepare("INSERT INTO wallet_transactions (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao) VALUES (?, 'credito', 'buyer_service_fee', 'order', ?, ?, ?)");
                            if ($txAdm) {
                                $txAdm->bind_param('iids', $adminReceiver, $orderId, $buyerFee, $descAdm);
                                $txAdm->execute();
                            }
                        } else {
                            error_log('[webhook/m5] buyer_fee não creditada — sem admin receiver (pedido #' . $orderId . ')');
                        }
                    }

                    if ($walletUsed > 0 && $buyerUserId > 0) {
                        $deb = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo - ? WHERE id = ? AND wallet_saldo >= ?');
                        $deb->bind_param('did', $walletUsed, $buyerUserId, $walletUsed);
                        $deb->execute();
                        if ($deb->affected_rows === 0) {
                            error_log('[webhook/m5] wallet debit bloqueado — order #' . $orderId . ' user #' . $buyerUserId . ' valor=' . $walletUsed);
                        } else {
                            $descWd = 'Uso de saldo wallet no checkout do pedido #' . $orderId;
                            $txWd = $conn->prepare("INSERT INTO wallet_transactions (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao) VALUES (?, 'debito', 'checkout_wallet', 'order', ?, ?, ?)");
                            if ($txWd) {
                                $txWd->bind_param('iids', $buyerUserId, $orderId, $walletUsed, $descWd);
                                $txWd->execute();
                            }
                        }
                    }

                    escrowInitializeOrderItems($conn, $orderId);
                }
            }

            $conn->commit();
        } catch (\Throwable $txErr) {
            $conn->rollback();
            throw $txErr;
        }

    } elseif (in_array($event, ['pix_out.update', 'pix_reversal_out.update'], true)) {
        // ── PIX enviado (saque) ──
        // Localiza wallet_withdrawal pelo transaction_id (que pode ser o M5 pix.id armazenado) OU pela observação
        $stW = $conn->prepare("SELECT id, user_id, valor, status, observacao FROM wallet_withdrawals WHERE transaction_id = ? OR observacao LIKE CONCAT('%m5_pix_id=', ?, '%') ORDER BY id DESC LIMIT 1");
        $stW->bind_param('ss', $pixId, $pixId);
        $stW->execute();
        $wd = $stW->get_result()->fetch_assoc();
        $stW->close();

        if (!$wd) {
            error_log('[webhook/m5] pix_out não encontrou wallet_withdrawal: pix_id=' . $pixId);
            $up = $conn->prepare("UPDATE webhook_events SET status='ignored', processed_at=NOW() WHERE idempotency_key = ?");
            $up->bind_param('s', $idempotencyKey);
            $up->execute();
            echo json_encode(['ok' => true, 'msg' => 'Saque não encontrado — ignorado']);
            exit;
        }

        $wdId    = (int)$wd['id'];
        $userId  = (int)$wd['user_id'];
        $valor   = (float)$wd['valor'];
        $current = strtolower((string)$wd['status']);

        $conn->begin_transaction();
        try {
            if ($pixStatus === 'confirmed') {
                if ($current !== 'pago') {
                    $upW = $conn->prepare("UPDATE wallet_withdrawals SET status='pago', observacao=CONCAT(IFNULL(observacao,''), ' | m5_confirmed=', ?, ' e2e=', ?) WHERE id = ?");
                    $upW->bind_param('ssi', $pixId, $endToEndId, $wdId);
                    $upW->execute();

                    // Notificar usuário
                    try {
                        require_once __DIR__ . '/../../src/notifications.php';
                        notificationsCreate($conn, $userId, 'venda', 'Saque pago!', 'Seu saque de R$ ' . number_format($valor, 2, ',', '.') . ' foi confirmado pelo banco.', '/wallet');
                    } catch (\Throwable $e) { error_log('[webhook/m5] notif error: ' . $e->getMessage()); }
                }
            } elseif ($pixStatus === 'error') {
                // Reverte saldo do usuário (o débito ocorreu no walletSaqueImediatoAdmin)
                if ($current !== 'recusado') {
                    $upW = $conn->prepare("UPDATE wallet_withdrawals SET status='recusado', observacao=CONCAT(IFNULL(observacao,''), ' | m5_error provider_status=', ?) WHERE id = ?");
                    $upW->bind_param('si', $providerStat, $wdId);
                    $upW->execute();

                    if ($valor > 0 && $userId > 0) {
                        $ref = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?');
                        $ref->bind_param('di', $valor, $userId);
                        $ref->execute();

                        $txR = $conn->prepare("INSERT INTO wallet_transactions (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao) VALUES (?, 'credito', 'withdrawal_refund', 'wallet_withdrawal', ?, ?, ?)");
                        $descR = 'Reembolso saque recusado #' . $wdId . ' (M5: ' . $providerStat . ')';
                        $txR->bind_param('iids', $userId, $wdId, $valor, $descR);
                        $txR->execute();
                    }

                    try {
                        require_once __DIR__ . '/../../src/notifications.php';
                        notificationsCreate($conn, $userId, 'venda', 'Saque recusado', 'Seu saque de R$ ' . number_format($valor, 2, ',', '.') . ' foi recusado e o valor retornou para a wallet.', '/wallet');
                    } catch (\Throwable $e) { error_log('[webhook/m5] notif error: ' . $e->getMessage()); }
                }
            } elseif ($pixStatus === 'reversed' || $event === 'pix_reversal_out.update') {
                $upW = $conn->prepare("UPDATE wallet_withdrawals SET status='estornado', observacao=CONCAT(IFNULL(observacao,''), ' | m5_reversed e2e=', ?) WHERE id = ?");
                $upW->bind_param('si', $endToEndId, $wdId);
                $upW->execute();
            }
            $conn->commit();
        } catch (\Throwable $txErr) {
            $conn->rollback();
            throw $txErr;
        }
    }

    $up = $conn->prepare("UPDATE webhook_events SET status='processed', processed_at=NOW() WHERE idempotency_key = ?");
    $up->bind_param('s', $idempotencyKey);
    $up->execute();

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    error_log('[webhook/m5] erro fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Erro ao processar webhook']);
}
