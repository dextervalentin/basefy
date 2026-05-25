<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\admin_users.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/user_identity.php';

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

    $sqlList = "SELECT id, nome, email, role, ativo, is_vendedor, status_vendedor, wallet_saldo, criado_em
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

    $sqlList = "SELECT id, nome, email, role, ativo, is_vendedor, status_vendedor, wallet_saldo, criado_em
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

function adminUsersEnsureWalletColumns($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try { $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS wallet_frozen BOOLEAN NOT NULL DEFAULT FALSE"); } catch (Throwable $e) {}
    try { $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS wallet_frozen_at TIMESTAMP NULL"); } catch (Throwable $e) {}
    try { $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS wallet_frozen_by BIGINT NULL"); } catch (Throwable $e) {}
    try { $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS status_conta VARCHAR(20) DEFAULT 'ativa'"); } catch (Throwable $e) {}
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS wallet_admin_movements (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id BIGINT NOT NULL,
            admin_id BIGINT NULL,
            action VARCHAR(30) NOT NULL,
            amount NUMERIC(12,2) NOT NULL DEFAULT 0.00,
            balance_before NUMERIC(12,2) NOT NULL DEFAULT 0.00,
            balance_after NUMERIC(12,2) NOT NULL DEFAULT 0.00,
            reason VARCHAR(120),
            note TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Throwable $e) {}
}

function adminUserWalletHistorico($conn, int $userId, int $limit = 30): array
{
    adminUsersEnsureWalletColumns($conn);
    $limit = max(1, min(100, $limit));
    try {
        $st = $conn->prepare("SELECT a.*, adm.nome AS admin_nome, adm.email AS admin_email
            FROM wallet_admin_movements a
            LEFT JOIN users adm ON adm.id = a.admin_id
            WHERE a.user_id = ?
            ORDER BY a.id DESC
            LIMIT {$limit}");
        $st->bind_param('i', $userId);
        $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function adminUserWalletAjustar($conn, int $userId, int $adminId, string $action, float $amount, string $reason, string $note = ''): array
{
    adminUsersEnsureWalletColumns($conn);
    $action = strtolower(trim($action));
    if (!in_array($action, ['credit', 'debit'], true)) return [false, 'Tipo de movimentação inválido.'];
    if ($userId <= 0 || $adminId <= 0 || $amount <= 0) return [false, 'Dados inválidos.'];
    $reason = trim($reason);
    if ($reason === '') return [false, 'Informe o motivo da movimentação.'];

    $conn->begin_transaction();
    try {
        $st = $conn->prepare('SELECT wallet_saldo FROM users WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) {
            $conn->rollback();
            return [false, 'Usuário não encontrado.'];
        }

        $before = (float)($row['wallet_saldo'] ?? 0);
        $after = $action === 'credit' ? $before + $amount : $before - $amount;
        if ($after < 0) {
            $conn->rollback();
            return [false, 'Saldo insuficiente para remover esse valor.'];
        }

        $up = $conn->prepare('UPDATE users SET wallet_saldo = ? WHERE id = ?');
        $up->bind_param('di', $after, $userId);
        $up->execute();

        $ins = $conn->prepare("INSERT INTO wallet_admin_movements (user_id, admin_id, action, amount, balance_before, balance_after, reason, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param('iisdddss', $userId, $adminId, $action, $amount, $before, $after, $reason, $note);
        $ins->execute();
        $adjustId = (int)$conn->insert_id;

        try {
            $tipo = $action === 'credit' ? 'credito' : 'debito';
            $origem = 'admin_adjustment';
            $refTipo = 'wallet_admin_movement';
            $descricao = trim($reason . ($note !== '' ? ' - ' . $note : ''));
            $tx = $conn->prepare("INSERT INTO wallet_transactions (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $tx->bind_param('isssids', $userId, $tipo, $origem, $refTipo, $adjustId, $amount, $descricao);
            $tx->execute();
        } catch (Throwable $e) {}

        $conn->commit();
        return [true, 'Wallet atualizada com sucesso.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Falha ao movimentar wallet.'];
    }
}

function adminUserWalletCongelar($conn, int $userId, int $adminId, bool $freeze, string $reason = ''): array
{
    adminUsersEnsureWalletColumns($conn);
    if ($userId <= 0 || $adminId <= 0) return [false, 'Dados inválidos.'];
    $frozen = $freeze ? 1 : 0;
    $frozenAtSql = $freeze ? 'CURRENT_TIMESTAMP' : 'NULL';
    $conn->begin_transaction();
    try {
        $saldo = 0.0;
        $st = $conn->prepare('SELECT wallet_saldo FROM users WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) {
            $conn->rollback();
            return [false, 'Usuário não encontrado.'];
        }
        $saldo = (float)($row['wallet_saldo'] ?? 0);

        $up = $conn->prepare('UPDATE users SET wallet_frozen = ?, wallet_frozen_at = ' . $frozenAtSql . ', wallet_frozen_by = ? WHERE id = ?');
        $up->bind_param('iii', $frozen, $adminId, $userId);
        $up->execute();

        $action = $freeze ? 'freeze' : 'unfreeze';
        $reason = trim($reason) !== '' ? trim($reason) : ($freeze ? 'Wallet congelada pelo admin' : 'Wallet desbloqueada pelo admin');
        $ins = $conn->prepare("INSERT INTO wallet_admin_movements (user_id, admin_id, action, amount, balance_before, balance_after, reason, note) VALUES (?, ?, ?, 0, ?, ?, ?, '')");
        $ins->bind_param('iisdds', $userId, $adminId, $action, $saldo, $saldo, $reason);
        $ins->execute();

        $conn->commit();
        return [true, $freeze ? 'Wallet congelada.' : 'Wallet desbloqueada.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Falha ao atualizar bloqueio da wallet.'];
    }
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
    string $novaSenha = '',
    string $telefone = '',
    string $cpf = ''
): array {
    $nome = trim($nome);
    $email = trim($email);
    $telefone = trim($telefone);
    $cpfDigits = identityDigits($cpf);

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

    if ($cpfDigits !== '' && strlen($cpfDigits) !== 11) {
        return [false, 'CPF inválido.'];
    }

    if ($cpfDigits !== '' && userCpfJaExiste($conn, $cpfDigits, $id)) {
        return [false, 'Este CPF já está cadastrado em outra conta.'];
    }

    $isVendedor = $role === 'vendedor' ? 1 : 0;
    $status = $role === 'vendedor' ? $statusVendedor : 'nao_solicitado';
    $cols = identityTableColumns($conn, 'users');

    $sets = ['nome = ?', 'email = ?', 'role = ?', 'is_vendedor = ?', 'status_vendedor = ?'];
    $types = 'sssis';
    $params = [$nome, $email, $role, $isVendedor, $status];

    if (in_array('telefone', $cols, true)) {
        $sets[] = 'telefone = ?';
        $types .= 's';
        $params[] = $telefone !== '' ? $telefone : null;
    }

    if (in_array('cpf', $cols, true)) {
        $sets[] = 'cpf = ?';
        $types .= 's';
        $params[] = $cpfDigits !== '' ? identityFormatCpf($cpfDigits) : null;
    }

    if ($novaSenha !== '') {
        if (strlen($novaSenha) < 8) {
            return [false, 'A nova senha deve ter no mínimo 8 caracteres.'];
        }
        $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $sets[] = 'senha = ?';
        $types .= 's';
        $params[] = $hash;
    }

    $params[] = $id;
    $types .= 'i';

    $stmt = $conn->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->bind_param($types, ...$params);

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