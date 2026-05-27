<?php
declare(strict_types=1);

function adminAlertsScalar($conn, string $sql): int
{
    try {
        $rs = $conn->query($sql);
        if (!$rs) return 0;
        $row = $rs->fetch_assoc() ?: [];
        return (int)($row['total'] ?? $row['qtd'] ?? $row['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function adminAlertsCounts($conn, int $adminUserId = 0): array
{
    $productsPending = adminAlertsScalar($conn, "SELECT COUNT(*) AS total FROM products WHERE COALESCE(status_aprovacao, 'aprovado') = 'pendente'");
    $ticketsPending = adminAlertsScalar($conn, "SELECT COUNT(*) AS total FROM support_tickets WHERE status IN ('aberto','em_andamento','nao_resolvido')");
    $reportsPending = adminAlertsScalar($conn, "SELECT COUNT(*) AS total FROM product_reports WHERE status = 'pendente'");
    $kycPending = adminAlertsScalar($conn, "SELECT COUNT(DISTINCT vd.user_id) AS total
        FROM user_verifications vd
        JOIN user_verifications vemail ON vemail.user_id = vd.user_id AND vemail.tipo = 'email'
        JOIN user_verifications vdoc ON vdoc.user_id = vd.user_id AND vdoc.tipo = 'documentos'
        WHERE vd.tipo = 'dados'
          AND (vd.status = 'pendente' OR vemail.status = 'pendente' OR vdoc.status = 'pendente')
          AND vd.status != 'rejeitado'
          AND vemail.status != 'rejeitado'
          AND vdoc.status != 'rejeitado'");

    $supportUnread = 0;
    if ($adminUserId > 0) {
        try {
            require_once __DIR__ . '/notifications.php';
            $byType = notificationsUnreadByType($conn, $adminUserId);
            $supportUnread = (int)($byType['chat'] ?? 0);
        } catch (Throwable $e) {
            $supportUnread = 0;
        }
    }

    $supportTotal = $supportUnread + $ticketsPending + $reportsPending;

    return [
        'products_pending' => $productsPending,
        'kyc_pending' => $kycPending,
        'support_unread' => $supportUnread,
        'tickets_pending' => $ticketsPending,
        'reports_pending' => $reportsPending,
        'support_total' => $supportTotal,
        'total' => $productsPending + $kycPending + $supportTotal,
    ];
}

function adminAlertsBadgeHtml(int $count, string $class = 'ml-auto'): string
{
    if ($count <= 0) return '';
    $label = $count > 99 ? '99+' : (string)$count;
    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}
