<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\tickets.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/src/db.php';
require_once $ROOT . '/src/auth.php';
require_once $ROOT . '/src/tickets.php';

exigirVendedor();

$conn = (new Database())->connect();
$uid  = (int)($_SESSION['user_id'] ?? 0);

// Handle detail AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_detail'])) {
    $id = (int)($_GET['ajax_detail']);
    $ticket = ticketGetById($conn, $id);
    header('Content-Type: application/json');
    if (!$ticket || (int)$ticket['user_id'] !== $uid) {
        echo json_encode(['ok' => false, 'msg' => 'Ticket não encontrado.']);
    } else {
        $msgs = ticketGetMessages($conn, $id);
        echo json_encode(['ok' => true, 'ticket' => $ticket, 'messages' => $msgs]);
    }
    exit;
}

$f = [
    'q'       => (string)($_GET['q'] ?? ''),
    'status'  => (string)($_GET['status'] ?? ''),
    'user_id' => $uid,
];

$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5, 10, 20]) ? $_pp : 10;
$lista = ticketsList($conn, $f, $pagina, $pp);

$cats = ticketCategories();

$pageTitle  = 'Meus Tickets';
$activeMenu = 'tickets';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';
?>

<div class="">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-bold">Meus Tickets de Suporte</h2>
    <a href="<?= BASE_PATH ?>/tickets_novo" class="inline-flex items-center gap-2 rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2 text-xs transition-all">
      <i data-lucide="plus" class="w-4 h-4"></i> Novo Ticket
    </a>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">

    <!-- Filter -->
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input name="q" value="<?= htmlspecialchars($f['q']) ?>" placeholder="Título ou referência" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Status</label>
          <select name="status" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="aberto" <?= $f['status'] === 'aberto' ? 'selected' : '' ?>>Aberto</option>
            <option value="em_andamento" <?= $f['status'] === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
            <option value="respondido" <?= $f['status'] === 'respondido' ? 'selected' : '' ?>>Respondido</option>
            <option value="fechado" <?= $f['status'] === 'fechado' ? 'selected' : '' ?>>Fechado</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Filtrar
          </button>
          <a href="tickets" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
    </form>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-zinc-400 border-b border-blackx3">
          <th class="text-left py-3 pr-3">ID</th>
          <th class="text-left py-3 pr-3">Categoria</th>
          <th class="text-left py-3 pr-3">Título</th>
          <th class="text-left py-3 pr-3">Status</th>
          <th class="text-left py-3 pr-3">Data</th>
          <th class="text-left py-3">Ações</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lista['itens'] as $row): ?>
          <tr class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
              data-href="<?= BASE_PATH ?>/ticket_detalhe?id=<?= (int)$row['id'] ?>" tabindex="0">
            <td class="py-3 pr-3 font-mono text-xs">#<?= (int)$row['id'] ?></td>
            <td class="py-3 pr-3 text-xs"><?= htmlspecialchars((string)($cats[$row['categoria']]['label'] ?? $row['categoria'])) ?></td>
            <td class="py-3 pr-3 max-w-[200px]"><span class="truncate block"><?= htmlspecialchars((string)$row['titulo']) ?></span></td>
            <td class="py-3 pr-3">
              <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= ticketStatusBadge((string)$row['status']) ?>">
                <?= ticketStatusLabel((string)$row['status']) ?>
              </span>
            </td>
            <td class="py-3 pr-3 text-xs text-zinc-400"><?= fmtDate((string)$row['criado_em']) ?></td>
            <td class="py-3">
              <div class="flex items-center gap-1.5">
                <button type="button" class="js-tk-detail inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 text-zinc-300 hover:border-greenx hover:text-white px-2.5 py-1.5 text-xs font-medium transition" data-id="<?= (int)$row['id'] ?>">
                  <i data-lucide="eye" class="w-3.5 h-3.5"></i> Ver
                </button>
                <a href="<?= BASE_PATH ?>/ticket_detalhe?id=<?= (int)$row['id'] ?>" class="inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 text-zinc-300 hover:border-greenx hover:text-white px-2.5 py-1.5 text-xs font-medium transition">
                  <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lista['itens']): ?><tr><td colspan="6" class="py-6 text-zinc-500">Nenhum ticket encontrado.</td></tr><?php endif; ?>
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

<!-- Detail dialog -->
<style>dialog#dlgTicket::backdrop{background:rgba(0,0,0,.75)}</style>
<dialog id="dlgTicket" class="bg-blackx2 border border-blackx3 rounded-2xl p-0 w-[95vw] max-w-2xl text-white">
  <div class="p-5 border-b border-blackx3 flex items-center justify-between">
    <h3 class="text-lg font-semibold">Detalhes do Ticket</h3>
    <button onclick="document.getElementById('dlgTicket').close()" class="text-zinc-400 hover:text-white transition">Fechar</button>
  </div>
  <div id="dlgTicketBody" class="p-5 text-sm text-zinc-200 max-h-[70vh] overflow-y-auto">Carregando...</div>
</dialog>

<script>
(function(){
  var cats=<?= json_encode(array_map(fn($c) => $c['label'], $cats), JSON_UNESCAPED_UNICODE) ?>;

  function escH(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
  function fmtDate(s){ if(!s)return'-'; try{var d=new Date(s.replace(' ','T'));return d.toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})}catch(e){return s;} }
  function statusBadge(s){
    s=(s||'').toLowerCase();
    if(s==='respondido')return'bg-greenx/15 border border-greenx/40 text-greenx';
    if(s==='em_andamento')return'bg-greenx/15 border border-greenx/40 text-purple-300';
    if(s==='fechado')return'bg-zinc-500/15 border border-zinc-400/40 text-zinc-300';
    return'bg-orange-500/15 border border-orange-400/40 text-orange-300';
  }
  function statusLabel(s){
    if(s==='aberto')return'Aberto';
    if(s==='em_andamento')return'Em Andamento';
    if(s==='respondido')return'Respondido';
    if(s==='fechado')return'Fechado';
    return s;
  }

  document.addEventListener('click',async function(e){
    var btn=e.target.closest('.js-tk-detail');
    if(!btn)return;
    var id=btn.dataset.id;
    var dlg=document.getElementById('dlgTicket');
    var body=document.getElementById('dlgTicketBody');
    body.textContent='Carregando...';
    dlg.showModal();
    try{
      var r=await fetch('tickets?ajax_detail='+id);
      var j=await r.json();
      if(!j.ok){body.innerHTML='<p class="text-red-400">'+escH(j.msg)+'</p>';return;}
      var tk=j.ticket;
      var msgs=j.messages||[];
      var html=
        '<div class="grid md:grid-cols-2 gap-3 mb-4">'+
        '<div><span class="text-zinc-400">ID:</span> #'+tk.id+'</div>'+
        '<div><span class="text-zinc-400">Status:</span> <span class="px-2 py-0.5 rounded-full text-xs font-medium '+statusBadge(tk.status)+'">'+statusLabel(tk.status)+'</span></div>'+
        '<div><span class="text-zinc-400">Categoria:</span> '+escH(cats[tk.categoria]||tk.categoria)+'</div>'+
        '<div><span class="text-zinc-400">Criado em:</span> '+fmtDate(tk.criado_em)+'</div>'+
        (tk.order_id?'<div><span class="text-zinc-400">Pedido:</span> #'+tk.order_id+'</div>':'')+
        '</div>'+
        '<div class="p-3 rounded-xl bg-blackx/50 border border-blackx3/60 mb-4">'+
        '<p class="text-xs text-zinc-400 mb-1">Título</p>'+
        '<p class="font-semibold break-words" style="overflow-wrap:anywhere">'+escH(tk.titulo)+'</p>'+
        '</div>'+
        '<div class="p-3 rounded-xl bg-greenx/10 border border-greenx/20 mb-4 overflow-hidden">'+
        '<p class="text-xs font-semibold text-purple-400 mb-1">Descrição</p>'+
        '<p class="text-sm whitespace-pre-wrap break-words" style="overflow-wrap:anywhere">'+escH(tk.mensagem)+'</p>'+
        '</div>';
      if(msgs.length){
        html+='<h4 class="text-xs uppercase tracking-wider text-zinc-500 font-semibold mb-2 mt-4">Conversa</h4>';
        msgs.forEach(function(m){
          var isAdm=m.is_admin==1||m.is_admin===true||m.is_admin==='1';
          html+='<div class="p-3 rounded-xl mb-2 border '+(isAdm?'border-greenx/20 bg-greenx/5':'border-blackx3 bg-blackx/50')+'">'+
            '<div class="flex items-center gap-2 mb-1">'+
            '<span class="text-xs font-semibold '+(isAdm?'text-greenx':'text-purple-400')+'">'+
            (isAdm?'Suporte Basefy':escH(tk.user_nome||'Você'))+'</span>'+
            '<span class="text-[10px] text-zinc-500">'+fmtDate(m.criado_em)+'</span>'+
            '</div>'+
            '<p class="text-sm whitespace-pre-wrap break-words" style="overflow-wrap:anywhere">'+escH(m.mensagem)+'</p></div>';
        });
      }
      body.innerHTML=html;
      if(window.lucide)lucide.createIcons({attrs:{'stroke-width':1.8}});
    }catch(err){body.innerHTML='<p class="text-red-400">Falha na requisição.</p>';}
  });
})();
</script>

<?php include __DIR__ . '/../../views/partials/vendor_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>
