<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\saque_novo.php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/wallet_portal.php';

exigirLogin();

$conn = (new Database())->connect();
$uid  = (int)($_SESSION['user_id'] ?? 0);
$savedPixData = walletSavedPixData($conn, $uid);
$savedPixKey = (string)($savedPixData['key'] ?? '');
$savedTipoChave = (string)($savedPixData['type'] ?? '');
$pixLocked = $savedPixKey !== '';

/* ── Verification gate ── */
if (!contaVerificada($uid)) {
    $_SESSION['flash_error'] = 'Para solicitar saques, complete a verificação da sua conta.';
    header('Location: ' . BASE_PATH . '/verificacao');
    exit;
}

/* ── detect columns ── */
$pixCol = null;
$tipoChaveCol = null;
$cols = $conn->query("SHOW COLUMNS FROM wallet_withdrawals");
if ($cols) {
    while ($c = $cols->fetch_assoc()) {
        $f = strtolower((string)$c['Field']);
        if (in_array($f, ['pix_chave', 'chave_pix', 'pix_key'], true)) $pixCol = $c['Field'];
        if ($f === 'tipo_chave') $tipoChaveCol = $c['Field'];
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
            $_SESSION['flash_success'] = 'Saque solicitado com sucesso. Aguarde a análise do administrador.';
            header('Location: ' . BASE_PATH . '/saques');
            exit;
        }
      $erro = $msgSaque ?: 'Não foi possível solicitar o saque.';
    }
}

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/user_layout_start.php';
?>

<div class="max-w-2xl mx-auto space-y-4">
  <?php include __DIR__ . '/../views/partials/financeiro_tabs.php'; ?>
  <div class="flex items-center gap-3 mb-2">
    <a href="<?= BASE_PATH ?>/saques" class="inline-flex items-center gap-1.5 text-sm text-zinc-400 hover:text-white transition">
      <i data-lucide="arrow-left" class="w-4 h-4"></i> Voltar
    </a>
  </div>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6">
    <div class="flex items-center gap-3 mb-5">
      <div class="w-10 h-10 rounded-xl bg-greenx/15 border border-greenx/30 flex items-center justify-center">
        <i data-lucide="banknote" class="w-5 h-5 text-greenx"></i>
      </div>
      <div>
        <h2 class="text-lg font-semibold">Solicitar novo saque</h2>
        <p class="text-xs text-zinc-500">Preencha os dados da sua chave PIX para receber</p>
      </div>
    </div>

    <?php if ($erro): ?>
      <div class="mb-4 rounded-xl border border-red-500/40 bg-red-500/10 text-red-300 px-4 py-3 text-sm flex items-center gap-2">
        <i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0"></i>
        <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="post" class="space-y-4" x-data="{ tipoChave: <?= htmlspecialchars(json_encode($savedTipoChave, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, pixKey: <?= htmlspecialchars(json_encode($savedPixKey, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?> }">
      <div>
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1">Valor do saque</label>
        <input type="text" name="valor" id="saqueValor" inputmode="numeric" placeholder="R$ 0,00" required
               class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-3 text-lg font-semibold outline-none focus:border-greenx/50 transition">
      </div>

      <div>
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1">Tipo de chave PIX <span class="text-red-400">*</span></label>
        <?php if ($pixLocked): ?><input type="hidden" name="tipo_chave" value="<?= htmlspecialchars($savedTipoChave, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <select disabled x-model="tipoChave" @change="pixKey = ''" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx/50 transition opacity-60 cursor-not-allowed" required>
          <option value="">Selecione o tipo</option>
          <option value="CPF">CPF</option>
          <option value="CNPJ">CNPJ</option>
          <option value="Email">Email</option>
          <option value="Telefone">Telefone</option>
          <option value="Aleatoria">Chave aleatória</option>
        </select>
      </div>

      <div>
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1">Chave PIX <span class="text-red-400">*</span></label>
        <?php if ($pixLocked): ?><input type="hidden" name="pix_chave" value="<?= htmlspecialchars($savedPixKey, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <input type="text" disabled x-model="pixKey" @input="pixKey = applyPixMask(tipoChave, $event.target.value)"
               :placeholder="tipoChave === 'CPF' ? '000.000.000-00' : tipoChave === 'CNPJ' ? '00.000.000/0000-00' : tipoChave === 'Email' ? 'email@exemplo.com' : tipoChave === 'Telefone' ? '(00) 00000-0000' : 'Cole sua chave'"
               :maxlength="tipoChave === 'CPF' ? 14 : tipoChave === 'CNPJ' ? 18 : tipoChave === 'Telefone' ? 15 : 100"
               class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx/50 transition opacity-60 cursor-not-allowed" required>
        <?php if ($pixLocked): ?>
          <p class="text-[11px] text-zinc-500 mt-1">Usando a chave PIX salva no seu cadastro aprovado.</p>
        <?php else: ?>
          <p class="text-[11px] text-orange-300 mt-1">Cadastre sua chave PIX no cadastro aprovado antes de solicitar saque.</p>
        <?php endif; ?>
      </div>

      <div>
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1">Observação <span class="text-zinc-600">(opcional)</span></label>
        <input type="text" name="observacao" placeholder="Ex: banco, titular, etc."
               class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx/50 transition">
      </div>

      <template x-if="tipoChave === '' || pixKey.trim() === ''">
        <div class="rounded-xl bg-orange-500/10 border border-orange-400/30 text-orange-300 px-4 py-3 text-xs flex items-center gap-2">
          <i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0"></i>
          Selecione o tipo e informe a chave PIX para solicitar o saque.
        </div>
      </template>

      <div class="flex gap-3 pt-2">
        <button type="submit" <?= !$pixLocked ? 'disabled' : '' ?> class="flex-1 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-5 py-3 transition-all flex items-center justify-center gap-2 <?= !$pixLocked ? 'opacity-50 cursor-not-allowed' : '' ?>">
          <i data-lucide="send" class="w-4 h-4"></i> Solicitar saque
        </button>
        <a href="<?= BASE_PATH ?>/saques" class="rounded-xl border border-blackx3 hover:border-zinc-600 px-5 py-3 text-sm flex items-center transition">Cancelar</a>
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
  var el = document.getElementById('saqueValor');
  if (!el) return;
  function maskBRL(v) {
    var digits = (v || '').replace(/\D/g, '');
    var n = (parseInt(digits || '0', 10) / 100);
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }
  el.addEventListener('input', function() { el.value = maskBRL(el.value); });
  el.addEventListener('blur', function() { if (!el.value.trim()) el.value = 'R$ 0,00'; });
})();
</script>

<?php
include __DIR__ . '/../views/partials/user_layout_end.php';
include __DIR__ . '/../views/partials/footer.php';
