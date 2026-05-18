<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/alup_api.php';

exigirAdmin();

$conn = (new Database())->connect();
alupEnsureTables($conn);

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$basePath = defined('BASE_PATH') ? BASE_PATH : '';

function _alupRedirect(string $url, string $msgKey, string $value): void
{
    $sep = (str_contains($url, '?')) ? '&' : '?';
    header('Location: ' . $url . $sep . $msgKey . '=' . rawurlencode($value));
    exit;
}

function _alupJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$referer = (string)($_SERVER['HTTP_REFERER'] ?? ($basePath . '/admin/alup_catalog'));

try {
    switch ($action) {
        case 'product_details': {
            $externalId = trim((string)($_GET['external_id'] ?? $_POST['external_id'] ?? ''));
            if ($externalId === '') {
                _alupJsonResponse(['ok' => false, 'msg' => 'Produto AlUp não informado.'], 422);
            }
            [$okApi, $body, $statusCode] = alupGetMarketplaceProduct($conn, $externalId);
            if (!$okApi) {
                _alupJsonResponse([
                    'ok' => false,
                    'msg' => (string)($body['error']['message'] ?? 'Não foi possível carregar detalhes AlUp.'),
                    'status' => $statusCode,
                ], $statusCode >= 400 ? $statusCode : 502);
            }
            _alupJsonResponse(['ok' => true, 'product' => alupExtractItem(is_array($body) ? $body : [])]);
            break;
        }
        case 'save_mapping': {
            $productId = (int)($_POST['product_id'] ?? 0);
            $externalId = trim((string)($_POST['external_id'] ?? ''));
            $kind = trim((string)($_POST['kind'] ?? 'marketplace')) ?: 'marketplace';
            $payload = [];
            $rawPayload = (string)($_POST['payload_json'] ?? '');
            if ($rawPayload !== '') {
                $decoded = json_decode($rawPayload, true);
                if (is_array($decoded)) $payload = $decoded;
            }
            [$ok, $msg] = alupSaveMapping($conn, $productId, $kind, $externalId, $payload);
            _alupRedirect($referer, $ok ? 'msg' : 'err', $msg);
            break;
        }
        case 'delete_mapping': {
            $mappingId = (int)($_POST['mapping_id'] ?? 0);
            [$ok, $msg] = alupDeleteMapping($conn, $mappingId);
            _alupRedirect($referer, $ok ? 'msg' : 'err', $msg);
            break;
        }
        case 'retry_fulfillment': {
            $fulfillmentId = (int)($_POST['fulfillment_id'] ?? 0);
            [$ok, $msg] = alupRetryFulfillment($conn, $fulfillmentId);
            _alupRedirect($referer, $ok ? 'msg' : 'err', $msg);
            break;
        }
        case 'manual_fulfill_order': {
            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($orderId <= 0) _alupRedirect($referer, 'err', 'order_id inválido');
            $result = alupFulfillOrder($conn, $orderId);
            $msg = 'Fulfillment disparado: processed=' . $result['processed']
                . ' skipped=' . $result['skipped'] . ' errors=' . $result['errors'];
            _alupRedirect($referer, $result['errors'] === 0 ? 'msg' : 'err', $msg);
            break;
        }
        default:
            _alupRedirect($referer, 'err', 'Ação inválida.');
    }
} catch (Throwable $e) {
    error_log('[admin_alup_action] ' . $e->getMessage());
    _alupRedirect($referer, 'err', 'Erro interno: ' . $e->getMessage());
}
