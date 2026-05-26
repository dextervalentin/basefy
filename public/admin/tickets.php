<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\tickets.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/tickets.php';
exigirAdmin();

$db   = new Database();
$conn = $db->connect();

$erro = '';
$ok   = '';

function adminWhatsappHref(?string $rawPhone): string
{
  $digits = preg_replace('/\D+/', '', (string)$rawPhone) ?? '';
  if ($digits === '') {
    return '';
  }
  if (strlen($digits) === 10 || strlen($digits) === 11) {
    $digits = '55' . $digits;
  }
  return 'https://wa.me/' . $digits;
}

// Handle status update via POST (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao      = (string)($_POST['acao'] ?? '');
    $ticketId  = (int)($_POST['id'] ?? 0);
    $newStatus = (string)($_POST['status'] ?? '');

    // Add admin reply
    if ($acao === 'reply' && $ticketId > 0) {
        $msg = trim((string)($_POST['mensagem'] ?? ''));
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        if (strlen($msg) >= 3) {
          [$sent, $replyMsg] = ticketAddMessage($conn, $ticketId, $adminId, $msg, true);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
            echo json_encode(['ok' => $sent, 'msg' => $sent ? 'Resposta enviada com sucesso.' : $replyMsg], JSON_UNESCAPED_UNICODE);
                exit;
            }
          if ($sent) $ok = 'Resposta enviada.'; else $erro = $replyMsg;
        } else {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'msg' => 'Mensagem muito curta.']);
                exit;
            }
            $erro = 'Mensagem muito curta.';
        }
    }

    // Update status
    if ($acao === 'update_status' && $ticketId > 0 && $newStatus !== '') {
        [$success, $msg] = ticketUpdateStatus($conn, $ticketId, $newStatus);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $success, 'msg' => $msg]);
            exit;
        }
        if ($success) $ok = $msg; else $erro = $msg;
    }

    if ($acao === 'dispute_decision' && $ticketId > 0) {
        $decision = (string)($_POST['decision'] ?? '');
        $note = (string)($_POST['mensagem'] ?? '');
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        [$success, $msg] = ticketResolveDispute($conn, $ticketId, $adminId, $decision, $note);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $success, 'msg' => $msg, 'status' => $decision], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($success) $ok = $msg; else $erro = $msg;
    }
}

// Handle detail AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_detail'])) {
    $id = (int)($_GET['ajax_detail']);
    $ticket = ticketGetById($conn, $id);
    header('Content-Type: application/json');
    if (!$ticket) {
        echo json_encode(['ok' => false, 'msg' => 'Ticket não encontrado.']);
    } else {
        $msgs = ticketGetMessages($conn, $id);
        echo json_encode(['ok' => true, 'ticket' => $ticket, 'messages' => $msgs]);
    }
    exit;
}

$f = [
    'q'         => (string)($_GET['q'] ?? ''),
    'status'    => (string)($_GET['status'] ?? ''),
    'categoria' => (string)($_GET['categoria'] ?? ''),
];

$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5, 10, 20]) ? $_pp : 10;
$lista = ticketsList($conn, $f, $pagina, $pp);

$statusCounts = ticketsCountByStatus($conn);
$cats = ticketCategories();
$statusOptions = ticketStatusOptions();
$manualStatusOptions = ticketManualStatusOptions();

$pageTitle  = 'Suporte';
$activeMenu = 'suporte';
$adminSupportTab = 'tickets';
$subnavItems = [
    ['label' => 'Todos', 'href' => 'tickets', 'active' => true],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="">
  <?php include __DIR__ . '/../../views/partials/admin_suporte_tabs.php'; ?>
  <!-- Stats cards -->
  <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-5">
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold">Total</p>
      <p class="text-2xl font-bold mt-1"><?= array_sum($statusCounts) ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-orange-400 font-semibold">Abertos</p>
      <p class="text-2xl font-bold mt-1 text-orange-400"><?= (int)($statusCounts['aberto'] ?? 0) ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-purple-400 font-semibold">Em Andamento</p>
      <p class="text-2xl font-bold mt-1 text-purple-400"><?= (int)($statusCounts['em_andamento'] ?? 0) ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-greenx font-semibold">Respondidos</p>
      <p class="text-2xl font-bold mt-1 text-greenx"><?= (int)($statusCounts['respondido'] ?? 0) ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-greenx font-semibold">Resolvidos</p>
      <p class="text-2xl font-bold mt-1 text-greenx"><?= (int)($statusCounts['resolvido'] ?? 0) ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-red-300 font-semibold">Não resolvidos</p>
      <p class="text-2xl font-bold mt-1 text-red-300"><?= (int)($statusCounts['nao_resolvido'] ?? 0) ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-blue-300 font-semibold">Reembolsos</p>
      <p class="text-2xl font-bold mt-1 text-blue-300"><?= (int)($statusCounts['reembolsado'] ?? 0) ?></p>
    </div>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <?php if ($erro): ?><div class="mb-3 rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="mb-3 rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

    <!-- Filter -->
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input name="q" value="<?= htmlspecialchars($f['q']) ?>" placeholder="Título, usuário ou referência" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Status</label>
          <select name="status" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <?php foreach ($statusOptions as $statusKey => $statusLabel): ?>
            <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" <?= $f['status'] === $statusKey ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:w-48">
          <label class="block text-xs text-zinc-500 mb-1">Categoria</label>
          <select name="categoria" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todas</option>
            <?php foreach ($cats as $k => $c): ?>
            <option value="<?= $k ?>" <?= $f['categoria'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($c['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar
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
          <th class="text-left py-3 pr-3">Usuário</th>
          <th class="text-left py-3 pr-3">Categoria</th>
          <th class="text-left py-3 pr-3">Motivo / Prazo</th>
          <th class="text-left py-3 pr-3">Título</th>
          <th class="text-left py-3 pr-3">Status</th>
          <th class="text-left py-3 pr-3">Data</th>
          <th class="text-left py-3">Ações</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lista['itens'] as $row): ?>
          <?php $userWhatsappHref = adminWhatsappHref((string)($row['user_whatsapp'] ?? '')); ?>
          <tr id="tk-row-<?= (int)$row['id'] ?>" class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
              data-click-selector=".js-tk-detail" tabindex="0">
            <td class="py-3 pr-3 font-mono text-xs">#<?= (int)$row['id'] ?></td>
            <td class="py-3 pr-3">
              <?= htmlspecialchars((string)($row['user_nome'] ?? '-')) ?>
              <?php if (!empty($row['user_email'])): ?>
              <br><span class="text-xs text-zinc-500"><?= htmlspecialchars($row['user_email']) ?></span>
              <?php endif; ?>
            </td>
            <td class="py-3 pr-3 text-xs"><?= htmlspecialchars((string)($cats[$row['categoria']]['label'] ?? $row['categoria'])) ?></td>
            <td class="py-3 pr-3 text-xs">
              <?php if (!empty($row['motivo'])): ?>
                <span class="block text-zinc-200 font-medium"><?= htmlspecialchars((string)$row['motivo']) ?></span>
              <?php endif; ?>
              <?php if (!empty($row['resolution_due_at'])): ?>
                <span class="block text-orange-300">Até <?= fmtDate((string)$row['resolution_due_at']) ?></span>
              <?php else: ?>
                <span class="text-zinc-600">-</span>
              <?php endif; ?>
            </td>
            <td class="py-3 pr-3 max-w-[200px]"><span class="truncate block"><?= htmlspecialchars((string)$row['titulo']) ?></span></td>
            <td class="py-3 pr-3" id="tk-status-<?= (int)$row['id'] ?>">
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
                <?php if ($userWhatsappHref !== ''): ?>
                <a href="<?= htmlspecialchars($userWhatsappHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 rounded-lg bg-blackx border border-emerald-400/30 text-emerald-300 hover:border-emerald-300 hover:text-white px-2.5 py-1.5 text-xs font-medium transition" title="Abrir WhatsApp do cliente">
                  <i data-lucide="message-circle" class="w-3.5 h-3.5"></i> WhatsApp
                </a>
                <?php endif; ?>
                <select class="js-tk-status-select rounded-lg bg-blackx border border-blackx3 text-xs px-2 py-1.5 outline-none focus:border-greenx cursor-pointer"
                        data-id="<?= (int)$row['id'] ?>" data-current="<?= htmlspecialchars((string)$row['status']) ?>">
                  <?php if (!isset($manualStatusOptions[(string)$row['status']])): ?>
                  <option value="<?= htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8') ?>" selected disabled><?= htmlspecialchars(ticketStatusLabel((string)$row['status']), ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endif; ?>
                  <?php foreach ($manualStatusOptions as $statusKey => $statusLabel): ?>
                  <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" <?= $row['status'] === $statusKey ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lista['itens']): ?><tr><td colspan="8" class="py-6 text-zinc-500">Nenhum ticket encontrado.</td></tr><?php endif; ?>
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
  var openTicketId=<?= (int)($_GET['open'] ?? 0) ?>;

  function escH(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
  function fmtDate(s){ if(!s)return'-'; try{var d=new Date(s.replace(' ','T'));return d.toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})}catch(e){return s;} }
  function statusBadge(s){
    s=(s||'').toLowerCase();
    if(s==='reembolsado')return'bg-blue-500/15 border border-blue-400/40 text-blue-300';
    if(s==='resolvido')return'bg-greenx/15 border border-greenx/40 text-greenx';
    if(s==='nao_resolvido')return'bg-red-500/15 border border-red-400/40 text-red-300';
    if(s==='respondido')return'bg-greenx/15 border border-greenx/40 text-greenx';
    if(s==='em_andamento')return'bg-greenx/15 border border-greenx/40 text-purple-300';
    if(s==='fechado')return'bg-zinc-500/15 border border-zinc-400/40 text-zinc-300';
    return'bg-orange-500/15 border border-orange-400/40 text-orange-300';
  }
  function statusLabel(s){
    if(s==='aberto')return'Aberto';
    if(s==='em_andamento')return'Em Andamento';
    if(s==='respondido')return'Respondido';
    if(s==='resolvido')return'Resolvido';
    if(s==='nao_resolvido')return'Não resolvido';
    if(s==='reembolsado')return'Reembolsado';
    if(s==='fechado')return'Fechado';
    return s;
  }
  function whatsappHref(phone){
    var digits=String(phone||'').replace(/\D+/g,'');
    if(!digits)return'';
    if(digits.length===10||digits.length===11)digits='55'+digits;
    return 'https://wa.me/'+digits;
  }
  function whatsappButton(phone){
    var href=whatsappHref(phone);
    if(!href)return'';
    return '<a href="'+href+'" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-3 py-2 text-xs font-semibold text-emerald-300 hover:border-emerald-300 hover:text-white transition">'+
      '<i data-lucide="message-circle" class="w-3.5 h-3.5"></i> WhatsApp do cliente</a>';
  }
  function isFinalStatus(s){ return ['resolvido','nao_resolvido','reembolsado','fechado'].indexOf((s||'').toLowerCase()) !== -1; }
  function toast(m,ok){
    var box=document.getElementById('admin-toast');
    if(!box){box=document.createElement('div');box.id='admin-toast';box.className='fixed top-8 right-4 z-[9999] px-4 py-2 rounded-lg border text-sm shadow-lg transition-opacity duration-200 opacity-0';document.body.appendChild(box);}
    box.classList.remove('border-greenx/40','bg-greenx/10','text-greenx','border-red-500/40','bg-red-500/10','text-red-300');
    box.classList.add(...(ok?['border-greenx/40','bg-greenx/10','text-greenx']:['border-red-500/40','bg-red-500/10','text-red-300']));
    box.textContent=m;box.style.opacity='1';
    clearTimeout(window.__adminToastTimer);
    window.__adminToastTimer=setTimeout(function(){box.style.opacity='0'},2200);
  }

  // Detail
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
        (tk.user_whatsapp?'<div class="mb-4 flex justify-end">'+whatsappButton(tk.user_whatsapp)+'</div>':'')+
        '<div class="grid md:grid-cols-2 gap-3 mb-4">'+
        '<div><span class="text-zinc-400">ID:</span> #'+tk.id+'</div>'+
        '<div><span class="text-zinc-400">Status:</span> <span class="px-2 py-0.5 rounded-full text-xs font-medium '+statusBadge(tk.status)+'">'+statusLabel(tk.status)+'</span></div>'+
        '<div><span class="text-zinc-400">Usuário:</span> '+escH(tk.user_nome)+' <span class="text-zinc-500 text-xs">('+escH(tk.user_email)+')</span></div>'+
        '<div><span class="text-zinc-400">Categoria:</span> '+escH(cats[tk.categoria]||tk.categoria)+'</div>'+
        (tk.motivo?'<div><span class="text-zinc-400">Motivo:</span> '+escH(tk.motivo)+'</div>':'')+
        (tk.resolution_due_at?'<div><span class="text-zinc-400">Prazo:</span> '+fmtDate(tk.resolution_due_at)+'</div>':'')+
        '<div><span class="text-zinc-400">Criado em:</span> '+fmtDate(tk.criado_em)+'</div>'+
        (tk.order_id?'<div><span class="text-zinc-400">Pedido:</span> #'+tk.order_id+'</div>':'')+
        '</div>'+
        '<div class="p-3 rounded-xl bg-blackx/50 border border-blackx3/60 mb-4">'+
        '<p class="text-xs text-zinc-400 mb-1">Título</p>'+
        '<p class="font-semibold break-words" style="overflow-wrap:anywhere">'+escH(tk.titulo)+'</p>'+
        '</div>'+
        '<div class="p-3 rounded-xl bg-greenx/10 border border-greenx/20 mb-4 overflow-hidden">'+
        '<p class="text-xs font-semibold text-purple-400 mb-1">Descrição</p>'+
        '<p class="text-sm whitespace-pre-wrap break-words overflow-wrap-anywhere" style="overflow-wrap:anywhere">'+escH(tk.mensagem)+'</p>'+
        '</div>';
      // Thread
      if(msgs.length){
        html+='<h4 class="text-xs uppercase tracking-wider text-zinc-500 font-semibold mb-2 mt-4">Conversa</h4>';
        msgs.forEach(function(m){
          var isAdm=m.is_admin==1||m.is_admin===true||m.is_admin==='1';
          html+='<div class="p-3 rounded-xl mb-2 border '+(isAdm?'border-greenx/20 bg-greenx/5':'border-blackx3 bg-blackx/50')+'">'+
            '<div class="flex items-center gap-2 mb-1">'+
            '<span class="text-xs font-semibold '+(isAdm?'text-greenx':'text-purple-400')+'">'+
            (isAdm?'Suporte Basefy':escH(tk.user_nome||'Usuário'))+'</span>'+
            '<span class="text-[10px] text-zinc-500">'+fmtDate(m.criado_em)+'</span>'+
            '</div>'+
            '<p class="text-sm whitespace-pre-wrap">'+escH(m.mensagem)+'</p></div>';
        });
      }
      if(tk.categoria==='pedido_disputa' && tk.order_id && !isFinalStatus(tk.status)){
        html+='<div class="mt-4 p-3 rounded-xl border border-purple-400/20 bg-purple-500/10">'+
        '<p class="text-xs font-semibold text-purple-200 mb-2">Decisão da disputa</p>'+
        '<div class="grid sm:grid-cols-3 gap-2 mb-2">'+
        '<label class="flex items-center gap-2 rounded-lg border border-white/[0.08] bg-blackx/60 p-2 text-xs cursor-pointer"><input type="radio" name="dlgDecision" value="resolvido"> Resolvida</label>'+
        '<label class="flex items-center gap-2 rounded-lg border border-white/[0.08] bg-blackx/60 p-2 text-xs cursor-pointer"><input type="radio" name="dlgDecision" value="nao_resolvido"> Não resolvida</label>'+
        '<label class="flex items-center gap-2 rounded-lg border border-blue-400/25 bg-blue-500/10 p-2 text-xs cursor-pointer"><input type="radio" name="dlgDecision" value="reembolsado"> Reembolsar</label>'+
        '</div>'+
        '<textarea id="dlgDecisionMsg" rows="3" class="w-full bg-blackx border border-blackx3 rounded-xl p-3 text-sm outline-none focus:border-greenx resize-y" placeholder="Explique a decisão para o comprador e para o histórico administrativo..."></textarea>'+
        '<div class="flex justify-end mt-2">'+
        '<button id="dlgDecisionBtn" data-id="'+tk.id+'" class="inline-flex items-center gap-2 rounded-xl bg-purple-600 text-white font-semibold px-4 py-2 text-xs hover:bg-purple-500 transition">'+
        '<i data-lucide="shield-check" class="w-3.5 h-3.5"></i> Registrar decisão</button></div></div>';
      }
      // Reply form
      if(!isFinalStatus(tk.status)){
        html+='<div class="mt-4 pt-3 border-t border-blackx3">'+
        '<label class="text-xs text-zinc-500 mb-1 block">Responder como Admin</label>'+
        '<textarea id="dlgReplyMsg" rows="3" class="w-full bg-blackx border border-blackx3 rounded-xl p-3 text-sm outline-none focus:border-greenx resize-y" placeholder="Digite sua resposta..."></textarea>'+
        '<div class="flex justify-end mt-2">'+
        '<button id="dlgReplyBtn" data-id="'+tk.id+'" class="inline-flex items-center gap-2 rounded-xl bg-greenx text-white font-semibold px-4 py-2 text-xs hover:bg-greenx2 transition">'+
        '<i data-lucide="send" class="w-3.5 h-3.5"></i> Enviar</button></div></div>';
      }
      body.innerHTML=html;
      if(window.lucide)lucide.createIcons({attrs:{'stroke-width':1.8}});
      // Bind reply
      var rbtn=body.querySelector('#dlgReplyBtn');
      if(rbtn){
        rbtn.addEventListener('click',async function(){
          var txt=body.querySelector('#dlgReplyMsg').value.trim();
          if(txt.length<3){toast('Mensagem muito curta.',false);return;}
          var fd=new FormData();
          fd.append('acao','reply');
          fd.append('id',rbtn.dataset.id);
          fd.append('mensagem',txt);
          var r2=await fetch('tickets',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
          var j2=await r2.json();
          if(j2.ok){toast('Resposta enviada!',true);dlg.close();location.reload();}
          else{toast(j2.msg||'Erro',false);}
        });
      }
      var dbtn=body.querySelector('#dlgDecisionBtn');
      if(dbtn){
        dbtn.addEventListener('click',async function(){
          var checked=body.querySelector('input[name="dlgDecision"]:checked');
          var txt=body.querySelector('#dlgDecisionMsg').value.trim();
          if(!checked){toast('Selecione uma decisão.',false);return;}
          if(txt.length<5){toast('Informe uma observação maior.',false);return;}
          if(checked.value==='reembolsado' && !confirm('Confirmar reembolso do pedido #' + tk.order_id + '? O valor volta para a carteira do comprador.')) return;
          dbtn.disabled=true;
          try{
            var fd=new FormData();
            fd.append('acao','dispute_decision');
            fd.append('id',dbtn.dataset.id);
            fd.append('decision',checked.value);
            fd.append('mensagem',txt);
            var r3=await fetch('tickets',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
            var j3=await r3.json();
            if(j3.ok){toast(j3.msg||'Decisão registrada.',true);dlg.close();location.reload();}
            else{toast(j3.msg||'Erro ao registrar decisão.',false);dbtn.disabled=false;}
          }catch(err){toast('Erro ao registrar decisão.',false);dbtn.disabled=false;}
        });
      }
    }catch(err){body.innerHTML='<p class="text-red-400">Falha na requisição.</p>';}
  });

  // Status change
  document.querySelectorAll('.js-tk-status-select').forEach(function(sel){
    sel.addEventListener('change',async function(){
      var id=sel.dataset.id;
      var newStatus=sel.value;
      try{
        var fd=new FormData();
        fd.append('acao','update_status');
        fd.append('id',id);
        fd.append('status',newStatus);
        var r=await fetch('tickets',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
        var j=await r.json();
        if(!j.ok){toast(j.msg,false);sel.value=sel.dataset.current;return;}
        sel.dataset.current=newStatus;
        var stEl=document.getElementById('tk-status-'+id);
        if(stEl){
          var cls=statusBadge(newStatus);
          stEl.innerHTML='<span class="px-2.5 py-1 rounded-full text-xs font-medium '+cls+'">'+statusLabel(newStatus)+'</span>';
        }
        toast(j.msg||'Status atualizado.',true);
      }catch(err){toast('Erro ao atualizar status.',false);sel.value=sel.dataset.current;}
    });
  });

  if(openTicketId>0){
    window.requestAnimationFrame(function(){
      var openBtn=document.querySelector('.js-tk-detail[data-id="'+openTicketId+'"]');
      if(openBtn) openBtn.click();
    });
  }
})();
</script>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>
