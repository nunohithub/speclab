<?php
/**
 * SpecLab - Cadernos de Encargos
 * Geração de PDF (mPDF ou fallback para impressão)
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');


require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$code = $_GET['code'] ?? '';
$tokenStr = $_GET['token'] ?? '';
$tokenData = null;

// Acesso via token (fornecedor)
if ($tokenStr && !$id) {
    $stmtTk = $db->prepare('SELECT t.*, e.id as espec_id FROM especificacao_tokens t INNER JOIN especificacoes e ON e.id = t.especificacao_id WHERE t.token = ? AND t.ativo = 1');
    $stmtTk->execute([$tokenStr]);
    $tokenData = $stmtTk->fetch();
    if ($tokenData) $id = (int)$tokenData['espec_id'];
}

if (!$id) {
    http_response_code(400);
    exit('ID inválido.');
}

// Verificar acesso
$authenticated = false;
if (isset($_SESSION['user_id'])) {
    $authenticated = true;
} elseif ($tokenData) {
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

// Traduções dos rótulos do PDF conforme idioma da spec
$lang = $data['idioma'] ?? 'pt';
$labels = [
    'pt' => ['produto'=>'Produto','cliente'=>'Cliente','fornecedor'=>'Fornecedor','emissao'=>'Data de Emissão','revisao'=>'Revisão','estado'=>'Estado','elaborado_por'=>'Elaborado por','aprovado_por'=>'Aprovado por','aprovacao'=>'Aprovação','aprovacoes'=>'Aprovações','aprovacao_interna'=>'Aprovação Interna','aceitacao'=>'Aceitação','pendente'=>'Pendente','aguarda'=>'Aguarda validação','pagina'=>'Página','de'=>'de','assinatura'=>'Assinatura / Aprovação','versao'=>'Versão','ensaio'=>'Ensaio / Controlo','especificacao'=>'Especificação','norma'=>'Norma','nei'=>'NEI','nqa'=>'NQA','rascunho'=>'RASCUNHO','ficheiros'=>'Ficheiros Anexos','pendente_aceitacao'=>'PENDENTE DE ACEITAÇÃO'],
    'en' => ['produto'=>'Product','cliente'=>'Client','fornecedor'=>'Supplier','emissao'=>'Issue Date','revisao'=>'Revision','estado'=>'Status','elaborado_por'=>'Prepared by','aprovado_por'=>'Approved by','aprovacao'=>'Approval','aprovacoes'=>'Approvals','aprovacao_interna'=>'Internal Approval','aceitacao'=>'Acceptance','pendente'=>'Pending','aguarda'=>'Awaiting validation','pagina'=>'Page','de'=>'of','assinatura'=>'Signature / Approval','versao'=>'Version','ensaio'=>'Test / Control','especificacao'=>'Specification','norma'=>'Standard','nei'=>'SIL','nqa'=>'AQL','rascunho'=>'DRAFT','ficheiros'=>'Attached Files','pendente_aceitacao'=>'PENDING ACCEPTANCE'],
    'es' => ['produto'=>'Producto','cliente'=>'Cliente','fornecedor'=>'Proveedor','emissao'=>'Fecha de Emisión','revisao'=>'Revisión','estado'=>'Estado','elaborado_por'=>'Elaborado por','aprovado_por'=>'Aprobado por','aprovacao'=>'Aprobación','aprovacoes'=>'Aprobaciones','aprovacao_interna'=>'Aprobación Interna','aceitacao'=>'Aceptación','pendente'=>'Pendiente','aguarda'=>'Pendiente de validación','pagina'=>'Página','de'=>'de','assinatura'=>'Firma / Aprobación','versao'=>'Versión','ensaio'=>'Ensayo / Control','especificacao'=>'Especificación','norma'=>'Norma','nei'=>'NEI','nqa'=>'NCA','rascunho'=>'BORRADOR','ficheiros'=>'Archivos Adjuntos','pendente_aceitacao'=>'PENDIENTE DE ACEPTACIÓN'],
    'fr' => ['produto'=>'Produit','cliente'=>'Client','fornecedor'=>'Fournisseur','emissao'=>'Date d\'émission','revisao'=>'Révision','estado'=>'Statut','elaborado_por'=>'Préparé par','aprovado_por'=>'Approuvé par','aprovacao'=>'Approbation','aprovacoes'=>'Approbations','aprovacao_interna'=>'Approbation Interne','aceitacao'=>'Acceptation','pendente'=>'En attente','aguarda'=>'En attente de validation','pagina'=>'Page','de'=>'de','assinatura'=>'Signature / Approbation','versao'=>'Version','ensaio'=>'Essai / Contrôle','especificacao'=>'Spécification','norma'=>'Norme','nei'=>'NEI','nqa'=>'NQA','rascunho'=>'BROUILLON','ficheiros'=>'Fichiers Joints','pendente_aceitacao'=>'EN ATTENTE D\'ACCEPTATION'],
    'de' => ['produto'=>'Produkt','cliente'=>'Kunde','fornecedor'=>'Lieferant','emissao'=>'Ausgabedatum','revisao'=>'Revision','estado'=>'Status','elaborado_por'=>'Erstellt von','aprovado_por'=>'Genehmigt von','aprovacao'=>'Genehmigung','aprovacoes'=>'Freigaben','aprovacao_interna'=>'Interne Freigabe','aceitacao'=>'Annahme','pendente'=>'Ausstehend','aguarda'=>'Warten auf Validierung','pagina'=>'Seite','de'=>'von','assinatura'=>'Unterschrift / Genehmigung','versao'=>'Version','ensaio'=>'Prüfung / Kontrolle','especificacao'=>'Spezifikation','norma'=>'Norm','nei'=>'NEI','nqa'=>'AQL','rascunho'=>'ENTWURF','ficheiros'=>'Angehängte Dateien','pendente_aceitacao'=>'ANNAHME AUSSTEHEND'],
    'it' => ['produto'=>'Prodotto','cliente'=>'Cliente','fornecedor'=>'Fornitore','emissao'=>'Data di Emissione','revisao'=>'Revisione','estado'=>'Stato','elaborado_por'=>'Preparato da','aprovado_por'=>'Approvato da','aprovacao'=>'Approvazione','aprovacoes'=>'Approvazioni','aprovacao_interna'=>'Approvazione Interna','aceitacao'=>'Accettazione','pendente'=>'In sospeso','aguarda'=>'In attesa di validazione','pagina'=>'Pagina','de'=>'di','assinatura'=>'Firma / Approvazione','versao'=>'Versione','ensaio'=>'Prova / Controllo','especificacao'=>'Specifica','norma'=>'Norma','nei'=>'NEI','nqa'=>'NQA','rascunho'=>'BOZZA','ficheiros'=>'File Allegati','pendente_aceitacao'=>'IN ATTESA DI ACCETTAZIONE'],
];
$L = $labels[$lang] ?? $labels['pt'];

// Carregar organização da especificação
$org = null;
$stmtOrg = $db->prepare('SELECT o.* FROM organizacoes o INNER JOIN especificacoes e ON e.organizacao_id = o.id WHERE e.id = ?');
$stmtOrg->execute([$id]);
$org = $stmtOrg->fetch();
$orgNome = $org ? $org['nome'] : getConfiguracao('empresa_nome', 'SpecLab');
$orgLogo = ($org && $org['logo']) ? (UPLOAD_DIR . 'logos/' . $org['logo']) : null;
$cores = getOrgColors($org);
$orgCorPrimaria = sanitizeColor($cores['primaria']);
$corPrimaria = $orgCorPrimaria;
$corPrimariaDark = sanitizeColor($cores['primaria_dark'], '#1a7a9e');
$corPrimariaLight = sanitizeColor($cores['primaria_light'], '#e6f4f9');
$temClientes = $org && !empty($org['tem_clientes']);
$temFornecedores = $org && !empty($org['tem_fornecedores']);
$fornecedorDisplay = $data['fornecedor_nome'] ?? '';
if (strpos($fornecedorDisplay, ',') !== false || empty($fornecedorDisplay)) {
    $fornecedorDisplay = 'Todos';
}

// Registar acesso PDF
$stmt = $db->prepare('INSERT INTO acessos_log (especificacao_id, ip, user_agent, tipo) VALUES (?, ?, ?, ?)');
$stmt->execute([$id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'pdf']);

// Dados de validação (elaboração + aceitação)
$elaboradoNome = '';
$elaboradoData = '';
$elaboradoAssinatura = '';
if (!empty($data['publicado_por'])) {
    $stmtElab = $db->prepare('SELECT nome, assinatura FROM utilizadores WHERE id = ?');
    $stmtElab->execute([$data['publicado_por']]);
    $elab = $stmtElab->fetch();
    if ($elab) {
        $elaboradoNome = $elab['nome'];
        $elaboradoAssinatura = $elab['assinatura'] ?? '';
        $elaboradoData = !empty($data['publicado_em']) ? date('d/m/Y H:i', strtotime($data['publicado_em'])) : '';
    }
}
// Aprovação interna
$aprovadoNome = ''; $aprovadoData = ''; $aprovadoAssinatura = '';
if (!empty($data['aprovado_por'])) {
    $stmtAprovInt = $db->prepare('SELECT nome, assinatura FROM utilizadores WHERE id = ?');
    $stmtAprovInt->execute([$data['aprovado_por']]);
    $apInt = $stmtAprovInt->fetch();
    if ($apInt) {
        $aprovadoNome = $apInt['nome'];
        $aprovadoAssinatura = $apInt['assinatura'] ?? '';
        $aprovadoData = !empty($data['aprovado_em']) ? date('d/m/Y H:i', strtotime($data['aprovado_em'])) : '';
    }
}
// Se acesso via token, mostrar apenas a decisão deste token; senão, todas
if ($tokenData) {
    $stmtAceite = $db->prepare('SELECT a.*, t.tipo_destinatario FROM especificacao_aceitacoes a LEFT JOIN especificacao_tokens t ON a.token_id = t.id WHERE a.token_id = ? ORDER BY a.created_at DESC LIMIT 1');
    $stmtAceite->execute([$tokenData['id']]);
} else {
    $stmtAceite = $db->prepare('SELECT a.*, t.tipo_destinatario FROM especificacao_aceitacoes a LEFT JOIN especificacao_tokens t ON a.token_id = t.id WHERE a.especificacao_id = ? AND a.tipo_decisao = "aceite" ORDER BY a.created_at DESC LIMIT 1');
    $stmtAceite->execute([$id]);
}
$aceite = $stmtAceite->fetch();
$tipoDestinatario = $aceite ? ($aceite['tipo_destinatario'] === 'cliente' ? 'Cliente' : 'Fornecedor') : (!empty($data['fornecedor_nome']) ? 'Fornecedor' : 'Cliente');


// Tentar mPDF
$useMpdf = file_exists(__DIR__ . '/vendor/autoload.php');

if ($useMpdf) {
    require_once __DIR__ . '/vendor/autoload.php';

    $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 25,
        'margin_bottom' => 18,
        'margin_header' => 8,
        'margin_footer' => 8,
        'default_font' => 'dejavusans',
        'default_font_size' => 10,
        'tempDir' => $tmpDir,
    ]);

    $mpdf->SetTitle(san($data['titulo']));
    $mpdf->SetAuthor(san($orgNome));

    // Watermark RASCUNHO se não publicado
    if (($data['estado'] ?? 'rascunho') !== 'ativo') {
        $mpdf->SetWatermarkText($L['rascunho'], 0.08);
        $mpdf->showWatermarkText = true;
    }
    // Watermark PENDENTE DE ACEITAÇÃO se publicado mas sem aceite externo
    elseif (!$aceite) {
        $mpdf->SetWatermarkText($L['pendente_aceitacao'], 0.08);
        $mpdf->watermarkTextAlpha = 0.08;
        $mpdf->watermark_font = 'dejavusans';
        $mpdf->showWatermarkText = true;
    }

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
    $cv = parseConfigVisual($data['config_visual'] ?? null, $orgCorPrimaria);
    $corTitulos = $cv['cor_titulos'];
    $corSubtitulos = $cv['cor_subtitulos'];
    $corLinhas  = $cv['cor_linhas'];
    $corNome    = $cv['cor_nome'];
    $tamTitulos = (int)$cv['tamanho_titulos'];
    $tamSubtitulos = (int)$cv['tamanho_subtitulos'];
    $subBold = ($cv['subtitulos_bold'] ?? '1') === '1' ? 'bold' : 'normal';
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
    $logoHtml = file_exists($logoPath) ? '<img src="' . $logoPath . '" style="height:10mm; width:auto; max-width:35mm;">' : san($orgNome);

    $headerHtml = '<table width="100%" style="border-bottom:2pt solid ' . san($corLinhas) . ';"><tr>'
        . '<td width="25%" style="vertical-align:middle;">' . $logoHtml . '</td>'
        . '<td width="75%" style="text-align:right;vertical-align:middle;">'
        . '<span style="font-size:' . $tamNome . 'pt;font-weight:bold;color:' . san($corNome) . ';">' . san($data['titulo']) . '</span><br>'
        . '<span style="font-size:8pt;color:#666;">' . san($data['numero']) . ' | ' . $L['versao'] . ' ' . san($data['versao']) . '</span>'
        . '</td></tr></table>';
    $footerHtml = '<table width="100%" style="border-top:0.5pt solid #ddd;font-size:8pt;color:#999;"><tr>'
        . '<td width="33%">' . san($orgNome) . '</td>'
        . '<td width="33%" style="text-align:center;">' . $L['pagina'] . ' {PAGENO} ' . $L['de'] . ' {nbpg}</td>'
        . '<td width="33%" style="text-align:right;">Powered by SpecLab &copy;' . date('Y') . '</td>'
        . '</tr></table>';
    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);

    // CSS para PDF — carregado do ficheiro externo
    $pdfCssRaw = file_get_contents(__DIR__ . '/assets/css/pdf.css');
    $pdfCssRaw = str_replace(
        ['{{COR_TITULOS}}', '{{COR_SUBTITULOS}}', '{{COR_LINHAS}}', '{{COR_NOME}}', '{{COR_PRIMARIA}}', '{{COR_PRIMARIA_LIGHT}}', '{{COR_PRIMARIA_DARK}}', '{{TAM_TITULOS}}', '{{TAM_SUBTITULOS}}', '{{SUB_BOLD}}', '{{TAM_NOME}}'],
        [san($corTitulos), san($corSubtitulos), san($corLinhas), san($corNome), san($corPrimaria), san($corPrimariaLight), san($corPrimariaDark), $tamTitulos, $tamSubtitulos, $subBold, $tamNome],
        $pdfCssRaw
    );

    // HTML content
    $html = '<style>' . $pdfCssRaw . '</style>';

    // Meta — full-width rows for Produto/Cliente/Fornecedor, 2-column grid for the rest
    // Meta — produto destaque, cliente+fornecedor paired, dados paired
    $metaFull = [];
    $metaFull[] = [$L['produto'], '<span style="font-size:11pt;">' . san($data['produto_nome'] ?? '-') . '</span>'];
    $metaPaired = [];
    if ($temClientes && $temFornecedores) {
        $metaPaired[] = [$L['cliente'], san($data['cliente_nome'] ?? 'Geral')];
        $metaPaired[] = [$L['fornecedor'], san($fornecedorDisplay)];
    } elseif ($temClientes) {
        $metaFull[] = [$L['cliente'], san($data['cliente_nome'] ?? 'Geral')];
    } elseif ($temFornecedores) {
        $metaFull[] = [$L['fornecedor'], san($fornecedorDisplay)];
    }
    $metaPaired[] = [$L['emissao'], formatDate($data['data_emissao'])];
    $metaPaired[] = [$L['revisao'], $data['data_revisao'] ? formatDate($data['data_revisao']) : '-'];
    $metaPaired[] = [$L['estado'], ucfirst($data['estado'])];
    $metaPaired[] = [$L['elaborado_por'], san($elaboradoNome ?: ($data['criado_por_nome'] ?? '-'))];
    $html .= '<div class="meta-box"><table class="meta-grid">';
    foreach ($metaFull as $item) {
        $html .= '<tr><td colspan="2"><span class="meta-label">' . $item[0] . ':</span> <span class="meta-value">' . $item[1] . '</span></td></tr>';
    }
    for ($mi = 0; $mi < count($metaPaired); $mi += 2) {
        $left = $metaPaired[$mi];
        $right = isset($metaPaired[$mi + 1]) ? $metaPaired[$mi + 1] : null;
        $html .= '<tr>';
        $html .= '<td width="50%"><span class="meta-label">' . $left[0] . ':</span> <span class="meta-value">' . $left[1] . '</span></td>';
        $html .= '<td width="50%">' . ($right ? '<span class="meta-label">' . $right[0] . ':</span> <span class="meta-value">' . $right[1] . '</span>' : '') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table></div>';
    // Bloco de aprovações separado
    if ($aprovadoNome || $aceite) {
        $html .= '<div style="border:0.5pt solid #e5e7eb; border-left:2pt solid ' . $corPrimaria . '; border-radius:1.5mm; padding:2mm 3mm; margin-bottom:4mm; font-size:9pt;">';
        $html .= '<p style="font-size:7pt; text-transform:uppercase; color:#667085; letter-spacing:0.3pt; margin:0 0 1.5mm; font-weight:600;">' . $L['aprovacoes'] . '</p>';
        $html .= '<table style="width:100%;">';
        if ($aprovadoNome) {
            $html .= '<tr><td><span class="meta-label">' . $L['aprovacao_interna'] . ':</span> <span class="meta-value">' . san($aprovadoNome) . '</span></td>';
            $html .= '<td style="text-align:right; color:#888; font-size:8pt;">' . $aprovadoData . '</td></tr>';
        }
        if ($aceite) {
            $lblAprov = $L['aceitacao'] . ' ' . $tipoDestinatario;
            $corDec = $aceite['tipo_decisao'] === 'aceite' ? '#16a34a' : '#dc2626';
            $txtDec = $aceite['tipo_decisao'] === 'aceite' ? 'Aceite' : 'Rejeitado';
            $html .= '<tr><td><span class="meta-label">' . $lblAprov . ':</span> <span style="color:' . $corDec . '; font-weight:bold;">' . $txtDec . '</span> — ' . san($aceite['nome_signatario']) . '</td>';
            $html .= '<td style="text-align:right; color:#888; font-size:8pt;">' . date('d/m/Y H:i', strtotime($aceite['created_at'])) . '</td></tr>';
        }
        $html .= '</table></div>';
    }

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
        // Numeração hierárquica
        $hierNumbers = []; $mainC = 0; $subC = 0;
        foreach ($data['seccoes'] as $si => $s) {
            $niv = (int)($s['nivel'] ?? 1);
            if ($niv === 1) { $mainC++; $subC = 0; $hierNumbers[$si] = $mainC . '.'; }
            else { $subC++; $hierNumbers[$si] = $mainC . '.' . $subC . '.'; }
        }

        foreach ($data['seccoes'] as $i => $sec) {
            $secTipo = $sec['tipo'] ?? 'texto';
            $secNivel = (int)($sec['nivel'] ?? 1);
            $secNum = $hierNumbers[$i] ?? ($i + 1) . '.';
            $hTag = $secNivel === 2 ? 'h3' : 'h2';
            $subStyle = $secNivel === 2 ? ' style="margin-left:15mm;"' : '';

            // Secção ficheiros
            if ($secTipo === 'ficheiros') {
                $ficConf = json_decode($sec['conteudo'] ?? '{}', true);
                $secGrupo = $ficConf['grupo'] ?? 'default';
                $secFiles = array_filter($validFiles, function($f) use ($secGrupo) {
                    return ($f['grupo'] ?? 'default') === $secGrupo;
                });
                if (!empty($secFiles)) {
                    $ficTitulo = $secNum . ' ' . san($sec['titulo'] ?? 'Ficheiros Anexos');
                    // Título na página do relatório (antes dos anexos)
                    $html .= '<div class="section"' . $subStyle . '><' . $hTag . '>' . $ficTitulo . '</' . $hTag . '>';
                    // Lista de ficheiros como referência
                    foreach ($secFiles as $fi => $f) {
                        $html .= '<div class="file-item">&#8226; ' . san($f['nome_original']) . ' (' . formatFileSize($f['tamanho']) . ')</div>';
                    }
                    $html .= '</div>';
                    $mpdf->WriteHTML($html);
                    $html = '';
                    // PDFs: importar páginas tal como são (sem escala, sem título overlay)
                    $mpdf->SetHTMLHeader('');
                    foreach ($secFiles as $f) {
                        $fExt = strtolower(pathinfo($f['nome_original'], PATHINFO_EXTENSION));
                        $filepath = UPLOAD_DIR . $f['nome_servidor'];
                        if ($fExt === 'pdf') {
                            try {
                                $pageCount = $mpdf->setSourceFile($filepath);
                                for ($p = 1; $p <= $pageCount; $p++) {
                                    $tplId = $mpdf->importPage($p);
                                    $size = $mpdf->getTemplateSize($tplId);
                                    $srcW = $size['width'];   // já em mm
                                    $srcH = $size['height'];
                                    // Normalizar: sempre A4, escalar conteúdo proporcionalmente
                                    $a4W = 210; $a4H = 297; $margin = 10;
                                    $usableW = $a4W - 2 * $margin;
                                    $usableH = $a4H - 2 * $margin;
                                    $scale = min($usableW / $srcW, $usableH / $srcH, 1);
                                    $renderW = $srcW * $scale;
                                    $renderH = $srcH * $scale;
                                    $offsetX = $margin + ($usableW - $renderW) / 2;
                                    $offsetY = $margin + ($usableH - $renderH) / 2;
                                    $mpdf->AddPageByArray([
                                        'orientation' => 'P',
                                        'sheet-size' => [$a4W, $a4H],
                                        'margin-left' => 0, 'margin-right' => 0,
                                        'margin-top' => 0, 'margin-bottom' => 0,
                                        'margin-header' => 0, 'margin-footer' => 0,
                                    ]);
                                    $mpdf->SetHTMLFooter('');
                                    $mpdf->SetPageTemplate('');
                                    $mpdf->useTemplate($tplId, $offsetX, $offsetY, $renderW, $renderH);
                                }
                            } catch (Exception $e) {}
                        } elseif (in_array($fExt, ['jpg','jpeg','png','gif','bmp','tif','tiff'])) {
                            // Imagens: página dedicada com margens
                            $mpdf->SetHTMLHeader($headerHtml);
                            $mpdf->AddPageByArray([
                                'margin-left' => 15, 'margin-right' => 15,
                                'margin-top' => 25, 'margin-bottom' => 18,
                                'margin-header' => 8, 'margin-footer' => 8,
                            ]);
                            $mpdf->SetHTMLFooter($footerHtml);
                            $imgHtml = '<div style="text-align:center;">';
                            $imgHtml .= '<div class="img-caption">' . san($f['nome_original']) . '</div>';
                            $imgHtml .= '<img src="' . $filepath . '" class="img-anexo">';
                            $imgHtml .= '</div>';
                            $mpdf->WriteHTML($imgHtml);
                            $mpdf->SetHTMLHeader('');
                        }
                    }
                    // Restaurar header/footer para continuar o relatório
                    $mpdf->SetHTMLHeader($headerHtml);
                    $mpdf->AddPageByArray([
                        'margin-left' => 15, 'margin-right' => 15,
                        'margin-top' => 25, 'margin-bottom' => 18,
                        'margin-header' => 8, 'margin-footer' => 8,
                    ]);
                    $mpdf->SetHTMLFooter($footerHtml);
                }
                continue;
            }

            if ($secTipo === 'parametros' || $secTipo === 'parametros_custom') {
                // Parâmetros genéricos (dinâmicos)
                $html .= '<div class="section"' . $subStyle . '>';
                $pc = parseParametrosSeccao($db, $sec, $data);
                $pcRaw = $pc['raw']; $pcRows = $pc['rows']; $pcColunas = $pc['colunas'];
                $pcColWidths = $pc['colWidths']; $pcLegenda = $pc['legenda']; $pcLegTam = $pc['legenda_tamanho'];
                $nCols = count($pcColunas);
                if ($nCols > 0 && count($pcColWidths) === $nCols) {
                    $cwSum = array_sum($pcColWidths) ?: 1;
                    $cwPct = array_map(function($v) use ($cwSum) { return round($v / $cwSum * 100, 1); }, $pcColWidths);
                } else {
                    $defW = $nCols > 0 ? round(100 / $nCols, 1) : 100;
                    $cwPct = array_fill(0, max(1, $nCols), $defW);
                }
                if (!empty($pcRows) && $nCols > 0) {
                    $secTitulo = san($sec['titulo']);
                    $pcOrientacao = $pcRaw['orientacao'] ?? 'horizontal';
                    $pTitleSize = $secNivel === 2 ? $tamSubtitulos : $tamTitulos;
                    $pTitleColor = $secNivel === 2 ? san($corSubtitulos) : san($corTitulos);
                    $pTitleWeight = $secNivel === 2 ? $subBold : 'bold';

                    if ($pcOrientacao === 'vertical') {
                        // Tabela transposta: colunas viram linhas
                        $pcDataRows = array_values(array_filter($pcRows, function($r) { return !isset($r['_cat']); }));
                        $nDataRows = count($pcDataRows);
                        $html .= '<table class="params" repeat_header="1" style="margin-top:6px;">';
                        $html .= '<thead><tr class="ensaio-titulo-row"><td colspan="' . ($nDataRows + 1) . '" style="font-size:' . $pTitleSize . 'pt; color:' . $pTitleColor . '; font-weight:' . $pTitleWeight . ';">' . $secNum . ' ' . $secTitulo . '</td></tr></thead>';
                        $html .= '<tbody>';
                        foreach ($pcColunas as $pcCol) {
                            $html .= '<tr><th class="ensaio-th" style="text-align:left;">' . san($pcCol['nome']) . '</th>';
                            foreach ($pcDataRows as $row) {
                                $html .= '<td class="ensaio-td">' . nl2br(san($row[$pcCol['chave']] ?? '')) . '</td>';
                            }
                            $html .= '</tr>';
                        }
                        $html .= '</tbody></table>';
                    } else {
                    // Tabela horizontal (padrão)
                    $groups = []; $curCat = null; $curRows = [];
                    foreach ($pcRows as $row) {
                        if (isset($row['_cat'])) {
                            if (!empty($curRows)) { $groups[] = ['cat' => $curCat, 'rows' => $curRows]; $curRows = []; }
                            $curCat = $row['_cat'];
                        } else { $curRows[] = $row; }
                    }
                    if (!empty($curRows)) $groups[] = ['cat' => $curCat, 'rows' => $curRows];
                    $theadCols = '<tr>';
                    foreach ($pcColunas as $ci => $pcCol) {
                        $theadCols .= '<th class="ensaio-th" style="width:' . ($cwPct[$ci] ?? 20) . '%;">' . san($pcCol['nome']) . '</th>';
                    }
                    $theadCols .= '</tr>';
                    $theadTitle = '<tr class="ensaio-titulo-row"><td colspan="' . $nCols . '" style="font-size:' . $pTitleSize . 'pt; color:' . $pTitleColor . '; font-weight:' . $pTitleWeight . ';">' . $secNum . ' ' . $secTitulo . '</td></tr>';
                    foreach ($groups as $gIdx => $group) {
                        $mt = $gIdx === 0 ? ' style="margin-top:6px;"' : '';
                        $html .= '<table class="params" repeat_header="1"' . $mt . '>';
                        $html .= '<thead>' . ($gIdx === 0 ? $theadTitle : '') . $theadCols;
                        if ($group['cat']) {
                            $html .= '<tr class="ensaio-cat-row"><td colspan="' . $nCols . '">' . san($group['cat']) . '</td></tr>';
                        }
                        $html .= '</thead><tbody>';
                        foreach ($group['rows'] as $row) {
                            $html .= '<tr>';
                            foreach ($pcColunas as $pcCol) {
                                $html .= '<td class="ensaio-td">' . nl2br(san($row[$pcCol['chave']] ?? '')) . '</td>';
                            }
                            $html .= '</tr>';
                        }
                        $html .= '</tbody></table>';
                    }
                    }
                    if (!empty($pcLegenda)) {
                        $html .= '<p style="font-size:' . $pcLegTam . 'pt; color:#888; font-style:italic; margin:2pt 0 0 0;">' . san($pcLegenda) . '</p>';
                    }
                }
            } else {
                $html .= '<div class="section"' . $subStyle . '><' . $hTag . '>' . $secNum . ' ' . san($sec['titulo']) . '</' . $hTag . '>';
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



    // Signature block dinâmico
    $html .= '<div class="sig-block">';
    $html .= '<table class="sig-table"><tr>';
    // Elaborado por
    $html .= '<td class="sig-cell">';
    $html .= '<p class="sig-title">' . $L['elaborado_por'] . '</p>';
    if ($elaboradoAssinatura && file_exists(__DIR__ . '/uploads/assinaturas/' . $elaboradoAssinatura)) {
        $html .= '<img src="' . __DIR__ . '/uploads/assinaturas/' . $elaboradoAssinatura . '" class="sig-img"><br>';
    }
    if ($elaboradoNome) {
        $html .= '<p class="sig-name">' . san($elaboradoNome) . '</p>';
        $html .= '<p class="sig-date">' . $elaboradoData . '</p>';
        $html .= '<p class="sig-validated">&#10003; Validado</p>';
    } else {
        $html .= '<p class="sig-pending">' . $L['pendente'] . '</p>';
    }
    $html .= '</td>';
    // Aprovado por (aprovação interna)
    if ($aprovadoNome) {
        $html .= '<td class="sig-cell">';
        $html .= '<p class="sig-title">' . $L['aprovado_por'] . '</p>';
        if ($aprovadoAssinatura && file_exists(__DIR__ . '/uploads/assinaturas/' . $aprovadoAssinatura)) {
            $html .= '<img src="' . __DIR__ . '/uploads/assinaturas/' . $aprovadoAssinatura . '" class="sig-img"><br>';
        }
        $html .= '<p class="sig-name">' . san($aprovadoNome) . '</p>';
        $html .= '<p class="sig-date">' . $aprovadoData . '</p>';
        $html .= '<p class="sig-validated">&#10003; Validado</p>';
        $html .= '</td>';
    }
    // Aceitação fornecedor/cliente
    $html .= '<td class="sig-cell">';
    $html .= '<p class="sig-title">' . $L['aceitacao'] . ' ' . $tipoDestinatario . '</p>';
    if ($aceite) {
        if (!empty($aceite['assinatura_signatario']) && file_exists(__DIR__ . '/uploads/assinaturas/' . $aceite['assinatura_signatario'])) {
            $html .= '<img src="' . __DIR__ . '/uploads/assinaturas/' . $aceite['assinatura_signatario'] . '" class="sig-img"><br>';
        }
        $html .= '<p class="sig-name">' . san($aceite['nome_signatario']) . '</p>';
        if ($aceite['cargo_signatario']) $html .= '<p class="sig-date">' . san($aceite['cargo_signatario']) . '</p>';
        $html .= '<p class="sig-date">' . date('d/m/Y H:i', strtotime($aceite['created_at'])) . '</p>';
        $html .= '<p class="sig-validated">&#10003; Validado</p>';
    } else {
        $html .= '<p class="sig-pending">' . $L['aguarda'] . '</p>';
    }
    $html .= '</td>';
    $html .= '</tr></table></div>';

    // Debug: timestamp visível + guardar HTML
    $isDebug = isset($_GET['debug']) || getConfiguracao('debug_pdf', '0') === '1';
    if ($isDebug) {
        $html .= '<div style="font-size:7pt;color:red;margin-top:5mm;border-top:0.5pt dashed red;padding-top:2mm;">DEBUG — Gerado em: ' . date('Y-m-d H:i:s') . '</div>';
        file_put_contents($tmpDir . '/last_pdf.html', '<html><head><meta charset="utf-8"><style>' . $pdfCssRaw . '</style></head><body>' . $html . '</body></html>');
    }

    $mpdf->WriteHTML($html);


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
            'margin-top' => 25, 'margin-bottom' => 18,
            'margin-header' => 8, 'margin-footer' => 8,
        ]);
        $mpdf->SetHTMLHeader($headerHtml);
        $mpdf->SetHTMLFooter($footerHtml);
        $sigHtml = '<div class="sig-page">';
        $sigHtml .= '<h2>' . $L['assinatura'] . '</h2>';
        if ($sigPath && file_exists($sigPath)) {
            $sigHtml .= '<div style="margin:10mm auto;"><img src="' . $sigPath . '" height="60"></div>';
        }
        $sigHtml .= '<div class="sig-page-line">' . san($assinaturaNome) . '</div>';
        $sigHtml .= '<div class="sig-page-date">' . date('d/m/Y') . '</div>';
        $sigHtml .= '</div>';
        $mpdf->WriteHTML($sigHtml);
    }

    $filename = 'Caderno_Encargos_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['numero']) . '.pdf';
    $dest = isset($_GET['view']) ? \Mpdf\Output\Destination::INLINE : \Mpdf\Output\Destination::DOWNLOAD;
    $mpdf->Output($filename, $dest);
    exit;
}

// Fallback: HTML para impressão (sem mPDF)
$cv = parseConfigVisual($data['config_visual'] ?? null, $orgCorPrimaria);
$corTitulos = $cv['cor_titulos'];
$corSubtitulos = $cv['cor_subtitulos'];
$corLinhas  = $cv['cor_linhas'];
$corNome    = $cv['cor_nome'];
$tamTitulos = (int)$cv['tamanho_titulos'];
$tamSubtitulos = (int)$cv['tamanho_subtitulos'];
$subBold = ($cv['subtitulos_bold'] ?? '1') === '1' ? 'bold' : 'normal';
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
        h3 { font-size: <?= $tamSubtitulos ?>pt; font-weight: <?= $subBold ?>; color: <?= san($corSubtitulos) ?>; border-bottom: 1px solid <?= san($corLinhas) ?>; padding-bottom: 1mm; margin: 4mm 0 2mm; }
        .header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 3mm; border-bottom: 3pt solid <?= san($corLinhas) ?>; margin-bottom: 5mm; }
        .header img { height: 48px; }
        .header .title { text-align: right; }
        .header .title h1 { margin: 0; font-size: <?= $tamNome ?>pt; }
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
        .embed-section h3 { font-size: <?= $tamSubtitulos ?>pt; font-weight: <?= $subBold ?>; color: <?= san($corSubtitulos) ?>; margin-bottom: 5mm; }
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
            <p><?= san($data['numero']) ?> | <?= $L['versao'] ?> <?= san($data['versao']) ?></p>
        </div>
    </div>

    <div class="meta">
        <div class="meta-full"><span><?= $L['produto'] ?>:</span> <strong><?= san($data['produto_nome'] ?? '-') ?></strong></div>
        <?php if ($temClientes): ?>
        <div class="meta-full"><span><?= $L['cliente'] ?>:</span> <strong><?= san($data['cliente_nome'] ?? 'Geral') ?></strong></div>
        <?php endif; ?>
        <?php if ($temFornecedores): ?>
        <div class="meta-full"><span><?= $L['fornecedor'] ?>:</span> <strong><?= san($fornecedorDisplay) ?></strong></div>
        <?php endif; ?>
        <div><span><?= $L['emissao'] ?>:</span> <strong><?= formatDate($data['data_emissao']) ?></strong></div>
        <div><span><?= $L['revisao'] ?>:</span> <strong><?= $data['data_revisao'] ? formatDate($data['data_revisao']) : '-' ?></strong></div>
        <div><span><?= $L['estado'] ?>:</span> <strong><?= ucfirst($data['estado']) ?></strong></div>
        <div><span><?= $L['elaborado_por'] ?>:</span> <strong><?= san($data['criado_por_nome'] ?? '-') ?></strong></div>
        <?php if ($aceite): ?>
        <div class="meta-full" style="margin-top:4px;">
            <span><?= $L['aprovacao'] ?>:</span>
            <strong style="color:<?= $aceite['tipo_decisao'] === 'aceite' ? '#16a34a' : '#dc2626' ?>">
                <?= $aceite['tipo_decisao'] === 'aceite' ? 'Aceite' : 'Rejeitado' ?>
            </strong>
            — <?= san($aceite['nome_signatario']) ?>
            <span style="color:#888; font-size:11px;"><?= date('d/m/Y', strtotime($aceite['created_at'])) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($data['seccoes'])):
        $hierN2 = []; $mC2 = 0; $sC2 = 0;
        foreach ($data['seccoes'] as $si2 => $s2) {
            $nv2 = (int)($s2['nivel'] ?? 1);
            if ($nv2 === 1) { $mC2++; $sC2 = 0; $hierN2[$si2] = $mC2 . '.'; }
            else { $sC2++; $hierN2[$si2] = $mC2 . '.' . $sC2 . '.'; }
        }
    ?>
        <?php foreach ($data['seccoes'] as $i => $sec):
            $secTipo = $sec['tipo'] ?? 'texto';
            if ($secTipo === 'ficheiros') continue;
            $secNivel2 = (int)($sec['nivel'] ?? 1);
            $secNum2 = $hierN2[$i] ?? ($i + 1) . '.';
        ?>
            <div class="section"<?= $secNivel2 === 2 ? ' style="margin-left:15mm;"' : '' ?>>
                <<?= $secNivel2 === 2 ? 'h3' : 'h2' ?>><?= $secNum2 . ' ' . san($sec['titulo']) ?></<?= $secNivel2 === 2 ? 'h3' : 'h2' ?>>
                <?php if ($secTipo === 'parametros' || $secTipo === 'parametros_custom'): ?>
                    <?php
                    $pcRaw = json_decode($sec['conteudo'] ?? '{}', true);
                    $pcRows = $pcRaw['rows'] ?? [];
                    $pcTipoId = $pcRaw['tipo_id'] ?? '';
                    $pcColunas = []; $pcLegenda = ''; $pcLegTam = 9;
                    $pcColWidths = $pcRaw['colWidths'] ?? [];
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
                    $pcCw = count($pcColWidths) ? $pcColWidths : array_fill(0, count($pcColunas), floor(100 / max(1, count($pcColunas))));
                    ?>
                    <?php if (!empty($pcRows)): ?>
                    <table class="doc-table">
                        <thead><tr>
                            <?php foreach ($pcColunas as $ci => $pcCol): ?>
                            <th style="width:<?= isset($pcCw[$ci]) ? $pcCw[$ci] : 15 ?>%; background:<?= $corPrimaria ?>; color:white; padding:6px 10px; text-align:left; font-weight:600;"><?= san($pcCol['nome']) ?></th>
                            <?php endforeach; ?>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($pcRows as $pcRow): ?>
                                <?php if (isset($pcRow['_cat'])): ?>
                                <tr><td colspan="<?= count($pcColunas) ?>" style="background:<?= $corPrimaria ?>20; padding:5px 10px; font-weight:600; font-size:11px; color:<?= $corPrimaria ?>;"><?= san($pcRow['_cat']) ?></td></tr>
                                <?php else: ?>
                                <tr>
                                    <?php foreach ($pcColunas as $pcCol): ?>
                                    <td style="white-space:pre-wrap;"><?= nl2br(san($pcRow[$pcCol['chave']] ?? '')) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!empty($pcLegenda)): ?>
                    <p style="font-size:<?= $pcLegTam ?>pt; color:#888; font-style:italic; margin:2pt 0 0 0;"><?= san($pcLegenda) ?></p>
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

    <!-- Validações -->
    <div class="signatures">
        <div class="sig-box" style="border-top:none; padding-top:0; text-align:center;">
            <p style="font-weight:bold; margin:0 0 8px; font-size:9pt; color:<?= $corPrimaria ?>;"><?= $L['elaborado_por'] ?></p>
            <?php if ($elaboradoAssinatura && file_exists(__DIR__ . '/uploads/assinaturas/' . $elaboradoAssinatura)): ?>
                <img src="<?= __DIR__ ?>/uploads/assinaturas/<?= $elaboradoAssinatura ?>" style="max-height:40px; margin-bottom:4px;">
            <?php endif; ?>
            <?php if ($elaboradoNome): ?>
                <p style="margin:0; font-size:9pt; border-top:1px solid #999; padding-top:6px;"><?= san($elaboradoNome) ?></p>
                <p style="margin:2px 0 0; font-size:8pt; color:#888;"><?= $elaboradoData ?></p>
                <p style="margin:2px 0 0; font-size:8pt; color:#16a34a; font-weight:600;">&#10003; Validado</p>
            <?php else: ?>
                <p style="margin:0; font-size:8pt; color:#999; padding-top:15mm; border-top:1px solid #999;"><?= $L['pendente'] ?></p>
            <?php endif; ?>
        </div>
        <div class="sig-box" style="border-top:none; padding-top:0; text-align:center;">
            <p style="font-weight:bold; margin:0 0 8px; font-size:9pt; color:<?= $corPrimaria ?>;"><?= $L['aprovacao'] ?> <?= $tipoDestinatario ?></p>
            <?php if ($aceite): ?>
                <?php if (!empty($aceite['assinatura_signatario']) && file_exists(__DIR__ . '/uploads/assinaturas/' . $aceite['assinatura_signatario'])): ?>
                    <img src="<?= __DIR__ ?>/uploads/assinaturas/<?= $aceite['assinatura_signatario'] ?>" style="max-height:40px; margin-bottom:4px;">
                <?php endif; ?>
                <p style="margin:0; font-size:9pt; border-top:1px solid #999; padding-top:6px;"><?= san($aceite['nome_signatario']) ?></p>
                <?php if ($aceite['cargo_signatario']): ?>
                <p style="margin:2px 0 0; font-size:8pt; color:#888;"><?= san($aceite['cargo_signatario']) ?></p>
                <?php endif; ?>
                <p style="margin:2px 0 0; font-size:8pt; color:#888;"><?= date('d/m/Y H:i', strtotime($aceite['created_at'])) ?></p>
                <p style="margin:2px 0 0; font-size:8pt; color:#16a34a; font-weight:600;">&#10003; Validado</p>
            <?php else: ?>
                <p style="margin:0; font-size:8pt; color:#999; padding-top:15mm; border-top:1px solid #999;">' . $L['aguarda'] . '</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        <span>&copy; <?= san($orgNome) ?> <?= date('Y') ?> | Powered by SpecLab</span>
        <span><?= san($data['numero']) ?> | <?= $L['versao'] ?> <?= san($data['versao']) ?> | <?= date('d/m/Y') ?></span>
    </div>
</body>
</html>
