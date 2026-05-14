<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\categorias_form.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_categorias.php';
require_once __DIR__ . '/../../src/media.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();

$id = (int)($_GET['id'] ?? 0);
$editar = $id > 0 ? obterCategoriaPorId($conn, $id) : null;

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idPost = (int)($_POST['id'] ?? 0);

    // Handle image upload — store in media_files (DB) so it persists across deploys
    $imagemPath = null;
    if (!empty($_FILES['imagem']['tmp_name']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $catEntityId = $idPost > 0 ? $idPost : 0;
        $mediaId = mediaSaveFromUpload($_FILES['imagem'], 'category', $catEntityId, true);
        if ($mediaId) {
            $imagemPath = 'media:' . $mediaId;
        }
    } elseif (!empty($_POST['remover_imagem'])) {
        $imagemPath = '';
    }

    [$success, $msg] = salvarCategoria(
        $conn,
        $idPost,
        (string)($_POST['nome'] ?? ''),
        (string)($_POST['tipo'] ?? 'produto'),
        trim((string)($_POST['slug'] ?? '')),
        $imagemPath,
        isset($_POST['destaque'])
    );

    if ($success) {
        header('Location: categorias');
        exit;
    }
    $erro = $msg;
}

$pageTitle = $editar ? 'Editar categoria' : 'Nova categoria';
$activeMenu = 'catalogo';
$subnavItems = [
    ['label' => 'Listar', 'href' => 'categorias', 'active' => false],
    ['label' => 'Adicionar', 'href' => 'categorias_form', 'active' => !$editar],
    ['label' => 'Editar', 'href' => '#', 'active' => (bool)$editar],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="max-w-2xl mx-auto bg-blackx2 border border-blackx3 rounded-xl p-4">
  <?php if ($erro): ?><div class="mb-3 rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="space-y-3">
    <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
    <div>
      <label class="block text-sm mb-1 text-zinc-400 font-medium">Nome</label>
      <input name="nome" required value="<?= htmlspecialchars($editar['nome'] ?? '') ?>" placeholder="Nome da categoria" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx">
    </div>
    <div>
      <label class="block text-sm mb-1 text-zinc-400 font-medium">Slug <span class="text-zinc-600 font-normal">(gerado automaticamente do nome)</span></label>
      <input name="slug" maxlength="191" value="<?= htmlspecialchars($editar['slug'] ?? '') ?>" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx transition-colors" placeholder="ex: eletronicos">
    </div>
    <div>
      <label class="block text-sm mb-1 text-zinc-400 font-medium">Tipo</label>
      <select name="tipo" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx">
        <option value="produto" <?= ($editar['tipo'] ?? 'produto') === 'produto' ? 'selected' : '' ?>>produto</option>
        <option value="servico" <?= ($editar['tipo'] ?? '') === 'servico' ? 'selected' : '' ?>>serviço</option>
      </select>
    </div>
    <label class="flex items-start gap-3 rounded-xl border border-purple-500/25 bg-purple-500/[0.06] p-3 cursor-pointer hover:bg-purple-500/[0.10] transition">
      <input type="checkbox" name="destaque" value="1" <?= !empty($editar['destaque']) ? 'checked' : '' ?> class="mt-1 h-4 w-4 rounded border-blackx3 accent-purple-500">
      <span>
        <span class="block text-sm font-semibold text-zinc-200">Categoria destaque na home</span>
        <span class="block text-xs text-zinc-500 mt-0.5">Categorias destacadas aparecem primeiro na home. Novas categorias ativas continuam aparecendo automaticamente.</span>
      </span>
    </label>
    <div>
      <label class="block text-sm mb-1 text-zinc-400 font-medium">Imagem destaque</label>
      <?php
        $catImg = trim((string)($editar['imagem'] ?? ''));
        $catImgUrl = '';
        if ($catImg !== '') {
            require_once __DIR__ . '/../../src/storefront.php';
            $catImgUrl = sfImageUrl($catImg);
        }
      ?>
      <div id="cat-img-upload" class="relative rounded-xl border-2 border-dashed border-blackx3 bg-blackx hover:border-greenx/40 transition-all overflow-hidden">
        <?php if ($catImgUrl): ?>
        <div id="cat-img-preview" class="relative group">
          <img src="<?= htmlspecialchars($catImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Imagem atual" class="w-full h-40 object-cover rounded-lg">
          <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-3">
            <label class="px-3 py-1.5 rounded-lg bg-greenx/20 text-greenx text-xs font-semibold cursor-pointer hover:bg-greenx/30 transition">
              <i data-lucide="upload" class="w-3.5 h-3.5 inline mr-1"></i>Trocar
              <input type="file" name="imagem" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" onchange="catImgChanged(this)">
            </label>
            <label class="px-3 py-1.5 rounded-lg bg-red-500/20 text-red-400 text-xs font-semibold cursor-pointer hover:bg-red-500/30 transition">
              <i data-lucide="trash-2" class="w-3.5 h-3.5 inline mr-1"></i>Remover
              <input type="checkbox" name="remover_imagem" value="1" class="hidden" onchange="catImgRemove()">
            </label>
          </div>
          <div class="absolute top-2 right-2 px-2 py-0.5 rounded bg-black/70 text-[10px] text-zinc-300 font-medium">Imagem atual</div>
        </div>
        <?php else: ?>
        <label id="cat-img-dropzone" class="flex flex-col items-center justify-center py-8 px-4 cursor-pointer group">
          <div class="w-12 h-12 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center mb-3 group-hover:bg-greenx/20 group-hover:scale-110 transition-all">
            <i data-lucide="image-plus" class="w-5 h-5 text-greenx"></i>
          </div>
          <span class="text-sm font-medium text-zinc-400 group-hover:text-zinc-300 transition">Clique ou arraste uma imagem</span>
          <span class="text-[11px] text-zinc-600 mt-1">JPG, PNG, WebP ou GIF — Máx 5MB</span>
          <input type="file" name="imagem" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" onchange="catImgChanged(this)">
        </label>
        <?php endif; ?>
        <div id="cat-img-new-preview" class="hidden relative">
          <img id="cat-img-new-thumb" src="" alt="Preview" class="w-full h-40 object-cover rounded-lg">
          <div class="absolute top-2 right-2 px-2 py-0.5 rounded bg-greenx/80 text-[10px] text-white font-medium">Nova imagem</div>
        </div>
      </div>
      <p class="text-[11px] text-zinc-600 mt-1.5">Usada como fundo na listagem de categorias da home.</p>
    </div>
    <script>
    function catImgChanged(input) {
      if (!input.files || !input.files[0]) return;
      var reader = new FileReader();
      reader.onload = function(e) {
        var prev = document.getElementById('cat-img-preview');
        if (prev) prev.style.display = 'none';
        var dropzone = document.getElementById('cat-img-dropzone');
        if (dropzone) dropzone.style.display = 'none';
        var np = document.getElementById('cat-img-new-preview');
        np.classList.remove('hidden');
        document.getElementById('cat-img-new-thumb').src = e.target.result;
      };
      reader.readAsDataURL(input.files[0]);
    }
    function catImgRemove() {
      var prev = document.getElementById('cat-img-preview');
      if (prev) prev.style.display = 'none';
    }
    </script>
    <button class="w-full rounded-lg bg-greenx hover:bg-greenx2 text-white font-semibold py-2.5 transition">Salvar</button>
  </form>
</div>

<script src="<?= BASE_PATH ?>/assets/js/slug-checker.js"></script>
<script>
  initSlugChecker({
    inputSelector: 'input[name="slug"]',
    nameSelector:  'input[name="nome"]',
    type:          'category',
    excludeId:     <?= (int)($editar['id'] ?? 0) ?>
  });
</script>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>