<?php
/**
 * Handler: Comentarios
 * Actions: add_comentario, list_comentarios, delete_comentario
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // ADICIONAR COMENTARIO
    // ===================================================================
    case 'add_comentario':
        $especId = (int)($jsonBody['especificacao_id'] ?? 0);
        $texto = trim($jsonBody['comentario'] ?? '');
        if ($especId <= 0) jsonError('ID invalido.');
        if (!$texto) jsonError('Comentario vazio.');
        checkSaOrgAccess($db, $user, $especId);
        $db->prepare('INSERT INTO especificacao_comentarios (especificacao_id, utilizador_id, comentario) VALUES (?, ?, ?)')
           ->execute([$especId, $user['id'], sanitize($texto)]);
        $newId = (int)$db->lastInsertId();
        jsonSuccess('Comentario adicionado.', ['id' => $newId, 'nome' => $user['nome'], 'comentario' => sanitize($texto), 'created_at' => date('Y-m-d H:i:s')]);
        break;

    // ===================================================================
    // LISTAR COMENTARIOS
    // ===================================================================
    case 'list_comentarios':
        $especId = (int)($jsonBody['especificacao_id'] ?? $_GET['especificacao_id'] ?? 0);
        if ($especId <= 0) jsonError('ID invalido.');
        checkSaOrgAccess($db, $user, $especId);
        $stmt = $db->prepare('SELECT c.id, c.comentario, c.created_at, c.utilizador_id, u.nome as nome_utilizador FROM especificacao_comentarios c LEFT JOIN utilizadores u ON u.id = c.utilizador_id WHERE c.especificacao_id = ? ORDER BY c.created_at DESC LIMIT 100');
        $stmt->execute([$especId]);
        $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $isAdmin = in_array($user['role'], ['super_admin', 'org_admin']);
        foreach ($comentarios as &$c) {
            $c['pode_apagar'] = ($c['utilizador_id'] == $user['id'] || $isAdmin);
            unset($c['utilizador_id']);
        }
        echo json_encode(['success' => true, 'comentarios' => $comentarios], JSON_UNESCAPED_UNICODE);
        exit;

    // ===================================================================
    // APAGAR COMENTARIO
    // ===================================================================
    case 'delete_comentario':
        $comId = (int)($jsonBody['comentario_id'] ?? 0);
        if ($comId <= 0) jsonError('ID invalido.');
        // So o autor ou admin pode apagar
        $stmt = $db->prepare('SELECT utilizador_id FROM especificacao_comentarios WHERE id = ?');
        $stmt->execute([$comId]);
        $com = $stmt->fetch();
        if (!$com) jsonError('Comentario nao encontrado.');
        if ($com['utilizador_id'] != $user['id'] && !in_array($user['role'], ['super_admin', 'org_admin'])) jsonError('Sem permissao.');
        $db->prepare('DELETE FROM especificacao_comentarios WHERE id = ?')->execute([$comId]);
        jsonSuccess('Comentario eliminado.');
        break;
}
