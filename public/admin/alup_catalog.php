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
$rs = $conn->query("SELECT id, nome, preco, ativo FROM products ORDER BY nome ASC LIMIT 2000");
if ($rs) $products = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

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
              $storeId = alupProductStoreId($p);
              $isOfficial = alupProductIsOfficial($p);
              $stockRaw = $p['stock_quantity'] ?? null;
              $stockText = ($stockRaw === null || $stockRaw === '') ? 'sem info' : number_format((int)$stockRaw, 0, ',', '.');
              $salesCount = isset($p['sales_count']) && $p['sales_count'] !== null ? (int)$p['sales_count'] : null;
            ?>
            <tr class="border-b border-blackx3/50 align-top">
              <td class="py-3 px-2">
                <div class="font-semibold text-white"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-[11px] text-zinc-500 mt-1 font-mono break-all">ID: <?= htmlspecialchars($extId, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($descr !== ''): ?>
                  <div class="text-xs text-zinc-500 mt-1 line-clamp-2"><?= htmlspecialchars(mb_substr($descr, 0, 220), ENT_QUOTES, 'UTF-8') ?></div>
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
                <button type="button" onclick="document.getElementById('<?= $rowId ?>').classList.toggle('hidden')" class="rounded-lg border border-blackx3 px-3 py-1.5 text-xs hover:border-greenx">
                  <?= $existing ? 'Reatribuir / Remover' : 'Vincular' ?>
                </button>
              </td>
            </tr>
            <tr id="<?= $rowId ?>" class="hidden bg-blackx/40">
              <td colspan="7" class="px-2 py-3">
                <form method="post" action="../api/admin_alup_action" class="flex flex-wrap items-end gap-2">
                  <input type="hidden" name="action" value="save_mapping">
                  <input type="hidden" name="external_id" value="<?= htmlspecialchars($extId, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="kind" value="<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="payload_json" value='<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}", ENT_QUOTES, "UTF-8") ?>'>
                  <div class="flex-1 min-w-[260px]">
                    <label class="block text-xs text-zinc-400 mb-1">Produto Basefy</label>
                    <select name="product_id" required class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 text-sm">
                      <option value="">— escolher —</option>
                      <?php foreach ($products as $bp): ?>
                        <option value="<?= (int)$bp['id'] ?>" <?= ($existing && (int)$existing['product_id'] === (int)$bp['id']) ? 'selected' : '' ?>>
                          #<?= (int)$bp['id'] ?> · <?= htmlspecialchars((string)$bp['nome'], ENT_QUOTES, 'UTF-8') ?>
                          <?= !((int)$bp['ativo']) ? ' (inativo)' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button type="submit" class="rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2 text-sm">Salvar vínculo</button>
                  <?php if ($existing): ?>
                    <button type="submit" name="action" value="delete_mapping" formaction="../api/admin_alup_action"
                            onclick="return confirm('Remover vínculo?')"
                            class="rounded-xl border border-red-500/50 text-red-300 hover:bg-red-500/10 px-4 py-2 text-sm">Remover vínculo</button>
                    <input type="hidden" name="mapping_id" value="<?= (int)$existing['id'] ?>">
                  <?php endif; ?>
                </form>
              </td>
            </tr>
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

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
