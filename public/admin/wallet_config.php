<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/wallet_escrow.php';

exigirAdmin();

$conn = (new Database())->connect();
escrowEnsureDefaults($conn);

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $days = max(1, min(60, (int)($_POST['auto_release_days'] ?? 3)));
  $confirmedHours = max(1, min(168, (int)($_POST['confirmed_release_hours'] ?? 24)));
    $fee = max(0, min(100, (float)($_POST['platform_fee_percent'] ?? 5)));
    $enabled = isset($_POST['auto_release_enabled']) ? '1' : '0';
  $withdrawAutoEnabled = '0';
    $adminId = max(0, (int)($_POST['platform_admin_user_id'] ?? 0));

    escrowSettingSet($conn, 'wallet.auto_release_days', (string)$days);
    escrowSettingSet($conn, 'wallet.confirmed_release_hours', (string)$confirmedHours);
    escrowSettingSet($conn, 'wallet.platform_fee_percent', number_format($fee, 2, '.', ''));
    escrowSettingSet($conn, 'wallet.auto_release_enabled', $enabled);
    escrowSettingSet($conn, 'wallet.withdraw_auto_enabled', $withdrawAutoEnabled);
    escrowSettingSet($conn, 'wallet.platform_admin_user_id', (string)$adminId);

    $msg = 'Regras da wallet atualizadas.';
}

$rules = escrowRules($conn);
$admins = [];
$q = $conn->query("SELECT id, nome, email FROM users WHERE role IN ('admin','administrador') ORDER BY id ASC");
if ($q) {
    $admins = $q->fetch_all(MYSQLI_ASSOC);
}

$pageTitle = 'Configuração Wallet';
$activeMenu = 'wallet_config';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="max-w-4xl mx-auto bg-blackx2 border border-blackx3 rounded-xl p-5 space-y-4">
  <?php if ($msg): ?><div class="rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <h2 class="text-lg font-semibold">Regras do escrow</h2>

  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
      <label class="block text-sm text-zinc-300 mb-1">Dias para auto-liberação</label>
      <input type="number" min="1" max="60" name="auto_release_days" value="<?= (int)$rules['auto_release_days'] ?>" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2">
      <p class="text-xs text-zinc-500 mt-1">Quando o comprador não confirma a entrega.</p>
    </div>

    <div>
      <label class="block text-sm text-zinc-300 mb-1">Horas após confirmação da entrega</label>
      <input type="number" min="1" max="168" name="confirmed_release_hours" value="<?= (int)$rules['confirmed_release_hours'] ?>" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2">
      <p class="text-xs text-zinc-500 mt-1">Padrão atual: 24 horas.</p>
    </div>

    <div>
      <label class="block text-sm text-zinc-300 mb-1">Taxa da plataforma (%)</label>
      <input type="number" step="0.01" min="0" max="100" name="platform_fee_percent" value="<?= htmlspecialchars(number_format((float)$rules['platform_fee_percent'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2">
    </div>

    <div class="md:col-span-2">
      <label class="block text-sm text-zinc-300 mb-1">Admin recebedor da taxa</label>
      <select name="platform_admin_user_id" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2">
        <option value="0">Selecionar automaticamente (primeiro admin ativo)</option>
        <?php foreach ($admins as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= (int)$rules['platform_admin_user_id'] === (int)$a['id'] ? 'selected' : '' ?>>
            #<?= (int)$a['id'] ?> - <?= htmlspecialchars((string)$a['nome'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$a['email'], ENT_QUOTES, 'UTF-8') ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="md:col-span-2">
      <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="auto_release_enabled" value="1" <?= $rules['auto_release_enabled'] ? 'checked' : '' ?>>
        Habilitar auto-liberação por prazo
      </label>
    </div>

    <div class="md:col-span-2">
      <button class="rounded-lg bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2">Salvar regras</button>
    </div>
  </form>
</div>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
