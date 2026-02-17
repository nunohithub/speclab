<?php
/**
 * SpecLab - Cadernos de Encargos
 * Instalador do Sistema
 *
 * ELIMINAR ESTE FICHEIRO APÓS INSTALAÇÃO!
 */
set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

$step = $_GET['step'] ?? 'check';
$errors = [];
$success = [];

// Passo 1: Verificar requisitos
function checkRequirements(): array {
    $checks = [];
    $checks['php_version'] = ['label' => 'PHP >= 7.4', 'ok' => version_compare(PHP_VERSION, '7.4.0', '>='), 'value' => PHP_VERSION];
    $checks['pdo_mysql'] = ['label' => 'PDO MySQL', 'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'Instalado' : 'Não disponível'];
    $checks['json'] = ['label' => 'JSON Extension', 'ok' => extension_loaded('json'), 'value' => extension_loaded('json') ? 'Instalado' : 'Não disponível'];
    $checks['fileinfo'] = ['label' => 'Fileinfo Extension', 'ok' => extension_loaded('fileinfo'), 'value' => extension_loaded('fileinfo') ? 'Instalado' : 'Não disponível'];
    $checks['uploads_writable'] = ['label' => 'Pasta uploads/ com escrita', 'ok' => is_writable(__DIR__ . '/uploads/'), 'value' => is_writable(__DIR__ . '/uploads/') ? 'OK' : 'Sem permissão'];

    try {
        $db = getDB();
        $checks['database'] = ['label' => 'Ligação à base de dados', 'ok' => true, 'value' => DB_NAME . '@' . DB_HOST];
    } catch (Exception $e) {
        $checks['database'] = ['label' => 'Ligação à base de dados', 'ok' => false, 'value' => $e->getMessage()];
    }

    return $checks;
}

// Passo 2: Criar tabelas
function createTables(): array {
    $results = [];
    try {
        $db = getDB();
        $sql = file_get_contents(__DIR__ . '/sql/schema.sql');

        // Separar por statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $stmt) {
            if (empty($stmt) || strpos($stmt, '--') === 0) continue;
            try {
                $db->exec($stmt);
                // Extract table name for display
                if (preg_match('/CREATE TABLE.*?(\w+)\s*\(/i', $stmt, $m)) {
                    $results[] = ['ok' => true, 'msg' => "Tabela '{$m[1]}' criada"];
                } elseif (preg_match('/INSERT/i', $stmt)) {
                    $results[] = ['ok' => true, 'msg' => 'Dados iniciais inseridos'];
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    if (preg_match('/CREATE TABLE.*?(\w+)\s*\(/i', $stmt, $m)) {
                        $results[] = ['ok' => true, 'msg' => "Tabela '{$m[1]}' já existe"];
                    }
                } elseif (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $results[] = ['ok' => true, 'msg' => 'Dados já existem (ignorado)'];
                } else {
                    $results[] = ['ok' => false, 'msg' => $e->getMessage()];
                }
            }
        }

        // Definir password do admin
        $hash = password_hash('exi2026', PASSWORD_DEFAULT);
        $db->prepare("UPDATE utilizadores SET password = ? WHERE username = 'admin'")->execute([$hash]);
        $results[] = ['ok' => true, 'msg' => "Password admin definida (username: admin, password: exi2026)"];

    } catch (Exception $e) {
        $results[] = ['ok' => false, 'msg' => 'Erro geral: ' . $e->getMessage()];
    }

    return $results;
}

// Passo 3: Instalar mPDF
function installMpdf(): array {
    $results = [];
    $vendorDir = __DIR__ . '/vendor';

    if (is_dir($vendorDir) && file_exists($vendorDir . '/autoload.php')) {
        $results[] = ['ok' => true, 'msg' => 'mPDF já está instalado'];
        return $results;
    }

    // Verificar se composer.json existe
    if (!file_exists(__DIR__ . '/composer.json')) {
        file_put_contents(__DIR__ . '/composer.json', json_encode([
            'require' => [
                'mpdf/mpdf' => '^8.0',
                'setasign/fpdi' => '^2.3'
            ]
        ], JSON_PRETTY_PRINT));
        $results[] = ['ok' => true, 'msg' => 'composer.json criado'];
    }

    // Tentar instalar
    $composerPhar = __DIR__ . '/composer.phar';
    $composerContent = @file_get_contents('https://getcomposer.org/composer-2.phar');

    if ($composerContent === false) {
        $results[] = ['ok' => false, 'msg' => 'Não foi possível descarregar o Composer. Instale manualmente via SSH.'];
        $results[] = ['ok' => false, 'msg' => "Comando: cd " . __DIR__ . " && composer install"];
        return $results;
    }

    file_put_contents($composerPhar, $composerContent);
    $results[] = ['ok' => true, 'msg' => 'Composer descarregado'];

    $oldDir = getcwd();
    chdir(__DIR__);
    putenv('COMPOSER_HOME=' . __DIR__ . '/.composer');
    putenv('HOME=' . __DIR__);
    @mkdir(__DIR__ . '/.composer', 0755, true);

    $output = [];
    $returnCode = 0;
    exec('php ' . escapeshellarg($composerPhar) . ' install --no-dev --no-interaction 2>&1', $output, $returnCode);
    chdir($oldDir);

    if ($returnCode === 0 && is_dir($vendorDir)) {
        $results[] = ['ok' => true, 'msg' => 'mPDF instalado com sucesso!'];
        @unlink($composerPhar);
    } else {
        $results[] = ['ok' => false, 'msg' => 'Instalação falhou. Output: ' . implode("\n", array_slice($output, -5))];
        $results[] = ['ok' => false, 'msg' => "Tente via SSH: cd " . __DIR__ . " && php composer.phar install"];
    }

    return $results;
}

if ($step === 'install') {
    $tableResults = createTables();
}
if ($step === 'mpdf') {
    $mpdfResults = installMpdf();
}

$checks = checkRequirements();
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Cadernos de Encargos - SpecLab</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; background: #f3f4f6; color: #111827; }
        h1 { color: #2596be; margin-bottom: 8px; }
        .subtitle { color: #667085; font-size: 14px; margin-bottom: 24px; }
        .step { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        .step h2 { font-size: 16px; margin: 0 0 12px; color: #2596be; }
        .check { display: flex; align-items: center; gap: 10px; padding: 6px 0; font-size: 14px; }
        .ok { color: #1a7f37; font-weight: 600; }
        .err { color: #b42318; font-weight: 600; }
        .warn { color: #b35c00; }
        .check-icon { width: 20px; text-align: center; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; color: white; }
        .btn-primary { background: #2596be; }
        .btn-primary:hover { background: #1a7a9e; }
        .btn-secondary { background: white; color: #111827; border: 1px solid #e5e7eb; }
        .btn-secondary:hover { background: #f3f4f6; }
        .actions { display: flex; gap: 10px; margin-top: 20px; }
        .warning-box { background: rgba(180, 35, 24, 0.1); color: #b42318; border: 1px solid rgba(180, 35, 24, 0.3); padding: 12px; border-radius: 8px; margin-top: 16px; font-size: 13px; }
        .success-box { background: rgba(26, 127, 55, 0.1); color: #1a7f37; border: 1px solid rgba(26, 127, 55, 0.3); padding: 12px; border-radius: 8px; margin-top: 16px; font-size: 13px; }
        pre { background: #1e293b; color: #e2e8f0; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 12px; }
        .logo { text-align: center; margin-bottom: 20px; }
        .logo img { height: 56px; }
    </style>
</head>
<body>
    <div class="logo">
        <img src="<?= BASE_PATH ?>/assets/img/exi_logo.png" alt="SpecLab" onerror="this.style.display='none'">
    </div>
    <h1>Instalação - Cadernos de Encargos</h1>
    <p class="subtitle">Assistente de configuração do sistema</p>

    <!-- Verificação de Requisitos -->
    <div class="step">
        <h2>1. Verificação de Requisitos</h2>
        <?php foreach ($checks as $key => $check): ?>
            <div class="check">
                <span class="check-icon"><?= $check['ok'] ? '<span class="ok">&#10004;</span>' : '<span class="err">&#10008;</span>' ?></span>
                <span><?= $check['label'] ?></span>
                <span class="<?= $check['ok'] ? 'ok' : 'err' ?>" style="margin-left:auto; font-size:12px;"><?= htmlspecialchars($check['value']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Criação de Tabelas -->
    <div class="step">
        <h2>2. Base de Dados</h2>
        <?php if ($step === 'install' && isset($tableResults)): ?>
            <?php foreach ($tableResults as $r): ?>
                <div class="check">
                    <span class="check-icon"><?= $r['ok'] ? '<span class="ok">&#10004;</span>' : '<span class="err">&#10008;</span>' ?></span>
                    <span><?= htmlspecialchars($r['msg']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="font-size:14px; color:#667085;">Criar as tabelas na base de dados e inserir dados iniciais.</p>
            <p style="font-size:13px; color:#667085;">Pode também importar o ficheiro <code>sql/schema.sql</code> no phpMyAdmin.</p>
        <?php endif; ?>

        <?php if ($step !== 'install'): ?>
            <div class="actions">
                <a href="?step=install" class="btn btn-primary">Criar Tabelas</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Instalação mPDF -->
    <div class="step">
        <h2>3. mPDF (Geração de PDF)</h2>
        <?php if ($step === 'mpdf' && isset($mpdfResults)): ?>
            <?php foreach ($mpdfResults as $r): ?>
                <div class="check">
                    <span class="check-icon"><?= $r['ok'] ? '<span class="ok">&#10004;</span>' : '<span class="err">&#10008;</span>' ?></span>
                    <span><?= htmlspecialchars($r['msg']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <?php if (file_exists(__DIR__ . '/vendor/autoload.php')): ?>
                <div class="check"><span class="check-icon"><span class="ok">&#10004;</span></span><span>mPDF já está instalado</span></div>
            <?php else: ?>
                <p style="font-size:14px; color:#667085;">Instalar o mPDF para geração nativa de PDF. Opcional - sem mPDF, usa impressão do browser.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!file_exists(__DIR__ . '/vendor/autoload.php') && $step !== 'mpdf'): ?>
            <div class="actions">
                <a href="?step=mpdf" class="btn btn-primary">Instalar mPDF</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Resultado -->
    <div class="step" style="border-color: #2596be; border-width: 2px;">
        <h2>Resultado</h2>
        <?php
        $allOk = true;
        foreach ($checks as $c) { if (!$c['ok']) $allOk = false; }
        $dbReady = ($step === 'install' && isset($tableResults));
        ?>

        <?php if ($allOk): ?>
            <div class="success-box">
                <strong>Requisitos verificados com sucesso!</strong><br>
                <?php if ($dbReady): ?>
                    Base de dados configurada. A aplicação está pronta.
                <?php else: ?>
                    Execute o passo 2 para criar as tabelas.
                <?php endif; ?>
            </div>

            <?php if ($dbReady): ?>
                <div style="margin-top:16px; padding:16px; background:#f0f8fb; border-radius:8px; font-size:13px;">
                    <strong>Credenciais de acesso:</strong><br>
                    Username: <code>admin</code><br>
                    Password: <code>exi2026</code><br><br>
                    <strong>Altere a password após o primeiro login!</strong>
                </div>
                <div class="actions">
                    <a href="<?= BASE_PATH ?>/index.php" class="btn btn-primary">Ir para a Aplicação</a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="warning-box">
                <strong>Alguns requisitos não foram cumpridos.</strong><br>
                Corrija os problemas assinalados e recarregue esta página.
            </div>
        <?php endif; ?>

        <div class="warning-box" style="margin-top:16px;">
            <strong>IMPORTANTE:</strong> Elimine este ficheiro (install.php) do servidor após a instalação!
        </div>
    </div>

    <!-- Configuração Manual -->
    <div class="step">
        <h2>Configuração Manual (alternativa)</h2>
        <p style="font-size:13px; color:#667085;">Se preferir, configure manualmente:</p>
        <ol style="font-size:13px; color:#374151; line-height:1.8;">
            <li>Crie a base de dados no phpMyAdmin (ex: <code>exipt_especificacoes</code>)</li>
            <li>Importe o ficheiro <code>sql/schema.sql</code></li>
            <li>Edite <code>config/database.php</code> com as credenciais corretas</li>
            <li>Defina as permissões da pasta <code>uploads/</code> para escrita (chmod 755)</li>
            <li>Para mPDF: execute <code>composer install</code> via SSH</li>
        </ol>
    </div>
</body>
</html>
