<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/upload_paths.php';
require_once __DIR__ . '/../src/media.php';
require_once __DIR__ . '/../src/email.php';
require_once __DIR__ . '/../src/user_identity.php';

exigirLogin();

$conn = (new Database())->connect();
$uid  = (int)($_SESSION['user_id'] ?? 0);

try {
    $conn->query("CREATE TABLE IF NOT EXISTS user_verifications (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL,
        tipo VARCHAR(30) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pendente',
        dados TEXT,
        observacao TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, tipo)
    )");
} catch (\Throwable $e) {}

try {
    $conn->query("CREATE TABLE IF NOT EXISTS user_verification_docs (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL,
        tipo_doc VARCHAR(30) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pendente',
        arquivo TEXT,
        observacao TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {}

// Auto-migrate: ensure telefone and cpf columns exist in users
try { $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS telefone VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cpf VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $e) {}

function verifStatus(object $conn, mixed $uid, string $tipo): array {
    $uid = (int)$uid;
    $st = $conn->prepare("SELECT status, dados, observacao FROM user_verifications WHERE user_id = ? AND tipo = ? LIMIT 1");
    $st->bind_param('is', $uid, $tipo);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: ['status' => 'nao_enviado', 'dados' => null, 'observacao' => null];
}

function verifUpsert(object $conn, mixed $uid, string $tipo, string $status, ?string $dados = null): void {
    $uid = (int)$uid;
    $st = $conn->prepare("UPDATE user_verifications SET status = ?, dados = ?, atualizado = CURRENT_TIMESTAMP WHERE user_id = ? AND tipo = ?");
    $st->bind_param('ssis', $status, $dados, $uid, $tipo);
    $st->execute();
    if ($st->affected_rows === 0) {
        $st->close();
        $st = $conn->prepare("INSERT INTO user_verifications (user_id, tipo, status, dados) VALUES (?, ?, ?, ?)");
        $st->bind_param('isss', $uid, $tipo, $status, $dados);
        $st->execute();
    }
    $st->close();
}

function verifDocStatus(object $conn, mixed $uid, string $tipoDoc): array {
    $uid = (int)$uid;
    $st = $conn->prepare("SELECT id, status, arquivo, observacao FROM user_verification_docs WHERE user_id = ? AND tipo_doc = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('is', $uid, $tipoDoc);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: ['status' => 'pendente', 'arquivo' => null, 'observacao' => null];
}

function listUserColumns(object $conn): array {
    $cols = [];

    try {
        $rs = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
        if ($rs) {
            while ($r = $rs->fetch_assoc()) {
                $col = strtolower((string)($r['column_name'] ?? ''));
                if ($col !== '') $cols[] = $col;
            }
        }
    } catch (\Throwable $e) {}

    if (!$cols) {
        try {
            $rs = $conn->query("SHOW COLUMNS FROM users");
            if ($rs) {
                while ($r = $rs->fetch_assoc()) {
                    $col = strtolower((string)($r['Field'] ?? ''));
                    if ($col !== '') $cols[] = $col;
                }
            }
        } catch (\Throwable $e) {}
    }

    return array_values(array_unique($cols));
}

function pickCol(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
        $v = strtolower($c);
        if (in_array($v, $cols, true)) return $v;
    }
    return null;
}

function loadUser(object $conn, mixed $uid, ?string $nameCol, ?string $emailCol, ?string $phoneCol, ?string $docCol): array {
    $uid = (int)$uid;
    $sel = ['id'];
    if ($nameCol)  $sel[] = $nameCol . ' AS nome';
    if ($emailCol) $sel[] = $emailCol . ' AS email';
    if ($phoneCol) $sel[] = $phoneCol . ' AS telefone';
    if ($docCol)   $sel[] = $docCol . ' AS documento';

    $st = $conn->prepare('SELECT ' . implode(', ', $sel) . ' FROM users WHERE id = ? LIMIT 1');
    $st->bind_param('i', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    return $row;
}

$cols = listUserColumns($conn);
$nameCol  = pickCol($cols, ['nome', 'name']);
$emailCol = pickCol($cols, ['email']);
$phoneCol = pickCol($cols, ['telefone', 'phone']);
$docCol   = pickCol($cols, ['cpf', 'documento']);

$user = loadUser($conn, $uid, $nameCol, $emailCol, $phoneCol, $docCol);

$msg = '';
$err = '';

$docTypes = [
    'identidade' => ['label' => 'Identidade (RG/CNH)', 'icon' => 'id-card', 'desc' => 'Frente e verso do documento'],
    'selfie' => ['label' => 'Selfie com documento', 'icon' => 'camera', 'desc' => 'Foto sua segurando o documento'],
    'comprovante_residencia' => ['label' => 'Comprovante de residência', 'icon' => 'home', 'desc' => 'Conta de luz, água ou internet'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'dados') {
        $nome     = trim((string)($_POST['nome'] ?? ''));
        $cpfRaw   = identityDigits(trim((string)($_POST['cpf'] ?? '')));
        $cpf      = identityFormatCpf($cpfRaw);
        $email    = trim((string)($_POST['email'] ?? ''));
        // Save phone with mask
        $telefone = trim((string)($_POST['telefone'] ?? ''));

        if ($nome === '' || $cpfRaw === '' || strlen($cpfRaw) !== 11) {
            $err = 'Preencha nome completo e CPF corretamente.';
        } else {
            if (userCpfJaExiste($conn, $cpfRaw, $uid)) {
              $err = 'Este CPF já está cadastrado em outra conta.';
            }
            // Check unique phone
            if ($err === '' && $phoneCol && $telefone !== '') {
                $chkTel = $conn->prepare("SELECT id FROM users WHERE {$phoneCol} = ? AND id != ? LIMIT 1");
                $chkTel->bind_param('si', $telefone, $uid);
                $chkTel->execute();
                if ($chkTel->get_result()->fetch_assoc()) {
                    $err = 'Este telefone já está cadastrado em outra conta.';
                    $chkTel->close();
                } else {
                    $chkTel->close();
                }
            }
        }
        if ($err === '') {
            $sets = [];
            $types = '';
            $vals = [];

            if ($nameCol)  { $sets[] = $nameCol . ' = ?'; $types .= 's'; $vals[] = $nome; }
            if ($docCol)   { $sets[] = $docCol . ' = ?'; $types .= 's'; $vals[] = $cpf; }
            if ($emailCol) { $sets[] = $emailCol . ' = ?'; $types .= 's'; $vals[] = $email; }
            if ($phoneCol) { $sets[] = $phoneCol . ' = ?'; $types .= 's'; $vals[] = $telefone; }

            if (!$sets) {
                $err = 'Não foi possível detectar os campos da conta para salvar seus dados.';
            } else {
                $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
                $types .= 'i';
                $vals[] = $uid;

                $up = $conn->prepare($sql);
                if (!$up) {
                    $err = 'Erro ao preparar atualização dos dados.';
                } else {
                    $up->bind_param($types, ...$vals);
                    $ok = $up->execute();
                    $up->close();

                    if (!$ok) {
                        $err = 'Falha ao salvar os dados pessoais.';
                    } else {
                        verifUpsert($conn, $uid, 'dados', 'pendente', json_encode([
                            'nome' => $nome,
                            'cpf' => $cpf,
                            'email' => $email,
                            'telefone' => $telefone,
                            'updated' => date('Y-m-d H:i:s'),
                        ]));

                        $_SESSION['user']['nome'] = $nome;
                        if ($email !== '') $_SESSION['user']['email'] = $email;

                        $user = loadUser($conn, $uid, $nameCol, $emailCol, $phoneCol, $docCol);
                        $msg = 'Dados pessoais salvos com sucesso!';
                    }
                }
            }
        }
    }

    if ($action === 'documentos_lote') {
        $missing = [];
        $uploaded = 0;
        $uploadErrors = [];

        foreach ($docTypes as $docKey => $docMeta) {
            $current = verifDocStatus($conn, $uid, $docKey);
            $status = strtolower((string)($current['status'] ?? 'pendente'));
            $hasFile = !empty($current['arquivo']);

            $locked = $hasFile && in_array($status, ['pendente', 'aprovado', 'verificado'], true);
            if ($locked) continue;

            $fileKey = 'arquivo_' . $docKey;
            if (!isset($_FILES[$fileKey]) || (int)($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $missing[] = $docMeta['label'];
                continue;
            }

            if ((int)($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $uploadErrors[] = 'Falha no upload de ' . $docMeta['label'] . '.';
                continue;
            }

            $mime = mime_content_type($_FILES[$fileKey]['tmp_name']) ?: '';
            $validMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            $size = (int)($_FILES[$fileKey]['size'] ?? 0);

            if (!in_array($mime, $validMimes, true)) {
                $uploadErrors[] = 'Formato inválido em ' . $docMeta['label'] . '. Use JPG, PNG, WEBP ou PDF.';
                continue;
            }
            if ($size > 10 * 1024 * 1024) {
                $uploadErrors[] = 'Arquivo muito grande em ' . $docMeta['label'] . ' (máx 10 MB).';
                continue;
            }

            try {
                // Use mediaSaveFromData to support PDFs and larger files (mediaSaveFromUpload only accepts images < 5MB)
                $tmpPath = $_FILES[$fileKey]['tmp_name'];
                $rawData = file_get_contents($tmpPath);
                if ($rawData === false) {
                    $uploadErrors[] = 'Falha ao ler arquivo de ' . $docMeta['label'] . '.';
                    continue;
                }
                $fileMime = $mime; // already validated above
                $fileName = basename((string)($_FILES[$fileKey]['name'] ?? 'document'));
                $mediaId = mediaSaveFromData($rawData, $fileMime, 'verification', (int)$uid, false, $fileName);
                unset($rawData); // free memory

                if (!$mediaId) {
                    $uploadErrors[] = 'Falha ao salvar ' . $docMeta['label'] . '.';
                    continue;
                }

                $arquivo = 'media:' . $mediaId;

                // Remove old doc row for same type (avoid duplicates)
                $delSql = "DELETE FROM user_verification_docs WHERE user_id = " . (int)$uid . " AND tipo_doc = '" . $conn->real_escape_string($docKey) . "' AND status IN ('pendente', 'rejeitado')";
                $conn->query($delSql);

                $ins = $conn->prepare("INSERT INTO user_verification_docs (user_id, tipo_doc, status, arquivo, observacao) VALUES (?, ?, 'pendente', ?, NULL)");
                if ($ins) {
                    $ins->bind_param('iss', $uid, $docKey, $arquivo);
                    $ins->execute();
                    $ins->close();
                }
                $uploaded++;
            } catch (\Throwable $e) {
                error_log('[Verificacao] Upload error for ' . $docKey . ': ' . $e->getMessage());
                $uploadErrors[] = 'Erro ao enviar ' . $docMeta['label'] . ': ' . $e->getMessage();
                continue;
            }
        }

        // Report collected errors
        if (!empty($uploadErrors)) {
            $err = implode(' ', $uploadErrors);
        }

        // Save whatever was uploaded (even partial)
        if ($uploaded > 0) {
            // Check if ALL docs are now uploaded
            $allSent = true;
            foreach (array_keys($docTypes) as $docKey) {
                $current = verifDocStatus($conn, $uid, $docKey);
                if (empty($current['arquivo'])) {
                    $allSent = false;
                    break;
                }
            }

            verifUpsert($conn, $uid, 'documentos', 'pendente', json_encode([
                'submitted_at' => date('Y-m-d H:i:s'),
                'uploaded_now' => $uploaded,
                'all_sent' => $allSent,
            ]));

            if ($allSent) {
                $msg = 'Todos os documentos enviados! Aguarde a análise do administrador.';
            } else {
                $msg = $uploaded . ' documento(s) salvo(s).' . ($err ? '' : ' Envie os restantes quando estiver pronto.');
            }
        } elseif ($err === '' && $uploaded === 0 && !empty($missing)) {
            $err = 'Selecione pelo menos um documento para enviar.';
        }
    }

    if ($action === 'enviar_email') {
        $result = enviarEmailVerificacao($conn, $uid);
        if ($result === true) {
            $msg = 'E-mail de verificação enviado! Verifique sua caixa de entrada (e spam).';
        } else {
            $err = (string)$result;
        }
    }
}

$vDados      = verifStatus($conn, $uid, 'dados');
$vEmail      = verifStatus($conn, $uid, 'email');
$vDocumentos = verifStatus($conn, $uid, 'documentos');

$docStatus = [];
foreach (array_keys($docTypes) as $docKey) {
    $docStatus[$docKey] = verifDocStatus($conn, $uid, $docKey);
}

$hasEmail = trim((string)($user['email'] ?? '')) !== '';
$smtpReady = smtpConfigured($conn);

$steps = [
    'dados' => $vDados['status'] === 'verificado',
    'email' => $vEmail['status'] === 'verificado',
    'documentos' => $vDocumentos['status'] === 'verificado',
];
$verifiedPct = (int) round((count(array_filter($steps)) / max(1, count($steps))) * 100);

$docUploadOpen = false;
foreach ($docStatus as $s) {
    $st = strtolower((string)($s['status'] ?? 'pendente'));
    $hasFile = !empty($s['arquivo']);
    if (!$hasFile || $st === 'rejeitado') {
        $docUploadOpen = true;
        break;
    }
}

$activeMenu = 'verificacao';
$pageTitle  = 'Verificação de Conta';

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/user_layout_start.php';
?>

<div class="space-y-5">
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

  <div class="bg-gradient-to-br from-blackx2 to-blackx2/80 border border-blackx3 rounded-2xl p-5 relative overflow-hidden">
    <div class="relative flex flex-col sm:flex-row sm:items-center gap-4">
      <div class="w-12 h-12 rounded-2xl bg-greenx/10 border border-greenx/20 flex items-center justify-center flex-shrink-0">
        <i data-lucide="shield-check" class="w-6 h-6 text-purple-400"></i>
      </div>
      <div class="flex-1 min-w-0">
        <h1 class="text-lg font-bold">Verificação de Conta</h1>
        <p class="text-xs text-zinc-400 mt-1">Complete as verificações para garantir a segurança dos pagamentos e liberar saques.</p>
      </div>
      <div class="inline-flex items-center gap-2 rounded-xl <?= $verifiedPct === 100 ? 'bg-greenx/15 border-greenx/30 text-greenx' : 'bg-greenx/15 border-greenx/30 text-purple-400' ?> border px-3 py-1.5">
        <span class="text-xl font-black"><?= $verifiedPct ?>%</span>
        <span class="text-[10px] font-medium"><?= $verifiedPct === 100 ? 'Verificado' : 'Concluído' ?></span>
      </div>
    </div>
  </div>

  <div class="space-y-3">
    <div class="bg-blackx2 border border-blackx3 rounded-2xl overflow-hidden" x-data="{ open: <?= $vDados['status'] !== 'verificado' ? 'true' : 'false' ?> }">
      <div class="flex items-center gap-3 px-5 py-4 cursor-pointer select-none" @click="open = !open">
        <div class="w-9 h-9 rounded-xl <?= $vDados['status'] === 'verificado' ? 'bg-greenx/15 border-greenx/30' : ($vDados['status'] === 'rejeitado' ? 'bg-red-500/15 border-red-500/30' : 'bg-greenx/15 border-greenx/30') ?> border flex items-center justify-center">
          <i data-lucide="user" class="w-4.5 h-4.5 <?= $vDados['status'] === 'verificado' ? 'text-greenx' : ($vDados['status'] === 'rejeitado' ? 'text-red-400' : 'text-purple-400') ?>"></i>
        </div>
        <div class="flex-1">
          <h3 class="text-sm font-semibold">Dados Pessoais</h3>
          <p class="text-[11px] text-zinc-500 mt-0.5">Preencha e salve seus dados.</p>
        </div>
        <i data-lucide="chevron-down" class="w-4 h-4 text-zinc-500 transition-transform" :class="open && 'rotate-180'"></i>
      </div>
      <div x-show="open" x-transition x-cloak class="px-5 pb-5 border-t border-blackx3">
        <?php
          $dadosLocked = in_array($vDados['status'], ['verificado', 'pendente'], true);
        ?>
        <form method="post" class="pt-4 space-y-3">
          <input type="hidden" name="action" value="dados">
          <?php
            // Fallback: if user DB columns are empty, read from verification dados JSON
            $dadosPayload = json_decode((string)($vDados['dados'] ?? ''), true) ?: [];
            $valNome  = (string)(($user['nome']     ?? '') ?: ($dadosPayload['nome']     ?? ''));
            $valCpf   = (string)(($user['documento'] ?? '') ?: ($dadosPayload['cpf']      ?? ''));
            $valEmail = (string)(($user['email']      ?? '') ?: ($dadosPayload['email']    ?? ''));
            $valTel   = (string)(($user['telefone']   ?? '') ?: ($dadosPayload['telefone'] ?? ''));
          ?>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <input name="nome" value="<?= htmlspecialchars($valNome, ENT_QUOTES, 'UTF-8') ?>" required placeholder="Nome completo" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx/50 transition <?= $dadosLocked ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= $dadosLocked ? 'readonly disabled' : '' ?>>
            </div>
            <div>
              <input name="cpf" id="verifCPF" value="<?= htmlspecialchars($valCpf, ENT_QUOTES, 'UTF-8') ?>" required placeholder="CPF" maxlength="14" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx/50 transition <?= $dadosLocked ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= $dadosLocked ? 'readonly disabled' : '' ?>>
            </div>
            <div>
              <input name="email" type="email" value="<?= htmlspecialchars($valEmail, ENT_QUOTES, 'UTF-8') ?>" placeholder="E-mail" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx/50 transition <?= $dadosLocked ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= $dadosLocked ? 'readonly disabled' : '' ?>>
            </div>
            <div>
              <input name="telefone" id="verifTel" value="<?= htmlspecialchars($valTel, ENT_QUOTES, 'UTF-8') ?>" placeholder="Telefone" maxlength="15" class="w-full bg-blackx border border-blackx3 rounded-xl px-3 py-2.5 text-sm outline-none focus:border-greenx/50 transition <?= $dadosLocked ? 'opacity-60 cursor-not-allowed' : '' ?>" <?= $dadosLocked ? 'readonly disabled' : '' ?>>
            </div>
          </div>
          <?php if ($vDados['status'] === 'verificado'): ?>
            <div class="rounded-xl border border-greenx/20 bg-greenx/[0.06] px-4 py-2.5 text-xs text-greenx flex items-center gap-2">
              <i data-lucide="check-circle-2" class="w-4 h-4 flex-shrink-0"></i>
              Dados verificados. Não é possível alterar.
            </div>
          <?php elseif ($vDados['status'] === 'pendente'): ?>
            <?php
              // Build dynamic message listing what's still missing
              $pendingItems = [];
              if ($vEmail['status'] !== 'verificado') $pendingItems[] = 'confirmar seu e-mail';
              // Check missing documents
              $missingDocs = [];
              foreach ($docStatus as $dKey => $dInfo) {
                  $dStat = strtolower((string)($dInfo['status'] ?? 'pendente'));
                  $hasF = !empty($dInfo['arquivo']);
                  if (!$hasF || $dStat === 'rejeitado') {
                      $missingDocs[] = $docTypes[$dKey]['label'] ?? $dKey;
                  }
              }
              if (!empty($missingDocs)) {
                  $pendingItems[] = 'enviar documento(s) pendente(s): ' . implode(', ', $missingDocs);
              }
            ?>
            <?php if (!empty($pendingItems)): ?>
            <div class="rounded-xl border border-orange-500/20 bg-orange-500/[0.06] px-4 py-2.5 text-xs text-orange-300 flex items-start gap-2">
              <i data-lucide="clock" class="w-4 h-4 flex-shrink-0 mt-0.5"></i>
              <span>Dados pessoais salvos! Para prosseguir com a análise, você ainda precisa: <?= htmlspecialchars(implode('; ', $pendingItems), ENT_QUOTES, 'UTF-8') ?>.</span>
            </div>
            <?php else: ?>
            <div class="rounded-xl border border-orange-500/20 bg-orange-500/[0.06] px-4 py-2.5 text-xs text-orange-300 flex items-center gap-2">
              <i data-lucide="clock" class="w-4 h-4 flex-shrink-0"></i>
              Dados enviados e aguardando análise do administrador. Não é possível alterar até a verificação.
            </div>
            <?php endif; ?>
          <?php elseif ($vDados['status'] === 'rejeitado'): ?>
            <div class="rounded-xl border border-red-500/20 bg-red-500/[0.06] px-4 py-2.5 text-xs text-red-300 flex items-center gap-2">
              <i data-lucide="x-circle" class="w-4 h-4 flex-shrink-0"></i>
              Dados rejeitados<?= !empty($vDados['observacao']) ? ': ' . htmlspecialchars((string)$vDados['observacao'], ENT_QUOTES, 'UTF-8') : '' ?>. Corrija e reenvie.
            </div>
          <?php endif; ?>
          <?php if (!$dadosLocked): ?>
          <button class="rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-6 py-2.5 text-sm transition inline-flex items-center gap-2">
            <i data-lucide="save" class="w-4 h-4"></i> Salvar dados
          </button>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <div class="bg-blackx2 border border-blackx3 rounded-2xl overflow-hidden" x-data="{ open: <?= $vEmail['status'] !== 'verificado' ? 'true' : 'false' ?> }">
      <div class="flex items-center gap-3 px-5 py-4 cursor-pointer select-none" @click="open = !open">
        <div class="w-9 h-9 rounded-xl <?= $vEmail['status'] === 'verificado' ? 'bg-greenx/15 border-greenx/30' : 'bg-greenx/15 border-greenx/30' ?> border flex items-center justify-center">
          <i data-lucide="mail" class="w-4.5 h-4.5 <?= $vEmail['status'] === 'verificado' ? 'text-greenx' : 'text-purple-400' ?>"></i>
        </div>
        <div class="flex-1">
          <h3 class="text-sm font-semibold">E-mail</h3>
          <p class="text-[11px] text-zinc-500 mt-0.5">Verifique clicando no link enviado.</p>
        </div>
        <i data-lucide="chevron-down" class="w-4 h-4 text-zinc-500 transition-transform" :class="open && 'rotate-180'"></i>
      </div>
      <div x-show="open" x-transition x-cloak class="px-5 pb-5 border-t border-blackx3 pt-4">
        <?php if ($vEmail['status'] === 'verificado'): ?>
          <p class="text-sm text-greenx">E-mail verificado.</p>
        <?php elseif (!$hasEmail): ?>
          <p class="text-sm text-zinc-500">Cadastre o e-mail nos dados pessoais primeiro.</p>
        <?php elseif ($smtpReady): ?>
          <form method="post">
            <input type="hidden" name="action" value="enviar_email">
            <button class="rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-6 py-2.5 text-sm transition inline-flex items-center gap-2">
              <i data-lucide="send" class="w-4 h-4"></i> Enviar e-mail de verificação
            </button>
          </form>
        <?php else: ?>
          <p class="text-sm text-zinc-500">SMTP não configurado.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="bg-blackx2 border border-blackx3 rounded-2xl overflow-hidden" x-data="{ open: true }">
      <div class="flex items-center gap-3 px-5 py-4 cursor-pointer select-none" @click="open = !open">
        <div class="w-9 h-9 rounded-xl <?= $vDocumentos['status'] === 'verificado' ? 'bg-greenx/15 border-greenx/30' : 'bg-greenx/15 border-greenx/30' ?> border flex items-center justify-center">
          <i data-lucide="file-text" class="w-4.5 h-4.5 <?= $vDocumentos['status'] === 'verificado' ? 'text-greenx' : 'text-purple-400' ?>"></i>
        </div>
        <div class="flex-1">
          <h3 class="text-sm font-semibold">Documentos</h3>
          <p class="text-[11px] text-zinc-500 mt-0.5">Envie os 3 documentos e salve no fim da seção.</p>
        </div>
        <i data-lucide="chevron-down" class="w-4 h-4 text-zinc-500 transition-transform" :class="open && 'rotate-180'"></i>
      </div>

      <div x-show="open" x-transition x-cloak class="px-5 pb-5 border-t border-blackx3 pt-4">
        <?php if ($vDocumentos['status'] === 'verificado'): ?>
          <p class="text-sm text-greenx">Documentos verificados pelo administrador.</p>
        <?php else: ?>
          <form method="post" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="documentos_lote">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
              <?php foreach ($docTypes as $docKey => $meta):
                $st = strtolower((string)($docStatus[$docKey]['status'] ?? 'pendente'));
                $hasFile = !empty($docStatus[$docKey]['arquivo']);
                $canUpload = !$hasFile || $st === 'rejeitado';
              ?>
              <div class="rounded-2xl border border-blackx3 bg-blackx/40 p-3" x-data="docUpload_<?= $docKey ?>()">
                <div class="flex items-center gap-2 mb-2">
                  <i data-lucide="<?= $meta['icon'] ?>" class="w-4 h-4 text-zinc-400"></i>
                  <p class="text-xs font-semibold"><?= $meta['label'] ?></p>
                </div>
                <p class="text-[10px] text-zinc-600 mb-2"><?= $meta['desc'] ?></p>

                <?php if (!$canUpload): ?>
                  <div class="rounded-xl border border-orange-500/20 bg-orange-500/[0.08] px-3 py-2 text-xs text-orange-300">
                    Documento enviado. Aguarde aprovação do admin.
                  </div>
                <?php else: ?>
                  <?php if ($st === 'rejeitado'): ?>
                    <div class="rounded-xl border border-red-500/25 bg-red-600/[0.08] px-3 py-2 text-xs text-red-300 mb-2">
                      Recusado<?= !empty($docStatus[$docKey]['observacao']) ? ': ' . htmlspecialchars((string)$docStatus[$docKey]['observacao'], ENT_QUOTES, 'UTF-8') : '.' ?>
                    </div>
                  <?php endif; ?>

                  <div class="relative cursor-pointer"
                       @dragover.prevent="dragging = true"
                       @dragleave.prevent="dragging = false"
                       @drop.prevent="handleDrop($event)">
                    <div x-show="!preview" class="border-2 border-dashed rounded-2xl p-4 text-center transition-all duration-300" :class="dragging ? 'border-greenx bg-greenx/5' : 'border-blackx3 hover:border-zinc-600 bg-blackx/40'" @click="$refs.fileInput_<?= $docKey ?>.click()">
                      <i data-lucide="cloud-upload" class="w-5 h-5 text-zinc-500 mx-auto"></i>
                      <p class="text-[11px] text-zinc-400 mt-1">Clique ou arraste</p>
                    </div>

                    <div x-show="preview" x-cloak class="relative group rounded-2xl overflow-hidden border border-blackx3">
                      <img :src="preview" class="w-full max-h-36 object-contain bg-blackx" x-show="isImage">
                      <div x-show="!isImage" class="p-2 text-xs text-zinc-300 bg-blackx" x-text="fileName"></div>
                    </div>

                    <input type="file" name="arquivo_<?= $docKey ?>" accept="image/*,.pdf" x-ref="fileInput_<?= $docKey ?>" class="hidden" @change="handleFile($event)">
                  </div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>

            <?php if ($docUploadOpen): ?>
              <button class="w-full lg:w-auto rounded-xl bg-greenx hover:bg-greenx/90 text-white font-semibold px-6 py-2.5 text-sm transition inline-flex items-center justify-center gap-2">
                <i data-lucide="upload" class="w-4 h-4"></i> Salvar documentos
              </button>
            <?php else: ?>
              <?php
                // Build dynamic message listing what's still missing beyond documents
                $docPendingItems = [];
                if ($vDados['status'] !== 'verificado' && $vDados['status'] !== 'pendente') $docPendingItems[] = 'preencher seus dados pessoais';
                if ($vEmail['status'] !== 'verificado') $docPendingItems[] = 'confirmar seu e-mail';
              ?>
              <?php if (!empty($docPendingItems)): ?>
                <div class="rounded-xl border border-orange-500/20 bg-orange-500/[0.06] px-4 py-2.5 text-xs text-orange-300 flex items-start gap-2">
                  <i data-lucide="clock" class="w-4 h-4 flex-shrink-0 mt-0.5"></i>
                  <span>Documentos enviados! Para prosseguir com a análise, você ainda precisa: <?= htmlspecialchars(implode('; ', $docPendingItems), ENT_QUOTES, 'UTF-8') ?>.</span>
                </div>
              <?php else: ?>
                <p class="text-xs text-orange-300">Documentos já enviados. Aguarde a análise do administrador.</p>
              <?php endif; ?>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  var cpf = document.getElementById('verifCPF');
  if (cpf && !cpf.disabled) {
    cpf.addEventListener('input', function() {
      var d = cpf.value.replace(/\D/g, '').slice(0, 11);
      if (d.length > 9) cpf.value = d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9);
      else if (d.length > 6) cpf.value = d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6);
      else if (d.length > 3) cpf.value = d.slice(0,3)+'.'+d.slice(3);
    });
  }

  var tel = document.getElementById('verifTel');
  if (tel && !tel.disabled) {
    tel.addEventListener('input', function() {
      var d = tel.value.replace(/\D/g, '').slice(0, 11);
      if (d.length > 6) tel.value = '('+d.slice(0,2)+') '+d.slice(2,7)+'-'+d.slice(7);
      else if (d.length > 2) tel.value = '('+d.slice(0,2)+') '+d.slice(2);
      else if (d.length > 0) tel.value = '('+d;
    });
  }

  // Real-time CPF/Phone uniqueness check
  var userId = <?= (int)$uid ?>;
  var basePath = <?= json_encode(rtrim(BASE_PATH, '/')) ?>;
  var debounceTimers = {};

  function createFeedback(input) {
    var fb = input.parentElement.querySelector('.verif-feedback');
    if (!fb) {
      fb = document.createElement('p');
      fb.className = 'verif-feedback text-[11px] mt-1 transition-all';
      input.parentElement.style.position = 'relative';
      input.after(fb);
    }
    return fb;
  }

  function checkUnique(type, input) {
    if (input.disabled || input.readOnly) return;
    var val = input.value.trim();
    var raw = val.replace(/\D/g, '');
    var minLen = (type === 'cpf') ? 11 : 10;

    var fb = createFeedback(input);

    if (raw.length < minLen) {
      fb.textContent = '';
      fb.className = 'verif-feedback text-[11px] mt-1';
      input.style.borderColor = '';
      return;
    }

    clearTimeout(debounceTimers[type]);
    debounceTimers[type] = setTimeout(function() {
      fb.textContent = 'Verificando...';
      fb.className = 'verif-feedback text-[11px] mt-1 text-zinc-500';

      fetch(basePath + '/api/check_verif?type=' + type + '&value=' + encodeURIComponent(val) + '&exclude_id=' + userId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.available) {
            fb.textContent = '\u2713 ' + data.message;
            fb.className = 'verif-feedback text-[11px] mt-1 text-greenx font-medium';
            input.style.borderColor = 'rgba(136, 0, 228, 0.5)';
          } else {
            fb.textContent = '\u2717 ' + data.message;
            fb.className = 'verif-feedback text-[11px] mt-1 text-red-400 font-medium';
            input.style.borderColor = 'rgba(239, 68, 68, 0.5)';
          }
        })
        .catch(function() {
          fb.textContent = '';
          input.style.borderColor = '';
        });
    }, 400);
  }

  if (cpf && !cpf.disabled) {
    cpf.addEventListener('input', function() { checkUnique('cpf', cpf); });
    // Check on page load if value exists
    if (cpf.value.replace(/\D/g, '').length >= 11) checkUnique('cpf', cpf);
  }
  if (tel && !tel.disabled) {
    tel.addEventListener('input', function() { checkUnique('telefone', tel); });
    if (tel.value.replace(/\D/g, '').length >= 10) checkUnique('telefone', tel);
  }
})();

<?php foreach (array_keys($docTypes) as $dk): ?>
function docUpload_<?= $dk ?>() {
  return {
    preview: null,
    dragging: false,
    fileName: '',
    isImage: true,
    handleFile(e) {
      var f = e.target.files?.[0];
      if (!f) return;
      this.processFile(f);
    },
    handleDrop(e) {
      this.dragging = false;
      var f = e.dataTransfer?.files?.[0];
      if (!f) return;
      var dt = new DataTransfer();
      dt.items.add(f);
      this.$refs.fileInput_<?= $dk ?>.files = dt.files;
      this.processFile(f);
    },
    processFile(f) {
      this.fileName = f.name;
      this.isImage = f.type.startsWith('image/');
      if (this.isImage) {
        var r = new FileReader();
        r.onload = (e) => { this.preview = e.target.result; };
        r.readAsDataURL(f);
      } else {
        this.preview = 'pdf';
      }
    }
  };
}
<?php endforeach; ?>
</script>

<?php
include __DIR__ . '/../views/partials/user_layout_end.php';
include __DIR__ . '/../views/partials/footer.php';
?>
