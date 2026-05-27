<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\produtos_form.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/vendor_portal.php';
require_once __DIR__ . '/../../src/upload_paths.php';
require_once __DIR__ . '/../../src/media.php';

exigirVendedor();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db = new Database();
$conn = $db->connect();
$uid = (int)($_SESSION['user_id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
$produto = $id > 0 ? obterMeuProduto($conn, $uid, $id) : null;
$categorias = listarCategoriasProdutoAtivasVendor($conn);

// Load existing gallery images
$galleryImages = [];
if ($produto) {
    $galleryImages = mediaListByEntity('product_gallery', (int)$produto['id']);
}

$erro = '';
if (empty($_SESSION['vendor_product_form_token'])) {
    $_SESSION['vendor_product_form_token'] = bin2hex(random_bytes(16));
}
if (!isset($_SESSION['vendor_auto_delivery_drafts']) || !is_array($_SESSION['vendor_auto_delivery_drafts'])) {
    $_SESSION['vendor_auto_delivery_drafts'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $ajaxAction = (string)($_POST['ajax_action'] ?? '');
    $ajaxId = (int)($_POST['id'] ?? 0);
    $draftToken = trim((string)($_POST['draft_token'] ?? ''));

    if ($ajaxId <= 0) {
        $sessionToken = (string)($_SESSION['vendor_product_form_token'] ?? '');
        if ($draftToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $draftToken)) {
            echo json_encode(['ok' => false, 'msg' => 'Rascunho inválido. Atualize a página e tente novamente.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxAction === 'toggle_auto_delivery') {
            $enabledRequested = !empty($_POST['enabled']);
            $draft = $_SESSION['vendor_auto_delivery_drafts'][$draftToken] ?? [];
            $itemsText = sfAutoDeliveryItemsText((string)($draft['items'] ?? ''));
            $hasConfig = count(sfAutoDeliveryItemsList($itemsText)) > 0;
            $_SESSION['vendor_auto_delivery_drafts'][$draftToken] = [
                'enabled' => $enabledRequested,
                'items' => $itemsText,
            ];

            echo json_encode([
                'ok' => true,
                'draft' => true,
                'has_config' => $hasConfig,
                'effective_enabled' => $enabledRequested && $hasConfig,
                'msg' => !$hasConfig && $enabledRequested ? 'Use o botão Gerenciar Estoque Automático para cadastrar os itens antes de ativar.' : '',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxAction === 'save_auto_delivery_items') {
            $itemsText = sfAutoDeliveryItemsText((string)($_POST['items'] ?? ''));
            $enabledRequested = !empty($_POST['enabled']);
            $hasConfig = count(sfAutoDeliveryItemsList($itemsText)) > 0;
            $_SESSION['vendor_auto_delivery_drafts'][$draftToken] = [
                'enabled' => $enabledRequested,
                'items' => $itemsText,
            ];

            echo json_encode([
                'ok' => true,
                'draft' => true,
                'has_config' => $hasConfig,
                'effective_enabled' => $enabledRequested && $hasConfig,
                'msg' => !$hasConfig && $enabledRequested ? 'Use o botão Gerenciar Estoque Automático para cadastrar os itens antes de ativar.' : '',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $produtoAjax = obterMeuProduto($conn, $uid, $ajaxId);
    if (!$produtoAjax) {
        echo json_encode(['ok' => false, 'msg' => 'Produto não encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $colV = vpColunaVendedorProdutos($conn);
    if (!$colV) {
        echo json_encode(['ok' => false, 'msg' => 'Configuração de vendedor indisponível.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajaxAction === 'toggle_auto_delivery') {
        $enabledRequested = !empty($_POST['enabled']);
        $hasConfig = sfAutoDeliveryConfiguredCount($conn, $ajaxId, (string)($produtoAjax['auto_delivery_items'] ?? '')) > 0;
        $enabledInt = ($enabledRequested && $hasConfig) ? 1 : 0;
        $st = $conn->prepare("UPDATE products SET auto_delivery_enabled = ? WHERE id = ? AND $colV = ?");
        if (!$st) {
            echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar atualização.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $st->bind_param('iii', $enabledInt, $ajaxId, $uid);
        $ok = $st->execute();
        $st->close();

        echo json_encode([
            'ok' => (bool)$ok,
            'has_config' => $hasConfig,
            'effective_enabled' => (bool)$enabledInt,
            'msg' => !$hasConfig && $enabledRequested ? 'Use o botão Gerenciar Estoque Automático para cadastrar os itens antes de ativar.' : '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajaxAction === 'save_auto_delivery_items') {
        $itemsText = sfAutoDeliveryItemsText((string)($_POST['items'] ?? ''));
        $itemsJson = sfAutoDeliveryItemsJson($itemsText);
        $enabledRequested = !empty($_POST['enabled']);
        $hasConfig = sfAutoDeliveryConfiguredCount($conn, $ajaxId, $itemsText) > 0;
        $enabledInt = ($enabledRequested && $hasConfig) ? 1 : 0;
        $st = $conn->prepare("UPDATE products SET auto_delivery_enabled = ?, auto_delivery_items = ? WHERE id = ? AND $colV = ?");
        if (!$st) {
            echo json_encode(['ok' => false, 'msg' => 'Falha ao preparar atualização.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $st->bind_param('isii', $enabledInt, $itemsJson, $ajaxId, $uid);
        $ok = $st->execute();
        $st->close();

        echo json_encode([
            'ok' => (bool)$ok,
            'has_config' => $hasConfig,
            'effective_enabled' => (bool)$enabledInt,
                'msg' => !$hasConfig && $enabledRequested ? 'Use o botão Gerenciar Estoque Automático para cadastrar os itens antes de ativar.' : '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['form_token'] ?? '');
    $sessionToken = (string)($_SESSION['vendor_product_form_token'] ?? '');
    if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
        $erro = 'Envio duplicado ou inválido. Atualize a página e tente novamente.';
    } else {
        unset($_SESSION['vendor_product_form_token']);
        $draft = is_array($_SESSION['vendor_auto_delivery_drafts'][$token] ?? null) ? $_SESSION['vendor_auto_delivery_drafts'][$token] : [];

        $idPost = (int)($_POST['id'] ?? 0);
        $categoriaId = (int)($_POST['categoria_id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $preco = (float)($_POST['preco'] ?? 0);

        // --- Save cover image to DB ---
        $imagemMedia = null;
        if (isset($_FILES['imagem']) && ($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $mediaId = mediaSaveFromUpload($_FILES['imagem'], 'product', $idPost > 0 ? $idPost : 0, true);
            if ($mediaId) {
                $imagemMedia = 'media:' . $mediaId;
            }
        }
        if (!$imagemMedia) {
            $imagemMedia = (string)($_POST['imagem_atual'] ?? '');
            if ($imagemMedia === '') $imagemMedia = null;
        }

        $tipo = (string)($_POST['tipo'] ?? 'produto');
        $quantidade = (int)($_POST['quantidade'] ?? 0);
        $prazoEntregaDias = ($_POST['prazo_entrega_dias'] ?? '') !== '' ? (int)$_POST['prazo_entrega_dias'] : null;
        $dataEntrega = trim((string)($_POST['data_entrega'] ?? '')) !== '' ? (string)$_POST['data_entrega'] : null;
        $customSlug = trim((string)($_POST['slug'] ?? ''));
        $variantes = trim((string)($_POST['variantes'] ?? '')) !== '' ? (string)$_POST['variantes'] : null;
        $autoDeliveryEnabled = !empty($_POST['auto_delivery_enabled']);
        $autoDeliveryItemsPosted = array_key_exists('auto_delivery_items', $_POST);
        $autoDeliveryItems = $autoDeliveryItemsPosted ? trim((string)$_POST['auto_delivery_items']) : (string)($produto['auto_delivery_items'] ?? '');
        if ($idPost <= 0 && !$autoDeliveryItemsPosted && $autoDeliveryItems === '' && isset($draft['items'])) {
            $autoDeliveryItems = (string)$draft['items'];
        }
        if ($autoDeliveryItems === '') {
            $autoDeliveryItems = null;
        }

        [$ok, $msg] = salvarMeuProduto($conn, $uid, $idPost, $categoriaId, $nome, $descricao, $preco, $imagemMedia, $tipo, $quantidade, $prazoEntregaDias, $dataEntrega, $customSlug, $variantes, $autoDeliveryEnabled, $autoDeliveryItems);

        if ($ok) {
            unset($_SESSION['vendor_auto_delivery_drafts'][$token]);
            // Determine product ID for gallery
            $productId = (int)$idPost;
            if ($productId <= 0) {
                $lastRow = $conn->query("SELECT MAX(id) AS last_id FROM products")->fetch_assoc();
                $productId = (int)($lastRow['last_id'] ?? 0);
            }

            // Fix entity_id for cover on new product
            if ($idPost <= 0 && $imagemMedia && str_starts_with($imagemMedia, 'media:')) {
                $coverId = (int)substr($imagemMedia, 6);
                $stUp = $conn->prepare("UPDATE media_files SET entity_id = ? WHERE id = ?");
                $stUp->bind_param('ii', $productId, $coverId);
                $stUp->execute(); $stUp->close();
                $colV = vpColunaVendedorProdutos($conn);
                if ($colV) {
                    $stUp2 = $conn->prepare("UPDATE products SET imagem = ? WHERE id = ? AND $colV = ?");
                    $ref = 'media:' . $coverId;
                    $stUp2->bind_param('sii', $ref, $productId, $uid);
                    $stUp2->execute(); $stUp2->close();
                }
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

    if (empty($_SESSION['vendor_product_form_token'])) {
        $_SESSION['vendor_product_form_token'] = bin2hex(random_bytes(16));
    }
}

$pageTitle = $produto ? 'Editar Produto' : 'Adicionar Produto';
$activeMenu = 'meus_produtos';
$subnavItems = [
    ['label' => 'Listar', 'href' => 'produtos', 'active' => false],
    ['label' => $produto ? 'Editar' : 'Adicionar', 'href' => '#', 'active' => true],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';

$imgAtual = $produto ? mediaResolveUrl((string)($produto['imagem'] ?? '')) : '';
$precoInicial = isset($produto['preco']) ? number_format((float)$produto['preco'], 2, ',', '.') : '0,00';
$formToken = (string)($_SESSION['vendor_product_form_token'] ?? '');
$tipoAtual = (string)($produto['tipo'] ?? 'produto');
$qtdAtual = (int)($produto['quantidade'] ?? 1);
if (!$produto && $qtdAtual < 1) $qtdAtual = 1;
$prazoAtual = $produto['prazo_entrega_dias'] ?? '';
$dataEntregaAtual = $produto['data_entrega'] ?? '';
$variantesAtual = $produto['variantes'] ?? '[]';
if (!$variantesAtual || $variantesAtual === '') $variantesAtual = '[]';
$draft = !$produto && is_array($_SESSION['vendor_auto_delivery_drafts'][$formToken] ?? null)
    ? $_SESSION['vendor_auto_delivery_drafts'][$formToken]
    : [];
$adEnabled = $produto ? sfProductAutoDeliveryEnabledEffective($conn, $produto) : !empty($draft['enabled']);
$autoDeliveryItemsAtual = $produto
    ? sfAutoDeliveryItemsText((string)($produto['auto_delivery_items'] ?? ''))
    : sfAutoDeliveryItemsText((string)($draft['items'] ?? ''));
$adHasConfig = $produto
    ? sfAutoDeliveryConfiguredCount($conn, (int)($produto['id'] ?? 0), (string)($produto['auto_delivery_items'] ?? '')) > 0
    : count(sfAutoDeliveryItemsList((string)($draft['items'] ?? ''))) > 0;
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
    #produto-form.was-submitted select:invalid,
    #produto-form.was-submitted input:invalid{border-color:#ef4444!important;background:rgba(239,68,68,.08)!important}
    #produto-form.was-submitted select:invalid + .required-hint{display:flex!important}
</style>

<div x-data="produtoForm()">
    <div class="mb-4 rounded-2xl border border-blackx3 bg-blackx2 p-5 md:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-widest text-zinc-500 font-semibold">Catálogo</p>
                <h2 class="text-xl md:text-2xl font-bold mt-1"><?= $produto ? 'Editar produto/serviço' : 'Adicionar novo produto/serviço' ?></h2>
                <p class="text-sm text-zinc-400 mt-1">Preencha os dados com atenção para publicar com qualidade profissional.</p>
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

    <div class="mb-4 rounded-2xl border border-amber-400/35 bg-amber-400/[0.08] px-4 py-4 text-amber-100 shadow-xl shadow-black/10">
        <div class="flex gap-3">
            <div class="mt-0.5 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-amber-400/15 text-amber-300">
                <i data-lucide="shield-alert" class="h-5 w-5"></i>
            </div>
            <div>
                <h3 class="text-sm font-bold text-amber-200">Contato externo não é permitido</h3>
                <p class="mt-1 text-sm leading-relaxed text-amber-100/80">Não inclua WhatsApp, Instagram, Telegram, e-mail, telefone ou links externos no produto. Toda comunicação e entrega devem acontecer dentro da Basefy para manter o pagamento protegido.</p>
            </div>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="space-y-5" id="produto-form">
        <?php if ($produto): ?><input type="hidden" name="id" value="<?= (int)$produto['id'] ?>"><?php endif; ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8') ?>">
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
                        <p class="text-xs mt-1" :class="tipo==='produto'?'text-zinc-300':'text-zinc-600'">Item digital com estoque</p>
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
                    <div :class="tipo === 'dinamico' ? 'md:col-span-2' : ''">
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Categoria <span class="text-red-400">*</span></label>
                        <select name="categoria_id" required class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors">
                            <option value="">Selecione a categoria</option>
                            <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)($produto['categoria_id'] ?? 0)===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars((string)$c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="required-hint hidden mt-1 items-center gap-1 text-xs text-red-300"><i data-lucide="alert-circle" class="w-3 h-3"></i> Selecione uma categoria antes de salvar.</p>
                    </div>
                    <div x-show="tipo !== 'dinamico'" x-transition>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Valor (R$)</label>
                        <input id="preco_display" type="text" inputmode="numeric" value="<?= htmlspecialchars($precoInicial) ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors" placeholder="0,00">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Nome</label>
                        <input name="nome" maxlength="120" required value="<?= htmlspecialchars((string)($produto['nome'] ?? '')) ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors" placeholder="Nome do produto ou serviço">
                    </div>
                    <div>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Slug <span class="text-zinc-600 font-normal">(Opcional — gerado automaticamente se vazio)</span></label>
                        <input name="slug" maxlength="191" value="<?= htmlspecialchars((string)($produto['slug'] ?? '')) ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors" placeholder="ex: meu-produto-personalizado">
                    </div>
                </div>
                <div>
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
                            <span class="flex items-center gap-1.5"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <span x-text="tipo==='dinamico'?'Definido nas variantes':'Não aplicável para serviços'"></span></span>
                        </div>
                    </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ═══ Prazo (serviços) ═══ -->
        <template x-if="tipo === 'servico'">
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20" x-init="$nextTick(()=>{if(window.lucide)lucide.createIcons()})">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="calendar-clock" class="w-4 h-4 text-greenx"></i> Prazo de entrega</h3>
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

        <!-- ═══ Variantes (Dinâmico) ═══ -->
        <template x-if="tipo === 'dinamico'">
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20" x-init="$nextTick(()=>{if(window.lucide)lucide.createIcons()})">
            <h3 class="text-sm font-semibold text-zinc-300 mb-4 flex items-center gap-2"><i data-lucide="list-plus" class="w-4 h-4 text-greenx"></i> Variantes do produto</h3>
            <p class="text-xs text-zinc-500 mb-4">Adicione opções que o comprador poderá escolher. Cada variante pode ter nome, preço e quantidade próprios.</p>

            <div class="space-y-3 mb-4">
                <template x-for="(v, idx) in variantes" :key="idx">
                <div class="flex items-start gap-2 p-3 rounded-xl border border-blackx3 bg-blackx/50 group hover:border-zinc-600 transition-all">
                    <div class="flex-1 grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <div>
                            <label class="block text-[11px] text-zinc-500 mb-1 font-medium">Nome da opção</label>
                            <input type="text" x-model="v.nome" placeholder="Ex: Tamanho P" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 text-sm outline-none focus:border-greenx transition-colors">
                        </div>
                        <div>
                            <label class="block text-[11px] text-zinc-500 mb-1 font-medium">Preço (R$)</label>
                            <input type="text" inputmode="numeric" :value="variantePriceFormat(v.preco)" @input="v.preco = variantePriceParse($event.target.value); $event.target.value = variantePriceFormat(v.preco)" placeholder="0,00" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 text-sm outline-none focus:border-greenx transition-colors">
                        </div>
                        <div>
                            <label class="block text-[11px] text-zinc-500 mb-1 font-medium">Quantidade</label>
                            <input type="number" x-model.number="v.quantidade" min="1" placeholder="1" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 text-sm outline-none focus:border-greenx transition-colors">
                        </div>
                    </div>
                    <button type="button" @click="variantes.splice(idx, 1)" class="mt-5 p-2 rounded-lg text-zinc-600 hover:text-red-400 hover:bg-red-500/10 transition-all" title="Remover variante">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                    </button>
                </div>
                </template>
            </div>

            <button type="button" @click="variantes.push({nome:'',preco:0,quantidade:1})"
                    class="inline-flex items-center gap-2 rounded-xl border border-dashed border-greenx/40 bg-greenx/5 px-4 py-2.5 text-sm text-greenx hover:bg-greenx/10 hover:border-greenx transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Adicionar variante
            </button>

            <div x-show="variantes.length === 0" class="mt-3 text-xs text-amber-400 flex items-center gap-1.5">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                Adicione pelo menos 1 variante para produtos dinâmicos.
            </div>
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

        <!-- ═══ Entrega Automática ═══ -->
        <template x-if="tipo !== 'servico'">
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 md:p-6 shadow-2xl shadow-black/20" x-init="$nextTick(()=>{if(window.lucide)lucide.createIcons()})">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold text-zinc-300 flex items-center gap-2"><i data-lucide="zap" class="w-4 h-4 text-amber-400"></i> Entrega Automática</h3>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="auto_delivery_enabled" value="1" x-model="adEnabled" @change="onToggleAutoDelivery($event.target.checked)" class="sr-only peer">
                    <div class="w-11 h-6 bg-blackx3 peer-focus:ring-2 peer-focus:ring-greenx/40 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-zinc-500 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-greenx peer-checked:after:bg-white"></div>
                </label>
            </div>
            <p class="text-xs text-zinc-500 mb-4">Quando ativada, o produto é entregue automaticamente ao comprador assim que o pagamento é confirmado. Ideal para chaves, códigos, contas e links de download.</p>

            <div x-show="adEnabled" x-transition x-cloak x-init="$watch('adEnabled', v => { if(v) $nextTick(() => { if(window.lucide) lucide.createIcons() }) })">
                <div class="flex items-start gap-3 p-4 rounded-xl bg-amber-500/[0.06] border border-amber-500/15 mb-5">
                    <div class="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="info" class="w-4 h-4 text-amber-400"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-amber-300 mb-1">Como funciona?</p>
                        <p class="text-xs text-zinc-400 leading-relaxed">Gerencie os itens de entrega automática na página de <strong class="text-white">Estoque Automático</strong>. Cada compra consumirá <strong class="text-white">1 item</strong> do estoque automaticamente. Você pode cadastrar itens por variante, definir mensagens de introdução e conclusão, e acompanhar o status de cada item.</p>
                    </div>
                </div>

                <?php if ($produto && (int)($produto['id'] ?? 0) > 0): ?>
                <a href="<?= BASE_PATH ?>/vendedor/estoque?id=<?= (int)$produto['id'] ?>"
                   class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-5 py-2.5 text-sm hover:from-greenx hover:to-greenxd transition-all shadow-lg shadow-greenx/20">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                    Gerenciar Estoque Automático
                </a>
                <?php else: ?>
                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                    <button type="button" disabled class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-5 py-2.5 text-sm opacity-60 cursor-not-allowed shadow-lg shadow-greenx/10">
                        <i data-lucide="boxes" class="w-4 h-4"></i>
                        Gerenciar Estoque Automático
                    </button>
                    <span class="text-xs text-zinc-500">Salve o produto para liberar o gerenciamento avançado.</span>
                </div>
                <?php endif; ?>
                <p x-show="adAjaxSaving" class="text-xs mt-3 text-amber-300">Atualizando...</p>
                <p x-show="adAjaxMsg" x-text="adAjaxMsg" :class="adAjaxError ? 'text-red-400' : 'text-greenx'" class="text-xs mt-3"></p>
            </div>

            <div x-show="!adEnabled" x-transition x-cloak x-init="$watch('adEnabled', v => { if(!v) $nextTick(() => { if(window.lucide) lucide.createIcons() }) })">
                <div class="flex items-start gap-3 p-4 rounded-xl bg-greenx/[0.06] border border-greenx/15 mb-5">
                    <div class="w-8 h-8 rounded-lg bg-greenx/10 border border-greenx/20 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="clock" class="w-4 h-4 text-greenx"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-greenx mb-1">Entrega manual</p>
                        <p class="text-xs text-zinc-400 leading-relaxed">Informe em quanto tempo você entrega depois da confirmação do pagamento. Esse prazo aparece na página pública do produto.</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Prazo em dias</label>
                        <input type="number" name="prazo_entrega_dias" min="1" max="365" value="<?= htmlspecialchars((string)$prazoAtual) ?>" :disabled="adEnabled" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors" placeholder="Ex: 2">
                    </div>
                    <div>
                        <label class="block text-sm mb-1.5 text-zinc-400 font-medium">Ou data específica</label>
                        <input type="date" name="data_entrega" value="<?= htmlspecialchars((string)$dataEntregaAtual) ?>" min="<?= date('Y-m-d') ?>" :disabled="adEnabled" class="w-full rounded-xl bg-blackx border border-blackx3 px-3.5 py-2.5 focus:border-greenx outline-none transition-colors [color-scheme:dark]">
                    </div>
                </div>
            </div>
        </div>
        </template>

        <!-- ═══ Botões ═══ -->
        <div class="flex items-center justify-end gap-3 pt-2">
            <a href="produtos" class="rounded-xl border border-blackx3 text-zinc-300 px-5 py-2.5 hover:border-greenx hover:text-white transition-all">Cancelar</a>
            <button id="produto-submit" type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-6 py-2.5 hover:from-greenx2 hover:to-greenxd shadow-lg shadow-greenx/20 transition-all">
                <i data-lucide="save" class="w-4 h-4" id="produto-submit-icon"></i>
                <span id="produto-submit-label">Salvar produto</span>
            </button>
        </div>
    </form>
</div>

<script>
const quill = new Quill('#quill-editor', {
    theme: 'snow',
    placeholder: 'Descreva o produto ou serviço em detalhes...',
    modules: { toolbar: [[{'header':[1,2,3,false]}],['bold','italic','underline','strike'],[{'color':[]},{'background':[]}],[{'align':[]}],[{'list':'ordered'},{'list':'bullet'}],['link','image'],['blockquote','code-block'],['clean']] }
});
const produtoFormEl = document.getElementById('produto-form');
produtoFormEl.addEventListener('invalid', function(){
    produtoFormEl.classList.add('was-submitted');
}, true);
produtoFormEl.addEventListener('submit', function(e){
    produtoFormEl.classList.add('was-submitted');
    document.getElementById('descricao-hidden').value = quill.root.innerHTML;
    // For dynamic products, force price to 0 (variants have their own prices)
    var tipoEl = document.querySelector('input[name="tipo"]:checked');
    if (tipoEl && tipoEl.value === 'dinamico') {
        document.getElementById('preco').value = '0';
    }
    const btn = document.getElementById('produto-submit');
    const lbl = document.getElementById('produto-submit-label');
    const ico = document.getElementById('produto-submit-icon');
    if (btn && !btn.disabled) {
        btn.disabled = true;
        btn.classList.add('opacity-70','cursor-not-allowed');
        if(lbl) lbl.textContent = 'Salvando...';
        if(ico){ ico.setAttribute('data-lucide','loader-2'); ico.classList.add('animate-spin'); }
        if(window.lucide) window.lucide.createIcons();
    }
});

function produtoForm() {
    return {
        productId: <?= (int)($produto['id'] ?? 0) ?>,
        draftToken: <?= json_encode($formToken, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        tipo: '<?= $tipoAtual ?>',
        quantidade: <?= $qtdAtual ?>,
        variantes: <?= $variantesAtual ?>,
        adEnabled: <?= $adEnabled ? 'true' : 'false' ?>,
        adItems: <?= json_encode($autoDeliveryItemsAtual, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        adHasConfig: <?= $adHasConfig ? 'true' : 'false' ?>,
        adAjaxMsg: '',
        adAjaxError: false,
        adAjaxSaving: false,
        variantePriceFormat(val) {
            const cents = Math.round((Number(val) || 0) * 100);
            return (cents / 100).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
        },
        variantePriceParse(str) {
            const digits = (str || '').replace(/\D/g, '');
            return Number((Number(digits || 0) / 100).toFixed(2));
        },
        init() {
            // Sync variants to hidden input on every change
            this.$watch('variantes', (val) => {
                // Enforce minimum quantity of 1 per variant
                val.forEach(v => { if (!v.quantidade || v.quantidade < 1) v.quantidade = 1; });
                document.getElementById('variantes-hidden').value = JSON.stringify(val);
            });
            // Also sync on init
            document.getElementById('variantes-hidden').value = JSON.stringify(this.variantes);
            // If new dynamic product with no variants, add one empty
            if (this.tipo === 'dinamico' && this.variantes.length === 0) {
                this.variantes.push({nome:'',preco:0,quantidade:1});
            }
        },
        hasAutoDeliveryItems() {
            return (this.adItems || '').split(/\r?\n/).some((line) => line.trim() !== '');
        },
        async sendAutoDeliveryRequest(action, extraFields = {}) {
            const fd = new FormData();
            fd.append('ajax_action', action);
            if (this.productId) {
                fd.append('id', String(this.productId));
            } else if (this.draftToken) {
                fd.append('draft_token', this.draftToken);
            }
            Object.entries(extraFields).forEach(([key, value]) => fd.append(key, value));

            const resp = await fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd
            });
            return resp.json();
        },
        async onToggleAutoDelivery(enabled) {
            this.adAjaxMsg = '';
            this.adAjaxError = false;
            if (!enabled && !this.productId && !this.draftToken) return;

            this.adAjaxSaving = true;

            try {
                const data = await this.sendAutoDeliveryRequest('toggle_auto_delivery', {
                    enabled: enabled ? '1' : '0'
                });
                if (!data || !data.ok) throw new Error((data && data.msg) ? data.msg : 'Falha ao salvar.');
                this.adHasConfig = !!data.has_config;
                if (enabled && data.effective_enabled === false) {
                    this.adAjaxMsg = data.msg || 'Use o botão Gerenciar Estoque Automático para cadastrar os itens antes de ativar.';
                } else if (enabled) {
                    this.adAjaxMsg = data.draft ? 'Rascunho atualizado.' : 'Entrega automática ativada.';
                } else if (!enabled) {
                    this.adAjaxMsg = data.draft ? 'Rascunho atualizado.' : 'Entrega automática desativada.';
                }
            } catch (e) {
                this.adAjaxError = true;
                this.adAjaxMsg = 'Não foi possível atualizar agora.';
            } finally {
                this.adAjaxSaving = false;
            }
        },
        async saveAutoDeliveryItems() {
            this.adAjaxMsg = '';
            this.adAjaxError = false;
            if (!this.adEnabled || (!this.productId && !this.draftToken)) return;
            this.adAjaxSaving = true;

            try {
                const data = await this.sendAutoDeliveryRequest('save_auto_delivery_items', {
                    items: this.adItems || '',
                    enabled: this.adEnabled ? '1' : '0'
                });
                if (!data || !data.ok) throw new Error((data && data.msg) ? data.msg : 'Falha ao salvar.');
                this.adHasConfig = !!data.has_config;
                if (this.adEnabled && data.effective_enabled === false) {
                    this.adAjaxError = true;
                    this.adAjaxMsg = data.msg || 'Use o botão Gerenciar Estoque Automático para cadastrar os itens antes de ativar.';
                } else {
                    this.adAjaxMsg = data.draft ? 'Rascunho salvo automaticamente.' : 'Itens salvos automaticamente.';
                }
            } catch (e) {
                this.adAjaxError = true;
                this.adAjaxMsg = 'Não foi possível salvar os itens agora.';
            } finally {
                this.adAjaxSaving = false;
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
include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
