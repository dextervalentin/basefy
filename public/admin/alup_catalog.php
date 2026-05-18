<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/alup_api.php';

exigirAdmin();

$conn = (new Database())->connect();
alupEnsureDefaults($conn);
alupEnsureTables($conn);

$cfg = alupConfig($conn);
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

$forceRefresh = !empty($_GET['refresh']);
$q = trim((string)($_GET['q'] ?? ''));
$originFilter = (string)($_GET['origin'] ?? 'all');
$deliveryFilter = (string)($_GET['delivery'] ?? 'all');
$stockFilter = (string)($_GET['stock'] ?? 'all');
$linkedFilter = (string)($_GET['linked'] ?? 'all');
$sort = (string)($_GET['sort'] ?? 'default');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = (int)($_GET['pp'] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 25;

$catalog = [];
$catalogFromCache = false;
$catalogError = '';
$catalogBody = [];

if ($cfg['enabled'] && (string)$cfg['api_key'] !== '') {
    [$okApi, $body, $statusCode, $fromCache] = alupListMarketplaceProducts($conn, $forceRefresh, ['limit' => 100, 'offset' => 0]);
    $catalogFromCache = $fromCache;
    $catalogBody = is_array($body) ? $body : [];
    if ($okApi) {
        $catalog = alupExtractList($catalogBody);
    } else {
        $catalogError = 'Falha ao consultar AlUp (' . $statusCode . '): '
            . (string)($body['error']['message'] ?? 'erro desconhecido');
    }
} else {
    $catalogError = 'Integração desabilitada ou sem API Key. Configure na aba Configuração.';
}

$mappings = alupListMappings($conn);
$mappedExternalIds = [];
foreach ($mappings as $m) {
    $mappedExternalIds[(string)$m['external_id']] = $m;
}

$products = [];
$rs = $conn->query("SELECT id, nome, preco, ativo, vendedor_id FROM products ORDER BY nome ASC LIMIT 2000");
if ($rs) $products = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$basefyProductOptions = [];
foreach ($products as $bp) {
  $bpId = (int)($bp['id'] ?? 0);
  $bpName = (string)($bp['nome'] ?? 'Produto Basefy');
  $bpPrice = (float)($bp['preco'] ?? 0);
  $basefyProductOptions[] = [
    'id' => $bpId,
    'name' => $bpName,
    'active' => ((int)($bp['ativo'] ?? 0)) === 1,
    'price_label' => $bpPrice > 0 ? ('R$ ' . number_format($bpPrice, 2, ',', '.')) : 'Sem preço',
    'search' => mb_strtolower('#' . $bpId . ' ' . $bpName),
  ];
}

$vendorOptions = [];
$rsV = $conn->query("SELECT u.id, u.nome, u.email,
        CASE WHEN COALESCE(u.is_vendedor, FALSE) = TRUE THEN 1 ELSE 0 END AS seller_rank,
        COUNT(p.id) AS product_count,
        SUM(CASE WHEN COALESCE(p.ativo, FALSE) = TRUE THEN 1 ELSE 0 END) AS published_count
      FROM users u
      LEFT JOIN products p ON p.vendedor_id = u.id
      GROUP BY u.id, u.nome, u.email, u.is_vendedor
      ORDER BY seller_rank DESC, published_count DESC, product_count DESC, u.nome ASC
      LIMIT 5000");
$vendorRows = $rsV ? ($rsV->fetch_all(MYSQLI_ASSOC) ?: []) : [];
if (empty($vendorRows)) {
  $rsFallback = $conn->query("SELECT u.id, u.nome, u.email,
                                    COUNT(p.id) AS product_count,
                                    SUM(CASE WHEN COALESCE(p.ativo, FALSE) = TRUE THEN 1 ELSE 0 END) AS published_count
                             FROM users u
                             LEFT JOIN products p ON p.vendedor_id = u.id
                             GROUP BY u.id, u.nome, u.email
                             ORDER BY published_count DESC, product_count DESC, u.nome ASC
                             LIMIT 1000");
  $vendorRows = $rsFallback ? ($rsFallback->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}
if (empty($vendorRows)) {
  $rsUsers = $conn->query("SELECT id, nome, email, 0 AS product_count, 0 AS published_count
                           FROM users
                           ORDER BY nome ASC
                           LIMIT 1000");
  $vendorRows = $rsUsers ? ($rsUsers->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}
foreach ($vendorRows as $v) {
    $vendorName = (string)($v['nome'] ?? 'Vendedor');
    $vendorEmail = (string)($v['email'] ?? '');
    $productCount = (int)($v['product_count'] ?? 0);
    $publishedCount = (int)($v['published_count'] ?? 0);
    $meta = trim($vendorEmail);
    if ($publishedCount > 0) {
      $meta .= ($meta !== '' ? ' · ' : '') . $publishedCount . ' publicado' . ($publishedCount > 1 ? 's' : '');
    } elseif ($productCount > 0) {
      $meta .= ($meta !== '' ? ' · ' : '') . $productCount . ' produto' . ($productCount > 1 ? 's' : '');
    }
    $vendorOptions[] = [
      'id' => (int)$v['id'],
      'name' => $vendorName,
      'email' => $vendorEmail,
      'meta' => $meta,
      'published_count' => $publishedCount,
      'search' => mb_strtolower('#' . $v['id'] . ' ' . $vendorName . ' ' . $vendorEmail),
    ];
}

// Categorias de produto
$categoryOptions = [];
$rsC = $conn->query("SELECT id, nome FROM categories WHERE ativo=1 AND tipo='produto' ORDER BY nome ASC LIMIT 500");
if ($rsC) {
  foreach ($rsC->fetch_all(MYSQLI_ASSOC) as $c) {
    $categoryOptions[] = ['id' => (int)$c['id'], 'name' => (string)$c['nome']];
  }
}

$stats = [
    'total' => count($catalog),
    'official' => 0,
    'vendor' => 0,
    'automatic' => 0,
    'manual' => 0,
    'linked' => 0,
];
$storeCounts = [];
foreach ($catalog as $p) {
    $extId = (string)($p['id'] ?? $p['external_id'] ?? '');
    if ($extId !== '' && isset($mappedExternalIds[$extId])) $stats['linked']++;
    if (alupProductIsOfficial($p)) $stats['official']++; else $stats['vendor']++;
    $delivery = (string)($p['delivery_type'] ?? '');
    if ($delivery === 'automatic') $stats['automatic']++;
    if ($delivery === 'manual') $stats['manual']++;
    $storeId = alupProductStoreId($p) ?: 'sem-store-id';
    $storeCounts[$storeId] = ($storeCounts[$storeId] ?? 0) + 1;
}
arsort($storeCounts);

$needle = mb_strtolower($q);
$filtered = array_values(array_filter($catalog, function (array $p) use ($needle, $originFilter, $deliveryFilter, $stockFilter, $linkedFilter, $mappedExternalIds): bool {
    $extId = (string)($p['id'] ?? $p['external_id'] ?? '');
    if ($extId === '') return false;

    if ($needle !== '') {
        $haystack = mb_strtolower(implode(' ', [
            (string)($p['title'] ?? $p['name'] ?? ''),
            (string)($p['description'] ?? ''),
            (string)($p['slug'] ?? ''),
            $extId,
            alupProductStoreId($p),
        ]));
        if (!str_contains($haystack, $needle)) return false;
    }

    $isOfficial = alupProductIsOfficial($p);
    if ($originFilter === 'official' && !$isOfficial) return false;
    if ($originFilter === 'vendor' && $isOfficial) return false;

    $delivery = (string)($p['delivery_type'] ?? '');
    if ($deliveryFilter !== 'all' && $delivery !== $deliveryFilter) return false;

    $stockRaw = $p['stock_quantity'] ?? null;
    $stockKnown = $stockRaw !== null && $stockRaw !== '';
    $stock = $stockKnown ? (int)$stockRaw : null;
    if ($stockFilter === 'available' && (!$stockKnown || $stock <= 0)) return false;
    if ($stockFilter === 'empty' && (!$stockKnown || $stock > 0)) return false;
    if ($stockFilter === 'unknown' && $stockKnown) return false;

    $isLinked = isset($mappedExternalIds[$extId]);
    if ($linkedFilter === 'linked' && !$isLinked) return false;
    if ($linkedFilter === 'unlinked' && $isLinked) return false;

    return true;
}));

usort($filtered, function (array $a, array $b) use ($sort): int {
    return match ($sort) {
        'price_asc' => alupExtractPriceCents($a) <=> alupExtractPriceCents($b),
        'price_desc' => alupExtractPriceCents($b) <=> alupExtractPriceCents($a),
        'stock_desc' => (int)($b['stock_quantity'] ?? -1) <=> (int)($a['stock_quantity'] ?? -1),
        'title_asc' => strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')),
        default => 0,
    };
});

$totalFiltered = count($filtered);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pageItems = array_slice($filtered, $offset, $perPage);

function _alupCatalogUrl(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || $value === 'all' || $value === 'default') unset($params[$key]);
    }
    $query = http_build_query($params);
    return 'alup_catalog' . ($query !== '' ? ('?' . $query) : '');
}

function _alupStoreShort(string $storeId): string
{
    if ($storeId === '' || $storeId === 'sem-store-id') return 'sem store_id';
    return substr($storeId, 0, 8) . '...' . substr($storeId, -4);
}

function _alupJsonScript(array $payload): string
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}';
}

$pageTitle = 'AlUp - Catalogo Marketplace';
$activeMenu = 'alup_catalog';
include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>
<div class="max-w-7xl mx-auto space-y-4">
  <div class="flex flex-wrap gap-2 text-sm">
    <a href="alup" class="px-3 py-1.5 rounded-lg border border-blackx3 hover:border-greenx">Configuração</a>
    <a href="alup_catalog" class="px-3 py-1.5 rounded-lg border border-greenx bg-greenx/10 text-greenx font-semibold">Catálogo</a>
    <a href="alup_fulfillments" class="px-3 py-1.5 rounded-lg border border-blackx3 hover:border-greenx">Fulfillments</a>
  </div>

  <?php if ($msg): ?><div class="rounded-xl bg-greenx/15 border border-greenx/40 text-greenx px-4 py-3 text-sm"><?= htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="rounded-xl bg-red-600/15 border border-red-500/40 text-red-300 px-4 py-3 text-sm"><?= htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($catalogError): ?><div class="rounded-xl bg-yellow-600/15 border border-yellow-500/40 text-yellow-200 px-4 py-3 text-sm"><?= htmlspecialchars($catalogError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <div class="grid grid-cols-2 md:grid-cols-6 gap-2 text-sm">
    <div class="rounded-xl border border-blackx3 bg-blackx2 p-3"><p class="text-xs text-zinc-500 uppercase font-semibold">Carregados</p><p class="text-lg font-bold text-white"><?= (int)$stats['total'] ?></p></div>
    <div class="rounded-xl border border-greenx/40 bg-greenx/10 p-3"><p class="text-xs text-greenx uppercase font-semibold">Loja oficial</p><p class="text-lg font-bold text-white"><?= (int)$stats['official'] ?></p></div>
    <div class="rounded-xl border border-blackx3 bg-blackx2 p-3"><p class="text-xs text-zinc-500 uppercase font-semibold">Vendedores</p><p class="text-lg font-bold text-white"><?= (int)$stats['vendor'] ?></p></div>
    <div class="rounded-xl border border-blackx3 bg-blackx2 p-3"><p class="text-xs text-zinc-500 uppercase font-semibold">Automáticos</p><p class="text-lg font-bold text-white"><?= (int)$stats['automatic'] ?></p></div>
    <div class="rounded-xl border border-blackx3 bg-blackx2 p-3"><p class="text-xs text-zinc-500 uppercase font-semibold">Manuais</p><p class="text-lg font-bold text-white"><?= (int)$stats['manual'] ?></p></div>
    <div class="rounded-xl border border-blackx3 bg-blackx2 p-3"><p class="text-xs text-zinc-500 uppercase font-semibold">Vinculados</p><p class="text-lg font-bold text-white"><?= (int)$stats['linked'] ?></p></div>
  </div>

  <div class="rounded-2xl border border-blackx3 bg-blackx2 p-5 space-y-4">
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold">Catálogo AlUp Marketplace</h2>
        <p class="text-sm text-zinc-400 mt-1">
          <?= (int)$totalFiltered ?> produto(s) no filtro atual · página <?= (int)$page ?> de <?= (int)$totalPages ?>
          <?php if ($catalogFromCache): ?><span class="text-zinc-500">· cache local</span><?php endif; ?>
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(_alupCatalogUrl(['refresh' => 1, 'p' => 1]), ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx">Atualizar catálogo</a>
        <a href="alup_catalog" class="rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx">Limpar filtros</a>
      </div>
    </div>

    <form method="get" class="rounded-2xl border border-blackx3 bg-blackx/40 p-3 grid grid-cols-1 md:grid-cols-6 gap-3">
      <div class="md:col-span-2">
        <label class="block text-xs text-zinc-500 mb-1">Busca</label>
        <input name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Nome, descrição, slug, ID ou store_id" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-sm outline-none focus:border-greenx">
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Origem</label>
        <select name="origin" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-sm outline-none focus:border-greenx">
          <option value="all" <?= $originFilter === 'all' ? 'selected' : '' ?>>Todas</option>
          <option value="official" <?= $originFilter === 'official' ? 'selected' : '' ?>>Loja oficial</option>
          <option value="vendor" <?= $originFilter === 'vendor' ? 'selected' : '' ?>>Vendedores</option>
        </select>
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Entrega</label>
        <select name="delivery" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-sm outline-none focus:border-greenx">
          <option value="all" <?= $deliveryFilter === 'all' ? 'selected' : '' ?>>Todas</option>
          <option value="automatic" <?= $deliveryFilter === 'automatic' ? 'selected' : '' ?>>Automática</option>
          <option value="manual" <?= $deliveryFilter === 'manual' ? 'selected' : '' ?>>Manual</option>
        </select>
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Estoque</label>
        <select name="stock" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-sm outline-none focus:border-greenx">
          <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>Todos</option>
          <option value="available" <?= $stockFilter === 'available' ? 'selected' : '' ?>>Com estoque</option>
          <option value="empty" <?= $stockFilter === 'empty' ? 'selected' : '' ?>>Zerado</option>
          <option value="unknown" <?= $stockFilter === 'unknown' ? 'selected' : '' ?>>Sem info</option>
        </select>
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Vínculo</label>
        <select name="linked" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-sm outline-none focus:border-greenx">
          <option value="all" <?= $linkedFilter === 'all' ? 'selected' : '' ?>>Todos</option>
          <option value="linked" <?= $linkedFilter === 'linked' ? 'selected' : '' ?>>Vinculados</option>
          <option value="unlinked" <?= $linkedFilter === 'unlinked' ? 'selected' : '' ?>>Sem vínculo</option>
        </select>
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Ordenar</label>
        <select name="sort" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-sm outline-none focus:border-greenx">
          <option value="default" <?= $sort === 'default' ? 'selected' : '' ?>>Padrão AlUp</option>
          <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Menor preço</option>
          <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Maior preço</option>
          <option value="stock_desc" <?= $sort === 'stock_desc' ? 'selected' : '' ?>>Mais estoque</option>
          <option value="title_asc" <?= $sort === 'title_asc' ? 'selected' : '' ?>>Nome A-Z</option>
        </select>
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Por página</label>
        <select name="pp" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-sm outline-none focus:border-greenx">
          <?php foreach ([10,25,50,100] as $pp): ?><option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="md:col-span-5 flex items-end gap-2">
        <input type="hidden" name="p" value="1">
        <button class="rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2 text-sm">Aplicar filtros</button>
      </div>
    </form>

    <div class="rounded-xl border border-blackx3 bg-blackx/40 p-3">
      <p class="text-xs text-zinc-500 uppercase font-semibold mb-2">Lojas detectadas pela API</p>
      <div class="flex flex-wrap gap-2 text-xs">
        <?php foreach ($storeCounts as $storeId => $count): ?>
          <span class="rounded-lg border <?= $storeId === ALUP_OFFICIAL_STORE_ID ? 'border-greenx/40 bg-greenx/10 text-greenx' : 'border-blackx3 text-zinc-300' ?> px-2 py-1">
            <?= $storeId === ALUP_OFFICIAL_STORE_ID ? 'Oficial' : 'Vendedor' ?> · <?= htmlspecialchars(_alupStoreShort((string)$storeId), ENT_QUOTES, 'UTF-8') ?> · <?= (int)$count ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (empty($pageItems)): ?>
      <p class="text-sm text-zinc-500 py-6 text-center">Nenhum produto encontrado com os filtros atuais.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-zinc-400 text-xs uppercase">
            <tr class="border-b border-blackx3">
              <th class="text-left py-2 px-2 min-w-[360px]">Produto AlUp</th>
              <th class="text-left py-2 px-2">Origem</th>
              <th class="text-left py-2 px-2">Entrega</th>
              <th class="text-left py-2 px-2">Estoque</th>
              <th class="text-left py-2 px-2">Preço fornecedor</th>
              <th class="text-left py-2 px-2">Vinculado a</th>
              <th class="text-right py-2 px-2">Ação</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pageItems as $p):
              $extId = (string)($p['id'] ?? $p['external_id'] ?? '');
              if ($extId === '') continue;
              $title = (string)($p['title'] ?? $p['name'] ?? 'Produto AlUp');
              $descr = (string)($p['description'] ?? '');
              $priceCents = alupExtractPriceCents($p);
              $priceBRL = $priceCents > 0 ? ('R$ ' . number_format($priceCents / 100, 2, ',', '.')) : '—';
              $kind = (string)($p['kind'] ?? 'marketplace');
              $existing = $mappedExternalIds[$extId] ?? null;
              $rowId = 'alup_row_' . md5($extId);
              $payloadId = 'alup_payload_' . md5($extId);
              $storeId = alupProductStoreId($p);
              $isOfficial = alupProductIsOfficial($p);
              $stockRaw = $p['stock_quantity'] ?? null;
              $stockText = ($stockRaw === null || $stockRaw === '') ? 'sem info' : number_format((int)$stockRaw, 0, ',', '.');
              $salesCount = isset($p['sales_count']) && $p['sales_count'] !== null ? (int)$p['sales_count'] : null;
              $selectedProductId = $existing ? (int)$existing['product_id'] : 0;
              $selectedProductLabel = $existing ? ('#' . $selectedProductId . ' · ' . (string)($existing['product_nome'] ?? 'Produto Basefy')) : '';
            ?>
            <tr class="border-b border-blackx3/50 align-top hover:bg-white/[0.02] transition-colors">
              <td class="py-3 px-2">
                <button type="button" data-payload-id="<?= htmlspecialchars($payloadId, ENT_QUOTES, 'UTF-8') ?>" onclick="alupOpenDetails(this)" class="font-semibold text-white text-left hover:text-greenx transition-colors">
                  <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                </button>
                <div class="text-[11px] text-zinc-500 mt-1 font-mono break-all">ID: <?= htmlspecialchars($extId, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($descr !== ''): ?>
                  <button type="button" data-payload-id="<?= htmlspecialchars($payloadId, ENT_QUOTES, 'UTF-8') ?>" onclick="alupOpenDetails(this)" class="block text-left text-xs text-zinc-500 mt-1 hover:text-zinc-300 transition-colors">
                    <span class="line-clamp-2"><?= htmlspecialchars(mb_substr($descr, 0, 220), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="mt-1 inline-block text-[11px] font-semibold text-greenx">Ver descrição completa</span>
                  </button>
                <?php endif; ?>
                <?php if (!empty($p['slug'])): ?><div class="text-[11px] text-zinc-600 mt-1">slug: <?= htmlspecialchars((string)$p['slug'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
              </td>
              <td class="py-3 px-2">
                <span class="inline-block rounded-md px-2 py-0.5 text-xs font-semibold <?= $isOfficial ? 'bg-greenx/20 text-greenx border border-greenx/40' : 'bg-zinc-700 text-zinc-200' ?>"><?= htmlspecialchars(alupProductOriginLabel($p), ENT_QUOTES, 'UTF-8') ?></span>
                <div class="text-[11px] text-zinc-500 mt-1 font-mono"><?= htmlspecialchars(_alupStoreShort($storeId), ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td class="py-3 px-2 text-zinc-300"><?= htmlspecialchars(alupProductDeliveryLabel($p), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-3 px-2 text-zinc-300">
                <?= htmlspecialchars($stockText, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($salesCount !== null): ?><div class="text-[11px] text-zinc-500">vendas: <?= (int)$salesCount ?></div><?php endif; ?>
              </td>
              <td class="py-3 px-2 text-zinc-200 font-semibold"><?= $priceBRL ?></td>
              <td class="py-3 px-2">
                <?php if ($existing): ?>
                  <div class="text-greenx text-xs font-semibold">VINCULADO</div>
                  <div class="text-zinc-300 text-sm">#<?= (int)$existing['product_id'] ?> — <?= htmlspecialchars((string)($existing['product_nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <?php else: ?>
                  <span class="text-zinc-500 text-xs">não vinculado</span>
                <?php endif; ?>
              </td>
              <td class="py-3 px-2 text-right">
                <div class="inline-flex flex-col sm:flex-row justify-end gap-1.5">
                  <button type="button" data-payload-id="<?= htmlspecialchars($payloadId, ENT_QUOTES, 'UTF-8') ?>" onclick="alupOpenDetails(this)" class="rounded-lg border border-blackx3 px-3 py-1.5 text-xs hover:border-greenx hover:text-white transition">
                    Detalhes
                  </button>
                  <button type="button"
                          data-payload-id="<?= htmlspecialchars($payloadId, ENT_QUOTES, 'UTF-8') ?>"
                          data-existing-id="<?= $existing ? (int)$existing['id'] : '' ?>"
                          data-existing-product-id="<?= $existing ? (int)$existing['product_id'] : '' ?>"
                          data-existing-label="<?= $existing ? htmlspecialchars('#' . (int)$existing['product_id'] . ' · ' . (string)($existing['product_nome'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>"
                          onclick="alupOpenLinkModal(this)"
                          class="alup-btn-primary rounded-lg px-3 py-1.5 text-xs font-semibold">
                    <?= $existing ? 'Gerenciar vínculo' : 'Vincular' ?>
                  </button>
                </div>
              </td>
            </tr>
            <script type="application/json" id="<?= htmlspecialchars($payloadId, ENT_QUOTES, 'UTF-8') ?>"><?= _alupJsonScript($p) ?></script>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 pt-3 border-t border-blackx3">
        <p class="text-xs text-zinc-500">Mostrando <?= $totalFiltered > 0 ? (int)($offset + 1) : 0 ?>–<?= (int)min($offset + $perPage, $totalFiltered) ?> de <?= (int)$totalFiltered ?></p>
        <div class="flex flex-wrap gap-2 text-sm">
          <a href="<?= htmlspecialchars(_alupCatalogUrl(['p' => max(1, $page - 1)]), ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg border border-blackx3 px-3 py-1.5 <?= $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:border-greenx' ?>">Anterior</a>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="<?= htmlspecialchars(_alupCatalogUrl(['p' => $i]), ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg border px-3 py-1.5 <?= $i === $page ? 'border-greenx bg-greenx/10 text-greenx font-semibold' : 'border-blackx3 hover:border-greenx' ?>"><?= (int)$i ?></a>
          <?php endfor; ?>
          <a href="<?= htmlspecialchars(_alupCatalogUrl(['p' => min($totalPages, $page + 1)]), ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg border border-blackx3 px-3 py-1.5 <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:border-greenx' ?>">Próxima</a>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($mappings)): ?>
  <div class="rounded-2xl border border-blackx3 bg-blackx2 p-5">
    <h3 class="font-semibold mb-3">Vínculos ativos (<?= count($mappings) ?>)</h3>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-zinc-400 text-xs uppercase">
          <tr class="border-b border-blackx3">
            <th class="text-left py-2 px-2">Produto Basefy</th>
            <th class="text-left py-2 px-2">Produto AlUp</th>
            <th class="text-left py-2 px-2">Origem</th>
            <th class="text-left py-2 px-2">Última sync</th>
            <th class="text-right py-2 px-2">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mappings as $m):
            $payload = json_decode((string)($m['external_payload'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
          ?>
          <tr class="border-b border-blackx3/50">
            <td class="py-2 px-2">#<?= (int)$m['product_id'] ?> — <?= htmlspecialchars((string)($m['product_nome'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="py-2 px-2">
              <div class="text-zinc-300"><?= htmlspecialchars((string)($payload['title'] ?? $m['external_id']), ENT_QUOTES, 'UTF-8') ?></div>
              <div class="font-mono text-xs text-zinc-500"><?= htmlspecialchars((string)$m['external_id'], ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <td class="py-2 px-2 text-zinc-300"><?= $payload ? htmlspecialchars(alupProductOriginLabel($payload), ENT_QUOTES, 'UTF-8') : '—' ?></td>
            <td class="py-2 px-2 text-zinc-500 text-xs"><?= htmlspecialchars((string)($m['last_synced_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="py-2 px-2 text-right">
              <form method="post" action="../api/admin_alup_action" class="inline" onsubmit="return confirm('Remover este vínculo?')">
                <input type="hidden" name="action" value="delete_mapping">
                <input type="hidden" name="mapping_id" value="<?= (int)$m['id'] ?>">
                <button class="rounded-lg border border-red-500/40 text-red-300 px-3 py-1 text-xs hover:bg-red-500/10">Remover</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>
.alup-modal{position:fixed;inset:0;z-index:1000002;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;animation:alupFade .18s ease-out;isolation:isolate}
.alup-modal.hidden{display:none}
.alup-modal-backdrop{position:fixed;inset:0;z-index:0;background:rgba(2,2,4,.78);backdrop-filter:blur(8px) saturate(1.1);-webkit-backdrop-filter:blur(8px) saturate(1.1)}
.alup-modal-wrap{position:relative;z-index:2;width:100%;max-width:64rem;display:flex;align-items:center;justify-content:center;animation:alupRise .22s cubic-bezier(.2,.9,.3,1.2)}
#alupLinkModal .alup-modal-wrap{max-width:54rem}
.alup-modal-card,.alup-modal-wrap>.flex{position:relative;z-index:3;display:flex;flex-direction:column;width:100%;max-height:calc(100dvh - 4rem);overflow:hidden;border-radius:20px;border:1px solid rgba(255,255,255,.06);background:linear-gradient(180deg,#101013 0%,#0a0a0c 100%);box-shadow:0 24px 64px -16px rgba(0,0,0,.7),0 0 0 1px rgba(16,185,129,.06)}
.alup-modal-header{position:relative;padding:18px 22px;border-bottom:1px solid rgba(255,255,255,.06);background:radial-gradient(120% 140% at 0% 0%,rgba(16,185,129,.18),transparent 55%)}
.alup-modal-body{flex:1;min-height:0;overflow-y:auto;padding:20px 22px}
.alup-btn-primary{background:linear-gradient(180deg,#10b981,#059669);color:#fff;border:1px solid rgba(255,255,255,.08);box-shadow:0 6px 16px -8px rgba(16,185,129,.6),inset 0 1px 0 rgba(255,255,255,.18);transition:transform .12s,filter .12s}
.alup-btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.alup-btn-primary:active{transform:translateY(0)}
.alup-tab{padding:10px 14px;font-size:13px;font-weight:600;color:#a1a1aa;border-bottom:2px solid transparent;transition:color .15s,border-color .15s;cursor:pointer;background:transparent}
.alup-tab[aria-selected="true"]{color:#10b981;border-color:#10b981}
.alup-tab:hover:not([aria-selected="true"]){color:#e4e4e7}
.alup-tab-panel[hidden]{display:none}
.alup-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:8px;font-size:11px;font-weight:600;border:1px solid rgba(255,255,255,.06)}
.alup-input{width:100%;background:#08080a;border:1px solid #232327;border-radius:12px;padding:10px 12px;font-size:14px;color:#fff;outline:none;transition:border-color .15s,box-shadow .15s}
.alup-input:focus{border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.12)}
.alup-combo-list{max-height:14rem;overflow-y:auto;border:1px solid #232327;background:#08080a;border-radius:12px;box-shadow:0 18px 32px -12px rgba(0,0,0,.6)}
.alup-combo-list:empty{display:none}
.alup-combo-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;font-size:13px;color:#e4e4e7;border-bottom:1px solid rgba(255,255,255,.03);cursor:pointer;text-align:left;width:100%;background:transparent}
.alup-combo-item:last-child{border-bottom:none}
.alup-combo-item:hover,.alup-combo-item.is-active{background:rgba(16,185,129,.08);color:#fff}
.alup-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
@media(min-width:768px){.alup-info-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
.alup-info-cell{border:1px solid #1f1f23;background:rgba(8,8,10,.6);border-radius:12px;padding:10px}
.alup-info-cell .label{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#71717a}
.alup-info-cell .value{font-size:13px;font-weight:600;color:#fafafa;margin-top:3px}
@keyframes alupFade{from{opacity:0}to{opacity:1}}
@keyframes alupRise{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
.alup-spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:alupSpin .8s linear infinite;vertical-align:-2px;margin-right:6px}
@keyframes alupSpin{to{transform:rotate(360deg)}}
</style>

<div id="alupDetailsModal" class="alup-modal hidden">
  <div class="alup-modal-backdrop" onclick="alupCloseDetails()"></div>
  <div class="alup-modal-wrap">
  <div class="flex w-full flex-col overflow-hidden rounded-2xl border border-blackx3 bg-blackx2 shadow-2xl shadow-black/50" style="max-height:calc(100vh - 8rem);max-height:calc(100dvh - 8rem);">
    <div class="shrink-0 flex items-start justify-between gap-4 border-b border-blackx3 px-5 py-4">
      <div class="min-w-0">
        <p id="alupDetailOrigin" class="text-xs font-semibold uppercase text-greenx"></p>
        <h3 id="alupDetailTitle" class="mt-1 text-xl font-bold text-white break-words">Produto AlUp</h3>
        <p id="alupDetailId" class="mt-1 font-mono text-xs text-zinc-500 break-all"></p>
      </div>
      <button type="button" onclick="alupCloseDetails()" class="shrink-0 rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx">Fechar</button>
    </div>
    <div class="min-h-0 flex-1 overflow-y-auto p-5 space-y-4">
      <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-4">
        <div id="alupDetailMedia" class="rounded-2xl border border-blackx3 bg-blackx/50 p-3 min-h-[180px]"></div>
        <div class="space-y-3">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
            <div class="rounded-xl border border-blackx3 bg-blackx/50 p-3"><p class="text-xs text-zinc-500">Preço</p><p id="alupDetailPrice" class="font-semibold text-white"></p></div>
            <div class="rounded-xl border border-blackx3 bg-blackx/50 p-3"><p class="text-xs text-zinc-500">Compare</p><p id="alupDetailCompare" class="font-semibold text-white"></p></div>
            <div class="rounded-xl border border-blackx3 bg-blackx/50 p-3"><p class="text-xs text-zinc-500">Entrega</p><p id="alupDetailDelivery" class="font-semibold text-white"></p></div>
            <div class="rounded-xl border border-blackx3 bg-blackx/50 p-3"><p class="text-xs text-zinc-500">Estoque</p><p id="alupDetailStock" class="font-semibold text-white"></p></div>
          </div>
          <div class="rounded-2xl border border-blackx3 bg-blackx/50 p-3">
            <div class="flex items-center justify-between gap-2 mb-2">
              <p class="text-xs font-semibold uppercase text-zinc-500">Descrição completa</p>
              <p id="alupDetailDescriptionMeta" class="text-[11px] text-zinc-500"></p>
            </div>
            <div id="alupDetailDescription" class="text-sm text-zinc-300 whitespace-pre-wrap leading-relaxed break-words"></div>
          </div>
        </div>
      </div>
      <div class="rounded-2xl border border-blackx3 bg-blackx/50 p-3">
        <p class="text-xs font-semibold uppercase text-zinc-500 mb-2">Campos principais</p>
        <div id="alupDetailFields" class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm"></div>
      </div>
      <div class="rounded-2xl border border-blackx3 bg-blackx/50 p-3">
        <div class="flex items-center justify-between gap-2 mb-2">
          <p class="text-xs font-semibold uppercase text-zinc-500">Payload completo</p>
          <button type="button" onclick="alupCopyDetails()" class="rounded-lg border border-blackx3 px-3 py-1.5 text-xs hover:border-greenx">Copiar JSON</button>
        </div>
        <pre id="alupDetailRaw" class="max-h-80 overflow-auto rounded-xl bg-blackx border border-blackx3 p-3 text-xs text-zinc-300 whitespace-pre-wrap break-words"></pre>
      </div>
    </div>
  </div>
  </div>
</div>

<div id="alupLinkModal" class="alup-modal hidden" role="dialog" aria-modal="true" aria-labelledby="alupLinkTitle">
  <div class="alup-modal-backdrop" onclick="alupCloseLinkModal()"></div>
  <div class="alup-modal-wrap">
    <div class="alup-modal-card">
      <header class="alup-modal-header">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase text-greenx tracking-wider" id="alupLinkOrigin">Vincular produto AlUp</p>
            <h3 id="alupLinkTitle" class="mt-1 text-lg font-bold text-white break-words"></h3>
            <p id="alupLinkId" class="mt-0.5 font-mono text-[11px] text-zinc-500 break-all"></p>
          </div>
          <button type="button" onclick="alupCloseLinkModal()" class="shrink-0 inline-flex h-9 w-9 items-center justify-center rounded-xl border border-blackx3 text-zinc-400 hover:border-greenx hover:text-white transition" aria-label="Fechar">
            <i data-lucide="x" class="h-4 w-4"></i>
          </button>
        </div>
        <div class="alup-info-grid mt-3">
          <div class="alup-info-cell"><div class="label">Fornecedor</div><div class="value" id="alupLinkPrice">—</div></div>
          <div class="alup-info-cell"><div class="label">Entrega</div><div class="value" id="alupLinkDelivery">—</div></div>
          <div class="alup-info-cell"><div class="label">Estoque</div><div class="value" id="alupLinkStock">—</div></div>
          <div class="alup-info-cell"><div class="label">Store</div><div class="value font-mono" id="alupLinkStore">—</div></div>
        </div>
      </header>

      <div class="flex items-center gap-1 px-5 pt-3 border-b border-blackx3" role="tablist">
        <button type="button" role="tab" id="alupTabImport" class="alup-tab" aria-selected="true" data-tab="import" onclick="alupSwitchLinkTab('import')">Importar e criar</button>
        <button type="button" role="tab" id="alupTabLink" class="alup-tab" aria-selected="false" data-tab="link" onclick="alupSwitchLinkTab('link')">Vincular existente</button>
        <span class="ml-auto hidden" id="alupLinkExistingBadge">
          <span class="alup-pill bg-greenx/15 text-greenx border-greenx/30"><i data-lucide="link-2" class="h-3 w-3"></i>Já vinculado</span>
        </span>
      </div>

      <div class="alup-modal-body">
        <section class="alup-tab-panel" data-panel="import">
          <p class="text-xs text-zinc-400 mb-3">Cria ou atualiza o produto Basefy com nome, descrição, imagem, preço e variantes do AlUp, já aprovado e publicado. <b class="text-greenx">1 clique.</b></p>
          <form id="alupImportForm" class="grid grid-cols-1 md:grid-cols-2 gap-3" onsubmit="return alupSubmitImport(event)">
            <input type="hidden" name="action" value="import_product">
            <input type="hidden" name="external_id" id="alupImportExternalId">
            <input type="hidden" name="kind" id="alupImportKind" value="marketplace">
            <input type="hidden" name="payload_json" id="alupImportPayload">

            <div class="md:col-span-2" data-vendor-picker>
              <label class="block text-xs text-zinc-400 mb-1">Vendedor da loja <span class="text-red-400">*</span></label>
              <input type="hidden" name="vendor_id" id="alupImportVendorId" value="">
              <input type="search" id="alupImportVendorInput" data-vendor-input
                     placeholder="Buscar vendedor por nome, e-mail ou ID"
                     onfocus="alupRenderVendorList(this)"
                     oninput="alupRenderVendorList(this)"
                     autocomplete="off" class="alup-input">
              <div data-vendor-list class="hidden mt-2 alup-combo-list"></div>
              <p data-vendor-selected class="mt-2 text-xs text-zinc-500">Nenhum vendedor selecionado.</p>
            </div>

            <div>
              <label class="block text-xs text-zinc-400 mb-1">Categoria</label>
              <select name="categoria_id" id="alupImportCategory" class="alup-input">
                <?php foreach ($categoryOptions as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars((string)$c['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
                <?php if (empty($categoryOptions)): ?><option value="0">— sem categorias —</option><?php endif; ?>
              </select>
            </div>

            <div>
              <label class="block text-xs text-zinc-400 mb-1">Markup sobre o custo (%)</label>
              <input type="number" name="markup_percent" id="alupImportMarkup" value="30" min="0" max="500" step="1" class="alup-input" oninput="alupRecalcImportPrice()">
              <p id="alupImportPriceHint" class="mt-1 text-[11px] text-zinc-500"></p>
            </div>

            <div class="md:col-span-2">
              <label class="block text-xs text-zinc-400 mb-1">Sobrescrever nome (opcional)</label>
              <input type="text" name="nome_override" id="alupImportNome" class="alup-input" placeholder="Deixe vazio para usar o nome do AlUp">
            </div>

            <div class="md:col-span-2 flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-blackx3">
              <span class="inline-flex items-center gap-2 text-xs text-greenx">
                <i data-lucide="shield-check" class="h-4 w-4"></i>
                Produto aprovado e publicado automaticamente
              </span>
              <button type="submit" id="alupImportSubmit" class="alup-btn-primary rounded-xl px-5 py-2.5 text-sm font-semibold">
                Importar e vincular
              </button>
            </div>
          </form>
        </section>

        <section class="alup-tab-panel" data-panel="link" hidden>
          <p class="text-xs text-zinc-400 mb-3">Vincula este produto AlUp a um produto Basefy já existente.</p>
          <form id="alupLinkForm" method="post" action="../api/admin_alup_action" onsubmit="return alupRequireBasefyProductForm(this)">
            <input type="hidden" name="action" value="save_mapping">
            <input type="hidden" name="external_id" id="alupLinkExternalId">
            <input type="hidden" name="kind" id="alupLinkKind" value="marketplace">
            <input type="hidden" name="payload_json" id="alupLinkPayload">
            <input type="hidden" name="mapping_id" id="alupLinkMappingId" value="">

            <div data-basefy-picker>
              <label class="block text-xs text-zinc-400 mb-1">Produto Basefy <span class="text-red-400">*</span></label>
              <input type="hidden" name="product_id" id="alupLinkProductId" value="">
              <input type="search" data-basefy-input
                     id="alupLinkBasefyInput"
                     placeholder="Digite ID ou nome do produto Basefy"
                     onfocus="alupRenderBasefyList(this)"
                     oninput="alupRenderBasefyList(this)"
                     autocomplete="off" class="alup-input">
              <div data-basefy-list class="hidden mt-2 alup-combo-list"></div>
              <p data-basefy-selected class="mt-2 text-xs text-zinc-500">Nenhum produto selecionado.</p>
            </div>

            <div class="mt-4 flex flex-wrap justify-between gap-2 pt-3 border-t border-blackx3">
              <button type="button" id="alupLinkDeleteBtn" class="hidden rounded-xl border border-red-500/50 text-red-300 hover:bg-red-500/10 px-4 py-2 text-sm" onclick="alupDeleteCurrentMapping()">Remover vínculo</button>
              <button type="submit" class="alup-btn-primary rounded-xl px-5 py-2.5 text-sm font-semibold ml-auto">Salvar vínculo</button>
            </div>
          </form>
        </section>
      </div>
    </div>
  </div>
</div>

<script>
const ALUP_OFFICIAL_STORE_ID_JS = '<?= htmlspecialchars(ALUP_OFFICIAL_STORE_ID, ENT_QUOTES, 'UTF-8') ?>';
const ALUP_BASEFY_PRODUCTS = <?= _alupJsonScript($basefyProductOptions) ?>;
const ALUP_VENDORS = <?= _alupJsonScript($vendorOptions) ?>;
let alupCurrentDetailPayload = null;
let alupCurrentLinkPayload = null;
let alupCurrentLinkCostCents = 0;
const ALUP_PRICE_KEYS = ['price_cents', 'cost_cents', 'supplier_cost_cents', 'amount_cents', 'unit_price_cents', 'sale_price_cents', 'value_cents', 'preco_centavos', 'valor_centavos', 'price', 'cost', 'supplier_cost', 'amount', 'unit_price', 'sale_price', 'base_price', 'public_price', 'value', 'preco', 'valor'];

function alupFormatBRLFromCents(value) {
  const number = Number(value || 0);
  if (!Number.isFinite(number) || number <= 0) return '—';
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(number / 100);
}

function alupMoneyValueToCents(value) {
  if (value === undefined || value === null || value === '' || typeof value === 'object') return 0;
  let raw = String(value).replace(/R\$/g, '').replace(/\s/g, '').trim();
  if (!raw) return 0;
  const hasDecimal = raw.includes(',') || raw.includes('.');
  if (raw.includes(',') && raw.includes('.')) raw = raw.replace(/\./g, '');
  raw = raw.replace(',', '.');
  const number = Number(raw);
  if (!Number.isFinite(number) || number <= 0) return 0;
  if (hasDecimal || Math.floor(number) !== number) return Math.round(number * 100);
  return number >= 1000 ? Math.round(number) : Math.round(number * 100);
}

function alupReadPrice(product, keys) {
  for (const key of keys) {
    if (product[key] === undefined || product[key] === null || product[key] === '' || typeof product[key] === 'object') continue;
    if (key.includes('_cents') || key.includes('_centavos')) {
      const cents = Number(String(product[key]).replace(',', '.'));
      if (Number.isFinite(cents) && cents > 0) return Math.round(cents);
      continue;
    }
    const cents = alupMoneyValueToCents(product[key]);
    if (cents > 0) return cents;
  }
  const nestedKeys = ['pricing', 'price_info', 'cost_info', 'supplier', 'product', 'data'];
  for (const key of nestedKeys) {
    if (product[key] && typeof product[key] === 'object' && !Array.isArray(product[key])) {
      const value = alupReadPrice(product[key], keys);
      if (value > 0) return value;
    }
  }
  const listKeys = ['variants', 'variantes', 'options', 'opcoes', 'skus', 'items', 'plans', 'packages', 'services'];
  const prices = [];
  for (const key of listKeys) {
    if (!Array.isArray(product[key])) continue;
    for (const item of product[key]) {
      if (!item || typeof item !== 'object') continue;
      const value = alupReadPrice(item, keys);
      if (value > 0) prices.push(value);
    }
  }
  return prices.length ? Math.min(...prices) : 0;
}

function alupSetText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value === undefined || value === null || value === '' ? '—' : String(value);
}

function alupDeliveryLabel(type) {
  if (type === 'automatic') return 'Automática';
  if (type === 'manual') return 'Manual';
  return 'Não informado';
}

function alupCollectMedia(product) {
  const media = [];
  const add = (item) => {
    if (!item) return;
    if (typeof item === 'string') media.push(item);
    if (typeof item === 'object') media.push(item.url || item.src || item.image_url || item.path || '');
  };
  add(product.image_url);
  add(product.image);
  if (Array.isArray(product.images)) product.images.forEach(add);
  if (Array.isArray(product.media)) product.media.forEach(add);
  return [...new Set(media.filter(Boolean))].slice(0, 8);
}

function alupRenderMedia(product) {
  const box = document.getElementById('alupDetailMedia');
  if (!box) return;
  const media = alupCollectMedia(product);
  if (!media.length) {
    box.innerHTML = '<div class="h-full min-h-[160px] grid place-items-center text-sm text-zinc-500">Sem mídia no payload</div>';
    return;
  }
  const first = media[0];
  const thumbs = media.map((url) => `<a href="${alupEscapeAttr(url)}" target="_blank" class="block truncate rounded-lg border border-blackx3 px-2 py-1 text-xs text-zinc-400 hover:border-greenx hover:text-white">${alupEscapeHtml(url)}</a>`).join('');
  box.innerHTML = `<img src="${alupEscapeAttr(first)}" alt="" class="w-full aspect-square object-cover rounded-xl border border-blackx3 bg-blackx" onerror="this.style.display='none'"><div class="mt-2 space-y-1">${thumbs}</div>`;
}

function alupEscapeHtml(value) {
  return String(value).replace(/[&<>"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[char]));
}

function alupEscapeAttr(value) {
  return alupEscapeHtml(value).replace(/'/g, '&#039;');
}

function alupRenderFields(product) {
  const box = document.getElementById('alupDetailFields');
  if (!box) return;
  const keys = ['id', 'title', 'slug', 'store_id', 'delivery_type', 'stock_quantity', 'sales_count', 'price', 'compare_price', 'created_at', 'updated_at'];
  box.innerHTML = keys.map((key) => {
    const raw = product[key];
    const value = raw === undefined || raw === null || raw === '' ? '—' : raw;
    return `<div class="rounded-xl border border-blackx3 bg-blackx p-2"><p class="text-[11px] text-zinc-500">${alupEscapeHtml(key)}</p><p class="font-mono text-xs text-zinc-200 break-all">${alupEscapeHtml(value)}</p></div>`;
  }).join('');
}

function alupReadPayloadFromTrigger(trigger) {
  const payloadId = trigger.getAttribute('data-payload-id') || '';
  const payloadNode = payloadId ? document.getElementById(payloadId) : null;
  const raw = payloadNode ? payloadNode.textContent : (trigger.getAttribute('data-product') || '{}');
  try { return JSON.parse(raw || '{}'); } catch (err) { return {}; }
}

function alupGetModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return null;
  const exitOverlay = document.getElementById('page-exit-overlay');
  if (exitOverlay) exitOverlay.classList.remove('fading');
  const preloader = document.getElementById('page-preloader');
  if (preloader) preloader.classList.add('hidden');
  if (modal.parentElement !== document.body) document.body.appendChild(modal);
  return modal;
}

function alupApplyDetails(product, meta) {
  alupCurrentDetailPayload = product;
  const storeId = String(product.store_id || '');
  const price = alupReadPrice(product, ALUP_PRICE_KEYS);
  const compare = alupReadPrice(product, ['compare_price_cents', 'compare_price']);
  alupSetText('alupDetailOrigin', storeId === ALUP_OFFICIAL_STORE_ID_JS ? 'Loja oficial AlUp' : 'Vendedor AlUp');
  alupSetText('alupDetailTitle', product.title || product.name || 'Produto AlUp');
  alupSetText('alupDetailId', product.id ? `ID: ${product.id}` : 'ID não informado');
  alupSetText('alupDetailPrice', alupFormatBRLFromCents(price));
  alupSetText('alupDetailCompare', alupFormatBRLFromCents(compare));
  alupSetText('alupDetailDelivery', alupDeliveryLabel(product.delivery_type));
  alupSetText('alupDetailStock', product.stock_quantity !== undefined && product.stock_quantity !== null && product.stock_quantity !== '' ? product.stock_quantity : 'sem info');
  alupSetText('alupDetailDescription', product.description || 'Sem descrição.');
  alupSetText('alupDetailDescriptionMeta', meta || '');
  alupRenderMedia(product);
  alupRenderFields(product);
  alupSetText('alupDetailRaw', JSON.stringify(product, null, 2));
}

async function alupOpenDetails(button) {
  const product = alupReadPayloadFromTrigger(button);
  alupApplyDetails(product, 'Carregando detalhe...');
  const modal = alupGetModal('alupDetailsModal');
  if (modal) modal.classList.remove('hidden');
  document.body.classList.add('overflow-hidden');

  const externalId = String(product.id || product.external_id || '').trim();
  if (!externalId) {
    alupSetText('alupDetailDescriptionMeta', 'ID não informado.');
    return;
  }

  try {
    const response = await fetch('../api/admin_alup_action?action=product_details&external_id=' + encodeURIComponent(externalId), {
      headers: { 'Accept': 'application/json' }
    });
    const json = await response.json();
    if (json && json.ok && json.product) {
      const detailed = Object.assign({}, product, json.product);
      alupApplyDetails(detailed, 'Detalhe completo da AlUp');
      return;
    }
    alupSetText('alupDetailDescriptionMeta', json && json.msg ? json.msg : 'Detalhe indisponível.');
  } catch (err) {
    alupSetText('alupDetailDescriptionMeta', 'Não foi possível buscar o detalhe agora.');
  }
}

function alupCloseDetails() {
  const modal = document.getElementById('alupDetailsModal');
  if (modal) modal.classList.add('hidden');
  document.body.classList.remove('overflow-hidden');
}

function alupCopyDetails() {
  if (!alupCurrentDetailPayload || !navigator.clipboard) return;
  navigator.clipboard.writeText(JSON.stringify(alupCurrentDetailPayload, null, 2));
}

function alupToggleLinkPanel(rowId) {
  // legacy: no-op (kept for safety)
}

function alupCloseLinkPanel(button) {
  // legacy: no-op
}

// ============= LINK MODAL =============
function alupOpenLinkModal(button) {
  const product = alupReadPayloadFromTrigger(button);
  alupCurrentLinkPayload = product;
  const extId = String(product.id || product.external_id || '');
  const storeId = String(product.store_id || '');
  const cost = alupReadPrice(product, ALUP_PRICE_KEYS);
  alupCurrentLinkCostCents = Number(cost) || 0;

  // Header info
  alupSetText('alupLinkTitle', product.title || product.name || 'Produto AlUp');
  alupSetText('alupLinkId', extId ? ('ID: ' + extId) : '');
  alupSetText('alupLinkOrigin', storeId === ALUP_OFFICIAL_STORE_ID_JS ? 'Loja oficial AlUp' : 'Vendedor AlUp');
  alupSetText('alupLinkPrice', alupFormatBRLFromCents(cost));
  alupSetText('alupLinkDelivery', alupDeliveryLabel(product.delivery_type));
  alupSetText('alupLinkStock', (product.stock_quantity !== undefined && product.stock_quantity !== null && product.stock_quantity !== '') ? product.stock_quantity : 'sem info');
  alupSetText('alupLinkStore', storeId ? (storeId.slice(0, 8) + '…' + storeId.slice(-4)) : '—');

  // Fill form hidden fields
  const payloadJson = JSON.stringify(product);
  document.getElementById('alupImportExternalId').value = extId;
  document.getElementById('alupImportPayload').value = payloadJson;
  document.getElementById('alupImportNome').value = '';
  document.getElementById('alupImportNome').placeholder = product.title || product.name || 'Nome do produto';
  document.getElementById('alupLinkExternalId').value = extId;
  document.getElementById('alupLinkPayload').value = payloadJson;

  alupRecalcImportPrice();

  // Existing mapping state
  const existingId = button.getAttribute('data-existing-id') || '';
  const existingProductId = button.getAttribute('data-existing-product-id') || '';
  const existingLabel = button.getAttribute('data-existing-label') || '';
  const linkExistingBadge = document.getElementById('alupLinkExistingBadge');
  const deleteBtn = document.getElementById('alupLinkDeleteBtn');
  if (existingId) {
    document.getElementById('alupLinkMappingId').value = existingId;
    document.getElementById('alupLinkProductId').value = existingProductId;
    const linkInput = document.getElementById('alupLinkBasefyInput');
    linkInput.value = existingLabel;
    linkInput.dataset.selectedLabel = existingLabel;
    const sel = linkInput.closest('[data-basefy-picker]').querySelector('[data-basefy-selected]');
    if (sel) { sel.textContent = 'Selecionado: ' + existingLabel; }
    linkExistingBadge.classList.remove('hidden');
    deleteBtn.classList.remove('hidden');
    alupSwitchLinkTab('link');
  } else {
    document.getElementById('alupLinkMappingId').value = '';
    document.getElementById('alupLinkProductId').value = '';
    const linkInput = document.getElementById('alupLinkBasefyInput');
    linkInput.value = '';
    linkInput.dataset.selectedLabel = '';
    const sel = linkInput.closest('[data-basefy-picker]').querySelector('[data-basefy-selected]');
    if (sel) { sel.textContent = 'Nenhum produto selecionado.'; }
    linkExistingBadge.classList.add('hidden');
    deleteBtn.classList.add('hidden');
    alupSwitchLinkTab('import');
  }

  // Reset vendor picker
  document.getElementById('alupImportVendorId').value = '';
  const vInput = document.getElementById('alupImportVendorInput');
  vInput.value = '';
  vInput.dataset.selectedLabel = '';
  const vSel = vInput.closest('[data-vendor-picker]').querySelector('[data-vendor-selected]');
  if (vSel) { vSel.textContent = 'Nenhum vendedor selecionado.'; vSel.classList.remove('text-greenx'); vSel.classList.add('text-zinc-500'); }
  // Auto-select first vendor for convenience
  if (ALUP_VENDORS.length === 1) {
    const v = ALUP_VENDORS[0];
    alupApplyVendor(v.id, '#' + v.id + ' · ' + v.name);
  }

  const modal = alupGetModal('alupLinkModal');
  if (modal) modal.classList.remove('hidden');
  document.body.classList.add('overflow-hidden');
  if (window.lucide) window.lucide.createIcons();
}

function alupCloseLinkModal() {
  const modal = document.getElementById('alupLinkModal');
  if (modal) modal.classList.add('hidden');
  if (!document.querySelector('#alupDetailsModal:not(.hidden)')) {
    document.body.classList.remove('overflow-hidden');
  }
}

function alupSwitchLinkTab(name) {
  document.querySelectorAll('#alupLinkModal .alup-tab').forEach(t => t.setAttribute('aria-selected', t.dataset.tab === name ? 'true' : 'false'));
  document.querySelectorAll('#alupLinkModal .alup-tab-panel').forEach(p => p.hidden = (p.dataset.panel !== name));
}

function alupRecalcImportPrice() {
  const markup = Number(document.getElementById('alupImportMarkup').value || 0);
  const cost = alupCurrentLinkCostCents;
  const finalCents = Math.round(cost * (1 + (markup / 100)));
  const hint = document.getElementById('alupImportPriceHint');
  if (!hint) return;
  if (cost <= 0) {
    hint.textContent = 'AlUp não informou custo — defina manualmente após importar.';
    return;
  }
  hint.innerHTML = 'Custo AlUp: <b class="text-zinc-300">' + alupFormatBRLFromCents(cost) + '</b> → preço final: <b class="text-greenx">' + alupFormatBRLFromCents(finalCents) + '</b>';
}

// ============= VENDOR PICKER =============
function alupRenderVendorList(input) {
  const picker = input.closest('[data-vendor-picker]');
  if (!picker) return;
  const list = picker.querySelector('[data-vendor-list]');
  const hidden = picker.querySelector('input[name="vendor_id"]');
  const selected = picker.querySelector('[data-vendor-selected]');
  if ((input.dataset.selectedLabel || '') !== input.value) {
    hidden.value = '';
    if (selected) { selected.textContent = 'Escolha um vendedor da lista.'; selected.classList.remove('text-greenx'); selected.classList.add('text-zinc-500'); }
  }
  const term = input.value.trim().toLowerCase();
  const matches = ALUP_VENDORS.filter(v => !term || String(v.search || '').includes(term));
  if (!matches.length) {
    list.innerHTML = '<div class="px-3 py-4 text-sm text-zinc-500 text-center">Nenhum vendedor encontrado.</div>';
    list.classList.remove('hidden');
    return;
  }
  list.innerHTML = matches.map(v => {
    const label = '#' + v.id + ' · ' + v.name;
    return `<button type="button" class="alup-combo-item" onclick="alupApplyVendor(${v.id}, '${alupEscapeAttr(label)}')">
      <span class="min-w-0"><b class="block truncate text-white">${alupEscapeHtml(label)}</b><span class="text-[11px] text-zinc-500">${alupEscapeHtml(v.meta || v.email || '')}</span></span>
      <i data-lucide="chevron-right" class="h-4 w-4 text-zinc-600"></i>
    </button>`;
  }).join('');
  list.classList.remove('hidden');
  if (window.lucide) window.lucide.createIcons();
}

function alupApplyVendor(id, label) {
  const input = document.getElementById('alupImportVendorInput');
  const hidden = document.getElementById('alupImportVendorId');
  const list = input.closest('[data-vendor-picker]').querySelector('[data-vendor-list]');
  const selected = input.closest('[data-vendor-picker]').querySelector('[data-vendor-selected]');
  hidden.value = String(id);
  input.value = label;
  input.dataset.selectedLabel = label;
  if (selected) { selected.textContent = 'Selecionado: ' + label; selected.classList.remove('text-zinc-500'); selected.classList.add('text-greenx'); }
  if (list) list.classList.add('hidden');
}

// ============= IMPORT SUBMIT =============
async function alupSubmitImport(event) {
  event.preventDefault();
  const form = event.target;
  const vendorId = Number(document.getElementById('alupImportVendorId').value || 0);
  if (vendorId <= 0) {
    const vSel = document.querySelector('[data-vendor-selected]');
    if (vSel) { vSel.textContent = 'Escolha um vendedor antes de importar.'; vSel.classList.remove('text-zinc-500','text-greenx'); vSel.classList.add('text-red-300'); }
    document.getElementById('alupImportVendorInput').focus();
    return false;
  }
  const btn = document.getElementById('alupImportSubmit');
  btn.disabled = true;
  btn.innerHTML = '<span class="alup-spinner"></span>Importando...';
  try {
    const fd = new FormData(form);
    const r = await fetch('../api/admin_alup_action', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
    const j = await r.json().catch(() => null);
    if (j && j.ok) {
      window.location.href = 'alup_catalog?msg=' + encodeURIComponent(j.msg || 'Produto importado e vinculado.');
      return false;
    }
    btn.disabled = false;
    btn.textContent = 'Importar e vincular';
    alert((j && j.msg) ? j.msg : 'Falha ao importar produto.');
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'Importar e vincular';
    alert('Erro de rede ao importar.');
  }
  return false;
}

function alupRequireBasefyProductForm(form) {
  const hidden = form.querySelector('input[name="product_id"]');
  if (hidden && Number(hidden.value || 0) > 0) return true;
  const selected = form.querySelector('[data-basefy-selected]');
  if (selected) { selected.textContent = 'Escolha um produto da lista antes de salvar.'; selected.classList.remove('text-zinc-500','text-greenx'); selected.classList.add('text-red-300'); }
  return false;
}

function alupDeleteCurrentMapping() {
  const id = document.getElementById('alupLinkMappingId').value;
  if (!id) return;
  if (!confirm('Remover este vínculo?')) return;
  const fd = new FormData();
  fd.append('action', 'delete_mapping');
  fd.append('mapping_id', id);
  fetch('../api/admin_alup_action', { method: 'POST', body: fd })
    .finally(() => { window.location.href = 'alup_catalog?msg=' + encodeURIComponent('Vínculo removido.'); });
}

function alupRenderBasefyList(input) {
  const picker = input.closest('[data-basefy-picker]');
  if (!picker) return;
  const list = picker.querySelector('[data-basefy-list]');
  const hidden = picker.querySelector('input[name="product_id"]');
  const selected = picker.querySelector('[data-basefy-selected]');
  if (!list || !hidden) return;

  if ((input.dataset.selectedLabel || '') !== input.value) {
    hidden.value = '';
    if (selected) {
      selected.textContent = 'Escolha um produto da lista abaixo.';
      selected.classList.remove('text-greenx', 'text-red-300');
      selected.classList.add('text-zinc-500');
    }
  }

  const term = input.value.trim().toLowerCase();
  const selectedId = Number(hidden.value || 0);
  const matches = ALUP_BASEFY_PRODUCTS
    .filter((product) => !term || String(product.search || '').includes(term))
    .slice(0, 10);

  if (!matches.length) {
    list.innerHTML = '<div class="px-3 py-4 text-sm text-zinc-500 text-center">Nenhum produto Basefy encontrado.</div>';
    list.classList.remove('hidden');
    return;
  }

  list.innerHTML = matches.map((product) => {
    const label = `#${product.id} · ${product.name}`;
    const active = product.active ? 'Ativo' : 'Inativo';
    const activeClass = product.active ? 'text-greenx bg-greenx/10 border-greenx/30' : 'text-yellow-200 bg-yellow-500/10 border-yellow-500/30';
    const selectedClass = selectedId === Number(product.id) ? 'border-greenx bg-greenx/10' : 'border-blackx3 hover:border-greenx hover:bg-white/[0.03]';
    return `<button type="button" data-id="${product.id}" data-label="${alupEscapeAttr(label)}" onclick="alupSelectBasefyProduct(this)" class="flex min-h-[68px] w-full items-center text-left px-3 py-2.5 border-b border-blackx3/70 last:border-b-0 ${selectedClass} transition-colors">
      <div class="flex w-full items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm font-semibold text-white truncate">${alupEscapeHtml(label)}</p>
          <p class="text-[11px] text-zinc-500 mt-0.5">${alupEscapeHtml(product.price_label || 'Sem preço')}</p>
        </div>
        <span class="shrink-0 rounded-md border px-2 py-0.5 text-[11px] font-semibold ${activeClass}">${active}</span>
      </div>
    </button>`;
  }).join('');
  list.classList.remove('hidden');
}

function alupSelectBasefyProduct(button) {
  const picker = button.closest('[data-basefy-picker]');
  if (!picker) return;
  const input = picker.querySelector('[data-basefy-input]');
  const hidden = picker.querySelector('input[name="product_id"]');
  const list = picker.querySelector('[data-basefy-list]');
  const selected = picker.querySelector('[data-basefy-selected]');
  const label = button.dataset.label || '';
  if (hidden) hidden.value = button.dataset.id || '';
  if (input) {
    input.value = label;
    input.dataset.selectedLabel = label;
  }
  if (selected) {
    selected.textContent = label ? ('Selecionado: ' + label) : 'Nenhum produto selecionado.';
    selected.classList.remove('text-zinc-500', 'text-red-300');
    selected.classList.add('text-greenx');
  }
  if (list) list.classList.add('hidden');
}

function alupRequireBasefyProduct(form) {
  const hidden = form.querySelector('input[name="product_id"]');
  if (hidden && Number(hidden.value || 0) > 0) return true;
  const input = form.querySelector('[data-basefy-input]');
  const selected = form.querySelector('[data-basefy-selected]');
  if (selected) {
    selected.textContent = 'Escolha um produto da lista antes de salvar.';
    selected.classList.remove('border-blackx3', 'bg-blackx/60', 'text-zinc-500', 'border-greenx/30', 'bg-greenx/10', 'text-greenx');
    selected.classList.add('border-red-500/40', 'bg-red-500/10', 'text-red-300');
  }
  if (input) {
    input.focus();
    alupRenderBasefyList(input);
  }
  return false;
}

document.addEventListener('click', (event) => {
  document.querySelectorAll('[data-basefy-list], [data-vendor-list]').forEach((list) => {
    const wrap = list.closest('[data-basefy-picker], [data-vendor-picker]');
    if (wrap && !wrap.contains(event.target)) list.classList.add('hidden');
  });
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') { alupCloseDetails(); alupCloseLinkModal(); }
});
</script>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
