<?php
/**
 * SpecLab - Cadernos de Encargos
 * Autenticação, autorização e funções auxiliares
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 28800);
    ini_set('session.cookie_lifetime', 28800);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// =============================================
// RATE LIMITING (sessão)
// =============================================

function checkRateLimit(string $key, int $maxPerHour = 20): bool {
    $now = time();
    $sessionKey = 'rl_' . $key;
    if (!isset($_SESSION[$sessionKey])) $_SESSION[$sessionKey] = [];
    // Limpar entradas com mais de 1h
    $_SESSION[$sessionKey] = array_filter($_SESSION[$sessionKey], function($t) use ($now) { return ($now - $t) < 3600; });
    if (count($_SESSION[$sessionKey]) >= $maxPerHour) return false;
    $_SESSION[$sessionKey][] = $now;
    return true;
}

// =============================================
// CSRF PROTECTION
// =============================================

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrf(): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    return $token !== '' && hash_equals(getCsrfToken(), $token);
}

// =============================================
// AUTENTICAÇÃO
// =============================================

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_PATH . '/index.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], ['super_admin', 'org_admin'])) {
        header('Location: ' . BASE_PATH . '/dashboard.php');
        exit;
    }
}

function requireSuperAdmin(): void {
    requireLogin();
    if ($_SESSION['user_role'] !== 'super_admin') {
        header('Location: ' . BASE_PATH . '/dashboard.php');
        exit;
    }
}

function isSuperAdmin(): bool {
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'super_admin';
}

function isOrgAdmin(): bool {
    return isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['super_admin', 'org_admin']);
}

// =============================================
// SESSÃO DO UTILIZADOR
// =============================================

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'nome' => $_SESSION['user_nome'],
        'username' => $_SESSION['user_username'],
        'role' => $_SESSION['user_role'],
        'org_id' => $_SESSION['org_id'] ?? null,
        'org_nome' => $_SESSION['org_nome'] ?? '',
        'org_logo' => $_SESSION['org_logo'] ?? '',
        'org_cor' => $_SESSION['org_cor'] ?? '#2596be',
        'org_cor_dark' => $_SESSION['org_cor_dark'] ?? '#1a7a9e',
        'org_cor_light' => $_SESSION['org_cor_light'] ?? '#e6f4f9',
        'org_tem_clientes' => $_SESSION['org_tem_clientes'] ?? 1,
        'org_tem_fornecedores' => $_SESSION['org_tem_fornecedores'] ?? 1,
    ];
}

function setUserSession(array $user, ?array $org = null): void {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_nome'] = $user['nome'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];

    if ($org) {
        $_SESSION['org_id'] = $org['id'];
        $_SESSION['org_nome'] = $org['nome'];
        $_SESSION['org_slug'] = $org['slug'] ?? '';
        $_SESSION['org_logo'] = $org['logo'] ?? '';
        $_SESSION['org_cor'] = $org['cor_primaria'] ?? '#2596be';
        $_SESSION['org_cor_dark'] = $org['cor_primaria_dark'] ?? '#1a7a9e';
        $_SESSION['org_cor_light'] = $org['cor_primaria_light'] ?? '#e6f4f9';
        $_SESSION['org_numeracao_prefixo'] = $org['numeracao_prefixo'] ?? 'CE';
        $_SESSION['org_tem_clientes'] = (int)($org['tem_clientes'] ?? 0);
        $_SESSION['org_tem_fornecedores'] = (int)($org['tem_fornecedores'] ?? 1);
    } else {
        $_SESSION['org_id'] = null;
        $_SESSION['org_nome'] = '';
        $_SESSION['org_logo'] = '';
        $_SESSION['org_cor'] = '#2596be';
        $_SESSION['org_cor_dark'] = '#1a7a9e';
        $_SESSION['org_cor_light'] = '#e6f4f9';
        $_SESSION['org_numeracao_prefixo'] = 'CE';
        $_SESSION['org_tem_clientes'] = 1;
        $_SESSION['org_tem_fornecedores'] = 1;
    }
}

// =============================================
// BRANDING DA ORGANIZAÇÃO
// =============================================

/**
 * Obter dados de branding da sessão do utilizador
 */
function getOrgBranding(): array {
    return [
        'id' => $_SESSION['org_id'] ?? null,
        'nome' => $_SESSION['org_nome'] ?? 'SpecLab',
        'logo' => $_SESSION['org_logo'] ?? '',
        'cor' => $_SESSION['org_cor'] ?? '#2596be',
        'cor_dark' => $_SESSION['org_cor_dark'] ?? '#1a7a9e',
        'cor_light' => $_SESSION['org_cor_light'] ?? '#e6f4f9',
    ];
}

/**
 * Obter dados completos de uma organização pela ID
 */
function getOrgData(PDO $db, int $orgId): ?array {
    $stmt = $db->prepare('SELECT * FROM organizacoes WHERE id = ?');
    $stmt->execute([$orgId]);
    return $stmt->fetch() ?: null;
}

/**
 * Obter organização de uma especificação (para views públicas/PDF sem sessão)
 */
function getOrgByEspecificacao(PDO $db, int $especId): ?array {
    $stmt = $db->prepare('
        SELECT o.* FROM organizacoes o
        INNER JOIN especificacoes e ON e.organizacao_id = o.id
        WHERE e.id = ?
    ');
    $stmt->execute([$especId]);
    return $stmt->fetch() ?: null;
}

// =============================================
// SANITIZAÇÃO
// =============================================

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitizeRichText(string $html): string {
    $html = preg_replace('/<\/div>\s*<div[^>]*>/i', '<br>', $html);
    $html = preg_replace('/<div[^>]*>/i', '<br>', $html);
    $html = str_ireplace('</div>', '', $html);
    $html = preg_replace('/<\/p>\s*/i', '<br>', $html);
    $html = preg_replace('/<p[^>]*>/i', '', $html);
    $html = strip_tags($html, '<b><strong><u><span><br><em><i><ul><ol><li>');
    $html = preg_replace('/^(<br\s*\/?>)+/i', '', $html);
    $html = preg_replace('/(<br\s*\/?>){3,}/i', '<br><br>', $html);
    return trim($html);
}

// =============================================
// NUMERAÇÃO DE ESPECIFICAÇÕES (scoped por org)
// =============================================

/**
 * Gera número automático de especificação
 * Formato: [PREFIXO]-YYYY-NNN (scoped por organização)
 */
function gerarNumeroEspecificacao(PDO $db, ?int $orgId = null): string {
    // Obter prefixo: da organização ou global
    $prefixo = 'CE';
    if ($orgId) {
        $stmt = $db->prepare('SELECT numeracao_prefixo FROM organizacoes WHERE id = ?');
        $stmt->execute([$orgId]);
        $orgPrefixo = $stmt->fetchColumn();
        if ($orgPrefixo) $prefixo = $orgPrefixo;
    } else {
        $prefixo = getConfiguracao('numeracao_prefixo', 'CE');
    }

    $ano = date('Y');

    // Contar specs da mesma org neste ano
    if ($orgId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM especificacoes WHERE numero LIKE ? AND organizacao_id = ?");
        $stmt->execute([$prefixo . '-' . $ano . '-%', $orgId]);
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM especificacoes WHERE numero LIKE ?");
        $stmt->execute([$prefixo . '-' . $ano . '-%']);
    }
    $count = (int)$stmt->fetchColumn() + 1;

    $numero = $prefixo . '-' . $ano . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

    // Garantir unicidade
    if ($orgId) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM especificacoes WHERE numero = ? AND organizacao_id = ?');
        $stmt->execute([$numero, $orgId]);
        while ($stmt->fetchColumn() > 0) {
            $count++;
            $numero = $prefixo . '-' . $ano . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            $stmt->execute([$numero, $orgId]);
        }
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM especificacoes WHERE numero = ?');
        $stmt->execute([$numero]);
        while ($stmt->fetchColumn() > 0) {
            $count++;
            $numero = $prefixo . '-' . $ano . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            $stmt->execute([$numero]);
        }
    }

    return $numero;
}

/**
 * Gera código de acesso público aleatório
 */
function gerarCodigoAcesso(): string {
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

// =============================================
// CONFIGURAÇÕES
// =============================================

function getConfiguracao(string $chave, string $default = ''): string {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $stmt->execute([$chave]);
        $valor = $stmt->fetchColumn();
        return $valor !== false ? $valor : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setConfiguracao(string $chave, string $valor, string $descricao = ''): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO configuracoes (chave, valor, descricao) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = ?');
    $stmt->execute([$chave, $valor, $descricao, $valor]);
}

// =============================================
// FORMATAÇÃO
// =============================================

function formatDate(?string $date): string {
    if (!$date) return '-';
    return date('d/m/Y', strtotime($date));
}

function formatFileSize(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
