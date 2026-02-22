<?php
/**
 * SpecLab - Cadernos de Encargos
 * API Router - Thin router that dispatches to domain-specific handlers in api/
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/versioning.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ---------------------------------------------------------------------------
// Autenticacao obrigatoria para todas as acoes
// ---------------------------------------------------------------------------
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessao expirada. Faca login novamente.']);
    exit;
}

$user = getCurrentUser();
$db   = getDB();

// ---------------------------------------------------------------------------
// Determinar a acao solicitada
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
    echo json_encode(['success' => false, 'error' => 'Acao nao especificada.']);
    exit;
}

// ---------------------------------------------------------------------------
// CSRF validation (exceto acoes de leitura e uploads com FormData)
// ---------------------------------------------------------------------------
$csrfExempt = ['get_especificacao', 'get_templates', 'get_legislacao_banco',
    'get_legislacao_log', 'get_ensaios_banco', 'get_banco_merges', 'get_ensaios_colunas', 'get_ensaios_legenda', 'get_ensaio_valores_custom', 'list_ficheiros',
    'get_parametros_tipos', 'get_parametros_tipos_all', 'get_parametros_banco'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $csrfExempt)) {
    // Para uploads multipart, token vem em $_POST; para JSON, vem no header
    if (!validateCsrf()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token de seguranca invalido. Recarregue a pagina.']);
        exit;
    }
}

// Aliases para compatibilidade frontend <-> API
$aliases = [
    'criar_especificacao' => 'save_especificacao',
    'atualizar_especificacao' => 'save_especificacao',
    'remover_ficheiro' => 'delete_ficheiro',
];
if (isset($aliases[$action])) {
    $action = $aliases[$action];
}

// ---------------------------------------------------------------------------
// Extensoes permitidas para upload
// ---------------------------------------------------------------------------
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];

// ---------------------------------------------------------------------------
// Helper: resposta JSON de sucesso
// ---------------------------------------------------------------------------
function jsonSuccess(string $message = 'Operacao realizada com sucesso.', array $data = []): void {
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

// Verificar se super admin pode modificar uma especificacao (so da propria org)
function checkSaOrgAccess(PDO $db, array $user, int $especId): void {
    if ($user['role'] !== 'super_admin' || empty($user['org_id'])) return;
    $stmt = $db->prepare('SELECT organizacao_id FROM especificacoes WHERE id = ?');
    $stmt->execute([$especId]);
    $row = $stmt->fetch();
    if ($row && $row['organizacao_id'] != $user['org_id']) {
        jsonError('Sem permissao para alterar especificacoes de outra organizacao.', 403);
    }
}

// ---------------------------------------------------------------------------
// Helper: verificar se o utilizador e admin
// ---------------------------------------------------------------------------
function requireAdminApi(array $user): void {
    if (!in_array($user['role'], ['super_admin', 'org_admin'])) {
        jsonError('Acesso negado. Apenas administradores podem realizar esta acao.', 403);
    }
}

// ---------------------------------------------------------------------------
// Helper: verificar acesso multi-tenant a uma especificacao
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
    // Produtos globais (organizacao_id = NULL) sao acessiveis a todos
    if ($orgId !== false && $orgId !== null && (int)$orgId !== (int)$user['org_id']) {
        jsonError('Acesso negado.', 403);
    }
}

// ===========================================================================
// ACTION-TO-HANDLER MAPPING
// ===========================================================================
$handlers = [
    // Especificacoes
    'save_especificacao'      => 'especificacoes',
    'save_parametros'         => 'especificacoes',
    'save_classes'            => 'especificacoes',
    'save_defeitos'           => 'especificacoes',
    'save_seccoes'            => 'especificacoes',
    'get_especificacao'       => 'especificacoes',
    'delete_especificacao'    => 'especificacoes',
    'duplicate_especificacao' => 'especificacoes',
    'set_password'            => 'especificacoes',

    // Ficheiros
    'upload_ficheiro'         => 'ficheiros',
    'delete_ficheiro'         => 'ficheiros',
    'download_ficheiro'       => 'ficheiros',
    'upload_logo_custom'      => 'ficheiros',

    // Email
    'enviar_email'            => 'email',
    'enviar_link_aceitacao'   => 'email',

    // Templates (product + spec)
    'get_templates'           => 'templates',
    'save_template'           => 'templates',
    'delete_template'         => 'templates',
    'load_product_templates'  => 'templates',
    'list_templates'          => 'templates',
    'get_template'            => 'templates',

    // Legislacao
    'get_legislacao_banco'    => 'legislacao',
    'save_legislacao_banco'   => 'legislacao',
    'delete_legislacao_banco' => 'legislacao',
    'verificar_legislacao_ai' => 'legislacao',
    'aplicar_sugestao_leg'    => 'legislacao',
    'chat_legislacao'         => 'legislacao',
    'get_legislacao_log'      => 'legislacao',

    // Ensaios
    'get_ensaios_banco'       => 'ensaios',
    'save_ensaio_banco'       => 'ensaios',
    'delete_ensaio_banco'     => 'ensaios',
    'get_banco_merges'        => 'ensaios',
    'save_banco_merges'       => 'ensaios',
    'get_ensaios_colunas'     => 'ensaios',
    'save_ensaio_coluna'      => 'ensaios',
    'delete_ensaio_coluna'    => 'ensaios',
    'save_ensaio_valor_custom'=> 'ensaios',
    'get_ensaio_valores_custom'=> 'ensaios',
    'save_colunas_legendas'   => 'ensaios',
    'save_ensaios_legenda'    => 'ensaios',
    'get_ensaios_legenda'     => 'ensaios',
    'save_ensaios_legenda_global' => 'ensaios',

    // Parametros (tipos custom + banco)
    'get_parametros_tipos'        => 'parametros',
    'get_parametros_tipos_all'    => 'parametros',
    'save_parametro_tipo'         => 'parametros',
    'delete_parametro_tipo'       => 'parametros',
    'save_parametro_tipo_config'  => 'parametros',
    'get_parametros_banco'        => 'parametros',
    'save_parametro_banco'        => 'parametros',
    'save_parametros_banco_bulk'  => 'parametros',
    'delete_parametro_banco'      => 'parametros',

    // Admin (clientes, produtos, orgs, fornecedores)
    'save_cliente'            => 'admin',
    'save_produto'            => 'admin',
    'delete_cliente'          => 'admin',
    'delete_produto'          => 'admin',
    'save_organizacao'        => 'admin',
    'upload_org_logo'         => 'admin',
    'get_fornecedor_log'      => 'admin',

    // Tokens
    'gerar_token'             => 'tokens',
    'revogar_token'           => 'tokens',
    'marcar_historico_visto'  => 'tokens',

    // AI
    'ai_assist'               => 'ai',
    'traduzir_especificacao'  => 'ai',

    // Fluxo de aprovacao
    'submeter_revisao'        => 'aprovacao',
    'aprovar_especificacao'   => 'aprovacao',
    'devolver_especificacao'  => 'aprovacao',

    // Comentarios
    'add_comentario'          => 'comentarios',
    'list_comentarios'        => 'comentarios',
    'delete_comentario'       => 'comentarios',

    // Versionamento
    'comparar_versoes'        => 'versoes',
    'publicar_versao'         => 'versoes',
    'nova_versao'             => 'versoes',
];

// ===========================================================================
// DISPATCH TO HANDLER
// ===========================================================================
try {
    $handlerFile = $handlers[$action] ?? null;

    if ($handlerFile) {
        require __DIR__ . '/api/' . $handlerFile . '.php';
    } else {
        jsonError('Acao desconhecida: ' . sanitize($action));
    }

} catch (PDOException $e) {
    // Erro de base de dados
    error_log('API DB Error [' . $action . ']: ' . $e->getMessage());
    jsonError('Erro de base de dados. Tente novamente.', 500);

} catch (Exception $e) {
    // Erro generico
    error_log('API Error [' . $action . ']: ' . $e->getMessage());
    jsonError('Erro interno. Tente novamente.', 500);
}
