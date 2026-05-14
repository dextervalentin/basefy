<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\vendas_analise.php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/vendor_portal.php';

exigirVendedor();

$conn = (new Database())->connect();

$activeMenu = 'vendas';
$vendasTab  = 'analise';
$pageTitle  = 'Vendas';

$uid = (int)($_SESSION['user_id'] ?? 0);
$q   = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$minTotal = trim((string)($_GET['min_total'] ?? ''));
$statusPag = trim((string)($_GET['status_pagamento'] ?? ''));

$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;
$pagina = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pagina - 1) * $pp;

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
  AND oi.moderation_status = 'pendente'
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

if ($from !== '') {
  $sql .= " AND DATE(o.criado_em) >= ?";
  $types .= 's';
  $args[] = $from;
}

if ($to !== '') {
  $sql .= " AND DATE(o.criado_em) <= ?";
  $types .= 's';
  $args[] = $to;
}

if ($minTotal !== '' && is_numeric(str_replace(',', '.', $minTotal))) {
  $sql .= " AND oi.subtotal >= ?";
  $types .= 'd';
  $args[] = (float)str_replace(',', '.', $minTotal);
}

if ($statusPag !== '' && in_array($statusPag, ['pago','pendente','entregue','cancelado'], true)) {
  $sql .= " AND o.status = ?";
  $types .= 's';
  $args[] = $statusPag;
}

// Count total
$countSql = "SELECT COUNT(DISTINCT oi.order_id) " . substr($sql, strpos($sql, 'FROM'));
$stC = $conn->prepare($countSql);
if ($types !== '') $stC->bind_param($types, ...$args);
$stC->execute();
$total = (int)$stC->get_result()->fetch_row()[0];
$stC->close();
$totalPaginas = max(1, (int)ceil($total / $pp));

$sql .= "
GROUP BY oi.order_id, o.user_id, u.nome, u.email, o.criado_em, o.status
ORDER BY oi.order_id DESC
LIMIT ? OFFSET ?
";
$types .= 'ii';
$args[] = $pp;
$args[] = $offset;

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
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input
            type="text"
            name="q"
            value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Pedido ou comprador"
            class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx"
          >
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">De</label>
          <input type="date" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Até</label>
          <input type="date" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-44">
          <label class="block text-xs text-zinc-500 mb-1">Total mínimo (R$)</label>
          <input type="text" name="min_total" value="<?= htmlspecialchars($minTotal, ENT_QUOTES, 'UTF-8') ?>" placeholder="0,00" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-36">
          <label class="block text-xs text-zinc-500 mb-1">Pagamento</label>
          <select name="status_pagamento" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="pago" <?= $statusPag==='pago'?'selected':'' ?>>Pago</option>
            <option value="pendente" <?= $statusPag==='pendente'?'selected':'' ?>>Pendente</option>
          </select>
        </div>
        <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i>
            Aplicar
          </button>
          <a href="<?= BASE_PATH ?>/vendedor/vendas_analise" title="Limpar filtros" aria-label="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
      <div class="mt-3 md:hidden flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
          <i data-lucide="sliders-horizontal" class="w-4 h-4"></i>
          Aplicar filtros
        </button>
        <a href="<?= BASE_PATH ?>/vendedor/vendas_analise" title="Limpar filtros" aria-label="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
        </a>
      </div>
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
            <tr class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
                data-click-selector=".js-detail" tabindex="0">
              <td class="py-3 pr-3">#<?= (int)$v['order_id'] ?></td>
              <td class="py-3 pr-3"><?= htmlspecialchars((string)($v['comprador_nome'] ?: ('#' . (int)$v['comprador_id'])), ENT_QUOTES, 'UTF-8') ?><br><span class="text-xs text-zinc-500"><?= htmlspecialchars((string)($v['comprador_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="py-3 pr-3"><?= (int)$v['qtd_total'] ?> (<?= (int)$v['linhas'] ?> linhas)</td>
              <td class="py-3 pr-3 font-medium">R$ <?= number_format((float)$v['total_venda'], 2, ',', '.') ?></td>
              <td class="py-3 pr-3"><?= fmtDate((string)$v['criado_em']) ?></td>
              <td class="py-3 pr-3">
                <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-orange-500/15 border border-orange-400/40 text-orange-300">Em análise</span>
                <?php
                  $stPag = strtolower(trim((string)($v['status_pedido'] ?? '')));
                  if (in_array($stPag, ['pago','entregue'], true)):
                ?>
                <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-greenx/15 border border-greenx/40 text-greenx">Pago</span>
                <?php elseif ($stPag === 'pendente'): ?>
                <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-orange-500/15 border border-orange-400/40 text-orange-300">Pendente</span>
                <?php endif; ?>
              </td>
              <td class="py-3">
                <button class="js-detail text-greenx hover:underline" type="button" onclick="abrirDetalhes(<?= (int)$v['order_id'] ?>)">
                  Ver detalhes
                </button>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($itens)): ?>
            <tr><td colspan="7" class="py-6 text-zinc-500">Nenhuma venda em análise.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile card list -->
    <div class="md:hidden space-y-3">
      <?php if (empty($itens)): ?>
        <div class="rounded-2xl border border-blackx3 bg-blackx/40 p-6 text-center">
          <p class="text-zinc-400 text-sm">Nenhuma venda em análise.</p>
        </div>
      <?php else: ?>
        <?php foreach ($itens as $v):
          $stPag = strtolower(trim((string)($v['status_pedido'] ?? '')));
          $isPaid = in_array($stPag, ['pago','entregue'], true);
        ?>
          <article class="rounded-2xl border border-blackx3 bg-blackx/60 p-3.5">
            <header class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-semibold text-sm">#<?= (int)$v['order_id'] ?></p>
                <p class="text-sm text-zinc-200 truncate"><?= htmlspecialchars((string)($v['comprador_nome'] ?: ('#' . (int)$v['comprador_id'])), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="text-[11px] text-zinc-500 truncate"><?= htmlspecialchars((string)($v['comprador_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
              </div>
              <div class="flex flex-col items-end gap-1 shrink-0">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-orange-500/15 border border-orange-400/40 text-orange-300 whitespace-nowrap">Em análise</span>
                <?php if ($isPaid): ?>
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-greenx/15 border border-greenx/40 text-greenx whitespace-nowrap">Pago</span>
                <?php elseif ($stPag === 'pendente'): ?>
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-orange-500/15 border border-orange-400/40 text-orange-300 whitespace-nowrap">Pendente</span>
                <?php endif; ?>
              </div>
            </header>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
              <div class="rounded-lg bg-blackx/60 border border-blackx3 px-2 py-2">
                <p class="text-[10px] uppercase tracking-wider text-zinc-500">Total</p>
                <p class="text-sm font-bold mt-0.5">R$ <?= number_format((float)$v['total_venda'], 2, ',', '.') ?></p>
              </div>
              <div class="rounded-lg bg-blackx/60 border border-blackx3 px-2 py-2">
                <p class="text-[10px] uppercase tracking-wider text-zinc-500">Itens</p>
                <p class="text-sm font-bold mt-0.5"><?= (int)$v['qtd_total'] ?></p>
                <p class="text-[10px] text-zinc-500"><?= (int)$v['linhas'] ?> linhas</p>
              </div>
              <div class="rounded-lg bg-blackx/60 border border-blackx3 px-2 py-2">
                <p class="text-[10px] uppercase tracking-wider text-zinc-500">Data</p>
                <p class="text-[11px] font-semibold mt-1"><?= fmtDate((string)$v['criado_em']) ?></p>
              </div>
            </div>
            <footer class="mt-3 flex justify-end">
              <button type="button" onclick="abrirDetalhes(<?= (int)$v['order_id'] ?>)"
                class="inline-flex rounded-lg bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-3 py-1.5 text-[11px]">
                Ver detalhes
              </button>
            </footer>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php
      $paginaAtual = $pagina;
      include __DIR__ . '/../../views/partials/pagination.php';
    ?>
  </div>
</div>

<style>dialog#dlgVenda::backdrop{background:rgba(0,0,0,.75)}</style>
<dialog id="dlgVenda" class="bg-blackx2 border border-blackx3 rounded-2xl p-0 w-[95vw] max-w-2xl text-white">
  <div class="p-5 border-b border-blackx3 flex items-center justify-between">
    <h3 class="text-lg font-semibold">Detalhes da venda</h3>
    <button onclick="document.getElementById('dlgVenda').close()" class="text-zinc-400 hover:text-white">Fechar</button>
  </div>
  <div id="dlgBody" class="p-5 text-sm text-zinc-200">Carregando...</div>
</dialog>

<script>
function fmtDateJS(s){if(!s)return '-';const d=new Date(s);if(isNaN(d))return s;return d.toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})}
async function abrirDetalhes(orderId) {
  // Double-click guard
  const clickedBtn = event && event.target ? event.target.closest('button') : null;
  if (clickedBtn) { if (clickedBtn.dataset.loading === '1') return; clickedBtn.dataset.loading = '1'; clickedBtn.classList.add('opacity-50','pointer-events-none'); }

  const dlg = document.getElementById('dlgVenda');
  const body = document.getElementById('dlgBody');
  body.textContent = 'Carregando...';
  dlg.showModal();

  try {
    const r = await fetch('<?= BASE_PATH ?>/vendedor/api_venda_detalhe_analise?order_id=' + encodeURIComponent(orderId));
    const j = await r.json();

    if (!j.ok) {
      body.innerHTML = `<div class="text-red-400">${j.msg || 'Erro ao carregar.'}</div>`;
      return;
    }

    const d = j.pedido;
    const isPaid = ['pago','entregue'].includes((d.status_pedido||'').toLowerCase());

    const rows = (j.itens || []).map(i => {
      const img = i.produto_imagem_url || '';
      const desc = String(i.produto_descricao || '').trim();
      const hasDelivery = !!i.delivery_content;
      const deliveredAt = i.delivered_at ? fmtDateJS(i.delivered_at) : '';

      // Delivery section
      let deliveryHtml = '';
      if (hasDelivery) {
        deliveryHtml = `
          <div class="mt-2 p-3 rounded-xl bg-greenx/10 border border-greenx/30">
            <div class="flex items-center gap-1.5 mb-1.5">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-greenx"><polyline points="20 6 9 17 4 12"/></svg>
              <span class="text-xs font-semibold text-greenx">Entrega enviada em ${deliveredAt}</span>
            </div>
            <div class="text-xs text-zinc-300 break-all bg-black/30 rounded-lg p-2">${escHtml(i.delivery_content)}</div>
          </div>`;
      } else if (isPaid) {
        deliveryHtml = `
          <div class="mt-2" id="delivery-form-${i.item_id}">
            <div class="flex items-center gap-1.5 mb-1.5">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-orange-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <span class="text-xs font-semibold text-orange-300">Entrega pendente — envie o link ou conteúdo digital</span>
            </div>
            <textarea id="dc-${i.item_id}" rows="2" placeholder="Cole o link (Google Drive, Mega, etc.) ou instruções de acesso..." class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-xs outline-none focus:border-greenx resize-none mt-1"></textarea>
            <button type="button" onclick="enviarEntrega(${orderId}, ${i.item_id})" class="mt-1.5 inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-3 py-1.5 text-xs transition-all">
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Enviar entrega digital
            </button>
          </div>`;
      } else {
        deliveryHtml = `
          <div class="mt-2 p-2 rounded-lg bg-zinc-800/50 border border-blackx3">
            <span class="text-[11px] text-zinc-500">Aguardando pagamento do pedido para enviar entrega.</span>
          </div>`;
      }

      return `
      <tr class="border-b border-blackx3/50 align-top">
        <td class="py-2 pr-3">
          <div class="flex gap-2">
            ${img ? `<img src="${img}" alt="Produto" class="w-12 h-12 rounded-lg object-cover border border-blackx3"/>` : `<div class="w-12 h-12 rounded-lg border border-blackx3 bg-blackx"></div>`}
            <div class="flex-1 min-w-0">
              <div class="font-medium">${escHtml(i.produto_nome)}</div>
              <div class="text-xs text-zinc-500">ID produto: #${i.product_id ?? '-'}</div>
              ${desc ? `<div class="text-xs text-zinc-400 mt-0.5 line-clamp-2">${stripHtml(desc)}</div>` : ''}
              ${deliveryHtml}
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
        <div><span class="text-zinc-400">Comprador:</span> ${escHtml(d.comprador_nome || ('#' + d.comprador_id))}${d.comprador_email ? ` <span class="text-zinc-500 text-xs">(${escHtml(d.comprador_email)})</span>` : ''}</div>
        <div><span class="text-zinc-400">Data:</span> ${fmtDateJS(d.criado_em)}</div>
        <div><span class="text-zinc-400">Total:</span> <b>R$ ${Number(d.total_venda).toLocaleString('pt-BR',{minimumFractionDigits:2})}</b></div>
        <div><span class="text-zinc-400">Status:</span> <span class="px-2 py-0.5 rounded-full text-xs font-medium ${isPaid ? 'bg-greenx/15 border border-greenx/40 text-greenx' : 'bg-orange-500/15 border border-orange-400/40 text-orange-300'}">${escHtml(d.status_pedido || 'pendente')}</span></div>
      </div>
      ${isPaid ? `<div class="mb-4"><a href="<?= BASE_PATH ?>/vendedor/venda_detalhe?id=${d.order_id}" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-4 py-2.5 text-xs transition-all shadow-lg shadow-greenx/10"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg> Confirmar entrega com código</a></div>` : ''}
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
  } finally {
    if (clickedBtn) { clickedBtn.dataset.loading = '0'; clickedBtn.classList.remove('opacity-50','pointer-events-none'); }
  }
}

function escHtml(s) {
  const el = document.createElement('span');
  el.textContent = s || '';
  return el.innerHTML;
}

function stripHtml(s) {
  const tmp = document.createElement('div');
  tmp.innerHTML = s || '';
  return escHtml(tmp.textContent || tmp.innerText || '');
}

async function enviarEntrega(orderId, itemId) {
  const ta = document.getElementById('dc-' + itemId);
  if (!ta) return;
  const content = ta.value.trim();
  if (!content) { alert('Cole o link ou instruções de entrega.'); return; }

  const btn = ta.parentElement.querySelector('button');
  const origLabel = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="animate-pulse">Enviando...</span>';

  try {
    const r = await fetch('<?= BASE_PATH ?>/vendedor/api_deliver_digital', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, item_id: itemId, delivery_content: content })
    });
    const j = await r.json();
    if (j.ok) {
      const container = document.getElementById('delivery-form-' + itemId);
      container.innerHTML = `
        <div class="p-3 rounded-xl bg-greenx/10 border border-greenx/30">
          <div class="flex items-center gap-1.5 mb-1.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-greenx"><polyline points="20 6 9 17 4 12"/></svg>
            <span class="text-xs font-semibold text-greenx">Entrega enviada agora</span>
          </div>
          <div class="text-xs text-zinc-300 break-all bg-black/30 rounded-lg p-2">${escHtml(content)}</div>
        </div>`;
    } else {
      alert(j.msg || 'Erro ao enviar entrega.');
      btn.disabled = false;
      btn.innerHTML = origLabel;
    }
  } catch (e) {
    alert('Erro de conexão.');
    btn.disabled = false;
    btn.innerHTML = origLabel;
  }
}
</script>

<?php
include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
