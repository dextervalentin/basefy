<?php
declare(strict_types=1);
/**
 * Admin — Blog Categories management (CRUD)
 * Uses the existing `categories` table with tipo='blog'
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';

exigirAdmin();

$conn = (new Database())->connect();
$msg = '';
$err = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome === '') {
            $err = 'Nome da categoria é obrigatório.';
        } else {
            try {
                $st = $conn->prepare("INSERT INTO categories (nome, tipo, ativo) VALUES (?, 'blog', TRUE)");
                $st->bind_param('s', $nome);
                $st->execute();
                $st->close();
                $msg = 'Categoria criada!';
            } catch (\Throwable $e) {
                $err = 'Erro: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'update') {
        $catId = (int)($_POST['cat_id'] ?? 0);
        $nome  = trim((string)($_POST['nome'] ?? ''));
        $ativo = ($_POST['ativo'] ?? '1') === '1';
        if ($catId > 0 && $nome !== '') {
            try {
                $st = $conn->prepare("UPDATE categories SET nome = ?, ativo = ? WHERE id = ? AND tipo = 'blog'");
                $at = $ativo ? 1 : 0;
                $st->bind_param('sii', $nome, $at, $catId);
                $st->execute();
                $st->close();
                $msg = 'Categoria atualizada!';
            } catch (\Throwable $e) {
                $err = 'Erro: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        $catId = (int)($_POST['cat_id'] ?? 0);
        if ($catId > 0) {
            try {
                $st = $conn->prepare("DELETE FROM categories WHERE id = ? AND tipo = 'blog'");
                $st->bind_param('i', $catId);
                $st->execute();
                $st->close();
                $msg = 'Categoria excluída!';
            } catch (\Throwable $e) {
                $code = (string)$e->getCode();
                $raw = mb_strtolower($e->getMessage());
                if ($code === '23503' || str_contains($raw, 'foreign key') || str_contains($raw, 'violates foreign key constraint')) {
                    $err = 'Não é possível excluir esta categoria porque ela está vinculada a outros registros.';
                } else {
                    $err = 'Erro ao excluir categoria.';
                }
            }
        }
    }
}

// Fetch blog categories with pagination
$catPage = max(1, (int)($_GET['p'] ?? 1));
$pp      = in_array(($_ppC = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_ppC : 10;
$blogCats = [];
$blogCatsTotal = 0;
try {
    // Count
    $stCnt = $conn->prepare("SELECT COUNT(*) AS total FROM categories WHERE tipo = 'blog'");
    $stCnt->execute();
    $blogCatsTotal = (int)($stCnt->get_result()->fetch_assoc()['total'] ?? 0);
    $stCnt->close();

    $catOffset = ($catPage - 1) * $pp;
    $st = $conn->prepare("SELECT id, nome, ativo, criado_em FROM categories WHERE tipo = 'blog' ORDER BY nome ASC LIMIT $pp OFFSET $catOffset");
    $st->execute();
    $blogCats = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();
} catch (\Throwable $e) {
    $err = 'Erro ao listar categorias: ' . $e->getMessage();
}
$catTotalPages = max(1, (int)ceil($blogCatsTotal / $pp));

// Count posts per category
$catCounts = [];
try {
    $stC = $conn->prepare(
        "SELECT c.id, COUNT(b.id) AS total
         FROM categories c
         LEFT JOIN blog_posts b ON LOWER(b.categoria) = LOWER(c.nome) AND b.status = 'publicado'
         WHERE c.tipo = 'blog'
         GROUP BY c.id"
    );
    $stC->execute();
    $rows = $stC->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stC->close();
    foreach ($rows as $r) $catCounts[(int)$r['id']] = (int)$r['total'];
} catch (\Throwable $e) {}

$pageTitle  = 'Conteúdo';
$activeMenu = 'conteudo';
$adminContentTab = 'categorias';
include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

    <main class="flex-1 p-4 sm:p-6 lg:p-8">
        <?php include __DIR__ . '/../../views/partials/admin_conteudo_tabs.php'; ?>

        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="flex items-center gap-2 text-sm text-zinc-400 mb-2">
                    <a href="<?= BASE_PATH ?>/admin/blog.php" class="hover:text-greenx transition-colors">Blog</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                    <span class="text-zinc-200">Categorias</span>
                </div>
                <h1 class="text-2xl font-bold">Categorias do Blog</h1>
                <p class="text-sm text-zinc-400 mt-1">Gerencie as categorias dos posts do blog</p>
            </div>
            <a href="<?= BASE_PATH ?>/admin/blog.php"
               class="flex items-center gap-2 border border-blackx3 px-4 py-2 rounded-xl text-sm text-zinc-300 hover:border-greenx hover:text-white transition-all">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Voltar ao Blog
            </a>
        </div>

        <?php if ($msg): ?>
        <div class="mb-6 px-4 py-3 rounded-xl bg-greenx/10 border border-greenx/20 text-greenx text-sm"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
        <div class="mb-6 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Create form -->
            <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6">
                <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
                    <i data-lucide="plus-circle" class="w-5 h-5 text-greenx"></i>
                    Nova categoria
                </h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="text-sm text-zinc-400 mb-1 block">Nome</label>
                        <input type="text" name="nome" required maxlength="120" placeholder="Ex: Novidades, Tutoriais, Dicas"
                               class="w-full rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-3 text-sm focus:border-greenx/50 focus:outline-none transition-colors">
                    </div>
                    <button class="flex items-center gap-2 bg-gradient-to-r from-greenx to-greenxd text-white font-bold px-5 py-2.5 rounded-xl text-sm hover:from-greenx2 hover:to-greenxd transition-all shadow-lg shadow-greenx/20">
                        <i data-lucide="plus" class="w-4 h-4"></i> Criar categoria
                    </button>
                </form>
            </div>

            <!-- Categories list -->
            <div class="lg:col-span-2 bg-blackx2 border border-blackx3 rounded-2xl overflow-hidden">
                <div class="px-6 py-4 border-b border-blackx3 flex items-center justify-between">
                    <h2 class="font-bold">Categorias (<?= $blogCatsTotal ?>)</h2>
                </div>

                <?php if (empty($blogCats)): ?>
                <div class="p-8 text-center text-zinc-500 text-sm">
                    <i data-lucide="tag" class="w-12 h-12 mx-auto mb-3 text-zinc-600"></i>
                    <p>Nenhuma categoria criada ainda.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-left border-b border-blackx3">
                            <th class="px-6 py-3 text-zinc-500 font-medium">Nome</th>
                            <th class="px-6 py-3 text-zinc-500 font-medium">Posts</th>
                            <th class="px-6 py-3 text-zinc-500 font-medium">Status</th>
                            <th class="px-6 py-3 text-zinc-500 font-medium">Data</th>
                            <th class="px-6 py-3 text-zinc-500 font-medium">Ações</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-blackx3">
                        <?php foreach ($blogCats as $cat):
                            $cCount = $catCounts[(int)$cat['id']] ?? 0;
                            $isActive = (int)($cat['ativo'] ?? 1) === 1;
                        ?>
                        <tr class="hover:bg-white/[0.02] transition-colors" id="cat-<?= (int)$cat['id'] ?>">
                            <td class="px-6 py-3">
                                <form method="post" class="flex items-center gap-2" id="editForm<?= (int)$cat['id'] ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">
                                    <input type="hidden" name="ativo" value="<?= $isActive ? '1' : '0' ?>">
                                    <input type="text" name="nome" value="<?= htmlspecialchars((string)$cat['nome'], ENT_QUOTES, 'UTF-8') ?>" required maxlength="120"
                                           class="bg-transparent border border-transparent hover:border-white/[0.08] focus:border-greenx/50 rounded-lg px-2 py-1 text-sm font-semibold focus:outline-none transition-all w-full max-w-[200px]"
                                           onchange="this.form.submit()">
                                </form>
                            </td>
                            <td class="px-6 py-3">
                                <span class="px-2 py-0.5 rounded-lg text-xs font-medium bg-greenx/10 text-purple-400 border border-greenx/20"><?= $cCount ?></span>
                            </td>
                            <td class="px-6 py-3">
                                <form method="post" class="inline">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">
                                    <input type="hidden" name="nome" value="<?= htmlspecialchars((string)$cat['nome'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="ativo" value="<?= $isActive ? '0' : '1' ?>">
                                    <button class="px-2.5 py-1 rounded-lg text-xs font-semibold transition-all <?= $isActive ? 'bg-greenx/10 text-greenx hover:bg-greenx/20' : 'bg-zinc-500/10 text-zinc-400 hover:bg-zinc-500/20' ?>">
                                        <?= $isActive ? 'Ativa' : 'Inativa' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="px-6 py-3 text-zinc-500"><?= date('d/m/Y', strtotime((string)$cat['criado_em'])) ?></td>
                            <td class="px-6 py-3">
                                <form method="post" onsubmit="return confirm('Excluir a categoria \'<?= htmlspecialchars((string)$cat['nome'], ENT_QUOTES, 'UTF-8') ?>\'?')" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">
                                    <button class="p-1.5 rounded-lg hover:bg-red-500/10 text-zinc-400 hover:text-red-400 transition-all" title="Excluir">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php
                  $paginaAtual  = $catPage;
                  $totalPaginas = $catTotalPages;
                  include __DIR__ . '/../../views/partials/pagination.php';
                ?>
            </div>
        </div>

    </main>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
?>
