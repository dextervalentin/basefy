<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\produtos.php
declare(strict_types=1);
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/vendor_portal.php';
require_once __DIR__ . '/../../src/media.php';
exigirVendedor();

$db = new Database(); $conn = $db->connect();
$uid = (int)($_SESSION['user_id'] ?? 0);
$produtoFilters = ['q' => isset($_GET['q']) ? trim((string)$_GET['q']) : ''];
$itens = listarMeusProdutos($conn, $uid, $produtoFilters);

header('Content-Type: text/html; charset=UTF-8');
$pageTitle = 'Meus Produtos';
$activeMenu = 'produtos';
$topActions = [['label' => 'Adicionar', 'href' => BASE_PATH . '/vendedor/produtos_form']];
$subnavItems = [
  ['label' => 'Listar', 'href' => BASE_PATH . '/vendedor/produtos', 'active' => true],
  ['label' => 'Adicionar', 'href' => BASE_PATH . '/vendedor/produtos_form', 'active' => false],
];

include __DIR__.'/../../views/partials/header.php';
include __DIR__.'/../../views/partials/vendor_layout_start.php';
?>
<div>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <form method="get" class="w-full md:w-auto">
        <input
          type="text"
          name="q"
          value="<?= htmlspecialchars((string)($_GET['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          placeholder="Buscar produto"
          class="w-full md:w-80 bg-blackx border border-blackx3 rounded-xl px-4 py-2 outline-none focus:border-greenx"
        >
      </form>
      <a href="<?= BASE_PATH ?>/vendedor/produtos_form" class="inline-flex items-center justify-center gap-2 rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2 transition">
        <i data-lucide="plus" class="w-4 h-4"></i>
        Adicionar produto
      </a>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-3 pr-3">ID</th>
            <th class="text-left py-3 pr-3">Imagem</th>
            <th class="text-left py-3 pr-3">Nome</th>
            <th class="text-left py-3 pr-3">Preço</th>
            <th class="text-left py-3 pr-3">Status</th>
            <th class="text-left py-3">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($itens as $p): ?>
            <tr id="vendor-prod-row-<?= (int)$p['id'] ?>" class="border-b border-blackx3/50 hover:bg-blackx/40 transition">
              <td class="py-3 pr-3">#<?= (int)$p['id'] ?></td>
              <td class="py-3 pr-3">
                <img
                  src="<?= htmlspecialchars(vpThumbUrl((string)($p['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                  alt="thumb"
                  class="w-12 h-12 rounded-lg object-cover border border-blackx3"
                  onerror="this.onerror=null;this.src='https://placehold.co/80x80/111827/9ca3af?text=Sem+Img';"
                />
              </td>
              <td class="py-3 pr-3 max-w-[380px]">
                <span class="block truncate" title="<?= htmlspecialchars((string)$p['nome'], ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars((string)$p['nome'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td class="py-3 pr-3 font-medium">R$ <?= number_format((float)$p['preco'], 2, ',', '.') ?></td>
              <td class="py-3 pr-3">
                <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= ((int)$p['ativo'] === 1) ? 'bg-greenx/15 border border-greenx/40 text-greenx' : 'bg-orange-500/15 border border-orange-400/40 text-orange-300' ?>">
                  <?= ((int)$p['ativo'] === 1) ? 'Ativo' : 'Inativo' ?>
                </span>
              </td>
              <td class="py-3">
                <div class="flex items-center gap-2">
                  <a href="<?= BASE_PATH ?>/vendedor/produtos_form?id=<?= (int)$p['id'] ?>" class="inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 hover:border-greenx px-2.5 py-1.5 text-xs text-zinc-300 hover:text-white transition">
                    <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Editar
                  </a>
                  <button type="button" class="js-vendor-prod-delete inline-flex items-center gap-1 rounded-lg bg-red-500/10 border border-red-400/30 text-red-300 hover:bg-red-500/20 px-2.5 py-1.5 text-xs font-medium transition" title="Excluir produto" aria-label="Excluir produto" data-id="<?= (int)$p['id'] ?>">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($itens)): ?>
            <tr>
              <td colspan="6" class="py-6 text-zinc-500">Nenhum produto encontrado.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  function vendorToast(message, type = 'success') {
    let box = document.getElementById('vendor-toast');
    if (!box) {
      box = document.createElement('div');
      box.id = 'vendor-toast';
      box.className = 'fixed top-8 right-4 z-[9999] px-4 py-2 rounded-lg border text-sm shadow-lg transition-opacity duration-200 opacity-0';
      document.body.appendChild(box);
    }

    box.classList.remove('border-greenx/40','bg-greenx/10','text-greenx','border-red-500/40','bg-red-500/10','text-red-300');
    box.classList.add(...(type === 'error' ? ['border-red-500/40','bg-red-500/10','text-red-300'] : ['border-greenx/40','bg-greenx/10','text-greenx']));
    box.textContent = message;
    box.style.opacity = '1';
    clearTimeout(window.__vendorToastTimer);
    window.__vendorToastTimer = setTimeout(() => { box.style.opacity = '0'; }, 2400);
  }

  document.querySelectorAll('.js-vendor-prod-delete').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.id || 0);
      if (!id) return;
      if (!confirm('Excluir este produto? A exclusão só será concluída se ele não estiver vinculado a pedidos.')) return;

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

        const row = document.getElementById('vendor-prod-row-' + id);
        if (row) row.remove();
        vendorToast(data.msg || 'Produto excluído com sucesso.', 'success');
      } catch (e) {
        vendorToast(e.message || 'Erro ao excluir produto.', 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>
<?php

include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';

function vpThumbUrl(string $raw): string
{
    $raw = trim(str_replace('\\', '/', $raw));
    if ($raw === '') {
        return 'https://placehold.co/80x80/111827/9ca3af?text=Sem+Img';
    }

    // URL externa
    if (preg_match('~^https?://~i', $raw)) {
        return $raw;
    }

    if (str_starts_with($raw, 'media:')) {
      return mediaResolveUrl($raw, 'https://placehold.co/80x80/111827/9ca3af?text=Sem+Img');
    }

    // já é URL completa do projeto
    // padrão salvo no banco: /uploads/produtos/arquivo.png
    if (str_starts_with($raw, '/uploads/')) {
      return BASE_PATH . $raw;
    }

    // sem barra inicial: uploads/produtos/arquivo.png
    if (str_starts_with($raw, 'uploads/')) {
      return BASE_PATH . '/' . $raw;
    }

    // public/uploads/...
    if (str_starts_with($raw, 'public/')) {
      return BASE_PATH . '/' . substr($raw, 7);
    }

    // só nome de arquivo
    if (!str_contains($raw, '/')) {
      return BASE_PATH . '/uploads/produtos/' . $raw;
    }

    return BASE_PATH . '/' . ltrim($raw, '/');
}
