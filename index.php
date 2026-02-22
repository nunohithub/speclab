<?php
/**
 * SpecLab - Cadernos de Encargos
 * Página de Login
 */
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
                AppLogger::security('Rate limit exceeded', ['username' => $username, 'ip' => $ip]);
                $error = 'Demasiadas tentativas. Aguarde 15 minutos.';
            }

            if (!$error) {
                $stmt = $db->prepare('SELECT id, nome, username, password, role, ativo, organizacao_id FROM utilizadores WHERE username = ?');
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    if (!$user['ativo']) {
                        AppLogger::security('Login attempt on disabled account', ['username' => $username, 'user_id' => $user['id']]);
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

                        AppLogger::info('User logged in', ['user_id' => $user['id'], 'username' => $user['username']]);

                        header('Location: ' . BASE_PATH . '/dashboard.php');
                        exit;
                    }
                } else {
                    // Registar tentativa falhada
                    $db->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([$ip, $username]);
                    AppLogger::security('Failed login', ['username' => $username, 'ip' => $ip]);
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
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/img/favicon.svg">
</head>
<body class="login-page">
    <div class="login-box">
        <img src="<?= BASE_PATH ?>/assets/img/speclab_logo.svg" alt="SpecLab" style="max-width: 180px; margin-bottom: 8px;" onerror="this.style.display='none'">
        <h1>Cadernos de Encargos</h1>
        <p>Sistema de Especificações Técnicas</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <div class="form-group">
                <label for="username">Utilizador</label>
                <input type="text" id="username" name="username" required autofocus
                       autocomplete="username" minlength="3"
                       value="<?= htmlspecialchars($username ?? '') ?>"
                       placeholder="O seu utilizador">
                <div class="field-error" id="username-error"></div>
            </div>
            <div class="form-group">
                <label for="password">Palavra-passe</label>
                <input type="password" id="password" name="password" required
                       autocomplete="current-password" minlength="6"
                       placeholder="A sua palavra-passe">
                <div class="field-error" id="password-error"></div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">Entrar</button>
        </form>
        <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            var user = document.getElementById('username');
            var pass = document.getElementById('password');
            var userErr = document.getElementById('username-error');
            var passErr = document.getElementById('password-error');
            var valid = true;
            userErr.textContent = '';
            passErr.textContent = '';

            if (!user.value.trim()) {
                userErr.textContent = 'Introduza o seu utilizador.';
                valid = false;
            } else if (user.value.trim().length < 3) {
                userErr.textContent = 'O utilizador deve ter pelo menos 3 caracteres.';
                valid = false;
            }

            if (!pass.value) {
                passErr.textContent = 'Introduza a palavra-passe.';
                valid = false;
            } else if (pass.value.length < 6) {
                passErr.textContent = 'A palavra-passe deve ter pelo menos 6 caracteres.';
                valid = false;
            }

            if (!valid) e.preventDefault();
        });
        </script>

        <p style="margin-top: 24px; font-size: 11px; color: #999;">&copy; SpecLab <?= date('Y') ?></p>
    </div>
</body>
</html>
