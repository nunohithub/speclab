<?php
/**
 * Handler: Tokens
 * Actions: gerar_token, revogar_token
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // GERAR TOKEN
    // ===================================================================
    case 'gerar_token':
        $especId = (int)($jsonBody['especificacao_id'] ?? $_POST['especificacao_id'] ?? 0);
        if ($especId > 0) verifySpecAccess($db, $especId, $user);
        $nome = sanitize($jsonBody['nome'] ?? $_POST['nome'] ?? '');
        $email = sanitize($jsonBody['email'] ?? $_POST['email'] ?? '');
        $tipo = sanitize($jsonBody['tipo'] ?? $_POST['tipo'] ?? 'outro');
        if (!$especId || !$nome || !$email) jsonError('Dados incompletos.');
        $token = gerarTokenDestinatario($db, $especId, $user['id'], $nome, $email, $tipo);
        echo json_encode(['success' => true, 'token' => $token]);
        exit;

    // ===================================================================
    // REVOGAR TOKEN
    // ===================================================================
    case 'revogar_token':
        $tokenId = (int)($jsonBody['token_id'] ?? $_POST['token_id'] ?? 0);
        if (!$tokenId) jsonError('Token invalido.');
        // Verificar acesso multi-tenant
        $stmtTk = $db->prepare('SELECT especificacao_id FROM especificacao_tokens WHERE id = ?');
        $stmtTk->execute([$tokenId]);
        $tkRow = $stmtTk->fetch();
        if (!$tkRow) jsonError('Token nao encontrado.', 404);
        verifySpecAccess($db, (int)$tkRow['especificacao_id'], $user);
        $db->prepare('UPDATE especificacao_tokens SET ativo = 0 WHERE id = ?')->execute([$tokenId]);
        jsonSuccess('Token revogado.');
        break;

    // ===================================================================
    // MARCAR HISTÓRICO COMO VISTO
    // ===================================================================
    case 'marcar_historico_visto':
        $especId = (int)($jsonBody['especificacao_id'] ?? 0);
        if (!$especId) jsonError('ID em falta.');
        verifySpecAccess($db, $especId, $user);
        $db->prepare('INSERT INTO historico_visitas (utilizador_id, especificacao_id, ultima_visita) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE ultima_visita = NOW()')
            ->execute([$user['id'], $especId]);
        jsonSuccess('ok');
        break;
}
