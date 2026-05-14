<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/affiliates.php';

exigirAdmin();

$conn = (new Database())->connect();
affEnsureTables($conn);

$msg = '';
$err = '';
$adminUid = (int)($_SESSION['user_id'] ?? 0);

// Tab
$tab = $_GET['tab'] ?? 'afiliados'; // afiliados | conversoes | saques

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve' && ($affId = (int)($_POST['affiliate_id'] ?? 0)) > 0) {
        affSetStatus($conn, $affId, 'ativo');
        $msg = 'Afiliado aprovado com sucesso.';
    }
    if ($action === 'suspend' && ($affId = (int)($_POST['affiliate_id'] ?? 0)) > 0) {
        affSetStatus($conn, $affId, 'suspenso');
        $msg = 'Afiliado suspenso.';
    }
    if ($action === 'reject' && ($affId = (int)($_POST['affiliate_id'] ?? 0)) > 0) {
        affSetStatus($conn, $affId, 'rejeitado');
        $msg = 'Afiliado rejeitado.';
    }
    if ($action === 'set_rate' && ($affId = (int)($_POST['affiliate_id'] ?? 0)) > 0) {
        $rate = $_POST['custom_rate'] ?? '';
        if ($rate === '' || $rate === 'null') {
            $conn->query("UPDATE affiliates SET custom_rate = NULL WHERE id = $affId");
            $msg = 'Taxa personalizada removida (usando taxa global).';
        } else {
            $r = max(0, min(50, (float)$rate));
            $st = $conn->prepare('UPDATE affiliates SET custom_rate = ? WHERE id = ?');
            $st->bind_param('di', $r, $affId);
            $st->execute();
            $msg = 'Taxa personalizada definida: ' . number_format($r, 1) . '%';
        }
    }
    if ($action === 'approve_payout' && ($pid = (int)($_POST['payout_id'] ?? 0)) > 0) {
        $tab = 'saques';
        if (affApprovePayout($conn, $pid, $adminUid)) {
            $msg = 'Saque aprovado.';
        } else {
            $err = 'Erro ao aprovar saque.';
        }
    }
    if ($action === 'reject_payout' && ($pid = (int)($_POST['payout_id'] ?? 0)) > 0) {
        $tab = 'saques';
        $notes = trim((string)($_POST['admin_notes'] ?? ''));
        affRejectPayout($conn, $pid, $adminUid, $notes);
        $msg = 'Saque rejeitado.';
    }
    if ($action === 'approve_conversion' && ($cid = (int)($_POST['conversion_id'] ?? 0)) > 0) {
        $tab = 'conversoes';
        affApproveConversion($conn, $cid);
        $msg = 'Conversão aprovada.';
    }
    if ($action === 'cancel_conversion' && ($cid = (int)($_POST['conversion_id'] ?? 0)) > 0) {
        $tab = 'conversoes';
        affCancelConversion($conn, $cid);
        $msg = 'Conversão cancelada.';
    }
}

// Stats
$overview = affAdminOverview($conn);
$topAffs = affTopAffiliates($conn, 5);

// List affiliates
$page = max(1, (int)($_GET['page'] ?? 1));
$pp   = in_array(($_ppA = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_ppA : 10;
$statusFilter = $_GET['status'] ?? '';
$search = trim((string)($_GET['q'] ?? ''));
$affiliates = affListAll($conn, $page, $pp, $statusFilter, $search);

// Conversions (admin global)
$convPage = max(1, (int)($_GET['conv_page'] ?? 1));
$convFilter = $_GET['conv_status'] ?? '';
$convOffset = ($convPage - 1) * $pp;
$convSql = "SELECT ac.*, a.referral_code, u.nome AS aff_nome, u.email AS aff_email
            FROM affiliate_conversions ac
            JOIN affiliates a ON a.id = ac.affiliate_id
            JOIN users u ON u.id = a.user_id";
$convCountSql = "SELECT COUNT(*) AS cnt FROM affiliate_conversions";
if ($convFilter) {
    $convSql .= " WHERE ac.status = ?";
    $convCountSql .= " WHERE status = ?";
}
$convSql .= " ORDER BY ac.created_at DESC LIMIT $pp OFFSET $convOffset";
$convRows = [];
if ($convFilter) {
    $stConv = $conn->prepare($convSql);
    $stConv->bind_param('s', $convFilter);
    $stConv->execute();
    $convRows = $stConv->get_result()->fetch_all(MYSQLI_ASSOC);
    $stConv->close();
    $stConvC = $conn->prepare($convCountSql);
    $stConvC->bind_param('s', $convFilter);
    $stConvC->execute();
    $convTotal = (int)($stConvC->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stConvC->close();
} else {
    $r = $conn->query($convSql);
    if ($r) $convRows = $r->fetch_all(MYSQLI_ASSOC);
    $rC = $conn->query($convCountSql);
    $convTotal = $rC ? (int)($rC->fetch_assoc()['cnt'] ?? 0) : 0;
}
$convPages = (int)ceil($convTotal / $pp);

// Payouts
$payPage = max(1, (int)($_GET['pay_page'] ?? 1));
$payFilter = $_GET['pay_status'] ?? '';
$payouts = affListPayouts($conn, 0, $payPage, $pp, $payFilter);

$pageTitle = 'Afiliados';
$activeMenu = 'afiliados';
$topActions = [
    ['label' => 'Configurações', 'href' => 'afiliados_config'],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';

$statusBadge = function(string $s): string {
    return match($s) {
        'ativo','aprovada','aprovado','pago','paga' => 'bg-greenx/15 border-greenx/40 text-greenx',
        'pendente'  => 'bg-yellow-500/15 border-yellow-400/40 text-yellow-300',
        'suspenso','rejeitado','rejeitada','cancelada' => 'bg-red-500/15 border-red-400/40 text-red-300',
        default => 'bg-zinc-500/15 border-zinc-400/40 text-zinc-300',
    };
};
?>

<div class="space-y-6">

  <?php if ($msg): ?>
    <div class="rounded-xl bg-greenx/10 border border-greenx/30 p-4 text-sm text-greenx flex items-center gap-2 animate-fade-in">
      <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0"></i> <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="rounded-xl bg-red-500/10 border border-red-400/30 p-4 text-sm text-red-300 flex items-center gap-2 animate-fade-in">
      <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i> <?= htmlspecialchars($err) ?>
    </div>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <?php
    $kpis = [
        ['label' => 'Total afiliados',   'value' => $overview['totalAffiliates'],   'icon' => 'users',        'color' => 'text-purple-400'],
        ['label' => 'Ativos',            'value' => $overview['activeAffiliates'],   'icon' => 'user-check',   'color' => 'text-greenx'],
        ['label' => 'Pendentes',         'value' => $overview['pendingAffiliates'],  'icon' => 'user-plus',    'color' => 'text-yellow-400'],
        ['label' => 'Cliques totais',    'value' => number_format($overview['totalClicks']),    'icon' => 'mouse-pointer-click', 'color' => 'text-purple-400'],
        ['label' => 'Conversões',        'value' => number_format($overview['totalConversions']),'icon' => 'shopping-cart','color' => 'text-cyan-400'],
        ['label' => 'Comissões geradas', 'value' => 'R$ ' . number_format($overview['totalCommissions'], 2, ',', '.'), 'icon' => 'coins', 'color' => 'text-greenx'],
        ['label' => 'Saques pendentes',  'value' => $overview['pendingPayouts'],     'icon' => 'clock',        'color' => 'text-orange-400'],
        ['label' => 'Valor pendente',    'value' => 'R$ ' . number_format($overview['pendingPayoutsAmount'], 2, ',', '.'), 'icon' => 'banknote', 'color' => 'text-red-400'],
    ];
    foreach ($kpis as $i => $kpi): ?>
      <div class="bg-blackx2 border border-blackx3 rounded-xl p-4 animate-fade-in-up stagger-<?= $i+1 ?>">
        <div class="flex items-center gap-2 mb-2">
          <i data-lucide="<?= $kpi['icon'] ?>" class="w-4 h-4 <?= $kpi['color'] ?>"></i>
          <span class="text-[11px] text-zinc-500 uppercase tracking-wider"><?= $kpi['label'] ?></span>
        </div>
        <div class="text-lg font-bold <?= $kpi['color'] ?>"><?= $kpi['value'] ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Tabs -->
  <div class="flex gap-1 border-b border-blackx3 overflow-x-auto">
    <?php foreach ([['afiliados','Afiliados','users'],['conversoes','Conversões','shopping-cart'],['saques','Saques','wallet']] as $t): ?>
      <a href="?tab=<?= $t[0] ?>" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 transition whitespace-nowrap <?= $tab === $t[0] ? 'border-greenx text-greenx' : 'border-transparent text-zinc-400 hover:text-white hover:border-zinc-600' ?>">
        <i data-lucide="<?= $t[2] ?>" class="w-4 h-4"></i> <?= $t[1] ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- TAB: Afiliados -->
  <?php if ($tab === 'afiliados'): ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl overflow-hidden">
    <!-- Filters -->
    <div class="p-4 border-b border-blackx3 flex flex-wrap gap-2 items-center">
      <form class="flex-1 min-w-[200px]">
        <input type="hidden" name="tab" value="afiliados">
        <div class="relative">
          <i data-lucide="search" class="w-4 h-4 text-zinc-500 absolute left-3 top-1/2 -translate-y-1/2"></i>
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por nome, email ou código..."
                 class="w-full bg-blackx border border-blackx3 rounded-xl pl-9 pr-3 py-2 text-sm text-white focus:border-greenx outline-none">
        </div>
      </form>
      <div class="flex gap-1">
        <?php foreach (['' => 'Todos', 'pendente' => 'Pendentes', 'ativo' => 'Ativos', 'suspenso' => 'Suspensos'] as $fv => $fl): ?>
          <a href="?tab=afiliados&status=<?= $fv ?>&q=<?= urlencode($search) ?>"
             class="px-3 py-1.5 rounded-lg text-xs font-medium border transition <?= $statusFilter === $fv ? 'bg-greenx/15 border-greenx text-greenx' : 'border-blackx3 text-zinc-400 hover:text-white' ?>">
            <?= $fl ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-[11px] uppercase tracking-wider text-zinc-500 border-b border-blackx3">
            <th class="text-left px-4 py-3">Afiliado</th>
            <th class="text-left px-4 py-3">Código</th>
            <th class="text-center px-4 py-3">Status</th>
            <th class="text-center px-4 py-3">Taxa</th>
            <th class="text-center px-4 py-3">Cliques</th>
            <th class="text-center px-4 py-3">Conversões</th>
            <th class="text-right px-4 py-3">Ganhos</th>
            <th class="text-right px-4 py-3">Pago</th>
            <th class="text-center px-4 py-3">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-blackx3/50">
          <?php if (empty($affiliates['items'])): ?>
            <tr><td colspan="9" class="px-4 py-8 text-center text-zinc-600">Nenhum afiliado encontrado.</td></tr>
          <?php endif; ?>
          <?php foreach ($affiliates['items'] as $aff): ?>
            <tr class="hover:bg-blackx/50 transition">
              <td class="px-4 py-3">
                <div class="font-medium"><?= htmlspecialchars($aff['nome'] ?? '') ?></div>
                <div class="text-xs text-zinc-500"><?= htmlspecialchars($aff['email'] ?? '') ?></div>
              </td>
              <td class="px-4 py-3">
                <code class="bg-blackx border border-blackx3 rounded px-2 py-0.5 text-xs text-greenx font-mono"><?= htmlspecialchars($aff['referral_code']) ?></code>
              </td>
              <td class="px-4 py-3 text-center">
                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium border <?= $statusBadge($aff['status']) ?>">
                  <?= ucfirst($aff['status']) ?>
                </span>
              </td>
              <td class="px-4 py-3 text-center text-xs">
                <?= $aff['custom_rate'] !== null ? number_format((float)$aff['custom_rate'], 1) . '%' : '<span class="text-zinc-600">Global</span>' ?>
              </td>
              <td class="px-4 py-3 text-center"><?= number_format((int)$aff['total_clicks']) ?></td>
              <td class="px-4 py-3 text-center"><?= number_format((int)$aff['total_conversions']) ?></td>
              <td class="px-4 py-3 text-right text-greenx font-medium">R$ <?= number_format((float)$aff['total_earned'], 2, ',', '.') ?></td>
              <td class="px-4 py-3 text-right text-zinc-400">R$ <?= number_format((float)$aff['total_paid'], 2, ',', '.') ?></td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-center gap-1" x-data="{ open: false }">
                  <button @click="open = !open" class="rounded-lg border border-blackx3 hover:border-greenx px-2 py-1 text-xs transition">
                    <i data-lucide="more-horizontal" class="w-3.5 h-3.5"></i>
                  </button>
                  <div x-show="open" @click.away="open = false" x-cloak
                       class="absolute right-8 z-50 bg-blackx2 border border-blackx3 rounded-xl shadow-2xl p-2 space-y-1 min-w-[180px]">
                    <?php if ($aff['status'] === 'pendente'): ?>
                      <form method="post" class="w-full">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="affiliate_id" value="<?= $aff['id'] ?>">
                        <button class="w-full text-left px-3 py-1.5 text-xs rounded-lg hover:bg-greenx/15 text-greenx transition">✓ Aprovar</button>
                      </form>
                      <form method="post" class="w-full">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="affiliate_id" value="<?= $aff['id'] ?>">
                        <button class="w-full text-left px-3 py-1.5 text-xs rounded-lg hover:bg-red-500/15 text-red-300 transition">✗ Rejeitar</button>
                      </form>
                    <?php elseif ($aff['status'] === 'ativo'): ?>
                      <form method="post" class="w-full">
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="affiliate_id" value="<?= $aff['id'] ?>">
                        <button class="w-full text-left px-3 py-1.5 text-xs rounded-lg hover:bg-yellow-500/15 text-yellow-300 transition">⏸ Suspender</button>
                      </form>
                    <?php elseif ($aff['status'] === 'suspenso' || $aff['status'] === 'rejeitado'): ?>
                      <form method="post" class="w-full">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="affiliate_id" value="<?= $aff['id'] ?>">
                        <button class="w-full text-left px-3 py-1.5 text-xs rounded-lg hover:bg-greenx/15 text-greenx transition">✓ Reativar</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" class="w-full" x-data="{ showRate: false }">
                      <input type="hidden" name="action" value="set_rate">
                      <input type="hidden" name="affiliate_id" value="<?= $aff['id'] ?>">
                      <button type="button" @click="showRate = !showRate" class="w-full text-left px-3 py-1.5 text-xs rounded-lg hover:bg-blackx text-zinc-300 transition">⚙ Taxa personalizada</button>
                      <div x-show="showRate" class="px-3 py-1.5 flex gap-1">
                        <input type="number" name="custom_rate" step="0.5" min="0" max="50" placeholder="% ou vazio = global"
                               class="flex-1 bg-blackx border border-blackx3 rounded-lg px-2 py-1 text-xs text-white">
                        <button type="submit" class="px-2 py-1 rounded-lg bg-greenx text-white text-xs font-semibold">OK</button>
                      </div>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php
      $paginaAtual  = (int)$affiliates['page'];
      $totalPaginas = (int)$affiliates['pages'];
      include __DIR__ . '/../../views/partials/pagination.php';
    ?>
  </div>

  <!-- Top affiliates -->
  <?php if ($topAffs): ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h3 class="text-sm font-semibold mb-3 flex items-center gap-2"><i data-lucide="trophy" class="w-4 h-4 text-yellow-400"></i> Top Afiliados</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <?php foreach ($topAffs as $i => $ta): ?>
        <div class="p-3 rounded-xl bg-blackx border border-blackx3">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-7 h-7 rounded-full bg-gradient-to-br from-greenx to-greenxd flex items-center justify-center text-xs font-bold text-white"><?= $i+1 ?></div>
            <div class="min-w-0 flex-1">
              <div class="text-xs font-medium truncate"><?= htmlspecialchars($ta['nome'] ?? '') ?></div>
              <div class="text-[10px] text-zinc-600 font-mono"><?= $ta['referral_code'] ?></div>
            </div>
          </div>
          <div class="text-sm font-bold text-greenx">R$ <?= number_format((float)$ta['total_earned'], 2, ',', '.') ?></div>
          <div class="text-[10px] text-zinc-500"><?= $ta['total_conversions'] ?> conversões • <?= $ta['total_clicks'] ?> cliques</div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- TAB: Conversões -->
  <?php if ($tab === 'conversoes'): ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl overflow-hidden">
    <div class="p-4 border-b border-blackx3 flex items-center gap-2">
      <span class="text-sm font-semibold flex-1">Conversões</span>
      <?php foreach (['' => 'Todas', 'pendente' => 'Pendentes', 'aprovada' => 'Aprovadas', 'paga' => 'Pagas', 'cancelada' => 'Canceladas'] as $fv => $fl): ?>
        <a href="?tab=conversoes&conv_status=<?= $fv ?>"
           class="px-3 py-1 rounded-lg text-xs font-medium border transition <?= $convFilter === $fv ? 'bg-greenx/15 border-greenx text-greenx' : 'border-blackx3 text-zinc-400 hover:text-white' ?>">
          <?= $fl ?>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-[11px] uppercase tracking-wider text-zinc-500 border-b border-blackx3">
            <th class="text-left px-4 py-3">Afiliado</th>
            <th class="text-center px-4 py-3">Pedido</th>
            <th class="text-right px-4 py-3">Total pedido</th>
            <th class="text-center px-4 py-3">Taxa</th>
            <th class="text-right px-4 py-3">Comissão</th>
            <th class="text-center px-4 py-3">Status</th>
            <th class="text-center px-4 py-3">Data</th>
            <th class="text-center px-4 py-3">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-blackx3/50">
          <?php if (empty($convRows)): ?>
            <tr><td colspan="8" class="px-4 py-8 text-center text-zinc-600">Nenhuma conversão encontrada.</td></tr>
          <?php endif; ?>
          <?php foreach ($convRows as $c): ?>
            <tr class="hover:bg-blackx/50 transition">
              <td class="px-4 py-3">
                <div class="text-xs font-medium"><?= htmlspecialchars($c['aff_nome'] ?? '') ?></div>
                <div class="text-[10px] text-zinc-500 font-mono"><?= $c['referral_code'] ?? '' ?></div>
              </td>
              <td class="px-4 py-3 text-center text-xs">#<?= $c['order_id'] ?></td>
              <td class="px-4 py-3 text-right text-xs">R$ <?= number_format((float)$c['order_total'], 2, ',', '.') ?></td>
              <td class="px-4 py-3 text-center text-xs"><?= number_format((float)$c['commission_rate'], 1) ?>%</td>
              <td class="px-4 py-3 text-right font-medium text-greenx text-xs">R$ <?= number_format((float)$c['commission_amount'], 2, ',', '.') ?></td>
              <td class="px-4 py-3 text-center">
                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium border <?= $statusBadge($c['status']) ?>"><?= ucfirst($c['status']) ?></span>
              </td>
              <td class="px-4 py-3 text-center text-xs text-zinc-500"><?= fmtDate($c['created_at']) ?></td>
              <td class="px-4 py-3 text-center">
                <?php if ($c['status'] === 'pendente'): ?>
                  <div class="flex gap-1 justify-center">
                    <form method="post"><input type="hidden" name="action" value="approve_conversion"><input type="hidden" name="conversion_id" value="<?= $c['id'] ?>">
                      <button class="rounded-lg bg-greenx/15 border border-greenx/30 text-greenx px-2 py-1 text-xs hover:bg-greenx/25 transition">✓</button>
                    </form>
                    <form method="post"><input type="hidden" name="action" value="cancel_conversion"><input type="hidden" name="conversion_id" value="<?= $c['id'] ?>">
                      <button class="rounded-lg bg-red-500/15 border border-red-400/30 text-red-300 px-2 py-1 text-xs hover:bg-red-500/25 transition">✗</button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="text-zinc-600 text-xs">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
    $paginaAtual  = $convPage;
    $totalPaginas = $convPages;
    include __DIR__ . '/../../views/partials/pagination.php';
  ?>
  <?php endif; ?>

  <!-- TAB: Saques -->
  <?php if ($tab === 'saques'): ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl overflow-hidden">
    <div class="p-4 border-b border-blackx3 flex items-center gap-2">
      <span class="text-sm font-semibold flex-1">Saques de Afiliados</span>
      <?php foreach (['' => 'Todos', 'pendente' => 'Pendentes', 'aprovado' => 'Aprovados', 'rejeitado' => 'Rejeitados'] as $fv => $fl): ?>
        <a href="?tab=saques&pay_status=<?= $fv ?>"
           class="px-3 py-1 rounded-lg text-xs font-medium border transition <?= $payFilter === $fv ? 'bg-greenx/15 border-greenx text-greenx' : 'border-blackx3 text-zinc-400 hover:text-white' ?>">
          <?= $fl ?>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-[11px] uppercase tracking-wider text-zinc-500 border-b border-blackx3">
            <th class="text-left px-4 py-3">Afiliado</th>
            <th class="text-right px-4 py-3">Valor</th>
            <th class="text-left px-4 py-3">Chave PIX</th>
            <th class="text-center px-4 py-3">Status</th>
            <th class="text-center px-4 py-3">Solicitado</th>
            <th class="text-center px-4 py-3">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-blackx3/50">
          <?php if (empty($payouts['items'])): ?>
            <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-600">Nenhum saque encontrado.</td></tr>
          <?php endif; ?>
          <?php foreach ($payouts['items'] as $po): ?>
            <tr class="hover:bg-blackx/50 transition">
              <td class="px-4 py-3">
                <div class="text-xs font-medium"><?= htmlspecialchars($po['nome'] ?? '') ?></div>
                <div class="text-[10px] text-zinc-500"><?= htmlspecialchars($po['email'] ?? '') ?></div>
              </td>
              <td class="px-4 py-3 text-right font-bold text-greenx">R$ <?= number_format((float)$po['amount'], 2, ',', '.') ?></td>
              <td class="px-4 py-3 text-xs">
                <span class="text-zinc-500"><?= strtoupper($po['pix_key_type'] ?? '') ?>:</span> <?= htmlspecialchars($po['pix_key'] ?? '') ?>
              </td>
              <td class="px-4 py-3 text-center">
                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium border <?= $statusBadge($po['status']) ?>"><?= ucfirst($po['status']) ?></span>
              </td>
              <td class="px-4 py-3 text-center text-xs text-zinc-500"><?= fmtDate($po['requested_at'] ?? '') ?></td>
              <td class="px-4 py-3 text-center">
                <?php if ($po['status'] === 'pendente'): ?>
                  <div class="flex gap-1 justify-center">
                    <form method="post"><input type="hidden" name="action" value="approve_payout"><input type="hidden" name="payout_id" value="<?= $po['id'] ?>">
                      <button class="rounded-lg bg-greenx/15 border border-greenx/30 text-greenx px-2.5 py-1 text-xs hover:bg-greenx/25 transition">Aprovar</button>
                    </form>
                    <form method="post" x-data="{ show: false }">
                      <input type="hidden" name="action" value="reject_payout"><input type="hidden" name="payout_id" value="<?= $po['id'] ?>">
                      <button type="button" @click="show = true" class="rounded-lg bg-red-500/15 border border-red-400/30 text-red-300 px-2.5 py-1 text-xs hover:bg-red-500/25 transition">Rejeitar</button>
                      <div x-show="show" class="mt-1 flex gap-1">
                        <input type="text" name="admin_notes" placeholder="Motivo..." class="flex-1 bg-blackx border border-blackx3 rounded-lg px-2 py-1 text-xs text-white">
                        <button type="submit" class="px-2 py-1 rounded-lg bg-red-500 text-white text-xs">OK</button>
                      </div>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="text-zinc-600 text-xs"><?= $po['processed_at'] ? fmtDate($po['processed_at']) : '—' ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
    $paginaAtual  = $payPage;
    $totalPaginas = (int)$payouts['pages'];
    include __DIR__ . '/../../views/partials/pagination.php';
  ?>
  <?php endif; ?>

</div>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
