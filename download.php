<?php
/**
 * SpecLab - Download de Ficheiros
 */
require_once __DIR__ . '/config/database.php';

ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
session_start();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$code = $_GET['code'] ?? '';

if (!$id) {
    http_response_code(400);
    exit('ID inválido.');
}

// Obter ficheiro
$stmt = $db->prepare('SELECT f.*, e.codigo_acesso, e.password_acesso FROM especificacao_ficheiros f JOIN especificacoes e ON f.especificacao_id = e.id WHERE f.id = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('Ficheiro não encontrado.');
}

// Verificar acesso: utilizador autenticado OU código de acesso válido com sessão
$authenticated = false;

if (isset($_SESSION['user_id'])) {
    $authenticated = true;
} elseif ($code && $code === $file['codigo_acesso']) {
    $sessionKey = 'espec_access_' . $file['especificacao_id'];
    if (empty($file['password_acesso']) || (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true)) {
        $authenticated = true;
    }
}

if (!$authenticated) {
    http_response_code(403);
    exit('Acesso negado.');
}

// Verificar acesso multi-tenant
if (!$code && isset($_SESSION['user_id'])) {
    $userRole = $_SESSION['user_role'] ?? '';
    $userOrgId = $_SESSION['org_id'] ?? null;
    // Verificar org da especificação
    $stmtOrg = $db->prepare('SELECT organizacao_id FROM especificacoes WHERE id = ?');
    $stmtOrg->execute([$file['especificacao_id']]);
    $specOrgId = $stmtOrg->fetchColumn();
    if ($userRole !== 'super_admin' && $specOrgId != $userOrgId) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

// Sanitizar filename nos headers
$safeFilename = str_replace(["\r", "\n", '"'], '', $file['nome_original']);

// Enviar ficheiro
$filepath = UPLOAD_DIR . $file['nome_servidor'];
if (!file_exists($filepath)) {
    http_response_code(404);
    exit('Ficheiro não encontrado no servidor.');
}

// Registar download
$stmt = $db->prepare('INSERT INTO acessos_log (especificacao_id, ip, user_agent, tipo) VALUES (?, ?, ?, ?)');
$stmt->execute([$file['especificacao_id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'download']);

// Headers - inline para visualização em iframe, attachment para download
$inline = isset($_GET['inline']);
header('Content-Type: ' . ($file['tipo_ficheiro'] ?: 'application/octet-stream'));
if ($inline) {
    header('Content-Disposition: inline; filename="' . $safeFilename . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
}
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');

readfile($filepath);
exit;
