<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\admin_produtos.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/storefront.php';
require_once __DIR__ . '/seller_levels.php';

function colunaExiste($conn, string $tabela, string $coluna): bool
{
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param('ss', $tabela, $coluna);
    $st->execute();
    return (bool)$st->get_result()->fetch_assoc();
}

function listarVendedoresAprovados($conn): array
{
    $colVendedor = colunaExiste($conn, 'products', 'vendedor_id')
        ? 'vendedor_id'
        : (colunaExiste($conn, 'products', 'user_id') ? 'user_id' : null);
    $ownerFilter = $colVendedor !== null
        ? " OR id IN (SELECT DISTINCT {$colVendedor} FROM products WHERE {$colVendedor} IS NOT NULL)"
        : '';

    $q = $conn->query("SELECT id, nome
                       FROM users
                       WHERE ativo=1
                         AND (
                           role='vendedor'
                           OR status_vendedor='aprovado'
                           OR COALESCE(is_vendedor, FALSE) = TRUE
                           {$ownerFilter}
                         )
                       ORDER BY nome");
    return $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
}

function listarCategoriasProdutoAtivas($conn): array
{
    _sfEnsureCategorySlugColumn($conn);
    $q = $conn->query("SELECT id, nome, tipo FROM categories WHERE ativo=1 AND tipo != 'blog' ORDER BY nome");
    return $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
}

function listarProdutos($conn, array|string $f = [], int $pagina = 1, int $pp = 15): array
{
    _sfEnsureSlugColumn($conn);

    if (!is_array($f)) $f = ['q' => (string)$f];

    $pagina = max(1, $pagina);
    $offset = ($pagina - 1) * $pp;

    $colVendedor = colunaExiste($conn, 'products', 'vendedor_id')
        ? 'vendedor_id'
        : (colunaExiste($conn, 'products', 'user_id') ? 'user_id' : null);

    $colCategoria = colunaExiste($conn, 'products', 'categoria_id') ? 'categoria_id' : null;
    $selectImagem = colunaExiste($conn, 'products', 'imagem') ? 'p.imagem' : 'NULL AS imagem';
    $selectDestaque = colunaExiste($conn, 'products', 'destaque') ? 'COALESCE(p.destaque, FALSE) AS destaque' : 'FALSE AS destaque';

    if ($colVendedor === null || $colCategoria === null) {
        return ['itens' => []];
    }

    $where = ["p.nome LIKE ?"];
    $types = "s";
    $params = ['%' . trim((string)($f['q'] ?? '')) . '%'];

    if (!empty($f['categoria_id'])) { $where[] = "p.{$colCategoria} = ?"; $types .= "i"; $params[] = (int)$f['categoria_id']; }
    if (!empty($f['vendedor_id'])) { $where[] = "p.{$colVendedor} = ?"; $types .= "i"; $params[] = (int)$f['vendedor_id']; }
    if (in_array((string)($f['ativo'] ?? ''), ['0', '1'], true) && colunaExiste($conn, 'products', 'ativo')) {
        $where[] = "p.ativo = ?"; $types .= "i"; $params[] = (int)$f['ativo'];
    }

    $sql = "SELECT p.id, p.nome, p.preco,
                   " . (colunaExiste($conn, 'products', 'ativo') ? "p.ativo" : "1 AS ativo") . ",
                   {$selectImagem},
                   COALESCE(p.tipo, 'produto') AS tipo,
                   COALESCE(p.quantidade, 0) AS quantidade,
                   p.variantes,
                   {$selectDestaque},
                   c.nome AS categoria_nome, u.nome AS vendedor_nome
            FROM products p
            INNER JOIN categories c ON c.id = p.{$colCategoria}
            INNER JOIN users u ON u.id = p.{$colVendedor}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.id DESC
            LIMIT ?, ?";

    $types2 = $types . "ii";
    $params2 = [...$params, $offset, $pp];

    $st = $conn->prepare($sql);
    $st->bind_param($types2, ...$params2);
    $st->execute();

    // count total
    $countSql = "SELECT COUNT(*) AS t FROM products p
                 INNER JOIN categories c ON c.id = p.{$colCategoria}
                 INNER JOIN users u ON u.id = p.{$colVendedor}
                 WHERE " . implode(' AND ', $where);
    $stc = $conn->prepare($countSql);
    $stc->bind_param($types, ...$params);
    $stc->execute();
    $total = (int)($stc->get_result()->fetch_assoc()['t'] ?? 0);

    return [
        'itens' => $st->get_result()->fetch_all(MYSQLI_ASSOC),
        'pagina' => $pagina,
        'total_paginas' => max(1, (int)ceil($total / $pp)),
    ];
}

function salvarProduto($conn, int $id, int $vendedorId, int $categoriaId, string $nome, string $descricao, float $preco, ?string $imagem, string $tipo = 'produto', int $quantidade = 0, ?int $prazoEntregaDias = null, ?string $dataEntrega = null, string $customSlug = '', ?string $variantes = null, bool $destaque = false, bool $productFeeOverrideEnabled = false, ?float $productFeePercent = null): array
{
    if ($vendedorId <= 0) return [false, 'Selecione um vendedor para o produto.'];
    if ($categoriaId <= 0) return [false, 'Selecione uma categoria para o produto.'];
    if (trim($nome) === '') return [false, 'Informe o nome do produto.'];
    if (!in_array($tipo, ['produto', 'servico', 'dinamico'], true)) $tipo = 'produto';

    // Imagem obrigatória: no create exige; no edit aceita manter a atual (passar $imagem=null vindo do form quando não enviou nova).
    if ($id <= 0 && (!is_string($imagem) || trim($imagem) === '')) {
        return [false, 'Adicione pelo menos uma imagem do produto.'];
    }

    // Serviços não têm quantidade
    if ($tipo === 'servico') $quantidade = 0;
    // Only normal products require price > 0 and quantity >= 1
    if ($tipo === 'produto' && $preco < 0) return [false, 'Dados inválidos.'];
    if ($tipo === 'produto' && $quantidade < 1) return [false, 'A quantidade mínima para produtos é 1.'];
    if ($prazoEntregaDias !== null && $prazoEntregaDias < 1) $prazoEntregaDias = null;

    // Dynamic product: validate variants
    if ($tipo === 'dinamico') {
        $preco = 0;
        $quantidade = 0;
        if ($variantes !== null && $variantes !== '') {
            $varArr = json_decode($variantes, true);
            if (!is_array($varArr) || count($varArr) < 1) return [false, 'Produto dinâmico precisa de pelo menos 1 variante.'];
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
    try { $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS variantes TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    _sfEnsureSlugColumn($conn);
    sellerProductFeeOverrideEnsureColumns($conn);
    $destaqueInt = $destaque ? 1 : 0;
    $productFeeOverrideInt = $productFeeOverrideEnabled ? 1 : 0;
    if ($productFeeOverrideEnabled) {
        if ($productFeePercent === null) return [false, 'Informe a taxa personalizada do produto.'];
        $productFeePercent = max(0.0, min(100.0, $productFeePercent));
    } else {
        $productFeePercent = null;
    }

    if ($id > 0) {
        $slug = trim($customSlug) !== '' ? sfCreateUniqueSlug($conn, $customSlug, $id) : sfCreateUniqueSlug($conn, $nome, $id);
        if ($imagem) {
            $st = $conn->prepare("UPDATE products SET vendedor_id=?, categoria_id=?, nome=?, descricao=?, preco=?, imagem=?, tipo=?, quantidade=?, prazo_entrega_dias=?, data_entrega=?, slug=?, variantes=?, destaque=?, product_fee_override_enabled=?, product_fee_percent=? WHERE id=?");
            $st->bind_param('iissdssiisssiidi', $vendedorId, $categoriaId, $nome, $descricao, $preco, $imagem, $tipo, $quantidade, $prazoEntregaDias, $dataEntrega, $slug, $variantes, $destaqueInt, $productFeeOverrideInt, $productFeePercent, $id);
        } else {
            $st = $conn->prepare("UPDATE products SET vendedor_id=?, categoria_id=?, nome=?, descricao=?, preco=?, tipo=?, quantidade=?, prazo_entrega_dias=?, data_entrega=?, slug=?, variantes=?, destaque=?, product_fee_override_enabled=?, product_fee_percent=? WHERE id=?");
            $st->bind_param('iissdsiisssiidi', $vendedorId, $categoriaId, $nome, $descricao, $preco, $tipo, $quantidade, $prazoEntregaDias, $dataEntrega, $slug, $variantes, $destaqueInt, $productFeeOverrideInt, $productFeePercent, $id);
        }
        $st->execute();
        return [true, 'Produto atualizado.'];
    }

    $slug = trim($customSlug) !== '' ? sfCreateUniqueSlug($conn, $customSlug) : sfCreateUniqueSlug($conn, $nome);
    $st = $conn->prepare("INSERT INTO products (vendedor_id, categoria_id, nome, descricao, preco, imagem, ativo, tipo, quantidade, prazo_entrega_dias, data_entrega, slug, variantes, destaque, product_fee_override_enabled, product_fee_percent) VALUES (?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?)");
    $st->bind_param('iissdssiisssiid', $vendedorId, $categoriaId, $nome, $descricao, $preco, $imagem, $tipo, $quantidade, $prazoEntregaDias, $dataEntrega, $slug, $variantes, $destaqueInt, $productFeeOverrideInt, $productFeePercent);
    $st->execute();
    return [true, 'Produto criado.'];
}

function obterProdutoPorId($conn, int $id): ?array
{
    _sfEnsureSlugColumn($conn);
    $st = $conn->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    return $r ?: null;
}

function alterarAtivoProduto($conn, int $id, int $ativo): array
{
    $st = $conn->prepare("UPDATE products SET ativo=? WHERE id=?");
    $st->bind_param('ii', $ativo, $id);
    $st->execute();
    return $st->affected_rows > 0 ? [true, 'Status atualizado.'] : [false, 'Produto não encontrado.'];
}

function excluirProduto($conn, int $id): array
{
    $st = $conn->prepare("DELETE FROM products WHERE id=?");
    $st->bind_param('i', $id);
    try {
        $st->execute();
    } catch (\Throwable $e) {
        $code = (string)$e->getCode();
        $msg = mb_strtolower($e->getMessage());
        if ($code === '23503' || str_contains($msg, 'foreign key') || str_contains($msg, 'violates foreign key constraint')) {
            return [false, 'Não é possível excluir este produto porque ele já está vinculado a pedidos.'];
        }
        return [false, 'Erro ao excluir produto.'];
    }
    return $st->affected_rows > 0 ? [true, 'Produto excluído.'] : [false, 'Produto não encontrado.'];
}