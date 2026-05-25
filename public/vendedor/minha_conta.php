<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\minha_conta.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/src/auth.php';
require_once $ROOT . '/src/db.php';
require_once $ROOT . '/src/upload_paths.php';
require_once $ROOT . '/src/media.php';
require_once $ROOT . '/src/chat.php';
require_once $ROOT . '/src/user_identity.php';

if (function_exists('exigirVendedor')) {
    exigirVendedor();
} else {
    exigirUsuario();
}

$conn = (new Database())->connect();
$uid  = (int)($_SESSION['user_id'] ?? 0);

$pageTitle  = 'Minha Conta';
$activeMenu = 'conta';

function mc_pick_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array(strtolower($c), $cols, true)) return $c;
    }
    return null;
}

// descobrir colunas reais
$cols = [];
$rs = $conn->query("SHOW COLUMNS FROM users");
if ($rs) {
    while ($r = $rs->fetch_assoc()) {
        $cols[] = strtolower((string)$r['Field']);
    }
}
$nameCol  = mc_pick_col($cols, ['nome', 'name', 'username']);
$emailCol = mc_pick_col($cols, ['email', 'mail']);
$fotoCol  = mc_pick_col($cols, ['foto_perfil', 'foto', 'avatar', 'profile_photo']);
$passCol  = mc_pick_col($cols, ['senha', 'password', 'password_hash']);
$phoneCol = mc_pick_col($cols, ['telefone', 'phone']);
$docCol   = mc_pick_col($cols, ['cpf', 'documento']);

// carregar usuário
$select = ['id'];
if ($nameCol)  $select[] = "`{$nameCol}` AS nome";
if ($emailCol) $select[] = "`{$emailCol}` AS email";
if ($fotoCol)  $select[] = "`{$fotoCol}` AS foto";
if ($phoneCol) $select[] = "`{$phoneCol}` AS telefone";
if ($docCol)   $select[] = "`{$docCol}` AS documento";

$sql = "SELECT " . implode(', ', $select) . " FROM users WHERE id = ? LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param('i', $uid);
$st->execute();
$user = $st->get_result()->fetch_assoc() ?: [];
$st->close();

$nome  = (string)($user['nome'] ?? $_SESSION['user']['nome'] ?? 'Vendedor');
$email = (string)($user['email'] ?? $_SESSION['user']['email'] ?? '');
$foto  = (string)($user['foto'] ?? $_SESSION['user']['foto'] ?? $_SESSION['user']['avatar'] ?? '');

$errors = [];
$okMsg = '';

// salvar perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile') {
    $novoNome  = trim((string)($_POST['nome'] ?? ''));
    $novoEmail = trim((string)($_POST['email'] ?? ''));

    $novoTelefone = trim((string)($_POST['telefone'] ?? ''));
    $novoDocumento = identityDigits(trim((string)($_POST['documento'] ?? '')));

    if ($novoNome === '') $errors[] = 'Informe o nome.';
    if ($novoEmail === '' || !filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Informe um e-mail válido.';

    $novoFotoRel = null;
    if (isset($_FILES['foto']) && (int)$_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int)$_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Falha no upload da imagem.';
        } else {
            $tmp  = (string)$_FILES['foto']['tmp_name'];
            $size = (int)$_FILES['foto']['size'];
            $info = @getimagesize($tmp);
            $mime = $info['mime'] ?? '';

            if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $errors[] = 'Imagem inválida (use JPG, PNG ou WEBP).';
            } elseif ($size > 4 * 1024 * 1024) {
                $errors[] = 'Imagem maior que 4MB.';
            } else {
                // Save avatar to database (survives deploys)
                try {
                    $mediaId = mediaSaveFromUpload($_FILES['foto'], 'avatar', (int)$uid, true);
                    if ($mediaId) {
                        $novoFotoRel = 'media:' . $mediaId;
                    } else {
                        $errors[] = 'Falha ao salvar a imagem.';
                    }
                } catch (\Throwable $e) {
                    error_log('[VendedorMinhaConta] Erro ao salvar foto: ' . $e->getMessage());
                    $errors[] = 'Erro ao salvar foto. Tente novamente.';
                }
            }
        }
    }

    if (!$errors) {
        $sets = [];
        $types = '';
        $vals = [];

        if ($nameCol)  { $sets[] = "`{$nameCol}` = ?";  $types .= 's'; $vals[] = $novoNome; }
        if ($emailCol) { $sets[] = "`{$emailCol}` = ?"; $types .= 's'; $vals[] = $novoEmail; }
        if ($fotoCol && $novoFotoRel !== null) { $sets[] = "`{$fotoCol}` = ?"; $types .= 's'; $vals[] = $novoFotoRel; }
        if ($phoneCol && $novoTelefone !== '') { $sets[] = "`{$phoneCol}` = ?"; $types .= 's'; $vals[] = $novoTelefone; }
        if ($docCol && $novoDocumento !== '') {
          if (strlen($novoDocumento) !== 11) {
            $errors[] = 'CPF inválido.';
          } elseif (userCpfJaExiste($conn, $novoDocumento, $uid)) {
                $errors[] = 'Este CPF já está cadastrado em outra conta.';
            } else {
                $sets[] = "`{$docCol}` = ?"; $types .= 's'; $vals[] = identityFormatCpf($novoDocumento);
            }
        }

        if (!$errors && $sets) {
            $sqlUp = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?";
            $types .= 'i';
            $vals[] = $uid;

            $up = $conn->prepare($sqlUp);
            $bind = array_merge([$types], $vals);
            $refs = [];
            foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
            call_user_func_array([$up, 'bind_param'], $refs);
            $up->execute();
            $up->close();
        }

        $nome = $novoNome;
        $email = $novoEmail;
        if ($novoFotoRel !== null) $foto = $novoFotoRel;

        $_SESSION['user']['nome']   = $nome;
        $_SESSION['user']['email']  = $email;
        $_SESSION['user']['foto']   = $foto;
        $_SESSION['user']['avatar'] = $foto;

        $okMsg = 'Perfil atualizado com sucesso.';
    }
}

// toggle chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_chat') {
    $chatOn = isset($_POST['chat_enabled']) && $_POST['chat_enabled'] === '1';
    chatToggleVendor($conn, $uid, $chatOn);
    $okMsg = 'Configuração de chat atualizada.';
}

// alterar senha
$isGoogleUser = !empty($_SESSION['is_google']) || !empty($_SESSION['user']['is_google']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $atual = (string)($_POST['senha_atual'] ?? '');
    $nova  = (string)($_POST['nova_senha'] ?? '');
    $conf  = (string)($_POST['confirmar_senha'] ?? '');

    // Se todos os campos estão vazios, não fazer nada (evita erro para Google users)
    if ($atual === '' && $nova === '' && $conf === '') {
        // skip silently
    } elseif (!$passCol) {
        $errors[] = 'Coluna de senha não encontrada na tabela users.';
    } else {
        if ($nova === '' || $conf === '') $errors[] = 'Preencha a nova senha e confirmação.';
        elseif (strlen($nova) < 6) $errors[] = 'A nova senha deve ter ao menos 6 caracteres.';
        elseif ($nova !== $conf) $errors[] = 'Confirmação de senha não confere.';

        if (!$errors) {
            if ($isGoogleUser && $atual === '') {
                // Google user definindo senha pela primeira vez — não precisa da atual
                $newHash = password_hash($nova, PASSWORD_DEFAULT);
                $up = $conn->prepare("UPDATE users SET `{$passCol}` = ? WHERE id = ?");
                $up->bind_param('si', $newHash, $uid);
                $up->execute();
                $up->close();
                $okMsg = 'Senha definida com sucesso.';
            } else {
                // Login normal — exige senha atual
                if ($atual === '') { $errors[] = 'Informe a senha atual.'; }
                else {
                    $st = $conn->prepare("SELECT `{$passCol}` AS senha FROM users WHERE id = ? LIMIT 1");
                    $st->bind_param('i', $uid);
                    $st->execute();
                    $row = $st->get_result()->fetch_assoc() ?: [];
                    $st->close();

                    $hash = (string)($row['senha'] ?? '');
                    if ($hash === '' || !password_verify($atual, $hash)) {
                        $errors[] = 'Senha atual inválida.';
                    } else {
                        $newHash = password_hash($nova, PASSWORD_DEFAULT);
                        $up = $conn->prepare("UPDATE users SET `{$passCol}` = ? WHERE id = ?");
                        $up->bind_param('si', $newHash, $uid);
                        $up->execute();
                        $up->close();
                        $okMsg = 'Senha alterada com sucesso.';
                    }
                }
            }
        }
    }
}

$fotoSrc = str_replace('\\', '/', $foto);
$fotoSrc = mediaResolveUrl($fotoSrc, 'https://placehold.co/240x240/111827/9ca3af?text=Foto');

include $ROOT . '/views/partials/header.php';
include $ROOT . '/views/partials/vendor_layout_start.php';
?>

<div class="space-y-6">

  <?php if ($okMsg !== ''): ?>
    <div class="rounded-2xl border border-greenx/30 bg-greenx/[0.08] px-5 py-3.5 text-sm text-greenx flex items-center gap-3">
      <i data-lucide="check-circle-2" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($okMsg, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="rounded-2xl border border-red-500/30 bg-red-600/[0.08] px-5 py-3.5 text-sm text-red-300">
      <?php foreach ($errors as $e): ?>
        <div class="flex items-start gap-2 mb-1 last:mb-0"><i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0 mt-0.5"></i> <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php
    // Verification progress
    $_vfFields = [
      'nome'      => ['label' => 'Nome',     'ok' => trim($nome) !== ''],
      'email'     => ['label' => 'E-mail',   'ok' => trim($email) !== ''],
      'telefone'  => ['label' => 'Telefone', 'ok' => trim((string)($user['telefone'] ?? '')) !== ''],
      'doc'       => ['label' => 'CPF',      'ok' => trim((string)($user['documento'] ?? '')) !== ''],
      'foto'      => ['label' => 'Foto',     'ok' => $foto !== ''],
    ];
    $_vfDone = count(array_filter(array_column($_vfFields, 'ok')));
    $_vfPct  = (int) round(($_vfDone / count($_vfFields)) * 100);
  ?>

  <?php if ($_vfPct < 100): ?>
  <div class="relative overflow-hidden rounded-2xl border border-orange-500/30 bg-gradient-to-br from-orange-500/[0.06] to-transparent p-5">
    <div class="flex items-start gap-4">
      <div class="w-11 h-11 rounded-xl bg-orange-500/15 border border-orange-500/25 flex items-center justify-center flex-shrink-0">
        <i data-lucide="shield-alert" class="w-5 h-5 text-orange-400"></i>
      </div>
      <div class="flex-1 min-w-0">
        <h3 class="font-semibold text-orange-300 text-sm">Verificação — <?= $_vfPct ?>%</h3>
        <p class="text-xs text-orange-200/60 mt-0.5 mb-3">Complete seu perfil para habilitar saques.</p>
        <div class="w-full bg-white/[0.06] rounded-full h-2 mb-3">
          <div class="bg-gradient-to-r from-orange-400 to-orange-500 h-2 rounded-full transition-all" style="width:<?= $_vfPct ?>%"></div>
        </div>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($_vfFields as $_vf): ?>
          <span class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] <?= $_vf['ok'] ? 'bg-greenx/10 border border-greenx/25 text-greenx' : 'bg-white/[0.04] border border-white/[0.06] text-zinc-500' ?>">
            <i data-lucide="<?= $_vf['ok'] ? 'check' : 'circle' ?>" class="w-3 h-3"></i> <?= $_vf['label'] ?>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="rounded-2xl border border-greenx/30 bg-greenx/[0.06] p-4 flex items-center gap-3">
    <div class="w-9 h-9 rounded-xl bg-greenx/15 border border-greenx/25 flex items-center justify-center">
      <i data-lucide="shield-check" class="w-5 h-5 text-greenx"></i>
    </div>
    <div>
      <p class="text-sm font-semibold text-greenx">Conta verificada</p>
      <p class="text-xs text-greenx/60">Todas as funcionalidades estão habilitadas.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── PROFILE FORM ── -->
  <form method="post" enctype="multipart/form-data" class="space-y-6">
    <input type="hidden" name="action" value="profile">

    <div class="grid lg:grid-cols-3 gap-6">
      <!-- Avatar -->
      <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 flex flex-col items-center"
           x-data="avatarUpload('<?= htmlspecialchars($fotoSrc, ENT_QUOTES, 'UTF-8') ?>')"
           @dragover.prevent="dragging = true"
           @dragleave.prevent="dragging = false"
           @drop.prevent="handleDrop($event)">

        <div class="relative group cursor-pointer mb-4" @click="$refs.fotoInput.click()">
          <div class="w-36 h-36 rounded-full p-[3px] bg-gradient-to-br from-greenx/60 to-greenx/20">
            <img :src="preview" alt="Avatar" class="w-full h-full rounded-full object-cover border-2 border-blackx2 transition-all" :class="dragging ? 'scale-105 border-greenx' : ''">
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
        <p class="text-sm font-semibold text-center"><?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="text-xs text-zinc-500 text-center mt-0.5 mb-3"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="text-[11px] text-zinc-600 text-center">JPG, PNG, WEBP (até 4 MB)</p>
        <input type="file" name="foto" x-ref="fotoInput" accept="image/*" class="hidden" @change="handleFile($event)">
      </div>

      <!-- Fields -->
      <div class="lg:col-span-2 space-y-5">
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 space-y-5">
          <div class="flex items-center gap-2.5 pb-3 border-b border-blackx3">
            <div class="w-8 h-8 rounded-lg bg-greenx/10 border border-greenx/20 flex items-center justify-center">
              <i data-lucide="user" class="w-4 h-4 text-greenx"></i>
            </div>
            <h2 class="text-sm font-semibold">Dados pessoais</h2>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">Nome</label>
              <div class="relative">
                <i data-lucide="user" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
                <input name="nome" value="<?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?>" required
                       class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-greenx/60 focus:ring-1 focus:ring-greenx/20 transition" placeholder="Seu nome">
              </div>
            </div>
            <div>
              <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">E-mail</label>
              <div class="relative">
                <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
                <input type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required
                       class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-greenx/60 focus:ring-1 focus:ring-greenx/20 transition" placeholder="seu@email.com">
              </div>
            </div>
            <div>
              <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">Telefone</label>
              <div class="relative">
                <i data-lucide="phone" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
                <input name="telefone" id="inputTelefone" value="<?= htmlspecialchars((string)($user['telefone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="(00) 00000-0000" maxlength="15"
                       class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-greenx/60 focus:ring-1 focus:ring-greenx/20 transition">
              </div>
            </div>
            <div>
              <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">CPF</label>
              <div class="relative">
                <i data-lucide="file-text" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
                <input name="documento" id="inputCPF" value="<?= htmlspecialchars((string)($user['documento'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="000.000.000-00" maxlength="14"
                       class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-greenx/60 focus:ring-1 focus:ring-greenx/20 transition">
              </div>
            </div>
          </div>

          <button class="w-full sm:w-auto bg-greenx hover:bg-greenx/90 text-white font-semibold rounded-xl px-6 py-3 text-sm transition flex items-center justify-center gap-2">
            <i data-lucide="save" class="w-4 h-4"></i> Salvar perfil
          </button>
        </div>

        <!-- Chat toggle -->
        <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6">
          <div class="flex items-center gap-2.5 pb-3 border-b border-blackx3 mb-4">
            <div class="w-8 h-8 rounded-lg bg-greenx/10 border border-greenx/20 flex items-center justify-center">
              <i data-lucide="message-circle" class="w-4 h-4 text-purple-400"></i>
            </div>
            <h2 class="text-sm font-semibold">Chat da Loja</h2>
          </div>
          <p class="text-xs text-zinc-400 mb-4">Permita que compradores enviem mensagens diretamente para você.</p>
          <?php $chatEnabled = chatVendorEnabled($conn, $uid); ?>
          <form method="post">
            <input type="hidden" name="action" value="toggle_chat">
            <label class="flex items-center gap-3 cursor-pointer group">
              <div class="relative">
                <input type="hidden" name="chat_enabled" value="0">
                <input type="checkbox" name="chat_enabled" value="1" <?= $chatEnabled ? 'checked' : '' ?>
                       class="sr-only peer" onchange="this.form.submit()">
                <div class="w-11 h-6 bg-zinc-700 rounded-full peer peer-checked:bg-greenx transition-colors"></div>
                <div class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full transition-transform peer-checked:translate-x-5 shadow"></div>
              </div>
              <span class="text-sm font-medium <?= $chatEnabled ? 'text-greenx' : 'text-zinc-400' ?>">
                <?= $chatEnabled ? 'Chat ativado' : 'Chat desativado' ?>
              </span>
            </label>
          </form>
        </div>
      </div>
    </div>
  </form>

  <!-- ── SECURITY FORM ── -->
  <form method="post" class="bg-blackx2 border border-blackx3 rounded-2xl p-6 space-y-5">
    <input type="hidden" name="action" value="password">
    <div class="flex items-center gap-2.5 pb-3 border-b border-blackx3">
      <div class="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
        <i data-lucide="key-round" class="w-4 h-4 text-purple-400"></i>
      </div>
      <h2 class="text-sm font-semibold"><?= $isGoogleUser ? 'Definir Senha' : 'Alterar Senha' ?></h2>
    </div>

    <?php if ($isGoogleUser): ?>
    <div class="rounded-xl border border-greenx/20 bg-greenx/[0.06] px-4 py-3 text-sm text-purple-300 flex items-center gap-2.5">
      <i data-lucide="info" class="w-4 h-4 flex-shrink-0"></i>
      Você entrou com o Google. A senha é opcional.
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <?php if (!$isGoogleUser): ?>
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
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5"><?= $isGoogleUser ? 'Criar senha' : 'Nova senha' ?></label>
        <div class="relative">
          <i data-lucide="key-round" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
          <input type="password" name="nova_senha" placeholder="<?= $isGoogleUser ? 'Criar senha (opcional)' : 'Mín. 6 caracteres' ?>"
                 class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/20 transition">
        </div>
      </div>
      <div>
        <label class="block text-[11px] font-medium text-zinc-500 uppercase tracking-wider mb-1.5">Confirmar</label>
        <div class="relative">
          <i data-lucide="check-circle-2" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-600 pointer-events-none"></i>
          <input type="password" name="confirmar_senha" placeholder="Repetir nova senha"
                 class="w-full bg-blackx border border-blackx3 rounded-xl pl-10 pr-3 py-3 text-sm outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/20 transition">
        </div>
      </div>
    </div>

    <button class="w-full sm:w-auto bg-purple-600 hover:bg-purple-500 text-white font-semibold rounded-xl px-6 py-3 text-sm transition flex items-center justify-center gap-2">
      <i data-lucide="shield-check" class="w-4 h-4"></i>
      <?= $isGoogleUser ? 'Definir senha' : 'Alterar senha' ?>
    </button>
  </form>

  <!-- Quick Links -->
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <h3 class="text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-3">Ações rápidas</h3>
    <div class="flex flex-wrap gap-2">
      <a href="<?= BASE_PATH ?>/vendedor/saques" class="rounded-xl border border-blackx3 bg-blackx hover:border-greenx/30 px-3 py-2 text-sm text-zinc-400 hover:text-white transition flex items-center gap-1.5">
        <i data-lucide="banknote" class="w-3.5 h-3.5"></i> Saques
      </a>
      <a href="<?= BASE_PATH ?>/wallet" class="rounded-xl border border-blackx3 bg-blackx hover:border-greenx/30 px-3 py-2 text-sm text-zinc-400 hover:text-white transition flex items-center gap-1.5">
        <i data-lucide="wallet" class="w-3.5 h-3.5"></i> Carteira
      </a>
      <a href="<?= BASE_PATH ?>/vendedor/produtos" class="rounded-xl border border-blackx3 bg-blackx hover:border-purple-500/30 px-3 py-2 text-sm text-zinc-400 hover:text-white transition flex items-center gap-1.5">
        <i data-lucide="package" class="w-3.5 h-3.5"></i> Produtos
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
include $ROOT . '/views/partials/vendor_layout_end.php';
include $ROOT . '/views/partials/footer.php';