<?php
/**
 * SpecLab - Cadernos de Encargos
 * Visualização Pública com Password
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/versioning.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// Suporta acesso por código (?code=XXX) ou token individual (?token=XXX)
$code = $_GET['code'] ?? '';
$tokenStr = $_GET['token'] ?? '';
$tokenData = null;

if (!$code && !$tokenStr) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Não encontrado</title></head><body><h1>Especificação não encontrada</h1></body></html>';
    exit;
}

$db = getDB();
$espec = null;

if ($tokenStr) {
    // Acesso via token individual — sem password necessária
    $stmtTk = $db->prepare('SELECT t.*, e.id as espec_id, e.titulo, e.password_acesso, e.estado, e.codigo_acesso
        FROM especificacao_tokens t
        INNER JOIN especificacoes e ON e.id = t.especificacao_id
        WHERE t.token = ? AND t.ativo = 1');
    $stmtTk->execute([$tokenStr]);
    $tokenData = $stmtTk->fetch();
    if ($tokenData) {
        $espec = ['id' => $tokenData['espec_id'], 'titulo' => $tokenData['titulo'], 'password_acesso' => '', 'estado' => $tokenData['estado']];
        $code = $tokenData['codigo_acesso'] ?: 'token';
        registarAcessoToken($db, $tokenData['id'], $tokenData['espec_id']);
    }
} else {
    $stmt = $db->prepare('SELECT id, titulo, password_acesso, estado FROM especificacoes WHERE codigo_acesso = ?');
    $stmt->execute([$code]);
    $espec = $stmt->fetch();
}

// Carregar organização da especificação
$org = null;
if ($espec) {
    $stmtOrg = $db->prepare('SELECT o.* FROM organizacoes o INNER JOIN especificacoes e ON e.organizacao_id = o.id WHERE e.id = ?');
    $stmtOrg->execute([$espec['id']]);
    $org = $stmtOrg->fetch();
}
$corPrimaria = sanitizeColor($org ? $org['cor_primaria'] : '#2596be');
$corPrimariaDark = sanitizeColor($org ? $org['cor_primaria_dark'] : '#1a7a9e', '#1a7a9e');
$corPrimariaLight = sanitizeColor($org ? $org['cor_primaria_light'] : '#e6f4f9', '#e6f4f9');
$orgNome = $org ? $org['nome'] : 'SpecLab';
$orgLogo = ($org && $org['logo']) ? (BASE_PATH . '/uploads/logos/' . $org['logo']) : (BASE_PATH . '/assets/img/exi_logo.png');
$temClientes = $org && !empty($org['tem_clientes']);
$temFornecedores = $org && !empty($org['tem_fornecedores']);

if (!$espec) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Não encontrado</title><link rel="stylesheet" href="' . BASE_PATH . '/assets/css/style.css"></head><body class="login-page"><div class="login-box"><h1>Especificação não encontrada</h1><p>O código de acesso é inválido ou expirou.</p></div></body></html>';
    exit;
}

// Token dá acesso direto sem password
$needsPassword = !$tokenData && !empty($espec['password_acesso']);
$authenticated = $tokenData ? true : false;

if ($needsPassword) {
    // Verificar se já autenticou na sessão
    $sessionKey = 'espec_access_' . $espec['id'];
    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
        $authenticated = true;
    }

    // Verificar password submetida
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && validateCsrf()) {
        if (password_verify($_POST['password'], $espec['password_acesso'])) {
            $_SESSION[$sessionKey] = true;
            $authenticated = true;
        } else {
            $error = 'Palavra-passe incorreta.';
        }
    }
} else {
    $authenticated = true;
}

// Registar acesso
if ($authenticated) {
    $stmt = $db->prepare('INSERT INTO acessos_log (especificacao_id, ip, user_agent, tipo) VALUES (?, ?, ?, ?)');
    $stmt->execute([$espec['id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'view']);
}



// Processar aceitação/rejeição via formulário
$aceitacaoMsg = null;
if ($authenticated && $tokenData && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_aceitacao']) && validateCsrf()) {
    $decisao = ($_POST['decisao'] ?? '') === 'aceite' ? 'aceite' : 'rejeitado';
    $nome = sanitize($_POST['aceitar_nome'] ?? '');
    $cargo = sanitize($_POST['aceitar_cargo'] ?? '');
    $comentario = sanitize($_POST['aceitar_comentario'] ?? '');
    // Upload opcional de assinatura
    $assinaturaFile = null;
    if (!empty($_FILES['aceitar_assinatura']['name']) && $_FILES['aceitar_assinatura']['error'] === UPLOAD_ERR_OK) {
        $upDir = __DIR__ . '/uploads/assinaturas/';
        if (!is_dir($upDir)) mkdir($upDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['aceitar_assinatura']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
            $assinaturaFile = 'aceite_' . $espec['id'] . '_' . $tokenData['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['aceitar_assinatura']['tmp_name'], $upDir . $assinaturaFile);
        }
    }
    // Processar uploads de pedidos
    if ($decisao === 'aceite' && !empty($_FILES)) {
        foreach ($_FILES as $key => $file) {
            if (strpos($key, 'pedido_file_') === 0 && $file['error'] === UPLOAD_ERR_OK) {
                $pedId = (int)str_replace('pedido_file_', '', $key);
                if ($pedId > 0) {
                    $_POST['pedido_id'] = $pedId;
                    $_POST['token_id'] = $tokenData['id'];
                    $_FILES['ficheiro'] = $file;
                    // Upload inline
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowedExts = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
                    if (in_array($ext, $allowedExts) && $file['size'] <= 10*1024*1024) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->file($file['tmp_name']);
                        $stmt = $db->prepare('SELECT e.organizacao_id FROM especificacao_pedidos p INNER JOIN especificacoes e ON e.id = p.especificacao_id WHERE p.id = ?');
                        $stmt->execute([$pedId]);
                        $pInfo = $stmt->fetch();
                        if ($pInfo) {
                            $oId = (int)$pInfo['organizacao_id'];
                            $upDir = __DIR__ . '/uploads/pedidos/' . $oId . '/' . $pedId . '/';
                            if (!is_dir($upDir)) mkdir($upDir, 0755, true);
                            $uuid = bin2hex(random_bytes(8));
                            $fn = $tokenData['id'] . '_' . $uuid . '.' . $ext;
                            $relPath = 'uploads/pedidos/' . $oId . '/' . $pedId . '/' . $fn;
                            if (move_uploaded_file($file['tmp_name'], $upDir . $fn)) {
                                // Remover anterior
                                $db->prepare('DELETE FROM especificacao_pedido_respostas WHERE pedido_id = ? AND token_id = ?')->execute([$pedId, $tokenData['id']]);
                                $db->prepare('INSERT INTO especificacao_pedido_respostas (pedido_id, token_id, nome_ficheiro, path_ficheiro, mime_type, tamanho) VALUES (?, ?, ?, ?, ?, ?)')
                                   ->execute([$pedId, $tokenData['id'], basename($file['name']), $relPath, $mimeType, $file['size']]);
                            }
                        }
                    }
                }
            }
        }
    }

    if ($nome && registarDecisao($db, $espec['id'], $tokenData['id'], $decisao, $nome, $cargo ?: null, $comentario ?: null, $assinaturaFile)) {
        $aceitacaoMsg = $decisao === 'aceite' ? 'Documento aceite com sucesso!' : 'Documento rejeitado.';
        // Enviar email de confirmação com link permanente
        require_once __DIR__ . '/includes/email.php';
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_PATH, '/');
        enviarEmailConfirmacaoDecisao($db, $espec['id'], $tokenData['id'], $decisao, $nome, $baseUrl);
    }
}

// Se autenticado, carregar dados completos
$data = null;
$aprovacoes = [];
if ($authenticated) {
    $data = getEspecificacaoCompleta($db, $espec['id']);
    // Se acesso via token, mostrar apenas a decisão deste token; senão (código), mostrar todas
    if ($tokenData) {
        $stmtAprov = $db->prepare('SELECT a.tipo_decisao, a.nome_signatario, a.cargo_signatario, a.created_at, t.destinatario_nome, t.tipo_destinatario
            FROM especificacao_aceitacoes a
            INNER JOIN especificacao_tokens t ON t.id = a.token_id
            WHERE a.token_id = ?
            ORDER BY a.created_at DESC');
        $stmtAprov->execute([$tokenData['id']]);
    } else {
        $stmtAprov = $db->prepare('SELECT a.tipo_decisao, a.nome_signatario, a.cargo_signatario, a.created_at, t.destinatario_nome, t.tipo_destinatario
            FROM especificacao_aceitacoes a
            INNER JOIN especificacao_tokens t ON t.id = a.token_id
            WHERE a.especificacao_id = ?
            ORDER BY a.created_at DESC');
        $stmtAprov->execute([$espec['id']]);
    }
    $aprovacoes = $stmtAprov->fetchAll();

    // Carregar pedidos da especificação
    $stmtPed = $db->prepare('SELECT * FROM especificacao_pedidos WHERE especificacao_id = ? ORDER BY ordem');
    $stmtPed->execute([$espec['id']]);
    $pedidosPublico = $stmtPed->fetchAll(PDO::FETCH_ASSOC);

    // Carregar respostas já enviadas por este token
    $respostasPedido = [];
    if ($tokenData) {
        $stmtResp = $db->prepare('SELECT r.* FROM especificacao_pedido_respostas r INNER JOIN especificacao_pedidos p ON p.id = r.pedido_id WHERE p.especificacao_id = ? AND r.token_id = ?');
        $stmtResp->execute([$espec['id'], $tokenData['id']]);
        while ($rr = $stmtResp->fetch(PDO::FETCH_ASSOC)) {
            $respostasPedido[$rr['pedido_id']] = $rr;
        }
    }
}

// Traduções dos rótulos conforme idioma
$lang = $data['idioma'] ?? 'pt';
$labels = [
    'pt' => ['produto'=>'Produto','cliente'=>'Cliente','fornecedor'=>'Fornecedor','emissao'=>'Emissão','revisao'=>'Revisão','estado'=>'Estado','elaborado_por'=>'Criado por','versao'=>'Versão','documento'=>'Documento'],
    'en' => ['produto'=>'Product','cliente'=>'Client','fornecedor'=>'Supplier','emissao'=>'Issue Date','revisao'=>'Revision','estado'=>'Status','elaborado_por'=>'Created by','versao'=>'Version','documento'=>'Document'],
    'es' => ['produto'=>'Producto','cliente'=>'Cliente','fornecedor'=>'Proveedor','emissao'=>'Emisión','revisao'=>'Revisión','estado'=>'Estado','elaborado_por'=>'Creado por','versao'=>'Versión','documento'=>'Documento'],
    'fr' => ['produto'=>'Produit','cliente'=>'Client','fornecedor'=>'Fournisseur','emissao'=>'Émission','revisao'=>'Révision','estado'=>'Statut','elaborado_por'=>'Créé par','versao'=>'Version','documento'=>'Document'],
    'de' => ['produto'=>'Produkt','cliente'=>'Kunde','fornecedor'=>'Lieferant','emissao'=>'Ausgabe','revisao'=>'Revision','estado'=>'Status','elaborado_por'=>'Erstellt von','versao'=>'Version','documento'=>'Dokument'],
    'it' => ['produto'=>'Prodotto','cliente'=>'Cliente','fornecedor'=>'Fornitore','emissao'=>'Emissione','revisao'=>'Revisione','estado'=>'Stato','elaborado_por'=>'Creato da','versao'=>'Versione','documento'=>'Documento'],
];
$L = $labels[$lang] ?? $labels['pt'];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= san($espec['titulo']) ?> - <?= san($orgNome) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= asset('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
    <style>
        .public-container { max-width: 900px; margin: 0 auto; padding: 24px; }
        .doc-header {
            display: flex; align-items: center; justify-content: space-between;
            padding-bottom: 12px; border-bottom: 3px solid <?= $corPrimaria ?>; margin-bottom: 24px;
        }
        .doc-header img { height: 48px; }
        .doc-header .doc-title { text-align: right; }
        .doc-header .doc-title h1 { font-size: 18px; color: <?= $corPrimaria ?>; margin: 0; }
        .doc-header .doc-title p { font-size: 12px; color: #667085; margin: 4px 0 0; }
        .doc-section { margin-bottom: 24px; }
        .doc-section h2 {
            font-size: 14px; color: <?= $corPrimaria ?>; border-bottom: 1px solid <?= $corPrimariaLight ?>;
            padding-bottom: 6px; margin-bottom: 12px;
        }
        .doc-section .content { font-size: 13px; line-height: 1.6; white-space: pre-wrap; }
        .doc-section-sub { margin-left: 24px; border-left: 3px solid <?= $corPrimaria ?>; padding-left: 16px; }
        .doc-section-sub h3 { font-size: 13px; color: <?= $corPrimaria ?>; border-bottom: 1px solid <?= $corPrimariaLight ?>; padding-bottom: 5px; margin-bottom: 10px; }
        .doc-meta {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
            background: #f3f4f6; padding: 12px; border-radius: 8px; font-size: 12px;
            margin-bottom: 24px;
        }
        .doc-meta strong { color: #111827; }
        .doc-meta span { color: #667085; }
        .doc-table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 12px; }
        .doc-table th {
            background: <?= $corPrimaria ?>; color: white; padding: 8px 10px;
            text-align: left; font-weight: 600;
        }
        .doc-table td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; }
        .doc-table tr:nth-child(even) { background: #f9fafb; }
        .cat-header td {
            background: <?= $corPrimariaLight ?>; font-weight: 600; color: <?= $corPrimariaDark ?>; padding: 6px 10px; text-align: center;
        }
        .doc-footer {
            margin-top: 32px; padding-top: 12px; border-top: 1px solid #e5e7eb;
            font-size: 11px; color: #999; display: flex; justify-content: space-between;
        }
        .doc-actions {
            position: fixed; bottom: 24px; right: 24px;
            display: flex; gap: 8px; z-index: 100;
        }
        .file-downloads { margin-top: 12px; }
        .file-dl {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; background: #f3f4f6; border-radius: 6px;
            font-size: 12px; color: #111827; margin: 4px 4px 4px 0;
            text-decoration: none; border: 1px solid #e5e7eb;
        }
        .file-dl:hover { background: <?= $corPrimariaLight ?>; border-color: <?= $corPrimaria ?>; }
        @media print {
            .doc-actions, .no-print { display: none !important; }
            .doc-header { border-bottom: 2pt solid <?= $corPrimaria ?>; }
            .doc-table th { background: <?= $corPrimaria ?> !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .cat-header td { background: <?= $corPrimariaLight ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { size: A4; margin: 15mm; }
        }
    </style>
</head>
<body style="background: #f3f4f6;">

<?php if (!$authenticated): ?>
    <!-- PASSWORD FORM -->
    <div class="login-page" style="min-height:100vh;">
        <div class="login-box">
            <img src="<?= $orgLogo ?>" alt="<?= sanitize($orgNome) ?>" onerror="this.style.display='none'">
            <h1 style="font-size:16px; color:<?= $corPrimaria ?>; margin-bottom:4px;">Caderno de Encargos</h1>
            <p style="font-size:13px; color:#111827; margin-bottom:4px;"><strong><?= san($espec['titulo']) ?></strong></p>
            <p style="font-size:12px; color:#667085; margin-bottom:20px;">Este documento requer autenticação para visualização.</p>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <div class="form-group">
                    <label for="password">Palavra-passe de Acesso</label>
                    <input type="password" id="password" name="password" required autofocus placeholder="Introduza a palavra-passe">
                </div>
                <button type="submit" class="btn btn-primary btn-lg">Aceder ao Documento</button>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- DOCUMENT VIEW -->
    <div class="public-container">
        <div class="card" style="padding:32px;">

            <!-- Header -->
            <div class="doc-header">
                <img src="<?= $orgLogo ?>" alt="<?= sanitize($orgNome) ?>" onerror="this.style.display='none'">
                <div class="doc-title">
                    <h1><?= san($data['titulo']) ?></h1>
                    <p><?= san($data['numero']) ?> | <?= $L['versao'] ?> <?= san($data['versao']) ?></p>
                </div>
            </div>

            <?php if (in_array($data['estado'], ['rascunho', 'em_revisao'])): ?>
            <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:10px 16px;margin-bottom:16px;font-size:14px;color:#92400e;text-align:center;">
                <strong>&#9888; <?= $data['estado'] === 'rascunho' ? 'RASCUNHO' : 'EM REVISÃO' ?></strong> — Este documento ainda não foi publicado.
            </div>
            <?php endif; ?>

            <!-- Meta -->
            <div class="doc-meta">
                <div><span><?= $L['produto'] ?>:</span> <strong><?= san($data['produto_nome'] ?? '-') ?></strong></div>
                <?php if ($temClientes): ?>
                <div><span><?= $L['cliente'] ?>:</span> <strong><?= san($data['cliente_nome'] ?? 'Geral') ?></strong></div>
                <?php endif; ?>
                <?php if ($temFornecedores): ?>
                <div><span><?= $L['fornecedor'] ?>:</span> <strong><?= san($data['fornecedor_nome'] ?? 'Todos') ?></strong></div>
                <?php endif; ?>
                <div><span><?= $L['emissao'] ?>:</span> <strong><?= formatDate($data['data_emissao']) ?></strong></div>
                <div><span><?= $L['revisao'] ?>:</span> <strong><?= $data['data_revisao'] ? formatDate($data['data_revisao']) : '-' ?></strong></div>
                <div><span><?= $L['estado'] ?>:</span> <strong><?= ucfirst($data['estado']) ?></strong></div>
                <div><span><?= $L['elaborado_por'] ?>:</span> <strong><?= san($data['criado_por_nome'] ?? '-') ?></strong></div>
                <?php if (!empty($aprovacoes)): ?>
                <?php foreach ($aprovacoes as $aprov): ?>
                <div style="width:100%; margin-top:4px;">
                    <span><?= $aprov['tipo_destinatario'] === 'fornecedor' ? $L['fornecedor'] : ($aprov['tipo_destinatario'] === 'cliente' ? $L['cliente'] : 'Aprovação') ?>:</span>
                    <strong style="color:<?= $aprov['tipo_decisao'] === 'aceite' ? '#16a34a' : '#dc2626' ?>">
                        <?= $aprov['tipo_decisao'] === 'aceite' ? 'Aceite' : 'Rejeitado' ?>
                    </strong>
                    — <?= san($aprov['nome_signatario']) ?>
                    <?= $aprov['cargo_signatario'] ? '(' . san($aprov['cargo_signatario']) . ')' : '' ?>
                    <span style="color:#888; font-size:11px;"><?= date('d/m/Y', strtotime($aprov['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Secções -->
            <?php if (!empty($data['seccoes'])):
                $hierNumbers = []; $mainC = 0; $subC = 0;
                foreach ($data['seccoes'] as $si => $s) {
                    $niv = (int)($s['nivel'] ?? 1);
                    if ($niv === 1) { $mainC++; $subC = 0; $hierNumbers[$si] = $mainC . '.'; }
                    else { $subC++; $hierNumbers[$si] = $mainC . '.' . $subC . '.'; }
                }
            ?>
                <?php foreach ($data['seccoes'] as $i => $sec):
                    $secTipo = $sec['tipo'] ?? 'texto';
                    $secNivel = (int)($sec['nivel'] ?? 1);
                    $secNum = $hierNumbers[$i] ?? ($i + 1) . '.';
                ?>
                    <div class="doc-section<?= $secNivel === 2 ? ' doc-section-sub' : '' ?>">
                        <<?= $secNivel === 2 ? 'h3' : 'h2' ?>><?= $secNum . ' ' . san($sec['titulo']) ?></<?= $secNivel === 2 ? 'h3' : 'h2' ?>>
                        <?php if ($secTipo === 'ensaios'): ?>
                            <?php
                            $ensaiosRaw = json_decode($sec['conteudo'] ?? '[]', true);
                            if (isset($ensaiosRaw['rows'])) {
                                $ensaiosData = $ensaiosRaw['rows'];
                                $colWidths = $ensaiosRaw['colWidths'] ?? [20, 22, 18, 13, 13, 10];
                                $merges = $ensaiosRaw['merges'] ?? [];
                            } else {
                                $ensaiosData = is_array($ensaiosRaw) ? $ensaiosRaw : [];
                                $colWidths = [20, 22, 18, 13, 13, 10];
                                $merges = [];
                            }
                            // 6 colWidths = editor (Ensaio,Espec,Norma,NEI,NQA,Unid) → usar 0..4
                            $outCw = array_slice($colWidths, 0, 5);
                            $colShift = 0;
                            if (count($outCw) < 5) $outCw = [26, 22, 18, 15, 14];
                            $cwSum = array_sum($outCw) ?: 1;
                            $cwPct = array_map(function($v) use ($cwSum) { return round($v / $cwSum * 100, 1); }, $outCw);
                            $hiddenCells = []; $spanCells = []; $alignCells = []; $rowInMerge = [];
                            foreach ($merges as $m) {
                                $nc = $m['col'] - $colShift;
                                if ($nc < 0 || $nc > 4) continue;
                                $k = $m['row'] . '_' . $nc;
                                $spanCells[$k] = $m['span'];
                                $alignCells[$k] = ['h' => $m['hAlign'] ?? 'center', 'v' => $m['vAlign'] ?? 'middle'];
                                for ($r = $m['row'] + 1; $r < $m['row'] + $m['span']; $r++) {
                                    $hiddenCells[$r . '_' . $nc] = true;
                                    $rowInMerge[$r] = true;
                                }
                            }
                            $catHeaders = []; $displayedCat = null;
                            foreach ($ensaiosData as $rIdx => $ens) {
                                $cat = trim($ens['categoria'] ?? '');
                                if ($cat !== '' && $cat !== $displayedCat && !isset($rowInMerge[$rIdx])) {
                                    $catHeaders[$rIdx] = $cat;
                                    $displayedCat = $cat;
                                }
                            }
                            ?>
                            <?php if (!empty($ensaiosData)): ?>
                            <table class="doc-table">
                                <thead>
                                    <tr>
                                        <th style="width:<?= $cwPct[0] ?>%">Ensaio / Controlo</th>
                                        <th style="width:<?= $cwPct[1] ?>%">Especificação</th>
                                        <th style="width:<?= $cwPct[2] ?>%">Norma</th>
                                        <th style="width:<?= $cwPct[3] ?>%" title="Nível Especial de Inspeção">NEI</th>
                                        <th style="width:<?= $cwPct[4] ?>%" title="Nível de Qualidade Aceitável">NQA</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $fields = ['ensaio','especificacao','norma','nivel_especial','nqa'];
                                    foreach ($ensaiosData as $rIdx => $ens):
                                        if (isset($catHeaders[$rIdx])):
                                    ?>
                                    <tr class="cat-header"><td colspan="5"><?= san($catHeaders[$rIdx]) ?></td></tr>
                                    <?php endif; ?>
                                    <tr>
                                        <?php foreach ($fields as $cIdx => $field):
                                            $key = $rIdx . '_' . $cIdx;
                                            if (isset($hiddenCells[$key])) continue;
                                            $rs = isset($spanCells[$key]) ? ' rowspan="' . $spanCells[$key] . '"' : '';
                                            $ms = '';
                                            if (isset($alignCells[$key])) {
                                                $ms = 'vertical-align:' . $alignCells[$key]['v'] . '; text-align:' . $alignCells[$key]['h'] . ';';
                                            }
                                        ?>
                                        <td<?= $rs ?><?= $ms ? ' style="' . $ms . '"' : '' ?>><?= $field === 'especificacao' ? '<strong>' . san($ens[$field] ?? '') . '</strong>' : san($ens[$field] ?? '') ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p style="font-size:11px; color:#888; margin:3px 0 0 0;">NEI — Nível Especial de Inspeção &nbsp;|&nbsp; NQA — Nível de Qualidade Aceitável &nbsp;(NP 2922)</p>
                            <?php endif; ?>
                        <?php elseif ($secTipo === 'parametros' || $secTipo === 'parametros_custom'): ?>
                            <?php
                            $pcRaw = json_decode($sec['conteudo'] ?? '{}', true);
                            $pcRows = $pcRaw['rows'] ?? [];
                            $pcTipoId = $pcRaw['tipo_id'] ?? '';
                            $pcColunas = []; $pcLegenda = ''; $pcLegTam = 9;
                            if ($pcTipoId) {
                                $stmtPt = $db->prepare('SELECT colunas, legenda, legenda_tamanho FROM parametros_tipos WHERE id = ?');
                                $stmtPt->execute([(int)$pcTipoId]);
                                $ptRow = $stmtPt->fetch();
                                if ($ptRow) { $pcColunas = json_decode($ptRow['colunas'], true) ?: []; $pcLegenda = $ptRow['legenda'] ?? ''; $pcLegTam = (int)($ptRow['legenda_tamanho'] ?? 9); }
                            }
                            // Override por especificação
                            if (!empty($espec['legenda_parametros'])) { $pcLegenda = $espec['legenda_parametros']; }
                            if (!empty($espec['legenda_parametros_tamanho'])) { $pcLegTam = (int)$espec['legenda_parametros_tamanho']; }
                            if (empty($pcColunas) && !empty($pcRows)) {
                                $firstDataRow = null; foreach ($pcRows as $pr) { if (!isset($pr['_cat'])) { $firstDataRow = $pr; break; } }
                                if ($firstDataRow) { foreach (array_keys($firstDataRow) as $k) { if ($k !== '_cat') $pcColunas[] = ['nome' => $k, 'chave' => $k]; } }
                            }
                            ?>
                            <?php if (!empty($pcRows)): ?>
                            <table class="doc-table">
                                <thead><tr>
                                    <?php foreach ($pcColunas as $pcCol): ?>
                                    <th><?= san($pcCol['nome']) ?></th>
                                    <?php endforeach; ?>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($pcRows as $pcRow): ?>
                                        <?php if (isset($pcRow['_cat'])): ?>
                                        <tr><td colspan="<?= count($pcColunas) ?>" style="background:#f0f4f8; padding:4px 8px; font-weight:600; font-size:12px;"><?= san($pcRow['_cat']) ?></td></tr>
                                        <?php else: ?>
                                        <tr>
                                            <?php foreach ($pcColunas as $pcCol): ?>
                                            <td><?= nl2br(san($pcRow[$pcCol['chave']] ?? '')) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (!empty($pcLegenda)): ?>
                            <p style="font-size:<?= $pcLegTam ?>px; color:#888; font-style:italic;"><?= san($pcLegenda) ?></p>
                            <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="content"><?php
                                $secContent = $sec['conteudo'] ?? '';
                                if (strip_tags($secContent) === $secContent) {
                                    echo nl2br(san($secContent));
                                } else {
                                    echo sanitizeRichText($secContent);
                                }
                            ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php
                $sections = [
                    'objetivo' => '1. Objetivo e Âmbito de Aplicação',
                    'ambito' => '2. Introdução',
                    'definicao_material' => '3. Definição do Material',
                    'regulamentacao' => '4. Regulamentação Aplicável',
                    'processos' => '5. Processos Industriais Relevantes',
                    'embalagem' => '6. Embalagem, Armazenamento e Transporte',
                    'aceitacao' => '7. Condições de Aceitação e Rejeição',
                    'arquivo_texto' => '8. Arquivo',
                    'indemnizacao' => '9. Indemnização',
                    'observacoes' => '10. Observações',
                ];
                foreach ($sections as $key => $title):
                    if (!empty($data[$key])):
                ?>
                    <div class="doc-section">
                        <h2><?= $title ?></h2>
                        <div class="content"><?= nl2br(san($data[$key])) ?></div>
                    </div>
                <?php endif; endforeach; ?>
            <?php endif; ?>



            <!-- Photo Gallery -->
            <?php
            $fotos = array_filter($data['ficheiros'], function($f) {
                $ext = strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION));
                return in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
            });
            if (!empty($fotos)):
            ?>
                <div class="doc-section">
                    <h2>Galeria de Fotos</h2>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-top: 8px;">
                        <?php foreach ($fotos as $f): ?>
                            <div style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; cursor: pointer;" onclick="abrirFoto('<?= BASE_PATH ?>/download.php?id=<?= $f['id'] ?>&code=<?= urlencode($code) ?>')">
                                <img src="<?= BASE_PATH ?>/download.php?id=<?= $f['id'] ?>&code=<?= urlencode($code) ?>" alt="<?= san($f['nome_original']) ?>" style="width: 100%; height: 160px; object-fit: cover;" loading="lazy">
                                <div style="padding: 6px 8px; font-size: 11px; color: #667085; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= san($f['legenda'] ?? $f['nome_original']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Embedded PDFs -->
            <?php
            $pdfs = array_filter($data['ficheiros'], function($f) {
                return strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION)) === 'pdf';
            });
            if (!empty($pdfs)):
            ?>
                <div class="doc-section">
                    <h2>Documentos PDF</h2>
                    <?php foreach ($pdfs as $f): ?>
                        <div style="margin-bottom: 16px;">
                            <h3 style="font-size: 13px; color: #374151; margin-bottom: 8px;"><?= san($f['nome_original']) ?></h3>
                            <iframe src="<?= BASE_PATH ?>/download.php?id=<?= $f['id'] ?>&code=<?= urlencode($code) ?>#toolbar=1" style="width: 100%; height: 600px; border: 1px solid #e5e7eb; border-radius: 8px;" loading="lazy"></iframe>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Other Files -->
            <?php
            $outrosFicheiros = array_filter($data['ficheiros'], function($f) {
                $ext = strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION));
                return !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'pdf']);
            });
            if (!empty($outrosFicheiros)):
            ?>
                <div class="doc-section">
                    <h2>Outros Documentos</h2>
                    <div class="file-downloads">
                        <?php foreach ($outrosFicheiros as $f): ?>
                            <a href="<?= BASE_PATH ?>/download.php?id=<?= $f['id'] ?>&code=<?= urlencode($code) ?>" class="file-dl">
                                &#128196; <?= san($f['nome_original']) ?>
                                <span style="color:#999; font-size:11px;">(<?= formatFileSize($f['tamanho']) ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="doc-footer">
                <span>&copy; <?= getConfiguracao('empresa_nome', 'SpecLab') ?> <?= date('Y') ?></span>
                <span><?= $L['documento'] ?>: <?= san($data['numero']) ?> | <?= $L['versao'] ?> <?= san($data['versao']) ?></span>
            </div>

            <?php
            // Formulário de aceitação (só para tokens com permissão ver_aceitar)
            $mostrarAceitacao = false;
            $jaDecidiu = false;
            $decisaoExistente = null;
            if ($tokenData && $tokenData['permissao'] === 'ver_aceitar') {
                $mostrarAceitacao = true;
                $stmtDec = $db->prepare('SELECT * FROM especificacao_aceitacoes WHERE token_id = ?');
                $stmtDec->execute([$tokenData['id']]);
                $decisaoExistente = $stmtDec->fetch();
                if ($decisaoExistente) $jaDecidiu = true;
            }
            ?>

            <?php if ($mostrarAceitacao): ?>
            <div class="doc-section" id="secAceitacao" style="margin-top:var(--spacing-xl); border-top:2px solid <?= $corPrimaria ?>; padding-top:var(--spacing-lg);">
                <h2 style="color:<?= $corPrimaria ?>;">Aceitação do Documento</h2>

                <?php if ($jaDecidiu): ?>
                    <div style="padding:var(--spacing-md); border-radius:8px; background:<?= $decisaoExistente['tipo_decisao'] === 'aceite' ? '#dcfce7' : '#fee2e2' ?>; text-align:center;">
                        <strong style="font-size:16px;">
                            <?= $decisaoExistente['tipo_decisao'] === 'aceite' ? 'Documento Aceite' : 'Documento Rejeitado' ?>
                        </strong>
                        <p style="margin:8px 0 0; color:#666;">
                            por <?= san($decisaoExistente['nome_signatario']) ?>
                            <?= $decisaoExistente['cargo_signatario'] ? ' (' . san($decisaoExistente['cargo_signatario']) . ')' : '' ?>
                            em <?= date('d/m/Y H:i', strtotime($decisaoExistente['created_at'])) ?>
                        </p>
                        <?php if ($decisaoExistente['comentario']): ?>
                        <p style="margin-top:8px; font-style:italic;">"<?= san($decisaoExistente['comentario']) ?>"</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php if (isset($aceitacaoMsg)): ?>
                        <div style="padding:var(--spacing-sm); border-radius:6px; background:#dcfce7; margin-bottom:var(--spacing-md); text-align:center;">
                            <?= $aceitacaoMsg ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data" style="max-width:500px;">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="acao_aceitacao" value="1">
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">Nome completo *</label>
                            <input type="text" name="aceitar_nome" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;" placeholder="O seu nome">
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">Cargo (opcional)</label>
                            <input type="text" name="aceitar_cargo" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;" placeholder="Ex: Diretor de Qualidade">
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">Assinatura digital <span style="font-weight:400; color:#888;">(opcional, imagem PNG/JPG)</span></label>
                            <input type="file" name="aceitar_assinatura" accept="image/png,image/jpeg" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px;">Comentário (opcional)</label>
                            <textarea name="aceitar_comentario" rows="2" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;" placeholder="Observações..."></textarea>
                        </div>
                        <?php if (!empty($pedidosPublico)): ?>
                        <div style="margin-bottom:16px; padding:12px; background:#fffbeb; border:1px solid #fbbf24; border-radius:8px;">
                            <h3 style="font-size:14px; color:#92400e; margin:0 0 8px;">Documentos Solicitados</h3>
                            <?php foreach ($pedidosPublico as $ped):
                                $jaRespondeu = isset($respostasPedido[$ped['id']]);
                            ?>
                            <div style="margin-bottom:10px; padding:8px; background:#fff; border-radius:6px; border:1px solid #e5e7eb;">
                                <strong style="font-size:13px;"><?= sanitize($ped['titulo']) ?></strong>
                                <?php if ($ped['obrigatorio']): ?><span style="color:#ef4444; font-size:11px;"> (obrigatório para aceitar)</span><?php endif; ?>
                                <?php if ($ped['descricao']): ?><p style="font-size:12px; color:#667085; margin:4px 0;"><?= sanitize($ped['descricao']) ?></p><?php endif; ?>
                                <?php if ($jaRespondeu): ?>
                                    <p style="font-size:12px; color:#16a34a; margin:4px 0;">&#10003; Ficheiro enviado: <?= sanitize($respostasPedido[$ped['id']]['nome_ficheiro']) ?></p>
                                <?php endif; ?>
                                <input type="file" name="pedido_file_<?= $ped['id'] ?>" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="margin-top:4px; width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; font-size:12px;">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div style="display:flex; gap:12px;">
                            <button type="submit" name="decisao" value="aceite" id="btnAceitar" style="flex:1; padding:12px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer;">Aceitar Documento</button>
                            <button type="submit" name="decisao" value="rejeitado" style="flex:1; padding:12px; background:#dc2626; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer;">Rejeitar</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Floating Actions -->
    <div class="doc-actions no-print">
        <a href="<?= BASE_PATH ?>/ver.php?id=<?= $data['id'] ?>" class="btn btn-secondary" target="_blank" style="display:none;">&#128065; Preview</a>
        <a href="<?= BASE_PATH ?>/pdf.php?id=<?= $data['id'] ?>&code=<?= urlencode($code) ?><?= $tokenData ? '&token=' . urlencode($tokenData['token']) : '' ?>" class="btn btn-primary" target="_blank">&#128196; PDF</a>
        <button class="btn btn-secondary" onclick="window.print()">&#128424; Imprimir</button>
    </div>

    <!-- Photo Lightbox -->
    <div id="lightbox" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:10000; cursor:pointer; align-items:center; justify-content:center;" onclick="this.style.display='none'">
        <img id="lightboxImg" style="max-width:90%; max-height:90%; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.5);">
        <button style="position:absolute; top:20px; right:20px; background:none; border:none; color:white; font-size:28px; cursor:pointer;">&times;</button>
    </div>

    <script>
    function abrirFoto(src) {
        document.getElementById('lightboxImg').src = src;
        document.getElementById('lightbox').style.display = 'flex';
    }
    </script>

<?php endif; ?>

<script>
// Bloquear "Aceitar" se faltam uploads obrigatórios
(function() {
    var btnAceitar = document.getElementById('btnAceitar');
    if (!btnAceitar) return;
    var obrigatorios = <?= json_encode(array_values(array_filter(array_map(function($p) { return $p['obrigatorio'] ? $p['id'] : null; }, $pedidosPublico ?? [])))) ?>;
    if (obrigatorios.length === 0) return;

    function verificarUploads() {
        var ok = true;
        obrigatorios.forEach(function(pedId) {
            var input = document.querySelector('input[name="pedido_file_' + pedId + '"]');
            if (input && !input.files.length) ok = false;
        });
        btnAceitar.disabled = !ok;
        btnAceitar.style.opacity = ok ? '1' : '0.5';
        btnAceitar.title = ok ? '' : 'Envie todos os documentos obrigatórios antes de aceitar';
    }
    verificarUploads();
    document.querySelectorAll('input[type="file"][name^="pedido_file_"]').forEach(function(input) {
        input.addEventListener('change', verificarUploads);
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
