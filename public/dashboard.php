<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/media.php';
require_once __DIR__ . '/../src/storefront.php';
require_once __DIR__ . '/../src/seller_levels.php';

exigirUsuario();

$conn = (new Database())->connect();
$uid = (int)($_SESSION['user_id'] ?? 0);

function pickCol(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array(strtolower($c), $cols, true)) return $c;
    }
    return null;
}

$cols = [];
$rs = $conn->query("SHOW COLUMNS FROM users");
if ($rs) while ($r = $rs->fetch_assoc()) $cols[] = strtolower((string)$r['Field']);

$nameCol  = pickCol($cols, ['nome', 'name', 'username']);
$emailCol = pickCol($cols, ['email', 'mail']);
$photoCol = pickCol($cols, ['foto_perfil', 'foto', 'avatar', 'profile_photo']);

$sel = ['id'];
if ($nameCol)  $sel[] = "`{$nameCol}` AS nome";
if ($emailCol) $sel[] = "`{$emailCol}` AS email";
if ($photoCol) $sel[] = "`{$photoCol}` AS foto";

$st = $conn->prepare("SELECT " . implode(', ', $sel) . " FROM users WHERE id = ? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute();
$user = $st->get_result()->fetch_assoc() ?: [];
$st->close();

$foto = mediaResolveUrl(
    (string)($user['foto'] ?? ''),
    'https://placehold.co/120x120/111827/9ca3af?text=Foto'
);

$activeMenu = 'dashboard';
$pageTitle  = 'Dashboard do Usuário';

function tableExistsDash($conn, string $table): bool {
  $safe = $conn->real_escape_string($table);
  $rs = $conn->query("SHOW TABLES LIKE '{$safe}'");
  if (!$rs) return false;
  return $rs->fetch_assoc() !== null;
}

function countDash($conn, string $sql, string $types = '', array $params = []): int {
  $stmt = $conn->prepare($sql);
  if (!$stmt) return 0;
  if ($types !== '') {
    $bind = array_merge([$types], $params);
    $refs = [];
    foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
    call_user_func_array([$stmt, 'bind_param'], $refs);
  }
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();
  return (int)($row['qtd'] ?? 0);
}

$cards = [
  'pedidos_total' => 0,
  'pedidos_pagos' => 0,
  'pedidos_andamento' => 0,
];

if (tableExistsDash($conn, 'orders')) {
  $cards['pedidos_total'] = countDash($conn, "SELECT COUNT(*) AS qtd FROM orders WHERE user_id = ?", 'i', [$uid]);
  $cards['pedidos_pagos'] = countDash($conn, "SELECT COUNT(*) AS qtd FROM orders WHERE user_id = ? AND status IN ('pago','paid','entregue','delivered')", 'i', [$uid]);
  $cards['pedidos_andamento'] = countDash($conn, "SELECT COUNT(*) AS qtd FROM orders WHERE user_id = ? AND status IN ('pendente','pending','aguardando_pagamento','enviado','shipped','processing')", 'i', [$uid]);
}

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/user_layout_start.php';
?>

<div class="space-y-4">
  <!-- Hero + Stats combinados -->
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5 sm:p-6">
    <div class="flex items-center gap-4">
      <img src="<?= htmlspecialchars($foto, ENT_QUOTES, 'UTF-8') ?>"
           class="w-14 h-14 sm:w-16 sm:h-16 rounded-full object-cover border border-blackx3 shrink-0" alt="avatar">
      <div class="min-w-0 flex-1">
        <p class="text-base sm:text-lg font-semibold truncate"><?= htmlspecialchars((string)($user['nome'] ?? 'Usuário'), ENT_QUOTES, 'UTF-8') ?></p>
        <p class="text-zinc-400 text-xs sm:text-sm truncate"><?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    </div>

    <div class="mt-5 grid grid-cols-3 gap-2 sm:gap-3">
      <div class="rounded-xl border border-blackx3 bg-blackx/40 px-3 py-3 text-center">
        <p class="text-[10px] sm:text-xs uppercase tracking-wider text-zinc-500">Pedidos</p>
        <p class="text-xl sm:text-2xl font-bold mt-0.5"><?= $cards['pedidos_total'] ?></p>
      </div>
      <div class="rounded-xl border border-blackx3 bg-blackx/40 px-3 py-3 text-center">
        <p class="text-[10px] sm:text-xs uppercase tracking-wider text-zinc-500">Pagos</p>
        <p class="text-xl sm:text-2xl font-bold mt-0.5 text-greenx"><?= $cards['pedidos_pagos'] ?></p>
      </div>
      <div class="rounded-xl border border-blackx3 bg-blackx/40 px-3 py-3 text-center">
        <p class="text-[10px] sm:text-xs uppercase tracking-wider text-zinc-500">Andamento</p>
        <p class="text-xl sm:text-2xl font-bold mt-0.5 text-yellow-300"><?= $cards['pedidos_andamento'] ?></p>
      </div>
    </div>
  </div>

  <!-- Ações rápidas -->
  <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 sm:gap-3">
    <a href="<?= BASE_PATH ?>/meus_pedidos"
       class="group flex items-center gap-3 rounded-2xl border border-blackx3 bg-blackx2 hover:border-greenx/50 hover:bg-blackx2/80 transition px-4 py-3.5">
      <div class="w-9 h-9 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center shrink-0">
        <i data-lucide="package" class="w-4 h-4 text-greenx"></i>
      </div>
      <span class="text-sm font-medium">Meus pedidos</span>
    </a>
    <a href="<?= BASE_PATH ?>/wallet"
       class="group flex items-center gap-3 rounded-2xl border border-blackx3 bg-blackx2 hover:border-greenx/50 hover:bg-blackx2/80 transition px-4 py-3.5">
      <div class="w-9 h-9 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center shrink-0">
        <i data-lucide="wallet" class="w-4 h-4 text-greenx"></i>
      </div>
      <span class="text-sm font-medium">Carteira</span>
    </a>
    <a href="<?= BASE_PATH ?>/minha_conta"
       class="group flex items-center gap-3 rounded-2xl border border-blackx3 bg-blackx2 hover:border-greenx/50 hover:bg-blackx2/80 transition px-4 py-3.5 col-span-2 sm:col-span-1">
      <div class="w-9 h-9 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center shrink-0">
        <i data-lucide="user-cog" class="w-4 h-4 text-greenx"></i>
      </div>
      <span class="text-sm font-medium">Minha conta</span>
    </a>
  </div>

  <?php if ($cards['pedidos_total'] === 0): ?>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 text-center">
      <div class="w-12 h-12 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center mx-auto mb-3">
        <i data-lucide="shopping-bag" class="w-5 h-5 text-greenx"></i>
      </div>
      <p class="text-zinc-200 font-medium">Você ainda não possui pedidos.</p>
      <p class="text-zinc-500 text-sm mt-1">Sua primeira compra aparecerá aqui.</p>
      <a href="<?= BASE_PATH ?>/" class="inline-flex mt-4 rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white text-sm font-semibold px-4 py-2">Explorar produtos</a>
    </div>
  <?php endif; ?>
</div>

<?php
include __DIR__ . '/../views/partials/user_layout_end.php';
include __DIR__ . '/../views/partials/footer.php';