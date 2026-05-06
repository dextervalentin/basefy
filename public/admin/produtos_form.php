<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\produtos_form.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_produtos.php';
require_once __DIR__ . '/../../src/upload_paths.php';
require_once __DIR__ . '/../../src/media.php';

exigirAdmin();

$db = new Database();
$conn = $db->connect();

function brToFloat(string $v): float {
    $v = trim($v);
    if ($v === '') return 0.0;
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)preg_replace('/[^\d.]/', '', $v);
}

$id = (int)($_GET['id'] ?? 0);
$produto = $id > 0 ? obterProdutoPorId($conn, $id) : null;

$categorias = listarCategoriasProdutoAtivas($conn);
$vendedores = listarVendedoresAprovados($conn);

// Load existing gallery images
$galleryImages = [];
if ($produto) {
    $galleryImages = mediaListByEntity('product_gallery', (int)$produto['id']);
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idPost = (int)($_POST['id'] ?? 0);

    // --- Save cover image to DB ---
    $imagemFinal = null;
    if (isset($_FILES['imagem']) && ($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $mediaId = mediaSaveFromUpload($_FILES['imagem'], 'product', $idPost > 0 ? $idPost : 0, true);
        if ($mediaId) {
            $imagemFinal = 'media:' . $mediaId;
        }
    }
    if (!$imagemFinal) {
        $imagemFinal = (string)($_POST['imagem_atual'] ?? '');
    }

    $preco = isset($_POST['preco']) ? (float)$_POST['preco'] : brToFloat((string)($_POST['preco_display'] ?? '0,00'));

    $tipo = (string)($_POST['tipo'] ?? 'produto');
    $quantidade = (int)($_POST['quantidade'] ?? 0);
    $prazoEntregaDias = ($_POST['prazo_entrega_dias'] ?? '') !== '' ? (int)$_POST['prazo_entrega_dias'] : null;
    $dataEntrega = trim((string)($_POST['data_entrega'] ?? '')) !== '' ? (string)$_POST['data_entrega'] : null;
    $customSlug = trim((string)($_POST['slug'] ?? ''));
    $variantes = trim((string)($_POST['variantes'] ?? '')) !== '' ? (string)$_POST['variantes'] : null;
    $productFeeOverrideEnabled = !empty($_POST['product_fee_override_enabled']);
    $productFeePercent = trim((string)($_POST['product_fee_percent'] ?? '')) !== '' ? (float)str_replace(',', '.', (string)$_POST['product_fee_percent']) : null;

    [$ok, $msg] = salvarProduto(
        $conn, $idPost,
        (int)($_POST['vendedor_id'] ?? 0),
        (int)($_POST['categoria_id'] ?? 0),
        (string)($_POST['nome'] ?? ''),
        (string)($_POST['descricao'] ?? ''),
        $preco, $imagemFinal,
        $tipo, $quantidade, $prazoEntregaDias, $dataEntrega, $customSlug, $variantes,
        isset($_POST['destaque']),
        $productFeeOverrideEnabled,
        $productFeePercent
    );

    if ($ok) {
        // Determine product ID for gallery
        $productId = (int)$idPost;
        if ($productId <= 0) {
            $lastRow = $conn->query("SELECT MAX(id) AS last_id FROM products")->fetch_assoc();
            $productId = (int)($lastRow['last_id'] ?? 0);
        }

        // Fix entity_id for cover image on new product
        if ($idPost <= 0 && $imagemFinal && str_starts_with($imagemFinal, 'media:')) {
            $coverId = (int)substr($imagemFinal, 6);
            $stUp = $conn->prepare("UPDATE media_files SET entity_id = ? WHERE id = ?");
            $stUp->bind_param('ii', $productId, $coverId);
            $stUp->execute(); $stUp->close();
            $stUp2 = $conn->prepare("UPDATE products SET imagem = ? WHERE id = ?");
            $ref = 'media:' . $coverId;
            $stUp2->bind_param('si', $ref, $productId);
            $stUp2->execute(); $stUp2->close();
        }

        // Save gallery images to DB
        if (!empty($_FILES['gallery']['name'][0]) && $_FILES['gallery']['name'][0] !== '') {
            $count = count($_FILES['gallery']['name']);
            for ($i = 0; $i < $count; $i++) {
                $galleryFile = [
                    'name' => $_FILES['gallery']['name'][$i],
                    'type' => $_FILES['gallery']['type'][$i],
                    'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                    'error' => $_FILES['gallery']['error'][$i],
                    'size' => $_FILES['gallery']['size'][$i],
                ];
                mediaSaveFromUpload($galleryFile, 'product_gallery', (int)$productId, false, $i);
            }
        }

        // Delete gallery images marked for deletion
        $deleteIds = $_POST['delete_gallery'] ?? [];
        if (is_array($deleteIds)) {
            foreach ($deleteIds as $delId) { mediaDeleteForEntity((int)$delId, 'product_gallery', (int)$productId); }
        }

        // Redirect to edit form of the saved product (PRG pattern)
        $savedId = $idPost > 0 ? $idPost : $productId;
        header('Location: produtos_form?id=' . $savedId . '&saved=1');
        exit;
    }
    $erro = $msg;
}

$pageTitle = $produto ? 'Editar Produto' : 'Novo Produto';
$activeMenu = 'produtos';
$topActions = [['label' => 'Voltar', 'href' => 'produtos']];
$subnavItems = [
    ['label' => 'Listar', 'href' => 'produtos', 'active' => false],
    ['label' => $produto ? 'Editar' : 'Adicionar', 'href' => '#', 'active' => true],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';

$imgAtual = $produto ? mediaResolveUrl((string)($produto['imagem'] ?? '')) : '';
$precoInicial = isset($produto['preco']) ? number_format((float)$produto['preco'], 2, ',', '.') : '0,00';
$tipoAtual = (string)($produto['tipo'] ?? 'produto');
$qtdAtual = (int)($produto['quantidade'] ?? 1);
if (!$produto && $qtdAtual < 1) $qtdAtual = 1;
$variantesAtual = $produto ? (string)($produto['variantes'] ?? '[]') : '[]';
if (!$variantesAtual || $variantesAtual === '') $variantesAtual = '[]';
$prazoAtual = $produto['prazo_entrega_dias'] ?? '';
$dataEntregaAtual = $produto['data_entrega'] ?? '';
$productFeeOverrideAtual = !empty($produto['product_fee_override_enabled']);
$productFeePercentAtual = $produto['product_fee_percent'] ?? '';
?>

<!-- Quill.js CDN (100% free, MIT license) -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<style>
    .ql-toolbar.ql-snow{background:#111214;border-color:#1A1C20!important;border-radius:12px 12px 0 0}
    .ql-container.ql-snow{background:#0B0B0C;border-color:#1A1C20!important;border-radius:0 0 12px 12px;min-height:200px;color:#d4d4d8;font-size:14px}
    .ql-editor{min-height:200px;line-height:1.6}.ql-editor.ql-blank::before{color:#52525b;font-style:normal}
    .ql-snow .ql-stroke{stroke:#a1a1aa}.ql-snow .ql-fill{fill:#a1a1aa}.ql-snow .ql-picker{color:#a1a1aa}
    .ql-snow .ql-picker-options{background:#111214;border-color:#1A1C20}
    .ql-snow .ql-picker-label:hover,.ql-snow .ql-picker-item:hover{color:var(--t-accent)}
    .ql-snow .ql-active .ql-stroke{stroke:var(--t-accent)}.ql-snow .ql-active .ql-fill{fill:var(--t-accent)}.ql-snow .ql-active{color:var(--t-accent)}
    .ql-snow a{color:var(--t-accent)}
    /* Quill editor overrides */
    .light-mode .ql-toolbar.ql-snow{background:#f4f4f5;border-color:#d4d4d8!important}
    .light-mode .ql-container.ql-snow{background:#fff;border-color:#d4d4d8!important;color:#18181b}
    .light-mode .ql-editor.ql-blank::before{color:#a1a1aa}
    .light-mode .ql-snow .ql-stroke{stroke:#52525b}.light-mode .ql-snow .ql-fill{fill:#52525b}.light-mode .ql-snow .ql-picker{color:#52525b}
    .light-mode .ql-snow .ql-picker-options{background:#fff;border-color:#d4d4d8}
</style>

<div class="max-w-4xl mx-auto" x-data="produtoForm()">
    <div class="mb-4 rounded-2xl border border-blackx3 bg-blackx2 p-5 md:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-widest text-zinc-500 font-semibold">Gerenciamento</p>
                <h2 class="text-xl md:text-2xl font-bold mt-1"><?= $produto ? 'Editar produto/serviço' : 'Novo produto/serviço' ?></h2>
                <p class="text-sm text-zinc-400 mt-1">Configure todos os detalhes para publicar com qualidade profissional.</p>
            </div>
            <a href="produtos" class="inline-flex items-center gap-2 rounded-xl border border-blackx3 bg-blackx px-3.5 py-2 text-sm text-zinc-300 hover:border-greenx hover:text-white transition-all">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Voltar
            </a>
        </div>
    </div>

    <?php if ($erro): ?>
    <div class="mb-4 rounded-xl border border-red-500/40 bg-red-500/10 text-red-300 px-4 py-3 text-sm">
        <div class="flex items-center gap-2"><i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0"></i> <?= htmlspecialchars($erro) ?></div>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['saved'])): ?>
    <div class="mb-4 rounded-xl border border-greenx/40 bg-greenx/10 text-greenx px-4 py-3 text-sm">
        <div class="flex items-center gap-2"><i data-lucide="check-circle" class="w-4 h-4 flex-shrink-0"></i> Produto salvo com sucesso!</div>
    </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="space-y-5" id="produto-form">
        <?php if ($produto): ?><input type="hidden" name="id" value="<?= (int)$produto['id'] ?>"><?php endif; ?>
        <input type="hidden" name="imagem_atual" value="<?= htmlspecialchars((string)($produto['imagem'] ?? '')) ?>">
        <input type="hidden" id="preco" name="preco" value="<?= htmlspecialchars(str_replace(',', '.', str_replace('.', '', $precoInicial))) ?>">
        <input type="hidden" name="descricao" id="descricao-hidden">
        <input type="hidden" name="variantes" id="variantes-hidden">
        <!-- Hidden fallback for quantidade — always present even if x-if template hasn't rendered -->
        <input type="hidden" name="quantidade" :value="tipo === 'produto' ? quantidade : 0">

        <!-- ═══ Tipo ═══ -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="layers" class="w-4 h-4 text-greenx"></i> Tipo de cadastro</h3>
            <div class="grid grid-cols-3 gap-3">
                <label class="relative cursor-pointer" @click="tipo = 'produto'">
                    <input type="radio" name="tipo" value="produto" class="sr-only" x-model="tipo">
                    <div class="rounded-xl border-2 p-4 text-center transition-all" :class="tipo==='produto'?'border-greenx bg-greenx/10 shadow-lg shadow-greenx/10':'border-blackx3 bg-blackx hover:border-zinc-600'">
                        <div class="w-12 h-12 mx-auto rounded-xl flex items-center justify-center mb-3" :class="tipo==='produto'?'bg-greenx/20':'bg-blackx3'"><i data-lucide="package" class="w-6 h-6" :class="tipo==='produto'?'text-greenx':'text-zinc-500'"></i></div>
                        <p class="font-semibold text-sm" :class="tipo==='produto'?'text-white':'text-zinc-400'">Produto</p>
                        <p class="text-xs mt-1" :class="tipo==='produto'?'text-zinc-300':'text-zinc-600'">Item físico com estoque</p>
                    </div>
                </label>
                <label class="relative cursor-pointer" @click="tipo = 'dinamico'">
                    <input type="radio" name="tipo" value="dinamico" class="sr-only" x-model="tipo">
                    <div class="rounded-xl border-2 p-4 text-center transition-all" :class="tipo==='dinamico'?'border-greenx bg-greenx/10 shadow-lg shadow-greenx/10':'border-blackx3 bg-blackx hover:border-zinc-600'">
                        <div class="w-12 h-12 mx-auto rounded-xl flex items-center justify-center mb-3" :class="tipo==='dinamico'?'bg-greenx/20':'bg-blackx3'"><i data-lucide="list-plus" class="w-6 h-6" :class="tipo==='dinamico'?'text-greenx':'text-zinc-500'"></i></div>
                        <p class="font-semibold text-sm" :class="tipo==='dinamico'?'text-white':'text-zinc-400'">Dinâmico</p>
                        <p class="text-xs mt-1" :class="tipo==='dinamico'?'text-zinc-300':'text-zinc-600'">Múltiplas opções</p>
                    </div>
                </label>
                <label class="relative cursor-pointer" @click="tipo = 'servico'">
                    <input type="radio" name="tipo" value="servico" class="sr-only" x-model="tipo">
                    <div class="rounded-xl border-2 p-4 text-center transition-all" :class="tipo==='servico'?'border-greenx bg-greenx/10 shadow-lg shadow-greenx/10':'border-blackx3 bg-blackx hover:border-zinc-600'">
                        <div class="w-12 h-12 mx-auto rounded-xl flex items-center justify-center mb-3" :class="tipo==='servico'?'bg-greenx/20':'bg-blackx3'"><i data-lucide="briefcase" class="w-6 h-6" :class="tipo==='servico'?'text-greenx':'text-zinc-500'"></i></div>
                        <p class="font-semibold text-sm" :class="tipo==='servico'?'text-white':'text-zinc-400'">Serviço</p>
                        <p class="text-xs mt-1" :class="tipo==='servico'?'text-zinc-300':'text-zinc-600'">Prestação com prazo</p>
                    </div>
                </label>
            </div>
        </div>

        <!-- ═══ Informações ═══ -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="file-text" class="w-4 h-4 text-greenx"></i> Informações básicas</h3>
            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Vendedor</label>
                        <select name="vendedor_id" required class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors">
                            <option value="">Selecione</option>
                            <?php foreach ($vendedores as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= (int)($produto['vendedor_id'] ?? 0)===(int)$v['id']?'selected':'' ?>><?= htmlspecialchars($v['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Categoria</label>
                        <select name="categoria_id" required class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors">
                            <option value="">Selecione</option>
                            <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)($produto['categoria_id'] ?? 0)===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Nome</label>
                    <input name="nome" maxlength="120" required value="<?= htmlspecialchars((string)($produto['nome'] ?? '')) ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors" placeholder="Nome do produto ou serviço">
                </div>
                <div>
                    <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Slug <span class="text-zinc-600 font-normal">(Opcional — gerado automaticamente se vazio)</span></label>
                    <input name="slug" maxlength="191" value="<?= htmlspecialchars((string)($produto['slug'] ?? '')) ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors" placeholder="ex: meu-produto-personalizado">
                </div>
                <label class="flex items-start gap-3 rounded-xl border border-fuchsia-500/25 bg-fuchsia-500/[0.06] p-3 cursor-pointer hover:bg-fuchsia-500/[0.10] transition">
                    <input type="checkbox" name="destaque" value="1" <?= !empty($produto['destaque']) ? 'checked' : '' ?> class="mt-1 h-4 w-4 rounded border-blackx3 accent-fuchsia-500">
                    <span>
                        <span class="block text-sm font-semibold text-zinc-200">Produto destaque na home</span>
                        <span class="block text-xs text-zinc-500 mt-0.5">Produtos destacados alimentam a seção Em destaque; se nenhum estiver marcado, a home usa os mais recentes.</span>
                    </span>
                </label>
                <div class="rounded-xl border border-amber-500/25 bg-amber-500/[0.06] p-4">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="product_fee_override_enabled" value="1" <?= $productFeeOverrideAtual ? 'checked' : '' ?> class="mt-1 h-4 w-4 rounded border-blackx3 accent-amber-500">
                        <span>
                            <span class="block text-sm font-semibold text-zinc-200">Taxa personalizada deste produto</span>
                            <span class="block text-xs text-zinc-500 mt-0.5">Quando ativa, substitui a taxa personalizada do vendedor e os níveis globais somente neste produto.</span>
                        </span>
                    </label>
                    <div class="mt-3 max-w-xs">
                        <label class="block text-xs mb-1.5 text-zinc-500 font-medium">Taxa da plataforma (%)</label>
                        <input type="number" name="product_fee_percent" min="0" max="100" step="0.01" value="<?= htmlspecialchars($productFeePercentAtual !== '' && $productFeePercentAtual !== null ? number_format((float)$productFeePercentAtual, 2, '.', '') : '') ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-amber-400 outline-none transition-colors" placeholder="Ex: 12.99">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div x-show="tipo !== 'dinamico'" x-transition>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Valor (R$)</label>
                        <input id="preco_display" name="preco_display" type="text" inputmode="numeric" value="<?= htmlspecialchars($precoInicial) ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors" placeholder="0,00">
                    </div>
                    <!-- Quantidade: usa template x-if para evitar duplicidade de name -->
                    <template x-if="tipo === 'produto'">
                    <div>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Quantidade em estoque</label>
                        <div class="flex items-center gap-0 rounded-xl border border-blackx3 bg-blackx overflow-hidden">
                            <button type="button" @click="quantidade = Math.max(1, quantidade - 1)" class="w-11 h-[42px] flex items-center justify-center text-zinc-400 hover:text-white hover:bg-white/[0.06] transition-all border-r border-blackx3"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg></button>
                            <input type="number" min="1" x-model.number="quantidade" class="flex-1 h-[42px] text-center bg-transparent text-sm font-semibold text-white focus:outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none">
                            <button type="button" @click="quantidade++" class="w-11 h-[42px] flex items-center justify-center text-zinc-400 hover:text-white hover:bg-white/[0.06] transition-all border-l border-blackx3"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></button>
                        </div>
                    </div>
                    </template>
                    <template x-if="tipo === 'servico' || tipo === 'dinamico'">
                    <div>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Quantidade</label>
                        <div class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 text-zinc-600 text-sm cursor-not-allowed">
                            <span class="flex items-center gap-1.5"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> <span x-text="tipo==='dinamico'?'Definido nas variantes':'Não aplicável para serviços'"></span></span>
                        </div>
                    </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ═══ Prazo de entrega manual ═══ -->
        <template x-if="tipo === 'servico' || tipo === 'produto' || tipo === 'dinamico'">
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20" x-init="$nextTick(()=>{if(window.lucide)lucide.createIcons()})">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="calendar-clock" class="w-4 h-4 text-greenx"></i> Prazo de entrega manual</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Prazo em dias</label>
                    <input type="number" name="prazo_entrega_dias" min="1" max="365" value="<?= htmlspecialchars((string)$prazoAtual) ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors" placeholder="Ex: 7">
                </div>
                <div>
                    <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Ou data específica</label>
                    <input type="date" name="data_entrega" value="<?= htmlspecialchars((string)$dataEntregaAtual) ?>" min="<?= date('Y-m-d') ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors [color-scheme:dark]">
                </div>
            </div>
        </div>
        </template>

        <!-- ═══ Variantes (dinâmico) ═══ -->
        <template x-if="tipo === 'dinamico'">
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20" x-init="$nextTick(()=>{if(window.lucide)lucide.createIcons()})">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="list-plus" class="w-4 h-4 text-greenx"></i> Variantes do produto</h3>
            <p class="text-xs text-zinc-500 mb-4">Adicione as opções disponíveis com nome, preço e quantidade individual.</p>
            <div class="space-y-3">
                <template x-for="(v, idx) in variantes" :key="idx">
                <div class="flex items-center gap-3 bg-blackx rounded-xl border border-blackx3 p-3">
                    <div class="flex-1">
                        <label class="block text-xs text-zinc-500 mb-1">Nome</label>
                        <input type="text" x-model="v.nome" placeholder="Ex: Plano Básico" class="w-full rounded-lg bg-blackx2 border border-blackx3 px-3 py-2 text-sm focus:border-greenx outline-none transition-colors">
                    </div>
                    <div class="w-28">
                        <label class="block text-xs text-zinc-500 mb-1">Preço (R$)</label>
                        <input type="text" inputmode="numeric" :value="variantePriceFormat(v.preco)" @input="v.preco = variantePriceParse($event.target.value); $event.target.value = variantePriceFormat(v.preco)" class="w-full rounded-lg bg-blackx2 border border-blackx3 px-3 py-2 text-sm focus:border-greenx outline-none transition-colors">
                    </div>
                    <div class="w-24">
                        <label class="block text-xs text-zinc-500 mb-1">Qtd</label>
                        <input type="number" x-model.number="v.quantidade" min="1" class="w-full rounded-lg bg-blackx2 border border-blackx3 px-3 py-2 text-sm focus:border-greenx outline-none transition-colors">
                    </div>
                    <button type="button" @click="variantes.splice(idx,1)" class="mt-5 p-2 rounded-lg text-zinc-500 hover:text-red-400 hover:bg-red-500/10 transition-all"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                </div>
                </template>
            </div>
            <button type="button" @click="variantes.push({nome:'',preco:0,quantidade:1})" class="mt-4 inline-flex items-center gap-2 rounded-xl border border-dashed border-greenx/40 px-4 py-2.5 text-sm text-greenx hover:bg-greenx/10 transition-all"><i data-lucide="plus-circle" class="w-4 h-4"></i> Adicionar variante</button>
            <div x-show="variantes.length===0" class="mt-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs px-4 py-2.5 flex items-center gap-2"><i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Adicione ao menos uma variante.</div>
        </div>
        </template>

        <!-- ═══ Descrição (Quill.js) ═══ -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="align-left" class="w-4 h-4 text-greenx"></i> Descrição detalhada</h3>
            <div id="quill-editor"><?= (string)($produto['descricao'] ?? '') ?></div>
        </div>

        <!-- ═══ Imagem de Capa ═══ -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="image" class="w-4 h-4 text-greenx"></i> Imagem de capa</h3>
            <p class="text-xs text-zinc-500 mb-3">Salva no banco de dados — não perde no deploy.</p>
            <div class="relative" x-data="imageUpload('<?= htmlspecialchars($imgAtual, ENT_QUOTES) ?>')" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="handleDrop($event)">
                <div x-show="!preview" x-transition class="relative border-2 border-dashed rounded-2xl p-8 text-center transition-all cursor-pointer" :class="dragging?'border-greenx bg-greenx/5 scale-[1.01]':'border-blackx3 hover:border-zinc-600 bg-blackx/40'" @click="$refs.fileInput.click()">
                    <div class="w-16 h-16 mx-auto rounded-2xl flex items-center justify-center mb-4 transition-all" :class="dragging?'bg-greenx/20 scale-110':'bg-blackx3'"><i data-lucide="cloud-upload" class="w-8 h-8" :class="dragging?'text-greenx':'text-zinc-500'"></i></div>
                    <p class="text-sm font-medium" :class="dragging?'text-greenx':'text-zinc-300'"><span x-show="!dragging">Arraste ou <span class="text-greenx underline">clique para selecionar</span></span><span x-show="dragging">Solte aqui</span></p>
                    <p class="text-xs text-zinc-600 mt-2">JPG, PNG, WebP &bull; Máx 5MB</p>
                </div>
                <div x-show="preview" x-transition class="relative group">
                    <div class="rounded-2xl overflow-hidden border border-blackx3 bg-blackx"><img :src="preview" class="w-full max-h-80 object-contain bg-blackx" alt="Preview"></div>
                    <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity rounded-2xl flex items-center justify-center gap-3">
                        <button type="button" @click="$refs.fileInput.click()" class="inline-flex items-center gap-2 rounded-xl bg-white/10 backdrop-blur border border-white/20 px-4 py-2.5 text-sm text-white hover:bg-white/20 transition-all"><i data-lucide="replace" class="w-4 h-4"></i> Trocar</button>
                        <button type="button" @click="removeImage()" class="inline-flex items-center gap-2 rounded-xl bg-red-500/20 backdrop-blur border border-red-500/30 px-4 py-2.5 text-sm text-red-300 hover:bg-red-500/30 transition-all"><i data-lucide="trash-2" class="w-4 h-4"></i> Remover</button>
                    </div>
                    <div x-show="fileName" class="mt-2 flex items-center gap-2 text-xs text-zinc-500"><i data-lucide="file-image" class="w-3.5 h-3.5"></i><span x-text="fileName" class="truncate"></span><span x-show="fileSize" x-text="fileSize" class="text-zinc-600"></span></div>
                </div>
                <input type="file" name="imagem" accept=".jpg,.jpeg,.png,.webp" x-ref="fileInput" class="hidden" @change="handleFile($event)">
            </div>
        </div>

        <!-- ═══ Galeria ═══ -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20" x-data="galleryUpload()">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="images" class="w-4 h-4 text-greenx"></i> Galeria de imagens</h3>
            <p class="text-xs text-zinc-500 mb-3">Exibidas em slider na página do produto. Salvas no banco de dados.</p>
            <?php if ($galleryImages): ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mb-4">
                <?php foreach ($galleryImages as $gi): ?>
                <div class="relative group rounded-xl overflow-hidden border border-blackx3 bg-blackx transition-all" :class="isMarked(<?= (int)$gi['id'] ?>) ? 'opacity-50 ring-2 ring-red-500 border-red-500' : ''">
                    <img src="<?= htmlspecialchars(mediaUrl((int)$gi['id'])) ?>" class="w-full aspect-square object-cover" alt="">
                    <input type="hidden" name="delete_gallery[]" value="<?= (int)$gi['id'] ?>" disabled :disabled="!isMarked(<?= (int)$gi['id'] ?>)">
                    <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                        <button type="button" @click.stop="toggleDelete(<?= (int)$gi['id'] ?>)" class="cursor-pointer inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs transition-all" :class="isMarked(<?= (int)$gi['id'] ?>) ? 'bg-zinc-700 text-zinc-100 hover:bg-zinc-600' : 'bg-red-500/20 text-red-300 hover:bg-red-500/40'">
                            <i data-lucide="trash-2" class="w-3 h-3"></i> Excluir
                        </button>
                    </div>
                    <div class="absolute inset-x-2 bottom-2 rounded-lg bg-red-500/90 px-2 py-1 text-center text-[11px] font-semibold text-white transition-opacity pointer-events-none" :class="isMarked(<?= (int)$gi['id'] ?>) ? 'opacity-100' : 'opacity-0'">Será excluída ao salvar</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div @dragover.prevent="dragging=true" @dragleave.prevent="dragging=false" @drop.prevent="handleGalleryDrop($event)" class="relative border-2 border-dashed rounded-2xl p-6 text-center transition-all cursor-pointer" :class="dragging?'border-greenx bg-greenx/5':'border-blackx3 hover:border-zinc-600 bg-blackx/40'" @click="$refs.galleryInput.click()">
                <div class="w-12 h-12 mx-auto rounded-xl flex items-center justify-center mb-3" :class="dragging?'bg-greenx/20':'bg-blackx3'"><i data-lucide="image-plus" class="w-6 h-6" :class="dragging?'text-greenx':'text-zinc-500'"></i></div>
                <p class="text-sm font-medium" :class="dragging?'text-greenx':'text-zinc-300'"><span x-show="!dragging">Arraste imagens ou <span class="text-greenx underline">clique para selecionar</span></span><span x-show="dragging">Solte para adicionar</span></p>
                <p class="text-xs text-zinc-600 mt-1.5">Múltiplas imagens &bull; JPG, PNG, WebP</p>
            </div>
            <input type="file" name="gallery[]" accept=".jpg,.jpeg,.png,.webp" multiple x-ref="galleryInput" class="hidden" @change="handleGallerySelect($event)">
            <div x-show="galleryCount>0" class="mt-3 flex items-center gap-2 text-xs text-greenx font-medium"><i data-lucide="check-circle" class="w-3.5 h-3.5"></i><span x-text="galleryCount+' nova(s) imagem(ns)'"></span></div>
        </div>

        <!-- ═══ Botões ═══ -->
        <div class="flex items-center justify-end gap-3 pt-2">
            <a href="produtos" class="rounded-xl border border-blackx3 text-zinc-300 px-5 py-2.5 hover:border-greenx hover:text-white transition-all">Cancelar</a>
            <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-6 py-2.5 hover:from-greenx2 hover:to-greenxd shadow-lg shadow-greenx/20 transition-all"><i data-lucide="save" class="w-4 h-4"></i> Salvar</button>
        </div>
    </form>
</div>

<script>
const quill = new Quill('#quill-editor', {
    theme: 'snow',
    placeholder: 'Descreva o produto ou serviço em detalhes...',
    modules: { toolbar: [[{'header':[1,2,3,false]}],['bold','italic','underline','strike'],[{'color':[]},{'background':[]}],[{'align':[]}],[{'list':'ordered'},{'list':'bullet'}],['link','image'],['blockquote','code-block'],['clean']] }
});
document.getElementById('produto-form').addEventListener('submit', function(){
    document.getElementById('descricao-hidden').value = quill.root.innerHTML;
    // For dynamic products, force price to 0 (variants have their own prices)
    var tipoEl = document.querySelector('input[name="tipo"]:checked');
    if (tipoEl && tipoEl.value === 'dinamico') {
        document.getElementById('preco').value = '0';
    }
});

function produtoForm() {
    return {
        tipo: '<?= $tipoAtual ?>',
        quantidade: <?= $qtdAtual ?>,
        variantes: <?= $variantesAtual ?>,
        variantePriceFormat(val) {
            const cents = Math.round((Number(val) || 0) * 100);
            return (cents / 100).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
        },
        variantePriceParse(str) {
            const digits = (str || '').replace(/\D/g, '');
            return Number((Number(digits || 0) / 100).toFixed(2));
        },
        init() {
            this.$watch('variantes', v => {
                v.forEach(vi => { if (!vi.quantidade || vi.quantidade < 1) vi.quantidade = 1; });
                document.getElementById('variantes-hidden').value = JSON.stringify(v);
            });
            // sync on load
            document.getElementById('variantes-hidden').value = JSON.stringify(this.variantes);
            // If new dynamic product with no variants, add one empty
            if (this.tipo === 'dinamico' && this.variantes.length === 0) {
                this.variantes.push({nome:'',preco:0,quantidade:1});
            }
        }
    };
}

function imageUpload(existingUrl) {
    return {
        preview: existingUrl||null, dragging:false, fileName:'', fileSize:'',
        handleFile(e){ const f=e.target.files?.[0]; if(f) this.processFile(f); },
        handleDrop(e){ this.dragging=false; const f=e.dataTransfer?.files?.[0]; if(!f)return; if(!['image/jpeg','image/png','image/webp'].includes(f.type)){alert('Use JPG, PNG ou WebP.');return;} const dt=new DataTransfer();dt.items.add(f);this.$refs.fileInput.files=dt.files;this.processFile(f); },
        processFile(f){ if(f.size>5*1024*1024){alert('Máximo 5MB.');return;} this.fileName=f.name;this.fileSize=this.fmtSize(f.size); const r=new FileReader();r.onload=e=>{this.preview=e.target.result;};r.readAsDataURL(f); },
        removeImage(){ this.preview=null;this.fileName='';this.fileSize='';this.$refs.fileInput.value='';const h=document.querySelector('input[name="imagem_atual"]');if(h)h.value=''; },
        fmtSize(b){ if(b<1024)return b+' B';if(b<1048576)return(b/1024).toFixed(1)+' KB';return(b/1048576).toFixed(1)+' MB'; }
    };
}

function galleryUpload() {
    return {
        dragging:false, galleryCount:0, deleteIds:[],
        isMarked(id){ return this.deleteIds.includes(Number(id)); },
        toggleDelete(id){ id=Number(id); this.deleteIds=this.isMarked(id)?this.deleteIds.filter(v=>v!==id):[...this.deleteIds,id]; },
        handleGallerySelect(e){ this.galleryCount=e.target.files?.length??0; },
        handleGalleryDrop(e){ this.dragging=false;const fs=e.dataTransfer?.files;if(!fs||!fs.length)return;const dt=new DataTransfer();for(const f of fs){if(f.type.startsWith('image/'))dt.items.add(f);}this.$refs.galleryInput.files=dt.files;this.galleryCount=dt.files.length; }
    };
}

(function(){
    const d=document.getElementById('preco_display'),h=document.getElementById('preco');
    if(!d||!h)return;
    const dig=v=>(v||'').replace(/\D/g,''),fmt=d=>{if(!d)return'0,00';return(Number(d)/100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});},toF=b=>{const s=(b||'').replace(/\./g,'').replace(',','.').replace(/[^\d.]/g,'');return Number(s||0).toFixed(2);};
    d.value=fmt(dig(d.value));h.value=toF(d.value);
    d.addEventListener('input',()=>{d.value=fmt(dig(d.value));h.value=toF(d.value);});
})();
</script>
<script src="<?= BASE_PATH ?>/assets/js/slug-checker.js"></script>
<script>
  initSlugChecker({
    inputSelector: 'input[name="slug"]',
    nameSelector:  'input[name="nome"]',
    type:          'product',
    excludeId:     <?= (int)($produto['id'] ?? 0) ?>
  });
</script>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
