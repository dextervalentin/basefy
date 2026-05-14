<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

exigirVendedor();

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

$st = $conn->prepare("SELECT id, provider_transaction_id, external_ref, status, amount_centavos, created_at
                     FROM payment_transactions
                     WHERE user_id = ?
                       AND external_ref LIKE 'wallet_topup:%'
                     ORDER BY id DESC
                     LIMIT 200");
$st->bind_param('i', $uid);
$st->execute();
$depositos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$pageTitle = 'Financeiro';
$activeMenu = 'financeiro';
$finTab = 'depositos';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';
?>

<div class="max-w-7xl mx-auto space-y-4">
  <?php include __DIR__ . '/../../views/partials/financeiro_tabs_vendor.php'; ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h2 class="text-lg font-semibold mb-1">Meus depósitos</h2>
    <p class="text-sm text-zinc-400">Recargas de carteira realizadas na sua conta de vendedor.</p>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-3 pr-3">ID</th>
            <th class="text-left py-3 pr-3">Transação</th>
            <th class="text-left py-3 pr-3">Referência</th>
            <th class="text-left py-3 pr-3">Valor</th>
            <th class="text-left py-3 pr-3">Status</th>
            <th class="text-left py-3">Data</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($depositos as $d): ?>
            <tr class="border-b border-blackx3/50 hover:bg-blackx/40 transition">
              <td class="py-3 pr-3">#<?= (int)$d['id'] ?></td>
              <td class="py-3 pr-3"><?= htmlspecialchars((string)($d['provider_transaction_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-3 pr-3"><?= htmlspecialchars((string)$d['external_ref'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-3 pr-3 font-medium">R$ <?= number_format(((int)$d['amount_centavos']) / 100, 2, ',', '.') ?></td>
              <td class="py-3 pr-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusBadge((string)$d['status']) ?>"><?= htmlspecialchars((string)$d['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="py-3"><?= htmlspecialchars((string)$d['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$depositos): ?>
            <tr><td colspan="6" class="py-6 text-zinc-500 text-center">Nenhum depósito encontrado.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
