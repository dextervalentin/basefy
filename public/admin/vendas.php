<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\vendas.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_vendas.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();

$f = [
  'q' => (string)($_GET['q'] ?? ''),
  'status_pedido' => (string)($_GET['status_pedido'] ?? ''),
  'status_moderacao' => (string)($_GET['status_moderacao'] ?? ''),
  'de' => (string)($_GET['de'] ?? ''),
  'ate' => (string)($_GET['ate'] ?? ''),
];

$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;
$lista = listarVendas($conn, $f, $pagina, $pp);

$statusBadge = static function (string $s): string {
  $s = strtolower(trim($s));
  if (in_array($s, ['aprovada','pago','entregue'], true)) return 'bg-greenx/15 border border-greenx/40 text-greenx';
  if (in_array($s, ['pendente','enviado'], true)) return 'bg-orange-500/15 border border-orange-400/40 text-orange-300';
  if (in_array($s, ['recusada','cancelado'], true)) return 'bg-red-500/15 border border-red-400/40 text-red-300';
  return 'bg-blackx border border-blackx3 text-zinc-300';
};

$pageTitle = 'Vendas Realizadas';
$activeMenu = 'vendas';
$subnavItems = [['label'=>'Listar / Editar', 'href' => 'vendas', 'active'=>true]];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="">
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <!-- Premium Filter -->
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input name="q" value="<?= htmlspecialchars($f['q']) ?>" placeholder="Comprador, vendedor ou produto" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Status Pedido</label>
          <select name="status_pedido" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="pendente" <?= $f['status_pedido']==='pendente'?'selected':'' ?>>Pendente</option>
            <option value="pago" <?= $f['status_pedido']==='pago'?'selected':'' ?>>Pago</option>
            <option value="enviado" <?= $f['status_pedido']==='enviado'?'selected':'' ?>>Enviado</option>
            <option value="entregue" <?= $f['status_pedido']==='entregue'?'selected':'' ?>>Entregue</option>
            <option value="cancelado" <?= $f['status_pedido']==='cancelado'?'selected':'' ?>>Cancelado</option>
          </select>
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Moderação</label>
          <select name="status_moderacao" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="pendente" <?= $f['status_moderacao']==='pendente'?'selected':'' ?>>Pendente</option>
            <option value="aprovada" <?= $f['status_moderacao']==='aprovada'?'selected':'' ?>>Aprovada</option>
            <option value="recusada" <?= $f['status_moderacao']==='recusada'?'selected':'' ?>>Recusada</option>
          </select>
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">De</label>
          <input type="date" name="de" value="<?= htmlspecialchars($f['de']) ?>" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Até</label>
          <input type="date" name="ate" value="<?= htmlspecialchars($f['ate']) ?>" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar
          </button>
          <a href="vendas" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
      <div class="mt-3 md:hidden flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
          <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar filtros
        </button>
        <a href="vendas" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
        </a>
      </div>
    </form>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-3 pr-3">Venda</th>
            <th class="text-left py-3 pr-3">Pedido</th>
            <th class="text-left py-3 pr-3">Produto</th>
            <th class="text-left py-3 pr-3">Comprador</th>
            <th class="text-left py-3 pr-3">Vendedor</th>
            <th class="text-left py-3 pr-3">Valor Item</th>
            <th class="text-left py-3 pr-3">Total Pedido</th>
            <th class="text-left py-3 pr-3">Moderação</th>
            <th class="text-left py-3">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($lista['itens'] as $row): ?>
          <tr id="sale-row-<?= (int)$row['venda_id'] ?>" class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
              data-click-selector=".js-sale-detail" tabindex="0">
            <td class="py-3 pr-3">#<?= (int)$row['venda_id'] ?></td>
            <td class="py-3 pr-3">
              <span class="font-mono text-xs">#<?= (int)$row['pedido_id'] ?></span>
              <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-medium <?= $statusBadge($row['pedido_status']) ?>"><?= htmlspecialchars($row['pedido_status']) ?></span>
            </td>
            <td class="py-3 pr-3 max-w-[200px]"><span class="block truncate"><?= htmlspecialchars($row['produto_nome']) ?></span></td>
            <td class="py-3 pr-3"><?= htmlspecialchars($row['comprador_nome']) ?><br><span class="text-xs text-zinc-500"><?= htmlspecialchars($row['comprador_email']) ?></span></td>
            <td class="py-3 pr-3"><?= htmlspecialchars($row['vendedor_nome']) ?><br><span class="text-xs text-zinc-500"><?= htmlspecialchars($row['vendedor_email']) ?></span></td>
            <td class="py-3 pr-3 font-medium">R$ <?= number_format((float)$row['subtotal'], 2, ',', '.') ?></td>
            <td class="py-3 pr-3 font-medium">R$ <?= number_format((float)$row['total_pedido'], 2, ',', '.') ?></td>
            <td class="py-3 pr-3" id="sale-status-<?= (int)$row['venda_id'] ?>">
              <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusBadge($row['moderation_status']) ?>"><?= htmlspecialchars($row['moderation_status']) ?></span>
            </td>
            <td class="py-3">
              <div class="flex items-center gap-1.5">
                <button type="button" class="js-sale-detail inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 text-zinc-300 hover:border-greenx hover:text-white px-2.5 py-1.5 text-xs font-medium transition" data-id="<?= (int)$row['venda_id'] ?>">
                  <i data-lucide="eye" class="w-3.5 h-3.5"></i> Detalhes
                </button>
                <?php if ($row['moderation_status'] === 'pendente' && in_array(strtolower((string)$row['pedido_status']), ['pago','paid','entregue','enviado'], true)): ?>
                  <button type="button" class="js-sale-ok inline-flex items-center gap-1 rounded-lg bg-greenx/15 border border-greenx/40 text-greenx hover:bg-greenx/25 px-2.5 py-1.5 text-xs font-medium transition" data-id="<?= (int)$row['venda_id'] ?>">
                    <i data-lucide="check" class="w-3.5 h-3.5"></i> Aprovar
                  </button>
                  <button type="button" class="js-sale-no inline-flex items-center gap-1 rounded-lg bg-red-500/15 border border-red-400/40 text-red-300 hover:bg-red-500/25 px-2.5 py-1.5 text-xs font-medium transition" data-id="<?= (int)$row['venda_id'] ?>">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i> Recusar
                  </button>
                <?php elseif ($row['moderation_status'] === 'pendente'): ?>
                  <span class="text-[10px] text-zinc-500 italic">Aguardando pagamento</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lista['itens']): ?>
          <tr><td colspan="9" class="py-6 text-zinc-500">Nenhuma venda encontrada.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php
      $paginaAtual  = (int)($lista['pagina'] ?? $pagina);
      $totalPaginas = (int)($lista['total_paginas'] ?? 1);
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
  <div id="dlgBody" class="p-5 text-sm text-zinc-200 max-h-[70vh] overflow-y-auto">Carregando...</div>
</dialog>

<script>
(function(){
  const post = async (d)=>{
    const r = await fetch('api_venda_action',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)});
    const txt = await r.text();
    try { return JSON.parse(txt); } catch(e) { throw new Error('Resposta inválida: ' + txt.substring(0, 200)); }
  };
  const toast = (m,ok=true)=>window.showToast?showToast(m,ok?'success':'error'):alert(m);

  function statusBadge(s) {
    s = (s||'').toLowerCase();
    if (['aprovada','pago','entregue'].includes(s)) return 'bg-greenx/15 border border-greenx/40 text-greenx';
    if (['pendente','enviado'].includes(s)) return 'bg-orange-500/15 border border-orange-400/40 text-orange-300';
    if (['recusada','cancelado'].includes(s)) return 'bg-red-500/15 border border-red-400/40 text-red-300';
    return 'bg-blackx border border-blackx3 text-zinc-300';
  }

  function escHtml(s) { const el = document.createElement('span'); el.textContent = s || ''; return el.innerHTML; }
  function stripHtml(s) { const tmp = document.createElement('div'); tmp.innerHTML = s || ''; return escHtml(tmp.textContent || tmp.innerText || ''); }
  function fmtDate(s) { if(!s) return '-'; let raw=s.replace(' ','T'); if(!/[Z+-]\d/.test(raw)) raw+='-03:00'; const d=new Date(raw); if(isNaN(d)) return s; return d.toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'America/Sao_Paulo'}); }

  document.addEventListener('click', async (e)=>{
    // Detail button
    const detBtn = e.target.closest('.js-sale-detail');
    if (detBtn) {
      // Double-click guard
      if (detBtn.dataset.loading === '1') return;
      detBtn.dataset.loading = '1'; detBtn.classList.add('opacity-50','pointer-events-none');

      const id = detBtn.dataset.id;
      const dlg = document.getElementById('dlgVenda');
      const body = document.getElementById('dlgBody');
      body.textContent = 'Carregando...';
      dlg.showModal();
      try {
        const r = await fetch('api_venda_detalhe?id=' + id);
        const j = await r.json();
        if (!j.ok) { body.innerHTML = `<div class="text-red-400">${escHtml(j.msg)}</div>`; return; }
        const v = j.venda;
        const img = v.produto_imagem_url || '';
        const desc = stripHtml(v.produto_descricao || '');
        const isPaid = ['pago','entregue'].includes((v.status_pedido||'').toLowerCase());

        // Payment method detection
        const grossTotal = Number(v.gross_total || 0);
        const walletUsed = Number(v.wallet_used || 0);
        const pixPortion = Math.max(0, grossTotal - walletUsed);
        let payMethod = 'PIX';
        let payClass = 'bg-greenx/15 border border-greenx/40 text-purple-300';
        if (walletUsed > 0 && pixPortion > 0) {
          payMethod = 'Wallet + PIX';
          payClass = 'bg-purple-500/15 border border-purple-400/40 text-purple-300';
        } else if (walletUsed > 0 && pixPortion <= 0) {
          payMethod = 'Saldo Wallet';
          payClass = 'bg-greenx/15 border border-greenx/40 text-greenx';
        }

        let paymentHtml = `<div class="mt-3 p-3 rounded-xl bg-blackx border border-blackx3">
          <h4 class="text-xs font-semibold text-zinc-400 mb-2">Pagamento</h4>
          <div class="flex items-center gap-3 text-xs">
            <span class="px-2.5 py-1 rounded-full font-medium ${payClass}">${payMethod}</span>
            ${walletUsed > 0 ? `<span class="text-zinc-500">Wallet: <b class="text-zinc-200">R$ ${walletUsed.toLocaleString('pt-BR',{minimumFractionDigits:2})}</b></span>` : ''}
            ${pixPortion > 0 ? `<span class="text-zinc-500">PIX: <b class="text-zinc-200">R$ ${pixPortion.toLocaleString('pt-BR',{minimumFractionDigits:2})}</b></span>` : ''}
            <span class="text-zinc-500">Total: <b class="text-zinc-200">R$ ${grossTotal.toLocaleString('pt-BR',{minimumFractionDigits:2})}</b></span>
          </div>
        </div>`;

        let deliveryHtml = '';
        if (v.delivery_content) {
          deliveryHtml = `<div class="mt-3 p-3 rounded-xl bg-greenx/10 border border-greenx/30">
            <div class="flex items-center gap-1.5 mb-1"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-greenx"><polyline points="20 6 9 17 4 12"/></svg><span class="text-xs font-semibold text-greenx">Entrega digital enviada em ${fmtDate(v.delivered_at)}</span></div>
            <div class="text-xs text-zinc-300 break-all bg-black/30 rounded-lg p-2">${escHtml(v.delivery_content)}</div>
          </div>`;
        }

        let feeHtml = '';
        if (v.escrow_fee_percent !== null) {
          feeHtml = `<div class="mt-3 p-3 rounded-xl bg-blackx border border-blackx3">
            <h4 class="text-xs font-semibold text-zinc-400 mb-2">Financeiro</h4>
            <div class="grid grid-cols-3 gap-2 text-xs">
              <div><span class="text-zinc-500">Bruto:</span> <b class="text-zinc-200">R$ ${Number(v.subtotal).toLocaleString('pt-BR',{minimumFractionDigits:2})}</b></div>
              <div><span class="text-zinc-500">Taxa (${Number(v.escrow_fee_percent).toFixed(1)}%):</span> <b class="text-red-300">-R$ ${Number(v.escrow_fee_amount).toLocaleString('pt-BR',{minimumFractionDigits:2})}</b></div>
              <div><span class="text-zinc-500">Líquido:</span> <b class="text-greenx">R$ ${Number(v.escrow_net_amount).toLocaleString('pt-BR',{minimumFractionDigits:2})}</b></div>
            </div>
          </div>`;
        }

        body.innerHTML = `
          <div class="grid md:grid-cols-2 gap-3 mb-4">
            <div><span class="text-zinc-400">Venda:</span> #${v.venda_id}</div>
            <div><span class="text-zinc-400">Pedido:</span> #${v.order_id} <span class="ml-1 px-2 py-0.5 rounded-full text-[10px] font-medium ${statusBadge(v.status_pedido)}">${escHtml(v.status_pedido)}</span></div>
            <div><span class="text-zinc-400">Comprador:</span> ${escHtml(v.comprador_nome)} <span class="text-zinc-500 text-xs">(${escHtml(v.comprador_email)})</span></div>
            <div><span class="text-zinc-400">Vendedor:</span> ${escHtml(v.vendedor_nome)} <span class="text-zinc-500 text-xs">(${escHtml(v.vendedor_email)})</span></div>
            <div><span class="text-zinc-400">Data:</span> ${fmtDate(v.criado_em)}</div>
            <div><span class="text-zinc-400">Moderação:</span> <span class="px-2 py-0.5 rounded-full text-xs font-medium ${statusBadge(v.moderation_status)}">${escHtml(v.moderation_status)}</span></div>
          </div>

          <div class="flex gap-3 p-3 rounded-xl bg-blackx/50 border border-blackx3/60">
            ${img ? `<img src="${img}" alt="Produto" class="w-16 h-16 rounded-xl object-cover border border-blackx3"/>` : `<div class="w-16 h-16 rounded-xl border border-blackx3 bg-blackx"></div>`}
            <div class="flex-1 min-w-0">
              <div class="font-semibold text-sm">${escHtml(v.produto_nome)}</div>
              <div class="text-xs text-zinc-500">ID: #${v.product_id}</div>
              ${desc ? `<div class="text-xs text-zinc-400 mt-1 line-clamp-3">${desc}</div>` : ''}
              <div class="flex items-center gap-4 mt-2 text-xs text-zinc-400">
                <span>Qtd: <strong class="text-zinc-200">${v.quantidade}</strong></span>
                <span>Unit: <strong class="text-zinc-200">R$ ${Number(v.preco_unit).toLocaleString('pt-BR',{minimumFractionDigits:2})}</strong></span>
                <span class="text-greenx font-bold text-sm">R$ ${Number(v.subtotal).toLocaleString('pt-BR',{minimumFractionDigits:2})}</span>
              </div>
            </div>
          </div>
          ${deliveryHtml}
          ${feeHtml}
          ${paymentHtml}
          ${v.moderation_motivo ? `<div class="mt-3 p-3 rounded-xl bg-red-500/10 border border-red-400/20"><span class="text-xs text-zinc-400">Motivo:</span> <span class="text-xs text-red-300">${escHtml(v.moderation_motivo)}</span></div>` : ''}
        `;
      } catch(err) {
        body.innerHTML = '<div class="text-red-400">Falha na requisição.</div>';
      } finally {
        detBtn.dataset.loading = '0'; detBtn.classList.remove('opacity-50','pointer-events-none');
      }
      return;
    }

    const okBtn = e.target.closest('.js-sale-ok');
    if (okBtn){
      if (okBtn.disabled) return;
      const id = okBtn.dataset.id;
      const wrap = okBtn.closest('.flex');
      // Immediately show approved badge
      const statusEl = document.getElementById('sale-status-'+id);
      const prevStatus = statusEl ? statusEl.innerHTML : '';
      if (statusEl) statusEl.innerHTML = '<span class="px-2.5 py-1 rounded-full text-xs font-medium bg-greenx/15 border border-greenx/40 text-greenx">aprovada</span>';
      wrap?.querySelectorAll('.js-sale-ok, .js-sale-no').forEach(b => { b.disabled = true; b.classList.add('opacity-50','pointer-events-none'); });
      try {
        const r = await post({id, acao:'aprovar'});
        if (!r.ok) {
          if (statusEl) statusEl.innerHTML = prevStatus;
          wrap?.querySelectorAll('.js-sale-ok, .js-sale-no').forEach(b => { b.disabled = false; b.classList.remove('opacity-50','pointer-events-none'); });
          return toast(r.msg,false);
        }
        wrap?.querySelectorAll('.js-sale-ok, .js-sale-no').forEach(b => b.remove());
        toast(r.msg,true);
      } catch(err) {
        // Badge already updated optimistically — keep it since backend likely succeeded
        wrap?.querySelectorAll('.js-sale-ok, .js-sale-no').forEach(b => b.remove());
        toast('Venda processada — atualize a página para confirmar.',true);
      }
      return;
    }

    const noBtn = e.target.closest('.js-sale-no');
    if (noBtn){
      if (noBtn.disabled) return;
      const id = noBtn.dataset.id;
      const motivo = prompt('Motivo da recusa:');
      if (!motivo) return;
      const wrap = noBtn.closest('.flex');
      const statusEl = document.getElementById('sale-status-'+id);
      const prevStatus = statusEl ? statusEl.innerHTML : '';
      if (statusEl) statusEl.innerHTML = '<span class="px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/15 border border-red-400/40 text-red-300">recusada</span>';
      wrap?.querySelectorAll('.js-sale-ok, .js-sale-no').forEach(b => { b.disabled = true; b.classList.add('opacity-50','pointer-events-none'); });
      try {
        const r = await post({id, acao:'recusar', motivo});
        if (!r.ok) {
          if (statusEl) statusEl.innerHTML = prevStatus;
          wrap?.querySelectorAll('.js-sale-ok, .js-sale-no').forEach(b => { b.disabled = false; b.classList.remove('opacity-50','pointer-events-none'); });
          return toast(r.msg,false);
        }
        wrap?.querySelectorAll('.js-sale-ok, .js-sale-no').forEach(b => b.remove());
        toast(r.msg,true);
      } catch(err) {
        wrap?.querySelectorAll('.js-sale-ok, .js-sale-no').forEach(b => b.remove());
        toast('Venda processada — atualize a página para confirmar.',true);
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>