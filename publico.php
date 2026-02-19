<?php
/**
 * SpecLab - Cadernos de Encargos
 * Visualização Pública com Password
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/versioning.php';

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
    if ($nome && registarDecisao($db, $espec['id'], $tokenData['id'], $decisao, $nome, $cargo ?: null, $comentario ?: null, $assinaturaFile)) {
        $aceitacaoMsg = $decisao === 'aceite' ? 'Documento aceite com sucesso!' : 'Documento rejeitado.';
    }
}

// Se autenticado, carregar dados completos
$data = null;
if ($authenticated) {
    $data = getEspecificacaoCompleta($db, $espec['id']);
}
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
                    <p><?= san($data['numero']) ?> | Versão <?= san($data['versao']) ?></p>
                </div>
            </div>

            <!-- Meta -->
            <div class="doc-meta">
                <div><span>Produto:</span> <strong><?= san($data['produto_nome'] ?? '-') ?></strong></div>
                <?php if ($temClientes): ?>
                <div><span>Cliente:</span> <strong><?= san($data['cliente_nome'] ?? 'Geral') ?></strong></div>
                <?php endif; ?>
                <?php if ($temFornecedores): ?>
                <div><span>Fornecedor:</span> <strong><?= san($data['fornecedor_nome'] ?? 'Todos') ?></strong></div>
                <?php endif; ?>
                <div><span>Emissão:</span> <strong><?= formatDate($data['data_emissao']) ?></strong></div>
                <div><span>Revisão:</span> <strong><?= $data['data_revisao'] ? formatDate($data['data_revisao']) : '-' ?></strong></div>
                <div><span>Estado:</span> <strong><?= ucfirst($data['estado']) ?></strong></div>
                <div><span>Criado por:</span> <strong><?= san($data['criado_por_nome'] ?? '-') ?></strong></div>
            </div>

            <!-- Secções -->
            <?php if (!empty($data['seccoes'])): ?>
                <?php foreach ($data['seccoes'] as $i => $sec):
                    $secTipo = $sec['tipo'] ?? 'texto';
                ?>
                    <div class="doc-section">
                        <h2><?= ($i + 1) . '. ' . san($sec['titulo']) ?></h2>
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
                            if (count($colWidths) >= 6) {
                                $outCw = array_slice($colWidths, 1, 5);
                                $colShift = 1;
                            } else {
                                $outCw = array_slice($colWidths, 0, 5);
                                $colShift = 0;
                            }
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


            <!-- Visual Classes -->
            <?php if (!empty($data['classes'])): ?>
                <div class="doc-section">
                    <h2>Classes Visuais</h2>
                    <table class="doc-table">
                        <thead>
                            <tr>
                                <th>Classe</th>
                                <th>Defeitos Máx. (%)</th>
                                <th>Descrição</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['classes'] as $cl): ?>
                            <tr>
                                <td><strong><?= san($cl['classe']) ?></strong></td>
                                <td><?= $cl['defeitos_max'] ?>%</td>
                                <td><?= san($cl['descricao'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Defects -->
            <?php if (!empty($data['defeitos'])): ?>
                <div class="doc-section">
                    <h2>Classificação de Defeitos</h2>
                    <table class="doc-table">
                        <thead>
                            <tr>
                                <th>Defeito</th>
                                <th>Tipo</th>
                                <th>Descrição</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['defeitos'] as $d):
                                $tipoLabel = ['critico' => 'Crítico', 'maior' => 'Maior', 'menor' => 'Menor'];
                                $tipoColor = ['critico' => '#b42318', 'maior' => '#b35c00', 'menor' => '#667085'];
                            ?>
                            <tr>
                                <td><strong><?= san($d['nome']) ?></strong></td>
                                <td style="color:<?= $tipoColor[$d['tipo']] ?? '#666' ?>; font-weight:600;">
                                    <?= $tipoLabel[$d['tipo']] ?? $d['tipo'] ?>
                                </td>
                                <td><?= san($d['descricao'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                <span>Documento: <?= san($data['numero']) ?> | Versão <?= san($data['versao']) ?></span>
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
                        <div style="display:flex; gap:12px;">
                            <button type="submit" name="decisao" value="aceite" style="flex:1; padding:12px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer;">Aceitar Documento</button>
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
        <a href="<?= BASE_PATH ?>/pdf.php?id=<?= $data['id'] ?>&code=<?= urlencode($code) ?>" class="btn btn-primary" target="_blank">&#128196; PDF</a>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
