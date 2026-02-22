<?php
/**
 * Handler: Especificacoes
 * Actions: save_especificacao, save_parametros, save_classes, save_defeitos,
 *          save_seccoes, get_especificacao, delete_especificacao, duplicate_especificacao,
 *          set_password, save_config_visual
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // SAVE ESPECIFICACAO
    // ===================================================================
    case 'save_especificacao':
        $id            = (int)($_POST['id'] ?? 0);
        if ($id > 0) checkSaOrgAccess($db, $user, $id);
        $numero        = sanitize($_POST['numero'] ?? '');
        $titulo        = sanitize($_POST['titulo'] ?? '');
        $cliente_id    = !empty($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : null;

        // Produtos e fornecedores: arrays (muitos-para-muitos)
        $produto_ids = [];
        if (!empty($_POST['produto_ids']) && is_array($_POST['produto_ids'])) {
            $produto_ids = array_filter(array_map('intval', $_POST['produto_ids']));
        } elseif (!empty($_POST['produto_id'])) {
            $produto_ids = [(int)$_POST['produto_id']];
        }
        $fornecedor_ids = [];
        if (!empty($_POST['fornecedor_ids']) && is_array($_POST['fornecedor_ids'])) {
            $fornecedor_ids = array_filter(array_map('intval', $_POST['fornecedor_ids']));
        } elseif (!empty($_POST['fornecedor_id'])) {
            $fornecedor_ids = [(int)$_POST['fornecedor_id']];
        }
        $versao        = sanitize($_POST['versao'] ?? '1');
        $data_emissao  = !empty($_POST['data_emissao']) ? $_POST['data_emissao'] : date('Y-m-d');
        $data_revisao  = !empty($_POST['data_revisao']) ? $_POST['data_revisao'] : null;
        $data_validade = !empty($_POST['data_validade']) ? $_POST['data_validade'] : null;
        $estado        = sanitize($_POST['estado'] ?? 'rascunho');
        $tipo_doc      = sanitize($_POST['tipo_doc'] ?? 'caderno');
        if (!in_array($tipo_doc, ['caderno', 'ficha_tecnica'])) $tipo_doc = 'caderno';
        $idioma        = sanitize($_POST['idioma'] ?? 'pt');
        if (!in_array($idioma, ['pt', 'en', 'es', 'fr', 'de', 'it'])) $idioma = 'pt';

        // Acesso publico
        $codigo_acesso_input = trim($_POST['codigo_acesso'] ?? '');
        $senha_publica  = trim($_POST['senha_publica'] ?? '');

        // Campos de texto (rich text)
        $objetivo       = sanitizeRichText($_POST['objetivo'] ?? '');
        $ambito         = sanitizeRichText($_POST['ambito'] ?? '');
        $definicao_material = sanitizeRichText($_POST['definicao_material'] ?? '');
        $regulamentacao = sanitizeRichText($_POST['regulamentacao'] ?? '');
        $processos      = sanitizeRichText($_POST['processos'] ?? '');
        $embalagem      = sanitizeRichText($_POST['embalagem'] ?? '');
        $aceitacao      = sanitizeRichText($_POST['aceitacao'] ?? '');
        $arquivo_texto  = sanitizeRichText($_POST['arquivo_texto'] ?? '');
        $indemnizacao   = sanitizeRichText($_POST['indemnizacao'] ?? '');
        $observacoes    = sanitizeRichText($_POST['observacoes'] ?? '');
        $config_visual  = $_POST['config_visual'] ?? null;
        $legislacao_json = $_POST['legislacao_json'] ?? null;

        // Validacao basica
        if ($titulo === '') {
            jsonError('O titulo e obrigatorio.');
        }

        // Validar estado
        if (!in_array($estado, ['rascunho', 'em_revisao', 'ativo', 'obsoleto'])) {
            jsonError('Estado invalido.');
        }

        if ($id === 0) {
            // --- CRIAR NOVA ---
            // Verificar limite de especificacoes do plano
            if ($user['org_id']) {
                $limiteSpec = podeCriarEspecificacao($db, $user['org_id']);
                if (!$limiteSpec['ok']) {
                    jsonError($limiteSpec['msg']);
                }
            }

            if ($numero === '') {
                $numero = gerarNumeroEspecificacao($db, $user['org_id']);
            }
            $codigo_acesso = gerarCodigoAcesso();

            $stmt = $db->prepare('
                INSERT INTO especificacoes (
                    numero, titulo, idioma, tipo_doc, cliente_id, versao,
                    data_emissao, data_revisao, data_validade, estado, codigo_acesso,
                    objetivo, ambito, definicao_material, regulamentacao,
                    processos, embalagem, aceitacao, arquivo_texto,
                    indemnizacao, observacoes, config_visual, legislacao_json,
                    criado_por, organizacao_id, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, NOW(), NOW()
                )
            ');
            $stmt->execute([
                $numero, $titulo, $idioma, $tipo_doc, $cliente_id, $versao,
                $data_emissao, $data_revisao, $data_validade, $estado, $codigo_acesso,
                $objetivo, $ambito, $definicao_material, $regulamentacao,
                $processos, $embalagem, $aceitacao, $arquivo_texto,
                $indemnizacao, $observacoes, $config_visual, $legislacao_json,
                $user['id'], $user['org_id'],
            ]);

            $newId = (int)$db->lastInsertId();

            // Guardar produtos e fornecedores (muitos-para-muitos)
            saveEspecProdutos($db, $newId, $produto_ids);
            saveEspecFornecedores($db, $newId, $fornecedor_ids);

            jsonSuccess('Especificacao criada com sucesso.', [
                'id'     => $newId,
                'numero' => $numero,
            ]);

        } else {
            // --- ATUALIZAR EXISTENTE ---
            verifySpecAccess($db, $id, $user);

            // Tratar password de acesso publico
            $passwordUpdate = '';
            $extraParams = [];
            if ($senha_publica !== '') {
                $passwordUpdate = ', password_acesso = ?';
                $extraParams[] = password_hash($senha_publica, PASSWORD_DEFAULT);
            }
            // Tratar codigo de acesso
            $codigoUpdate = '';
            if ($codigo_acesso_input !== '') {
                $codigoUpdate = ', codigo_acesso = ?';
                $extraParams[] = $codigo_acesso_input;
            }

            $stmt = $db->prepare('
                UPDATE especificacoes SET
                    numero = ?, titulo = ?, idioma = ?, tipo_doc = ?, cliente_id = ?, versao = ?,
                    data_emissao = ?, data_revisao = ?, data_validade = ?, estado = ?,
                    objetivo = ?, ambito = ?, definicao_material = ?, regulamentacao = ?,
                    processos = ?, embalagem = ?, aceitacao = ?, arquivo_texto = ?,
                    indemnizacao = ?, observacoes = ?, config_visual = ?, legislacao_json = ?,
                    updated_at = NOW()
                    ' . $passwordUpdate . $codigoUpdate . '
                WHERE id = ?
            ');
            $executeParams = [
                $numero, $titulo, $idioma, $tipo_doc, $cliente_id, $versao,
                $data_emissao, $data_revisao, $data_validade, $estado,
                $objetivo, $ambito, $definicao_material, $regulamentacao,
                $processos, $embalagem, $aceitacao, $arquivo_texto,
                $indemnizacao, $observacoes, $config_visual, $legislacao_json,
            ];
            $executeParams = array_merge($executeParams, $extraParams, [$id]);
            $stmt->execute($executeParams);

            // Guardar produtos e fornecedores (muitos-para-muitos)
            saveEspecProdutos($db, $id, $produto_ids);
            saveEspecFornecedores($db, $id, $fornecedor_ids);

            jsonSuccess('Especificacao guardada com sucesso.', [
                'id'     => $id,
                'numero' => $numero,
            ]);
        }
        break;

    // ===================================================================
    // SAVE PARAMETROS
    // ===================================================================
    case 'save_parametros':
        $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
        if ($especificacao_id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $especificacao_id, $user);

        $parametros = $_POST['parametros'] ?? [];

        $db->beginTransaction();
        try {
            // Apagar existentes
            $stmt = $db->prepare('DELETE FROM especificacao_parametros WHERE especificacao_id = ?');
            $stmt->execute([$especificacao_id]);

            // Inserir novos
            if (!empty($parametros) && is_array($parametros)) {
                $stmt = $db->prepare('
                    INSERT INTO especificacao_parametros
                        (especificacao_id, categoria, ensaio, especificacao_valor, metodo, amostra_nqa, unidade, ordem)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                foreach ($parametros as $i => $p) {
                    $stmt->execute([
                        $especificacao_id,
                        sanitize($p['categoria'] ?? ''),
                        sanitize($p['ensaio'] ?? ''),
                        sanitize($p['especificacao_valor'] ?? ''),
                        sanitize($p['metodo'] ?? ''),
                        sanitize($p['amostra_nqa'] ?? ''),
                        sanitize($p['unidade'] ?? ''),
                        (int)($p['ordem'] ?? $i),
                    ]);
                }
            }

            // Atualizar timestamp da especificacao
            $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
            $stmt->execute([$especificacao_id]);

            $db->commit();
            jsonSuccess('Parametros guardados com sucesso.');
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        break;

    // ===================================================================
    // SAVE CLASSES VISUAIS
    // ===================================================================
    case 'save_classes':
        $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
        if ($especificacao_id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $especificacao_id, $user);

        $classes = $_POST['classes'] ?? [];

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('DELETE FROM especificacao_classes WHERE especificacao_id = ?');
            $stmt->execute([$especificacao_id]);

            if (!empty($classes) && is_array($classes)) {
                $stmt = $db->prepare('
                    INSERT INTO especificacao_classes
                        (especificacao_id, classe, defeitos_max, descricao, ordem)
                    VALUES (?, ?, ?, ?, ?)
                ');
                foreach ($classes as $i => $c) {
                    $stmt->execute([
                        $especificacao_id,
                        sanitize($c['classe'] ?? ''),
                        (int)($c['defeitos_max'] ?? 0),
                        sanitize($c['descricao'] ?? ''),
                        (int)($c['ordem'] ?? $i),
                    ]);
                }
            }

            $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
            $stmt->execute([$especificacao_id]);

            $db->commit();
            jsonSuccess('Classes visuais guardadas com sucesso.');
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        break;

    // ===================================================================
    // SAVE DEFEITOS
    // ===================================================================
    case 'save_defeitos':
        $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
        if ($especificacao_id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $especificacao_id, $user);

        $defeitos = $_POST['defeitos'] ?? [];

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('DELETE FROM especificacao_defeitos WHERE especificacao_id = ?');
            $stmt->execute([$especificacao_id]);

            if (!empty($defeitos) && is_array($defeitos)) {
                $stmt = $db->prepare('
                    INSERT INTO especificacao_defeitos
                        (especificacao_id, nome, tipo, descricao, ordem)
                    VALUES (?, ?, ?, ?, ?)
                ');
                foreach ($defeitos as $i => $d) {
                    $tipo = sanitize($d['tipo'] ?? 'menor');
                    if (!in_array($tipo, ['critico', 'maior', 'menor'])) {
                        $tipo = 'menor';
                    }
                    $stmt->execute([
                        $especificacao_id,
                        sanitize($d['nome'] ?? ''),
                        $tipo,
                        sanitize($d['descricao'] ?? ''),
                        (int)($d['ordem'] ?? $i),
                    ]);
                }
            }

            $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
            $stmt->execute([$especificacao_id]);

            $db->commit();
            jsonSuccess('Defeitos guardados com sucesso.');
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        break;

    // ===================================================================
    // SAVE SECCOES
    // ===================================================================
    case 'save_seccoes':
        $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
        if ($especificacao_id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $especificacao_id, $user);

        $seccoes = $_POST['seccoes'] ?? [];

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('DELETE FROM especificacao_seccoes WHERE especificacao_id = ?');
            $stmt->execute([$especificacao_id]);

            if (!empty($seccoes) && is_array($seccoes)) {
                $stmt = $db->prepare('
                    INSERT INTO especificacao_seccoes
                        (especificacao_id, titulo, conteudo, tipo, nivel, ordem)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                foreach ($seccoes as $i => $s) {
                    $titulo = trim($s['titulo'] ?? '');
                    if ($titulo === '') $titulo = 'Seccao ' . ($i + 1);
                    $tipoRaw = $s['tipo'] ?? 'texto';
                    $tipo = in_array($tipoRaw, ['ensaios', 'ficheiros']) ? $tipoRaw : 'texto';
                    $conteudo = $s['conteudo'] ?? '';
                    // Para ensaios, o conteudo e JSON - nao sanitizar como rich text
                    if ($tipo === 'texto') {
                        $conteudo = sanitizeRichText($conteudo);
                    }
                    $stmt->execute([
                        $especificacao_id,
                        $titulo,
                        $conteudo,
                        $tipo,
                        (int)($s['nivel'] ?? 1),
                        (int)($s['ordem'] ?? $i),
                    ]);
                }
            }

            $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
            $stmt->execute([$especificacao_id]);

            $db->commit();
            jsonSuccess('Seccoes guardadas com sucesso.');
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        break;

    // ===================================================================
    // GET ESPECIFICACAO
    // ===================================================================
    case 'get_especificacao':
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $id, $user);

        $espec = getEspecificacaoCompleta($db, $id);
        if (!$espec) {
            jsonError('Especificacao nao encontrada.', 404);
        }

        jsonSuccess('Especificacao carregada.', $espec);
        break;

    // ===================================================================
    // DELETE ESPECIFICACAO
    // ===================================================================
    case 'delete_especificacao':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        // Verificar se existe e quem criou
        $stmt = $db->prepare('SELECT id, criado_por FROM especificacoes WHERE id = ?');
        $stmt->execute([$id]);
        $specDel = $stmt->fetch();
        if (!$specDel) {
            jsonError('Especificacao nao encontrada.', 404);
        }

        // Permissao: criador ou admin da mesma org
        $isCriador = ((int)$specDel['criado_por'] === (int)$user['id']);
        if (!$isCriador && $user['role'] === 'user') {
            jsonError('So pode eliminar especificacoes que criou.', 403);
        }
        if (!$isCriador && in_array($user['role'], ['org_admin', 'super_admin'])) {
            $stmtOrg = $db->prepare('SELECT organizacao_id FROM especificacoes WHERE id = ?');
            $stmtOrg->execute([$id]);
            $specOrg = $stmtOrg->fetch();
            if (!$specOrg || $specOrg['organizacao_id'] != $user['org_id']) {
                jsonError('So pode eliminar especificacoes da sua organizacao.', 403);
            }
        }

        // Obter ficheiros associados para apagar do disco
        $stmt = $db->prepare('SELECT nome_servidor FROM especificacao_ficheiros WHERE especificacao_id = ?');
        $stmt->execute([$id]);
        $ficheiros = $stmt->fetchAll();

        $db->beginTransaction();
        try {
            // Apagar dados relacionados
            $db->prepare('DELETE FROM especificacao_aceitacoes WHERE especificacao_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM especificacao_tokens WHERE especificacao_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM especificacao_produtos WHERE especificacao_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM especificacao_fornecedores WHERE especificacao_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM especificacao_parametros WHERE especificacao_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM especificacao_classes WHERE especificacao_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM especificacao_defeitos WHERE especificacao_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM especificacao_seccoes WHERE especificacao_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM especificacao_ficheiros WHERE especificacao_id = ?')->execute([$id]);

            // Apagar a especificacao
            $db->prepare('DELETE FROM especificacoes WHERE id = ?')->execute([$id]);

            $db->commit();

            // Apagar ficheiros do disco (depois do commit para garantir consistencia)
            foreach ($ficheiros as $f) {
                $filePath = UPLOAD_DIR . $f['nome_servidor'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            jsonSuccess('Especificacao eliminada com sucesso.');
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        break;

    // ===================================================================
    // DUPLICATE ESPECIFICACAO
    // ===================================================================
    case 'duplicate_especificacao':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $id, $user);

        // Verificar limite de especificacoes
        if ($user['org_id']) {
            $limiteSpec = podeCriarEspecificacao($db, $user['org_id']);
            if (!$limiteSpec['ok']) {
                jsonError($limiteSpec['msg']);
            }
        }

        // Obter especificacao original completa
        $espec = getEspecificacaoCompleta($db, $id);
        if (!$espec) {
            jsonError('Especificacao nao encontrada.', 404);
        }

        $db->beginTransaction();
        try {
            // Gerar novo numero
            $novoNumero = gerarNumeroEspecificacao($db, $user['org_id']);

            // Inserir copia da especificacao
            $stmt = $db->prepare('
                INSERT INTO especificacoes (
                    numero, titulo, cliente_id, versao,
                    data_emissao, data_revisao, estado, codigo_acesso,
                    objetivo, ambito, definicao_material, regulamentacao,
                    processos, embalagem, aceitacao, arquivo_texto,
                    indemnizacao, observacoes, criado_por,
                    organizacao_id, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, NULL, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, NOW(), NOW()
                )
            ');
            $stmt->execute([
                $novoNumero,
                $espec['titulo'] . ' (copia)',
                $espec['cliente_id'],
                '1',
                date('Y-m-d'),
                'rascunho',
                gerarCodigoAcesso(),
                $espec['objetivo'],
                $espec['ambito'],
                $espec['definicao_material'],
                $espec['regulamentacao'],
                $espec['processos'],
                $espec['embalagem'],
                $espec['aceitacao'],
                $espec['arquivo_texto'],
                $espec['indemnizacao'],
                $espec['observacoes'],
                $user['id'],
                $user['org_id'],
            ]);

            $novoId = (int)$db->lastInsertId();

            // Copiar produtos e fornecedores (muitos-para-muitos)
            saveEspecProdutos($db, $novoId, $espec['produto_ids'] ?? []);
            saveEspecFornecedores($db, $novoId, $espec['fornecedor_ids'] ?? []);

            // Copiar parametros
            if (!empty($espec['parametros'])) {
                $stmt = $db->prepare('
                    INSERT INTO especificacao_parametros
                        (especificacao_id, categoria, ensaio, especificacao_valor, metodo, amostra_nqa, ordem)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                foreach ($espec['parametros'] as $p) {
                    $stmt->execute([
                        $novoId,
                        $p['categoria'],
                        $p['ensaio'],
                        $p['especificacao_valor'],
                        $p['metodo'],
                        $p['amostra_nqa'],
                        $p['ordem'],
                    ]);
                }
            }

            // Copiar classes visuais
            if (!empty($espec['classes'])) {
                $stmt = $db->prepare('
                    INSERT INTO especificacao_classes
                        (especificacao_id, classe, defeitos_max, descricao, ordem)
                    VALUES (?, ?, ?, ?, ?)
                ');
                foreach ($espec['classes'] as $c) {
                    $stmt->execute([
                        $novoId,
                        $c['classe'],
                        $c['defeitos_max'],
                        $c['descricao'],
                        $c['ordem'],
                    ]);
                }
            }

            // Copiar defeitos
            if (!empty($espec['defeitos'])) {
                $stmt = $db->prepare('
                    INSERT INTO especificacao_defeitos
                        (especificacao_id, nome, tipo, descricao, ordem)
                    VALUES (?, ?, ?, ?, ?)
                ');
                foreach ($espec['defeitos'] as $d) {
                    $stmt->execute([
                        $novoId,
                        $d['nome'],
                        $d['tipo'],
                        $d['descricao'],
                        $d['ordem'],
                    ]);
                }
            }

            // Copiar seccoes personalizadas
            if (!empty($espec['seccoes'])) {
                $stmt = $db->prepare('
                    INSERT INTO especificacao_seccoes
                        (especificacao_id, titulo, conteudo, tipo, nivel, ordem)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                foreach ($espec['seccoes'] as $s) {
                    $stmt->execute([
                        $novoId,
                        $s['titulo'],
                        $s['conteudo'],
                        $s['tipo'] ?? 'texto',
                        (int)($s['nivel'] ?? 1),
                        $s['ordem'],
                    ]);
                }
            }

            $db->commit();

            jsonSuccess('Especificacao duplicada com sucesso.', [
                'id'     => $novoId,
                'numero' => $novoNumero,
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        break;

    // ===================================================================
    // SET PASSWORD (acesso publico)
    // ===================================================================
    case 'set_password':
        $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
        $password         = $_POST['password'] ?? '';

        if ($especificacao_id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $especificacao_id, $user);

        // Verificar se a especificacao existe
        $stmt = $db->prepare('SELECT id, codigo_acesso FROM especificacoes WHERE id = ?');
        $stmt->execute([$especificacao_id]);
        $espec = $stmt->fetch();

        if (!$espec) {
            jsonError('Especificacao nao encontrada.', 404);
        }

        // Gerar codigo de acesso se nao existir
        $codigo_acesso = $espec['codigo_acesso'];
        if (empty($codigo_acesso)) {
            $codigo_acesso = gerarCodigoAcesso();
        }

        if ($password !== '') {
            // Definir ou atualizar password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('
                UPDATE especificacoes
                SET password_acesso = ?, codigo_acesso = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$hashedPassword, $codigo_acesso, $especificacao_id]);
        } else {
            // Remover password (acesso publico sem password)
            $stmt = $db->prepare('
                UPDATE especificacoes
                SET password_acesso = NULL, codigo_acesso = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$codigo_acesso, $especificacao_id]);
        }

        jsonSuccess('Configuracao de acesso atualizada com sucesso.', [
            'codigo_acesso' => $codigo_acesso,
        ]);
        break;
}
