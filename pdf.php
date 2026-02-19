<?php
/**
 * SpecLab - Cadernos de Encargos
 * Geração de PDF (mPDF ou fallback para impressão)
 */
require_once __DIR__ . '/config/database.php';

ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$code = $_GET['code'] ?? '';

if (!$id) {
    http_response_code(400);
    exit('ID inválido.');
}

// Verificar acesso
$authenticated = false;
if (isset($_SESSION['user_id'])) {
    $authenticated = true;
} elseif ($code) {
    $stmt = $db->prepare('SELECT id, password_acesso FROM especificacoes WHERE id = ? AND codigo_acesso = ?');
    $stmt->execute([$id, $code]);
    $check = $stmt->fetch();
    if ($check) {
        $sessionKey = 'espec_access_' . $id;
        if (empty($check['password_acesso']) || (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true)) {
            $authenticated = true;
        }
    }
}

if (!$authenticated) {
    http_response_code(403);
    exit('Acesso negado.');
}

$data = getEspecificacaoCompleta($db, $id);
if (!$data) {
    http_response_code(404);
    exit('Especificação não encontrada.');
}

// Verificar acesso multi-tenant (utilizadores autenticados sem código público)
if (!$code && isset($_SESSION['user_id'])) {
    $userRole = $_SESSION['user_role'] ?? '';
    $userOrgId = $_SESSION['org_id'] ?? null;
    if ($userRole !== 'super_admin' && ($data['organizacao_id'] ?? null) != $userOrgId) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

// Carregar organização da especificação
$org = null;
$stmtOrg = $db->prepare('SELECT o.* FROM organizacoes o INNER JOIN especificacoes e ON e.organizacao_id = o.id WHERE e.id = ?');
$stmtOrg->execute([$id]);
$org = $stmtOrg->fetch();
$orgNome = $org ? $org['nome'] : getConfiguracao('empresa_nome', 'SpecLab');
$orgLogo = ($org && $org['logo']) ? (UPLOAD_DIR . 'logos/' . $org['logo']) : null;
$orgCorPrimaria = $org ? $org['cor_primaria'] : '#2596be';
$corPrimaria = $orgCorPrimaria;
$corPrimariaDark = $org ? ($org['cor_primaria_dark'] ?? '#1a7a9e') : '#1a7a9e';
$corPrimariaLight = $org ? ($org['cor_primaria_light'] ?? '#e6f4f9') : '#e6f4f9';
$temClientes = $org && !empty($org['tem_clientes']);
$temFornecedores = $org && !empty($org['tem_fornecedores']);
$fornecedorDisplay = $data['fornecedor_nome'] ?? '';
if (strpos($fornecedorDisplay, ',') !== false || empty($fornecedorDisplay)) {
    $fornecedorDisplay = 'Todos';
}

// Registar acesso PDF
$stmt = $db->prepare('INSERT INTO acessos_log (especificacao_id, ip, user_agent, tipo) VALUES (?, ?, ?, ?)');
$stmt->execute([$id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'pdf']);


// Tentar mPDF
$useMpdf = file_exists(__DIR__ . '/vendor/autoload.php');

if ($useMpdf) {
    require_once __DIR__ . '/vendor/autoload.php';

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 30,
        'margin_bottom' => 20,
        'margin_header' => 10,
        'margin_footer' => 10,
        'default_font' => 'dejavusans',
        'default_font_size' => 10,
    ]);

    $mpdf->SetTitle(san($data['titulo']));
    $mpdf->SetAuthor(san($orgNome));

    // Apply PDF template background if configured
    $templateFile = $data['template_pdf'] ?? null;
    if ($templateFile) {
        $templatePath = UPLOAD_DIR . $templateFile;
        if (file_exists($templatePath)) {
            try {
                $pageCount = $mpdf->setSourceFile($templatePath);
                if ($pageCount >= 1) {
                    $tplId = $mpdf->importPage(1);
                    $size = $mpdf->getTemplateSize($tplId);
                    $mpdf->SetPageTemplate($tplId);
                }
            } catch (Exception $e) {
                // Template import failed, continue without
            }
        }
    }

    // Config visual
    $cvDefaults = ['cor_titulos' => $orgCorPrimaria, 'cor_linhas' => $orgCorPrimaria, 'cor_nome' => $orgCorPrimaria, 'tamanho_titulos' => '14', 'tamanho_nome' => '16', 'logo_custom' => ''];
    $cv = $cvDefaults;
    if (!empty($data['config_visual'])) {
        $parsed = is_string($data['config_visual']) ? json_decode($data['config_visual'], true) : $data['config_visual'];
        if (is_array($parsed)) $cv = array_merge($cvDefaults, $parsed);
    }
    $corTitulos = $cv['cor_titulos'];
    $corLinhas  = $cv['cor_linhas'];
    $corNome    = $cv['cor_nome'];
    $tamTitulos = (int)$cv['tamanho_titulos'];
    $tamNome    = (int)$cv['tamanho_nome'];

    // Cor mais clara para backgrounds (aproximação)
    $corLight = $corTitulos . '20'; // com alpha

    // Header - logo (priority: org logo → spec custom logo → fallback exi_logo)
    $logoPath = __DIR__ . '/assets/img/exi_logo.png';
    if ($orgLogo && file_exists($orgLogo)) {
        $logoPath = $orgLogo;
    }
    if (!empty($cv['logo_custom'])) {
        $customLogo = UPLOAD_DIR . 'logos/' . $cv['logo_custom'];
        if (file_exists($customLogo)) $logoPath = $customLogo;
    }
    $logoHtml = file_exists($logoPath) ? '<img src="' . $logoPath . '" height="35">' : san($orgNome);

    $headerHtml = '
        <table width="100%" style="border-bottom: 2pt solid ' . san($corLinhas) . '; margin-bottom: 5mm;">
            <tr>
                <td width="30%">' . $logoHtml . '</td>
                <td width="70%" style="text-align: right;">
                    <span style="font-size: 12pt; font-weight: bold; color: ' . san($corNome) . ';">' . san($data['titulo']) . '</span><br>
                    <span style="font-size: 8pt; color: #666;">' . san($data['numero']) . ' | Versão ' . san($data['versao']) . '</span>
                </td>
            </tr>
        </table>';
    $footerHtml = '
        <table width="100%" style="border-top: 0.5pt solid #ddd; font-size: 8pt; color: #999;">
            <tr>
                <td width="33%">' . san($orgNome) . '</td>
                <td width="33%" style="text-align: center;">Página {PAGENO} de {nbpg}</td>
                <td width="33%" style="text-align: right;">' . san($data['numero']) . '</td>
            </tr>
        </table>';
    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);

    // CSS para PDF
    $css = '
        <style>
            body { font-family: dejavusans; font-size: 10pt; color: #111827; line-height: 1.4; }
            h1 { font-size: ' . $tamNome . 'pt; color: ' . san($corNome) . '; margin-bottom: 5mm; }
            h2 { font-size: ' . $tamTitulos . 'pt; color: ' . san($corTitulos) . '; border-bottom: 1px solid ' . san($corLinhas) . '; padding-bottom: 2mm; margin-top: 6mm; margin-bottom: 3mm; }
            .meta-box { background: #f3f4f6; padding: 3mm; border-radius: 2mm; margin-bottom: 5mm; font-size: 9pt; }
            .meta-grid { width: 100%; }
            .meta-grid td { padding: 1mm 3mm; }
            .meta-label { color: #667085; }
            .meta-value { font-weight: bold; color: #111827; }
            table.params { width: 100%; border-collapse: collapse; margin: 3mm 0; font-size: 9pt; }
            table.params th { background-color: ' . san($corTitulos) . '; color: white; padding: 2mm 3mm; text-align: left; font-weight: 600; }
            table.params td { padding: 1.5mm 3mm; border-bottom: 0.5pt solid #e5e7eb; }
            table.params tr.cat td { background-color: ' . san($corPrimariaLight) . '; font-weight: 600; color: ' . san($corPrimariaDark) . '; text-align: center; }
            .section { margin-bottom: 4mm; }
            .content { font-size: 10pt; line-height: 1.5; }
            .content p { margin: 0 0 2mm; }
            .defeito-critico { color: #b42318; font-weight: bold; }
            .defeito-maior { color: #b35c00; font-weight: bold; }
            .defeito-menor { color: #667085; font-weight: bold; }
            .file-list { margin-top: 2mm; }
            .file-item { padding: 1mm 0; font-size: 9pt; color: #374151; }
        </style>
    ';

    // HTML content
    $html = $css;

    // Meta — full-width rows for Produto/Cliente/Fornecedor, 2-column grid for the rest
    $metaFull = [];
    $metaFull[] = ['Produto', san($data['produto_nome'] ?? '-')];
    if ($temClientes) $metaFull[] = ['Cliente', san($data['cliente_nome'] ?? 'Geral')];
    if ($temFornecedores) $metaFull[] = ['Fornecedor', san($fornecedorDisplay)];
    $metaPaired = [];
    $metaPaired[] = ['Emissão', formatDate($data['data_emissao'])];
    $metaPaired[] = ['Revisão', $data['data_revisao'] ? formatDate($data['data_revisao']) : '-'];
    $metaPaired[] = ['Estado', ucfirst($data['estado'])];
    $metaPaired[] = ['Elaborado por', san($data['criado_por_nome'] ?? '-')];
    $html .= '<div class="meta-box"><table class="meta-grid">';
    foreach ($metaFull as $item) {
        $html .= '<tr><td colspan="2"><span class="meta-label">' . $item[0] . ':</span> <span class="meta-value">' . $item[1] . '</span></td></tr>';
    }
    for ($mi = 0; $mi < count($metaPaired); $mi += 2) {
        $html .= '<tr><td width="50%"><span class="meta-label">' . $metaPaired[$mi][0] . ':</span> <span class="meta-value">' . $metaPaired[$mi][1] . '</span></td>';
        if (isset($metaPaired[$mi + 1])) {
            $html .= '<td width="50%"><span class="meta-label">' . $metaPaired[$mi + 1][0] . ':</span> <span class="meta-value">' . $metaPaired[$mi + 1][1] . '</span></td>';
        } else {
            $html .= '<td></td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table></div>';

    // Preparar lista de ficheiros válidos (antes do loop de secções)
    $validFiles = [];
    if (!empty($data['ficheiros'])) {
        foreach ($data['ficheiros'] as $f) {
            if (file_exists(UPLOAD_DIR . $f['nome_servidor'])) {
                $validFiles[] = $f;
            }
        }
    }

    // Determinar posição dos ficheiros
    $ficheirosPos = 'final';
    $ficheirosRenderedPdf = false;
    if (!empty($data['seccoes'])) {
        foreach ($data['seccoes'] as $sec) {
            if (($sec['tipo'] ?? '') === 'ficheiros') {
                $ficConf = json_decode($sec['conteudo'] ?? '{}', true);
                $ficheirosPos = $ficConf['posicao'] ?? 'final';
            }
        }
    }

    // Secções dinâmicas (prioridade) ou campos fixos (backward compat)
    if (!empty($data['seccoes'])) {
        foreach ($data['seccoes'] as $i => $sec) {
            $secTipo = $sec['tipo'] ?? 'texto';

            // Secção ficheiros
            if ($secTipo === 'ficheiros') {
                if ($ficheirosPos === 'local' && !empty($validFiles)) {
                    $ficheirosRenderedPdf = true;
                    $ficTitulo = ($i + 1) . '. ' . san($sec['titulo'] ?? 'Ficheiros Anexos');
                    // Título na página do relatório (antes dos anexos)
                    $html .= '<div class="section"><h2>' . $ficTitulo . '</h2>';
                    // Lista de ficheiros como referência
                    foreach ($validFiles as $fi => $f) {
                        $html .= '<div style="font-size:9pt; color:#444; margin:1mm 0;">&#8226; ' . san($f['nome_original']) . ' (' . formatFileSize($f['tamanho']) . ')</div>';
                    }
                    $html .= '</div>';
                    $mpdf->WriteHTML($html);
                    $html = '';
                    // PDFs: importar páginas tal como são (sem escala, sem título overlay)
                    $mpdf->SetHTMLHeader('');
                    foreach ($validFiles as $f) {
                        $fExt = strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION));
                        $filepath = UPLOAD_DIR . $f['nome_servidor'];
                        if ($fExt === 'pdf') {
                            try {
                                $pageCount = $mpdf->setSourceFile($filepath);
                                for ($p = 1; $p <= $pageCount; $p++) {
                                    $tplId = $mpdf->importPage($p);
                                    $size = $mpdf->getTemplateSize($tplId);
                                    $wMm = $size['width'] * 25.4 / 72;
                                    $hMm = $size['height'] * 25.4 / 72;
                                    $mpdf->AddPageByArray([
                                        'orientation' => $wMm > $hMm ? 'L' : 'P',
                                        'sheet-size' => [$wMm, $hMm],
                                        'margin-left' => 0, 'margin-right' => 0,
                                        'margin-top' => 0, 'margin-bottom' => 0,
                                        'margin-header' => 0, 'margin-footer' => 0,
                                    ]);
                                    $mpdf->SetHTMLFooter('');
                                    $mpdf->SetPageTemplate('');
                                    $mpdf->useTemplate($tplId, 0, 0, $wMm, $hMm);
                                }
                            } catch (Exception $e) {}
                        } elseif (in_array($fExt, ['jpg','jpeg','png','gif','bmp','tif','tiff'])) {
                            // Imagens: página dedicada com margens
                            $mpdf->SetHTMLHeader($headerHtml);
                            $mpdf->AddPageByArray([
                                'margin-left' => 15, 'margin-right' => 15,
                                'margin-top' => 30, 'margin-bottom' => 20,
                                'margin-header' => 10, 'margin-footer' => 10,
                            ]);
                            $mpdf->SetHTMLFooter($footerHtml);
                            $imgHtml = '<div style="text-align:center;">';
                            $imgHtml .= '<div style="font-size:9pt; color:#666; margin-bottom:3mm;">' . san($f['nome_original']) . '</div>';
                            $imgHtml .= '<img src="' . $filepath . '" style="max-width:170mm; max-height:230mm;">';
                            $imgHtml .= '</div>';
                            $mpdf->WriteHTML($imgHtml);
                            $mpdf->SetHTMLHeader('');
                        }
                    }
                    // Restaurar header/footer para continuar o relatório
                    $mpdf->SetHTMLHeader($headerHtml);
                    $mpdf->AddPageByArray([
                        'margin-left' => 15, 'margin-right' => 15,
                        'margin-top' => 30, 'margin-bottom' => 20,
                        'margin-header' => 10, 'margin-footer' => 10,
                    ]);
                    $mpdf->SetHTMLFooter($footerHtml);
                }
                continue;
            }

            if ($secTipo === 'ensaios') {
                $html .= '<div class="section">';
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
                if (!empty($ensaiosData)) {
                    $headers = ['Ensaio / Controlo','Especificação','Norma','NEI','NQA'];
                    $fields = ['ensaio','especificacao','norma','nivel_especial','nqa'];
                    $secTitulo = san($sec['titulo']);
                    $colspanN = count($headers);
                    // Agrupar linhas por categoria
                    $groups = []; $curCat = null; $curRows = [];
                    foreach ($ensaiosData as $rIdx => $ens) {
                        if (isset($catHeaders[$rIdx]) && !empty($curRows)) {
                            $groups[] = ['cat' => $curCat, 'rows' => $curRows];
                            $curRows = [];
                        }
                        if (isset($catHeaders[$rIdx])) $curCat = $catHeaders[$rIdx];
                        $curRows[] = ['rIdx' => $rIdx, 'data' => $ens];
                    }
                    if (!empty($curRows)) $groups[] = ['cat' => $curCat, 'rows' => $curRows];
                    // Gerar thead colunas
                    $theadCols = '<tr>';
                    foreach ($headers as $hi => $hName) {
                        $theadCols .= '<th style="width:' . $cwPct[$hi] . '%; padding:6px 8px; text-align:left; font-weight:600; background-color:' . $corPrimaria . '; color:white;">' . $hName . '</th>';
                    }
                    $theadCols .= '</tr>';
                    // Título dentro do thead da 1ª tabela (para não separar)
                    $theadTitle = '<tr><td colspan="' . $colspanN . '" style="padding:3px 0 5px; font-size:' . $tamTitulos . 'pt; font-weight:bold; color:' . san($corTitulos) . '; border-bottom:1px solid ' . san($corLinhas) . ';">' . ($i + 1) . '. ' . $secTitulo . '</td></tr>';
                    // Uma tabela por categoria
                    foreach ($groups as $gIdx => $group) {
                        $mt = $gIdx === 0 ? '6px' : '0';
                        $html .= '<table class="params" repeat_header="1" style="width:100%; border-collapse:collapse; font-size:9pt; margin-top:' . $mt . ';">';
                        $html .= '<thead>' . ($gIdx === 0 ? $theadTitle : '') . $theadCols;
                        if ($group['cat']) {
                            $html .= '<tr><td colspan="' . $colspanN . '" style="background-color:' . $corPrimariaLight . '; font-weight:600; padding:5px 8px; color:' . $corPrimariaDark . '; text-align:center; border-bottom:1px solid #d1d5db;">' . san($group['cat']) . '</td></tr>';
                        }
                        $html .= '</thead><tbody>';
                        foreach ($group['rows'] as $row) {
                            $rIdx = $row['rIdx'];
                            $ens = $row['data'];
                            $html .= '<tr>';
                            foreach ($fields as $cIdx => $field) {
                                $key = $rIdx . '_' . $cIdx;
                                if (isset($hiddenCells[$key])) continue;
                                $rs = isset($spanCells[$key]) ? ' rowspan="' . $spanCells[$key] . '"' : '';
                                $ms = isset($alignCells[$key]) ? ' vertical-align:' . $alignCells[$key]['v'] . '; text-align:' . $alignCells[$key]['h'] . ';' : '';
                                $fw = ($field === 'especificacao') ? ' font-weight:bold;' : '';
                                $html .= '<td' . $rs . ' style="padding:4px 8px; border-bottom:1px solid #e5e7eb;' . $fw . $ms . '">' . san($ens[$field] ?? '') . '</td>';
                            }
                            $html .= '</tr>';
                        }
                        $html .= '</tbody></table>';
                    }
                    $html .= '<p style="font-size:7pt; color:#888; margin:2px 0 0 0;">NEI — Nível Especial de Inspeção &nbsp;|&nbsp; NQA — Nível de Qualidade Aceitável &nbsp;(NP 2922)</p>';
                }
            } else {
                $html .= '<div class="section"><h2>' . ($i + 1) . '. ' . san($sec['titulo']) . '</h2>';
                $secContent = $sec['conteudo'] ?? '';
                if (strip_tags($secContent) === $secContent) {
                    $secContent = nl2br(san($secContent));
                } else {
                    $secContent = sanitizeRichText($secContent);
                }
                $html .= '<div class="content">' . $secContent . '</div>';
            }

            $html .= '</div>';
        }
    } else {
        // Backward compat: campos fixos
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

        foreach ($sections as $key => $title) {
            if (!empty($data[$key])) {
                $html .= '<div class="section"><h2>' . $title . '</h2>';
                $content = $data[$key];
                if (strip_tags($content) === $content) {
                    $content = nl2br(san($content));
                }
                $html .= '<div class="content">' . $content . '</div></div>';
            }
        }
    }


    // Visual classes
    if (!empty($data['classes'])) {
        $html .= '<div class="section"><h2>Classes Visuais</h2>';
        $html .= '<table class="params"><thead><tr><th>Classe</th><th>Defeitos Máx. (%)</th><th>Descrição</th></tr></thead><tbody>';
        foreach ($data['classes'] as $cl) {
            $html .= '<tr><td><strong>' . san($cl['classe']) . '</strong></td>';
            $html .= '<td>' . $cl['defeitos_max'] . '%</td>';
            $html .= '<td>' . san($cl['descricao'] ?? '') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }

    // Defects
    if (!empty($data['defeitos'])) {
        $html .= '<div class="section"><h2>Classificação de Defeitos</h2>';
        $html .= '<table class="params"><thead><tr><th>Defeito</th><th>Tipo</th><th>Descrição</th></tr></thead><tbody>';
        $tipoLabel = ['critico' => 'Crítico', 'maior' => 'Maior', 'menor' => 'Menor'];
        $tipoClass = ['critico' => 'defeito-critico', 'maior' => 'defeito-maior', 'menor' => 'defeito-menor'];
        foreach ($data['defeitos'] as $d) {
            $html .= '<tr><td><strong>' . san($d['nome']) . '</strong></td>';
            $html .= '<td class="' . ($tipoClass[$d['tipo']] ?? '') . '">' . ($tipoLabel[$d['tipo']] ?? $d['tipo']) . '</td>';
            $html .= '<td>' . san($d['descricao'] ?? '') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }

    // Files list at end (only if not rendered inline)
    if (!$ficheirosRenderedPdf && !empty($validFiles)) {
        $html .= '<div class="section"><h2>Documentos Anexos</h2><div class="file-list">';
        foreach ($validFiles as $f) {
            $html .= '<div class="file-item">&#8226; ' . san($f['nome_original']) . ' (' . formatFileSize($f['tamanho']) . ')</div>';
        }
        $html .= '</div></div>';
    }

    // Signature block
    $html .= '<div style="margin-top: 15mm; padding-top: 5mm; border-top: 1px solid #e5e7eb;">';
    $html .= '<table width="100%"><tr>';
    $html .= '<td width="50%" style="text-align: center; padding-top: 15mm; border-top: 1px solid #999; font-size: 9pt;">Elaborado por</td>';
    $html .= '<td width="50%" style="text-align: center; padding-top: 15mm; border-top: 1px solid #999; font-size: 9pt;">Aprovação Cliente</td>';
    $html .= '</tr></table></div>';

    $mpdf->WriteHTML($html);

    // Anexos no final (só quando posição = final)
    if (!$ficheirosRenderedPdf && !empty($validFiles)) {
        // PDFs: importar páginas tal como são
        $mpdf->SetHTMLHeader('');
        foreach ($validFiles as $f) {
            $ext = strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION));
            $filepath = UPLOAD_DIR . $f['nome_servidor'];
            if ($ext === 'pdf') {
                try {
                    $mpdf->SetPageTemplate('');
                    $pageCount = $mpdf->setSourceFile($filepath);
                    for ($p = 1; $p <= $pageCount; $p++) {
                        $tplId = $mpdf->importPage($p);
                        $size = $mpdf->getTemplateSize($tplId);
                        $wMm = $size['width'] * 25.4 / 72;
                        $hMm = $size['height'] * 25.4 / 72;
                        $mpdf->AddPageByArray([
                            'orientation' => $wMm > $hMm ? 'L' : 'P',
                            'sheet-size' => [$wMm, $hMm],
                            'margin-left' => 0, 'margin-right' => 0,
                            'margin-top' => 0, 'margin-bottom' => 0,
                            'margin-header' => 0, 'margin-footer' => 0,
                        ]);
                        $mpdf->SetHTMLFooter('');
                        $mpdf->SetPageTemplate('');
                        $mpdf->useTemplate($tplId, 0, 0, $wMm, $hMm);
                    }
                } catch (Exception $e) {}
            } elseif (in_array($ext, ['jpg','jpeg','png','gif','bmp','tif','tiff'])) {
                // Imagens: página dedicada com margens
                $mpdf->SetHTMLHeader($headerHtml);
                $mpdf->AddPageByArray([
                    'margin-left' => 15, 'margin-right' => 15,
                    'margin-top' => 30, 'margin-bottom' => 20,
                    'margin-header' => 10, 'margin-footer' => 10,
                ]);
                $mpdf->SetHTMLFooter($footerHtml);
                $imgHtml = '<div style="text-align:center;">';
                $imgHtml .= '<div style="font-size:9pt; color:#666; margin-bottom:3mm;">' . san($f['nome_original']) . '</div>';
                $imgHtml .= '<img src="' . $filepath . '" style="max-width:170mm; max-height:230mm;">';
                $imgHtml .= '</div>';
                $mpdf->WriteHTML($imgHtml);
                $mpdf->SetHTMLHeader('');
            }
        }
    }

    // PDF Protection
    $protegido = !empty($data['pdf_protegido']);
    if ($protegido) {
        $ownerPass = bin2hex(random_bytes(8));
        $userPass = '';
        $mpdf->SetProtection(['print', 'print-highres'], $userPass, $ownerPass);
    }

    // Signature block with actual signature image if configured
    $assinaturaNome = $data['assinatura_nome'] ?? '';
    if ($assinaturaNome) {
        // Check for signature image
        $stmt = $db->prepare('SELECT assinatura FROM utilizadores WHERE nome = ? OR username = ? LIMIT 1');
        $stmt->execute([$assinaturaNome, $assinaturaNome]);
        $sigUser = $stmt->fetch();
        $sigPath = ($sigUser && !empty($sigUser['assinatura'])) ? UPLOAD_DIR . $sigUser['assinatura'] : '';

        $mpdf->AddPageByArray([
            'orientation' => 'P',
            'sheet-size' => [210, 297],
            'margin-left' => 15, 'margin-right' => 15,
            'margin-top' => 30, 'margin-bottom' => 20,
            'margin-header' => 10, 'margin-footer' => 10,
        ]);
        $mpdf->SetHTMLHeader($headerHtml);
        $mpdf->SetHTMLFooter($footerHtml);
        $sigHtml = '<div style="margin-top: 20mm; text-align: center;">';
        $sigHtml .= '<h2 style="color: ' . san($orgCorPrimaria) . '; font-size: 12pt;">Assinatura / Aprovação</h2>';
        if ($sigPath && file_exists($sigPath)) {
            $sigHtml .= '<div style="margin: 10mm auto;"><img src="' . $sigPath . '" height="60"></div>';
        }
        $sigHtml .= '<div style="border-top: 1px solid #999; width: 200px; margin: 5mm auto; padding-top: 3mm; font-size: 9pt;">' . san($assinaturaNome) . '</div>';
        $sigHtml .= '<div style="font-size: 8pt; color: #667085;">' . date('d/m/Y') . '</div>';
        $sigHtml .= '</div>';
        $mpdf->WriteHTML($sigHtml);
    }

    $filename = 'Caderno_Encargos_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['numero']) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}

// Fallback: HTML para impressão (sem mPDF)
$cvDefaults = ['cor_titulos' => $orgCorPrimaria, 'cor_linhas' => $orgCorPrimaria, 'cor_nome' => $orgCorPrimaria, 'tamanho_titulos' => '14', 'tamanho_nome' => '16', 'logo_custom' => ''];
$cv = $cvDefaults;
if (!empty($data['config_visual'])) {
    $parsed = is_string($data['config_visual']) ? json_decode($data['config_visual'], true) : $data['config_visual'];
    if (is_array($parsed)) $cv = array_merge($cvDefaults, $parsed);
}
$corTitulos = $cv['cor_titulos'];
$corLinhas  = $cv['cor_linhas'];
$corNome    = $cv['cor_nome'];
$tamTitulos = (int)$cv['tamanho_titulos'];
$tamNome    = (int)$cv['tamanho_nome'];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF - <?= san($data['titulo']) ?></title>
    <style>
        @page { size: A4; margin: 15mm; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 10pt; color: #111827; line-height: 1.4; margin: 0; padding: 20px; background: white; }
        .toolbar { background: #f3f4f6; padding: 12px 20px; margin: -20px -20px 20px; display: flex; gap: 10px; align-items: center; border-bottom: 1px solid #e5e7eb; }
        .toolbar .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; }
        .btn-primary { background: <?= san($corTitulos) ?>; color: white; }
        .btn-secondary { background: white; color: #111827; border: 1px solid #e5e7eb; }
        .btn:hover { opacity: 0.9; }
        h1 { font-size: <?= $tamNome ?>pt; color: <?= san($corNome) ?>; margin: 0 0 5mm; }
        h2 { font-size: <?= $tamTitulos ?>pt; color: <?= san($corTitulos) ?>; border-bottom: 1px solid <?= san($corLinhas) ?>; padding-bottom: 2mm; margin: 6mm 0 3mm; }
        .header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 3mm; border-bottom: 3pt solid <?= san($corLinhas) ?>; margin-bottom: 5mm; }
        .header img { height: 48px; }
        .header .title { text-align: right; }
        .header .title h1 { margin: 0; font-size: 14pt; }
        .header .title p { margin: 2px 0 0; font-size: 9pt; color: #667085; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; background: #f3f4f6; padding: 8px 12px; border-radius: 4px; margin-bottom: 5mm; font-size: 9pt; }
        .meta .meta-full { grid-column: 1 / -1; }
        .meta strong { color: #111827; }
        .meta span { color: #667085; }
        table.params { width: 100%; border-collapse: collapse; margin: 3mm 0; font-size: 9pt; }
        table.params th { background: <?= san($corTitulos) ?>; color: white; padding: 6px 8px; text-align: left; font-weight: 600; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.params td { padding: 4px 8px; border-bottom: 0.5pt solid #e5e7eb; }
        table.params tr.cat td { background: <?= san($corPrimariaLight) ?>; font-weight: 600; color: <?= san($corPrimariaDark) ?>; text-align: center; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .content { font-size: 10pt; line-height: 1.5; white-space: pre-wrap; }
        .section { margin-bottom: 4mm; page-break-inside: avoid; }
        .footer { margin-top: 10mm; padding-top: 3mm; border-top: 0.5pt solid #ddd; font-size: 8pt; color: #999; display: flex; justify-content: space-between; }
        .signatures { margin-top: 15mm; display: flex; gap: 20mm; }
        .sig-box { flex: 1; text-align: center; padding-top: 15mm; border-top: 1px solid #999; font-size: 9pt; color: #667085; }
        .embed-section { page-break-before: always; margin-top: 10mm; }
        .embed-section h3 { font-size: 11pt; color: <?= san($corTitulos) ?>; margin-bottom: 5mm; }
        .embed-frame { width: 100%; height: 80vh; border: 1px solid #e5e7eb; border-radius: 4px; }
        @media print {
            .toolbar { display: none !important; }
            body { padding: 0; }
            .embed-frame { height: 100%; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn btn-primary" onclick="window.print()">&#128424; Imprimir / Guardar PDF</button>
        <a href="<?= BASE_PATH ?>/ver.php?id=<?= $data['id'] ?>" class="btn btn-secondary">&larr; Voltar</a>
        <span style="color: #667085; font-size: 12px; margin-left: auto;">
            Use Ctrl+P para gerar o PDF. Para mPDF nativo, instale via install.php.
        </span>
    </div>

    <!-- Document -->
    <div class="header">
        <?php
        // Logo priority: org logo → spec custom logo → fallback exi_logo
        $fallbackLogoSrc = BASE_PATH . '/assets/img/exi_logo.png';
        $fallbackLogoAlt = san($orgNome);
        if ($orgLogo && file_exists($orgLogo)) {
            $fallbackLogoSrc = BASE_PATH . '/uploads/logos/' . ($org['logo'] ?? '');
        }
        if (!empty($cv['logo_custom'])) {
            $customLogoCheck = UPLOAD_DIR . 'logos/' . $cv['logo_custom'];
            if (file_exists($customLogoCheck)) {
                $fallbackLogoSrc = BASE_PATH . '/uploads/logos/' . $cv['logo_custom'];
            }
        }
        ?>
        <img src="<?= $fallbackLogoSrc ?>" alt="<?= $fallbackLogoAlt ?>" onerror="this.style.display='none'">
        <div class="title">
            <h1><?= san($data['titulo']) ?></h1>
            <p><?= san($data['numero']) ?> | Versão <?= san($data['versao']) ?></p>
        </div>
    </div>

    <div class="meta">
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
        <div><span>Elaborado por:</span> <strong><?= san($data['criado_por_nome'] ?? '-') ?></strong></div>
    </div>

    <?php if (!empty($data['seccoes'])): ?>
        <?php foreach ($data['seccoes'] as $i => $sec):
            $secTipo = $sec['tipo'] ?? 'texto';
            if ($secTipo === 'ficheiros') continue;
        ?>
            <div class="section">
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
                    $fields = ['ensaio','especificacao','norma','nivel_especial','nqa'];
                    ?>
                    <?php if (!empty($ensaiosData)): ?>
                    <table class="doc-table">
                        <thead>
                            <tr>
                                <th style="width:<?= $cwPct[0] ?>%; background:<?= $corPrimaria ?>; color:white; padding:6px 10px; text-align:left; font-weight:600;">Ensaio / Controlo</th>
                                <th style="width:<?= $cwPct[1] ?>%; background:<?= $corPrimaria ?>; color:white; padding:6px 10px; text-align:left; font-weight:600;">Especificação</th>
                                <th style="width:<?= $cwPct[2] ?>%; background:<?= $corPrimaria ?>; color:white; padding:6px 10px; text-align:left; font-weight:600;">Norma</th>
                                <th style="width:<?= $cwPct[3] ?>%; background:<?= $corPrimaria ?>; color:white; padding:6px 10px; text-align:left; font-weight:600;">NEI</th>
                                <th style="width:<?= $cwPct[4] ?>%; background:<?= $corPrimaria ?>; color:white; padding:6px 10px; text-align:left; font-weight:600;">NQA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ensaiosData as $rIdx => $ens):
                                if (isset($catHeaders[$rIdx])):
                            ?>
                            <tr><td colspan="5" style="background:<?= $corPrimariaLight ?>; font-weight:600; padding:6px 10px; color:<?= $corPrimariaDark ?>; text-align:center;"><?= san($catHeaders[$rIdx]) ?></td></tr>
                            <?php endif; ?>
                            <tr>
                                <?php foreach ($fields as $cIdx => $field):
                                    $key = $rIdx . '_' . $cIdx;
                                    if (isset($hiddenCells[$key])) continue;
                                    $rs = isset($spanCells[$key]) ? ' rowspan="' . $spanCells[$key] . '"' : '';
                                    $ms = isset($alignCells[$key]) ? ' style="vertical-align:' . $alignCells[$key]['v'] . '; text-align:' . $alignCells[$key]['h'] . ';"' : '';
                                ?>
                                <td<?= $rs ?><?= $ms ?>><?= $field === 'especificacao' ? '<strong>' . san($ens[$field] ?? '') . '</strong>' : san($ens[$field] ?? '') ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="font-size:10px; color:#888; margin:2px 0 0 0;">NEI — Nível Especial de Inspeção &nbsp;|&nbsp; NQA — Nível de Qualidade Aceitável &nbsp;(NP 2922)</p>
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
            <div class="section"><h2><?= $title ?></h2><div class="content"><?php
                $content = $data[$key];
                if (strip_tags($content) === $content) {
                    echo nl2br(san($content));
                } else {
                    echo $content;
                }
            ?></div></div>
        <?php endif; endforeach; ?>
    <?php endif; ?>


    <?php if (!empty($data['classes'])): ?>
        <div class="section">
            <h2>Classes Visuais</h2>
            <table class="params">
                <thead><tr><th>Classe</th><th>Defeitos Máx. (%)</th><th>Descrição</th></tr></thead>
                <tbody>
                <?php foreach ($data['classes'] as $cl): ?>
                    <tr><td><strong><?= san($cl['classe']) ?></strong></td><td><?= $cl['defeitos_max'] ?>%</td><td><?= san($cl['descricao'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($data['defeitos'])): ?>
        <div class="section">
            <h2>Classificação de Defeitos</h2>
            <table class="params">
                <thead><tr><th>Defeito</th><th>Tipo</th><th>Descrição</th></tr></thead>
                <tbody>
                <?php
                $tipoLabel = ['critico' => 'Crítico', 'maior' => 'Maior', 'menor' => 'Menor'];
                $tipoColor = ['critico' => '#b42318', 'maior' => '#b35c00', 'menor' => '#667085'];
                foreach ($data['defeitos'] as $d):
                ?>
                    <tr>
                        <td><strong><?= san($d['nome']) ?></strong></td>
                        <td style="color:<?= $tipoColor[$d['tipo']] ?? '#666' ?>; font-weight:bold;"><?= $tipoLabel[$d['tipo']] ?? $d['tipo'] ?></td>
                        <td><?= san($d['descricao'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php
    $validFilesFallback = [];
    if (!empty($data['ficheiros'])) {
        foreach ($data['ficheiros'] as $f) {
            if (file_exists(UPLOAD_DIR . $f['nome_servidor'])) {
                $validFilesFallback[] = $f;
            }
        }
    }
    if (!empty($validFilesFallback)): ?>
        <div class="section">
            <h2>Documentos Anexos</h2>
            <?php foreach ($validFilesFallback as $f): ?>
                <div style="padding:2px 0; font-size:9pt;">
                    &#8226; <?= san($f['nome_original']) ?> (<?= formatFileSize($f['tamanho']) ?>)
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($validFilesFallback as $f):
            $ext = strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION));
            if ($ext === 'pdf'):
        ?>
            <div class="embed-section">
                <h3>Anexo: <?= san($f['nome_original']) ?></h3>
                <iframe class="embed-frame" src="<?= BASE_PATH ?>/download.php?id=<?= $f['id'] ?>&inline=1<?= $code ? '&code=' . urlencode($code) : '' ?>" type="application/pdf"></iframe>
            </div>
        <?php endif; endforeach; ?>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="signatures">
        <div class="sig-box">Elaborado por</div>
        <div class="sig-box">Aprovação Cliente</div>
    </div>

    <div class="footer">
        <span>&copy; <?= san($orgNome) ?> <?= date('Y') ?></span>
        <span><?= san($data['numero']) ?> | Versão <?= san($data['versao']) ?> | <?= date('d/m/Y') ?></span>
    </div>
</body>
</html>
