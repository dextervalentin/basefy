<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\minha_conta.php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/upload_paths.php';
require_once __DIR__ . '/../src/media.php';
require_once __DIR__ . '/../src/user_identity.php';

exigirLogin();

$conn = (new Database())->connect();
$uid = (int)($_SESSION['user_id'] ?? 0);

try { $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS telefone VARCHAR(40)"); } catch (\Throwable $e) {}

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
$passCol  = pickCol($cols, ['senha', 'senha_hash', 'password', 'password_hash']);
$photoCol = pickCol($cols, ['foto_perfil', 'foto', 'avatar', 'profile_photo']);
$phoneCol = pickCol($cols, ['telefone', 'phone']);
$docCol   = pickCol($cols, ['cpf', 'documento']);

$msg = '';
$err = '';

// Check if dados verification is approved (lock CPF + phone)
$_dadosVerificado = false;
try {
    $stVerif = $conn->prepare("SELECT status FROM user_verifications WHERE user_id = ? AND tipo = 'dados' LIMIT 1");
    if ($stVerif) {
        $stVerif->bind_param('i', $uid);
        $stVerif->execute();
        $verifRow = $stVerif->get_result()->fetch_assoc();
        $stVerif->close();
        $_dadosVerificado = in_array(strtolower((string)($verifRow['status'] ?? '')), ['verificado', 'aprovado'], true);
    }
} catch (\Throwable $e) {}

// Flash messages from redirects (e.g. verification gate)
if (!empty($_SESSION['flash_error'])) {
    $err = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
if (!empty($_SESSION['flash_msg'])) {
    $msg = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'perfil') {
        $nome = trim((string)($_POST['nome'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if (!$nameCol || !$emailCol) {
            $err = 'Colunas de nome/email não encontradas.';
        } elseif ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Dados inválidos.';
        } else {
            $set = ["`{$nameCol}` = ?", "`{$emailCol}` = ?"];
            $types = 'ss';
            $vals = [$nome, $email];

            // Save phone (only if dados not verified)
            if (!$_dadosVerificado) {
                $telefone = trim((string)($_POST['telefone'] ?? ''));
                if ($phoneCol && $telefone !== '') {
                    $set[] = "`{$phoneCol}` = ?";
                    $types .= 's';
                    $vals[] = $telefone;
                }
            }

            // Save document / CPF (only if dados not verified)
            if (!$_dadosVerificado) {
                $documento = identityDigits(trim((string)($_POST['documento'] ?? '')));
                if ($docCol && $documento !== '') {
                  if (strlen($documento) !== 11) {
                    $err = 'CPF inválido.';
                  } elseif (userCpfJaExiste($conn, $documento, $uid)) {
                        $err = 'Este CPF já está cadastrado em outra conta.';
                    } else {
                        $set[] = "`{$docCol}` = ?";
                        $types .= 's';
                        $vals[] = identityFormatCpf($documento);
                    }
                }
            }

            if ($photoCol && isset($_FILES['foto']) && (int)($_FILES['foto']['error'] ?? 4) === UPLOAD_ERR_OK) {
                try {
                    $mediaId = mediaSaveFromUpload($_FILES['foto'], 'avatar', $uid, true);
                    if ($mediaId) {
                        $dbPath = 'media:' . $mediaId;
                        $set[] = "`{$photoCol}` = ?";
                        $types .= 's';
                        $vals[] = $dbPath;
                    } else {
                        $err = 'Foto inválida (JPG/PNG/WEBP até 5MB).';
                    }
                } catch (\Throwable $e) {
                    error_log('[MinhaConta] Erro ao salvar foto: ' . $e->getMessage());
                    $err = 'Erro ao salvar foto. Tente novamente.';
                }
            }

            if ($err === '') {
                $sql = "UPDATE users SET " . implode(', ', $set) . " WHERE id = ? LIMIT 1";
                $types .= 'i';
                $vals[] = $uid;

                $up = $conn->prepare($sql);
                $up->bind_param($types, ...$vals);
                if ($up->execute()) {
                    $msg = 'Perfil atualizado com sucesso.';
                    // Sync session so header/sidebar reflect the new data immediately
                    $_SESSION['user']['nome']  = $nome;
                    $_SESSION['user']['email'] = $email;
                    if (isset($dbPath)) {
                        $_SESSION['user']['foto']   = $dbPath;
                        $_SESSION['user']['avatar'] = $dbPath;
                    }
                } else {
                    $err = 'Não foi possível atualizar.';
                }
                $up->close();
            }
        }
    }

    if ($action === 'senha') {
        $isGoogleUser = !empty($_SESSION['is_google']) || !empty($_SESSION['user']['is_google']);
        $atual = (string)($_POST['senha_atual'] ?? '');
        $nova  = (string)($_POST['senha_nova'] ?? '');
        $conf  = (string)($_POST['senha_confirma'] ?? '');

        // All fields empty = skip silently (Google user might submit accidentally)
        if ($atual === '' && $nova === '' && $conf === '') {
            // do nothing
        } elseif (!$passCol) {
            $err = 'Coluna de senha não encontrada.';
        } elseif ($nova === '' || strlen($nova) < 6 || $nova !== $conf) {
            $err = 'Confira os dados da nova senha.';
        } else {
            if ($isGoogleUser && $atual === '') {
                // Google user setting password for the first time
                $newHash = password_hash($nova, PASSWORD_DEFAULT);
                $up = $conn->prepare("UPDATE users SET `{$passCol}` = ? WHERE id = ? LIMIT 1");
                $up->bind_param('si', $newHash, $uid);
                if ($up->execute()) $msg = 'Senha definida com sucesso.';
                else $err = 'Não foi possível definir senha.';
                $up->close();
            } else {
                $st = $conn->prepare("SELECT `{$passCol}` AS senha FROM users WHERE id = ? LIMIT 1");
                $st->bind_param('i', $uid);
                $st->execute();
                $u = $st->get_result()->fetch_assoc() ?: [];
                $st->close();

                $hash = (string)($u['senha'] ?? '');
                $okAtual = password_verify($atual, $hash) || hash_equals($hash, $atual);

                if (!$okAtual) {
                    $err = 'Senha atual incorreta.';
                } else {
                    $newHash = password_hash($nova, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE users SET `{$passCol}` = ? WHERE id = ? LIMIT 1");
                    $up->bind_param('si', $newHash, $uid);
                    if ($up->execute()) $msg = 'Senha alterada com sucesso.';
                    else $err = 'Não foi possível alterar senha.';
                    $up->close();
                }
            }
        }
    }

    if ($action === 'enviar_verificacao_email') {
        require_once __DIR__ . '/../src/email.php';
        $result = enviarEmailVerificacao($conn, $uid);
        if ($result === true) {
            $msg = 'E-mail de verificação enviado! Verifique sua caixa de entrada e clique no link.';
        } else {
            $err = is_string($result) ? $result : 'Erro ao enviar e-mail de verificação.';
        }
    }
}

$sel = ['id'];
if ($nameCol)  $sel[] = "`{$nameCol}` AS nome";
if ($emailCol) $sel[] = "`{$emailCol}` AS email";
if ($photoCol) $sel[] = "`{$photoCol}` AS foto";
if ($phoneCol) $sel[] = "`{$phoneCol}` AS telefone";
if ($docCol)   $sel[] = "`{$docCol}` AS documento";

$st = $conn->prepare("SELECT " . implode(', ', $sel) . " FROM users WHERE id = ? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute();
$user = $st->get_result()->fetch_assoc() ?: [];
$st->close();

$fotoRaw = trim((string)($user['foto'] ?? ''));
$foto = mediaResolveUrl($fotoRaw, 'https://placehold.co/160x160/111827/9ca3af?text=Foto');

$activeMenu = 'conta';
$pageTitle  = 'Minha Conta';

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/user_layout_start.php';
?>

<?php
  // Check actual email verification status from user_verifications table
  $_emailVerificado = false;
  try {
      $stEv = $conn->prepare("SELECT status FROM user_verifications WHERE user_id = ? AND tipo = 'email' LIMIT 1");
      if ($stEv) {
          $stEv->bind_param('i', $uid);
          $stEv->execute();
          $evRow = $stEv->get_result()->fetch_assoc();
          $stEv->close();
          $_emailVerificado = in_array(strtolower((string)($evRow['status'] ?? '')), ['verificado', 'aprovado'], true);
      }
  } catch (\Throwable $e) {}

  // Verification checklist
  $_verif_fields = [
    'nome'      => ['label' => 'Nome completo', 'icon' => 'user',   'filled' => trim((string)($user['nome'] ?? '')) !== ''],
    'email'     => ['label' => 'E-mail',        'icon' => 'mail',   'filled' => $_emailVerificado],
    'telefone'  => ['label' => 'Telefone',      'icon' => 'phone',  'filled' => trim((string)($user['telefone'] ?? '')) !== ''],
    'documento' => ['label' => 'CPF',           'icon' => 'file-text','filled' => trim((string)($user['documento'] ?? '')) !== ''],
    'foto'      => ['label' => 'Foto de perfil','icon' => 'camera', 'filled' => $fotoRaw !== ''],
  ];
  $_verif_done  = count(array_filter(array_column($_verif_fields, 'filled')));
  $_verif_total = count($_verif_fields);
  $_verif_pct   = (int) round(($_verif_done / $_verif_total) * 100);
  $_isGoogleUser = !empty($_SESSION['is_google']) || !empty($_SESSION['user']['is_google']);
  $_telefonePendente = trim((string)($user['telefone'] ?? '')) === '';
?>

<div class="space-y-6">

  <!-- ── Flash Messages ── -->
  <?php if ($msg): ?>
    <div class="rounded-2xl border border-greenx/30 bg-greenx/[0.08] px-5 py-3.5 text-sm text-greenx flex items-center gap-3">
      <i data-lucide="check-circle-2" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="rounded-2xl border border-red-500/30 bg-red-600/[0.08] px-5 py-3.5 text-sm text-red-300 flex items-center gap-3">
      <i data-lucide="alert-triangle" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>

  <?php if ($_telefonePendente): ?>
    <div class="rounded-2xl border border-red-500/35 bg-red-500/[0.08] px-5 py-4 text-sm text-red-200 flex items-start gap-3">
      <i data-lucide="message-circle" class="w-5 h-5 flex-shrink-0 text-red-300 mt-0.5"></i>
      <div>
        <p class="font-semibold text-red-100">Complete seu cadastro com WhatsApp</p>
        <p class="text-red-200/80 mt-1">O número com DDD é obrigatório para avisos de compra, venda, suporte e segurança da conta.</p>
      </div>
    </div>
  <?php endif; ?>

  <!-- ── Verification Progress Card ── -->
  <?php if ($_verif_pct < 100): ?>
  <div class="relative overflow-hidden rounded-2xl border border-white/[0.08] bg-blackx2 p-5">
    <div class="absolute -top-12 -right-12 w-40 h-40 rounded-full bg-greenx/[0.07] blur-[60px] pointer-events-none"></div>
    <div class="flex items-start gap-4 relative">
      <div class="w-10 h-10 rounded-xl bg-white/[0.04] border border-white/[0.08] flex items-center justify-center flex-shrink-0 mt-0.5">
        <i data-lucide="shield" class="w-4.5 h-4.5 text-zinc-400"></i>
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between mb-0.5">
          <h3 class="font-semibold text-white text-sm">Verificação da conta</h3>
          <span class="text-xs font-bold text-zinc-400"><?= $_verif_pct ?>%</span>
        </div>
        <p class="text-xs text-zinc-500 mb-3">Complete seu perfil para habilitar saques e recursos premium.</p>
        <div class="w-full bg-white/[0.05] rounded-full h-1.5 mb-4 overflow-hidden">
          <div class="h-1.5 rounded-full transition-all duration-700"
               style="width:<?= $_verif_pct ?>%; background: linear-gradient(90deg, var(--t-accent, #8800E4), var(--t-accent-hover, #7200C0))"></div>
        </div>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($_verif_fields as $_vk => $_vf): ?>
          <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[11px] font-medium
            <?= $_vk === 'telefone' && !$_vf['filled']
              ? 'bg-red-500/[0.08] border border-red-500/30 text-red-300'
              : ($_vf['filled']
              ? 'bg-white/[0.06] border border-white/[0.10] text-zinc-300'
              : 'bg-white/[0.02] border border-white/[0.05] text-zinc-600') ?>">
            <?php if ($_vf['filled']): ?>
            <i data-lucide="check" class="w-3 h-3 text-greenx"></i>
            <?php else: ?>
            <i data-lucide="<?= $_vf['icon'] ?>" class="w-3 h-3"></i>
            <?php endif; ?>
            <?= $_vf['label'] ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php if (!$_emailVerificado && trim((string)($user['email'] ?? '')) !== ''): ?>
        <form method="post" class="mt-4">
          <input type="hidden" name="action" value="enviar_verificacao_email">
          <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-white/[0.06] border border-white/[0.10] px-3 py-1.5 text-xs font-semibold text-zinc-300 hover:bg-white/[0.10] hover:text-white transition-all">
            <i data-lucide="mail" class="w-3.5 h-3.5"></i>
            Enviar e-mail de verificação
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="rounded-2xl border border-white/[0.08] bg-blackx2 p-4 flex items-center gap-3">
    <div class="w-9 h-9 rounded-xl bg-white/[0.04] border border-white/[0.08] flex items-center justify-center">
      <i data-lucide="shield-check" class="w-4.5 h-4.5 text-greenx"></i>
    </div>
    <div>
      <p class="text-sm font-semibold text-white">Conta verificada</p>
      <p class="text-xs text-zinc-500">Todas as funcionalidades estão habilitadas.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── PROFILE FORM ── -->
  <form method="post" enctype="multipart/form-data" class="space-y-6">
    <input type="hidden" name="action" value="perfil">

    <div class="grid lg:grid-cols-3 gap-6">

      <!-- Avatar Column -->
      <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 flex flex-col items-center"
           x-data="avatarUpload('<?= htmlspecialchars($foto, ENT_QUOTES, 'UTF-8') ?>')"
           @dragover.prevent="dragging = true"
           @dragleave.prevent="dragging = false"
           @drop.prevent="handleDrop($event)">

        <div class="relative group cursor-pointer mb-4" @click="$refs.fotoInput.click()">
          <div class="w-36 h-36 rounded-full p-[3px] bg-gradient-to-br from-greenx/60 to-greenx/20">
            <img :src="preview" alt="Avatar"
                 class="w-full h-full rounded-full object-cover border-2 border-blackx2 transition-all"
                 :class="dragging ? 'scale-105 border-greenx' : ''">
          </div>
          <div class="absolute inset-0 rounded-full bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
            <div class="text-center">
              <i data-lucide="camera" class="w-7 h-7 text-white mx-auto mb-1"></i>
              <span class="text-[11px] text-white font-medium">Alterar foto</span>
            </div>
          </div>
          <div class="absolute -bottom-1 -right-1 w-8 h-8 rounded-full bg-greenx flex items-center justify-center border-2 border-blackx2">
            <i data-lucide="pencil" class="w-3.5 h-3.5 text-black"></i>
          </div>
        </div>
        <p class="text-sm font-semibold text-center"><?= htmlspecialchars((string)($user['nome'] ?? 'Usuário'), ENT_QUOTES, 'UTF-8') ?></p>
        <p class="text-xs text-zinc-500 text-center mt-0.5 mb-3"><?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
        <p class="text-[11px] text-zinc-600 text-center">Arraste ou clique para alterar • JPG, PNG, WEBP (até 5 MB)</p>
        <input type="file" name="foto" x-ref="fotoInput" accept="image/*"
               class="hidden" @change="handleFile($event)">
      </div>

      <!-- Data Columns -->
      <div class="lg:col-span-2 space-y-5">
        <!-- Personal Info Section -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 space-y-5">
          <div class="flex items-center gap-2.5 pb-3 border-b border-blackx3">
            <div class="w-8 h-8 rounded-lg bg-greenx/10 border border-greenx/20 flex items-center justify-center">
              <i data-lucide="user" class="w-4 h-4 text-greenx"></i>
            </div>
            <h2 class="text-sm font-semibold">Dados pessoais</h2>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">Nome completo</label>
              <div class="relative">
                <i data-lucide="user" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
                <input name="nome" value="<?= htmlspecialchars((string)($user['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required placeholder="Seu nome"
                       class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-greenx/60 focus:ring-1 focus:ring-greenx/20 transition">
              </div>
            </div>
            <div>
              <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">E-mail</label>
              <div class="relative">
                <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
                <input type="email" name="email" value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required placeholder="seu@email.com"
                       class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-greenx/60 focus:ring-1 focus:ring-greenx/20 transition">
              </div>
            </div>
            <div>
              <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">Telefone</label>
              <div class="relative">
                <i data-lucide="phone" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
                  <input name="telefone" id="inputTelefone" value="<?= htmlspecialchars((string)($user['telefone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="(00) 00000-0000" maxlength="15" <?= !$_dadosVerificado ? 'required' : '' ?>
                       <?= $_dadosVerificado ? 'readonly disabled' : '' ?>
                       class="w-full bg-blackx border rounded-xl pl-10 pr-3 py-3 text-sm outline-none transition <?= $_dadosVerificado ? 'border-blackx3 opacity-60 cursor-not-allowed' : ($_telefonePendente ? 'border-red-500/60 bg-red-500/[0.06] focus:border-red-400/80 focus:ring-1 focus:ring-red-500/20' : 'border-blackx3 focus:border-greenx/60 focus:ring-1 focus:ring-greenx/20') ?>">
              </div>
                <?php if ($_telefonePendente && !$_dadosVerificado): ?>
                <p class="text-[10px] text-red-300 mt-1 flex items-center gap-1"><i data-lucide="alert-circle" class="w-3 h-3"></i> WhatsApp obrigatório para completar o cadastro</p>
                <?php endif; ?>
              <?php if ($_dadosVerificado): ?>
              <p class="text-[10px] text-greenx mt-1 flex items-center gap-1"><i data-lucide="lock" class="w-3 h-3"></i> Verificado — não pode ser alterado</p>
              <?php endif; ?>
            </div>
            <div>
              <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">CPF</label>
              <div class="relative">
                <i data-lucide="file-text" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
                <input name="documento" id="inputCPF" value="<?= htmlspecialchars((string)($user['documento'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="000.000.000-00" maxlength="14"
                       <?= $_dadosVerificado ? 'readonly disabled' : '' ?>
                       class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none transition <?= $_dadosVerificado ? 'opacity-60 cursor-not-allowed' : 'focus:border-greenx/60 focus:ring-1 focus:ring-greenx/20' ?>">
              </div>
              <?php if ($_dadosVerificado): ?>
              <p class="text-[10px] text-greenx mt-1 flex items-center gap-1"><i data-lucide="lock" class="w-3 h-3"></i> Verificado — não pode ser alterado</p>
              <?php endif; ?>
            </div>
          </div>

          <button class="w-full sm:w-auto bg-greenx hover:bg-greenx/90 text-white font-semibold rounded-xl px-6 py-3 text-sm transition flex items-center justify-center gap-2">
            <i data-lucide="save" class="w-4 h-4"></i>
            Salvar perfil
          </button>
        </div>

        <!-- Security Section -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 space-y-5">
          <div class="flex items-center gap-2.5 pb-3 border-b border-blackx3">
            <div class="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
              <i data-lucide="lock" class="w-4 h-4 text-purple-400"></i>
            </div>
            <h2 class="text-sm font-semibold"><?= $_isGoogleUser ? 'Definir Senha' : 'Segurança' ?></h2>
          </div>

          <?php if ($_isGoogleUser): ?>
          <div class="rounded-xl border border-greenx/20 bg-greenx/[0.06] px-4 py-3 text-sm text-purple-300 flex items-center gap-2.5">
            <i data-lucide="info" class="w-4 h-4 flex-shrink-0"></i>
            Você entrou com o Google. A senha é opcional.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </form>

  <!-- Security form (separate so it doesn't conflict with profile form) -->
  <form method="post" class="bg-blackx2 border border-blackx3 rounded-2xl p-6 space-y-5">
    <input type="hidden" name="action" value="senha">
    <div class="flex items-center gap-2.5 pb-3 border-b border-blackx3">
      <div class="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
        <i data-lucide="key-round" class="w-4 h-4 text-purple-400"></i>
      </div>
      <h2 class="text-sm font-semibold"><?= $_isGoogleUser ? 'Definir Senha' : 'Alterar Senha' ?></h2>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <?php if (!$_isGoogleUser): ?>
      <div>
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">Senha atual</label>
        <div class="relative">
          <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
          <input type="password" name="senha_atual" placeholder="••••••••"
                 class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/20 transition">
        </div>
      </div>
      <?php endif; ?>
      <div>
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5"><?= $_isGoogleUser ? 'Criar senha' : 'Nova senha' ?></label>
        <div class="relative">
          <i data-lucide="key-round" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
          <input type="password" name="senha_nova" placeholder="<?= $_isGoogleUser ? 'Criar senha (opcional)' : 'Mín. 6 caracteres' ?>"
                 class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/20 transition">
        </div>
      </div>
      <div>
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">Confirmar</label>
        <div class="relative">
          <i data-lucide="check-circle-2" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
          <input type="password" name="senha_confirma" placeholder="Repetir nova senha"
                 class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/20 transition">
        </div>
      </div>
    </div>

    <button class="w-full sm:w-auto bg-purple-600 hover:bg-purple-500 text-white font-semibold rounded-xl px-6 py-3 text-sm transition flex items-center justify-center gap-2">
      <i data-lucide="shield-check" class="w-4 h-4"></i>
      <?= $_isGoogleUser ? 'Definir senha' : 'Alterar senha' ?>
    </button>
  </form>

  <!-- ── Quick Links ── -->
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h3 class="text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-3">Ações rápidas</h3>
    <div class="flex flex-wrap gap-2">
      <a href="<?= BASE_PATH ?>/wallet" class="rounded-xl border border-blackx3 bg-blackx hover:border-greenx/30 px-3 py-2 text-sm text-zinc-400 hover:text-white transition flex items-center gap-1.5">
        <i data-lucide="wallet" class="w-3.5 h-3.5"></i> Carteira
      </a>
      <a href="<?= BASE_PATH ?>/saques" class="rounded-xl border border-blackx3 bg-blackx hover:border-greenx/30 px-3 py-2 text-sm text-zinc-400 hover:text-white transition flex items-center gap-1.5">
        <i data-lucide="banknote" class="w-3.5 h-3.5"></i> Saques
      </a>
      <a href="<?= BASE_PATH ?>/meus_pedidos" class="rounded-xl border border-blackx3 bg-blackx hover:border-greenx/30 px-3 py-2 text-sm text-zinc-400 hover:text-white transition flex items-center gap-1.5">
        <i data-lucide="package" class="w-3.5 h-3.5"></i> Pedidos
      </a>
      <a href="<?= BASE_PATH ?>/dashboard" class="rounded-xl border border-blackx3 bg-blackx hover:border-greenx/30 px-3 py-2 text-sm text-zinc-400 hover:text-white transition flex items-center gap-1.5">
        <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i> Dashboard
      </a>
    </div>
  </div>
</div>

<script>
function avatarUpload(existingUrl) {
    return {
        preview: existingUrl || '',
        dragging: false,
        handleFile(event) {
            const file = event.target.files?.[0];
            if (!file) return;
            this.processFile(file);
        },
        handleDrop(event) {
            this.dragging = false;
            const file = event.dataTransfer?.files?.[0];
            if (!file || !file.type.startsWith('image/')) return;
            const dt = new DataTransfer();
            dt.items.add(file);
            this.$refs.fotoInput.files = dt.files;
            this.processFile(file);
        },
        processFile(file) {
            if (file.size > 5 * 1024 * 1024) {
                alert('Arquivo muito grande. Máximo 5MB.');
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => { this.preview = e.target.result; };
            reader.readAsDataURL(file);
        }
    };
}

/* ── Input masks ── */
(function() {
  var tel = document.getElementById('inputTelefone');
  if (tel) {
    tel.addEventListener('input', function() {
      var d = tel.value.replace(/\D/g, '').slice(0, 11);
      if (d.length > 6) tel.value = '(' + d.slice(0,2) + ') ' + d.slice(2,7) + '-' + d.slice(7);
      else if (d.length > 2) tel.value = '(' + d.slice(0,2) + ') ' + d.slice(2);
      else if (d.length > 0) tel.value = '(' + d;
    });
  }
  var cpf = document.getElementById('inputCPF');
  if (cpf) {
    cpf.addEventListener('input', function() {
      var d = cpf.value.replace(/\D/g, '').slice(0, 11);
      if (d.length > 9) cpf.value = d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6,9) + '-' + d.slice(9);
      else if (d.length > 6) cpf.value = d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6);
      else if (d.length > 3) cpf.value = d.slice(0,3) + '.' + d.slice(3);
    });
  }
})();
</script>

<?php
include __DIR__ . '/../views/partials/user_layout_end.php';
include __DIR__ . '/../views/partials/footer.php';