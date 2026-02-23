<?php
/**
 * Handler: Pedidos (documentos solicitados ao fornecedor)
 * Actions: save_pedido, delete_pedido, upload_pedido_resposta, download_pedido_resposta
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    case 'save_pedido':
        $especId = (int)($jsonBody['especificacao_id'] ?? 0);
        $pedidoId = (int)($jsonBody['id'] ?? 0);
        $titulo = trim($jsonBody['titulo'] ?? '');
        $descricao = trim($jsonBody['descricao'] ?? '');
        $obrigatorio = (int)($jsonBody['obrigatorio'] ?? 1);

        if (!$especId) jsonError('ID da especificação é obrigatório.');
        if (!$titulo) jsonError('Título do pedido é obrigatório.');
        checkSaOrgAccess($db, $user, $especId);

        if ($pedidoId > 0) {
            $db->prepare('UPDATE especificacao_pedidos SET titulo = ?, descricao = ?, obrigatorio = ? WHERE id = ? AND especificacao_id = ?')
               ->execute([$titulo, $descricao, $obrigatorio, $pedidoId, $especId]);
        } else {
            $stmtOrd = $db->prepare('SELECT COALESCE(MAX(ordem), -1) + 1 FROM especificacao_pedidos WHERE especificacao_id = ?');
            $stmtOrd->execute([$especId]);
            $ordem = (int)$stmtOrd->fetchColumn();
            $db->prepare('INSERT INTO especificacao_pedidos (especificacao_id, titulo, descricao, obrigatorio, ordem) VALUES (?, ?, ?, ?, ?)')
               ->execute([$especId, $titulo, $descricao, $obrigatorio, $ordem]);
            $pedidoId = (int)$db->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $pedidoId, 'message' => 'Pedido guardado.']);
        exit;

    case 'delete_pedido':
        $pedidoId = (int)($jsonBody['id'] ?? 0);
        if (!$pedidoId) jsonError('ID do pedido é obrigatório.');
        // Verificar acesso
        $stmt = $db->prepare('SELECT especificacao_id FROM especificacao_pedidos WHERE id = ?');
        $stmt->execute([$pedidoId]);
        $ped = $stmt->fetch();
        if (!$ped) jsonError('Pedido não encontrado.');
        checkSaOrgAccess($db, $user, (int)$ped['especificacao_id']);
        // Apagar ficheiros de respostas
        $stmtFiles = $db->prepare('SELECT path_ficheiro FROM especificacao_pedido_respostas WHERE pedido_id = ?');
        $stmtFiles->execute([$pedidoId]);
        while ($f = $stmtFiles->fetch()) {
            $fullPath = __DIR__ . '/../' . $f['path_ficheiro'];
            if (file_exists($fullPath)) @unlink($fullPath);
        }
        $db->prepare('DELETE FROM especificacao_pedidos WHERE id = ?')->execute([$pedidoId]);
        jsonSuccess('Pedido removido.');
        break;

    case 'upload_pedido_resposta':
        // Este action é chamado por fornecedor via publico.php (sem auth normal)
        // A validação de token é feita em publico.php antes de chamar a API
        $pedidoId = (int)($_POST['pedido_id'] ?? 0);
        $tokenId = (int)($_POST['token_id'] ?? 0);
        if (!$pedidoId || !$tokenId) jsonError('Dados incompletos.');

        if (empty($_FILES['ficheiro']) || $_FILES['ficheiro']['error'] !== UPLOAD_ERR_OK) {
            jsonError('Ficheiro não recebido.');
        }

        $file = $_FILES['ficheiro'];
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) jsonError('Ficheiro demasiado grande (máx. 10MB).');

        // Validar extensão e MIME
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowedExts)) jsonError('Tipo de ficheiro não permitido.');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $allowedMimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg', 'image/png'];
        if (!in_array($mimeType, $allowedMimes)) jsonError('Tipo MIME não permitido.');

        // Verificar que o pedido existe e obter org_id
        $stmt = $db->prepare('SELECT p.id, e.organizacao_id FROM especificacao_pedidos p INNER JOIN especificacoes e ON e.id = p.especificacao_id WHERE p.id = ?');
        $stmt->execute([$pedidoId]);
        $pedInfo = $stmt->fetch();
        if (!$pedInfo) jsonError('Pedido não encontrado.');

        $orgId = (int)$pedInfo['organizacao_id'];
        $uploadDir = __DIR__ . '/../uploads/pedidos/' . $orgId . '/' . $pedidoId . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $uuid = bin2hex(random_bytes(8));
        $filename = $tokenId . '_' . $uuid . '.' . $ext;
        $filepath = $uploadDir . $filename;
        $relativePath = 'uploads/pedidos/' . $orgId . '/' . $pedidoId . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            jsonError('Erro ao guardar ficheiro.');
        }

        // Remover resposta anterior (se existir) para mesmo pedido+token
        $stmtOld = $db->prepare('SELECT path_ficheiro FROM especificacao_pedido_respostas WHERE pedido_id = ? AND token_id = ?');
        $stmtOld->execute([$pedidoId, $tokenId]);
        $old = $stmtOld->fetch();
        if ($old) {
            $oldPath = __DIR__ . '/../' . $old['path_ficheiro'];
            if (file_exists($oldPath)) @unlink($oldPath);
            $db->prepare('DELETE FROM especificacao_pedido_respostas WHERE pedido_id = ? AND token_id = ?')->execute([$pedidoId, $tokenId]);
        }

        $db->prepare('INSERT INTO especificacao_pedido_respostas (pedido_id, token_id, nome_ficheiro, path_ficheiro, mime_type, tamanho) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$pedidoId, $tokenId, basename($file['name']), $relativePath, $mimeType, $file['size']]);

        jsonSuccess('Ficheiro enviado.');
        break;

    case 'download_pedido_resposta':
        $respostaId = (int)($_GET['id'] ?? $jsonBody['id'] ?? 0);
        if (!$respostaId) jsonError('ID da resposta é obrigatório.');

        $stmt = $db->prepare('SELECT r.*, p.especificacao_id FROM especificacao_pedido_respostas r INNER JOIN especificacao_pedidos p ON p.id = r.pedido_id WHERE r.id = ?');
        $stmt->execute([$respostaId]);
        $resp = $stmt->fetch();
        if (!$resp) jsonError('Ficheiro não encontrado.');

        checkSaOrgAccess($db, $user, (int)$resp['especificacao_id']);

        $fullPath = __DIR__ . '/../' . $resp['path_ficheiro'];
        if (!file_exists($fullPath)) jsonError('Ficheiro não encontrado no servidor.');

        header('Content-Type: ' . $resp['mime_type']);
        header('Content-Disposition: inline; filename="' . basename($resp['nome_ficheiro']) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
}
