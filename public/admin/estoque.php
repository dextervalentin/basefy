<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_produtos.php';
require_once __DIR__ . '/../../src/stock_items.php';

exigirAdmin();

$db = new Database();
$conn = $db->connect();

stockEnsureTables($conn);

$productId = (int)($_GET['id'] ?? 0);
if ($productId < 1) {
    header('Location: produtos');
    exit;
}

$ownerCol = colunaExiste($conn, 'products', 'vendedor_id')
    ? 'vendedor_id'
    : (colunaExiste($conn, 'products', 'user_id') ? 'user_id' : null);
$ownerSelect = $ownerCol !== null ? 'p.' . $ownerCol : 'NULL';

$stP = $conn->prepare("SELECT p.id, p.nome, p.tipo, p.variantes, p.imagem, p.auto_delivery_enabled, p.auto_delivery_intro, p.auto_delivery_conclusion,
                              COALESCE(NULLIF(u.nome, ''), u.email, 'Vendedor') AS vendedor_nome
                       FROM products p
                       LEFT JOIN users u ON u.id = {$ownerSelect}
                       WHERE p.id = ?
                       LIMIT 1");
$stP->bind_param('i', $productId);
$stP->execute();
$produto = $stP->get_result()->fetch_assoc();
$stP->close();

if (!$produto) {
    header('Location: produtos');
    exit;
}

$tipo = (string)($produto['tipo'] ?? 'produto');
$variantes = [];
if ($tipo === 'dinamico') {
    $variantes = json_decode((string)($produto['variantes'] ?? ''), true) ?: [];
}

$imgUrl = normalizarProdutoImagemUrl((string)($produto['imagem'] ?? ''));
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['stock_action'] ?? '');

    if ($postAction === 'save_config') {
        $enabled = isset($_POST['auto_delivery_enabled']);
        $intro = trim((string)($_POST['auto_delivery_intro'] ?? ''));
        $conclusion = trim((string)($_POST['auto_delivery_conclusion'] ?? ''));
        stockSaveDeliveryConfig($conn, $productId, $enabled, $intro, $conclusion);
        $produto['auto_delivery_enabled'] = $enabled;
        $produto['auto_delivery_intro'] = $intro;
        $produto['auto_delivery_conclusion'] = $conclusion;
        $msg = 'Configurações de entrega automática salvas.';
    }

    if ($postAction === 'add_items') {
        $varianteNome = ($tipo === 'dinamico') ? trim((string)($_POST['variante_nome'] ?? '')) : null;
        $conteudo = trim((string)($_POST['conteudo'] ?? ''));

        if ($conteudo === '') {
            $err = 'Preencha o conteúdo dos itens.';
        } else {
            $added = stockAddBulk($conn, $productId, explode("\n", $conteudo), $varianteNome);
            if ($added > 0) {
                $msg = $added . ' item(ns) adicionado(s) ao estoque automático.';
            } else {
                $err = 'Nenhum item válido para adicionar.';
            }
        }
    }

    if ($postAction === 'edit_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $newContent = trim((string)($_POST['new_content'] ?? ''));
        if ($itemId < 1 || $newContent === '') {
            $err = 'Dados inválidos para edição.';
        } elseif (stockEditItem($conn, $itemId, $productId, $newContent)) {
            $msg = 'Item atualizado.';
        } else {
            $err = 'Não foi possível editar. Itens já entregues não podem ser alterados.';
        }
    }

    if ($postAction === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0 && stockDeleteItem($conn, $itemId, $productId)) {
            $msg = 'Item removido do estoque.';
        } else {
            $err = 'Não foi possível remover. Itens já entregues não podem ser removidos.';
        }
    }
}

$filterVariante = ($tipo === 'dinamico') ? (string)($_GET['variante'] ?? '') : '';
$filterStatus = (string)($_GET['status'] ?? 'todos');
$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array((int)($_GET['pp'] ?? 10), [5, 10, 20], true) ? (int)($_GET['pp'] ?? 10) : 10;

$statusArg = ($filterStatus !== '' && $filterStatus !== 'todos') ? $filterStatus : '';
$varArg = ($filterVariante !== '' && $filterVariante !== 'todos') ? $filterVariante : null;

$result = stockListItems($conn, $productId, $varArg, $statusArg, $pagina, $pp);
$items = $result['items'];
$totalItems = $result['total'];
$totalPaginas = $result['total_pages'];

$summary = stockSummaryByVariant($conn, $productId);
$variantQtd = [];
if ($tipo === 'dinamico' && !empty($variantes)) {
    foreach ($variantes as $variant) {
        $variantName = (string)($variant['nome'] ?? '');
        if ($variantName === '') continue;
        $variantQtd[$variantName] = (int)($variant['quantidade'] ?? 0);
        $summary[$variantName] = $summary[$variantName] ?? ['disponivel' => 0, 'vendido' => 0, 'total' => 0];
        $summary[$variantName]['config_qtd'] = $variantQtd[$variantName];
    }
}

$totalDisponivel = 0;
if ($tipo === 'dinamico' && $variantQtd) {
    foreach ($variantQtd as $variantName => $configuredQty) {
        $totalDisponivel += max((int)($summary[$variantName]['disponivel'] ?? 0), $configuredQty);
    }
} else {
    foreach ($summary as $stats) {
        $totalDisponivel += (int)($stats['disponivel'] ?? 0);
    }
}

$deliveryConfig = stockGetDeliveryConfig($conn, $productId);

$pageTitle = 'Estoque Automático';
$activeMenu = 'catalogo';
$adminCatalogTab = 'produtos';
$topActions = [['label' => 'Voltar ao produto', 'href' => 'produtos_form?id=' . $productId]];
$subnavItems = [
    ['label' => 'Listar', 'href' => 'produtos', 'active' => false],
    ['label' => 'Editar', 'href' => 'produtos_form?id=' . $productId, 'active' => false],
    ['label' => 'Estoque Automático', 'href' => '#', 'active' => true],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="space-y-5" x-data="{ configOpen: true }">
  <?php include __DIR__ . '/../../views/partials/admin_catalogo_tabs.php'; ?>

  <?php if ($msg): ?>
    <div class="rounded-2xl border border-greenx/30 bg-greenx/[0.08] px-5 py-3.5 text-sm text-greenx flex items-center gap-3">
      <i data-lucide="check-circle-2" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="rounded-2xl border border-red-500/30 bg-red-600/[0.08] px-5 py-3.5 text-sm text-red-300 flex items-center gap-3">
      <i data-lucide="alert-triangle" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 flex flex-col md:flex-row md:items-center gap-4">
    <?php if ($imgUrl): ?>
      <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" class="w-16 h-16 rounded-xl object-cover border border-blackx3 flex-shrink-0" alt="">
    <?php else: ?>
      <div class="w-16 h-16 rounded-xl bg-blackx3 flex items-center justify-center flex-shrink-0"><i data-lucide="package" class="w-6 h-6 text-zinc-600"></i></div>
    <?php endif; ?>
    <div class="flex-1 min-w-0">
      <p class="text-xs uppercase tracking-widest text-zinc-500 font-semibold">Estoque automático</p>
      <h2 class="text-base md:text-lg font-bold truncate"><?= htmlspecialchars((string)$produto['nome'], ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="text-xs text-zinc-500 mt-1">Vendedor: <?= htmlspecialchars((string)($produto['vendedor_nome'] ?? 'Vendedor'), ENT_QUOTES, 'UTF-8') ?> · Disponível: <span class="text-greenx font-semibold"><?= $totalDisponivel ?></span></p>
    </div>
    <div class="flex items-center gap-2 flex-shrink-0">
      <a href="produtos_form?id=<?= $productId ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-blackx3 hover:border-greenx px-3 py-2 text-xs text-zinc-300 hover:text-white transition">
        <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Editar produto
      </a>
    </div>
  </div>

  <?php if ($tipo === 'servico'): ?>
    <div class="rounded-2xl border border-amber-400/30 bg-amber-500/[0.08] p-5 text-sm text-amber-200">
      Este cadastro é um serviço. A entrega automática avançada fica disponível para itens digitais com estoque.
    </div>
  <?php else: ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <div class="flex items-center justify-between cursor-pointer" @click="configOpen = !configOpen">
      <h3 class="text-sm font-semibold flex items-center gap-2">
        <i data-lucide="settings" class="w-4 h-4 text-amber-400"></i> Configurações de Entrega Automática
      </h3>
      <i data-lucide="chevron-down" class="w-4 h-4 text-zinc-500 transition-transform" :class="configOpen && 'rotate-180'"></i>
    </div>
    <div x-show="configOpen" x-transition class="mt-4">
      <form method="post" class="space-y-4">
        <input type="hidden" name="stock_action" value="save_config">
        <label class="flex items-center gap-3 cursor-pointer select-none">
          <div class="relative">
            <input type="checkbox" name="auto_delivery_enabled" value="1" class="sr-only peer" <?= $deliveryConfig['enabled'] ? 'checked' : '' ?>>
            <div class="w-11 h-6 bg-blackx3 peer-focus:ring-2 peer-focus:ring-greenx/40 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-zinc-500 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-greenx peer-checked:after:bg-white"></div>
          </div>
          <span class="text-sm text-zinc-300">Ativar entrega automática</span>
        </label>
        <div>
          <label class="block text-sm text-zinc-400 mb-1">Introdução <span class="text-zinc-600">(mensagem enviada antes do item)</span></label>
          <textarea name="auto_delivery_intro" rows="3" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-greenx resize-y" placeholder="Obrigado pela compra! Segue seu item:"><?= htmlspecialchars($deliveryConfig['intro'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <div>
          <label class="block text-sm text-zinc-400 mb-1">Conclusão <span class="text-zinc-600">(mensagem enviada após o item)</span></label>
          <textarea name="auto_delivery_conclusion" rows="3" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-greenx resize-y" placeholder="Qualquer dúvida, entre em contato. Boas compras!"><?= htmlspecialchars($deliveryConfig['conclusion'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-5 py-2.5 text-sm hover:from-greenx2 hover:to-greenxd transition-all">
          <i data-lucide="save" class="w-4 h-4"></i> Salvar configurações
        </button>
      </form>
    </div>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h3 class="text-sm font-semibold flex items-center gap-2 mb-4">
      <i data-lucide="plus-circle" class="w-4 h-4 text-greenx"></i> Adicionar item(s)
    </h3>
    <p class="text-xs text-zinc-500 mb-4">Cada linha vira um item individual de entrega automática. Para produtos dinâmicos, escolha a variante correta antes de adicionar.</p>
    <form method="post" class="space-y-4">
      <input type="hidden" name="stock_action" value="add_items">
      <?php if ($tipo === 'dinamico' && !empty($variantes)): ?>
      <div>
        <label class="block text-sm text-zinc-400 mb-1">Item do anúncio dinâmico *</label>
        <select name="variante_nome" required class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-greenx">
          <?php foreach ($variantes as $variant):
            $variantName = (string)($variant['nome'] ?? '');
            $variantPrice = number_format((float)($variant['preco'] ?? 0), 2, ',', '.');
            $variantReady = stockCountAvailable($conn, $productId, $variantName);
          ?>
          <option value="<?= htmlspecialchars($variantName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($variantName, ENT_QUOTES, 'UTF-8') ?> — R$ <?= $variantPrice ?>/un — <?= $variantReady ?> itens prontos</option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label class="block text-sm text-zinc-400 mb-1">O que deve ser enviado ao comprador? <span class="text-zinc-600">(um por linha)</span></label>
        <textarea name="conteudo" rows="6" required class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-greenx resize-y font-mono" placeholder="cole-aqui-a-chave-1&#10;cole-aqui-a-chave-2&#10;https://link-download.com&#10;email:senha"></textarea>
      </div>
      <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-semibold px-5 py-2.5 text-sm hover:from-greenx2 hover:to-greenxd transition-all">
        <i data-lucide="plus" class="w-4 h-4"></i> Adicionar
      </button>
    </form>
  </div>

  <?php if ($summary): ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h3 class="text-sm font-semibold flex items-center gap-2 mb-3">
      <i data-lucide="bar-chart-3" class="w-4 h-4 text-purple-400"></i> Resumo do estoque
    </h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <?php foreach ($summary as $variantName => $stats): ?>
      <div class="rounded-xl border border-blackx3 bg-blackx/60 px-4 py-3">
        <p class="text-xs text-zinc-400 font-semibold mb-1"><?= htmlspecialchars((string)$variantName, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="flex items-center gap-3 text-xs flex-wrap">
          <?php if (isset($stats['config_qtd']) && (int)$stats['config_qtd'] > 0): ?>
            <span class="text-purple-400 font-bold"><?= (int)$stats['config_qtd'] ?> configurado</span>
          <?php endif; ?>
          <span class="text-greenx font-bold"><?= (int)($stats['disponivel'] ?? 0) ?> itens prontos</span>
          <span class="text-zinc-500"><?= (int)($stats['vendido'] ?? 0) ?> entregue</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
      <h3 class="text-sm font-semibold flex items-center gap-2">
        <i data-lucide="list" class="w-4 h-4 text-zinc-400"></i> Itens cadastrados
        <span class="text-zinc-500 font-normal">(<?= $totalItems ?>)</span>
      </h3>
      <form method="get" class="flex items-center gap-2 text-xs">
        <input type="hidden" name="id" value="<?= $productId ?>">
        <select name="status" onchange="this.form.submit()" class="bg-blackx border border-blackx3 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:border-greenx">
          <option value="todos" <?= $filterStatus === 'todos' ? 'selected' : '' ?>>Todos</option>
          <option value="disponivel" <?= $filterStatus === 'disponivel' ? 'selected' : '' ?>>Disponível</option>
          <option value="vendido" <?= $filterStatus === 'vendido' ? 'selected' : '' ?>>Entregue</option>
        </select>
        <?php if ($tipo === 'dinamico' && !empty($variantes)): ?>
        <select name="variante" onchange="this.form.submit()" class="bg-blackx border border-blackx3 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:border-greenx">
          <option value="todos">Todas variantes</option>
          <?php foreach ($variantes as $variant): ?>
          <option value="<?= htmlspecialchars((string)$variant['nome'], ENT_QUOTES, 'UTF-8') ?>" <?= $filterVariante === (string)$variant['nome'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$variant['nome'], ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($items)): ?>
      <div class="text-center py-8 text-zinc-500">
        <i data-lucide="package-x" class="w-8 h-8 mx-auto mb-2 opacity-40"></i>
        <p class="text-sm">Nenhum item no estoque automático.</p>
        <p class="text-xs text-zinc-600 mt-1">Adicione itens acima para preparar a entrega automática.</p>
      </div>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($items as $item):
          $itemStatus = (string)($item['status'] ?? 'disponivel');
          $isAvailable = $itemStatus === 'disponivel';
          $statusClass = $isAvailable ? 'text-greenx' : ($itemStatus === 'vendido' ? 'text-purple-400' : 'text-zinc-500');
          $statusLabel = match ($itemStatus) {
              'disponivel' => 'Aguardando comprador',
              'vendido' => 'Entregue',
              default => ucfirst($itemStatus),
          };
        ?>
        <div class="rounded-xl border border-blackx3 bg-blackx/40 px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-2" x-data="{ editing: false }">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 text-xs text-zinc-500 mb-1">
              <span class="font-medium text-zinc-400">#<?= (int)$item['id'] ?></span>
              <?php if (!empty($item['variante_nome'])): ?>
                <span class="px-1.5 py-0.5 rounded bg-greenx/15 text-purple-300 text-[10px]"><?= htmlspecialchars((string)$item['variante_nome'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </div>
            <div x-show="!editing">
              <p class="text-sm text-zinc-300 font-mono truncate" title="<?= htmlspecialchars((string)$item['conteudo'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(mb_strimwidth((string)$item['conteudo'], 0, 80, '...'), ENT_QUOTES, 'UTF-8') ?>
              </p>
            </div>
            <div x-show="editing" x-cloak>
              <form method="post" class="flex items-center gap-2">
                <input type="hidden" name="stock_action" value="edit_item">
                <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                <input type="text" name="new_content" value="<?= htmlspecialchars((string)$item['conteudo'], ENT_QUOTES, 'UTF-8') ?>" class="flex-1 bg-blackx border border-blackx3 rounded-lg px-3 py-1.5 text-sm font-mono outline-none focus:border-greenx">
                <button type="submit" class="text-greenx text-xs font-semibold hover:text-white transition">Salvar</button>
                <button type="button" @click="editing = false" class="text-zinc-500 text-xs hover:text-white transition">Cancelar</button>
              </form>
            </div>
          </div>
          <div class="flex items-center gap-3 flex-shrink-0">
            <span class="text-xs font-medium <?= $statusClass ?>"><?= $statusLabel ?></span>
            <?php if ($isAvailable): ?>
              <button type="button" @click="editing = !editing" class="text-xs text-zinc-400 hover:text-white transition">Editar</button>
              <form method="post" onsubmit="return confirm('Remover este item do estoque?')">
                <input type="hidden" name="stock_action" value="delete_item">
                <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                <button type="submit" class="text-xs text-red-400 hover:text-red-300 transition">Remover</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($totalPaginas > 1): ?>
      <div class="flex items-center justify-center gap-2 mt-5 text-xs">
        <?php for ($page = 1; $page <= $totalPaginas; $page++):
          $query = http_build_query(['id' => $productId, 'status' => $filterStatus, 'variante' => $filterVariante, 'p' => $page, 'pp' => $pp]);
        ?>
          <a href="?<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>" class="w-8 h-8 rounded-lg flex items-center justify-center border <?= $page === $pagina ? 'border-greenx bg-greenx/15 text-greenx' : 'border-blackx3 text-zinc-500 hover:text-white hover:border-greenx' ?>"><?= $page ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; ?>