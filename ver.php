<?php
/**
 * SpecLab - Cadernos de Encargos
 * Visualização Completa (utilizadores autenticados)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . BASE_PATH . '/dashboard.php');
    exit;
}

$data = getEspecificacaoCompleta($db, $id);
if (!$data) {
    header('Location: ' . BASE_PATH . '/dashboard.php');
    exit;
}

// Verificar acesso multi-tenant
if (!isSuperAdmin() && ($data['organizacao_id'] ?? null) != $user['org_id']) {
    header('Location: ' . BASE_PATH . '/dashboard.php');
    exit;
}

$org = getOrgByEspecificacao($db, $id);
$temClientes = $org && !empty($org['tem_clientes']);
$temFornecedores = $org && !empty($org['tem_fornecedores']);
$fornecedorDisplay = $data['fornecedor_nome'] ?? '';
if (strpos($fornecedorDisplay, ',') !== false || empty($fornecedorDisplay)) {
    $fornecedorDisplay = 'Todos';
}
$corPrimaria = $org ? $org['cor_primaria'] : '#2596be';
$corPrimariaDark = $org ? $org['cor_primaria_dark'] : '#1a7a9e';
$corPrimariaLight = $org ? $org['cor_primaria_light'] : '#e6f4f9';
$orgNome = $org ? $org['nome'] : 'SpecLab';
$orgLogo = '';
if ($org && $org['logo']) {
    $orgLogo = BASE_PATH . '/uploads/logos/' . $org['logo'];
} else {
    $orgLogo = BASE_PATH . '/assets/img/exi_logo.png';
}

function san(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= san($data['titulo']) ?> - SpecLab</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
    <style>
        .doc-container { max-width: 900px; margin: 0 auto; padding: 24px; }
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
        .doc-meta .meta-full { grid-column: 1 / -1; }
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
        .toolbar {
            position: sticky; top: 0; background: white; z-index: 50; padding: 12px 0;
            border-bottom: 1px solid #e5e7eb; margin-bottom: 24px;
            display: flex; gap: 8px; align-items: center; justify-content: space-between;
        }
        @media print {
            .toolbar, .no-print { display: none !important; }
            @page { size: A4; margin: 15mm; }
            .doc-table th { background: <?= $corPrimaria ?> !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .cat-header td { background: <?= $corPrimariaLight ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body style="background: #f3f4f6;">
    <div class="doc-container">

        <!-- Toolbar -->
        <div class="toolbar no-print">
            <div class="flex gap-sm">
                <a href="<?= BASE_PATH ?>/dashboard.php" class="btn btn-ghost btn-sm">&larr; Voltar</a>
                <a href="<?= BASE_PATH ?>/especificacao.php?id=<?= $data['id'] ?>" class="btn btn-secondary btn-sm">&#9998; Editar</a>
            </div>
            <div class="flex gap-sm">
                <?php if ($data['codigo_acesso']): ?>
                    <button class="btn btn-secondary btn-sm" onclick="copyLink()">&#128279; Link Público</button>
                <?php endif; ?>
                <a href="<?= BASE_PATH ?>/pdf.php?id=<?= $data['id'] ?>" class="btn btn-secondary btn-sm" target="_blank">&#128196; PDF</a>
                <button class="btn btn-primary btn-sm" onclick="window.print()">&#128424; Imprimir</button>
            </div>
        </div>

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
                <div class="meta-full"><span>Produto:</span> <strong><?= san($data['produto_nome'] ?? '-') ?></strong></div>
                <?php if ($temClientes): ?>
                <div class="meta-full"><span>Cliente:</span> <strong><?= san($data['cliente_nome'] ?? 'Geral') ?></strong></div>
                <?php endif; ?>
                <?php if ($temFornecedores): ?>
                <div class="meta-full"><span>Fornecedor:</span> <strong><?= san($fornecedorDisplay) ?></strong></div>
                <?php endif; ?>
                <div><span>Emissão:</span> <strong><?= formatDate($data['data_emissao']) ?></strong></div>
                <div><span>Revisão:</span> <strong><?= $data['data_revisao'] ? formatDate($data['data_revisao']) : '-' ?></strong></div>
                <div><span>Estado:</span> <strong><?= ucfirst($data['estado']) ?></strong></div>
                <div><span>Criado por:</span> <strong><?= san($data['criado_por_nome'] ?? '-') ?></strong></div>
            </div>

            <?php
            // No Ver, ficheiros aparecem sempre na posição do editor
            $ficheirosPos = 'local';
            $ficheirosRendered = false;
            // Preparar lista de ficheiros válidos
            $validFilesVer = [];
            if (!empty($data['ficheiros'])) {
                foreach ($data['ficheiros'] as $f) {
                    if (file_exists(UPLOAD_DIR . $f['nome_servidor'])) {
                        $validFilesVer[] = $f;
                    }
                }
            }
            ?>

            <?php if (!empty($data['seccoes'])): ?>
                <?php foreach ($data['seccoes'] as $i => $sec):
                    $secTipo = $sec['tipo'] ?? 'texto';
                ?>
                    <?php if ($secTipo === 'ficheiros'): ?>
                        <?php if ($ficheirosPos === 'local' && !empty($validFilesVer)):
                            $ficheirosRendered = true; ?>
                            <div class="doc-section">
                                <h2><?= ($i + 1) . '. ' . san($sec['titulo']) ?></h2>
                                <?php foreach ($validFilesVer as $f):
                                    $ext = strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION));
                                    $isPdf = ($ext === 'pdf');
                                    $isImage = in_array($ext, ['jpg','jpeg','png','gif','bmp','tif','tiff']);
                                    $downloadUrl = BASE_PATH . '/download.php?id=' . $f['id'];
                                    $inlineUrl = $downloadUrl . '&inline=1';
                                ?>
                                    <div style="margin-top:12px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                                        <div style="padding:8px 12px; background:#f9fafb; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e5e7eb;">
                                            <span style="font-weight:600; font-size:13px;">&#128196; <?= san($f['nome_original']) ?> <span style="color:#999; font-weight:normal; font-size:11px;">(<?= formatFileSize($f['tamanho']) ?>)</span></span>
                                            <a href="<?= $downloadUrl ?>" style="font-size:12px; color:#2563eb; text-decoration:none;">Descarregar</a>
                                        </div>
                                        <?php if ($isPdf): ?>
                                            <iframe src="<?= $inlineUrl ?>#toolbar=0&navpanes=0&view=FitH" style="width:100%; height:600px; border:none;"></iframe>
                                        <?php elseif ($isImage): ?>
                                            <div style="padding:12px; text-align:center; max-height:500px; overflow:auto;">
                                                <img src="<?= $inlineUrl ?>" style="max-width:100%; max-height:480px;" alt="<?= san($f['nome_original']) ?>">
                                            </div>
                                        <?php else: ?>
                                            <div style="padding:20px; text-align:center; color:#666;">
                                                <p>Pré-visualização não disponível.</p>
                                                <a href="<?= $downloadUrl ?>" style="color:#2563eb;">Descarregar ficheiro</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php continue; ?>
                    <?php endif; ?>

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
                            // Compat: 6 colWidths = formato com Cat+5cols
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
                            // Mapas de merge (col shift: ignorar Categoria)
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
                            // Headers de categoria
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
                                            $mergeStyle = '';
                                            if (isset($alignCells[$key])) {
                                                $mergeStyle = 'vertical-align:' . $alignCells[$key]['v'] . '; text-align:' . $alignCells[$key]['h'] . ';';
                                            }
                                            $val = san($ens[$field] ?? '');
                                        ?>
                                        <td<?= $rs ?><?= $mergeStyle ? ' style="' . $mergeStyle . '"' : '' ?>><?= $field === 'especificacao' ? '<strong>' . $val . '</strong>' : $val ?></td>
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
                                    echo $secContent;
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


            <?php if (!empty($data['classes'])): ?>
                <div class="doc-section">
                    <h2>Classes Visuais</h2>
                    <table class="doc-table">
                        <thead><tr><th>Classe</th><th>Defeitos Máx. (%)</th><th>Descrição</th></tr></thead>
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

            <?php if (!empty($data['defeitos'])): ?>
                <div class="doc-section">
                    <h2>Classificação de Defeitos</h2>
                    <table class="doc-table">
                        <thead><tr><th>Defeito</th><th>Tipo</th><th>Descrição</th></tr></thead>
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

            <?php if (!$ficheirosRendered && !empty($validFilesVer)): ?>
                <div class="doc-section">
                    <h2>Documentos Anexos</h2>
                    <?php foreach ($validFilesVer as $f):
                        $ext = strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION));
                        $isPdf = ($ext === 'pdf');
                        $isImage = in_array($ext, ['jpg','jpeg','png','gif','bmp','tif','tiff']);
                        $downloadUrl = BASE_PATH . '/download.php?id=' . $f['id'];
                        $inlineUrl = $downloadUrl . '&inline=1';
                    ?>
                        <div style="margin-top:12px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                            <div style="padding:8px 12px; background:#f9fafb; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e5e7eb;">
                                <span style="font-weight:600; font-size:13px;">&#128196; <?= san($f['nome_original']) ?> <span style="color:#999; font-weight:normal; font-size:11px;">(<?= formatFileSize($f['tamanho']) ?>)</span></span>
                                <a href="<?= $downloadUrl ?>" style="font-size:12px; color:#2563eb; text-decoration:none;">Descarregar</a>
                            </div>
                            <?php if ($isPdf): ?>
                                <iframe src="<?= $inlineUrl ?>#toolbar=0&navpanes=0&view=FitH" style="width:100%; height:600px; border:none;"></iframe>
                            <?php elseif ($isImage): ?>
                                <div style="padding:12px; text-align:center; max-height:500px; overflow:auto;">
                                    <img src="<?= $inlineUrl ?>" style="max-width:100%; max-height:480px;" alt="<?= san($f['nome_original']) ?>">
                                </div>
                            <?php else: ?>
                                <div style="padding:20px; text-align:center; color:#666;">
                                    <p>Pré-visualização não disponível.</p>
                                    <a href="<?= $downloadUrl ?>" style="color:#2563eb;">Descarregar ficheiro</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="doc-footer">
                <span>&copy; <?= sanitize($orgNome) ?> <?= date('Y') ?></span>
                <span><?= san($data['numero']) ?> | Versão <?= san($data['versao']) ?> | Impresso: <?= date('d/m/Y H:i') ?></span>
            </div>
        </div>
    </div>

    <script>
    function copyLink() {
        const url = window.location.origin + '<?= BASE_PATH ?>/publico.php?code=<?= $data['codigo_acesso'] ?? '' ?>';
        navigator.clipboard.writeText(url).then(() => {
            alert('Link público copiado para a área de transferência!');
        });
    }
    </script>
</body>
</html>
