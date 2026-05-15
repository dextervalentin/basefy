<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
applySessionLifetime();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$query  = (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '')
         ? ('?' . $_SERVER['QUERY_STRING']) : '';

$legacyPrefix = '/mercado_admin/public';

// --- Legacy prefix redirect ---
if (str_starts_with($uri, $legacyPrefix)) {
    $cleanPath = substr($uri, strlen($legacyPrefix));
    if ($cleanPath === '' || $cleanPath === false) $cleanPath = '/';
    if (in_array($method, ['GET', 'HEAD'], true)) {
        header('Location: ' . $cleanPath . $query, true, 302);
        exit;
    }
    $uri = $cleanPath;
}

// --- Redirect .php URLs → clean URLs (GET/HEAD, 301) ---
if (in_array($method, ['GET', 'HEAD'], true) && str_contains($uri, '.php')) {
    $clean = $uri;
    $clean = preg_replace('#/index\.php$#', '/', $clean);   // /index.php → /
    $clean = preg_replace('#\.php$#', '', $clean);           // /xyz.php  → /xyz
    if ($clean !== $uri) {
        header('Location: ' . $clean . $query, true, 301);
        exit;
    }
}

$uri     = '/' . ltrim(rawurldecode($uri), '/');
$docRoot = realpath(__DIR__) ?: __DIR__;
$docRootPrefix = rtrim($docRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

// --- Static asset pass-through ---
$target = realpath($docRoot . DIRECTORY_SEPARATOR . ltrim($uri, '/'));
if ($target !== false && str_starts_with($target, $docRootPrefix) && is_file($target)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    if ($ext !== 'php') {
        // Cache static assets aggressively (PHP built-in server doesn't send these by default)
        static $cacheableExts = ['css'=>1,'js'=>1,'png'=>1,'jpg'=>1,'jpeg'=>1,'gif'=>1,'webp'=>1,'svg'=>1,'ico'=>1,'woff'=>1,'woff2'=>1,'ttf'=>1,'eot'=>1,'mp4'=>1,'webm'=>1];
        if (isset($cacheableExts[$ext])) {
            $etag = '"' . substr(md5_file($target) ?: '', 0, 16) . '"';
            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if ($ifNoneMatch === $etag) {
                http_response_code(304);
                return true;
            }
            header('Cache-Control: public, max-age=2592000, immutable');
            header('ETag: ' . $etag);
        }
        return false;
    }
    // Exact .php file hit (POST to API endpoints etc.)
    $_SERVER['SCRIPT_NAME'] = $uri;
    $_SERVER['PHP_SELF']    = $uri;
    $_SERVER['SCRIPT_FILENAME'] = $target;
    require $target;
    return true;
}

// --- Clean URL → .php resolution ---
$phpFile = realpath($docRoot . rtrim($uri, '/') . '.php');
if ($phpFile !== false && str_starts_with($phpFile, $docRoot) && is_file($phpFile)) {
    $_SERVER['SCRIPT_NAME']     = rtrim($uri, '/') . '.php';
    $_SERVER['PHP_SELF']        = rtrim($uri, '/') . '.php';
    $_SERVER['SCRIPT_FILENAME'] = $phpFile;
    require $phpFile;
    return true;
}

// --- Directory → index.php (e.g. /admin/ → /admin/index.php) ---
if (str_ends_with($uri, '/')) {
    $idx = realpath($docRoot . $uri . 'index.php');
    if ($idx !== false && str_starts_with($idx, $docRoot) && is_file($idx)) {
        require $idx;
        return true;
    }
}

// --- Saques: /saques/novo → saque_novo.php ---
if ($uri === '/saques/novo' || $uri === '/saques/novo/') {
    require $docRoot . '/saque_novo.php';
    return true;
}

// --- Email verification: /verificar-email → verificar_email.php ---
if ($uri === '/verificar-email' || $uri === '/verificar-email/') {
    require $docRoot . '/verificar_email.php';
    return true;
}

// --- Product slug: /p/slug-name ---
if (preg_match('#^/p/([a-z0-9][a-z0-9-]*)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    $_SERVER['QUERY_STRING'] = 'slug=' . urlencode($m[1]);
    require $docRoot . '/produto.php';
    return true;
}

// --- Category slug: /c/slug-name ---
if (preg_match('#^/c/([a-z0-9][a-z0-9-]*)/?$#', $uri, $m)) {
    $_GET['cat_slug'] = $m[1];
    $_SERVER['QUERY_STRING'] = 'cat_slug=' . urlencode($m[1]);
    require $docRoot . '/categorias.php';
    return true;
}

// --- Vendor slug: /loja/slug-name ---
if (preg_match('#^/loja/([a-z0-9][a-z0-9-]*)/?$#', $uri, $m)) {
    $_GET['vendor_slug'] = $m[1];
    $_SERVER['QUERY_STRING'] = 'vendor_slug=' . urlencode($m[1]);
    require $docRoot . '/loja.php';
    return true;
}

// --- Blog author: /blog/autor/ID ---
if (preg_match('#^/blog/autor/(\d+)/?$#', $uri, $m)) {
    $_GET['author_id'] = $m[1];
    $_SERVER['QUERY_STRING'] = 'author_id=' . urlencode($m[1]);
    require $docRoot . '/blog_author.php';
    return true;
}

// --- Blog category: /blog/categoria/slug-name ---
if (preg_match('#^/blog/categoria/([a-z0-9][a-z0-9-]*)/?$#', $uri, $m)) {
    $_GET['cat_slug'] = $m[1];
    $_SERVER['QUERY_STRING'] = 'cat_slug=' . urlencode($m[1]);
    require $docRoot . '/blog_categoria.php';
    return true;
}

// --- Blog post slug: /blog/slug-name ---
if (preg_match('#^/blog/([a-z0-9][a-z0-9-]*)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    $_SERVER['QUERY_STRING'] = 'slug=' . urlencode($m[1]);
    require $docRoot . '/blog_post.php';
    return true;
}

// --- Affiliate referral shortlink: /ref/CODE or /ref/CODE/product-slug ---
if (preg_match('#^/ref/([a-z0-9]{4,32})(?:/([a-z0-9][a-z0-9-]*))?/?$#', $uri, $m)) {
    $_GET['ref'] = $m[1];
    if (!empty($m[2])) {
        $_GET['slug'] = $m[2];
        $_SERVER['QUERY_STRING'] = 'ref=' . urlencode($m[1]) . '&slug=' . urlencode($m[2]);
        require $docRoot . '/produto.php';
    } else {
        $_SERVER['QUERY_STRING'] = 'ref=' . urlencode($m[1]);
        require $docRoot . '/index.php';
    }
    return true;
}

// --- Fallback → index.php ---
require $docRoot . '/index.php';
return true;
