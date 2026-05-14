<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\denuncias.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/media.php';
require_once __DIR__ . '/../../src/storefront.php';
require_once __DIR__ . '/../../src/reports.php';

exigirVendedor();

$conn = (new Database())->connect();
$uid  = (int)($_SESSION['user_id'] ?? 0);

// Handle detail AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_detail'])) {
    $id = (int)($_GET['ajax_detail']);
    $report = reportsGetById($conn, $id);
    header('Content-Type: application/json');
    // Only show reports that were made by this vendor
    if (!$report || (int)($report['user_id'] ?? 0) !== $uid) {
        echo json_encode(['ok' => false, 'msg' => 'Denúncia não encontrada.']);
        exit;
    }
    echo json_encode(['ok' => true, 'report' => $report]);
    exit;
}

$f = [
    'q'           => (string)($_GET['q'] ?? ''),
    'status'      => (string)($_GET['status'] ?? ''),
    'user_id'     => $uid,
];

$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5, 10, 20]) ? $_pp : 10;
$lista = reportsList($conn, $f, $pagina, $pp);

$statusBadge = static function (string $s): string {
    $s = strtolower(trim($s));
    if ($s === 'resolvido') return 'bg-greenx/15 border border-greenx/40 text-greenx';
    if ($s === 'analisando') return 'bg-greenx/15 border border-greenx/40 text-purple-300';
    if ($s === 'rejeitado') return 'bg-zinc-500/15 border border-zinc-400/40 text-zinc-300';
    return 'bg-orange-500/15 border border-orange-400/40 text-orange-300';
};

$pageTitle  = 'Minhas Denúncias';
$activeMenu = 'denuncias';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';
?>

<div class="">
  <!-- Stats summary -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold">Total</p>
      <p class="text-2xl font-bold mt-1"><?= (int)$lista['total'] ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-orange-400 font-semibold">Pendentes</p>
      <p class="text-2xl font-bold mt-1 text-orange-400"><?= count(array_filter($lista['itens'], fn($r) => $r['status'] === 'pendente')) ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-purple-400 font-semibold">Analisando</p>
      <p class="text-2xl font-bold mt-1 text-purple-400"><?= count(array_filter($lista['itens'], fn($r) => $r['status'] === 'analisando')) ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 text-center">
      <p class="text-[10px] uppercase tracking-wider text-greenx font-semibold">Resolvidos</p>
      <p class="text-2xl font-bold mt-1 text-greenx"><?= count(array_filter($lista['itens'], fn($r) => $r['status'] === 'resolvido')) ?></p>
    </div>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">

    <!-- Filter -->
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input name="q" value="<?= htmlspecialchars($f['q']) ?>" placeholder="Produto, denunciante ou motivo" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Status</label>
          <select name="status" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="pendente" <?= $f['status'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
            <option value="analisando" <?= $f['status'] === 'analisando' ? 'selected' : '' ?>>Analisando</option>
            <option value="resolvido" <?= $f['status'] === 'resolvido' ? 'selected' : '' ?>>Resolvido</option>
            <option value="rejeitado" <?= $f['status'] === 'rejeitado' ? 'selected' : '' ?>>Rejeitado</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Filtrar
          </button>
          <a href="denuncias" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
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
          <th class="text-left py-3 pr-3">Produto</th>
          <th class="text-left py-3 pr-3">Denunciante</th>
          <th class="text-left py-3 pr-3">Motivo</th>
          <th class="text-left py-3 pr-3">Status</th>
          <th class="text-left py-3 pr-3">Data</th>
          <th class="text-left py-3">Ações</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lista['itens'] as $row): ?>
          <tr class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
              data-click-selector=".js-rep-detail" tabindex="0">
            <td class="py-3 pr-3 font-mono text-xs">#<?= (int)$row['id'] ?></td>
            <td class="py-3 pr-3 max-w-[200px]">
              <div class="flex items-center gap-2">
                <?php if (!empty($row['produto_imagem'])): ?>
                <img src="<?= htmlspecialchars(sfImageUrl((string)$row['produto_imagem'])) ?>" alt="" class="w-8 h-8 rounded-lg object-cover border border-blackx3 flex-shrink-0">
                <?php endif; ?>
                <span class="truncate"><?= htmlspecialchars((string)($row['produto_nome'] ?? 'Removido')) ?></span>
              </div>
            </td>
            <td class="py-3 pr-3">
              <?= htmlspecialchars((string)($row['user_nome'] ?? '-')) ?>
              <?php if (!empty($row['user_email'])): ?>
              <br><span class="text-xs text-zinc-500"><?= htmlspecialchars($row['user_email']) ?></span>
              <?php endif; ?>
            </td>
            <td class="py-3 pr-3 max-w-[180px]"><span class="truncate block"><?= htmlspecialchars((string)$row['motivo']) ?></span></td>
            <td class="py-3 pr-3">
              <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $statusBadge((string)$row['status']) ?>">
                <?= htmlspecialchars((string)$row['status']) ?>
              </span>
            </td>
            <td class="py-3 pr-3 text-xs text-zinc-400"><?= date('d/m/Y H:i', strtotime((string)$row['criado_em'])) ?></td>
            <td class="py-3">
              <button type="button" class="js-rep-detail inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 text-zinc-300 hover:border-greenx hover:text-white px-2.5 py-1.5 text-xs font-medium transition" data-id="<?= (int)$row['id'] ?>">
                <i data-lucide="eye" class="w-3.5 h-3.5"></i> Ver
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lista['itens']): ?><tr><td colspan="7" class="py-6 text-zinc-500">Nenhuma denúncia encontrada.</td></tr><?php endif; ?>
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
<style>dialog#dlgReport::backdrop{background:rgba(0,0,0,.75)}</style>
<dialog id="dlgReport" class="bg-blackx2 border border-blackx3 rounded-2xl p-0 w-[95vw] max-w-2xl text-white">
  <div class="p-5 border-b border-blackx3 flex items-center justify-between">
    <h3 class="text-lg font-semibold">Detalhes da Denúncia</h3>
    <button onclick="document.getElementById('dlgReport').close()" class="text-zinc-400 hover:text-white transition">Fechar</button>
  </div>
  <div id="dlgReportBody" class="p-5 text-sm text-zinc-200 max-h-[70vh] overflow-y-auto">Carregando...</div>
</dialog>

<script>
(function(){
  function escH(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
  function fmtDate(s){ if(!s)return'-'; try{var d=new Date(s.replace(' ','T'));return d.toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})}catch(e){return s;} }
  function statusBadge(s){
    s=(s||'').toLowerCase();
    if(s==='resolvido')return'bg-greenx/15 border border-greenx/40 text-greenx';
    if(s==='analisando')return'bg-greenx/15 border border-greenx/40 text-purple-300';
    if(s==='rejeitado')return'bg-zinc-500/15 border border-zinc-400/40 text-zinc-300';
    return'bg-orange-500/15 border border-orange-400/40 text-orange-300';
  }

  document.addEventListener('click',async function(e){
    var btn=e.target.closest('.js-rep-detail');
    if(!btn)return;
    var id=btn.dataset.id;
    var dlg=document.getElementById('dlgReport');
    var body=document.getElementById('dlgReportBody');
    body.textContent='Carregando...';
    dlg.showModal();
    try{
      var r=await fetch('denuncias?ajax_detail='+id);
      var j=await r.json();
      if(!j.ok){body.innerHTML='<p class="text-red-400">'+escH(j.msg)+'</p>';return;}
      var rp=j.report;
      body.innerHTML=
        '<div class="grid md:grid-cols-2 gap-3 mb-4">'+
        '<div><span class="text-zinc-400">ID:</span> #'+rp.id+'</div>'+
        '<div><span class="text-zinc-400">Status:</span> <span class="px-2 py-0.5 rounded-full text-xs font-medium '+statusBadge(rp.status)+'">'+escH(rp.status)+'</span></div>'+
        '<div><span class="text-zinc-400">Denunciante:</span> '+escH(rp.user_nome)+' <span class="text-zinc-500 text-xs">('+escH(rp.user_email)+')</span></div>'+
        '<div><span class="text-zinc-400">Data:</span> '+fmtDate(rp.criado_em)+'</div>'+
        '</div>'+
        '<div class="p-3 rounded-xl bg-blackx/50 border border-blackx3/60 mb-4">'+
        '<p class="text-xs text-zinc-400 mb-1">Produto Denunciado</p>'+
        '<p class="font-semibold">'+escH(rp.produto_nome||'Produto removido')+' <span class="text-xs text-zinc-500">(ID: '+rp.product_id+')</span></p>'+
        '</div>'+
        '<div class="p-3 rounded-xl bg-orange-500/10 border border-orange-400/20 mb-4">'+
        '<p class="text-xs font-semibold text-orange-400 mb-1">Motivo</p>'+
        '<p class="text-sm">'+escH(rp.motivo)+'</p>'+
        '</div>'+
        (rp.mensagem?'<div class="p-3 rounded-xl bg-blackx border border-blackx3"><p class="text-xs text-zinc-400 mb-1">Mensagem</p><p class="text-sm whitespace-pre-wrap">'+escH(rp.mensagem)+'</p></div>':'');
    }catch(err){body.innerHTML='<p class="text-red-400">Falha na requisição.</p>';}
  });
})();
</script>

<?php include __DIR__ . '/../../views/partials/vendor_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>
