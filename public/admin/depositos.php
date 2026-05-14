<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_depositos.php';

exigirAdmin();

$conn = (new Database())->connect();

$f = [
    'q' => (string)($_GET['q'] ?? ''),
    'status' => (string)($_GET['status'] ?? ''),
    'de' => (string)($_GET['de'] ?? ''),
    'ate' => (string)($_GET['ate'] ?? ''),
];

$pagina = max(1, (int)($_GET['p'] ?? 1));
$lista = listarDepositos($conn, $f, $pagina, 20);

$statusBadge = static function (string $status): string {
  $s = strtoupper(trim($status));
  if (in_array($s, ['PAID', 'PAGO'], true)) {
    return 'bg-greenx/15 border border-greenx/40 text-greenx';
  }
  if (in_array($s, ['PENDING', 'PENDENTE', 'PROCESSING', 'PROCESSANDO'], true)) {
    return 'bg-orange-500/15 border border-orange-400/40 text-orange-300';
  }
  return 'bg-blackx border border-blackx3 text-zinc-300';
};

$pageTitle = 'Financeiro';
$activeMenu = 'financeiro';
$adminFinanceTab = 'depositos';
$subnavItems = [['label' => 'Listar depósitos', 'href' => 'depositos.php', 'active' => true]];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<?php include __DIR__ . '/../../views/partials/admin_financeiro_tabs.php'; ?>

<div class="max-w-7xl mx-auto bg-blackx2 border border-blackx3 rounded-xl p-4">
  <form method="get" class="mb-4 grid grid-cols-1 md:grid-cols-5 gap-2">
    <input name="q" value="<?= htmlspecialchars($f['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="ID transação, externalRef, nome ou email" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
    <select name="status" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
      <option value="">Status</option>
      <?php foreach (['PENDING','PAID','CANCELLED','REFUNDED'] as $st): ?>
        <option value="<?= $st ?>" <?= strtoupper($f['status']) === $st ? 'selected' : '' ?>><?= $st ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="de" value="<?= htmlspecialchars($f['de'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
    <input type="date" name="ate" value="<?= htmlspecialchars($f['ate'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
    <button class="rounded-lg border border-blackx3 px-3 py-2 hover:border-greenx">Filtrar</button>
  </form>

  <div class="overflow-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-zinc-400 border-b border-blackx3">
          <th class="text-left py-2">ID</th>
          <th class="text-left py-2">Transação</th>
          <th class="text-left py-2">Pedido</th>
          <th class="text-left py-2">Usuário</th>
          <th class="text-left py-2">Status</th>
          <th class="text-left py-2">Valor</th>
          <th class="text-left py-2">Criado em</th>
          <th class="text-left py-2">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($lista['itens'] as $row): ?>
          <tr class="row-link border-b border-blackx3/60 hover:bg-blackx/40"
              data-href="<?= BASE_PATH ?>/admin/deposito_detalhe.php?id=<?= (int)$row['id'] ?>" tabindex="0">
          <td class="py-2">#<?= (int)$row['id'] ?></td>
          <td class="py-2"><?= htmlspecialchars((string)($row['provider_transaction_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="py-2"><?= (int)($row['order_id'] ?? 0) > 0 ? ('#' . (int)$row['order_id']) : '-' ?></td>
          <td class="py-2"><?= htmlspecialchars((string)($row['user_nome'] ?? '-'), ENT_QUOTES, 'UTF-8') ?><br><span class="text-zinc-500 text-xs"><?= htmlspecialchars((string)($row['user_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
            <td class="py-2"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusBadge((string)$row['status']) ?>"><?= htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="py-2">R$ <?= number_format(((int)$row['amount_centavos']) / 100, 2, ',', '.') ?></td>
          
          <td class="py-2"><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="py-2">
            <a href="deposito_detalhe.php?id=<?= (int)$row['id'] ?>" class="text-greenx hover:underline">Detalhes</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista['itens']): ?>
        <tr><td colspan="9" class="py-4 text-zinc-400">Nenhum depósito encontrado.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php';
