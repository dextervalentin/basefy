<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/alup_api.php';

exigirAdmin();

$conn = (new Database())->connect();
alupEnsureTables($conn);

$statusFilter = (string)($_GET['status'] ?? '');
$rows = alupListFulfillments($conn, ['status' => $statusFilter, 'limit' => 200]);

$counts = ['queued' => 0, 'processing' => 0, 'delivered' => 0, 'failed' => 0, 'cancelled' => 0];
try {
    $q = $conn->query("SELECT status, COUNT(*) AS total FROM external_fulfillments WHERE provider='alup' GROUP BY status");
    if ($q) foreach ($q->fetch_all(MYSQLI_ASSOC) as $r) $counts[(string)$r['status']] = (int)$r['total'];
} catch (Throwable $e) {}

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

$pageTitle = 'AlUp — Fulfillments';
$activeMenu = 'alup_fulfillments';
include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';

function _alupBadge(string $s): string {
    $map = [
        'queued'     => 'bg-zinc-700 text-zinc-200',
        'processing' => 'bg-yellow-600/30 text-yellow-200 border border-yellow-500/40',
        'delivered'  => 'bg-greenx/20 text-greenx border border-greenx/40',
        'failed'     => 'bg-red-600/20 text-red-300 border border-red-500/40',
        'cancelled'  => 'bg-zinc-600/40 text-zinc-300',
        'received'   => 'bg-blue-600/20 text-blue-300',
    ];
    $cls = $map[$s] ?? 'bg-zinc-700 text-zinc-200';
    return '<span class="inline-block rounded-md px-2 py-0.5 text-xs font-semibold ' . $cls . '">' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<div class="max-w-7xl mx-auto space-y-4">
  <div class="flex flex-wrap gap-2 text-sm">
    <a href="alup" class="px-3 py-1.5 rounded-lg border border-blackx3 hover:border-greenx">Configuração</a>
    <a href="alup_catalog" class="px-3 py-1.5 rounded-lg border border-blackx3 hover:border-greenx">Catálogo</a>
    <a href="alup_fulfillments" class="px-3 py-1.5 rounded-lg border border-greenx bg-greenx/10 text-greenx font-semibold">Fulfillments</a>
  </div>

  <?php if ($msg): ?><div class="rounded-xl bg-greenx/15 border border-greenx/40 text-greenx px-4 py-3 text-sm"><?= htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="rounded-xl bg-red-600/15 border border-red-500/40 text-red-300 px-4 py-3 text-sm"><?= htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-sm">
    <?php foreach (['queued','processing','delivered','failed','cancelled'] as $s): ?>
      <a href="?status=<?= $s ?>" class="rounded-xl border <?= $statusFilter===$s?'border-greenx bg-greenx/10':'border-blackx3 bg-blackx2' ?> p-3 hover:border-greenx">
        <p class="text-xs uppercase text-zinc-400 font-semibold"><?= $s ?></p>
        <p class="text-lg font-bold text-white"><?= (int)($counts[$s] ?? 0) ?></p>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="rounded-2xl border border-blackx3 bg-blackx2 p-5">
    <div class="flex justify-between items-center mb-3">
      <h2 class="text-lg font-semibold">Fila de fulfillments AlUp</h2>
      <?php if ($statusFilter): ?>
        <a href="alup_fulfillments" class="text-xs text-zinc-400 hover:text-greenx">Limpar filtro</a>
      <?php endif; ?>
    </div>

    <?php if (empty($rows)): ?>
      <p class="text-sm text-zinc-500 py-6 text-center">Nenhum fulfillment encontrado.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-zinc-400 text-xs uppercase">
            <tr class="border-b border-blackx3">
              <th class="text-left py-2 px-2">ID</th>
              <th class="text-left py-2 px-2">Pedido</th>
              <th class="text-left py-2 px-2">Produto</th>
              <th class="text-left py-2 px-2">Status</th>
              <th class="text-left py-2 px-2">Tentativas</th>
              <th class="text-left py-2 px-2">External order</th>
              <th class="text-left py-2 px-2">Erro</th>
              <th class="text-right py-2 px-2">Ação</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr class="border-b border-blackx3/50 align-top">
              <td class="py-2 px-2 text-zinc-500">#<?= (int)$r['id'] ?></td>
              <td class="py-2 px-2">
                <a href="<?= htmlspecialchars(BASE_PATH) ?>/pedido_detalhes?id=<?= (int)$r['order_id'] ?>" class="text-greenx hover:underline">#<?= (int)$r['order_id'] ?></a>
                <div class="text-xs text-zinc-500">item #<?= (int)$r['order_item_id'] ?></div>
              </td>
              <td class="py-2 px-2"><?= htmlspecialchars((string)($r['product_nome'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-2 px-2"><?= _alupBadge((string)$r['status']) ?>
                <?php if (!empty($r['provider_status'])): ?><div class="text-xs text-zinc-500 mt-1"><?= htmlspecialchars((string)$r['provider_status'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
              </td>
              <td class="py-2 px-2 text-zinc-300"><?= (int)$r['attempts'] ?></td>
              <td class="py-2 px-2 font-mono text-xs text-zinc-400">
                <?= htmlspecialchars((string)($r['external_order_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($r['external_order_no'])): ?><br><?= htmlspecialchars((string)$r['external_order_no'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
              </td>
              <td class="py-2 px-2 text-red-300 text-xs max-w-xs">
                <?php if (!empty($r['error_message'])): ?>
                  <div class="font-semibold"><?= htmlspecialchars((string)$r['error_code'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="text-zinc-400"><?= htmlspecialchars(mb_substr((string)$r['error_message'], 0, 160), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </td>
              <td class="py-2 px-2 text-right">
                <?php if (in_array((string)$r['status'], ['failed', 'queued', 'processing', 'received'], true)): ?>
                <form method="post" action="../api/admin_alup_action" class="inline">
                  <input type="hidden" name="action" value="retry_fulfillment">
                  <input type="hidden" name="fulfillment_id" value="<?= (int)$r['id'] ?>">
                  <button class="rounded-lg border border-blackx3 px-3 py-1 text-xs hover:border-greenx">Retry / Sync</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
