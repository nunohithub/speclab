<?php
/**
 * Handler: Legislacao
 * Actions: get_legislacao_banco, save_legislacao_banco, delete_legislacao_banco,
 *          verificar_legislacao_ai, aplicar_sugestao_leg, chat_legislacao, get_legislacao_log
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // GET LEGISLACAO BANCO
    // ===================================================================
    case 'get_legislacao_banco':
        if (isSuperAdmin() && !empty($_GET['all'])) {
            $stmt = $db->query('SELECT id, legislacao_norma, rolhas_aplicaveis, resumo, link_url, ativo, organizacao_id FROM legislacao_banco ORDER BY ativo DESC, legislacao_norma');
        } elseif (isSuperAdmin()) {
            $stmt = $db->query('SELECT id, legislacao_norma, rolhas_aplicaveis, resumo, link_url FROM legislacao_banco WHERE ativo = 1 ORDER BY legislacao_norma');
        } else {
            $stmt = $db->prepare('SELECT id, legislacao_norma, rolhas_aplicaveis, resumo, link_url FROM legislacao_banco WHERE ativo = 1 AND organizacao_id = ? ORDER BY legislacao_norma');
            $stmt->execute([$user['org_id']]);
        }
        jsonSuccess('OK', ['legislacao' => $stmt->fetchAll()]);
        break;

    // ===================================================================
    // SAVE LEGISLACAO BANCO
    // ===================================================================
    case 'save_legislacao_banco':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $lid = (int)($_POST['id'] ?? 0);
        $norma = trim($_POST['legislacao_norma'] ?? '');
        $rolhas = trim($_POST['rolhas_aplicaveis'] ?? '');
        $resumo = trim($_POST['resumo'] ?? '');
        $linkUrl = trim($_POST['link_url'] ?? '');
        $ativoL = (int)($_POST['ativo'] ?? 1);
        if ($norma === '') jsonError('Introduza a legislacao/norma.');
        if ($lid > 0) {
            $stmt = $db->prepare('UPDATE legislacao_banco SET legislacao_norma = ?, rolhas_aplicaveis = ?, resumo = ?, link_url = ?, ativo = ? WHERE id = ? AND organizacao_id = ?');
            $stmt->execute([$norma, $rolhas, $resumo, $linkUrl ?: null, $ativoL, $lid, $user['org_id']]);
        } else {
            $stmt = $db->prepare('INSERT INTO legislacao_banco (legislacao_norma, rolhas_aplicaveis, resumo, link_url, ativo, organizacao_id) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$norma, $rolhas, $resumo, $linkUrl ?: null, $ativoL, $user['org_id']]);
            $lid = $db->lastInsertId();
        }
        jsonSuccess(['id' => $lid, 'msg' => 'Legislacao guardada.']);
        break;

    // ===================================================================
    // DELETE LEGISLACAO BANCO
    // ===================================================================
    case 'delete_legislacao_banco':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $lid = (int)($_POST['id'] ?? 0);
        if ($lid <= 0) jsonError('ID invalido.');
        // Log before delete
        $stmtDel = $db->prepare('SELECT * FROM legislacao_banco WHERE id = ? AND organizacao_id = ?');
        $stmtDel->execute([$lid, $user['org_id']]);
        $delData = $stmtDel->fetch();
        if ($delData) {
            $db->prepare('INSERT INTO legislacao_log (legislacao_id, acao, dados_anteriores, notas, alterado_por) VALUES (?, ?, ?, ?, ?)')
               ->execute([$lid, 'eliminada', json_encode($delData, JSON_UNESCAPED_UNICODE), 'Eliminada manualmente', $user['id']]);
        }
        $db->prepare('DELETE FROM legislacao_banco WHERE id = ? AND organizacao_id = ?')->execute([$lid, $user['org_id']]);
        jsonSuccess('Legislacao removida.');
        break;

    // ===================================================================
    // VERIFICAR LEGISLACAO COM IA
    // ===================================================================
    case 'verificar_legislacao_ai':
        if (!checkRateLimit('ai', 20)) jsonError('Limite de IA atingido (20/hora). Aguarde.');
        set_time_limit(120);
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $apiKey = getConfiguracao('openai_api_key', '');
        if (!$apiKey) jsonError('Chave OpenAI nao configurada em Configuracoes.');

        $stmtLeg = $db->prepare('SELECT id, legislacao_norma, rolhas_aplicaveis, resumo FROM legislacao_banco WHERE ativo = 1 AND organizacao_id = ? ORDER BY legislacao_norma');
        $stmtLeg->execute([$user['org_id']]);
        $legs = $stmtLeg->fetchAll();
        if (empty($legs)) jsonError('Nenhuma legislacao ativa para verificar.');

        $legJson = json_encode($legs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $systemMsg = 'Es um especialista em legislacao europeia de materiais em contacto com alimentos, com foco na industria de rolhas de cortica. Responde sempre em portugues de Portugal. Nunca inventas informacao — apenas trabalhas com factos verificaveis.';

        $userMsg = "Analisa a seguinte lista de legislacao europeia relacionada com materiais em contacto com alimentos, especificamente para a industria de rolhas de cortica.\n\n" .
            "REGRAS OBRIGATORIAS:\n" .
            "1. NAO INVENTES NADA. Nao inventes normas, numeros, datas, referencias ou informacao que nao tenhas a certeza que e factual.\n" .
            "2. Trabalha APENAS com as normas fornecidas na lista. Nao adiciones normas novas.\n" .
            "3. Para cada norma, verifica:\n" .
            "   a) Se a referencia (numero, ano, designacao) esta correta\n" .
            "   b) Se existem erros de escrita no nome, rolhas aplicaveis ou resumo\n" .
            "   c) Se a norma ainda esta em vigor\n" .
            "   d) Se foi revogada ou substituida\n" .
            "   e) Se sofreu alteracoes/amendments significativos\n" .
            "4. Corrige erros de escrita mantendo o sentido tecnico original\n" .
            "5. Mantem os campos EXATAMENTE como estao quando nao ha correcao concreta a fazer\n" .
            "6. Se nao encontraste problemas numa norma, o status e \"ok\". Nao uses \"verificar\" como escape.\n" .
            "7. Usa \"verificar\" APENAS quando tens uma razao concreta de duvida — e nas notas explica EXATAMENTE o que deve ser verificado e porque.\n\n" .
            "Responde APENAS com um array JSON valido (sem markdown, sem blocos de codigo, sem texto antes ou depois).\n" .
            "Formato por norma:\n" .
            "{\"id\": <id>, \"status\": \"ok|corrigir|atualizada|revogada|verificar\", \"legislacao_norma\": \"...\", \"rolhas_aplicaveis\": \"...\", \"resumo\": \"...\", \"notas\": \"explicacao concreta ou 'Sem alteracoes'\"}\n\n" .
            "Status:\n" .
            "- ok: Norma correta e em vigor, nada a alterar\n" .
            "- corrigir: Erros de escrita corrigidos nos campos (explicar nas notas o que mudou)\n" .
            "- atualizada: Versao mais recente existe (indicar qual nas notas)\n" .
            "- revogada: Norma revogada ou substituida (indicar por qual nas notas)\n" .
            "- verificar: Duvida concreta e real (explicar nas notas O QUE verificar e PORQUE)\n\n" .
            "Lista atual:\n{$legJson}";

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemMsg],
                ['role' => 'user', 'content' => $userMsg],
            ],
            'max_tokens' => 4000,
            'temperature' => 0.2,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) jsonError('Erro de ligacao a API: ' . $curlErr);

        $result = json_decode($response, true);
        if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
            $errMsg = $result['error']['message'] ?? 'Erro desconhecido da API OpenAI.';
            jsonError('OpenAI: ' . $errMsg);
        }

        $aiContent = trim($result['choices'][0]['message']['content']);
        if (preg_match('/\[.*\]/s', $aiContent, $m)) {
            $aiContent = $m[0];
        }
        $sugestoes = json_decode($aiContent, true);
        if (!is_array($sugestoes)) {
            jsonError('Resposta da IA invalida. Tente novamente.');
        }

        jsonSuccess('Verificacao concluida.', ['sugestoes' => $sugestoes]);
        break;

    // ===================================================================
    // APLICAR SUGESTAO DE LEGISLACAO
    // ===================================================================
    case 'aplicar_sugestao_leg':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $lid = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $norma = trim($_POST['legislacao_norma'] ?? '');
        $rolhas = trim($_POST['rolhas_aplicaveis'] ?? '');
        $resumo = trim($_POST['resumo'] ?? '');
        $notas = trim($_POST['notas'] ?? '');

        if ($lid <= 0 || $norma === '') jsonError('Dados invalidos.');

        $stmtA = $db->prepare('SELECT * FROM legislacao_banco WHERE id = ? AND organizacao_id = ?');
        $stmtA->execute([$lid, $user['org_id']]);
        $atual = $stmtA->fetch();
        if (!$atual) jsonError('Legislacao nao encontrada.');

        $dadosAnteriores = json_encode([
            'legislacao_norma' => $atual['legislacao_norma'],
            'rolhas_aplicaveis' => $atual['rolhas_aplicaveis'],
            'resumo' => $atual['resumo'],
            'ativo' => $atual['ativo']
        ], JSON_UNESCAPED_UNICODE);

        if ($status === 'revogada') {
            $db->prepare('UPDATE legislacao_banco SET ativo = 0 WHERE id = ? AND organizacao_id = ?')->execute([$lid, $user['org_id']]);
            $dadosNovos = json_encode(['ativo' => 0], JSON_UNESCAPED_UNICODE);
            $acao = 'desativada';
        } elseif ($status === 'atualizada') {
            $db->prepare('UPDATE legislacao_banco SET ativo = 0 WHERE id = ? AND organizacao_id = ?')->execute([$lid, $user['org_id']]);
            $db->prepare('INSERT INTO legislacao_banco (legislacao_norma, rolhas_aplicaveis, resumo, ativo, organizacao_id) VALUES (?, ?, ?, 1, ?)')
               ->execute([$norma, $rolhas, $resumo, $user['org_id']]);
            $newId = (int)$db->lastInsertId();
            $dadosNovos = json_encode([
                'novo_id' => $newId, 'legislacao_norma' => $norma,
                'rolhas_aplicaveis' => $rolhas, 'resumo' => $resumo
            ], JSON_UNESCAPED_UNICODE);
            $acao = 'atualizada';
        } else {
            $db->prepare('UPDATE legislacao_banco SET legislacao_norma = ?, rolhas_aplicaveis = ?, resumo = ? WHERE id = ? AND organizacao_id = ?')
               ->execute([$norma, $rolhas, $resumo, $lid, $user['org_id']]);
            $dadosNovos = json_encode([
                'legislacao_norma' => $norma, 'rolhas_aplicaveis' => $rolhas, 'resumo' => $resumo
            ], JSON_UNESCAPED_UNICODE);
            $acao = 'corrigida';
        }

        $db->prepare('INSERT INTO legislacao_log (legislacao_id, acao, dados_anteriores, dados_novos, notas, alterado_por) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$lid, $acao, $dadosAnteriores, $dadosNovos, $notas, $user['id']]);

        jsonSuccess('Alteracao aplicada.', ['acao' => $acao]);
        break;

    // ===================================================================
    // CHAT LEGISLACAO (IA)
    // ===================================================================
    case 'chat_legislacao':
        if (!checkRateLimit('ai', 20)) jsonError('Limite de IA atingido (20/hora). Aguarde.');
        set_time_limit(90);
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $pergunta = trim($_POST['pergunta'] ?? '');
        if ($pergunta === '') jsonError('Escreva uma pergunta.');

        $apiKey = getConfiguracao('openai_api_key', '');
        if (!$apiKey) jsonError('Chave OpenAI nao configurada.');

        $stmtLeg = $db->prepare('SELECT legislacao_norma, rolhas_aplicaveis, resumo, ativo FROM legislacao_banco WHERE organizacao_id = ? ORDER BY ativo DESC, legislacao_norma');
        $stmtLeg->execute([$user['org_id']]);
        $legs = $stmtLeg->fetchAll();
        $legJson = json_encode($legs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $systemMsg = "Es um especialista em legislacao europeia de materiais em contacto com alimentos, focado na industria de rolhas de cortica. Responde em portugues de Portugal, de forma clara e concisa. REGRA ABSOLUTA: NAO INVENTES NADA — nao inventes normas, numeros, datas, artigos ou informacao que nao tenhas a certeza de ser factual. Se nao sabes, diz que nao sabes.\n\nBase de legislacao atual do sistema:\n{$legJson}";

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemMsg],
                ['role' => 'user', 'content' => $pergunta],
            ],
            'max_tokens' => 2000,
            'temperature' => 0.4,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) jsonError('Erro de ligacao: ' . $curlErr);

        $result = json_decode($response, true);
        if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
            $errMsg = $result['error']['message'] ?? 'Erro desconhecido.';
            jsonError('OpenAI: ' . $errMsg);
        }

        jsonSuccess('OK', ['resposta' => $result['choices'][0]['message']['content']]);
        break;

    // ===================================================================
    // GET LEGISLACAO LOG
    // ===================================================================
    case 'get_legislacao_log':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $stmt = $db->query('SELECT l.*, u.nome as utilizador_nome FROM legislacao_log l LEFT JOIN utilizadores u ON l.alterado_por = u.id ORDER BY l.criado_em DESC LIMIT 100');
        jsonSuccess('OK', ['log' => $stmt->fetchAll()]);
        break;
}
