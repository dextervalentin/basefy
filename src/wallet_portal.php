<?php
declare(strict_types=1);

require_once __DIR__ . '/blackcat_api.php';
require_once __DIR__ . '/m5_api.php';
require_once __DIR__ . '/notifications.php';

/**
 * Ensures wallet_withdrawals has tipo_chave and transaction_id columns (auto-migration).
 * Safe to call multiple times — runs ALTER only once per request.
 */
function _walletEnsureTipoChave($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->query("ALTER TABLE wallet_withdrawals ADD COLUMN IF NOT EXISTS tipo_chave VARCHAR(30)");
    } catch (Throwable $e) {}
    try {
        $conn->query("ALTER TABLE wallet_withdrawals ADD COLUMN IF NOT EXISTS transaction_id VARCHAR(60)");
    } catch (Throwable $e) {}
}

function walletSaldo($conn, int $userId): float
{
    $st = $conn->prepare('SELECT wallet_saldo FROM users WHERE id = ? LIMIT 1');
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (float)($row['wallet_saldo'] ?? 0);
}

function walletPortalTableColumns($conn, string $table): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return [];
    $cols = [];
    try {
        $rs = $conn->query('SHOW COLUMNS FROM ' . $table);
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $field = strtolower((string)($row['Field'] ?? ''));
                if ($field !== '') $cols[] = $field;
            }
        }
    } catch (Throwable $e) {}
    return $cols;
}

function walletNormalizePixType(string $type): string
{
    $type = mb_strtolower(trim($type));
    return match ($type) {
        'cpf' => 'CPF',
        'cnpj' => 'CNPJ',
        'email', 'e-mail' => 'Email',
        'telefone', 'phone', 'celular' => 'Telefone',
        'aleatoria', 'aleatória', 'evp', 'random', 'chave aleatoria', 'chave aleatória' => 'Aleatoria',
        default => '',
    };
}

function walletInferPixKeyType(string $pixKey): string
{
    $pixKey = trim($pixKey);
    if ($pixKey === '') return '';
    if (filter_var($pixKey, FILTER_VALIDATE_EMAIL)) return 'Email';

    $digits = preg_replace('/\D+/', '', $pixKey) ?? '';
    $len = strlen($digits);
    if ($len === 14) return 'CNPJ';
    if ($len === 11 && (str_contains($pixKey, '(') || str_starts_with($pixKey, '+'))) return 'Telefone';
    if ($len === 11) return 'CPF';
    if ($len === 10 || $len === 13) return 'Telefone';

    return 'Aleatoria';
}

function walletSavedPixData($conn, int $userId): array
{
    $empty = ['key' => '', 'type' => ''];
    if ($userId <= 0) return $empty;

    $sources = [
        ['table' => 'seller_profiles', 'user_col' => 'user_id', 'keys' => ['chave_pix', 'pix_key', 'pix_chave'], 'types' => ['tipo_chave', 'pix_key_type', 'tipo_chave_pix']],
        ['table' => 'users', 'user_col' => 'id', 'keys' => ['chave_pix', 'pix_key', 'pix_chave'], 'types' => ['tipo_chave', 'pix_key_type', 'tipo_chave_pix']],
    ];

    foreach ($sources as $source) {
        $table = (string)$source['table'];
        $cols = walletPortalTableColumns($conn, $table);
        if (!$cols) continue;

        $keyCol = '';
        foreach ($source['keys'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $keyCol = $candidate;
                break;
            }
        }
        if ($keyCol === '') continue;

        $typeSelect = "'' AS pix_type";
        foreach ($source['types'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $typeSelect = "`{$candidate}` AS pix_type";
                break;
            }
        }

        try {
            $sql = "SELECT `{$keyCol}` AS pix_key, {$typeSelect} FROM `{$table}` WHERE `{$source['user_col']}` = ? LIMIT 1";
            $st = $conn->prepare($sql);
            if (!$st) continue;
            $st->bind_param('i', $userId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc() ?: [];
            $st->close();

            $key = trim((string)($row['pix_key'] ?? ''));
            if ($key === '') continue;
            $type = walletNormalizePixType((string)($row['pix_type'] ?? '')) ?: walletInferPixKeyType($key);
            return ['key' => $key, 'type' => $type];
        } catch (Throwable $e) {}
    }

    return $empty;
}

function walletResumoFinanceiroUsuario($conn, int $userId): array
{
    $resumo = [
        'saldo_disponivel' => walletSaldo($conn, $userId),
        'vendas_bruto_liberado' => 0.0,
        'vendas_liquido_liberado' => 0.0,
        'taxa_plataforma' => 0.0,
        'valor_retido' => 0.0,
        'saques_pendentes' => 0.0,
        'saques_pagos' => 0.0,
    ];

    $wdCols = walletPortalTableColumns($conn, 'wallet_withdrawals');
    if (in_array('user_id', $wdCols, true) && in_array('valor', $wdCols, true) && in_array('status', $wdCols, true)) {
        try {
            $st = $conn->prepare("SELECT
                COALESCE(SUM(CASE WHEN status IN ('pendente','processando') THEN valor ELSE 0 END), 0) AS pendentes,
                COALESCE(SUM(CASE WHEN status IN ('pago','aprovado') THEN valor ELSE 0 END), 0) AS pagos
                FROM wallet_withdrawals WHERE user_id = ?");
            if ($st) {
                $st->bind_param('i', $userId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc() ?: [];
                $resumo['saques_pendentes'] = (float)($row['pendentes'] ?? 0);
                $resumo['saques_pagos'] = (float)($row['pagos'] ?? 0);
            }
        } catch (Throwable $e) {}
    }

    $oiCols = walletPortalTableColumns($conn, 'order_items');
    if (in_array('vendedor_id', $oiCols, true) && in_array('moderation_status', $oiCols, true)) {
        $grossExpr = in_array('subtotal', $oiCols, true) ? 'COALESCE(subtotal, 0)' : 'COALESCE(quantidade, 0) * COALESCE(preco_unit, 0)';
        $feeExpr = in_array('escrow_fee_amount', $oiCols, true) ? 'COALESCE(escrow_fee_amount, 0)' : '0';
        $netExpr = in_array('escrow_net_amount', $oiCols, true) ? 'COALESCE(escrow_net_amount, ' . $grossExpr . ' - ' . $feeExpr . ')' : '(' . $grossExpr . ' - ' . $feeExpr . ')';
        try {
            $sql = "SELECT
                COALESCE(SUM(CASE WHEN moderation_status = 'pendente' THEN {$grossExpr} ELSE 0 END), 0) AS retido,
                COALESCE(SUM(CASE WHEN moderation_status = 'aprovada' THEN {$grossExpr} ELSE 0 END), 0) AS bruto_liberado,
                COALESCE(SUM(CASE WHEN moderation_status = 'aprovada' THEN {$feeExpr} ELSE 0 END), 0) AS taxa_plataforma,
                COALESCE(SUM(CASE WHEN moderation_status = 'aprovada' THEN {$netExpr} ELSE 0 END), 0) AS liquido_liberado
                FROM order_items WHERE vendedor_id = ?";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param('i', $userId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc() ?: [];
                $resumo['valor_retido'] = (float)($row['retido'] ?? 0);
                $resumo['vendas_bruto_liberado'] = (float)($row['bruto_liberado'] ?? 0);
                $resumo['taxa_plataforma'] = (float)($row['taxa_plataforma'] ?? 0);
                $resumo['vendas_liquido_liberado'] = (float)($row['liquido_liberado'] ?? 0);
            }
        } catch (Throwable $e) {}
    }

    return $resumo;
}

function walletCriarRecargaPix($conn, int $userId, float $valor): array
{
    if ($userId <= 0 || $valor <= 0) {
        return [false, 'Valor inválido.', 0];
    }

    $stUser = $conn->prepare('SELECT nome, email FROM users WHERE id = ? LIMIT 1');
    $stUser->bind_param('i', $userId);
    $stUser->execute();
    $user = $stUser->get_result()->fetch_assoc();
    if (!$user) {
        return [false, 'Usuário não encontrado.', 0];
    }

    $amountCentavos = (int)round($valor * 100);
    if ($amountCentavos <= 0) {
        return [false, 'Valor inválido.', 0];
    }

    $topupCode = bin2hex(random_bytes(4));
    $externalRef = 'wallet_topup:' . $userId . ':' . time() . ':' . $topupCode;
    $webhookUrl  = rtrim(APP_URL, '/') . '/webhooks/m5';
    $description = 'Wallet topup #' . $userId . ' ' . $topupCode;

    [$okApi, $resp] = m5CreatePixQrCode($amountCentavos, $description, $webhookUrl, null);
    if (!$okApi) {
        error_log('[wallet_topup/m5] Falha ao gerar PIX: ' . json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return [false, (string)($resp['message'] ?? 'Falha ao gerar PIX.'), 0];
    }

    $data = $resp['data'] ?? [];
    $providerTransactionId = (string)($data['transactionId'] ?? '');
    $status = 'PENDING';
    $net = null;
    $fees = null;
    $pd = (array)($data['paymentData'] ?? []);
    $invoiceUrl = (string)($pd['qrCode'] ?? $pd['copyPaste'] ?? '');
    $raw = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ins = $conn->prepare("INSERT INTO payment_transactions
        (provider, order_id, user_id, external_ref, provider_transaction_id, status, payment_method, amount_centavos, net_centavos, fees_centavos, invoice_url, raw_response)
        VALUES ('m5', NULL, ?, ?, ?, ?, 'pix', ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          status = VALUES(status),
          net_centavos = VALUES(net_centavos),
          fees_centavos = VALUES(fees_centavos),
          invoice_url = VALUES(invoice_url),
          raw_response = VALUES(raw_response),
          updated_at = CURRENT_TIMESTAMP");
    $ins->bind_param('isssiiiss', $userId, $externalRef, $providerTransactionId, $status, $amountCentavos, $net, $fees, $invoiceUrl, $raw);
    $ins->execute();

    $txId = (int)$conn->insert_id;
    if ($txId <= 0) {
        $stFind = $conn->prepare("SELECT id FROM payment_transactions WHERE provider='m5' AND provider_transaction_id=? LIMIT 1");
        $stFind->bind_param('s', $providerTransactionId);
        $stFind->execute();
        $found = $stFind->get_result()->fetch_assoc();
        $txId = (int)($found['id'] ?? 0);
    }

    return [true, 'PIX gerado com sucesso.', $txId];
}

function walletObterRecargaPorId($conn, int $userId, int $paymentTxId): ?array
{
    if ($paymentTxId <= 0 || $userId <= 0) {
        return null;
    }

    $sql = "SELECT * FROM payment_transactions
            WHERE id = ? AND user_id = ? AND external_ref LIKE 'wallet_topup:%' AND provider IN ('m5','blackcat')
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param('ii', $paymentTxId, $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    return $row ?: null;
}

function walletAplicarCreditoRecargaSeNecessario($conn, int $paymentTxId): array
{
    if ($paymentTxId <= 0) {
        return [false, 'Transação inválida.'];
    }

    $conn->begin_transaction();
    try {
        $st = $conn->prepare("SELECT id, user_id, external_ref, status, amount_centavos
                             FROM payment_transactions
                             WHERE id = ?
                             FOR UPDATE");
        $st->bind_param('i', $paymentTxId);
        $st->execute();
        $tx = $st->get_result()->fetch_assoc();

        if (!$tx) {
            $conn->rollback();
            return [false, 'Transação não encontrada.'];
        }

        if (!str_starts_with((string)($tx['external_ref'] ?? ''), 'wallet_topup:')) {
            $conn->rollback();
            return [false, 'Transação não é de recarga.'];
        }

        if (strtoupper((string)$tx['status']) !== 'PAID') {
            $conn->rollback();
            return [false, 'Transação ainda não está paga.'];
        }

        $stDup = $conn->prepare("SELECT id FROM wallet_transactions
                                WHERE origem='wallet_topup_paid'
                                  AND referencia_tipo='payment_transaction'
                                  AND referencia_id = ?
                                LIMIT 1");
        $stDup->bind_param('i', $paymentTxId);
        $stDup->execute();
        if ($stDup->get_result()->fetch_assoc()) {
            $conn->rollback();
            return [true, 'Recarga já aplicada anteriormente.'];
        }

        $userId = (int)$tx['user_id'];
        $valor = ((int)$tx['amount_centavos']) / 100;

        $up = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo + ? WHERE id = ?');
        $up->bind_param('di', $valor, $userId);
        $up->execute();

        $ins = $conn->prepare("INSERT INTO wallet_transactions
            (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao)
            VALUES (?, 'credito', 'wallet_topup_paid', 'payment_transaction', ?, ?, ?)");
        $desc = 'Recarga via PIX confirmada #' . $paymentTxId;
        $ins->bind_param('iids', $userId, $paymentTxId, $valor, $desc);
        $ins->execute();

        // Notify user about top-up credit
        try {
            require_once __DIR__ . '/notifications.php';
            notificationsCreate($conn, $userId, 'anuncio', 'Recarga confirmada!', 'R$ ' . number_format($valor, 2, ',', '.') . ' foi creditado na sua carteira via PIX.', '/wallet');
        } catch (\Throwable $e) { error_log('[WalletPortal] Notification error (topup): ' . $e->getMessage()); }

        $conn->commit();
        return [true, 'Recarga aplicada com sucesso.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Falha ao aplicar recarga.'];
    }
}

function walletAtualizarStatusRecarga($conn, int $userId, int $paymentTxId): array
{
    $tx = walletObterRecargaPorId($conn, $userId, $paymentTxId);
    if (!$tx) {
        return [false, 'Recarga não encontrada para este usuário.'];
    }

    $statusAtual = strtoupper((string)($tx['status'] ?? ''));
    if ($statusAtual === 'PAID') {
        [$okCredit, $msgCredit] = walletAplicarCreditoRecargaSeNecessario($conn, $paymentTxId);
        if (!$okCredit) {
            return [false, $msgCredit, 'PAID'];
        }
        return [true, 'Pagamento confirmado e saldo creditado.', 'PAID'];
    }

    $providerTransactionId = trim((string)($tx['provider_transaction_id'] ?? ''));
    if ($providerTransactionId === '') {
        return [false, 'Transação sem provider_transaction_id.'];
    }

    $provider = strtolower((string)($tx['provider'] ?? 'm5'));
    if ($provider === 'm5') {
        [$ok, $resp] = m5GetTransaction($providerTransactionId);
    } else {
        [$ok, $resp] = blackcatGetSaleStatus($providerTransactionId);
    }
    if (!$ok) {
        return [false, (string)($resp['message'] ?? 'Falha ao consultar status.')];
    }

    $data = $resp['data'] ?? [];
    $rawStatus = strtolower((string)($data['status'] ?? 'pending'));
    // M5 → confirmed/pending/error/reversed.  BlackCat → PAID/PENDING.
    $statusMap = ['confirmed' => 'PAID', 'paid' => 'PAID', 'pending' => 'PENDING', 'error' => 'FAILED', 'reversed' => 'REFUNDED', 'failed' => 'FAILED'];
    $status = $statusMap[$rawStatus] ?? strtoupper($rawStatus);
    $net = isset($data['netAmount']) ? (int)$data['netAmount'] : null;
    $fees = isset($data['fees']) ? (int)$data['fees'] : null;
    $raw = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($statusAtual === 'PAID' && $status !== 'PAID') {
        $status = 'PAID';
    }

    $up = $conn->prepare("UPDATE payment_transactions
                         SET status = IF(status='PAID','PAID', ?), net_centavos = ?, fees_centavos = ?, paid_at = IF(?='PAID', NOW(), paid_at), raw_response = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?");
    $up->bind_param('siissi', $status, $net, $fees, $status, $raw, $paymentTxId);
    $up->execute();

    if ($status === 'PAID') {
        [$okCredit, $msgCredit] = walletAplicarCreditoRecargaSeNecessario($conn, $paymentTxId);
        if (!$okCredit) {
            return [false, $msgCredit, $status];
        }
        return [true, 'Pagamento confirmado e saldo creditado.', $status];
    }

    return [true, 'Status atualizado: ' . $status, $status];
}

function walletHandleTransactionPaidWebhook($conn, string $transactionId, string $externalReference, array $payload): array
{
    $txId = 0;
    $status = strtoupper((string)($payload['status'] ?? 'PAID'));
    $amount = isset($payload['amount']) ? (int)$payload['amount'] : 0;
    $net = isset($payload['netAmount']) ? (int)$payload['netAmount'] : null;
    $fees = isset($payload['fees']) ? (int)$payload['fees'] : null;
    $paymentMethod = strtolower((string)($payload['paymentMethod'] ?? 'pix'));
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($transactionId !== '') {
        $st = $conn->prepare("SELECT id FROM payment_transactions WHERE provider='blackcat' AND provider_transaction_id = ? LIMIT 1");
        $st->bind_param('s', $transactionId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $txId = (int)($row['id'] ?? 0);
    }

    if ($txId <= 0 && $externalReference !== '') {
        $st = $conn->prepare("SELECT id FROM payment_transactions WHERE provider='blackcat' AND external_ref = ? LIMIT 1");
        $st->bind_param('s', $externalReference);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $txId = (int)($row['id'] ?? 0);
    }

    if ($txId > 0) {
        $up = $conn->prepare("UPDATE payment_transactions
                             SET status = ?, net_centavos = ?, fees_centavos = ?, paid_at = IF(?='PAID', NOW(), paid_at), raw_response = ?, updated_at = CURRENT_TIMESTAMP
                             WHERE id = ?");
        $up->bind_param('siissi', $status, $net, $fees, $status, $raw, $txId);
        $up->execute();
    } elseif (str_starts_with($externalReference, 'wallet_topup:')) {
        $userId = 0;
        if (preg_match('/^wallet_topup:(\d+):/', $externalReference, $m)) {
            $userId = (int)$m[1];
        }

        if ($userId > 0 && $amount > 0) {
            $ins = $conn->prepare("INSERT INTO payment_transactions
                (provider, order_id, user_id, external_ref, provider_transaction_id, status, payment_method, amount_centavos, net_centavos, fees_centavos, invoice_url, raw_response, paid_at)
                VALUES ('blackcat', NULL, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW())
                ON DUPLICATE KEY UPDATE
                  status = VALUES(status),
                  net_centavos = VALUES(net_centavos),
                  fees_centavos = VALUES(fees_centavos),
                  raw_response = VALUES(raw_response),
                  paid_at = NOW(),
                  updated_at = CURRENT_TIMESTAMP");
            $ins->bind_param('issssiiss', $userId, $externalReference, $transactionId, $status, $paymentMethod, $amount, $net, $fees, $raw);
            $ins->execute();

            $st = $conn->prepare("SELECT id FROM payment_transactions WHERE provider='blackcat' AND external_ref = ? LIMIT 1");
            $st->bind_param('s', $externalReference);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $txId = (int)($row['id'] ?? 0);
        }
    }

    if ($txId > 0 && str_starts_with($externalReference, 'wallet_topup:')) {
        return walletAplicarCreditoRecargaSeNecessario($conn, $txId);
    }

    return [true, 'Webhook processado sem crédito de recarga.'];
}

/**
 * Generate a standardized TRX ID for withdrawals.
 */
function walletGerarTrxId(): string
{
    return 'TRX' . time() . strtoupper(bin2hex(random_bytes(4)));
}

function walletSolicitarSaque($conn, int $userId, float $valor, string $pixKey, string $observacao = '', string $tipoChave = ''): array
{
    _walletEnsureTipoChave($conn);
    $pixKey = trim($pixKey);
    $tipoChave = walletNormalizePixType($tipoChave) ?: walletInferPixKeyType($pixKey);
    if ($userId <= 0 || $valor <= 0 || $pixKey === '' || $tipoChave === '') {
        return [false, 'Dados inválidos. Preencha o tipo de chave e a chave PIX.'];
    }

    $conn->begin_transaction();
    try {
        $userCols = walletPortalTableColumns($conn, 'users');
        $walletFrozenSelect = in_array('wallet_frozen', $userCols, true) ? ', wallet_frozen' : '';
        $st = $conn->prepare('SELECT wallet_saldo' . $walletFrozenSelect . ' FROM users WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $saldo = (float)($row['wallet_saldo'] ?? 0);

        if ((string)($row['wallet_frozen'] ?? '0') === '1' || (string)($row['wallet_frozen'] ?? '') === 't') {
            $conn->rollback();
            return [false, 'Wallet bloqueada pelo admin.'];
        }

        if ($saldo < $valor) {
            $conn->rollback();
            return [false, 'Saldo insuficiente.'];
        }

        $trxId = walletGerarTrxId();
        $ins = $conn->prepare("INSERT INTO wallet_withdrawals (user_id, valor, status, chave_pix, tipo_chave, observacao, transaction_id)
                              VALUES (?, ?, 'pendente', ?, ?, ?, ?)");
        $ins->bind_param('idssss', $userId, $valor, $pixKey, $tipoChave, $observacao, $trxId);
        $ins->execute();

        $conn->commit();
        return [true, 'Saque solicitado com sucesso. O débito ocorrerá após confirmação do admin.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Falha ao solicitar saque.'];
    }
}

function walletListarSolicitacoesSaque($conn, string $status = 'pendente', int $limit = 100): array
{
    _walletEnsureTipoChave($conn);
    $limit = max(1, min(500, $limit));
    $allowed = ['pendente', 'processando', 'pago', 'recusado', 'insuficiente'];
    if (!in_array($status, $allowed, true)) {
        $status = 'pendente';
    }

    $sql = "SELECT w.id, w.user_id, w.valor, w.status, w.chave_pix, w.tipo_chave, w.observacao, w.transaction_id, w.criado_em,
                   u.nome AS user_nome, u.email AS user_email
            FROM wallet_withdrawals w
            INNER JOIN users u ON u.id = w.user_id
            WHERE w.status = ?
            ORDER BY w.id DESC
            LIMIT $limit";
    $st = $conn->prepare($sql);
    $st->bind_param('s', $status);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function walletAprovarSaqueAdmin($conn, int $withdrawalId, int $adminUserId): array
{
    if ($withdrawalId <= 0 || $adminUserId <= 0) {
        return [false, 'Parâmetros inválidos.'];
    }

    $conn->begin_transaction();
    try {
        $st = $conn->prepare("SELECT id, user_id, valor, status, chave_pix, observacao
                             FROM wallet_withdrawals
                             WHERE id = ?
                             FOR UPDATE");
        $st->bind_param('i', $withdrawalId);
        $st->execute();
        $wd = $st->get_result()->fetch_assoc();

        if (!$wd) {
            $conn->rollback();
            return [false, 'Solicitação não encontrada.'];
        }

        if ((string)$wd['status'] !== 'pendente') {
            $conn->rollback();
            return [false, 'Solicitação já processada.'];
        }

        $userId = (int)$wd['user_id'];
        $valor = (float)$wd['valor'];
        $pixKey = (string)$wd['chave_pix'];
        $obs = (string)($wd['observacao'] ?? '');

        $stSaldo = $conn->prepare('SELECT wallet_saldo FROM users WHERE id = ? FOR UPDATE');
        $stSaldo->bind_param('i', $userId);
        $stSaldo->execute();
        $saldoRow = $stSaldo->get_result()->fetch_assoc();
        $saldoAtual = (float)($saldoRow['wallet_saldo'] ?? 0);

        if ($saldoAtual < $valor) {
            $upInsuf = $conn->prepare("UPDATE wallet_withdrawals SET status='insuficiente', observacao = CONCAT(IFNULL(observacao,''), ' | recusado: saldo insuficiente na aprovação') WHERE id = ?");
            $upInsuf->bind_param('i', $withdrawalId);
            $upInsuf->execute();
            $conn->commit();
            return [false, 'Saldo insuficiente no momento da aprovação.'];
        }

        $statusFinal = 'pago';
        $obsFinal = trim($obs . ' | aprovado_manual_por=' . $adminUserId);

        $upSaldo = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo - ? WHERE id = ?');
        $upSaldo->bind_param('di', $valor, $userId);
        $upSaldo->execute();

        $upWd = $conn->prepare('UPDATE wallet_withdrawals SET status = ?, observacao = ? WHERE id = ?');
        $upWd->bind_param('ssi', $statusFinal, $obsFinal, $withdrawalId);
        $upWd->execute();

        $tx = $conn->prepare("INSERT INTO wallet_transactions
            (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao)
            VALUES (?, 'debito', 'withdraw_approved', 'wallet_withdrawal', ?, ?, ?)");
        $desc = 'Saque aprovado #' . $withdrawalId . ' por admin #' . $adminUserId;
        $tx->bind_param('iids', $userId, $withdrawalId, $valor, $desc);
        $tx->execute();

        $conn->commit();

        // Notify user about withdrawal approval
        try {
            require_once __DIR__ . '/notifications.php';
            notificationsCreate($conn, $userId, 'venda', 'Saque aprovado!', 'Seu saque de R$ ' . number_format($valor, 2, ',', '.') . ' foi aprovado e processado.', '/wallet');
        } catch (\Throwable $e) { error_log('[WalletPortal] Notification error (withdraw): ' . $e->getMessage()); }

        return [true, 'Saque aprovado manualmente e debitado.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Falha ao aprovar saque.'];
    }
}

/**
 * Paginated withdrawal listing for admin.
 * Returns ['itens' => [...], 'total' => int, 'pagina' => int, 'total_paginas' => int]
 */
function walletListarSaquesPaginado($conn, array $statuses, int $pagina = 1, int $pp = 10): array
{
    _walletEnsureTipoChave($conn);
    $allowed = ['pendente', 'processando', 'pago', 'recusado', 'insuficiente'];
    $valid = [];
    foreach ($statuses as $status) {
        $status = strtolower(trim((string)$status));
        if (in_array($status, $allowed, true)) $valid[] = $status;
    }
    $valid = array_values(array_unique($valid));
    if (!$valid) $valid = ['pendente', 'processando', 'pago'];

    $placeholders = implode(',', array_fill(0, count($valid), '?'));
    $types = str_repeat('s', count($valid));

    // count
    $cSt = $conn->prepare("SELECT COUNT(*) AS qtd FROM wallet_withdrawals WHERE status IN ($placeholders)");
    $cSt->bind_param($types, ...$valid);
    $cSt->execute();
    $total = (int)($cSt->get_result()->fetch_assoc()['qtd'] ?? 0);
    $cSt->close();

    $pp = max(1, min(100, $pp));
    $pagina = max(1, $pagina);
    $totalPaginas = max(1, (int)ceil($total / $pp));
    if ($pagina > $totalPaginas) $pagina = $totalPaginas;
    $offset = ($pagina - 1) * $pp;

    $sql = "SELECT w.id, w.user_id, w.valor, w.status, w.chave_pix, w.tipo_chave, w.observacao, w.transaction_id, w.criado_em,
                   u.nome AS user_nome, u.email AS user_email
            FROM wallet_withdrawals w
            INNER JOIN users u ON u.id = w.user_id
            WHERE w.status IN ($placeholders)
            ORDER BY w.id DESC
            LIMIT $pp OFFSET $offset";
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$valid);
    $st->execute();
    $itens = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'total_paginas' => $totalPaginas];
}

function walletListarSaquesPorStatus($conn, array $statuses, int $limit = 100): array
{
    _walletEnsureTipoChave($conn);
    $limit = max(1, min(500, $limit));
    $allowed = ['pendente', 'processando', 'pago', 'recusado', 'insuficiente'];
    $valid = [];
    foreach ($statuses as $status) {
        $status = strtolower(trim((string)$status));
        if (in_array($status, $allowed, true)) {
            $valid[] = $status;
        }
    }
    $valid = array_values(array_unique($valid));
    if (!$valid) {
        $valid = ['pendente'];
    }

    $placeholders = implode(',', array_fill(0, count($valid), '?'));
    $types = str_repeat('s', count($valid));

    $sql = "SELECT w.id, w.user_id, w.valor, w.status, w.chave_pix, w.tipo_chave, w.observacao, w.transaction_id, w.criado_em,
                   u.nome AS user_nome, u.email AS user_email
            FROM wallet_withdrawals w
            INNER JOIN users u ON u.id = w.user_id
            WHERE w.status IN ($placeholders)
            ORDER BY w.id DESC
            LIMIT $limit";
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$valid);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function walletSaqueImediatoAdmin($conn, int $adminUserId, float $valor, string $pixKey, string $observacao = '', string $tipoChave = ''): array
{
    _walletEnsureTipoChave($conn);
    $pixKey = trim($pixKey);
    $tipoChave = walletNormalizePixType($tipoChave) ?: walletInferPixKeyType($pixKey);
    if ($adminUserId <= 0 || $valor <= 0 || $pixKey === '' || $tipoChave === '') {
        return [false, 'Dados inválidos. Preencha o tipo de chave e a chave PIX.'];
    }

    $conn->begin_transaction();
    try {
        $st = $conn->prepare('SELECT wallet_saldo FROM users WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $adminUserId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $saldo = (float)($row['wallet_saldo'] ?? 0);

        if ($saldo < $valor) {
            $conn->rollback();
            return [false, 'Saldo insuficiente para saque imediato.'];
        }

        $payload = [
            'amount' => round($valor, 2),
            'pixKey' => $pixKey,
            'description' => $observacao !== '' ? $observacao : 'Saque admin via painel',
        ];

        // ── M5 Open PIX ── (substitui BlackCat)
        $amountCentavos = (int)round($valor * 100);
        $description    = $observacao !== '' ? mb_substr($observacao, 0, 100) : 'Saque admin via painel';

        // Webhook absoluto
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') $scheme = 'https';
        $host = (string)($_SERVER['HTTP_HOST'] ?? parse_url(APP_URL, PHP_URL_HOST) ?? 'localhost');
        $webhookUrl = $scheme . '://' . $host . BASE_PATH . '/webhooks/m5';

        // Normalizar tipo da chave: nosso painel usa CPF/CNPJ/Email/Telefone/Aleatoria → M5 espera cpf/cnpj/email/phone/evp (ou omitir)
        $tcLower = strtolower(trim($tipoChave));
        $m5KeyType = match($tcLower) {
            'cpf' => 'cpf',
            'cnpj' => 'cnpj',
            'email' => 'email',
            'telefone', 'phone' => 'phone',
            'aleatoria', 'aleatória', 'evp', 'chave aleatoria', 'chave aleatória' => 'evp',
            default => '',
        };
        // Phone na M5 precisa apenas dos dígitos (E.164 é normalizado automaticamente)
        $keyForM5 = $m5KeyType === 'phone' ? preg_replace('/\D+/', '', $pixKey) : $pixKey;

        [$okApi, $confirmResp, $initiateResp] = m5TransferSend(
            (string)$keyForM5,
            $amountCentavos,
            $description,
            $webhookUrl,
            $m5KeyType
        );

        if (!$okApi) {
            $conn->rollback();
            $msg = (string)($confirmResp['message'] ?? 'Falha no saque via API');
            return [false, 'M5: ' . $msg];
        }

        // M5 retorna { data: { pix_id, status: 'processing', ... } } no confirm
        $apiData   = $confirmResp['data'] ?? [];
        $apiId     = (string)($apiData['pix_id'] ?? $apiData['id'] ?? ($initiateResp['data']['id'] ?? ''));
        $apiStatus = strtolower((string)($apiData['status'] ?? 'processing'));
        // Mapeia status M5 → status interno (apenas 'pago' será setado depois pelo webhook)
        $internalStatus = match($apiStatus) {
            'confirmed' => 'pago',
            'error', 'failed', 'rejected', 'refused', 'denied', 'cancelled' => 'recusado',
            default => 'processando',
        };
        $obsFinal = trim($observacao . ' | m5_pix_id=' . $apiId);

        $up = $conn->prepare('UPDATE users SET wallet_saldo = wallet_saldo - ? WHERE id = ?');
        $up->bind_param('di', $valor, $adminUserId);
        $up->execute();

        $trxId = walletGerarTrxId();
        $ins = $conn->prepare('INSERT INTO wallet_withdrawals (user_id, valor, status, chave_pix, tipo_chave, observacao, transaction_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->bind_param('idsssss', $adminUserId, $valor, $internalStatus, $pixKey, $tipoChave, $obsFinal, $trxId);
        $ins->execute();
        $withdrawalId = (int)$conn->insert_id;

        $tx = $conn->prepare("INSERT INTO wallet_transactions
            (user_id, tipo, origem, referencia_tipo, referencia_id, valor, descricao)
            VALUES (?, 'debito', 'withdrawal_api', 'wallet_withdrawal', ?, ?, ?)");
        $desc = 'Saque imediato admin #' . $withdrawalId;
        $tx->bind_param('iids', $adminUserId, $withdrawalId, $valor, $desc);
        $tx->execute();

        $conn->commit();
        return [true, 'Saque enviado para API com sucesso.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, 'Falha ao executar saque imediato.'];
    }
}

function walletHistoricoTransacoes($conn, int $userId, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $sql = "SELECT id, tipo, origem, referencia_tipo, referencia_id, valor, descricao, criado_em
            FROM wallet_transactions
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT $limit";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $userId);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Paginated transaction history.
 * @return array{items: array, total: int}
 */
function walletHistoricoTransacoesPaginado($conn, int $userId, int $pagina = 1, int $pp = 10): array
{
    $pp = max(1, min(50, $pp));
    $offset = ($pagina - 1) * $pp;

    $stC = $conn->prepare("SELECT COUNT(*) AS cnt FROM wallet_transactions WHERE user_id = ?");
    $stC->bind_param('i', $userId);
    $stC->execute();
    $total = (int)($stC->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stC->close();

    $st = $conn->prepare("SELECT id, tipo, origem, referencia_tipo, referencia_id, valor, descricao, criado_em
            FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT {$pp} OFFSET {$offset}");
    $st->bind_param('i', $userId);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return ['items' => $items, 'total' => $total];
}

function walletHistoricoSaques($conn, int $userId, int $limit = 50): array
{
    _walletEnsureTipoChave($conn);
    $limit = max(1, min(200, $limit));
    $sql = "SELECT id, valor, status, chave_pix, tipo_chave, observacao, transaction_id, criado_em
            FROM wallet_withdrawals
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT $limit";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $userId);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Paginated withdrawal history.
 * @return array{items: array, total: int}
 */
function walletHistoricoSaquesPaginado($conn, int $userId, int $pagina = 1, int $pp = 10): array
{
    _walletEnsureTipoChave($conn);
    $pp = max(1, min(50, $pp));
    $offset = ($pagina - 1) * $pp;

    $stC = $conn->prepare("SELECT COUNT(*) AS cnt FROM wallet_withdrawals WHERE user_id = ?");
    $stC->bind_param('i', $userId);
    $stC->execute();
    $total = (int)($stC->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stC->close();

    $st = $conn->prepare("SELECT id, valor, status, chave_pix, tipo_chave, observacao, transaction_id, criado_em
            FROM wallet_withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT {$pp} OFFSET {$offset}");
    $st->bind_param('i', $userId);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return ['items' => $items, 'total' => $total];
}

/**
 * Admin adds a new observation to a withdrawal (appended with timestamp).
 */
function walletAdminAdicionarObservacao($conn, int $withdrawalId, int $adminId, string $novaObs): array
{
    if ($withdrawalId <= 0 || trim($novaObs) === '') {
        return [false, 'Dados inválidos.'];
    }

    $st = $conn->prepare("SELECT id, observacao FROM wallet_withdrawals WHERE id = ? LIMIT 1");
    $st->bind_param('i', $withdrawalId);
    $st->execute();
    $wd = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$wd) {
        return [false, 'Saque não encontrado.'];
    }

    $existing = trim((string)($wd['observacao'] ?? ''));
    $timestamp = date('d/m/Y H:i');
    $append = "[Admin #{$adminId} - {$timestamp}] " . trim($novaObs);
    $final = $existing !== '' ? ($existing . "\n" . $append) : $append;

    $up = $conn->prepare("UPDATE wallet_withdrawals SET observacao = ? WHERE id = ?");
    $up->bind_param('si', $final, $withdrawalId);
    $ok = $up->execute();
    $up->close();

    return $ok ? [true, 'Observação adicionada.'] : [false, 'Falha ao salvar.'];
}
