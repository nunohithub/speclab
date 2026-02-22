<?php
/**
 * Handler: Admin
 * Actions: save_cliente, save_produto, delete_cliente, delete_produto,
 *          save_organizacao, upload_org_logo, get_fornecedor_log,
 *          save_fornecedor, delete_fornecedor, save_config, save_user, delete_user
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // SAVE CLIENTE
    // ===================================================================
    case 'save_cliente':
        requireAdminApi($user);
        $id       = (int)($_POST['id'] ?? 0);
        $nome     = sanitize($_POST['nome'] ?? '');
        $sigla    = sanitize($_POST['sigla'] ?? '');
        $morada   = sanitize($_POST['morada'] ?? '');
        $telefone = sanitize($_POST['telefone'] ?? '');
        $email    = sanitize($_POST['email'] ?? '');
        $nif      = sanitize($_POST['nif'] ?? '');
        $contacto = sanitize($_POST['contacto'] ?? '');

        if ($nome === '') {
            jsonError('O nome do cliente e obrigatorio.');
        }

        if ($id === 0) {
            // Criar novo cliente
            $stmt = $db->prepare('
                INSERT INTO clientes (nome, sigla, morada, telefone, email, nif, contacto, organizacao_id, ativo, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ');
            $stmt->execute([$nome, $sigla, $morada, $telefone, $email, $nif, $contacto, $user['org_id']]);
            $newId = (int)$db->lastInsertId();

            jsonSuccess('Cliente criado com sucesso.', ['id' => $newId]);
        } else {
            // Atualizar cliente existente
            verifyClienteAccess($db, $id, $user);

            $stmt = $db->prepare('
                UPDATE clientes SET
                    nome = ?, sigla = ?, morada = ?, telefone = ?,
                    email = ?, nif = ?, contacto = ?
                WHERE id = ?
            ');
            $stmt->execute([$nome, $sigla, $morada, $telefone, $email, $nif, $contacto, $id]);

            jsonSuccess('Cliente atualizado com sucesso.', ['id' => $id]);
        }
        break;

    // ===================================================================
    // SAVE PRODUTO
    // ===================================================================
    case 'save_produto':
        requireAdminApi($user);
        $id        = (int)($_POST['id'] ?? 0);
        $nome      = sanitize($_POST['nome'] ?? '');
        $descricao = sanitize($_POST['descricao'] ?? '');

        if ($nome === '') {
            jsonError('O nome do produto e obrigatorio.');
        }

        // Determinar organizacao_id: super_admin pode definir NULL (global), org_admin usa a sua org
        $produtoOrgId = $user['org_id'];
        if (isSuperAdmin() && isset($_POST['global']) && $_POST['global']) {
            $produtoOrgId = null;
        }

        if ($id === 0) {
            // Criar novo produto
            $stmt = $db->prepare('
                INSERT INTO produtos (nome, descricao, organizacao_id, ativo, created_at)
                VALUES (?, ?, ?, 1, NOW())
            ');
            $stmt->execute([$nome, $descricao, $produtoOrgId]);
            $newId = (int)$db->lastInsertId();

            jsonSuccess('Produto criado com sucesso.', ['id' => $newId]);
        } else {
            // Atualizar produto existente
            verifyProdutoAccess($db, $id, $user);

            $updateFields = 'nome = ?, descricao = ?';
            $updateParams = [$nome, $descricao];

            // Super admin pode alterar se e global
            if (isSuperAdmin()) {
                $updateFields .= ', organizacao_id = ?';
                $updateParams[] = $produtoOrgId;
            }

            $updateParams[] = $id;
            $stmt = $db->prepare("UPDATE produtos SET {$updateFields} WHERE id = ?");
            $stmt->execute($updateParams);

            jsonSuccess('Produto atualizado com sucesso.', ['id' => $id]);
        }
        break;

    // ===================================================================
    // DELETE CLIENTE (soft delete)
    // ===================================================================
    case 'delete_cliente':
        requireAdminApi($user);

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID do cliente invalido.');
        }

        $stmt = $db->prepare('SELECT id FROM clientes WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            jsonError('Cliente nao encontrado.', 404);
        }

        verifyClienteAccess($db, $id, $user);

        $stmt = $db->prepare('UPDATE clientes SET ativo = 0 WHERE id = ?');
        $stmt->execute([$id]);

        jsonSuccess('Cliente eliminado com sucesso.');
        break;

    // ===================================================================
    // DELETE PRODUTO (soft delete)
    // ===================================================================
    case 'delete_produto':
        requireAdminApi($user);

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID do produto invalido.');
        }

        $stmt = $db->prepare('SELECT id FROM produtos WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            jsonError('Produto nao encontrado.', 404);
        }

        verifyProdutoAccess($db, $id, $user);

        $stmt = $db->prepare('UPDATE produtos SET ativo = 0 WHERE id = ?');
        $stmt->execute([$id]);

        jsonSuccess('Produto eliminado com sucesso.');
        break;

    // ===================================================================
    // SAVE ORGANIZACAO (super_admin only)
    // ===================================================================
    case 'save_organizacao':
        if (!isSuperAdmin()) {
            jsonError('Acesso negado. Apenas super administradores podem gerir organizacoes.', 403);
        }

        $id     = (int)($_POST['id'] ?? 0);
        $nome   = sanitize($_POST['nome'] ?? '');
        $slug   = sanitize($_POST['slug'] ?? '');
        $nif    = sanitize($_POST['nif'] ?? '');
        $morada = sanitize($_POST['morada'] ?? '');
        $telefone = sanitize($_POST['telefone'] ?? '');
        $email  = sanitize($_POST['email'] ?? '');
        $website = sanitize($_POST['website'] ?? '');
        $cor_primaria = sanitize($_POST['cor_primaria'] ?? '#2596be');
        $cor_primaria_dark = sanitize($_POST['cor_primaria_dark'] ?? '#1a7a9e');
        $cor_primaria_light = sanitize($_POST['cor_primaria_light'] ?? '#e6f4f9');
        $numeracao_prefixo = sanitize($_POST['numeracao_prefixo'] ?? 'CE');
        $ativo  = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
        $plano  = sanitize($_POST['plano'] ?? 'basico');
        $max_utilizadores = isset($_POST['max_utilizadores']) ? (int)$_POST['max_utilizadores'] : 5;
        $max_especificacoes = isset($_POST['max_especificacoes']) ? (int)$_POST['max_especificacoes'] : null;

        if ($nome === '') {
            jsonError('O nome da organizacao e obrigatorio.');
        }

        if ($id === 0) {
            // Criar nova organizacao
            if ($slug === '') {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $nome));
                $slug = trim($slug, '-');
            }

            // Verificar slug unico
            $stmt = $db->prepare('SELECT id FROM organizacoes WHERE slug = ?');
            $stmt->execute([$slug]);
            if ($stmt->fetch()) {
                $slug .= '-' . time();
            }

            $stmt = $db->prepare('
                INSERT INTO organizacoes (nome, slug, nif, morada, telefone, email, website, cor_primaria, cor_primaria_dark, cor_primaria_light, numeracao_prefixo, ativo, plano, max_utilizadores, max_especificacoes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ');
            $stmt->execute([$nome, $slug, $nif, $morada, $telefone, $email, $website, $cor_primaria, $cor_primaria_dark, $cor_primaria_light, $numeracao_prefixo, $ativo, $plano, $max_utilizadores, $max_especificacoes]);
            $newId = (int)$db->lastInsertId();

            jsonSuccess('Organizacao criada com sucesso.', ['id' => $newId, 'slug' => $slug]);
        } else {
            // Atualizar organizacao existente
            $stmt = $db->prepare('
                UPDATE organizacoes SET
                    nome = ?, slug = ?, nif = ?, morada = ?, telefone = ?,
                    email = ?, website = ?, cor_primaria = ?, cor_primaria_dark = ?,
                    cor_primaria_light = ?, numeracao_prefixo = ?, ativo = ?, plano = ?,
                    max_utilizadores = ?, max_especificacoes = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$nome, $slug, $nif, $morada, $telefone, $email, $website, $cor_primaria, $cor_primaria_dark, $cor_primaria_light, $numeracao_prefixo, $ativo, $plano, $max_utilizadores, $max_especificacoes, $id]);

            jsonSuccess('Organizacao atualizada com sucesso.', ['id' => $id]);
        }
        break;

    // ===================================================================
    // UPLOAD LOGO DA ORGANIZACAO (super_admin only)
    // ===================================================================
    case 'upload_org_logo':
        if (!isSuperAdmin()) {
            jsonError('Acesso negado. Apenas super administradores podem gerir organizacoes.', 403);
        }

        $org_id = (int)($_POST['organizacao_id'] ?? 0);
        if ($org_id <= 0) {
            jsonError('ID da organizacao invalido.');
        }

        // Verificar se a organizacao existe
        $stmt = $db->prepare('SELECT id, logo FROM organizacoes WHERE id = ?');
        $stmt->execute([$org_id]);
        $org = $stmt->fetch();
        if (!$org) {
            jsonError('Organizacao nao encontrada.', 404);
        }

        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            jsonError('Nenhum ficheiro enviado.');
        }

        $file = $_FILES['logo'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
            jsonError('Formato invalido. Use PNG ou JPG.');
        }

        // Validar MIME type real do ficheiro
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMimeType = $finfo->file($file['tmp_name']);
        $logoMimes = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];
        if (isset($logoMimes[$ext]) && !in_array($realMimeType, $logoMimes[$ext])) {
            jsonError('Tipo de ficheiro invalido (MIME type nao corresponde a extensao).');
        }

        $logosDir = UPLOAD_DIR . 'logos/';
        if (!is_dir($logosDir)) {
            mkdir($logosDir, 0755, true);
        }

        $filename = 'org_' . $org_id . '_' . time() . '.' . $ext;
        $destPath = $logosDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            jsonError('Erro ao guardar o ficheiro.');
        }

        // Remover logo antigo se existir
        if (!empty($org['logo']) && file_exists($logosDir . $org['logo'])) {
            unlink($logosDir . $org['logo']);
        }

        // Atualizar na base de dados
        $stmt = $db->prepare('UPDATE organizacoes SET logo = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$filename, $org_id]);

        jsonSuccess('Logo da organizacao carregado.', ['filename' => $filename]);
        break;

    // ===================================================================
    // GET FORNECEDOR LOG
    // ===================================================================
    case 'get_fornecedor_log':
        requireAdminApi($user);
        $fornId = (int)($_GET['fornecedor_id'] ?? 0);
        if ($fornId <= 0) jsonError('ID do fornecedor invalido.');
        // Verificar acesso multi-tenant
        if (!isSuperAdmin()) {
            $chk = $db->prepare('SELECT organizacao_id FROM fornecedores WHERE id = ?');
            $chk->execute([$fornId]);
            if ($chk->fetchColumn() != $user['org_id']) jsonError('Acesso negado.');
        }
        $stmt = $db->prepare('SELECT fl.*, u.nome as user_nome FROM fornecedores_log fl LEFT JOIN utilizadores u ON u.id = fl.alterado_por WHERE fl.fornecedor_id = ? ORDER BY fl.created_at DESC LIMIT 50');
        $stmt->execute([$fornId]);
        jsonSuccess('OK', $stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
}
