<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';

exigirLogin();

$conn = (new Database())->connect();
$uid = (int)($_SESSION['user_id'] ?? 0);

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

/* ---------- filter params ---------- */
$fStatus = trim((string)($_GET['status'] ?? ''));
$fDe     = trim((string)($_GET['de'] ?? ''));
$fAte    = trim((string)($_GET['ate'] ?? ''));
$allowedDepStatus = ['pendente', 'pago', 'cancelado', 'expired'];
if ($fStatus !== '' && !in_array($fStatus, $allowedDepStatus, true)) $fStatus = '';

/* ---------- build filtered query ---------- */
$where = "user_id = ? AND external_ref LIKE 'wallet_topup:%'";
$types = 'i';
$params = [$uid];

if ($fStatus !== '') { $where .= " AND status = ?"; $types .= 's'; $params[] = $fStatus; }
if ($fDe !== '')     { $where .= " AND DATE(created_at) >= ?"; $types .= 's'; $params[] = $fDe; }
if ($fAte !== '')    { $where .= " AND DATE(created_at) <= ?"; $types .= 's'; $params[] = $fAte; }

/* count */
$stC = $conn->prepare("SELECT COUNT(*) FROM payment_transactions WHERE $where");
$cBind = array_merge([$types], $params);
$cRefs = [];
foreach ($cBind as $k => $v) $cRefs[$k] = &$cBind[$k];
call_user_func_array([$stC, 'bind_param'], $cRefs);
$stC->execute();
$totalRegistros = (int)$stC->get_result()->fetch_row()[0];
$stC->close();

$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;
$pagina = max(1, (int)($_GET['p'] ?? 1));
$totalPaginas = max(1, (int)ceil($totalRegistros / $pp));

/* data page */
$sql = "SELECT id, provider_transaction_id, status, amount_centavos, created_at
        FROM payment_transactions WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?";
$types2 = $types . 'ii';
$params2 = array_merge($params, [$pp, ($pagina - 1) * $pp]);
$st = $conn->prepare($sql);
$bind2 = array_merge([$types2], $params2);
$refs2 = [];
foreach ($bind2 as $k => $v) $refs2[$k] = &$bind2[$k];
call_user_func_array([$st, 'bind_param'], $refs2);
$st->execute();
$depositos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$pageTitle = 'Financeiro';
$activeMenu = 'financeiro';
$finTab = 'depositos';

/* summary stats (all records, no filter) */
$stAll = $conn->prepare("SELECT status, amount_centavos FROM payment_transactions WHERE user_id = ? AND external_ref LIKE 'wallet_topup:%'");
$stAll->bind_param('i', $uid);
$stAll->execute();
$allDeps = $stAll->get_result()->fetch_all(MYSQLI_ASSOC);
$stAll->close();
$totalDepositos = 0; $totalPagos = 0; $totalPendentes = 0;
foreach ($allDeps as $d) {
    $amt = ((int)($d['amount_centavos'] ?? 0)) / 100;
    $st2 = strtolower(trim((string)($d['status'] ?? '')));
    $totalDepositos += $amt;
    if (in_array($st2, ['pago', 'paid', 'aprovado', 'approved'], true)) $totalPagos += $amt;
    if (in_array($st2, ['pendente', 'pending'], true)) $totalPendentes += $amt;
}

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/user_layout_start.php';
?>

<div class="space-y-4">
  <?php include __DIR__ . '/../views/partials/financeiro_tabs.php'; ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h2 class="text-lg font-semibold mb-1">Meus depósitos</h2>
    <p class="text-sm text-zinc-400 mb-4">Listagem das suas recargas de carteira via PIX.</p>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="w-10 h-10 rounded-xl bg-greenx/15 border border-greenx/30 flex items-center justify-center flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-purple-400"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div>
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide">Total depositado</p>
          <p class="font-bold text-sm">R$ <?= number_format($totalDepositos, 2, ',', '.') ?></p>
        </div>
      </div>
      <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="w-10 h-10 rounded-xl bg-greenx/15 border border-greenx/30 flex items-center justify-center flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-greenx"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div>
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide">Confirmados</p>
          <p class="font-bold text-sm text-greenx">R$ <?= number_format($totalPagos, 2, ',', '.') ?></p>
        </div>
      </div>
      <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="w-10 h-10 rounded-xl bg-orange-500/15 border border-orange-400/30 flex items-center justify-center flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-orange-400"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide">Pendentes</p>
          <p class="font-bold text-sm text-orange-300">R$ <?= number_format($totalPendentes, 2, ',', '.') ?></p>
        </div>
      </div>
    </div>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:w-44">
          <label class="block text-xs text-zinc-500 mb-1">Status</label>
          <select name="status" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx">
            <option value="">Todos</option>
            <?php foreach ($allowedDepStatus as $s): ?>
              <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">De</label>
          <input type="date" name="de" value="<?= htmlspecialchars($fDe, ENT_QUOTES, 'UTF-8') ?>"
                 class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx">
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Até</label>
          <input type="date" name="ate" value="<?= htmlspecialchars($fAte, ENT_QUOTES, 'UTF-8') ?>"
                 class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx">
        </div>
        <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Filtrar
          </button>
          <a href="<?= BASE_PATH ?>/depositos" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
      <div class="mt-3 md:hidden flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
          <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Filtrar
        </button>
        <a href="<?= BASE_PATH ?>/depositos" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
        </a>
      </div>
    </form>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-3 pr-3">ID</th>
            <th class="text-left py-3 pr-3">Transação</th>
            <th class="text-left py-3 pr-3">Valor</th>
            <th class="text-left py-3 pr-3">Status</th>
            <th class="text-left py-3">Data</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($depositos as $d): ?>
            <?php
              $trxId = (string)($d['provider_transaction_id'] ?? '');
              if ($trxId === '') { $trxId = 'TRX' . str_pad((string)$d['id'], 6, '0', STR_PAD_LEFT); }
              $dataBr = date('d/m/Y H:i', strtotime((string)$d['created_at']));
              $valorDep = ((int)$d['amount_centavos']) / 100;
            ?>
            <tr class="border-b border-blackx3/50 hover:bg-blackx/40 transition">
              <td class="py-3 pr-3 font-medium">#<?= (int)$d['id'] ?></td>
              <td class="py-3 pr-3"><span class="font-mono text-xs bg-blackx border border-blackx3 rounded-lg px-2 py-1"><?= htmlspecialchars($trxId, ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="py-3 pr-3 font-semibold text-greenx">R$ <?= number_format($valorDep, 2, ',', '.') ?></td>
              <td class="py-3 pr-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusBadge((string)$d['status']) ?>"><?= htmlspecialchars(ucfirst((string)$d['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="py-3 text-zinc-400"><?= $dataBr ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$depositos): ?>
            <tr><td colspan="5" class="py-6 text-zinc-500 text-center">Nenhum depósito encontrado.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php
      $paginaAtual = $pagina;
      include __DIR__ . '/../views/partials/pagination.php';
    ?>
  </div>
</div>

<?php
include __DIR__ . '/../views/partials/user_layout_end.php';
include __DIR__ . '/../views/partials/footer.php';
