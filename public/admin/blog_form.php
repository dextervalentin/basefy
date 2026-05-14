<?php
declare(strict_types=1);
/**
 * Admin — Blog post create/edit form
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';

exigirAdmin();

require_once __DIR__ . '/../../src/blog.php';
require_once __DIR__ . '/../../src/media.php';

$conn = (new Database())->connect();
$userId = (int)$_SESSION['user_id'];
$postId = (int)($_GET['id'] ?? 0);
try {
    $post = $postId > 0 ? blogGetById($conn, $postId) : null;
} catch (\Throwable $e) {
    // Auto-create table if missing
    blogEnsureTable($conn);
    try {
        $post = $postId > 0 ? blogGetById($conn, $postId) : null;
    } catch (\Throwable $e2) {
        $post = null;
    }
}
$isEdit = $post !== null;

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo   = trim((string)($_POST['titulo'] ?? ''));
    $resumo   = trim((string)($_POST['resumo'] ?? ''));
    $conteudo = trim((string)($_POST['conteudo'] ?? ''));
    $status   = (string)($_POST['status'] ?? 'rascunho');
    $categoria = trim((string)($_POST['categoria'] ?? ''));
    $slugInput = trim((string)($_POST['slug'] ?? ''));
    $imagem   = trim((string)($post['imagem'] ?? ''));

    // Handle image upload via media library (base64 in DB — works on Railway)
    if (!empty($_FILES['imagem']['name']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $mediaId = mediaSaveFromUpload($_FILES['imagem'], 'blog', $postId > 0 ? $postId : 0, true);
            if ($mediaId) {
                $imagem = 'media:' . $mediaId;
            }
        }
    }

    if ($titulo === '' || $conteudo === '') {
        $err = 'Título e conteúdo são obrigatórios.';
    } else {
        try {
            if ($isEdit) {
                blogUpdate($conn, $postId, $titulo, $conteudo, $resumo, $imagem, $status, $categoria, $slugInput);
                $msg = 'Post atualizado!';
                $post = blogGetById($conn, $postId);
            } else {
                $newId = blogCreate($conn, $userId, $titulo, $conteudo, $resumo, $imagem, $status, $categoria);
                if ($newId) {
                    header('Location: ' . BASE_PATH . '/admin/blog_form.php?id=' . $newId . '&saved=1');
                    exit;
                }
                $err = 'Erro ao criar post.';
            }
        } catch (\Throwable $e) {
            // Auto-create table and retry
            if (blogEnsureTable($conn)) {
                try {
                    if ($isEdit) {
                        blogUpdate($conn, $postId, $titulo, $conteudo, $resumo, $imagem, $status, $categoria, $slugInput);
                        $msg = 'Post atualizado!';
                        $post = blogGetById($conn, $postId);
                    } else {
                        $newId = blogCreate($conn, $userId, $titulo, $conteudo, $resumo, $imagem, $status, $categoria);
                        if ($newId) {
                            header('Location: ' . BASE_PATH . '/admin/blog_form.php?id=' . $newId . '&saved=1');
                            exit;
                        }
                        $err = 'Erro ao criar post.';
                    }
                } catch (\Throwable $e2) {
                    $err = 'Erro ao salvar: ' . $e2->getMessage();
                }
            } else {
                $err = 'Não foi possível criar a tabela blog_posts automaticamente.';
            }
        }
    }
}

if (isset($_GET['saved'])) $msg = 'Post criado com sucesso!';

$pageTitle = ($isEdit ? 'Editar post' : 'Novo post') . ' — Blog Admin';
$activeMenu = 'conteudo';
include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 max-w-4xl">

        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 text-sm text-zinc-400 mb-6">
            <a href="<?= BASE_PATH ?>/admin/blog.php" class="hover:text-greenx transition-colors">Blog</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <span class="text-zinc-200"><?= $isEdit ? 'Editar post' : 'Novo post' ?></span>
        </div>

        <h1 class="text-2xl font-bold mb-6"><?= $isEdit ? 'Editar post' : 'Novo post' ?></h1>

        <?php if ($msg): ?>
        <div class="mb-4 px-4 py-3 rounded-xl bg-greenx/10 border border-greenx/20 text-greenx text-sm"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
        <div class="mb-4 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="space-y-6">
            <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 space-y-5">
                <!-- Title -->
                <div>
                    <label class="text-sm text-zinc-400 mb-1 block">Título *</label>
                    <input type="text" name="titulo" required maxlength="200"
                           value="<?= htmlspecialchars((string)($post['titulo'] ?? '')) ?>"
                           class="w-full rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-3 text-sm focus:border-greenx/50 focus:outline-none transition-colors"
                           placeholder="Título do post">
                </div>

                <!-- Slug -->
                <?php if ($isEdit): ?>
                <div>
                    <label class="text-sm text-zinc-400 mb-1 block">Slug (URL)</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-zinc-500">/blog/</span>
                        <input type="text" name="slug" maxlength="210"
                               value="<?= htmlspecialchars((string)($post['slug'] ?? '')) ?>"
                               class="flex-1 rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-3 text-sm focus:border-greenx/50 focus:outline-none transition-colors font-mono"
                               placeholder="slug-do-post">
                    </div>
                    <p class="text-xs text-zinc-600 mt-1">Gerado automaticamente a partir do título. Edite para personalizar.</p>
                </div>
                <?php endif; ?>

                <!-- Categoria -->
                <div>
                    <label class="text-sm text-zinc-400 mb-1 block">Categoria</label>
                    <?php
                    // Fetch managed blog categories from categories table
                    $managedCats = [];
                    try {
                        $stCat = $conn->prepare("SELECT nome FROM categories WHERE tipo = 'blog' AND ativo = TRUE ORDER BY nome ASC");
                        $stCat->execute();
                        $managedCats = array_column($stCat->get_result()->fetch_all(MYSQLI_ASSOC) ?: [], 'nome');
                        $stCat->close();
                    } catch (\Throwable $e) { $managedCats = []; }
                    // Also get any free-text categories from existing posts
                    try { $existingCats = blogGetCategories($conn); } catch (\Throwable $e) { $existingCats = []; }
                    // Merge and deduplicate
                    $allCats = array_values(array_unique(array_merge($managedCats, $existingCats)));
                    sort($allCats);
                    $currentCat = (string)($post['categoria'] ?? '');
                    ?>
                    <select name="categoria"
                            class="w-full sm:w-80 rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-3 text-sm focus:border-greenx/50 focus:outline-none transition-colors"
                            style="color:var(--t-text-primary,#e5e5e5)">
                        <option value="" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)">— Sem categoria —</option>
                        <?php foreach ($allCats as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"
                                style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)"
                                <?= $c === $currentCat ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-zinc-600 mt-1">Gerencie categorias em <a href="<?= BASE_PATH ?>/admin/blog_categorias.php" class="text-greenx hover:underline">Blog Categorias</a>.</p>
                </div>

                <!-- Summary -->
                <div>
                    <label class="text-sm text-zinc-400 mb-1 block">Resumo (exibido na listagem)</label>
                    <textarea name="resumo" rows="2" maxlength="400"
                              class="w-full rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-3 text-sm focus:border-greenx/50 focus:outline-none transition-colors resize-none"
                              placeholder="Breve resumo do post"><?= htmlspecialchars((string)($post['resumo'] ?? '')) ?></textarea>
                </div>

                <!-- Content -->
                <div>
                    <label class="text-sm text-zinc-400 mb-1 block">Conteúdo * (suporta HTML)</label>
                    <textarea name="conteudo" rows="14" required
                              class="w-full rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-3 text-sm focus:border-greenx/50 focus:outline-none transition-colors font-mono resize-y"
                              placeholder="Conteúdo do post (HTML permitido)"><?= htmlspecialchars((string)($post['conteudo'] ?? '')) ?></textarea>
                </div>

                <!-- Image -->
                <div>
                    <label class="text-sm text-zinc-400 mb-1 block">Imagem de capa</label>
                    <?php if (!empty($post['imagem'])):
                        $blogImgUrl = mediaResolveUrl((string)$post['imagem']);
                        if (!$blogImgUrl && !str_starts_with((string)$post['imagem'], 'media:')) {
                            $blogImgUrl = BASE_PATH . '/uploads/' . htmlspecialchars((string)$post['imagem']);
                        }
                    ?>
                    <div class="mb-2">
                        <img src="<?= htmlspecialchars($blogImgUrl) ?>"
                             class="w-40 h-24 object-cover rounded-xl border border-blackx3" alt="">
                    </div>
                    <?php endif; ?>
                    <input type="file" name="imagem" accept="image/*"
                           class="w-full rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-2.5 text-sm file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:bg-greenx/10 file:text-greenx file:font-semibold file:text-xs">
                </div>

                <!-- Status -->
                <div>
                    <label class="text-sm text-zinc-400 mb-1 block">Status</label>
                    <select name="status"
                            class="w-full sm:w-60 rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-3 text-sm focus:border-greenx/50 focus:outline-none transition-colors"
                            style="color:var(--t-text-primary, #e5e5e5)">
                        <option value="rascunho" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)" <?= ($post['status'] ?? '') === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                        <option value="publicado" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)" <?= ($post['status'] ?? '') === 'publicado' ? 'selected' : '' ?>>Publicado</option>
                        <option value="arquivado" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)" <?= ($post['status'] ?? '') === 'arquivado' ? 'selected' : '' ?>>Arquivado</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button class="flex items-center gap-2 bg-gradient-to-r from-greenx to-greenxd text-white font-bold px-6 py-3 rounded-xl text-sm hover:from-greenx2 hover:to-greenxd transition-all shadow-lg shadow-greenx/20">
                    <i data-lucide="save" class="w-4 h-4"></i> <?= $isEdit ? 'Salvar alterações' : 'Criar post' ?>
                </button>
                <a href="<?= BASE_PATH ?>/admin/blog.php" class="px-6 py-3 rounded-xl border border-blackx3 text-sm text-zinc-400 hover:text-white hover:border-zinc-600 transition-all">
                    Cancelar
                </a>
            </div>
        </form>

    </main>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
?>
