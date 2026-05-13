<?php
/**
 * M5 Open PIX API client
 *
 * Auth: header x-secret-key
 * Base: https://api.m5contadigital.com.br
 *
 * Endpoints used:
 *  - POST /secret/pix/qrcode             (gera QR Code estático para RECEBER PIX — NÃO documentado publicamente)
 *  - POST /secret/pix/transfer           (inicia envio PIX por chave DICT)
 *  - PUT  /secret/pix/transfer/{id}/confirm
 *  - GET  /secret/balance
 *  - GET  /secret/pix/transaction/{id}
 *  - POST /secret/pix/refund/{e2e}
 *
 * Webhook payload (event = pix_in.update | pix_out.update | pix_reversal_in.update | pix_reversal_out.update):
 *  { event, pix: { id, external_id, end_to_end_id, status, provider_status, direction, amount, description, ... }, timestamp }
 *  status ∈ { confirmed, error, reversed } sempre é final.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!defined('M5_BASE_URL')) {
    define('M5_BASE_URL', (string)envValue('M5_BASE_URL', 'https://api.m5contadigital.com.br'));
}
if (!defined('M5_SECRET_KEY')) {
    define('M5_SECRET_KEY', (string)envValue('M5_SECRET_KEY', ''));
}
if (!defined('M5_WEBHOOK_SECRET')) {
    // Opcional — só usado se a M5 começar a assinar webhook via HMAC
    define('M5_WEBHOOK_SECRET', (string)envValue('M5_WEBHOOK_SECRET', ''));
}

function m5DetectOutboundIp(): string
{
    $ch = curl_init('https://api.ipify.org?format=json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        return '';
    }

    $json = json_decode((string)$body, true);
    if (is_array($json) && !empty($json['ip'])) {
        return (string)$json['ip'];
    }

    return trim((string)$body);
}

/**
 * Baixo nível: faz request HTTP autenticada. Retorna [bool ok, array body].
 *
 * @param string     $method  GET | POST | PUT | DELETE
 * @param string     $path    Path relativo (ex: '/secret/pix/qrcode')
 * @param array|null $payload Body JSON. null = sem body.
 */
function m5Request(string $method, string $path, ?array $payload = null): array
{
    if (M5_SECRET_KEY === '') {
        return [false, ['message' => 'M5_SECRET_KEY não configurada.']];
    }

    $url = rtrim(M5_BASE_URL, '/') . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-secret-key: ' . M5_SECRET_KEY,
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];

    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        return [false, ['message' => 'Falha de comunicação com M5.', 'error' => $err, 'statusCode' => $status]];
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        return [false, ['message' => 'Resposta inválida da M5.', 'raw' => $body, 'statusCode' => $status]];
    }

    if ($status < 200 || $status >= 300 || ($json['success'] ?? null) === false) {
        // Mensagem amigável
        $msg = $json['message'] ?? $json['error'] ?? 'Erro desconhecido na M5';
        $msg = (string)$msg;
        $outboundIp = '';

        if (str_contains(strtolower($msg), 'ip') || str_contains(strtolower($msg), 'autoriz')) {
            $outboundIp = (string)($json['error']['requester_ip'] ?? '');
            if ($outboundIp === '') {
                $outboundIp = m5DetectOutboundIp();
            }
            if ($outboundIp !== '') {
                $msg .= ' IP de saída do servidor: ' . $outboundIp . '. Cadastre este IP na M5 (não o IP do seu computador) ou desative a restrição de IP.';
            }
        }

        $json['statusCode'] = $status;
        $json['message'] = $msg;
        $json['outboundIp'] = $outboundIp;
        return [false, $json];
    }

    return [true, $json];
}

/**
 * Gera um QR Code estático PIX para RECEBER pagamento.
 * Endpoint não documentado: POST /secret/pix/qrcode
 *
 * Resposta esperada:
 *  data.transactionId             — id da M5 (UUID)
 *  data.status                    — 'pending'
 *  data.amount                    — em centavos
 *  data.paymentData.qrCode        — payload EMV (copia-cola)
 *  data.paymentData.copyPaste     — alias de qrCode
 *  data.paymentData.qrCodeBase64  — data:image/png;base64,...
 */
function m5CreatePixQrCode(int $amountCentavos, string $description, string $webhookUrl, ?string $externalId = null): array
{
    $body = [
        'amount'      => $amountCentavos,
        'description' => $description,
    ];
    if ($webhookUrl !== '') $body['webhook_url'] = $webhookUrl;
    if ($externalId)        $body['external_id'] = $externalId;

    return m5Request('POST', '/secret/pix/qrcode', $body);
}

/**
 * Inicia transferência PIX por chave DICT (passo 1 de 2).
 *
 * @param string $key      Chave PIX destino
 * @param int    $amount   Centavos
 * @param string $keyType  cpf|cnpj|phone|email|evp (opcional; auto-detect)
 */
function m5TransferInitiate(string $key, int $amount, string $description = '', string $webhookUrl = '', string $keyType = ''): array
{
    $body = [
        'key'    => $key,
        'amount' => $amount,
    ];
    if ($keyType !== '')    $body['key_type']    = $keyType;
    if ($description !== '') $body['description'] = $description;
    if ($webhookUrl !== '')  $body['webhook_url'] = $webhookUrl;

    return m5Request('POST', '/secret/pix/transfer', $body);
}

/**
 * Confirma transferência PIX iniciada (passo 2 de 2). Body vazio.
 */
function m5TransferConfirm(string $pixId): array
{
    return m5Request('PUT', '/secret/pix/transfer/' . rawurlencode($pixId) . '/confirm', null);
}

/**
 * Inicia + confirma em uma chamada. Atômico do lado do nosso código.
 * Retorna [ok, dataConfirm, dataInitiate].
 */
function m5TransferSend(string $key, int $amount, string $description = '', string $webhookUrl = '', string $keyType = ''): array
{
    [$ok1, $r1] = m5TransferInitiate($key, $amount, $description, $webhookUrl, $keyType);
    if (!$ok1) return [false, $r1, null];

    $pixId = (string)($r1['data']['id'] ?? '');
    if ($pixId === '') return [false, ['message' => 'M5: id ausente na resposta de initiate', 'raw' => $r1], $r1];

    [$ok2, $r2] = m5TransferConfirm($pixId);
    if (!$ok2) return [false, $r2 + ['initiate_id' => $pixId], $r1];

    return [true, $r2, $r1];
}

function m5GetBalance(): array
{
    return m5Request('GET', '/secret/balance');
}

function m5GetTransaction(string $idOrExternalId): array
{
    return m5Request('GET', '/secret/pix/transaction/' . rawurlencode($idOrExternalId));
}

function m5Refund(string $endToEndId, int $refundAmountCentavos): array
{
    return m5Request('POST', '/secret/pix/refund/' . rawurlencode($endToEndId), [
        'refund_amount' => $refundAmountCentavos,
    ]);
}

/**
 * Webhook helper: opcional HMAC. Se M5_WEBHOOK_SECRET vazio, sempre retorna true.
 */
function m5WebhookVerifySignature(string $payloadRaw, string $signatureHeader): bool
{
    if (M5_WEBHOOK_SECRET === '') return true;
    if ($signatureHeader === '') return false;
    if (stripos($signatureHeader, 'sha256=') === 0) $signatureHeader = substr($signatureHeader, 7);
    $expected = hash_hmac('sha256', $payloadRaw, M5_WEBHOOK_SECRET);
    return hash_equals($expected, strtolower(trim($signatureHeader)));
}
