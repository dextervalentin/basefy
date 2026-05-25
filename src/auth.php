<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\auth.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function iniciarSessao(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        applySessionLifetime();
        session_start();
    }
}

function usuarioLogado(): bool
{
    iniciarSessao();
    return isset($_SESSION['user_id']);
}

function exigirLogin(): void
{
    if (!usuarioLogado()) {
        header('Location: login');
        exit;
    }
}

/**
 * Require verified account (complete profile, phone, documents, email).
 * Used for withdrawals and other sensitive operations.
 */
function exigirVerificado(): bool
{
    if (!usuarioLogado()) {
        header('Location: ' . BASE_PATH . '/login');
        exit;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    return contaVerificada($uid);
}

/**
 * Check if user account is verified (has name, email, phone, document).
 * Also checks user_verifications table for full verification (dados, telefone, email, documentos).
 */
function contaVerificada(int $uid): bool
{
    if ($uid <= 0) return false;

    // Admin accounts are always considered verified
    if (roleAtual() === 'admin') return true;

    $conn = (new Database())->connect();

    // Check user_verifications table first (advanced verification system)
    try {
        $st = $conn->prepare("SELECT tipo, status FROM user_verifications WHERE user_id = ?");
        $st->bind_param('i', $uid);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        // Table exists — use new verification system
        $required = ['dados', 'email', 'documentos']; // telefone excluded until SMS is implemented
        $verified = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'verificado') $verified[] = $r['tipo'];
        }
        foreach ($required as $req) {
            if (!in_array($req, $verified, true)) return false;
        }
        return true;
    } catch (\Throwable $e) {
        // Table may not exist yet — fall through to legacy check
    }

    // Legacy fallback: check basic profile fields
    $cols = [];
    $rs = $conn->query("SHOW COLUMNS FROM users");
    if ($rs) while ($r = $rs->fetch_assoc()) $cols[] = strtolower((string)$r['Field']);

    $nameCol = in_array('nome', $cols) ? 'nome' : (in_array('name', $cols) ? 'name' : null);
    $emailCol = in_array('email', $cols) ? 'email' : null;
    $phoneCol = in_array('telefone', $cols) ? 'telefone' : (in_array('phone', $cols) ? 'phone' : null);
    $docCol = in_array('cpf', $cols) ? 'cpf' : (in_array('documento', $cols) ? 'documento' : null);

    $fields = array_filter([$nameCol, $emailCol, $phoneCol, $docCol]);
    if (empty($fields)) return false;

    $selects = implode(', ', $fields);
    $st = $conn->prepare("SELECT {$selects} FROM users WHERE id = ? LIMIT 1");
    if (!$st) return false;
    $st->bind_param('i', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return false;

    foreach ($fields as $f) {
        if (trim((string)($row[$f] ?? '')) === '') return false;
    }
    return true;
}

function usuarioAtual(): ?array
{
    iniciarSessao();
    return $_SESSION['user'] ?? null;
}

function validarEmail(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function valorBooleano(mixed $valor, bool $padrao = false): bool
{
    if ($valor === null) {
        return $padrao;
    }

    if (is_bool($valor)) {
        return $valor;
    }

    if (is_int($valor) || is_float($valor)) {
        return ((int)$valor) === 1;
    }

    $normalizado = strtolower(trim((string)$valor));

    if (in_array($normalizado, ['1', 'true', 't', 'yes', 'y', 'on'], true)) {
        return true;
    }

    if (in_array($normalizado, ['0', 'false', 'f', 'no', 'n', 'off', ''], true)) {
        return false;
    }

    return $padrao;
}

function buscarUsuarioPorEmail($conn, string $email): ?array
{
    $stmt = $conn->prepare('SELECT * FROM users WHERE lower(btrim(email)) = lower(btrim(?)) LIMIT 1');
    $stmt->execute([$email]);
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    return $row ?: null;
}

function cadastrarContaPublica($conn, string $nome, string $email, string $senha, string $tipo = 'comprador', string $telefone = '', bool $telefoneObrigatorio = false): array
{
    $nome = trim($nome);
    $email = trim($email);
    $telefone = preg_replace('/\D+/', '', trim($telefone)) ?? '';
    // Unified accounts — everyone registers as 'comprador' (mapped to 'usuario')
    $tipo = 'comprador';

    if ($email === '' || $senha === '') {
        return [false, 'Preencha e-mail e senha.'];
    }

    if ($telefoneObrigatorio && $telefone === '') {
        return [false, 'Informe seu WhatsApp para concluir o cadastro.'];
    }

    if ($telefone !== '' && (strlen($telefone) < 10 || strlen($telefone) > 13)) {
        return [false, 'Informe um WhatsApp válido com DDD.'];
    }

    // Name is optional — use email prefix if not provided
    if ($nome === '') {
        $nome = explode('@', $email)[0];
    }

    if (!validarEmail($email)) {
        return [false, 'E-mail inválido.'];
    }

    if (strlen($senha) < 8) {
        return [false, 'A senha deve ter no mínimo 8 caracteres.'];
    }

    if (buscarUsuarioPorEmail($conn, $email)) {
        return [false, 'Este e-mail já está cadastrado.'];
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);

    $isVendedor = $tipo === 'vendedor' ? 1 : 0;
    $statusVendedor = $tipo === 'vendedor' ? 'nao_solicitado' : 'nao_solicitado';

    try { $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS telefone VARCHAR(40)"); } catch (\Throwable $e) {}

    // Generate unique slug from name
    require_once __DIR__ . '/storefront.php';
    _sfEnsureVendorSlugColumn($conn);
    $baseSlug = sfGenerateSlug($nome);
    $slug = $baseSlug;
    $sfx = 1;
    while (true) {
        $chkSt = $conn->prepare("SELECT id FROM users WHERE slug = ? LIMIT 1");
        if (!$chkSt) break;
        $chkSt->bind_param('s', $slug);
        $chkSt->execute();
        if (!$chkSt->get_result()->fetch_assoc()) { $chkSt->close(); break; }
        $chkSt->close();
        $slug = $baseSlug . '-' . (++$sfx);
    }

    $stmt = $conn->prepare(
        'INSERT INTO users (nome, email, senha, avatar, role, is_vendedor, status_vendedor, slug, telefone)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$nome, $email, $hash, $tipo, $isVendedor, $statusVendedor, $slug, $telefone]);

    return [true, 'Conta criada com sucesso.'];
}

function autenticarConta($conn, string $email, string $senha): array
{
    $email = trim($email);

    if ($email === '' || $senha === '') {
        return [false, 'Informe e-mail e senha.'];
    }

    $user = buscarUsuarioPorEmail($conn, $email);
    $hashOuSenha = (string)($user['senha'] ?? '');
    $senhaValida = $hashOuSenha !== '' && (password_verify($senha, $hashOuSenha) || hash_equals($hashOuSenha, $senha));

    if (!$user || !$senhaValida) {
        return [false, 'E-mail ou senha inválidos.'];
    }

    if (!valorBooleano($user['ativo'] ?? true, true)) {
        return [false, 'Conta desativada. Contate o suporte.'];
    }

    iniciarSessao();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'nome' => $user['nome'],
        'email' => $user['email'],
        'role' => normalizarRole((string)($user['role'] ?? 'usuario')),
        'is_vendedor' => valorBooleano($user['is_vendedor'] ?? false) ? 1 : 0,
        'status_vendedor' => $user['status_vendedor'],
        'avatar' => $user['avatar']
    ];

    return [true, 'Login realizado com sucesso.'];
}

function normalizarRole(?string $role): string
{
    $r = mb_strtolower(trim((string)$role));
    return match ($r) {
        'admin', 'administrador' => 'admin',
        'vendedor', 'vendendor', 'seller', 'vendor' => 'vendedor',
        default => 'usuario',
    };
}

function roleAtual(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $raw = $_SESSION['role'] ?? ($_SESSION['user']['role'] ?? 'usuario');
    return normalizarRole((string)$raw);
}

function exigirAdmin(): void
{
    if (roleAtual() !== 'admin') {
        header('Location: ' . BASE_PATH . '/login');
        exit;
    }
}

/**
 * Legacy — now just requires login. All users can access vendor features.
 */
function exigirVendedor(): void
{
    exigirLogin();
}

/**
 * Legacy — now just requires login. No role distinction.
 */
function exigirUsuario(): void
{
    exigirLogin();
}

function redirecionarPorPerfil(): void
{
    $u = usuarioAtual();
    if (!$u) {
        header('Location: login');
        exit;
    }

    $role = normalizarRole((string)($u['role'] ?? 'usuario'));

    if ($role === 'admin') {
        header('Location: admin/index');
        exit;
    }

    // Unified dashboard for all users
    header('Location: ' . BASE_PATH . '/dashboard');
    exit;
}

function redirectDashboardByRole(string $role): void
{
    $base = BASE_PATH . '/';

    if (mb_strtolower(trim($role)) === 'admin') {
        header('Location: ' . $base . 'admin/dashboard');
        exit;
    }

    // Unified dashboard for all users
    header('Location: ' . $base . 'dashboard');
    exit;
}

function normalizarStatusVendedor(string $status): string
{
    $s = mb_strtolower(trim($status));
    return match ($s) {
        'aprovado', 'approved' => 'aprovado',
        'rejeitado', 'recusado', 'rejected' => 'rejeitado',
        'pendente', 'aberto', 'em_analise', 'analise' => 'pendente',
        default => 'nao_solicitado',
    };
}

function statusVendedorDoUsuario(int $userId): string
{
    if ($userId <= 0) {
        return 'nao_solicitado';
    }

    $conn = (new Database())->connect();
    $st = $conn->prepare('SELECT status_vendedor FROM users WHERE id = ? LIMIT 1');
    if (!$st) {
        return 'nao_solicitado';
    }
    $st->execute([$userId]);
    $row = $st->get_result()->fetch_assoc() ?: [];
    $status = normalizarStatusVendedor((string)($row['status_vendedor'] ?? 'nao_solicitado'));

    iniciarSessao();
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user']['status_vendedor'] = $status;
    }

    return $status;
}

function vendedorEstaAprovado(): bool
{
    iniciarSessao();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    return statusVendedorDoUsuario($uid) === 'aprovado';
}