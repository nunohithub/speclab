<?php
/**
 * SpecLab - Download de Ficheiros
 */
require_once __DIR__ . '/config/database.php';

ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);
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
    header('Content-Disposition: inline; filename="' . $file['nome_original'] . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $file['nome_original'] . '"');
}
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');

readfile($filepath);
exit;
