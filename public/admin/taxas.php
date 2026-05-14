<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\taxas.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/wallet_escrow.php';
require_once __DIR__ . '/../../src/seller_levels.php';

exigirAdmin();

$conn = (new Database())->connect();
sellerLevelsEnsure($conn);
escrowEnsureDefaults($conn);

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = (string)($_POST['form_type'] ?? 'global_taxas');

    if ($formType === 'seller_override') {
        $vendorId = max(0, (int)($_POST['seller_id'] ?? 0));
        $customEnabled = isset($_POST['seller_fee_override_enabled']);
        $customRaw = trim((string)($_POST['seller_fee_percent'] ?? ''));
        $customPct = $customRaw !== '' ? (float)str_replace(',', '.', $customRaw) : null;

        [$ok, $feedback] = sellerFeeOverrideSave($conn, $vendorId, $customEnabled, $customPct);
        if ($ok) $msg = $feedback; else $err = $feedback;
    } else {
        $enabled       = isset($_POST['taxas_enabled']) ? '1' : '0';
        $n1Pct         = max(0.0, min(100.0, (float)($_POST['nivel1_percent'] ?? 14.99)));
        $n2Pct         = max(0.0, min(100.0, (float)($_POST['nivel2_percent'] ?? 12.99)));
        $n2Thr         = max(0.0, (float)($_POST['nivel2_threshold'] ?? 20000));
        $n3Pct         = max(0.0, min(100.0, (float)($_POST['nivel3_percent'] ?? 9.99)));
        $n3Thr         = max(0.0, (float)($_POST['nivel3_threshold'] ?? 40000));
        $leadPct       = max(0.0, min(100.0, (float)($_POST['lead_fee_percent'] ?? 4.99)));
        $globalPct     = max(0.0, min(100.0, (float)($_POST['global_vendor_percent'] ?? 1.99)));
        $globalFlat    = max(0.0, (float)($_POST['global_flat_per_order'] ?? 1.00));

        // Escrow / auto-release settings
        $days          = max(1, min(60, (int)($_POST['auto_release_days'] ?? 7)));
        $autoEnabled   = isset($_POST['auto_release_enabled']) ? '1' : '0';
        $adminId       = max(0, (int)($_POST['platform_admin_user_id'] ?? 0));

        if ($n3Thr <= $n2Thr) {
            $err = 'O limite do Nível 3 deve ser maior que o do Nível 2.';
        } else {
            escrowSettingSet($conn, 'taxas.enabled',                $enabled);
            escrowSettingSet($conn, 'taxas.nivel1_percent',         number_format($n1Pct, 2, '.', ''));
            escrowSettingSet($conn, 'taxas.nivel2_percent',         number_format($n2Pct, 2, '.', ''));
            escrowSettingSet($conn, 'taxas.nivel2_threshold',       number_format($n2Thr, 2, '.', ''));
            escrowSettingSet($conn, 'taxas.nivel3_percent',         number_format($n3Pct, 2, '.', ''));
            escrowSettingSet($conn, 'taxas.nivel3_threshold',       number_format($n3Thr, 2, '.', ''));
            escrowSettingSet($conn, 'taxas.lead_fee_percent',       number_format($leadPct, 2, '.', ''));
            escrowSettingSet($conn, 'taxas.global_vendor_percent',  number_format($globalPct, 2, '.', ''));
            escrowSettingSet($conn, 'taxas.global_flat_per_order',  number_format($globalFlat, 2, '.', ''));

            // Save escrow settings
            escrowSettingSet($conn, 'wallet.auto_release_days', (string)$days);
            escrowSettingSet($conn, 'wallet.auto_release_enabled', $autoEnabled);
            escrowSettingSet($conn, 'wallet.platform_admin_user_id', (string)$adminId);

            $msg = 'Configurações atualizadas com sucesso.';
        }
    }
}

$cfg = sellerLevelsConfig($conn);
$rules = escrowRules($conn);
sellerFeeOverrideEnsureColumns($conn);

// Admin list for receiver selector
$admins = [];
$q = $conn->query("SELECT id, nome, email FROM users WHERE role IN ('admin','administrador') ORDER BY id ASC");
if ($q) {
    $admins = $q->fetch_all(MYSQLI_ASSOC);
}

$overrideSellers = [];
// Pega todos que são vendedores por role, por flag, ou que têm produtos/vendas
$sellersSql = "
  SELECT u.id, u.nome, u.email,
         COALESCE(u.seller_fee_override_enabled, FALSE) AS seller_fee_override_enabled,
         u.seller_fee_percent
  FROM users u
  WHERE
     u.role IN ('vendedor','vendor','seller','vendendor')
     OR COALESCE(u.is_vendedor, FALSE) = TRUE
     OR u.id IN (SELECT DISTINCT vendedor_id FROM products WHERE vendedor_id IS NOT NULL)
     OR u.id IN (SELECT DISTINCT vendedor_id FROM order_items WHERE vendedor_id IS NOT NULL)
  ORDER BY u.nome ASC, u.id ASC
  LIMIT 1000
";
try {
  $qSellers = $conn->query($sellersSql);
  if ($qSellers) {
    $overrideSellers = $qSellers->fetch_all(MYSQLI_ASSOC) ?: [];
  }
} catch (\Throwable $e) {
  // Fallback caso colunas/tabelas não existam: lista qualquer usuário com vendas
  $fb = $conn->query("SELECT u.id, u.nome, u.email, FALSE AS seller_fee_override_enabled, NULL::numeric AS seller_fee_percent FROM users u WHERE u.id IN (SELECT DISTINCT vendedor_id FROM order_items WHERE vendedor_id IS NOT NULL) OR u.id IN (SELECT DISTINCT vendedor_id FROM products WHERE vendedor_id IS NOT NULL) ORDER BY u.nome ASC LIMIT 1000");
  if ($fb) $overrideSellers = $fb->fetch_all(MYSQLI_ASSOC) ?: [];
}

// Top sellers — any user who has sales (not limited to "vendedor" role)
$tsSearch = trim((string)($_GET['ts'] ?? ''));
$sp       = max(1, (int)($_GET['sp'] ?? 1));
$spp      = in_array(($_spp = (int)($_GET['spp'] ?? 10)), [5,10,20]) ? $_spp : 10;

$tsWhere = "oi.vendedor_id IS NOT NULL AND oi.vendedor_id > 0 AND oi.moderation_status != 'rejeitado'";
$tsParams = [];
$tsTypes  = '';
if ($tsSearch !== '') {
    $tsWhere .= " AND (u.nome ILIKE ? OR u.email ILIKE ?)";
    $tsLike = '%' . $tsSearch . '%';
    $tsParams = [$tsLike, $tsLike];
    $tsTypes  = 'ss';
}

// Count total
$cntSql = "SELECT COUNT(DISTINCT oi.vendedor_id) AS t FROM order_items oi INNER JOIN users u ON u.id = oi.vendedor_id WHERE {$tsWhere}";
$cntSt  = $conn->prepare($cntSql);
if ($tsParams) $cntSt->bind_param($tsTypes, ...$tsParams);
$cntSt->execute();
$tsSellersTotal = (int)($cntSt->get_result()->fetch_assoc()['t'] ?? 0);
$cntSt->close();
$tsTotalPages = max(1, (int)ceil($tsSellersTotal / $spp));
if ($sp > $tsTotalPages) $sp = $tsTotalPages;
$tsOffset = ($sp - 1) * $spp;

// Fetch page
$tsSql = "SELECT oi.vendedor_id, u.nome, u.email,
                 COALESCE(SUM(oi.subtotal), 0) AS total_revenue,
                 COUNT(oi.id) AS total_vendas
          FROM order_items oi
          INNER JOIN users u ON u.id = oi.vendedor_id
          WHERE {$tsWhere}
          GROUP BY oi.vendedor_id, u.nome, u.email
          ORDER BY total_revenue DESC
          LIMIT {$spp} OFFSET {$tsOffset}";
$tsSt = $conn->prepare($tsSql);
if ($tsParams) $tsSt->bind_param($tsTypes, ...$tsParams);
$tsSt->execute();
$topSellers = $tsSt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$tsSt->close();

$pageTitle  = 'Financeiro';
$activeMenu = 'financeiro';
$adminFinanceTab = 'taxas';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="space-y-6">
  <?php include __DIR__ . '/../../views/partials/admin_financeiro_tabs.php'; ?>

  <?php if ($msg): ?>
    <div class="rounded-2xl border border-greenx/30 bg-greenx/[0.08] px-5 py-3.5 text-sm text-greenx flex items-center gap-3">
      <i data-lucide="check-circle-2" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($msg) ?></span>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="rounded-2xl border border-red-500/30 bg-red-600/[0.08] px-5 py-3.5 text-sm text-red-300 flex items-center gap-3">
      <i data-lucide="alert-triangle" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($err) ?></span>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

  <!-- ═══ LEFT COLUMN: Config ═══ -->
  <div class="space-y-6">

  <!-- Explanation Card -->
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 space-y-3">
    <div class="flex items-center gap-2 text-purple-400">
      <i data-lucide="info" class="w-5 h-5"></i>
      <h3 class="font-semibold text-sm">Como funciona o sistema de taxas</h3>
    </div>
    <div class="text-xs text-zinc-400 space-y-1.5 leading-relaxed">
      <?php if ($cfg['enabled']): ?>
        <p><strong class="text-greenx">Modo níveis ATIVO.</strong> Cada vendedor tem um <strong class="text-zinc-200">nível</strong> baseado no faturamento aprovado. Taxa total = <strong>Taxa do Nível</strong> + <strong>Taxa de Lead</strong> (paga pelo comprador).</p>
      <?php else: ?>
        <p><strong class="text-greenx">Modo taxa global ATIVO.</strong> Todos os vendedores pagam <strong class="text-zinc-200"><?= number_format($cfg['global_vendor_percent'], 2, ',', '.') ?>%</strong> sobre cada venda + <strong class="text-zinc-200">R$ <?= number_format($cfg['global_flat_per_order'], 2, ',', '.') ?></strong> fixo por pedido. O comprador <strong>não paga</strong> taxa de serviço.</p>
      <?php endif; ?>
      <p>Todo valor de taxa é creditado automaticamente na carteira do <strong class="text-zinc-200">admin recebedor</strong> configurado abaixo.</p>
    </div>
  </div>

  <!-- Config Form -->
  <form method="post" class="bg-blackx2 border border-blackx3 rounded-2xl p-5 space-y-5">
    <input type="hidden" name="form_type" value="global_taxas">

    <div class="flex items-center justify-between">
      <h2 class="text-lg font-bold flex items-center gap-2">
        <i data-lucide="percent" class="w-5 h-5 text-greenx"></i> Configuração de Taxas
      </h2>
      <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
        <input type="checkbox" name="taxas_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>
               class="w-4 h-4 rounded border-blackx3 accent-greenx">
        <span class="text-zinc-300">Sistema de níveis ativo <span class="text-zinc-500">(sobrescreve a taxa global)</span></span>
      </label>
    </div>

    <!-- Taxa Global -->
    <div class="<?= $cfg['enabled'] ? 'opacity-60' : '' ?> bg-greenx/[0.04] border border-greenx/30 rounded-xl p-4 space-y-3">
      <div class="flex items-center gap-2">
        <i data-lucide="globe" class="w-4 h-4 text-greenx"></i>
        <h3 class="font-semibold text-sm text-greenx">Taxa Global (modo padrão — ativa quando o sistema de níveis está desligado)</h3>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Percentual do vendedor (%)</label>
          <input type="number" step="0.01" min="0" max="100" name="global_vendor_percent"
                 value="<?= htmlspecialchars(number_format($cfg['global_vendor_percent'], 2, '.', '')) ?>"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-greenx transition">
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Taxa fixa por pedido (R$)</label>
          <input type="number" step="0.01" min="0" name="global_flat_per_order"
                 value="<?= htmlspecialchars(number_format($cfg['global_flat_per_order'], 2, '.', '')) ?>"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-greenx transition">
        </div>
      </div>
      <p class="text-[11px] text-zinc-500">Cobrada do vendedor na liberação do escrow. A taxa fixa é aplicada <strong>uma vez por pedido</strong>, no primeiro item liberado.</p>
    </div>

    <!-- Lead Fee -->
    <div class="<?= !$cfg['enabled'] ? 'opacity-60' : '' ?> bg-blackx/60 border border-blackx3 rounded-xl p-4">
      <div class="flex items-center gap-2 mb-3">
        <i data-lucide="badge-dollar-sign" class="w-4 h-4 text-yellow-400"></i>
        <h3 class="font-semibold text-sm text-yellow-300">Taxa de Lead (só aplica no modo níveis — cobrada do comprador)</h3>
      </div>
      <div class="max-w-xs">
        <label class="block text-xs text-zinc-400 mb-1">Percentual (%)</label>
        <input type="number" step="0.01" min="0" max="100" name="lead_fee_percent"
               value="<?= htmlspecialchars(number_format($cfg['lead_fee_percent'], 2, '.', '')) ?>"
               class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-yellow-400 transition">
      </div>
    </div>

    <!-- Levels -->
    <div class="<?= !$cfg['enabled'] ? 'opacity-60' : '' ?> grid grid-cols-1 md:grid-cols-3 gap-4">

      <!-- Level 1 -->
      <div class="bg-blackx/60 border border-blackx3 rounded-xl p-4 space-y-3">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-lg bg-orange-500/20 border border-orange-400/40 flex items-center justify-center text-orange-300 text-xs font-bold">1</div>
          <div>
            <h3 class="font-semibold text-sm">Nível 1</h3>
            <p class="text-[10px] text-zinc-500">Vendedores iniciantes</p>
          </div>
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Taxa (%)</label>
          <input type="number" step="0.01" min="0" max="100" name="nivel1_percent"
                 value="<?= htmlspecialchars(number_format($cfg['nivel1_percent'], 2, '.', '')) ?>"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-orange-400 transition">
        </div>
        <p class="text-[10px] text-zinc-600">Faturamento: R$ 0 até R$ <?= number_format($cfg['nivel2_threshold'], 2, ',', '.') ?></p>
      </div>

      <!-- Level 2 -->
      <div class="bg-blackx/60 border border-blackx3 rounded-xl p-4 space-y-3">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-lg bg-greenx/20 border border-greenx/40 flex items-center justify-center text-purple-300 text-xs font-bold">2</div>
          <div>
            <h3 class="font-semibold text-sm">Nível 2</h3>
            <p class="text-[10px] text-zinc-500">Vendedores intermediários</p>
          </div>
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Taxa (%)</label>
          <input type="number" step="0.01" min="0" max="100" name="nivel2_percent"
                 value="<?= htmlspecialchars(number_format($cfg['nivel2_percent'], 2, '.', '')) ?>"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-greenx transition">
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Faturamento mínimo (R$)</label>
          <input type="number" step="0.01" min="0" name="nivel2_threshold"
                 value="<?= htmlspecialchars(number_format($cfg['nivel2_threshold'], 2, '.', '')) ?>"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-greenx transition">
        </div>
      </div>

      <!-- Level 3 -->
      <div class="bg-blackx/60 border border-blackx3 rounded-xl p-4 space-y-3">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-lg bg-greenx/20 border border-greenx/40 flex items-center justify-center text-greenx text-xs font-bold">3</div>
          <div>
            <h3 class="font-semibold text-sm">Nível 3</h3>
            <p class="text-[10px] text-zinc-500">Top vendedores</p>
          </div>
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Taxa (%)</label>
          <input type="number" step="0.01" min="0" max="100" name="nivel3_percent"
                 value="<?= htmlspecialchars(number_format($cfg['nivel3_percent'], 2, '.', '')) ?>"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-greenx transition">
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Faturamento mínimo (R$)</label>
          <input type="number" step="0.01" min="0" name="nivel3_threshold"
                 value="<?= htmlspecialchars(number_format($cfg['nivel3_threshold'], 2, '.', '')) ?>"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-greenx transition">
        </div>
      </div>

    </div>

    <!-- Summary -->
    <div class="bg-blackx/60 border border-blackx3 rounded-xl p-4">
      <h4 class="text-xs text-zinc-500 font-semibold mb-2 uppercase tracking-wider">Resumo das Taxas</h4>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
        <div class="flex items-center gap-2">
          <span class="w-3 h-3 rounded-full bg-orange-400"></span>
          <span class="text-zinc-400">Nível 1:</span>
          <span class="text-white font-semibold"><?= number_format($cfg['nivel1_percent'] + $cfg['lead_fee_percent'], 2) ?>%</span>
          <span class="text-zinc-600 text-xs">(<?= number_format($cfg['nivel1_percent'], 2) ?>% + <?= number_format($cfg['lead_fee_percent'], 2) ?>% lead)</span>
        </div>
        <div class="flex items-center gap-2">
          <span class="w-3 h-3 rounded-full bg-greenx"></span>
          <span class="text-zinc-400">Nível 2:</span>
          <span class="text-white font-semibold"><?= number_format($cfg['nivel2_percent'] + $cfg['lead_fee_percent'], 2) ?>%</span>
          <span class="text-zinc-600 text-xs">(<?= number_format($cfg['nivel2_percent'], 2) ?>% + <?= number_format($cfg['lead_fee_percent'], 2) ?>% lead)</span>
        </div>
        <div class="flex items-center gap-2">
          <span class="w-3 h-3 rounded-full bg-greenx"></span>
          <span class="text-zinc-400">Nível 3:</span>
          <span class="text-white font-semibold"><?= number_format($cfg['nivel3_percent'] + $cfg['lead_fee_percent'], 2) ?>%</span>
          <span class="text-zinc-600 text-xs">(<?= number_format($cfg['nivel3_percent'], 2) ?>% + <?= number_format($cfg['lead_fee_percent'], 2) ?>% lead)</span>
        </div>
      </div>
    </div>

    <!-- Escrow / Auto-release / Admin Receiver -->
    <div class="border-t border-blackx3 pt-5 space-y-4">
      <h3 class="text-base font-bold flex items-center gap-2">
        <i data-lucide="timer" class="w-5 h-5 text-purple-400"></i> Escrow & Auto-liberação
      </h3>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Dias para auto-liberação</label>
          <input type="number" min="1" max="60" name="auto_release_days"
                 value="<?= (int)$rules['auto_release_days'] ?>"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-greenx transition">
        </div>

        <div class="flex items-end pb-1">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input type="checkbox" name="auto_release_enabled" value="1" <?= $rules['auto_release_enabled'] ? 'checked' : '' ?>
                   class="w-4 h-4 rounded border-blackx3 accent-greenx">
            <span class="text-zinc-300">Habilitar auto-liberação por prazo</span>
          </label>
        </div>
      </div>

      <div>
        <label class="block text-xs text-zinc-400 mb-1">Admin recebedor das taxas</label>
        <select name="platform_admin_user_id"
                class="w-full md:w-1/2 rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-greenx transition">
          <option value="0">Automático (primeiro admin ativo)</option>
          <?php foreach ($admins as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= (int)$rules['platform_admin_user_id'] === (int)$a['id'] ? 'selected' : '' ?>>
              #<?= (int)$a['id'] ?> - <?= htmlspecialchars((string)$a['nome']) ?> (<?= htmlspecialchars((string)$a['email']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-5 py-2.5 text-sm transition">
        <i data-lucide="save" class="w-4 h-4"></i> Salvar Configurações
      </button>
    </div>
  </form>

  </div><!-- /LEFT COLUMN -->

  <!-- ═══ RIGHT COLUMN: Top Vendedores ═══ -->
  <div class="space-y-6">

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 space-y-4">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h2 class="text-lg font-bold flex items-center gap-2">
          <i data-lucide="user-cog" class="w-5 h-5 text-fuchsia-400"></i> Taxa personalizada por vendedor
        </h2>
        <p class="text-xs text-zinc-500 mt-1">Sem personalização, o vendedor herda automaticamente as taxas globais e os níveis configurados à esquerda.</p>
      </div>
    </div>

    <form method="post" class="rounded-xl border border-blackx3 bg-blackx/50 p-4 space-y-4">
      <input type="hidden" name="form_type" value="seller_override">
      <div>
        <label class="block text-xs text-zinc-400 mb-1">Vendedor</label>
        <select id="sellerOverrideSelect" name="seller_id" required
                class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-fuchsia-400 transition">
          <option value="">Selecione um vendedor</option>
          <?php foreach ($overrideSellers as $seller):
            $customEnabled = sellerBool($seller['seller_fee_override_enabled'] ?? false) && ($seller['seller_fee_percent'] ?? null) !== null;
            $customPct = $seller['seller_fee_percent'] ?? '';
          ?>
            <option value="<?= (int)$seller['id'] ?>"
                    data-enabled="<?= $customEnabled ? '1' : '0' ?>"
                    data-percent="<?= htmlspecialchars($customPct !== '' && $customPct !== null ? number_format((float)$customPct, 2, '.', '') : '') ?>">
              #<?= (int)$seller['id'] ?> - <?= htmlspecialchars((string)$seller['nome']) ?> (<?= htmlspecialchars((string)$seller['email']) ?>)<?= $customEnabled ? ' · personalizada: ' . number_format((float)$customPct, 2, ',', '.') . '%' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
        <input id="sellerOverrideEnabled" type="checkbox" name="seller_fee_override_enabled" value="1"
               class="w-4 h-4 rounded border-blackx3 accent-fuchsia-500">
        <span class="text-zinc-300">Usar taxa personalizada para este vendedor</span>
      </label>

      <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-3 items-end">
        <div>
          <label class="block text-xs text-zinc-400 mb-1">Taxa personalizada (%)</label>
          <input id="sellerOverridePercent" type="number" step="0.01" min="0" max="100" name="seller_fee_percent" placeholder="Ex.: 8.50"
                 class="w-full rounded-xl bg-blackx border border-blackx3 px-4 py-2.5 text-sm outline-none focus:border-fuchsia-400 transition">
        </div>
        <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-fuchsia-600 hover:bg-fuchsia-500 text-white font-semibold px-5 py-2.5 text-sm transition">
          <i data-lucide="save" class="w-4 h-4"></i> Salvar taxa
        </button>
      </div>
      <p class="text-[11px] text-zinc-500 leading-relaxed">Para voltar ao padrão global, selecione o vendedor, desmarque a opção personalizada e salve.</p>
    </form>

    <script>
    (function(){
      var select = document.getElementById('sellerOverrideSelect');
      var enabled = document.getElementById('sellerOverrideEnabled');
      var percent = document.getElementById('sellerOverridePercent');
      if (!select || !enabled || !percent) return;
      function syncFromSelected(){
        var opt = select.options[select.selectedIndex];
        if (!opt) return;
        enabled.checked = opt.getAttribute('data-enabled') === '1';
        percent.value = opt.getAttribute('data-percent') || '';
      }
      select.addEventListener('change', syncFromSelected);
      enabled.addEventListener('change', function(){ if (!enabled.checked) percent.value = ''; });
    })();
    </script>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 space-y-4">
    <h2 class="text-lg font-bold flex items-center gap-2">
      <i data-lucide="trophy" class="w-5 h-5 text-yellow-400"></i> Top Vendedores — Nível Atual
    </h2>

    <!-- Filter -->
    <form method="get" class="rounded-xl border border-blackx3 bg-blackx/50 p-3">
      <div class="flex items-end gap-2">
        <div class="flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Buscar vendedor</label>
          <input type="text" name="ts" value="<?= htmlspecialchars($tsSearch) ?>" placeholder="Nome ou e-mail"
                 class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-sm outline-none focus:border-greenx">
        </div>
        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-3 py-2 text-sm whitespace-nowrap transition-all">
          <i data-lucide="search" class="w-3.5 h-3.5"></i> Filtrar
        </button>
        <?php if ($tsSearch !== ''): ?>
          <a href="taxas" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-9 h-9 text-zinc-300 hover:border-greenx hover:text-white transition-all" title="Limpar">
            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3 text-xs uppercase tracking-wider">
            <th class="text-left py-3 pr-3">Vendedor</th>
            <th class="text-left py-3 pr-3">Vendas</th>
            <th class="text-right py-3 pr-3">Faturamento</th>
            <th class="text-center py-3 pr-3">Nível</th>
            <th class="text-right py-3 pr-3">Taxa Nível</th>
            <th class="text-right py-3 pr-3">Lead</th>
            <th class="text-right py-3">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($topSellers)): ?>
            <tr><td colspan="7" class="py-6 text-center text-zinc-500">Nenhum vendedor encontrado.</td></tr>
          <?php endif; ?>
          <?php foreach ($topSellers as $s):
            $sInfo = sellerLevelCalc($conn, (int)$s['vendedor_id']);
            $levelColor = match($sInfo['level']) {
                0 => 'bg-fuchsia-500/20 border-fuchsia-400/40 text-fuchsia-300',
                3 => 'bg-greenx/20 border-greenx/40 text-greenx',
                2 => 'bg-greenx/20 border-greenx/40 text-purple-300',
                default => 'bg-orange-500/20 border-orange-400/40 text-orange-300',
            };
          ?>
            <tr class="border-b border-blackx3/50 hover:bg-blackx/40 transition">
              <td class="py-3 pr-3">
                <p class="font-medium text-sm"><?= htmlspecialchars((string)$s['nome']) ?></p>
                <p class="text-[11px] text-zinc-500">#<?= (int)$s['vendedor_id'] ?> · <?= htmlspecialchars((string)$s['email']) ?></p>
              </td>
              <td class="py-3 pr-3 text-zinc-300"><?= (int)$s['total_vendas'] ?></td>
              <td class="py-3 pr-3 text-right text-zinc-200 font-semibold">R$ <?= number_format((float)$s['total_revenue'], 2, ',', '.') ?></td>
              <td class="py-3 pr-3 text-center">
                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium <?= $levelColor ?>"><?= $sInfo['label'] ?></span>
              </td>
              <td class="py-3 pr-3 text-right text-zinc-300"><?= number_format($sInfo['fee_percent'], 2) ?>%</td>
              <td class="py-3 pr-3 text-right text-zinc-400"><?= number_format($sInfo['lead_fee_percent'], 2) ?>%</td>
              <td class="py-3 text-right text-white font-semibold"><?= number_format($sInfo['total_fee_percent'], 2) ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Top Sellers Pagination -->
    <?php
      // Build query string preserving ts filter
      $_tsQp = $_GET; unset($_tsQp['sp'], $_tsQp['spp']);
      $_tsQs = http_build_query($_tsQp);
      $_tsSep = $_tsQs !== '' ? '&' : '';
      $tsPrev = max(1, $sp - 1);
      $tsNext = min($tsTotalPages, $sp + 1);
      $_tsPpUid = 'tsPpSel_' . mt_rand(1000, 9999);
    ?>
    <nav class="mt-3 flex items-center justify-between gap-3 flex-wrap">
      <div class="flex items-center gap-1">
        <?php if ($tsTotalPages > 1): ?>
          <?php if ($sp > 1): ?>
            <a href="?<?= $_tsQs . $_tsSep ?>sp=1&spp=<?= $spp ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs" title="Primeira">&laquo;</a>
            <a href="?<?= $_tsQs . $_tsSep ?>sp=<?= $tsPrev ?>&spp=<?= $spp ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs" title="Anterior">&lsaquo;</a>
          <?php endif; ?>
          <?php
            $tsMaxBtns = 5; $tsHalf = (int)floor($tsMaxBtns / 2);
            $tsStart = max(1, $sp - $tsHalf); $tsEnd = min($tsTotalPages, $tsStart + $tsMaxBtns - 1);
            if ($tsEnd - $tsStart + 1 < $tsMaxBtns) $tsStart = max(1, $tsEnd - $tsMaxBtns + 1);
          ?>
          <?php for ($tsPg = $tsStart; $tsPg <= $tsEnd; $tsPg++): ?>
            <?php if ($tsPg === $sp): ?>
              <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-r from-greenx to-greenxd text-white font-bold text-xs"><?= $tsPg ?></span>
            <?php else: ?>
              <a href="?<?= $_tsQs . $_tsSep ?>sp=<?= $tsPg ?>&spp=<?= $spp ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs"><?= $tsPg ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($sp < $tsTotalPages): ?>
            <a href="?<?= $_tsQs . $_tsSep ?>sp=<?= $tsNext ?>&spp=<?= $spp ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs" title="Próxima">&rsaquo;</a>
            <a href="?<?= $_tsQs . $_tsSep ?>sp=<?= $tsTotalPages ?>&spp=<?= $spp ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs" title="Última">&raquo;</a>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-xs text-zinc-500">Página <?= $sp ?> de <?= $tsTotalPages ?></span>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-2">
        <label class="text-xs text-zinc-500 whitespace-nowrap">Por página</label>
        <select id="<?= $_tsPpUid ?>" class="rounded-lg bg-white/[0.04] border border-white/[0.08] px-2.5 py-1.5 text-xs text-zinc-300 focus:outline-none focus:border-greenx/50 cursor-pointer">
          <?php foreach ([5,10,20] as $opt): ?>
            <option value="<?= $opt ?>" <?= $opt === $spp ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </nav>
    <script>
    (function(){
      var sel=document.getElementById('<?= $_tsPpUid ?>');
      if(!sel)return;
      sel.addEventListener('change',function(){
        try{
          var url=new URL(window.location.href);
          url.searchParams.set('spp',sel.value);
          url.searchParams.set('sp','1');
          window.location.assign(url.toString());
        }catch(e){
          window.location.href=window.location.pathname+'?spp='+sel.value+'&sp=1';
        }
      });
    })();
    </script>
  </div>
  </div><!-- /RIGHT COLUMN -->

  </div><!-- /grid -->

</div>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
