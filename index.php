<?php
/**
 * SpecLab - Cadernos de Encargos
 * Página de Login
 */
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_PATH . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $error = 'Erro de segurança. Recarregue a página.';
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$error && (empty($username) || empty($password))) {
        $error = 'Preencha todos os campos.';
    } else if (!$error) {
        try {
            $db = getDB();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            // Rate limiting: max 5 tentativas por IP nos últimos 15 min
            $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
            $stmt->execute([$ip]);
            if ((int)$stmt->fetchColumn() >= 5) {
                $error = 'Demasiadas tentativas. Aguarde 15 minutos.';
            }

            if (!$error) {
                $stmt = $db->prepare('SELECT id, nome, username, password, role, ativo, organizacao_id FROM utilizadores WHERE username = ?');
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    if (!$user['ativo']) {
                        $error = 'Conta desativada. Contacte o administrador.';
                    } else {
                        // Login OK - limpar tentativas
                        $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);

                        $org = null;
                        if ($user['organizacao_id']) {
                            $stmt = $db->prepare('SELECT * FROM organizacoes WHERE id = ? AND ativo = 1');
                            $stmt->execute([$user['organizacao_id']]);
                            $org = $stmt->fetch();
                        }

                        session_regenerate_id(true);
                        setUserSession($user, $org);

                        header('Location: ' . BASE_PATH . '/dashboard.php');
                        exit;
                    }
                } else {
                    // Registar tentativa falhada
                    $db->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([$ip, $username]);
                    $error = 'Utilizador ou palavra-passe incorretos.';
                }
            }
        } catch (PDOException $e) {
            $error = 'Erro de ligação à base de dados. Verifique a configuração.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpecLab - Cadernos de Encargos</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="login-page">
    <div class="login-box">
        <img src="<?= BASE_PATH ?>/assets/img/exi_logo.png" alt="SpecLab" onerror="this.style.display='none'">
        <h1>Cadernos de Encargos</h1>
        <p>Sistema de Especificações Técnicas</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <div class="form-group">
                <label for="username">Utilizador</label>
                <input type="text" id="username" name="username" required autofocus
                       autocomplete="username"
                       value="<?= htmlspecialchars($username ?? '') ?>"
                       placeholder="O seu utilizador">
            </div>
            <div class="form-group">
                <label for="password">Palavra-passe</label>
                <input type="password" id="password" name="password" required
                       autocomplete="current-password"
                       placeholder="A sua palavra-passe">
            </div>
            <button type="submit" class="btn btn-primary btn-lg">Entrar</button>
        </form>

        <p style="margin-top: 24px; font-size: 11px; color: #999;">&copy; SpecLab <?= date('Y') ?></p>
    </div>
</body>
</html>
