<?php
declare(strict_types=1);
$_ftLoggedIn = !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
?>
<footer class="border-t border-white/[0.06] bg-blackx mt-16">
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 py-12">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-8">
            <!-- Brand / Sobre -->
            <div class="col-span-2 sm:col-span-2 lg:col-span-1 space-y-4">
                <img src="<?= BASE_PATH ?>/assets/img/logo22.png" alt="Basefy" class="h-8 w-auto object-contain">
                <p class="text-sm text-zinc-500 leading-relaxed">
                    Marketplace digital com pagamento via PIX, carteira integrada e moderação segura.
                </p>
                <div class="flex items-center gap-2">
                    <a href="https://www.instagram.com/basefy.io" target="_blank" rel="noopener noreferrer" aria-label="Instagram da Basefy" title="Instagram"
                       class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/[0.08] bg-white/[0.03] text-zinc-400 hover:border-greenx/40 hover:text-greenx hover:bg-greenx/[0.06] transition-all">
                        <span class="h-4 w-4 bg-current" style="-webkit-mask:url('https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/instagram.svg') center/contain no-repeat;mask:url('https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/instagram.svg') center/contain no-repeat;"></span>
                    </a>
                    <a href="https://wa.me/554796709178" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp da Basefy" title="WhatsApp"
                       class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/[0.08] bg-white/[0.03] text-zinc-400 hover:border-greenx/40 hover:text-greenx hover:bg-greenx/[0.06] transition-all">
                        <span class="h-4 w-4 bg-current" style="-webkit-mask:url('https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/whatsapp.svg') center/contain no-repeat;mask:url('https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/whatsapp.svg') center/contain no-repeat;"></span>
                    </a>
                </div>
            </div>

            <!-- Acesso Rápido -->
            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-4">Acesso Rápido</h4>
                <ul class="space-y-2.5">
                    <li><a href="<?= BASE_PATH ?>/" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Início</a></li>
                    <li><a href="<?= BASE_PATH ?>/categorias" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Catálogo</a></li>
                    <li><a href="<?= BASE_PATH ?>/carrinho" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Carrinho</a></li>
                    <li><a href="<?= BASE_PATH ?>/blog" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Blog</a></li>
                    <li><a href="<?= BASE_PATH ?>/como_funciona" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Como Funciona</a></li>
                </ul>
            </div>

            <!-- Minha Conta (login-aware) -->
            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-4">Minha Conta</h4>
                <ul class="space-y-2.5">
                    <?php if ($_ftLoggedIn): ?>
                    <li><a href="<?= BASE_PATH ?>/minha_conta" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Minha conta</a></li>
                    <li><a href="<?= BASE_PATH ?>/meus_pedidos" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Meus pedidos</a></li>
                    <li><a href="<?= BASE_PATH ?>/wallet" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Carteira</a></li>
                    <li><a href="<?= BASE_PATH ?>/afiliados" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Afiliados</a></li>
                    <?php else: ?>
                    <li><a href="<?= BASE_PATH ?>/login" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Entrar</a></li>
                    <li><a href="<?= BASE_PATH ?>/register" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Criar conta</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Suporte -->
            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-4">Suporte</h4>
                <ul class="space-y-2.5">
                    <li><a href="<?= BASE_PATH ?>/central_ajuda" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Central de Ajuda</a></li>
                    <li><a href="<?= BASE_PATH ?>/faq" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Perguntas Frequentes</a></li>
                    <li><a href="<?= BASE_PATH ?>/tickets" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Tickets de Suporte</a></li>
                </ul>
            </div>

            <!-- Legal -->
            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-4">Legal</h4>
                <ul class="space-y-2.5">
                    <li><a href="<?= BASE_PATH ?>/termos" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Termos de Uso</a></li>
                    <li><a href="<?= BASE_PATH ?>/privacidade" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Privacidade</a></li>
                    <li><a href="<?= BASE_PATH ?>/reembolso" class="text-sm text-zinc-500 hover:text-greenx transition-colors">Reembolso</a></li>
                </ul>
            </div>
        </div>

        <div class="mt-10 pt-6 border-t border-white/[0.06] flex items-center justify-center">
            <p class="text-xs text-zinc-600">&copy; <?= date('Y') ?> Basefy. Todos os direitos reservados.</p>
        </div>
    </div>
</footer>

<?php
// Floating chat widget — shows on storefront for any logged-in user
if (session_status() === PHP_SESSION_ACTIVE) {
    $_sfVUid = (int)($_SESSION['user_id'] ?? 0);
    if ($_sfVUid > 0) {
        include __DIR__ . '/chat_widget_vendor.php';
    }
}
?>
