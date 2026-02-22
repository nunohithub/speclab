<?php
/**
 * Handler: Templates
 * Actions: get_templates, save_template, delete_template, load_product_templates,
 *          list_templates, get_template
 *
 * Note: save_template and delete_template serve dual purposes:
 *   - Product parameter templates (when produto_id is present)
 *   - Specification templates (when especificacao_id or template_id is present)
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // GET PRODUCT TEMPLATES
    // ===================================================================
    case 'get_templates':
        $produto_id = (int)($_GET['produto_id'] ?? $_POST['produto_id'] ?? 0);
        if ($produto_id <= 0) jsonError('ID do produto invalido.');

        if (isSuperAdmin()) {
            $stmt = $db->prepare('SELECT * FROM produto_parametros_template WHERE produto_id = ? ORDER BY ordem, categoria, ensaio');
            $stmt->execute([$produto_id]);
        } else {
            $stmt = $db->prepare('
                SELECT ppt.* FROM produto_parametros_template ppt
                INNER JOIN produtos p ON p.id = ppt.produto_id
                WHERE ppt.produto_id = ?
                  AND (p.organizacao_id IS NULL OR p.organizacao_id = ?)
                ORDER BY ppt.ordem, ppt.categoria, ppt.ensaio
            ');
            $stmt->execute([$produto_id, $user['org_id']]);
        }
        $templates = $stmt->fetchAll();

        jsonSuccess('Templates carregados.', $templates);
        break;

    // ===================================================================
    // SAVE TEMPLATE (disambiguates product vs spec template)
    // ===================================================================
    case 'save_template':
        requireAdminApi($user);

        // Check if this is a product template (produto_id) or spec template (especificacao_id)
        $produto_id = (int)($_POST['produto_id'] ?? $jsonBody['produto_id'] ?? 0);
        $especId = (int)($jsonBody['especificacao_id'] ?? $_POST['especificacao_id'] ?? 0);

        if ($produto_id > 0) {
            // --- PRODUCT TEMPLATE ---
            $categoria = sanitize($_POST['categoria'] ?? '');
            $ensaio = sanitize($_POST['ensaio'] ?? '');
            $especificacao_valor = sanitize($_POST['especificacao_valor'] ?? '');
            $metodo = sanitize($_POST['metodo'] ?? '');
            $amostra_nqa = sanitize($_POST['amostra_nqa'] ?? '');

            if (empty($ensaio)) jsonError('O nome do ensaio e obrigatorio.');

            // Get next order
            $stmt = $db->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM produto_parametros_template WHERE produto_id = ?');
            $stmt->execute([$produto_id]);
            $ordem = (int)$stmt->fetchColumn();

            $stmt = $db->prepare('INSERT INTO produto_parametros_template (produto_id, categoria, ensaio, especificacao_valor, metodo, amostra_nqa, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$produto_id, $categoria, $ensaio, $especificacao_valor, $metodo, $amostra_nqa, $ordem]);

            jsonSuccess('Template adicionado.', ['id' => (int)$db->lastInsertId()]);

        } elseif ($especId > 0) {
            // --- SPECIFICATION TEMPLATE ---
            $nome = sanitize($jsonBody['nome'] ?? $_POST['nome'] ?? '');
            $descricao = sanitize($jsonBody['descricao'] ?? $_POST['descricao'] ?? '');
            if (!$nome) jsonError('Nome do template e obrigatorio.');
            checkSaOrgAccess($db, $user, $especId);

            // Carregar dados da spec para o template
            $spec = getEspecificacaoCompleta($db, $especId);
            if (!$spec) jsonError('Especificacao nao encontrada.');
            $dados = [
                'titulo' => $spec['titulo'],
                'objetivo' => $spec['objetivo'] ?? '',
                'ambito' => $spec['ambito'] ?? '',
                'definicao_material' => $spec['definicao_material'] ?? '',
                'regulamentacao' => $spec['regulamentacao'] ?? '',
                'processos' => $spec['processos'] ?? '',
                'embalagem' => $spec['embalagem'] ?? '',
                'aceitacao' => $spec['aceitacao'] ?? '',
                'observacoes' => $spec['observacoes'] ?? '',
                'seccoes' => $spec['seccoes'] ?? [],
                'parametros' => $spec['parametros'] ?? [],
                'classes' => $spec['classes'] ?? [],
                'defeitos' => $spec['defeitos'] ?? [],
            ];
            $orgIdTpl = isSuperAdmin() ? null : $user['org_id'];
            $db->prepare('INSERT INTO especificacao_templates (nome, descricao, organizacao_id, dados, criado_por) VALUES (?, ?, ?, ?, ?)')
               ->execute([$nome, $descricao, $orgIdTpl, json_encode($dados), $user['id']]);
            jsonSuccess('Template guardado.');

        } else {
            jsonError('ID do produto ou especificacao invalido.');
        }
        break;

    // ===================================================================
    // DELETE TEMPLATE (disambiguates product vs spec template)
    // ===================================================================
    case 'delete_template':
        requireAdminApi($user);

        // Spec template uses template_id, product template uses id
        $tplId = (int)($jsonBody['template_id'] ?? 0);
        $id = (int)($_POST['id'] ?? $jsonBody['id'] ?? 0);

        if ($tplId > 0) {
            // --- SPECIFICATION TEMPLATE ---
            $db->prepare('DELETE FROM especificacao_templates WHERE id = ?')->execute([$tplId]);
            jsonSuccess('Template eliminado.');

        } elseif ($id > 0) {
            // --- PRODUCT TEMPLATE ---
            // Verificar que template pertence a produto acessivel
            $stmt = $db->prepare('SELECT p.organizacao_id FROM produto_parametros_template t JOIN produtos p ON t.produto_id = p.id WHERE t.id = ?');
            $stmt->execute([$id]);
            $tplOrg = $stmt->fetchColumn();
            if (!isSuperAdmin() && $tplOrg != $user['org_id'] && $tplOrg !== null) {
                jsonError('Acesso negado.', 403);
            }

            $stmt = $db->prepare('DELETE FROM produto_parametros_template WHERE id = ?');
            $stmt->execute([$id]);

            jsonSuccess('Template removido.');

        } else {
            jsonError('ID do template invalido.');
        }
        break;

    // ===================================================================
    // LOAD PRODUCT TEMPLATES FOR EDITOR
    // ===================================================================
    case 'load_product_templates':
        $produto_id = (int)($_GET['produto_id'] ?? $_POST['produto_id'] ?? 0);
        if ($produto_id <= 0) jsonError('ID do produto invalido.');

        if (isSuperAdmin()) {
            $stmt = $db->prepare('SELECT categoria, ensaio, especificacao_valor, metodo, amostra_nqa, ordem FROM produto_parametros_template WHERE produto_id = ? ORDER BY ordem, categoria');
            $stmt->execute([$produto_id]);
        } else {
            $stmt = $db->prepare('
                SELECT ppt.categoria, ppt.ensaio, ppt.especificacao_valor, ppt.metodo, ppt.amostra_nqa, ppt.ordem
                FROM produto_parametros_template ppt
                INNER JOIN produtos p ON p.id = ppt.produto_id
                WHERE ppt.produto_id = ?
                  AND (p.organizacao_id IS NULL OR p.organizacao_id = ?)
                ORDER BY ppt.ordem, ppt.categoria
            ');
            $stmt->execute([$produto_id, $user['org_id']]);
        }
        $templates = $stmt->fetchAll();

        jsonSuccess('Templates do produto carregados.', $templates);
        break;

    // ===================================================================
    // LIST SPECIFICATION TEMPLATES
    // ===================================================================
    case 'list_templates':
        if (isSuperAdmin()) {
            $tpls = $db->query('SELECT id, nome, descricao, created_at FROM especificacao_templates ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare('SELECT id, nome, descricao, created_at FROM especificacao_templates WHERE organizacao_id = ? OR organizacao_id IS NULL ORDER BY nome');
            $stmt->execute([$user['org_id']]);
            $tpls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        jsonSuccess('OK', $tpls);
        break;

    // ===================================================================
    // GET SPECIFICATION TEMPLATE
    // ===================================================================
    case 'get_template':
        $tplId = (int)($jsonBody['template_id'] ?? $_GET['template_id'] ?? 0);
        if ($tplId <= 0) jsonError('ID invalido.');
        $stmt = $db->prepare('SELECT * FROM especificacao_templates WHERE id = ?');
        $stmt->execute([$tplId]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) jsonError('Template nao encontrado.');
        if (!isSuperAdmin() && $tpl['organizacao_id'] && $tpl['organizacao_id'] != $user['org_id']) jsonError('Acesso negado.');
        $tpl['dados'] = json_decode($tpl['dados'], true);
        jsonSuccess('OK', $tpl);
        break;
}
