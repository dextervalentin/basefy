<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\saques.php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/vendor_portal.php';

exigirVendedor();
$conn = (new Database())->connect();

// Verification gate — withdrawals require completed profile
$_uid_check = (int)($_SESSION['user_id'] ?? 0);
if (!contaVerificada($_uid_check)) {
    $_SESSION['flash_error'] = 'Para solicitar saques, complete a verificação da sua conta.';
    header('Location: ' . BASE_PATH . '/verificacao');
    exit;
}

$activeMenu = 'financeiro';
$pageTitle  = 'Financeiro';
$finTab     = 'saques';
$uid = (int)($_SESSION['user_id'] ?? 0);

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));

function saqCols($conn, string $table): array {
    $out = [];
    $rs = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($rs) while ($r = $rs->fetch_assoc()) $out[] = strtolower((string)$r['Field']);
    return $out;
}
function saqPick(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) if (in_array(strtolower($c), $cols, true)) return $c;
    return null;
}

$wdCols = saqCols($conn, 'wallet_withdrawals');

$idCol      = saqPick($wdCols, ['id']);
$userCol    = saqPick($wdCols, ['user_id', 'vendedor_id', 'usuario_id']);
$valorCol   = saqPick($wdCols, ['valor', 'amount', 'value']);
$statusCol  = saqPick($wdCols, ['status', 'situacao']);
$pixCol     = saqPick($wdCols, ['pix_chave', 'chave_pix', 'pix_key']);
$obsCol     = saqPick($wdCols, ['observacao', 'descricao', 'motivo', 'note']);
$dataCol    = saqPick($wdCols, ['criado_em', 'created_at', 'data_criacao']);
$tipoChaveCol = saqPick($wdCols, ['tipo_chave']);
$trxCol     = saqPick($wdCols, ['transaction_id']);

if (!$userCol || !$valorCol) {
    http_response_code(500);
    exit('Tabela wallet_withdrawals sem colunas esperadas (user_id/vendedor_id e valor).');
}

$selId     = $idCol ? "`{$idCol}` AS id" : "0 AS id";
$selValor  = "`{$valorCol}` AS valor";
$selStatus = $statusCol ? "`{$statusCol}` AS status" : "'-' AS status";
$selPix    = $pixCol ? "`{$pixCol}` AS pix_chave" : "'' AS pix_chave";
$selObs    = $obsCol ? "`{$obsCol}` AS observacao" : "'' AS observacao";
$selData   = $dataCol ? "`{$dataCol}` AS criado_em" : "'' AS criado_em";
$selTipo   = $tipoChaveCol ? "`{$tipoChaveCol}` AS tipo_chave" : "'' AS tipo_chave";
$selTrx    = $trxCol ? "`{$trxCol}` AS transaction_id" : "'' AS transaction_id";
$rawId     = $idCol ? "`{$idCol}`" : "0";
$rawValor  = "`{$valorCol}`";
$rawPix    = $pixCol ? "`{$pixCol}`" : "''";
$rawObs    = $obsCol ? "`{$obsCol}`" : "''";

$sql = "
SELECT
  {$selId},
  {$selValor},
  {$selStatus},
  {$selPix},
  {$selObs},
  {$selData},
  {$selTipo},
  {$selTrx}
FROM wallet_withdrawals
WHERE `{$userCol}` = ?
";

$types = 'i';
$args = [$uid];

if ($statusFilter !== '' && $statusCol) {
  $sql .= " AND LOWER(`{$statusCol}`) = ?";
  $types .= 's';
  $args[] = $statusFilter;
}

if ($q !== '') {
  $qLike = '%' . $q . '%';
  $sql .= " AND ({$rawPix} LIKE ? OR {$rawObs} LIKE ?";
  $types .= 'ss';
  $args[] = $qLike;
  $args[] = $qLike;

  if (ctype_digit($q) && $idCol) {
    $sql .= " OR {$rawId} = ?";
    $types .= 'i';
    $args[] = (int)$q;
  }

  if (is_numeric(str_replace(',', '.', $q))) {
    $sql .= " OR {$rawValor} = ?";
    $types .= 'd';
    $args[] = (float)str_replace(',', '.', $q);
  }

  $sql .= ")";
}

$sql .= " ORDER BY id DESC";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$args);
$st->execute();
$saques = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;
$pagina = max(1, (int)($_GET['p'] ?? 1));
$totalPaginas = max(1, (int)ceil(count($saques) / $pp));

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

/* summary stats */
$totalSaques    = 0;
$totalAprovados = 0;
$totalPendentes = 0;
foreach ($saques as $sw) {
    $valSaq = (float)($sw['valor'] ?? 0);
    $stSaq  = strtolower(trim((string)($sw['status'] ?? '')));
    $totalSaques += $valSaq;
    if (in_array($stSaq, ['pago', 'paid', 'aprovado', 'approved'], true)) $totalAprovados += $valSaq;
    if (in_array($stSaq, ['pendente', 'pending'], true)) $totalPendentes += $valSaq;
}

$saques = array_slice($saques, ($pagina - 1) * $pp, $pp);

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';
?>

<div class="space-y-5" x-data="{ obsModal: false, obsText: '', obsTrx: '' }">
  <?php include __DIR__ . '/../../views/partials/financeiro_tabs_vendor.php'; ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h2 class="text-lg font-semibold mb-1">Minhas solicitações de saque</h2>
        <p class="text-sm text-zinc-400">Aqui você acompanha seus saques solicitados e aprovados.</p>
      </div>
      <a href="<?= BASE_PATH ?>/vendedor/saque_novo" class="bg-greenx text-white font-semibold rounded-xl px-4 py-2 hover:opacity-90">
        Novo saque
      </a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="w-10 h-10 rounded-xl bg-greenx/15 border border-greenx/30 flex items-center justify-center flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-purple-400"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div>
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide">Total solicitado</p>
          <p class="font-bold text-sm">R$ <?= number_format($totalSaques, 2, ',', '.') ?></p>
        </div>
      </div>
      <div class="flex items-center gap-3 p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="w-10 h-10 rounded-xl bg-greenx/15 border border-greenx/30 flex items-center justify-center flex-shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-greenx"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div>
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide">Aprovados</p>
          <p class="font-bold text-sm text-greenx">R$ <?= number_format($totalAprovados, 2, ',', '.') ?></p>
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
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="ID, valor, chave PIX ou observação" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-44">
          <label class="block text-xs text-zinc-500 mb-1">Status</label>
          <select name="status" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="pendente" <?= $statusFilter === 'pendente' ? 'selected' : '' ?>>Pendente</option>
            <option value="pago" <?= $statusFilter === 'pago' ? 'selected' : '' ?>>Pago</option>
            <option value="aprovado" <?= $statusFilter === 'aprovado' ? 'selected' : '' ?>>Aprovado</option>
            <option value="recusado" <?= $statusFilter === 'recusado' ? 'selected' : '' ?>>Recusado</option>
          </select>
        </div>
        <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i>
            Aplicar
          </button>
          <a href="<?= BASE_PATH ?>/vendedor/saques" title="Limpar filtros" aria-label="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
      <div class="mt-3 md:hidden flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
          <i data-lucide="sliders-horizontal" class="w-4 h-4"></i>
          Aplicar filtros
        </button>
        <a href="<?= BASE_PATH ?>/vendedor/saques" title="Limpar filtros" aria-label="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
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
            <th class="text-left py-3 pr-3">Chave PIX</th>
            <th class="text-left py-3 pr-3">Obs</th>
            <th class="text-left py-3">Data</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($saques as $s): ?>
            <?php
              $obsRaw = (string)($s['observacao'] ?? '');
              $obsClean = preg_replace('/\s*\|\s*aprovado_manual_por=\d+/i', '', $obsRaw) ?? $obsRaw;
              $obsClean = preg_replace('/\s*\|\s*blackcat_withdrawal_id=\S+/i', '', $obsClean) ?? $obsClean;
              $trxId = 'TRX' . str_pad((string)$s['id'], 6, '0', STR_PAD_LEFT);
              $tipoChave = (string)($s['tipo_chave'] ?? '');
            ?>
            <tr class="border-b border-blackx3/50 hover:bg-blackx/40 transition">
              <td class="py-3 pr-3">#<?= (int)$s['id'] ?></td>
              <td class="py-3 pr-3"><span class="font-mono text-xs bg-blackx border border-blackx3 rounded-lg px-2 py-1"><?= htmlspecialchars($trxId, ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="py-3 pr-3 font-medium">R$ <?= number_format((float)$s['valor'], 2, ',', '.') ?></td>
              <td class="py-3 pr-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusBadge((string)$s['status']) ?>"><?= htmlspecialchars((string)$s['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="py-3 pr-3"><?php if ($tipoChave !== ''): ?><span class="text-xs text-zinc-500">(<?= htmlspecialchars($tipoChave, ENT_QUOTES, 'UTF-8') ?>)</span> <?php endif; ?><?= htmlspecialchars((string)$s['pix_chave'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-3 pr-3">
                <?php if (trim($obsClean) !== ''): ?>
                  <button type="button"
                    @click="obsText = <?= htmlspecialchars(json_encode($obsClean, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>; obsTrx = '<?= htmlspecialchars($trxId, ENT_QUOTES, 'UTF-8') ?>'; obsModal = true"
                    class="px-2.5 py-1 rounded-lg text-xs font-medium bg-blackx border border-blackx3 hover:border-greenx/40 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline mr-1"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Ver
                  </button>
                <?php else: ?>
                  <span class="text-zinc-600 text-xs">—</span>
                <?php endif; ?>
              </td>
              <td class="py-3"><?= fmtDate((string)$s['criado_em']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$saques): ?>
            <tr><td colspan="7" class="py-6 text-zinc-500 text-center">Nenhuma solicitação de saque encontrada.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php
      $paginaAtual = $pagina;
      include __DIR__ . '/../../views/partials/pagination.php';
    ?>
  </div>

  <!-- Observation Modal -->
  <template x-if="obsModal">
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4" @click.self="obsModal = false" @keydown.escape.window="obsModal = false">
      <div class="w-full max-w-lg bg-blackx2 border border-blackx3 rounded-2xl p-5 space-y-4" @click.stop>
        <div class="flex items-center justify-between">
          <h3 class="font-semibold text-lg">Observações — <span class="font-mono text-sm text-greenx" x-text="obsTrx"></span></h3>
          <button type="button" @click="obsModal = false" class="rounded-lg bg-blackx border border-blackx3 px-3 py-1 text-sm hover:border-red-500/40 transition">Fechar</button>
        </div>
        <div class="bg-blackx border border-blackx3 rounded-xl p-4 text-sm text-zinc-300 whitespace-pre-wrap max-h-64 overflow-y-auto" x-text="obsText"></div>
      </div>
    </div>
  </template>
</div>

<?php
include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
