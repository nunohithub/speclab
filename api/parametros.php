<?php
/**
 * Handler: Parâmetros Custom (tipos e banco)
 * Actions: get_parametros_tipos, save_parametro_tipo, delete_parametro_tipo,
 *          get_parametros_banco, save_parametro_banco, delete_parametro_banco
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // GET PARAMETROS TIPOS (lista tipos de parâmetros da org)
    // ===================================================================
    case 'get_parametros_tipos':
        $orgId = (int)($user['org_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM parametros_tipos WHERE organizacao_id = ? AND ativo = 1 ORDER BY ordem, nome');
        $stmt->execute([$orgId]);
        jsonSuccess('OK', ['tipos' => $stmt->fetchAll()]);
        break;

    // ===================================================================
    // GET ALL PARAMETROS TIPOS (super_admin - inclui inativos)
    // ===================================================================
    case 'get_parametros_tipos_all':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $orgId = (int)($user['org_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM parametros_tipos WHERE organizacao_id = ? ORDER BY ordem, nome');
        $stmt->execute([$orgId]);
        jsonSuccess('OK', ['tipos' => $stmt->fetchAll()]);
        break;

    // ===================================================================
    // SAVE PARAMETRO TIPO
    // ===================================================================
    case 'save_parametro_tipo':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        $nome = trim($jsonBody['nome'] ?? '');
        $slug = trim($jsonBody['slug'] ?? '');
        $colunas = $jsonBody['colunas'] ?? [];
        $legenda = trim($jsonBody['legenda'] ?? '');
        $ativo = (int)($jsonBody['ativo'] ?? 1);
        $orgId = (int)($user['org_id'] ?? 0);

        if (!$nome) jsonError('Nome é obrigatório.');
        if (!$slug) {
            // Gerar slug a partir do nome
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome)));
            $slug = trim($slug, '_');
        }
        if (empty($colunas)) jsonError('Defina pelo menos uma coluna.');

        // Validar colunas: cada uma deve ter nome e chave
        foreach ($colunas as &$col) {
            $col['nome'] = trim($col['nome'] ?? '');
            $col['chave'] = trim($col['chave'] ?? '');
            if (!$col['nome']) jsonError('Todas as colunas devem ter um nome.');
            if (!$col['chave']) {
                $col['chave'] = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $col['nome'])));
                $col['chave'] = trim($col['chave'], '_');
            }
        }
        unset($col);
        $colunasJson = json_encode($colunas, JSON_UNESCAPED_UNICODE);

        if ($id > 0) {
            // Verificar que pertence à org e não é o tipo "ensaios" fixo
            $stmt = $db->prepare('UPDATE parametros_tipos SET nome = ?, slug = ?, colunas = ?, legenda = ?, ativo = ? WHERE id = ? AND organizacao_id = ?');
            $stmt->execute([$nome, $slug, $colunasJson, $legenda, $ativo, $id, $orgId]);
        } else {
            $stmtMax = $db->prepare('SELECT COALESCE(MAX(ordem),0)+1 FROM parametros_tipos WHERE organizacao_id = ?');
            $stmtMax->execute([$orgId]);
            $maxOrdem = $stmtMax->fetchColumn();
            $stmt = $db->prepare('INSERT INTO parametros_tipos (organizacao_id, nome, slug, colunas, legenda, ativo, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$orgId, $nome, $slug, $colunasJson, $legenda, $ativo, $maxOrdem]);
            $id = (int)$db->lastInsertId();
        }
        jsonSuccess('Tipo de parâmetro guardado.', ['id' => $id]);
        break;

    // ===================================================================
    // DELETE PARAMETRO TIPO
    // ===================================================================
    case 'delete_parametro_tipo':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        $orgId = (int)($user['org_id'] ?? 0);
        if ($id <= 0) jsonError('ID inválido.');
        // Verificar se é o tipo "ensaios" padrão (slug = ensaios) — não pode eliminar
        $stmt = $db->prepare('SELECT slug FROM parametros_tipos WHERE id = ? AND organizacao_id = ?');
        $stmt->execute([$id, $orgId]);
        $slug = $stmt->fetchColumn();
        if ($slug === 'ensaios') jsonError('Não é possível eliminar o tipo Ensaios padrão. Pode desativá-lo.');
        $db->prepare('DELETE FROM parametros_tipos WHERE id = ? AND organizacao_id = ?')->execute([$id, $orgId]);
        jsonSuccess('Tipo eliminado.');
        break;

    // ===================================================================
    // GET PARAMETROS BANCO (entradas de um tipo)
    // ===================================================================
    case 'get_parametros_banco':
        $tipoId = (int)($_GET['tipo_id'] ?? $jsonBody['tipo_id'] ?? 0);
        $orgId = (int)($user['org_id'] ?? 0);
        if (!$tipoId) jsonError('Tipo não especificado.');
        $stmt = $db->prepare('SELECT * FROM parametros_banco WHERE tipo_id = ? AND organizacao_id = ? ORDER BY ordem, categoria');
        $stmt->execute([$tipoId, $orgId]);
        jsonSuccess('OK', ['parametros' => $stmt->fetchAll()]);
        break;

    // ===================================================================
    // SAVE PARAMETRO BANCO
    // ===================================================================
    case 'save_parametro_banco':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        $tipoId = (int)($jsonBody['tipo_id'] ?? 0);
        $orgId = (int)($user['org_id'] ?? 0);
        $categoria = trim($jsonBody['categoria'] ?? '');
        $valores = $jsonBody['valores'] ?? [];
        $ativo = (int)($jsonBody['ativo'] ?? 1);

        if (!$tipoId) jsonError('Tipo não especificado.');
        $valoresJson = json_encode($valores, JSON_UNESCAPED_UNICODE);

        if ($id > 0) {
            $stmt = $db->prepare('UPDATE parametros_banco SET categoria = ?, valores = ?, ativo = ? WHERE id = ? AND organizacao_id = ?');
            $stmt->execute([$categoria, $valoresJson, $ativo, $id, $orgId]);
        } else {
            $stmtMax = $db->prepare('SELECT COALESCE(MAX(ordem),0)+1 FROM parametros_banco WHERE tipo_id = ? AND organizacao_id = ?');
            $stmtMax->execute([$tipoId, $orgId]);
            $maxOrdem = $stmtMax->fetchColumn();
            $stmt = $db->prepare('INSERT INTO parametros_banco (tipo_id, organizacao_id, categoria, valores, ativo, ordem) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$tipoId, $orgId, $categoria, $valoresJson, $ativo, $maxOrdem]);
            $id = (int)$db->lastInsertId();
        }
        jsonSuccess('Parâmetro guardado.', ['id' => $id]);
        break;

    // ===================================================================
    // DELETE PARAMETRO BANCO
    // ===================================================================
    case 'delete_parametro_banco':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        $orgId = (int)($user['org_id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM parametros_banco WHERE id = ? AND organizacao_id = ?')->execute([$id, $orgId]);
        }
        jsonSuccess('Parâmetro eliminado.');
        break;
}
