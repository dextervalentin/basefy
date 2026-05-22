<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\admin_users.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function statusVendedorValido(string $status): bool
{
    return in_array($status, ['nao_solicitado', 'pendente', 'aprovado', 'rejeitado'], true);
}

function normalizarRolePainel(string $role): string
{
    $r = mb_strtolower(trim($role));
    return match ($r) {
        'admin', 'administrador' => 'admin',
        'vendedor', 'vendor', 'seller', 'vendendor' => 'vendedor',
        'usuario', 'comprador', 'user', 'cliente' => 'usuario',
        default => 'usuario',
    };
}

function rolesEquivalentes(string $role): array
{
    $r = normalizarRolePainel($role);
    return match ($r) {
        'admin' => ['admin', 'administrador'],
        'vendedor' => ['vendedor', 'vendor', 'seller', 'vendendor'],
        default => ['usuario', 'comprador', 'user', 'cliente'],
    };
}

function listarUsuariosPorRole($conn, string $role, string $busca = '', int $pagina = 1, int $porPagina = 10): array
{
    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $porPagina;
    $like = '%' . $busca . '%';
    $roles = rolesEquivalentes($role);

    $ph = implode(',', array_fill(0, count($roles), '?'));

    $sqlCount = "SELECT COUNT(*) AS total
                 FROM users
                 WHERE role IN ($ph) AND (nome LIKE ? OR email LIKE ?)";
    $stmtCount = $conn->prepare($sqlCount);
    $paramsCount = array_merge($roles, [$like, $like]);
    $stmtCount->execute($paramsCount);
    $total = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);

    $sqlList = "SELECT id, nome, email, role, ativo, is_vendedor, status_vendedor, criado_em
                FROM users
                WHERE role IN ($ph) AND (nome LIKE ? OR email LIKE ?)
                ORDER BY id DESC
                LIMIT ?, ?";
    $stmtList = $conn->prepare($sqlList);
    $paramsList = array_merge($roles, [$like, $like, $offset, $porPagina]);
    $stmtList->execute($paramsList);
    $itens = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);

    return [
        'itens' => $itens,
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => max(1, (int)ceil($total / $porPagina)),
    ];
}

/**
 * List ALL non-admin users (compradores + vendedores) in a single list.
 */
function listarTodosUsuarios($conn, string $busca = '', int $pagina = 1, int $porPagina = 10): array
{
    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $porPagina;
    $like = '%' . $busca . '%';

    // Exclude admin roles
    $adminRoles = rolesEquivalentes('admin');
    $ph = implode(',', array_fill(0, count($adminRoles), '?'));

    $sqlCount = "SELECT COUNT(*) AS total FROM users WHERE role NOT IN ($ph) AND (nome LIKE ? OR email LIKE ?)";
    $stCount = $conn->prepare($sqlCount);
    $stCount->execute(array_merge($adminRoles, [$like, $like]));
    $total = (int)($stCount->get_result()->fetch_assoc()['total'] ?? 0);

    $sqlList = "SELECT id, nome, email, role, ativo, is_vendedor, status_vendedor, criado_em
                FROM users WHERE role NOT IN ($ph) AND (nome LIKE ? OR email LIKE ?)
                ORDER BY id DESC LIMIT ?, ?";
    $stList = $conn->prepare($sqlList);
    $stList->execute(array_merge($adminRoles, [$like, $like, $offset, $porPagina]));
    $itens = $stList->get_result()->fetch_all(MYSQLI_ASSOC);

    return [
        'itens' => $itens,
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => max(1, (int)ceil($total / $porPagina)),
    ];
}

function obterUsuarioPorIdRole($conn, int $id, string $role): ?array
{
    $roles = rolesEquivalentes($role);
    $ph = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role IN ($ph) LIMIT 1");
    $stmt->execute(array_merge([$id], $roles));
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function adminUsersTableExists($conn, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    $rs = $conn->query("SHOW TABLES LIKE '" . $table . "'");
    return (bool)($rs && $rs->fetch_assoc());
}

function obterUsuarioDetalhesPainel($conn, int $id): array
{
    $usuario = obterUsuarioPorId($conn, $id);
    if (!$usuario) {
        return ['usuario' => [], 'seller_profile' => null, 'verifications' => [], 'documents' => []];
    }

    unset($usuario['senha']);
    $sellerProfile = null;
    $verifications = [];
    $documents = [];

    if (adminUsersTableExists($conn, 'seller_profiles')) {
        $st = $conn->prepare('SELECT * FROM seller_profiles WHERE user_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $id);
            $st->execute();
            $sellerProfile = $st->get_result()->fetch_assoc() ?: null;
        }
    }

    if (adminUsersTableExists($conn, 'user_verifications')) {
        $st = $conn->prepare('SELECT tipo, status, dados, observacao, criado_em, atualizado FROM user_verifications WHERE user_id = ? ORDER BY tipo ASC');
        if ($st) {
            $st->bind_param('i', $id);
            $st->execute();
            $verifications = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        }
    }

    if (adminUsersTableExists($conn, 'user_verification_docs')) {
        $st = $conn->prepare('SELECT tipo_doc, status, arquivo, observacao, criado_em FROM user_verification_docs WHERE user_id = ? ORDER BY id DESC');
        if ($st) {
            $st->bind_param('i', $id);
            $st->execute();
            $documents = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        }
    }

    return [
        'usuario' => $usuario,
        'seller_profile' => $sellerProfile,
        'verifications' => $verifications,
        'documents' => $documents,
    ];
}

function emailJaExiste($conn, string $email, ?int $ignorarId = null): bool
{
    if ($ignorarId) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $email, $ignorarId);
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
    }
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function criarUsuarioPainel(
    $conn,
    string $nome,
    string $email,
    string $senha,
    string $role,
    string $statusVendedor = 'nao_solicitado'
): array {
    $nome = trim($nome);
    $email = trim($email);

    if ($nome === '' || $email === '' || $senha === '') {
        return [false, 'Preencha nome, e-mail e senha.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'E-mail inválido.'];
    }

    if (strlen($senha) < 8) {
        return [false, 'A senha deve ter no mínimo 8 caracteres.'];
    }

    $role = normalizarRolePainel($role);

    if (!in_array($role, ['admin', 'usuario', 'vendedor'], true)) {
        return [false, 'Perfil inválido.'];
    }

    if ($role === 'vendedor' && !statusVendedorValido($statusVendedor)) {
        return [false, 'Status de vendedor inválido.'];
    }

    if (emailJaExiste($conn, $email)) {
        return [false, 'Este e-mail já está em uso.'];
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $isVendedor = $role === 'vendedor' ? 1 : 0;
    $status = $role === 'vendedor' ? $statusVendedor : 'nao_solicitado';
    $avatar = null;

    $stmt = $conn->prepare(
        'INSERT INTO users (nome, email, senha, avatar, role, is_vendedor, status_vendedor)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssis', $nome, $email, $hash, $avatar, $role, $isVendedor, $status);
    $stmt->execute();

    return [true, 'Registro criado com sucesso.'];
}

function atualizarUsuarioPainel(
    $conn,
    int $id,
    string $nome,
    string $email,
    string $role,
    string $statusVendedor = 'nao_solicitado',
    string $novaSenha = ''
): array {
    $nome = trim($nome);
    $email = trim($email);

    if ($id <= 0 || $nome === '' || $email === '') {
        return [false, 'Dados inválidos.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'E-mail inválido.'];
    }

    $role = normalizarRolePainel($role);

    if (!in_array($role, ['admin', 'usuario', 'vendedor'], true)) {
        return [false, 'Perfil inválido.'];
    }

    if ($role === 'vendedor' && !statusVendedorValido($statusVendedor)) {
        return [false, 'Status de vendedor inválido.'];
    }

    if (emailJaExiste($conn, $email, $id)) {
        return [false, 'Este e-mail já está em uso por outra conta.'];
    }

    $isVendedor = $role === 'vendedor' ? 1 : 0;
    $status = $role === 'vendedor' ? $statusVendedor : 'nao_solicitado';

    if ($novaSenha !== '') {
        if (strlen($novaSenha) < 8) {
            return [false, 'A nova senha deve ter no mínimo 8 caracteres.'];
        }
        $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            'UPDATE users
             SET nome = ?, email = ?, role = ?, is_vendedor = ?, status_vendedor = ?, senha = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sssissi', $nome, $email, $role, $isVendedor, $status, $hash, $id);
    } else {
        $stmt = $conn->prepare(
            'UPDATE users
             SET nome = ?, email = ?, role = ?, is_vendedor = ?, status_vendedor = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sssisi', $nome, $email, $role, $isVendedor, $status, $id);
    }

    $stmt->execute();
    return [true, 'Registro atualizado com sucesso.'];
}

function listarUsuariosGerais($conn, string $busca = '', int $pagina = 1, int $porPagina = 10): array
{
    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $porPagina;
    $like = '%' . $busca . '%';

    $sqlCount = "SELECT COUNT(*) AS total
                 FROM users
                 WHERE role IN ('usuario','comprador','user','cliente','vendedor')
                   AND (nome LIKE ? OR email LIKE ?)";
    $stmtCount = $conn->prepare($sqlCount);
    $stmtCount->bind_param('ss', $like, $like);
    $stmtCount->execute();
    $total = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);

    $sqlList = "SELECT id, nome, email, role, is_vendedor, status_vendedor, criado_em
                FROM users
                WHERE role IN ('usuario','comprador','user','cliente','vendedor')
                  AND (nome LIKE ? OR email LIKE ?)
                ORDER BY id DESC
                LIMIT ?, ?";
    $stmtList = $conn->prepare($sqlList);
    $stmtList->bind_param('ssii', $like, $like, $offset, $porPagina);
    $stmtList->execute();
    $itens = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);

    return [
        'itens' => $itens,
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => max(1, (int)ceil($total / $porPagina)),
    ];
}

function obterUsuarioPorId($conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function atualizarStatusAtivoUsuario($conn, int $id, int $ativo, array $rolesPermitidos = ['comprador', 'vendedor']): array
{
    $id = (int)$id;
    $ativo = $ativo === 1 ? 1 : 0;
    if ($id <= 0) return [false, 'ID inválido.'];

    $roles = expandirRolesPermitidos($rolesPermitidos);
    $ph = implode(',', array_fill(0, count($roles), '?'));

    // valida se existe e se role é permitido
    $sqlCheck = "SELECT id, role FROM users WHERE id = ? AND LOWER(role) IN ($ph) LIMIT 1";
    $st = $conn->prepare($sqlCheck);
    $types = 'i' . str_repeat('s', count($roles));
    $params = array_merge([$id], $roles);
    $st->bind_param($types, ...$params);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();

    if (!$u) {
        return [false, 'Usuário não encontrado ou perfil não permitido.'];
    }

    $up = $conn->prepare("UPDATE users SET ativo = ? WHERE id = ?");
    $up->bind_param('ii', $ativo, $id);
    $ok = $up->execute();

    if (!$ok) return [false, 'Erro ao atualizar status.'];
    return [true, $ativo ? 'Usuário ativado.' : 'Usuário desativado.'];
}

function expandirRolesPermitidos(array $roles): array
{
    $out = [];
    foreach ($roles as $r) {
        foreach (rolesEquivalentes((string)$r) as $eq) {
            $out[] = mb_strtolower(trim($eq));
        }
    }
    $out = array_values(array_unique(array_filter($out)));
    return $out ?: ['usuario', 'comprador', 'user', 'cliente', 'vendedor', 'vendor', 'seller', 'vendendor'];
}