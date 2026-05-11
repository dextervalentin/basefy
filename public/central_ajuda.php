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
                    <p class="text-sm text-zinc-400 mb-4">Canais oficiais da Basefy para contato rápido.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <a href="https://www.instagram.com/basefy.io" target="_blank" rel="noopener noreferrer" class="group/social flex items-center gap-3 rounded-xl border border-white/[0.06] bg-white/[0.03] px-4 py-3 hover:border-greenx/30 hover:bg-greenx/[0.04] transition-all" title="Instagram da Basefy" aria-label="Instagram da Basefy">
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-white/[0.08] bg-[#E4405F]/10 text-[#E4405F]">
                                <span class="h-5 w-5 bg-current" style="-webkit-mask:url('https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/instagram.svg') center/contain no-repeat;mask:url('https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/instagram.svg') center/contain no-repeat;"></span>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-zinc-200 group-hover/social:text-white transition-colors">Instagram</span>
                                <span class="block text-xs text-zinc-500 truncate">@basefy.io</span>
                            </span>
                        </a>
                        <a href="https://wa.me/554796709178" target="_blank" rel="noopener noreferrer" class="group/social flex items-center gap-3 rounded-xl border border-white/[0.06] bg-white/[0.03] px-4 py-3 hover:border-greenx/30 hover:bg-greenx/[0.04] transition-all" title="WhatsApp da Basefy" aria-label="WhatsApp da Basefy">
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-white/[0.08] bg-[#25D366]/10 text-[#25D366]">
                                <span class="h-5 w-5 bg-current" style="-webkit-mask:url('https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/whatsapp.svg') center/contain no-repeat;mask:url('https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/whatsapp.svg') center/contain no-repeat;"></span>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-zinc-200 group-hover/social:text-white transition-colors">WhatsApp</span>
                                <span class="block text-xs text-zinc-500 truncate">Atendimento comercial</span>
                            </span>
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
