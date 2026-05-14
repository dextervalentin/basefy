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

$sellerLevelInfo = [
  'level' => 1,
  'label' => 'Nível 1',
  'fee_percent' => 0.0,
  'total_fee_percent' => 0.0,
  'revenue' => 0.0,
  'next_threshold' => null,
  'is_custom' => false,
];
$sellerLevelCfg = [
  'enabled' => true,
  'nivel1_percent' => 14.99,
  'nivel2_percent' => 12.99,
  'nivel2_threshold' => 20000.00,
  'nivel3_percent' => 9.99,
  'nivel3_threshold' => 40000.00,
  'lead_fee_percent' => 4.99,
];
$sellerLevelProgress = 0.0;
$sellerLevelRemaining = null;
$sellerLevelNextLabel = '';

try {
  $sellerLevelCfg = sellerLevelsConfig($conn);
  $sellerLevelInfo = sellerLevelCalc($conn, $uid);
  $sellerRevenue = (float)($sellerLevelInfo['revenue'] ?? 0.0);

  if (!empty($sellerLevelInfo['is_custom'])) {
    $sellerLevelProgress = 100.0;
  } else {
    $level = (int)($sellerLevelInfo['level'] ?? 1);
    if ($level <= 1) {
      $stageStart = 0.0;
      $nextThreshold = (float)$sellerLevelCfg['nivel2_threshold'];
      $sellerLevelNextLabel = 'Nível 2';
    } elseif ($level === 2) {
      $stageStart = (float)$sellerLevelCfg['nivel2_threshold'];
      $nextThreshold = (float)$sellerLevelCfg['nivel3_threshold'];
      $sellerLevelNextLabel = 'Nível 3';
    } else {
      $stageStart = (float)$sellerLevelCfg['nivel3_threshold'];
      $nextThreshold = null;
    }

    if ($nextThreshold !== null && $nextThreshold > $stageStart) {
      $sellerLevelProgress = max(0.0, min(100.0, (($sellerRevenue - $stageStart) / ($nextThreshold - $stageStart)) * 100));
      $sellerLevelRemaining = max(0.0, $nextThreshold - $sellerRevenue);
    } else {
      $sellerLevelProgress = 100.0;
    }
  }
} catch (\Throwable $e) {
  $sellerLevelProgress = 0.0;
}

// Só mostra o card de níveis para quem é vendedor (tem produto cadastrado ou alguma venda).
// Mantém o painel limpo para o comprador "puro".
$isSeller = false;
try {
  if ((float)($sellerLevelInfo['revenue'] ?? 0) > 0) {
    $isSeller = true;
  } else {
    $stChk = $conn->prepare('SELECT 1 FROM produtos WHERE vendedor_id = ? LIMIT 1');
    $stChk->bind_param('i', $uid);
    $stChk->execute();
    $isSeller = (bool)$stChk->get_result()->fetch_assoc();
    $stChk->close();
  }
} catch (\Throwable $e) {
  $isSeller = false;
}

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/user_layout_start.php';
?>

<div class="space-y-4">
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6">
    <div class="flex items-center gap-4">
      <img src="<?= htmlspecialchars($foto, ENT_QUOTES, 'UTF-8') ?>" class="w-16 h-16 rounded-full object-cover border border-blackx3" alt="avatar">
      <div>
        <p class="text-lg font-semibold"><?= htmlspecialchars((string)($user['nome'] ?? 'Usuário'), ENT_QUOTES, 'UTF-8') ?></p>
        <p class="text-zinc-400 text-sm"><?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4">
      <p class="text-zinc-400 text-sm">Pedidos totais</p>
      <p class="text-2xl font-bold mt-1"><?= $cards['pedidos_total'] ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4">
      <p class="text-zinc-400 text-sm">Pedidos pagos</p>
      <p class="text-2xl font-bold mt-1"><?= $cards['pedidos_pagos'] ?></p>
    </div>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4">
      <p class="text-zinc-400 text-sm">Em andamento</p>
      <p class="text-2xl font-bold mt-1"><?= $cards['pedidos_andamento'] ?></p>
    </div>
  </div>

  <?php if ($isSeller): ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4 sm:p-5 overflow-hidden">
    <div class="flex flex-col lg:flex-row lg:items-center gap-5">
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center shrink-0">
            <i data-lucide="trophy" class="w-5 h-5 text-greenx"></i>
          </div>
          <div class="min-w-0">
            <p class="text-xs uppercase tracking-wider text-zinc-500 font-semibold">Níveis de taxa</p>
            <h3 class="text-lg font-semibold truncate"><?= htmlspecialchars((string)($sellerLevelInfo['label'] ?? 'Nível 1'), ENT_QUOTES, 'UTF-8') ?></h3>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="rounded-xl border border-white/[0.06] bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">Sua taxa atual</p>
            <p class="text-xl font-bold text-greenx mt-1"><?= number_format((float)($sellerLevelInfo['total_fee_percent'] ?? 0), 2, ',', '.') ?>%</p>
          </div>
          <div class="rounded-xl border border-white/[0.06] bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">Vendas aprovadas</p>
            <p class="text-xl font-bold mt-1">R$ <?= number_format((float)($sellerLevelInfo['revenue'] ?? 0), 2, ',', '.') ?></p>
          </div>
          <div class="rounded-xl border border-white/[0.06] bg-white/[0.03] p-3">
            <p class="text-xs text-zinc-500">Próximo marco</p>
            <?php if ($sellerLevelRemaining !== null): ?>
              <p class="text-xl font-bold mt-1">R$ <?= number_format($sellerLevelRemaining, 2, ',', '.') ?></p>
              <p class="text-[11px] text-zinc-500 mt-0.5">para chegar ao <?= htmlspecialchars($sellerLevelNextLabel, ENT_QUOTES, 'UTF-8') ?></p>
            <?php elseif (!empty($sellerLevelInfo['is_custom'])): ?>
              <p class="text-xl font-bold mt-1 text-fuchsia-300">Personalizada</p>
              <p class="text-[11px] text-zinc-500 mt-0.5">definida pela equipe</p>
            <?php else: ?>
              <p class="text-xl font-bold mt-1 text-yellow-300">Topo</p>
              <p class="text-[11px] text-zinc-500 mt-0.5">melhor nível ativo</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="mt-4">
          <div class="flex items-center justify-between text-xs text-zinc-500 mb-2">
            <span>Progresso do nível</span>
            <span><?= number_format($sellerLevelProgress, 0, ',', '.') ?>%</span>
          </div>
          <div class="h-2 rounded-full bg-white/[0.06] overflow-hidden">
            <div class="h-full rounded-full bg-gradient-to-r from-greenx to-greenx2" style="width:<?= number_format($sellerLevelProgress, 2, '.', '') ?>%"></div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-2 lg:w-[360px] shrink-0">
        <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-center <?= (int)($sellerLevelInfo['level'] ?? 1) === 1 && empty($sellerLevelInfo['is_custom']) ? 'ring-1 ring-greenx/40' : '' ?>">
          <p class="text-[11px] text-zinc-500 font-semibold">Nível 1</p>
          <p class="text-sm font-bold mt-1"><?= number_format((float)$sellerLevelCfg['nivel1_percent'], 2, ',', '.') ?>%</p>
          <p class="text-[10px] text-zinc-600 mt-1">inicial</p>
        </div>
        <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-center <?= (int)($sellerLevelInfo['level'] ?? 1) === 2 && empty($sellerLevelInfo['is_custom']) ? 'ring-1 ring-greenx/40' : '' ?>">
          <p class="text-[11px] text-zinc-500 font-semibold">Nível 2</p>
          <p class="text-sm font-bold mt-1"><?= number_format((float)$sellerLevelCfg['nivel2_percent'], 2, ',', '.') ?>%</p>
          <p class="text-[10px] text-zinc-600 mt-1">R$ <?= number_format((float)$sellerLevelCfg['nivel2_threshold'], 0, ',', '.') ?></p>
        </div>
        <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-center <?= (int)($sellerLevelInfo['level'] ?? 1) === 3 && empty($sellerLevelInfo['is_custom']) ? 'ring-1 ring-greenx/40' : '' ?>">
          <p class="text-[11px] text-zinc-500 font-semibold">Nível 3</p>
          <p class="text-sm font-bold mt-1"><?= number_format((float)$sellerLevelCfg['nivel3_percent'], 2, ',', '.') ?>%</p>
          <p class="text-[10px] text-zinc-600 mt-1">R$ <?= number_format((float)$sellerLevelCfg['nivel3_threshold'], 0, ',', '.') ?></p>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4">
    <h3 class="font-semibold mb-3">Ações rápidas</h3>
    <div class="flex flex-wrap gap-2">
      <a href="<?= BASE_PATH ?>/meus_pedidos" class="rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx transition">Ver meus pedidos</a>
      <a href="<?= BASE_PATH ?>/wallet" class="rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx transition">Abrir carteira</a>
      <a href="<?= BASE_PATH ?>/minha-conta" class="rounded-xl border border-blackx3 px-3 py-2 text-sm hover:border-greenx transition">Atualizar conta</a>
    </div>
  </div>

  <?php if ($cards['pedidos_total'] === 0): ?>
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 text-center">
      <p class="text-zinc-300 font-medium">Você ainda não possui pedidos.</p>
      <p class="text-zinc-500 text-sm mt-1">Quando realizar sua primeira compra, o resumo aparecerá aqui.</p>
    </div>
  <?php endif; ?>

  <?php
    $dashCats = array_values(array_filter(sfListCategories($conn), fn($c) => ($c['tipo'] ?? 'produto') !== 'blog'));
    if ($dashCats):
  ?>
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-semibold">Categorias em Destaque</h3>
      <a href="<?= BASE_PATH ?>/categorias" class="text-xs text-greenx hover:underline">Ver todas</a>
    </div>
    <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2">
      <?php
        $dIcons = ['package','monitor','gamepad-2','shirt','book-open','music','camera','cpu'];
        foreach ($dashCats as $di => $dc):
          $dcImg = trim((string)($dc['imagem'] ?? ''));
          $dcUrl = $dcImg !== '' ? sfImageUrl($dcImg) : '';
      ?>
      <a href="<?= sfCategoryUrl($dc) ?>"
         class="group relative overflow-hidden rounded-xl border border-white/[0.06] <?= $dcUrl ? 'bg-blackx' : 'bg-white/[0.03]' ?> hover:border-greenx/30 transition-all">
        <?php if ($dcUrl): ?>
        <img src="<?= htmlspecialchars($dcUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="absolute inset-0 w-full h-full object-cover opacity-30 group-hover:opacity-50 group-hover:scale-110 transition-all duration-500">
        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>
        <?php endif; ?>
        <div class="aspect-square flex flex-col items-center justify-center p-2 text-center relative z-10">
          <?php if (!$dcUrl): ?>
          <div class="w-8 h-8 rounded-lg bg-white/[0.06] border border-white/[0.08] flex items-center justify-center mb-1 group-hover:scale-110 transition-transform">
            <i data-lucide="<?= $dIcons[$di % count($dIcons)] ?>" class="w-4 h-4 text-greenx"></i>
          </div>
          <?php endif; ?>
          <p class="text-[10px] sm:text-xs font-medium <?= $dcUrl ? 'text-white drop-shadow-lg mt-auto' : 'text-zinc-300' ?> group-hover:text-white transition-colors line-clamp-2 leading-tight"><?= htmlspecialchars((string)$dc['nome'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php
include __DIR__ . '/../views/partials/user_layout_end.php';
include __DIR__ . '/../views/partials/footer.php';