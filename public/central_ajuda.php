<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/storefront.php';

$userId     = (int)($_SESSION['user_id'] ?? 0);
$isLoggedIn = $userId > 0;
$conn       = (new Database())->connect();
$cartCount  = sfCartCount();

$currentPage = 'central_ajuda';
$pageTitle   = 'Central de Ajuda';

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<div class="min-h-screen bg-blackx">
    <!-- Breadcrumb -->
    <div class="max-w-5xl mx-auto px-4 sm:px-6 pt-6">
        <nav class="flex items-center gap-2 text-sm text-zinc-500 animate-fade-in">
            <a href="/" class="hover:text-greenx transition-colors">Início</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <span class="text-zinc-300">Central de Ajuda</span>
        </nav>
    </div>

    <!-- Hero -->
    <section class="max-w-5xl mx-auto px-4 sm:px-6 pt-8 pb-10 text-center animate-fade-in-up">
        <h1 class="text-3xl md:text-4xl font-black mb-4">Central de Ajuda</h1>
        <p class="text-zinc-400 max-w-2xl mx-auto leading-relaxed">
            Nós acreditamos que todo o suporte e atenção aos nossos usuários é importante.<br>
            Separamos abaixo alguns dos nossos meios de contato para que você possa fazer compras e vendas com toda a segurança!
        </p>
    </section>

    <!-- Cards Grid -->
    <section class="max-w-5xl mx-auto px-4 sm:px-6 pb-16">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- Perguntas Frequentes -->
            <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-6 hover:border-greenx/30 transition-all group">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
                        <i data-lucide="help-circle" class="w-5 h-5 text-purple-400"></i>
                    </div>
                    <h2 class="text-lg font-bold">Perguntas frequentes</h2>
                </div>
                <p class="text-sm text-zinc-400 leading-relaxed mb-5">
                    Lista de respostas para as dúvidas mais frequentes que os nossos usuários costumam ter. Antes de usar os outros meios de suporte, verifique se a sua dúvida já não está respondida aqui!
                </p>
                <a href="<?= BASE_PATH ?>/faq"
                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-greenx hover:bg-greenx2 text-white text-sm font-bold transition-all shadow-lg shadow-greenx/20">
                    Ver FAQ's
                </a>
            </div>

            <!-- Tickets de Suporte -->
            <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-6 hover:border-greenx/30 transition-all group">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center" style="border-color:rgba(248,113,113,0.3)">
                        <i data-lucide="mail-check" class="w-5 h-5 text-red-400"></i>
                    </div>
                    <h2 class="text-lg font-bold">Tickets de suporte</h2>
                </div>
                <p class="text-sm text-zinc-400 leading-relaxed mb-5">
                    Problema com alguma compra? Precisa de suporte técnico? Problema com o site? Nossa equipe de suporte está sempre pronto para responder as suas dúvidas no nosso suporte via ticket.
                </p>
                <a href="<?= BASE_PATH ?>/tickets"
                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-red-600 hover:bg-red-500 text-white text-sm font-bold transition-all shadow-lg shadow-red-600/20">
                    Ir para Tickets
                </a>
            </div>

            <!-- Documentos Legais -->
            <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-6 hover:border-greenx/30 transition-all group">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-greenx/10 border border-greenx/20 flex items-center justify-center">
                        <i data-lucide="scale" class="w-5 h-5 text-greenx"></i>
                    </div>
                    <h2 class="text-lg font-bold">Documentos e Políticas</h2>
                </div>
                <p class="text-sm text-zinc-400 mb-4">Conheça nossos termos, políticas e diretrizes.</p>
                <div class="space-y-3">
                    <a href="<?= BASE_PATH ?>/termos" class="flex items-center gap-3 p-3 rounded-xl border border-white/[0.06] hover:border-greenx/30 hover:bg-greenx/[0.03] transition-all group/link">
                        <div class="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="file-text" class="w-4 h-4 text-purple-400"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold group-hover/link:text-greenx transition-colors">Termos de Uso</p>
                            <p class="text-xs text-zinc-600">Condições gerais de utilização da plataforma</p>
                        </div>
                        <i data-lucide="chevron-right" class="w-4 h-4 text-zinc-600 group-hover/link:text-greenx transition-colors"></i>
                    </a>
                    <a href="<?= BASE_PATH ?>/privacidade" class="flex items-center gap-3 p-3 rounded-xl border border-white/[0.06] hover:border-greenx/30 hover:bg-greenx/[0.03] transition-all group/link">
                        <div class="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="shield" class="w-4 h-4 text-purple-400"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold group-hover/link:text-greenx transition-colors">Política de Privacidade</p>
                            <p class="text-xs text-zinc-600">Como coletamos e protegemos seus dados</p>
                        </div>
                        <i data-lucide="chevron-right" class="w-4 h-4 text-zinc-600 group-hover/link:text-greenx transition-colors"></i>
                    </a>
                    <a href="<?= BASE_PATH ?>/reembolso" class="flex items-center gap-3 p-3 rounded-xl border border-white/[0.06] hover:border-greenx/30 hover:bg-greenx/[0.03] transition-all group/link">
                        <div class="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="rotate-ccw" class="w-4 h-4 text-amber-400"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold group-hover/link:text-greenx transition-colors">Política de Reembolso</p>
                            <p class="text-xs text-zinc-600">Regras e prazos para solicitar reembolso</p>
                        </div>
                        <i data-lucide="chevron-right" class="w-4 h-4 text-zinc-600 group-hover/link:text-greenx transition-colors"></i>
                    </a>
                    <a href="<?= BASE_PATH ?>/como_funciona" class="flex items-center gap-3 p-3 rounded-xl border border-white/[0.06] hover:border-greenx/30 hover:bg-greenx/[0.03] transition-all group/link">
                        <div class="w-8 h-8 rounded-lg bg-greenx/10 border border-greenx/20 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="info" class="w-4 h-4 text-greenx"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold group-hover/link:text-greenx transition-colors">Como Funciona</p>
                            <p class="text-xs text-zinc-600">Guia completo da plataforma</p>
                        </div>
                        <i data-lucide="chevron-right" class="w-4 h-4 text-zinc-600 group-hover/link:text-greenx transition-colors"></i>
                    </a>
                </div>
            </div>

            <!-- Sociais + Fale Conosco -->
            <div class="space-y-6">
                <!-- Sociais -->
                <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-6 hover:border-greenx/30 transition-all group">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
                            <i data-lucide="users" class="w-5 h-5 text-purple-400"></i>
                        </div>
                        <h2 class="text-lg font-bold">Sociais</h2>
                    </div>
                    <p class="text-sm text-zinc-400 mb-4">Nossas redes sociais.</p>
                    <div class="flex items-center gap-4">
                        <a href="#" class="w-10 h-10 rounded-full bg-white/[0.06] border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-greenx hover:border-greenx/30 transition-all" title="Discord">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M13.545 2.907a13.2 13.2 0 0 0-3.257-1.011.05.05 0 0 0-.052.025c-.141.25-.297.577-.406.833a12.2 12.2 0 0 0-3.658 0 8 8 0 0 0-.412-.833.05.05 0 0 0-.052-.025c-1.125.194-2.22.534-3.257 1.011a.04.04 0 0 0-.021.018C.356 6.024-.213 9.047.066 12.032q.003.022.021.037a13.3 13.3 0 0 0 3.995 2.02.05.05 0 0 0 .056-.019q.463-.63.818-1.329a.05.05 0 0 0-.01-.059l-.018-.011a9 9 0 0 1-1.248-.595.05.05 0 0 1-.02-.066l.015-.019q.127-.095.248-.195a.05.05 0 0 1 .051-.007c2.619 1.196 5.454 1.196 8.041 0a.05.05 0 0 1 .053.007q.121.1.248.195a.05.05 0 0 1-.004.085 8 8 0 0 1-1.249.594.05.05 0 0 0-.03.03.05.05 0 0 0 .003.041c.24.465.515.909.817 1.329a.05.05 0 0 0 .056.019 13.2 13.2 0 0 0 4.001-2.02.05.05 0 0 0 .021-.037c.334-3.451-.559-6.449-2.366-9.106a.03.03 0 0 0-.02-.019"/></svg>
                        </a>
                        <a href="#" class="w-10 h-10 rounded-full bg-white/[0.06] border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-greenx hover:border-greenx/30 transition-all" title="YouTube">
                            <i data-lucide="youtube" class="w-5 h-5"></i>
                        </a>
                        <a href="#" class="w-10 h-10 rounded-full bg-white/[0.06] border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-greenx hover:border-greenx/30 transition-all" title="Facebook">
                            <i data-lucide="facebook" class="w-5 h-5"></i>
                        </a>
                        <a href="#" class="w-10 h-10 rounded-full bg-white/[0.06] border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-greenx hover:border-greenx/30 transition-all" title="Instagram">
                            <i data-lucide="instagram" class="w-5 h-5"></i>
                        </a>
                    </div>
                </div>

                <!-- Fale Conosco -->
                <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-6 hover:border-greenx/30 transition-all group">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-yellow-500/10 border border-yellow-500/20 flex items-center justify-center">
                            <i data-lucide="headphones" class="w-5 h-5 text-yellow-400"></i>
                        </div>
                        <h2 class="text-lg font-bold">Fale conosco</h2>
                    </div>
                    <p class="text-sm text-zinc-400 mb-2">
                        E-mail comercial para assuntos não relacionados ao suporte:<br>
                        <a href="mailto:contato@basefy.io" class="text-greenx hover:underline font-medium">contato@basefy.io</a>
                    </p>
                    <p class="text-xs text-zinc-500 bg-yellow-500/5 border border-yellow-500/10 rounded-lg px-3 py-2 mt-3">
                        E-mail exclusivo para tratativas comerciais, parcerias e semelhantes. Assuntos relacionados a suporte <strong>não</strong> serão respondidos.
                    </p>
                </div>
            </div>

        </div>
    </section>
</div>

<?php
include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
?>
