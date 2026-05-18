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

// Carrega catálogo (cacheado)
$forceRefresh = !empty($_GET['refresh']);
$catalog = [];
$catalogFromCache = false;
$catalogError = '';

if ($cfg['enabled'] && (string)$cfg['api_key'] !== '') {
    [$okApi, $body, $statusCode, $fromCache] = alupListMarketplaceProducts($conn, $forceRefresh);
    $catalogFromCache = $fromCache;
    if ($okApi) {
        $catalog = alupExtractList($body);
    } else {
        $catalogError = 'Falha ao consultar AlUp (' . $statusCode . '): '
            . (string)($body['error']['message'] ?? 'erro desconhecido');
    }
} else {
    $catalogError = 'Integração desabilitada ou sem API Key. Configure na aba Configuração.';
}

// Mapeamentos existentes (para mostrar status "vinculado")
$mappings = alupListMappings($conn);
$mappedExternalIds = [];
foreach ($mappings as $m) {
    $mappedExternalIds[(string)$m['external_id']] = $m;
}

// Lista de produtos Basefy para o select de vinculação
$products = [];
$rs = $conn->query("SELECT id, nome, preco, ativo FROM products ORDER BY nome ASC LIMIT 2000");
if ($rs) $products = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$pageTitle = 'AlUp — Catálogo Marketplace';
$activeMenu = 'alup_catalog';
include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>
<div class="max-w-7xl mx-auto space-y-4">

  <!-- Subnav AlUp -->
  <div class="flex flex-wrap gap-2 text-sm">
    <a href="alup" class="px-3 py-1.5 rounded-lg border border-blackx3 hover:border-greenx">Configuração</a>
    <a href="alup_catalog" class="px-3 py-1.5 rounded-lg border border-greenx bg-greenx/10 text-greenx font-semibold">Catálogo</a>
    <a href="alup_fulfillments" class="px-3 py-1.5 rounded-lg border border-blackx3 hover:border-greenx">Fulfillments</a>
  </div>

  <?php if ($msg): ?><div class="rounded-xl bg-greenx/15 border border-greenx/40 text-greenx px-4 py-3 text-sm"><?= htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="rounded-xl bg-red-600/15 border border-red-500/40 text-red-300 px-4 py-3 text-sm"><?= htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($catalogError): ?><div class="rounded-xl bg-yellow-600/15 border border-yellow-500/40 text-yellow-200 px-4 py-3 text-sm"><?= htmlspecialchars($catalogError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <div class="rounded-2xl border border-blackx3 bg-blackx2 p-5">
    <div class="flex items-start justify-between gap-3 mb-3">
      <div>
        <h2 class="text-lg font-semibold">Catálogo AlUp Marketplace</h2>
        <p class="text-sm text-zinc-400 mt-1">
          Vincule cada produto AlUp a um produto Basefy existente. Após pagamento confirmado,
          o fulfillment é disparado automaticamente.
          <?php if ($catalogFromCache): ?><span class="text-zinc-500">(cache local)</span><?php endif; ?>
        </p>
      </div>
      <a href="?refresh=1" class="rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx">Atualizar catálogo</a>
    </div>

    <?php if (empty($catalog)): ?>
      <p class="text-sm text-zinc-500 py-6 text-center">Nenhum produto retornado pela AlUp.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-zinc-400 text-xs uppercase">
            <tr class="border-b border-blackx3">
              <th class="text-left py-2 px-2">Produto AlUp</th>
              <th class="text-left py-2 px-2">External ID</th>
              <th class="text-left py-2 px-2">Preço fornecedor</th>
              <th class="text-left py-2 px-2">Vinculado a</th>
              <th class="text-right py-2 px-2">Ação</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($catalog as $p):
              $extId = (string)($p['id'] ?? $p['external_id'] ?? '');
              if ($extId === '') continue;
              $title = (string)($p['title'] ?? $p['name'] ?? 'Produto AlUp');
              $descr = (string)($p['description'] ?? '');
              $priceCents = alupExtractPriceCents($p);
              $priceBRL = $priceCents > 0 ? ('R$ ' . number_format($priceCents / 100, 2, ',', '.')) : '—';
              $kind = (string)($p['kind'] ?? 'marketplace');
              $existing = $mappedExternalIds[$extId] ?? null;
              $rowId = 'alup_row_' . md5($extId);
            ?>
            <tr class="border-b border-blackx3/50 align-top">
              <td class="py-3 px-2">
                <div class="font-semibold text-white"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($descr !== ''): ?>
                  <div class="text-xs text-zinc-500 mt-1 line-clamp-2"><?= htmlspecialchars(mb_substr($descr, 0, 200), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </td>
              <td class="py-3 px-2 text-zinc-400 font-mono text-xs"><?= htmlspecialchars($extId, ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-3 px-2 text-zinc-200"><?= $priceBRL ?></td>
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
              <td colspan="5" class="px-2 py-3">
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
            <th class="text-left py-2 px-2">External ID AlUp</th>
            <th class="text-left py-2 px-2">Tipo</th>
            <th class="text-left py-2 px-2">Última sync</th>
            <th class="text-right py-2 px-2">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mappings as $m): ?>
          <tr class="border-b border-blackx3/50">
            <td class="py-2 px-2">#<?= (int)$m['product_id'] ?> — <?= htmlspecialchars((string)($m['product_nome'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="py-2 px-2 font-mono text-xs text-zinc-400"><?= htmlspecialchars((string)$m['external_id'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="py-2 px-2 text-zinc-300"><?= htmlspecialchars((string)$m['kind'], ENT_QUOTES, 'UTF-8') ?></td>
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
