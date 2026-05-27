<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/wallet_portal.php';

exigirVendedor();

$conn = (new Database())->connect();
$uid = (int)($_SESSION['user_id'] ?? 0);
$savedPixData = walletSavedPixData($conn, $uid);
$savedPixKey = (string)($savedPixData['key'] ?? '');
$savedTipoChave = (string)($savedPixData['type'] ?? '');
$pixLocked = $savedPixKey !== '' && $savedTipoChave !== '';

$msg = '';
$err = '';
$topupTxId = isset($_GET['tx']) ? (int)$_GET['tx'] : 0;

function parseMoneyBRLVendedor(string $raw): float
{
  $clean = preg_replace('/[^\d,\.]/', '', $raw) ?? '';
  if ($clean === '') {
    return 0.0;
  }
  if (str_contains($clean, ',')) {
    $clean = str_replace('.', '', $clean);
    $clean = str_replace(',', '.', $clean);
  }
  return (float)$clean;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

  if ($action === 'topup_create') {
    $valor = parseMoneyBRLVendedor((string)($_POST['valor'] ?? '0'));
    [$ok, $m, $createdTxId] = walletCriarRecargaPix($conn, $uid, $valor);
    if ($ok) {
      $msg = $m;
      $topupTxId = (int)$createdTxId;
    } else {
      $err = $m;
    }
  }

    if ($action === 'withdraw') {
    $valor = parseMoneyBRLVendedor((string)($_POST['valor'] ?? '0'));
        $pix = $savedPixKey;
        $tipoChave = $savedTipoChave ?: walletInferPixKeyType($pix);
        $obs = trim((string)($_POST['observacao'] ?? ''));
        [$ok, $m] = walletSolicitarSaque($conn, $uid, $valor, $pix, $obs, $tipoChave);
        if ($ok) $msg = $m; else $err = $m;
    }
}

$saldo = walletSaldo($conn, $uid);
$txs = [];
$saques = [];
$topupTx = $topupTxId > 0 ? walletObterRecargaPorId($conn, $uid, $topupTxId) : null;
$topupRaw = [];
if ($topupTx && !empty($topupTx['raw_response'])) {
  $decoded = json_decode((string)$topupTx['raw_response'], true);
  if (is_array($decoded)) {
    $topupRaw = (array)($decoded['data'] ?? []);
  }
}
$paymentData = (array)($topupRaw['paymentData'] ?? []);
$pixCode = (string)($paymentData['copyPaste'] ?? $paymentData['pixCode'] ?? $paymentData['qrCode'] ?? '');
$qrCodeImage = (string)($paymentData['pixQrCode'] ?? $paymentData['qrCodeUrl'] ?? '');
$qrCodeBase64 = (string)($paymentData['qrCodeBase64'] ?? '');
if ($qrCodeImage === '' && $qrCodeBase64 !== '') {
  $qrCodeImage = str_starts_with($qrCodeBase64, 'data:') ? $qrCodeBase64 : ('data:image/png;base64,' . $qrCodeBase64);
}
$topupStatus = strtoupper((string)($topupTx['status'] ?? ''));
$showTopupModal = $topupTx ? 'true' : 'false';
$topupValor = $topupTx ? ((int)($topupTx['amount_centavos'] ?? 0) / 100) : 0;


$pageTitle = 'Financeiro';
$activeMenu = 'financeiro';
$finTab = 'wallet';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/vendor_layout_start.php';
?>

<div class="max-w-7xl mx-auto space-y-4">
  <?php include __DIR__ . '/../../views/partials/financeiro_tabs_vendor.php'; ?>
  <?php if ($msg): ?><div class="rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <h2 class="text-lg font-semibold">Saldo atual: R$ <?= number_format($saldo, 2, ',', '.') ?></h2>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <form method="post" class="bg-blackx2 border border-blackx3 rounded-xl p-4 space-y-3">
      <input type="hidden" name="action" value="topup_create">
      <h3 class="font-semibold">Adicionar saldo via PIX</h3>
      <input type="text" name="valor" placeholder="R$ 0,00" class="js-money w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2" required>
      <button class="rounded-lg bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2">Gerar PIX</button>
      <?php if ($topupTx): ?>
        <button type="button" id="openTopupModal" class="rounded-lg bg-blackx border border-blackx3 px-4 py-2 text-sm">Abrir cobrança atual</button>
      <?php endif; ?>
    </form>

    <form method="post" class="bg-blackx2 border border-blackx3 rounded-xl p-4 space-y-3" x-data="{ tipoChave: <?= htmlspecialchars(json_encode($savedTipoChave, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, pixKey: <?= htmlspecialchars(json_encode($savedPixKey, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?> }">
      <input type="hidden" name="action" value="withdraw">
      <h3 class="font-semibold">Solicitar saque</h3>
      <input type="text" name="valor" placeholder="R$ 0,00" class="js-money w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2" required>
      <?php if ($pixLocked): ?><input type="hidden" name="tipo_chave" value="<?= htmlspecialchars($savedTipoChave, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
      <select disabled x-model="tipoChave" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 opacity-60 cursor-not-allowed" required>
        <option value="">Tipo de chave PIX</option>
        <option value="CPF">CPF</option>
        <option value="CNPJ">CNPJ</option>
        <option value="Email">Email</option>
        <option value="Telefone">Telefone</option>
        <option value="Aleatoria">Chave aleatória</option>
      </select>
      <?php if ($pixLocked): ?><input type="hidden" name="pix_key" value="<?= htmlspecialchars($savedPixKey, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
      <input type="text" disabled x-model="pixKey" placeholder="Chave PIX" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 opacity-60 cursor-not-allowed" required>
      <?php if ($pixLocked): ?><p class="text-[11px] text-zinc-500">Usando a chave PIX salva no seu cadastro aprovado.</p><?php else: ?><p class="text-[11px] text-orange-300">Cadastre sua chave PIX no cadastro aprovado antes de solicitar saque.</p><?php endif; ?>
      <input type="text" name="observacao" placeholder="Observação (opcional)" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2">
      <button <?= !$pixLocked ? 'disabled' : '' ?> class="rounded-lg bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2 <?= !$pixLocked ? 'opacity-50 cursor-not-allowed' : '' ?>">Solicitar saque</button>
    </form>
  </div>

  <?php if ($topupTx): ?>
  <div id="topupModal" class="fixed inset-0 <?= $showTopupModal === 'true' ? 'flex' : 'hidden' ?> items-center justify-center bg-black/70 px-4" style="z-index:2147483000">
    <div class="w-full max-w-xl bg-blackx2 border border-blackx3 rounded-2xl p-5 space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="font-semibold text-lg">Recarga PIX</h3>
        <button type="button" id="closeTopupModal" class="rounded-lg bg-blackx border border-blackx3 px-3 py-1 text-sm">Fechar</button>
      </div>
      <div class="rounded-lg bg-blackx border border-blackx3 px-3 py-2 text-sm">
        <div class="text-zinc-300">Valor: <strong id="topupAmount">R$ <?= number_format($topupValor, 2, ',', '.') ?></strong></div>
        <div class="text-zinc-300">Status: <strong id="topupStatusText"><?= htmlspecialchars($topupStatus, ENT_QUOTES, 'UTF-8') ?></strong></div>
      </div>
      <div id="topupApprovedBox" class="<?= $topupStatus === 'PAID' ? '' : 'hidden ' ?>rounded-lg bg-greenx/20 border border-greenx text-greenx px-3 py-2 text-sm">✅ Pagamento aprovado. Saldo creditado.</div>
      <div id="topupPendingBox" class="<?= ($topupStatus !== 'PAID' && $topupStatus !== 'CANCELED') ? '' : 'hidden ' ?>rounded-lg bg-blackx border border-blackx3 text-zinc-300 px-3 py-2 text-xs">Aguardando pagamento... atualização automática ativa.</div>
      <?php if ($qrCodeImage): ?>
        <div class="flex justify-center">
          <img src="<?= htmlspecialchars($qrCodeImage, ENT_QUOTES, 'UTF-8') ?>" alt="QR Code PIX" class="w-56 h-56 rounded-lg border border-blackx3 bg-white p-2">
        </div>
      <?php endif; ?>
      <?php if ($pixCode): ?>
        <div>
          <p class="text-xs text-zinc-400 mb-1">Copia e cola</p>
          <textarea readonly class="w-full h-24 rounded-lg bg-blackx border border-blackx3 px-3 py-2 text-xs"><?= htmlspecialchars($pixCode, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      <?php endif; ?>
      <input type="hidden" id="topupTxId" value="<?= (int)$topupTx['id'] ?>">
    </div>
  </div>
  <?php endif; ?>

  
</div>

<?php
include __DIR__ . '/../../views/partials/vendor_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';

?>
<script>
  (function () {
    const modal = document.getElementById('topupModal');
    const openBtn = document.getElementById('openTopupModal');
    const closeBtn = document.getElementById('closeTopupModal');
    const topupTxIdEl = document.getElementById('topupTxId');
    const topupStatusText = document.getElementById('topupStatusText');
    const topupApprovedBox = document.getElementById('topupApprovedBox');
    const topupPendingBox = document.getElementById('topupPendingBox');
    let pollTimer = null;

    if (modal && modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }

    function updateSaldoHeader(saldo) {
      const el = document.querySelector('h2.text-lg.font-semibold');
      if (!el || typeof saldo !== 'number') return;
      el.textContent = 'Saldo atual: ' + saldo.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function setPaidUI() {
      if (topupApprovedBox) topupApprovedBox.classList.remove('hidden');
      if (topupPendingBox) topupPendingBox.classList.add('hidden');
    }

    async function pollTopupStatus() {
      if (!topupTxIdEl) return;
      const txId = topupTxIdEl.value;
      if (!txId) return;

      try {
        const res = await fetch('<?= BASE_PATH ?>/api/wallet_topup_status.php?tx_id=' + encodeURIComponent(txId), { cache: 'no-store' });
        const data = await res.json();
        if (!data || !data.ok) return;

        const status = String(data.status || '').toUpperCase();
        if (topupStatusText) topupStatusText.textContent = status;
        if (typeof data.saldo === 'number') updateSaldoHeader(data.saldo);

        if (status === 'PAID') {
          setPaidUI();
          if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
          }
        }
        if (status === 'CANCELED' && topupPendingBox) {
          topupPendingBox.textContent = 'Cobrança cancelada.';
        }
      } catch (e) {}
    }

    function startPollingIfNeeded() {
      if (!modal || modal.classList.contains('hidden')) return;
      const currentStatus = (topupStatusText ? topupStatusText.textContent : '').toUpperCase();
      if (currentStatus === 'PAID' || currentStatus === 'CANCELED') return;
      pollTopupStatus();
      if (!pollTimer) {
        pollTimer = setInterval(pollTopupStatus, 5000);
      }
    }

    function stopPolling() {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    }

    if (openBtn && modal) {
      openBtn.addEventListener('click', function () {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        startPollingIfNeeded();
      });
    }
    if (closeBtn && modal) {
      closeBtn.addEventListener('click', function () {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        stopPolling();
      });
    }
    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) {
          modal.classList.add('hidden');
          modal.classList.remove('flex');
          stopPolling();
        }
      });
      startPollingIfNeeded();
    }

    function formatBRL(value) {
      const digits = String(value || '').replace(/\D/g, '');
      const num = (parseInt(digits || '0', 10) / 100);
      return num.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    document.querySelectorAll('.js-money').forEach(function (el) {
      if (!el.value) el.value = 'R$ 0,00';
      el.addEventListener('input', function () { el.value = formatBRL(el.value); });
      el.addEventListener('focus', function () {
        if (el.value.trim() === '') el.value = 'R$ 0,00';
      });
    });
  })();
</script>
