<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\notifications.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/push_debug.php';

/**
 * Notifications system — supports categories: anuncio, venda, chat, ticket
 */

function notificationsEnsureTable($conn): void
{
    static $done = false;
    if ($done) return;

    // Try creating the table
    $conn->query("
        CREATE TABLE IF NOT EXISTS notifications (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL DEFAULT 0,
            tipo VARCHAR(30) NOT NULL DEFAULT 'anuncio',
            titulo VARCHAR(255) NOT NULL DEFAULT '',
            mensagem TEXT NOT NULL DEFAULT '',
            link VARCHAR(500) NOT NULL DEFAULT '',
            lida BOOLEAN NOT NULL DEFAULT FALSE,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Verify table actually exists before marking done
    $check = $conn->query("SELECT 1 FROM notifications LIMIT 1");
    if ($check === false) {
        // Table still doesn't exist — don't set $done so next call retries
        error_log('[Notifications] WARN: notifications table could not be created or verified.');
        return;
    }
    $done = true;

    // Index for fast user lookups
    $conn->query("CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, lida, criado_em DESC)");
}

function notificationsTypeLabel(string $tipo): string
{
    return match ($tipo) {
        'venda' => 'Venda',
        'chat' => 'Chat',
        'ticket' => 'Ticket',
        default => 'Anúncio',
    };
}

function notificationsAbsoluteLink(string $link): string
{
    $trimmed = trim($link);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $trimmed)) {
        return $trimmed;
    }
    return rtrim(APP_URL, '/') . '/' . ltrim($trimmed, '/');
}

function notificationsLoadEmailRecipient($conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $st = $conn->prepare("SELECT nome, email FROM users WHERE id = ? LIMIT 1");
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    if (!$row || trim((string)($row['email'] ?? '')) === '') {
        return null;
    }

    return [
        'nome' => trim((string)($row['nome'] ?? 'Usuário')) ?: 'Usuário',
        'email' => trim((string)$row['email']),
    ];
}

function notificationsSendEmail($conn, int $userId, string $tipo, string $titulo, string $mensagem = '', string $link = ''): bool
{
    if ($userId <= 0 || !smtpConfigured($conn)) {
        return false;
    }

    $recipient = notificationsLoadEmailRecipient($conn, $userId);
    if (!$recipient) {
        return false;
    }

    $absoluteLink = notificationsAbsoluteLink($link);
    $tipoLabel = notificationsTypeLabel($tipo);
    $html = emailNotificacaoSistema($recipient['nome'], $tipoLabel, $titulo, $mensagem, $absoluteLink, $conn);
    $subject = $titulo . ' - ' . (defined('APP_NAME') ? APP_NAME : 'Basefy');

    return smtpSend($recipient['email'], $subject, $html);
}

function notificationsAdminRecipientIds($conn, int $excludeUserId = 0): array
{
    $excludeUserId = max(0, $excludeUserId);
    $ids = [];

    try {
        $sql = "SELECT id FROM users WHERE role IN ('admin','administrador') AND COALESCE(ativo, 1) = 1";
        if ($excludeUserId > 0) {
            $sql .= ' AND id <> ?';
        }
        $sql .= ' ORDER BY id ASC';

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }

        if ($excludeUserId > 0) {
            $st->bind_param('i', $excludeUserId);
        }

        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $st->close();

        foreach ($rows as $row) {
            $userId = (int)($row['id'] ?? 0);
            if ($userId > 0) {
                $ids[] = $userId;
            }
        }
    } catch (\Throwable $e) {
        error_log('[Notifications] Admin recipients error: ' . $e->getMessage());
    }

    return array_values(array_unique($ids));
}

function notificationsNotifyAdmins($conn, string $tipo, string $titulo, string $mensagem = '', string $link = '', array $options = [], int $excludeUserId = 0): int
{
    $sent = 0;
    foreach (notificationsAdminRecipientIds($conn, $excludeUserId) as $adminId) {
        notificationsCreate($conn, (int)$adminId, $tipo, $titulo, $mensagem, $link, $options);
        $sent++;
    }
    return $sent;
}

/**
 * Create a notification for a specific user.
 * @param string $tipo  anuncio|venda|chat|ticket
 */
function notificationsCreate($conn, int $userId, string $tipo, string $titulo, string $mensagem = '', string $link = '', array $options = []): int
{
    // Capture the REAL caller (who triggered this notification)
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
    $realCaller = 'unknown';
    foreach ($bt as $frame) {
        $f = basename($frame['file'] ?? '');
        if ($f !== 'notifications.php' && $f !== '') {
            $realCaller = $f . ':' . ($frame['line'] ?? '?') . ' (' . ($frame['function'] ?? '?') . ')';
            break;
        }
    }

    pushDebugLog('▶ notificationsCreate CALLED', [
        'userId' => $userId,
        'tipo' => $tipo,
        'titulo' => $titulo,
        'mensagem' => mb_substr($mensagem, 0, 80),
        'link' => $link,
        'skipEmail' => !empty($options['skip_email']),
        'caller' => $realCaller,
    ]);

    notificationsEnsureTable($conn);
    pushDebugLog('  ✓ notificationsEnsureTable done');

    // ── Step 1: INSERT notification (isolated so it never blocks push) ──
    $id = 0;
    try {
        $st = $conn->prepare("INSERT INTO notifications (user_id, tipo, titulo, mensagem, link) VALUES (?, ?, ?, ?, ?)");
        if (!$st) {
            pushDebugLog('  ✗ STEP 1 FAIL: prepare returned false', ['userId' => $userId]);
        } else {
            $st->bind_param('issss', $userId, $tipo, $titulo, $mensagem, $link);
            $st->execute();
            $id = (int)$conn->insert_id;
            $st->close();
            pushDebugLog('  ✓ STEP 1 OK: notification inserted', ['notifId' => $id, 'userId' => $userId]);
        }
    } catch (\Throwable $insErr) {
        pushDebugLog('  ✗ STEP 1 EXCEPTION: INSERT failed', [
            'userId' => $userId,
            'error' => $insErr->getMessage(),
            'trace' => $insErr->getTraceAsString(),
        ]);
    }

    // ── Step 2: Web Push — runs ALWAYS regardless of INSERT outcome ──
    // IMPORTANT: cast (int) because bind_param's &...$vars reference can
    // cause PDO to convert $userId from int to string, which breaks
    // strict_types calls to pushSendToUser.
    $userIdInt = (int)$userId;
    if ($userIdInt > 0) {
        pushDebugLog('  → STEP 2: calling pushSendToUser', ['userId' => $userIdInt, 'type' => gettype($userId)]);
        try {
            $pushSent = pushSendToUser($conn, $userIdInt, $titulo, $mensagem, $link);
            pushDebugLog('  ✓ STEP 2 DONE: pushSendToUser returned', ['userId' => $userId, 'sent' => $pushSent]);
        } catch (\Throwable $pushErr) {
            pushDebugLog('  ✗ STEP 2 EXCEPTION: pushSendToUser failed', [
                'userId' => $userId,
                'error' => $pushErr->getMessage(),
                'trace' => $pushErr->getTraceAsString(),
            ]);
        }
    } else {
        pushDebugLog('  ⊘ STEP 2 SKIP: userId <= 0', ['userId' => $userId]);
    }

    // ── Step 3: Email — uses the same SMTP transport already configured ──
    if ($userIdInt > 0 && empty($options['skip_email'])) {
        pushDebugLog('  → STEP 3: calling notificationsSendEmail', ['userId' => $userIdInt]);
        try {
            $emailSent = notificationsSendEmail($conn, $userIdInt, $tipo, $titulo, $mensagem, $link);
            pushDebugLog('  ✓ STEP 3 DONE: notificationsSendEmail returned', ['userId' => $userId, 'sent' => $emailSent]);
        } catch (
            \Throwable $emailErr
        ) {
            pushDebugLog('  ✗ STEP 3 EXCEPTION: notificationsSendEmail failed', [
                'userId' => $userId,
                'error' => $emailErr->getMessage(),
                'trace' => $emailErr->getTraceAsString(),
            ]);
        }
    } else {
        pushDebugLog('  ⊘ STEP 3 SKIP: email disabled for this notification', ['userId' => $userId, 'skipEmail' => !empty($options['skip_email'])]);
    }

    pushDebugLog('◀ notificationsCreate FINISHED', ['notifId' => $id, 'userId' => $userId]);
    return $id;
}

/**
 * Create a broadcast notification for ALL users (user_id = 0).
 * Also sends web push to all subscribed users.
 */
function notificationsBroadcast($conn, string $tipo, string $titulo, string $mensagem = '', string $link = ''): int
{
    notificationsEnsureTable($conn);
    $zero = 0;
    $st = $conn->prepare("INSERT INTO notifications (user_id, tipo, titulo, mensagem, link) VALUES (?, ?, ?, ?, ?)");
    if (!$st) return 0;
    $st->bind_param('issss', $zero, $tipo, $titulo, $mensagem, $link);
    $st->execute();
    $id = (int)$conn->insert_id;
    $st->close();

    // ── Web Push: send to ALL subscribed users ──
    try {
        $pushFile = __DIR__ . '/push.php';
        if (is_file($pushFile)) {
            require_once $pushFile;
            pushEnsureTable($conn);
            $allSubs = $conn->query("SELECT DISTINCT user_id FROM push_subscriptions WHERE user_id > 0");
            if ($allSubs) {
                while ($subRow = $allSubs->fetch_assoc()) {
                    pushSendToUser($conn, (int)$subRow['user_id'], $titulo, $mensagem, $link);
                }
            }
        }
    } catch (\Throwable $pushErr) {
        error_log('[Notifications] Broadcast push error: ' . $pushErr->getMessage());
    }

    return $id;
}

/**
 * List notifications for a user (includes broadcasts where user_id=0).
 */
function notificationsList($conn, int $userId, int $limit = 20, int $offset = 0): array
{
    notificationsEnsureTable($conn);
    $st = $conn->prepare("SELECT id, user_id, tipo, titulo, mensagem, link, lida, criado_em
                          FROM notifications
                          WHERE user_id = ? OR user_id = 0
                          ORDER BY criado_em DESC
                          LIMIT ? OFFSET ?");
    if (!$st) return [];
    $st->bind_param('iii', $userId, $limit, $offset);
    $st->execute();
    $rs = $st->get_result();
    $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
}

/**
 * Count unread notifications for a user.
 */
function notificationsUnreadCount($conn, int $userId): int
{
    notificationsEnsureTable($conn);
    $st = $conn->prepare("SELECT COUNT(*) AS qtd FROM notifications WHERE (user_id = ? OR user_id = 0) AND lida = FALSE");
    if (!$st) return 0;
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    return (int)($row['qtd'] ?? 0);
}

/**
 * Count unread by type for a user.
 */
function notificationsUnreadByType($conn, int $userId): array
{
    notificationsEnsureTable($conn);
    $st = $conn->prepare("SELECT tipo, COUNT(*) AS qtd FROM notifications WHERE (user_id = ? OR user_id = 0) AND lida = FALSE GROUP BY tipo");
    if (!$st) return ['anuncio' => 0, 'venda' => 0, 'chat' => 0, 'ticket' => 0];
    $st->bind_param('i', $userId);
    $st->execute();
    $rs = $st->get_result();
    $result = ['anuncio' => 0, 'venda' => 0, 'chat' => 0, 'ticket' => 0];
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $result[(string)$r['tipo']] = (int)$r['qtd'];
        }
    }
    $st->close();
    return $result;
}

/**
 * Mark a notification as read.
 */
function notificationsMarkRead($conn, int $notifId, int $userId): bool
{
    notificationsEnsureTable($conn);
    $st = $conn->prepare("UPDATE notifications SET lida = TRUE WHERE id = ? AND (user_id = ? OR user_id = 0)");
    if (!$st) return false;
    $st->bind_param('ii', $notifId, $userId);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();
    return $ok;
}

/**
 * Mark ALL notifications as read for a user.
 */
function notificationsMarkAllRead($conn, int $userId): int
{
    notificationsEnsureTable($conn);
    // Mark user-specific
    $st = $conn->prepare("UPDATE notifications SET lida = TRUE WHERE user_id = ? AND lida = FALSE");
    if (!$st) return 0;
    $st->bind_param('i', $userId);
    $st->execute();
    $cnt = (int)$st->affected_rows;
    $st->close();
    // For broadcasts, mark as read too
    $st2 = $conn->prepare("UPDATE notifications SET lida = TRUE WHERE user_id = 0 AND lida = FALSE");
    if ($st2) {
        $st2->execute();
        $cnt += (int)$st2->affected_rows;
        $st2->close();
    }
    return $cnt;
}
