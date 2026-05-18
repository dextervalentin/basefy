<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function alupDefaultBaseUrl(): string
{
    return 'https://xydfessmlaghhgdkfhcz.supabase.co/functions/v1/public-api';
}

function alupNormalizeBaseUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') return alupDefaultBaseUrl();
    return rtrim($url, '/');
}

function alupSettingsDefaults(): array
{
    return [
        'alup.enabled' => '0',
        'alup.base_url' => alupDefaultBaseUrl(),
        'alup.api_key' => '',
        'alup.webhook_secret' => '',
        'alup.catalog_cache_seconds' => '120',
    ];
}

function alupSettingGet(object $conn, string $key, string $default = ''): string
{
    $st = $conn->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1');
    if (!$st) return $default;
    $st->bind_param('s', $key);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    return (string)($row['setting_value'] ?? $default);
}

function alupSettingSet(object $conn, string $key, string $value): void
{
    $st = $conn->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
    if (!$st) return;
    $st->bind_param('ss', $key, $value);
    $st->execute();
    $st->close();
}

function alupEnsureDefaults(object $conn): void
{
    foreach (alupSettingsDefaults() as $key => $default) {
        if (alupSettingGet($conn, $key, '__missing__') === '__missing__') {
            alupSettingSet($conn, $key, $default);
        }
    }
}

function alupConfig(object $conn): array
{
    alupEnsureDefaults($conn);

    $envBase = (string)envValue('ALUP_BASE_URL', '');
    $envKey = (string)envValue('ALUP_API_KEY', '');
    $envWebhookSecret = (string)envValue('ALUP_WEBHOOK_SECRET', '');

    return [
        'enabled' => alupSettingGet($conn, 'alup.enabled', '0') === '1',
        'base_url' => alupNormalizeBaseUrl($envBase !== '' ? $envBase : alupSettingGet($conn, 'alup.base_url', alupDefaultBaseUrl())),
        'api_key' => $envKey !== '' ? $envKey : alupSettingGet($conn, 'alup.api_key', ''),
        'webhook_secret' => $envWebhookSecret !== '' ? $envWebhookSecret : alupSettingGet($conn, 'alup.webhook_secret', ''),
        'catalog_cache_seconds' => max(30, min(900, (int)alupSettingGet($conn, 'alup.catalog_cache_seconds', '120'))),
        'api_key_from_env' => $envKey !== '',
        'base_url_from_env' => $envBase !== '',
        'webhook_secret_from_env' => $envWebhookSecret !== '',
    ];
}

function alupEnsureTables(object $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = method_exists($conn, 'getPdo') ? $conn->getPdo() : null;
    if (!$pdo instanceof PDO) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS external_product_mappings (
        id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        provider VARCHAR(30) NOT NULL DEFAULT 'alup',
        kind VARCHAR(30) NOT NULL,
        external_id VARCHAR(191) NOT NULL,
        external_payload TEXT,
        supplier_cost_cents BIGINT NOT NULL DEFAULT 0,
        last_synced_at TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(product_id, provider),
        UNIQUE(provider, kind, external_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_external_product_mappings_kind ON external_product_mappings(provider, kind)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS external_fulfillments (
        id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
        order_item_id BIGINT NOT NULL UNIQUE REFERENCES order_items(id) ON DELETE CASCADE,
        provider VARCHAR(30) NOT NULL DEFAULT 'alup',
        kind VARCHAR(30) NOT NULL,
        external_product_id VARCHAR(191),
        external_service_id VARCHAR(191),
        external_order_id VARCHAR(191),
        external_order_no VARCHAR(64),
        idempotency_key VARCHAR(128) NOT NULL UNIQUE,
        status VARCHAR(30) NOT NULL DEFAULT 'queued',
        payment_status VARCHAR(30),
        provider_status VARCHAR(60),
        request_payload TEXT,
        response_payload TEXT,
        delivery_content TEXT,
        download_url TEXT,
        sms_phone VARCHAR(40),
        sms_code VARCHAR(80),
        supplier_total_cents BIGINT NOT NULL DEFAULT 0,
        error_code VARCHAR(80),
        error_message TEXT,
        attempts INT NOT NULL DEFAULT 0,
        next_retry_at TIMESTAMP,
        last_polled_at TIMESTAMP,
        delivered_at TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_external_fulfillments_status ON external_fulfillments(provider, status, next_retry_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_external_fulfillments_external ON external_fulfillments(provider, external_order_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_external_fulfillments_kind ON external_fulfillments(provider, kind)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS external_catalog_cache (
        cache_key VARCHAR(191) PRIMARY KEY,
        provider VARCHAR(30) NOT NULL DEFAULT 'alup',
        payload TEXT NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_external_catalog_cache_provider ON external_catalog_cache(provider, expires_at)");
}

function alupMaskSecret(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $len = strlen($value);
    if ($len <= 10) return str_repeat('*', $len);
    return substr($value, 0, 8) . str_repeat('*', max(4, $len - 12)) . substr($value, -4);
}

function alupNewIdempotencyKey(): string
{
    $bytes = random_bytes(16);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-' . dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
}

function alupRequest(object $conn, string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
{
    $cfg = alupConfig($conn);
    if ((string)$cfg['api_key'] === '') {
        return [false, ['error' => ['code' => 'missing_api_key', 'message' => 'Chave AlUp não configurada.']], 0];
    }

    $url = rtrim((string)$cfg['base_url'], '/') . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . (string)$cfg['api_key'],
    ];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . substr($idempotencyKey, 0, 128);
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        return [false, ['error' => ['code' => 'communication_error', 'message' => 'Falha de comunicação com AlUp.', 'detail' => $err]], $status];
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        return [false, ['error' => ['code' => 'invalid_response', 'message' => 'Resposta inválida da AlUp.'], 'raw' => (string)$body], $status];
    }

    $ok = $status >= 200 && $status < 300 && !isset($json['error']);
    return [$ok, $json, $status];
}

function alupGetBalance(object $conn): array
{
    return alupRequest($conn, 'GET', '/v1/account/balance');
}

function alupWebhookSignatureValid(string $secret, string $timestamp, string $signature, string $rawBody): bool
{
    $secret = trim($secret);
    $timestamp = trim($timestamp);
    $signature = trim($signature);
    if ($secret === '' || $timestamp === '' || $signature === '') return false;
    if (!ctype_digit($timestamp)) return false;
    if (abs(time() - (int)$timestamp) > 300) return false;

    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, $signature);
}

function alupWebhookEventStatus(string $event): string
{
    return match ($event) {
        'order.paid', 'order.shipped' => 'processing',
        'order.delivered' => 'delivered',
        'order.cancelled' => 'cancelled',
        'order.failed' => 'failed',
        default => 'received',
    };
}

function alupTryApplyWebhookToFulfillment(object $conn, string $event, array $payload, string $rawBody): void
{
    alupEnsureTables($conn);

    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $externalId = (string)($data['id'] ?? '');
    $orderNo = (string)($data['order_no'] ?? $data['activation_no'] ?? '');
    if ($externalId === '' && $orderNo === '') return;

    $status = alupWebhookEventStatus($event);
    $providerStatus = (string)($data['provider_status'] ?? $data['status'] ?? '');
    $paymentStatus = (string)($data['payment_status'] ?? '');
    $delivery = is_array($data['delivery'] ?? null) ? $data['delivery'] : [];
    $deliveryContent = trim((string)($delivery['content'] ?? $data['delivery_content'] ?? ''));
    $downloadUrl = trim((string)($delivery['download_url'] ?? $data['download_url'] ?? ''));
    $smsPhone = trim((string)($data['phone'] ?? ''));
    $smsCode = trim((string)($data['sms_code'] ?? ''));
    $totalCents = (int)($data['total_cents'] ?? $data['total'] ?? $data['cost_cents'] ?? 0);

    $where = [];
    $types = '';
    $params = [];
    if ($externalId !== '') { $where[] = 'external_order_id = ?'; $types .= 's'; $params[] = $externalId; }
    if ($orderNo !== '') { $where[] = 'external_order_no = ?'; $types .= 's'; $params[] = $orderNo; }
    if (!$where) return;

    $sql = "SELECT id, order_item_id FROM external_fulfillments WHERE provider='alup' AND (" . implode(' OR ', $where) . ') ORDER BY id DESC LIMIT 1';
    $stFind = $conn->prepare($sql);
    if (!$stFind) return;
    $stFind->bind_param($types, ...$params);
    $stFind->execute();
    $ful = $stFind->get_result()->fetch_assoc();
    $stFind->close();
    if (!$ful) return;

    $fulfillmentId = (int)$ful['id'];
    $orderItemId = (int)$ful['order_item_id'];
    $deliveredAtSql = $status === 'delivered' ? ', delivered_at = CURRENT_TIMESTAMP' : '';

    $stUp = $conn->prepare("UPDATE external_fulfillments
        SET status=?, provider_status=?, payment_status=?, response_payload=?,
            delivery_content=COALESCE(NULLIF(?, ''), delivery_content),
            download_url=COALESCE(NULLIF(?, ''), download_url),
            sms_phone=COALESCE(NULLIF(?, ''), sms_phone),
            sms_code=COALESCE(NULLIF(?, ''), sms_code),
            supplier_total_cents=CASE WHEN ? > 0 THEN ? ELSE supplier_total_cents END,
            updated_at=CURRENT_TIMESTAMP{$deliveredAtSql}
        WHERE id=?");
    if ($stUp) {
        $stUp->bind_param('ssssssssiii', $status, $providerStatus, $paymentStatus, $rawBody, $deliveryContent, $downloadUrl, $smsPhone, $smsCode, $totalCents, $totalCents, $fulfillmentId);
        $stUp->execute();
        $stUp->close();
    }

    if ($status === 'delivered' && $orderItemId > 0 && ($deliveryContent !== '' || $smsCode !== '')) {
        $content = $deliveryContent !== '' ? $deliveryContent : ('Código SMS: ' . $smsCode . ($smsPhone !== '' ? "\nTelefone: " . $smsPhone : ''));
        $stItem = $conn->prepare("UPDATE order_items SET delivery_content = COALESCE(NULLIF(delivery_content, ''), ?), delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP) WHERE id = ?");
        if ($stItem) {
            $stItem->bind_param('si', $content, $orderItemId);
            $stItem->execute();
            $stItem->close();
        }
        alupNotifyDeliveryToChat($conn, $orderItemId, $content);
    }
}

/* ============================================================
 * AlUp Marketplace — endpoints, catálogo, mapeamento e fulfillment
 * Paths assumidos; ajuste as constantes se a doc AlUp diferir.
 * ============================================================ */

const ALUP_PATH_MARKETPLACE_LIST         = '/v1/products';
const ALUP_PATH_MARKETPLACE_GET          = '/v1/products/';
const ALUP_PATH_MARKETPLACE_ORDER_CREATE = '/v1/orders';
const ALUP_PATH_MARKETPLACE_ORDER_GET    = '/v1/orders/';
const ALUP_OFFICIAL_STORE_ID             = '8b80f8fd-9f02-48da-9ee1-d3bae135f96c';

function alupExtractList(array $body): array
{
    if (isset($body['data']) && is_array($body['data'])) {
        if (isset($body['data']['items']) && is_array($body['data']['items'])) return $body['data']['items'];
        return array_values($body['data']);
    }
    if (isset($body['items']) && is_array($body['items'])) return $body['items'];
    return [];
}

function alupExtractItem(array $body): array
{
    if (isset($body['data']) && is_array($body['data'])) return $body['data'];
    if (isset($body['item']) && is_array($body['item'])) return $body['item'];
    return $body;
}

function alupExtractPriceCents(array $payload): int
{
    foreach (['price_cents', 'cost_cents', 'supplier_cost_cents', 'price'] as $key) {
        if (isset($payload[$key]) && is_numeric($payload[$key])) return max(0, (int)round((float)$payload[$key]));
    }
    return 0;
}

function alupProductStoreId(array $payload): string
{
    return trim((string)($payload['store_id'] ?? ''));
}

function alupProductIsOfficial(array $payload): bool
{
    return alupProductStoreId($payload) === ALUP_OFFICIAL_STORE_ID;
}

function alupProductOriginLabel(array $payload): string
{
    return alupProductIsOfficial($payload) ? 'Loja oficial AlUp' : 'Vendedor AlUp';
}

function alupProductDeliveryLabel(array $payload): string
{
    return match ((string)($payload['delivery_type'] ?? '')) {
        'automatic' => 'Automática',
        'manual' => 'Manual',
        default => 'Não informado',
    };
}

function alupCacheGet(object $conn, string $cacheKey): ?array
{
    alupEnsureTables($conn);
    $st = $conn->prepare("SELECT payload FROM external_catalog_cache WHERE cache_key = ? AND expires_at > CURRENT_TIMESTAMP LIMIT 1");
    if (!$st) return null;
    $st->bind_param('s', $cacheKey);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    if (!$row) return null;
    $decoded = json_decode((string)($row['payload'] ?? ''), true);
    return is_array($decoded) ? $decoded : null;
}

function alupCacheSet(object $conn, string $cacheKey, array $payload, int $ttlSeconds): void
{
    alupEnsureTables($conn);
    $pdo = method_exists($conn, 'getPdo') ? $conn->getPdo() : null;
    if (!$pdo instanceof PDO) return;
    $expiresAt = date('Y-m-d H:i:s', time() + max(30, $ttlSeconds));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare("INSERT INTO external_catalog_cache (cache_key, provider, payload, expires_at)
                           VALUES (?, 'alup', ?, ?)
                           ON CONFLICT (cache_key) DO UPDATE
                             SET payload = EXCLUDED.payload,
                                 expires_at = EXCLUDED.expires_at,
                                 updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$cacheKey, $json, $expiresAt]);
}

function alupListMarketplaceProducts(object $conn, bool $forceRefresh = false, array $params = []): array
{
    $allowed = [];
    foreach (['limit', 'offset', 'q'] as $key) {
        if (isset($params[$key]) && trim((string)$params[$key]) !== '') $allowed[$key] = trim((string)$params[$key]);
    }
    $query = $allowed ? ('?' . http_build_query($allowed)) : '';
    $cacheKey = 'alup:marketplace:products:' . md5($query);
    if (!$forceRefresh) {
        $cached = alupCacheGet($conn, $cacheKey);
        if ($cached !== null) return [true, $cached, 200, true];
    }
    [$ok, $body, $status] = alupRequest($conn, 'GET', ALUP_PATH_MARKETPLACE_LIST . $query);
    if ($ok) {
        $ttl = (int)(alupConfig($conn)['catalog_cache_seconds'] ?? 120);
        alupCacheSet($conn, $cacheKey, $body, $ttl);
    }
    return [$ok, $body, $status, false];
}

function alupGetMarketplaceProduct(object $conn, string $externalId): array
{
    $externalId = trim($externalId);
    if ($externalId === '') return [false, ['error' => ['code' => 'invalid_id', 'message' => 'external_id vazio']], 0];
    return alupRequest($conn, 'GET', ALUP_PATH_MARKETPLACE_GET . rawurlencode($externalId));
}

function alupCreateMarketplaceOrder(object $conn, array $payload, string $idempotencyKey): array
{
    return alupRequest($conn, 'POST', ALUP_PATH_MARKETPLACE_ORDER_CREATE, $payload, $idempotencyKey);
}

function alupGetMarketplaceOrder(object $conn, string $externalOrderId): array
{
    $externalOrderId = trim($externalOrderId);
    if ($externalOrderId === '') return [false, ['error' => ['code' => 'invalid_id', 'message' => 'order_id vazio']], 0];
    return alupRequest($conn, 'GET', ALUP_PATH_MARKETPLACE_ORDER_GET . rawurlencode($externalOrderId));
}

function alupListMappings(object $conn): array
{
    alupEnsureTables($conn);
    $sql = "SELECT m.id, m.product_id, m.kind, m.external_id, m.external_payload, m.last_synced_at,
                   p.nome AS product_nome, p.preco AS product_preco, p.ativo AS product_ativo,
                   p.imagem AS product_imagem
            FROM external_product_mappings m
            LEFT JOIN products p ON p.id = m.product_id
            WHERE m.provider = 'alup'
            ORDER BY m.id DESC
            LIMIT 500";
    $rs = $conn->query($sql);
    if (!$rs) return [];
    return $rs->fetch_all(MYSQLI_ASSOC) ?: [];
}

function alupGetMappingByProduct(object $conn, int $productId): ?array
{
    alupEnsureTables($conn);
    if ($productId <= 0) return null;
    $st = $conn->prepare("SELECT id, product_id, kind, external_id, external_payload, last_synced_at
                          FROM external_product_mappings
                          WHERE provider='alup' AND product_id = ? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('i', $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row ?: null;
}

function alupSaveMapping(object $conn, int $productId, string $kind, string $externalId, array $payload = []): array
{
    alupEnsureTables($conn);
    $kind = $kind === '' ? 'marketplace' : $kind;
    if ($productId <= 0 || $externalId === '') return [false, 'Dados inválidos.'];

    $stProd = $conn->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
    $stProd->bind_param('i', $productId);
    $stProd->execute();
    $exists = $stProd->get_result()->fetch_assoc();
    $stProd->close();
    if (!$exists) return [false, 'Produto Basefy não encontrado.'];

    $existing = alupGetMappingByProduct($conn, $productId);
    $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $supplierCostCents = alupExtractPriceCents($payload);

    if ($existing) {
        $st = $conn->prepare("UPDATE external_product_mappings
                              SET kind = ?, external_id = ?, external_payload = ?, supplier_cost_cents = ?, last_synced_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                              WHERE id = ?");
        $id = (int)$existing['id'];
        $st->bind_param('sssii', $kind, $externalId, $payloadJson, $supplierCostCents, $id);
        try {
            $ok = $st->execute();
        } catch (Throwable $e) {
            $st->close();
            return [false, 'Conflito: external_id já vinculado a outro produto.'];
        }
        $st->close();
        return [(bool)$ok, $ok ? 'Vínculo atualizado.' : 'Falha ao atualizar vínculo.'];
    }

    $st = $conn->prepare("INSERT INTO external_product_mappings (product_id, provider, kind, external_id, external_payload, supplier_cost_cents, last_synced_at)
                          VALUES (?, 'alup', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $st->bind_param('isssi', $productId, $kind, $externalId, $payloadJson, $supplierCostCents);
    try {
        $ok = $st->execute();
    } catch (Throwable $e) {
        $st->close();
        return [false, 'Conflito: external_id já vinculado a outro produto.'];
    }
    $st->close();
    return [(bool)$ok, $ok ? 'Vínculo criado.' : 'Falha ao criar vínculo.'];
}

function alupDeleteMapping(object $conn, int $mappingId): array
{
    alupEnsureTables($conn);
    if ($mappingId <= 0) return [false, 'ID inválido.'];
    $st = $conn->prepare("DELETE FROM external_product_mappings WHERE id = ? AND provider='alup'");
    $st->bind_param('i', $mappingId);
    $ok = $st->execute();
    $st->close();
    return [(bool)$ok, $ok ? 'Vínculo removido.' : 'Falha ao remover.'];
}

/**
 * Importa um produto AlUp para a base local: cria registro em products e cria o mapping.
 * Retorna [ok, mensagem, product_id].
 */
function alupImportProductFromCatalog(object $conn, array $opts): array
{
    alupEnsureTables($conn);
    $externalId = trim((string)($opts['external_id'] ?? ''));
    $vendorId = (int)($opts['vendor_id'] ?? 0);
    $categoriaId = (int)($opts['categoria_id'] ?? 0);
    $markup = max(0.0, (float)($opts['markup_percent'] ?? 30));
    $nomeOverride = trim((string)($opts['nome_override'] ?? ''));
    $ativo = !empty($opts['ativo']) ? 1 : 0;
    $kind = trim((string)($opts['kind'] ?? 'marketplace')) ?: 'marketplace';

    $payload = is_array($opts['payload'] ?? null) ? $opts['payload'] : [];
    if (!$payload) {
        $raw = (string)($opts['payload_json'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $payload = $decoded;
        }
    }

    if ($externalId === '') return [false, 'external_id é obrigatório.', 0];
    if ($vendorId <= 0) return [false, 'vendor_id é obrigatório.', 0];

        // Verifica vendedor válido: seller explícito ou usuário que já tenha produto publicado
        $stV = $conn->prepare("SELECT u.id
                                                     FROM users u
                                                     WHERE u.id = ?
                                                         AND (
                                                             COALESCE(u.is_vendedor, 0) = 1
                                                             OR EXISTS (
                                                                 SELECT 1
                                                                 FROM products p
                                                                 WHERE p.vendedor_id = u.id
                                                                     AND COALESCE(p.ativo, 0) = 1
                                                             )
                                                         )
                                                     LIMIT 1");
    $stV->bind_param('i', $vendorId);
    $stV->execute();
    $okV = (bool)$stV->get_result()->fetch_assoc();
    $stV->close();
    if (!$okV) return [false, 'Vendedor não encontrado e sem produto publicado.', 0];

    // Categoria fallback: primeira categoria ativa do tipo produto
    if ($categoriaId <= 0) {
        $rsC = $conn->query("SELECT id FROM categories WHERE ativo=1 AND tipo='produto' ORDER BY id ASC LIMIT 1");
        if ($rsC) {
            $rowC = $rsC->fetch_assoc();
            $categoriaId = (int)($rowC['id'] ?? 0);
        }
    }
    if ($categoriaId <= 0) return [false, 'Crie ao menos uma categoria de produto antes de importar.', 0];

    // Se já existe mapping para este external_id, retorna o existente
    $stExist = $conn->prepare("SELECT product_id FROM external_product_mappings WHERE provider='alup' AND kind=? AND external_id=? LIMIT 1");
    $stExist->bind_param('ss', $kind, $externalId);
    $stExist->execute();
    $existRow = $stExist->get_result()->fetch_assoc();
    $stExist->close();
    if ($existRow) {
        return [false, 'Este produto AlUp já está vinculado (produto #' . (int)$existRow['product_id'] . ').', (int)$existRow['product_id']];
    }

    // Monta campos
    $nome = $nomeOverride !== '' ? $nomeOverride : (string)($payload['title'] ?? $payload['name'] ?? 'Produto AlUp');
    $nome = mb_substr($nome, 0, 160);
    $descricao = (string)($payload['description'] ?? '');
    $imagem = (string)($payload['image_url'] ?? $payload['image'] ?? '');
    if ($imagem === '' && !empty($payload['images']) && is_array($payload['images'])) {
        $first = $payload['images'][0] ?? null;
        if (is_string($first)) $imagem = $first;
        elseif (is_array($first)) $imagem = (string)($first['url'] ?? $first['src'] ?? '');
    }
    $imagem = mb_substr($imagem, 0, 255);

    $costCents = alupExtractPriceCents($payload);
    $finalCents = (int)round($costCents * (1 + ($markup / 100)));
    $preco = $finalCents > 0 ? round($finalCents / 100, 2) : 0.00;

    $deliveryType = (string)($payload['delivery_type'] ?? '');
    $tipo = $deliveryType === 'automatic' ? 'dinamico' : 'produto';
    $quantidade = (int)($payload['stock_quantity'] ?? 0);
    if ($quantidade < 0) $quantidade = 0;

    // INSERT product
    $st = $conn->prepare("INSERT INTO products (vendedor_id, categoria_id, nome, descricao, preco, imagem, tipo, quantidade, ativo)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$st) return [false, 'Falha ao preparar insert: ' . $conn->error, 0];
    $st->bind_param('iissdssii', $vendorId, $categoriaId, $nome, $descricao, $preco, $imagem, $tipo, $quantidade, $ativo);
    if (!$st->execute()) {
        $msg = 'Falha ao inserir produto: ' . $st->error;
        $st->close();
        return [false, $msg, 0];
    }
    $productId = (int)$conn->insert_id;
    $st->close();

    // Cria mapping
    [$okMap, $msgMap] = alupSaveMapping($conn, $productId, $kind, $externalId, $payload);
    if (!$okMap) {
        // Não rollback do produto: admin pode vincular manualmente depois
        return [true, 'Produto #' . $productId . ' criado mas vínculo falhou: ' . $msgMap, $productId];
    }

    return [true, 'Produto #' . $productId . ' importado e vinculado.', $productId];
}

function alupNotifyDeliveryToChat(object $conn, int $orderItemId, string $deliveryContent): void
{
    if ($orderItemId <= 0 || trim($deliveryContent) === '') return;
    $chatFile = __DIR__ . '/chat.php';
    if (!file_exists($chatFile)) return;
    require_once $chatFile;
    if (!function_exists('chatGetOrCreateConversation') || !function_exists('chatSendSystemMessage')) return;

    $st = $conn->prepare("SELECT oi.order_id, oi.vendedor_id, oi.product_id, o.user_id, p.nome AS product_name
                          FROM order_items oi
                          INNER JOIN orders o ON o.id = oi.order_id
                          LEFT JOIN products p ON p.id = oi.product_id
                          WHERE oi.id = ? LIMIT 1");
    if (!$st) return;
    $st->bind_param('i', $orderItemId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return;

    $buyerId = (int)($row['user_id'] ?? 0);
    $vendorId = (int)($row['vendedor_id'] ?? 0);
    $productId = (int)($row['product_id'] ?? 0);
    $orderId = (int)($row['order_id'] ?? 0);
    if ($buyerId <= 0 || $vendorId <= 0) return;

    try {
        $conv = chatGetOrCreateConversation($conn, $buyerId, $vendorId, $productId > 0 ? $productId : null);
        if (!$conv) return;
        $pName = (string)($row['product_name'] ?? 'Produto');
        $msg = "📦 Produto entregue (AlUp): {$pName}\n\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . trim($deliveryContent) . "\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
            . "⚠️ Copie e guarde este conteúdo em local seguro.\n"
            . "Pedido #{$orderId}.";
        chatSendSystemMessage($conn, (int)$conv['id'], $vendorId, $msg, 'delivery');
    } catch (Throwable $e) {
        error_log('[alup/chat] erro ao notificar entrega item #' . $orderItemId . ': ' . $e->getMessage());
    }
}

/**
 * Orquestra fulfillment AlUp para cada item de um pedido pago.
 * Idempotente: itens já com fulfillment em status final ou em processamento são ignorados.
 * Falhas individuais ficam registradas — não propagam exceções.
 */
function alupFulfillOrder(object $conn, int $orderId): array
{
    if ($orderId <= 0) return ['processed' => 0, 'skipped' => 0, 'errors' => 0];
    alupEnsureTables($conn);

    $cfg = alupConfig($conn);
    if (!$cfg['enabled'] || (string)$cfg['api_key'] === '') {
        return ['processed' => 0, 'skipped' => 0, 'errors' => 0, 'disabled' => true];
    }

    $st = $conn->prepare("SELECT oi.id AS item_id, oi.product_id, oi.quantidade,
                                 m.kind, m.external_id,
                                 f.id AS fulfillment_id, f.status AS fulfillment_status
                          FROM order_items oi
                          INNER JOIN external_product_mappings m
                                  ON m.product_id = oi.product_id AND m.provider = 'alup'
                          LEFT JOIN external_fulfillments f ON f.order_item_id = oi.id
                          WHERE oi.order_id = ?");
    if (!$st) return ['processed' => 0, 'skipped' => 0, 'errors' => 1];
    $st->bind_param('i', $orderId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();

    $processed = 0; $skipped = 0; $errors = 0;

    foreach ($rows as $row) {
        $existingStatus = (string)($row['fulfillment_status'] ?? '');
        if (in_array($existingStatus, ['delivered', 'processing', 'cancelled'], true)) {
            $skipped++;
            continue;
        }

        $itemId = (int)$row['item_id'];
        $kind = (string)($row['kind'] ?? 'marketplace');
        $externalId = (string)($row['external_id'] ?? '');
        if ($externalId === '') { $errors++; continue; }

        $idempotencyKey = 'order:' . $orderId . ':item:' . $itemId;
        $payload = [
            'product_id'      => $externalId,
            'external_id'     => $externalId,
            'quantity'        => max(1, (int)$row['quantidade']),
            'external_ref'    => 'basefy_order_item_' . $itemId,
            'idempotency_key' => $idempotencyKey,
        ];
        $requestJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existingFulfillmentId = (int)($row['fulfillment_id'] ?? 0);
        if ($existingFulfillmentId === 0) {
            $stIns = $conn->prepare("INSERT INTO external_fulfillments
                (order_item_id, provider, kind, external_product_id, idempotency_key, status, request_payload, attempts)
                VALUES (?, 'alup', ?, ?, ?, 'queued', ?, 0)");
            if (!$stIns) { $errors++; continue; }
            try {
                $stIns->bind_param('issss', $itemId, $kind, $externalId, $idempotencyKey, $requestJson);
                $stIns->execute();
            } catch (Throwable $e) {
                $stIns->close();
                $skipped++;
                continue;
            }
            $stIns->close();
        }

        [$okApi, $body, $statusCode] = alupCreateMarketplaceOrder($conn, $payload, $idempotencyKey);
        $responseJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!$okApi) {
            $errCode = (string)($body['error']['code'] ?? ('http_' . $statusCode));
            $errMsg  = (string)($body['error']['message'] ?? 'Falha ao criar pedido AlUp');
            $stErr = $conn->prepare("UPDATE external_fulfillments
                SET status='failed', error_code=?, error_message=?, response_payload=?,
                    attempts = attempts + 1,
                    next_retry_at = CURRENT_TIMESTAMP + INTERVAL '5 minutes',
                    updated_at = CURRENT_TIMESTAMP
                WHERE order_item_id = ?");
            if ($stErr) {
                $stErr->bind_param('sssi', $errCode, $errMsg, $responseJson, $itemId);
                $stErr->execute();
                $stErr->close();
            }
            error_log('[alup/fulfill] order #' . $orderId . ' item #' . $itemId . ' falha: ' . $errCode . ' / ' . $errMsg);
            $errors++;
            continue;
        }

        $data            = alupExtractItem($body);
        $extOrderId      = (string)($data['id'] ?? '');
        $extOrderNo      = (string)($data['order_no'] ?? $data['number'] ?? '');
        $providerStatus  = (string)($data['status'] ?? '');
        $delivery        = is_array($data['delivery'] ?? null) ? $data['delivery'] : [];
        $deliveryContent = trim((string)($delivery['content'] ?? $data['delivery_content'] ?? ''));
        $downloadUrl     = trim((string)($delivery['download_url'] ?? $data['download_url'] ?? ''));
        $totalCents      = (int)($data['total_cents'] ?? $data['total'] ?? $data['cost_cents'] ?? 0);

        $finalStatus = match (strtolower($providerStatus)) {
            'delivered', 'completed', 'done'        => 'delivered',
            'cancelled', 'canceled', 'refunded'     => 'cancelled',
            'failed', 'error'                       => 'failed',
            default                                  => ($deliveryContent !== '' ? 'delivered' : 'processing'),
        };
        $isDelivered = $finalStatus === 'delivered';
        $deliveredSql = $isDelivered ? ', delivered_at = CURRENT_TIMESTAMP' : '';

        $stUp = $conn->prepare("UPDATE external_fulfillments
            SET status = ?, provider_status = ?, external_order_id = ?, external_order_no = ?,
                delivery_content = NULLIF(?, ''), download_url = NULLIF(?, ''),
                supplier_total_cents = CASE WHEN ? > 0 THEN ? ELSE supplier_total_cents END,
                response_payload = ?, error_code = NULL, error_message = NULL,
                attempts = attempts + 1, next_retry_at = NULL, updated_at = CURRENT_TIMESTAMP{$deliveredSql}
            WHERE order_item_id = ?");
        if ($stUp) {
            $stUp->bind_param('ssssssiisi', $finalStatus, $providerStatus, $extOrderId, $extOrderNo, $deliveryContent, $downloadUrl, $totalCents, $totalCents, $responseJson, $itemId);
            $stUp->execute();
            $stUp->close();
        }

        if ($isDelivered && $deliveryContent !== '') {
            $stItem = $conn->prepare("UPDATE order_items
                SET delivery_content = COALESCE(NULLIF(delivery_content, ''), ?),
                    delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP)
                WHERE id = ?");
            if ($stItem) {
                $stItem->bind_param('si', $deliveryContent, $itemId);
                $stItem->execute();
                $stItem->close();
            }
            alupNotifyDeliveryToChat($conn, $itemId, $deliveryContent);
        }

        $processed++;
    }

    return ['processed' => $processed, 'skipped' => $skipped, 'errors' => $errors];
}

function alupListFulfillments(object $conn, array $filters = []): array
{
    alupEnsureTables($conn);
    $where = ["f.provider = 'alup'"];
    $params = [];
    $types = '';
    $status = (string)($filters['status'] ?? '');
    if ($status !== '') { $where[] = 'f.status = ?'; $types .= 's'; $params[] = $status; }
    $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));

    $sql = "SELECT f.*, oi.order_id, oi.product_id, oi.quantidade, p.nome AS product_nome
            FROM external_fulfillments f
            LEFT JOIN order_items oi ON oi.id = f.order_item_id
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.id DESC LIMIT " . $limit;
    $st = $conn->prepare($sql);
    if (!$st) return [];
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();
    return $rows;
}

function alupRetryFulfillment(object $conn, int $fulfillmentId): array
{
    alupEnsureTables($conn);
    if ($fulfillmentId <= 0) return [false, 'ID inválido.'];

    $st = $conn->prepare("SELECT order_item_id, status, external_order_id FROM external_fulfillments WHERE id = ? AND provider='alup' LIMIT 1");
    $st->bind_param('i', $fulfillmentId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return [false, 'Fulfillment não encontrado.'];

    $extOrderId = (string)($row['external_order_id'] ?? '');
    $orderItemId = (int)$row['order_item_id'];

    if ($extOrderId !== '') {
        [$okApi, $body, $statusCode] = alupGetMarketplaceOrder($conn, $extOrderId);
        $responseJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($okApi) {
            $data = alupExtractItem($body);
            $providerStatus = strtolower((string)($data['status'] ?? $data['provider_status'] ?? ''));
            $delivery = is_array($data['delivery'] ?? null) ? $data['delivery'] : [];
            $deliveryContent = trim((string)($delivery['content'] ?? $data['delivery_content'] ?? ''));
            $syncEvent = match (true) {
                $deliveryContent !== '' || in_array($providerStatus, ['delivered', 'completed', 'done'], true) => 'order.delivered',
                in_array($providerStatus, ['cancelled', 'canceled', 'refunded'], true) => 'order.cancelled',
                in_array($providerStatus, ['failed', 'error'], true) => 'order.failed',
                default => 'order.paid',
            };
            alupTryApplyWebhookToFulfillment($conn, $syncEvent, ['data' => $data], $responseJson);
            return [true, 'Status sincronizado com AlUp.'];
        }
        return [false, 'Falha ao sincronizar: ' . (string)($body['error']['message'] ?? 'erro AlUp ' . $statusCode)];
    }

    $stOrder = $conn->prepare("SELECT order_id FROM order_items WHERE id = ? LIMIT 1");
    $stOrder->bind_param('i', $orderItemId);
    $stOrder->execute();
    $orderRow = $stOrder->get_result()->fetch_assoc();
    $stOrder->close();
    if (!$orderRow) return [false, 'Item do pedido não encontrado.'];

    $stDel = $conn->prepare("DELETE FROM external_fulfillments WHERE id = ? AND status = 'failed'");
    $stDel->bind_param('i', $fulfillmentId);
    $stDel->execute();
    $stDel->close();

    $result = alupFulfillOrder($conn, (int)$orderRow['order_id']);
    return [$result['errors'] === 0, 'Retry executado: processed=' . $result['processed'] . ' errors=' . $result['errors']];
}
