<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/vendor_portal.php';

header('Content-Type: application/json; charset=utf-8');
exigirVendedor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new Database();
$conn = $db->connect();

$uid = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? $_POST['acao'] ?? 'toggle');

if ($id <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Parâmetros inválidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    [$ok, $msg] = excluirMeuProduto($conn, $uid, $id);
    echo json_encode(['ok' => $ok, 'msg' => $msg, 'id' => $id], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'toggle') {
    $ativo = (int)($_POST['ativo'] ?? -1);
    if (!in_array($ativo, [0, 1], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Parâmetros inválidos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    [$ok, $msg] = toggleMeuProdutoAtivo($conn, $uid, $id);
    echo json_encode(['ok' => $ok, 'msg' => $msg, 'id' => $id, 'ativo' => $ativo], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
