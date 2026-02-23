<?php
/**
 * SpecLab - Cadernos de Encargos
 * Visualização Completa (utilizadores autenticados)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$user = getCurrentUser();
$db = getDB();
$isEmbed = !empty($_GET['embed']);

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

// Traduções dos rótulos conforme idioma da spec
$lang = $data['idioma'] ?? 'pt';
$labels = [
    'pt' => ['produto'=>'Produto','cliente'=>'Cliente','fornecedor'=>'Fornecedor','emissao'=>'Emissão','revisao'=>'Revisão','estado'=>'Estado','elaborado_por'=>'Elaborado por','aprovacao'=>'Aprovação','pendente'=>'Pendente','aguarda'=>'Aguarda validação','pagina'=>'Página','de'=>'de','assinatura'=>'Assinatura / Aprovação','versao'=>'Versão','impresso'=>'Impresso'],
    'en' => ['produto'=>'Product','cliente'=>'Client','fornecedor'=>'Supplier','emissao'=>'Issue Date','revisao'=>'Revision','estado'=>'Status','elaborado_por'=>'Prepared by','aprovacao'=>'Approval','pendente'=>'Pending','aguarda'=>'Awaiting validation','pagina'=>'Page','de'=>'of','assinatura'=>'Signature / Approval','versao'=>'Version','impresso'=>'Printed'],
    'es' => ['produto'=>'Producto','cliente'=>'Cliente','fornecedor'=>'Proveedor','emissao'=>'Emisión','revisao'=>'Revisión','estado'=>'Estado','elaborado_por'=>'Elaborado por','aprovacao'=>'Aprobación','pendente'=>'Pendiente','aguarda'=>'En espera de validación','pagina'=>'Página','de'=>'de','assinatura'=>'Firma / Aprobación','versao'=>'Versión','impresso'=>'Impreso'],
    'fr' => ['produto'=>'Produit','cliente'=>'Client','fornecedor'=>'Fournisseur','emissao'=>'Émission','revisao'=>'Révision','estado'=>'Statut','elaborado_por'=>'Préparé par','aprovacao'=>'Approbation','pendente'=>'En attente','aguarda'=>'En attente de validation','pagina'=>'Page','de'=>'de','assinatura'=>'Signature / Approbation','versao'=>'Version','impresso'=>'Imprimé'],
    'de' => ['produto'=>'Produkt','cliente'=>'Kunde','fornecedor'=>'Lieferant','emissao'=>'Ausgabe','revisao'=>'Revision','estado'=>'Status','elaborado_por'=>'Erstellt von','aprovacao'=>'Genehmigung','pendente'=>'Ausstehend','aguarda'=>'Warten auf Validierung','pagina'=>'Seite','de'=>'von','assinatura'=>'Unterschrift / Genehmigung','versao'=>'Version','impresso'=>'Gedruckt'],
    'it' => ['produto'=>'Prodotto','cliente'=>'Cliente','fornecedor'=>'Fornitore','emissao'=>'Emissione','revisao'=>'Revisione','estado'=>'Stato','elaborado_por'=>'Preparato da','aprovacao'=>'Approvazione','pendente'=>'In sospeso','aguarda'=>'In attesa di validazione','pagina'=>'Pagina','de'=>'di','assinatura'=>'Firma / Approvazione','versao'=>'Versione','impresso'=>'Stampato'],
];
$L = $labels[$lang] ?? $labels['pt'];

$org = getOrgByEspecificacao($db, $id);
$temClientes = $org && !empty($org['tem_clientes']);
$temFornecedores = $org && !empty($org['tem_fornecedores']);
$fornecedorDisplay = $data['fornecedor_nome'] ?? '';
if (strpos($fornecedorDisplay, ',') !== false || empty($fornecedorDisplay)) {
    $fornecedorDisplay = 'Todos';
}
// Carregar aprovações (tokens com decisão)
$stmtAprov = $db->prepare('SELECT a.tipo_decisao, a.nome_signatario, a.cargo_signatario, a.created_at, a.token_id, t.destinatario_nome, t.tipo_destinatario
    FROM especificacao_aceitacoes a
    INNER JOIN especificacao_tokens t ON t.id = a.token_id
    WHERE a.especificacao_id = ?
    ORDER BY a.created_at DESC');
$stmtAprov->execute([$id]);
$aprovacoes = $stmtAprov->fetchAll();

// Carregar respostas de pedidos (ficheiros enviados por fornecedores)
$pedidoRespostas = [];
$stmtPedResp = $db->prepare('SELECT r.*, p.titulo as pedido_titulo FROM especificacao_pedido_respostas r INNER JOIN especificacao_pedidos p ON p.id = r.pedido_id WHERE p.especificacao_id = ? ORDER BY r.created_at');
$stmtPedResp->execute([$id]);
while ($pr = $stmtPedResp->fetch(PDO::FETCH_ASSOC)) {
    $pedidoRespostas[$pr['token_id']][] = $pr;
}

$cores = getOrgColors($org);
$corPrimaria = $cores['primaria'];
$corPrimariaDark = $cores['primaria_dark'];
$corPrimariaLight = $cores['primaria_light'];
$orgNome = $org ? $org['nome'] : 'SpecLab';
$orgLogo = '';
if ($org && $org['logo']) {
    $orgLogo = BASE_PATH . '/uploads/logos/' . $org['logo'];
} else {
    $orgLogo = BASE_PATH . '/assets/img/exi_logo.png';
}

// Config visual
$cv = parseConfigVisual($data['config_visual'] ?? null, $corPrimaria);
$corTitulos = $cv['cor_titulos'];
$corSubtitulos = $cv['cor_subtitulos'];
$tamTitulos = (int)$cv['tamanho_titulos'];
$tamSubtitulos = (int)$cv['tamanho_subtitulos'];
$subBold = ($cv['subtitulos_bold'] ?? '1') === '1' ? 'bold' : 'normal';

?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= san($data['titulo']) ?> - SpecLab</title>
    <link rel="icon" type="image/svg+xml" href="<?= asset('assets/img/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
    <style>
        .doc-container { max-width: 1120px; margin: 0 auto; padding: 24px; }
        .doc-header {
            display: flex; align-items: center; justify-content: space-between;
            padding-bottom: 12px; border-bottom: 3px solid <?= $corPrimaria ?>; margin-bottom: 24px;
        }
        .doc-header img { height: 48px; }
        .doc-header .doc-title { text-align: right; }
        .doc-header .doc-title h1 { font-size: 18px; color: <?= $corPrimaria ?>; margin: 0; }
        .doc-header .doc-title p { font-size: 12px; color: #667085; margin: 4px 0 0; }
        .doc-section { margin-bottom: 24px; scroll-margin-top: 72px; }
        .doc-section h2 {
            font-size: <?= $tamTitulos ?>pt; color: <?= $corTitulos ?>; border-bottom: 1px solid <?= $corPrimariaLight ?>;
            padding-bottom: 6px; margin-bottom: 12px;
        }
        .doc-section .content { font-size: 13px; line-height: 1.6; white-space: pre-wrap; }
        .doc-section-sub { margin-left: 24px; padding-left: 16px; }
        .doc-section-sub h3 { font-size: <?= $tamSubtitulos ?>pt; font-weight: <?= $subBold ?>; color: <?= $corSubtitulos ?>; border-bottom: 1px solid <?= $corPrimariaLight ?>; padding-bottom: 5px; margin-bottom: 10px; }
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
        /* Sidebar + Content layout */
        .doc-layout { display: flex; gap: 24px; align-items: flex-start; }
        .doc-main { flex: 1; min-width: 0; }
        .doc-sidebar {
            width: 200px; flex-shrink: 0; position: sticky; top: 72px;
            max-height: calc(100vh - 96px); overflow-y: auto;
        }
        .doc-sidebar nav {
            background: white; border-radius: 10px; padding: 16px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06); border: 1px solid #e5e7eb;
        }
        .doc-sidebar .nav-title {
            font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
            color: #9ca3af; padding: 0 16px 8px; margin: 0;
        }
        .doc-sidebar a {
            display: block; padding: 6px 16px; font-size: 12px; color: #6b7280;
            text-decoration: none; border-left: 2px solid transparent; transition: all 0.15s ease;
            line-height: 1.4; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .doc-sidebar a:hover { color: #111827; background: #f9fafb; }
        .doc-sidebar a.active {
            color: <?= $corPrimaria ?>; border-left-color: <?= $corPrimaria ?>;
            background: <?= $corPrimariaLight ?>; font-weight: 600;
        }
        /* Mobile toggle */
        .sidebar-toggle {
            display: none; position: fixed; bottom: 20px; right: 20px; z-index: 100;
            width: 44px; height: 44px; border-radius: 50%; border: none; cursor: pointer;
            background: <?= $corPrimaria ?>; color: white; font-size: 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: transform 0.2s;
        }
        .sidebar-toggle:hover { transform: scale(1.08); }
        .sidebar-mobile-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3);
            z-index: 90; opacity: 0; transition: opacity 0.2s;
        }
        .sidebar-mobile-overlay.show { opacity: 1; }
        @media (max-width: 840px) {
            .doc-layout { display: block; }
            .doc-sidebar {
                display: none; position: fixed; bottom: 72px; right: 16px; z-index: 95;
                width: 220px; top: auto; max-height: 60vh;
            }
            .doc-sidebar.open { display: block; animation: slideUp 0.2s ease; }
            .sidebar-toggle { display: flex; align-items: center; justify-content: center; }
            @keyframes slideUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
        }
        @media print {
            .toolbar, .no-print, .doc-sidebar, .sidebar-toggle, .sidebar-mobile-overlay { display: none !important; }
            .doc-container { max-width: 900px; }
            @page { size: A4; margin: 15mm; }
            .doc-table th { background: <?= $corPrimaria ?> !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .cat-header td { background: <?= $corPrimariaLight ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<?php
// Preparar dados para sidebar (antes do body)
$ficheirosPos = 'local';
$ficheirosRendered = false;
$validFilesVer = [];
if (!empty($data['ficheiros'])) {
    foreach ($data['ficheiros'] as $f) {
        if (file_exists(UPLOAD_DIR . $f['nome_servidor'])) {
            $validFilesVer[] = $f;
        }
    }
}
$navItems = [];
if (!empty($data['seccoes'])) {
    $navMain = 0; $navSub = 0;
    foreach ($data['seccoes'] as $ni => $ns) {
        $niv = (int)($ns['nivel'] ?? 1);
        if ($niv === 1) { $navMain++; $navSub = 0; $navNum = $navMain . '.'; }
        else { $navSub++; $navNum = $navMain . '.' . $navSub . '.'; }
        $navItems[] = ['id' => 'sec-' . $ni, 'label' => $navNum . ' ' . ($ns['titulo'] ?? 'Secção'), 'nivel' => $niv];
    }
} else {
    $legacySections = ['objetivo'=>'Objetivo','ambito'=>'Introdução','definicao_material'=>'Definição do Material','regulamentacao'=>'Regulamentação','processos'=>'Processos','embalagem'=>'Embalagem','aceitacao'=>'Aceitação','arquivo_texto'=>'Arquivo','indemnizacao'=>'Indemnização','observacoes'=>'Observações'];
    $lni = 1;
    foreach ($legacySections as $lk => $lv) {
        if (!empty($data[$lk])) { $navItems[] = ['id' => 'sec-' . $lk, 'label' => $lni . '. ' . $lv]; $lni++; }
    }
}
?>
<body style="background: #f3f4f6;">
    <div class="doc-container">

        <!-- Toolbar -->
        <div class="toolbar no-print" <?= $isEmbed ? 'style="display:none;"' : '' ?>>
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

        <div class="doc-layout">
        <!-- Sidebar Navigation -->
        <?php if (count($navItems) > 1): ?>
        <aside class="doc-sidebar no-print" id="docSidebar">
            <nav>
                <p class="nav-title">Navegar</p>
                <?php foreach ($navItems as $nav): ?>
                <a href="#<?= $nav['id'] ?>" title="<?= san($nav['label']) ?>"<?= (!empty($nav['nivel']) && $nav['nivel'] === 2) ? ' style="padding-left:20px; font-size:11px;"' : '' ?>><?= san($nav['label']) ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <?php endif; ?>
        <div class="doc-main">
        <div class="card" style="padding:32px;">

            <!-- Header -->
            <div class="doc-header">
                <img src="<?= $orgLogo ?>" alt="<?= sanitize($orgNome) ?>" onerror="this.style.display='none'">
                <div class="doc-title">
                    <h1><?= san($data['titulo']) ?></h1>
                    <p><?= san($data['numero']) ?> | <?= $L['versao'] ?> <?= san($data['versao']) ?></p>
                </div>
            </div>

            <!-- Meta -->
            <div class="doc-meta">
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
                <div><span>Criado por:</span> <strong><?= san($data['criado_por_nome'] ?? '-') ?></strong></div>
                <?php if (!empty($aprovacoes)): ?>
                <?php foreach ($aprovacoes as $aprov): ?>
                <div class="meta-full" style="margin-top:4px;">
                    <span><?= $aprov['tipo_destinatario'] === 'fornecedor' ? $L['fornecedor'] : ($aprov['tipo_destinatario'] === 'cliente' ? $L['cliente'] : $L['aprovacao']) ?>:</span>
                    <strong style="color:<?= $aprov['tipo_decisao'] === 'aceite' ? '#16a34a' : '#dc2626' ?>">
                        <?= $aprov['tipo_decisao'] === 'aceite' ? 'Aceite' : 'Rejeitado' ?>
                    </strong>
                    — <?= san($aprov['nome_signatario']) ?>
                    <?= $aprov['cargo_signatario'] ? '(' . san($aprov['cargo_signatario']) . ')' : '' ?>
                    <span style="color:#888; font-size:11px;"><?= date('d/m/Y', strtotime($aprov['created_at'])) ?></span>
                    <?php if (!empty($pedidoRespostas[$aprov['token_id']])): ?>
                        <?php foreach ($pedidoRespostas[$aprov['token_id']] as $pResp): ?>
                        <a href="<?= BASE_PATH ?>/api.php?action=download_pedido_resposta&id=<?= $pResp['id'] ?>" target="_blank" style="margin-left:8px; font-size:11px; color:#2596be; text-decoration:underline;" title="<?= sanitize($pResp['pedido_titulo']) ?>">
                            &#128206; <?= sanitize($pResp['nome_ficheiro']) ?>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php /* $navItems, $validFilesVer, $ficheirosPos já preparados antes do body */ ?>

            <?php if (!empty($data['seccoes'])):
                // Calcular numeração hierárquica
                $hierNumbers = [];
                $mainC = 0; $subC = 0;
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
                    <?php if ($secTipo === 'ficheiros'): ?>
                        <?php
                            $ficConf = json_decode($sec['conteudo'] ?? '{}', true);
                            $secGrupo = $ficConf['grupo'] ?? 'default';
                            $secFiles = array_filter($validFilesVer, function($f) use ($secGrupo) {
                                return ($f['grupo'] ?? 'default') === $secGrupo;
                            });
                        ?>
                        <?php if (!empty($secFiles)): ?>
                            <div class="doc-section<?= $secNivel === 2 ? ' doc-section-sub' : '' ?>" id="sec-<?= $i ?>">
                                <<?= $secNivel === 2 ? 'h3' : 'h2' ?>><?= $secNum . ' ' . san($sec['titulo']) ?></<?= $secNivel === 2 ? 'h3' : 'h2' ?>>
                                <?php foreach ($secFiles as $f):
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

                    <div class="doc-section<?= $secNivel === 2 ? ' doc-section-sub' : '' ?>" id="sec-<?= $i ?>">
                        <<?= $secNivel === 2 ? 'h3' : 'h2' ?>><?= $secNum . ' ' . san($sec['titulo']) ?></<?= $secNivel === 2 ? 'h3' : 'h2' ?>>
                        <?php if ($secTipo === 'parametros' || $secTipo === 'parametros_custom'): ?>
                            <?php
                            $pc = parseParametrosSeccao($db, $sec, $data);
                            $pcRaw = $pc['raw']; $pcRows = $pc['rows']; $pcColunas = $pc['colunas'];
                            $pcColWidths = $pc['colWidths']; $pcLegenda = $pc['legenda']; $pcLegTam = $pc['legenda_tamanho'];
                            $pcCw = count($pcColWidths) ? $pcColWidths : array_fill(0, count($pcColunas), floor(100 / max(1, count($pcColunas))));
                            ?>
                            <?php if (!empty($pcRows)):
                                $pcOrientacao = $pcRaw['orientacao'] ?? 'horizontal';
                                // Filtrar rows de dados (sem _cat) para transposta
                                $pcDataRows = array_values(array_filter($pcRows, function($r) { return !isset($r['_cat']); }));
                            ?>
                            <?php if ($pcOrientacao === 'vertical' && !empty($pcDataRows)): ?>
                            <table class="doc-table">
                                <tbody>
                                    <?php foreach ($pcColunas as $pcCol): ?>
                                    <tr>
                                        <th style="width:<?= max(15, floor(100 / (count($pcDataRows) + 1))) ?>%; text-align:left;"><?= san($pcCol['nome']) ?></th>
                                        <?php foreach ($pcDataRows as $pcRow): ?>
                                        <td><?= nl2br(san($pcRow[$pcCol['chave']] ?? '')) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <table class="doc-table">
                                <thead><tr>
                                    <?php foreach ($pcColunas as $ci => $pcCol): ?>
                                    <th style="width:<?= isset($pcCw[$ci]) ? $pcCw[$ci] : 15 ?>%"><?= san($pcCol['nome']) ?></th>
                                    <?php endforeach; ?>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($pcRows as $pcRow): ?>
                                        <?php if (isset($pcRow['_cat'])): ?>
                                        <tr><td colspan="<?= count($pcColunas) ?>" style="background:<?= sanitizeColor($orgCor ?? '#2596be') ?>15; padding:4px 8px; font-weight:600; font-size:12px; color:<?= sanitizeColor($orgCor ?? '#2596be') ?>;"><?= san($pcRow['_cat']) ?></td></tr>
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
                            <?php endif; ?>
                            <?php if (!empty($pcLegenda)): ?>
                            <p style="font-size:<?= $pcLegTam ?>px; color:#888; font-style:italic; margin:3px 0 0 0;"><?= san($pcLegenda) ?></p>
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
                    <div class="doc-section" id="sec-<?= $key ?>">
                        <h2><?= $title ?></h2>
                        <div class="content"><?= nl2br(san($data[$key])) ?></div>
                    </div>
                <?php endif; endforeach; ?>
            <?php endif; ?>



            <div class="doc-footer">
                <span>&copy; <?= sanitize($orgNome) ?> <?= date('Y') ?></span>
                <span><?= san($data['numero']) ?> | <?= $L['versao'] ?> <?= san($data['versao']) ?> | <?= $L['impresso'] ?>: <?= date('d/m/Y H:i') ?></span>
            </div>
        </div>
        </div><!-- /doc-main -->
        </div><!-- /doc-layout -->

        <!-- Mobile sidebar toggle -->
        <?php if (count($navItems) > 1): ?>
        <button class="sidebar-toggle no-print" id="sidebarToggle" aria-label="Navegar secções">&#9776;</button>
        <div class="sidebar-mobile-overlay no-print" id="sidebarOverlay"></div>
        <?php endif; ?>
    </div>

    <script>
    function copyLink() {
        const url = window.location.origin + '<?= BASE_PATH ?>/publico.php?code=<?= $data['codigo_acesso'] ?? '' ?>';
        navigator.clipboard.writeText(url).then(() => {
            appAlert('Link público copiado para a área de transferência!');
        });
    }

    // Sidebar: scroll spy + smooth scroll + mobile toggle
    (function() {
        var sidebar = document.getElementById('docSidebar');
        if (!sidebar) return;
        var links = sidebar.querySelectorAll('a[href^="#"]');
        var sections = [];
        links.forEach(function(a) {
            var el = document.querySelector(a.getAttribute('href'));
            if (el) sections.push({ link: a, el: el });
        });

        // Smooth scroll on click
        links.forEach(function(a) {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                var target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    // Close mobile sidebar
                    sidebar.classList.remove('open');
                    var overlay = document.getElementById('sidebarOverlay');
                    if (overlay) { overlay.classList.remove('show'); overlay.style.display = 'none'; }
                }
            });
        });

        // Scroll spy with IntersectionObserver
        var currentActive = null;
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var match = sections.find(function(s) { return s.el === entry.target; });
                    if (match && match.link !== currentActive) {
                        if (currentActive) currentActive.classList.remove('active');
                        match.link.classList.add('active');
                        currentActive = match.link;
                    }
                }
            });
        }, { rootMargin: '-10% 0px -70% 0px' });

        sections.forEach(function(s) { observer.observe(s.el); });

        // Activate first by default
        if (sections.length && !currentActive) {
            sections[0].link.classList.add('active');
            currentActive = sections[0].link;
        }

        // Mobile toggle
        var toggle = document.getElementById('sidebarToggle');
        var overlay = document.getElementById('sidebarOverlay');
        if (toggle) {
            toggle.addEventListener('click', function() {
                var isOpen = sidebar.classList.toggle('open');
                if (overlay) {
                    overlay.style.display = isOpen ? 'block' : 'none';
                    setTimeout(function() { overlay.classList.toggle('show', isOpen); }, 10);
                }
            });
        }
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
                setTimeout(function() { overlay.style.display = 'none'; }, 200);
            });
        }
    })();
    </script>
    <?php include __DIR__ . '/includes/modals.php'; ?>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
