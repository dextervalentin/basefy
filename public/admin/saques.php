<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/wallet_portal.php';

exigirAdmin();

$conn = (new Database())->connect();
$adminId = (int)($_SESSION['user_id'] ?? 0);

$msg = '';
$err = '';

function parseMoneyBRLAdmin(string $raw): float
{
  $clean = preg_replace('/[^\d,\.]/', '', $raw) ?? '';
  if ($clean === '') {
    return 0.0;
  }
  if (str_contains($clean, ',')) {
    $clean = str_replace('.', '', $clean);
    $clean = str_replace(',', '.', $clean);
  }
  return (float)$clean;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'approve_withdrawal') {
    $withdrawalId = (int)($_POST['withdrawal_id'] ?? 0);
    [$ok, $m] = walletAprovarSaqueAdmin($conn, $withdrawalId, $adminId);
    if ($ok) {
      $msg = $m;
    } else {
      $err = $m;
    }
  }

  if ($action === 'reject_withdrawal') {
    $withdrawalId = (int)($_POST['withdrawal_id'] ?? 0);
    $noRefund = (string)($_POST['no_refund'] ?? '') === '1';
    [$ok, $m] = walletRejeitarSaqueAdmin($conn, $withdrawalId, $adminId, !$noRefund);
    if ($ok) {
      $msg = $m;
    } else {
      $err = $m;
    }
  }

  if ($action === 'add_observation') {
    $withdrawalId = (int)($_POST['withdrawal_id'] ?? 0);
    $novaObs = trim((string)($_POST['nova_obs'] ?? ''));
    [$ok, $m] = walletAdminAdicionarObservacao($conn, $withdrawalId, $adminId, $novaObs);
    if ($ok) {
      $msg = $m;
    } else {
      $err = $m;
    }
  }

  if ($action === 'withdraw_now') {
    $valor = parseMoneyBRLAdmin((string)($_POST['valor'] ?? '0'));
    $pix = trim((string)($_POST['pix_key'] ?? ''));
    $obs = trim((string)($_POST['observacao'] ?? ''));
    $tipoChave = trim((string)($_POST['tipo_chave'] ?? ''));
    [$ok, $m] = walletSaqueImediatoAdmin($conn, $adminId, $valor, $pix, $obs, $tipoChave);
    if ($ok) {
      $msg = $m;
    } else {
      $err = $m;
    }
    }
}

/* ── Tab + Pagination ── */
$tab = strtolower(trim((string)($_GET['tab'] ?? 'pendentes')));
if (!in_array($tab, ['pendentes', 'aprovados', 'todos'], true)) $tab = 'pendentes';
$statusFilter = match($tab) {
    'pendentes' => ['pendente'],
    'aprovados' => ['pago', 'processando'],
    default     => ['pendente', 'pago', 'processando'],
};
$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;
$lista = walletListarSaquesPaginado($conn, $statusFilter, $pagina, $pp);

/* summary stats (lightweight count queries) */
$_allPend = walletListarSaquesPorStatus($conn, ['pendente'], 500);
$_allAprov = walletListarSaquesPorStatus($conn, ['pago', 'processando'], 500);
$allForStats = array_merge($_allPend, $_allAprov);
$totalSaquesAdmin = 0; $totalAprovadosAdmin = 0; $totalPendentesAdmin = 0;
foreach ($allForStats as $sw) {
    $valSaq = (float)($sw['valor'] ?? 0);
    $stSaq  = strtolower(trim((string)($sw['status'] ?? '')));
    $totalSaquesAdmin += $valSaq;
    if (in_array($stSaq, ['pago', 'paid', 'aprovado', 'approved', 'processando'], true)) $totalAprovadosAdmin += $valSaq;
    if (in_array($stSaq, ['pendente', 'pending'], true)) $totalPendentesAdmin += $valSaq;
}

$statusBadge = static function (string $status): string {
  $s = strtolower(trim($status));
  if (in_array($s, ['pago', 'paid', 'aprovado', 'approved'], true)) {
    return 'bg-greenx/15 border border-greenx/40 text-greenx';
  }
  if (in_array($s, ['pendente', 'pending', 'processando', 'processing'], true)) {
    return 'bg-orange-500/15 border border-orange-400/40 text-orange-300';
  }
  return 'bg-blackx border border-blackx3 text-zinc-300';
};

$pageTitle = 'Financeiro';
$activeMenu = 'financeiro';
$adminFinanceTab = 'saques';
$subnavItems = [['label' => 'Solicitações e aprovados', 'href' => 'saques', 'active' => true]];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="space-y-4" x-data="{ obsModal: false, obsText: '', obsTrx: '', obsId: 0 }">
  <?php include __DIR__ . '/../../views/partials/admin_financeiro_tabs.php'; ?>
  <?php if ($msg): ?><div class="rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h2 class="text-lg font-semibold mb-3">Visão geral de saques</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="w-10 h-10 rounded-xl bg-greenx/15 border border-greenx/30 flex items-center justify-center flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-purple-400"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div>
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide">Total solicitado</p>
          <p class="font-bold text-sm">R$ <?= number_format($totalSaquesAdmin, 2, ',', '.') ?></p>
        </div>
      </div>
      <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="w-10 h-10 rounded-xl bg-greenx/15 border border-greenx/30 flex items-center justify-center flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-greenx"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div>
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide">Aprovados / Pagos</p>
          <p class="font-bold text-sm text-greenx">R$ <?= number_format($totalAprovadosAdmin, 2, ',', '.') ?></p>
        </div>
      </div>
      <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="w-10 h-10 rounded-xl bg-orange-500/15 border border-orange-400/30 flex items-center justify-center flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-orange-400"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide">Pendentes</p>
          <p class="font-bold text-sm text-orange-300">R$ <?= number_format($totalPendentesAdmin, 2, ',', '.') ?></p>
        </div>
      </div>
    </div>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <h3 class="font-semibold mb-3">Saque imediato (sem aprovação)</h3>
    <form method="post" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2" x-data="{ tipoChave: '', pixKey: '' }">
      <input type="hidden" name="action" value="withdraw_now">
      <input type="text" name="valor" placeholder="R$ 0,00" class="js-money rounded-lg bg-blackx border border-blackx3 px-3 py-2" required>
      <select name="tipo_chave" x-model="tipoChave" @change="pixKey = ''" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2" required>
        <option value="">Tipo chave</option>
        <option value="CPF">CPF</option>
        <option value="CNPJ">CNPJ</option>
        <option value="Email">Email</option>
        <option value="Telefone">Telefone</option>
        <option value="Aleatoria">Aleatória</option>
      </select>
      <input type="text" name="pix_key" x-model="pixKey" @input="pixKey = applyPixMask(tipoChave, $event.target.value)"
             :placeholder="tipoChave === 'CPF' ? '000.000.000-00' : tipoChave === 'CNPJ' ? '00.000.000/0000-00' : tipoChave === 'Email' ? 'email@exemplo.com' : tipoChave === 'Telefone' ? '(00) 00000-0000' : 'Selecione o tipo'"
             :maxlength="tipoChave === 'CPF' ? 14 : tipoChave === 'CNPJ' ? 18 : tipoChave === 'Telefone' ? 15 : 100"
             :disabled="tipoChave === ''"
             class="rounded-lg bg-blackx border border-blackx3 px-3 py-2 disabled:opacity-40 disabled:cursor-not-allowed" required>
      <input type="text" name="observacao" placeholder="Observação (opcional)" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
      <button class="rounded-lg bg-greenx hover:bg-greenx2 text-black font-semibold px-4 py-2">Sacar agora</button>
    </form>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <!-- Tab pills -->
    <div class="flex items-center gap-2 mb-3">
      <?php
        $tabUrl = function(string $t) use ($pp): string {
          $qs = $_GET; $qs['tab'] = $t; $qs['p'] = 1; $qs['pp'] = $pp;
          return '?' . http_build_query($qs);
        };
      ?>
      <a href="<?= $tabUrl('pendentes') ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition <?= $tab === 'pendentes' ? 'bg-orange-500/20 border border-orange-400/40 text-orange-300' : 'border border-blackx3 text-zinc-400 hover:border-zinc-500' ?>">Pendentes</a>
      <a href="<?= $tabUrl('aprovados') ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition <?= $tab === 'aprovados' ? 'bg-greenx/20 border border-greenx/40 text-greenx' : 'border border-blackx3 text-zinc-400 hover:border-zinc-500' ?>">Aprovados</a>
      <a href="<?= $tabUrl('todos') ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition <?= $tab === 'todos' ? 'bg-greenx/20 border border-greenx/40 text-purple-300' : 'border border-blackx3 text-zinc-400 hover:border-zinc-500' ?>">Todos</a>
      <span class="ml-auto text-xs text-zinc-500"><?= $lista['total'] ?> registro<?= $lista['total'] !== 1 ? 's' : '' ?></span>
    </div>
    <div class="overflow-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-zinc-400 border-b border-blackx3">
          <th class="text-left py-2">ID</th>
          <th class="text-left py-2">Transação</th>
          <th class="text-left py-2">Usuário</th>
          <th class="text-left py-2">Valor</th>
          <th class="text-left py-2">Status</th>
          <th class="text-left py-2">PIX</th>
          <th class="text-left py-2">Obs</th>
          <th class="text-left py-2">Data</th>
          <?php if ($tab !== 'aprovados'): ?><th class="text-left py-2">Ação</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($lista['itens'] as $row): ?>
        <?php
          $trxId = (string)($row['transaction_id'] ?? '');
          if ($trxId === '') { $trxId = 'TRX' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT); }
          $rObs = trim((string)($row['observacao'] ?? ''));
          $rObsClean = preg_replace('/\s*\|\s*(aprovado_manual_por=\d+|blackcat_withdrawal_id=\S+)/i', '', $rObs) ?? $rObs;
          $rTipoChave = (string)($row['tipo_chave'] ?? '');
          $isPendente = in_array(strtolower(trim((string)$row['status'])), ['pendente', 'pending'], true);
        ?>
        <tr class="border-b border-blackx3/60 hover:bg-blackx/40 transition">
          <td class="py-2">#<?= (int)$row['id'] ?></td>
          <td class="py-2"><span class="font-mono text-xs bg-blackx border border-blackx3 rounded-lg px-2 py-0.5"><?= htmlspecialchars($trxId, ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="py-2"><?= htmlspecialchars((string)$row['user_nome'], ENT_QUOTES, 'UTF-8') ?><br><span class="text-xs text-zinc-500"><?= htmlspecialchars((string)$row['user_email'], ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="py-2">R$ <?= number_format((float)$row['valor'], 2, ',', '.') ?></td>
          <td class="py-2"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusBadge((string)$row['status']) ?>"><?= htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="py-2"><?php if ($rTipoChave !== ''): ?><span class="text-xs text-zinc-500">(<?= htmlspecialchars($rTipoChave, ENT_QUOTES, 'UTF-8') ?>)</span> <?php endif; ?><?= htmlspecialchars((string)$row['chave_pix'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="py-2">
            <button type="button"
              @click="obsText = <?= htmlspecialchars(json_encode($rObsClean, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>; obsTrx = '<?= htmlspecialchars($trxId, ENT_QUOTES, 'UTF-8') ?>'; obsId = <?= (int)$row['id'] ?>; obsModal = true"
              class="px-2 py-1 rounded-lg text-xs font-medium bg-blackx border border-blackx3 hover:border-greenx/40 transition">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline mr-0.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              <?= trim($rObsClean) !== '' ? 'Ver' : '+' ?>
            </button>
          </td>
          <td class="py-2"><?= fmtDate((string)$row['criado_em']) ?></td>
          <?php if ($tab !== 'aprovados'): ?>
          <td class="py-2">
            <?php if ($isPendente): ?>
            <div class="flex items-center gap-1.5">
              <form method="post">
                <input type="hidden" name="action" value="approve_withdrawal">
                <input type="hidden" name="withdrawal_id" value="<?= (int)$row['id'] ?>">
                <button class="rounded-lg bg-greenx hover:bg-greenx2 text-black font-semibold px-3 py-1 text-xs">Confirmar</button>
              </form>
              <form method="post" onsubmit="return confirm('Rejeitar este saque e estornar o saldo para o usuário?');">
                <input type="hidden" name="action" value="reject_withdrawal">
                <input type="hidden" name="withdrawal_id" value="<?= (int)$row['id'] ?>">
                <button class="rounded-lg bg-red-500/90 hover:bg-red-500 text-white font-semibold px-3 py-1 text-xs">Rejeitar</button>
              </form>
              <?php if ((int)$row['id'] === 1): ?>
              <form method="post" onsubmit="return confirm('EXCEÇÃO: rejeitar sem estornar saldo. Confirma?');">
                <input type="hidden" name="action" value="reject_withdrawal">
                <input type="hidden" name="withdrawal_id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="no_refund" value="1">
                <button class="rounded-lg bg-zinc-700 hover:bg-zinc-600 text-white font-semibold px-3 py-1 text-xs">Rejeitar sem estorno</button>
              </form>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <span class="text-xs text-zinc-600">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$lista['itens']): ?>
          <tr><td colspan="9" class="py-4 text-zinc-500 text-center">Nenhum saque encontrado nesta aba.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php
      $paginaAtual  = (int)$lista['pagina'];
      $totalPaginas = (int)$lista['total_paginas'];
      include __DIR__ . '/../../views/partials/pagination.php';
    ?>
  </div>

  <!-- Observation Modal (Admin can view + add new) -->
  <template x-if="obsModal">
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4" @click.self="obsModal = false" @keydown.escape.window="obsModal = false">
      <div class="w-full max-w-lg bg-blackx2 border border-blackx3 rounded-2xl p-5 space-y-4" @click.stop>
        <div class="flex items-center justify-between">
          <h3 class="font-semibold text-lg">Observações — <span class="font-mono text-sm text-greenx" x-text="obsTrx"></span></h3>
          <button type="button" @click="obsModal = false" class="rounded-lg bg-blackx border border-blackx3 px-3 py-1 text-sm hover:border-red-500/40 transition">Fechar</button>
        </div>
        <div class="bg-blackx border border-blackx3 rounded-xl p-4 text-sm text-zinc-300 whitespace-pre-wrap max-h-48 overflow-y-auto" x-text="obsText || 'Nenhuma observação registrada.'"></div>
        <form method="post" class="space-y-2">
          <input type="hidden" name="action" value="add_observation">
          <input type="hidden" name="withdrawal_id" :value="obsId">
          <textarea name="nova_obs" rows="2" placeholder="Adicionar nova observação..." class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 text-sm" required></textarea>
          <button class="rounded-lg bg-greenx hover:bg-greenx2 text-black font-semibold px-4 py-2 text-sm">Enviar observação</button>
        </form>
      </div>
    </div>
  </template>
</div>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
?>
<script>
  (function () {
    function formatBRL(value) {
      const digits = String(value || '').replace(/\D/g, '');
      const num = (parseInt(digits || '0', 10) / 100);
      return num.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    document.querySelectorAll('.js-money').forEach(function (el) {
      if (!el.value) el.value = 'R$ 0,00';
      el.addEventListener('input', function () { el.value = formatBRL(el.value); });
      el.addEventListener('focus', function () {
        if (el.value.trim() === '') el.value = 'R$ 0,00';
      });
    });
  })();

  /* ── PIX key mask ── */
  function applyPixMask(tipo, val) {
    const d = val.replace(/\D/g, '');
    if (tipo === 'CPF') return d.replace(/(\d{3})(\d{0,3})(\d{0,3})(\d{0,2})/, function(_, a, b, c, e) { return a + (b ? '.' + b : '') + (c ? '.' + c : '') + (e ? '-' + e : ''); });
    if (tipo === 'CNPJ') return d.replace(/(\d{2})(\d{0,3})(\d{0,3})(\d{0,4})(\d{0,2})/, function(_, a, b, c, e, f) { return a + (b ? '.' + b : '') + (c ? '.' + c : '') + (e ? '/' + e : '') + (f ? '-' + f : ''); });
    if (tipo === 'Telefone') return d.replace(/(\d{2})(\d{0,5})(\d{0,4})/, function(_, a, b, c) { return '(' + a + ')' + (b ? ' ' + b : '') + (c ? '-' + c : ''); });
    return val;
  }
</script>
