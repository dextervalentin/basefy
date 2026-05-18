<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/alup_api.php';

exigirAdmin();

$conn = (new Database())->connect();
alupEnsureDefaults($conn);
alupEnsureTables($conn);

$msg = '';
$err = '';
$balanceInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    $cfgBefore = alupConfig($conn);

    if (!$cfgBefore['base_url_from_env']) {
        alupSettingSet($conn, 'alup.base_url', alupNormalizeBaseUrl((string)($_POST['base_url'] ?? alupDefaultBaseUrl())));
    }
    if (!$cfgBefore['api_key_from_env']) {
        if (!empty($_POST['clear_api_key'])) {
            alupSettingSet($conn, 'alup.api_key', '');
        } else {
            $apiKey = trim((string)($_POST['api_key'] ?? ''));
            if ($apiKey !== '') alupSettingSet($conn, 'alup.api_key', $apiKey);
        }
    }
    if (!$cfgBefore['webhook_secret_from_env']) {
        if (!empty($_POST['clear_webhook_secret'])) {
            alupSettingSet($conn, 'alup.webhook_secret', '');
        } else {
            $webhookSecret = trim((string)($_POST['webhook_secret'] ?? ''));
            if ($webhookSecret !== '') alupSettingSet($conn, 'alup.webhook_secret', $webhookSecret);
        }
    }

    alupSettingSet($conn, 'alup.enabled', isset($_POST['enabled']) ? '1' : '0');
    $cacheSeconds = max(30, min(900, (int)($_POST['catalog_cache_seconds'] ?? 120)));
    alupSettingSet($conn, 'alup.catalog_cache_seconds', (string)$cacheSeconds);

    $msg = 'Configuração AlUp salva.';

    if ($action === 'test') {
        [$okApi, $body, $statusCode] = alupGetBalance($conn);
        if ($okApi) {
            $balanceInfo = $body;
            $msg = 'Conexão AlUp OK. Saldo consultado com sucesso.';
        } else {
            $code = (string)($body['error']['code'] ?? 'erro');
            $message = (string)($body['error']['message'] ?? 'Falha ao consultar saldo.');
            $err = 'Falha no teste AlUp (' . $statusCode . ' / ' . $code . '): ' . $message;
        }
    }
}

$cfg = alupConfig($conn);
$webhookUrl = rtrim(APP_URL, '/') . '/webhooks/alup';

$counts = ['queued' => 0, 'processing' => 0, 'delivered' => 0, 'failed' => 0];
try {
    $q = $conn->query("SELECT status, COUNT(*) AS total FROM external_fulfillments WHERE provider='alup' GROUP BY status");
    if ($q) {
        foreach ($q->fetch_all(MYSQLI_ASSOC) as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }
    }
} catch (Throwable $e) {}

$pageTitle = 'Integração AlUp API';
$activeMenu = 'alup';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="max-w-5xl mx-auto space-y-4">
  <div class="flex flex-wrap gap-2 text-sm">
    <a href="alup" class="px-3 py-1.5 rounded-lg border border-greenx bg-greenx/10 text-greenx font-semibold">Configuração</a>
    <a href="alup_catalog" class="px-3 py-1.5 rounded-lg border border-blackx3 hover:border-greenx">Catálogo</a>
    <a href="alup_fulfillments" class="px-3 py-1.5 rounded-lg border border-blackx3 hover:border-greenx">Fulfillments</a>
  </div>

  <?php if ($msg): ?><div class="rounded-xl bg-greenx/15 border border-greenx/40 text-greenx px-4 py-3 text-sm"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="rounded-xl bg-red-600/15 border border-red-500/40 text-red-300 px-4 py-3 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <div class="rounded-2xl border border-blackx3 bg-blackx2 p-4">
      <p class="text-xs text-zinc-500 uppercase font-semibold">Status</p>
      <p class="mt-1 text-lg font-bold <?= $cfg['enabled'] ? 'text-greenx' : 'text-zinc-300' ?>"><?= $cfg['enabled'] ? 'Ativo' : 'Inativo' ?></p>
    </div>
    <div class="rounded-2xl border border-blackx3 bg-blackx2 p-4">
      <p class="text-xs text-zinc-500 uppercase font-semibold">Fila</p>
      <p class="mt-1 text-lg font-bold text-zinc-100"><?= (int)$counts['queued'] ?> pendente(s)</p>
    </div>
    <div class="rounded-2xl border border-blackx3 bg-blackx2 p-4">
      <p class="text-xs text-zinc-500 uppercase font-semibold">Processando</p>
      <p class="mt-1 text-lg font-bold text-yellow-300"><?= (int)$counts['processing'] ?></p>
    </div>
    <div class="rounded-2xl border border-blackx3 bg-blackx2 p-4">
      <p class="text-xs text-zinc-500 uppercase font-semibold">Entregues</p>
      <p class="mt-1 text-lg font-bold text-greenx"><?= (int)$counts['delivered'] ?></p>
    </div>
  </div>

  <div class="rounded-2xl border border-blackx3 bg-blackx2 p-5">
    <div class="flex items-start justify-between gap-4 mb-5">
      <div>
        <h2 class="text-lg font-semibold">Credenciais e webhook</h2>
        <p class="text-sm text-zinc-400 mt-1">Base técnica para catálogo, fulfillment externo, SMM, SMS e eventos assinados.</p>
      </div>
      <?php if ($balanceInfo): ?>
      <div class="rounded-xl border border-greenx/30 bg-greenx/10 px-4 py-2 text-right">
        <p class="text-xs text-greenx uppercase font-semibold">Saldo AlUp</p>
        <p class="text-lg font-bold">R$ <?= number_format(((int)($balanceInfo['balance_cents'] ?? 0)) / 100, 2, ',', '.') ?></p>
      </div>
      <?php endif; ?>
    </div>

    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="md:col-span-2">
        <label class="inline-flex items-center gap-2 text-sm">
          <input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
          Habilitar integração AlUp
        </label>
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm text-zinc-300 mb-1">Base URL</label>
        <input type="url" name="base_url" value="<?= htmlspecialchars((string)$cfg['base_url'], ENT_QUOTES, 'UTF-8') ?>" <?= $cfg['base_url_from_env'] ? 'readonly' : '' ?> class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 text-sm">
        <?php if ($cfg['base_url_from_env']): ?><p class="text-xs text-zinc-500 mt-1">Definida via ALUP_BASE_URL no ambiente.</p><?php endif; ?>
      </div>

      <div>
        <label class="block text-sm text-zinc-300 mb-1">API Key</label>
        <input type="password" name="api_key" placeholder="<?= $cfg['api_key'] !== '' ? htmlspecialchars(alupMaskSecret((string)$cfg['api_key']), ENT_QUOTES, 'UTF-8') : 'alup_live_sua_chave' ?>" <?= $cfg['api_key_from_env'] ? 'readonly' : '' ?> class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 text-sm">
        <?php if ($cfg['api_key_from_env']): ?><p class="text-xs text-zinc-500 mt-1">Definida via ALUP_API_KEY no ambiente.</p><?php else: ?><label class="mt-2 inline-flex items-center gap-2 text-xs text-zinc-500"><input type="checkbox" name="clear_api_key" value="1"> remover chave salva</label><?php endif; ?>
      </div>

      <div>
        <label class="block text-sm text-zinc-300 mb-1">Webhook Secret</label>
        <input type="password" name="webhook_secret" placeholder="<?= $cfg['webhook_secret'] !== '' ? htmlspecialchars(alupMaskSecret((string)$cfg['webhook_secret']), ENT_QUOTES, 'UTF-8') : 'secret HMAC do webhook' ?>" <?= $cfg['webhook_secret_from_env'] ? 'readonly' : '' ?> class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 text-sm">
        <?php if ($cfg['webhook_secret_from_env']): ?><p class="text-xs text-zinc-500 mt-1">Definido via ALUP_WEBHOOK_SECRET no ambiente.</p><?php else: ?><label class="mt-2 inline-flex items-center gap-2 text-xs text-zinc-500"><input type="checkbox" name="clear_webhook_secret" value="1"> remover secret salvo</label><?php endif; ?>
      </div>

      <div>
        <label class="block text-sm text-zinc-300 mb-1">Cache de catálogo (segundos)</label>
        <input type="number" min="30" max="900" name="catalog_cache_seconds" value="<?= (int)$cfg['catalog_cache_seconds'] ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 text-sm">
      </div>

      <div>
        <label class="block text-sm text-zinc-300 mb-1">URL do webhook</label>
        <div class="flex gap-2">
          <input type="text" readonly value="<?= htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 text-sm text-zinc-400">
          <button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') ?>')" class="rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx">Copiar</button>
        </div>
      </div>

      <div class="md:col-span-2 flex flex-wrap gap-2 pt-2">
        <button name="action" value="save" class="rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2">Salvar</button>
        <button name="action" value="test" class="rounded-xl border border-blackx3 px-4 py-2 font-semibold hover:border-greenx">Salvar e testar saldo</button>
      </div>
    </form>
  </div>

  <div class="rounded-2xl border border-blackx3 bg-blackx2 p-5">
    <h3 class="font-semibold mb-3">Base criada neste passo</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm text-zinc-300">
      <div class="rounded-xl bg-blackx border border-blackx3 p-3"><b class="text-white">external_product_mappings</b><br><span class="text-zinc-500">Vínculo produto Basefy ↔ produto/serviço AlUp.</span></div>
      <div class="rounded-xl bg-blackx border border-blackx3 p-3"><b class="text-white">external_fulfillments</b><br><span class="text-zinc-500">Fila idempotente de compra/entrega externa.</span></div>
      <div class="rounded-xl bg-blackx border border-blackx3 p-3"><b class="text-white">external_catalog_cache</b><br><span class="text-zinc-500">Cache backend para catálogos SMM/SMS/marketplace.</span></div>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
