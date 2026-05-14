<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_depositos.php';

exigirAdmin();

$conn = (new Database())->connect();
$id = (int)($_GET['id'] ?? 0);
$deposito = obterDepositoPorId($conn, $id);

if (!$deposito) {
    header('Location: depositos');
    exit;
}

$webhooks = listarWebhooksRelacionadosAoDeposito($conn, $deposito);

$pageTitle = 'Financeiro';
$activeMenu = 'financeiro';
$adminFinanceTab = 'depositos';
$subnavItems = [
    ['label' => 'Listar depósitos', 'href' => 'depositos', 'active' => false],
    ['label' => 'Detalhes', 'href' => '#', 'active' => true],
];

$raw = json_decode((string)($deposito['raw_response'] ?? '{}'), true);
$rawPretty = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="space-y-4">
  <?php include __DIR__ . '/../../views/partials/admin_financeiro_tabs.php'; ?>
  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-lg font-semibold">Depósito #<?= (int)$deposito['id'] ?></h2>
      <a href="depositos" class="rounded-lg border border-blackx3 px-3 py-1.5 text-xs hover:border-greenx">Voltar</a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
      <div><span class="text-zinc-400">Provider:</span> <?= htmlspecialchars((string)$deposito['provider'], ENT_QUOTES, 'UTF-8') ?></div>
      <div><span class="text-zinc-400">Transaction ID:</span> <?= htmlspecialchars((string)($deposito['provider_transaction_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
      <div><span class="text-zinc-400">External Ref:</span> <?= htmlspecialchars((string)($deposito['external_ref'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
      <div><span class="text-zinc-400">Status:</span> <?= htmlspecialchars((string)$deposito['status'], ENT_QUOTES, 'UTF-8') ?></div>
      <div><span class="text-zinc-400">Método:</span> <?= htmlspecialchars((string)($deposito['payment_method'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
      <div><span class="text-zinc-400">Pedido:</span> <?= (int)($deposito['order_id'] ?? 0) > 0 ? ('#' . (int)$deposito['order_id']) : '-' ?></div>
      <div><span class="text-zinc-400">Bruto:</span> R$ <?= number_format(((int)$deposito['amount_centavos']) / 100, 2, ',', '.') ?></div>
      <div><span class="text-zinc-400">Líquido:</span> <?= $deposito['net_centavos'] !== null ? ('R$ ' . number_format(((int)$deposito['net_centavos']) / 100, 2, ',', '.')) : '-' ?></div>
      <div><span class="text-zinc-400">Taxas:</span> <?= $deposito['fees_centavos'] !== null ? ('R$ ' . number_format(((int)$deposito['fees_centavos']) / 100, 2, ',', '.')) : '-' ?></div>
      <div><span class="text-zinc-400">Usuário:</span> <?= htmlspecialchars((string)($deposito['user_nome'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
      <div><span class="text-zinc-400">Email:</span> <?= htmlspecialchars((string)($deposito['user_email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
      <div><span class="text-zinc-400">Criado:</span> <?= fmtDate((string)$deposito['created_at']) ?></div>
    </div>

    <?php if (!empty($deposito['invoice_url'])): ?>
      <div class="mt-3">
        <a href="<?= htmlspecialchars((string)$deposito['invoice_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="text-greenx hover:underline">Abrir fatura</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <h3 class="font-semibold mb-2">Eventos de webhook relacionados</h3>
    <div class="overflow-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-zinc-400 border-b border-blackx3"><th class="text-left py-2">ID</th><th class="text-left py-2">Evento</th><th class="text-left py-2">Status</th><th class="text-left py-2">Recebido</th><th class="text-left py-2">Processado</th></tr></thead>
        <tbody>
        <?php foreach ($webhooks as $w): ?>
          <tr class="border-b border-blackx3/60">
            <td class="py-2">#<?= (int)$w['id'] ?></td>
            <td class="py-2"><?= htmlspecialchars((string)$w['event_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="py-2"><?= htmlspecialchars((string)$w['status'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="py-2"><?= htmlspecialchars((string)$w['received_at'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="py-2"><?= htmlspecialchars((string)($w['processed_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$webhooks): ?><tr><td colspan="5" class="py-3 text-zinc-500">Nenhum webhook relacionado.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <h3 class="font-semibold mb-2">Resposta bruta da transação</h3>
    <pre class="text-xs overflow-auto bg-blackx border border-blackx3 rounded-xl p-3"><?= htmlspecialchars((string)$rawPretty, ENT_QUOTES, 'UTF-8') ?></pre>
  </div>
</div>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php';
