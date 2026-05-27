<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\saque_novo.php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/vendor_portal.php';
require_once __DIR__ . '/../../src/wallet_portal.php';

exigirVendedor();
$conn = (new Database())->connect();
$uid = (int)($_SESSION['user_id'] ?? 0);
$savedPixData = walletSavedPixData($conn, $uid);
$savedPixKey = (string)($savedPixData['key'] ?? '');
$savedTipoChave = (string)($savedPixData['type'] ?? '');
$pixLocked = $savedPixKey !== '';

// Verification gate — withdrawals require completed profile
$_uid_check = $uid;
if (!contaVerificada($_uid_check)) {
    $_SESSION['flash_error'] = 'Para solicitar saques, complete a verificação da sua conta.';
    header('Location: ' . BASE_PATH . '/verificacao');
    exit;
}

// detectar se existe coluna de PIX e tipo_chave
$pixCol = null;
$tipoChaveCol = null;
$cols = $conn->query("SHOW COLUMNS FROM wallet_withdrawals");
if ($cols) {
    while ($c = $cols->fetch_assoc()) {
        $f = strtolower((string)$c['Field']);
        if (in_array($f, ['pix_chave', 'chave_pix', 'pix_key'], true)) {
            $pixCol = $c['Field'];
        }
        if ($f === 'tipo_chave') {
            $tipoChaveCol = $c['Field'];
        }
    }
}

$activeMenu = 'financeiro';
$finTab = 'saques';
$pageTitle  = 'Solicitar Saque';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = (string)($_POST['valor'] ?? '0');
    $raw = preg_replace('/[^\d,\.]/', '', $raw);
    $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
    $valor = (float)$raw;

    $pix = $savedPixKey;
    $obs = trim((string)($_POST['observacao'] ?? ''));
    $tipoChave = $savedTipoChave ?: walletInferPixKeyType($pix);

    if ($valor <= 0) {
        $erro = 'Informe um valor válido.';
    } elseif (!$pixLocked || $tipoChave === '') {
      $erro = 'Cadastre sua chave PIX no cadastro aprovado antes de solicitar saque.';
    } elseif ($pix === '' || $tipoChave === '') {
      $erro = 'Dados inválidos. Preencha o tipo de chave e a chave PIX.';
    } else {
      [$okSaque, $msgSaque] = walletSolicitarSaque($conn, $uid, $valor, $pix, $obs, $tipoChave);
      if ($okSaque) {
            header('Location: ' . BASE_PATH . '/vendedor/saques');
            exit;
        }
      $erro = $msgSaque ?: 'Não foi possível solicitar o saque.';
    }
}

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';
?>

<div class="max-w-2xl mx-auto">
  <?php include __DIR__ . '/../../views/partials/financeiro_tabs_vendor.php'; ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h2 class="text-lg font-semibold mb-4">Solicitar novo saque</h2>

    <?php if ($erro): ?>
      <div class="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 text-red-300 px-3 py-2 text-sm">
        <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="post" class="space-y-3" x-data="{ tipoChave: <?= htmlspecialchars(json_encode($savedTipoChave, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, pixKey: <?= htmlspecialchars(json_encode($savedPixKey, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?> }">
      <div>
        <label class="text-xs text-zinc-500 uppercase tracking-wide mb-1 block">Valor</label>
        <input type="text" name="valor" id="valor" inputmode="numeric" placeholder="R$ 0,00" required
           class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2">
      </div>

      <div>
        <label class="text-xs text-zinc-500 uppercase tracking-wide mb-1 block">Tipo de chave PIX <span class="text-red-400">*</span></label>
        <?php if ($pixLocked): ?><input type="hidden" name="tipo_chave" value="<?= htmlspecialchars($savedTipoChave, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <select disabled x-model="tipoChave" @change="pixKey = ''" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 opacity-60 cursor-not-allowed" required>
          <option value="">Selecione o tipo</option>
          <option value="CPF">CPF</option>
          <option value="CNPJ">CNPJ</option>
          <option value="Email">Email</option>
          <option value="Telefone">Telefone</option>
          <option value="Aleatoria">Chave aleatória</option>
        </select>
      </div>

      <div>
        <label class="text-xs text-zinc-500 uppercase tracking-wide mb-1 block">Chave PIX <span class="text-red-400">*</span></label>
        <?php if ($pixLocked): ?><input type="hidden" name="pix_chave" value="<?= htmlspecialchars($savedPixKey, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <input type="text" disabled x-model="pixKey" @input="pixKey = applyPixMask(tipoChave, $event.target.value)"
           :placeholder="tipoChave === 'CPF' ? '000.000.000-00' : tipoChave === 'CNPJ' ? '00.000.000/0000-00' : tipoChave === 'Email' ? 'email@exemplo.com' : tipoChave === 'Telefone' ? '(00) 00000-0000' : 'Cole sua chave'"
           :maxlength="tipoChave === 'CPF' ? 14 : tipoChave === 'CNPJ' ? 18 : tipoChave === 'Telefone' ? 15 : 100"
           class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2 opacity-60 cursor-not-allowed" required>
        <?php if ($pixLocked): ?>
          <p class="text-[11px] text-zinc-500 mt-1">Usando a chave PIX salva no seu cadastro aprovado.</p>
        <?php else: ?>
          <p class="text-[11px] text-orange-300 mt-1">Cadastre sua chave PIX no cadastro aprovado antes de solicitar saque.</p>
        <?php endif; ?>
      </div>

      <div>
        <label class="text-xs text-zinc-500 uppercase tracking-wide mb-1 block">Observação</label>
        <input type="text" name="observacao" placeholder="Opcional"
           class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2">
      </div>

      <template x-if="tipoChave === '' || pixKey.trim() === ''">
        <div class="rounded-lg bg-orange-500/10 border border-orange-400/30 text-orange-300 px-3 py-2 text-xs flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          Selecione o tipo e informe a chave PIX para solicitar o saque.
        </div>
      </template>

      <div class="flex gap-2 pt-2">
        <button type="submit" <?= !$pixLocked ? 'disabled' : '' ?> class="bg-greenx text-white font-semibold rounded-xl px-4 py-2 hover:opacity-90 <?= !$pixLocked ? 'opacity-50 cursor-not-allowed' : '' ?>">Solicitar</button>
        <a href="<?= BASE_PATH ?>/vendedor/saques" class="border border-blackx3 rounded-xl px-4 py-2">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<script>
  function applyPixMask(tipo, val) {
    const d = val.replace(/\D/g, '');
    if (tipo === 'CPF') return d.replace(/(\d{3})(\d{0,3})(\d{0,3})(\d{0,2})/, function(_, a, b, c, e) { return a + (b ? '.' + b : '') + (c ? '.' + c : '') + (e ? '-' + e : ''); });
    if (tipo === 'CNPJ') return d.replace(/(\d{2})(\d{0,3})(\d{0,3})(\d{0,4})(\d{0,2})/, function(_, a, b, c, e, f) { return a + (b ? '.' + b : '') + (c ? '.' + c : '') + (e ? '/' + e : '') + (f ? '-' + f : ''); });
    if (tipo === 'Telefone') return d.replace(/(\d{2})(\d{0,5})(\d{0,4})/, function(_, a, b, c) { return '(' + a + ')' + (b ? ' ' + b : '') + (c ? '-' + c : ''); });
    return val;
  }

(function () {
  const el = document.getElementById('valor');
  if (!el) return;

  function maskBRL(v) {
    const digits = (v || '').replace(/\D/g, '');
    const n = (parseInt(digits || '0', 10) / 100);
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  el.addEventListener('input', () => {
    el.value = maskBRL(el.value);
  });

  el.addEventListener('blur', () => {
    if (!el.value.trim()) el.value = 'R$ 0,00';
  });
})();
</script>

<?php
include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
