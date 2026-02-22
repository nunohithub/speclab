<?php
/**
 * Handler: AI
 * Actions: ai_assist, traduzir_especificacao
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // AI ASSIST (OpenAI proxy)
    // ===================================================================
    case 'ai_assist':
        if (!checkRateLimit('ai', 20)) jsonError('Limite de IA atingido (20/hora). Aguarde.');
        $mode      = $_POST['mode'] ?? '';       // 'sugerir' ou 'melhorar'
        $prompt    = trim($_POST['prompt'] ?? '');
        $conteudo  = $_POST['conteudo'] ?? '';
        $titulo    = trim($_POST['titulo'] ?? '');

        if (!in_array($mode, ['sugerir', 'melhorar'])) {
            jsonError('Modo invalido.');
        }
        if ($prompt === '') {
            jsonError('Escreva uma indicacao para a IA.');
        }

        $apiKey = getConfiguracao('openai_api_key', '');

        $systemMsg = 'Es um assistente tecnico especializado em cadernos de encargo e especificacoes tecnicas para a industria de rolhas de cortica. Responde sempre em portugues de Portugal. Gera conteudo profissional, tecnico e conciso em formato HTML simples (usa <p>, <ul>, <li>, <strong>, <em> - sem <html>, <head> ou <body>).';

        if ($mode === 'sugerir') {
            $userMsg = "Seccao: \"{$titulo}\"\n\nO utilizador pede:\n{$prompt}\n\nGera o conteudo para esta seccao de um caderno de encargo tecnico.";
        } else {
            $userMsg = "Seccao: \"{$titulo}\"\n\nConteudo atual:\n{$conteudo}\n\nO utilizador pede para melhorar:\n{$prompt}\n\nReescreve o conteudo melhorado mantendo o contexto tecnico.";
        }

        $payload = [
            'model'    => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemMsg],
                ['role' => 'user',   'content' => $userMsg],
            ],
            'max_tokens'  => 2000,
            'temperature' => 0.7,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            jsonError('Erro de ligacao a API: ' . $curlErr);
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
            $errMsg = $result['error']['message'] ?? 'Erro desconhecido da API OpenAI.';
            jsonError('OpenAI: ' . $errMsg);
        }

        $aiContent = $result['choices'][0]['message']['content'];
        jsonSuccess('Conteudo gerado.', ['content' => $aiContent]);
        break;

    // ===================================================================
    // TRADUZIR ESPECIFICACAO
    // ===================================================================
    case 'traduzir_especificacao':
        if (!checkRateLimit('ai', 20)) jsonError('Limite de IA atingido (20/hora). Aguarde.');
        $especId = (int)($jsonBody['especificacao_id'] ?? 0);
        $idiomaDest = strtolower(trim($jsonBody['idioma_destino'] ?? ''));
        $idiomasValidos = ['pt' => 'Portugues', 'en' => 'English', 'es' => 'Espanol', 'fr' => 'Francais', 'de' => 'Deutsch', 'it' => 'Italiano'];
        if (!isset($idiomasValidos[$idiomaDest])) jsonError('Idioma invalido.');
        if ($especId <= 0) jsonError('ID invalido.');
        checkSaOrgAccess($db, $user, $especId);

        $apiKey = getConfiguracao('openai_api_key', '');
        if (!$apiKey) jsonError('Chave OpenAI nao configurada em Configuracoes.');

        // Carregar spec original
        $stmt = $db->prepare('SELECT * FROM especificacoes WHERE id = ?');
        $stmt->execute([$especId]);
        $orig = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$orig) jsonError('Especificacao nao encontrada.');

        // Contexto do produto e cliente para o prompt IA
        $ctxProduto = '';
        if (!empty($orig['produto_id'])) {
            $stmtP = $db->prepare('SELECT nome FROM produtos WHERE id = ?');
            $stmtP->execute([$orig['produto_id']]);
            $ctxProduto = $stmtP->fetchColumn() ?: '';
        }
        $ctxCliente = '';
        if (!empty($orig['cliente_id'])) {
            $stmtC = $db->prepare('SELECT nome FROM clientes WHERE id = ?');
            $stmtC->execute([$orig['cliente_id']]);
            $ctxCliente = $stmtC->fetchColumn() ?: '';
        }
        $ctxTipoDoc = $orig['tipo_doc'] ?? 'Caderno de Encargos';
        $ctxTitulo = $orig['titulo'] ?? '';

        // Carregar seccoes
        $stmt = $db->prepare('SELECT id, titulo, conteudo, tipo, nivel, ordem FROM especificacao_seccoes WHERE especificacao_id = ? ORDER BY ordem');
        $stmt->execute([$especId]);
        $seccoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Construir texto para traduzir
        $paraTraduzir = ['titulo' => $orig['titulo']];
        $camposTexto = ['objetivo', 'ambito', 'definicao_material', 'regulamentacao', 'processos', 'embalagem', 'aceitacao', 'arquivo_texto', 'indemnizacao', 'observacoes'];
        foreach ($camposTexto as $campo) {
            if (!empty($orig[$campo])) $paraTraduzir['campo_' . $campo] = $orig[$campo];
        }
        foreach ($seccoes as $i => $sec) {
            $paraTraduzir['sec_' . $i . '_titulo'] = $sec['titulo'];
            if ($sec['tipo'] !== 'ensaios' && !empty($sec['conteudo'])) {
                $paraTraduzir['sec_' . $i . '_conteudo'] = $sec['conteudo'];
            }
        }

        $nomeLang = $idiomasValidos[$idiomaDest];
        $ctxLines = "DOCUMENT CONTEXT (use this to choose the correct technical terminology):";
        if ($ctxTitulo) $ctxLines .= "\n- Title: {$ctxTitulo}";
        if ($ctxProduto) $ctxLines .= "\n- Product: {$ctxProduto}";
        if ($ctxCliente) $ctxLines .= "\n- Client: {$ctxCliente}";
        $ctxLines .= "\n- Document type: {$ctxTipoDoc}";

        $systemMsg = <<<PROMPT
You are an expert technical translator for industrial product specification documents.

Target language: {$nomeLang}.

{$ctxLines}

STRICT RULES:
1. FAITHFUL TRANSLATION — translate the exact meaning. Never add, remove, paraphrase, or invent content.
2. TECHNICAL ACCURACY — identify the industry/sector from the document context above and use the correct domain-specific terminology in the target language. Adapt vocabulary to the product type (e.g., cork stoppers, packaging, food, cosmetics, etc.).
3. DO NOT TRANSLATE — keep these exactly as they are: ISO/EN/NP standard references (e.g., "iso 9727-1"), unit abbreviations (mm, %, kg/m3, daN, bar), code references (It04, S-4), proper nouns, brand names, product codes, NQA/NEI values, chemical formulas.
4. PRESERVE HTML — keep all HTML tags, attributes, and structure exactly intact (<b>, <ul>, <li>, <br>, <table>, etc.).
5. FORMAL REGISTER — use formal language appropriate for contractual/normative technical documents.
6. Return ONLY valid JSON with the exact same keys. No explanations, no markdown.
PROMPT;
        $userMsg = "Translate the following JSON values (not keys) to {$nomeLang}. Return ONLY the JSON object with translated values:\n\n" . json_encode($paraTraduzir, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemMsg],
                ['role' => 'user', 'content' => $userMsg],
            ],
            'max_tokens' => 4000,
            'temperature' => 0.3,
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

        $aiText = $result['choices'][0]['message']['content'];
        // Limpar markdown code blocks se presentes
        $aiText = preg_replace('/^```json\s*/i', '', trim($aiText));
        $aiText = preg_replace('/\s*```$/', '', $aiText);
        $traduzido = json_decode($aiText, true);
        if (!$traduzido || !isset($traduzido['titulo'])) jsonError('A IA devolveu formato invalido. Tente novamente.');

        // Criar nova spec como clone traduzido
        $novoNumero = gerarNumeroEspecificacao($db, $orig['organizacao_id']);
        $db->beginTransaction();
        try {
            $novoCodigo = gerarCodigoAcesso();
            $db->prepare('INSERT INTO especificacoes
                (numero, titulo, idioma, tipo_doc, produto_id, cliente_id, fornecedor_id, versao, versao_numero,
                 versao_bloqueada, data_emissao, data_validade, estado,
                 objetivo, ambito, definicao_material, regulamentacao, processos, embalagem,
                 aceitacao, arquivo_texto, indemnizacao, observacoes, config_visual, legislacao_json,
                 template_pdf, assinatura_nome, pdf_protegido, codigo_acesso, criado_por, organizacao_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, CURDATE(), ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $novoNumero,
                    sanitize($traduzido['titulo'] ?? $orig['titulo']),
                    $idiomaDest,
                    $orig['tipo_doc'],
                    $orig['produto_id'],
                    $orig['cliente_id'],
                    $orig['fornecedor_id'],
                    '1.0',
                    $orig['data_validade'],
                    'rascunho',
                    $traduzido['campo_objetivo'] ?? $orig['objetivo'],
                    $traduzido['campo_ambito'] ?? $orig['ambito'],
                    $traduzido['campo_definicao_material'] ?? $orig['definicao_material'],
                    $traduzido['campo_regulamentacao'] ?? $orig['regulamentacao'],
                    $traduzido['campo_processos'] ?? $orig['processos'],
                    $traduzido['campo_embalagem'] ?? $orig['embalagem'],
                    $traduzido['campo_aceitacao'] ?? $orig['aceitacao'],
                    $traduzido['campo_arquivo_texto'] ?? $orig['arquivo_texto'],
                    $traduzido['campo_indemnizacao'] ?? $orig['indemnizacao'],
                    $traduzido['campo_observacoes'] ?? $orig['observacoes'],
                    $orig['config_visual'],
                    $orig['legislacao_json'],
                    $orig['template_pdf'],
                    $orig['assinatura_nome'],
                    $orig['pdf_protegido'],
                    $novoCodigo,
                    $user['id'],
                    $orig['organizacao_id'],
                ]);

            $novoId = (int)$db->lastInsertId();

            // Clonar seccoes com traducao
            foreach ($seccoes as $i => $sec) {
                $tituloTrad = $traduzido['sec_' . $i . '_titulo'] ?? $sec['titulo'];
                $conteudoTrad = $sec['conteudo'];
                if ($sec['tipo'] !== 'ensaios' && isset($traduzido['sec_' . $i . '_conteudo'])) {
                    $conteudoTrad = $traduzido['sec_' . $i . '_conteudo'];
                }
                $db->prepare('INSERT INTO especificacao_seccoes (especificacao_id, titulo, conteudo, tipo, nivel, ordem) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$novoId, sanitize($tituloTrad), $conteudoTrad, $sec['tipo'], (int)($sec['nivel'] ?? 1), $sec['ordem']]);
            }

            // Clonar parametros, classes, defeitos, produtos, fornecedores
            $cols = getColumnList($db, 'especificacao_parametros', ['id', 'especificacao_id']);
            if ($cols) {
                $db->prepare("INSERT INTO especificacao_parametros (especificacao_id, $cols) SELECT ?, $cols FROM especificacao_parametros WHERE especificacao_id = ?")
                    ->execute([$novoId, $especId]);
            }
            $db->prepare('INSERT INTO especificacao_classes (especificacao_id, classe, defeitos_max, descricao, ordem) SELECT ?, classe, defeitos_max, descricao, ordem FROM especificacao_classes WHERE especificacao_id = ?')
                ->execute([$novoId, $especId]);
            $db->prepare('INSERT INTO especificacao_defeitos (especificacao_id, nome, tipo, descricao, ordem) SELECT ?, nome, tipo, descricao, ordem FROM especificacao_defeitos WHERE especificacao_id = ?')
                ->execute([$novoId, $especId]);
            $db->prepare('INSERT INTO especificacao_produtos (especificacao_id, produto_id) SELECT ?, produto_id FROM especificacao_produtos WHERE especificacao_id = ?')
                ->execute([$novoId, $especId]);
            $db->prepare('INSERT INTO especificacao_fornecedores (especificacao_id, fornecedor_id) SELECT ?, fornecedor_id FROM especificacao_fornecedores WHERE especificacao_id = ?')
                ->execute([$novoId, $especId]);

            $db->commit();
            jsonSuccess('Traducao criada.', ['nova_id' => $novoId]);
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Erro traducao: ' . $e->getMessage());
            jsonError('Erro ao criar especificacao traduzida.');
        }
        break;
}
