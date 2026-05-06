<?php
declare(strict_types=1);
/**
 * API endpoint for product questions (Q&A)
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/questions.php';
require_once __DIR__ . '/../../src/media.php';

$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($input['action'] ?? $_GET['action'] ?? '');

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['user']['role'] ?? '');

$conn = (new Database())->connect();

switch ($action) {
    case 'ask':
        if ($userId < 1) {
            echo json_encode(['ok' => false, 'error' => 'Faça login para enviar uma pergunta.']);
            exit;
        }
        $productId = (int)($input['product_id'] ?? 0);
        $pergunta  = trim((string)($input['pergunta'] ?? ''));

        [$ok, $msg] = questionsAsk($conn, $productId, $userId, $pergunta);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : $msg, 'message' => $ok ? $msg : null]);
        break;

    case 'answer':
        if ($userId < 1) {
            echo json_encode(['ok' => false, 'error' => 'Faça login para responder.']);
            exit;
        }
        $questionId = (int)($input['question_id'] ?? 0);
        $resposta   = trim((string)($input['resposta'] ?? ''));

        // Verify the user is the product vendor or admin
        $q = questionsGetById($conn, $questionId);
        if (!$q) {
            echo json_encode(['ok' => false, 'error' => 'Pergunta não encontrada.']);
            exit;
        }
        // Check if user is vendor of the product or admin
        $prodStmt = $conn->prepare("SELECT vendedor_id FROM products WHERE id = ? LIMIT 1");
        $prodStmt->bind_param('i', $q['product_id']);
        $prodStmt->execute();
        $prod = $prodStmt->get_result()->fetch_assoc();
        $vendorId = $prod ? (int)$prod['vendedor_id'] : 0;

        if ($userId !== $vendorId && $userRole !== 'admin') {
            echo json_encode(['ok' => false, 'error' => 'Apenas o vendedor pode responder.']);
            exit;
        }

        [$ok, $msg] = questionsAnswer($conn, $questionId, $userId, $resposta);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : $msg, 'message' => $ok ? $msg : null]);
        break;

    case 'list':
        $productId = (int)($input['product_id'] ?? $_GET['product_id'] ?? 0);
        $page      = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
        $limit     = 5;
        $offset    = ($page - 1) * $limit;

        $questions = questionsListByProduct($conn, $productId, $limit, $offset);
        $total     = questionsCountByProduct($conn, $productId);

        // Resolve avatar URLs
        foreach ($questions as &$_q) {
            $rawAvatar = trim((string)($_q['user_avatar'] ?? ''));
            $_q['user_avatar_url'] = $rawAvatar !== '' ? mediaResolveUrl($rawAvatar) : '';
            $_q['criado_em_label'] = questionsTimeAgo((string)($_q['criado_em'] ?? ''));
            $_q['respondido_em_label'] = !empty($_q['respondido_em']) ? questionsTimeAgo((string)$_q['respondido_em']) : '';
        }
        unset($_q);

        echo json_encode([
            'ok'        => true,
            'questions' => $questions,
            'total'     => $total,
            'page'      => $page,
            'pages'     => max(1, (int)ceil($total / $limit)),
        ]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Ação inválida.']);
}
