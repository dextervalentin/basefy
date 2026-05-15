<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\seller_levels.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/wallet_escrow.php';

/* ════════════════════════════════════════════════════════════
   SELLER LEVELS — tiered fee system based on seller revenue
   ════════════════════════════════════════════════════════════
   Nível 1 (default):  14.99%  (0 – threshold2)
   Nível 2:            12.99%  (threshold2 – threshold3)
   Nível 3:             9.99%  (threshold3+)
   Lead fee (fixed):    4.99%  (always added on top)
   ──────────────────────────────────────────────────────── */

function sellerLevelsDefaults(): array
{
    return [
        // Sistema de níveis (legacy). '0' = usa taxa global plana.
        'taxas.enabled'                 => '0',
        'taxas.nivel1_percent'          => '14.99',
        'taxas.nivel2_percent'          => '12.99',
        'taxas.nivel2_threshold'        => '20000.00',
        'taxas.nivel3_percent'          => '9.99',
        'taxas.nivel3_threshold'        => '40000.00',
        'taxas.lead_fee_percent'        => '4.99',
        // Taxa global aplicada ao vendedor quando taxas.enabled = '0'
        'taxas.global_vendor_percent'   => '1.99',
        'taxas.global_flat_per_order'   => '1.00',
    ];
}

/**
 * Ensure all seller-level keys exist in platform_settings.
 */
function sellerLevelsEnsure($conn): void
{
    foreach (sellerLevelsDefaults() as $key => $default) {
        $current = escrowSettingGet($conn, $key, '');
        if ($current === '') {
            escrowSettingSet($conn, $key, $default);
        }
    }

    // Migração v2 (one-shot): força desativação do sistema de níveis e ativa
    // a taxa global (1.99% + R$1) como padrão.
    if (escrowSettingGet($conn, 'taxas.migrated_v2', '') !== '1') {
        escrowSettingSet($conn, 'taxas.enabled', '0');
        escrowSettingSet($conn, 'taxas.migrated_v2', '1');
    }

    sellerFeeOverrideEnsureColumns($conn);
    sellerProductFeeOverrideEnsureColumns($conn);
}

function sellerFeeOverrideEnsureColumns($conn): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $st = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users' AND column_name IN ('seller_fee_override_enabled', 'seller_fee_percent')");
        if (!$st) return;
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $found = [];
        foreach ($rows as $row) {
            $name = (string)($row['column_name'] ?? '');
            if ($name !== '') $found[$name] = true;
        }

        if (!isset($found['seller_fee_override_enabled'])) {
            $conn->query('ALTER TABLE users ADD COLUMN seller_fee_override_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        }
        if (!isset($found['seller_fee_percent'])) {
            $conn->query('ALTER TABLE users ADD COLUMN seller_fee_percent NUMERIC(5,2) DEFAULT NULL');
        }
    } catch (\Throwable $e) {
        error_log('[SellerLevels] Falha ao garantir colunas de taxa personalizada: ' . $e->getMessage());
    }
}

function sellerBool(mixed $value): bool
{
    if (is_bool($value)) return $value;
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 't', 'yes', 'y', 'sim'], true);
}

function sellerFeeOverrideGet($conn, int $vendorId): array
{
    sellerFeeOverrideEnsureColumns($conn);

    $st = $conn->prepare('SELECT seller_fee_override_enabled, seller_fee_percent FROM users WHERE id = ? LIMIT 1');
    if (!$st) {
        return ['enabled' => false, 'percent' => null];
    }
    $st->bind_param('i', $vendorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];

    $percent = $row['seller_fee_percent'] ?? null;
    $enabled = sellerBool($row['seller_fee_override_enabled'] ?? false) && $percent !== null && $percent !== '';

    return [
        'enabled' => $enabled,
        'percent' => $percent !== null && $percent !== '' ? max(0.0, min(100.0, (float)$percent)) : null,
    ];
}

function sellerFeeOverrideSave($conn, int $vendorId, bool $enabled, ?float $percent): array
{
    sellerFeeOverrideEnsureColumns($conn);

    if ($vendorId <= 0) {
        return [false, 'Selecione um vendedor válido.'];
    }

    $st = $conn->prepare("SELECT id FROM users WHERE id = ? AND (
            role IN ('vendedor','vendor','seller','vendendor')
            OR is_vendedor = TRUE
            OR id IN (SELECT DISTINCT vendedor_id FROM order_items WHERE vendedor_id IS NOT NULL)
            OR id IN (SELECT DISTINCT vendedor_id FROM products WHERE vendedor_id IS NOT NULL)
        ) LIMIT 1");
    if (!$st) {
        // Fallback: aceita qualquer usuário existente (caso colunas/tabelas não existam)
        $st = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        if (!$st) {
            return [false, 'Não foi possível validar o vendedor.'];
        }
    }
    $st->bind_param('i', $vendorId);
    $st->execute();
    if (!$st->get_result()->fetch_assoc()) {
        return [false, 'Vendedor não encontrado.'];
    }

    if ($enabled) {
        if ($percent === null) {
            return [false, 'Informe a taxa personalizada.'];
        }
        $percent = max(0.0, min(100.0, $percent));
        $enabledInt = 1;
        $stUp = $conn->prepare('UPDATE users SET seller_fee_override_enabled = ?, seller_fee_percent = ? WHERE id = ?');
        $stUp->bind_param('idi', $enabledInt, $percent, $vendorId);
    } else {
        $enabledInt = 0;
        $percentNull = null;
        $stUp = $conn->prepare('UPDATE users SET seller_fee_override_enabled = ?, seller_fee_percent = ? WHERE id = ?');
        $stUp->bind_param('idi', $enabledInt, $percentNull, $vendorId);
    }
    $stUp->execute();

    return [true, $enabled ? 'Taxa personalizada salva para o vendedor.' : 'Vendedor voltou a herdar as taxas globais.'];
}

function sellerProductFeeOverrideEnsureColumns($conn): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $st = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'products' AND column_name IN ('product_fee_override_enabled', 'product_fee_percent')");
        if (!$st) return;
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $found = [];
        foreach ($rows as $row) {
            $name = (string)($row['column_name'] ?? '');
            if ($name !== '') $found[$name] = true;
        }

        if (!isset($found['product_fee_override_enabled'])) {
            $conn->query('ALTER TABLE products ADD COLUMN IF NOT EXISTS product_fee_override_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        }
        if (!isset($found['product_fee_percent'])) {
            $conn->query('ALTER TABLE products ADD COLUMN IF NOT EXISTS product_fee_percent NUMERIC(5,2) DEFAULT NULL');
        }
    } catch (\Throwable $e) {
        error_log('[SellerLevels] Falha ao garantir colunas de taxa por produto: ' . $e->getMessage());
    }
}

function sellerProductFeeOverrideGet($conn, int $productId): array
{
    sellerProductFeeOverrideEnsureColumns($conn);

    if ($productId <= 0) {
        return ['enabled' => false, 'percent' => null];
    }

    $st = $conn->prepare('SELECT product_fee_override_enabled, product_fee_percent FROM products WHERE id = ? LIMIT 1');
    if (!$st) {
        return ['enabled' => false, 'percent' => null];
    }
    $st->bind_param('i', $productId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];

    $percent = $row['product_fee_percent'] ?? null;
    $enabled = sellerBool($row['product_fee_override_enabled'] ?? false) && $percent !== null && $percent !== '';

    return [
        'enabled' => $enabled,
        'percent' => $percent !== null && $percent !== '' ? max(0.0, min(100.0, (float)$percent)) : null,
    ];
}

/**
 * Fetch the full level/tax config from DB.
 */
function sellerLevelsConfig($conn): array
{
    sellerLevelsEnsure($conn);

    return [
        'enabled'               => escrowSettingGet($conn, 'taxas.enabled', '0') === '1',
        'nivel1_percent'        => max(0.0, (float)escrowSettingGet($conn, 'taxas.nivel1_percent', '14.99')),
        'nivel2_percent'        => max(0.0, (float)escrowSettingGet($conn, 'taxas.nivel2_percent', '12.99')),
        'nivel2_threshold'      => max(0.0, (float)escrowSettingGet($conn, 'taxas.nivel2_threshold', '20000.00')),
        'nivel3_percent'        => max(0.0, (float)escrowSettingGet($conn, 'taxas.nivel3_percent', '9.99')),
        'nivel3_threshold'      => max(0.0, (float)escrowSettingGet($conn, 'taxas.nivel3_threshold', '40000.00')),
        'lead_fee_percent'      => max(0.0, (float)escrowSettingGet($conn, 'taxas.lead_fee_percent', '4.99')),
        'global_vendor_percent' => max(0.0, min(100.0, (float)escrowSettingGet($conn, 'taxas.global_vendor_percent', '1.99'))),
        'global_flat_per_order' => max(0.0, (float)escrowSettingGet($conn, 'taxas.global_flat_per_order', '1.00')),
    ];
}

/**
 * Modo atual de cobrança: 'levels' (sistema de níveis legacy)
 * ou 'global' (taxa plana de X% + R$Y por pedido).
 */
function sellerFeeMode($conn): string
{
    $cfg = sellerLevelsConfig($conn);
    return $cfg['enabled'] ? 'levels' : 'global';
}

/**
 * Taxa fixa por pedido (modo global): legado por compat. SEMPRE retorna 0
 * porque o flat agora é pago pelo comprador (ver buyerFlatFeePerOrder).
 */
function sellerFlatFeePerOrder($conn): float
{
    return 0.0;
}

/**
 * Taxa fixa em R$ cobrada do comprador uma única vez por pedido,
 * apenas no modo global.
 */
function buyerFlatFeePerOrder($conn): float
{
    $cfg = sellerLevelsConfig($conn);
    if ($cfg['enabled']) return 0.0;
    return max(0.0, (float)$cfg['global_flat_per_order']);
}

/**
 * Calculate total approved revenue for a seller.
 */
function sellerTotalRevenue($conn, int $vendorId): float
{
    $sql = "SELECT COALESCE(SUM(subtotal), 0) AS total
            FROM order_items
            WHERE vendedor_id = ?
              AND moderation_status = 'aprovada'";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $vendorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (float)($row['total'] ?? 0);
}

/**
 * Determine the seller's current level (1, 2 or 3) and associated info.
 *
 * @return array{level: int, label: string, fee_percent: float, lead_fee_percent: float, total_fee_percent: float, revenue: float, next_threshold: float|null}
 */
function sellerLevelCalc($conn, int $vendorId): array
{
    $cfg = sellerLevelsConfig($conn);

    $override = sellerFeeOverrideGet($conn, $vendorId);
    if ($override['enabled']) {
        $feePct = (float)$override['percent'];
        return [
            'level'             => 0,
            'label'             => 'Personalizada',
            'fee_percent'       => $feePct,
            'lead_fee_percent'  => 0.0,
            'total_fee_percent' => $feePct,
            'revenue'           => sellerTotalRevenue($conn, $vendorId),
            'next_threshold'    => null,
            'is_custom'         => true,
        ];
    }

    if (!$cfg['enabled']) {
        // Modo global: taxa plana % (a parte flat é aplicada uma vez por pedido,
        // tratada em wallet_escrow.php — não entra aqui no calc por item).
        $globalPct = (float)$cfg['global_vendor_percent'];
        return [
            'level'             => 0,
            'label'             => 'Taxa global',
            'fee_percent'       => $globalPct,
            'lead_fee_percent'  => 0.0,
            'total_fee_percent' => $globalPct,
            'revenue'           => 0.0,
            'next_threshold'    => null,
            'is_custom'         => false,
        ];
    }

    $revenue = sellerTotalRevenue($conn, $vendorId);

    if ($revenue >= $cfg['nivel3_threshold']) {
        $level   = 3;
        $label   = 'Nível 3';
        $feePct  = $cfg['nivel3_percent'];
        $nextThr = null;
    } elseif ($revenue >= $cfg['nivel2_threshold']) {
        $level   = 2;
        $label   = 'Nível 2';
        $feePct  = $cfg['nivel2_percent'];
        $nextThr = $cfg['nivel3_threshold'];
    } else {
        $level   = 1;
        $label   = 'Nível 1';
        $feePct  = $cfg['nivel1_percent'];
        $nextThr = $cfg['nivel2_threshold'];
    }

    // Lead fee was moved to the buyer (service fee at checkout).
    // Sellers only pay their tier-based fee now.
    $leadFee  = 0.0;
    $totalFee = round($feePct + $leadFee, 2);

    return [
        'level'             => $level,
        'label'             => $label,
        'fee_percent'       => $feePct,
        'lead_fee_percent'  => $leadFee,
        'total_fee_percent' => $totalFee,
        'revenue'           => $revenue,
        'next_threshold'    => $nextThr,
        'is_custom'         => false,
    ];
}

/**
 * Calculate fee amounts for a given gross value using the seller's level.
 *
 * @return array{fee_percent: float, lead_fee_percent: float, total_fee_percent: float, fee_amount: float, lead_fee_amount: float, total_fee_amount: float, net_amount: float, level: int, label: string}
 */
function sellerFeeCalc($conn, int $vendorId, float $gross, ?int $productId = null): array
{
    $productOverride = $productId !== null && $productId > 0
        ? sellerProductFeeOverrideGet($conn, $productId)
        : ['enabled' => false, 'percent' => null];

    if ($productOverride['enabled']) {
        $feePct = (float)$productOverride['percent'];
        $info = [
            'level' => 0,
            'label' => 'Personalizada por produto',
            'fee_percent' => $feePct,
            'lead_fee_percent' => 0.0,
            'total_fee_percent' => $feePct,
        ];
    } else {
        $info = sellerLevelCalc($conn, $vendorId);
    }

    $levelFeeAmount = round($gross * ($info['fee_percent'] / 100), 2);
    $leadFeeAmount  = round($gross * ($info['lead_fee_percent'] / 100), 2);
    $totalFeeAmount = round($levelFeeAmount + $leadFeeAmount, 2);

    // Clamp
    if ($totalFeeAmount < 0) $totalFeeAmount = 0;
    if ($totalFeeAmount > $gross) $totalFeeAmount = $gross;

    $netAmount = round($gross - $totalFeeAmount, 2);

    return [
        'fee_percent'       => $info['fee_percent'],
        'lead_fee_percent'  => $info['lead_fee_percent'],
        'total_fee_percent' => $info['total_fee_percent'],
        'fee_amount'        => $levelFeeAmount,
        'lead_fee_amount'   => $leadFeeAmount,
        'total_fee_amount'  => $totalFeeAmount,
        'net_amount'        => $netAmount,
        'level'             => $info['level'],
        'label'             => $info['label'],
    ];
}

/**
 * Get the buyer service fee percentage (the former lead_fee moved to buyer).
 */
function buyerServiceFeePercent($conn): float
{
    $cfg = sellerLevelsConfig($conn);
    // No modo global o comprador não paga taxa de serviço — o vendedor cobre tudo.
    if (!$cfg['enabled']) return 0.0;
    return max(0.0, $cfg['lead_fee_percent']);
}
