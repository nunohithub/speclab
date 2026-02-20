<?php
/**
 * SpecLab - Cadernos de Encargos
 * API Handler - Processa todos os pedidos AJAX do editor
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/versioning.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ---------------------------------------------------------------------------
// Autenticação obrigatória para todas as ações
// ---------------------------------------------------------------------------
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

$user = getCurrentUser();
$db   = getDB();

// ---------------------------------------------------------------------------
// Determinar a ação solicitada
// ---------------------------------------------------------------------------
// Suportar JSON body (para fetch com Content-Type: application/json)
$rawInput = file_get_contents('php://input');
$jsonBody = json_decode($rawInput, true);
if (is_array($jsonBody)) {
    $_POST = array_merge($_POST, $jsonBody);
    // Unwrap nested 'data' key (frontend sends { action: '...', data: { ...fields } })
    if (isset($_POST['data']) && is_array($_POST['data'])) {
        $_POST = array_merge($_POST, $_POST['data']);
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação não especificada.']);
    exit;
}

// ---------------------------------------------------------------------------
// CSRF validation (exceto ações de leitura e uploads com FormData)
// ---------------------------------------------------------------------------
$csrfExempt = ['get_especificacao', 'get_templates', 'get_legislacao_banco',
    'get_legislacao_log', 'get_ensaios_banco', 'get_banco_merges', 'get_ensaios_colunas', 'get_ensaios_legenda', 'get_ensaio_valores_custom', 'list_ficheiros'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $csrfExempt)) {
    // Para uploads multipart, token vem em $_POST; para JSON, vem no header
    if (!validateCsrf()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token de segurança inválido. Recarregue a página.']);
        exit;
    }
}

// Aliases para compatibilidade frontend ↔ API
$aliases = [
    'criar_especificacao' => 'save_especificacao',
    'atualizar_especificacao' => 'save_especificacao',
    'remover_ficheiro' => 'delete_ficheiro',
];
if (isset($aliases[$action])) {
    $action = $aliases[$action];
}

// ---------------------------------------------------------------------------
// Extensões permitidas para upload
// ---------------------------------------------------------------------------
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];

// ---------------------------------------------------------------------------
// Helper: resposta JSON de sucesso
// ---------------------------------------------------------------------------
function jsonSuccess(string $message = 'Operação realizada com sucesso.', array $data = []): void {
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Helper: resposta JSON de erro
// ---------------------------------------------------------------------------
function jsonError(string $error, int $httpCode = 400): void {
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar se super admin pode modificar uma especificação (só da própria org)
function checkSaOrgAccess(PDO $db, array $user, int $especId): void {
    if ($user['role'] !== 'super_admin' || empty($user['org_id'])) return;
    $stmt = $db->prepare('SELECT organizacao_id FROM especificacoes WHERE id = ?');
    $stmt->execute([$especId]);
    $row = $stmt->fetch();
    if ($row && $row['organizacao_id'] != $user['org_id']) {
        jsonError('Sem permissão para alterar especificações de outra organização.', 403);
    }
}

// ---------------------------------------------------------------------------
// Helper: verificar se o utilizador é admin
// ---------------------------------------------------------------------------
function requireAdminApi(array $user): void {
    if (!in_array($user['role'], ['super_admin', 'org_admin'])) {
        jsonError('Acesso negado. Apenas administradores podem realizar esta ação.', 403);
    }
}

// ---------------------------------------------------------------------------
// Helper: verificar acesso multi-tenant a uma especificação
// ---------------------------------------------------------------------------
function verifySpecAccess(PDO $db, int $specId, array $user): void {
    if (isSuperAdmin()) return;
    $stmt = $db->prepare('SELECT organizacao_id FROM especificacoes WHERE id = ?');
    $stmt->execute([$specId]);
    $orgId = $stmt->fetchColumn();
    if ($orgId !== false && (int)$orgId !== (int)$user['org_id']) {
        jsonError('Acesso negado.', 403);
    }
}

// ---------------------------------------------------------------------------
// Helper: verificar acesso multi-tenant a um cliente
// ---------------------------------------------------------------------------
function verifyClienteAccess(PDO $db, int $clienteId, array $user): void {
    if (isSuperAdmin()) return;
    $stmt = $db->prepare('SELECT organizacao_id FROM clientes WHERE id = ?');
    $stmt->execute([$clienteId]);
    $orgId = $stmt->fetchColumn();
    if ($orgId !== false && (int)$orgId !== (int)$user['org_id']) {
        jsonError('Acesso negado.', 403);
    }
}

// ---------------------------------------------------------------------------
// Helper: verificar acesso multi-tenant a um produto
// ---------------------------------------------------------------------------
function verifyProdutoAccess(PDO $db, int $produtoId, array $user): void {
    if (isSuperAdmin()) return;
    $stmt = $db->prepare('SELECT organizacao_id FROM produtos WHERE id = ?');
    $stmt->execute([$produtoId]);
    $orgId = $stmt->fetchColumn();
    // Produtos globais (organizacao_id = NULL) são acessíveis a todos
    if ($orgId !== false && $orgId !== null && (int)$orgId !== (int)$user['org_id']) {
        jsonError('Acesso negado.', 403);
    }
}

// ===========================================================================
// ROUTER DE AÇÕES
// ===========================================================================

try {
    switch ($action) {

        // ===================================================================
        // 1. SAVE ESPECIFICAÇÃO
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

            // Acesso público
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

            // Validação básica
            if ($titulo === '') {
                jsonError('O título é obrigatório.');
            }

            // Validar estado
            if (!in_array($estado, ['rascunho', 'em_revisao', 'ativo', 'obsoleto'])) {
                jsonError('Estado inválido.');
            }

            if ($id === 0) {
                // --- CRIAR NOVA ---
                // Verificar limite de especificações do plano
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
                        numero, titulo, tipo_doc, cliente_id, versao,
                        data_emissao, data_revisao, data_validade, estado, codigo_acesso,
                        objetivo, ambito, definicao_material, regulamentacao,
                        processos, embalagem, aceitacao, arquivo_texto,
                        indemnizacao, observacoes, config_visual, legislacao_json,
                        criado_por, organizacao_id, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, NOW(), NOW()
                    )
                ');
                $stmt->execute([
                    $numero, $titulo, $tipo_doc, $cliente_id, $versao,
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

                jsonSuccess('Especificação criada com sucesso.', [
                    'id'     => $newId,
                    'numero' => $numero,
                ]);

            } else {
                // --- ATUALIZAR EXISTENTE ---
                verifySpecAccess($db, $id, $user);

                // Tratar password de acesso público
                $passwordUpdate = '';
                $extraParams = [];
                if ($senha_publica !== '') {
                    $passwordUpdate = ', password_acesso = ?';
                    $extraParams[] = password_hash($senha_publica, PASSWORD_DEFAULT);
                }
                // Tratar código de acesso
                $codigoUpdate = '';
                if ($codigo_acesso_input !== '') {
                    $codigoUpdate = ', codigo_acesso = ?';
                    $extraParams[] = $codigo_acesso_input;
                }

                $stmt = $db->prepare('
                    UPDATE especificacoes SET
                        numero = ?, titulo = ?, tipo_doc = ?, cliente_id = ?, versao = ?,
                        data_emissao = ?, data_revisao = ?, data_validade = ?, estado = ?,
                        objetivo = ?, ambito = ?, definicao_material = ?, regulamentacao = ?,
                        processos = ?, embalagem = ?, aceitacao = ?, arquivo_texto = ?,
                        indemnizacao = ?, observacoes = ?, config_visual = ?, legislacao_json = ?,
                        updated_at = NOW()
                        ' . $passwordUpdate . $codigoUpdate . '
                    WHERE id = ?
                ');
                $executeParams = [
                    $numero, $titulo, $tipo_doc, $cliente_id, $versao,
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

                jsonSuccess('Especificação guardada com sucesso.', [
                    'id'     => $id,
                    'numero' => $numero,
                ]);
            }
            break;

        // ===================================================================
        // 2. SAVE PARÂMETROS
        // ===================================================================
        case 'save_parametros':
            $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
            if ($especificacao_id <= 0) {
                jsonError('ID da especificação inválido.');
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
                            (especificacao_id, categoria, ensaio, especificacao_valor, metodo, amostra_nqa, ordem)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    foreach ($parametros as $i => $p) {
                        $stmt->execute([
                            $especificacao_id,
                            sanitize($p['categoria'] ?? ''),
                            sanitize($p['ensaio'] ?? ''),
                            sanitize($p['especificacao_valor'] ?? ''),
                            sanitize($p['metodo'] ?? ''),
                            sanitize($p['amostra_nqa'] ?? ''),
                            (int)($p['ordem'] ?? $i),
                        ]);
                    }
                }

                // Atualizar timestamp da especificação
                $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
                $stmt->execute([$especificacao_id]);

                $db->commit();
                jsonSuccess('Parâmetros guardados com sucesso.');
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        // ===================================================================
        // 3. SAVE CLASSES VISUAIS
        // ===================================================================
        case 'save_classes':
            $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
            if ($especificacao_id <= 0) {
                jsonError('ID da especificação inválido.');
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
        // 4. SAVE DEFEITOS
        // ===================================================================
        case 'save_defeitos':
            $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
            if ($especificacao_id <= 0) {
                jsonError('ID da especificação inválido.');
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
        // 4B. SAVE SECÇÕES
        // ===================================================================
        case 'save_seccoes':
            $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
            if ($especificacao_id <= 0) {
                jsonError('ID da especificação inválido.');
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
                            (especificacao_id, titulo, conteudo, tipo, ordem)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    foreach ($seccoes as $i => $s) {
                        $titulo = trim($s['titulo'] ?? '');
                        if ($titulo === '') $titulo = 'Secção ' . ($i + 1);
                        $tipoRaw = $s['tipo'] ?? 'texto';
                        $tipo = in_array($tipoRaw, ['ensaios', 'ficheiros']) ? $tipoRaw : 'texto';
                        $conteudo = $s['conteudo'] ?? '';
                        // Para ensaios, o conteúdo é JSON - não sanitizar como rich text
                        if ($tipo === 'texto') {
                            $conteudo = sanitizeRichText($conteudo);
                        }
                        $stmt->execute([
                            $especificacao_id,
                            $titulo,
                            $conteudo,
                            $tipo,
                            (int)($s['ordem'] ?? $i),
                        ]);
                    }
                }

                $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
                $stmt->execute([$especificacao_id]);

                $db->commit();
                jsonSuccess('Secções guardadas com sucesso.');
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        // ===================================================================
        // 4C. AI ASSIST (OpenAI proxy)
        // ===================================================================
        case 'ai_assist':
            if (!checkRateLimit('ai', 20)) jsonError('Limite de IA atingido (20/hora). Aguarde.');
            $mode      = $_POST['mode'] ?? '';       // 'sugerir' ou 'melhorar'
            $prompt    = trim($_POST['prompt'] ?? '');
            $conteudo  = $_POST['conteudo'] ?? '';
            $titulo    = trim($_POST['titulo'] ?? '');

            if (!in_array($mode, ['sugerir', 'melhorar'])) {
                jsonError('Modo inválido.');
            }
            if ($prompt === '') {
                jsonError('Escreva uma indicação para a IA.');
            }

            $apiKey = getConfiguracao('openai_api_key', '');

            $systemMsg = 'És um assistente técnico especializado em cadernos de encargo e especificações técnicas para a indústria de rolhas de cortiça. Responde sempre em português de Portugal. Gera conteúdo profissional, técnico e conciso em formato HTML simples (usa <p>, <ul>, <li>, <strong>, <em> - sem <html>, <head> ou <body>).';

            if ($mode === 'sugerir') {
                $userMsg = "Secção: \"{$titulo}\"\n\nO utilizador pede:\n{$prompt}\n\nGera o conteúdo para esta secção de um caderno de encargo técnico.";
            } else {
                $userMsg = "Secção: \"{$titulo}\"\n\nConteúdo atual:\n{$conteudo}\n\nO utilizador pede para melhorar:\n{$prompt}\n\nReescreve o conteúdo melhorado mantendo o contexto técnico.";
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
                jsonError('Erro de ligação à API: ' . $curlErr);
            }

            $result = json_decode($response, true);

            if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
                $errMsg = $result['error']['message'] ?? 'Erro desconhecido da API OpenAI.';
                jsonError('OpenAI: ' . $errMsg);
            }

            $aiContent = $result['choices'][0]['message']['content'];
            jsonSuccess('Conteúdo gerado.', ['content' => $aiContent]);
            break;

        // ===================================================================
        // 4D. UPLOAD LOGO PERSONALIZADO
        // ===================================================================
        case 'upload_logo_custom':
            $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
            if ($especificacao_id <= 0) {
                jsonError('ID da especificação inválido.');
            }

            verifySpecAccess($db, $especificacao_id, $user);

            if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                jsonError('Nenhum ficheiro enviado.');
            }

            $file = $_FILES['logo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                jsonError('Formato inválido. Use PNG ou JPG.');
            }

            $logosDir = UPLOAD_DIR . 'logos/';
            if (!is_dir($logosDir)) {
                mkdir($logosDir, 0755, true);
            }

            $filename = 'logo_' . $especificacao_id . '_' . time() . '.' . $ext;
            $destPath = $logosDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                jsonError('Erro ao guardar o ficheiro.');
            }

            // Guardar na config_visual da especificação
            $stmt = $db->prepare('SELECT config_visual FROM especificacoes WHERE id = ?');
            $stmt->execute([$especificacao_id]);
            $currentConfig = $stmt->fetchColumn();
            $cv = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($cv)) $cv = [];

            // Remover logo antigo se existir
            if (!empty($cv['logo_custom']) && file_exists($logosDir . $cv['logo_custom'])) {
                unlink($logosDir . $cv['logo_custom']);
            }

            $cv['logo_custom'] = $filename;

            $stmt = $db->prepare('UPDATE especificacoes SET config_visual = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([json_encode($cv, JSON_UNESCAPED_UNICODE), $especificacao_id]);

            jsonSuccess('Logo carregado.', ['filename' => $filename]);
            break;

        // ===================================================================
        // 5. UPLOAD FICHEIRO
        // ===================================================================
        case 'upload_ficheiro':
            $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
            if ($especificacao_id <= 0) {
                jsonError('ID da especificação inválido.');
            }

            verifySpecAccess($db, $especificacao_id, $user);

            if (!isset($_FILES['ficheiro']) || $_FILES['ficheiro']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'O ficheiro excede o tamanho máximo permitido pelo servidor.',
                    UPLOAD_ERR_FORM_SIZE  => 'O ficheiro excede o tamanho máximo permitido pelo formulário.',
                    UPLOAD_ERR_PARTIAL    => 'O ficheiro foi apenas parcialmente enviado.',
                    UPLOAD_ERR_NO_FILE    => 'Nenhum ficheiro foi enviado.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário em falta.',
                    UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever o ficheiro no disco.',
                    UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão do servidor.',
                ];
                $errorCode = $_FILES['ficheiro']['error'] ?? UPLOAD_ERR_NO_FILE;
                $errorMsg  = $uploadErrors[$errorCode] ?? 'Erro desconhecido no upload.';
                jsonError($errorMsg);
            }

            $file = $_FILES['ficheiro'];

            // Validar tamanho
            if ($file['size'] > MAX_UPLOAD_SIZE) {
                jsonError('O ficheiro excede o tamanho máximo de ' . formatFileSize(MAX_UPLOAD_SIZE) . '.');
            }

            // Validar extensão
            $originalName = $file['name'];
            $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions)) {
                jsonError('Tipo de ficheiro não permitido. Extensões permitidas: ' . implode(', ', $allowedExtensions));
            }

            // Criar diretório de uploads se não existir
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            // Gerar nome único
            $uniqueName = uniqid('file_', true) . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $destPath   = UPLOAD_DIR . $uniqueName;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                jsonError('Erro ao guardar o ficheiro no servidor.');
            }

            // Optimize images (resize large photos, compress)
            $imageExts = ['jpg', 'jpeg', 'png', 'gif'];
            if (in_array($extension, $imageExts) && function_exists('imagecreatefromjpeg')) {
                $maxWidth = 2000;
                $maxHeight = 2000;
                $quality = 85;

                $imageInfo = @getimagesize($destPath);
                if ($imageInfo) {
                    $origWidth = $imageInfo[0];
                    $origHeight = $imageInfo[1];
                    $mimeType = $imageInfo['mime'];

                    if ($origWidth > $maxWidth || $origHeight > $maxHeight) {
                        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
                        $newWidth = (int)($origWidth * $ratio);
                        $newHeight = (int)($origHeight * $ratio);

                        $srcImage = null;
                        switch ($mimeType) {
                            case 'image/jpeg': $srcImage = @imagecreatefromjpeg($destPath); break;
                            case 'image/png':  $srcImage = @imagecreatefrompng($destPath); break;
                            case 'image/gif':  $srcImage = @imagecreatefromgif($destPath); break;
                        }

                        if ($srcImage) {
                            $dstImage = imagecreatetruecolor($newWidth, $newHeight);

                            // Preserve transparency for PNG
                            if ($mimeType === 'image/png') {
                                imagealphablending($dstImage, false);
                                imagesavealpha($dstImage, true);
                            }

                            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

                            switch ($mimeType) {
                                case 'image/jpeg': imagejpeg($dstImage, $destPath, $quality); break;
                                case 'image/png':  imagepng($dstImage, $destPath, 6); break;
                                case 'image/gif':  imagegif($dstImage, $destPath); break;
                            }

                            imagedestroy($srcImage);
                            imagedestroy($dstImage);

                            // Update file size after optimization
                            $file['size'] = filesize($destPath);
                        }
                    } elseif ($mimeType === 'image/jpeg' && $file['size'] > 500000) {
                        // Compress large JPEGs even if dimensions are OK
                        $srcImage = @imagecreatefromjpeg($destPath);
                        if ($srcImage) {
                            imagejpeg($srcImage, $destPath, $quality);
                            imagedestroy($srcImage);
                            $file['size'] = filesize($destPath);
                        }
                    }
                }
            }

            // Inserir na base de dados
            $stmt = $db->prepare('
                INSERT INTO especificacao_ficheiros
                    (especificacao_id, nome_original, nome_servidor, tamanho, tipo_ficheiro, uploaded_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $especificacao_id,
                $originalName,
                $uniqueName,
                $file['size'],
                $file['type'],
            ]);

            $ficheiroId = (int)$db->lastInsertId();

            // Atualizar timestamp da especificação
            $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
            $stmt->execute([$especificacao_id]);

            jsonSuccess('Ficheiro enviado com sucesso.', [
                'id'             => $ficheiroId,
                'nome_original'  => $originalName,
                'nome_servidor'  => $uniqueName,
                'tamanho'        => $file['size'],
                'tamanho_fmt'    => formatFileSize($file['size']),
                'tipo_mime'      => $file['type'],
            ]);
            break;

        // ===================================================================
        // 6. DELETE FICHEIRO
        // ===================================================================
        case 'delete_ficheiro':
            $ficheiro_id = (int)($_POST['ficheiro_id'] ?? $_POST['id'] ?? 0);
            if ($ficheiro_id <= 0) {
                jsonError('ID do ficheiro inválido.');
            }

            // Obter informação do ficheiro
            $stmt = $db->prepare('SELECT * FROM especificacao_ficheiros WHERE id = ?');
            $stmt->execute([$ficheiro_id]);
            $ficheiro = $stmt->fetch();

            if (!$ficheiro) {
                jsonError('Ficheiro não encontrado.', 404);
            }

            verifySpecAccess($db, (int)$ficheiro['especificacao_id'], $user);

            // Remover do disco
            $filePath = UPLOAD_DIR . $ficheiro['nome_servidor'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Remover da base de dados
            $stmt = $db->prepare('DELETE FROM especificacao_ficheiros WHERE id = ?');
            $stmt->execute([$ficheiro_id]);

            // Atualizar timestamp da especificação
            $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
            $stmt->execute([$ficheiro['especificacao_id']]);

            jsonSuccess('Ficheiro eliminado com sucesso.');
            break;

        // ===================================================================
        // 7. DELETE ESPECIFICAÇÃO
        // ===================================================================
        case 'delete_especificacao':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError('ID da especificação inválido.');
            }

            // Verificar se existe e quem criou
            $stmt = $db->prepare('SELECT id, criado_por FROM especificacoes WHERE id = ?');
            $stmt->execute([$id]);
            $specDel = $stmt->fetch();
            if (!$specDel) {
                jsonError('Especificação não encontrada.', 404);
            }

            // Permissão: criador ou admin da mesma org
            $isCriador = ((int)$specDel['criado_por'] === (int)$user['id']);
            if (!$isCriador && $user['role'] === 'user') {
                jsonError('Só pode eliminar especificações que criou.', 403);
            }
            if (!$isCriador && in_array($user['role'], ['org_admin', 'super_admin'])) {
                $stmtOrg = $db->prepare('SELECT organizacao_id FROM especificacoes WHERE id = ?');
                $stmtOrg->execute([$id]);
                $specOrg = $stmtOrg->fetch();
                if (!$specOrg || $specOrg['organizacao_id'] != $user['org_id']) {
                    jsonError('Só pode eliminar especificações da sua organização.', 403);
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

                // Apagar a especificação
                $db->prepare('DELETE FROM especificacoes WHERE id = ?')->execute([$id]);

                $db->commit();

                // Apagar ficheiros do disco (depois do commit para garantir consistência)
                foreach ($ficheiros as $f) {
                    $filePath = UPLOAD_DIR . $f['nome_servidor'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }

                jsonSuccess('Especificação eliminada com sucesso.');
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        // ===================================================================
        // 8. SET PASSWORD (acesso público)
        // ===================================================================
        case 'set_password':
            $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
            $password         = $_POST['password'] ?? '';

            if ($especificacao_id <= 0) {
                jsonError('ID da especificação inválido.');
            }

            verifySpecAccess($db, $especificacao_id, $user);

            // Verificar se a especificação existe
            $stmt = $db->prepare('SELECT id, codigo_acesso FROM especificacoes WHERE id = ?');
            $stmt->execute([$especificacao_id]);
            $espec = $stmt->fetch();

            if (!$espec) {
                jsonError('Especificação não encontrada.', 404);
            }

            // Gerar código de acesso se não existir
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
                // Remover password (acesso público sem password)
                $stmt = $db->prepare('
                    UPDATE especificacoes
                    SET password_acesso = NULL, codigo_acesso = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$codigo_acesso, $especificacao_id]);
            }

            jsonSuccess('Configuração de acesso atualizada com sucesso.', [
                'codigo_acesso' => $codigo_acesso,
            ]);
            break;

        // ===================================================================
        // 9. GET ESPECIFICAÇÃO (dados completos em JSON)
        // ===================================================================
        case 'get_especificacao':
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                jsonError('ID da especificação inválido.');
            }

            verifySpecAccess($db, $id, $user);

            $espec = getEspecificacaoCompleta($db, $id);
            if (!$espec) {
                jsonError('Especificação não encontrada.', 404);
            }

            jsonSuccess('Especificação carregada.', $espec);
            break;

        // ===================================================================
        // 10. SAVE CLIENTE
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
                jsonError('O nome do cliente é obrigatório.');
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
        // 11. SAVE PRODUTO
        // ===================================================================
        case 'save_produto':
            requireAdminApi($user);
            $id        = (int)($_POST['id'] ?? 0);
            $nome      = sanitize($_POST['nome'] ?? '');
            $descricao = sanitize($_POST['descricao'] ?? '');

            if ($nome === '') {
                jsonError('O nome do produto é obrigatório.');
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

                // Super admin pode alterar se é global
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
        // 12. DELETE CLIENTE (soft delete)
        // ===================================================================
        case 'delete_cliente':
            requireAdminApi($user);

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError('ID do cliente inválido.');
            }

            $stmt = $db->prepare('SELECT id FROM clientes WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                jsonError('Cliente não encontrado.', 404);
            }

            verifyClienteAccess($db, $id, $user);

            $stmt = $db->prepare('UPDATE clientes SET ativo = 0 WHERE id = ?');
            $stmt->execute([$id]);

            jsonSuccess('Cliente eliminado com sucesso.');
            break;

        // ===================================================================
        // 13. DELETE PRODUTO (soft delete)
        // ===================================================================
        case 'delete_produto':
            requireAdminApi($user);

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError('ID do produto inválido.');
            }

            $stmt = $db->prepare('SELECT id FROM produtos WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                jsonError('Produto não encontrado.', 404);
            }

            verifyProdutoAccess($db, $id, $user);

            $stmt = $db->prepare('UPDATE produtos SET ativo = 0 WHERE id = ?');
            $stmt->execute([$id]);

            jsonSuccess('Produto eliminado com sucesso.');
            break;

        // ===================================================================
        // 14. DUPLICATE ESPECIFICAÇÃO
        // ===================================================================
        case 'duplicate_especificacao':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonError('ID da especificação inválido.');
            }

            verifySpecAccess($db, $id, $user);

            // Verificar limite de especificações
            if ($user['org_id']) {
                $limiteSpec = podeCriarEspecificacao($db, $user['org_id']);
                if (!$limiteSpec['ok']) {
                    jsonError($limiteSpec['msg']);
                }
            }

            // Obter especificação original completa
            $espec = getEspecificacaoCompleta($db, $id);
            if (!$espec) {
                jsonError('Especificação não encontrada.', 404);
            }

            $db->beginTransaction();
            try {
                // Gerar novo número
                $novoNumero = gerarNumeroEspecificacao($db, $user['org_id']);

                // Inserir cópia da especificação
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
                    $espec['titulo'] . ' (cópia)',
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

                // Copiar parâmetros
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

                // Copiar secções personalizadas
                if (!empty($espec['seccoes'])) {
                    $stmt = $db->prepare('
                        INSERT INTO especificacao_seccoes
                            (especificacao_id, titulo, conteudo, tipo, ordem)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    foreach ($espec['seccoes'] as $s) {
                        $stmt->execute([
                            $novoId,
                            $s['titulo'],
                            $s['conteudo'],
                            $s['tipo'] ?? 'texto',
                            $s['ordem'],
                        ]);
                    }
                }

                $db->commit();

                jsonSuccess('Especificação duplicada com sucesso.', [
                    'id'     => $novoId,
                    'numero' => $novoNumero,
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        // ===================================================================
        // 15. ENVIAR EMAIL
        // ===================================================================
        case 'enviar_email':
            require_once __DIR__ . '/includes/email.php';

            $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
            $destinatario = sanitize($_POST['destinatario'] ?? '');
            $assunto = sanitize($_POST['assunto'] ?? '');
            $mensagem = $_POST['mensagem'] ?? '';
            $anexarPdf = !empty($_POST['anexar_pdf']);
            $incluirLink = !empty($_POST['incluir_link']);

            if ($especificacao_id <= 0) jsonError('ID da especificação inválido.');
            if (empty($destinatario) || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) jsonError('Email de destino inválido.');

            verifySpecAccess($db, $especificacao_id, $user);

            $espec = getEspecificacaoCompleta($db, $especificacao_id);
            if (!$espec) jsonError('Especificação não encontrada.', 404);

            // Gerar link público se solicitado
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
        // 16. DOWNLOAD FICHEIRO (via GET)
        // ===================================================================
        case 'download_ficheiro':
            $fid = (int)($_GET['id'] ?? 0);
            if ($fid <= 0) jsonError('ID inválido.');

            $stmt = $db->prepare('SELECT * FROM especificacao_ficheiros WHERE id = ?');
            $stmt->execute([$fid]);
            $f = $stmt->fetch();
            if (!$f) jsonError('Ficheiro não encontrado.', 404);

            // Verificar acesso multi-tenant
            verifySpecAccess($db, (int)$f['especificacao_id'], $user);

            $filepath = UPLOAD_DIR . $f['nome_servidor'];
            if (!file_exists($filepath)) jsonError('Ficheiro não encontrado no servidor.', 404);

            $safeFilename = str_replace(["\r", "\n", '"'], '', $f['nome_original']);
            header('Content-Type: ' . ($f['tipo_ficheiro'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;

        // ===================================================================
        // 17. GET PRODUCT TEMPLATES
        // ===================================================================
        case 'get_fornecedor_log':
            requireAdminApi($user);
            $fornId = (int)($_GET['fornecedor_id'] ?? 0);
            if ($fornId <= 0) jsonError('ID do fornecedor inválido.');
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

        case 'get_templates':
            $produto_id = (int)($_GET['produto_id'] ?? $_POST['produto_id'] ?? 0);
            if ($produto_id <= 0) jsonError('ID do produto inválido.');

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
        // 18. SAVE PRODUCT TEMPLATE
        // ===================================================================
        case 'save_template':
            requireAdminApi($user);
            $produto_id = (int)($_POST['produto_id'] ?? 0);
            if ($produto_id <= 0) jsonError('ID do produto inválido.');

            $categoria = sanitize($_POST['categoria'] ?? '');
            $ensaio = sanitize($_POST['ensaio'] ?? '');
            $especificacao_valor = sanitize($_POST['especificacao_valor'] ?? '');
            $metodo = sanitize($_POST['metodo'] ?? '');
            $amostra_nqa = sanitize($_POST['amostra_nqa'] ?? '');

            if (empty($ensaio)) jsonError('O nome do ensaio é obrigatório.');

            // Get next order
            $stmt = $db->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM produto_parametros_template WHERE produto_id = ?');
            $stmt->execute([$produto_id]);
            $ordem = (int)$stmt->fetchColumn();

            $stmt = $db->prepare('INSERT INTO produto_parametros_template (produto_id, categoria, ensaio, especificacao_valor, metodo, amostra_nqa, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$produto_id, $categoria, $ensaio, $especificacao_valor, $metodo, $amostra_nqa, $ordem]);

            jsonSuccess('Template adicionado.', ['id' => (int)$db->lastInsertId()]);
            break;

        // ===================================================================
        // 19. DELETE PRODUCT TEMPLATE
        // ===================================================================
        case 'delete_template':
            requireAdminApi($user);
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) jsonError('ID do template inválido.');

            // Verificar que template pertence a produto acessível
            $stmt = $db->prepare('SELECT p.organizacao_id FROM produto_parametros_template t JOIN produtos p ON t.produto_id = p.id WHERE t.id = ?');
            $stmt->execute([$id]);
            $tplOrg = $stmt->fetchColumn();
            if (!isSuperAdmin() && $tplOrg != $user['org_id'] && $tplOrg !== null) {
                jsonError('Acesso negado.', 403);
            }

            $stmt = $db->prepare('DELETE FROM produto_parametros_template WHERE id = ?');
            $stmt->execute([$id]);

            jsonSuccess('Template removido.');
            break;

        // ===================================================================
        // 20. GET PRODUCT TEMPLATES FOR EDITOR (load into spec)
        // ===================================================================
        case 'load_product_templates':
            $produto_id = (int)($_GET['produto_id'] ?? $_POST['produto_id'] ?? 0);
            if ($produto_id <= 0) jsonError('ID do produto inválido.');

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
        // 21. SAVE ORGANIZAÇÃO (super_admin only)
        // ===================================================================
        case 'save_organizacao':
            if (!isSuperAdmin()) {
                jsonError('Acesso negado. Apenas super administradores podem gerir organizações.', 403);
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
                jsonError('O nome da organização é obrigatório.');
            }

            if ($id === 0) {
                // Criar nova organização
                if ($slug === '') {
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $nome));
                    $slug = trim($slug, '-');
                }

                // Verificar slug único
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

                jsonSuccess('Organização criada com sucesso.', ['id' => $newId, 'slug' => $slug]);
            } else {
                // Atualizar organização existente
                $stmt = $db->prepare('
                    UPDATE organizacoes SET
                        nome = ?, slug = ?, nif = ?, morada = ?, telefone = ?,
                        email = ?, website = ?, cor_primaria = ?, cor_primaria_dark = ?,
                        cor_primaria_light = ?, numeracao_prefixo = ?, ativo = ?, plano = ?,
                        max_utilizadores = ?, max_especificacoes = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$nome, $slug, $nif, $morada, $telefone, $email, $website, $cor_primaria, $cor_primaria_dark, $cor_primaria_light, $numeracao_prefixo, $ativo, $plano, $max_utilizadores, $max_especificacoes, $id]);

                jsonSuccess('Organização atualizada com sucesso.', ['id' => $id]);
            }
            break;

        // ===================================================================
        // 22. UPLOAD LOGO DA ORGANIZAÇÃO (super_admin only)
        // ===================================================================
        case 'upload_org_logo':
            if (!isSuperAdmin()) {
                jsonError('Acesso negado. Apenas super administradores podem gerir organizações.', 403);
            }

            $org_id = (int)($_POST['organizacao_id'] ?? 0);
            if ($org_id <= 0) {
                jsonError('ID da organização inválido.');
            }

            // Verificar se a organização existe
            $stmt = $db->prepare('SELECT id, logo FROM organizacoes WHERE id = ?');
            $stmt->execute([$org_id]);
            $org = $stmt->fetch();
            if (!$org) {
                jsonError('Organização não encontrada.', 404);
            }

            if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                jsonError('Nenhum ficheiro enviado.');
            }

            $file = $_FILES['logo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                jsonError('Formato inválido. Use PNG ou JPG.');
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

            jsonSuccess('Logo da organização carregado.', ['filename' => $filename]);
            break;

        // ===================================================================
        // LEGISLAÇÃO - BANCO
        // ===================================================================
        case 'get_legislacao_banco':
            if (isSuperAdmin() && !empty($_GET['all'])) {
                $stmt = $db->query('SELECT id, legislacao_norma, rolhas_aplicaveis, resumo, link_url, ativo FROM legislacao_banco ORDER BY ativo DESC, legislacao_norma');
            } else {
                $stmt = $db->query('SELECT id, legislacao_norma, rolhas_aplicaveis, resumo, link_url FROM legislacao_banco WHERE ativo = 1 ORDER BY legislacao_norma');
            }
            jsonSuccess('OK', ['legislacao' => $stmt->fetchAll()]);
            break;

        case 'save_legislacao_banco':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $lid = (int)($_POST['id'] ?? 0);
            $norma = trim($_POST['legislacao_norma'] ?? '');
            $rolhas = trim($_POST['rolhas_aplicaveis'] ?? '');
            $resumo = trim($_POST['resumo'] ?? '');
            $linkUrl = trim($_POST['link_url'] ?? '');
            $ativoL = (int)($_POST['ativo'] ?? 1);
            if ($norma === '') jsonError('Introduza a legislação/norma.');
            if ($lid > 0) {
                $stmt = $db->prepare('UPDATE legislacao_banco SET legislacao_norma = ?, rolhas_aplicaveis = ?, resumo = ?, link_url = ?, ativo = ? WHERE id = ?');
                $stmt->execute([$norma, $rolhas, $resumo, $linkUrl ?: null, $ativoL, $lid]);
            } else {
                $stmt = $db->prepare('INSERT INTO legislacao_banco (legislacao_norma, rolhas_aplicaveis, resumo, link_url, ativo) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$norma, $rolhas, $resumo, $linkUrl ?: null, $ativoL]);
                $lid = $db->lastInsertId();
            }
            jsonSuccess(['id' => $lid, 'msg' => 'Legislação guardada.']);
            break;

        case 'delete_legislacao_banco':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $lid = (int)($_POST['id'] ?? 0);
            if ($lid <= 0) jsonError('ID inválido.');
            // Log before delete
            $stmtDel = $db->prepare('SELECT * FROM legislacao_banco WHERE id = ?');
            $stmtDel->execute([$lid]);
            $delData = $stmtDel->fetch();
            if ($delData) {
                $db->prepare('INSERT INTO legislacao_log (legislacao_id, acao, dados_anteriores, notas, alterado_por) VALUES (?, ?, ?, ?, ?)')
                   ->execute([$lid, 'eliminada', json_encode($delData, JSON_UNESCAPED_UNICODE), 'Eliminada manualmente', $user['id']]);
            }
            $db->prepare('DELETE FROM legislacao_banco WHERE id = ?')->execute([$lid]);
            jsonSuccess('Legislação removida.');
            break;

        // ===================================================================
        // LEGISLAÇÃO - VERIFICAÇÃO IA
        // ===================================================================
        case 'verificar_legislacao_ai':
            if (!checkRateLimit('ai', 20)) jsonError('Limite de IA atingido (20/hora). Aguarde.');
            set_time_limit(120);
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $apiKey = getConfiguracao('openai_api_key', '');
            if (!$apiKey) jsonError('Chave OpenAI não configurada em Configurações.');

            $legs = $db->query('SELECT id, legislacao_norma, rolhas_aplicaveis, resumo FROM legislacao_banco WHERE ativo = 1 ORDER BY legislacao_norma')->fetchAll();
            if (empty($legs)) jsonError('Nenhuma legislação ativa para verificar.');

            $legJson = json_encode($legs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $systemMsg = 'És um especialista em legislação europeia de materiais em contacto com alimentos, com foco na indústria de rolhas de cortiça. Responde sempre em português de Portugal. Nunca inventas informação — apenas trabalhas com factos verificáveis.';

            $userMsg = "Analisa a seguinte lista de legislação europeia relacionada com materiais em contacto com alimentos, especificamente para a indústria de rolhas de cortiça.\n\n" .
                "REGRAS OBRIGATÓRIAS:\n" .
                "1. NÃO INVENTES NADA. Não inventes normas, números, datas, referências ou informação que não tenhas a certeza que é factual.\n" .
                "2. Trabalha APENAS com as normas fornecidas na lista. Não adiciones normas novas.\n" .
                "3. Para cada norma, verifica:\n" .
                "   a) Se a referência (número, ano, designação) está correta\n" .
                "   b) Se existem erros de escrita no nome, rolhas aplicáveis ou resumo\n" .
                "   c) Se a norma ainda está em vigor\n" .
                "   d) Se foi revogada ou substituída\n" .
                "   e) Se sofreu alterações/amendments significativos\n" .
                "4. Corrige erros de escrita mantendo o sentido técnico original\n" .
                "5. Mantém os campos EXATAMENTE como estão quando não há correção concreta a fazer\n" .
                "6. Se não encontraste problemas numa norma, o status é \"ok\". Não uses \"verificar\" como escape.\n" .
                "7. Usa \"verificar\" APENAS quando tens uma razão concreta de dúvida — e nas notas explica EXATAMENTE o que deve ser verificado e porquê.\n\n" .
                "Responde APENAS com um array JSON válido (sem markdown, sem blocos de código, sem texto antes ou depois).\n" .
                "Formato por norma:\n" .
                "{\"id\": <id>, \"status\": \"ok|corrigir|atualizada|revogada|verificar\", \"legislacao_norma\": \"...\", \"rolhas_aplicaveis\": \"...\", \"resumo\": \"...\", \"notas\": \"explicação concreta ou 'Sem alterações'\"}\n\n" .
                "Status:\n" .
                "- ok: Norma correta e em vigor, nada a alterar\n" .
                "- corrigir: Erros de escrita corrigidos nos campos (explicar nas notas o que mudou)\n" .
                "- atualizada: Versão mais recente existe (indicar qual nas notas)\n" .
                "- revogada: Norma revogada ou substituída (indicar por qual nas notas)\n" .
                "- verificar: Dúvida concreta e real (explicar nas notas O QUÊ verificar e PORQUÊ)\n\n" .
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

            if ($curlErr) jsonError('Erro de ligação à API: ' . $curlErr);

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
                jsonError('Resposta da IA inválida. Tente novamente.');
            }

            jsonSuccess('Verificação concluída.', ['sugestoes' => $sugestoes]);
            break;

        case 'aplicar_sugestao_leg':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $lid = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            $norma = trim($_POST['legislacao_norma'] ?? '');
            $rolhas = trim($_POST['rolhas_aplicaveis'] ?? '');
            $resumo = trim($_POST['resumo'] ?? '');
            $notas = trim($_POST['notas'] ?? '');

            if ($lid <= 0 || $norma === '') jsonError('Dados inválidos.');

            $stmtA = $db->prepare('SELECT * FROM legislacao_banco WHERE id = ?');
            $stmtA->execute([$lid]);
            $atual = $stmtA->fetch();
            if (!$atual) jsonError('Legislação não encontrada.');

            $dadosAnteriores = json_encode([
                'legislacao_norma' => $atual['legislacao_norma'],
                'rolhas_aplicaveis' => $atual['rolhas_aplicaveis'],
                'resumo' => $atual['resumo'],
                'ativo' => $atual['ativo']
            ], JSON_UNESCAPED_UNICODE);

            if ($status === 'revogada') {
                $db->prepare('UPDATE legislacao_banco SET ativo = 0 WHERE id = ?')->execute([$lid]);
                $dadosNovos = json_encode(['ativo' => 0], JSON_UNESCAPED_UNICODE);
                $acao = 'desativada';
            } elseif ($status === 'atualizada') {
                $db->prepare('UPDATE legislacao_banco SET ativo = 0 WHERE id = ?')->execute([$lid]);
                $db->prepare('INSERT INTO legislacao_banco (legislacao_norma, rolhas_aplicaveis, resumo, ativo) VALUES (?, ?, ?, 1)')
                   ->execute([$norma, $rolhas, $resumo]);
                $newId = (int)$db->lastInsertId();
                $dadosNovos = json_encode([
                    'novo_id' => $newId, 'legislacao_norma' => $norma,
                    'rolhas_aplicaveis' => $rolhas, 'resumo' => $resumo
                ], JSON_UNESCAPED_UNICODE);
                $acao = 'atualizada';
            } else {
                $db->prepare('UPDATE legislacao_banco SET legislacao_norma = ?, rolhas_aplicaveis = ?, resumo = ? WHERE id = ?')
                   ->execute([$norma, $rolhas, $resumo, $lid]);
                $dadosNovos = json_encode([
                    'legislacao_norma' => $norma, 'rolhas_aplicaveis' => $rolhas, 'resumo' => $resumo
                ], JSON_UNESCAPED_UNICODE);
                $acao = 'corrigida';
            }

            $db->prepare('INSERT INTO legislacao_log (legislacao_id, acao, dados_anteriores, dados_novos, notas, alterado_por) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$lid, $acao, $dadosAnteriores, $dadosNovos, $notas, $user['id']]);

            jsonSuccess('Alteração aplicada.', ['acao' => $acao]);
            break;

        case 'chat_legislacao':
            if (!checkRateLimit('ai', 20)) jsonError('Limite de IA atingido (20/hora). Aguarde.');
            set_time_limit(90);
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $pergunta = trim($_POST['pergunta'] ?? '');
            if ($pergunta === '') jsonError('Escreva uma pergunta.');

            $apiKey = getConfiguracao('openai_api_key', '');
            if (!$apiKey) jsonError('Chave OpenAI não configurada.');

            $legs = $db->query('SELECT legislacao_norma, rolhas_aplicaveis, resumo, ativo FROM legislacao_banco ORDER BY ativo DESC, legislacao_norma')->fetchAll();
            $legJson = json_encode($legs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $systemMsg = "És um especialista em legislação europeia de materiais em contacto com alimentos, focado na indústria de rolhas de cortiça. Responde em português de Portugal, de forma clara e concisa. REGRA ABSOLUTA: NÃO INVENTES NADA — não inventes normas, números, datas, artigos ou informação que não tenhas a certeza de ser factual. Se não sabes, diz que não sabes.\n\nBase de legislação atual do sistema:\n{$legJson}";

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

            if ($curlErr) jsonError('Erro de ligação: ' . $curlErr);

            $result = json_decode($response, true);
            if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
                $errMsg = $result['error']['message'] ?? 'Erro desconhecido.';
                jsonError('OpenAI: ' . $errMsg);
            }

            jsonSuccess('OK', ['resposta' => $result['choices'][0]['message']['content']]);
            break;

        case 'get_legislacao_log':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $stmt = $db->query('SELECT l.*, u.nome as utilizador_nome FROM legislacao_log l LEFT JOIN utilizadores u ON l.alterado_por = u.id ORDER BY l.criado_em DESC LIMIT 100');
            jsonSuccess('OK', ['log' => $stmt->fetchAll()]);
            break;

        // ===================================================================
        // BANCO DE ENSAIOS
        // ===================================================================
        case 'get_ensaios_banco':
            if (isset($_GET['all']) && isSuperAdmin()) {
                $stmt = $db->query('SELECT * FROM ensaios_banco ORDER BY ordem, categoria, ensaio');
            } else {
                $stmt = $db->query('SELECT id, categoria, ensaio, metodo, nivel_especial, nqa, exemplo FROM ensaios_banco WHERE ativo = 1 ORDER BY ordem, categoria, ensaio');
            }
            jsonSuccess('OK', ['ensaios' => $stmt->fetchAll()]);
            break;

        case 'save_ensaio_banco':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $eid = (int)($_POST['id'] ?? 0);
            $cat = trim($_POST['categoria'] ?? '');
            $ens = trim($_POST['ensaio'] ?? '');
            $met = trim($_POST['metodo'] ?? '');
            $niv = trim($_POST['nivel_especial'] ?? '');
            $nqa = trim($_POST['nqa'] ?? '');
            $ex = trim($_POST['exemplo'] ?? '');
            $ativoE = (int)($_POST['ativo'] ?? 1);
            if (!$cat || !$ens) jsonError('Categoria e ensaio são obrigatórios.');
            if ($eid > 0) {
                $stmt = $db->prepare('UPDATE ensaios_banco SET categoria = ?, ensaio = ?, metodo = ?, nivel_especial = ?, nqa = ?, exemplo = ?, ativo = ? WHERE id = ?');
                $stmt->execute([$cat, $ens, $met, $niv, $nqa, $ex, $ativoE, $eid]);
            } else {
                $maxOrdem = $db->query('SELECT COALESCE(MAX(ordem),0)+1 FROM ensaios_banco')->fetchColumn();
                $stmt = $db->prepare('INSERT INTO ensaios_banco (categoria, ensaio, metodo, nivel_especial, nqa, exemplo, ativo, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$cat, $ens, $met, $niv, $nqa, $ex, $ativoE, $maxOrdem]);
                $eid = (int)$db->lastInsertId();
            }
            jsonSuccess('Ensaio guardado.', ['id' => $eid]);
            break;

        case 'delete_ensaio_banco':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $eid = (int)($_POST['id'] ?? 0);
            if ($eid > 0) {
                $db->prepare('DELETE FROM ensaios_banco WHERE id = ?')->execute([$eid]);
            }
            jsonSuccess('Ensaio eliminado.');
            break;

        case 'get_banco_merges':
            $stmt = $db->prepare("SELECT valor FROM configuracoes WHERE chave = 'banco_ensaios_merges'");
            $stmt->execute();
            $val = json_decode($stmt->fetchColumn() ?: '[]', true);
            // Compat: pode ser array legado (só merges) ou objeto {merges, colWidths}
            if (isset($val['merges'])) {
                jsonSuccess('OK', ['merges' => $val]);
            } else {
                jsonSuccess('OK', ['merges' => is_array($val) ? $val : []]);
            }
            break;

        case 'save_banco_merges':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $merges = json_encode($jsonBody['merges'] ?? []);
            $stmt = $db->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'banco_ensaios_merges'");
            $stmt->execute([$merges]);
            jsonSuccess('Merges guardados.');
            break;

        // ===================================================================
        // COLUNAS CONFIGURÁVEIS DO BANCO DE ENSAIOS
        // ===================================================================
        case 'get_ensaios_colunas':
            $orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : ($user['org_id'] ?? 0);
            $stmt = $db->query('SELECT c.*, GROUP_CONCAT(DISTINCT co.org_id) as org_ids FROM ensaios_colunas c LEFT JOIN ensaios_colunas_org co ON co.coluna_id = c.id GROUP BY c.id ORDER BY c.ordem');
            $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Carregar nomes custom da org
            $nomesCustom = [];
            if ($orgId) {
                $stmtNc = $db->prepare('SELECT coluna_id, nome_custom FROM ensaios_colunas_org WHERE org_id = ? AND nome_custom IS NOT NULL AND nome_custom != ""');
                $stmtNc->execute([$orgId]);
                foreach ($stmtNc->fetchAll(PDO::FETCH_ASSOC) as $nc) {
                    $nomesCustom[$nc['coluna_id']] = $nc['nome_custom'];
                }
            }
            // Se não é super admin, filtrar só as visíveis para a org
            if (!isSuperAdmin() && $orgId) {
                $colunas = array_values(array_filter($colunas, function($c) use ($orgId) {
                    if ($c['todas_orgs']) return $c['ativo'];
                    $ids = $c['org_ids'] ? explode(',', $c['org_ids']) : [];
                    return $c['ativo'] && in_array($orgId, $ids);
                }));
            }
            // Aplicar nomes custom
            foreach ($colunas as &$c) {
                $c['nome_custom'] = $nomesCustom[$c['id']] ?? '';
                $c['nome_display'] = $c['nome_custom'] ?: $c['nome'];
            }
            unset($c);
            jsonSuccess('OK', ['colunas' => $colunas]);
            break;

        case 'save_ensaio_coluna':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $cid = (int)($jsonBody['id'] ?? 0);
            $nome = trim($jsonBody['nome'] ?? '');
            $tipo = $jsonBody['tipo'] ?? 'texto';
            $ordem = (int)($jsonBody['ordem'] ?? 0);
            $todasOrgs = (int)($jsonBody['todas_orgs'] ?? 1);
            $ativo = (int)($jsonBody['ativo'] ?? 1);
            $orgIds = $jsonBody['org_ids'] ?? [];
            if (!$nome) jsonError('Nome da coluna é obrigatório.');
            if ($cid > 0) {
                // Não permitir alterar campo_fixo
                $stmt = $db->prepare('UPDATE ensaios_colunas SET nome = ?, tipo = ?, ordem = ?, todas_orgs = ?, ativo = ? WHERE id = ?');
                $stmt->execute([$nome, $tipo, $ordem, $todasOrgs, $ativo, $cid]);
            } else {
                $stmt = $db->prepare('INSERT INTO ensaios_colunas (nome, campo_fixo, tipo, ordem, todas_orgs, ativo) VALUES (?, NULL, ?, ?, ?, ?)');
                $stmt->execute([$nome, $tipo, $ordem, $todasOrgs, $ativo]);
                $cid = (int)$db->lastInsertId();
            }
            // Gerir orgs associadas
            $db->prepare('DELETE FROM ensaios_colunas_org WHERE coluna_id = ?')->execute([$cid]);
            if (!$todasOrgs && !empty($orgIds)) {
                $ins = $db->prepare('INSERT INTO ensaios_colunas_org (coluna_id, org_id) VALUES (?, ?)');
                foreach ($orgIds as $oid) $ins->execute([$cid, (int)$oid]);
            }
            jsonSuccess('Coluna guardada.', ['id' => $cid]);
            break;

        case 'delete_ensaio_coluna':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $cid = (int)($jsonBody['id'] ?? 0);
            if ($cid <= 0) jsonError('ID inválido.');
            // Não permitir eliminar colunas fixas
            $fixo = $db->prepare('SELECT campo_fixo FROM ensaios_colunas WHERE id = ?');
            $fixo->execute([$cid]);
            if ($fixo->fetchColumn()) jsonError('Não é possível eliminar colunas fixas. Pode desativá-las.');
            $db->prepare('DELETE FROM ensaios_colunas WHERE id = ?')->execute([$cid]);
            jsonSuccess('Coluna eliminada.');
            break;

        case 'save_ensaio_valor_custom':
            if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
            $ensaioId = (int)($jsonBody['ensaio_id'] ?? 0);
            $colunaId = (int)($jsonBody['coluna_id'] ?? 0);
            $valor = trim($jsonBody['valor'] ?? '');
            if (!$ensaioId || !$colunaId) jsonError('Dados inválidos.');
            $stmt = $db->prepare('INSERT INTO ensaios_valores_custom (ensaio_id, coluna_id, valor) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
            $stmt->execute([$ensaioId, $colunaId, $valor]);
            jsonSuccess('Valor guardado.');
            break;

        case 'get_ensaio_valores_custom':
            $stmt = $db->query('SELECT ensaio_id, coluna_id, valor FROM ensaios_valores_custom');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $r) $map[$r['ensaio_id']][$r['coluna_id']] = $r['valor'];
            jsonSuccess('OK', ['valores' => $map]);
            break;

        case 'save_colunas_legendas':
            if ($user['role'] !== 'super_admin' && $user['role'] !== 'org_admin') jsonError('Acesso negado.', 403);
            $orgId = (int)($user['org_id'] ?? 0);
            if (!$orgId) jsonError('Organização não definida.');
            $legendas = $jsonBody['legendas'] ?? [];
            foreach ($legendas as $leg) {
                $colId = (int)($leg['coluna_id'] ?? 0);
                $nomeCustom = trim($leg['nome_custom'] ?? '');
                if (!$colId) continue;
                // Upsert: se já existe registo para esta org+coluna, atualizar; senão inserir
                $exists = $db->prepare('SELECT COUNT(*) FROM ensaios_colunas_org WHERE coluna_id = ? AND org_id = ?');
                $exists->execute([$colId, $orgId]);
                if ($exists->fetchColumn() > 0) {
                    $db->prepare('UPDATE ensaios_colunas_org SET nome_custom = ? WHERE coluna_id = ? AND org_id = ?')->execute([$nomeCustom ?: null, $colId, $orgId]);
                } else {
                    $db->prepare('INSERT INTO ensaios_colunas_org (coluna_id, org_id, nome_custom) VALUES (?, ?, ?)')->execute([$colId, $orgId, $nomeCustom ?: null]);
                }
            }
            jsonSuccess('Legendas guardadas.');
            break;

        case 'save_ensaios_legenda':
            if ($user['role'] !== 'super_admin' && $user['role'] !== 'org_admin') jsonError('Acesso negado.', 403);
            $orgId = (int)($jsonBody['org_id'] ?? $user['org_id'] ?? 0);
            if ($user['role'] === 'org_admin') $orgId = (int)$user['org_id'];
            if (!$orgId) jsonError('Organização não definida.');
            $legenda = trim($jsonBody['legenda'] ?? '');
            $tamanho = (int)($jsonBody['tamanho'] ?? 9);
            if ($tamanho < 6) $tamanho = 6;
            if ($tamanho > 14) $tamanho = 14;
            $db->prepare('UPDATE organizacoes SET ensaios_legenda = ?, ensaios_legenda_tamanho = ? WHERE id = ?')->execute([$legenda ?: null, $tamanho, $orgId]);
            jsonSuccess('Legenda guardada.');
            break;

        case 'get_ensaios_legenda':
            if (isset($_GET['global']) && $_GET['global'] == '1') {
                $stmt = $db->prepare("SELECT valor FROM configuracoes WHERE chave = 'ensaios_legenda_global'");
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $gData = $row ? json_decode($row['valor'], true) : [];
                jsonSuccess('OK', ['legenda' => $gData['legenda'] ?? '', 'tamanho' => (int)($gData['tamanho'] ?? 9)]);
            }
            $orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : ($user['org_id'] ?? 0);
            if (!$orgId) jsonSuccess('OK', ['legenda' => '', 'tamanho' => 9]);
            $stmt = $db->prepare('SELECT ensaios_legenda, ensaios_legenda_tamanho FROM organizacoes WHERE id = ?');
            $stmt->execute([$orgId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            jsonSuccess('OK', ['legenda' => $row['ensaios_legenda'] ?? '', 'tamanho' => (int)($row['ensaios_legenda_tamanho'] ?? 9)]);
            break;

        case 'save_ensaios_legenda_global':
            if ($user['role'] !== 'super_admin') jsonError('Acesso negado.', 403);
            $legenda = trim($jsonBody['legenda'] ?? '');
            $tamanho = (int)($jsonBody['tamanho'] ?? 9);
            if ($tamanho < 6) $tamanho = 6;
            if ($tamanho > 14) $tamanho = 14;
            $valor = json_encode(['legenda' => $legenda, 'tamanho' => $tamanho]);
            $stmt = $db->prepare("SELECT id FROM configuracoes WHERE chave = 'ensaios_legenda_global'");
            $stmt->execute();
            if ($stmt->fetch()) {
                $db->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'ensaios_legenda_global'")->execute([$valor]);
            } else {
                $db->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ensaios_legenda_global', ?)")->execute([$valor]);
            }
            jsonSuccess('Legenda global guardada.');
            break;

        // ===================================================================
        // FLUXO DE APROVAÇÃO
        // ===================================================================
        case 'submeter_revisao':
            $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) jsonError('ID inválido.');
            checkSaOrgAccess($db, $user, $id);
            $stmt = $db->prepare('SELECT estado, versao_bloqueada FROM especificacoes WHERE id = ?');
            $stmt->execute([$id]);
            $esp = $stmt->fetch();
            if (!$esp) jsonError('Especificação não encontrada.');
            if ($esp['versao_bloqueada']) jsonError('Versão já bloqueada.');
            if ($esp['estado'] !== 'rascunho') jsonError('Só especificações em rascunho podem ser submetidas.');
            $db->prepare('UPDATE especificacoes SET estado = ? WHERE id = ?')->execute(['em_revisao', $id]);
            jsonSuccess('Submetida para revisão.');
            break;

        case 'aprovar_especificacao':
            $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) jsonError('ID inválido.');
            requireAdminApi($user);
            checkSaOrgAccess($db, $user, $id);
            $stmt = $db->prepare('SELECT estado, versao_bloqueada FROM especificacoes WHERE id = ?');
            $stmt->execute([$id]);
            $esp = $stmt->fetch();
            if (!$esp) jsonError('Especificação não encontrada.');
            if ($esp['estado'] !== 'em_revisao') jsonError('Só especificações em revisão podem ser aprovadas.');
            $db->prepare('UPDATE especificacoes SET estado = ?, aprovado_por = ?, aprovado_em = NOW(), motivo_devolucao = NULL WHERE id = ?')
               ->execute(['ativo', $user['id'], $id]);
            jsonSuccess('Especificação aprovada.');
            break;

        case 'devolver_especificacao':
            $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) jsonError('ID inválido.');
            requireAdminApi($user);
            checkSaOrgAccess($db, $user, $id);
            $motivo = sanitize($jsonBody['motivo'] ?? $_POST['motivo'] ?? '');
            if (!$motivo) jsonError('Indique o motivo da devolução.');
            $stmt = $db->prepare('SELECT estado FROM especificacoes WHERE id = ?');
            $stmt->execute([$id]);
            $esp = $stmt->fetch();
            if (!$esp || $esp['estado'] !== 'em_revisao') jsonError('Só especificações em revisão podem ser devolvidas.');
            $db->prepare('UPDATE especificacoes SET estado = ?, motivo_devolucao = ?, aprovado_por = NULL, aprovado_em = NULL WHERE id = ?')
               ->execute(['rascunho', $motivo, $id]);
            jsonSuccess('Especificação devolvida ao autor.');
            break;

        // ===================================================================
        // VERSIONAMENTO
        // ===================================================================
        case 'publicar_versao':
            $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) jsonError('ID inválido.');
            checkSaOrgAccess($db, $user, $id);
            $notas = sanitize($jsonBody['notas'] ?? $_POST['notas'] ?? '');
            if (!publicarVersao($db, $id, $user['id'], $notas ?: null)) {
                jsonError('Não foi possível publicar. Versão já bloqueada ou não encontrada.');
            }
            jsonSuccess('Versão publicada.');
            break;

        case 'nova_versao':
            $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) jsonError('ID inválido.');
            checkSaOrgAccess($db, $user, $id);
            $novoId = criarNovaVersao($db, $id, $user['id']);
            if (!$novoId) jsonError('Erro ao criar nova versão.');
            echo json_encode(['success' => true, 'novo_id' => $novoId]);
            exit;

        case 'gerar_token':
            $especId = (int)($jsonBody['especificacao_id'] ?? $_POST['especificacao_id'] ?? 0);
            if ($especId > 0) verifySpecAccess($db, $especId, $user);
            $nome = sanitize($jsonBody['nome'] ?? $_POST['nome'] ?? '');
            $email = sanitize($jsonBody['email'] ?? $_POST['email'] ?? '');
            $tipo = sanitize($jsonBody['tipo'] ?? $_POST['tipo'] ?? 'outro');
            if (!$especId || !$nome || !$email) jsonError('Dados incompletos.');
            $token = gerarTokenDestinatario($db, $especId, $user['id'], $nome, $email, $tipo);
            echo json_encode(['success' => true, 'token' => $token]);
            exit;

        case 'revogar_token':
            $tokenId = (int)($jsonBody['token_id'] ?? $_POST['token_id'] ?? 0);
            if (!$tokenId) jsonError('Token inválido.');
            // Verificar acesso multi-tenant
            $stmtTk = $db->prepare('SELECT especificacao_id FROM especificacao_tokens WHERE id = ?');
            $stmtTk->execute([$tokenId]);
            $tkRow = $stmtTk->fetch();
            if (!$tkRow) jsonError('Token não encontrado.', 404);
            verifySpecAccess($db, (int)$tkRow['especificacao_id'], $user);
            $db->prepare('UPDATE especificacao_tokens SET ativo = 0 WHERE id = ?')->execute([$tokenId]);
            jsonSuccess('Token revogado.');
            break;

        case 'enviar_link_aceitacao':
            require_once __DIR__ . '/includes/email.php';
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

        // AÇÃO DESCONHECIDA
        // ===================================================================
        default:
            jsonError('Ação desconhecida: ' . sanitize($action));
            break;
    }

} catch (PDOException $e) {
    // Erro de base de dados
    error_log('API DB Error [' . $action . ']: ' . $e->getMessage());
    jsonError('Erro de base de dados. Tente novamente.', 500);

} catch (Exception $e) {
    // Erro genérico
    error_log('API Error [' . $action . ']: ' . $e->getMessage());
    jsonError('Erro interno. Tente novamente.', 500);
}
