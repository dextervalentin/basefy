<?php
declare(strict_types=1);
/**
 * Support Tickets — backend logic
 * Modeled after the reports (denúncias) system
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

function ticketsEnsureTable($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS support_tickets (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                categoria VARCHAR(100) NOT NULL,
                titulo VARCHAR(255) NOT NULL,
                mensagem TEXT NOT NULL,
                order_id INTEGER DEFAULT NULL,
                motivo VARCHAR(120) DEFAULT NULL,
                resolution_due_at TIMESTAMP DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'aberto',
                admin_resposta TEXT DEFAULT NULL,
                admin_id INTEGER DEFAULT NULL,
                respondido_em TIMESTAMP DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $conn->query("ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS motivo VARCHAR(120) DEFAULT NULL");
        $conn->query("ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS resolution_due_at TIMESTAMP DEFAULT NULL");
        // Attachment table for ticket files
        $conn->query("
            CREATE TABLE IF NOT EXISTS support_ticket_attachments (
                id SERIAL PRIMARY KEY,
                ticket_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                filename VARCHAR(255) NOT NULL,
                filepath VARCHAR(500) NOT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // Messages / replies table
        $conn->query("
            CREATE TABLE IF NOT EXISTS support_ticket_messages (
                id SERIAL PRIMARY KEY,
                ticket_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                is_admin SMALLINT DEFAULT 0,
                mensagem TEXT NOT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (\Throwable $e) {}
}

/**
 * Get ticket categories
 */
function ticketCategories(): array
{
    return [
        'pedido_disputa' => [
            'label' => 'Pedido / Disputa',
            'desc'  => 'Categoria voltada para tickets abertos a partir de um pedido, com motivo e prazo de resolução.',
        ],
        'alteracao_cadastral' => [
            'label' => 'Alteração Cadastral e Verificação',
            'desc'  => 'Categoria voltada para alteração de dados cadastrais, verificação de identidade e documentos.',
        ],
        'anuncios' => [
            'label' => 'Anúncios',
            'desc'  => 'Categoria voltada para problemas com os anúncios, comprovação de autoria, reprovações e aprovações.',
        ],
        'denuncias_banimentos' => [
            'label' => 'Denúncias e Banimentos',
            'desc'  => 'Categoria voltada para denúncias de usuários, contestação de banimentos e problemas de conduta.',
        ],
        'duvidas_gerais' => [
            'label' => 'Dúvidas Gerais',
            'desc'  => 'Categoria voltada para dúvidas gerais sobre a plataforma e suas funcionalidades.',
        ],
        'financeiro_retiradas' => [
            'label' => 'Financeiro e Retiradas',
            'desc'  => 'Categoria voltada para problemas com pagamentos, retiradas, saldo e questões financeiras.',
        ],
        'outros' => [
            'label' => 'Outros',
            'desc'  => 'Categoria voltada para assuntos que não se encaixam nas demais categorias.',
        ],
        'problemas_reembolsos' => [
            'label' => 'Problemas e Reembolsos',
            'desc'  => 'Categoria voltada para problemas com pedidos, solicitação de reembolso e disputas.',
        ],
        'problemas_tecnicos' => [
            'label' => 'Problemas Técnicos / Bugs',
            'desc'  => 'Categoria voltada para bugs, erros técnicos e problemas com o funcionamento do site.',
        ],
    ];
}

/**
 * Reasons available when opening a dispute from an order.
 */
function ticketDisputeReasons(): array
{
    return [
        'nao_recebi_ativo' => 'Não recebi o ativo',
        'ativo_problema_invalido' => 'Ativo com problema / inválido',
        'entrega_incompleta' => 'Entrega incompleta',
        'produto_diferente_anunciado' => 'Produto diferente do anunciado',
        'suspeita_fraude' => 'Suspeita de fraude',
        'conduta_inadequada' => 'Conduta inadequada',
        'contato_externo' => 'Contato externo',
        'outro' => 'Outro',
    ];
}

function ticketDisputeReasonLabel(string $key): string
{
    $reasons = ticketDisputeReasons();
    return $reasons[$key] ?? '';
}

function ticketStatusOptions(): array
{
    return [
        'aberto' => 'Aberto',
        'em_andamento' => 'Em Andamento',
        'respondido' => 'Respondido',
        'resolvido' => 'Resolvido',
        'nao_resolvido' => 'Não resolvido',
        'reembolsado' => 'Reembolsado',
        'fechado' => 'Fechado',
    ];
}

function ticketManualStatusOptions(): array
{
    return [
        'aberto' => 'Aberto',
        'em_andamento' => 'Em Andamento',
        'respondido' => 'Respondido',
        'fechado' => 'Fechado',
    ];
}

function ticketFinalStatuses(): array
{
    return ['resolvido', 'nao_resolvido', 'reembolsado', 'fechado'];
}

function ticketIsFinalStatus(string $status): bool
{
    return in_array(strtolower(trim($status)), ticketFinalStatuses(), true);
}

/**
 * Create a new ticket
 */
function ticketCreate($conn, int $userId, string $categoria, string $titulo, string $mensagem, ?int $orderId = null, array $extra = []): array
{
    ticketsEnsureTable($conn);
    $titulo   = trim($titulo);
    $mensagem = trim($mensagem);
    $categoria = trim($categoria);
    $motivo = trim((string)($extra['motivo'] ?? ''));
    $resolutionDueAt = trim((string)($extra['resolution_due_at'] ?? ''));

    if ($titulo === '') return ['ok' => false, 'error' => 'Informe um título para o ticket.'];
    if ($mensagem === '') return ['ok' => false, 'error' => 'Descreva o problema.'];
    if ($categoria === '') return ['ok' => false, 'error' => 'Selecione uma categoria.'];

    // Check duplicate (same user + same title within 1h)
    $dupStmt = $conn->prepare(
        "SELECT COUNT(*) total FROM support_tickets WHERE user_id = ? AND titulo = ? AND criado_em > NOW() - INTERVAL '1 hour'"
    );
    $dupStmt->bind_param('is', $userId, $titulo);
    $dupStmt->execute();
    $dup = (int)($dupStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $dupStmt->close();
    if ($dup > 0) return ['ok' => false, 'error' => 'Você já abriu um ticket com este título recentemente. Aguarde.'];

    $stmt = $conn->prepare(
        "INSERT INTO support_tickets (user_id, categoria, titulo, mensagem, order_id, motivo, resolution_due_at) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $oid = $orderId ?: null;
    $motivoValue = $motivo !== '' ? $motivo : null;
    $deadlineValue = $resolutionDueAt !== '' ? $resolutionDueAt : null;
    $stmt->bind_param('isssiss', $userId, $categoria, $titulo, $mensagem, $oid, $motivoValue, $deadlineValue);
    $stmt->execute();
    $ticketId = $conn->insert_id;
    $stmt->close();

    try {
        $userName = 'Cliente';
        $stUser = $conn->prepare('SELECT nome FROM users WHERE id = ? LIMIT 1');
        if ($stUser) {
            $stUser->bind_param('i', $userId);
            $stUser->execute();
            $userRow = $stUser->get_result()->fetch_assoc() ?: null;
            $stUser->close();
            $userName = trim((string)($userRow['nome'] ?? '')) ?: 'Cliente';
        }

        $title = 'Novo ticket aberto';
        $summary = $userName . ' abriu o ticket #' . $ticketId . ': ' . $titulo;
        notificationsNotifyAdmins($conn, 'ticket', $title, $summary, '/admin/tickets?open=' . $ticketId, [], $userId);
    } catch (\Throwable $e) {
        error_log('[Tickets] Admin notification error: ' . $e->getMessage());
    }

    return ['ok' => true, 'msg' => 'Ticket criado com sucesso!', 'id' => $ticketId];
}

/**
 * List tickets with pagination. Filters: user_id, status, q, categoria
 */
function ticketsList($conn, array $filters = [], int $page = 1, int $pp = 10): array
{
    ticketsEnsureTable($conn);
    $where  = '1=1';
    $params = [];
    $types  = '';

    $status = (string)($filters['status'] ?? '');
    $q      = trim((string)($filters['q'] ?? ''));
    $cat    = (string)($filters['categoria'] ?? '');

    if (isset(ticketStatusOptions()[$status])) {
        $where .= ' AND t.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($cat !== '') {
        $where .= ' AND t.categoria = ?';
        $types .= 's';
        $params[] = $cat;
    }

    $filterUserId = (int)($filters['user_id'] ?? 0);
    if ($filterUserId > 0) {
        $where .= ' AND t.user_id = ?';
        $types .= 'i';
        $params[] = $filterUserId;
    }

    if ($q !== '') {
        $where .= ' AND (t.titulo ILIKE ? OR t.mensagem ILIKE ? OR u.nome ILIKE ?)';
        $types .= 'sss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // Count
    $countSql = "SELECT COUNT(*) total FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE $where";
    $countStmt = $conn->prepare($countSql);
    if ($types && $params) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalPaginas = max(1, (int)ceil($total / $pp));
    $page = min($page, $totalPaginas);
    $offset = ($page - 1) * $pp;

    $sql = "SELECT t.*, u.nome AS user_nome, u.email AS user_email,
                   COALESCE(NULLIF(u.telefone, ''), NULLIF(sp.telefone, '')) AS user_whatsapp
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN seller_profiles sp ON sp.user_id = u.id
            WHERE $where
            ORDER BY
                CASE t.status
                    WHEN 'aberto' THEN 1
                    WHEN 'em_andamento' THEN 2
                    WHEN 'respondido' THEN 3
                    WHEN 'resolvido' THEN 4
                    WHEN 'nao_resolvido' THEN 5
                    WHEN 'reembolsado' THEN 6
                    WHEN 'fechado' THEN 7
                    ELSE 8
                END,
                t.criado_em DESC
            LIMIT ? OFFSET ?";
    $types2 = $types . 'ii';
    $params2 = array_merge($params, [$pp, $offset]);

    $stmt = $conn->prepare($sql);
    if ($types2 && $params2) {
        $stmt->bind_param($types2, ...$params2);
    }
    $stmt->execute();
    $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    return [
        'itens' => $itens,
        'total' => $total,
        'pagina' => $page,
        'total_paginas' => $totalPaginas,
        'por_pagina' => $pp,
    ];
}

/**
 * Get single ticket by ID
 */
function ticketGetById($conn, int $id): ?array
{
    ticketsEnsureTable($conn);
    $stmt = $conn->prepare(
        "SELECT t.*, u.nome AS user_nome, u.email AS user_email,
            COALESCE(NULLIF(u.telefone, ''), NULLIF(sp.telefone, '')) AS user_whatsapp
         FROM support_tickets t
         LEFT JOIN users u ON u.id = t.user_id
         LEFT JOIN seller_profiles sp ON sp.user_id = u.id
         WHERE t.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Get ticket messages (conversation thread)
 */
function ticketGetMessages($conn, int $ticketId): array
{
    ticketsEnsureTable($conn);
    $stmt = $conn->prepare(
        "SELECT m.*, u.nome AS user_nome
         FROM support_ticket_messages m
         LEFT JOIN users u ON u.id = m.user_id
         WHERE m.ticket_id = ?
         ORDER BY m.criado_em ASC"
    );
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

/**
 * Add a message to a ticket
 */
function ticketAddMessage($conn, int $ticketId, int $userId, string $mensagem, bool $isAdmin = false): array
{
    $mensagem = trim($mensagem);
    if ($mensagem === '') return [false, 'Mensagem vazia.'];

    $stStatus = $conn->prepare("SELECT status FROM support_tickets WHERE id = ? LIMIT 1");
    $stStatus->bind_param('i', $ticketId);
    $stStatus->execute();
    $ticketRow = $stStatus->get_result()->fetch_assoc();
    $stStatus->close();
    if (!$ticketRow) return [false, 'Ticket não encontrado.'];
    if (ticketIsFinalStatus((string)$ticketRow['status'])) return [false, 'Este ticket já foi encerrado.'];

    $ownerId = 0;
    $ticketTitle = '';
    $stOwner = $conn->prepare('SELECT user_id, titulo FROM support_tickets WHERE id = ? LIMIT 1');
    if ($stOwner) {
        $stOwner->bind_param('i', $ticketId);
        $stOwner->execute();
        $ticketMeta = $stOwner->get_result()->fetch_assoc() ?: [];
        $stOwner->close();
        $ownerId = (int)($ticketMeta['user_id'] ?? 0);
        $ticketTitle = trim((string)($ticketMeta['titulo'] ?? ''));
    }

    $admin = $isAdmin ? 1 : 0;
    $stmt = $conn->prepare(
        "INSERT INTO support_ticket_messages (ticket_id, user_id, is_admin, mensagem) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('iiis', $ticketId, $userId, $admin, $mensagem);
    $stmt->execute();
    $stmt->close();

    // Update ticket timestamp
    $conn->query("UPDATE support_tickets SET atualizado_em = CURRENT_TIMESTAMP WHERE id = " . (int)$ticketId);

    // If admin replies, also update status
    if ($isAdmin) {
        $upd = $conn->prepare("UPDATE support_tickets SET status = 'respondido', admin_id = ?, admin_resposta = ?, respondido_em = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->bind_param('isi', $userId, $mensagem, $ticketId);
        $upd->execute();
        $upd->close();

        // Notify ticket owner about admin reply
        try {
            if ($ownerId > 0) {
                notificationsCreate($conn, $ownerId, 'ticket', 'Resposta no seu ticket', 'O suporte respondeu ao seu ticket #' . $ticketId . '. Confira a resposta.', '/ticket_detalhe?id=' . $ticketId);
            }
        } catch (\Throwable $e) { error_log('[Tickets] Notification error: ' . $e->getMessage()); }
    } else {
        try {
            $userName = 'Cliente';
            $stUser = $conn->prepare('SELECT nome FROM users WHERE id = ? LIMIT 1');
            if ($stUser) {
                $stUser->bind_param('i', $userId);
                $stUser->execute();
                $userRow = $stUser->get_result()->fetch_assoc() ?: null;
                $stUser->close();
                $userName = trim((string)($userRow['nome'] ?? '')) ?: 'Cliente';
            }

            $summary = $userName . ' respondeu ao ticket #' . $ticketId;
            if ($ticketTitle !== '') {
                $summary .= ': ' . $ticketTitle;
            }
            notificationsNotifyAdmins($conn, 'ticket', 'Nova mensagem em ticket', $summary, '/admin/tickets?open=' . $ticketId, [], $userId);
        } catch (\Throwable $e) {
            error_log('[Tickets] Admin reply notification error: ' . $e->getMessage());
        }
    }

    return [true, 'Mensagem enviada.'];
}

/**
 * Update ticket status
 */
function ticketUpdateStatus($conn, int $id, string $newStatus): array
{
    if (!isset(ticketManualStatusOptions()[$newStatus])) return [false, 'Status inválido.'];

    $stCurrent = $conn->prepare("SELECT status FROM support_tickets WHERE id = ? LIMIT 1");
    $stCurrent->bind_param('i', $id);
    $stCurrent->execute();
    $current = $stCurrent->get_result()->fetch_assoc();
    $stCurrent->close();
    if (!$current) return [false, 'Ticket não encontrado.'];
    if (ticketIsFinalStatus((string)$current['status'])) return [false, 'Este ticket já foi encerrado.'];

    $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->bind_param('si', $newStatus, $id);
    $stmt->execute();
    if ($stmt->affected_rows < 1) return [false, 'Ticket não encontrado.'];
    $stmt->close();
    return [true, 'Status atualizado.'];
}

function ticketResolveDispute($conn, int $ticketId, int $adminId, string $decision, string $note): array
{
    ticketsEnsureTable($conn);
    $ticketId = (int)$ticketId;
    $adminId = (int)$adminId;
    $decision = strtolower(trim($decision));
    $note = trim($note);

    $labels = [
        'resolvido' => 'Disputa resolvida',
        'nao_resolvido' => 'Disputa não resolvida',
        'reembolsado' => 'Reembolso aprovado',
    ];

    if ($ticketId <= 0 || $adminId <= 0 || !isset($labels[$decision])) return [false, 'Parâmetros inválidos.'];
    if (mb_strlen($note) < 5) return [false, 'Informe uma observação com pelo menos 5 caracteres.'];

    $refundAmount = 0.0;
    $buyerId = 0;
    $orderId = 0;
    $vendorIds = [];

    $conn->begin_transaction();
    try {
        $st = $conn->prepare("SELECT * FROM support_tickets WHERE id = ? FOR UPDATE");
        $st->bind_param('i', $ticketId);
        $st->execute();
        $ticket = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$ticket) { $conn->rollback(); return [false, 'Ticket não encontrado.']; }
        if ((string)$ticket['categoria'] !== 'pedido_disputa' || (int)($ticket['order_id'] ?? 0) <= 0) {
            $conn->rollback();
            return [false, 'Esta ação é exclusiva para tickets de pedido/disputa.'];
        }
        if (ticketIsFinalStatus((string)$ticket['status'])) {
            $conn->rollback();
            return [false, 'Esta disputa já foi encerrada.'];
        }

        $buyerId = (int)$ticket['user_id'];
        $orderId = (int)$ticket['order_id'];

        if ($decision === 'reembolsado') {
            [$okRefund, $refundMsg, $refundAmount, $vendorIds] = ticketRefundDisputeOrderInTransaction($conn, $ticket, $adminId, $note);
            if (!$okRefund) {
                $conn->rollback();
                return [false, $refundMsg];
            }
        }

        $decisionMessage = $labels[$decision];
        if ($refundAmount > 0) {
            $decisionMessage .= ' — R$ ' . number_format($refundAmount, 2, ',', '.');
        }
        $decisionMessage .= "\n\nObservação:\n" . $note;

        $adminFlag = 1;
        $ins = $conn->prepare("INSERT INTO support_ticket_messages (ticket_id, user_id, is_admin, mensagem) VALUES (?, ?, ?, ?)");
        $ins->bind_param('iiis', $ticketId, $adminId, $adminFlag, $decisionMessage);
        $ins->execute();
        $ins->close();

        $up = $conn->prepare("UPDATE support_tickets SET status = ?, admin_id = ?, admin_resposta = ?, respondido_em = CURRENT_TIMESTAMP, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?");
        $up->bind_param('sisi', $decision, $adminId, $decisionMessage, $ticketId);
        $up->execute();
        $up->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Erro ao decidir disputa: ' . $e->getMessage()];
    }

    try {
        notificationsCreate($conn, $buyerId, 'ticket', $labels[$decision], 'A equipe atualizou a disputa do pedido #' . $orderId . '.', '/ticket_detalhe?id=' . $ticketId);
        if ($decision === 'reembolsado') {
            notificationsCreate($conn, $buyerId, 'venda', 'Reembolso aprovado', 'R$ ' . number_format($refundAmount, 2, ',', '.') . ' do pedido #' . $orderId . ' foi devolvido à sua carteira.', '/wallet');
            foreach (array_unique($vendorIds) as $vendorId) {
                if ((int)$vendorId > 0) {
                    notificationsCreate($conn, (int)$vendorId, 'venda', 'Pedido reembolsado', 'O pedido #' . $orderId . ' foi reembolsado após análise da disputa.', '/vendedor/vendas_analise');
                }
            }
        }
    } catch (\Throwable $e) { error_log('[Tickets] Dispute decision notification error: ' . $e->getMessage()); }

    return [true, $labels[$decision] . ' com sucesso.'];
}

function ticketRefundDisputeOrderInTransaction($conn, array $ticket, int $adminId, string $note): array
{
    $ticketId = (int)$ticket['id'];
    $orderId = (int)$ticket['order_id'];
    $buyerId = (int)$ticket['user_id'];

    $stOrder = $conn->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ? FOR UPDATE");
    $stOrder->bind_param('ii', $orderId, $buyerId);
    $stOrder->execute();
    $order = $stOrder->get_result()->fetch_assoc();
    $stOrder->close();
    if (!$order) return [false, 'Pedido não encontrado para este ticket.', 0.0, []];

    $stReleased = $conn->prepare("SELECT COUNT(*) AS c FROM order_items WHERE order_id = ? AND (released_at IS NOT NULL OR moderation_status = 'aprovada')");
    $stReleased->bind_param('i', $orderId);
    $stReleased->execute();
    $released = (int)($stReleased->get_result()->fetch_assoc()['c'] ?? 0);
    $stReleased->close();
    if ($released > 0) return [false, 'Este pedido já possui valor liberado ao vendedor e não pode ser reembolsado automaticamente.', 0.0, []];

    $stDup = $conn->prepare("SELECT COUNT(*) AS c FROM wallet_transactions WHERE user_id = ? AND origem = 'dispute_refund' AND referencia_tipo = 'order' AND referencia_id = ?");
    $stDup->bind_param('ii', $buyerId, $orderId);
    $stDup->execute();
    $alreadyRefunded = (int)($stDup->get_result()->fetch_assoc()['c'] ?? 0);
    $stDup->close();
    if ($alreadyRefunded > 0) return [false, 'Este pedido já possui reembolso de disputa registrado.', 0.0, []];

    $stItems = $conn->prepare("SELECT id, vendedor_id, quantidade, preco_unit, subtotal FROM order_items WHERE order_id = ? AND moderation_status = 'pendente' FOR UPDATE");
    $stItems->bind_param('i', $orderId);
    $stItems->execute();
    $items = $stItems->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stItems->close();
    if (!$items) return [false, 'Não há itens pendentes para reembolsar neste pedido.', 0.0, []];

    $refundAmount = 0.0;
    $vendorIds = [];
    foreach ($items as $item) {
        $gross = (float)($item['subtotal'] ?? 0);
        if ($gross <= 0) $gross = (int)$item['quantidade'] * (float)$item['preco_unit'];
        $refundAmount += $gross;
        if ((int)($item['vendedor_id'] ?? 0) > 0) $vendorIds[] = (int)$item['vendedor_id'];
    }
    $refundAmount = round($refundAmount, 2);
    if ($refundAmount <= 0) return [false, 'Valor de reembolso inválido.', 0.0, []];

    $upBuyer = $conn->prepare("UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?");
    $upBuyer->bind_param('di', $refundAmount, $buyerId);
    $upBuyer->execute();
    $upBuyer->close();

    $tx = $conn->prepare("INSERT INTO wallet_transactions (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao) VALUES (?, 'credito', 'dispute_refund', 'order', ?, ?, ?)");
    $desc = 'Reembolso da disputa #' . $ticketId . ' do pedido #' . $orderId;
    $tx->bind_param('iids', $buyerId, $orderId, $refundAmount, $desc);
    $tx->execute();
    $tx->close();

    $reason = 'Reembolso por disputa #' . $ticketId . ': ' . mb_substr($note, 0, 180);
    $upItem = $conn->prepare("UPDATE order_items SET moderation_status = 'recusada', moderation_motivo = ?, moderation_at = NOW(), moderation_by = ?, auto_release_at = NULL WHERE id = ?");
    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $upItem->bind_param('sii', $reason, $adminId, $itemId);
        $upItem->execute();
    }
    $upItem->close();

    $upOrder = $conn->prepare("UPDATE orders SET status = 'cancelado' WHERE id = ?");
    $upOrder->bind_param('i', $orderId);
    $upOrder->execute();
    $upOrder->close();

    return [true, 'Reembolso aplicado.', $refundAmount, $vendorIds];
}

/**
 * Count tickets by status
 */
function ticketsCountByStatus($conn, int $userId = 0): array
{
    ticketsEnsureTable($conn);
    $sql = "SELECT status, COUNT(*) qtd FROM support_tickets";
    if ($userId > 0) {
        $sql .= " WHERE user_id = " . (int)$userId;
    }
    $sql .= " GROUP BY status";
    $stmt = $conn->query($sql);
    $result = [];
    if ($stmt) {
        while ($row = $stmt->fetch_assoc()) {
            $result[(string)$row['status']] = (int)$row['qtd'];
        }
    }
    return $result;
}

/**
 * Status badge CSS helper
 */
function ticketStatusBadge(string $s): string
{
    $s = strtolower(trim($s));
    if ($s === 'reembolsado') return 'bg-blue-500/15 border border-blue-400/40 text-blue-300';
    if ($s === 'resolvido') return 'bg-greenx/15 border border-greenx/40 text-greenx';
    if ($s === 'nao_resolvido') return 'bg-red-500/15 border border-red-400/40 text-red-300';
    if ($s === 'respondido') return 'bg-greenx/15 border border-greenx/40 text-greenx';
    if ($s === 'em_andamento') return 'bg-greenx/15 border border-greenx/40 text-greenx';
    if ($s === 'fechado') return 'bg-zinc-500/15 border border-zinc-400/40 text-zinc-300';
    return 'bg-orange-500/15 border border-orange-400/40 text-orange-300'; // aberto
}

/**
 * Status label helper
 */
function ticketStatusLabel(string $s): string
{
    $s = strtolower(trim($s));
    $map = ticketStatusOptions();
    return $map[$s] ?? ucfirst($s);
}
