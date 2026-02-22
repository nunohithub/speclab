<?php
/**
 * Handler: Email
 * Actions: enviar_email, enviar_link_aceitacao, partilhar_especificacao
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // ENVIAR EMAIL
    // ===================================================================
    case 'enviar_email':
        require_once __DIR__ . '/../includes/email.php';

        $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
        $destinatario = sanitize($_POST['destinatario'] ?? '');
        $assunto = sanitize($_POST['assunto'] ?? '');
        $mensagem = $_POST['mensagem'] ?? '';
        $anexarPdf = !empty($_POST['anexar_pdf']);
        $incluirLink = !empty($_POST['incluir_link']);

        if ($especificacao_id <= 0) jsonError('ID da especificacao invalido.');
        if (empty($destinatario) || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) jsonError('Email de destino invalido.');

        verifySpecAccess($db, $especificacao_id, $user);

        $espec = getEspecificacaoCompleta($db, $especificacao_id);
        if (!$espec) jsonError('Especificacao nao encontrada.', 404);

        // Gerar link publico se solicitado
        $linkPublico = '';
        if ($incluirLink && !empty($espec['codigo_acesso'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $linkPublico = $protocol . '://' . $host . BASE_PATH . '/publico.php?code=' . $espec['codigo_acesso'];
        }

        // Corpo do email
        $corpo = !empty($mensagem) ? '<p>' . nl2br(htmlspecialchars($mensagem)) . '</p><hr>' : '';
        $corpo .= gerarCorpoEmail($espec, $linkPublico);

        if (empty($assunto)) {
            $assunto = 'Caderno de Encargos: ' . $espec['numero'] . ' - ' . $espec['titulo'];
        }

        $result = enviarEmail($db, $especificacao_id, $destinatario, $assunto, $corpo, $anexarPdf, $user['id']);

        if ($result['success']) {
            jsonSuccess($result['message']);
        } else {
            jsonError($result['error']);
        }
        break;

    // ===================================================================
    // ENVIAR LINK DE ACEITACAO
    // ===================================================================
    case 'enviar_link_aceitacao':
        require_once __DIR__ . '/../includes/email.php';
        $tokenId = (int)($jsonBody['token_id'] ?? $_POST['token_id'] ?? 0);
        $especId = (int)($jsonBody['especificacao_id'] ?? $_POST['especificacao_id'] ?? 0);
        if (!$tokenId || !$especId) jsonError('Dados incompletos.');
        checkSaOrgAccess($db, $user, $especId);
        $baseUrl = rtrim(($jsonBody['base_url'] ?? $_POST['base_url'] ?? ''), '/');
        if (!$baseUrl) $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_PATH;
        $result = enviarLinkAceitacao($db, $especId, $tokenId, $baseUrl, $user['id']);
        if ($result['success']) {
            jsonSuccess('Email enviado.');
        } else {
            jsonError($result['error'] ?? 'Erro ao enviar.');
        }
        break;
}
