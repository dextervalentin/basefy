<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Catch fatal errors that bypass try/catch (OOM, segfault, exit, etc.)
register_shutdown_function(static function(): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[produto.php SHUTDOWN] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Erro Fatal</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">';
        echo '<script src="https://cdn.tailwindcss.com"></script></head>';
        echo '<body class="min-h-screen bg-[#121316] text-white font-[Inter,sans-serif] flex items-center justify-center px-4">';
        echo '<div class="text-center max-w-md"><h1 class="text-2xl font-bold mb-2">Erro Fatal</h1>';
        echo '<p class="text-zinc-400 mb-2">Ocorreu um erro inesperado.</p>';
        echo '<p class="text-red-400 text-xs mb-4">' . htmlspecialchars($err['message']) . '</p>';
        echo '<a href="/" class="inline-block px-6 py-3 rounded-xl bg-greenx text-white font-bold text-sm hover:bg-greenx2 transition-all">Voltar ao início</a>';
        echo '</div></body></html>';
    }
});

ob_start();
try {

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/storefront.php';
require_once __DIR__ . '/../src/affiliates.php';
require_once __DIR__ . '/../src/reviews.php';
require_once __DIR__ . '/../src/questions.php';
require_once __DIR__ . '/../src/media.php';

$userId     = (int)($_SESSION['user_id'] ?? 0);
$userRole   = (string)($_SESSION['user']['role'] ?? 'usuario');
$isLoggedIn = $userId > 0;

$conn = (new Database())->connect();

// Fetch current user's avatar for real-time Q&A display
$_currentUserAvatar = '';
$_currentUserNome   = (string)($_SESSION['user']['nome'] ?? $_SESSION['user']['name'] ?? 'Você');
if ($isLoggedIn) {
    try {
        $_uStmt = $conn->prepare("SELECT avatar, nome FROM users WHERE id = ? LIMIT 1");
        $_uStmt->bind_param('i', $userId);
        $_uStmt->execute();
        $_uRow = $_uStmt->get_result()->fetch_assoc();
        $_uStmt->close();
        if ($_uRow) {
            $_currentUserAvatar = mediaResolveUrl(trim((string)($_uRow['avatar'] ?? '')));
            $_currentUserNome = (string)($_uRow['nome'] ?? $_currentUserNome);
        }
    } catch (\Throwable $e) {}
}

// Affiliate referral tracking
affHandleReferral($conn);

// Support both ?id=X and slug-based routes (/p/slug-name)
$slug      = trim((string)($_GET['slug'] ?? ''));
$productId = (int)($_GET['id'] ?? 0);

if ($slug !== '') {
    $produto = sfGetProductBySlug($conn, $slug);
} else {
    $produto = sfGetProductById($conn, $productId);
}

if (!$produto) {
    header('Location: /categorias');
    exit;
}

$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'add_cart') {
    $qty = max(1, min(99, (int)($_POST['qty'] ?? 1)));
    // For dynamic products, require a variant selection
    $varianteSelected = trim((string)($_POST['variante'] ?? ''));
    if (($produto['tipo'] ?? 'produto') === 'dinamico' && $varianteSelected === '') {
        $feedback = 'Selecione uma variante antes de comprar.';
    } else {
        sfCartAdd((int)$produto['id'], $qty, $varianteSelected !== '' ? $varianteSelected : null);
        header('Location: ' . BASE_PATH . '/carrinho');
        exit;
    }
}

$relacionados = sfListProducts($conn, ['category_id' => (int)($produto['categoria_id'] ?? 0), 'limit' => 6]);
$relacionados = array_values(array_filter($relacionados, static fn(array $p): bool => (int)$p['id'] !== (int)$produto['id']));
$relacionados = array_slice($relacionados, 0, 5);

$vendorId    = (int)($produto['vendedor_id'] ?? 0);
$vendorName  = (string)($produto['vendedor_nome'] ?? 'Marketplace');
$vendorVerification = sfUserVerificationStatuses($conn, $vendorId);
$vendorKycVerified = sfUserKycVerified($conn, $vendorId);

// Vendor profile data (avatar)
$vendorAvatar = '';
try {
    $vendorProfile = sfGetVendorProfile($conn, $vendorId);
    if ($vendorProfile) {
        $vendorAvatar = sfAvatarUrl($vendorProfile['avatar'] ?? null);
        $vendorKycVerified = !empty($vendorProfile['kyc_verificado']);
    }
} catch (\Throwable $e) {}
if ($vendorAvatar === '') {
    $vendorAvatar = 'https://placehold.co/200x200/111827/9ca3af?text=' . urlencode(mb_strtoupper(mb_substr($vendorName, 0, 1)));
}

// Reviews data (graceful fallback if table missing)
try {
    reviewEnsureTable($conn);
    $reviewAgg = reviewAggregate($conn, (int)$produto['id']);
    $reviewData = reviewListByProduct($conn, (int)$produto['id'], 5);
    $reviews    = $reviewData['rows'];
    $reviewsTotal = $reviewData['total'];
    $vendorAgg = reviewVendorAggregate($conn, $vendorId);
    $canReview = $isLoggedIn ? reviewCanUserReview($conn, $userId, (int)$produto['id']) : ['can' => false, 'reason' => 'Faça login para avaliar.'];
} catch (\Throwable $e) {
    // Try once more after ensuring table
    try {
        reviewEnsureTable($conn);
        $reviewAgg = reviewAggregate($conn, (int)$produto['id']);
        $reviewData = reviewListByProduct($conn, (int)$produto['id'], 5);
        $reviews    = $reviewData['rows'];
        $reviewsTotal = $reviewData['total'];
        $vendorAgg = reviewVendorAggregate($conn, $vendorId);
        $canReview = $isLoggedIn ? reviewCanUserReview($conn, $userId, (int)$produto['id']) : ['can' => false, 'reason' => 'Faça login para avaliar.'];
    } catch (\Throwable $e2) {
        $reviewAgg = ['total' => 0, 'avg_rating' => 0, 'breakdown' => [5=>0,4=>0,3=>0,2=>0,1=>0]];
        $reviews   = [];
        $reviewsTotal = 0;
        $vendorAgg = ['total' => 0, 'avg_rating' => 0];
        $canReview = ['can' => false, 'reason' => 'Sistema de avaliações indisponível no momento.'];
    }
}

// Questions data
$questions     = [];
$questionsTotal = 0;
try {
    $questions      = questionsListByProduct($conn, (int)$produto['id'], 5, 0);
    $questionsTotal = questionsCountByProduct($conn, (int)$produto['id']);
} catch (\Throwable $e) {}

$cartCount   = sfCartCount();
$currentPage = '';
$pageTitle   = (string)$produto['nome'] . ' — Basefy';
include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/storefront_nav.php';
?>

<div class="min-h-screen bg-blackx">
    <?php if ($feedback !== ''): ?>
    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 pt-4 animate-scale-in">
        <div class="flex items-center gap-3 rounded-2xl border border-greenx/30 bg-greenx/[0.06] px-5 py-3.5">
            <div class="w-8 h-8 rounded-full bg-greenx/20 flex items-center justify-center flex-shrink-0"><i data-lucide="check" class="w-4 h-4 text-greenx"></i></div>
            <p class="text-sm text-greenx"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></p>
            <a href="/carrinho" class="ml-auto text-xs text-greenx hover:underline font-medium">Ver carrinho &rarr;</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Breadcrumb -->
    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 pt-5 pb-4">
        <nav class="flex items-center gap-2 text-sm text-zinc-500 animate-fade-in">
            <a href="/" class="hover:text-greenx transition-colors">Início</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <?php if ((int)($produto['categoria_id'] ?? 0) > 0): ?>
            <a href="/categorias?categoria_id=<?= (int)$produto['categoria_id'] ?>" class="hover:text-greenx transition-colors">
                <?= htmlspecialchars((string)($produto['categoria_nome'] ?? 'Categoria'), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            <?php endif; ?>
            <span class="text-zinc-300 truncate max-w-[300px]"><?= htmlspecialchars((string)$produto['nome'], ENT_QUOTES, 'UTF-8') ?></span>
        </nav>
    </div>

    <!-- ========== PRODUCT DETAIL — GGMax 3-column layout ========== -->
    <main class="max-w-[1600px] mx-auto px-4 sm:px-6 pb-12">

        <div class="grid grid-cols-1 lg:grid-cols-[340px_1fr_320px] xl:grid-cols-[400px_1fr_340px] gap-6 animate-fade-in-up">

            <!-- ── LEFT: Product Image + Gallery ── -->
            <div class="lg:sticky lg:top-24 self-start space-y-3">
                <?php
                // Build gallery array: cover first, then gallery images
                $galleryImages = [];
                $coverRaw = trim((string)($produto['imagem'] ?? ''));
                $coverUrl = $coverRaw !== '' ? sfImageUrl($coverRaw) : '';
                if ($coverUrl !== '') {
                    $galleryImages[] = $coverUrl;
                }
                if (!empty($produto['gallery']) && is_array($produto['gallery'])) {
                    foreach ($produto['gallery'] as $gImg) {
                        $gUrl = !empty($gImg['id']) ? mediaUrl((int)$gImg['id']) : '';
                        if ($gUrl !== '' && $gUrl !== $coverUrl) {
                            $galleryImages[] = $gUrl;
                        }
                    }
                }
                $totalImages = count($galleryImages);
                ?>
                <!-- Main Image (swipe/drag enabled) -->
                <div class="rounded-2xl overflow-hidden border border-white/[0.06] bg-blackx2 relative" id="mainImageWrap">
                    <div class="aspect-square overflow-hidden relative group cursor-pointer" id="mainImageContainer"
                         <?= $totalImages > 0 ? 'onclick="openLightbox(galleryIdx)"' : '' ?>>
                        <?php if ($totalImages > 0): ?>
                        <img id="mainImage" src="<?= htmlspecialchars($galleryImages[0], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars((string)$produto['nome'], ENT_QUOTES, 'UTF-8') ?>"
                             class="w-full h-full object-cover transition-transform duration-300 select-none" draggable="false">
                        <?php else: ?>
                        <div class="w-full h-full flex flex-col items-center justify-center gap-3 bg-white/[0.02] text-zinc-500">
                            <i data-lucide="image-off" class="w-12 h-12 text-zinc-600"></i>
                            <span class="text-sm font-semibold">Produto sem imagem</span>
                        </div>
                        <?php endif; ?>
                        <!-- Category badge -->
                        <span class="absolute top-3 left-3 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wide bg-greenx/90 text-white pointer-events-none">
                            <?= htmlspecialchars((string)($produto['categoria_nome'] ?? 'Geral'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <!-- Expand icon -->
                        <div class="absolute top-3 right-3 w-8 h-8 rounded-lg bg-black/50 backdrop-blur flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                            <i data-lucide="expand" class="w-4 h-4 text-white"></i>
                        </div>
                        <?php if ($totalImages > 1): ?>
                        <!-- Image counter -->
                        <span class="absolute bottom-3 right-3 px-2.5 py-1 rounded-lg bg-black/60 backdrop-blur text-[11px] font-semibold text-white pointer-events-none" id="imgCounter">1 / <?= $totalImages ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($totalImages > 1): ?>
                <!-- Thumbnails: 3 visible, arrows left/right -->
                <div class="relative flex items-center gap-2">
                    <button onclick="galleryThumbScroll(-1)" class="flex-shrink-0 w-7 h-7 rounded-full bg-blackx2 border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-white hover:border-greenx/40 transition-all">
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </button>
                    <div id="galleryTrack" class="flex gap-2 overflow-hidden flex-1 justify-center">
                        <?php foreach ($galleryImages as $gi => $gUrl): ?>
                        <button onclick="gallerySelect(<?= $gi ?>)" data-gidx="<?= $gi ?>"
                                class="gallery-thumb flex-shrink-0 rounded-xl overflow-hidden border-2 transition-all duration-200 <?= $gi === 0 ? 'border-greenx w-[76px] h-[76px] ring-2 ring-greenx/30' : 'border-white/[0.08] w-[68px] h-[68px] hover:border-greenx/50' ?>">
                            <img src="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>"
                                 alt="Foto <?= $gi + 1 ?>"
                                 class="w-full h-full object-cover" draggable="false">
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <button onclick="galleryThumbScroll(1)" class="flex-shrink-0 w-7 h-7 rounded-full bg-blackx2 border border-white/[0.08] flex items-center justify-center text-zinc-400 hover:text-white hover:border-greenx/40 transition-all">
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Lightbox Modal -->
                <div id="lightboxModal" onclick="if(event.target===this)closeLightbox()"
                     style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:99999;background:rgba(0,0,0,0.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);align-items:center;justify-content:center;flex-direction:column">
                    <!-- X close -->
                    <button onclick="closeLightbox()" style="position:absolute;top:16px;right:16px;width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.25);display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;z-index:10;transition:all .2s" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                    <!-- Left arrow -->
                    <button onclick="lightboxNav(-1)" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.25);display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;z-index:10;transition:all .2s" onmouseover="this.style.background='var(--t-accent,#8800E4)';this.style.color='#000'" onmouseout="this.style.background='rgba(255,255,255,0.12)';this.style.color='#fff'">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <!-- Right arrow -->
                    <button onclick="lightboxNav(1)" style="position:absolute;right:16px;top:50%;transform:translateY(-50%);width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.25);display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;z-index:10;transition:all .2s" onmouseover="this.style.background='var(--t-accent,#8800E4)';this.style.color='#000'" onmouseout="this.style.background='rgba(255,255,255,0.12)';this.style.color='#fff'">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                    <!-- Centered image with box shadow -->
                    <div style="display:flex;align-items:center;justify-content:center;max-width:92vw;max-height:85vh;padding:8px">
                        <img id="lightboxImg" src="" alt="" draggable="false"
                             style="max-width:90vw;max-height:82vh;object-fit:contain;border-radius:16px;box-shadow:0 25px 60px rgba(0,0,0,0.6),0 8px 24px rgba(0,0,0,0.4);user-select:none;-webkit-user-select:none">
                    </div>
                    <!-- Counter badge -->
                    <span id="lightboxCounter" style="position:absolute;bottom:20px;left:50%;transform:translateX(-50%);padding:8px 18px;border-radius:12px;background:rgba(0,0,0,0.65);backdrop-filter:blur(6px);font-size:14px;font-weight:600;color:#fff;letter-spacing:0.5px"></span>
                </div>

                <script>
                var galleryUrls = <?= json_encode(array_values($galleryImages)) ?>;
                var galleryIdx = 0;
                var thumbOffset = 0;
                var thumbVisible = 3;

                function gallerySelect(i) {
                    if (i < 0) i = 0;
                    if (i >= galleryUrls.length) i = galleryUrls.length - 1;
                    galleryIdx = i;
                    document.getElementById('mainImage').src = galleryUrls[i];
                    var counter = document.getElementById('imgCounter');
                    if (counter) counter.textContent = (i+1) + ' / ' + galleryUrls.length;
                    // Ensure thumb is visible: active is always the leftmost
                    thumbOffset = i;
                    if (thumbOffset + thumbVisible > galleryUrls.length) {
                        thumbOffset = Math.max(0, galleryUrls.length - thumbVisible);
                    }
                    updateThumbs();
                }

                function updateThumbs() {
                    document.querySelectorAll('.gallery-thumb').forEach(function(btn, idx) {
                        var isActive = idx === galleryIdx;
                        btn.style.width = isActive ? '76px' : '68px';
                        btn.style.height = isActive ? '76px' : '68px';
                        btn.style.borderColor = isActive ? 'var(--t-accent,#8800E4)' : 'rgba(255,255,255,0.08)';
                        btn.style.boxShadow = isActive ? '0 0 0 2px rgba(136,0,228,0.3)' : 'none';
                        btn.style.display = (idx >= thumbOffset && idx < thumbOffset + thumbVisible) ? '' : 'none';
                    });
                }

                function galleryThumbScroll(dir) {
                    // Move the view AND the main image
                    var newIdx = galleryIdx + dir;
                    if (newIdx < 0) newIdx = galleryUrls.length - 1;
                    if (newIdx >= galleryUrls.length) newIdx = 0;
                    gallerySelect(newIdx);
                }

                // Main image swipe/drag
                (function(){
                    var el = document.getElementById('mainImageContainer');
                    if (!el || galleryUrls.length <= 1) return;
                    var startX = 0, dragging = false, moved = false;
                    el.addEventListener('mousedown', function(e){ startX = e.clientX; dragging = true; moved = false; e.preventDefault(); });
                    el.addEventListener('touchstart', function(e){ startX = e.touches[0].clientX; dragging = true; moved = false; }, {passive:true});
                    function endDrag(endX) {
                        if (!dragging) return; dragging = false;
                        var dx = endX - startX;
                        if (Math.abs(dx) > 40) {
                            moved = true;
                            if (dx < 0) galleryThumbScroll(1);
                            else galleryThumbScroll(-1);
                        }
                    }
                    el.addEventListener('mouseup', function(e){ endDrag(e.clientX); });
                    el.addEventListener('mouseleave', function(e){ if(dragging) endDrag(e.clientX); });
                    el.addEventListener('touchend', function(e){ endDrag(e.changedTouches[0].clientX); });
                    el.addEventListener('click', function(e){
                        if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; }
                    }, true);
                })();

                // Lightbox
                var lightboxIdx = 0;
                function openLightbox(i) {
                    lightboxIdx = i;
                    var modal = document.getElementById('lightboxModal');
                    document.getElementById('lightboxImg').src = galleryUrls[i];
                    document.getElementById('lightboxCounter').textContent = (i+1) + ' / ' + galleryUrls.length;
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
                function closeLightbox() {
                    var modal = document.getElementById('lightboxModal');
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
                function lightboxNav(dir) {
                    lightboxIdx += dir;
                    if (lightboxIdx < 0) lightboxIdx = galleryUrls.length - 1;
                    if (lightboxIdx >= galleryUrls.length) lightboxIdx = 0;
                    document.getElementById('lightboxImg').src = galleryUrls[lightboxIdx];
                    document.getElementById('lightboxCounter').textContent = (lightboxIdx+1) + ' / ' + galleryUrls.length;
                    // Also update main gallery
                    gallerySelect(lightboxIdx);
                }
                // Lightbox swipe
                (function(){
                    var el = document.getElementById('lightboxModal');
                    if (!el) return;
                    var sx = 0, dragging = false;
                    el.addEventListener('touchstart', function(e){ sx = e.touches[0].clientX; dragging = true; }, {passive:true});
                    el.addEventListener('touchend', function(e){
                        if (!dragging) return; dragging = false;
                        var dx = e.changedTouches[0].clientX - sx;
                        if (Math.abs(dx) > 50) { lightboxNav(dx < 0 ? 1 : -1); }
                    });
                })();
                // Keyboard nav for lightbox
                document.addEventListener('keydown', function(e){
                    var modal = document.getElementById('lightboxModal');
                    if (modal.style.display === 'none' || modal.style.display === '') return;
                    if (e.key === 'Escape') closeLightbox();
                    if (e.key === 'ArrowLeft') lightboxNav(-1);
                    if (e.key === 'ArrowRight') lightboxNav(1);
                });
                // Init thumb visibility
                if (galleryUrls.length > 1) updateThumbs();
                // Move lightbox to <body> to escape sticky/transform stacking contexts
                (function(){ var lb = document.getElementById('lightboxModal'); if(lb) document.body.appendChild(lb); })();
                </script>
            </div>

            <!-- ── CENTER: Product Info ── -->
            <div class="space-y-6">
                <!-- Title + Badge -->
                <div>
                    <h1 class="text-2xl sm:text-3xl font-black leading-tight tracking-tight">
                        <?= htmlspecialchars((string)$produto['nome'], ENT_QUOTES, 'UTF-8') ?>
                    </h1>
                    <?php if ($reviewAgg['total'] > 0): ?>
                    <div class="flex items-center gap-2 mt-2">
                        <div class="flex items-center gap-0.5"><?= reviewStarsHTML($reviewAgg['avg_rating'], 'w-4 h-4') ?></div>
                        <span class="text-sm font-semibold text-zinc-300"><?= $reviewAgg['avg_rating'] ?></span>
                        <a href="#avaliacoes" class="text-sm text-zinc-400 hover:text-greenx transition-colors">(<?= $reviewAgg['total'] ?> avaliação<?= $reviewAgg['total'] > 1 ? 'ões' : '' ?>)</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Stats: DISPONÍVEL / VENDAS -->
                <?php
                    // For dynamic products, compute stock from variant quantities
                    $_tipoProd = (string)($produto['tipo'] ?? 'produto');
                    $_varJson  = $produto['variantes'] ?? null;
                    $_varArr   = ($_tipoProd === 'dinamico' && $_varJson) ? json_decode($_varJson, true) : null;
                    if (!is_array($_varArr)) $_varArr = null;
                    $_dispQtd  = ($_tipoProd === 'dinamico' && $_varArr !== null)
                        ? array_sum(array_column($_varArr, 'quantidade'))
                        : (int)($produto['quantidade'] ?? 0);
                    $_dispLabel = $_tipoProd === 'servico' ? 'Serviço' : 'Disponível';
                    $_dispValue = $_tipoProd === 'servico' ? '-' : (string)$_dispQtd;
                    $_tipoProdLabel = $_tipoProd === 'servico' ? 'Serviço' : ($_tipoProd === 'dinamico' ? 'Dinâmico' : 'Produto');
                    $_deliveryMode = !empty($produto['auto_delivery_enabled']) ? 'Automática' : 'Manual';
                ?>
                <div class="flex items-center gap-4 sm:gap-6 flex-wrap">
                    <div class="text-center">
                        <p class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold"><?= htmlspecialchars($_dispLabel, ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-xl font-bold mt-0.5"><?= htmlspecialchars($_dispValue, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="w-px h-10 bg-white/[0.08]"></div>
                    <div class="text-center">
                        <p class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold">Tipo</p>
                        <p class="text-lg sm:text-xl font-bold mt-0.5"><?= htmlspecialchars($_tipoProdLabel, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="w-px h-10 bg-white/[0.08]"></div>
                    <div class="text-center">
                        <p class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold">Entrega</p>
                        <p class="text-lg sm:text-xl font-bold mt-0.5"><?= htmlspecialchars($_deliveryMode, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>

                <!-- Price + Buy -->
                <?php
                    $tipoProduto = (string)($produto['tipo'] ?? 'produto');
                    $variantesJson = $produto['variantes'] ?? null;
                    $variantesArr = ($tipoProduto === 'dinamico' && $variantesJson) ? json_decode($variantesJson, true) : [];
                    if (!is_array($variantesArr)) $variantesArr = [];
                ?>
                <?php if ($tipoProduto === 'dinamico' && count($variantesArr) > 0): ?>
                <div x-data="{
                    variantes: <?= htmlspecialchars(json_encode($variantesArr, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>,
                    selected: 0,
                    get selectedVariant() { return this.variantes[this.selected] || this.variantes[0]; },
                    get selectedPreco() { return this.selectedVariant ? this.selectedVariant.preco : 0; },
                    get selectedQtd() { return this.selectedVariant ? (this.selectedVariant.quantidade ?? 0) : 0; }
                }" class="space-y-4 pt-2">
                    <!-- Variant selector (select dropdown) -->
                    <div>
                        <label for="variantSelect" class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold mb-2 block">Escolha uma opção</label>
                        <select id="variantSelect" x-model.number="selected"
                                class="w-full px-4 py-3 rounded-xl border-2 border-white/[0.08] bg-white/[0.03] text-sm font-semibold text-zinc-200 focus:border-greenx/50 focus:outline-none transition-all appearance-none cursor-pointer"
                                style="background-image: url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2212%22 height=%2212%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%2371717a%22 stroke-width=%222%22%3E%3Cpath d=%22m6 9 6 6 6-6%22/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 12px center;">
                            <template x-for="(v, idx) in variantes" :key="idx">
                                <option :value="idx" x-text="v.nome + ' — R$ ' + Number(v.preco).toFixed(2).replace('.',',')"></option>
                            </template>
                        </select>
                    </div>
                    <div class="flex items-center gap-4">
                        <p class="text-3xl sm:text-4xl font-black text-greenx tracking-tight">
                            R$&nbsp;<span x-text="Number(selectedPreco).toFixed(2).replace('.',',')"></span>
                        </p>
                        <form method="post" class="flex-1 max-w-[200px]">
                            <input type="hidden" name="action" value="add_cart">
                            <input type="hidden" name="qty" value="1">
                            <input type="hidden" name="variante" :value="selectedVariant ? selectedVariant.nome : ''">
                            <button class="w-full flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-5 py-3 text-sm shadow-lg shadow-greenx/20 hover:shadow-greenx/30 transition-all uppercase tracking-wide"
                                    :disabled="selectedQtd <= 0"
                                    :class="selectedQtd <= 0 ? 'opacity-50 cursor-not-allowed' : ''">
                                <span x-text="selectedQtd <= 0 ? 'Esgotado' : 'Comprar'"></span>
                            </button>
                        </form>
                    </div>
                    <p x-show="selectedQtd > 0" class="text-xs text-zinc-500">
                        <span x-text="selectedQtd"></span> disponível(is) para esta opção
                    </p>
                </div>
                <?php elseif ($tipoProduto === 'dinamico'): ?>
                <!-- Dynamic product with no variants configured yet -->
                <div class="space-y-3 pt-2">
                    <div class="flex items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-400">
                        <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0"></i>
                        Este produto ainda não possui variantes configuradas. Entre em contato com o vendedor.
                    </div>
                </div>
                <?php else: ?>
                <div class="flex items-center gap-4 pt-2">
                    <p class="text-3xl sm:text-4xl font-black text-greenx tracking-tight">R$&nbsp;<?= number_format((float)$produto['preco'], 2, ',', '.') ?></p>
                    <form method="post" class="flex-1 max-w-[200px]">
                        <input type="hidden" name="action" value="add_cart">
                        <input type="hidden" name="qty" value="1">
                        <button class="w-full flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-5 py-3 text-sm shadow-lg shadow-greenx/20 hover:shadow-greenx/30 transition-all uppercase tracking-wide">
                            Comprar
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Favorite / Share buttons -->
                <div class="flex items-center gap-2 -mt-2">
                    <button type="button" class="fav-btn-detail flex items-center gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-4 py-2.5 text-sm text-zinc-400 hover:text-red-400 hover:border-red-400/30 transition-all" data-product-id="<?= (int)$produto['id'] ?>">
                        <i data-lucide="heart" class="w-4 h-4"></i>
                        <span class="fav-label">Favoritar</span>
                    </button>
                    <button type="button" onclick="navigator.share?navigator.share({title:'<?= htmlspecialchars(addslashes($produto['nome']), ENT_QUOTES) ?>',url:location.href}):navigator.clipboard.writeText(location.href).then(()=>alert('Link copiado!'))" class="flex items-center gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-4 py-2.5 text-sm text-zinc-400 hover:text-white hover:border-white/[0.15] transition-all">
                        <i data-lucide="share-2" class="w-4 h-4"></i>
                        <span>Compartilhar</span>
                    </button>
                </div>

                <hr class="border-white/[0.06]">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-wide mb-4">Características</h2>
                    <?php
                    $deliveryText = '';
                    if (!empty($produto['auto_delivery_enabled'])) {
                        $deliveryText = 'Automática após confirmação do pagamento';
                    } elseif (!empty($produto['data_entrega'])) {
                        $deliveryText = 'Manual até ' . date('d/m/Y', strtotime((string)$produto['data_entrega']));
                    } elseif (!empty($produto['prazo_entrega_dias'])) {
                        $deliveryText = 'Manual em até ' . (int)$produto['prazo_entrega_dias'] . ' dia(s) após confirmação do pagamento';
                    } else {
                        $deliveryText = 'Manual combinada pelo chat após confirmação do pagamento';
                    }
                    ?>
                    <div class="border border-white/[0.06] rounded-xl overflow-hidden">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-white/[0.06]">
                                <tr>
                                    <td class="px-4 py-3 bg-white/[0.02] text-zinc-400 font-medium w-1/3">Tipo do Anúncio</td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($_tipoProdLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 bg-white/[0.02] text-zinc-400 font-medium">Categoria</td>
                                    <td class="px-4 py-3"><?= htmlspecialchars((string)($produto['categoria_nome'] ?? 'Geral'), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 bg-white/[0.02] text-zinc-400 font-medium">Estoque</td>
                                    <td class="px-4 py-3"><?= $_dispQtd ?> unidade(s)</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 bg-white/[0.02] text-zinc-400 font-medium">Entrega</td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($deliveryText, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 bg-white/[0.02] text-zinc-400 font-medium">Pagamento</td>
                                    <td class="px-4 py-3 flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-greenx/10 border border-greenx/20 text-greenx">PIX</span>
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-purple-500/10 border border-purple-500/20 text-purple-400">WALLET</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <hr class="border-white/[0.06]">

                <!-- DESCRIÇÃO DO ANÚNCIO -->
                <?php
                    $desc = trim((string)($produto['descricao'] ?? ''));
                    // Mask any contact info in the rendered description
                    if ($desc !== '') {
                        require_once __DIR__ . '/../src/helpers.php';
                        $desc = maskContactInfo($desc, '***', true);
                    }
                ?>
                <?php if ($desc !== ''): ?>
                <div>
                    <h2 class="text-sm font-black uppercase tracking-wide mb-4">Descrição do Anúncio</h2>
                    <div id="descContent" class="prose prose-invert prose-sm max-w-none text-zinc-400 leading-relaxed bg-white/[0.02] border border-white/[0.04] rounded-xl p-5 [&_a]:text-greenx [&_img]:rounded-xl [&_img]:max-w-full overflow-hidden transition-all duration-300" style="max-height:350px">
                        <?= $desc ?>
                    </div>
                    <button id="descToggle" type="button" onclick="(function(){var c=document.getElementById('descContent'),b=document.getElementById('descToggle'),i=b.querySelector('.desc-icon');if(c.style.maxHeight!=='none'){c.style.maxHeight='none';b.querySelector('.desc-label').textContent='Ver menos';i.style.transform='rotate(180deg)';}else{c.style.maxHeight='350px';b.querySelector('.desc-label').textContent='Ver descrição completa';i.style.transform='';}})()"
                        class="mt-3 w-full flex items-center justify-center gap-2 py-2.5 rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm font-semibold text-greenx hover:bg-greenx/10 hover:border-greenx/25 transition-all" style="display:none">
                        <span class="desc-label">Ver descrição completa</span>
                        <i data-lucide="chevron-down" class="w-4 h-4 desc-icon transition-transform duration-300"></i>
                    </button>
                    <script>(function(){var c=document.getElementById('descContent');if(c&&c.scrollHeight>360){document.getElementById('descToggle').style.display='';}})()</script>
                </div>
                <?php endif; ?>

                <!-- Criado em + Denunciar -->
                <div class="flex items-center justify-between text-xs text-zinc-500 py-2">
                    <span class="uppercase font-bold tracking-wide">Criado em: <?= date('d/m/Y H:i', strtotime((string)($produto['criado_em'] ?? $produto['created_at'] ?? 'now'))) ?></span>
                    <a href="/denunciar?produto_id=<?= (int)$produto['id'] ?>" class="text-orange-400 hover:text-orange-300 font-semibold transition-colors">Denunciar</a>
                </div>

                <hr class="border-white/[0.06]">

                <!-- AVALIAÇÕES SECTION -->
                <section id="avaliacoes">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-sm font-black uppercase tracking-wide">Avaliações</h2>
                        <?php if ($reviewAgg['total'] > 0): ?>
                        <div class="flex items-center gap-2">
                            <div class="flex items-center gap-0.5"><?= reviewStarsHTML($reviewAgg['avg_rating'], 'w-4 h-4') ?></div>
                            <span class="text-sm font-bold"><?= $reviewAgg['avg_rating'] ?></span>
                            <span class="text-xs text-zinc-400">(<?= $reviewAgg['total'] ?>)</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($reviewAgg['total'] > 0): ?>
                    <!-- Rating breakdown -->
                    <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-5 mb-4">
                        <div class="flex items-center gap-6">
                            <div class="text-center shrink-0">
                                <p class="text-4xl font-black text-greenx"><?= $reviewAgg['avg_rating'] ?></p>
                                <div class="flex items-center justify-center gap-0.5 mt-1"><?= reviewStarsHTML($reviewAgg['avg_rating'], 'w-4 h-4') ?></div>
                                <p class="text-xs text-zinc-400 mt-1"><?= $reviewAgg['total'] ?> avaliação<?= $reviewAgg['total'] > 1 ? 'ões' : '' ?></p>
                            </div>
                            <div class="flex-1 space-y-1.5">
                                <?php for ($s = 5; $s >= 1; $s--): ?>
                                <?php $pct = $reviewAgg['total'] > 0 ? round(($reviewAgg['breakdown'][$s] / $reviewAgg['total']) * 100) : 0; ?>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-zinc-400 w-3 text-right"><?= $s ?></span>
                                    <svg class="w-3 h-3 text-yellow-400 fill-current flex-shrink-0" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    <div class="flex-1 h-1.5 bg-white/[0.06] rounded-full overflow-hidden">
                                        <div class="h-full bg-yellow-400 rounded-full" style="width:<?= $pct ?>%"></div>
                                    </div>
                                    <span class="text-[10px] text-zinc-500 w-5 text-right"><?= $reviewAgg['breakdown'][$s] ?></span>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Star filter (Shopee style) -->
                    <div class="flex flex-wrap gap-2 mb-4" id="reviewStarFilter">
                        <button type="button" onclick="filterReviews(null)" data-filter="all"
                                class="rev-filter-btn active px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all bg-greenx/10 border-greenx/40 text-greenx">
                            Todas (<?= $reviewAgg['total'] ?>)
                        </button>
                        <?php for ($s = 5; $s >= 1; $s--): ?>
                        <button type="button" onclick="filterReviews(<?= $s ?>)" data-filter="<?= $s ?>"
                                class="rev-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all border-white/[0.08] text-zinc-400 hover:border-yellow-400/40 hover:text-yellow-400 <?= $reviewAgg['breakdown'][$s] === 0 ? 'opacity-40 pointer-events-none' : '' ?>">
                            <?= $s ?> <svg class="w-3 h-3 text-yellow-400 fill-current inline-block -mt-px" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg> (<?= $reviewAgg['breakdown'][$s] ?>)
                        </button>
                        <?php endfor; ?>
                    </div>

                    <!-- Reviews list (AJAX) -->
                    <div class="space-y-4" id="reviewsList">
                        <?php foreach ($reviews as $rev):
                            $revAvatarRaw = trim((string)($rev['user_avatar'] ?? ''));
                            $revAvatarUrl = $revAvatarRaw !== '' ? mediaResolveUrl($revAvatarRaw) : '';
                        ?>
                        <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-4">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0">
                                    <?php if ($revAvatarUrl !== ''): ?>
                                    <img src="<?= htmlspecialchars($revAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="w-9 h-9 rounded-full object-cover border-2 border-greenx/20">
                                    <?php else: ?>
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-greenx/20 to-greenxd/10 border border-greenx/20 flex items-center justify-center">
                                        <span class="text-xs font-bold text-greenx"><?= strtoupper(mb_substr((string)$rev['user_nome'], 0, 1)) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-semibold text-sm"><?= htmlspecialchars((string)$rev['user_nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <div class="flex items-center gap-0.5"><?= reviewStarsHTML((float)$rev['rating'], 'w-3 h-3') ?></div>
                                        <span class="text-[10px] text-zinc-500"><?= date('d/m/Y', strtotime((string)$rev['criado_em'])) ?></span>
                                    </div>
                                    <?php if (!empty($rev['titulo'])): ?>
                                    <p class="font-semibold text-sm mt-1"><?= htmlspecialchars((string)$rev['titulo'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($rev['comentario'])): ?>
                                    <p class="text-sm text-zinc-400 mt-1 leading-relaxed"><?= nl2br(htmlspecialchars((string)$rev['comentario'], ENT_QUOTES, 'UTF-8')) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($rev['resposta_vendedor'])): ?>
                                    <div class="mt-3 pl-3 border-l-2 border-greenx/30">
                                        <p class="text-[10px] font-bold text-greenx uppercase tracking-wide mb-0.5">Resposta do vendedor</p>
                                        <p class="text-sm text-zinc-400"><?= nl2br(htmlspecialchars((string)$rev['resposta_vendedor'], ENT_QUOTES, 'UTF-8')) ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Reviews pagination -->
                    <?php if ($reviewsTotal > 5): ?>
                    <div class="mt-4 flex items-center justify-center gap-2" id="reviewsPagination">
                        <button type="button" id="revPrev" onclick="reviewsGoPage(revCurrentPage-1)" disabled
                                class="flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-medium border border-white/[0.08] text-zinc-400 hover:border-greenx/30 hover:text-greenx transition-all disabled:opacity-40 disabled:pointer-events-none">
                            <i data-lucide="chevron-left" class="w-4 h-4"></i> Anterior
                        </button>
                        <span id="revPageInfo" class="text-xs text-zinc-500 px-2">1 / <?= (int)ceil($reviewsTotal / 5) ?></span>
                        <button type="button" id="revNext" onclick="reviewsGoPage(revCurrentPage+1)"
                                class="flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-medium border border-white/[0.08] text-zinc-400 hover:border-greenx/30 hover:text-greenx transition-all disabled:opacity-40 disabled:pointer-events-none">
                            Próximo <i data-lucide="chevron-right" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-6 text-center">
                        <i data-lucide="star" class="w-10 h-10 mx-auto text-zinc-600 mb-2"></i>
                        <p class="text-zinc-400 text-sm">Este produto ainda não tem avaliações.</p>
                        <?php if ($canReview['can']): ?>
                        <p class="text-zinc-500 text-xs mt-1">Seja o primeiro a avaliar!</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Review form -->
                    <?php if ($canReview['can']): ?>
                    <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-5 mt-6" id="reviewForm">
                        <h3 class="text-sm font-bold uppercase tracking-wide mb-4">Avaliar este produto</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="text-xs text-zinc-400 mb-2 block">Sua nota</label>
                                <div class="flex items-center gap-1" id="starSelector">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <button type="button" data-star="<?= $s ?>" onclick="selectStar(<?= $s ?>)"
                                            class="star-btn p-1 rounded-lg hover:bg-white/[0.06] transition-all">
                                        <svg class="w-6 h-6 text-zinc-600 fill-current transition-colors" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                    </button>
                                    <?php endfor; ?>
                                    <span class="text-xs text-zinc-500 ml-2" id="starLabel">Selecione</span>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-zinc-400 mb-1 block">Título (opcional)</label>
                                <input type="text" id="reviewTitulo" maxlength="160" placeholder="Resumo da sua experiência"
                                       class="w-full rounded-lg bg-white/[0.03] border border-white/[0.08] px-3 py-2 text-sm focus:border-greenx/50 focus:outline-none transition-colors">
                            </div>
                            <div>
                                <label class="text-xs text-zinc-400 mb-1 block">Comentário (opcional)</label>
                                <textarea id="reviewComentario" rows="3" maxlength="1000" placeholder="Conte sobre sua experiência..."
                                          class="w-full rounded-lg bg-white/[0.03] border border-white/[0.08] px-3 py-2 text-sm focus:border-greenx/50 focus:outline-none transition-colors resize-none"></textarea>
                            </div>
                            <button type="button" onclick="submitReview()" id="submitReviewBtn"
                                    class="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-bold px-5 py-2.5 text-sm shadow-lg shadow-greenx/20 transition-all">
                                <i data-lucide="send" class="w-4 h-4"></i> Enviar avaliação
                            </button>
                            <p id="reviewMsg" class="text-sm mt-2 hidden"></p>
                        </div>
                    </div>
                    <?php elseif ($isLoggedIn): ?>
                    <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-4 text-center mt-6">
                        <p class="text-sm text-zinc-400"><?= htmlspecialchars($canReview['reason'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <?php else: ?>
                    <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-4 text-center mt-6">
                        <p class="text-sm text-zinc-400"><a href="/login?return_to=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>" class="text-greenx hover:underline">Faça login</a> para avaliar este produto.</p>
                    </div>
                    <?php endif; ?>
                </section>

                <hr class="border-white/[0.06]">

                <!-- PERGUNTAS (Q&A) SECTION -->
                <section id="perguntas">
                    <div class="flex items-center gap-3 mb-6">
                        <h2 class="text-sm font-black uppercase tracking-wide">Perguntas e Respostas</h2>
                        <span class="px-2 py-0.5 rounded-full bg-purple-500/15 border border-purple-400/30 text-purple-400 text-[10px] font-bold"><?= $questionsTotal ?></span>
                    </div>

                    <div class="space-y-4" id="questionsList">
                        <?php if (!empty($questions)): ?>
                        <?php foreach ($questions as $qa): ?>
                        <div class="qa-thread rounded-2xl bg-blackx2 border border-white/[0.06] p-4 hover:border-white/[0.10] transition-colors">
                            <!-- Question bubble -->
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <?php
                                        $qaAvatarRaw = trim((string)($qa['user_avatar'] ?? ''));
                                        $qaAvatarUrl = $qaAvatarRaw !== '' ? mediaResolveUrl($qaAvatarRaw) : '';
                                    ?>
                                    <?php if ($qaAvatarUrl !== ''): ?>
                                    <img src="<?= htmlspecialchars($qaAvatarUrl) ?>" alt="" class="w-9 h-9 rounded-full object-cover border-2 border-purple-500/30">
                                    <?php else: ?>
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-greenx to-greenx2 flex items-center justify-center shadow-lg shadow-greenx/10">
                                        <span class="text-xs font-bold text-white"><?= strtoupper(mb_substr((string)($qa['user_nome'] ?? 'U'), 0, 1)) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                                        <span class="font-semibold text-sm text-purple-300"><?= htmlspecialchars((string)($qa['user_nome'] ?? 'Usuário')) ?></span>
                                        <span class="text-[10px] text-zinc-500"><?= questionsTimeAgo((string)$qa['criado_em']) ?></span>
                                        <?php if (empty($qa['resposta'])): ?>
                                        <span class="ml-auto px-2 py-0.5 rounded-full bg-orange-500/15 border border-orange-400/30 text-orange-400 text-[9px] font-bold uppercase">Aguardando</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="qa-bubble-question rounded-xl rounded-tl-sm bg-purple-500/8 border border-purple-500/15 px-3.5 py-2.5">
                                        <p class="text-sm leading-relaxed"><?= nl2br(htmlspecialchars((string)$qa['pergunta'])) ?></p>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($qa['resposta'])): ?>
                            <!-- Answer bubble -->
                            <div class="flex gap-3 mt-3 ml-6">
                                <div class="flex-shrink-0">
                                    <?php if (!empty($vendorAvatar)): ?>
                                    <img src="<?= htmlspecialchars($vendorAvatar) ?>" alt="" class="w-9 h-9 rounded-full object-cover border-2 border-greenx/30">
                                    <?php else: ?>
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-greenx to-greenxd flex items-center justify-center shadow-lg shadow-greenx/10">
                                        <span class="text-xs font-bold text-white"><?= strtoupper(mb_substr($vendorName, 0, 1)) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                                        <span class="font-semibold text-sm text-greenx"><?= htmlspecialchars($vendorName) ?></span>
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-greenx text-white">Vendedor</span>
                                        <?php if (!empty($qa['respondido_em'])): ?>
                                        <span class="text-[10px] text-zinc-500"><?= questionsTimeAgo((string)$qa['respondido_em']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="qa-bubble-answer rounded-xl rounded-tl-sm bg-greenx/8 border border-greenx/15 px-3.5 py-2.5">
                                        <p class="text-sm leading-relaxed text-zinc-300"><?= nl2br(htmlspecialchars((string)$qa['resposta'])) ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-8 text-center">
                            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
                                <i data-lucide="message-circle-question" class="w-6 h-6 text-purple-400"></i>
                            </div>
                            <p class="text-sm text-zinc-400">Nenhuma pergunta ainda.</p>
                            <p class="text-xs text-zinc-500 mt-1">Seja o primeiro a perguntar!</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($questionsTotal > 5): ?>
                    <div class="mt-4 flex items-center justify-center gap-2" id="questionsPagination">
                        <button type="button" id="qPrev" onclick="questionsGoPage(qCurrentPage-1)" disabled
                                class="flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-medium border border-white/[0.08] text-zinc-400 hover:border-greenx/30 hover:text-greenx transition-all disabled:opacity-40 disabled:pointer-events-none">
                            <i data-lucide="chevron-left" class="w-4 h-4"></i> Anterior
                        </button>
                        <span id="qPageInfo" class="text-xs text-zinc-500 px-2">1 / <?= (int)ceil($questionsTotal / 5) ?></span>
                        <button type="button" id="qNext" onclick="questionsGoPage(qCurrentPage+1)"
                                class="flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-medium border border-white/[0.08] text-zinc-400 hover:border-greenx/30 hover:text-greenx transition-all disabled:opacity-40 disabled:pointer-events-none">
                            Próximo <i data-lucide="chevron-right" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Ask question form -->
                    <div class="mt-8">
                        <h3 class="text-sm font-black uppercase tracking-wide mb-4">Faça uma pergunta</h3>
                        <?php if ($isLoggedIn): ?>
                        <div class="space-y-3">
                            <textarea id="questionInput" rows="3" maxlength="1000" placeholder="Digite sua pergunta aqui."
                                      class="w-full rounded-xl bg-white/[0.03] border border-white/[0.08] px-4 py-3 text-sm focus:border-greenx/50 focus:outline-none transition-colors resize-none"></textarea>
                            <div class="flex items-center justify-between">
                                <p class="text-[10px] text-orange-400 font-semibold">
                                    <span class="uppercase font-black">Atenção:</span> Não é permitido enviar contatos externos como o WhatsApp, Discord, Facebook, Instagram, E-mail e semelhantes.
                                </p>
                                <button type="button" onclick="submitQuestion()" id="submitQuestionBtn"
                                        class="flex items-center gap-2 rounded-xl bg-greenx hover:bg-greenx2 text-white font-bold px-5 py-2.5 text-sm transition-all flex-shrink-0 ml-4">
                                    Perguntar
                                </button>
                            </div>
                            <p id="questionMsg" class="text-sm hidden"></p>
                        </div>
                        <?php else: ?>
                        <div class="bg-blackx2 border border-white/[0.06] rounded-xl p-4 text-center">
                            <p class="text-sm text-zinc-400"><a href="/login?return_to=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>" class="text-greenx hover:underline">Faça login</a> para fazer uma pergunta.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <!-- ── RIGHT SIDEBAR ── -->
            <?php
                // Vendor extended stats for sidebar
                $vendorSince = '';
                if (!empty($vendorProfile['criado_em'])) {
                    try { $vendorSince = (new DateTime((string)$vendorProfile['criado_em']))->format('d/m/Y'); } catch (\Throwable $e) {}
                }
                $vendorProducts = sfVendorProductCount($conn, $vendorId);
                $vendorSales    = sfVendorSalesCount($conn, $vendorId);
                $vendorPositive = $vendorAgg['total'] > 0 ? round(($vendorAgg['avg_rating'] / 5) * 100) : 0;
                $vendorLastSeen = '';
                if (!empty($vendorProfile['last_seen_at'])) {
                    try {
                        $diff = (new DateTime())->diff(new DateTime((string)$vendorProfile['last_seen_at']));
                        if ($diff->days === 0) $vendorLastSeen = 'Hoje';
                        elseif ($diff->days === 1) $vendorLastSeen = 'Ontem';
                        elseif ($diff->days < 7) $vendorLastSeen = 'há ' . $diff->days . ' dias';
                        elseif ($diff->days < 30) $vendorLastSeen = 'há ' . floor($diff->days / 7) . ' semana(s)';
                        else $vendorLastSeen = 'há ' . floor($diff->days / 30) . ' mês(es)';
                    } catch (\Throwable $e) {}
                }
            ?>
            <div class="space-y-4 lg:sticky lg:top-24 self-start">
                <!-- Vendedor Card (GGMax style) -->
                <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-5">
                    <h3 class="text-sm font-bold text-center mb-4">Vendedor</h3>
                    <a href="/loja?id=<?= $vendorId ?>" class="flex flex-col items-center gap-3 group">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-greenx/30 to-greenxd/20 border-2 border-greenx/30 overflow-hidden">
                            <img src="<?= htmlspecialchars($vendorAvatar, ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8') ?>"
                                 class="w-full h-full object-cover">
                        </div>
                        <div class="text-center">
                            <p class="font-bold text-greenx group-hover:underline inline-flex items-center gap-1.5">
                                <?= htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($vendorAgg['total'] > 0): ?>
                                <span class="text-xs text-zinc-400 font-normal">(<?= $vendorAgg['total'] ?>)</span>
                                <?php endif; ?>
                            </p>
                            <?php if ($vendorAgg['total'] > 0): ?>
                            <div class="flex items-center justify-center gap-0.5 mt-1"><?= reviewStarsHTML($vendorAgg['avg_rating'], 'w-3 h-3') ?></div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <!-- Vendor Info Table -->
                    <div class="mt-4 pt-4 border-t border-white/[0.06] space-y-2.5 text-xs">
                        <?php if ($vendorSince): ?>
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-400">Membro desde:</span>
                            <span class="font-semibold"><?= $vendorSince ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($vendorAgg['total'] > 0): ?>
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-400">Avaliações positivas:</span>
                            <span class="font-semibold text-greenx"><?= $vendorPositive ?>%</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-400">Nº de avaliações:</span>
                            <span class="font-semibold"><?= number_format($vendorAgg['total']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-400">Produtos:</span>
                            <span class="font-semibold"><?= $vendorProducts ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-400">Vendas:</span>
                            <span class="font-semibold"><?= number_format($vendorSales) ?></span>
                        </div>
                        <?php if ($vendorLastSeen): ?>
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-400">Último acesso:</span>
                            <span class="font-semibold"><?= $vendorLastSeen ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>



                <!-- Verificações Card -->
                <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-5">
                    <h3 class="text-sm font-bold text-center mb-4">Verificações</h3>
                    <div class="space-y-2.5">
                        <?php
                            $verificationRows = [
                                'KYC' => $vendorKycVerified,
                                'Dados' => sfVerificationStatusApproved((string)($vendorVerification['dados'] ?? '')),
                                'Documentos' => sfVerificationStatusApproved((string)($vendorVerification['documentos'] ?? '')),
                            ];
                        ?>
                        <?php foreach ($verificationRows as $verificationLabel => $verificationOk): ?>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-zinc-400"><?= htmlspecialchars($verificationLabel, ENT_QUOTES, 'UTF-8') ?>:</span>
                            <span class="text-xs font-bold <?= $verificationOk ? 'text-greenx' : 'text-zinc-500' ?>"><?= $verificationOk ? 'Verificado' : 'Pendente' ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Entrega Garantida Card -->
                <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-5 text-center">
                    <i data-lucide="shield-check" class="w-8 h-8 text-greenx mx-auto mb-2"></i>
                    <h3 class="text-sm font-bold">Entrega garantida</h3>
                    <p class="text-[11px] text-zinc-500 mt-1">Ou o seu dinheiro de volta</p>
                </div>

                <!-- Trust signals compact -->
                <div class="bg-blackx2 border border-white/[0.06] rounded-2xl p-4 space-y-3">
                    <div class="flex items-center gap-3">
                        <i data-lucide="lock" class="w-4 h-4 text-greenx shrink-0"></i>
                        <span class="text-xs text-zinc-400">Pagamento protegido por Escrow</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <i data-lucide="qr-code" class="w-4 h-4 text-greenx shrink-0"></i>
                        <span class="text-xs text-zinc-400">PIX instantâneo</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <i data-lucide="wallet" class="w-4 h-4 text-purple-400 shrink-0"></i>
                        <span class="text-xs text-zinc-400">Pague com saldo da carteira</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== RELATED PRODUCTS — 5 per line ========== -->
        <?php if ($relacionados): ?>
        <section class="mt-16 animate-fade-in-up">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold">Anúncios parecidos</h2>
                <a href="/categorias?categoria_id=<?= (int)($produto['categoria_id'] ?? 0) ?>"
                   class="text-sm text-zinc-400 hover:text-greenx transition-colors inline-flex items-center gap-1">
                    Ver mais <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                </a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                <?php foreach ($relacionados as $i => $p): ?>
                <article class="product-card group bg-blackx2 border border-white/[0.06] rounded-2xl overflow-hidden flex flex-col">
                    <a href="<?= sfProductUrl($p) ?>" class="block relative overflow-hidden">
                        <div class="aspect-[4/3] overflow-hidden bg-blackx">
                            <img src="<?= htmlspecialchars(sfImageUrl((string)($p['imagem'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars((string)($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                        </div>
                        <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md text-[9px] font-bold uppercase tracking-wide bg-greenx/90 text-white">
                            <?= htmlspecialchars((string)($p['categoria_nome'] ?? 'Geral'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </a>
                    <button type="button" class="fav-btn absolute top-2 right-2 w-7 h-7 rounded-full bg-black/50 backdrop-blur-sm border border-white/10 flex items-center justify-center text-zinc-400 hover:text-red-400 hover:border-red-400/40 transition-all z-10" data-product-id="<?= (int)$p['id'] ?>" title="Favoritar">
                        <i data-lucide="heart" class="w-3.5 h-3.5"></i>
                    </button>
                    <div class="p-3 flex flex-col flex-1 text-center">
                        <a href="<?= sfProductUrl($p) ?>"
                           class="font-semibold text-xs sm:text-sm line-clamp-2 min-h-[2rem] leading-snug hover:text-greenx transition-colors block">
                            <?= htmlspecialchars((string)$p['nome'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <div class="flex-1"></div>
                        <?php if (!empty($p['vendedor_nome'])): ?>
                        <?php $relatedVendorVerified = sfUserKycVerified($conn, (int)($p['vendedor_id'] ?? 0)); ?>
                        <div class="flex items-center justify-center gap-1.5 text-[10px] text-zinc-500 mt-1.5">
                            <i data-lucide="<?= $relatedVendorVerified ? 'shield-check' : 'store' ?>" class="w-3 h-3 <?= $relatedVendorVerified ? 'text-greenx' : 'text-zinc-500' ?> shrink-0"></i>
                            <span class="truncate"><?= htmlspecialchars((string)$p['vendedor_nome'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="inline-flex items-center self-center px-2.5 py-1 rounded-lg border border-greenx/40 bg-greenx/[0.06] mt-1.5">
                            <span class="text-sm font-bold text-greenx"><?= sfDisplayPrice($p) ?></span>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

    </main>
</div>

<script>
var selectedRating = 0;
var starLabels = ['', 'Péssimo', 'Ruim', 'Regular', 'Bom', 'Excelente'];
function selectStar(n){
    selectedRating = n;
    document.querySelectorAll('#starSelector .star-btn svg').forEach(function(svg, i){
        svg.classList.toggle('text-yellow-400', i < n);
        svg.classList.toggle('text-zinc-600', i >= n);
    });
    document.getElementById('starLabel').textContent = starLabels[n] || '';
}
function submitReview(){
    if(selectedRating < 1){ alert('Selecione uma nota de 1 a 5.'); return; }
    var btn = document.getElementById('submitReviewBtn');
    btn.disabled = true;
    btn.textContent = 'Enviando...';
    fetch('<?= BASE_PATH ?>/api/reviews.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            action: 'submit',
            product_id: <?= (int)$produto['id'] ?>,
            rating: selectedRating,
            titulo: document.getElementById('reviewTitulo').value,
            comentario: document.getElementById('reviewComentario').value
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        var msg = document.getElementById('reviewMsg');
        msg.classList.remove('hidden');
        if(data.ok){
            msg.className = 'text-sm mt-2 text-greenx';
            msg.textContent = 'Avaliação enviada com sucesso! Recarregando...';
            setTimeout(function(){ location.reload(); }, 1200);
        } else {
            msg.className = 'text-sm mt-2 text-red-400';
            msg.textContent = data.error || 'Erro ao enviar avaliação.';
            btn.disabled = false;
            btn.textContent = 'Enviar avaliação';
        }
    })
    .catch(function(){
        btn.disabled = false;
        btn.textContent = 'Enviar avaliação';
    });
}

/* ---- Reviews AJAX pagination + star filter ---- */
var revCurrentPage = 1;
var revTotalPages = <?= max(1, (int)ceil(($reviewsTotal ?? 0) / 5)) ?>;
var revActiveFilter = null;

function filterReviews(rating){
    revActiveFilter = rating;
    revCurrentPage = 1;
    // Update filter button styles
    document.querySelectorAll('.rev-filter-btn').forEach(function(btn){
        var f = btn.getAttribute('data-filter');
        var isActive = (rating === null && f === 'all') || (rating !== null && f == rating);
        if(isActive){
            btn.classList.remove('border-white/[0.08]', 'text-zinc-400');
            btn.classList.add('bg-greenx/10', 'border-greenx/40', 'text-greenx', 'active');
        } else {
            btn.classList.remove('bg-greenx/10', 'border-greenx/40', 'text-greenx', 'active');
            btn.classList.add('border-white/[0.08]', 'text-zinc-400');
        }
    });
    loadReviews();
}

function reviewsGoPage(page){
    if(page < 1 || page > revTotalPages) return;
    revCurrentPage = page;
    loadReviews();
}

function loadReviews(){
    var list = document.getElementById('reviewsList');
    list.innerHTML = '<div class="p-6 text-center text-zinc-500 text-sm"><div class="inline-block w-5 h-5 border-2 border-zinc-500 border-t-transparent rounded-full animate-spin mr-2"></div>Carregando...</div>';

    var url = '<?= BASE_PATH ?>/api/reviews.php?action=list&product_id=<?= (int)$produto['id'] ?>&page=' + revCurrentPage;
    if(revActiveFilter !== null) url += '&filter_rating=' + revActiveFilter;

    fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(!data.ok){ list.innerHTML='<div class="p-6 text-center text-red-400 text-sm">Erro ao carregar avaliações.</div>'; return; }
        revTotalPages = data.pages;
        var html = '';
        if(!data.reviews.length){
            html = '<div class="bg-blackx2 border border-white/[0.06] rounded-xl p-6 text-center"><p class="text-zinc-400 text-sm">Nenhuma avaliação ' + (revActiveFilter ? 'com ' + revActiveFilter + ' estrela' + (revActiveFilter > 1 ? 's' : '') : '') + ' encontrada.</p></div>';
        }
        data.reviews.forEach(function(rev){
            var initial = (rev.user_nome || 'U').charAt(0).toUpperCase();
            html += '<div class="bg-blackx2 border border-white/[0.06] rounded-xl p-4">';
            html += '<div class="flex items-start gap-3">';
            // Avatar
            html += '<div class="flex-shrink-0">';
            if(rev.user_avatar_url){
                html += '<img src="'+escH(rev.user_avatar_url)+'" alt="" class="w-9 h-9 rounded-full object-cover border-2 border-greenx/20">';
            } else {
                html += '<div class="w-9 h-9 rounded-full bg-gradient-to-br from-greenx/20 to-greenxd/10 border border-greenx/20 flex items-center justify-center"><span class="text-xs font-bold text-greenx">'+initial+'</span></div>';
            }
            html += '</div>';
            // Content
            html += '<div class="flex-1 min-w-0">';
            html += '<div class="flex items-center gap-2 flex-wrap">';
            html += '<span class="font-semibold text-sm">'+escH(rev.user_nome)+'</span>';
            html += renderStars(rev.rating);
            html += '<span class="text-[10px] text-zinc-500">'+escH(rev.criado_em)+'</span>';
            html += '</div>';
            if(rev.titulo) html += '<p class="font-semibold text-sm mt-1">'+escH(rev.titulo)+'</p>';
            if(rev.comentario) html += '<p class="text-sm text-zinc-400 mt-1 leading-relaxed">'+escH(rev.comentario).replace(/\n/g,'<br>')+'</p>';
            if(rev.resposta_vendedor){
                html += '<div class="mt-3 pl-3 border-l-2 border-greenx/30">';
                html += '<p class="text-[10px] font-bold text-greenx uppercase tracking-wide mb-0.5">Resposta do vendedor</p>';
                html += '<p class="text-sm text-zinc-400">'+escH(rev.resposta_vendedor).replace(/\n/g,'<br>')+'</p>';
                html += '</div>';
            }
            html += '</div></div></div>';
        });
        list.innerHTML = html;
        updateRevPagination();
        if(window.lucide) lucide.createIcons();
        list.scrollIntoView({behavior:'smooth', block:'nearest'});
    })
    .catch(function(){ list.innerHTML='<div class="p-6 text-center text-red-400 text-sm">Erro de conexão.</div>'; });
}

function updateRevPagination(){
    var pag = document.getElementById('reviewsPagination');
    if(!pag){
        // Create pagination if it doesn't exist yet
        var section = document.getElementById('avaliacoes');
        var listEl = document.getElementById('reviewsList');
        if(revTotalPages > 1 && section && listEl){
            var pagHtml = '<div class="mt-4 flex items-center justify-center gap-2" id="reviewsPagination">';
            pagHtml += '<button type="button" id="revPrev" onclick="reviewsGoPage(revCurrentPage-1)" class="flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-medium border border-white/[0.08] text-zinc-400 hover:border-greenx/30 hover:text-greenx transition-all disabled:opacity-40 disabled:pointer-events-none"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Anterior</button>';
            pagHtml += '<span id="revPageInfo" class="text-xs text-zinc-500 px-2"></span>';
            pagHtml += '<button type="button" id="revNext" onclick="reviewsGoPage(revCurrentPage+1)" class="flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-medium border border-white/[0.08] text-zinc-400 hover:border-greenx/30 hover:text-greenx transition-all disabled:opacity-40 disabled:pointer-events-none">Próximo <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></button>';
            pagHtml += '</div>';
            listEl.insertAdjacentHTML('afterend', pagHtml);
            pag = document.getElementById('reviewsPagination');
        }
    }
    if(!pag) return;
    var info = document.getElementById('revPageInfo');
    var prev = document.getElementById('revPrev');
    var next = document.getElementById('revNext');
    if(revTotalPages <= 1){
        pag.style.display = 'none';
    } else {
        pag.style.display = '';
        if(info) info.textContent = revCurrentPage + ' / ' + revTotalPages;
        if(prev) prev.disabled = revCurrentPage <= 1;
        if(next) next.disabled = revCurrentPage >= revTotalPages;
    }
}

function renderStars(n){
    var html = '<div class="flex items-center gap-0.5">';
    for(var i=1;i<=5;i++){
        if(i<=n){
            html += '<svg class="w-3 h-3 text-yellow-400 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
        } else {
            html += '<svg class="w-3 h-3 text-zinc-600 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
        }
    }
    html += '</div>';
    return html;
}

/* ---- Questions (Q&A) with AJAX pagination ---- */
var qCurrentPage = 1;
var qTotalPages = <?= max(1, (int)ceil($questionsTotal / 5)) ?>;

function questionsGoPage(page){
    if(page < 1 || page > qTotalPages) return;
    qCurrentPage = page;
    var list = document.getElementById('questionsList');
    list.innerHTML = '<div class="p-6 text-center text-zinc-500 text-sm"><div class="inline-block w-5 h-5 border-2 border-zinc-500 border-t-transparent rounded-full animate-spin mr-2"></div>Carregando...</div>';
    list.scrollIntoView({behavior:'smooth', block:'nearest'});

    fetch('<?= BASE_PATH ?>/api/questions.php?product_id=<?= (int)$produto['id'] ?>&page=' + page + '&action=list')
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(!data.ok){ list.innerHTML='<div class="p-6 text-center text-red-400 text-sm">Erro ao carregar.</div>'; return; }
        qTotalPages = data.pages;
        var html = '';
        if(!data.questions.length){
            html = '<div class="p-8 text-center text-zinc-500 text-sm">Nenhuma pergunta nesta página.</div>';
        }
        data.questions.forEach(function(qa){
            var initial = (qa.user_nome || 'U').charAt(0).toUpperCase();
            var avatarUrl = qa.user_avatar_url || '';
            html += '<div class="qa-thread rounded-2xl bg-blackx2 border border-white/[0.06] p-4 hover:border-white/[0.10] transition-colors">';
            // Question bubble
            html += '<div class="flex gap-3"><div class="flex-shrink-0">';
            if(avatarUrl){
                html += '<img src="'+escH(avatarUrl)+'" alt="" class="w-9 h-9 rounded-full object-cover border-2 border-purple-500/30">';
            } else {
                html += '<div class="w-9 h-9 rounded-full bg-gradient-to-br from-greenx to-greenx2 flex items-center justify-center shadow-lg shadow-greenx/10"><span class="text-xs font-bold text-white">'+initial+'</span></div>';
            }
            html += '</div><div class="flex-1 min-w-0">';
            html += '<div class="flex items-center gap-2 mb-1.5 flex-wrap"><span class="font-semibold text-sm text-purple-300">'+escH(qa.user_nome || 'Usuário')+'</span>';
            if(qa.criado_em || qa.criado_em_label) html += '<span class="text-[10px] text-zinc-500">'+escH(qa.criado_em_label || qa.criado_em)+'</span>';
            if(!qa.resposta) html += '<span class="ml-auto px-2 py-0.5 rounded-full bg-orange-500/15 border border-orange-400/30 text-orange-400 text-[9px] font-bold uppercase">Aguardando</span>';
            html += '</div>';
            html += '<div class="qa-bubble-question rounded-xl rounded-tl-sm bg-purple-500/8 border border-purple-500/15 px-3.5 py-2.5"><p class="text-sm leading-relaxed">'+escH(qa.pergunta)+'</p></div>';
            html += '</div></div>';
            // Answer bubble
            if(qa.resposta){
                html += '<div class="flex gap-3 mt-3 ml-6"><div class="flex-shrink-0">';
                html += '<img src="<?= htmlspecialchars($vendorAvatar) ?>" alt="" class="w-9 h-9 rounded-full object-cover border-2 border-greenx/30">';
                html += '</div><div class="flex-1 min-w-0">';
                html += '<div class="flex items-center gap-2 mb-1.5 flex-wrap"><span class="font-semibold text-sm text-greenx"><?= htmlspecialchars($vendorName) ?></span>';
                html += '<span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-greenx text-white">Vendedor</span>';
                if(qa.respondido_em || qa.respondido_em_label) html += '<span class="text-[10px] text-zinc-500">'+escH(qa.respondido_em_label || qa.respondido_em)+'</span>';
                html += '</div>';
                html += '<div class="qa-bubble-answer rounded-xl rounded-tl-sm bg-greenx/8 border border-greenx/15 px-3.5 py-2.5"><p class="text-sm leading-relaxed text-zinc-300">'+escH(qa.resposta)+'</p></div>';
                html += '</div></div>';
            }
            html += '</div>';
        });
        list.innerHTML = html;
        if(window.lucide) lucide.createIcons();
        // Update pagination
        updateQPagination();
    })
    .catch(function(){ list.innerHTML='<div class="p-6 text-center text-red-400 text-sm">Erro de conexão.</div>'; });
}

function updateQPagination(){
    var info = document.getElementById('qPageInfo');
    var prev = document.getElementById('qPrev');
    var next = document.getElementById('qNext');
    if(info) info.textContent = qCurrentPage + ' / ' + qTotalPages;
    if(prev) prev.disabled = qCurrentPage <= 1;
    if(next) next.disabled = qCurrentPage >= qTotalPages;
}
function escH(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function submitQuestion(){
    var input = document.getElementById('questionInput');
    var pergunta = (input.value || '').trim();
    if(!pergunta){ alert('Digite sua pergunta.'); return; }
    var btn = document.getElementById('submitQuestionBtn');
    btn.disabled = true;
    btn.textContent = 'Enviando...';
    fetch('<?= BASE_PATH ?>/api/questions.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'ask', product_id:<?= (int)$produto['id'] ?>, pergunta: pergunta })
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        var msg = document.getElementById('questionMsg');
        msg.classList.remove('hidden');
        if(data.ok){
            msg.className = 'text-sm mt-2 text-greenx';
            msg.textContent = 'Pergunta enviada com sucesso!';
            input.value = '';
            btn.disabled = false;
            btn.textContent = 'Perguntar';
            // Insert the new question inline at the top
            var list = document.getElementById('questionsList');
            var userName = <?= json_encode(htmlspecialchars($_currentUserNome)) ?>;
            var userAvatar = <?= json_encode(htmlspecialchars($_currentUserAvatar)) ?>;
            var initial = userName.charAt(0).toUpperCase();
            var newHtml = '<div class="qa-thread rounded-2xl bg-blackx2 border border-white/[0.06] p-4 hover:border-white/[0.10] transition-colors animate-fade-in">';
            newHtml += '<div class="flex gap-3"><div class="flex-shrink-0">';
            if(userAvatar){
                newHtml += '<img src="'+escH(userAvatar)+'" alt="" class="w-9 h-9 rounded-full object-cover border-2 border-purple-500/30">';
            } else {
                newHtml += '<div class="w-9 h-9 rounded-full bg-gradient-to-br from-greenx to-greenx2 flex items-center justify-center shadow-lg shadow-greenx/10"><span class="text-xs font-bold text-white">'+initial+'</span></div>';
            }
            newHtml += '</div><div class="flex-1 min-w-0">';
            newHtml += '<div class="flex items-center gap-2 mb-1.5 flex-wrap"><span class="font-semibold text-sm text-purple-300">'+escH(userName)+'</span>';
            newHtml += '<span class="text-[10px] text-zinc-500">agora</span>';
            newHtml += '<span class="ml-auto px-2 py-0.5 rounded-full bg-orange-500/15 border border-orange-400/30 text-orange-400 text-[9px] font-bold uppercase">Aguardando</span>';
            newHtml += '</div>';
            newHtml += '<div class="qa-bubble-question rounded-xl rounded-tl-sm bg-purple-500/8 border border-purple-500/15 px-3.5 py-2.5"><p class="text-sm leading-relaxed">'+escH(pergunta)+'</p></div>';
            newHtml += '</div></div></div>';
            // Remove empty state if present
            var emptyState = list.querySelector('.text-center');
            if(emptyState && emptyState.textContent.indexOf('Nenhuma pergunta') !== -1) {
                emptyState.closest('.bg-blackx2')?.remove();
            }
            list.insertAdjacentHTML('afterbegin', newHtml);
            if(window.lucide) lucide.createIcons();
            setTimeout(function(){ msg.classList.add('hidden'); }, 3000);
        } else {
            msg.className = 'text-sm mt-2 text-red-400';
            msg.textContent = data.error || 'Erro ao enviar pergunta.';
            btn.disabled = false;
            btn.textContent = 'Perguntar';
        }
    })
    .catch(function(){
        btn.disabled = false;
        btn.textContent = 'Perguntar';
    });
}
</script>

<?php
// Chat widget removed — chat only accessible after purchase

include __DIR__ . '/../views/partials/storefront_footer.php';
include __DIR__ . '/../views/partials/footer.php';
ob_end_flush();
} catch (\Throwable $fatalEx) {
    // Discard any partial output so we can render a clean error page
    while (ob_get_level()) ob_end_clean();
    error_log('[produto.php] Fatal: ' . $fatalEx->getMessage() . ' in ' . $fatalEx->getFile() . ':' . $fatalEx->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    // Minimal error page — NO DB, NO includes, NO nav (to avoid cascading failures)
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Erro — Produto</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-[#121316] text-white font-[Inter,sans-serif] flex items-center justify-center px-4">
<div class="text-center max-w-md">
<div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 border border-red-400/30 flex items-center justify-center"><i data-lucide="alert-triangle" class="w-8 h-8 text-red-400"></i></div>
<h1 class="text-2xl font-bold mb-2">Ops! Algo deu errado</h1>
<p class="text-zinc-400 mb-2">Não foi possível carregar este produto.</p>
<p class="text-zinc-600 text-xs mb-6"><?= htmlspecialchars($fatalEx->getMessage()) ?></p>
<a href="/" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-greenx text-white font-bold text-sm hover:bg-greenx2 transition-all"><i data-lucide="home" class="w-4 h-4"></i> Voltar ao início</a>
</div>
<script>lucide.createIcons();</script>
</body>
</html>
<?php
}