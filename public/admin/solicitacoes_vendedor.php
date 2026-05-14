<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\solicitacoes_vendedor.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_solicitacoes.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();

$filtros = [
  'q' => (string)($_GET['q'] ?? ''),
  'status' => (string)($_GET['status'] ?? 'pendente'),
  'de' => (string)($_GET['de'] ?? ''),
  'ate' => (string)($_GET['ate'] ?? ''),
];

$lista = listarSolicitacoesVendedor($conn, $filtros, max(1, (int)($_GET['p'] ?? 1)), 10);

$pageTitle = 'Solicitações de Vendedor';
$activeMenu = 'solicitacoes';
$subnavItems = [['label'=>'Pendentes','href'=>'solicitacoes_vendedor.php','active'=>true]];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="max-w-7xl mx-auto bg-blackx2 border border-blackx3 rounded-xl p-4">
  <form method="get" class="mb-4 grid grid-cols-1 md:grid-cols-5 gap-2">
    <input name="q" value="<?= htmlspecialchars($filtros['q']) ?>" placeholder="Nome ou e-mail" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
    <select name="status" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
      <option value="">Todos</option>
      <option value="pendente" <?= $filtros['status']==='pendente'?'selected':'' ?>>pendente</option>
      <option value="aberto" <?= $filtros['status']==='aberto'?'selected':'' ?>>aberto</option>
      <option value="aprovada" <?= $filtros['status']==='aprovada'?'selected':'' ?>>aprovada</option>
      <option value="rejeitada" <?= $filtros['status']==='rejeitada'?'selected':'' ?>>rejeitada</option>
    </select>
    <input type="date" name="de" value="<?= htmlspecialchars($filtros['de']) ?>" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
    <input type="date" name="ate" value="<?= htmlspecialchars($filtros['ate']) ?>" class="rounded-lg bg-blackx border border-blackx3 px-3 py-2">
    <button class="rounded-lg border border-blackx3 px-3 py-2 hover:border-greenx">Filtrar</button>
  </form>

  <div class="overflow-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-zinc-400 border-b border-blackx3">
          <th class="text-left py-2">#</th>
          <th class="text-left py-2">Nome</th>
          <th class="text-left py-2">E-mail</th>
          <th class="text-left py-2">Enviado em</th>
          <th class="text-left py-2">Status</th>
          <th class="text-left py-2">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($lista['itens'] as $row): ?>
        <tr class="row-link border-b border-blackx3/60 hover:bg-blackx/40"
            data-href="<?= BASE_PATH ?>/admin/solicitacao_vendedor_detalhe.php?id=<?= (int)$row['solicitacao_id'] ?>" tabindex="0">
          <td class="py-2">#<?= (int)$row['solicitacao_id'] ?></td>
          <td class="py-2"><?= htmlspecialchars((string)$row['nome']) ?></td>
          <td class="py-2"><?= htmlspecialchars((string)$row['email']) ?></td>
          <td class="py-2 text-zinc-400"><?= htmlspecialchars((string)$row['criado_em']) ?></td>
          <td class="py-2">
            <?php
              $st = mb_strtolower((string)$row['status']);
              $statusClass = 'bg-zinc-500/20 border-zinc-400/40 text-zinc-300';
              if (in_array($st, ['pendente', 'aberto'], true)) {
                $statusClass = 'bg-orange-500/20 border-orange-400/40 text-orange-300';
              } elseif ($st === 'aprovada') {
                $statusClass = 'bg-greenx/20 border-greenx/40 text-greenx';
              } elseif ($st === 'rejeitada') {
                $statusClass = 'bg-red-600/20 border-red-500/40 text-red-300';
              }
            ?>
            <span class="inline-flex rounded-full border px-2 py-1 text-xs <?= $statusClass ?>"><?= htmlspecialchars((string)$row['status']) ?></span>
          </td>
          <td class="py-2">
            <div class="flex items-center gap-2">
              <a href="solicitacao_vendedor_detalhe.php?id=<?= (int)$row['solicitacao_id'] ?>" class="rounded-lg border border-blackx3 px-3 py-1.5 hover:border-greenx">Detalhes</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista['itens']): ?><tr><td colspan="6" class="py-4 text-zinc-400">Nenhuma solicitação.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>