<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\categorias.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/media.php';
require_once __DIR__ . '/../../src/admin_categorias.php';
require_once __DIR__ . '/../../src/storefront.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();

$erro = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_home_featured_category') {
  $rawCategoryId = trim((string)($_POST['featured_category_id'] ?? ''));
  $featuredCategoryId = $rawCategoryId === '' ? '' : (string)max(0, (int)$rawCategoryId);
  try {
    sfHomeSettingSet($conn, 'featured_category_id', $featuredCategoryId);
    $ok = 'Categoria destaque da home atualizada.';
  } catch (\Throwable $e) {
    $erro = 'Erro ao salvar categoria destaque.';
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$tipo = (string)($_GET['tipo'] ?? '');
$ativo = (string)($_GET['ativo'] ?? '');
$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;
$lista = listarCategorias($conn, ['q' => $q, 'tipo' => $tipo, 'ativo' => $ativo], $pagina, $pp);
$homeCategoryOptions = array_values(array_filter(
  sfListCategories($conn),
  fn($cat) => strtolower(trim((string)($cat['tipo'] ?? ''))) !== 'blog'
));
$homeFeaturedCategorySetting = sfHomeSettingGet($conn, 'featured_category_id', '');

$pageTitle = 'Catálogo';
$activeMenu = 'catalogo';
$adminCatalogTab = 'categorias';
$topActions = [['label' => 'Nova categoria', 'href' => 'categorias_form']];
$subnavItems = [
    ['label' => 'Listar', 'href' => 'categorias', 'active' => true],
    ['label' => 'Adicionar', 'href' => 'categorias_form', 'active' => false],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="">
  <?php include __DIR__ . '/../../views/partials/admin_catalogo_tabs.php'; ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <?php if ($erro): ?><div class="mb-3 rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="mb-3 rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

    <!-- Premium Filter -->
    <form method="post" class="mb-4 rounded-2xl border border-purple-500/20 bg-purple-500/[0.05] p-3 md:p-4">
      <input type="hidden" name="action" value="save_home_featured_category">
      <div class="flex flex-col md:flex-row md:items-end gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-400 mb-1 font-semibold uppercase tracking-wide">Categoria destaque abaixo da home</label>
          <select name="featured_category_id" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="" <?= $homeFeaturedCategorySetting === '' ? 'selected' : '' ?>>Automático: Ativos Meta quando existir</option>
            <option value="0" <?= $homeFeaturedCategorySetting === '0' ? 'selected' : '' ?>>Não exibir seção</option>
            <?php foreach ($homeCategoryOptions as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= $homeFeaturedCategorySetting === (string)(int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$cat['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-zinc-500 mt-1">Controla a nova seção de produtos exibida logo abaixo de Categorias na home.</p>
        </div>
        <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
          <i data-lucide="save" class="w-4 h-4"></i> Salvar destaque
        </button>
      </div>
    </form>

    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar categoria" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="md:w-36">
          <label class="block text-xs text-zinc-500 mb-1">Tipo</label>
          <select name="tipo" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="produto" <?= $tipo === 'produto' ? 'selected' : '' ?>>Produto</option>
            <option value="servico" <?= $tipo === 'servico' ? 'selected' : '' ?>>Serviço</option>
          </select>
        </div>
        <div class="md:w-32">
          <label class="block text-xs text-zinc-500 mb-1">Status</label>
          <select name="ativo" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx">
            <option value="">Todos</option>
            <option value="1" <?= $ativo === '1' ? 'selected' : '' ?>>Ativa</option>
            <option value="0" <?= $ativo === '0' ? 'selected' : '' ?>>Inativa</option>
          </select>
        </div>
        <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar
          </button>
          <a href="categorias" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
      <div class="mt-3 md:hidden flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
          <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar filtros
        </button>
        <a href="categorias" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
        </a>
      </div>
    </form>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-zinc-400 border-b border-blackx3">
          <th class="text-left py-3 pr-3">ID</th>
          <th class="text-left py-3 pr-3">Imagem</th>
          <th class="text-left py-3 pr-3">Nome</th>
          <th class="text-left py-3 pr-3">Tipo</th>
          <th class="text-left py-3 pr-3">Destaque</th>
          <th class="text-left py-3 pr-3">Status</th>
          <th class="text-left py-3">Ações</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lista['itens'] as $row): ?>
          <?php
            $isAtivo = (int)$row['ativo'] === 1;
            $catImg = trim((string)($row['imagem'] ?? ''));
            $catImgUrl = '';
            if ($catImg !== '') {
                require_once __DIR__ . '/../../src/upload_paths.php';
                $catImgUrl = uploadsPublicUrl($catImg);
            }
          ?>
          <tr id="cat-row-<?= (int)$row['id'] ?>" class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
              data-href="<?= BASE_PATH ?>/admin/categorias_form?id=<?= (int)$row['id'] ?>" tabindex="0">
            <td class="py-3 pr-3"><?= (int)$row['id'] ?></td>
            <td class="py-3 pr-3">
              <?php if ($catImgUrl): ?>
              <img src="<?= htmlspecialchars($catImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="w-10 h-10 object-cover rounded-lg border border-blackx3">
              <?php else: ?>
              <div class="w-10 h-10 rounded-lg bg-blackx border border-blackx3 flex items-center justify-center text-zinc-600"><i data-lucide="image" class="w-4 h-4"></i></div>
              <?php endif; ?>
            </td>
            <td class="py-3 pr-3"><?= htmlspecialchars($row['nome']) ?></td>
            <td class="py-3 pr-3">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $row['tipo'] === 'servico' ? 'bg-purple-500/15 border border-purple-400/40 text-purple-300' : 'bg-greenx/15 border border-greenx/40 text-purple-300' ?>">
                <?= htmlspecialchars($row['tipo']) ?>
              </span>
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
              <span id="cat-status-<?= (int)$row['id'] ?>" class="px-2.5 py-1 rounded-full text-xs font-medium <?= $isAtivo ? 'bg-greenx/15 border border-greenx/40 text-greenx' : 'bg-red-500/15 border border-red-400/40 text-red-300' ?>">
                <?= $isAtivo ? 'Ativa' : 'Inativa' ?>
              </span>
            </td>
            <td class="py-3">
              <div class="flex items-center gap-2">
                <a href="categorias_form?id=<?= (int)$row['id'] ?>" class="inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 hover:border-greenx px-2.5 py-1.5 text-xs text-zinc-300 hover:text-white transition">
                  <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Editar
                </a>
                <button type="button"
                  class="js-toggle-cat relative inline-flex h-6 w-11 items-center rounded-full transition <?= $isAtivo ? 'bg-greenx' : 'bg-zinc-600' ?>"
                  data-id="<?= (int)$row['id'] ?>"
                  data-ativo="<?= $isAtivo ? '1' : '0' ?>">
                  <span class="js-knob inline-block h-4 w-4 rounded-full bg-white transition <?= $isAtivo ? 'translate-x-6' : 'translate-x-1' ?>"></span>
                </button>
                <button type="button" class="js-delete-cat inline-flex items-center gap-1 rounded-lg bg-red-500/10 border border-red-400/30 text-red-300 hover:bg-red-500/20 px-2.5 py-1.5 text-xs font-medium transition"
                  data-id="<?= (int)$row['id'] ?>">
                  <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lista['itens']): ?><tr><td colspan="7" class="py-6 text-zinc-500">Nenhuma categoria encontrada.</td></tr><?php endif; ?>
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

  document.querySelectorAll('.js-toggle-cat').forEach((btn) => {
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

        const res = await fetch('api_categoria_action', {
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

        const st = document.getElementById('cat-status-' + id);
        if (st) {
          st.textContent = novoAtivo ? 'Ativa' : 'Inativa';
          st.classList.remove('bg-greenx/15','border-greenx/40','text-greenx','bg-red-500/15','border-red-400/40','text-red-300','text-greenx','text-red-400');
          if (novoAtivo === 1) { st.classList.add('bg-greenx/15','border-greenx/40','text-greenx'); }
          else { st.classList.add('bg-red-500/15','border-red-400/40','text-red-300'); }
        }

        adminToast(data.msg || 'Status atualizado.', 'success');
      } catch (e) {
        adminToast(e.message || 'Erro ao atualizar status.', 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>