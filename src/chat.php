<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\chat.php
// Note: strict_types intentionally omitted — session values are strings

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/media.php';

/**
 * Chat System — Business Logic
 * Handles conversations and messages between buyers and vendors.
 */

/**
 * Ensure chat tables exist (auto-migration).
 * Uses PDO::exec() directly because the PgCompat wrapper's query()
 * doesn't handle multi-statement SQL and silently catches errors.
 */
function chatEnsureTables($conn): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = $conn->getPdo();
        $rs = $pdo->query("SELECT to_regclass('public.chat_conversations')");
        $val = $rs ? $rs->fetchColumn() : null;
        if (!$val) {
            $sqlFile = __DIR__ . '/../sql/chat_migration.sql';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                $pdo->exec($sql);
            }
        }
        // Ensure last_seen_at column exists on users table
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMP DEFAULT NULL");
    } catch (Throwable $e) {
        error_log('chatEnsureTables migration error: ' . $e->getMessage());
    }
}

function chatBuildAttachmentMessage(string $mediaRef, string $caption = ''): string
{
    $mediaRef = trim($mediaRef);
    $caption = trim($caption);
    if ($mediaRef === '') {
        return $caption;
    }

    return "[ANEXO_IMAGEM]\n" . $mediaRef . ($caption !== '' ? "\n" . $caption : '');
}

function chatParseAttachmentMessage(string $message): ?array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($message));
    $prefix = "[ANEXO_IMAGEM]\n";
    if (!str_starts_with($normalized, $prefix)) {
        return null;
    }

    $payload = substr($normalized, strlen($prefix));
    $lines = explode("\n", $payload, 2);
    $mediaRef = trim((string)($lines[0] ?? ''));
    if ($mediaRef === '') {
        return null;
    }

    return [
        'media_ref' => $mediaRef,
        'caption' => trim((string)($lines[1] ?? '')),
    ];
}

function chatAttachmentUrl(string $mediaRef): string
{
    return mediaResolveUrl($mediaRef, '');
}

function chatMessagePreview(string $message): string
{
    $attachment = chatParseAttachmentMessage($message);
    if ($attachment) {
        return $attachment['caption'] !== ''
            ? 'Imagem enviada: ' . $attachment['caption']
            : 'Imagem enviada';
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", trim($message));
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^\[(INSTRUCOES_VENDA|ENTREGA_AUTO|CODIGO_ENTREGA|SISTEMA)\]\n/', $normalized, $match)) {
        return match ($match[1]) {
            'INSTRUCOES_VENDA' => 'Instruções da compra',
            'ENTREGA_AUTO' => 'Produto entregue',
            'CODIGO_ENTREGA' => 'Código de entrega',
            default => 'Mensagem do sistema',
        };
    }

    return trim((string)preg_replace('/^Equipe Basefy:\s*\n?/u', '', $normalized));
}

/**
 * Check if vendor has chat enabled.
 */
function chatVendorEnabled($conn, $vendorId): bool
{
    $vendorId = (int)$vendorId;
    chatEnsureTables($conn);

    try {
        $st = $conn->prepare("SELECT chat_enabled FROM seller_profiles WHERE user_id = ? LIMIT 1");
        if (!$st) return true;
        $st->bind_param('i', $vendorId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row) return true;
        return (bool)($row['chat_enabled'] ?? true);
    } catch (Throwable $e) {
        // Column might not exist yet — default to enabled
        return true;
    }
}

/**
 * Buyer can contact vendor only after at least one paid/completed purchase.
 * If $productId is provided, the purchase must include that product.
 */
function chatBuyerCanContactVendor($conn, $buyerId, $vendorId, $productId = null): bool
{
    $buyerId = (int)$buyerId;
    $vendorId = (int)$vendorId;
    $productId = $productId !== null ? (int)$productId : 0;

    if ($buyerId <= 0 || $vendorId <= 0 || $buyerId === $vendorId) {
        return false;
    }

    try {
        if ($productId > 0) {
            $st = $conn->prepare(
                "SELECT o.id
                 FROM orders o
                 JOIN order_items oi ON oi.order_id = o.id
                 WHERE o.user_id = ?
                   AND oi.vendedor_id = ?
                   AND oi.product_id = ?
                   AND LOWER(o.status) IN ('pago','entregue','concluido')
                 LIMIT 1"
            );
            if (!$st) return false;
            $st->bind_param('iii', $buyerId, $vendorId, $productId);
        } else {
            $st = $conn->prepare(
                "SELECT o.id
                 FROM orders o
                 JOIN order_items oi ON oi.order_id = o.id
                 WHERE o.user_id = ?
                   AND oi.vendedor_id = ?
                   AND LOWER(o.status) IN ('pago','entregue','concluido')
                 LIMIT 1"
            );
            if (!$st) return false;
            $st->bind_param('ii', $buyerId, $vendorId);
        }

        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (bool)$row;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Toggle vendor chat setting.
 */
function chatToggleVendor($conn, $vendorId, $enabled): bool
{
    $vendorId = (int)$vendorId;
    $enabled = (bool)$enabled;
    chatEnsureTables($conn);
    $val = $enabled ? 1 : 0;
    $st = $conn->prepare("UPDATE seller_profiles SET chat_enabled = ? WHERE user_id = ?");
    $st->bind_param('ii', $val, $vendorId);
    $st->execute();
    $st->close();
    return true;
}

/**
 * Get or create a conversation between buyer and vendor.
 * Optionally linked to a product.
 */
function chatGetOrCreateConversation($conn, $buyerId, $vendorId, $productId = null): ?array
{
    $buyerId = (int)$buyerId;
    $vendorId = (int)$vendorId;
    $productId = $productId !== null ? (int)$productId : null;
    chatEnsureTables($conn);

    $pid = $productId ?? 0;

    // Try to find existing conversation
    $st = $conn->prepare(
        "SELECT * FROM chat_conversations 
         WHERE buyer_id = ? AND vendor_id = ? AND COALESCE(product_id, 0) = ?
         LIMIT 1"
    );
    $st->bind_param('iii', $buyerId, $vendorId, $pid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) return $row;

    // Create new conversation
    $prodVal = $productId > 0 ? $productId : null;
    if ($prodVal) {
        $st = $conn->prepare(
            "INSERT INTO chat_conversations (buyer_id, vendor_id, product_id) VALUES (?, ?, ?) RETURNING *"
        );
        $st->bind_param('iii', $buyerId, $vendorId, $prodVal);
    } else {
        $st = $conn->prepare(
            "INSERT INTO chat_conversations (buyer_id, vendor_id) VALUES (?, ?) RETURNING *"
        );
        $st->bind_param('ii', $buyerId, $vendorId);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row ?: null;
}

/**
 * Send a message in a conversation.
 */
function chatSendMessage($conn, $conversationId, $senderId, string $message): ?array
{
    $conversationId = (int)$conversationId;
    $senderId = (int)$senderId;
    chatEnsureTables($conn);

    $message = trim($message);
    if ($message === '') return null;

    // Mask contact information to prevent off-platform transactions
    require_once __DIR__ . '/helpers.php';
    $message = maskContactInfo($message);

    // ── Fragmented censorship: check if recent messages + current form contact info ──
    try {
        $stRecent = $conn->prepare(
            "SELECT message FROM chat_messages
             WHERE conversation_id = ? AND sender_id = ?
             ORDER BY id DESC LIMIT 5"
        );
        $stRecent->bind_param('ii', $conversationId, $senderId);
        $stRecent->execute();
        $recentRows = $stRecent->get_result()->fetch_all(MYSQLI_ASSOC);
        $stRecent->close();

        if (!empty($recentRows)) {
            // Build combined text from recent messages (oldest first) + current
            $fragments = array_reverse(array_column($recentRows, 'message'));
            $fragments[] = $message;
            $combined = implode(' ', $fragments);

            // Also check a compact version (no spaces) to catch split numbers/emails
            $compact = preg_replace('/\s+/', '', $combined);

            if (hasContactInfo($combined) || hasContactInfo($compact)) {
                $message = '***';
            }
        }
    } catch (\Throwable $e) {
        // Fail open — don't block messages on error
    }

    // Verify sender belongs to this conversation
    $st = $conn->prepare("SELECT buyer_id, vendor_id FROM chat_conversations WHERE id = ? LIMIT 1");
    $st->bind_param('i', $conversationId);
    $st->execute();
    $conv = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$conv) return null;
    if ((int)$conv['buyer_id'] !== $senderId && (int)$conv['vendor_id'] !== $senderId) return null;

    // Insert message
    $st = $conn->prepare(
        "INSERT INTO chat_messages (conversation_id, sender_id, message) VALUES (?, ?, ?) RETURNING *"
    );
    $st->bind_param('iis', $conversationId, $senderId, $message);
    $st->execute();
    $msg = $st->get_result()->fetch_assoc();
    $st->close();

    // Update last_message_at
    $st = $conn->prepare("UPDATE chat_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?");
    $st->bind_param('i', $conversationId);
    $st->execute();
    $st->close();

    // Un-archive for both parties
    $st = $conn->prepare("UPDATE chat_conversations SET buyer_archived = FALSE, vendor_archived = FALSE WHERE id = ?");
    $st->bind_param('i', $conversationId);
    $st->execute();
    $st->close();

    // Notify the other participant about new message
    try {
        require_once __DIR__ . '/notifications.php';
        $recipientId = ($senderId === (int)$conv['buyer_id']) ? (int)$conv['vendor_id'] : (int)$conv['buyer_id'];
        if ($recipientId > 0) {
            notificationsCreate($conn, $recipientId, 'chat', 'Nova mensagem', 'Você recebeu uma nova mensagem. Abra o chat para responder.', '/dashboard?open_chat=' . $conversationId);
        }

        $senderName = 'Cliente';
        $otherName = 'Participante';
        $stNames = $conn->prepare(
            "SELECT bu.nome AS buyer_name, vu.nome AS vendor_name
             FROM chat_conversations c
             JOIN users bu ON bu.id = c.buyer_id
             JOIN users vu ON vu.id = c.vendor_id
             WHERE c.id = ?
             LIMIT 1"
        );
        if ($stNames) {
            $stNames->bind_param('i', $conversationId);
            $stNames->execute();
            $namesRow = $stNames->get_result()->fetch_assoc() ?: null;
            $stNames->close();
            if ($namesRow) {
                $buyerName = trim((string)($namesRow['buyer_name'] ?? '')) ?: 'Comprador';
                $vendorName = trim((string)($namesRow['vendor_name'] ?? '')) ?: 'Vendedor';
                if ($senderId === (int)$conv['buyer_id']) {
                    $senderName = $buyerName;
                    $otherName = $vendorName;
                } else {
                    $senderName = $vendorName;
                    $otherName = $buyerName;
                }
            }
        }

        $adminSummary = $senderName . ' enviou uma mensagem para ' . $otherName . ' na conversa #' . $conversationId . '.';
        notificationsNotifyAdmins($conn, 'chat', 'Nova mensagem no chat', $adminSummary, '/admin/chat?open=' . $conversationId, [], $senderId);
    } catch (\Throwable $e) { error_log('[Chat] Notification error: ' . $e->getMessage()); }

    return $msg;
}

/**
 * Send a SYSTEM message (auto-message) bypassing contact censorship.
 * Used for automated messages like delivery instructions, warranty info, etc.
 * The sender_id should be the vendor's user_id so it appears as a vendor message.
 * $type can be: 'system', 'delivery', 'instructions'
 */
function chatSendSystemMessage($conn, int $conversationId, int $senderId, string $message, string $type = 'system'): ?array
{
    $conversationId = (int)$conversationId;
    $senderId = (int)$senderId;
    chatEnsureTables($conn);

    $message = trim($message);
    if ($message === '') return null;

    // Verify sender belongs to this conversation
    $st = $conn->prepare("SELECT buyer_id, vendor_id FROM chat_conversations WHERE id = ? LIMIT 1");
    $st->bind_param('i', $conversationId);
    $st->execute();
    $conv = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$conv) return null;
    if ((int)$conv['buyer_id'] !== $senderId && (int)$conv['vendor_id'] !== $senderId) return null;

    // Prefix system messages with a type marker for special rendering
    $prefix = match ($type) {
        'delivery'     => "[ENTREGA_AUTO]\n",
        'instructions' => "[INSTRUCOES_VENDA]\n",
        'delivery_code' => "[CODIGO_ENTREGA]\n",
        default        => "[SISTEMA]\n",
    };
    $fullMessage = $prefix . $message;

    // Insert message (NO censorship — system messages are trusted)
    $st = $conn->prepare(
        "INSERT INTO chat_messages (conversation_id, sender_id, message) VALUES (?, ?, ?) RETURNING *"
    );
    $st->bind_param('iis', $conversationId, $senderId, $fullMessage);
    $st->execute();
    $msg = $st->get_result()->fetch_assoc();
    $st->close();

    // Update last_message_at
    $st = $conn->prepare("UPDATE chat_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?");
    $st->bind_param('i', $conversationId);
    $st->execute();
    $st->close();

    // Un-archive for both parties
    $st = $conn->prepare("UPDATE chat_conversations SET buyer_archived = FALSE, vendor_archived = FALSE WHERE id = ?");
    $st->bind_param('i', $conversationId);
    $st->execute();
    $st->close();

    return $msg;
}

function chatFormatDeliveryContentLevelUp(string $content): string
{
    $content = trim(str_replace(["\r\n", "\r"], "\n", $content));
    if ($content === '') return '';

    if (substr_count($content, "\n") < 2) {
        $content = preg_replace('/\s+(LOGIN\s*:)/iu', "\n$1", $content) ?? $content;
        $content = preg_replace('/\s+(SENHA\s*:)/iu', "\n$1", $content) ?? $content;
        $content = preg_replace('/\s+(2FA\s*:)/iu', "\n$1", $content) ?? $content;
        $content = preg_replace('/\s+(C[ÓO]DIGOS?\s+DE\s+RECUPERA[ÇC][ÃA]O\s*:)/iu', "\n$1", $content) ?? $content;
        $content = preg_replace('/\s+(PROXY\s*:)/iu', "\n$1", $content) ?? $content;
        $content = preg_replace('/\s+(\[\s*\{)/u', "\n$1", $content) ?? $content;
    }

    if (!preg_match('/cookies?\s*:/iu', $content)) {
        $content = preg_replace('/\n(\[\s*\{)/u', "\nCOOKIES:\n$1", $content, 1) ?? $content;
    }

    $content = preg_replace('/[ \t]*\n[ \t]*/', "\n", $content) ?? $content;
    $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;
    return trim($content);
}

function chatSendAdminMessage($conn, int $conversationId, int $adminId, string $message): ?array
{
    $conversationId = (int)$conversationId;
    $adminId = (int)$adminId;
    chatEnsureTables($conn);

    $message = trim($message);
    if ($conversationId <= 0 || $adminId <= 0 || $message === '') return null;

    $conv = chatGetConversationAdmin($conn, $conversationId);
    if (!$conv) return null;

    $fullMessage = chatParseAttachmentMessage($message)
        ? $message
        : "Equipe Basefy:\n" . $message;

    $st = $conn->prepare(
        "INSERT INTO chat_messages (conversation_id, sender_id, message) VALUES (?, ?, ?) RETURNING *"
    );
    if (!$st) return null;
    $st->bind_param('iis', $conversationId, $adminId, $fullMessage);
    $st->execute();
    $msg = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$msg) return null;

    $st = $conn->prepare("UPDATE chat_conversations SET last_message_at = CURRENT_TIMESTAMP, buyer_archived = FALSE, vendor_archived = FALSE WHERE id = ?");
    if ($st) {
        $st->bind_param('i', $conversationId);
        $st->execute();
        $st->close();
    }

    try {
        $buyerId = (int)($conv['buyer_id'] ?? 0);
        $vendorId = (int)($conv['vendor_id'] ?? 0);
        foreach ([$buyerId, $vendorId] as $recipientId) {
            if ($recipientId > 0 && $recipientId !== $adminId) {
                notificationsCreate($conn, $recipientId, 'chat', 'Mensagem da Equipe Basefy', 'A moderação enviou uma mensagem no chat do pedido.', '/dashboard?open_chat=' . $conversationId);
            }
        }
    } catch (\Throwable $e) { error_log('[Chat] Admin notification error: ' . $e->getMessage()); }

    return $msg;
}

/**
 * Auto-open chat after purchase: creates conversation, sends auto instructions,
 * delivery content, and delivery code — all as vendor messages.
 *
 * Called from escrowInitializeOrderItems() after payment confirmation.
 *
 * @param mixed $conn   Database connection
 * @param int   $orderId  The order ID
 * @return void
 */
function chatAutoOpenAfterPurchase($conn, int $orderId): void
{
    if ($orderId <= 0) { error_log('[ChatAutoOpen] Invalid orderId: ' . $orderId); return; }
    chatEnsureTables($conn);

    // Get order info
    $st = $conn->prepare("SELECT user_id, status FROM orders WHERE id = ? LIMIT 1");
    if (!$st) { error_log('[ChatAutoOpen] Failed prepare orders query for order #' . $orderId); return; }
    $st->bind_param('i', $orderId);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$order) { error_log('[ChatAutoOpen] Order #' . $orderId . ' not found'); return; }

    $buyerId = (int)$order['user_id'];

    // Get order items with product + vendor info
    $st = $conn->prepare("
        SELECT oi.id AS item_id, oi.product_id, oi.vendedor_id, oi.delivery_content,
               oi.quantidade, oi.preco_unit, oi.subtotal,
               p.nome AS product_name, p.slug AS product_slug, p.imagem AS product_image,
               p.descricao, p.auto_delivery_intro, p.auto_delivery_conclusion,
               u.nome AS vendor_name
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN users u ON u.id = oi.vendedor_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    if (!$st) { error_log('[ChatAutoOpen] Failed prepare order_items query for order #' . $orderId); return; }
    $st->bind_param('i', $orderId);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if (empty($items)) return;

    // Get delivery code for this order
    $deliveryCode = '';
    try {
        require_once __DIR__ . '/wallet_escrow.php';
        $deliveryCode = escrowGetDeliveryCode($conn, $orderId);
    } catch (\Throwable $e) {}

    // Group items by vendor
    $vendorItems = [];
    foreach ($items as $item) {
        $vendorId = (int)($item['vendedor_id'] ?? 0);
        if ($vendorId <= 0) continue;
        if (!isset($vendorItems[$vendorId])) $vendorItems[$vendorId] = [];
        $vendorItems[$vendorId][] = $item;
    }

    // For each vendor, create/open a conversation and send automated messages
    foreach ($vendorItems as $vendorId => $vItems) {
        // Use the first product_id for conversation linking
        $firstProductId = (int)($vItems[0]['product_id'] ?? 0);

        // Create or reuse conversation
        $conv = chatGetOrCreateConversation($conn, $buyerId, $vendorId, $firstProductId > 0 ? $firstProductId : null);
        if (!$conv) continue;
        $convId = (int)$conv['id'];

        // Check if we already sent auto-messages for this order (prevent duplicates)
        $checkMsg = $conn->prepare("SELECT id FROM chat_messages WHERE conversation_id = ? AND message LIKE ? LIMIT 1");
        if ($checkMsg) {
            $orderMarker = '%[INSTRUCOES_VENDA]%Pedido #' . $orderId . '%';
            $checkMsg->bind_param('is', $convId, $orderMarker);
            $checkMsg->execute();
            $existing = $checkMsg->get_result()->fetch_assoc();
            $checkMsg->close();
            if ($existing) continue; // Already sent for this order
        }

        // ── 1. Send auto-instructions message (from vendor) ──
        $vendorName = $vItems[0]['vendor_name'] ?? 'Vendedor';
        $productList = '';
        foreach ($vItems as $vi) {
            $pName = $vi['product_name'] ?? 'Produto';
            $qty = (int)($vi['quantidade'] ?? 1);
            $price = number_format((float)($vi['subtotal'] ?? 0), 2, ',', '.');
            $productList .= "• {$pName} (x{$qty}) — R$ {$price}\n";
        }

        $instructionMsg = "📋 Pedido #{$orderId} confirmado!\n\n"
            . "Olá! Seu pagamento foi confirmado com sucesso. Seguem os detalhes:\n\n"
            . "🛒 Itens comprados:\n{$productList}\n"
            . "📌 Instruções importantes:\n"
            . "• Confira o conteúdo entregue abaixo com atenção\n"
            . "• Em caso de problemas, responda neste chat\n"
            . "• Após verificar, confirme a entrega para liberar o pagamento ao vendedor\n\n"
            . "🛡️ Garantia:\n"
            . "• Você tem proteção durante o período de escrow\n"
            . "• Caso o produto não corresponda, abra uma disputa antes de confirmar a entrega\n"
            . "• Nunca compartilhe dados pessoais (telefone, e-mail) no chat";

        chatSendSystemMessage($conn, $convId, $vendorId, $instructionMsg, 'instructions');

        // ── 2. Send delivery content for each item (if auto-delivered) ──
        // Re-read delivery_content freshly from DB (autoDeliveryProcessOrder may have written it)
        foreach ($vItems as $vi) {
            $itemId = (int)($vi['item_id'] ?? 0);
            $deliveryContent = '';
            if ($itemId > 0) {
                $stFresh = $conn->prepare("SELECT delivery_content FROM order_items WHERE id = ? LIMIT 1");
                if ($stFresh) {
                    $stFresh->bind_param('i', $itemId);
                    $stFresh->execute();
                    $freshRow = $stFresh->get_result()->fetch_assoc();
                    $stFresh->close();
                    $deliveryContent = trim((string)($freshRow['delivery_content'] ?? ''));
                }
            }
            if ($deliveryContent === '') continue;

            $pName = $vi['product_name'] ?? 'Produto';
            $deliveryContent = chatFormatDeliveryContentLevelUp($deliveryContent);
            $deliveryMsg = "📦 Produto entregue: {$pName}\n"
                . "Formato padrão Level Up\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                . $deliveryContent . "\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
                . "⚠️ Copie e guarde este conteúdo em local seguro.";

            chatSendSystemMessage($conn, $convId, $vendorId, $deliveryMsg, 'delivery');
        }

        // ── 3. Delivery code is NOT sent automatically ──
        // The buyer must verify the product first, then provide the code
        // to the vendor manually via the chat or pedido_detalhes page.

        // ── 4. Notify buyer to check the chat ──
        try {
            require_once __DIR__ . '/notifications.php';
            notificationsCreate($conn, $buyerId, 'chat', 'Produto entregue no chat!', 'Seu pedido #' . $orderId . ' foi processado. Acesse o chat para ver as instruções e o conteúdo entregue.', '/dashboard?open_chat=' . $convId);
        } catch (\Throwable $e) {}

        // ── 5. Notify vendor about the new purchase chat ──
        try {
            notificationsCreate($conn, $vendorId, 'chat', 'Nova compra recebida!', 'O pedido #' . $orderId . ' foi pago. Acesse o chat para ver os detalhes.', '/dashboard?open_chat=' . $convId);
        } catch (\Throwable $e) {}
    }
}

/**
 * Get messages for a conversation (paginated, newest first → reversed for display).
 */
function chatGetMessages($conn, $conversationId, $page = 1, $perPage = 30): array
{
    $conversationId = (int)$conversationId;
    $page = (int)$page;
    $perPage = (int)$perPage;
    chatEnsureTables($conn);

    $offset = ($page - 1) * $perPage;
    $st = $conn->prepare(
        "SELECT m.*, u.nome AS sender_name, u.avatar AS sender_avatar
         FROM chat_messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = ?
         ORDER BY m.criado_em DESC
         LIMIT ? OFFSET ?"
    );
    $st->bind_param('iii', $conversationId, $perPage, $offset);
    $st->execute();
    $rows = $st->get_result()->fetch_all();
    $st->close();

    return array_reverse($rows); // chronological order for display
}

/**
 * Mark all messages as read for a user in a conversation.
 */
function chatMarkRead($conn, $conversationId, $userId): void
{
    $conversationId = (int)$conversationId;
    $userId = (int)$userId;
    chatEnsureTables($conn);

    $st = $conn->prepare(
        "UPDATE chat_messages SET is_read = TRUE 
         WHERE conversation_id = ? AND sender_id != ? AND is_read = FALSE"
    );
    $st->bind_param('ii', $conversationId, $userId);
    $st->execute();
    $st->close();
}

/**
 * Get conversation list for a user (buyer or vendor).
 */
function chatListConversations($conn, $userId, string $role = 'usuario'): array
{
    $userId = (int)$userId;
    chatEnsureTables($conn);

    // Retorna TODAS conversas do usuário (lado comprador OU vendedor) em uma única lista.
    // O "outro lado" é resolvido dinamicamente para cada linha. Antes a query filtrava por role,
    // o que escondia conversas do vendedor quando o role da sessão era 'usuario' (caso comum: o
    // usuário também é vendedor, com role default 'usuario' até o admin promover).
    $st = $conn->prepare(
        "SELECT c.*, 
                CASE WHEN c.vendor_id = ? THEN u_buyer.nome ELSE u_vendor.nome END  AS other_name,
                CASE WHEN c.vendor_id = ? THEN u_buyer.avatar ELSE u_vendor.avatar END AS other_avatar,
                CASE WHEN c.vendor_id = ? THEN u_buyer.last_seen_at ELSE u_vendor.last_seen_at END AS other_last_seen,
                CASE WHEN c.vendor_id = ? THEN 'vendedor' ELSE 'comprador' END AS my_role,
                p.nome AS product_name, p.imagem AS product_image,
                sp.nome_loja AS store_name,
                (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = c.id AND cm.sender_id != ? AND cm.is_read = FALSE) AS unread_count,
                (SELECT cm2.message FROM chat_messages cm2 WHERE cm2.conversation_id = c.id ORDER BY cm2.criado_em DESC LIMIT 1) AS last_message,
                (SELECT cm3.criado_em FROM chat_messages cm3 WHERE cm3.conversation_id = c.id ORDER BY cm3.criado_em DESC LIMIT 1) AS last_msg_time
         FROM chat_conversations c
         JOIN users u_buyer  ON u_buyer.id  = c.buyer_id
         JOIN users u_vendor ON u_vendor.id = c.vendor_id
         LEFT JOIN products p ON p.id = c.product_id
         LEFT JOIN seller_profiles sp ON sp.user_id = c.vendor_id
         WHERE (
                 (c.buyer_id  = ? AND c.buyer_archived  = FALSE)
              OR (c.vendor_id = ? AND c.vendor_archived = FALSE)
               )
           AND EXISTS (SELECT 1 FROM chat_messages cm0 WHERE cm0.conversation_id = c.id)
         ORDER BY c.last_message_at DESC"
    );
    $st->bind_param('iiiiiii', $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    $st->execute();
    $rows = $st->get_result()->fetch_all();
    $st->close();

    foreach ($rows as &$row) {
        $row['last_message'] = chatMessagePreview((string)($row['last_message'] ?? ''));
    }
    unset($row);

    return $rows;
}

/**
 * Get total unread count for a user.
 */
function chatUnreadCount($conn, $userId): int
{
    $userId = (int)$userId;
    chatEnsureTables($conn);

    $st = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM chat_messages m
         JOIN chat_conversations c ON c.id = m.conversation_id
         WHERE m.sender_id != ? AND m.is_read = FALSE
           AND (c.buyer_id = ? OR c.vendor_id = ?)"
    );
    $st->bind_param('iii', $userId, $userId, $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return (int)($row['cnt'] ?? 0);
}

/**
 * Get conversation details by ID, with permission check.
 */
function chatGetConversation($conn, $convId, $userId): ?array
{
    $convId = (int)$convId;
    $userId = (int)$userId;
    chatEnsureTables($conn);

    $st = $conn->prepare(
        "SELECT c.*, 
                bu.nome AS buyer_name, bu.avatar AS buyer_avatar, bu.last_seen_at AS buyer_last_seen,
                vu.nome AS vendor_name, vu.avatar AS vendor_avatar, vu.last_seen_at AS vendor_last_seen,
                sp.nome_loja AS store_name,
                p.nome AS product_name, p.imagem AS product_image, p.slug AS product_slug, p.preco AS product_price
         FROM chat_conversations c
         JOIN users bu ON bu.id = c.buyer_id
         JOIN users vu ON vu.id = c.vendor_id
         LEFT JOIN seller_profiles sp ON sp.user_id = c.vendor_id
         LEFT JOIN products p ON p.id = c.product_id
         WHERE c.id = ? AND (c.buyer_id = ? OR c.vendor_id = ?)
         LIMIT 1"
    );
    $st->bind_param('iii', $convId, $userId, $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row ?: null;
}

/**
 * Get new messages since a given message ID (for polling).
 */
function chatGetNewMessages($conn, $conversationId, $afterId): array
{
    $conversationId = (int)$conversationId;
    $afterId = (int)$afterId;
    chatEnsureTables($conn);

    $st = $conn->prepare(
        "SELECT m.*, u.nome AS sender_name, u.avatar AS sender_avatar
         FROM chat_messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = ? AND m.id > ?
         ORDER BY m.criado_em ASC"
    );
    $st->bind_param('ii', $conversationId, $afterId);
    $st->execute();
    $rows = $st->get_result()->fetch_all();
    $st->close();

    foreach ($rows as &$row) {
        $row['last_message'] = chatMessagePreview((string)($row['last_message'] ?? ''));
    }
    unset($row);

    return $rows;
}

/**
 * Archive a conversation for the requesting user.
 */
function chatArchiveConversation($conn, $convId, $userId, string $role): bool
{
    $convId = (int)$convId;
    $userId = (int)$userId;
    chatEnsureTables($conn);

    $col = $role === 'vendedor' ? 'vendor_archived' : 'buyer_archived';
    $idCol = $role === 'vendedor' ? 'vendor_id' : 'buyer_id';

    $st = $conn->prepare("UPDATE chat_conversations SET {$col} = TRUE WHERE id = ? AND {$idCol} = ?");
    $st->bind_param('ii', $convId, $userId);
    $st->execute();
    $st->close();

    return true;
}

/**
 * List ALL conversations system-wide (admin monitoring).
 * Returns conversations with buyer name, vendor/store name, message counts, dates.
 */
function chatListAllConversations($conn, $page = 1, $perPage = 50, string $search = ''): array
{
    $page = max(1, (int)$page);
    $perPage = (int)$perPage;
    chatEnsureTables($conn);

    $offset = ($page - 1) * $perPage;

    $searchClause = '';
    if ($search !== '') {
        $searchClause = " AND (bu.nome ILIKE ? OR vu.nome ILIKE ? OR COALESCE(sp.nome_loja,'') ILIKE ? OR COALESCE(p.nome,'') ILIKE ?)";
    }

    $st = $conn->prepare(
        "SELECT c.*,
                bu.nome AS buyer_name, bu.email AS buyer_email, bu.last_seen_at AS buyer_last_seen,
                vu.nome AS vendor_name, vu.email AS vendor_email, vu.last_seen_at AS vendor_last_seen,
                sp.nome_loja AS store_name,
                p.nome AS product_name,
                (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = c.id) AS total_messages,
                (SELECT cm2.message FROM chat_messages cm2 WHERE cm2.conversation_id = c.id ORDER BY cm2.criado_em DESC LIMIT 1) AS last_message,
                (SELECT cm3.criado_em FROM chat_messages cm3 WHERE cm3.conversation_id = c.id ORDER BY cm3.criado_em DESC LIMIT 1) AS last_msg_time
         FROM chat_conversations c
         JOIN users bu ON bu.id = c.buyer_id
         JOIN users vu ON vu.id = c.vendor_id
         LEFT JOIN seller_profiles sp ON sp.user_id = c.vendor_id
         LEFT JOIN products p ON p.id = c.product_id
         WHERE EXISTS (SELECT 1 FROM chat_messages cm0 WHERE cm0.conversation_id = c.id)
         {$searchClause}
         ORDER BY c.last_message_at DESC
         LIMIT ? OFFSET ?"
    );
    if ($search !== '') {
        $like = '%' . $search . '%';
        $st->bind_param('ssssii', $like, $like, $like, $like, $perPage, $offset);
    } else {
        $st->bind_param('ii', $perPage, $offset);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all();
    $st->close();

    return $rows;
}

/**
 * Count total conversations (admin stats).
 */
function chatCountAllConversations($conn, string $search = ''): int
{
    chatEnsureTables($conn);

    $searchClause = '';
    if ($search !== '') {
        $searchClause = " AND (bu.nome ILIKE ? OR vu.nome ILIKE ? OR COALESCE(sp.nome_loja,'') ILIKE ? OR COALESCE(p.nome,'') ILIKE ?)";
    }

    $st = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM chat_conversations c
         JOIN users bu ON bu.id = c.buyer_id
         JOIN users vu ON vu.id = c.vendor_id
         LEFT JOIN seller_profiles sp ON sp.user_id = c.vendor_id
         LEFT JOIN products p ON p.id = c.product_id
         WHERE EXISTS (SELECT 1 FROM chat_messages cm0 WHERE cm0.conversation_id = c.id)
         {$searchClause}"
    );
    if ($search !== '') {
        $like = '%' . $search . '%';
        $st->bind_param('ssss', $like, $like, $like, $like);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['cnt'] ?? 0);
}

/**
 * Get conversation details for admin (no ownership check).
 */
function chatGetConversationAdmin($conn, $convId): ?array
{
    $convId = (int)$convId;
    chatEnsureTables($conn);

    $st = $conn->prepare(
        "SELECT c.*,
                bu.nome AS buyer_name, bu.email AS buyer_email, bu.avatar AS buyer_avatar,
                vu.nome AS vendor_name, vu.email AS vendor_email, vu.avatar AS vendor_avatar,
                sp.nome_loja AS store_name,
                p.nome AS product_name, p.imagem AS product_image, p.slug AS product_slug
         FROM chat_conversations c
         JOIN users bu ON bu.id = c.buyer_id
         JOIN users vu ON vu.id = c.vendor_id
         LEFT JOIN seller_profiles sp ON sp.user_id = c.vendor_id
         LEFT JOIN products p ON p.id = c.product_id
         WHERE c.id = ?
         LIMIT 1"
    );
    $st->bind_param('i', $convId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row ?: null;
}

/**
 * Get messages for admin (no ownership check).
 */
function chatGetMessagesAdmin($conn, $conversationId, $page = 1, $perPage = 50): array
{
    $conversationId = (int)$conversationId;
    $page = max(1, (int)$page);
    $perPage = (int)$perPage;
    chatEnsureTables($conn);

    $offset = ($page - 1) * $perPage;
    $st = $conn->prepare(
        "SELECT m.*, u.nome AS sender_name, u.avatar AS sender_avatar
         FROM chat_messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = ?
         ORDER BY m.criado_em DESC
         LIMIT ? OFFSET ?"
    );
    $st->bind_param('iii', $conversationId, $perPage, $offset);
    $st->execute();
    $rows = $st->get_result()->fetch_all();
    $st->close();

    return array_reverse($rows);
}
