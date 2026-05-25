<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\usuarios_form.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_users.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();
adminUsersEnsureWalletColumns($conn);

$id = (int)($_GET['id'] ?? 0);
$erro = '';
$msgOk = isset($_GET['saved']) ? 'Usuário atualizado com sucesso.' : '';

function adminParseMoneyUser(string $value): float
{
    $value = trim(str_replace(['R$', ' '], '', $value));
    if ($value === '') return 0.0;
    if (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }
    return (float)preg_replace('/[^0-9.\-]/', '', $value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['form_action'] ?? 'save_user');
    $idPost = (int)($_POST['id'] ?? 0);
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'wallet_adjust' && $idPost > 0) {
        [$success, $msg] = adminUserWalletAjustar(
            $conn,
            $idPost,
            $adminId,
            (string)($_POST['wallet_action'] ?? ''),
            adminParseMoneyUser((string)($_POST['valor'] ?? '0')),
            (string)($_POST['motivo'] ?? ''),
            (string)($_POST['observacao'] ?? '')
        );
        if ($success) {
            header('Location: usuarios_form?id=' . $idPost . '&saved=1');
            exit;
        }
        $erro = $msg;
        $id = $idPost;
    } elseif (in_array($action, ['wallet_freeze', 'wallet_unfreeze'], true) && $idPost > 0) {
        [$success, $msg] = adminUserWalletCongelar($conn, $idPost, $adminId, $action === 'wallet_freeze', (string)($_POST['motivo_bloqueio'] ?? ''));
        if ($success) {
            header('Location: usuarios_form?id=' . $idPost . '&saved=1');
            exit;
        }
        $erro = $msg;
        $id = $idPost;
    } elseif ($idPost > 0) {
        $usuarioAtual = obterUsuarioPorId($conn, $idPost);
        if (!$usuarioAtual || normalizarRolePainel((string)($usuarioAtual['role'] ?? '')) === 'admin') {
            $erro = 'Usuário não encontrado.';
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
                (string)($_POST['nova_senha'] ?? ''),
                (string)($_POST['telefone'] ?? ''),
                (string)($_POST['cpf'] ?? '')
            );
            if ($success) {
                header('Location: usuarios_form?id=' . $idPost . '&saved=1');
                exit;
            }
            $erro = $msg;
        }
        $id = $idPost;
    } else {
        [$success, $msg] = criarUsuarioPainel(
            $conn,
            (string)($_POST['nome'] ?? ''),
            (string)($_POST['email'] ?? ''),
            (string)($_POST['senha'] ?? ''),
            'comprador'
        );
        if ($success) {
            header('Location: usuarios');
            exit;
        }
        $erro = $msg;
    }
}

$editar = $id > 0 ? obterUsuarioPorId($conn, $id) : null;
if ($editar && normalizarRolePainel((string)($editar['role'] ?? '')) === 'admin') {
    $editar = null;
}
$detalhesPerfil = $editar ? obterUsuarioDetalhesPainel($conn, (int)$editar['id']) : ['usuario' => [], 'seller_profile' => null, 'verifications' => [], 'documents' => []];
$walletHistorico = $editar ? adminUserWalletHistorico($conn, (int)$editar['id'], 30) : [];

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
        'id' => 'ID', 'nome' => 'Nome', 'email' => 'E-mail', 'avatar' => 'Avatar', 'role' => 'Perfil',
        'ativo' => 'Ativo', 'is_vendedor' => 'Vendedor', 'status_vendedor' => 'Status vendedor',
        'wallet_saldo' => 'Saldo wallet', 'wallet_frozen' => 'Wallet bloqueada', 'status_conta' => 'Status conta',
        'criado_em' => 'Criado em', 'atualizado_em' => 'Atualizado em', 'last_seen_at' => 'Último acesso',
        'telefone' => 'Telefone', 'cpf' => 'CPF', 'slug' => 'Slug', 'nome_loja' => 'Nome da loja',
        'documento' => 'Documento', 'chave_pix' => 'Chave PIX', 'bio' => 'Bio', 'chat_enabled' => 'Chat ativo',
        'seller_fee_override_enabled' => 'Taxa personalizada ativa', 'seller_fee_percent' => 'Taxa personalizada',
    ];
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function adminUsuarioValor(string $key, mixed $value): string
{
    if ($value === null || $value === '') return '-';
    if (is_bool($value)) return $value ? 'Sim' : 'Não';
    if (in_array($key, ['ativo', 'is_vendedor', 'chat_enabled', 'seller_fee_override_enabled', 'wallet_frozen'], true)) {
        return ((string)$value === '1' || $value === true || (string)$value === 't') ? 'Sim' : 'Não';
    }
    if ($key === 'wallet_saldo') return 'R$ ' . number_format((float)$value, 2, ',', '.');
    if ($key === 'seller_fee_percent') return number_format((float)$value, 2, ',', '.') . '%';
    if (in_array($key, ['criado_em', 'atualizado_em', 'last_seen_at', 'wallet_frozen_at', 'created_at'], true)) return adminUsuarioData($value);
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '-';
    return (string)$value;
}

function adminUsuarioData(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') return '-';
    $ts = strtotime($raw);
    return $ts ? date('d/m/Y H:i', $ts) : $raw;
}

function adminUsuarioBool(mixed $value): bool
{
    return (string)$value === '1' || $value === true || (string)$value === 't';
}

function adminUsuarioBadge(string $label, bool $ok): string
{
    $class = $ok ? 'bg-greenx/15 border-greenx/40 text-greenx' : 'bg-red-500/15 border-red-400/40 text-red-300';
    return '<span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold ' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

$u = $detalhesPerfil['usuario'] ?? [];
$saldoAtual = (float)($u['wallet_saldo'] ?? 0);
$walletFrozen = adminUsuarioBool($u['wallet_frozen'] ?? false);
$infoCards = ['id', 'slug', 'role', 'ativo', 'is_vendedor', 'status_vendedor', 'status_conta', 'criado_em', 'atualizado_em', 'last_seen_at'];
?>

<div class="max-w-7xl mx-auto space-y-4" x-data="{ walletModal:false, walletAction:'credit' }">
  <?php if ($erro): ?>
    <div class="rounded-xl bg-red-600/20 border border-red-500 text-red-300 px-4 py-3 text-sm"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($msgOk): ?>
    <div class="rounded-xl bg-greenx/10 border border-greenx/40 text-greenx px-4 py-3 text-sm"><?= htmlspecialchars($msgOk, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_390px] gap-4 items-start">
    <section class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4">
        <div>
          <p class="text-xs text-zinc-500 uppercase tracking-wide font-semibold">Campos editáveis</p>
          <h2 class="text-lg font-bold"><?= $editar ? htmlspecialchars((string)($editar['nome'] ?? 'Usuário'), ENT_QUOTES, 'UTF-8') : 'Novo usuário' ?></h2>
        </div>
        <?php if ($editar): ?>
          <div class="flex flex-wrap items-center gap-2">
            <?= adminUsuarioBadge(!empty($u['ativo']) ? 'Conta ativa' : 'Conta inativa', adminUsuarioBool($u['ativo'] ?? false)) ?>
            <?= adminUsuarioBadge($walletFrozen ? 'Wallet bloqueada' : 'Wallet liberada', !$walletFrozen) ?>
          </div>
        <?php endif; ?>
      </div>

      <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <input type="hidden" name="form_action" value="save_user">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
        <div>
          <label class="block text-xs text-zinc-500 mb-1">Nome</label>
          <input name="nome" required value="<?= htmlspecialchars((string)($editar['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <div>
          <label class="block text-xs text-zinc-500 mb-1">E-mail</label>
          <input name="email" type="email" required value="<?= htmlspecialchars((string)($editar['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <div>
          <label class="block text-xs text-zinc-500 mb-1">Telefone</label>
          <input name="telefone" value="<?= htmlspecialchars((string)($editar['telefone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="(00) 00000-0000" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <div>
          <label class="block text-xs text-zinc-500 mb-1">CPF</label>
          <input name="cpf" value="<?= htmlspecialchars((string)($editar['cpf'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="000.000.000-00" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx">
        </div>
        <?php if ($editar): ?>
          <div class="md:col-span-2">
            <label class="block text-xs text-zinc-500 mb-1">Nova senha</label>
            <input name="nova_senha" type="password" placeholder="Opcional, mínimo 8 caracteres" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx">
          </div>
        <?php else: ?>
          <div class="md:col-span-2">
            <label class="block text-xs text-zinc-500 mb-1">Senha</label>
            <input name="senha" type="password" required placeholder="Mínimo 8 caracteres" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx">
          </div>
        <?php endif; ?>
        <div class="md:col-span-2 flex justify-end gap-2 pt-1">
          <a href="usuarios" class="rounded-xl border border-blackx3 text-zinc-300 px-4 py-2.5 hover:border-greenx hover:text-white transition">Voltar</a>
          <button class="rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold px-5 py-2.5 transition">Salvar alterações</button>
        </div>
      </form>
    </section>

    <?php if ($editar): ?>
    <aside class="space-y-4">
      <section class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5">
        <div class="flex items-center justify-between gap-3 mb-4">
          <div>
            <p class="text-xs text-zinc-500 uppercase tracking-wide font-semibold">Wallet</p>
            <h2 class="text-2xl font-black text-greenx">R$ <?= number_format($saldoAtual, 2, ',', '.') ?></h2>
          </div>
          <i data-lucide="wallet" class="w-6 h-6 text-zinc-500"></i>
        </div>
        <div class="grid grid-cols-2 gap-2 mb-3 text-xs">
          <div class="rounded-xl bg-blackx border border-blackx3 px-3 py-2">
            <p class="text-zinc-500">Estado</p>
            <p class="font-semibold <?= $walletFrozen ? 'text-red-300' : 'text-greenx' ?>"><?= $walletFrozen ? 'Bloqueada' : 'Liberada' ?></p>
          </div>
          <div class="rounded-xl bg-blackx border border-blackx3 px-3 py-2">
            <p class="text-zinc-500">Último bloqueio</p>
            <p class="font-semibold text-zinc-300"><?= htmlspecialchars(adminUsuarioData($u['wallet_frozen_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" @click="walletAction='credit'; walletModal=true" class="inline-flex items-center justify-center gap-2 rounded-xl bg-greenx/15 border border-greenx/35 text-greenx px-3 py-2.5 text-sm font-semibold hover:bg-greenx/25 transition">
            <i data-lucide="plus" class="w-4 h-4"></i> Adicionar
          </button>
          <button type="button" @click="walletAction='debit'; walletModal=true" class="inline-flex items-center justify-center gap-2 rounded-xl bg-red-500/15 border border-red-500/35 text-red-300 px-3 py-2.5 text-sm font-semibold hover:bg-red-500/25 transition">
            <i data-lucide="minus" class="w-4 h-4"></i> Remover
          </button>
        </div>
        <form method="post" class="mt-2 grid grid-cols-1 gap-2">
          <input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
          <input type="hidden" name="form_action" value="<?= $walletFrozen ? 'wallet_unfreeze' : 'wallet_freeze' ?>">
          <input name="motivo_bloqueio" placeholder="Motivo do <?= $walletFrozen ? 'desbloqueio' : 'bloqueio' ?>" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2 text-sm outline-none focus:border-greenx">
          <button class="inline-flex items-center justify-center gap-2 rounded-xl border border-blackx3 px-3 py-2.5 text-sm font-semibold <?= $walletFrozen ? 'text-greenx hover:border-greenx' : 'text-orange-300 hover:border-orange-400' ?> transition">
            <i data-lucide="<?= $walletFrozen ? 'unlock' : 'lock' ?>" class="w-4 h-4"></i> <?= $walletFrozen ? 'Desbloquear wallet' : 'Congelar wallet' ?>
          </button>
        </form>
      </section>
    </aside>
    <?php endif; ?>
  </div>

  <?php if ($editar): ?>
  <section class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5">
    <div class="flex items-center gap-2 mb-3">
      <i data-lucide="info" class="w-4 h-4 text-greenx"></i>
      <h2 class="font-semibold">Campos informativos</h2>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2 text-sm">
      <?php foreach ($infoCards as $key): ?>
        <?php if (!array_key_exists($key, $u)) continue; ?>
        <div class="rounded-xl bg-blackx border border-blackx3 px-3 py-2 min-w-0">
          <p class="text-[11px] text-zinc-500 uppercase tracking-wide"><?= htmlspecialchars(adminUsuarioLabel($key), ENT_QUOTES, 'UTF-8') ?></p>
          <p class="text-zinc-200 break-words mt-0.5"><?= htmlspecialchars(adminUsuarioValor($key, $u[$key]), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5">
    <div class="flex items-center justify-between gap-3 mb-3">
      <div class="flex items-center gap-2">
        <i data-lucide="history" class="w-4 h-4 text-greenx"></i>
        <h2 class="font-semibold">Histórico de wallet</h2>
      </div>
      <span class="text-xs text-zinc-500"><?= count($walletHistorico) ?> registro(s)</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-2 pr-3">Data</th>
            <th class="text-left py-2 pr-3">Tipo</th>
            <th class="text-left py-2 pr-3">Valor</th>
            <th class="text-left py-2 pr-3">Antes</th>
            <th class="text-left py-2 pr-3">Depois</th>
            <th class="text-left py-2 pr-3">Admin</th>
            <th class="text-left py-2">Motivo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($walletHistorico as $mov): ?>
            <?php
              $action = (string)($mov['action'] ?? '');
              $actionLabel = match ($action) {
                'credit' => 'Crédito', 'debit' => 'Débito', 'freeze' => 'Bloqueio', 'unfreeze' => 'Desbloqueio', default => ucfirst($action),
              };
              $actionClass = match ($action) {
                'credit', 'unfreeze' => 'text-greenx', 'debit', 'freeze' => 'text-red-300', default => 'text-zinc-300',
              };
            ?>
            <tr class="border-b border-blackx3/60 hover:bg-blackx/40 transition">
              <td class="py-2 pr-3 text-zinc-400 whitespace-nowrap"><?= htmlspecialchars(adminUsuarioData($mov['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-2 pr-3 font-semibold <?= $actionClass ?>"><?= htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-2 pr-3"><?= (float)($mov['amount'] ?? 0) > 0 ? 'R$ ' . number_format((float)$mov['amount'], 2, ',', '.') : '-' ?></td>
              <td class="py-2 pr-3">R$ <?= number_format((float)($mov['balance_before'] ?? 0), 2, ',', '.') ?></td>
              <td class="py-2 pr-3">R$ <?= number_format((float)($mov['balance_after'] ?? 0), 2, ',', '.') ?></td>
              <td class="py-2 pr-3 text-zinc-400"><?= htmlspecialchars((string)(($mov['admin_nome'] ?? '') ?: ($mov['admin_email'] ?? ('#' . ($mov['admin_id'] ?? '-')))), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="py-2 text-zinc-400 max-w-[320px]"><span class="line-clamp-2"><?= htmlspecialchars(trim((string)($mov['reason'] ?? '') . ' ' . (string)($mov['note'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$walletHistorico): ?><tr><td colspan="7" class="py-4 text-zinc-500">Nenhuma movimentação administrativa registrada.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
    <section class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5">
      <div class="flex items-center gap-2 mb-3">
        <i data-lucide="store" class="w-4 h-4 text-greenx"></i>
        <h2 class="font-semibold">Perfil de vendedor</h2>
      </div>
      <?php if (!empty($detalhesPerfil['seller_profile'])): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
          <?php foreach ($detalhesPerfil['seller_profile'] as $key => $value): ?>
            <div class="rounded-xl bg-blackx border border-blackx3 px-3 py-2 min-w-0">
              <p class="text-[11px] text-zinc-500 uppercase tracking-wide"><?= htmlspecialchars(adminUsuarioLabel((string)$key), ENT_QUOTES, 'UTF-8') ?></p>
              <p class="text-zinc-200 break-words mt-0.5"><?= htmlspecialchars(adminUsuarioValor((string)$key, $value), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-sm text-zinc-500">Nenhum perfil de vendedor cadastrado para este usuário.</p>
      <?php endif; ?>
    </section>

    <section class="bg-blackx2 border border-blackx3 rounded-2xl p-4 md:p-5">
      <div class="flex items-center gap-2 mb-3">
        <i data-lucide="shield-check" class="w-4 h-4 text-greenx"></i>
        <h2 class="font-semibold">KYC e documentos</h2>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
        <div class="space-y-2">
          <p class="text-xs text-zinc-500 uppercase tracking-wide">Etapas</p>
          <?php foreach (($detalhesPerfil['verifications'] ?? []) as $verif): ?>
            <div class="rounded-xl bg-blackx border border-blackx3 p-3">
              <div class="flex items-center justify-between gap-3">
                <span class="font-semibold"><?= htmlspecialchars((string)($verif['tipo'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="text-xs px-2 py-1 rounded-full border border-blackx3 text-zinc-300"><?= htmlspecialchars((string)($verif['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <?php if (!empty($verif['observacao'])): ?><p class="text-xs text-zinc-400 mt-2"><?= htmlspecialchars((string)$verif['observacao'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (empty($detalhesPerfil['verifications'])): ?><p class="text-sm text-zinc-500">Nenhum KYC enviado.</p><?php endif; ?>
        </div>
        <div class="space-y-2">
          <p class="text-xs text-zinc-500 uppercase tracking-wide">Documentos</p>
          <?php foreach (($detalhesPerfil['documents'] ?? []) as $doc): ?>
            <div class="rounded-xl bg-blackx border border-blackx3 p-3">
              <div class="flex items-center justify-between gap-3">
                <span class="font-semibold"><?= htmlspecialchars((string)($doc['tipo_doc'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="text-xs px-2 py-1 rounded-full border border-blackx3 text-zinc-300"><?= htmlspecialchars((string)($doc['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <p class="text-xs text-zinc-500 break-words mt-1"><?= htmlspecialchars((string)($doc['arquivo'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
              <?php if (!empty($doc['observacao'])): ?><p class="text-xs text-zinc-400 mt-1"><?= htmlspecialchars((string)$doc['observacao'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (empty($detalhesPerfil['documents'])): ?><p class="text-sm text-zinc-500">Nenhum documento enviado.</p><?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <div x-show="walletModal" x-transition class="fixed inset-0 z-[9999] flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/75 backdrop-blur-sm" @click="walletModal=false"></div>
    <form method="post" class="relative w-full max-w-md rounded-2xl border border-blackx3 bg-blackx2 p-5 shadow-2xl space-y-3">
      <input type="hidden" name="form_action" value="wallet_adjust">
      <input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
      <input type="hidden" name="wallet_action" :value="walletAction">
      <div class="flex items-center justify-between gap-3 mb-1">
        <h3 class="font-bold" x-text="walletAction === 'credit' ? 'Adicionar saldo' : 'Remover saldo'"></h3>
        <button type="button" @click="walletModal=false" class="w-8 h-8 rounded-lg border border-blackx3 text-zinc-400 hover:text-white"><i data-lucide="x" class="w-4 h-4 mx-auto"></i></button>
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Valor</label>
        <input name="valor" required inputmode="decimal" placeholder="0,00" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx">
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Motivo</label>
        <input name="motivo" required placeholder="Ex: ajuste manual, chargeback, bônus" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx">
      </div>
      <div>
        <label class="block text-xs text-zinc-500 mb-1">Observação interna</label>
        <textarea name="observacao" rows="3" class="w-full rounded-xl bg-blackx border border-blackx3 px-3 py-2.5 outline-none focus:border-greenx resize-none"></textarea>
      </div>
      <button class="w-full rounded-xl bg-greenx hover:bg-greenx2 text-white font-semibold py-2.5 transition">Confirmar movimentação</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>