<?php
declare(strict_types=1);
/**
 * Google OAuth 2.0 — pure PHP (no composer library).
 *
 * Settings are stored in platform_settings:
 *   google.client_id      – from Google Cloud Console
 *   google.client_secret  – from Google Cloud Console
 *   google.redirect_uri   – callback URL (auto-detected if empty)
 */

require_once __DIR__ . '/db.php';

/* ── Setting helpers ─────────────────────────────────────────────────── */

function googleSettingGet($conn, string $key, string $default = ''): string
{
    $fullKey = 'google.' . $key;
    $st = $conn->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
    if (!$st) return $default;
    $st->bind_param('s', $fullKey);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ? (string)$row['setting_value'] : $default;
}

function googleSettingSet($conn, string $key, string $value): void
{
    $fullKey = 'google.' . $key;
    $st = $conn->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
                          ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value");
    if ($st) {
        $st->bind_param('ss', $fullKey, $value);
        $st->execute();
        $st->close();
    }
}

function googleGetAllSettings($conn): array
{
    $clientId     = googleSettingGet($conn, 'client_id');
    $clientSecret = googleSettingGet($conn, 'client_secret');
    $redirectUri  = googleSettingGet($conn, 'redirect_uri');

    // Fallback to environment variables if DB settings are empty
    if ($clientId === '') {
        $clientId = (string)(envValue('GOOGLE_CLIENT_ID', '') ?: '');
    }
    if ($clientSecret === '') {
        $clientSecret = (string)(envValue('GOOGLE_CLIENT_SECRET', '') ?: '');
    }
    if ($redirectUri === '') {
        $redirectUri = (string)(envValue('GOOGLE_REDIRECT_URI', '') ?: '');
    }

    return [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
    ];
}

function googleIsConfigured($conn): bool
{
    $s = googleGetAllSettings($conn);
    return $s['client_id'] !== '' && $s['client_secret'] !== '';
}

/* ── OAuth flow helpers ──────────────────────────────────────────────── */

function googleGetRedirectUri($conn): string
{
    $custom = googleSettingGet($conn, 'redirect_uri');
    if ($custom !== '') return $custom;

    // Use APP_URL as canonical base (reliable behind reverse proxies like Railway)
    $appUrl = defined('APP_URL') ? APP_URL : '';
    if ($appUrl !== '' && preg_match('#^https?://#i', $appUrl)) {
        return rtrim($appUrl, '/') . '/google_callback';
    }

    // Fallback: auto-detect from request (check X-Forwarded-Proto for proxied HTTPS)
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif ((!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
           || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')) {
        $scheme = 'https';
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . BASE_PATH . '/google_callback';
}

/**
 * Build the Google OAuth authorization URL.
 * @param string $mode 'login' or 'register'
 * @param string $role 'comprador' or 'vendedor' (used only when mode=register)
 */
function googleAuthUrl($conn, string $returnTo = '', string $mode = 'login', string $role = 'comprador'): string
{
    $settings = googleGetAllSettings($conn);
    $redirectUri = googleGetRedirectUri($conn);

    // Store return_to and flow context in session
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['google_return_to'] = $returnTo;
    $_SESSION['google_oauth_mode'] = $mode; // 'login' or 'register'
    $_SESSION['google_oauth_role'] = $role; // role for registration

    // Generate CSRF state token
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $params = [
        'client_id'     => $settings['client_id'],
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'state'         => $state,
        'prompt'        => 'select_account',
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchange authorization code for access token, then fetch user profile.
 * Returns array with: email, name, picture, google_id — or null on failure.
 */
function googleExchangeCode($conn, string $code): ?array
{
    $settings = googleGetAllSettings($conn);
    $redirectUri = googleGetRedirectUri($conn);

    // Exchange code → token
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $postData = [
        'code'          => $code,
        'client_id'     => $settings['client_id'],
        'client_secret' => $settings['client_secret'],
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return null;

    $tokenData = json_decode((string)$resp, true);
    $accessToken = $tokenData['access_token'] ?? '';
    if ($accessToken === '') return null;

    // Fetch user info
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    ]);
    $userResp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$userResp) return null;

    $userInfo = json_decode((string)$userResp, true);
    if (empty($userInfo['email'])) return null;

    return [
        'email'     => (string)$userInfo['email'],
        'name'      => (string)($userInfo['name'] ?? ''),
        'picture'   => (string)($userInfo['picture'] ?? ''),
        'google_id' => (string)($userInfo['id'] ?? ''),
    ];
}

/**
 * Login-only via Google. User MUST already exist.
 * If no account is found, automatically creates one (auto-register).
 * Returns [true, user_array] on success, [false, error_message] on failure.
 */
function googleLogin($conn, array $googleUser): array
{
    require_once __DIR__ . '/auth.php';

    $email = $googleUser['email'];

    $existing = buscarUsuarioPorEmail($conn, $email);

    if (!$existing) {
        // Auto-register: create account automatically instead of showing error
        return googleRegister($conn, $googleUser, 'comprador');
    }

    if (!valorBooleano($existing['ativo'] ?? true, true)) {
        return [false, 'Conta desativada. Contate o suporte.'];
    }

    // Save Google photo as avatar if the user has no avatar yet
    $googlePhoto = trim((string)($googleUser['picture'] ?? ''));
    $currentAvatar = trim((string)($existing['avatar'] ?? ''));
    if ($googlePhoto !== '' && ($currentAvatar === '' || $currentAvatar === null)) {
        try {
            $stA = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            if ($stA) {
                $stA->bind_param('si', $googlePhoto, $existing['id']);
                $stA->execute();
                $stA->close();
                $existing['avatar'] = $googlePhoto;
            }
        } catch (\Throwable $e) {}
    }

    // Start session
    iniciarSessao();
    $guestCart = $_SESSION['store_cart'] ?? [];
    session_regenerate_id(true);
    $_SESSION = [];
    if (is_array($guestCart)) {
        $_SESSION['store_cart'] = $guestCart;
    }

    $role = normalizarRole((string)($existing['role'] ?? 'usuario'));
    $_SESSION['user_id'] = (int)$existing['id'];
    $_SESSION['nome'] = (string)$existing['nome'];
    $_SESSION['role'] = $role;
    $_SESSION['is_google'] = true;
    $_SESSION['user'] = [
        'id'               => (int)$existing['id'],
        'nome'             => (string)$existing['nome'],
        'email'            => (string)$existing['email'],
        'role'             => $role,
        'is_vendedor'      => valorBooleano($existing['is_vendedor'] ?? false) ? 1 : 0,
        'status_vendedor'  => (string)($existing['status_vendedor'] ?? 'nao_solicitado'),
        'avatar'           => $existing['avatar'] ?? null,
        'foto'             => $existing['avatar'] ?? null,
        'is_google'        => true,
    ];

    // Send "Novo Login Detectado" email (non-blocking)
    try {
        require_once __DIR__ . '/email.php';
        if (smtpConfigured($conn)) {
            $loginIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
            if (str_contains($loginIp, ',')) $loginIp = trim(explode(',', $loginIp)[0]);
            $loginDevice = $_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido';
            $loginDataHora = date('d/m/Y H:i:s');
            $loginHtml = emailNovoLogin((string)$existing['nome'], $loginDevice, $loginIp, $loginDataHora, '', $conn);
            smtpSend((string)$existing['email'], 'Novo login detectado – ' . APP_NAME, $loginHtml);
        }
    } catch (\Throwable $e) {
        error_log('[GoogleLogin] new login email error: ' . $e->getMessage());
    }

    return [true, $existing];
}

/**
 * Register a new user via Google. Creates the account with the given role.
 * Returns [true, user_array] on success, [false, error_message] on failure.
 */
function googleRegister($conn, array $googleUser, string $role = 'comprador'): array
{
    require_once __DIR__ . '/auth.php';

    $email = $googleUser['email'];
    $name  = $googleUser['name'] ?: explode('@', $email)[0];

    // Check if user already exists — if so, just log them in
    $existing = buscarUsuarioPorEmail($conn, $email);
    if ($existing) {
        return googleLogin($conn, $googleUser);
    }

    // Validate role
    $tipo = ($role === 'vendedor') ? 'vendedor' : 'comprador';

    // New user → register with a random password
    $randomPassword = bin2hex(random_bytes(16));
    [$ok, $msg] = cadastrarContaPublica($conn, $name, $email, $randomPassword, $tipo, '', false);

    if (!$ok) {
        return [false, $msg];
    }

    // Now log in the newly created user
    $newUser = buscarUsuarioPorEmail($conn, $email);
    if (!$newUser) {
        return [false, 'Erro ao buscar conta recém-criada.'];
    }

    // Save Google photo as initial avatar
    $googlePhoto = trim((string)($googleUser['picture'] ?? ''));
    if ($googlePhoto !== '' && (int)($newUser['id'] ?? 0) > 0) {
        try {
            $stA = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            if ($stA) {
                $stA->bind_param('si', $googlePhoto, $newUser['id']);
                $stA->execute();
                $stA->close();
                $newUser['avatar'] = $googlePhoto;
            }
        } catch (\Throwable $e) {}
    }

    iniciarSessao();
    $guestCart = $_SESSION['store_cart'] ?? [];
    session_regenerate_id(true);
    $_SESSION = [];
    if (is_array($guestCart)) {
        $_SESSION['store_cart'] = $guestCart;
    }

    $userRole = normalizarRole((string)($newUser['role'] ?? 'usuario'));
    $_SESSION['user_id'] = (int)$newUser['id'];
    $_SESSION['nome'] = (string)$newUser['nome'];
    $_SESSION['role'] = $userRole;
    $_SESSION['is_google'] = true;
    $_SESSION['user'] = [
        'id'               => (int)$newUser['id'],
        'nome'             => (string)$newUser['nome'],
        'email'            => (string)$newUser['email'],
        'role'             => $userRole,
        'is_vendedor'      => valorBooleano($newUser['is_vendedor'] ?? false) ? 1 : 0,
        'status_vendedor'  => (string)($newUser['status_vendedor'] ?? 'nao_solicitado'),
        'avatar'           => $newUser['avatar'] ?? null,
        'foto'             => $newUser['avatar'] ?? null,
        'is_google'        => true,
    ];

    // Send welcome + verification email (FIRST email on registration)
    try {
        require_once __DIR__ . '/email.php';
        $uid = (int)$newUser['id'];
        if ($uid > 0) {
            $emailResult = enviarEmailVerificacao($conn, $uid, 'boas_vindas');
            if ($emailResult !== true) {
                error_log('[GoogleAuth] welcome email failed for uid=' . $uid . ': ' . ($emailResult ?: 'unknown error'));
            }
        }
    } catch (\Throwable $e) {
        error_log('[GoogleAuth] welcome email exception: ' . $e->getMessage());
    }

    // Welcome notification (in-app + email via Step 3)
    try {
        require_once __DIR__ . '/notifications.php';
        $uid = (int)$newUser['id'];
        if ($uid > 0) {
            notificationsCreate($conn, $uid, 'sistema', 'Bem-vindo(a) ao ' . APP_NAME . '!', 'Sua conta foi criada com sucesso. Verifique seu e-mail para ativar sua conta.', '/minha-conta', ['skip_email' => true]);
            notificationsCreate($conn, $uid, 'sistema', 'Complete seu cadastro', 'Informe seu WhatsApp em Minha Conta para receber avisos importantes de compra, venda e segurança.', '/minha-conta', ['skip_email' => true]);
        }
    } catch (\Throwable $e) {
        error_log('[GoogleAuth] welcome notification error: ' . $e->getMessage());
    }

    return [true, $newUser];
}

/**
 * Legacy wrapper — kept for backward compatibility.
 * Find or create a user from Google OAuth data, then start session.
 * Returns [true, user_array] on success, [false, error_message] on failure.
 */
function googleLoginOrRegister($conn, array $googleUser): array
{
    return googleRegister($conn, $googleUser, 'comprador');
}
