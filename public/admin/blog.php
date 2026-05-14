<?php
declare(strict_types=1);
/**
 * Admin — Blog management (list + settings)
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';

exigirAdmin();

require_once __DIR__ . '/../../src/blog.php';

$conn = (new Database())->connect();

// Handle table creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_table') {
    try {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/sql/blog.sql');
        if ($sql) {
            $conn->query($sql);
            $msg = 'Tabela blog_posts criada com sucesso!';
        }
    } catch (\Throwable $e) {
        $msg = 'Erro ao criar tabela: ' . $e->getMessage();
    }
}

// Handle settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    try {
        blogSaveSettings($conn, [
            'enabled'          => ($_POST['enabled'] ?? '0'),
            'visible_usuario'  => ($_POST['visible_usuario'] ?? '0'),
            'visible_vendedor' => ($_POST['visible_vendedor'] ?? '0'),
            'visible_admin'    => ($_POST['visible_admin'] ?? '0'),
            'visible_public'   => ($_POST['visible_public'] ?? '0'),
        ]);
        $msg = 'Configurações salvas!';
    } catch (\Throwable $e) {
        $msg = 'Erro: tabela blog_posts não existe. Execute sql/blog.sql no banco.';
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId > 0) blogDelete($conn, $postId);
    } catch (\Throwable $e) {}
}

// Settings live in platform_settings — always available
$settings = blogGetAllSettings($conn);

// Posts live in blog_posts — auto-create if missing
$filterStatus = (string)($_GET['status'] ?? 'all');
$filterCat    = (string)($_GET['cat'] ?? '');
$filterQ      = (string)($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['p'] ?? 1));
$pp           = in_array(($_ppB = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_ppB : 10;
$perPage      = $pp;
$posts        = [];
$totalPosts   = 0;
$categories   = [];

try {
    blogEnsureTable($conn);
    $categories = blogGetCategories($conn);
    $totalPosts = blogCount($conn, $filterStatus);
    $posts      = blogList($conn, $filterStatus, $perPage, ($page - 1) * $perPage);
    // Apply category + search filter in PHP (simple approach for small datasets)
    if ($filterCat !== '' || $filterQ !== '') {
        // Re-fetch ALL for filtering
        $allPosts = blogList($conn, $filterStatus, 9999);
        if ($filterCat !== '') {
            $allPosts = array_filter($allPosts, fn($p) => ($p['categoria'] ?? '') === $filterCat);
        }
        if ($filterQ !== '') {
            $q = mb_strtolower($filterQ);
            $allPosts = array_filter($allPosts, fn($p) => str_contains(mb_strtolower($p['titulo']), $q) || str_contains(mb_strtolower($p['slug'] ?? ''), $q));
        }
        $totalPosts = count($allPosts);
        $posts = array_slice(array_values($allPosts), ($page - 1) * $perPage, $perPage);
    }
} catch (\Throwable $e) {
    if (blogEnsureTable($conn)) {
        try {
            $posts = blogList($conn, 'all', $perPage);
        } catch (\Throwable $e2) {
            $msg = $msg ?? 'Erro ao listar posts: ' . $e2->getMessage();
        }
    } else {
        $msg = $msg ?? 'Tabela blog_posts não encontrada. Clique "Criar tabela" ou execute sql/blog.sql no banco.';
    }
}
$totalPages = max(1, (int)ceil($totalPosts / $perPage));
$pageTitle = 'Conteúdo';
$activeMenu = 'conteudo';
$adminContentTab = 'blog';
include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

    <main class="flex-1 p-4 sm:p-6 lg:p-8">
        <?php include __DIR__ . '/../../views/partials/admin_conteudo_tabs.php'; ?>

        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold">Blog</h1>
                <p class="text-sm text-zinc-400 mt-1">Gerencie posts e configurações de visibilidade</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= BASE_PATH ?>/admin/blog_categorias.php"
                   class="flex items-center gap-2 border border-blackx3 px-4 py-2.5 rounded-xl text-sm text-zinc-300 hover:border-greenx hover:text-white transition-all">
                    <i data-lucide="tags" class="w-4 h-4"></i> Categorias
                </a>
                <a href="<?= BASE_PATH ?>/admin/blog_form.php"
                   class="flex items-center gap-2 bg-gradient-to-r from-greenx to-greenxd text-white font-bold px-5 py-2.5 rounded-xl text-sm hover:from-greenx2 hover:to-greenxd transition-all shadow-lg shadow-greenx/20">
                    <i data-lucide="plus" class="w-4 h-4"></i> Novo post
                </a>
            </div>
        </div>

        <?php if (!empty($msg ?? '')): ?>
        <div class="mb-6 px-4 py-3 rounded-xl bg-greenx/10 border border-greenx/20 text-greenx text-sm flex items-center justify-between gap-3">
            <span><?= htmlspecialchars($msg) ?></span>
            <?php if (str_contains($msg, 'Criar tabela') || str_contains($msg, 'não encontrada')): ?>
            <form method="post" class="shrink-0">
                <input type="hidden" name="action" value="create_table">
                <button class="px-4 py-1.5 rounded-lg bg-greenx text-white font-bold text-xs hover:bg-greenx2 transition-all">Criar tabela</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Settings panel -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 mb-8">
            <h2 class="text-lg font-bold mb-4">Configurações de visibilidade</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="action" value="save_settings">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <?php
                    $toggles = [
                        'enabled'          => ['Blog ativo no site', 'Ativa ou desativa as páginas e listagens do blog'],
                        'visible_public'   => ['Visitantes', 'Visível para não logados'],
                        'visible_usuario'  => ['Usuários', 'Visível para compradores'],
                        'visible_vendedor' => ['Vendedores', 'Visível para vendedores'],
                        'visible_admin'    => ['Admins', 'Visível para administradores'],
                    ];
                    foreach ($toggles as $key => [$label, $desc]):
                        $checked = ($settings[$key] ?? '0') === '1';
                    ?>
                    <label class="flex flex-col gap-2 bg-white/[0.03] rounded-xl border border-white/[0.06] p-4 cursor-pointer hover:border-greenx/30 transition-all">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold"><?= $label ?></span>
                            <input type="hidden" name="<?= $key ?>" value="0">
                            <input type="checkbox" name="<?= $key ?>" value="1" <?= $checked ? 'checked' : '' ?>
                                   class="w-5 h-5 rounded accent-greenx bg-blackx border-blackx3">
                        </div>
                        <span class="text-xs text-zinc-500"><?= $desc ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button class="mt-2 px-6 py-2.5 rounded-xl bg-greenx/10 border border-greenx/20 text-greenx font-semibold text-sm hover:bg-greenx/20 transition-all">
                    Salvar configurações
                </button>
            </form>
        </div>

        <!-- Posts list -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl overflow-hidden">
            <div class="px-6 py-4 border-b border-blackx3 flex items-center justify-between">
                <h2 class="font-bold">Posts (<?= $totalPosts ?>)</h2>
            </div>

            <!-- Premium Filter -->
            <form method="get" class="m-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
              <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
                <div class="md:flex-1">
                  <label class="block text-xs text-zinc-500 mb-1">Busca</label>
                  <input name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="Título ou slug" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
                </div>
                <div class="md:w-40">
                  <label class="block text-xs text-zinc-500 mb-1">Status</label>
                  <select name="status" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx" style="color:var(--t-text-primary,#e5e5e5)">
                    <option value="all" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)" <?= $filterStatus==='all'?'selected':'' ?>>Todos</option>
                    <option value="publicado" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)" <?= $filterStatus==='publicado'?'selected':'' ?>>Publicado</option>
                    <option value="rascunho" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)" <?= $filterStatus==='rascunho'?'selected':'' ?>>Rascunho</option>
                    <option value="arquivado" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)" <?= $filterStatus==='arquivado'?'selected':'' ?>>Arquivado</option>
                  </select>
                </div>
                <?php if ($categories): ?>
                <div class="md:w-44">
                  <label class="block text-xs text-zinc-500 mb-1">Categoria</label>
                  <select name="cat" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 outline-none focus:border-greenx" style="color:var(--t-text-primary,#e5e5e5)">
                    <option value="" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)">Todas</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" style="background:var(--t-bg-card,#1a1a2e);color:var(--t-text-primary,#e5e5e5)" <?= $filterCat===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
                <div class="flex items-center gap-2">
                  <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
                    <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Filtrar
                  </button>
                  <a href="blog" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                  </a>
                </div>
              </div>
            </form>
            <?php if (empty($posts)): ?>
            <div class="p-8 text-center text-zinc-500 text-sm">
                <i data-lucide="file-text" class="w-12 h-12 mx-auto mb-3 text-zinc-600"></i>
                <p>Nenhum post criado ainda.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                    <tr class="text-left border-b border-blackx3">
                        <th class="px-6 py-3 text-zinc-500 font-medium">Título</th>
                        <th class="px-6 py-3 text-zinc-500 font-medium">Categoria</th>
                        <th class="px-6 py-3 text-zinc-500 font-medium">Status</th>
                        <th class="px-6 py-3 text-zinc-500 font-medium">Autor</th>
                        <th class="px-6 py-3 text-zinc-500 font-medium">Views</th>
                        <th class="px-6 py-3 text-zinc-500 font-medium">Data</th>
                        <th class="px-6 py-3 text-zinc-500 font-medium">Ação</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-blackx3">
                    <?php foreach ($posts as $post): ?>
                    <tr class="row-link hover:bg-white/[0.02]"
                        data-href="<?= BASE_PATH ?>/admin/blog_form.php?id=<?= (int)$post['id'] ?>" tabindex="0">
                        <td class="px-6 py-3">
                            <p class="font-semibold truncate max-w-[300px]"><?= htmlspecialchars((string)$post['titulo']) ?></p>
                            <p class="text-xs text-zinc-500">/blog/<?= htmlspecialchars((string)$post['slug']) ?></p>
                        </td>
                        <td class="px-6 py-3">
                            <?php if (!empty($post['categoria'])): ?>
                            <span class="px-2 py-0.5 rounded-lg text-xs font-medium bg-greenx/10 text-purple-400 border border-greenx/20"><?= htmlspecialchars((string)$post['categoria']) ?></span>
                            <?php else: ?>
                            <span class="text-zinc-600 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-3">
                            <?php
                            $sc = ['publicado' => 'bg-greenx/10 text-greenx', 'rascunho' => 'bg-yellow-500/10 text-yellow-400', 'arquivado' => 'bg-zinc-500/10 text-zinc-400'];
                            $sLabel = ['publicado' => 'Publicado', 'rascunho' => 'Rascunho', 'arquivado' => 'Arquivado'];
                            ?>
                            <span class="px-2.5 py-1 rounded-lg text-xs font-semibold <?= $sc[$post['status']] ?? '' ?>">
                                <?= $sLabel[$post['status']] ?? $post['status'] ?>
                            </span>
                        </td>
                        <td class="px-6 py-3 text-zinc-400"><?= htmlspecialchars((string)$post['author_nome']) ?></td>
                        <td class="px-6 py-3 text-zinc-400"><?= number_format((int)$post['visualizacoes']) ?></td>
                        <td class="px-6 py-3 text-zinc-500"><?= date('d/m/Y', strtotime((string)$post['criado_em'])) ?></td>
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-2">
                                <a href="<?= BASE_PATH ?>/admin/blog_form.php?id=<?= (int)$post['id'] ?>"
                                   class="p-1.5 rounded-lg hover:bg-greenx/10 text-zinc-400 hover:text-greenx transition-all" title="Editar">
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                </a>
                                <form method="post" onsubmit="return confirm('Excluir este post?')" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                                    <button class="p-1.5 rounded-lg hover:bg-red-500/10 text-zinc-400 hover:text-red-400 transition-all" title="Excluir">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($totalPages > 1 || true): ?>
            <div class="px-6 py-4 border-t border-blackx3">
            <?php
              $paginaAtual  = $page;
              $totalPaginas = $totalPages;
              include __DIR__ . '/../../views/partials/pagination.php';
            ?>
            </div>
            <?php endif; ?>
        </div>

    </main>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
?>
