<?php declare(strict_types=1);
// filepath: public/vendedor/vendas_canceladas.php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/vendor_portal.php';

exigirVendedor();

$conn = (new Database())->connect();

$activeMenu = 'vendas';
$vendasTab  = 'canceladas';
$pageTitle  = 'Vendas';

$uid = (int)($_SESSION['user_id'] ?? 0);
$q   = trim((string)($_GET['q'] ?? ''));

$sql = "
SELECT
  oi.order_id,
  o.user_id AS comprador_id,
  u.nome AS comprador_nome,
  u.email AS comprador_email,
  o.criado_em,
  o.status AS status_pedido,
  COUNT(oi.id) AS linhas,
  COALESCE(SUM(oi.quantidade),0) AS qtd_total,
  COALESCE(SUM(oi.subtotal),0) AS total_venda,
  oi.moderation_status
FROM order_items oi
INNER JOIN orders o ON o.id = oi.order_id
LEFT JOIN users u ON u.id = o.user_id
WHERE oi.vendedor_id = ?
  AND (oi.moderation_status = 'rejeitada' OR o.status = 'cancelado')
";
$types = 'i';
$args  = [$uid];

if ($q !== '') {
    $sql .= " AND (oi.order_id = ? OR o.user_id = ? OR u.nome LIKE ? OR u.email LIKE ?)";
    $types .= 'iiss';
    $args[] = (int)$q;
    $args[] = (int)$q;
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}

$sql .= "
GROUP BY oi.order_id, o.user_id, u.nome, u.email, o.criado_em, o.status, oi.moderation_status
ORDER BY oi.order_id DESC
LIMIT 200
";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$args);
$st->execute();
$itens = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';
?>

<div class="space-y-4">
  <?php include __DIR__ . '/../../views/partials/vendas_tabs.php'; ?>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="ID do pedido, ID do comprador, nome ou e-mail" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 text-sm">
        </div>
        <div>
          <button type="submit" class="rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-4 py-2 text-sm">Filtrar</button>
        </div>
      </div>
    </form>

    <!-- Desktop table -->
    <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-2 pr-3">Pedido</th>
            <th class="text-left py-2 pr-3">Comprador</th>
            <th class="text-left py-2 pr-3">Data</th>
            <th class="text-left py-2 pr-3">Itens</th>
            <th class="text-left py-2 pr-3">Total</th>
            <th class="text-left py-2 pr-3">Motivo</th>
            <th class="text-left py-2">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($itens as $v):
            $motivo = ((string)($v['moderation_status'] ?? '')) === 'rejeitada' ? 'Rejeitada (moderação)' : 'Pedido cancelado';
          ?>
            <tr class="border-b border-blackx3/50">
              <td class="py-3 pr-3">#<?= (int)$v['order_id'] ?></td>
              <td class="py-3 pr-3">
                <div class="font-medium"><?= htmlspecialchars((string)($v['comprador_nome'] ?: ('#' . (int)$v['comprador_id'])), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-xs text-zinc-500"><?= htmlspecialchars((string)($v['comprador_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td class="py-3 pr-3"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$v['criado_em'])), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-3 pr-3"><?= (int)$v['qtd_total'] ?></td>
              <td class="py-3 pr-3">R$ <?= number_format((float)$v['total_venda'], 2, ',', '.') ?></td>
              <td class="py-3 pr-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-red-500/15 border border-red-500/40 text-red-400"><?= $motivo ?></span></td>
              <td class="py-3">
                <a href="<?= BASE_PATH ?>/vendedor/venda_detalhe?order_id=<?= (int)$v['order_id'] ?>" class="text-greenx hover:underline">Ver detalhes</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($itens)): ?>
            <tr><td colspan="7" class="py-6 text-zinc-500">Nenhuma venda cancelada.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile cards -->
    <div class="md:hidden space-y-3">
      <?php if (empty($itens)): ?>
        <div class="rounded-2xl border border-blackx3 bg-blackx/40 p-6 text-center">
          <p class="text-zinc-400 text-sm">Nenhuma venda cancelada.</p>
        </div>
      <?php else: foreach ($itens as $v):
        $motivo = ((string)($v['moderation_status'] ?? '')) === 'rejeitada' ? 'Rejeitada' : 'Cancelado';
      ?>
        <article class="rounded-2xl border border-blackx3 bg-blackx/60 p-3.5">
          <header class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm">#<?= (int)$v['order_id'] ?></p>
              <p class="text-sm text-zinc-200 truncate"><?= htmlspecialchars((string)($v['comprador_nome'] ?: ('#' . (int)$v['comprador_id'])), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-red-500/15 border border-red-500/40 text-red-400 shrink-0"><?= $motivo ?></span>
          </header>
          <div class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div class="rounded-lg bg-blackx/60 border border-blackx3 px-2 py-2">
              <p class="text-[10px] uppercase tracking-wider text-zinc-500">Total</p>
              <p class="text-sm font-bold mt-0.5">R$ <?= number_format((float)$v['total_venda'], 2, ',', '.') ?></p>
            </div>
            <div class="rounded-lg bg-blackx/60 border border-blackx3 px-2 py-2">
              <p class="text-[10px] uppercase tracking-wider text-zinc-500">Itens</p>
              <p class="text-sm font-bold mt-0.5"><?= (int)$v['qtd_total'] ?></p>
            </div>
            <div class="rounded-lg bg-blackx/60 border border-blackx3 px-2 py-2">
              <p class="text-[10px] uppercase tracking-wider text-zinc-500">Data</p>
              <p class="text-[11px] font-semibold mt-1"><?= htmlspecialchars(date('d/m/y H:i', strtotime((string)$v['criado_em'])), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          </div>
          <footer class="mt-3 flex justify-end">
            <a href="<?= BASE_PATH ?>/vendedor/venda_detalhe?order_id=<?= (int)$v['order_id'] ?>" class="inline-flex rounded-lg bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-3 py-1.5 text-[11px]">Ver detalhes</a>
          </footer>
        </article>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
