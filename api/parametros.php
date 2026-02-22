<?php
/**
 * Handler: Parâmetros (tipos e banco) — sistema genérico
 * Actions: get_parametros_tipos, get_parametros_tipos_all, save_parametro_tipo,
 *          delete_parametro_tipo, get_parametros_banco, save_parametro_banco,
 *          delete_parametro_banco, save_parametro_tipo_config
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // GET PARAMETROS TIPOS (tipos visíveis para a org do user, apenas ativos)
    // ===================================================================
    case 'get_parametros_tipos':
        $orgId = (int)($user['org_id'] ?? 0);
        $stmt = $db->prepare('
            SELECT t.*, GROUP_CONCAT(DISTINCT po.org_id) as org_ids
            FROM parametros_tipos t
            LEFT JOIN parametros_tipos_org po ON po.tipo_id = t.id
            WHERE t.ativo = 1 AND (t.todas_orgs = 1 OR po.org_id = ?)
            GROUP BY t.id
            ORDER BY t.ordem, t.nome
        ');
        $stmt->execute([$orgId]);
        jsonSuccess('OK', ['tipos' => $stmt->fetchAll()]);
        break;

    // ===================================================================
    // GET ALL PARAMETROS TIPOS (super_admin — inclui inativos, todos)
    // ===================================================================
    case 'get_parametros_tipos_all':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $stmt = $db->query('
            SELECT t.*, GROUP_CONCAT(DISTINCT po.org_id) as org_ids
            FROM parametros_tipos t
            LEFT JOIN parametros_tipos_org po ON po.tipo_id = t.id
            GROUP BY t.id
            ORDER BY t.ordem, t.nome
        ');
        jsonSuccess('OK', ['tipos' => $stmt->fetchAll()]);
        break;

    // ===================================================================
    // SAVE PARAMETRO TIPO (criar/editar tipo)
    // ===================================================================
    case 'save_parametro_tipo':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        $nome = trim($jsonBody['nome'] ?? '');
        $slug = trim($jsonBody['slug'] ?? '');
        $colunas = $jsonBody['colunas'] ?? [];
        $legenda = trim($jsonBody['legenda'] ?? '');
        $legendaTamanho = (int)($jsonBody['legenda_tamanho'] ?? 9);
        $ativo = (int)($jsonBody['ativo'] ?? 1);
        $todasOrgs = (int)($jsonBody['todas_orgs'] ?? 1);
        $orgIds = $jsonBody['org_ids'] ?? [];
        $categorias = $jsonBody['categorias'] ?? [];

        if (!$nome) jsonError('Nome é obrigatório.');
        if (!$slug) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome)));
            $slug = trim($slug, '_');
        }
        if (empty($colunas)) jsonError('Defina pelo menos uma coluna.');

        // Validar colunas
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
        // Sanitizar categorias — array de strings
        $catsFinal = [];
        foreach ($categorias as $cat) {
            $cat = trim($cat);
            if ($cat !== '') $catsFinal[] = $cat;
        }
        $categoriasJson = !empty($catsFinal) ? json_encode($catsFinal, JSON_UNESCAPED_UNICODE) : null;
        if ($legendaTamanho < 6) $legendaTamanho = 6;
        if ($legendaTamanho > 14) $legendaTamanho = 14;

        if ($id > 0) {
            $stmt = $db->prepare('UPDATE parametros_tipos SET nome = ?, slug = ?, colunas = ?, legenda = ?, legenda_tamanho = ?, ativo = ?, todas_orgs = ?, categorias = ? WHERE id = ?');
            $stmt->execute([$nome, $slug, $colunasJson, $legenda, $legendaTamanho, $ativo, $todasOrgs, $categoriasJson, $id]);
        } else {
            $stmtMax = $db->query('SELECT COALESCE(MAX(ordem),0)+1 FROM parametros_tipos');
            $maxOrdem = $stmtMax->fetchColumn();
            $stmt = $db->prepare('INSERT INTO parametros_tipos (nome, slug, colunas, legenda, legenda_tamanho, ativo, todas_orgs, categorias, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$nome, $slug, $colunasJson, $legenda, $legendaTamanho, $ativo, $todasOrgs, $categoriasJson, $maxOrdem]);
            $id = (int)$db->lastInsertId();
        }

        // Gerir orgs associadas
        $db->prepare('DELETE FROM parametros_tipos_org WHERE tipo_id = ?')->execute([$id]);
        if (!$todasOrgs && !empty($orgIds)) {
            $ins = $db->prepare('INSERT INTO parametros_tipos_org (tipo_id, org_id) VALUES (?, ?)');
            foreach ($orgIds as $oid) $ins->execute([$id, (int)$oid]);
        }

        jsonSuccess('Tipo de parâmetro guardado.', ['id' => $id]);
        break;

    // ===================================================================
    // DELETE PARAMETRO TIPO
    // ===================================================================
    case 'delete_parametro_tipo':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        if ($id <= 0) jsonError('ID inválido.');
        // CASCADE: parametros_tipos_org apagados automaticamente
        // Apagar também registos do banco
        $db->prepare('DELETE FROM parametros_banco WHERE tipo_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM parametros_tipos WHERE id = ?')->execute([$id]);
        jsonSuccess('Tipo e registos eliminados.');
        break;

    // ===================================================================
    // SAVE PARAMETRO TIPO CONFIG (merges, col_widths, legenda)
    // ===================================================================
    case 'save_parametro_tipo_config':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        if ($id <= 0) jsonError('ID inválido.');
        $updates = [];
        $params = [];
        if (array_key_exists('merges', $jsonBody)) {
            $updates[] = 'merges = ?';
            $params[] = json_encode($jsonBody['merges']);
        }
        if (array_key_exists('col_widths', $jsonBody)) {
            $updates[] = 'col_widths = ?';
            $params[] = json_encode($jsonBody['col_widths']);
        }
        if (array_key_exists('legenda', $jsonBody)) {
            $updates[] = 'legenda = ?';
            $params[] = trim($jsonBody['legenda']);
        }
        if (array_key_exists('legenda_tamanho', $jsonBody)) {
            $tam = (int)$jsonBody['legenda_tamanho'];
            if ($tam < 6) $tam = 6;
            if ($tam > 14) $tam = 14;
            $updates[] = 'legenda_tamanho = ?';
            $params[] = $tam;
        }
        if (empty($updates)) jsonError('Nada para atualizar.');
        $params[] = $id;
        $db->prepare('UPDATE parametros_tipos SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
        jsonSuccess('Configuração guardada.');
        break;

    // ===================================================================
    // GET PARAMETROS BANCO (entradas de um tipo)
    // ===================================================================
    case 'get_parametros_banco':
        $tipoId = (int)($_GET['tipo_id'] ?? $jsonBody['tipo_id'] ?? 0);
        $all = isset($_GET['all']) && isSuperAdmin();
        if (!$tipoId) jsonError('Tipo não especificado.');
        if ($all) {
            $stmt = $db->prepare('SELECT * FROM parametros_banco WHERE tipo_id = ? ORDER BY ordem, categoria');
        } else {
            $stmt = $db->prepare('SELECT * FROM parametros_banco WHERE tipo_id = ? AND ativo = 1 ORDER BY ordem, categoria');
        }
        $stmt->execute([$tipoId]);
        jsonSuccess('OK', ['parametros' => $stmt->fetchAll()]);
        break;

    // ===================================================================
    // SAVE PARAMETRO BANCO
    // ===================================================================
    case 'save_parametro_banco':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        $tipoId = (int)($jsonBody['tipo_id'] ?? 0);
        $categoria = trim($jsonBody['categoria'] ?? '');
        $valores = $jsonBody['valores'] ?? [];
        $ativo = (int)($jsonBody['ativo'] ?? 1);

        if (!$tipoId) jsonError('Tipo não especificado.');
        $valoresJson = json_encode($valores, JSON_UNESCAPED_UNICODE);

        if ($id > 0) {
            $stmt = $db->prepare('UPDATE parametros_banco SET categoria = ?, valores = ?, ativo = ? WHERE id = ?');
            $stmt->execute([$categoria, $valoresJson, $ativo, $id]);
        } else {
            $stmtMax = $db->prepare('SELECT COALESCE(MAX(ordem),0)+1 FROM parametros_banco WHERE tipo_id = ?');
            $stmtMax->execute([$tipoId]);
            $maxOrdem = $stmtMax->fetchColumn();
            $stmt = $db->prepare('INSERT INTO parametros_banco (tipo_id, organizacao_id, categoria, valores, ativo, ordem) VALUES (?, 0, ?, ?, ?, ?)');
            $stmt->execute([$tipoId, $categoria, $valoresJson, $ativo, $maxOrdem]);
            $id = (int)$db->lastInsertId();
        }
        jsonSuccess('Registo guardado.', ['id' => $id]);
        break;

    // ===================================================================
    // DELETE PARAMETRO BANCO
    // ===================================================================
    case 'delete_parametro_banco':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $id = (int)($jsonBody['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM parametros_banco WHERE id = ?')->execute([$id]);
        }
        jsonSuccess('Registo eliminado.');
        break;
}
