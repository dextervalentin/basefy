<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\usuarios.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/admin_users.php';
exigirAdmin();

$db = new Database();
$conn = $db->connect();

$q = trim((string)($_GET['q'] ?? ''));
$like = '%' . $q . '%';
$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;

$lista = listarTodosUsuarios($conn, $q, $pagina, $pp);

$pageTitle = 'Usuários';
$activeMenu = 'usuarios';
$topActions = [['label' => 'Novo usuário', 'href' => 'usuarios_form']];
$subnavItems = [
    ['label' => 'Listar', 'href' => 'usuarios', 'active' => true],
    ['label' => 'Adicionar', 'href' => 'usuarios_form', 'active' => false],
];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="">
  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <!-- Premium Filter -->
    <form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
      <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
        <div class="md:flex-1">
          <label class="block text-xs text-zinc-500 mb-1">Busca</label>
          <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nome ou e-mail" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
        </div>
        <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar
          </button>
          <a href="usuarios" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          </a>
        </div>
      </div>
      <div class="mt-3 md:hidden flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
          <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar filtros
        </button>
        <a href="usuarios" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
        </a>
      </div>
    </form>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-zinc-400 border-b border-blackx3">
            <th class="text-left py-3 pr-3">ID</th>
            <th class="text-left py-3 pr-3">Nome</th>
            <th class="text-left py-3 pr-3">E-mail</th>
            <th class="text-left py-3 pr-3">Saldo</th>
            <th class="text-left py-3 pr-3">Conta</th>
            <th class="text-left py-3">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lista['itens'] as $row): ?>
            <?php $isAtivo = (int)$row['ativo'] === 1; $statusId = 'status-user-' . (int)$row['id']; ?>
            <tr class="row-link border-b border-blackx3/50 hover:bg-blackx/40"
                data-href="<?= BASE_PATH ?>/admin/usuarios_form?id=<?= (int)$row['id'] ?>" tabindex="0">
              <td class="py-3 pr-3"><?= (int)$row['id'] ?></td>
              <td class="py-3 pr-3"><?= htmlspecialchars($row['nome']) ?></td>
              <td class="py-3 pr-3"><?= htmlspecialchars($row['email']) ?></td>
              <td class="py-3 pr-3 font-semibold text-zinc-200">R$ <?= number_format((float)($row['wallet_saldo'] ?? 0), 2, ',', '.') ?></td>
              <td class="py-3 pr-3">
                <span id="<?= $statusId ?>" class="px-2.5 py-1 rounded-full text-xs font-medium <?= $isAtivo ? 'bg-greenx/15 border border-greenx/40 text-greenx' : 'bg-red-500/15 border border-red-400/40 text-red-300' ?>">
                  <?= $isAtivo ? 'Ativo' : 'Inativo' ?>
                </span>
              </td>
              <td class="py-3">
                <div class="flex items-center gap-2">
                  <a class="inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 hover:border-greenx px-2.5 py-1.5 text-xs text-zinc-300 hover:text-white transition" href="usuarios_form?id=<?= (int)$row['id'] ?>">
                    <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Editar
                  </a>
                  <button
                    type="button"
                    class="js-toggle-ativo relative inline-flex h-6 w-11 items-center rounded-full transition <?= $isAtivo ? 'bg-greenx' : 'bg-zinc-600' ?>"
                    data-id="<?= (int)$row['id'] ?>"
                    data-ativo="<?= $isAtivo ? '1' : '0' ?>"
                    data-scope="usuarios"
                    data-status-target="<?= $statusId ?>">
                    <span class="js-toggle-knob inline-block h-4 w-4 rounded-full bg-white transition <?= $isAtivo ? 'translate-x-6' : 'translate-x-1' ?>"></span>
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lista['itens']): ?>
            <tr><td colspan="6" class="py-6 text-zinc-500">Nenhum usuário encontrado.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php
      $paginaAtual  = (int)($lista['pagina'] ?? $pagina);
      $totalPaginas = (int)($lista['total_paginas'] ?? 1);
      include __DIR__ . '/../../views/partials/pagination.php';
    ?>
  </div>
</div>

<script>
(function () {
  function setVisual(btn, ativo) {
    const knob = btn.querySelector('.js-toggle-knob');
    btn.classList.toggle('bg-greenx', ativo === 1);
    btn.classList.toggle('bg-zinc-600', ativo !== 1);
    knob?.classList.toggle('translate-x-6', ativo === 1);
    knob?.classList.toggle('translate-x-1', ativo !== 1);
    btn.dataset.ativo = String(ativo);

    const statusEl = document.getElementById(btn.dataset.statusTarget || '');
    if (statusEl) {
      statusEl.textContent = ativo === 1 ? 'Ativo' : 'Inativo';
      statusEl.classList.remove('bg-greenx/15','border-greenx/40','text-greenx','bg-red-500/15','border-red-400/40','text-red-300','text-greenx','text-red-400');
      if (ativo === 1) { statusEl.classList.add('bg-greenx/15','border-greenx/40','text-greenx'); }
      else { statusEl.classList.add('bg-red-500/15','border-red-400/40','text-red-300'); }
    }
  }

  const toast = (msg, ok = true) => {
    if (typeof window.showToast === 'function') {
      window.showToast(msg, ok ? 'success' : 'error');
      return;
    }

    let el = document.getElementById('adm-toast-fallback');
    if (!el) {
      el = document.createElement('div');
      el.id = 'adm-toast-fallback';
      el.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;padding:10px 14px;border-radius:10px;color:#fff;font:500 14px sans-serif;opacity:0;transition:.2s';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.style.background = ok ? '#8800E4' : '#dc2626';
    el.style.opacity = '1';
    clearTimeout(window.__admToastTimer);
    window.__admToastTimer = setTimeout(() => { el.style.opacity = '0'; }, 1800);
  };

  document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.js-toggle-ativo');
    if (!btn) return;

    const atual = Number(btn.dataset.ativo || '0');
    const novo = atual === 1 ? 0 : 1;
    btn.disabled = true;

    try {
      const body = new URLSearchParams({
        id: btn.dataset.id,
        ativo: String(novo),
        scope: 'usuarios'
      });

      const res = await fetch('api_toggle_status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
      });

      const data = await res.json();
      if (!data.ok) throw new Error(data.msg || 'Falha ao atualizar status.');
      setVisual(btn, novo);
      toast(data.msg || 'Usuário atualizado.');
    } catch (err) {
      alert(err.message || 'Erro ao atualizar status.');
      toast(err.message || 'Erro ao atualizar status.', false);
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>