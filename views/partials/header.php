<?php
// filepath: c:\xampp\htdocs\mercado_admin\views\partials\header.php
declare(strict_types=1);
require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/theme.php';
require_once __DIR__ . '/../../src/db.php';

// Load active theme
try {
    $_themeConn = (new Database())->connect();
} catch (\Throwable $_dbErr) {
    error_log('[header.php] DB connect failed: ' . $_dbErr->getMessage());
    $_themeConn = null;
}

// One-time migration: switch to Basefy theme with dark mode
if ($_themeConn !== null) {
    try {
        $_migFlag = themeSettingGet($_themeConn, '_migrated_basefy_v1', '');
        if ($_migFlag === '') {
            themeSettingSet($_themeConn, 'active_theme', 'basefy');
            themeSettingSet($_themeConn, 'color_mode', 'dark');
            themeSettingSet($_themeConn, '_migrated_basefy_v1', '1');
        }
    } catch (\Throwable $_migErr) {}
}

if ($_themeConn !== null) {
    try {
        $_activeTheme = themeGetActive($_themeConn);
    } catch (\Throwable $_thErr) {
        $_activeTheme = ['colors' => ['blackx'=>'#0E0324','blackx2'=>'#160636','blackx3'=>'#221048','greenx'=>'#8800E4','greenx2'=>'#7200C0','greenxd'=>'#6200AA'], 'color_mode' => 'dark'];
    }
} else {
    $_activeTheme = ['colors' => ['blackx'=>'#0E0324','blackx2'=>'#160636','blackx3'=>'#221048','greenx'=>'#8800E4','greenx2'=>'#7200C0','greenxd'=>'#6200AA'], 'color_mode' => 'dark'];
}
$_activeThemeKey = 'basefy';
$_themeColors = $_activeTheme['colors'];
$_themeMode   = 'dark';

if ($_themeConn !== null) {
    try {
        $_twColors = themeTailwindColors($_themeConn);
    } catch (\Throwable $_twErr) {
        $_twColors = ['blackx'=>'#0E0324','blackx2'=>'#160636','blackx3'=>'#221048','greenx'=>'#8800E4','greenx2'=>'#7200C0','greenxd'=>'#6200AA'];
    }
} else {
    $_twColors = ['blackx'=>'#0E0324','blackx2'=>'#160636','blackx3'=>'#221048','greenx'=>'#8800E4','greenx2'=>'#7200C0','greenxd'=>'#6200AA'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= htmlspecialchars($_activeThemeKey, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
  <meta name="description" content="Marketplace digital com pagamento via PIX e carteira integrada.">
  <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/img/02.png">
  <link rel="apple-touch-icon" href="<?= BASE_PATH ?>/assets/img/02.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Montserrat', 'sans-serif'] },
          colors: {
            blackx: '<?= $_twColors['blackx'] ?>',
            blackx2: '<?= $_twColors['blackx2'] ?>',
            blackx3: '<?= $_twColors['blackx3'] ?>',
            greenx: '<?= $_twColors['greenx'] ?>',
            greenx2: '<?= $_twColors['greenx2'] ?>',
            greenxd: '<?= $_twColors['greenxd'] ?>'
          }
        }
      }
    }
  </script>
  <style>
    <?php if ($_themeConn !== null): try { echo themeRenderCSSVars($_themeConn); } catch (\Throwable $_cssErr) {} endif; ?>
  </style>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/themes.css">
  <style>
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes scaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
    @keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
    @keyframes pulse-green { 0%, 100% { box-shadow: 0 0 0 0 rgba(var(--t-pulse-rgb),0.3); } 50% { box-shadow: 0 0 0 8px rgba(var(--t-pulse-rgb),0); } }
    .animate-fade-in-up { animation: fadeInUp 0.5s ease-out both; }
    .animate-fade-in { animation: fadeIn 0.4s ease-out both; }
    .animate-scale-in { animation: scaleIn 0.3s ease-out both; }
    .stagger-1 { animation-delay: 0.05s; }
    .stagger-2 { animation-delay: 0.1s; }
    .stagger-3 { animation-delay: 0.15s; }
    .stagger-4 { animation-delay: 0.2s; }
    .stagger-5 { animation-delay: 0.25s; }
    .stagger-6 { animation-delay: 0.3s; }
    .stagger-7 { animation-delay: 0.35s; }
    .stagger-8 { animation-delay: 0.4s; }
    .shimmer-bg { background: linear-gradient(90deg,transparent,rgba(255,255,255,0.03),transparent); background-size: 200% 100%; animation: shimmer 3s infinite; }
    .pulse-green { animation: pulse-green 2s ease-in-out infinite; }
    /* Page transition loader */
    #page-loader{position:fixed;top:0;left:0;width:100%;height:3px;z-index:99999;pointer-events:none;opacity:0;transition:opacity .15s}
    #page-loader.active{opacity:1}
    #page-loader .bar{height:100%;background:linear-gradient(90deg,var(--t-accent,#8800E4),var(--t-gradient-to,var(--t-accent-hover,#7200C0)),var(--t-accent,#8800E4));background-size:200% 100%;animation:loaderSlide 1.2s ease-in-out infinite;width:0;border-radius:0 2px 2px 0;transition:width .3s ease}
    @keyframes loaderSlide{0%{background-position:200% 0}100%{background-position:-200% 0}}
    .product-card { transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease; position: relative; }
    .product-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px -8px rgba(0,0,0,0.5), 0 0 0 1px rgba(var(--t-accent-rgb),0.15); }
    .product-card img { transition: transform 0.4s ease; }
    .product-card:hover img { transform: scale(1.05); }
    .line-clamp-2 { display: -webkit-box !important; -webkit-line-clamp: 2 !important; -webkit-box-orient: vertical !important; overflow: hidden !important; text-overflow: ellipsis; }
    .glass { background: rgba(18,19,22,0.6); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type="number"] { -moz-appearance: textfield; }
    input:focus, textarea:focus, select:focus { outline: none !important; box-shadow: none !important; }
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: var(--t-bg-body); }
    ::-webkit-scrollbar-thumb { background: var(--t-bg-border); border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: var(--t-scrollbar-hover); }
    /* ── PAGE PRELOADER ── */
    #page-preloader {
      position: fixed; inset: 0; z-index: 999999;
      background: rgba(0,0,0,0.88);
      display: flex; align-items: center; justify-content: center;
      transition: opacity 0.35s ease, visibility 0.35s;
    }
    #page-preloader.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
    .preloader-spinner {
      width: 36px; height: 36px;
      border: 2.5px solid rgba(255,255,255,0.08);
      border-top-color: var(--t-accent, #8800E4);
      border-radius: 50%;
      animation: spinnerRotate 0.7s linear infinite;
    }
    @keyframes spinnerRotate { to { transform: rotate(360deg); } }
    /* page-exit fade overlay */
    #page-exit-overlay {
      position: fixed; inset: 0; z-index: 999998;
      background: rgba(0,0,0,0.88);
      opacity: 0; pointer-events: none;
      transition: opacity 0.28s ease;
    }
    #page-exit-overlay.fading { opacity: 1; pointer-events: all; }
  </style>
</head>
<body class="min-h-screen bg-blackx text-white font-sans antialiased">
<script>document.documentElement.classList.remove('light-mode');</script>

<!-- Page preloader -->
<div id="page-preloader">
  <div class="preloader-spinner"></div>
</div>
<div id="page-exit-overlay"></div>

<script>
(function() {
  // Fade in on load
  window.addEventListener('load', function() {
    var el = document.getElementById('page-preloader');
    if (el) {
      setTimeout(function() { el.classList.add('hidden'); }, 80);
    }
  });

  // Fade out on navigation
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a');
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript') || href.startsWith('mailto') || a.target === '_blank') return;
    if (a.hasAttribute('data-no-transition')) return;

    e.preventDefault();
    var overlay = document.getElementById('page-exit-overlay');
    if (overlay) {
      overlay.classList.add('fading');
      setTimeout(function() { window.location.href = href; }, 280);
    } else {
      window.location.href = href;
    }
  });

  // Handle form submits (optional — keeps the feel consistent on POST)
  document.addEventListener('submit', function() {
    var overlay = document.getElementById('page-exit-overlay');
    if (overlay) overlay.classList.add('fading');
  });
})();
</script>