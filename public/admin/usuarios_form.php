<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\usuarios_form.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_users.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();

$id = (int)($_GET['id'] ?? 0);
$editar = $id > 0 ? obterUsuarioPorId($conn, $id) : null;
if ($editar && normalizarRolePainel((string)($editar['role'] ?? '')) === 'admin') {
  $editar = null;
}
$detalhesPerfil = $editar ? obterUsuarioDetalhesPainel($conn, (int)$editar['id']) : ['usuario' => [], 'seller_profile' => null, 'verifications' => [], 'documents' => []];

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idPost = (int)($_POST['id'] ?? 0);

    if ($idPost > 0) {
      $usuarioAtual = obterUsuarioPorId($conn, $idPost);
      if (!$usuarioAtual || normalizarRolePainel((string)($usuarioAtual['role'] ?? '')) === 'admin') {
        $success = false;
        $msg = 'Usuário não encontrado.';
      } else {
        $roleAtual = normalizarRolePainel((string)($usuarioAtual['role'] ?? 'usuario'));
        $statusAtual = $roleAtual === 'vendedor' ? (string)($usuarioAtual['status_vendedor'] ?? 'nao_solicitado') : 'nao_solicitado';
        [$success, $msg] = atualizarUsuarioPainel(
          $conn,
          $idPost,
          (string)($_POST['nome'] ?? ''),
          (string)($_POST['email'] ?? ''),
          $roleAtual,
          $statusAtual,
          (string)($_POST['nova_senha'] ?? '')
        );
      }
    } else {
        [$success, $msg] = criarUsuarioPainel(
            $conn,
            (string)($_POST['nome'] ?? ''),
            (string)($_POST['email'] ?? ''),
            (string)($_POST['senha'] ?? ''),
            'comprador'
        );
    }

    if ($success) {
        header('Location: usuarios');
        exit;
    }
    $erro = $msg;
}

$pageTitle = $editar ? 'Editar usuário' : 'Novo usuário';
$activeMenu = 'usuarios';
$subnavItems = [
    ['label' => 'Listar', 'href' => 'usuarios', 'active' => false],
    ['label' => 'Adicionar', 'href' => 'usuarios_form', 'active' => !$editar],
    ['label' => 'Editar', 'href' => '#', 'active' => (bool)$editar],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';

function adminUsuarioLabel(string $key): string
{
    $labels = [
        'id' => 'ID',
        'nome' => 'Nome',
        'email' => 'E-mail',
        'avatar' => 'Avatar',
        'role' => 'Perfil',
        'ativo' => 'Ativo',
        'is_vendedor' => 'Vendedor',
        'status_vendedor' => 'Status vendedor',
        'wallet_saldo' => 'Saldo wallet',
        'criado_em' => 'Criado em',
        'atualizado_em' => 'Atualizado em',
        'telefone' => 'Telefone',
        'cpf' => 'CPF',
        'slug' => 'Slug',
        'nome_loja' => 'Nome da loja',
        'documento' => 'Documento',
        'chave_pix' => 'Chave PIX',
        'bio' => 'Bio',
        'chat_enabled' => 'Chat ativo',
        'seller_fee_override_enabled' => 'Taxa personalizada ativa',
        'seller_fee_percent' => 'Taxa personalizada',
    ];
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function adminUsuarioValor(string $key, mixed $value): string
{
    if ($value === null || $value === '') return '-';
    if (is_bool($value)) return $value ? 'Sim' : 'Não';
    if (in_array($key, ['ativo', 'is_vendedor', 'chat_enabled', 'seller_fee_override_enabled'], true)) {
        return ((string)$value === '1' || $value === true || (string)$value === 't') ? 'Sim' : 'Não';
    }
    if ($key === 'wallet_saldo') return 'R$ ' . number_format((float)$value, 2, ',', '.');
    if ($key === 'seller_fee_percent') return number_format((float)$value, 2, ',', '.') . '%';
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '-';
    return (string)$value;
}
?>

<div class="max-w-6xl mx-auto space-y-4">
  <div class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <?php if ($erro): ?><div class="mb-4 rounded-lg bg-red-600/20 border border-red-500 text-red-300 px-3 py-2 text-sm"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

    <form method="post" class="space-y-3">
      <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
      <input name="nome" required value="<?= htmlspecialchars($editar['nome'] ?? '') ?>" placeholder="Nome" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx">
      <input name="email" type="email" required value="<?= htmlspecialchars($editar['email'] ?? '') ?>" placeholder="E-mail" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx">
      <?php if ($editar): ?>
        <input name="nova_senha" type="password" placeholder="Nova senha (opcional)" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx">
      <?php else: ?>
        <input name="senha" type="password" required placeholder="Senha (mín. 8)" class="w-full rounded-lg bg-blackx border border-blackx3 px-3 py-2 outline-none focus:border-greenx">
      <?php endif; ?>
      <button class="w-full rounded-lg bg-greenx hover:bg-greenx2 text-white font-semibold py-2.5 transition">Salvar</button>
    </form>
  </div>

  <?php if ($editar): ?>
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
    <section class="bg-blackx2 border border-blackx3 rounded-xl p-4">
      <div class="flex items-center gap-2 mb-3">
        <i data-lucide="user-round" class="w-4 h-4 text-greenx"></i>
        <h2 class="font-semibold">Dados completos da conta</h2>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
        <?php foreach (($detalhesPerfil['usuario'] ?? []) as $key => $value): ?>
          <div class="rounded-lg bg-blackx border border-blackx3 px-3 py-2 min-w-0">
            <p class="text-[11px] text-zinc-500 uppercase tracking-wide"><?= htmlspecialchars(adminUsuarioLabel((string)$key), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-zinc-200 break-words mt-0.5"><?= htmlspecialchars(adminUsuarioValor((string)$key, $value), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="bg-blackx2 border border-blackx3 rounded-xl p-4">
      <div class="flex items-center gap-2 mb-3">
        <i data-lucide="store" class="w-4 h-4 text-greenx"></i>
        <h2 class="font-semibold">Perfil de vendedor</h2>
      </div>
      <?php if (!empty($detalhesPerfil['seller_profile'])): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
          <?php foreach ($detalhesPerfil['seller_profile'] as $key => $value): ?>
            <div class="rounded-lg bg-blackx border border-blackx3 px-3 py-2 min-w-0">
              <p class="text-[11px] text-zinc-500 uppercase tracking-wide"><?= htmlspecialchars(adminUsuarioLabel((string)$key), ENT_QUOTES, 'UTF-8') ?></p>
              <p class="text-zinc-200 break-words mt-0.5"><?= htmlspecialchars(adminUsuarioValor((string)$key, $value), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-sm text-zinc-500">Nenhum perfil de vendedor cadastrado para este usuário.</p>
      <?php endif; ?>
    </section>
  </div>

  <section class="bg-blackx2 border border-blackx3 rounded-xl p-4">
    <div class="flex items-center gap-2 mb-3">
      <i data-lucide="shield-check" class="w-4 h-4 text-greenx"></i>
      <h2 class="font-semibold">Verificações e documentos</h2>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
      <div class="space-y-2">
        <p class="text-xs text-zinc-500 uppercase tracking-wide">Etapas de verificação</p>
        <?php foreach (($detalhesPerfil['verifications'] ?? []) as $verif): ?>
          <div class="rounded-lg bg-blackx border border-blackx3 p-3 text-sm">
            <div class="flex items-center justify-between gap-3 mb-2">
              <span class="font-semibold"><?= htmlspecialchars((string)($verif['tipo'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="text-xs px-2 py-1 rounded-full border border-blackx3 text-zinc-300"><?= htmlspecialchars((string)($verif['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php if (!empty($verif['dados'])): ?><pre class="text-xs text-zinc-400 whitespace-pre-wrap break-words bg-blackx2 rounded-lg p-2"><?= htmlspecialchars((string)$verif['dados'], ENT_QUOTES, 'UTF-8') ?></pre><?php endif; ?>
            <?php if (!empty($verif['observacao'])): ?><p class="text-xs text-zinc-400 mt-2"><?= htmlspecialchars((string)$verif['observacao'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (empty($detalhesPerfil['verifications'])): ?><p class="text-sm text-zinc-500">Nenhuma verificação enviada.</p><?php endif; ?>
      </div>
      <div class="space-y-2">
        <p class="text-xs text-zinc-500 uppercase tracking-wide">Documentos</p>
        <?php foreach (($detalhesPerfil['documents'] ?? []) as $doc): ?>
          <div class="rounded-lg bg-blackx border border-blackx3 p-3 text-sm">
            <div class="flex items-center justify-between gap-3 mb-1">
              <span class="font-semibold"><?= htmlspecialchars((string)($doc['tipo_doc'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="text-xs px-2 py-1 rounded-full border border-blackx3 text-zinc-300"><?= htmlspecialchars((string)($doc['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <p class="text-xs text-zinc-400 break-words"><?= htmlspecialchars((string)($doc['arquivo'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (!empty($doc['observacao'])): ?><p class="text-xs text-zinc-500 mt-1"><?= htmlspecialchars((string)$doc['observacao'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (empty($detalhesPerfil['documents'])): ?><p class="text-sm text-zinc-500">Nenhum documento enviado.</p><?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>