<?php declare(strict_types=1);
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\vendas_aprovadas.php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/vendor_portal.php';

exigirVendedor();

$conn = (new Database())->connect();

$activeMenu = 'aprovadas';
$pageTitle  = 'Vendas Aprovadas';

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
  COALESCE(SUM(oi.subtotal),0) AS total_venda
FROM order_items oi
INNER JOIN orders o ON o.id = oi.order_id
LEFT JOIN users u ON u.id = o.user_id
WHERE oi.vendedor_id = ?
  AND oi.moderation_status = 'aprovada'
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
GROUP BY oi.order_id, o.user_id, u.nome, u.email, o.criado_em, o.status
ORDER BY oi.order_id DESC
LIMIT 200
";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$args);
$st->execute();
$itens = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ─── Níveis de taxa (card no topo) ───────────────────────────────────────────
$sellerLevelInfo      = ['label' => 'Nível 1', 'level' => 1, 'total_fee_percent' => 0.0, 'revenue' => 0.0, 'is_custom' => false];
$sellerLevelCfg       = ['nivel1_percent' => 0, 'nivel2_percent' => 0, 'nivel3_percent' => 0, 'nivel2_threshold' => 0, 'nivel3_threshold' => 0];
$sellerLevelProgress  = 0.0;
$sellerLevelRemaining = null;
$sellerLevelNextLabel = '';
try {
    require_once __DIR__ . '/../../src/seller_levels.php';
    $sellerLevelCfg  = sellerLevelsConfig($conn);
    $sellerLevelInfo = sellerLevelCalc($conn, $uid);
    $sellerRevenue   = (float)($sellerLevelInfo['revenue'] ?? 0.0);
    if (!empty($sellerLevelInfo['is_custom'])) {
        $sellerLevelProgress = 100.0;
    } else {
        $level = (int)($sellerLevelInfo['level'] ?? 1);
        $stageStart = 0.0; $nextThreshold = 0.0;
        if ($level === 1) {
            $nextThreshold = (float)$sellerLevelCfg['nivel2_threshold'];
            $sellerLevelNextLabel = 'Nível 2';
        } elseif ($level === 2) {
            $stageStart = (float)$sellerLevelCfg['nivel2_threshold'];
            $nextThreshold = (float)$sellerLevelCfg['nivel3_threshold'];
            $sellerLevelNextLabel = 'Nível 3';
        } else {
            $stageStart = (float)$sellerLevelCfg['nivel3_threshold'];
        }
        if ($nextThreshold > $stageStart) {
            $sellerLevelProgress  = max(0.0, min(100.0, (($sellerRevenue - $stageStart) / ($nextThreshold - $stageStart)) * 100.0));
            $sellerLevelRemaining = max(0.0, $nextThreshold - $sellerRevenue);
        } else {
            $sellerLevelProgress = 100.0;
        }
    }
} catch (Throwable $e) { /* silencioso */ }

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';
?>

<div class="space-y-4">
  <!-- Card: Níveis de taxa do vendedor -->
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 sm:p-5 overflow-hidden">
    <div class="flex flex-col lg:flex-row lg:items-center gap-5">
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center shrink-0">
            <i data-lucide="trophy" class="w-5 h-5 text-greenx"></i>
          </div>
          <div class="min-w-0">
            <p class="text-xs uppercase tracking-wider text-zinc-500 font-semibold">Níveis de taxa</p>
            <h3 class="text-lg font-semibold truncate"><?= htmlspecialchars((string)($sellerLevelInfo['label'] ?? 'Nível 1'), ENT_QUOTES, 'UTF-8') ?></h3>
          </div>
        </div>
        <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="rounded-xl border border-white/[0.06] bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">Sua taxa atual</p>
            <p class="text-xl font-bold text-greenx mt-1"><?= number_format((float)($sellerLevelInfo['total_fee_percent'] ?? 0), 2, ',', '.') ?>%</p>
          </div>
          <div class="rounded-xl border border-white/[0.06] bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">Vendas aprovadas</p>
            <p class="text-xl font-bold mt-1">R$ <?= number_format((float)($sellerLevelInfo['revenue'] ?? 0), 2, ',', '.') ?></p>
          </div>
          <div class="rounded-xl border border-white/[0.06] bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">Próximo marco</p>
            <?php if ($sellerLevelRemaining !== null): ?>
              <p class="text-xl font-bold mt-1">R$ <?= number_format($sellerLevelRemaining, 2, ',', '.') ?></p>
              <p class="text-[11px] text-zinc-500 mt-0.5">para chegar ao <?= htmlspecialchars($sellerLevelNextLabel, ENT_QUOTES, 'UTF-8') ?></p>
            <?php elseif (!empty($sellerLevelInfo['is_custom'])): ?>
              <p class="text-xl font-bold mt-1 text-fuchsia-300">Personalizada</p>
              <p class="text-[11px] text-zinc-500 mt-0.5">definida pela equipe</p>
            <?php else: ?>
              <p class="text-xl font-bold mt-1 text-yellow-300">Topo</p>
              <p class="text-[11px] text-zinc-500 mt-0.5">melhor nível ativo</p>
            <?php endif; ?>
          </div>
        </div>
        <div class="mt-4">
          <div class="flex items-center justify-between text-xs text-zinc-500 mb-2">
            <span>Progresso do nível</span>
            <span><?= number_format($sellerLevelProgress, 0, ',', '.') ?>%</span>
          </div>
          <div class="h-2 rounded-full bg-white/[0.06] overflow-hidden">
            <div class="h-full rounded-full bg-gradient-to-r from-greenx to-greenx2" style="width:<?= number_format($sellerLevelProgress, 2, '.', '') ?>%"></div>
          </div>
        </div>
      </div>
      <div class="grid grid-cols-3 gap-2 lg:w-[360px] shrink-0">
        <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-center <?= (int)($sellerLevelInfo['level'] ?? 1) === 1 && empty($sellerLevelInfo['is_custom']) ? 'ring-1 ring-greenx/40' : '' ?>">
          <p class="text-[11px] text-zinc-500 font-semibold">Nível 1</p>
          <p class="text-sm font-bold mt-1"><?= number_format((float)$sellerLevelCfg['nivel1_percent'], 2, ',', '.') ?>%</p>
          <p class="text-[10px] text-zinc-600 mt-1">inicial</p>
        </div>
        <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-center <?= (int)($sellerLevelInfo['level'] ?? 1) === 2 && empty($sellerLevelInfo['is_custom']) ? 'ring-1 ring-greenx/40' : '' ?>">
          <p class="text-[11px] text-zinc-500 font-semibold">Nível 2</p>
          <p class="text-sm font-bold mt-1"><?= number_format((float)$sellerLevelCfg['nivel2_percent'], 2, ',', '.') ?>%</p>
          <p class="text-[10px] text-zinc-600 mt-1">R$ <?= number_format((float)$sellerLevelCfg['nivel2_threshold'], 0, ',', '.') ?></p>
        </div>
        <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-center <?= (int)($sellerLevelInfo['level'] ?? 1) === 3 && empty($sellerLevelInfo['is_custom']) ? 'ring-1 ring-greenx/40' : '' ?>">
          <p class="text-[11px] text-zinc-500 font-semibold">Nível 3</p>
          <p class="text-sm font-bold mt-1"><?= number_format((float)$sellerLevelCfg['nivel3_percent'], 2, ',', '.') ?>%</p>
          <p class="text-[10px] text-zinc-600 mt-1">R$ <?= number_format((float)$sellerLevelCfg['nivel3_threshold'], 0, ',', '.') ?></p>
        </div>
      </div>
    </div>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <form method="get" class="mb-4">
      <input
        type="text"
        name="q"
        value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="Buscar por pedido ou comprador"
        class="w-full md:w-96 bg-blackx border border-blackx3 rounded-xl px-4 py-2 outline-none focus:border-greenx"
      >
    </form>

    <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-3 pr-3">Pedido</th>
            <th class="text-left py-3 pr-3">Comprador</th>
            <th class="text-left py-3 pr-3">Itens</th>
            <th class="text-left py-3 pr-3">Total</th>
            <th class="text-left py-3 pr-3">Data</th>
            <th class="text-left py-3 pr-3">Status</th>
            <th class="text-left py-3">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($itens as $v): ?>
            <tr class="border-b border-blackx3/50 hover:bg-blackx/40 transition">
              <td class="py-3 pr-3">#<?= (int)$v['order_id'] ?></td>
              <td class="py-3 pr-3"><?= htmlspecialchars((string)($v['comprador_nome'] ?: ('#' . (int)$v['comprador_id'])), ENT_QUOTES, 'UTF-8') ?><br><span class="text-xs text-zinc-500"><?= htmlspecialchars((string)($v['comprador_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="py-3 pr-3"><?= (int)$v['qtd_total'] ?> (<?= (int)$v['linhas'] ?> linhas)</td>
              <td class="py-3 pr-3 font-medium">R$ <?= number_format((float)$v['total_venda'], 2, ',', '.') ?></td>
              <td class="py-3 pr-3"><?= htmlspecialchars((string)$v['criado_em'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-3 pr-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium bg-greenx/15 border border-greenx/40 text-greenx">Aprovada</span></td>
              <td class="py-3">
                <button class="text-greenx hover:underline" type="button" onclick="abrirDetalhes(<?= (int)$v['order_id'] ?>)">
                  Ver detalhes
                </button>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($itens)): ?>
            <tr><td colspan="7" class="py-6 text-zinc-500">Nenhuma venda aprovada.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile cards -->
    <div class="md:hidden space-y-3">
      <?php if (empty($itens)): ?>
        <div class="rounded-2xl border border-blackx3 bg-blackx/40 p-6 text-center">
          <p class="text-zinc-400 text-sm">Nenhuma venda aprovada.</p>
        </div>
      <?php else: foreach ($itens as $v): ?>
        <article class="rounded-2xl border border-blackx3 bg-blackx/60 p-3.5">
          <header class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm">#<?= (int)$v['order_id'] ?></p>
              <p class="text-sm text-zinc-200 truncate"><?= htmlspecialchars((string)($v['comprador_nome'] ?: ('#' . (int)$v['comprador_id'])), ENT_QUOTES, 'UTF-8') ?></p>
              <p class="text-[11px] text-zinc-500 truncate"><?= htmlspecialchars((string)($v['comprador_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-greenx/15 border border-greenx/40 text-greenx whitespace-nowrap shrink-0">Aprovada</span>
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
            <button type="button" onclick="abrirDetalhes(<?= (int)$v['order_id'] ?>)"
              class="inline-flex rounded-lg bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-3 py-1.5 text-[11px]">
              Ver detalhes
            </button>
          </footer>
        </article>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<dialog id="dlgVenda" class="bg-blackx2 border border-blackx3 rounded-2xl p-0 w-[95vw] max-w-2xl text-white">
  <div class="p-5 border-b border-blackx3 flex items-center justify-between">
    <h3 class="text-lg font-semibold">Detalhes da venda</h3>
    <button onclick="document.getElementById('dlgVenda').close()" class="text-zinc-400 hover:text-white">Fechar</button>
  </div>
  <div id="dlgBody" class="p-5 text-sm text-zinc-200">Carregando...</div>
</dialog>

<script>
async function abrirDetalhes(orderId) {
  const dlg = document.getElementById('dlgVenda');
  const body = document.getElementById('dlgBody');
  body.textContent = 'Carregando...';
  dlg.showModal();

  try {
    const r = await fetch('<?= BASE_PATH ?>/vendedor/api_venda_detalhe?order_id=' + encodeURIComponent(orderId));
    const j = await r.json();

    if (!j.ok) {
      body.innerHTML = `<div class="text-red-400">${j.msg || 'Erro ao carregar.'}</div>`;
      return;
    }

    const d = j.pedido;
    const rows = (j.itens || []).map(i => {
      const img = i.produto_imagem_url || '';
      const desc = String(i.produto_descricao || '').trim();
      return `
      <tr class="border-b border-blackx3/50 align-top">
        <td class="py-2 pr-3">
          <div class="flex gap-2">
            ${img ? `<img src="${img}" alt="Produto" class="w-12 h-12 rounded-lg object-cover border border-blackx3"/>` : `<div class="w-12 h-12 rounded-lg border border-blackx3 bg-blackx"></div>`}
            <div>
              <div class="font-medium">${i.produto_nome}</div>
              <div class="text-xs text-zinc-500">ID produto: #${i.product_id ?? '-'}</div>
              ${desc ? `<div class="text-xs text-zinc-400 mt-0.5">${desc}</div>` : ''}
            </div>
          </div>
        </td>
        <td class="py-2 pr-3">${i.quantidade}</td>
        <td class="py-2 pr-3">R$ ${Number(i.preco_unit).toLocaleString('pt-BR',{minimumFractionDigits:2})}</td>
        <td class="py-2">R$ ${Number(i.subtotal).toLocaleString('pt-BR',{minimumFractionDigits:2})}</td>
      </tr>`;
    }).join('');

    body.innerHTML = `
      <div class="grid md:grid-cols-2 gap-2 mb-4">
        <div><span class="text-zinc-400">Pedido:</span> #${d.order_id}</div>
        <div><span class="text-zinc-400">Comprador:</span> ${d.comprador_nome || ('#' + d.comprador_id)}${d.comprador_email ? ` <span class="text-zinc-500 text-xs">(${d.comprador_email})</span>` : ''}</div>
        <div><span class="text-zinc-400">Data:</span> ${d.criado_em}</div>
        <div><span class="text-zinc-400">Total:</span> <b>R$ ${Number(d.total_venda).toLocaleString('pt-BR',{minimumFractionDigits:2})}</b></div>
      </div>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-2 pr-3">Produto</th>
            <th class="text-left py-2 pr-3">Qtd</th>
            <th class="text-left py-2 pr-3">Preço Unit.</th>
            <th class="text-left py-2">Subtotal</th>
          </tr>
        </thead>
        <tbody>${rows || '<tr><td colspan="4" class="py-3 text-zinc-500">Sem itens.</td></tr>'}</tbody>
      </table>
    `;
  } catch (e) {
    body.innerHTML = '<div class="text-red-400">Falha na requisição.</div>';
  }
}
</script>

<?php
include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
