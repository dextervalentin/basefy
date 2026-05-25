<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

exigirAdmin();

$db = new Database();
$conn = $db->connect();

// Carrega config de taxas para banner de status
require_once __DIR__ . '/../../src/seller_levels.php';
try { sellerLevelsEnsure($conn); } catch (\Throwable $e) {}
$feeCfg  = sellerLevelsConfig($conn);
$feeMode = $feeCfg['enabled'] ? 'levels' : 'global';

try {
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS buyer_fee NUMERIC(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS escrow_fee_amount NUMERIC(12,2) DEFAULT NULL");
    $conn->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS escrow_net_amount NUMERIC(12,2) DEFAULT NULL");
} catch (Throwable $e) {}

function scalarIntSafe($conn, string $sql, int $default = 0): int {
    try {
        $q = $conn->query($sql);
        if (!$q) return $default;
        $row = $q->fetch_assoc() ?: [];
        if (array_key_exists('qtd', $row)) {
            return (int)$row['qtd'];
        }
        $first = array_values($row)[0] ?? $default;
        return (int)$first;
    } catch (Throwable $e) {
        return $default;
    }
}

function scalarMoneySafe($conn, string $sql, float $default = 0.0): float {
    try {
        $q = $conn->query($sql);
        if (!$q) return $default;
        $row = $q->fetch_assoc() ?: [];
        if (array_key_exists('valor', $row)) {
            return (float)$row['valor'];
        }
        $first = array_values($row)[0] ?? $default;
        return (float)$first;
    } catch (Throwable $e) {
        return $default;
    }
}

function moneyBR(float $value): string {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function firstExistingColumn($conn, string $table, array $candidates): ?string {
    $sql = "SELECT column_name
            FROM information_schema.COLUMNS
            WHERE table_schema = current_schema() AND table_name = ?";
    $st = $conn->prepare($sql);
    $st->execute([$table]);
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $set = [];
    foreach ($rows as $r) {
        $col = (string)($r['column_name'] ?? $r['COLUMN_NAME'] ?? '');
        if ($col !== '') {
            $set[strtolower($col)] = true;
        }
    }

    foreach ($candidates as $c) {
        if (isset($set[strtolower($c)])) return $c;
    }
    return null;
}

$totUsers      = scalarIntSafe($conn, "SELECT COUNT(*) AS qtd FROM users");
$totVendedores = scalarIntSafe($conn, "SELECT COUNT(*) AS qtd FROM users WHERE role='vendedor'");

$paidOrdersSql = "LOWER(status) IN ('pago','entregue','concluido','enviado')";
$paidPaymentsSql = "order_id IS NOT NULL AND LOWER(status) IN ('paid','pago','approved','aprovado','captured','confirmed','completed','succeeded','success')";

$financeBruto = scalarMoneySafe($conn, "SELECT COALESCE(SUM(COALESCE(NULLIF(gross_total, 0), total) + COALESCE(buyer_fee, 0)), 0) AS valor FROM orders WHERE $paidOrdersSql");
$financeBuyerFee = scalarMoneySafe($conn, "SELECT COALESCE(SUM(COALESCE(buyer_fee, 0)), 0) AS valor FROM orders WHERE $paidOrdersSql");
$financeEscrowFee = scalarMoneySafe($conn, "SELECT COALESCE(SUM(COALESCE(escrow_fee_amount, 0)), 0) AS valor FROM order_items WHERE LOWER(COALESCE(moderation_status, '')) IN ('aprovada','approved')");
$financeTaxasRetidas = $financeBuyerFee + $financeEscrowFee;
$financePixBruto = scalarMoneySafe($conn, "SELECT COALESCE(SUM(COALESCE(amount_centavos, 0)), 0) / 100.0 AS valor FROM payment_transactions WHERE $paidPaymentsSql");
$financePixFees = scalarMoneySafe($conn, "SELECT COALESCE(SUM(COALESCE(fees_centavos, 0)), 0) / 100.0 AS valor FROM payment_transactions WHERE $paidPaymentsSql");
$financePixLiquido = scalarMoneySafe($conn, "SELECT COALESCE(SUM(COALESCE(net_centavos, amount_centavos - COALESCE(fees_centavos, 0), 0)), 0) / 100.0 AS valor FROM payment_transactions WHERE $paidPaymentsSql");
$financeRepasseVendedores = scalarMoneySafe($conn, "SELECT COALESCE(SUM(COALESCE(escrow_net_amount, 0)), 0) AS valor FROM order_items WHERE LOWER(COALESCE(moderation_status, '')) IN ('aprovada','approved')");
$financeLiquidoPlataforma = $financeTaxasRetidas - $financePixFees;

$orderDateCol = firstExistingColumn($conn, 'order_items', ['criado_em', 'created_at', 'data_criacao']);
if ($orderDateCol) {
    $vendasHoje = scalarIntSafe($conn, "SELECT COUNT(*) AS qtd FROM order_items WHERE DATE($orderDateCol)=CURRENT_DATE");
} else {
    $vendasHoje = 0;
}

$hasModeration = firstExistingColumn($conn, 'order_items', ['moderation_status', 'status_moderacao']) !== null;
if ($hasModeration) {
    $modCol = firstExistingColumn($conn, 'order_items', ['moderation_status', 'status_moderacao']);
    $analise   = scalarIntSafe($conn, "SELECT COUNT(*) AS qtd FROM order_items WHERE $modCol='pendente'");
    $aprovadas = scalarIntSafe($conn, "SELECT COUNT(*) AS qtd FROM order_items WHERE $modCol='aprovada'");
} else {
    $analise = 0;
    $aprovadas = 0;
}

/* ── Chart data ──────────────────────────────────────────────── */
// Daily sales last 7 days
$_dailyMap = [];
if ($orderDateCol) {
    $_q7 = $conn->query("SELECT DATE($orderDateCol) AS dia, COUNT(*) AS qtd FROM order_items WHERE $orderDateCol >= CURRENT_DATE - 6 GROUP BY DATE($orderDateCol) ORDER BY dia");
    if ($_q7) while ($_r = $_q7->fetch_assoc()) $_dailyMap[(string)$_r['dia']] = (int)$_r['qtd'];
}
$chartLabels7 = [];
$chartData7   = [];
for ($_i = 6; $_i >= 0; $_i--) {
    $_d = date('Y-m-d', strtotime("-{$_i} days"));
    $chartLabels7[] = date('d/m', strtotime("-{$_i} days"));
    $chartData7[]   = $_dailyMap[$_d] ?? 0;
}
// Rejected count
$rejeitadas = 0;
if ($hasModeration) {
    $rejeitadas = scalarIntSafe($conn, "SELECT COUNT(*) AS qtd FROM order_items WHERE $modCol IN ('rejeitada','rejected','recusada')");
}
// New users last 7 days
$userDateCol = firstExistingColumn($conn, 'users', ['criado_em', 'created_at', 'data_criacao']);
$_userMap = [];
if ($userDateCol) {
    $_qu = $conn->query("SELECT DATE($userDateCol) AS dia, COUNT(*) AS qtd FROM users WHERE $userDateCol >= CURRENT_DATE - 6 GROUP BY DATE($userDateCol) ORDER BY dia");
    if ($_qu) while ($_r = $_qu->fetch_assoc()) $_userMap[(string)$_r['dia']] = (int)$_r['qtd'];
}
$chartDataUsers = [];
for ($_i = 6; $_i >= 0; $_i--) {
    $_d = date('Y-m-d', strtotime("-{$_i} days"));
    $chartDataUsers[] = $_userMap[$_d] ?? 0;
}

$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
$topActions = [
    ['label' => 'Vendas', 'href' => 'vendas'],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="space-y-4">
    <a href="taxas" class="block bg-blackx2 border <?= $feeMode === 'global' ? 'border-greenx/40 hover:border-greenx' : 'border-yellow-400/40 hover:border-yellow-300' ?> rounded-2xl p-4 transition group">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl <?= $feeMode === 'global' ? 'bg-greenx/15 text-greenx' : 'bg-yellow-500/15 text-yellow-300' ?> flex items-center justify-center shrink-0">
                <i data-lucide="<?= $feeMode === 'global' ? 'percent' : 'trophy' ?>" class="w-5 h-5"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[11px] uppercase tracking-wider <?= $feeMode === 'global' ? 'text-greenx' : 'text-yellow-300' ?> font-semibold">Modelo de taxa ativo</p>
                <p class="text-sm font-semibold mt-0.5 truncate">
                <?php if ($feeMode === 'global'): ?>
                    Taxa global — <?= number_format($feeCfg['global_vendor_percent'], 2, ',', '.') ?>%
                    <?php if ($feeCfg['global_flat_per_order'] > 0): ?>
                      + R$ <?= number_format($feeCfg['global_flat_per_order'], 2, ',', '.') ?> por pedido
                    <?php endif; ?>
                <?php else: ?>
                    Sistema de níveis — <?= number_format($feeCfg['nivel1_percent'], 2, ',', '.') ?>% / <?= number_format($feeCfg['nivel2_percent'], 2, ',', '.') ?>% / <?= number_format($feeCfg['nivel3_percent'], 2, ',', '.') ?>%
                <?php endif; ?>
                </p>
            </div>
            <i data-lucide="arrow-right" class="w-4 h-4 text-zinc-500 group-hover:text-white transition shrink-0"></i>
        </div>
    </a>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold">Resumo operacional</h2>
            <p class="text-sm text-zinc-400">Visão rápida dos principais indicadores do painel.</p>
        </div>
        <div class="hidden md:flex gap-2">
            <a href="vendas" class="rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx transition">Revisar vendas</a>
        </div>
    </div>

    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-2 mb-4">
            <div>
                <p class="text-[11px] uppercase tracking-wider text-greenx font-semibold">Financeiro da plataforma</p>
                <h2 class="text-lg font-semibold mt-0.5">Bruto, taxas, custos PIX e líquido</h2>
            </div>
            <p class="text-xs text-zinc-500">Considera pedidos pagos/entregues e transações PIX confirmadas.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            <div class="rounded-xl border border-white/[0.06] bg-blackx p-4">
                <div class="flex items-center justify-between gap-2 text-zinc-400 text-sm">
                    <span>Bruto final processado</span>
                    <i data-lucide="banknote" class="w-4 h-4 text-zinc-500"></i>
                </div>
                <div class="text-2xl font-semibold mt-2"><?= moneyBR($financeBruto) ?></div>
                <p class="text-[11px] text-zinc-500 mt-1">Produtos + taxa do comprador</p>
            </div>
            <div class="rounded-xl border border-white/[0.06] bg-blackx p-4">
                <div class="flex items-center justify-between gap-2 text-zinc-400 text-sm">
                    <span>Taxas totais retidas</span>
                    <i data-lucide="receipt-text" class="w-4 h-4 text-zinc-500"></i>
                </div>
                <div class="text-2xl font-semibold mt-2 text-greenx"><?= moneyBR($financeTaxasRetidas) ?></div>
                <p class="text-[11px] text-zinc-500 mt-1">Comprador <?= moneyBR($financeBuyerFee) ?> + escrow <?= moneyBR($financeEscrowFee) ?></p>
            </div>
            <div class="rounded-xl border border-white/[0.06] bg-blackx p-4">
                <div class="flex items-center justify-between gap-2 text-zinc-400 text-sm">
                    <span>Custos API PIX</span>
                    <i data-lucide="zap" class="w-4 h-4 text-zinc-500"></i>
                </div>
                <div class="text-2xl font-semibold mt-2 text-red-300">-<?= moneyBR($financePixFees) ?></div>
                <p class="text-[11px] text-zinc-500 mt-1">PIX bruto <?= moneyBR($financePixBruto) ?> · líquido gateway <?= moneyBR($financePixLiquido) ?></p>
            </div>
            <div class="rounded-xl border border-white/[0.06] bg-blackx p-4">
                <div class="flex items-center justify-between gap-2 text-zinc-400 text-sm">
                    <span>Faturamento líquido</span>
                    <i data-lucide="trending-up" class="w-4 h-4 text-zinc-500"></i>
                </div>
                <div class="text-2xl font-semibold mt-2 <?= $financeLiquidoPlataforma >= 0 ? 'text-greenx' : 'text-red-300' ?>"><?= moneyBR($financeLiquidoPlataforma) ?></div>
                <p class="text-[11px] text-zinc-500 mt-1">Retidas - custos PIX · repasse vendedores <?= moneyBR($financeRepasseVendedores) ?></p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
            <div class="text-zinc-400 text-sm">Usuários</div>
            <div class="text-2xl font-semibold"><?= $totUsers ?></div>
        </div>
        <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
            <div class="text-zinc-400 text-sm">Vendedores</div>
            <div class="text-2xl font-semibold"><?= $totVendedores ?></div>
        </div>
        <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
            <div class="text-zinc-400 text-sm">Vendas hoje</div>
            <div class="text-2xl font-semibold"><?= $vendasHoje ?></div>
        </div>
        <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
            <div class="text-zinc-400 text-sm">Vendas em análise</div>
            <div class="text-2xl font-semibold"><?= $analise ?></div>
        </div>
        <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
            <div class="text-zinc-400 text-sm">Vendas aprovadas</div>
            <div class="text-2xl font-semibold"><?= $aprovadas ?></div>
        </div>
    </div>

    <!-- Charts -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div class="bg-blackx2 border border-blackx3 rounded-xl p-3">
            <h3 class="text-xs font-semibold text-zinc-400 mb-2">Vendas — 7 dias</h3>
            <canvas id="chartVendas7d" height="150"></canvas>
        </div>
        <div class="bg-blackx2 border border-blackx3 rounded-xl p-3">
            <h3 class="text-xs font-semibold text-zinc-400 mb-2">Moderação</h3>
            <div class="flex justify-center" style="max-height:180px">
                <canvas id="chartModeracao"></canvas>
            </div>
        </div>
        <div class="bg-blackx2 border border-blackx3 rounded-xl p-3">
            <h3 class="text-xs font-semibold text-zinc-400 mb-2">Novos usuários — 7 dias</h3>
            <canvas id="chartUsers7d" height="150"></canvas>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <script>
    Chart.defaults.color='#a1a1aa';
    Chart.defaults.borderColor='rgba(63,63,70,0.45)';
    new Chart(document.getElementById('chartVendas7d'),{type:'line',data:{labels:<?= json_encode($chartLabels7) ?>,datasets:[{label:'Vendas',data:<?= json_encode($chartData7) ?>,borderColor:'#8800E4',backgroundColor:'rgba(var(--t-accent-rgb),0.06)',fill:true,tension:.4,pointBackgroundColor:'#8800E4',pointRadius:3,borderWidth:2}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:10}}},y:{beginAtZero:true,ticks:{precision:0,font:{size:10}}}}}});
    new Chart(document.getElementById('chartModeracao'),{type:'doughnut',data:{labels:['Aprovadas','Em análise','Rejeitadas'],datasets:[{data:[<?= $aprovadas ?>,<?= $analise ?>,<?= $rejeitadas ?>],backgroundColor:['#8800E4','#F59E0B','#EF4444'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:true,cutout:'60%',plugins:{legend:{position:'bottom',labels:{padding:10,usePointStyle:true,font:{size:10}}}}}});
    new Chart(document.getElementById('chartUsers7d'),{type:'bar',data:{labels:<?= json_encode($chartLabels7) ?>,datasets:[{label:'Novos usuários',data:<?= json_encode($chartDataUsers) ?>,backgroundColor:'rgba(99,102,241,0.65)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:10}}},y:{beginAtZero:true,ticks:{precision:0,font:{size:10}}}}}});
    </script>

    <?php if (($totUsers + $totVendedores + $vendasHoje + $analise + $aprovadas) === 0): ?>
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 text-center">
            <p class="text-zinc-300 font-medium">Ainda não há dados para exibir no dashboard.</p>
            <p class="text-zinc-500 text-sm mt-1">Cadastre usuários, vendedores e vendas para popular os indicadores.</p>
        </div>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';