<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/wallet_portal.php';
require_once __DIR__ . '/../../src/wallet_escrow.php';

exigirAdmin();

$conn = (new Database())->connect();
$uid = (int)($_SESSION['user_id'] ?? 0);
escrowEnsureDefaults($conn);

$msg = '';
$err = '';

$saldo = walletSaldo($conn, $uid);
$rules = escrowRules($conn);

// Pagination for transactions
$txPp     = in_array((int)($_GET['pp'] ?? 10), [5, 10, 20], true) ? (int)($_GET['pp'] ?? 10) : 10;
$txPage   = max(1, (int)($_GET['p'] ?? 1));
$txData   = walletHistoricoTransacoesPaginado($conn, $uid, $txPage, $txPp);
$txs      = $txData['items'];
$txTotal  = $txData['total'];
$txPages  = max(1, (int)ceil($txTotal / $txPp));

// Pagination for withdrawals
$sqPp     = in_array((int)($_GET['spp'] ?? 10), [5, 10, 20], true) ? (int)($_GET['spp'] ?? 10) : 10;
$sqPage   = max(1, (int)($_GET['sp'] ?? 1));
$sqData   = walletHistoricoSaquesPaginado($conn, $uid, $sqPage, $sqPp);
$saques   = $sqData['items'];
$sqTotal  = $sqData['total'];
$sqPages  = max(1, (int)ceil($sqTotal / $sqPp));

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
$adminFinanceTab = 'wallet_admin';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="space-y-4">
  <?php include __DIR__ . '/../../views/partials/admin_financeiro_tabs.php'; ?>
  <?php if ($msg): ?><div class="rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <p class="text-xs text-zinc-500 uppercase tracking-wide mb-1">Saldo administrativo</p>
    <h2 class="text-2xl font-bold text-greenx">R$ <?= number_format($saldo, 2, ',', '.') ?></h2>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4 text-sm text-zinc-300 flex items-center gap-2">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-500 flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <span><strong>Modo de saque:</strong>
    <?= !empty($rules['withdraw_auto_enabled']) ? 'Automático via API (debita ao aprovar e envia para API)' : 'Manual (debita ao aprovar no admin)' ?></span>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
      <h3 class="font-semibold mb-2 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-purple-400"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Transações (<?= $txTotal ?>)
      </h3>
      <div class="overflow-auto"><table class="w-full text-sm"><thead><tr class="text-zinc-400 border-b border-blackx3"><th class="text-left py-2">Data</th><th class="text-left py-2">Tipo</th><th class="text-left py-2">Origem</th><th class="text-left py-2">Valor</th></tr></thead><tbody>
      <?php foreach ($txs as $t): ?><tr class="border-b border-blackx3/60 hover:bg-blackx/40 transition"><td class="py-2"><?= fmtDate((string)$t['criado_em']) ?></td><td class="py-2"><?= htmlspecialchars((string)$t['tipo'], ENT_QUOTES, 'UTF-8') ?></td><td class="py-2"><?= htmlspecialchars((string)$t['origem'], ENT_QUOTES, 'UTF-8') ?></td><td class="py-2">R$ <?= number_format((float)$t['valor'], 2, ',', '.') ?></td></tr><?php endforeach; ?>
      <?php if (!$txs): ?><tr><td colspan="4" class="py-2 text-zinc-500">Sem transações.</td></tr><?php endif; ?></tbody></table></div>
      <?php
        $paginaAtual = $txPage;
        $totalPaginas = $txPages;
        $pp = $txPp;
        include __DIR__ . '/../../views/partials/pagination.php';
      ?>
    </div>

    <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
      <h3 class="font-semibold mb-2 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-orange-400"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
        Saques (<?= $sqTotal ?>)
      </h3>
      <div class="overflow-auto"><table class="w-full text-sm"><thead><tr class="text-zinc-400 border-b border-blackx3"><th class="text-left py-2">Data</th><th class="text-left py-2">Valor</th><th class="text-left py-2">Status</th><th class="text-left py-2">PIX</th></tr></thead><tbody>
      <?php foreach ($saques as $s): ?><tr class="border-b border-blackx3/60 hover:bg-blackx/40 transition"><td class="py-2"><?= fmtDate((string)$s['criado_em']) ?></td><td class="py-2">R$ <?= number_format((float)$s['valor'], 2, ',', '.') ?></td><td class="py-2"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusBadge((string)$s['status']) ?>"><?= htmlspecialchars((string)$s['status'], ENT_QUOTES, 'UTF-8') ?></span></td><td class="py-2"><?= htmlspecialchars((string)$s['chave_pix'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
      <?php if (!$saques): ?><tr><td colspan="4" class="py-2 text-zinc-500">Sem saques.</td></tr><?php endif; ?></tbody></table></div>
      <?php
        // Saques pagination — uses sp/spp params to not conflict with transactions p/pp
        $_qpSq = $_GET; unset($_qpSq['sp'], $_qpSq['spp']);
        $_qsSq = http_build_query($_qpSq);
        $_sepSq = $_qsSq !== '' ? '&' : '';
        $_sqPrevPage = max(1, $sqPage - 1);
        $_sqNextPage = min($sqPages, $sqPage + 1);
        $_sqStartP = max(1, $sqPage - 3);
        $_sqEndP = min($sqPages, $_sqStartP + 6);
        if ($_sqEndP - $_sqStartP + 1 < 7) $_sqStartP = max(1, $_sqEndP - 6);
        $_sqPpUid = 'sqPpSel_' . mt_rand(1000,9999);
      ?>
      <nav class="mt-5 flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-1">
          <?php if ($sqPages > 1): ?>
            <?php if ($sqPage > 1): ?>
              <a href="?<?= $_qsSq . $_sepSq ?>sp=1&spp=<?= $sqPp ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs">&laquo;</a>
              <a href="?<?= $_qsSq . $_sepSq ?>sp=<?= $_sqPrevPage ?>&spp=<?= $sqPp ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs">&lsaquo;</a>
            <?php endif; ?>
            <?php for ($pg = $_sqStartP; $pg <= $_sqEndP; $pg++): ?>
              <?php if ($pg === $sqPage): ?>
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-gradient-to-r from-greenx to-greenxd text-white font-bold text-xs"><?= $pg ?></span>
              <?php else: ?>
                <a href="?<?= $_qsSq . $_sepSq ?>sp=<?= $pg ?>&spp=<?= $sqPp ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs"><?= $pg ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($sqPage < $sqPages): ?>
              <a href="?<?= $_qsSq . $_sepSq ?>sp=<?= $_sqNextPage ?>&spp=<?= $sqPp ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs">&rsaquo;</a>
              <a href="?<?= $_qsSq . $_sepSq ?>sp=<?= $sqPages ?>&spp=<?= $sqPp ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition text-xs">&raquo;</a>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-xs text-zinc-500">Página 1 de 1</span>
          <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-xs text-zinc-500 whitespace-nowrap">Por página</label>
          <select id="<?= $_sqPpUid ?>" class="rounded-lg bg-white/[0.04] border border-white/[0.08] px-2.5 py-1.5 text-xs text-zinc-300 focus:outline-none focus:border-greenx/50 cursor-pointer">
            <?php foreach ([5,10,20] as $opt): ?>
              <option value="<?= $opt ?>" <?= $opt === $sqPp ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </nav>
      <script>(function(){var s=document.getElementById('<?= $_sqPpUid ?>');if(!s)return;s.addEventListener('change',function(){try{var u=new URL(window.location.href);u.searchParams.set('spp',s.value);u.searchParams.set('sp','1');window.location.assign(u.toString())}catch(e){window.location.href=window.location.pathname+'?spp='+s.value+'&sp=1'}})})();</script>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php';
