<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/affiliates.php';

exigirAdmin();

$conn = (new Database())->connect();
affEnsureTables($conn);
affEnsureDefaults($conn);

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commission  = max(0, min(50, (float)($_POST['commission_percent'] ?? 8)));
    $cookieDays  = max(1, min(365, (int)($_POST['cookie_days'] ?? 30)));
    $minPayout   = max(1, (float)($_POST['min_payout'] ?? 50));
    $autoApprove = isset($_POST['auto_approve']) ? '1' : '0';
    $enabled     = isset($_POST['program_enabled']) ? '1' : '0';
    $selfRef     = isset($_POST['allow_self_referral']) ? '1' : '0';
    $name        = trim((string)($_POST['program_name'] ?? 'Programa de Afiliados'));
    $desc        = trim((string)($_POST['program_description'] ?? ''));

    affSettingSet($conn, 'commission_percent', number_format($commission, 2, '.', ''));
    affSettingSet($conn, 'cookie_days', (string)$cookieDays);
    affSettingSet($conn, 'min_payout', number_format($minPayout, 2, '.', ''));
    affSettingSet($conn, 'auto_approve', $autoApprove);
    affSettingSet($conn, 'program_enabled', $enabled);
    affSettingSet($conn, 'allow_self_referral', $selfRef);
    affSettingSet($conn, 'program_name', $name);
    affSettingSet($conn, 'program_description', $desc);

    $msg = 'Configurações do programa de afiliados atualizadas.';
}

$rules = affRules($conn);

$pageTitle  = 'Config. Afiliados';
$activeMenu = 'afiliados';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="max-w-4xl mx-auto space-y-6">

  <?php if ($msg): ?>
    <div class="rounded-xl bg-greenx/10 border border-greenx/30 p-4 text-sm text-greenx flex items-center gap-2 animate-fade-in">
      <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0"></i> <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>

  <!-- Header card -->
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6">
    <div class="flex items-center gap-3 mb-1">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-greenx to-greenxd flex items-center justify-center">
        <i data-lucide="megaphone" class="w-5 h-5 text-white"></i>
      </div>
      <div>
        <h2 class="text-lg font-bold">Programa de Afiliados</h2>
        <p class="text-xs text-zinc-500">Configure comissões, regras e comportamento do programa</p>
      </div>
    </div>
  </div>

  <!-- Config form -->
  <form method="post" class="bg-blackx2 border border-blackx3 rounded-2xl p-6 space-y-6">

    <!-- Program toggle -->
    <div class="flex items-center justify-between p-4 rounded-xl bg-blackx border border-blackx3">
      <div>
        <h3 class="text-sm font-semibold">Programa ativo</h3>
        <p class="text-xs text-zinc-500 mt-0.5">Permite novas inscrições e rastreamento de referrals</p>
      </div>
      <label class="relative inline-flex items-center cursor-pointer">
        <input type="checkbox" name="program_enabled" value="1" <?= $rules['program_enabled'] ? 'checked' : '' ?> class="sr-only peer">
        <div class="w-11 h-6 bg-blackx3 rounded-full peer peer-checked:bg-greenx peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
      </label>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Program name -->
      <div>
        <label class="block text-sm font-medium text-zinc-300 mb-1.5">Nome do programa</label>
        <input type="text" name="program_name" value="<?= htmlspecialchars($rules['program_name']) ?>"
               class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm text-white focus:border-greenx focus:ring-1 focus:ring-greenx outline-none transition">
      </div>

      <!-- Commission rate -->
      <div>
        <label class="block text-sm font-medium text-zinc-300 mb-1.5">Comissão padrão (%)</label>
        <input type="number" name="commission_percent" value="<?= $rules['commission_percent'] ?>" step="0.5" min="0" max="50"
               class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm text-white focus:border-greenx focus:ring-1 focus:ring-greenx outline-none transition">
        <p class="text-[11px] text-zinc-600 mt-1">Percentual aplicado sobre o valor do pedido</p>
      </div>

      <!-- Cookie duration -->
      <div>
        <label class="block text-sm font-medium text-zinc-300 mb-1.5">Duração do cookie (dias)</label>
        <input type="number" name="cookie_days" value="<?= $rules['cookie_days'] ?>" min="1" max="365"
               class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm text-white focus:border-greenx focus:ring-1 focus:ring-greenx outline-none transition">
        <p class="text-[11px] text-zinc-600 mt-1">Tempo que o link de referência é válido após o clique</p>
      </div>

      <!-- Min payout -->
      <div>
        <label class="block text-sm font-medium text-zinc-300 mb-1.5">Saque mínimo (R$)</label>
        <input type="number" name="min_payout" value="<?= $rules['min_payout'] ?>" step="1" min="1"
               class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm text-white focus:border-greenx focus:ring-1 focus:ring-greenx outline-none transition">
      </div>
    </div>

    <!-- Description -->
    <div>
      <label class="block text-sm font-medium text-zinc-300 mb-1.5">Descrição do programa (público)</label>
      <textarea name="program_description" rows="3"
                class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm text-white focus:border-greenx focus:ring-1 focus:ring-greenx outline-none transition resize-none"><?= htmlspecialchars($rules['program_description']) ?></textarea>
    </div>

    <!-- Toggles -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="flex items-center justify-between p-4 rounded-xl bg-blackx border border-blackx3">
        <div>
          <h3 class="text-sm font-semibold">Aprovar automaticamente</h3>
          <p class="text-xs text-zinc-500 mt-0.5">Novos afiliados ficam ativos sem aprovação manual</p>
        </div>
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="checkbox" name="auto_approve" value="1" <?= $rules['auto_approve'] ? 'checked' : '' ?> class="sr-only peer">
          <div class="w-11 h-6 bg-blackx3 rounded-full peer peer-checked:bg-greenx peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
        </label>
      </div>

      <div class="flex items-center justify-between p-4 rounded-xl bg-blackx border border-blackx3">
        <div>
          <h3 class="text-sm font-semibold">Permitir auto-referência</h3>
          <p class="text-xs text-zinc-500 mt-0.5">Afiliado ganha comissão nas próprias compras</p>
        </div>
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="checkbox" name="allow_self_referral" value="1" <?= $rules['allow_self_referral'] ? 'checked' : '' ?> class="sr-only peer">
          <div class="w-11 h-6 bg-blackx3 rounded-full peer peer-checked:bg-greenx peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
        </label>
      </div>
    </div>

    <div class="flex justify-end">
      <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-6 py-2.5 text-sm transition-colors">
        <i data-lucide="save" class="w-4 h-4"></i> Salvar configurações
      </button>
    </div>
  </form>

  <!-- Quick info card -->
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6">
    <h3 class="text-sm font-semibold mb-3 flex items-center gap-2"><i data-lucide="info" class="w-4 h-4 text-zinc-400"></i> Como funciona</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs text-zinc-400">
      <div class="p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="text-greenx font-bold mb-1">1. Inscrição</div>
        Qualquer usuário pode se inscrever como afiliado na página pública. Recebe um código de referência único.
      </div>
      <div class="p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="text-greenx font-bold mb-1">2. Divulgação</div>
        O afiliado compartilha links com seu código. Visitantes que clicam recebem um cookie de <?= $rules['cookie_days'] ?> dias.
      </div>
      <div class="p-3 rounded-xl bg-blackx border border-blackx3">
        <div class="text-greenx font-bold mb-1">3. Comissão</div>
        Cada compra via referral gera <?= number_format($rules['commission_percent'], 1) ?>% de comissão. Saque disponível a partir de R$ <?= number_format($rules['min_payout'], 2, ',', '.') ?>.
      </div>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
