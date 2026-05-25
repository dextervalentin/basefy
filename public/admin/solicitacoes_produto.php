<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\solicitacoes_produto.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/upload_paths.php';
require_once __DIR__ . '/../../src/media.php';
require_once __DIR__ . '/../../src/admin_product_approval.php';
exigirAdmin();

$db   = new Database();
$conn = $db->connect();

$filtros = [
  'q'      => (string)($_GET['q'] ?? ''),
  'status' => (string)($_GET['status'] ?? 'pendente'),
];

$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp     = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;
$lista  = listarSolicitacoesProduto($conn, $filtros, $pagina, $pp);

$pageTitle   = 'Solicitações de Produto';
$activeMenu  = 'catalogo';
$adminCatalogTab = 'solicitacoes_produto';
$subnavItems = [['label' => 'Pendentes', 'href' => 'solicitacoes_produto', 'active' => true]];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

  <div>
  <?php include __DIR__ . '/../../views/partials/admin_catalogo_tabs.php'; ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <!-- Filter -->
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input name="q" value="<?= htmlspecialchars($filtros['q']) ?>" placeholder="Nome do produto ou vendedor" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-44">
          <label class="block text-xs text-zinc-500 mb-1">Status</label>
          <select name="status" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="pendente"  <?= $filtros['status']==='pendente'?'selected':'' ?>>Pendente</option>
            <option value="aprovado"  <?= $filtros['status']==='aprovado'?'selected':'' ?>>Aprovado</option>
            <option value="rejeitado" <?= $filtros['status']==='rejeitado'?'selected':'' ?>>Rejeitado</option>
          </select>
        </div>
        <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar
          </button>
          <a href="solicitacoes_produto" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
      <div class="mt-3 md:hidden flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
          <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar filtros
        </button>
        <a href="solicitacoes_produto" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
        </a>
      </div>
    </form>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-3 pr-3">#</th>
            <th class="text-left py-3 pr-3">Produto</th>
            <th class="text-left py-3 pr-3">Vendedor</th>
            <th class="text-left py-3 pr-3">Tipo</th>
            <th class="text-left py-3 pr-3">Preço</th>
            <th class="text-left py-3 pr-3">Status</th>
            <th class="text-left py-3">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($lista['itens'] as $row): ?>
          <?php
            $st = mb_strtolower((string)($row['status_aprovacao'] ?? ''));
            $statusClass = 'bg-zinc-500/20 border-zinc-400/40 text-zinc-300';
            if ($st === 'pendente') {
              $statusClass = 'bg-orange-500/20 border-orange-400/40 text-orange-300';
            } elseif ($st === 'aprovado') {
              $statusClass = 'bg-greenx/20 border-greenx/40 text-greenx';
            } elseif ($st === 'rejeitado') {
              $statusClass = 'bg-red-600/20 border-red-500/40 text-red-300';
            }
            $_tipo = $row['tipo'] ?? 'produto';
            $tipoLabel = match($_tipo) {
                'dinamico' => 'Dinâmico',
                'servico'  => 'Serviço',
                'infoproduto' => 'Infoproduto',
                default    => 'Produto',
            };
          ?>
          <tr class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
              data-href="<?= BASE_PATH ?>/admin/solicitacao_produto_detalhe?id=<?= (int)$row['id'] ?>" tabindex="0">
            <td class="py-3 pr-3">#<?= (int)$row['id'] ?></td>
            <td class="py-3 pr-3">
              <div class="flex items-center gap-2">
                <?php
                  $_imgUrl = mediaResolveUrl((string)($row['imagem'] ?? ''), '');
                ?>
                <?php if ($_imgUrl !== ''): ?>
                  <img src="<?= htmlspecialchars($_imgUrl) ?>" alt="" class="w-8 h-8 rounded-lg object-cover border border-blackx3">
                <?php else: ?>
                  <div class="w-8 h-8 rounded-lg bg-blackx3 flex items-center justify-center"><i data-lucide="package" class="w-4 h-4 text-zinc-500"></i></div>
                <?php endif; ?>
                <span class="truncate max-w-[180px]"><?= htmlspecialchars((string)$row['nome']) ?></span>
              </div>
            </td>
            <td class="py-3 pr-3 text-zinc-300"><?= htmlspecialchars((string)($row['vendedor_nome'] ?? '-')) ?></td>
            <td class="py-3 pr-3 text-zinc-400"><?= $tipoLabel ?></td>
            <td class="py-3 pr-3">
            <?php
              $_preco = (float)($row['preco'] ?? 0);
              if ($_preco <= 0 && ($_tipo === 'dinamico') && !empty($row['variantes'])) {
                  $_vars = is_string($row['variantes']) ? json_decode($row['variantes'], true) : (is_array($row['variantes']) ? $row['variantes'] : []);
                  $_prices = array_filter(array_map(fn($v) => (float)($v['preco'] ?? 0), $_vars ?: []), fn($p) => $p > 0);
                  if ($_prices) {
                      $_min = min($_prices); $_max = max($_prices);
                      echo $_min === $_max
                          ? 'R$ ' . number_format($_min, 2, ',', '.')
                          : 'R$ ' . number_format($_min, 2, ',', '.') . ' ~ R$ ' . number_format($_max, 2, ',', '.');
                  } else {
                      echo 'R$ 0,00';
                  }
              } else {
                  echo 'R$ ' . number_format($_preco, 2, ',', '.');
              }
            ?>
            </td>
            <td class="py-3 pr-3">
              <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium <?= $statusClass ?>"><?= ucfirst($st) ?></span>
            </td>
            <td class="py-3">
              <a href="solicitacao_produto_detalhe?id=<?= (int)$row['id'] ?>" class="inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 hover:border-greenx px-2.5 py-1.5 text-xs text-zinc-300 hover:text-white transition">
                <i data-lucide="eye" class="w-3.5 h-3.5"></i> Detalhes
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lista['itens']): ?><tr><td colspan="7" class="py-6 text-zinc-500">Nenhuma solicitação de produto.</td></tr><?php endif; ?>
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

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>
