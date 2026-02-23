<?php
/**
 * Handler: Aprovacao (fluxo de aprovacao)
 * Actions: submeter_revisao, aprovar_especificacao, devolver_especificacao
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // SUBMETER PARA REVISAO
    // ===================================================================
    case 'submeter_revisao':
        $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) jsonError('ID invalido.');
        checkSaOrgAccess($db, $user, $id);
        $stmt = $db->prepare('SELECT estado, versao_bloqueada FROM especificacoes WHERE id = ?');
        $stmt->execute([$id]);
        $esp = $stmt->fetch();
        if (!$esp) jsonError('Especificacao nao encontrada.');
        if ($esp['versao_bloqueada']) jsonError('Versao ja bloqueada.');
        if ($esp['estado'] !== 'rascunho') jsonError('So especificacoes em rascunho podem ser submetidas.');
        $errosVal = validateForPublish($db, $id);
        if (!empty($errosVal)) {
            echo json_encode(['success' => false, 'error' => 'Documento incompleto:', 'validation_errors' => $errosVal]);
            exit;
        }
        $db->prepare('UPDATE especificacoes SET estado = ? WHERE id = ?')->execute(['em_revisao', $id]);

        // Notificar admins selecionados por email
        $adminIds = $jsonBody['admin_ids'] ?? [];
        if (!empty($adminIds) && is_array($adminIds)) {
            require_once __DIR__ . '/../includes/email.php';
            $baseUrl = rtrim($jsonBody['base_url'] ?? '', '/');
            if (!$baseUrl) $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_PATH;
            $emailResult = enviarNotificacaoRevisao($db, $id, array_map('intval', $adminIds), $baseUrl, $user['id']);
            jsonSuccess($emailResult['message'] ?? 'Submetida para revisão.');
        } else {
            jsonSuccess('Submetida para revisão.');
        }
        break;

    // ===================================================================
    // APROVAR ESPECIFICACAO
    // ===================================================================
    case 'aprovar_especificacao':
        $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) jsonError('ID invalido.');
        requireAdminApi($user);
        checkSaOrgAccess($db, $user, $id);
        $stmt = $db->prepare('SELECT estado, versao_bloqueada FROM especificacoes WHERE id = ?');
        $stmt->execute([$id]);
        $esp = $stmt->fetch();
        if (!$esp) jsonError('Especificacao nao encontrada.');
        if ($esp['estado'] !== 'em_revisao') jsonError('So especificacoes em revisao podem ser aprovadas.');
        $errosVal = validateForPublish($db, $id);
        if (!empty($errosVal)) {
            echo json_encode(['success' => false, 'error' => 'Documento incompleto:', 'validation_errors' => $errosVal]);
            exit;
        }
        $db->prepare('UPDATE especificacoes SET estado = ?, aprovado_por = ?, aprovado_em = NOW(), motivo_devolucao = NULL WHERE id = ?')
           ->execute(['ativo', $user['id'], $id]);
        jsonSuccess('Especificacao aprovada.');
        break;

    // ===================================================================
    // DEVOLVER ESPECIFICACAO
    // ===================================================================
    case 'devolver_especificacao':
        $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) jsonError('ID invalido.');
        requireAdminApi($user);
        checkSaOrgAccess($db, $user, $id);
        $motivo = sanitize($jsonBody['motivo'] ?? $_POST['motivo'] ?? '');
        if (!$motivo) jsonError('Indique o motivo da devolucao.');
        $stmt = $db->prepare('SELECT estado FROM especificacoes WHERE id = ?');
        $stmt->execute([$id]);
        $esp = $stmt->fetch();
        if (!$esp || $esp['estado'] !== 'em_revisao') jsonError('So especificacoes em revisao podem ser devolvidas.');
        $db->prepare('UPDATE especificacoes SET estado = ?, motivo_devolucao = ?, aprovado_por = NULL, aprovado_em = NULL WHERE id = ?')
           ->execute(['rascunho', $motivo, $id]);
        jsonSuccess('Especificacao devolvida ao autor.');
        break;
}
