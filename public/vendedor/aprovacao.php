<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

iniciarSessao();
if (!usuarioLogado()) {
  header('Location: ' . BASE_PATH . '/login');
    exit;
}

if (roleAtual() !== 'vendedor') {
  header('Location: ' . BASE_PATH . '/dashboard');
    exit;
}

$conn = (new Database())->connect();
$userId = (int)($_SESSION['user_id'] ?? 0);
$userNome = (string)($_SESSION['user']['nome'] ?? $_SESSION['nome'] ?? 'Vendedor');
$userEmail = (string)($_SESSION['user']['email'] ?? '');

if ($userId <= 0) {
  header('Location: ' . BASE_PATH . '/login');
    exit;
}

$erro = '';
$sucesso = '';

$perfil = [
    'nome_loja' => '',
    'documento' => '',
    'telefone' => '',
    'chave_pix' => '',
    'bio' => '',
];

$stPerfil = $conn->prepare("SELECT nome_loja, documento, telefone, chave_pix, bio FROM seller_profiles WHERE user_id = ? LIMIT 1");
if ($stPerfil) {
    $stPerfil->bind_param('i', $userId);
    $stPerfil->execute();
    $rowPerfil = $stPerfil->get_result()->fetch_assoc();
    if ($rowPerfil) {
        $perfil = array_merge($perfil, $rowPerfil);
    }
}

$stStatus = $conn->prepare("SELECT status_vendedor FROM users WHERE id = ? LIMIT 1");
$currentStatus = 'nao_solicitado';
if ($stStatus) {
    $stStatus->bind_param('i', $userId);
    $stStatus->execute();
    $rowStatus = $stStatus->get_result()->fetch_assoc() ?: [];
    $currentStatus = normalizarStatusVendedor((string)($rowStatus['status_vendedor'] ?? 'nao_solicitado'));
}

$ultimaSolicitacao = null;
$stReq = $conn->prepare("SELECT id, status, motivo_recusa, criado_em, atualizado_em FROM seller_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
if ($stReq) {
    $stReq->bind_param('i', $userId);
    $stReq->execute();
    $ultimaSolicitacao = $stReq->get_result()->fetch_assoc() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomeLoja = trim((string)($_POST['nome_loja'] ?? ''));
    $documento = trim((string)($_POST['documento'] ?? ''));
    $telefone = trim((string)($_POST['telefone'] ?? ''));
  $pixCadastroBloqueado = trim((string)($perfil['chave_pix'] ?? '')) !== '';
  $chavePix = $pixCadastroBloqueado ? trim((string)$perfil['chave_pix']) : trim((string)($_POST['chave_pix'] ?? ''));
    $bio = trim((string)($_POST['bio'] ?? ''));

  $documento = preg_replace('/\D+/', '', $documento) ?? '';
  $telefone = preg_replace('/\D+/', '', $telefone) ?? '';

    $perfil = [
        'nome_loja' => $nomeLoja,
        'documento' => $documento,
        'telefone' => $telefone,
        'chave_pix' => $chavePix,
        'bio' => $bio,
    ];

    if ($nomeLoja === '' || $documento === '' || $telefone === '' || $chavePix === '' || $bio === '') {
        $erro = 'Preencha todos os campos obrigatórios do formulário de vendedor.';
    } elseif (!in_array(strlen($documento), [11, 14], true)) {
      $erro = 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.';
    } elseif (strlen($telefone) < 10 || strlen($telefone) > 11) {
      $erro = 'Informe um telefone válido com DDD.';
    } elseif (mb_strlen($bio) < 30) {
        $erro = 'A descrição da loja deve ter pelo menos 30 caracteres.';
    } else {
        $conn->begin_transaction();
        try {
            $upPerfil = $conn->prepare(
                "INSERT INTO seller_profiles (user_id, nome_loja, documento, telefone, bio, chave_pix)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    nome_loja = VALUES(nome_loja),
                    documento = VALUES(documento),
                    telefone = VALUES(telefone),
                    bio = VALUES(bio),
                    chave_pix = VALUES(chave_pix)"
            );
            $upPerfil->bind_param('isssss', $userId, $nomeLoja, $documento, $telefone, $bio, $chavePix);
            $upPerfil->execute();

            $stLock = $conn->prepare("SELECT id, status FROM seller_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $stLock->bind_param('i', $userId);
            $stLock->execute();
            $req = $stLock->get_result()->fetch_assoc() ?: null;

            if ($req && in_array((string)$req['status'], ['pendente', 'aberto'], true)) {
                $reqId = (int)$req['id'];
                $upReq = $conn->prepare("UPDATE seller_requests SET status='pendente', motivo_recusa=NULL WHERE id = ?");
                $upReq->bind_param('i', $reqId);
                $upReq->execute();
            } else {
                $insReq = $conn->prepare("INSERT INTO seller_requests (user_id, status, motivo_recusa) VALUES (?, 'pendente', NULL)");
                $insReq->bind_param('i', $userId);
                $insReq->execute();
            }

            $upUser = $conn->prepare("UPDATE users SET role='vendedor', is_vendedor=1, status_vendedor='pendente' WHERE id = ?");
            $upUser->bind_param('i', $userId);
            $upUser->execute();

            $conn->commit();

            $_SESSION['role'] = 'vendedor';
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                $_SESSION['user']['role'] = 'vendedor';
                $_SESSION['user']['status_vendedor'] = 'pendente';
            }

            $currentStatus = 'pendente';
            $sucesso = 'Formulário enviado com sucesso. Sua conta está em análise.';

            $stReq2 = $conn->prepare("SELECT id, status, motivo_recusa, criado_em, atualizado_em FROM seller_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            if ($stReq2) {
                $stReq2->bind_param('i', $userId);
                $stReq2->execute();
                $ultimaSolicitacao = $stReq2->get_result()->fetch_assoc() ?: null;
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $erro = 'Não foi possível enviar sua solicitação no momento. Tente novamente.';
        }
    }
}

$pageTitle = 'Aprovação de Vendedor';
include __DIR__ . '/../../views/partials/header.php';
?>

<div class="min-h-screen bg-blackx text-white">
  <div class="max-w-4xl mx-auto px-4 py-10">
    <div class="bg-blackx2 border border-blackx3 rounded-2xl p-6 md:p-8">
      <h1 class="text-2xl font-bold">Aprovação de conta de vendedor</h1>
      <p class="text-zinc-400 mt-2">Olá, <?= htmlspecialchars($userNome, ENT_QUOTES, 'UTF-8') ?>. Complete os dados da sua empresa para habilitar seu painel de vendas.</p>
      <p class="text-zinc-500 text-sm mt-1">Conta: <?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?></p>

      <?php if ($erro): ?>
        <div class="mt-4 rounded-xl border border-red-500 bg-red-600/20 text-red-300 px-4 py-3 text-sm"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($sucesso): ?>
        <div class="mt-4 rounded-xl border border-greenx bg-greenx/20 text-greenx px-4 py-3 text-sm"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($currentStatus === 'aprovado'): ?>
        <div class="mt-5 rounded-2xl border border-greenx/50 bg-greenx/10 p-5">
          <h2 class="font-semibold text-greenx">Conta aprovada</h2>
          <p class="text-sm text-zinc-300 mt-2">Seu acesso de vendedor foi aprovado. O dashboard está liberado.</p>
          <a href="<?= BASE_PATH ?>/vendedor/dashboard" class="inline-flex mt-4 rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2">Ir para o dashboard</a>
        </div>

      <?php elseif ($currentStatus === 'pendente'): ?>
        <div class="mt-5 rounded-2xl border border-orange-400/50 bg-orange-500/10 p-5">
          <h2 class="font-semibold text-orange-300">Conta em análise</h2>
          <p class="text-sm text-zinc-300 mt-2">Seu formulário foi recebido e está em avaliação pelo time de aprovação. O dashboard de vendedor será liberado após aprovação.</p>
          <?php if (!empty($ultimaSolicitacao['atualizado_em'])): ?>
            <p class="text-xs text-zinc-500 mt-2">Última atualização: <?= htmlspecialchars((string)$ultimaSolicitacao['atualizado_em'], ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <?php if ($currentStatus === 'rejeitado'): ?>
          <div class="mt-5 rounded-2xl border border-red-500/50 bg-red-600/10 p-5">
            <h2 class="font-semibold text-red-300">Solicitação rejeitada</h2>
            <p class="text-sm text-zinc-300 mt-2">Ajuste os dados e envie novamente para nova análise.</p>
            <?php if (!empty($ultimaSolicitacao['motivo_recusa'])): ?>
              <p class="text-sm text-red-200 mt-2">Motivo: <?= htmlspecialchars((string)$ultimaSolicitacao['motivo_recusa'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="text-sm text-zinc-300">Nome da loja / Razão social *</label>
            <input type="text" name="nome_loja" value="<?= htmlspecialchars((string)$perfil['nome_loja'], ENT_QUOTES, 'UTF-8') ?>" required class="mt-1 w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx">
          </div>

          <div>
            <label class="text-sm text-zinc-300">CPF ou CNPJ *</label>
            <input type="text" id="documento" name="documento" value="<?= htmlspecialchars((string)$perfil['documento'], ENT_QUOTES, 'UTF-8') ?>" required inputmode="numeric" maxlength="18" class="mt-1 w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx" placeholder="000.000.000-00 ou 00.000.000/0000-00">
          </div>

          <div>
            <label class="text-sm text-zinc-300">Telefone / WhatsApp *</label>
            <input type="text" id="telefone" name="telefone" value="<?= htmlspecialchars((string)$perfil['telefone'], ENT_QUOTES, 'UTF-8') ?>" required inputmode="numeric" maxlength="15" class="mt-1 w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx" placeholder="(11) 99999-9999">
          </div>

          <div class="md:col-span-2">
            <label class="text-sm text-zinc-300">Chave PIX para recebimentos *</label>
            <?php $pixCadastroBloqueado = trim((string)$perfil['chave_pix']) !== ''; ?>
            <?php if ($pixCadastroBloqueado): ?><input type="hidden" name="chave_pix" value="<?= htmlspecialchars((string)$perfil['chave_pix'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
            <input type="text" <?= $pixCadastroBloqueado ? 'disabled' : 'name="chave_pix"' ?> value="<?= htmlspecialchars((string)$perfil['chave_pix'], ENT_QUOTES, 'UTF-8') ?>" required class="mt-1 w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx <?= $pixCadastroBloqueado ? 'opacity-60 cursor-not-allowed' : '' ?>" placeholder="CPF, e-mail, telefone ou chave aleatória">
            <?php if ($pixCadastroBloqueado): ?><p class="text-xs text-zinc-500 mt-1">Chave PIX cadastrada e bloqueada para proteger seus saques.</p><?php endif; ?>
          </div>

          <div class="md:col-span-2">
            <label class="text-sm text-zinc-300">Descrição da loja e produtos *</label>
            <textarea name="bio" rows="4" required class="mt-1 w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx" placeholder="Fale sobre sua operação, tipo de produtos e prazos de entrega."><?= htmlspecialchars((string)$perfil['bio'], ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>

          <div class="md:col-span-2 flex items-center gap-2">
            <button class="rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-4 py-2">Enviar para análise</button>
            <a href="<?= BASE_PATH ?>/logout" class="rounded-xl border border-blackx3 px-4 py-2 text-sm hover:border-red-500">Sair</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  (function () {
    const docInput = document.getElementById('documento');
    const telInput = document.getElementById('telefone');

    const digitsOnly = (value) => (value || '').replace(/\D+/g, '');

    const maskCpfCnpj = (value) => {
      const digits = digitsOnly(value).slice(0, 14);
      if (digits.length <= 11) {
        return digits
          .replace(/(\d{3})(\d)/, '$1.$2')
          .replace(/(\d{3})(\d)/, '$1.$2')
          .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
      }
      return digits
        .replace(/(\d{2})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d)/, '$1/$2')
        .replace(/(\d{4})(\d{1,2})$/, '$1-$2');
    };

    const maskPhone = (value) => {
      const digits = digitsOnly(value).slice(0, 11);
      if (digits.length <= 10) {
        return digits
          .replace(/(\d{2})(\d)/, '($1) $2')
          .replace(/(\d{4})(\d{1,4})$/, '$1-$2');
      }
      return digits
        .replace(/(\d{2})(\d)/, '($1) $2')
        .replace(/(\d{5})(\d{1,4})$/, '$1-$2');
    };

    if (docInput) {
      docInput.value = maskCpfCnpj(docInput.value);
      docInput.addEventListener('input', function () {
        this.value = maskCpfCnpj(this.value);
      });
    }

    if (telInput) {
      telInput.value = maskPhone(telInput.value);
      telInput.addEventListener('input', function () {
        this.value = maskPhone(this.value);
      });
    }
  })();
</script>

<?php include __DIR__ . '/../../views/partials/footer.php';
