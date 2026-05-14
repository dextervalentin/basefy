<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\api\chat.php

/**
 * Chat API — Handles all chat actions via ?action=...
 * All responses are JSON: {ok: bool, ...}
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/chat.php';

header('Content-Type: application/json; charset=utf-8');

// Auth check
if (!usuarioLogado()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Não autenticado']);
    exit;
}

$conn   = (new Database())->connect();
$userId = (int)($_SESSION['user_id'] ?? 0);
$user   = $_SESSION['user'] ?? [];
$role   = (string)($user['role'] ?? 'usuario');
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// Update last_seen_at on every authenticated chat API call
try {
    $lsSt = $conn->prepare("UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
    if ($lsSt) { $lsSt->bind_param('i', $userId); $lsSt->execute(); $lsSt->close(); }
} catch (Throwable $e) {}

try {
    match ($action) {
        'start'         => apiChatStart($conn, $userId, $role),
        'send'          => apiChatSend($conn, $userId),
        'messages'      => apiChatMessages($conn, $userId),
        'poll'          => apiChatPoll($conn, $userId),
        'conversations' => apiChatConversations($conn, $userId, $role),
        'read'          => apiChatMarkRead($conn, $userId),
        'unread_count'  => apiChatUnreadCount($conn, $userId),
        'archive'       => apiChatArchive($conn, $userId, $role),
        'vendor_status' => apiChatVendorStatus($conn),
        'toggle_chat'   => apiChatToggle($conn, $userId, $role),
        'order_chat'    => apiChatFromOrder($conn, $userId),
        'confirm_delivery' => apiChatConfirmDelivery($conn, $userId, $role),
        'get_buyer_code'  => apiChatGetBuyerCode($conn, $userId),
        'get_delivery_status' => apiChatGetDeliveryStatus($conn, $userId),
        'admin_conversations' => apiChatAdminConversations($conn, $role),
        'admin_messages'      => apiChatAdminMessages($conn, $role),
        default         => apiNotFound(),
    };
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Erro interno: ' . $e->getMessage()]);
}

// ─── Helpers ───────────────────────────────────────────────

function chatResolveMediaUrl(?string $raw, string $placeholder = ''): ?string
{
    if ($raw === null || trim($raw) === '') return $placeholder ?: null;
    $raw = trim(str_replace('\\', '/', $raw));
    if (preg_match('~^https?://~i', $raw)) return $raw;
    // Handle media: format (media library)
    if (str_starts_with($raw, 'media:')) {
        return BASE_PATH . '/api/media?id=' . substr($raw, 6);
    }
    if (str_starts_with($raw, '/mercado_admin/public/')) {
        return BASE_PATH . substr($raw, strlen('/mercado_admin/public'));
    }
    if (str_starts_with($raw, '/mercado_admin/')) {
        return BASE_PATH . '/' . ltrim(substr($raw, strlen('/mercado_admin')), '/');
    }
    // Prefix with BASE_PATH (matches chat.php resolution)
    return BASE_PATH . '/' . ltrim($raw, '/');
}

// ─── Action handlers ───────────────────────────────────────

function apiChatStart($conn, int $userId, string $role): void
{
    $vendorId  = (int)($_POST['vendor_id'] ?? $_GET['vendor_id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);

    if ($vendorId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Vendedor inválido']);
        return;
    }

    // Check if vendor has chat enabled
    if (!chatVendorEnabled($conn, $vendorId)) {
        echo json_encode(['ok' => false, 'msg' => 'Este vendedor não aceita mensagens no momento']);
        return;
    }

    // Determine buyer: if current user is the vendor, swap roles
    $buyerId = $userId;
    if ($userId === $vendorId) {
        echo json_encode(['ok' => false, 'msg' => 'Você não pode conversar consigo mesmo']);
        return;
    }

    if (!chatBuyerCanContactVendor($conn, $buyerId, $vendorId, $productId > 0 ? $productId : null)) {
        echo json_encode(['ok' => false, 'msg' => 'Chat disponível somente após compra confirmada com este vendedor']);
        return;
    }

    $conv = chatGetOrCreateConversation($conn, $buyerId, $vendorId, $productId > 0 ? $productId : null);
    if (!$conv) {
        echo json_encode(['ok' => false, 'msg' => 'Erro ao criar conversa']);
        return;
    }

    echo json_encode([
        'ok' => true,
        'conversation_id' => (int)$conv['id'],
    ]);
}

function apiChatSend($conn, int $userId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => 'Método não permitido']);
        return;
    }

    $convId  = (int)($_POST['conversation_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));

    if ($convId <= 0 || $message === '') {
        echo json_encode(['ok' => false, 'msg' => 'Dados incompletos']);
        return;
    }

    if (mb_strlen($message) > 2000) {
        echo json_encode(['ok' => false, 'msg' => 'Mensagem muito longa (máx 2000 caracteres)']);
        return;
    }

    $conv = chatGetConversation($conn, $convId, $userId);
    if (!$conv) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada']);
        return;
    }

    $buyerId = (int)($conv['buyer_id'] ?? 0);
    $vendorId = (int)($conv['vendor_id'] ?? 0);
    $productId = isset($conv['product_id']) ? (int)$conv['product_id'] : 0;
    if ($userId === $buyerId && !chatBuyerCanContactVendor($conn, $buyerId, $vendorId, $productId > 0 ? $productId : null)) {
        echo json_encode(['ok' => false, 'msg' => 'Chat disponível somente após compra confirmada com este vendedor']);
        return;
    }

    $msg = chatSendMessage($conn, $convId, $userId, $message);
    if (!$msg) {
        echo json_encode(['ok' => false, 'msg' => 'Erro ao enviar mensagem']);
        return;
    }

    echo json_encode([
        'ok'  => true,
        'msg' => [
            'id'         => (int)$msg['id'],
            'sender_id'  => (int)$msg['sender_id'],
            'message'    => $msg['message'],
            'criado_em'  => $msg['criado_em'],
            'is_mine'    => true,
        ],
    ]);
}

function apiChatMessages($conn, int $userId): void
{
    $convId = (int)($_GET['conversation_id'] ?? 0);
    $page   = max(1, (int)($_GET['page'] ?? 1));

    if ($convId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa inválida']);
        return;
    }

    // Verify access
    $conv = chatGetConversation($conn, $convId, $userId);
    if (!$conv) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada']);
        return;
    }

    // Mark as read
    chatMarkRead($conn, $convId, $userId);

    $messages = chatGetMessages($conn, $convId, $page);

    $formatted = array_map(function ($m) use ($userId) {
        return [
            'id'            => (int)$m['id'],
            'sender_id'     => (int)$m['sender_id'],
            'sender_name'   => $m['sender_name'],
            'sender_avatar' => chatResolveMediaUrl($m['sender_avatar']),
            'message'       => $m['message'],
            'criado_em'     => $m['criado_em'],
            'is_read'       => (bool)$m['is_read'],
            'is_mine'       => (int)$m['sender_id'] === $userId,
        ];
    }, $messages);

    echo json_encode([
        'ok'           => true,
        'conversation' => [
            'id'            => (int)$conv['id'],
            'buyer_name'    => $conv['buyer_name'],
            'vendor_name'   => $conv['vendor_name'],
            'store_name'    => $conv['store_name'],
            'product_name'  => $conv['product_name'],
            'product_image' => $conv['product_image'],
            'product_slug'  => $conv['product_slug'],
            'product_price' => $conv['product_price'],
        ],
        'messages' => $formatted,
    ]);
}

function apiChatPoll($conn, int $userId): void
{
    $convId  = (int)($_GET['conversation_id'] ?? 0);
    $afterId = (int)($_GET['after_id'] ?? 0);

    if ($convId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa inválida']);
        return;
    }

    // Verify access
    $conv = chatGetConversation($conn, $convId, $userId);
    if (!$conv) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada']);
        return;
    }

    // Mark as read
    chatMarkRead($conn, $convId, $userId);

    $messages = chatGetNewMessages($conn, $convId, $afterId);

    $formatted = array_map(function ($m) use ($userId) {
        return [
            'id'            => (int)$m['id'],
            'sender_id'     => (int)$m['sender_id'],
            'sender_name'   => $m['sender_name'],
            'sender_avatar' => chatResolveMediaUrl($m['sender_avatar']),
            'message'       => $m['message'],
            'criado_em'     => $m['criado_em'],
            'is_mine'       => (int)$m['sender_id'] === $userId,
        ];
    }, $messages);

    echo json_encode([
        'ok'       => true,
        'messages' => $formatted,
    ]);
}

function apiChatConversations($conn, int $userId, string $role): void
{
    $conversations = chatListConversations($conn, $userId, $role);

    $formatted = array_map(function ($c) {
        // Cada conversa carrega "my_role" indicando se o usuário é vendedor ou comprador NAQUELA conversa.
        $myRole = (string)($c['my_role'] ?? 'comprador');
        $otherName = $myRole === 'vendedor'
            ? ($c['other_name'] ?? '')
            : (($c['store_name'] ?? '') ?: ($c['other_name'] ?? ''));
        return [
            'id'            => (int)$c['id'],
            'my_role'       => $myRole,
            'other_name'    => $otherName,
            'other_avatar'  => chatResolveMediaUrl($c['other_avatar']),
            'product_name'  => $c['product_name'],
            'product_image' => chatResolveMediaUrl($c['product_image']),
            'last_message'  => $c['last_message'],
            'last_msg_time' => $c['last_msg_time'],
            'unread_count'  => (int)($c['unread_count'] ?? 0),
            'last_seen_at'  => $c['other_last_seen'] ?? null,
        ];
    }, $conversations);

    echo json_encode(['ok' => true, 'conversations' => $formatted]);
}

function apiChatMarkRead($conn, int $userId): void
{
    $convId = (int)($_POST['conversation_id'] ?? $_GET['conversation_id'] ?? 0);
    if ($convId > 0) {
        chatMarkRead($conn, $convId, $userId);
    }
    echo json_encode(['ok' => true]);
}

function apiChatUnreadCount($conn, int $userId): void
{
    $count = chatUnreadCount($conn, $userId);
    echo json_encode(['ok' => true, 'count' => $count]);
}

function apiChatArchive($conn, int $userId, string $role): void
{
    $convId = (int)($_POST['conversation_id'] ?? 0);
    if ($convId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa inválida']);
        return;
    }
    chatArchiveConversation($conn, $convId, $userId, $role);
    echo json_encode(['ok' => true]);
}

function apiChatVendorStatus($conn): void
{
    $vendorId = (int)($_GET['vendor_id'] ?? 0);
    if ($vendorId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Vendedor inválido']);
        return;
    }
    $enabled = chatVendorEnabled($conn, $vendorId);
    echo json_encode(['ok' => true, 'chat_enabled' => $enabled]);
}

function apiChatToggle($conn, int $userId, string $role): void
{
    if ($role !== 'vendedor') {
        echo json_encode(['ok' => false, 'msg' => 'Apenas vendedores podem alterar esta configuração']);
        return;
    }
    $enabled = (bool)($_POST['enabled'] ?? true);
    chatToggleVendor($conn, $userId, $enabled);
    echo json_encode(['ok' => true, 'chat_enabled' => $enabled]);
}

/**
 * Get the chat conversation ID for an order (used for post-purchase redirect).
 */
function apiChatFromOrder($conn, int $userId): void
{
    $orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Pedido inválido']);
        return;
    }

    // Verify the order belongs to this user
    $st = $conn->prepare('SELECT id, user_id FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
    if (!$st) {
        echo json_encode(['ok' => false, 'msg' => 'Erro interno']);
        return;
    }
    $st->bind_param('ii', $orderId, $userId);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$order) {
        echo json_encode(['ok' => false, 'msg' => 'Pedido não encontrado']);
        return;
    }

    // Find the first vendor's conversation for this order
    $st = $conn->prepare('SELECT vendedor_id, product_id FROM order_items WHERE order_id = ? AND vendedor_id IS NOT NULL AND vendedor_id > 0 ORDER BY id ASC LIMIT 1');
    if (!$st) {
        echo json_encode(['ok' => false, 'msg' => 'Erro interno']);
        return;
    }
    $st->bind_param('i', $orderId);
    $st->execute();
    $item = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$item) {
        echo json_encode(['ok' => false, 'msg' => 'Nenhum vendedor encontrado']);
        return;
    }

    $vendorId = (int)$item['vendedor_id'];
    $productId = (int)($item['product_id'] ?? 0);

    $conv = chatGetOrCreateConversation($conn, $userId, $vendorId, $productId > 0 ? $productId : null);
    if (!$conv) {
        echo json_encode(['ok' => false, 'msg' => 'Erro ao encontrar conversa']);
        return;
    }

    echo json_encode([
        'ok' => true,
        'conversation_id' => (int)$conv['id'],
    ]);
}

function apiNotFound(): void
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Ação inválida']);
}

// ─── Admin handlers ───────────────────────────────────────

function apiChatAdminConversations($conn, string $role): void
{
    if ($role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Acesso negado']);
        return;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $conversations = chatListAllConversations($conn, $page, 50);
    $total = chatCountAllConversations($conn);

    $formatted = array_map(function ($c) {
        return [
            'id'             => (int)$c['id'],
            'buyer_name'     => $c['buyer_name'],
            'buyer_email'    => $c['buyer_email'],
            'vendor_name'    => $c['vendor_name'],
            'vendor_email'   => $c['vendor_email'],
            'store_name'     => $c['store_name'],
            'product_name'   => $c['product_name'],
            'total_messages' => (int)($c['total_messages'] ?? 0),
            'last_message'   => $c['last_message'],
            'last_msg_time'  => $c['last_msg_time'],
            'criado_em'      => $c['criado_em'],
        ];
    }, $conversations);

    echo json_encode(['ok' => true, 'conversations' => $formatted, 'total' => $total, 'page' => $page]);
}

function apiChatAdminMessages($conn, string $role): void
{
    if ($role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Acesso negado']);
        return;
    }

    $convId = (int)($_GET['conversation_id'] ?? 0);
    if ($convId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa inválida']);
        return;
    }

    $conv = chatGetConversationAdmin($conn, $convId);
    if (!$conv) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada']);
        return;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $messages = chatGetMessagesAdmin($conn, $convId, $page, 100);

    $buyerId = (int)$conv['buyer_id'];
    $formatted = array_map(function ($m) use ($buyerId) {
        return [
            'id'            => (int)$m['id'],
            'sender_id'     => (int)$m['sender_id'],
            'sender_name'   => $m['sender_name'],
            'message'       => $m['message'],
            'criado_em'     => $m['criado_em'],
            'is_read'       => (bool)$m['is_read'],
            'is_buyer'      => (int)$m['sender_id'] === $buyerId,
        ];
    }, $messages);

    echo json_encode([
        'ok'           => true,
        'conversation' => [
            'id'           => (int)$conv['id'],
            'buyer_name'   => $conv['buyer_name'],
            'buyer_email'  => $conv['buyer_email'],
            'vendor_name'  => $conv['vendor_name'],
            'vendor_email' => $conv['vendor_email'],
            'store_name'   => $conv['store_name'],
            'product_name' => $conv['product_name'],
        ],
        'messages' => $formatted,
    ]);
}

// ─── Delivery code confirmation via chat widget ────────────

function apiChatConfirmDelivery($conn, int $userId, string $role): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => 'Método não permitido']);
        return;
    }

    if ($role !== 'vendedor') {
        echo json_encode(['ok' => false, 'msg' => 'Apenas vendedores podem confirmar entrega.']);
        return;
    }

    $convId = (int)($_POST['conversation_id'] ?? 0);
    $code   = strtoupper(trim((string)($_POST['delivery_code'] ?? '')));

    if ($convId <= 0 || strlen($code) !== 6) {
        echo json_encode(['ok' => false, 'msg' => 'Dados inválidos. O código deve ter 6 caracteres.']);
        return;
    }

    // Get conversation to find the buyer
    $conv = chatGetConversation($conn, $convId, $userId);
    if (!$conv) {
        echo json_encode(['ok' => false, 'msg' => 'Conversa não encontrada.']);
        return;
    }

    $vendorId = (int)($conv['vendor_id'] ?? 0);
    $buyerId  = (int)($conv['buyer_id']  ?? 0);
    if ($userId !== $vendorId) {
        echo json_encode(['ok' => false, 'msg' => 'Apenas o vendedor pode confirmar.']);
        return;
    }

    // Find orders between this buyer+vendor with matching delivery code
    require_once __DIR__ . '/../../src/wallet_escrow.php';

    $st = $conn->prepare(
        "SELECT DISTINCT o.id
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE o.user_id = ?
           AND oi.vendedor_id = ?
           AND UPPER(o.delivery_code) = ?
           AND LOWER(o.status) IN ('pago','entregue')
         LIMIT 1"
    );
    $st->bind_param('iis', $buyerId, $vendorId, $code);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'Código de entrega incorreto ou pedido não encontrado.']);
        return;
    }

    $orderId = (int)$row['id'];
    [$ok, $msg] = escrowConfirmDeliveryByCode($conn, $orderId, $vendorId, $code);

    if ($ok) {
        // Send a system message confirming the delivery in chat
        chatSendSystemMessage($conn, $convId, $vendorId, "✅ Entrega confirmada com sucesso!\nO escrow foi liberado e o pagamento será creditado na carteira do vendedor.", 'system');
    }

    echo json_encode(['ok' => $ok, 'msg' => $msg]);
}

/**
 * Get buyer's delivery code for a conversation.
 */
function apiChatGetBuyerCode($conn, $userId): void
{
    $convId = (int)($_GET['conversation_id'] ?? 0);
    if ($convId <= 0) {
        echo json_encode(['ok' => false]);
        return;
    }

    $conv = chatGetConversation($conn, $convId, $userId);
    if (!$conv || (int)$conv['buyer_id'] !== $userId) {
        echo json_encode(['ok' => true, 'code' => null]);
        return;
    }

    $buyerId  = (int)$conv['buyer_id'];
    $vendorId = (int)$conv['vendor_id'];

    // Find the latest order between this buyer and vendor that has a delivery code
    $st = $conn->prepare(
        "SELECT o.delivery_code, o.status FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE o.user_id = ? AND oi.vendedor_id = ?
           AND o.delivery_code IS NOT NULL AND o.delivery_code != ''
           AND LOWER(o.status) IN ('pago','paid','entregue','enviado')
         ORDER BY o.id DESC LIMIT 1"
    );
    $st->bind_param('ii', $buyerId, $vendorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $status = $row ? strtolower(trim((string)$row['status'])) : null;
    $delivered = in_array($status, ['entregue', 'concluido'], true);

    echo json_encode([
        'ok' => true,
        'code' => $row['delivery_code'] ?? null,
        'delivered' => $delivered,
    ]);
}

/**
 * Get delivery status for a conversation (works for both buyer and vendor).
 * Returns whether the delivery has been confirmed.
 */
function apiChatGetDeliveryStatus($conn, $userId): void
{
    $convId = (int)($_GET['conversation_id'] ?? 0);
    if ($convId <= 0) {
        echo json_encode(['ok' => false]);
        return;
    }

    $conv = chatGetConversation($conn, $convId, $userId);
    if (!$conv) {
        echo json_encode(['ok' => true, 'delivered' => false]);
        return;
    }

    $buyerId  = (int)$conv['buyer_id'];
    $vendorId = (int)$conv['vendor_id'];

    $st = $conn->prepare(
        "SELECT o.status FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE o.user_id = ? AND oi.vendedor_id = ?
           AND o.delivery_code IS NOT NULL AND o.delivery_code != ''
           AND LOWER(o.status) IN ('pago','paid','entregue','enviado','concluido')
         ORDER BY o.id DESC LIMIT 1"
    );
    $st->bind_param('ii', $buyerId, $vendorId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $status = $row ? strtolower(trim((string)$row['status'])) : null;
    $delivered = in_array($status, ['entregue', 'concluido'], true);

    echo json_encode(['ok' => true, 'delivered' => $delivered]);
}