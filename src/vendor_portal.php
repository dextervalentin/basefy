<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\vendor_portal.php
declare(strict_types=1);

require_once __DIR__ . '/media.php';
require_once __DIR__ . '/storefront.php';

function vpColunaExiste($c, string $t, string $col): bool {
    $st = $c->prepare("SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->bind_param('ss', $t, $col);
    $st->execute();
    return (bool)$st->get_result()->fetch_assoc();
}

function vpColunaVendedorProdutos($c): ?string {
    if (vpColunaExiste($c, 'products', 'vendedor_id')) return 'vendedor_id';
    if (vpColunaExiste($c, 'products', 'user_id')) return 'user_id';
    return null;
}

function colVendedorProduto($c): ?string {
    $q = $c->query("SHOW COLUMNS FROM products");
    if (!$q) return null;
    $cols = array_map(fn($r) => strtolower((string)$r['Field']), $q->fetch_all(MYSQLI_ASSOC));
    if (in_array('vendedor_id', $cols, true)) return 'vendedor_id';
    if (in_array('user_id', $cols, true)) return 'user_id';
    return null;
}

function vpProdutoTemColuna($c, string $column): bool {
    $q = $c->query("SHOW COLUMNS FROM products");
    if (!$q) return false;
    $cols = array_map(fn($r) => strtolower((string)$r['Field']), $q->fetch_all(MYSQLI_ASSOC));
    return in_array(strtolower($column), $cols, true);
}

function listarCategoriasProdutoAtivasVendor($c): array {
    $q = $c->query("SELECT id, nome FROM categories WHERE tipo='produto' AND ativo=1 ORDER BY nome");
    return $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
}

function listarMeusProdutos($c, int $uid, $filters = []): array
{
    if ($uid <= 0) return [];

    $col = vpColunaVendedorProdutos($c);
    if (!$col) return [];

    if (is_string($filters)) {
        $filters = ['q' => $filters];
    }
    if (!is_array($filters)) {
        $filters = [];
    }

    $q = trim((string)($filters['q'] ?? ''));
    $categoriaId = (int)($filters['categoria_id'] ?? 0);
    $status = strtolower(trim((string)($filters['status'] ?? '')));
    $hasApprovalStatus = vpProdutoTemColuna($c, 'status_aprovacao');
    $hasMotivoRecusa = vpProdutoTemColuna($c, 'motivo_recusa');

    $statusExpr = $hasApprovalStatus ? "COALESCE(p.status_aprovacao, 'aprovado')" : "'aprovado'";
    $motivoExpr = $hasMotivoRecusa ? "p.motivo_recusa" : "NULL";

    $sql = "SELECT p.id, p.nome, p.preco, p.ativo, p.imagem, p.categoria_id,
                   COALESCE(p.tipo, 'produto') AS tipo,
                   COALESCE(p.quantidade, 0) AS quantidade,
                   p.variantes,
                   {$statusExpr} AS status_aprovacao,
                   {$motivoExpr} AS motivo_recusa,
                   COALESCE(c.nome, 'Sem categoria') AS categoria_nome
            FROM products p
            LEFT JOIN categories c ON c.id = p.categoria_id
            WHERE p." . $col . " = ?";

    $types = 'i';
    $args = [$uid];

    if ($q !== '') {
        $sql .= " AND (p.nome LIKE ? OR p.descricao LIKE ?)";
        $like = "%{$q}%";
        $types .= 'ss';
        $args[] = $like;
        $args[] = $like;
    }

    if ($categoriaId > 0) {
        $sql .= " AND p.categoria_id = ?";
        $types .= 'i';
        $args[] = $categoriaId;
    }

    if ($status === 'ativo') {
        $sql .= " AND p.ativo = 1";
    } elseif ($status === 'inativo') {
        $sql .= " AND p.ativo = 0";
    }

    $sql .= " ORDER BY p.id DESC";

    $pagina = (int)($filters['pagina'] ?? 0);
    $pp = (int)($filters['pp'] ?? 0);

    if ($pagina > 0 && $pp > 0) {
        $fromPos = strpos($sql, 'FROM');
        $countBase = substr($sql, $fromPos);
        $countBase = preg_replace('/\s+ORDER\s+BY\s+.+$/i', '', $countBase);
        $countSql = "SELECT COUNT(*) " . $countBase;
        $stC = $c->prepare($countSql);
        if ($stC) {
            $stC->bind_param($types, ...$args);
            $stC->execute();
            $total = (int)$stC->get_result()->fetch_row()[0];
            $stC->close();
        } else {
            $total = 0;
        }
        $totalPaginas = max(1, (int)ceil($total / $pp));
        $offset = ($pagina - 1) * $pp;

        $sql .= " LIMIT ? OFFSET ?";
        $types .= 'ii';
        $args[] = $pp;
        $args[] = $offset;

        $st = $c->prepare($sql);
        if (!$st) return ['itens' => [], 'pagina' => $pagina, 'total_paginas' => 1, 'total' => 0];
        $st->bind_param($types, ...$args);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return ['itens' => $rows, 'pagina' => $pagina, 'total_paginas' => $totalPaginas, 'total' => $total];
    }

    $st = $c->prepare($sql);
    if (!$st) return [];

    $st->bind_param($types, ...$args);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function obterMeuProduto($c, int $uid, int $id): ?array {
    $col = vpColunaVendedorProdutos($c);
    if (!$col) return null;

    $st = $c->prepare("SELECT * FROM products WHERE id=? AND $col=? LIMIT 1");
    $st->bind_param('ii', $id, $uid);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

function salvarMeuProduto($c, int $uid, int $id, int $categoriaId, string $nome, string $descricao, float $preco, ?string $imagem, string $tipo = 'produto', int $quantidade = 0, ?int $prazoEntregaDias = null, ?string $dataEntrega = null, string $customSlug = '', ?string $variantes = null, bool $autoDeliveryEnabled = false, ?string $autoDeliveryItems = null): array {
    $col = vpColunaVendedorProdutos($c);
    if (!$col) return [false, 'Schema de products inválido.'];
    if ($categoriaId <= 0 || trim($nome) === '') return [false, 'Dados inválidos.'];
    if (!in_array($tipo, ['produto', 'servico', 'dinamico'], true)) $tipo = 'produto';
    if ($id <= 0 && (!is_string($imagem) || trim($imagem) === '')) {
        return [false, 'Adicione pelo menos uma imagem do produto.'];
    }
    if ($tipo === 'servico') { $quantidade = 0; $autoDeliveryEnabled = false; $autoDeliveryItems = null; }
    // Only normal products require price > 0 and quantity >= 1
    if ($tipo === 'produto' && $preco < 0) return [false, 'Dados inválidos.'];
    if ($tipo === 'produto' && $quantidade < 1) return [false, 'A quantidade mínima para produtos é 1.'];
    if ($prazoEntregaDias !== null && $prazoEntregaDias < 1) $prazoEntregaDias = null;

    // Dynamic product: validate variants JSON
    if ($tipo === 'dinamico') {
        $preco = 0;
        $quantidade = 0;
        if ($variantes !== null && $variantes !== '') {
            $varArr = json_decode($variantes, true);
            if (!is_array($varArr) || count($varArr) < 1) return [false, 'Produto dinâmico precisa de pelo menos 1 variante.'];
            // Sanitize variants
            $cleanVariants = [];
            foreach ($varArr as $v) {
                $vNome = trim((string)($v['nome'] ?? ''));
                $vPreco = (float)($v['preco'] ?? 0);
                $vQtd = (int)($v['quantidade'] ?? 0);
                if ($vNome === '' || $vPreco < 0) continue;
                $cleanVariants[] = ['nome' => $vNome, 'preco' => $vPreco, 'quantidade' => max(1, $vQtd)];
            }
            if (count($cleanVariants) < 1) return [false, 'Produto dinâmico precisa de pelo menos 1 variante válida.'];
            $variantes = json_encode($cleanVariants, JSON_UNESCAPED_UNICODE);
        } else {
            return [false, 'Produto dinâmico precisa de variantes.'];
        }
    } else {
        $variantes = null;
    }

    // Mask contact information in product description
    require_once __DIR__ . '/helpers.php';
    $descricao = maskContactInfo($descricao, '***', true);

    // Auto-migrate: ensure variantes column exists
    try { $c->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS variantes TEXT DEFAULT NULL"); } catch (\Throwable $e) {}

    // Auto-migrate: ensure auto-delivery columns exist
    try { $c->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_enabled BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $e) {}
    try { $c->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS auto_delivery_items TEXT DEFAULT NULL"); } catch (\Throwable $e) {}

    // Validate auto-delivery items (JSON array of strings)
    $autoDeliveryInt = $autoDeliveryEnabled ? 1 : 0;
    if ($autoDeliveryEnabled && $autoDeliveryItems !== null && $autoDeliveryItems !== '') {
        $adArr = json_decode($autoDeliveryItems, true);
        if (!is_array($adArr)) {
            $autoDeliveryItems = null;
            $autoDeliveryInt = 0;
        } else {
            $cleanItems = [];
            foreach ($adArr as $item) {
                $item = trim((string)$item);
                if ($item !== '') $cleanItems[] = $item;
            }
            $autoDeliveryItems = count($cleanItems) > 0 ? json_encode($cleanItems, JSON_UNESCAPED_UNICODE) : null;
            if ($autoDeliveryItems === null) $autoDeliveryInt = 0;
        }
    } else {
        $autoDeliveryItems = null;
    }

    if ($autoDeliveryInt === 1) {
        $prazoEntregaDias = null;
        $dataEntrega = null;
    }

    if ($id > 0) {
        $old = obterMeuProduto($c, $uid, $id);
        if (!$old) return [false, 'Produto não encontrado.'];
        $imgFinal = $imagem ?: (string)($old['imagem'] ?? '');
        $slug = trim($customSlug) !== '' ? sfCreateUniqueSlug($c, $customSlug, $id) : sfCreateUniqueSlug($c, $nome, $id);

        $st = $c->prepare("UPDATE products
                           SET categoria_id=?, nome=?, descricao=?, preco=?, imagem=?, tipo=?, quantidade=?, prazo_entrega_dias=?, data_entrega=?, slug=?, variantes=?, auto_delivery_enabled=?, auto_delivery_items=?
                           WHERE id=? AND $col=?");
        $st->bind_param('issdssiisssisii', $categoriaId, $nome, $descricao, $preco, $imgFinal, $tipo, $quantidade, $prazoEntregaDias, $dataEntrega, $slug, $variantes, $autoDeliveryInt, $autoDeliveryItems, $id, $uid);
        $ok = $st->execute();
        return [$ok, $ok ? 'Produto atualizado.' : 'Erro ao atualizar produto.'];
    }

    $imgFinal = $imagem ?? '';
    $ativo = 0; // New products start inactive until admin approval
    $statusAprovacao = 'pendente';
    $slug = trim($customSlug) !== '' ? sfCreateUniqueSlug($c, $customSlug) : sfCreateUniqueSlug($c, $nome);

    // Auto-migrate: ensure status_aprovacao column exists
    try { $c->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS status_aprovacao VARCHAR(20) NOT NULL DEFAULT 'pendente'"); } catch (\Throwable $e) {}
    try { $c->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS motivo_recusa TEXT DEFAULT NULL"); } catch (\Throwable $e) {}

    $hasApprovalStatus = vpProdutoTemColuna($c, 'status_aprovacao');
    if ($hasApprovalStatus) {
        $st = $c->prepare("INSERT INTO products (categoria_id, $col, nome, descricao, preco, imagem, ativo, tipo, quantidade, prazo_entrega_dias, data_entrega, slug, variantes, auto_delivery_enabled, auto_delivery_items, status_aprovacao)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->bind_param('iissdsisiisssiss', $categoriaId, $uid, $nome, $descricao, $preco, $imgFinal, $ativo, $tipo, $quantidade, $prazoEntregaDias, $dataEntrega, $slug, $variantes, $autoDeliveryInt, $autoDeliveryItems, $statusAprovacao);
        $ok = $st->execute();
        // Send "Produto Enviado para Análise" email
        if ($ok) {
            try {
                require_once __DIR__ . '/email.php';
                if (smtpConfigured($c)) {
                    $vendorSt = $c->prepare("SELECT nome, email FROM users WHERE id = ? LIMIT 1");
                    $vendorSt->bind_param('i', $uid);
                    $vendorSt->execute();
                    $vendorRow = $vendorSt->get_result()->fetch_assoc();
                    $vendorSt->close();
                    if ($vendorRow && !empty($vendorRow['email'])) {
                        $html = emailProdutoEnviado((string)$vendorRow['nome'], $nome, $c);
                        smtpSend((string)$vendorRow['email'], 'Produto enviado para análise – ' . APP_NAME, $html);
                    }
                }
            } catch (\Throwable $e) {
                error_log('[VendorPortal] product submitted email error: ' . $e->getMessage());
            }
        }
        return [$ok, $ok ? 'Produto enviado para aprovação. Você será notificado quando for analisado.' : 'Erro ao criar produto.'];
    }

    $ativo = 1;
    $st = $c->prepare("INSERT INTO products (categoria_id, $col, nome, descricao, preco, imagem, ativo, tipo, quantidade, prazo_entrega_dias, data_entrega, slug, variantes, auto_delivery_enabled, auto_delivery_items)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->bind_param('iissdsisiisssis', $categoriaId, $uid, $nome, $descricao, $preco, $imgFinal, $ativo, $tipo, $quantidade, $prazoEntregaDias, $dataEntrega, $slug, $variantes, $autoDeliveryInt, $autoDeliveryItems);
    $ok = $st->execute();
    return [$ok, $ok ? 'Produto criado com sucesso.' : 'Erro ao criar produto.'];
}

function excluirMeuProduto($c, int $uid, int $id): array {
    $col = vpColunaVendedorProdutos($c);
    if (!$col) return [false, 'Schema de products inválido.'];
    if ($uid <= 0 || $id <= 0) return [false, 'Dados inválidos.'];

    $exists = obterMeuProduto($c, $uid, $id);
    if (!$exists) return [false, 'Produto não encontrado.'];

    $st = $c->prepare("DELETE FROM products WHERE id=? AND $col=? LIMIT 1");
    if (!$st) return [false, 'Erro ao preparar exclusão.'];
    $st->bind_param('ii', $id, $uid);
    try {
        $ok = $st->execute();
    } catch (\Throwable $e) {
        $code = (string)$e->getCode();
        $msg = mb_strtolower($e->getMessage());
        if ($code === '23503' || str_contains($msg, 'foreign key') || str_contains($msg, 'violates foreign key constraint')) {
            return [false, 'Não é possível excluir este produto porque ele já está vinculado a pedidos.'];
        }
        return [false, 'Erro ao excluir produto.'];
    }
    if (!$ok || (int)$st->affected_rows < 1) {
        return [false, 'Não foi possível excluir o produto.'];
    }
    return [true, 'Produto excluído com sucesso.'];
}

function toggleMeuProdutoAtivo($c, int $uid, int $id): array {
    $col = vpColunaVendedorProdutos($c);
    if (!$col) return [false, 'Schema de products inválido.'];
    if ($uid <= 0 || $id <= 0) return [false, 'Dados inválidos.'];

    $produto = obterMeuProduto($c, $uid, $id);
    if (!$produto) return [false, 'Produto não encontrado.'];

    $novoAtivo = ((int)($produto['ativo'] ?? 1)) === 1 ? 0 : 1;
    $st = $c->prepare("UPDATE products SET ativo=? WHERE id=? AND $col=?");
    $st->bind_param('iii', $novoAtivo, $id, $uid);
    $ok = $st->execute();
    return [$ok, $ok ? 'Status atualizado.' : 'Erro ao atualizar status.'];
}

function listarMinhasVendasPorStatus($c, int $uid, string $status): array {
    $st = $c->prepare("SELECT oi.id AS venda_id, o.id AS pedido_id, p.nome AS produto_nome,
                              oi.quantidade, oi.subtotal, oi.moderation_status, oi.moderation_motivo,
                              o.criado_em, u.nome AS comprador_nome
                       FROM order_items oi
                       INNER JOIN orders o ON o.id = oi.order_id
                       INNER JOIN products p ON p.id = oi.product_id
                       INNER JOIN users u ON u.id = o.user_id
                       WHERE oi.vendedor_id=? AND oi.moderation_status=?
                       ORDER BY oi.id DESC");
    $st->bind_param('is', $uid, $status);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function detalheMinhaVenda($c, int $uid, int $vendaId): ?array {
    $st = $c->prepare("SELECT oi.*, o.id AS pedido_id, o.id AS order_id, o.status AS pedido_status, o.criado_em,
                              p.nome AS produto_nome, u.nome AS comprador_nome, u.email AS comprador_email
                       FROM order_items oi
                       INNER JOIN orders o ON o.id = oi.order_id
                       INNER JOIN products p ON p.id = oi.product_id
                       INNER JOIN users u ON u.id = o.user_id
                       WHERE o.id=? AND oi.vendedor_id=?
                       LIMIT 1");
    $st->bind_param('ii', $vendaId, $uid);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

function listarMeusSaques($c, int $uid): array {
    $st = $c->prepare("SELECT id, valor, status, chave_pix, observacao, criado_em
                       FROM wallet_withdrawals
                       WHERE user_id=?
                       ORDER BY id DESC");
    $st->bind_param('i', $uid);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function solicitarSaque($c, int $uid, float $valor, string $chavePix): array {
    if ($valor <= 0 || trim($chavePix) === '') return [false, 'Dados inválidos.'];

    $c->begin_transaction();
    try {
        $st = $c->prepare("SELECT wallet_saldo FROM users WHERE id=? FOR UPDATE");
        $st->bind_param('i', $uid);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        if (!$u) { $c->rollback(); return [false, 'Usuário inválido.']; }

        $saldo = (float)$u['wallet_saldo'];
        if ($valor > $saldo) { $c->rollback(); return [false, 'Saldo insuficiente.']; }

        $u1 = $c->prepare("UPDATE users SET wallet_saldo = wallet_saldo - ? WHERE id=?");
        $u1->bind_param('di', $valor, $uid);
        $u1->execute();

        $ins = $c->prepare("INSERT INTO wallet_withdrawals (user_id, valor, status, chave_pix) VALUES (?, ?, 'pendente', ?)");
        $ins->bind_param('ids', $uid, $valor, $chavePix);
        $ins->execute();

        $c->commit();
        return [true, 'Saque solicitado com sucesso.'];
    } catch (Throwable $e) {
        $c->rollback();
        return [false, 'Erro ao solicitar saque.'];
    }
}

if (!function_exists('resumoDashboardVendedor')) {
    function resumoDashboardVendedor($c, int $uid): array
    {
        $out = [
            'produtos_total' => 0,
            'produtos_ativos' => 0,
            'vendas_aprovadas' => 0,
            'vendas_analise' => 0,
            'saques_pendentes' => 0,
            'wallet_saldo' => 0.0,
        ];

        $col = vpColunaVendedorProdutos($c); // vendedor_id ou user_id
        if ($col) {
            $st = $c->prepare("SELECT COUNT(*) total, SUM(CASE WHEN ativo=1 THEN 1 ELSE 0 END) ativos FROM products WHERE $col=?");
            $st->bind_param('i', $uid);
            $st->execute();
            $r = $st->get_result()->fetch_assoc() ?: [];
            $out['produtos_total'] = (int)($r['total'] ?? 0);
            $out['produtos_ativos'] = (int)($r['ativos'] ?? 0);
        }

        $st = $c->prepare("SELECT
        SUM(CASE WHEN moderation_status='aprovada' THEN 1 ELSE 0 END) aprovadas,
        SUM(CASE WHEN moderation_status='pendente' THEN 1 ELSE 0 END) analise
        FROM order_items WHERE vendedor_id=?");
        $st->bind_param('i', $uid);
        $st->execute();
        $r = $st->get_result()->fetch_assoc() ?: [];
        $out['vendas_aprovadas'] = (int)($r['aprovadas'] ?? 0);
        $out['vendas_analise'] = (int)($r['analise'] ?? 0);

        $st = $c->prepare("SELECT COUNT(*) c FROM wallet_withdrawals WHERE user_id=? AND status='pendente'");
        $st->bind_param('i', $uid);
        $st->execute();
        $out['saques_pendentes'] = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);

        $st = $c->prepare("SELECT wallet_saldo FROM users WHERE id=? LIMIT 1");
        $st->bind_param('i', $uid);
        $st->execute();
        $out['wallet_saldo'] = (float)($st->get_result()->fetch_assoc()['wallet_saldo'] ?? 0);

        return $out;
    }
}

if (!function_exists('ultimasVendasVendedor')) {
    function ultimasVendasVendedor($c, int $uid, int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        $sql = "SELECT oi.id venda_id, p.nome produto_nome, oi.subtotal, oi.moderation_status, o.criado_em
                FROM order_items oi
                INNER JOIN orders o ON o.id=oi.order_id
                INNER JOIN products p ON p.id=oi.product_id
                WHERE oi.vendedor_id=?
                ORDER BY oi.id DESC
                LIMIT $limit";
        $st = $c->prepare($sql);
        $st->bind_param('i', $uid);
        $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('ultimosSaquesVendedor')) {
    function ultimosSaquesVendedor($c, int $uid, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $sql = "SELECT id, valor, status, criado_em
                FROM wallet_withdrawals
                WHERE user_id=?
                ORDER BY id DESC
                LIMIT $limit";
        $st = $c->prepare($sql);
        $st->bind_param('i', $uid);
        $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}