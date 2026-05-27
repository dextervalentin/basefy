<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\produtos.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_produtos.php';
require_once __DIR__ . '/../../src/stock_items.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();

$f = [
    'q' => (string)($_GET['q'] ?? ''),
    'categoria_id' => (string)($_GET['categoria_id'] ?? ''),
    'vendedor_id' => (string)($_GET['vendedor_id'] ?? ''),
    'ativo' => (string)($_GET['ativo'] ?? ''),
];

$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;
$lista = listarProdutos($conn, $f, $pagina, $pp);
$categorias = listarCategoriasProdutoAtivas($conn);
$vendedores = listarVendedoresAprovados($conn);

$pageTitle = 'Gerenciar Produtos';
$activeMenu = 'catalogo';
$adminCatalogTab = 'produtos';
$topActions = [['label' => 'Novo produto', 'href' => 'produtos_form']];
$subnavItems = [
    ['label' => 'Listar', 'href' => 'produtos', 'active' => true],
    ['label' => 'Adicionar', 'href' => 'produtos_form', 'active' => false],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="">
  <?php include __DIR__ . '/../../views/partials/admin_catalogo_tabs.php'; ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <!-- Premium Filter -->
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input name="q" value="<?= htmlspecialchars($f['q']) ?>" placeholder="Buscar produto" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Categoria</label>
          <select name="categoria_id" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todas</option>
            <?php foreach ($categorias as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $f['categoria_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:w-40">
          <label class="block text-xs text-zinc-500 mb-1">Vendedor</label>
          <select name="vendedor_id" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <?php foreach ($vendedores as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= $f['vendedor_id'] === (string)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:w-32">
          <label class="block text-xs text-zinc-500 mb-1">Status</label>
          <select name="ativo" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="1" <?= $f['ativo'] === '1' ? 'selected' : '' ?>>Ativo</option>
            <option value="0" <?= $f['ativo'] === '0' ? 'selected' : '' ?>>Inativo</option>
          </select>
        </div>
        <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar
          </button>
          <a href="produtos" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
      <div class="mt-3 md:hidden flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
          <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar filtros
        </button>
        <a href="produtos" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
        </a>
      </div>
    </form>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
        <tr class="text-zinc-400 border-b border-blackx3">
          <th class="text-left py-3 pr-3">ID</th>
          <th class="text-left py-3 pr-3">Imagem</th>
          <th class="text-left py-3 pr-3">Nome</th>
          <th class="text-left py-3 pr-3">Tipo</th>
          <th class="text-left py-3 pr-3">Categoria</th>
          <th class="text-left py-3 pr-3">Vendedor</th>
          <th class="text-left py-3 pr-3">Preço</th>
          <th class="text-left py-3 pr-3">Qtd</th>
          <th class="text-left py-3 pr-3">Destaque</th>
          <th class="text-left py-3 pr-3">Status</th>
          <th class="text-left py-3">Ações</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($lista['itens'] as $row): ?>
          <?php $isAtivo = (int)$row['ativo'] === 1; ?>
          <?php $rowTipo = (string)($row['tipo'] ?? 'produto'); ?>
          <tr id="prod-row-<?= (int)$row['id'] ?>" class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
              data-href="<?= BASE_PATH ?>/admin/produtos_form?id=<?= (int)$row['id'] ?>" tabindex="0">
            <td class="py-3 pr-3"><?= (int)$row['id'] ?></td>
            <td class="py-3 pr-3">
              <?php $img = normalizarProdutoImagemUrl($row['imagem'] ?? ''); ?>
              <?php if ($img): ?>
                <img src="<?= htmlspecialchars($img) ?>" class="h-10 w-10 object-cover rounded-lg border border-blackx3" loading="lazy">
              <?php else: ?>
                <span class="text-zinc-500">-</span>
              <?php endif; ?>
            </td>
            <td class="py-3 pr-3 max-w-[360px]">
              <span class="block truncate" title="<?= htmlspecialchars((string)$row['nome']) ?>">
                <?= htmlspecialchars((string)$row['nome']) ?>
              </span>
            </td>
            <td class="py-3 pr-3">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $rowTipo === 'servico' ? 'bg-purple-500/15 border border-purple-400/40 text-purple-300' : ($rowTipo === 'dinamico' ? 'bg-orange-500/15 border border-orange-400/40 text-orange-300' : 'bg-greenx/15 border border-greenx/40 text-purple-300') ?>">
                <?= $rowTipo === 'servico' ? 'Serviço' : ($rowTipo === 'dinamico' ? 'Dinâmico' : 'Produto') ?>
              </span>
            </td>
            <td class="py-3 pr-3"><?= htmlspecialchars((string)$row['categoria_nome']) ?></td>
            <td class="py-3 pr-3"><?= htmlspecialchars((string)$row['vendedor_nome']) ?></td>
            <td class="py-3 pr-3 font-medium">
              <?php
                $_preco = (float)$row['preco'];
                if ($rowTipo === 'dinamico' && $_preco <= 0) {
                    $varArr2 = json_decode((string)($row['variantes'] ?? ''), true);
                    if (is_array($varArr2) && count($varArr2)) {
                        $prices = array_map(fn($v) => (float)($v['preco'] ?? 0), $varArr2);
                        $_preco = min($prices);
                    }
                }
              ?>
              R$ <?= number_format($_preco, 2, ',', '.') ?>
              <?php if ($rowTipo === 'dinamico'): ?><span class="text-[10px] text-zinc-500 ml-1">(min)</span><?php endif; ?>
            </td>
            <td class="py-3 pr-3">
              <?php if ($rowTipo === 'servico'): ?>
                <span class="text-zinc-600">-</span>
              <?php elseif (stockBoolValue($row['auto_delivery_enabled'] ?? false)): ?>
                <?php
                  $autoConfigured = sfAutoDeliveryConfiguredCount($conn, (int)$row['id'], (string)($row['auto_delivery_items'] ?? '')) > 0;
                  $autoQtd = $autoConfigured
                      ? sfAutoDeliveryAvailableCount($conn, (int)$row['id'], $rowTipo, null, (string)($row['auto_delivery_items'] ?? ''))
                      : 0;
                ?>
                <span class="<?= $autoQtd <= 0 ? 'text-red-400' : 'text-zinc-300' ?>">
                  <?= $autoQtd ?>
                </span>
                <span class="text-[10px] ml-1 <?= $autoConfigured ? 'text-amber-400' : 'text-red-400' ?>">
                  <?= $autoConfigured ? '(auto)' : '(sem config)' ?>
                </span>
              <?php elseif ($rowTipo === 'dinamico'): ?>
                <?php
                  $varQtd = 0;
                  $varArr = json_decode((string)($row['variantes'] ?? ''), true);
                  if (is_array($varArr)) {
                      foreach ($varArr as $v) $varQtd += (int)($v['quantidade'] ?? 0);
                  }
                ?>
                <span class="<?= $varQtd <= 0 ? 'text-red-400' : 'text-zinc-300' ?>">
                  <?= $varQtd ?>
                </span>
                <span class="text-[10px] text-zinc-500 ml-1">(<?= count($varArr ?: []) ?> var)</span>
              <?php else: ?>
                <span class="<?= (int)($row['quantidade'] ?? 0) <= 0 ? 'text-red-400' : 'text-zinc-300' ?>">
                  <?= (int)($row['quantidade'] ?? 0) ?>
                </span>
              <?php endif; ?>
            </td>
            <td class="py-3 pr-3">
              <?php if (!empty($row['destaque'])): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-fuchsia-500/15 border border-fuchsia-400/40 text-fuchsia-300">
                  <i data-lucide="star" class="w-3 h-3"></i> Home
                </span>
              <?php else: ?>
                <span class="text-xs text-zinc-600">-</span>
              <?php endif; ?>
            </td>
            <td class="py-3 pr-3">
              <span id="prod-status-<?= (int)$row['id'] ?>" class="px-2.5 py-1 rounded-full text-xs font-medium <?= $isAtivo ? 'bg-greenx/15 border border-greenx/40 text-greenx' : 'bg-red-500/15 border border-red-400/40 text-red-300' ?>">
                <?= $isAtivo ? 'Ativo' : 'Inativo' ?>
              </span>
            </td>
            <td class="py-3">
              <div class="flex items-center gap-2">
                <a href="produtos_form?id=<?= (int)$row['id'] ?>" class="inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 hover:border-greenx px-2.5 py-1.5 text-xs text-zinc-300 hover:text-white transition">
                  <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Editar
                </a>
                <?php if ($rowTipo !== 'servico'): ?>
                <a href="estoque?id=<?= (int)$row['id'] ?>" class="inline-flex items-center gap-1 rounded-lg bg-amber-500/10 border border-amber-400/30 hover:border-amber-300 px-2.5 py-1.5 text-xs text-amber-200 hover:text-white transition" title="Gerenciar estoque automático">
                  <i data-lucide="boxes" class="w-3.5 h-3.5"></i> Estoque
                </a>
                <?php endif; ?>
                <button type="button"
                  class="js-prod-toggle relative inline-flex h-6 w-11 items-center rounded-full transition <?= $isAtivo ? 'bg-greenx' : 'bg-zinc-600' ?>"
                  data-id="<?= (int)$row['id'] ?>"
                  data-ativo="<?= $isAtivo ? '1' : '0' ?>">
                  <span class="js-knob inline-block h-4 w-4 rounded-full bg-white transition <?= $isAtivo ? 'translate-x-6' : 'translate-x-1' ?>"></span>
                </button>
                <button type="button" class="js-prod-delete inline-flex items-center gap-1 rounded-lg bg-red-500/10 border border-red-400/30 text-red-300 hover:bg-red-500/20 px-2.5 py-1.5 text-xs font-medium transition"
                  title="Excluir produto" aria-label="Excluir produto"
                  data-id="<?= (int)$row['id'] ?>">
                  <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (!$lista['itens']): ?>
          <tr><td colspan="11" class="py-6 text-zinc-500">Nenhum produto encontrado.</td></tr>
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

<script>
(function () {
  function adminToast(message, type = 'success') {
    let box = document.getElementById('admin-toast');
    if (!box) {
      box = document.createElement('div');
      box.id = 'admin-toast';
      box.className = 'fixed top-8 right-4 z-[9999] px-4 py-2 rounded-lg border text-sm shadow-lg transition-opacity duration-200 opacity-0';
      document.body.appendChild(box);
    }

    box.classList.remove(
      'border-greenx/40','bg-greenx/10','text-greenx',
      'border-red-500/40','bg-red-500/10','text-red-300'
    );
    box.classList.add(
      ...(type === 'error'
        ? ['border-red-500/40','bg-red-500/10','text-red-300']
        : ['border-greenx/40','bg-greenx/10','text-greenx'])
    );

    box.textContent = message;
    box.style.opacity = '1';
    clearTimeout(window.__adminToastTimer);
    window.__adminToastTimer = setTimeout(() => { box.style.opacity = '0'; }, 2200);
  }

  document.querySelectorAll('.js-prod-toggle').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.id || 0);
      const ativoAtual = (btn.dataset.ativo === '1');
      const novoAtivo = ativoAtual ? 0 : 1;
      if (!id) return;

      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('id', String(id));
        fd.append('ativo', String(novoAtivo));
        fd.append('action', 'toggle');
        fd.append('acao', 'toggle');

        const res = await fetch('api_produto_action', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const raw = await res.text();
        let data = {};
        try { data = JSON.parse(raw); } catch { throw new Error('Resposta inválida do servidor.'); }

        if (!res.ok || !data.ok) throw new Error(data.msg || 'Erro ao atualizar status.');

        btn.dataset.ativo = String(novoAtivo);
        btn.classList.toggle('bg-greenx', novoAtivo === 1);
        btn.classList.toggle('bg-zinc-600', novoAtivo !== 1);

        const knob = btn.querySelector('.js-knob');
        if (knob) {
          knob.classList.toggle('translate-x-6', novoAtivo === 1);
          knob.classList.toggle('translate-x-1', novoAtivo !== 1);
        }

        const st = document.getElementById('prod-status-' + id);
        if (st) {
          st.textContent = novoAtivo ? 'Ativo' : 'Inativo';
          st.classList.toggle('text-greenx', novoAtivo === 1);
          st.classList.toggle('text-red-400', novoAtivo !== 1);
        }

        adminToast(data.msg || 'Status atualizado.', 'success');
      } catch (e) {
        adminToast(e.message || 'Erro ao atualizar status.', 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });

  document.querySelectorAll('.js-prod-delete').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.id || 0);
      if (!id) return;
      if (!confirm('Excluir este produto? Esta ação só será concluída se ele não estiver vinculado a pedidos.')) return;

      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('id', String(id));
        fd.append('action', 'delete');

        const res = await fetch('api_produto_action', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const raw = await res.text();
        let data = {};
        try { data = JSON.parse(raw); } catch { throw new Error('Resposta inválida do servidor.'); }

        if (!res.ok || !data.ok) throw new Error(data.msg || 'Erro ao excluir produto.');

        const row = document.getElementById('prod-row-' + id);
        if (row) row.remove();
        adminToast(data.msg || 'Produto excluído.', 'success');
      } catch (e) {
        adminToast(e.message || 'Erro ao excluir produto.', 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';

function appBasePublicPath(): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return (string)preg_replace('#/admin/.*$#', '', $script);
}

function normalizarProdutoImagemUrl(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    // DB-stored media reference
    if (str_starts_with($path, 'media:') && function_exists('mediaUrl')) {
        return mediaUrl((int)substr($path, 6));
    }
    if (str_starts_with($path, 'media:') && function_exists('uploadsPublicUrl')) {
        return uploadsPublicUrl($path);
    }
    $base = appBasePublicPath();

    if (str_starts_with($path, '/admin/uploads/')) {
        $path = str_replace('/admin/uploads/', '/uploads/', $path);
    }
    if (str_starts_with($path, '/uploads/')) {
        return $base . $path;
    }
    return $base . '/uploads/produtos/' . ltrim($path, '/');
}