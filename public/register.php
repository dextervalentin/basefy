<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\register.php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/email.php';

$db = new Database();
$conn = $db->connect();

$erro = '';
$sucesso = '';
$telefone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = (string)($_POST['nome'] ?? '');
    $email = (string)($_POST['email'] ?? '');
    $senha = (string)($_POST['senha'] ?? '');
  $telefone = (string)($_POST['telefone'] ?? '');

  [$ok, $msg] = cadastrarContaPublica($conn, $nome, $email, $senha, 'comprador', $telefone, true);

    if ($ok) {
        // login automático após cadastro
        [$okLogin, $msgLogin] = autenticarConta($conn, $email, $senha);

        if ($okLogin) {
          // Send welcome + verification email (FIRST email on registration)
          try {
              $uid = (int)($_SESSION['user_id'] ?? 0);
              if ($uid > 0) {
                  $emailResult = enviarEmailVerificacao($conn, $uid, 'boas_vindas');
                  if ($emailResult !== true) {
                      error_log('[Register] welcome email failed for uid=' . $uid . ': ' . ($emailResult ?: 'unknown error'));
                  }
              } else {
                  error_log('[Register] uid is 0 after login — cannot send welcome email');
              }
          } catch (\Throwable $e) {
              error_log('[Register] welcome email exception: ' . $e->getMessage());
          }

          // Welcome notification (in-app + email via Step 3)
          try {
              $uid = (int)($_SESSION['user_id'] ?? 0);
              if ($uid > 0) {
                  require_once __DIR__ . '/../src/notifications.php';
                  notificationsCreate($conn, $uid, 'sistema', 'Bem-vindo(a) ao ' . APP_NAME . '!', 'Sua conta foi criada com sucesso. Verifique seu e-mail para ativar sua conta.', BASE_PATH . '/minha-conta', ['skip_email' => true]);
              }
          } catch (\Throwable $e) {
              error_log('[Register] welcome notification error: ' . $e->getMessage());
          }

          header('Location: ' . BASE_PATH . '/dashboard');
          exit;
        }

        $erro = 'Conta criada, mas não foi possível iniciar sessão automaticamente.';
    } else {
        $erro = $msg;
    }
}

$pageTitle = 'Criar conta';
include __DIR__ . '/../views/partials/header.php';

// Check if Google OAuth is configured
require_once __DIR__ . '/../src/google_auth.php';
$googleConfigured = googleIsConfigured($conn);
$googleError = isset($_GET['google_error']) ? htmlspecialchars((string)$_GET['google_error']) : '';
?>
<style>
  @keyframes blob{0%,100%{transform:translate(0,0) scale(1)}25%{transform:translate(30px,-50px) scale(1.1)}50%{transform:translate(-20px,20px) scale(0.9)}75%{transform:translate(20px,40px) scale(1.05)}}
  .auth-blob{position:absolute;border-radius:50%;filter:blur(80px);opacity:.15;animation:blob 20s ease-in-out infinite}
  .auth-input{transition:all .3s cubic-bezier(.4,0,.2,1)}
  @media (max-width:767px){.auth-input{font-size:16px!important}}
  .auth-input:focus{box-shadow:0 0 0 3px rgba(var(--t-accent-rgb),.15);border-color:var(--t-accent)!important;transform:translateY(-1px)}
  .auth-form.was-submitted .auth-input:invalid{border-color:#ef4444!important;background:rgba(239,68,68,.08)!important}
  .auth-form.was-submitted .auth-input:invalid ~ .auth-icon{color:#f87171!important}
  .auth-form.was-submitted .auth-input:invalid + .required-hint,
  .auth-form.was-submitted .relative:has(.auth-input:invalid) + .required-hint{display:flex!important}
  .auth-icon{position:absolute;left:0.875rem;top:50%;transform:translateY(-50%);width:1rem;height:1rem;color:#71717a;pointer-events:none;z-index:10;transition:color .3s}
  .auth-input:focus ~ .auth-icon, .relative:focus-within .auth-icon{color:var(--t-accent)}
  .auth-btn{position:relative;overflow:hidden;transition:all .3s cubic-bezier(.4,0,.2,1)}
  .auth-btn::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);transform:translateX(-100%);transition:transform .5s}
  .auth-btn:hover::before{transform:translateX(100%)}
  .auth-btn:hover{transform:translateY(-1px);box-shadow:0 8px 30px -4px rgba(var(--t-accent-rgb),.4)}
  .auth-card{backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);animation:fadeInUp .6s ease-out both}
  .auth-link{position:relative}.auth-link::after{content:'';position:absolute;bottom:-2px;left:0;width:0;height:2px;background:var(--t-accent);transition:width .3s ease}.auth-link:hover::after{width:100%}
  .tipo-card{transition:all .3s cubic-bezier(.4,0,.2,1);cursor:pointer}
  .tipo-card:hover{transform:translateY(-2px)}
</style>

<div class="min-h-screen relative overflow-hidden flex items-center justify-center px-4 py-8">
  <!-- Animated background blobs -->
  <div class="auth-blob w-[500px] h-[500px] bg-greenx -top-40 -right-40" style="animation-delay:0s"></div>
  <div class="auth-blob w-[400px] h-[400px] bg-greenx bottom-0 -left-32" style="animation-delay:-7s"></div>
  <div class="auth-blob w-[350px] h-[350px] bg-purple-500 top-1/3 left-1/2" style="animation-delay:-14s"></div>

  <!-- Grid overlay -->
  <div class="absolute inset-0 opacity-[0.03]" style="background-image:linear-gradient(rgba(255,255,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.1) 1px,transparent 1px);background-size:60px 60px"></div>

  <div class="w-full max-w-[960px] relative z-10">
    <div class="grid md:grid-cols-2 gap-0 rounded-3xl overflow-hidden shadow-2xl shadow-black/40">

      <!-- Left panel — branding -->
      <div class="auth-left-panel hidden md:flex flex-col justify-between p-10 relative overflow-hidden" style="background:linear-gradient(135deg,rgba(var(--t-accent-rgb),.12),rgba(var(--t-accent-rgb),.03))">
        <div class="absolute inset-0 border-r border-white/[0.06]"></div>
        <div class="relative z-10">
          <a href="<?= BASE_PATH ?>/" class="inline-flex items-center group mb-10" aria-label="Basefy">
            <img src="<?= BASE_PATH ?>/assets/img/logo22.png" alt="Basefy" class="h-10 w-auto object-contain">
          </a>
          <h2 class="text-2xl font-bold text-white leading-tight mb-3">Crie sua conta gratuitamente</h2>
          <p class="text-zinc-400 text-sm leading-relaxed mb-8">Junte-se à nossa plataforma. Compre e venda com uma única conta e WhatsApp confirmado.</p>
          <div class="space-y-5">
            <div class="flex items-start gap-3">
              <div class="w-8 h-8 rounded-lg bg-greenx/15 flex items-center justify-center flex-shrink-0 mt-0.5">
                <span class="text-greenx font-bold text-sm">1</span>
              </div>
              <div>
                <p class="text-sm font-semibold text-white">Crie sua conta</p>
                <p class="text-xs text-zinc-500 mt-0.5">Rápido — e-mail, WhatsApp e senha</p>
              </div>
            </div>
            <div class="flex items-start gap-3">
              <div class="w-8 h-8 rounded-lg bg-greenx/15 flex items-center justify-center flex-shrink-0 mt-0.5">
                <span class="text-greenx font-bold text-sm">2</span>
              </div>
              <div>
                <p class="text-sm font-semibold text-white">Compre e venda</p>
                <p class="text-xs text-zinc-500 mt-0.5">Uma conta para tudo</p>
              </div>
            </div>
            <div class="flex items-start gap-3">
              <div class="w-8 h-8 rounded-lg bg-greenx/15 flex items-center justify-center flex-shrink-0 mt-0.5">
                <span class="text-greenx font-bold text-sm">3</span>
              </div>
              <div>
                <p class="text-sm font-semibold text-white">Receba com segurança</p>
                <p class="text-xs text-zinc-500 mt-0.5">Verifique sua conta para sacar</p>
              </div>
            </div>
          </div>
        </div>
        <p class="text-xs text-zinc-600 relative z-10 mt-8">&copy; <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?>. Todos os direitos reservados.</p>
      </div>

      <!-- Right panel — form -->
      <div class="auth-card bg-blackx2/80 border border-white/[0.06] p-8 md:p-10 flex flex-col justify-center relative">
        <!-- Mobile logo -->
        <div class="flex md:hidden items-center mb-8">
          <img src="<?= BASE_PATH ?>/assets/img/logo22.png" alt="Basefy" class="h-9 w-auto object-contain">
        </div>

        <div class="mb-6">
          <a href="javascript:void(0)" onclick="window.history.length>1?window.history.back():window.location='<?= BASE_PATH ?>/'"
             class="inline-flex items-center gap-1.5 text-sm text-zinc-500 hover:text-greenx transition-colors mb-3">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Voltar
          </a>
          <h1 class="text-2xl font-bold text-white">Criar conta</h1>
          <p class="text-sm text-zinc-500 mt-1">WhatsApp com DDD é obrigatório para avisos de compra, venda e segurança.</p>
        </div>

        <?php if ($erro): ?>
        <div class="mb-5 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm flex items-center gap-2.5 animate-fade-in">
          <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0"></i>
          <?= htmlspecialchars($erro) ?>
        </div>
        <?php endif; ?>

        <?php if ($googleError): ?>
        <div class="mb-5 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm flex items-center gap-2.5 animate-fade-in">
          <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0"></i>
          <?= $googleError ?>
        </div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
        <div class="mb-5 rounded-xl bg-greenx/10 border border-greenx/30 text-greenx px-4 py-3 text-sm flex items-center gap-2.5 animate-fade-in">
          <i data-lucide="check-circle" class="w-4 h-4 flex-shrink-0"></i>
          <?= htmlspecialchars($sucesso) ?>
        </div>
        <?php endif; ?>

        <form method="post" class="auth-form space-y-4" data-required-form>
          <div>
            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wider">Nome <span class="text-zinc-600">(opcional)</span></label>
            <div class="relative">
              <i data-lucide="user" class="auth-icon"></i>
              <input type="text" name="nome" placeholder="Seu nome"
                     class="auth-input w-full rounded-xl bg-white/[0.04] border border-white/[0.08] pl-11 pr-4 py-3 outline-none text-white placeholder:text-zinc-600 text-sm">
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-zinc-400 mb-1.5 uppercase tracking-wider">E-mail</label>
            <div class="relative">
              <i data-lucide="mail" class="auth-icon"></i>
              <input type="email" name="email" placeholder="seu@email.com" required value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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
            Criar minha conta
          </button>
        </form>

        <?php if ($googleConfigured): ?>
        <div class="relative my-6">
          <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-white/[0.06]"></div></div>
          <div class="relative flex justify-center text-xs"><span class="px-4 bg-blackx2/80 text-zinc-500 font-medium">ou crie com Google</span></div>
        </div>

        <a href="<?= BASE_PATH ?>/google_redirect?mode=register"
           class="flex items-center justify-center gap-3 w-full rounded-xl border border-white/[0.1] bg-white/[0.03] hover:bg-white/[0.07] text-white font-medium py-3 transition-all hover:border-white/[0.2] hover:shadow-lg group">
          <svg width="20" height="20" viewBox="0 0 48 48" class="transition-transform group-hover:scale-110"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
          Criar conta com Google
        </a>
        <?php endif; ?>

        <p class="text-sm text-zinc-500 mt-6 text-center">
          Já possui conta?
          <a href="login" class="auth-link text-greenx font-semibold hover:text-greenx2 transition-colors">Entrar</a>
        </p>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('[data-required-form]').forEach(function(form){
  form.addEventListener('submit', function(){ form.classList.add('was-submitted'); });
  form.querySelectorAll('[required]').forEach(function(field){
    field.addEventListener('invalid', function(){ form.classList.add('was-submitted'); }, true);
    field.addEventListener('input', function(){ if (field.checkValidity()) field.classList.remove('field-error'); });
  });
});
</script>

<?php include __DIR__ . '/../views/partials/footer.php'; ?>