<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\cadastro_usuario.php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';

iniciarSessao();

if (usuarioLogado()) {
    redirecionarPorPerfil();
}

$conn = (new Database())->connect();

$ok = '';
$err = '';
$telefone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = trim((string)($_POST['nome'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
  $telefone = trim((string)($_POST['telefone'] ?? ''));

  [$success, $msg] = cadastrarContaPublica($conn, $nome, $email, $senha, 'comprador', $telefone, true);

    if ($success) {
        header('Location: ' . BASE_PATH . '/login?ok=1');
        exit;
    }

    $err = $msg;
}

include __DIR__ . '/../views/partials/header.php';
?>
<style>
  @keyframes blob{0%,100%{transform:translate(0,0) scale(1)}25%{transform:translate(30px,-50px) scale(1.1)}50%{transform:translate(-20px,20px) scale(0.9)}75%{transform:translate(20px,40px) scale(1.05)}}
  .auth-blob{position:absolute;border-radius:50%;filter:blur(80px);opacity:.15;animation:blob 20s ease-in-out infinite}
  .auth-input{transition:all .3s cubic-bezier(.4,0,.2,1)}
  .auth-input:focus{box-shadow:0 0 0 3px rgba(var(--t-accent-rgb),.15);border-color:var(--t-accent)!important;transform:translateY(-1px)}
  .auth-form.was-submitted .auth-input:invalid{border-color:#ef4444!important;background:rgba(239,68,68,.08)!important}
  .auth-form.was-submitted .relative:has(.auth-input:invalid) + .required-hint{display:flex!important}
  .auth-icon{position:absolute;left:0.875rem;top:50%;transform:translateY(-50%);width:1rem;height:1rem;color:#71717a;pointer-events:none;z-index:10;transition:color .3s}
  .auth-input:focus ~ .auth-icon, .relative:focus-within .auth-icon{color:var(--t-accent)}
  .auth-btn{position:relative;overflow:hidden;transition:all .3s cubic-bezier(.4,0,.2,1)}
  .auth-btn::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);transform:translateX(-100%);transition:transform .5s}
  .auth-btn:hover::before{transform:translateX(100%)}
  .auth-btn:hover{transform:translateY(-1px);box-shadow:0 8px 30px -4px rgba(var(--t-accent-rgb),.4)}
  .auth-card{backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);animation:fadeInUp .6s ease-out both}
  .auth-link{position:relative}.auth-link::after{content:'';position:absolute;bottom:-2px;left:0;width:0;height:2px;background:var(--t-accent);transition:width .3s ease}.auth-link:hover::after{width:100%}
</style>

<div class="min-h-screen relative overflow-hidden flex items-center justify-center px-4 py-8">
  <!-- Animated background blobs -->
  <div class="auth-blob w-[400px] h-[400px] bg-greenx -top-32 -right-32" style="animation-delay:0s"></div>
  <div class="auth-blob w-[350px] h-[350px] bg-purple-500 bottom-0 -left-24" style="animation-delay:-10s"></div>

  <!-- Grid overlay -->
  <div class="absolute inset-0 opacity-[0.03]" style="background-image:linear-gradient(rgba(255,255,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.1) 1px,transparent 1px);background-size:60px 60px"></div>

  <div class="w-full max-w-md relative z-10">
    <div class="auth-card bg-blackx2/80 border border-white/[0.06] rounded-3xl p-8 shadow-2xl shadow-black/40 relative">
      <!-- Logo -->
      <div class="flex items-center mb-8">
        <img src="<?= BASE_PATH ?>/assets/img/logo22.png" alt="Basefy" class="h-10 w-auto object-contain">
      </div>

      <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Criar conta</h1>
        <p class="text-sm text-zinc-500 mt-1">WhatsApp com DDD é obrigatório para avisos importantes.</p>
      </div>

      <?php if ($ok): ?>
      <div class="mb-5 rounded-xl bg-greenx/10 border border-greenx/30 text-greenx px-4 py-3 text-sm flex items-center gap-2.5 animate-fade-in">
        <i data-lucide="check-circle" class="w-4 h-4 flex-shrink-0"></i>
        <?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <?php endif; ?>

      <?php if ($err): ?>
      <div class="mb-5 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm flex items-center gap-2.5 animate-fade-in">
        <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0"></i>
        <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <?php endif; ?>

      <form method="post" class="auth-form space-y-4" data-required-form>
        <div>
          <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wider">Nome completo</label>
          <div class="relative">
            <i data-lucide="user" class="auth-icon"></i>
            <input name="nome" placeholder="Seu nome" required
                   class="auth-input w-full rounded-xl bg-white/[0.04] border border-white/[0.08] pl-11 pr-4 py-3 outline-none text-white placeholder:text-zinc-600 text-sm">
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wider">E-mail</label>
          <div class="relative">
            <i data-lucide="mail" class="auth-icon"></i>
            <input type="email" name="email" placeholder="seu@email.com" required
                   class="auth-input w-full rounded-xl bg-white/[0.04] border border-white/[0.08] pl-11 pr-4 py-3 outline-none text-white placeholder:text-zinc-600 text-sm">
          </div>
          <p class="required-hint hidden mt-1 items-center gap-1 text-xs text-red-300"><i data-lucide="alert-circle" class="w-3 h-3"></i> Informe um e-mail válido.</p>
        </div>

        <div>
          <label class="block text-xs font-semibold text-zinc-300 mb-1.5 uppercase tracking-wider">WhatsApp <span class="text-red-400">obrigatório</span></label>
          <div class="relative">
            <i data-lucide="message-circle" class="auth-icon"></i>
            <input type="tel" name="telefone" placeholder="(11) 99999-9999" required inputmode="tel" minlength="10" maxlength="16" value="<?= htmlspecialchars($telefone, ENT_QUOTES, 'UTF-8') ?>"
                   class="auth-input w-full rounded-xl bg-white/[0.04] border border-white/[0.08] pl-11 pr-4 py-3 outline-none text-white placeholder:text-zinc-600 text-sm">
          </div>
          <p class="required-hint hidden mt-1 items-center gap-1 text-xs text-red-300"><i data-lucide="alert-circle" class="w-3 h-3"></i> Informe seu WhatsApp com DDD para concluir o cadastro.</p>
        </div>

        <div>
          <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wider">Senha</label>
          <div class="relative" x-data="{show:false}">
            <i data-lucide="lock" class="auth-icon"></i>
                 <input :type="show?'text':'password'" name="senha" placeholder="Mínimo 8 caracteres" required minlength="8"
                   class="auth-input w-full rounded-xl bg-white/[0.04] border border-white/[0.08] pl-11 pr-11 py-3 outline-none text-white placeholder:text-zinc-600 text-sm">
            <button type="button" @click="show=!show" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300 transition-colors">
              <i :data-lucide="show?'eye-off':'eye'" class="w-4 h-4"></i>
            </button>
          </div>
          <p class="required-hint hidden mt-1 items-center gap-1 text-xs text-red-300"><i data-lucide="alert-circle" class="w-3 h-3"></i> Informe uma senha com no mínimo 8 caracteres.</p>
        </div>

        <button type="submit" class="auth-btn w-full rounded-xl bg-gradient-to-r from-greenx to-greenxd text-white font-bold py-3.5 text-sm tracking-wide mt-2">
          Cadastrar
        </button>
      </form>

      <p class="text-sm text-zinc-500 mt-6 text-center">
        Já tem conta?
        <a class="auth-link text-greenx font-semibold hover:text-greenx2 transition-colors" href="<?= BASE_PATH ?>/login">Entrar</a>
      </p>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('[data-required-form]').forEach(function(form){
  form.addEventListener('submit', function(){ form.classList.add('was-submitted'); });
  form.querySelectorAll('[required]').forEach(function(field){
    field.addEventListener('invalid', function(){ form.classList.add('was-submitted'); }, true);
  });
});
</script>
<?php include __DIR__ . '/../views/partials/footer.php'; ?>