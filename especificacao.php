<?php
/**
 * SpecLab - Cadernos de Encargos
 * Editor de Especificação Técnica
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/versioning.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();
$orgId = $user['org_id'];

// Carregar listas para selects (scoped por org)
if (isSuperAdmin()) {
    $produtos = $db->query("SELECT id, nome FROM produtos WHERE ativo = 1 ORDER BY nome")->fetchAll();
    $clientes = $db->query("SELECT id, nome, sigla FROM clientes WHERE ativo = 1 ORDER BY nome")->fetchAll();
    $fornecedores = $db->query("SELECT id, nome, sigla FROM fornecedores WHERE ativo = 1 ORDER BY nome")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT id, nome FROM produtos WHERE ativo = 1 AND (organizacao_id IS NULL OR organizacao_id = ?) ORDER BY nome");
    $stmt->execute([$orgId]);
    $produtos = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT id, nome, sigla FROM clientes WHERE ativo = 1 AND organizacao_id = ? ORDER BY nome");
    $stmt->execute([$orgId]);
    $clientes = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT id, nome, sigla FROM fornecedores WHERE ativo = 1 AND organizacao_id = ? ORDER BY nome");
    $stmt->execute([$orgId]);
    $fornecedores = $stmt->fetchAll();
}

// Carregar admins da org para notificação de revisão
$orgAdmins = [];
if ($orgId) {
    $stmtAdm = $db->prepare("SELECT id, nome, email FROM utilizadores WHERE organizacao_id = ? AND role IN ('org_admin','super_admin') AND ativo = 1 ORDER BY nome");
    $stmtAdm->execute([$orgId]);
    $orgAdmins = $stmtAdm->fetchAll(PDO::FETCH_ASSOC);
}

// Carregar secções permitidas por tipo de documento
$docTiposConfig = [];
$stmtDt = $db->query('SELECT slug, seccoes FROM doc_tipos WHERE ativo = 1');
while ($dtRow = $stmtDt->fetch(PDO::FETCH_ASSOC)) {
    $docTiposConfig[$dtRow['slug']] = json_decode($dtRow['seccoes'], true) ?: [];
}

// Determinar se é nova especificação ou edição
$isNew = isset($_GET['novo']) && $_GET['novo'] == '1';
$especId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Carregar pedidos da especificação
$pedidosEspec = [];
if (!$isNew && $especId) {
    $stmtPed = $db->prepare('SELECT * FROM especificacao_pedidos WHERE especificacao_id = ? ORDER BY ordem');
    $stmtPed->execute([$especId]);
    $pedidosEspec = $stmtPed->fetchAll(PDO::FETCH_ASSOC);
}

if ($isNew) {
    // Nova especificação - gerar número e defaults
    $numero = gerarNumeroEspecificacao($db, $orgId);
    $espec = [
        'id' => 0,
        'numero' => $numero,
        'titulo' => '',
        'idioma' => 'pt',
        'versao' => '1.0',
        'estado' => 'rascunho',
        'produto_ids' => [],
        'fornecedor_ids' => [],
        'cliente_id' => '',
        'produto_nome' => '',
        'produto_tipo' => null,
        'cliente_nome' => '',
        'cliente_sigla' => '',
        'fornecedor_nome' => '',
        'fornecedor_sigla' => '',
        'criado_por' => $user['id'],
        'criado_por_nome' => $user['nome'],
        'data_emissao' => date('Y-m-d'),
        'data_revisao' => date('Y-m-d'),
        'data_validade' => date('Y-m-d', strtotime('+1 year')),
        'objetivo' => '',
        'ambito' => '',
        'definicao_material' => '',
        'regulamentacao' => getRegulamentacaoPadrao(),
        'processos' => '',
        'embalagem' => '',
        'aceitacao' => '',
        'arquivo_texto' => '',
        'indemnizacao' => '',
        'observacoes' => '',
        'senha_publica' => '',
        'codigo_acesso' => '',
        'parametros' => [],
        'config_visual' => null,
        'motivo_devolucao' => null,
        'seccoes' => [],
        'ficheiros' => [],
    ];
} elseif ($especId > 0) {
    // Editar especificação existente
    $espec = getEspecificacaoCompleta($db, $especId);
    if (!$espec) {
        header('Location: ' . BASE_PATH . '/dashboard.php');
        exit;
    }
    // Verificar acesso multi-tenant (não-super_admin só acede à sua org)
    if (!isSuperAdmin() && (int)($espec['organizacao_id'] ?? 0) !== (int)$orgId) {
        header('Location: ' . BASE_PATH . '/dashboard.php');
        exit;
    }

    // Backward compat: se não há secções dinâmicas mas há campos fixos, converter
    if (empty($espec['seccoes'])) {
        $fixedSections = [
            'objetivo' => 'Objetivo',
            'ambito' => 'Âmbito',
            'definicao_material' => 'Definição do Material',
            'regulamentacao' => 'Regulamentação',
            'processos' => 'Processos',
            'embalagem' => 'Embalagem',
            'aceitacao' => 'Critérios de Aceitação',
            'arquivo_texto' => 'Arquivo',
            'indemnizacao' => 'Indemnização',
            'observacoes' => 'Observações',
        ];
        $ordem = 0;
        foreach ($fixedSections as $key => $titulo) {
            if (!empty($espec[$key])) {
                $espec['seccoes'][] = [
                    'titulo' => $titulo,
                    'conteudo' => $espec[$key],
                    'ordem' => $ordem,
                ];
                $ordem++;
            }
        }
        // Se ainda vazio, adicionar uma secção default
        if (empty($espec['seccoes'])) {
            $espec['seccoes'][] = ['titulo' => 'Objetivo', 'conteudo' => '', 'ordem' => 0];
        }
    }
} else {
    header('Location: ' . BASE_PATH . '/dashboard.php');
    exit;
}

// Super admin a ver spec de outra org → só leitura
$saOutraOrg = (isSuperAdmin() && !$isNew && ($espec['organizacao_id'] ?? null) != $orgId && $orgId !== null);

// Versionamento
$versaoBloqueada = (bool)($espec['versao_bloqueada'] ?? 0);
$grupoVersao = $espec['grupo_versao'] ?? '';
$versaoNumero = (int)($espec['versao_numero'] ?? 1);
$versoesGrupo = [];
$resumoAceitacao = ['total_tokens' => 0, 'aceites' => 0, 'rejeicoes' => 0, 'pendentes' => 0];
$tokensEspec = [];
if (!$isNew && $grupoVersao) {
    $versoesGrupo = getVersoesGrupo($db, $grupoVersao);
}
if (!$isNew) {
    $resumoAceitacao = getResumoAceitacao($db, $espec['id']);
    $tokensEspec = getTokensEspecificacao($db, $espec['id']);
    // Última visita ao histórico para badge de "novas decisões"
    $stmtVis = $db->prepare('SELECT ultima_visita FROM historico_visitas WHERE utilizador_id = ? AND especificacao_id = ?');
    $stmtVis->execute([$user['id'], $espec['id']]);
    $ultimaVisita = $stmtVis->fetchColumn() ?: '2000-01-01';
    $nNovasDecisoes = 0;
    foreach ($tokensEspec as $t) {
        if (!empty($t['tipo_decisao']) && $t['decisao_em'] > $ultimaVisita) $nNovasDecisoes++;
    }
}

// Templates de parâmetros
$categoriasPadrao = getCategoriasPadrao($orgId);
// Config visual (JSON -> array com defaults, usando cores da org)
$orgCor = $user['org_cor'] ?? '#2596be';
$configVisual = parseConfigVisual($espec['config_visual'] ?? '', $orgCor);

$pageTitle = $isNew ? 'Nova Especificação' : 'Editar: ' . sanitize($espec['numero']);
$pageSubtitle = 'Editor de Especificação';
$showNav = true;
$activeNav = 'especificacoes';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => BASE_PATH . '/dashboard.php'],
    ['label' => $isNew ? 'Nova Especificação' : sanitize($espec['numero'])]
];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Cadernos de Encargos</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/img/favicon.svg">
    <style>
        .save-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: var(--font-size-sm);
            color: var(--color-muted);
            transition: all var(--transition-fast);
        }
        .save-indicator.saving { color: var(--color-warning); }
        .save-indicator.saved { color: var(--color-success); }
        .save-indicator.error { color: var(--color-error); }
        .save-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--color-muted);
        }
        .save-indicator.saving .save-dot { background: var(--color-warning); animation: pulse 1s infinite; }
        .save-indicator.saved .save-dot { background: var(--color-success); }
        .save-indicator.error .save-dot { background: var(--color-error); }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .sticky-header {
            position: sticky;
            top: 49px;
            z-index: 40;
            background: var(--color-bg, #f3f4f6);
        }
        .editor-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--spacing-sm);
            flex-wrap: wrap;
            padding: var(--spacing-sm) 0;
            border-bottom: 1px solid var(--color-border, #e5e7eb);
        }
        .editor-toolbar .left { display: flex; align-items: center; gap: var(--spacing-sm); }
        .editor-toolbar .right { display: flex; align-items: center; gap: var(--spacing-sm); flex-wrap: wrap; justify-content: flex-end; }
        .sticky-header .tabs { background: var(--color-bg, #f3f4f6); margin-bottom: 0; padding-bottom: 0; }
        .sticky-header { box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: var(--spacing-lg); }

        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: var(--spacing-sm);
            max-height: 400px;
            overflow-y: auto;
            padding: var(--spacing-sm);
        }
        .template-item {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            padding: var(--spacing-sm) var(--spacing-md);
            background: var(--color-bg);
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
            font-size: var(--font-size-sm);
            border: 1px solid var(--color-border);
        }
        .template-item:hover {
            background: var(--color-primary-lighter);
            border-color: var(--color-primary);
        }
        .template-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }
        .template-item .info {
            flex: 1;
        }
        .template-item .info .name { font-weight: 500; }
        .template-item .info .method { color: var(--color-muted); font-size: var(--font-size-xs); }

        .defect-group {
            margin-bottom: var(--spacing-lg);
        }
        .defect-group-header {
            font-weight: 600;
            font-size: var(--font-size-sm);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: var(--border-radius-sm);
            margin-bottom: var(--spacing-sm);
        }
        .defect-group-header.critico { background: rgba(180, 35, 24, 0.1); color: var(--color-error); }
        .defect-group-header.maior { background: rgba(179, 92, 0, 0.1); color: var(--color-warning); }
        .defect-group-header.menor { background: var(--color-primary-light); color: var(--color-primary-dark); }

        .defect-row {
            display: grid;
            grid-template-columns: 180px 1fr 40px;
            gap: var(--spacing-xs);
            align-items: center;
            margin-bottom: var(--spacing-xs);
        }
        .class-row {
            display: grid;
            grid-template-columns: 150px 100px 1fr 40px;
            gap: var(--spacing-xs);
            align-items: center;
            margin-bottom: var(--spacing-xs);
        }
        .class-row.header, .defect-row.header {
            font-weight: 600;
            font-size: var(--font-size-xs);
            color: var(--color-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: var(--spacing-sm);
        }

        .progress-bar-container {
            width: 100%;
            height: 4px;
            background: var(--color-border);
            border-radius: 2px;
            overflow: hidden;
            margin-top: var(--spacing-xs);
        }
        .progress-bar-fill {
            height: 100%;
            background: var(--color-primary);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .section-label {
            font-size: var(--font-size-xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--color-primary);
            margin-bottom: var(--spacing-xs);
        }

        .seccao-block {
            border: 1px solid var(--color-border);
            border-radius: var(--border-radius-sm);
            margin-bottom: var(--spacing-md);
            background: var(--color-bg);
        }
        .seccao-header {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            padding: var(--spacing-sm) var(--spacing-md);
            background: var(--color-bg-alt, #f8f9fa);
            border-bottom: 1px solid var(--color-border);
            border-radius: var(--border-radius-sm) var(--border-radius-sm) 0 0;
        }
        .seccao-numero {
            font-weight: 700;
            font-size: var(--font-size-base);
            color: var(--color-primary);
            min-width: 28px;
        }
        .seccao-titulo {
            flex: 1;
            max-width: 320px;
            font-weight: 600;
            font-size: var(--font-size-sm);
            border: 1px solid transparent;
            background: transparent;
            padding: 4px 8px;
            border-radius: var(--border-radius-sm);
            transition: all var(--transition-fast);
        }
        .seccao-titulo:hover, .seccao-titulo:focus {
            border-color: var(--color-border);
            background: white;
        }
        .seccao-ai-btns {
            display: flex;
            gap: 4px;
            margin-left: auto;
        }
        .btn-ai {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            font-size: 11px;
            font-weight: 600;
            border-radius: var(--border-radius-sm);
            border: 1px solid #8b5cf6;
            color: #8b5cf6;
            background: white;
            cursor: pointer;
            transition: all var(--transition-fast);
            white-space: nowrap;
        }
        .btn-ai:hover {
            background: #8b5cf6;
            color: white;
        }
        .btn-ai .ai-icon {
            font-size: 13px;
        }
        .btn-ai.loading {
            opacity: 0.7;
            pointer-events: none;
        }
        .ai-disclaimer {
            font-size: 9px;
            color: #8b5cf6;
            background: #ede9fe;
            padding: 2px 5px;
            border-radius: 3px;
            cursor: help;
            font-weight: 600;
        }
        .seccao-actions {
            display: flex;
            gap: 2px;
        }
        .seccao-actions .btn {
            padding: 2px 6px;
            font-size: 12px;
            line-height: 1;
        }
        .seccao-remove-btn:hover {
            color: var(--color-error) !important;
        }

        /* Modal IA */
        .ai-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1100;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ai-modal-overlay.hidden { display: none; }
        .ai-modal {
            background: white;
            border-radius: var(--border-radius-md, 8px);
            width: 480px;
            max-width: 90vw;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .ai-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: var(--spacing-md);
            border-bottom: 1px solid var(--color-border);
        }
        .ai-modal-header h3 {
            margin: 0;
            font-size: var(--font-size-base);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ai-modal-header h3 .ai-badge {
            background: #8b5cf6;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
        }
        .ai-modal-body {
            padding: var(--spacing-md);
        }
        .ai-modal-body label {
            display: block;
            font-size: var(--font-size-sm);
            font-weight: 600;
            margin-bottom: var(--spacing-xs);
            color: var(--color-text-secondary, #555);
        }
        .ai-modal-body textarea {
            width: 100%;
            min-height: 100px;
            padding: var(--spacing-sm);
            border: 1px solid var(--color-border);
            border-radius: var(--border-radius-sm);
            font-size: var(--font-size-sm);
            resize: vertical;
        }
        .ai-modal-body textarea:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
        }
        .ai-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: var(--spacing-sm);
            padding: var(--spacing-md);
            border-top: 1px solid var(--color-border);
        }
        .ai-result-preview {
            background: #faf5ff;
            border: 1px solid #d8b4fe;
            border-radius: var(--border-radius-sm);
            padding: var(--spacing-sm);
            max-height: 300px;
            overflow-y: auto;
            font-size: var(--font-size-sm);
            line-height: 1.5;
            margin-top: var(--spacing-sm);
        }
        .btn-ai-submit {
            background: #8b5cf6;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
            font-size: var(--font-size-sm);
            cursor: pointer;
        }
        .btn-ai-submit:hover { background: #7c3aed; }
        .btn-ai-submit:disabled { opacity: 0.6; cursor: not-allowed; }
        .seccao-block .tox-tinymce, .seccao-block textarea {
            border-radius: 0 0 var(--border-radius-sm) var(--border-radius-sm);
            border: none;
            border-top: none;
        }
        .seccao-block textarea {
            width: 100%;
            padding: var(--spacing-sm) var(--spacing-md);
            resize: vertical;
            min-height: 120px;
        }

        /* Barra fixa de ações do conteúdo */
        .content-actions-bar {
            position: sticky;
            bottom: 0;
            background: white;
            border-top: 2px solid var(--color-primary);
            padding: var(--spacing-sm) var(--spacing-md);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--spacing-md);
            z-index: 30;
            border-radius: 0 0 var(--border-radius-md, 8px) var(--border-radius-md, 8px);
            box-shadow: 0 -4px 12px rgba(0,0,0,0.08);
        }
        .content-actions-bar .btn {
            font-weight: 600;
            gap: 6px;
            display: inline-flex;
            align-items: center;
        }
        /* Secções secundárias (nivel 2) — indentação */
        .seccao-block[data-nivel="2"] {
            margin-left: 32px;
            border-left: 3px solid var(--color-primary, #2563eb);
            padding-left: 12px;
        }
        .seccao-block[data-nivel="2"] .seccao-numero {
            font-size: 13px;
            color: var(--color-muted, #6b7280);
        }
        /* Modal Nivel */
        .nivel-modal-btns { display: flex; gap: 16px; justify-content: center; margin-top: 16px; }
        .nivel-modal-btns .btn-nivel {
            flex: 1; padding: 16px; border: 2px solid var(--color-border, #e5e7eb); border-radius: 8px;
            background: white; cursor: pointer; text-align: center; transition: all 0.15s;
        }
        .nivel-modal-btns .btn-nivel:hover { border-color: var(--color-primary, #2563eb); background: #f0f7ff; }
        .nivel-modal-btns .btn-nivel strong { display: block; font-size: 15px; margin-bottom: 4px; }
        .nivel-modal-btns .btn-nivel span { font-size: 12px; color: var(--color-muted, #6b7280); }

        /* Tabela de parâmetros inline (editável) */
        .seccao-ensaios-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: var(--font-size-sm);
        }
        .seccao-ensaios-table th {
            background: var(--color-primary);
            color: white;
            padding: 6px 10px;
            text-align: left;
            font-weight: 600;
            font-size: var(--font-size-xs);
        }
        .seccao-ensaios-table td {
            padding: 4px 6px;
            border-bottom: 1px solid var(--color-border);
            overflow: hidden;
        }
        .seccao-ensaios-table input,
        .seccao-ensaios-table textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid transparent;
            background: transparent;
            padding: 4px 6px;
            font-size: var(--font-size-xs);
            border-radius: 3px;
            transition: all var(--transition-fast);
        }
        .seccao-ensaios-table textarea {
            resize: none;
            overflow: hidden;
            min-height: 28px;
            line-height: 1.4;
            font-family: inherit;
        }
        .seccao-ensaios-table input:hover,
        .seccao-ensaios-table input:focus,
        .seccao-ensaios-table textarea:hover,
        .seccao-ensaios-table textarea:focus {
            border-color: var(--color-border);
            background: white;
        }
        .seccao-ensaios-table .cat-row td {
            background: var(--color-primary-lighter);
            font-weight: 600;
            color: var(--color-primary-dark);
            padding: 4px 10px;
            font-size: var(--font-size-xs);
        }
        .seccao-ensaios-table .remove-btn {
            background: none;
            border: none;
            color: var(--color-muted);
            cursor: pointer;
            font-size: 14px;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .seccao-ensaios-table .remove-btn:hover {
            color: var(--color-error);
            background: rgba(180,35,24,0.1);
        }
        /* Handles de redimensionamento de colunas */
        .seccao-ensaios-table th {
            position: relative;
            user-select: none;
        }
        .seccao-ensaios-table th .col-resize-handle {
            position: absolute;
            right: -3px;
            top: 0;
            bottom: 0;
            width: 6px;
            cursor: col-resize;
            z-index: 2;
        }
        .seccao-ensaios-table th .col-resize-handle:hover,
        .seccao-ensaios-table th .col-resize-handle.active {
            background: rgba(255,255,255,0.35);
        }
        /* Merge cells - seleção */
        .seccao-ensaios-table td.merge-selected {
            background: rgba(59,130,246,0.12) !important;
            outline: 2px solid rgba(59,130,246,0.5);
            outline-offset: -2px;
        }
        /* Merge cells - célula master (com rowspan real) */
        .seccao-ensaios-table td.merge-master {
            vertical-align: middle;
            position: relative;
        }
        .seccao-ensaios-table td.merge-master input,
        .seccao-ensaios-table td.merge-master textarea {
            text-align: center;
        }
        .seccao-ensaios-table td.merge-master .merge-tools {
            position: absolute;
            top: 1px;
            right: 1px;
            display: flex;
            gap: 1px;
            opacity: 0;
            transition: opacity 0.15s;
        }
        .seccao-ensaios-table td.merge-master:hover .merge-tools {
            opacity: 1;
        }
        .seccao-ensaios-table td.merge-master .merge-tools button {
            background: none;
            border: none;
            color: var(--color-muted);
            cursor: pointer;
            font-size: 9px;
            padding: 1px 3px;
            border-radius: 3px;
            line-height: 1;
        }
        .seccao-ensaios-table td.merge-master .merge-tools button:hover {
            color: var(--color-primary);
            background: rgba(59,130,246,0.1);
        }
        .seccao-ensaios-table td.merge-master .merge-tools .unmerge-btn:hover {
            color: var(--color-error);
            background: rgba(180,35,24,0.1);
        }
        /* Merge cells - células slave (ocultas) */
        .seccao-ensaios-table td.merge-slave {
            border-top-color: transparent !important;
            border-bottom-color: transparent !important;
        }
        .seccao-ensaios-table td.merge-slave-last {
            border-top-color: transparent !important;
            border-bottom-color: var(--color-border) !important;
        }
        .seccao-ensaios-table td.merge-slave input,
        .seccao-ensaios-table td.merge-slave-last input,
        .seccao-ensaios-table td.merge-slave textarea,
        .seccao-ensaios-table td.merge-slave-last textarea {
            visibility: hidden;
        }
        /* Botão flutuante de merge */
        .merge-float-actions {
            position: fixed;
            background: white;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            padding: 4px 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            z-index: 100;
            display: none;
            align-items: center;
            gap: 6px;
        }
        .merge-float-actions.visible {
            display: flex;
        }
        /* Linha de cabeçalho de categoria */
        .seccao-ensaios-table .ensaio-cat-row td {
            background-color: var(--color-primary-lighter);
            padding: 0;
            border-bottom: 1px solid var(--color-border);
            position: relative;
            text-align: center;
        }
        .seccao-ensaios-table .cat-header-input {
            width: 100%;
            border: none;
            background: transparent;
            font-weight: 600;
            color: var(--color-primary-dark);
            font-size: 13px;
            padding: 6px 10px;
            outline: none;
            text-align: center;
        }
        .seccao-ensaios-table .cat-header-input::placeholder {
            color: var(--color-muted);
            font-weight: 400;
        }
        .seccao-ensaios-table .cat-remove-btn {
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0;
            transition: opacity 0.15s;
        }
        .seccao-ensaios-table .ensaio-cat-row:hover .cat-remove-btn {
            opacity: 1;
        }
        .seccao-ensaios-wrap {
            padding: 0;
        }
        .seccao-ensaios-actions {
            padding: var(--spacing-sm) var(--spacing-md);
            border-top: 1px solid var(--color-border);
            display: flex;
            gap: var(--spacing-sm);
        }

        /* TinyMCE - toolbar compacta e elegante */
        .tox-tinymce { border: none !important; border-radius: 0 !important; }
        .tox .tox-editor-header { box-shadow: none !important; border-bottom: 1px solid #e5e7eb; background: #fafbfc !important; padding: 2px 4px !important; }
        .tox .tox-toolbar-overlord { background: #fafbfc !important; }
        .tox .tox-toolbar { background: transparent !important; }
        .tox .tox-toolbar__primary { background: transparent !important; }
        .tox .tox-toolbar__overflow { background: #fafbfc !important; border-top: 1px solid #e5e7eb; padding: 2px 4px !important; }
        .tox .tox-toolbar__group { padding: 0 2px; }
        .tox .tox-toolbar__group::after { height: 20px !important; background: #dde1e6 !important; margin: 0 2px !important; }
        .tox .tox-tbtn {
            width: 30px; height: 30px; margin: 1px 0;
            border-radius: 5px; cursor: pointer;
        }
        .tox .tox-tbtn:hover { background: #edf0f4 !important; }
        .tox .tox-tbtn--enabled, .tox .tox-tbtn--enabled:hover { background: #e0e5ec !important; }
        .tox .tox-tbtn svg { fill: #374151 !important; }
        .tox .tox-tbtn--bespoke {
            height: 30px; border-radius: 5px;
            background: #f0f2f5 !important;
            border: none !important;
        }
        .tox .tox-tbtn--bespoke:hover { background: #e4e8ed !important; }
        .tox .tox-tbtn--bespoke .tox-tbtn__select-label {
            font-size: 11px; font-weight: 500;
            width: auto; max-width: 85px;
            overflow: hidden; text-overflow: ellipsis;
            color: #374151;
        }
        .tox .tox-split-button { border-radius: 5px; }
        .tox .tox-split-button:hover { box-shadow: none !important; }
        .tox .tox-split-button .tox-tbtn { width: 24px; }
        .tox .tox-tbtn--select { height: 30px; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
</head>
<body>
    <?php
    // CSS override para cores da organização
    $branding = getOrgBranding();
    $logoSrc = BASE_PATH . '/assets/img/exi_logo.png';
    if ($branding['logo']) $logoSrc = BASE_PATH . '/uploads/logos/' . $branding['logo'];
    ?>
    <style>:root { --color-primary: <?= sanitize($branding['cor']) ?>; --color-primary-dark: <?= sanitize($branding['cor_dark']) ?>; --color-primary-lighter: <?= sanitize($branding['cor_light']) ?>; }</style>

    <!-- HEADER -->
    <div class="app-header">
        <div class="logo">
            <img src="<?= $logoSrc ?>" alt="<?= sanitize($branding['nome']) ?>" onerror="this.style.display='none'">
            <div>
                <h1>Cadernos de Encargos</h1>
                <span><?= sanitize($pageSubtitle) ?></span>
            </div>
        </div>
        <div class="header-actions">
            <?php if (!$saOutraOrg): ?>
            <div class="save-indicator" id="saveIndicator">
                <span class="save-dot"></span>
                <span class="save-text">Pronto</span>
            </div>
            <?php endif; ?>
            <span class="user-info"><?= sanitize($user['nome']) ?> (<?= $user['role'] ?>)</span>
            <?php if (in_array($user['role'], ['super_admin', 'org_admin'])): ?>
                <a href="<?= BASE_PATH ?>/admin.php" class="btn btn-secondary btn-sm">Admin</a>
            <?php endif; ?>
            <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-ghost btn-sm">Sair</a>
        </div>
    </div>

    <!-- EDITOR TOOLBAR -->
    <div class="container">
        <div class="sticky-header no-print">
        <div class="editor-toolbar">
            <div class="left">
                <a href="<?= BASE_PATH ?>/dashboard.php" class="btn btn-ghost btn-sm" title="Voltar ao Dashboard" onclick="return verificarSaidaPagina(this.href)">&larr; Voltar</a>
                <h2><?= $saOutraOrg ? 'Ver Especificação' : ($isNew ? 'Nova Especificação' : 'Editar Especificação') ?></h2>
                <span class="pill <?= $espec['estado'] === 'ativo' ? 'pill-success' : ($espec['estado'] === 'rascunho' ? 'pill-warning' : ($espec['estado'] === 'em_revisao' ? 'pill-info' : 'pill-muted')) ?>" id="estadoPill">
                    <?= $espec['estado'] === 'em_revisao' ? 'Em Revisão' : ucfirst($espec['estado']) ?>
                </span>
                <span class="muted" id="specNumero"><?= sanitize($espec['numero']) ?></span>
                <?php if (!$isNew): ?>
                <span class="pill pill-info" style="font-size:11px;">v<?= sanitize($espec['versao']) ?></span>
                <?php if ($versaoBloqueada): ?>
                <span class="pill pill-muted" style="font-size:11px;" title="Versão bloqueada">Bloqueada</span>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="right">
                <?php if (!$saOutraOrg): ?>
                <?php if (!$versaoBloqueada): ?>
                <?php $isAdminUser = in_array($user['role'], ['super_admin', 'org_admin']); ?>
                <button class="btn btn-primary btn-sm" onclick="guardarTudo()">Guardar</button>
                <?php if (!$isNew): ?>
                <?php if ($espec['estado'] === 'rascunho'): ?>
                <button class="btn btn-info btn-sm" onclick="submeterRevisao()" title="Submeter para revisão por um administrador">Submeter Revisão</button>
                <?php endif; ?>
                <?php if ($espec['estado'] === 'em_revisao' && $isAdminUser): ?>
                <button class="btn btn-success btn-sm" onclick="aprovarEspecificacao()" title="Aprovar esta especificação">Aprovar</button>
                <button class="btn btn-warning btn-sm" onclick="devolverEspecificacao()" title="Devolver ao autor com comentário">Devolver</button>
                <?php endif; ?>
                <?php if ($espec['estado'] === 'ativo' || ($espec['estado'] === 'em_revisao' && $isAdminUser)): ?>
                <button class="btn btn-primary btn-sm" onclick="publicarVersaoUI()" title="Bloquear esta versão e enviar ao cliente">Publicar</button>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($isAdminUser): ?>
                <div class="dropdown">
                    <button class="btn btn-secondary btn-sm" onclick="toggleDropdown('estadoMenu')">Estado</button>
                    <div class="dropdown-menu" id="estadoMenu">
                        <button onclick="alterarEstado('rascunho')">Rascunho</button>
                        <button onclick="alterarEstado('em_revisao')">Em Revisão</button>
                        <button onclick="alterarEstado('ativo')">Ativo</button>
                        <div class="dropdown-divider"></div>
                        <button onclick="alterarEstado('obsoleto')">Obsoleto</button>
                    </div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <button class="btn btn-primary btn-sm" onclick="criarNovaVersaoUI()" title="Criar nova versão editável a partir desta">Nova Versão</button>
                <?php endif; ?>
                <?php endif; ?>
                <span style="border-left:1px solid var(--color-border);height:20px;"></span>
                <?php if (!$isNew): ?>
                <button class="btn btn-outline-primary btn-sm" onclick="traduzirEspecificacao()" title="Traduzir para outro idioma com IA">Traduzir</button>
                <?php endif; ?>
                <a href="<?= BASE_PATH ?>/pdf.php?id=<?= $espec['id'] ?>&view=1&t=<?= time() ?>" class="btn btn-outline-primary btn-sm" target="_blank" title="Exportar PDF" id="btnPdf"<?= $isNew ? ' style="display:none"' : '' ?>>PDF</a>
                <a href="<?= BASE_PATH ?>/ver.php?id=<?= $espec['id'] ?>" class="btn btn-outline-primary btn-sm" target="_blank" title="Ver documento completo" id="btnVer"<?= $isNew ? ' style="display:none"' : '' ?>>Ver</a>
                <button class="btn btn-outline-primary btn-sm" onclick="window.print()" title="Imprimir">Imprimir</button>
                <?php if (!$isNew && in_array($user['role'], ['super_admin', 'org_admin'])): ?>
                <button class="btn btn-outline-primary btn-sm" onclick="guardarComoTemplate()" title="Guardar como modelo reutilizável">Template</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($saOutraOrg): ?>
        <div class="alert alert-info" style="margin: var(--spacing-sm) 0; display:flex; align-items:center; gap:var(--spacing-sm);">
            <strong>Modo consulta</strong> &mdash; Esta especificação pertence a outra organização. Só pode visualizar.
        </div>
        <?php elseif ($versaoBloqueada): ?>
        <div class="alert alert-warning" style="margin: var(--spacing-sm) 0; display:flex; align-items:center; gap:var(--spacing-sm);">
            <strong>Versão bloqueada (v<?= sanitize($espec['versao']) ?>)</strong> &mdash; Esta versão foi publicada e não pode ser editada. Use "Nova Versão" para criar uma cópia editável.
        </div>
        <?php endif; ?>

        <?php if (!empty($espec['motivo_devolucao']) && $espec['estado'] === 'rascunho'): ?>
        <div class="alert alert-warning" style="margin: var(--spacing-sm) 0;">
            <strong>Devolvida para correção:</strong> <?= sanitize($espec['motivo_devolucao']) ?>
        </div>
        <?php elseif ($espec['estado'] === 'em_revisao'): ?>
        <div class="alert alert-info" style="margin: var(--spacing-sm) 0;">
            <strong>Em revisão</strong> &mdash; A aguardar aprovação de um administrador.
        </div>
        <?php endif; ?>

        <!-- TABS NAVIGATION -->
        <div class="tabs" id="mainTabs">
            <button class="tab active" data-tab="dados-gerais">Dados Gerais</button>
            <button class="tab" data-tab="conteudo">Conteúdo</button>
            <?php if (!$saOutraOrg): ?>
            <button class="tab" data-tab="partilha">Partilha</button>
            <button class="tab" data-tab="historico">Histórico<?php if (!empty($nNovasDecisoes)): ?> <span style="background:var(--color-primary); color:#fff; font-size:10px; padding:1px 6px; border-radius:10px; margin-left:4px;"><?= $nNovasDecisoes ?></span><?php endif; ?></button>
            <button class="tab" data-tab="configuracoes">Aspeto PDF / Online</button>
            <?php endif; ?>
        </div>
        </div><!-- /.sticky-header -->

        <!-- CONTENT GRID WITH SIDEBAR -->
        <div class="content-grid with-sidebar">
            <!-- MAIN CONTENT -->
            <div class="main-content">

                <!-- TAB 1: DADOS GERAIS -->
                <div class="tab-panel active" id="panel-dados-gerais">
                    <?php if ($isNew): ?>
                    <div class="card" id="templateSelector" style="border-left:3px solid var(--color-primary); margin-bottom:var(--spacing-md);">
                        <div class="card-header">
                            <span class="card-title">Criar a partir de Template</span>
                            <span class="muted" style="font-size:12px;">(opcional)</span>
                        </div>
                        <div style="padding:var(--spacing-sm) var(--spacing-md);">
                            <p class="muted" style="font-size:12px; margin-bottom:8px;">Escolha um template para pré-preencher as secções, ou comece em branco.</p>
                            <div style="display:flex; gap:var(--spacing-sm); align-items:center;">
                                <select id="templateSelect" style="flex:1;">
                                    <option value="">— Especificação em branco —</option>
                                </select>
                                <button class="btn btn-secondary btn-sm" onclick="carregarTemplate()">Aplicar</button>
                                <button class="btn btn-danger btn-sm" onclick="eliminarTemplateSelecionado()" title="Eliminar template selecionado">&times;</button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Identificação</span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="tipo_doc">Tipo de Documento</label>
                                <select id="tipo_doc" name="tipo_doc" onchange="atualizarSeccoesPermitidas()">
                                    <?php
                                    $stmtDtSel = $db->query('SELECT slug, nome FROM doc_tipos WHERE ativo = 1 ORDER BY id');
                                    while ($dtOpt = $stmtDtSel->fetch(PDO::FETCH_ASSOC)):
                                    ?>
                                    <option value="<?= sanitize($dtOpt['slug']) ?>" <?= ($espec['tipo_doc'] ?? 'caderno') === $dtOpt['slug'] ? 'selected' : '' ?>><?= sanitize($dtOpt['nome']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="idioma">Idioma</label>
                                <select id="idioma" name="idioma">
                                    <?php $idiomas = ['pt' => 'Português', 'en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'de' => 'Deutsch', 'it' => 'Italiano'];
                                    foreach ($idiomas as $code => $label): ?>
                                    <option value="<?= $code ?>" <?= ($espec['idioma'] ?? 'pt') === $code ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="numero">Número</label>
                                <input type="text" id="numero" name="numero" value="<?= sanitize($espec['numero']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="versao">Versão</label>
                                <input type="text" id="versao" name="versao" value="<?= sanitize($espec['versao']) ?>" placeholder="1.0">
                            </div>
                            <input type="hidden" id="estado" name="estado" value="<?= sanitize($espec['estado']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="titulo">Título da Especificação</label>
                            <input type="text" id="titulo" name="titulo" value="<?= sanitize($espec['titulo']) ?>" placeholder="Ex: Especificação Técnica - Rolha Natural 45x24">
                        </div>
                    </div>

                    <?php
                    $isSA = isSuperAdmin();
                    $temClientes = $isSA || !empty($_SESSION['org_tem_clientes']);
                    $temFornecedores = $isSA || !empty($_SESSION['org_tem_fornecedores']);
                    $cardTitle = 'Produto(s)';
                    if ($temClientes && $temFornecedores) $cardTitle = 'Produto(s), Cliente e Fornecedor(es)';
                    elseif ($temClientes) $cardTitle = 'Produto(s) e Cliente';
                    elseif ($temFornecedores) $cardTitle = 'Produto(s) e Fornecedor(es)';
                    ?>
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title"><?= $cardTitle ?></span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Produto(s)</label>
                                <div class="multi-select-wrap" id="produtosWrap">
                                    <div class="multi-select-toggle" onclick="toggleMultiSelect('produtosWrap')">
                                        <span class="multi-select-label" id="produtosLabel">-- Selecionar produto(s) --</span>
                                        <span class="multi-select-arrow">&#9662;</span>
                                    </div>
                                    <div class="multi-select-dropdown" id="produtosDropdown">
                                        <?php foreach ($produtos as $p):
                                            $checked = in_array($p['id'], $espec['produto_ids'] ?? []) ? 'checked' : '';
                                        ?>
                                        <label class="multi-select-item">
                                            <input type="checkbox" name="produto_ids[]" value="<?= $p['id'] ?>" <?= $checked ?> onchange="updateMultiLabel('produtosWrap'); marcarAlterado();">
                                            <?= sanitize($p['nome']) ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($temClientes): ?>
                            <div class="form-group">
                                <label for="cliente_id">Cliente</label>
                                <select id="cliente_id" name="cliente_id">
                                    <option value="">-- Selecionar cliente --</option>
                                    <?php foreach ($clientes as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= $espec['cliente_id'] == $c['id'] ? 'selected' : '' ?>>
                                            <?= sanitize($c['nome']) ?> <?= $c['sigla'] ? '(' . sanitize($c['sigla']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if ($temFornecedores): ?>
                            <div class="form-group">
                                <label>Fornecedor(es)</label>
                                <div class="multi-select-wrap" id="fornecedoresWrap">
                                    <div class="multi-select-toggle" onclick="toggleMultiSelect('fornecedoresWrap')">
                                        <span class="multi-select-label" id="fornecedoresLabel">Todos os fornecedores</span>
                                        <span class="multi-select-arrow">&#9662;</span>
                                    </div>
                                    <div class="multi-select-dropdown" id="fornecedoresDropdown">
                                        <?php foreach ($fornecedores as $f):
                                            $checked = in_array($f['id'], $espec['fornecedor_ids'] ?? []) ? 'checked' : '';
                                        ?>
                                        <label class="multi-select-item">
                                            <input type="checkbox" name="fornecedor_ids[]" value="<?= $f['id'] ?>" <?= $checked ?> onchange="updateMultiLabel('fornecedoresWrap'); marcarAlterado();">
                                            <?= sanitize($f['nome']) ?> <?= $f['sigla'] ? '(' . sanitize($f['sigla']) . ')' : '' ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Datas</span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="data_emissao">Data de Emissão</label>
                                <input type="date" id="data_emissao" name="data_emissao" value="<?= sanitize($espec['data_emissao'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="data_revisao">Data de Revisão</label>
                                <input type="date" id="data_revisao" name="data_revisao" value="<?= sanitize($espec['data_revisao'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="data_validade">Data de Validade</label>
                                <input type="date" id="data_validade" name="data_validade" value="<?= sanitize($espec['data_validade'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- COMENTÁRIOS INTERNOS -->
                    <?php if (!$isNew): ?>
                    <div class="card" id="cardComentarios">
                        <div class="card-header">
                            <span class="card-title">Comentários Internos</span>
                            <span class="muted" id="comentariosCount"></span>
                        </div>
                        <?php if (!$saOutraOrg): ?>
                        <div style="margin-bottom:var(--spacing-md);">
                            <textarea id="novoComentario" rows="2" placeholder="Escrever comentário..." style="width:100%;margin-bottom:var(--spacing-xs);"></textarea>
                            <button class="btn btn-primary btn-sm" onclick="adicionarComentario()">Comentar</button>
                        </div>
                        <?php endif; ?>
                        <div id="listaComentarios" style="max-height:400px; overflow-y:auto;">
                            <p class="muted" style="font-size:13px;">A carregar...</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 2: CONTEUDO -->
                <div class="tab-panel" id="panel-conteudo">
                    <div class="card" style="padding-bottom: 0;">
                        <div class="card-header">
                            <span class="card-title">Secções do Caderno de Encargos</span>
                        </div>

                        <div id="seccoesContainer">
                            <?php
                            // Calcular numeração hierárquica
                            $hierNumbers = [];
                            $mainCounter = 0;
                            $subCounter = 0;
                            foreach ($espec['seccoes'] as $si => $s) {
                                $niv = (int)($s['nivel'] ?? 1);
                                if ($niv === 1) {
                                    $mainCounter++;
                                    $subCounter = 0;
                                    $hierNumbers[$si] = $mainCounter . '.';
                                } else {
                                    $subCounter++;
                                    $hierNumbers[$si] = $mainCounter . '.' . $subCounter . '.';
                                }
                            }
                            ?>
                            <?php foreach ($espec['seccoes'] as $i => $sec):
                                $secTipo = $sec['tipo'] ?? 'texto';
                            ?>
                                <?php if ($secTipo === 'texto'): ?>
                                <div class="seccao-block" data-seccao-idx="<?= $i ?>" data-tipo="texto" data-nivel="<?= (int)($sec['nivel'] ?? 1) ?>">
                                    <div class="seccao-header">
                                        <span class="seccao-numero"><?= $hierNumbers[$i] ?? ($i + 1) . '.' ?></span>
                                        <input type="text" class="seccao-titulo" value="<?= sanitize($sec['titulo'] ?? '') ?>" placeholder="Título da secção">
                                        <div class="seccao-ai-btns">
                                            <button class="btn-ai" onclick="abrirAI(this, 'sugerir')" title="Sugerir conteúdo com IA"><span class="ai-icon">&#10024;</span> Sugerir</button>
                                            <button class="btn-ai" onclick="abrirAI(this, 'melhorar')" title="Melhorar conteúdo com IA"><span class="ai-icon">&#9998;</span> Melhorar</button>
                                            <span class="ai-disclaimer" title="Conteúdo gerado por IA deve ser revisto antes de usar">IA</span>
                                        </div>
                                        <div class="seccao-actions">
                                            <button class="btn btn-ghost btn-sm seccao-collapse-btn" onclick="toggleCollapse(this)" title="Colapsar/Expandir">&minus;</button>
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>
                                            <button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>
                                        </div>
                                    </div>
                                    <textarea id="seccao_<?= $i ?>" class="seccao-editor" rows="6" placeholder="Conteúdo da secção..."><?= $sec['conteudo'] ?? '' ?></textarea>
                                </div>
                                <?php elseif ($secTipo === 'ficheiros'): ?>
                                <?php
                                    $ficConf = json_decode($sec['conteudo'] ?? '{}', true);
                                    $ficPosicao = $ficConf['posicao'] ?? 'final';
                                    $ficGrupo = $ficConf['grupo'] ?? 'default';
                                ?>
                                <div class="seccao-block" data-seccao-idx="<?= $i ?>" data-tipo="ficheiros" data-nivel="<?= (int)($sec['nivel'] ?? 1) ?>" data-grupo="<?= sanitize($ficGrupo) ?>">
                                    <div class="seccao-header">
                                        <span class="seccao-numero"><?= $hierNumbers[$i] ?? ($i + 1) . '.' ?></span>
                                        <input type="text" class="seccao-titulo" value="<?= sanitize($sec['titulo'] ?? 'Ficheiros Anexos') ?>" placeholder="Título">
                                        <span class="pill pill-info" style="font-size:10px; padding:2px 8px;">Ficheiros</span>
                                        <div class="seccao-actions">
                                            <button class="btn btn-ghost btn-sm seccao-collapse-btn" onclick="toggleCollapse(this)" title="Colapsar/Expandir">&minus;</button>
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>
                                            <button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>
                                        </div>
                                    </div>
                                    <div style="padding: var(--spacing-md);">
                                        <div style="margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                            <label style="font-size:12px; font-weight:600; color:var(--color-text);">No PDF:</label>
                                            <select class="fic-posicao" style="font-size:12px; padding:4px 8px; border:1px solid var(--color-border); border-radius:4px;">
                                                <option value="local" <?= $ficPosicao === 'local' ? 'selected' : '' ?>>Mostrar neste local</option>
                                                <option value="final" <?= $ficPosicao === 'final' ? 'selected' : '' ?>>Mostrar no final do documento</option>
                                            </select>
                                        </div>
                                        <div class="upload-zone" style="cursor:pointer; padding:20px; border:2px dashed var(--color-border); border-radius:8px; text-align:center;">
                                            <div class="icon">&#128206;</div>
                                            <p><strong>Arraste ficheiros ou clique para selecionar</strong></p>
                                            <p class="muted" style="font-size:12px;">Máx. 50MB. Formatos: PDF, DOC, XLS, JPG, PNG</p>
                                            <input type="file" class="fic-file-input" multiple style="display:none" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.bmp,.tif,.tiff,.csv,.txt">
                                        </div>
                                        <div class="fic-progress hidden" style="margin-top:8px;">
                                            <div class="flex-between"><span class="muted fic-file-name">A enviar...</span><span class="muted fic-percent">0%</span></div>
                                            <div class="progress-bar-container"><div class="progress-bar-fill fic-bar" style="width:0%"></div></div>
                                        </div>
                                        <ul class="file-list fic-file-list" style="margin-top:8px;"></ul>
                                    </div>
                                </div>
                                <?php elseif ($secTipo === 'parametros' || $secTipo === 'parametros_custom'): ?>
                                <?php
                                    $pc = parseParametrosSeccao($db, $sec, $espec);
                                    $pcRaw = $pc['raw']; $pcRows = $pc['rows']; $pcColunas = $pc['colunas'];
                                    $pcTipoId = $pc['tipo_id']; $pcTipoSlug = $pc['tipo_slug'];
                                    $pcTipoNome = $pc['tipo_nome']; $pcColWidths = $pc['colWidths'];
                                    $pcLegenda = $pc['legenda']; $pcLegTam = $pc['legenda_tamanho'];
                                    // Se colWidths não corresponde ao nº de colunas, usar largura do tipo ou recalcular
                                    if (count($pcColWidths) === count($pcColunas)) {
                                        $pcColW = $pcColWidths;
                                    } else {
                                        $pcColW = [];
                                        $defW = floor(90 / max(1, count($pcColunas)));
                                        foreach ($pcColunas as $pc) {
                                            $pcColW[] = $pc['largura'] ?? $defW;
                                        }
                                    }
                                ?>
                                <?php
                                    // Orientação: prioridade ao tipo, fallback ao valor guardado na secção
                                    $pcOrientacao = 'horizontal';
                                    if ($pcTipoId) {
                                        $stmtOri = $db->prepare('SELECT orientacao FROM parametros_tipos WHERE id = ?');
                                        $stmtOri->execute([(int)$pcTipoId]);
                                        $oriRow = $stmtOri->fetch();
                                        if ($oriRow) $pcOrientacao = $oriRow['orientacao'] ?? 'horizontal';
                                    }
                                ?>
                                <div class="seccao-block" data-seccao-idx="<?= $i ?>" data-tipo="parametros" data-tipo-id="<?= (int)$pcTipoId ?>" data-tipo-slug="<?= sanitize($pcTipoSlug) ?>" data-nivel="<?= (int)($sec['nivel'] ?? 1) ?>" data-orientacao="<?= $pcOrientacao ?>">
                                    <div class="seccao-header">
                                        <span class="seccao-numero"><?= $hierNumbers[$i] ?? ($i + 1) . '.' ?></span>
                                        <input type="text" class="seccao-titulo" value="<?= sanitize($sec['titulo'] ?? $pcTipoNome) ?>" placeholder="Título da secção">
                                        <span class="pill pill-info" style="font-size:10px; padding:2px 8px;"><?= sanitize($pcTipoNome) ?></span>
                                        <div class="seccao-actions">
                                            <button class="btn btn-ghost btn-sm seccao-collapse-btn" onclick="toggleCollapse(this)" title="Colapsar/Expandir">&minus;</button>
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>
                                            <button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>
                                        </div>
                                    </div>
                                    <div class="seccao-ensaios-wrap">
                                        <?php $pcMerges = $pcRaw['merges'] ?? []; ?>
                                        <table class="seccao-ensaios-table" data-param-tipo-id="<?= (int)$pcTipoId ?>" data-merges="<?= sanitize(json_encode($pcMerges)) ?>">
                                            <thead><tr>
                                                <?php foreach ($pcColunas as $ci => $pcCol): ?>
                                                <th style="width:<?= isset($pcColW[$ci]) ? $pcColW[$ci] : 15 ?>%" data-chave="<?= sanitize($pcCol['chave']) ?>"><?= sanitize($pcCol['nome']) ?></th>
                                                <?php endforeach; ?>
                                                <th style="width:4%"></th>
                                            </tr></thead>
                                            <tbody class="ensaios-tbody">
                                                <?php foreach ($pcRows as $pcRow): ?>
                                                    <?php if (isset($pcRow['_cat'])): ?>
                                                    <tr class="cat-header-row" data-cat="1">
                                                        <td colspan="<?= count($pcColunas) + 1 ?>" style="background:var(--color-primary-lighter, #e6f4f9); padding:4px 8px; font-weight:600; font-size:12px; color:var(--color-primary, #2596be);">
                                                            <input type="text" class="cat-header-input" value="<?= sanitize($pcRow['_cat']) ?>" style="border:none; background:transparent; font-weight:600; color:var(--color-primary, #2596be); width:calc(100% - 30px); font-size:12px;">
                                                            <button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover" style="float:right;">&times;</button>
                                                        </td>
                                                    </tr>
                                                    <?php else: ?>
                                                    <tr>
                                                        <?php foreach ($pcColunas as $pcCol): ?>
                                                        <?php $pcVal = $pcRow[$pcCol['chave']] ?? ''; $pcRowCount = max(1, substr_count($pcVal, "\n") + 1); ?>
                                                        <td><textarea rows="<?= $pcRowCount ?>" data-field="<?= sanitize($pcCol['chave']) ?>"><?= sanitize($pcVal) ?></textarea></td>
                                                        <?php endforeach; ?>
                                                        <td><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php
                                            $specLeg = $espec['legenda_parametros'] ?? '';
                                            $specLegTam = (int)($espec['legenda_parametros_tamanho'] ?? 0);
                                            $legText = $specLeg !== '' ? $specLeg : $pcLegenda;
                                            $legSize = $specLegTam > 0 ? $specLegTam : $pcLegTam;
                                        ?>
                                        <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                                            <label style="font-size:11px; color:#888; white-space:nowrap;">Legenda:</label>
                                            <input type="text" class="param-legenda-text" value="<?= sanitize($legText) ?>" placeholder="Texto da legenda (opcional)" style="flex:1; font-size:12px; font-style:italic; padding:3px 6px; border:1px solid var(--color-border); border-radius:4px;">
                                            <label style="font-size:11px; color:#888; white-space:nowrap;">Tam:</label>
                                            <input type="number" class="param-legenda-tam" value="<?= $legSize ?>" min="6" max="14" style="width:55px; font-size:12px; padding:3px 4px; border:1px solid var(--color-border); border-radius:4px;">
                                        </div>
                                        <div class="seccao-ensaios-actions">
                                            <button class="btn btn-secondary btn-sm" onclick="adicionarParamCatLinha(this, <?= (int)$pcTipoId ?>)">+ Categoria</button>
                                            <button class="btn btn-secondary btn-sm" onclick="adicionarParamCustomLinha(this, <?= (int)$pcTipoId ?>)">+ Linha</button>
                                            <button class="btn btn-secondary btn-sm" onclick="abrirBancoParamCustom(this, <?= (int)$pcTipoId ?>)">+ Do Banco</button>
                                            <span class="muted" style="font-size:10px; margin-left:auto;">&#8984;/Ctrl+Clique para juntar células</span>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if (empty($espec['seccoes'])): ?>
                            <div class="empty-state" id="seccoesEmpty" style="padding: var(--spacing-xl);">
                                <div class="icon">&#128196;</div>
                                <h3>Sem secções definidas</h3>
                                <p class="muted">Adicione secções usando os botões abaixo.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Pedidos ao fornecedor -->
                        <?php if (!empty($pedidosEspec)): ?>
                        <div id="pedidos-container" style="margin-top:16px;">
                            <h4 style="font-size:13px; color:#667085; margin-bottom:8px;">Pedidos ao Fornecedor</h4>
                            <?php foreach ($pedidosEspec as $ped): ?>
                            <div class="card pedido-block" data-pedido-id="<?= $ped['id'] ?>" style="padding:12px; margin-bottom:8px; border-left:3px solid #f59e0b;">
                                <div style="display:flex; justify-content:space-between; align-items:start; gap:8px;">
                                    <div style="flex:1;">
                                        <input type="text" class="pedido-titulo" value="<?= sanitize($ped['titulo']) ?>" placeholder="Título do pedido" style="font-weight:600; width:100%; border:1px solid #e5e7eb; border-radius:4px; padding:4px 8px; font-size:13px;">
                                        <textarea class="pedido-descricao" placeholder="Descrição (o que pedir ao fornecedor)" rows="2" style="width:100%; margin-top:4px; border:1px solid #e5e7eb; border-radius:4px; padding:4px 8px; font-size:12px; resize:vertical;"><?= sanitize($ped['descricao']) ?></textarea>
                                        <label style="display:flex; align-items:center; gap:4px; font-size:11px; color:#667085; margin-top:4px;">
                                            <input type="checkbox" class="pedido-obrigatorio" <?= $ped['obrigatorio'] ? 'checked' : '' ?>> Obrigatório para aceitar
                                        </label>
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:4px;">
                                        <button class="btn btn-ghost btn-sm" onclick="guardarPedido(<?= $ped['id'] ?>, this)" title="Guardar">&#128190;</button>
                                        <button class="btn btn-ghost btn-sm" onclick="removerPedido(<?= $ped['id'] ?>, this)" title="Remover" style="color:#ef4444;">&#128465;</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div id="pedidos-container" style="margin-top:16px;"></div>
                        <?php endif; ?>

                        <!-- Barra fixa de ações -->
                        <div class="content-actions-bar" id="seccoesBar">
                            <button class="btn btn-primary btn-sm btn-seccao" data-seccao="texto" onclick="pedirNivelSeccao('texto')">&#128196; + Texto</button>
                            <button class="btn btn-secondary btn-sm btn-seccao" data-seccao="parametros" onclick="pedirNivelSeccao('parametros')">&#9881; + Parâmetros</button>
                            <button class="btn btn-secondary btn-sm btn-seccao" data-seccao="legislacao" onclick="pedirNivelSeccao('legislacao')">&#9878; + Legislação</button>
                            <button class="btn btn-secondary btn-sm btn-seccao" data-seccao="ficheiros" onclick="pedirNivelSeccao('ficheiros')">&#128206; + Ficheiros</button>
                            <button class="btn btn-secondary btn-sm btn-seccao" data-seccao="pedido" onclick="adicionarPedido()" style="border-color:#f59e0b; color:#92400e;">&#128230; + Pedido</button>
                        </div>
                    </div>
                </div>


                <!-- TAB: PARTILHA -->
                <div class="tab-panel" id="panel-partilha">

                    <?php if (!$isNew && count($versoesGrupo) > 1): ?>
                    <!-- HISTÓRICO DE VERSÕES -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Histórico de Versões</span>
                            <span class="muted"><?= count($versoesGrupo) ?> versões</span>
                        </div>
                        <table class="table" style="font-size:13px;">
                            <thead>
                                <tr><th>Versão</th><th>Estado</th><th>Publicado por</th><th>Data</th><th>Aceitação</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($versoesGrupo as $v): ?>
                                <tr<?= $v['id'] == $espec['id'] ? ' style="background:var(--color-bg-alt);font-weight:600;"' : '' ?>>
                                    <td>v<?= sanitize($v['versao']) ?></td>
                                    <td>
                                        <?php if ($v['versao_bloqueada']): ?>
                                            <span class="pill pill-success" style="font-size:11px;">Publicada</span>
                                        <?php else: ?>
                                            <span class="pill pill-warning" style="font-size:11px;"><?= ucfirst(sanitize($v['estado'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= sanitize($v['publicado_por_nome'] ?? '-') ?></td>
                                    <td><?= $v['publicado_em'] ? date('d/m/Y H:i', strtotime($v['publicado_em'])) : '-' ?></td>
                                    <td>
                                        <?php if ($v['total_aceites'] || $v['total_rejeicoes']): ?>
                                            <span style="color:var(--color-success);"><?= (int)$v['total_aceites'] ?> aceites</span>
                                            <?php if ($v['total_rejeicoes']): ?><span style="color:var(--color-danger);"> / <?= (int)$v['total_rejeicoes'] ?> rej.</span><?php endif; ?>
                                        <?php elseif ($v['total_tokens']): ?>
                                            <span class="muted"><?= (int)$v['total_tokens'] ?> pendentes</span>
                                        <?php else: ?>
                                            <span class="muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($v['id'] != $espec['id']): ?>
                                        <a href="<?= BASE_PATH ?>/especificacao.php?id=<?= $v['id'] ?>" class="btn btn-ghost btn-sm">Abrir</a>
                                        <button class="btn btn-outline-primary btn-sm" onclick="compararVersoes(<?= $v['id'] ?>, '<?= sanitize($v['versao']) ?>')" title="Comparar com versão atual">Comparar</button>
                                        <?php else: ?>
                                        <span class="muted">Atual</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if (!$isNew && $versaoBloqueada): ?>
                    <!-- ACEITAÇÃO FORMAL -->
                    <div class="card" style="border-left:3px solid var(--color-primary);">
                        <div class="card-header">
                            <span class="card-title">Enviar para Aceitação</span>
                            <span class="muted">Cada pessoa recebe um link pessoal para ver, aceitar ou rejeitar o documento</span>
                        </div>
                        <!-- FORNECEDORES ASSOCIADOS -->
                        <?php
                        $tokensPendentes = array_filter($tokensEspec, function($t) { return empty($t['tipo_decisao']); });
                        $fornecedoresLista = $espec['fornecedores_lista'] ?? [];
                        if (!empty($fornecedoresLista)):
                            $emailsPendentes = array_map(function($t) { return strtolower($t['destinatario_email']); }, $tokensPendentes);
                        ?>
                        <div style="margin-bottom:var(--spacing-md); border-bottom:1px solid var(--color-border); padding-bottom:var(--spacing-md);">
                            <label style="font-weight:600; font-size:13px; margin-bottom:8px; display:block;">Fornecedores desta especificação</label>
                            <?php foreach ($fornecedoresLista as $f):
                                $fEmail = trim($f['email'] ?? '');
                                $jaEnviado = $fEmail && in_array(strtolower($fEmail), $emailsPendentes);
                            ?>
                            <div style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
                                <span style="min-width:140px; font-size:13px; font-weight:500;"><?= sanitize($f['nome']) ?></span>
                                <?php if ($fEmail): ?>
                                    <span style="flex:1; font-size:13px; color:#666;"><?= sanitize($fEmail) ?></span>
                                    <?php if ($jaEnviado): ?>
                                        <span class="muted" style="font-size:11px;">Pendente</span>
                                    <?php else: ?>
                                        <button class="btn btn-primary btn-sm" onclick="enviarParaFornecedor(<?= htmlspecialchars(json_encode($f['nome'])) ?>, <?= htmlspecialchars(json_encode($fEmail)) ?>)">Enviar</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <input type="email" id="forn_email_<?= $f['id'] ?>" placeholder="email@exemplo.com" style="flex:1; font-size:13px;" oninput="toggleFornBtn(<?= $f['id'] ?>)">
                                    <button class="btn btn-ghost btn-sm" disabled id="forn_btn_<?= $f['id'] ?>" onclick="enviarParaFornecedor(<?= htmlspecialchars(json_encode($f['nome'])) ?>, document.getElementById('forn_email_<?= $f['id'] ?>').value)">Falta email</button>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- ADICIONAR OUTRO DESTINATÁRIO -->
                        <div style="display:flex; gap:var(--spacing-sm); align-items:end; flex-wrap:wrap; margin-bottom:var(--spacing-md);">
                            <div class="form-group" style="flex:1; min-width:150px; margin:0;">
                                <label for="dest_nome">Nome</label>
                                <input type="text" id="dest_nome" placeholder="Nome do destinatário">
                            </div>
                            <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                                <label for="dest_email">Email</label>
                                <input type="email" id="dest_email" placeholder="email@exemplo.com">
                            </div>
                            <div class="form-group" style="width:140px; margin:0;">
                                <label for="dest_tipo">Tipo</label>
                                <select id="dest_tipo">
                                    <?php if ($temClientes): ?><option value="cliente">Cliente</option><?php endif; ?>
                                    <?php if ($temFornecedores): ?><option value="fornecedor">Fornecedor</option><?php endif; ?>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                            <button class="btn btn-primary btn-sm" onclick="adicionarDestinatario()">Adicionar</button>
                        </div>

                        <div id="listaDestinatarios">
                        <?php if (empty($tokensPendentes)): ?>
                            <p class="muted">Nenhum destinatário pendente.</p>
                        <?php else: ?>
                            <table class="table" style="font-size:13px;">
                                <thead>
                                    <tr><th>Nome</th><th>Email</th><th>Tipo</th><th>Acessos</th><th></th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($tokensPendentes as $tk): ?>
                                    <tr>
                                        <td><?= sanitize($tk['destinatario_nome'] ?? '-') ?></td>
                                        <td><?= sanitize($tk['destinatario_email'] ?? '-') ?></td>
                                        <td><?= ucfirst(sanitize($tk['tipo_destinatario'])) ?></td>
                                        <td><?= (int)$tk['total_acessos'] ?></td>
                                        <td style="display:flex; gap:4px; align-items:center; flex-wrap:nowrap;">
                                            <?php if (empty($tk['enviado_em'])): ?>
                                            <button class="btn btn-primary btn-sm" onclick="enviarLinkToken(<?= $tk['id'] ?>)" title="Enviar email">Enviar</button>
                                            <?php else: ?>
                                            <span class="muted" style="font-size:11px;" title="Enviado em <?= date('d/m/Y H:i', strtotime($tk['enviado_em'])) ?>">Enviado</span>
                                            <?php endif; ?>
                                            <button class="btn btn-ghost btn-sm" onclick="copiarLinkToken('<?= sanitize($tk['token']) ?>')" title="Copiar link">Copiar</button>
                                            <a href="<?= BASE_PATH ?>/publico.php?token=<?= urlencode($tk['token']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Ver como fornecedor">&#128065;</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        </div>

                    </div>
                    <?php endif; ?>

                    <!-- PARTILHA RÁPIDA -->
                    <?php if (!$isNew): ?>
                    <?php
                        // Verificar se esta org tem email configurado
                        $orgSmtp = $db->prepare('SELECT email_speclab, email_speclab_pass, smtp_host, smtp_user, usar_smtp_speclab FROM organizacoes WHERE id = ?');
                        $orgSmtp->execute([$orgId]);
                        $orgSmtpData = $orgSmtp->fetch();
                        $orgTemEmail = false;
                        if ($orgSmtpData) {
                            // Tem SMTP próprio OU email speclab com password
                            $orgTemEmail = (!$orgSmtpData['usar_smtp_speclab'] && !empty($orgSmtpData['smtp_host']) && !empty($orgSmtpData['smtp_user']))
                                || (!empty($orgSmtpData['email_speclab']) && !empty($orgSmtpData['email_speclab_pass']));
                        }
                        $isSuperAdmin = ($user['role'] === 'super_admin');
                        $smtpConfigurado = $isSuperAdmin ? (!empty(getConfiguracao('smtp_host')) && !empty(getConfiguracao('smtp_user'))) : $orgTemEmail;
                        $emailsForn = [];
                        foreach (($espec['fornecedores_lista'] ?? []) as $f) {
                            if (!empty($f['email'])) {
                                foreach (array_map('trim', explode(',', $f['email'])) as $em) {
                                    if ($em) $emailsForn[] = $em;
                                }
                            }
                        }
                        $emailsCli = [];
                        if (!empty($espec['cliente_email'])) {
                            foreach (array_map('trim', explode(',', $espec['cliente_email'])) as $em) {
                                if ($em) $emailsCli[] = $em;
                            }
                        }
                        $nForn = count($espec['fornecedores_lista'] ?? []);
                        $nCli = !empty($espec['cliente_id']) ? 1 : 0;
                    ?>
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Partilha Rápida</span>
                            <span class="muted">Enviar documento por email ou gerar link público</span>
                        </div>

                        <!-- Link Interno (colegas da org) -->
                        <div style="margin-bottom:var(--spacing-lg);">
                            <div class="section-label" style="margin-bottom:var(--spacing-xs);">Link Interno</div>
                            <p class="muted" style="font-size:12px; margin:0 0 var(--spacing-sm);">Partilhar com colegas da sua organização (requer login).</p>
                            <div class="flex gap-sm" style="align-items:center;">
                                <input type="text" id="linkInterno" value="<?= rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/') . BASE_PATH ?>/especificacao.php?id=<?= $espec['id'] ?>" readonly style="font-family:monospace;font-size:12px;flex:1;background:var(--color-bg);">
                                <button class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('linkInterno').value).then(function(){showToast('Link copiado!','success')})">Copiar</button>
                            </div>
                        </div>

                        <!-- Enviar por Email -->
                        <div style="margin-bottom:var(--spacing-lg);">
                            <div class="section-label" style="margin-bottom:var(--spacing-sm);">Enviar por Email</div>
                            <div class="form-group">
                                <label for="email_destinatario">Destinatário</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="text" id="email_destinatario" placeholder="email@exemplo.com" style="flex:1;">
                                    <button type="button" id="btnLimparDest" class="btn btn-sm btn-ghost" onclick="limparDestinatarios()" style="display:none;" title="Limpar">&times;</button>
                                </div>
                                <?php if ($emailsForn || $emailsCli): ?>
                                <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:4px;">
                                    <?php if ($emailsForn): ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="preencherDestinatarios('fornecedores')">
                                        <?= $nForn === 1 ? sanitize($espec['fornecedores_lista'][0]['nome']) : 'Fornecedores ('.$nForn.')' ?>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($emailsCli): ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="preencherDestinatarios('cliente')">
                                        <?= sanitize($espec['cliente_nome']) ?>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($emailsForn && $emailsCli): ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="preencherDestinatarios('todos')">Todos</button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="email_assunto">Assunto</label>
                                <input type="text" id="email_assunto" value="Caderno de Encargos: <?= sanitize($espec['numero']) ?> - <?= sanitize($espec['titulo']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="email_mensagem">Mensagem personalizada (opcional)</label>
                                <textarea id="email_mensagem" rows="3" placeholder="Adicionar mensagem ao email..."></textarea>
                            </div>
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="email_incluir_link" checked> Incluir link de visualização online
                                </label>
                            </div>
                            <div style="display:flex; align-items:center; gap: var(--spacing-sm); flex-wrap:wrap;">
                                <button class="btn btn-primary" onclick="abrirEmailCliente()" id="btnAbrirEmail">Abrir no Email</button>
                                <?php if ($smtpConfigurado): ?>
                                <button class="btn btn-secondary" onclick="enviarEmailEspec()" id="btnEnviarEmail">Enviar via Servidor</button>
                                <?php endif; ?>
                                <span class="muted" id="emailStatus"></span>
                            </div>
                            <?php if (!$smtpConfigurado): ?>
                            <div class="alert alert-info" style="margin-top: var(--spacing-sm);">
                                <?php if (!$isSuperAdmin): ?>
                                    Para enviar emails pelo servidor, configure o email da sua organização em <a href="<?= BASE_PATH ?>/admin.php?tab=configuracoes" style="font-weight:600; text-decoration:underline;">Admin → Configurações</a>.
                                <?php else: ?>
                                    Para enviar diretamente pelo servidor, configure o SMTP nas <a href="<?= BASE_PATH ?>/admin.php?tab=configuracoes" style="font-weight:600; text-decoration:underline;">Configurações</a>.
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Link de Consulta -->
                        <div style="border-top:1px solid var(--color-border); padding-top:var(--spacing-md);">
                            <div class="section-label" style="margin-bottom:var(--spacing-xs);">Link Público (só leitura)</div>
                            <p class="muted" style="font-size:12px; margin:0 0 var(--spacing-sm);">Qualquer pessoa com este link pode ver o documento. Sem registo de quem acedeu.</p>
                            <div class="form-group">
                                <label>Código de Acesso</label>
                                <div class="flex gap-sm" style="align-items: center;">
                                    <input type="text" id="codigo_acesso" name="codigo_acesso" value="<?= sanitize($espec['codigo_acesso'] ?? '') ?>" readonly style="background: var(--color-bg); font-family: monospace; flex:1; max-width:300px;">
                                    <?php if (!$versaoBloqueada): ?>
                                    <button class="btn btn-secondary btn-sm" onclick="gerarCodigoAcesso()">Gerar Código</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <input type="hidden" id="senha_publica" name="senha_publica" value="">
                            <?php if (!empty($espec['codigo_acesso'])): ?>
                                <div class="form-group">
                                    <label>Link de Partilha</label>
                                    <div class="share-link">
                                        <input type="text" id="shareLink" value="" readonly>
                                        <button class="btn btn-primary btn-sm" onclick="copiarLink()">Copiar</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    Clique em "Gerar Código" para criar um link público de consulta.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- TAB: HISTÓRICO DE ACEITAÇÕES -->
                <div class="tab-panel" id="panel-historico">
                    <?php if (!$isNew): ?>
                    <?php
                    $tokensComDecisao = array_filter($tokensEspec, function($t) { return !empty($t['tipo_decisao']); });
                    $tiposHist = array_unique(array_map(function($t) { return $t['tipo_destinatario']; }, $tokensComDecisao));
                    ?>
                    <div class="card">
                        <div class="card-header" style="flex-wrap:wrap; gap:8px;">
                            <div>
                                <span class="card-title">Histórico de Aceitações</span>
                                <span class="muted" style="display:block; font-size:12px;">Registo permanente — não é apagado ao revogar acesso</span>
                            </div>
                            <?php if (count($tiposHist) > 1): ?>
                            <select id="filtroHistTipo" onchange="filtrarHistorico()" style="font-size:12px; padding:4px 8px; border-radius:4px; border:1px solid var(--color-border);">
                                <option value="">Todos os tipos</option>
                                <?php foreach ($tiposHist as $th): ?>
                                <option value="<?= sanitize($th) ?>"><?= ucfirst(sanitize($th)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($tokensComDecisao)): ?>
                            <p class="muted">Nenhuma decisão registada.</p>
                        <?php else: ?>
                            <table class="table" style="font-size:13px;" id="tabelaHistorico">
                                <thead>
                                    <tr><th>Nome</th><th>Email</th><th>Tipo</th><th>Decisão</th><th>Data</th><th>Comentário</th><th></th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($tokensComDecisao as $tk): ?>
                                    <tr data-tipo="<?= sanitize($tk['tipo_destinatario']) ?>">
                                        <td><?= sanitize($tk['destinatario_nome'] ?? '-') ?></td>
                                        <td><?= sanitize($tk['destinatario_email'] ?? '-') ?></td>
                                        <td><?= ucfirst(sanitize($tk['tipo_destinatario'])) ?></td>
                                        <td>
                                            <?php if ($tk['tipo_decisao'] === 'aceite'): ?>
                                                <span class="pill pill-success" style="font-size:11px;">Aceite</span>
                                            <?php else: ?>
                                                <span class="pill pill-danger" style="font-size:11px;">Rejeitado</span>
                                            <?php endif; ?>
                                            <div style="font-size:11px; color:#666;">por <?= sanitize($tk['nome_signatario']) ?><?= $tk['cargo_signatario'] ? ' (' . sanitize($tk['cargo_signatario']) . ')' : '' ?></div>
                                        </td>
                                        <td style="white-space:nowrap; font-size:12px;"><?= date('d/m/Y H:i', strtotime($tk['decisao_em'])) ?></td>
                                        <td>
                                            <?php if (!empty($tk['decisao_comentario'])): ?>
                                                <a href="#" onclick="verMotivoRejeicao(this)" data-nome="<?= sanitize($tk['nome_signatario']) ?>" data-data="<?= date('d/m/Y H:i', strtotime($tk['decisao_em'])) ?>" data-motivo="<?= sanitize($tk['decisao_comentario']) ?>" style="color:var(--color-primary); text-decoration:underline;">Ver</a>
                                            <?php else: ?>
                                                <span class="muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="display:flex; gap:4px; align-items:center; flex-wrap:nowrap;">
                                            <a href="<?= BASE_PATH ?>/publico.php?token=<?= urlencode($tk['token']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Ver como fornecedor">&#128065;</a>
                                            <a href="comprovativo.php?token_id=<?= $tk['id'] ?>" target="_blank" class="btn btn-ghost btn-sm" title="Imprimir comprovativo" style="font-size:11px;">Comprovativo</a>
                                            <button class="btn btn-danger btn-sm" onclick="revogarToken(<?= $tk['id'] ?>)" title="Revogar acesso">&times;</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <?php if ($resumoAceitacao['total_tokens'] > 0): ?>
                        <div style="margin-top:var(--spacing-md); padding:var(--spacing-sm); background:var(--color-bg-alt); border-radius:var(--radius); font-size:13px;">
                            <strong>Resumo:</strong>
                            <?= (int)$resumoAceitacao['aceites'] ?> aceites,
                            <?= (int)$resumoAceitacao['rejeicoes'] ?> rejeições,
                            <?= (int)$resumoAceitacao['pendentes'] ?> pendentes
                            de <?= (int)$resumoAceitacao['total_tokens'] ?> destinatários
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                        <p class="muted">Guarde a especificação para ver o histórico.</p>
                    <?php endif; ?>
                </div>

                <!-- TAB: CONFIGURAÇÕES VISUAIS -->
                <div class="tab-panel" id="panel-configuracoes">
                    <?php if (!$isNew): ?>
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Informações</span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Criado por</label>
                                <input type="text" value="<?= sanitize($espec['criado_por_nome'] ?? '-') ?>" readonly style="background: var(--color-bg);">
                            </div>
                            <div class="form-group">
                                <label>Criado em</label>
                                <input type="text" value="<?= formatDate($espec['created_at'] ?? '') ?>" readonly style="background: var(--color-bg);">
                            </div>
                            <div class="form-group">
                                <label>Última atualização</label>
                                <input type="text" value="<?= formatDate($espec['updated_at'] ?? '') ?>" readonly style="background: var(--color-bg);">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Configurações Visuais do Documento</span>
                            <span class="muted">Personalizar a aparência do PDF e pré-visualização</span>
                        </div>

                        <!-- Nome do Documento -->
                        <div class="section-label" style="margin-bottom:var(--spacing-sm);">Nome do Documento (Título Principal)</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cfg_cor_nome">Cor</label>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="color" id="cfg_cor_nome" value="<?= sanitize($configVisual['cor_nome']) ?>" style="width:40px; height:32px; padding:2px; border:1px solid var(--color-border); border-radius:4px; cursor:pointer;">
                                    <input type="text" id="cfg_cor_nome_hex" value="<?= sanitize($configVisual['cor_nome']) ?>" style="width:80px; font-family:monospace; font-size:12px;" maxlength="7">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="cfg_tamanho_nome">Tamanho</label>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="range" id="cfg_tamanho_nome" min="8" max="28" value="<?= (int)$configVisual['tamanho_nome'] ?>" style="flex:1;">
                                    <span id="cfg_tamanho_nome_val" style="font-weight:600; min-width:36px; font-size:12px;"><?= (int)$configVisual['tamanho_nome'] ?>pt</span>
                                </div>
                            </div>
                        </div>

                        <!-- Títulos das Secções -->
                        <div class="section-label" style="margin-top:var(--spacing-md); margin-bottom:var(--spacing-sm);">Títulos das Secções</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cfg_cor_titulos">Cor</label>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="color" id="cfg_cor_titulos" value="<?= sanitize($configVisual['cor_titulos']) ?>" style="width:40px; height:32px; padding:2px; border:1px solid var(--color-border); border-radius:4px; cursor:pointer;">
                                    <input type="text" id="cfg_cor_titulos_hex" value="<?= sanitize($configVisual['cor_titulos']) ?>" style="width:80px; font-family:monospace; font-size:12px;" maxlength="7">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="cfg_tamanho_titulos">Tamanho</label>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="range" id="cfg_tamanho_titulos" min="8" max="28" value="<?= (int)$configVisual['tamanho_titulos'] ?>" style="flex:1;">
                                    <span id="cfg_tamanho_titulos_val" style="font-weight:600; min-width:36px; font-size:12px;"><?= (int)$configVisual['tamanho_titulos'] ?>pt</span>
                                </div>
                            </div>
                        </div>

                        <!-- Subtítulos (secções secundárias) -->
                        <div class="section-label" style="margin-top:var(--spacing-md); margin-bottom:var(--spacing-sm);">Subtítulos (Secções Secundárias)</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cfg_cor_subtitulos">Cor</label>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="color" id="cfg_cor_subtitulos" value="<?= sanitize($configVisual['cor_subtitulos']) ?>" style="width:40px; height:32px; padding:2px; border:1px solid var(--color-border); border-radius:4px; cursor:pointer;">
                                    <input type="text" id="cfg_cor_subtitulos_hex" value="<?= sanitize($configVisual['cor_subtitulos']) ?>" style="width:80px; font-family:monospace; font-size:12px;" maxlength="7">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="cfg_tamanho_subtitulos">Tamanho</label>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="range" id="cfg_tamanho_subtitulos" min="8" max="28" value="<?= (int)$configVisual['tamanho_subtitulos'] ?>" style="flex:1;">
                                    <span id="cfg_tamanho_subtitulos_val" style="font-weight:600; min-width:36px; font-size:12px;"><?= (int)$configVisual['tamanho_subtitulos'] ?>pt</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label style="margin-bottom:4px;">Estilo</label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:13px;">
                                    <input type="checkbox" id="cfg_subtitulos_bold" <?= ($configVisual['subtitulos_bold'] ?? '1') === '1' ? 'checked' : '' ?> style="width:16px; height:16px;">
                                    Negrito
                                </label>
                            </div>
                        </div>

                        <!-- Linhas / Separadores -->
                        <div class="section-label" style="margin-top:var(--spacing-md); margin-bottom:var(--spacing-sm);">Linhas e Separadores</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cfg_cor_linhas">Cor</label>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="color" id="cfg_cor_linhas" value="<?= sanitize($configVisual['cor_linhas']) ?>" style="width:40px; height:32px; padding:2px; border:1px solid var(--color-border); border-radius:4px; cursor:pointer;">
                                    <input type="text" id="cfg_cor_linhas_hex" value="<?= sanitize($configVisual['cor_linhas']) ?>" style="width:80px; font-family:monospace; font-size:12px;" maxlength="7">
                                </div>
                            </div>
                        </div>

                        <!-- Preview ao vivo -->
                        <div style="margin-top: var(--spacing-lg); padding: var(--spacing-md); border: 1px solid var(--color-border); border-radius: var(--border-radius-sm); background: white;">
                            <div class="muted" style="font-size:10px; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Pré-visualização</div>
                            <div id="cfgPreviewNome" style="font-weight:700; color:<?= sanitize($configVisual['cor_nome']) ?>; font-size:<?= (int)$configVisual['tamanho_nome'] ?>pt; margin-bottom:12px;">
                                <?= ($espec['tipo_doc'] ?? 'caderno') === 'ficha_tecnica' ? 'Ficha Técnica' : 'Caderno de Encargos' ?>
                            </div>
                            <div id="cfgPreviewTitle" style="font-weight:700; color:<?= sanitize($configVisual['cor_titulos']) ?>; font-size:<?= (int)$configVisual['tamanho_titulos'] ?>pt; padding-bottom:6px; border-bottom:2px solid <?= sanitize($configVisual['cor_linhas']) ?>; margin-bottom:8px;">
                                1. Exemplo de Título de Secção
                            </div>
                            <div id="cfgPreviewSubtitle" style="font-weight:700; color:<?= sanitize($configVisual['cor_subtitulos']) ?>; font-size:<?= (int)$configVisual['tamanho_subtitulos'] ?>pt; padding-bottom:4px; border-bottom:1px solid <?= sanitize($configVisual['cor_linhas']) ?>; margin-bottom:8px; margin-left:15px;">
                                1.1. Exemplo de Subtítulo
                            </div>
                            <p style="font-size: var(--font-size-sm); color: var(--color-text-secondary); margin:0;">
                                Texto de exemplo do conteúdo da secção...
                            </p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Logotipo Personalizado</span>
                            <span class="muted">Substituir o logo por outro (opcional)</span>
                        </div>

                        <div class="form-group">
                            <div style="display:flex; align-items:center; gap: var(--spacing-md);">
                                <div id="cfgLogoPreview" style="width:120px; height:60px; border:1px dashed var(--color-border); border-radius:var(--border-radius-sm); display:flex; align-items:center; justify-content:center; overflow:hidden; background:white;">
                                    <?php if (!empty($configVisual['logo_custom'])): ?>
                                        <img src="<?= BASE_PATH ?>/uploads/logos/<?= sanitize($configVisual['logo_custom']) ?>" style="max-width:100%; max-height:100%;" alt="Logo">
                                    <?php else: ?>
                                        <span class="muted" style="font-size:11px;">Sem logo</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <input type="file" id="cfg_logo_file" accept="image/png,image/jpeg" style="font-size: var(--font-size-sm);">
                                    <p class="muted" style="margin-top:4px; font-size:11px;">PNG ou JPG. Tamanho recomendado: 300x150px</p>
                                    <?php if (!empty($configVisual['logo_custom'])): ?>
                                        <button class="btn btn-ghost btn-sm" onclick="removerLogoCustom()" style="color:var(--color-error); margin-top:4px;">Remover logo</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- SIDEBAR - PREVIEW -->
            <div class="sidebar no-print">
                <div class="preview-container">
                    <div class="preview-header">
                        <h3>Pré-visualização do Documento</h3>
                        <button class="btn btn-sm" style="background:rgba(255,255,255,0.2); color:white; border:none;" onclick="atualizarPreview()" title="Atualizar pré-visualização">&#8635;</button>
                    </div>
                    <div class="preview-body" id="previewBody">
                        <div class="preview-logo">
                            <img id="prevLogoImg" src="<?= $logoSrc ?>" alt="<?= sanitize($branding['nome']) ?>" onerror="this.style.display='none'">
                            <div>
                                <strong style="color: var(--color-primary);"><?= sanitize($branding['nome'] ?: 'SpecLab') ?></strong><br>
                                <span class="muted">Caderno de Encargos</span>
                            </div>
                        </div>

                        <div class="preview-meta" id="previewMeta">
                            <div><strong>Nº:</strong> <span id="prevNumero"><?= sanitize($espec['numero']) ?></span></div>
                            <div><strong>Versão:</strong> <span id="prevVersao"><?= sanitize($espec['versao']) ?></span></div>
                            <div class="meta-full"><strong>Produto:</strong> <span id="prevProduto"><?= sanitize($espec['produto_nome'] ?? '-') ?></span></div>
                            <?php if ($temFornecedores): ?>
                            <div class="meta-full"><strong>Fornecedor:</strong> <span id="prevFornecedor"><?= sanitize($espec['fornecedor_nome'] ?? '-') ?></span></div>
                            <?php endif; ?>
                            <?php if ($temClientes): ?>
                            <div class="meta-full"><strong>Cliente:</strong> <span id="prevCliente"><?= sanitize($espec['cliente_nome'] ?? '-') ?></span></div>
                            <?php endif; ?>
                            <div><strong>Data:</strong> <span id="prevData"><?= formatDate($espec['data_emissao'] ?? '') ?></span></div>
                            <div><strong>Estado:</strong> <span id="prevEstado"><?= ucfirst($espec['estado']) ?></span></div>
                        </div>

                        <div id="prevTitulo" style="font-weight: 600; text-align: center; margin-bottom: var(--spacing-md); color: <?= sanitize($configVisual['cor_nome']) ?>; font-size: <?= (int)$configVisual['tamanho_nome'] ?>pt;">
                            <?= sanitize($espec['titulo']) ?: 'Título da Especificação' ?>
                        </div>

                        <div id="previewSections">
                            <!-- Conteúdo dinâmico via JS -->
                        </div>

                        <div id="previewParams">
                            <!-- Tabela de parâmetros via JS -->
                        </div>

                        <div id="previewClasses">
                            <!-- Classes via JS -->
                        </div>

                        <div id="previewDefects">
                            <!-- Defeitos via JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BOTÃO FLUTUANTE MERGE -->
    <div class="merge-float-actions" id="mergeFloatActions">
        <button class="btn btn-primary btn-sm" onclick="executarMerge()">Juntar</button>
        <button class="btn btn-ghost btn-sm" onclick="cancelarMergeSelection()" style="padding:2px 6px;">&times;</button>
    </div>

    <!-- MODAL: ALTERAÇÕES POR GUARDAR -->
    <div class="modal-overlay hidden" id="unsavedModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal-box" style="max-width:420px;">
            <div class="modal-header">
                <h3>Alterações não guardadas</h3>
                <button class="modal-close" onclick="fecharUnsavedModal()">&times;</button>
            </div>
            <div style="padding:var(--spacing-lg);">
                <p style="margin:0;">Tem alterações por guardar. Se sair agora, as alterações serão perdidas.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="fecharUnsavedModal()">Ficar na página</button>
                <button class="btn btn-danger" onclick="confirmarSairSemGuardar()">Sair sem guardar</button>
            </div>
        </div>
    </div>

    <!-- MODAL: CONFIRMAR PUBLICAÇÃO -->
    <div class="modal-overlay hidden" id="publicarModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal-box" style="max-width:460px;">
            <div class="modal-header">
                <h3>Publicar Versão</h3>
                <button class="modal-close" onclick="document.getElementById('publicarModal').classList.add('hidden')">&times;</button>
            </div>
            <div style="padding:var(--spacing-lg);">
                <div class="alert alert-warning" style="margin-bottom:var(--spacing-md);">
                    <strong>Atenção:</strong> Ao publicar, esta versão fica bloqueada e não poderá ser editada. Para fazer alterações terá de criar uma nova versão.
                </div>
                <div class="form-group">
                    <label for="publicarNotas">Notas desta versão (opcional)</label>
                    <textarea id="publicarNotas" rows="2" placeholder="Ex: Versão final aprovada pelo cliente..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="document.getElementById('publicarModal').classList.add('hidden')">Cancelar</button>
                <button class="btn btn-primary" onclick="confirmarPublicar()">Confirmar Publicação</button>
            </div>
        </div>
    </div>

    <!-- MODAL: COMPARAÇÃO DE VERSÕES -->
    <div class="modal-overlay hidden" id="diffModal">
        <div class="modal-box" style="max-width:800px; max-height:80vh; overflow-y:auto;">
            <div class="modal-header">
                <h3 id="diffModalTitle">Comparação de Versões</h3>
                <button class="modal-close" onclick="document.getElementById('diffModal').classList.add('hidden')">&times;</button>
            </div>
            <div class="modal-body" id="diffModalBody" style="padding:var(--spacing-md);">
                <p class="muted">A carregar...</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="document.getElementById('diffModal').classList.add('hidden')">Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL: SELECTOR TIPO DE PARÂMETRO -->
    <div class="modal-overlay hidden" id="modalSelectorTipo">
        <div class="modal-box" style="max-width:400px;">
            <div class="modal-header">
                <h3>Tipo de Parâmetro</h3>
                <button class="modal-close" onclick="document.getElementById('modalSelectorTipo').classList.add('hidden');">&times;</button>
            </div>
            <p class="muted mb-md">Escolha o tipo de parâmetro a adicionar.</p>
            <div id="tipoSelectorList" style="display:flex; flex-direction:column; gap:8px; padding:0 8px 8px;">
                <div class="muted" style="text-align:center;">A carregar...</div>
            </div>
        </div>
    </div>

    <!-- MODAL: SELECTOR LEGISLAÇÃO -->
    <div class="modal-overlay hidden" id="modalSelectorLeg">
        <div class="modal-box modal-box-lg">
            <div class="modal-header">
                <h3>Selecionar Legislação</h3>
                <button class="modal-close" onclick="document.getElementById('modalSelectorLeg').classList.add('hidden');">&times;</button>
            </div>
            <p class="muted mb-md">Escolha a legislação para adicionar. Selecione da lista abaixo.</p>
            <div class="ensaios-selector-grid" id="legSelectorGrid" style="max-height:400px; overflow-y:auto;">
                <div class="muted" style="padding:var(--spacing-md); text-align:center;">A carregar...</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="document.getElementById('modalSelectorLeg').classList.add('hidden');">Cancelar</button>
                <button class="btn btn-primary" onclick="confirmarSelectorLeg()">Adicionar Selecionadas</button>
            </div>
        </div>
    </div>

    <!-- MODAL: NIVEL DA SECÇÃO -->
    <div class="modal-overlay hidden" id="nivelModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal-box" style="max-width:400px;">
            <div class="modal-header">
                <h3>Tipo de Secção</h3>
                <button class="modal-close" onclick="fecharNivelModal()">&times;</button>
            </div>
            <p style="text-align:center; margin:8px 0 0; color:var(--color-muted);">Escolha o nível hierárquico desta secção:</p>
            <div class="nivel-modal-btns">
                <button class="btn-nivel" onclick="confirmarNivel(1)">
                    <strong>Principal</strong>
                    <span>1. / 2. / 3.</span>
                </button>
                <button class="btn-nivel" onclick="confirmarNivel(2)">
                    <strong>Secundária</strong>
                    <span>1.1 / 1.2 / 2.1</span>
                </button>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button class="btn btn-secondary btn-sm" onclick="fecharNivelModal()">Cancelar</button>
            </div>
        </div>
    </div>

    <!-- MODAL: ALERTA / CONFIRMACAO -->
    <div class="modal-overlay hidden" id="modalAlert">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="alertTitle">Alerta</h3>
                <button class="modal-close" onclick="fecharModalAlert()">&times;</button>
            </div>
            <p id="alertMessage">Mensagem</p>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="fecharModalAlert()" id="alertCancelBtn" style="display:none;">Cancelar</button>
                <button class="btn btn-primary" onclick="fecharModalAlert()" id="alertOkBtn">OK</button>
            </div>
        </div>
    </div>

    <!-- MODAL: SELECIONAR ADMINS PARA REVISAO -->
    <div class="modal-overlay hidden" id="modalRevisao">
        <div class="modal-box" style="max-width:420px;">
            <div class="modal-header">
                <h3>Submeter para Revisão</h3>
                <button class="modal-close" onclick="document.getElementById('modalRevisao').classList.add('hidden')">&times;</button>
            </div>
            <p style="margin:0 0 12px; font-size:13px; color:#667085;">Selecione os administradores a notificar por email:</p>
            <div id="revisaoAdminList" style="margin-bottom:16px;">
                <?php if (empty($orgAdmins)): ?>
                    <p style="color:#ef4444; font-size:13px;">Não há administradores com email na organização.</p>
                <?php else: ?>
                    <?php foreach ($orgAdmins as $adm): ?>
                    <label style="display:flex; align-items:center; gap:8px; padding:6px 0; cursor:pointer; font-size:14px;">
                        <input type="checkbox" class="revisao-admin-cb" value="<?= $adm['id'] ?>" <?= $adm['email'] ? 'checked' : 'disabled' ?>>
                        <?= sanitize($adm['nome']) ?>
                        <?php if ($adm['email']): ?>
                            <span style="color:#667085; font-size:11px;">(<?= sanitize($adm['email']) ?>)</span>
                        <?php else: ?>
                            <span style="color:#ef4444; font-size:11px;">(sem email)</span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="document.getElementById('modalRevisao').classList.add('hidden')">Cancelar</button>
                <button class="btn btn-info" onclick="confirmarSubmissaoRevisao()" <?= empty($orgAdmins) ? 'disabled' : '' ?>>Submeter</button>
            </div>
        </div>
    </div>

    <!-- MODAL: IA ASSISTENTE -->
    <div class="ai-modal-overlay hidden" id="aiModal">
        <div class="ai-modal">
            <div class="ai-modal-header">
                <h3><span class="ai-badge">IA</span> <span id="aiModalTitle">Sugerir conteúdo</span></h3>
                <button class="modal-close" onclick="fecharAIModal()">&times;</button>
            </div>
            <div class="ai-modal-body">
                <label id="aiModalLabel">Descreva o que pretende que a IA gere para esta secção:</label>
                <textarea id="aiPromptInput" placeholder="Ex: Descreve o objetivo desta especificação para rolhas de cortiça natural..."></textarea>
            </div>
            <p style="font-size:11px;color:#6b7280;margin:0 0 8px;padding:0 20px">Conteúdo gerado por IA. Revise e valide antes de usar em documentos oficiais.</p>
            <div class="ai-modal-footer">
                <button class="btn btn-secondary btn-sm" onclick="fecharAIModal()">Cancelar</button>
                <button class="btn-ai-submit" id="aiSubmitBtn" onclick="executarAI()">Gerar</button>
            </div>
        </div>
    </div>

    <!-- TOAST CONTAINER -->
    <div id="toast-container" class="toast-container"></div>

    <script>
    // ============================================================
    // CONFIGURAÇÃO GLOBAL
    // ============================================================
    const BASE_PATH = '<?= BASE_PATH ?>';
    const CSRF_TOKEN = '<?= getCsrfToken() ?>';
    const IS_NEW = <?= $isNew ? 'true' : 'false' ?>;
    let especId = <?= $espec['id'] ?: 0 ?>;
    let autoSaveTimer = null;
    let isDirty = false;
    let isSaving = false;

    // Secções permitidas por tipo de documento
    const DOC_TIPOS_CONFIG = <?= json_encode($docTiposConfig) ?>;

    function atualizarSeccoesPermitidas() {
        var tipo = document.getElementById('tipo_doc').value;
        var permitidas = DOC_TIPOS_CONFIG[tipo] || ['texto','parametros','legislacao','ficheiros'];
        document.querySelectorAll('.btn-seccao').forEach(function(btn) {
            var seccao = btn.getAttribute('data-seccao');
            btn.style.display = permitidas.indexOf(seccao) !== -1 ? '' : 'none';
        });
    }

    // Helper para fetch POST com CSRF automático
    function apiPost(data) {
        return fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify(data)
        });
    }
    function apiPostForm(formData) {
        formData.append('csrf_token', CSRF_TOKEN);
        return fetch(BASE_PATH + '/api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: formData });
    }

    // Deteção de sessão expirada (401/403)
    function checkSession(response) {
        if (response.status === 401 || response.status === 403) {
            showToast('A sua sessão expirou. Vai ser redirecionado para o login.', 'warning');
            setTimeout(function() { window.location.href = BASE_PATH + '/index.php'; }, 2000);
            throw new Error('SESSION_EXPIRED');
        }
        if (!response.ok) {
            throw new Error('Erro do servidor (' + response.status + ')');
        }
        return response.json();
    }

    // Emails de fornecedores/cliente para pré-preenchimento
    var emailDataForn = <?= json_encode($emailsForn ?? []) ?>;
    var emailDataCli = <?= json_encode($emailsCli ?? []) ?>;
    var emailUsaBcc = false;

    function preencherDestinatarios(tipo) {
        var emails = [];
        if (tipo === 'fornecedores' || tipo === 'todos') emails = emails.concat(emailDataForn);
        if (tipo === 'cliente' || tipo === 'todos') emails = emails.concat(emailDataCli);
        emailUsaBcc = emails.length > 1;
        var campo = document.getElementById('email_destinatario');
        campo.value = emailUsaBcc ? 'Destinatários ocultos (' + emails.length + ')' : emails.join(', ');
        campo.readOnly = emailUsaBcc;
        var btnLimpar = document.getElementById('btnLimparDest');
        if (btnLimpar) btnLimpar.style.display = emailUsaBcc ? 'inline' : 'none';
    }
    function limparDestinatarios() {
        emailUsaBcc = false;
        var campo = document.getElementById('email_destinatario');
        campo.value = '';
        campo.readOnly = false;
        campo.focus();
        var btnLimpar = document.getElementById('btnLimparDest');
        if (btnLimpar) btnLimpar.style.display = 'none';
    }

    // ============================================================
    // RICH TEXT EDITOR (TinyMCE) - DINÂMICO PARA SECÇÕES
    // ============================================================
    let tinyEditors = {};
    let seccaoCounter = <?= count($espec['seccoes']) ?>;

    function getTinyConfig(selector) {
        return {
            selector: selector,
            height: 250,
            menubar: false,
            language: 'pt_PT',
            language_url: '',
            branding: false,
            promotion: false,
            statusbar: false,
            init_instance_callback: function(editor) {
                editor.setDirty(false);
                editor.on('dirty', function() { editor.setDirty(false); });
            },
            plugins: 'lists link table code wordcount paste lineheight',
            toolbar: 'fontsize | bold italic underline | forecolor | alignleft aligncenter alignright | bullist numlist | table link',
            toolbar_mode: 'floating',
            font_family_formats: 'Arial=arial,helvetica,sans-serif; Calibri=calibri,sans-serif; Georgia=georgia,serif; Helvetica=helvetica; Roboto=roboto,sans-serif; Segoe UI=segoe ui; Tahoma=tahoma; Times New Roman=times new roman,serif; Verdana=verdana',
            font_size_formats: '8pt 9pt 10pt 11pt 12pt 14pt 16pt 18pt 20pt 24pt 36pt',
            lineheight_formats: '1 1.15 1.25 1.5 1.75 2 2.5 3',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 12pt; color: #111827; line-height: 1.5; } p { margin: 0 0 0.5em; }',
            setup: function(editor) {
                editor.on('init', function() {
                    tinyEditors[editor.id] = editor;
                });
                editor.on('change keyup', function() {
                    editor.save();
                    marcarAlterado();
                });
            }
        };
    }

    function initSeccaoEditor(textareaId) {
        if (tinyEditors[textareaId]) {
            tinymce.get(textareaId).remove();
            delete tinyEditors[textareaId];
        }
        tinymce.init(getTinyConfig('#' + textareaId));
    }

    // Inicializar editores das secções existentes (apenas tipo texto)
    <?php foreach ($espec['seccoes'] as $i => $sec): ?>
    <?php if (($sec['tipo'] ?? 'texto') === 'texto'): ?>
    initSeccaoEditor('seccao_<?= $i ?>');
    <?php endif; ?>
    <?php endforeach; ?>

    // ============================================================
    // NIVEL DE SECÇÃO (modal)
    // ============================================================
    var _pendingNivel = 1;
    var _pendingAction = null;

    function pedirNivelSeccao(action) {
        var container = document.getElementById('seccoesContainer');
        var blocks = container.querySelectorAll('.seccao-block');
        // Se não houver secções, auto-principal
        if (blocks.length === 0) {
            _pendingNivel = 1;
            executarAcaoSeccao(action);
            return;
        }
        _pendingAction = action;
        var m = document.getElementById('nivelModal');
        m.classList.remove('hidden');
    }

    function confirmarNivel(nivel) {
        _pendingNivel = nivel;
        var action = _pendingAction;
        fecharNivelModal();
        if (action) {
            executarAcaoSeccao(action);
        }
    }

    function fecharNivelModal() {
        var m = document.getElementById('nivelModal');
        m.classList.add('hidden');
        _pendingAction = null;
    }

    function executarAcaoSeccao(action) {
        if (action === 'texto') {
            adicionarSeccao();
        } else if (action === 'parametros') {
            abrirSelectorTipoParametro();
        } else if (action === 'legislacao') {
            abrirSelectorLegConteudo();
        } else if (action === 'ficheiros') {
            adicionarSeccaoFicheiros();
        }
    }

    // ============================================================
    // SECÇÕES DINÂMICAS
    // ============================================================
    function criarSeccao(titulo, conteudo, idx, nivel) {
        nivel = nivel || 1;
        var block = document.createElement('div');
        block.className = 'seccao-block';
        block.setAttribute('data-seccao-idx', idx);
        block.setAttribute('data-tipo', 'texto');
        block.setAttribute('data-nivel', nivel);

        var headerHtml =
            '<div class="seccao-header">' +
                '<span class="seccao-numero">' + (idx + 1) + '.</span>' +
                '<input type="text" class="seccao-titulo" value="' + escapeHtml(titulo) + '" placeholder="Título da secção">' +
                '<div class="seccao-ai-btns">' +
                    '<button class="btn-ai" onclick="abrirAI(this, \'sugerir\')" title="Sugerir conteúdo com IA"><span class="ai-icon">&#10024;</span> Sugerir</button>' +
                    '<button class="btn-ai" onclick="abrirAI(this, \'melhorar\')" title="Melhorar conteúdo com IA"><span class="ai-icon">&#9998;</span> Melhorar</button>' +
                '</div>' +
                '<div class="seccao-actions">' +
                    '<button class="btn btn-ghost btn-sm seccao-collapse-btn" onclick="toggleCollapse(this)" title="Colapsar/Expandir">&minus;</button>' +
                    '<button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>' +
                    '<button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>' +
                    '<button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>' +
                '</div>' +
            '</div>';

        var editorId = 'seccao_' + idx;
        var editorHtml = '<textarea id="' + editorId + '" class="seccao-editor" rows="6" placeholder="Conteúdo da secção...">' + (conteudo || '') + '</textarea>';

        block.innerHTML = headerHtml + editorHtml;

        block.querySelector('.seccao-titulo').addEventListener('input', marcarAlterado);

        return { block: block, editorId: editorId };
    }

    function adicionarSeccao(tipo, titulo, conteudo, nivel) {
        var container = document.getElementById('seccoesContainer');
        var idx = seccaoCounter++;

        var result = criarSeccao(titulo || '', conteudo || '', idx, nivel || _pendingNivel);
        container.appendChild(result.block);

        var empty = document.getElementById('seccoesEmpty');
        if (empty) empty.remove();

        initSeccaoEditor(result.editorId);
        renumerarSeccoes();
        marcarAlterado();
        result.block.querySelector('.seccao-titulo').focus();
    }

    function removerSeccao(btn) {
        var block = btn.closest('.seccao-block');
        var tipo = block.getAttribute('data-tipo');

        if (tipo === 'texto') {
            var editor = block.querySelector('.seccao-editor');
            if (editor && tinyEditors[editor.id]) {
                tinymce.get(editor.id).remove();
                delete tinyEditors[editor.id];
            }
        }

        block.remove();
        renumerarSeccoes();
        marcarAlterado();
    }

    // Remover linha de tabela (parâmetros)
    function removerEnsaioLinha(btn) {
        var tr = btn.closest('tr');
        var tbody = tr.closest('tbody');
        var table = tbody.closest('table');

        // Restaurar DOM primeiro (recriar slave cells removidos pelo rowspan)
        restoreMergesDOM(tbody);

        var dataRows = getDataRows(tbody);
        var rowIdx = dataRows.indexOf(tr);

        // Ajustar merges (preservando hAlign e vAlign)
        var merges = getTableMerges(table);
        var newMerges = [];
        merges.forEach(function(m) {
            var mEnd = m.row + m.span - 1;
            if (rowIdx < m.row) {
                newMerges.push({ col: m.col, row: m.row - 1, span: m.span, hAlign: m.hAlign, vAlign: m.vAlign });
            } else if (rowIdx > mEnd) {
                newMerges.push({ col: m.col, row: m.row, span: m.span, hAlign: m.hAlign, vAlign: m.vAlign });
            } else {
                var newSpan = m.span - 1;
                if (newSpan >= 2) {
                    newMerges.push({ col: m.col, row: m.row, span: newSpan, hAlign: m.hAlign, vAlign: m.vAlign });
                }
            }
        });
        setTableMerges(table, newMerges);

        // Se era a última data row sob um cat-header, remover o header também
        var prevSib = tr.previousElementSibling;
        var nextSib = tr.nextElementSibling;
        var removeCatRow = (prevSib && prevSib.classList.contains('ensaio-cat-row') &&
                           (!nextSib || nextSib.classList.contains('ensaio-cat-row')));
        tr.remove();
        if (removeCatRow && prevSib) prevSib.remove();

        applyMergesVisual(table);
        marcarAlterado();
    }

    // ============================================================
    // REDIMENSIONAMENTO DE COLUNAS (col-resize)
    // ============================================================
    function initColResize(table) {
        var ths = table.querySelectorAll('thead th');
        // Handles nas primeiras 4 colunas de dados (excluir NQA e ações)
        // Handle na coluna i permite redimensionar colunas i e i+1
        var dataCols = ths.length - 1; // excluir coluna ações
        for (var i = 0; i < dataCols - 1; i++) {
            if (ths[i].querySelector('.col-resize-handle')) continue;
            var handle = document.createElement('div');
            handle.className = 'col-resize-handle';
            ths[i].appendChild(handle);
            handle.addEventListener('mousedown', colResizeStart);
        }
    }

    var colResizeState = null;

    function colResizeStart(e) {
        e.preventDefault();
        e.stopPropagation();
        var th = e.target.parentElement;
        var table = th.closest('table');
        var ths = table.querySelectorAll('thead th');
        var idx = Array.prototype.indexOf.call(ths, th);
        var thNext = ths[idx + 1];
        if (!thNext) return;
        var tableW = table.offsetWidth;
        var startX = e.clientX;
        var startW = th.offsetWidth;
        var startNextW = thNext.offsetWidth;

        e.target.classList.add('active');
        colResizeState = { table: table, th: th, thNext: thNext, ths: ths, tableW: tableW, startX: startX, startW: startW, startNextW: startNextW, handle: e.target };
        document.addEventListener('mousemove', colResizeMove);
        document.addEventListener('mouseup', colResizeEnd);
    }

    function colResizeMove(e) {
        if (!colResizeState) return;
        var s = colResizeState;
        var diff = e.clientX - s.startX;
        var newW = s.startW + diff;
        var newNextW = s.startNextW - diff;
        // Largura mínima de 5%
        var minPx = s.tableW * 0.05;
        if (newW < minPx || newNextW < minPx) return;
        var pct = (newW / s.tableW * 100).toFixed(1);
        var pctNext = (newNextW / s.tableW * 100).toFixed(1);
        s.th.style.width = pct + '%';
        s.thNext.style.width = pctNext + '%';
    }

    function colResizeEnd(e) {
        if (!colResizeState) return;
        colResizeState.handle.classList.remove('active');
        colResizeState = null;
        document.removeEventListener('mousemove', colResizeMove);
        document.removeEventListener('mouseup', colResizeEnd);
        marcarAlterado();
    }

    // Inicializar col-resize em todas as tabelas de ensaios existentes
    document.querySelectorAll('.seccao-ensaios-table').forEach(initColResize);

    // ============================================================
    // MERGE DE CÉLULAS (juntar células) - com rowspan real
    // ============================================================

    // Ler colunas dinâmicas de uma tabela (via data-chave nos th ou data-field nos td)
    function getTableColumns(table) {
        var cols = [];
        var ths = table.querySelectorAll('thead th[data-chave]');
        ths.forEach(function(th) { cols.push(th.getAttribute('data-chave')); });
        if (cols.length === 0) {
            // Fallback: ler do primeiro data row
            var firstRow = table.querySelector('.ensaios-tbody tr:not([data-cat])');
            if (firstRow) {
                firstRow.querySelectorAll('textarea[data-field], input[data-field]').forEach(function(inp) {
                    var f = inp.getAttribute('data-field');
                    if (f && f !== 'cat-header') cols.push(f);
                });
            }
        }
        return cols;
    }

    // Obter apenas as data rows (excluir cat-header-row e ensaio-cat-row)
    function getDataRows(tbody) {
        return Array.from(tbody.querySelectorAll('tr:not(.ensaio-cat-row):not(.cat-header-row)'));
    }

    // Obter índice lógico de coluna de um td
    function getTdColumnIndex(td) {
        var input = td.querySelector('input[data-field], textarea[data-field]');
        if (input) {
            var f = input.getAttribute('data-field');
            if (f === 'cat-header') return -1;
            var table = td.closest('table');
            var cols = getTableColumns(table);
            return cols.indexOf(f);
        }
        if (td.querySelector('.remove-btn:not(.cat-remove-btn)')) {
            var table = td.closest('table');
            return getTableColumns(table).length; // coluna de ações
        }
        return -1;
    }

    // Criar td para uma coluna específica (dinâmico)
    function createCellForColumn(table, col, value) {
        var td = document.createElement('td');
        var cols = getTableColumns(table);
        if (col >= 0 && col < cols.length) {
            td.innerHTML = '<textarea rows="1" data-field="' + cols[col] + '">' + escapeHtml(value || '') + '</textarea>';
        } else {
            td.innerHTML = '<button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button>';
        }
        return td;
    }

    // Obter td por coluna lógica numa row
    function getTdByColumn(tr, col) {
        var tds = tr.querySelectorAll('td');
        for (var i = 0; i < tds.length; i++) {
            if (getTdColumnIndex(tds[i]) === col) return tds[i];
        }
        return null;
    }

    // Inserir td na posição correta numa row
    function insertTdAtColumn(tr, newTd, col) {
        var tds = Array.from(tr.querySelectorAll('td'));
        for (var i = 0; i < tds.length; i++) {
            if (getTdColumnIndex(tds[i]) > col) {
                tr.insertBefore(newTd, tds[i]);
                return;
            }
        }
        tr.appendChild(newTd);
    }

    var mergeAnchor = null; // {table, col, row}
    var mergeSelection = { table: null, col: null, startRow: null, endRow: null };

    function getTableMerges(table) {
        var attr = table.getAttribute('data-merges');
        return attr ? JSON.parse(attr) : [];
    }
    function setTableMerges(table, merges) {
        table.setAttribute('data-merges', JSON.stringify(merges));
    }

    // Verificar se uma célula é slave de um merge
    function isCellInMerge(merges, row, col) {
        for (var i = 0; i < merges.length; i++) {
            var m = merges[i];
            if (m.col === col && row > m.row && row < m.row + m.span) return true;
        }
        return false;
    }

    // Handler de clique para seleção (Ctrl/Cmd + Click)
    function initMergeHandlers(table) {
        var tbody = table.querySelector('.ensaios-tbody');
        if (!tbody || tbody._mergeInit) return;
        tbody._mergeInit = true;
        tbody.addEventListener('mousedown', function(e) {
            if (!e.ctrlKey && !e.metaKey) return;
            var td = e.target.closest('td');
            if (!td) return;
            var tr = td.closest('tr');
            if (!tr || tr.classList.contains('ensaio-cat-row')) return;
            // Obter coluna lógica (funciona com tds em falta por rowspan)
            var colIdx = getTdColumnIndex(td);
            // Ignorar coluna de ações ou desconhecida
            var nCols = getTableColumns(table).length;
            if (colIdx >= nCols || colIdx < 0) return;
            e.preventDefault();

            var dataRows = getDataRows(tbody);
            var rowIdx = dataRows.indexOf(tr);
            if (rowIdx < 0) return;

            if (!mergeAnchor || mergeAnchor.table !== table || mergeAnchor.col !== colIdx) {
                clearMergeSelection();
                mergeAnchor = { table: table, col: colIdx, row: rowIdx };
                mergeSelection = { table: table, col: colIdx, startRow: rowIdx, endRow: rowIdx };
                highlightMergeSelection();
            } else {
                var startRow = Math.min(mergeAnchor.row, rowIdx);
                var endRow = Math.max(mergeAnchor.row, rowIdx);
                mergeSelection = { table: table, col: colIdx, startRow: startRow, endRow: endRow };
                highlightMergeSelection();
            }
            updateMergeFloatButton();
        });
    }

    function highlightMergeSelection() {
        document.querySelectorAll('.seccao-ensaios-table td.merge-selected').forEach(function(el) {
            el.classList.remove('merge-selected');
        });
        if (!mergeSelection.table || mergeSelection.startRow === null) return;
        var tbody = mergeSelection.table.querySelector('.ensaios-tbody');
        var dataRows = getDataRows(tbody);
        for (var r = mergeSelection.startRow; r <= mergeSelection.endRow && r < dataRows.length; r++) {
            var td = getTdByColumn(dataRows[r], mergeSelection.col);
            if (td) td.classList.add('merge-selected');
        }
    }

    function updateMergeFloatButton() {
        var floatEl = document.getElementById('mergeFloatActions');
        if (!mergeSelection.table || mergeSelection.startRow === null || mergeSelection.startRow === mergeSelection.endRow) {
            floatEl.classList.remove('visible');
            return;
        }
        var tbody = mergeSelection.table.querySelector('.ensaios-tbody');
        var dataRows = getDataRows(tbody);
        var lastTd = getTdByColumn(dataRows[mergeSelection.endRow], mergeSelection.col);
        if (!lastTd && mergeSelection.startRow < dataRows.length) {
            lastTd = getTdByColumn(dataRows[mergeSelection.startRow], mergeSelection.col);
        }
        if (!lastTd) { floatEl.classList.remove('visible'); return; }
        var rect = lastTd.getBoundingClientRect();
        floatEl.style.top = (rect.bottom + 4) + 'px';
        floatEl.style.left = rect.left + 'px';
        floatEl.classList.add('visible');
    }

    function clearMergeSelection() {
        document.querySelectorAll('.seccao-ensaios-table td.merge-selected').forEach(function(el) {
            el.classList.remove('merge-selected');
        });
        mergeAnchor = null;
        mergeSelection = { table: null, col: null, startRow: null, endRow: null };
        var floatEl = document.getElementById('mergeFloatActions');
        if (floatEl) floatEl.classList.remove('visible');
    }

    function cancelarMergeSelection() {
        clearMergeSelection();
    }

    // Clicar fora da tabela cancela a seleção
    document.addEventListener('mousedown', function(e) {
        if (e.ctrlKey || e.metaKey) return;
        if (!e.target.closest('.seccao-ensaios-table') && !e.target.closest('.merge-float-actions')) {
            clearMergeSelection();
        }
    });

    function executarMerge() {
        if (!mergeSelection.table || mergeSelection.startRow === null || mergeSelection.startRow === mergeSelection.endRow) return;

        var table = mergeSelection.table;
        var tbody = table.querySelector('.ensaios-tbody');
        var col = mergeSelection.col;
        var startRow = mergeSelection.startRow;
        var endRow = mergeSelection.endRow;

        // Restaurar DOM primeiro (para que todas as cells existam)
        restoreMergesDOM(tbody);

        var merges = getTableMerges(table);

        // Verificar sobreposição e expandir se necessário
        for (var i = 0; i < merges.length; i++) {
            var m = merges[i];
            if (m.col === col) {
                var mEnd = m.row + m.span - 1;
                if (!(endRow < m.row || startRow > mEnd)) {
                    startRow = Math.min(startRow, m.row);
                    endRow = Math.max(endRow, mEnd);
                    merges.splice(i, 1);
                    i--;
                }
            }
        }

        var span = endRow - startRow + 1;

        // Determinar valor da célula juntada (agora todas cells existem no DOM)
        var dataRows = getDataRows(tbody);
        var values = [];
        for (var r = startRow; r <= endRow && r < dataRows.length; r++) {
            var td = getTdByColumn(dataRows[r], col);
            var input = td ? td.querySelector('textarea, input') : null;
            if (input) values.push(input.value.trim());
        }
        var allSame = values.length > 0 && values.every(function(v) { return v === values[0]; });
        var mergedValue = allSame ? values[0] : '';

        // Sincronizar valor em todas as células do merge
        for (var r = startRow; r <= endRow && r < dataRows.length; r++) {
            var td = getTdByColumn(dataRows[r], col);
            var input = td ? td.querySelector('textarea, input') : null;
            if (input) input.value = mergedValue;
        }

        merges.push({ col: col, row: startRow, span: span, hAlign: 'center', vAlign: 'middle' });
        setTableMerges(table, merges);

        clearMergeSelection();
        applyMergesDOM(tbody, merges);
        marcarAlterado();
    }

    function desfazerMerge(table, col, row) {
        var merges = getTableMerges(table);
        var newMerges = [];
        merges.forEach(function(m) {
            if (!(m.col === col && m.row === row)) {
                newMerges.push(m);
            }
        });
        setTableMerges(table, newMerges);
        applyMergesVisual(table);
        marcarAlterado();
    }

    var hAlignCycle = ['left','center','right'];
    var vAlignCycle = ['top','middle','bottom'];
    var hAlignIcons = { left: '&#9776;', center: '&#9866;', right: '&#9776;' };
    var vAlignLabels = { top: '&#8593;', middle: '&#8597;', bottom: '&#8595;' };

    function toggleMergeAlign(table, col, row, axis) {
        var merges = getTableMerges(table);
        var cycle = axis === 'h' ? hAlignCycle : vAlignCycle;
        for (var i = 0; i < merges.length; i++) {
            if (merges[i].col === col && merges[i].row === row) {
                var key = axis === 'h' ? 'hAlign' : 'vAlign';
                var cur = merges[i][key] || (axis === 'h' ? 'center' : 'middle');
                var idx = cycle.indexOf(cur);
                merges[i][key] = cycle[(idx + 1) % cycle.length];
                break;
            }
        }
        setTableMerges(table, merges);
        applyMergesVisual(table);
        marcarAlterado();
    }

    // Fase 1: RESTAURAR - recriar tds slave que foram removidos pelo rowspan
    function restoreMergesDOM(tbody) {
        var table = tbody.closest('table');
        var nCols = getTableColumns(table).length;
        var actionColIdx = nCols; // coluna de ações é a última
        var dataRows = getDataRows(tbody);

        // Recolher valores dos masters antes de resetar
        var masterValues = {};
        dataRows.forEach(function(tr, rowIdx) {
            tr.querySelectorAll('td[rowspan]').forEach(function(td) {
                var col = getTdColumnIndex(td);
                var span = parseInt(td.getAttribute('rowspan')) || 1;
                if (span > 1 && col >= 0 && col < nCols) {
                    var inp = td.querySelector('textarea, input');
                    var value = inp ? inp.value : '';
                    for (var r = rowIdx + 1; r < rowIdx + span && r < dataRows.length; r++) {
                        masterValues[col + '-' + r] = value;
                    }
                }
            });
        });

        // Resetar rowspan e limpar classes/tools
        dataRows.forEach(function(tr) {
            tr.querySelectorAll('td').forEach(function(td) {
                td.classList.remove('merge-master', 'merge-slave', 'merge-slave-last');
                td.style.position = '';
                td.style.verticalAlign = '';
                if (td.hasAttribute('rowspan')) td.removeAttribute('rowspan');
                var inp = td.querySelector('textarea, input');
                if (inp) { inp.style.visibility = ''; inp.style.textAlign = ''; }
                var tools = td.querySelector('.merge-tools');
                if (tools) tools.remove();
            });
        });

        // Recriar tds em falta
        dataRows.forEach(function(tr, rowIdx) {
            var existingCols = {};
            tr.querySelectorAll('td').forEach(function(td) {
                var col = getTdColumnIndex(td);
                if (col >= 0) existingCols[col] = true;
            });
            for (var col = 0; col < nCols; col++) {
                if (!existingCols[col]) {
                    var value = masterValues[col + '-' + rowIdx] || '';
                    var newTd = createCellForColumn(table, col, value);
                    insertTdAtColumn(tr, newTd, col);
                }
            }
            if (!existingCols[actionColIdx]) {
                var actionTd = createCellForColumn(table, actionColIdx, '');
                tr.appendChild(actionTd);
            }
        });
    }

    // Fase 2: APLICAR - definir rowspan real e remover slave tds
    function applyMergesDOM(tbody, merges) {
        var dataRows = getDataRows(tbody);

        merges.forEach(function(m) {
            if (m.row >= dataRows.length) return;
            var hAlign = m.hAlign || 'center';
            var vAlign = m.vAlign || 'middle';

            var masterTd = getTdByColumn(dataRows[m.row], m.col);
            if (!masterTd) return;

            // Definir rowspan real no master
            masterTd.setAttribute('rowspan', m.span);
            masterTd.classList.add('merge-master');
            masterTd.style.position = 'relative';
            masterTd.style.verticalAlign = vAlign;
            var masterInput = masterTd.querySelector('textarea, input');
            if (masterInput) masterInput.style.textAlign = hAlign;

            // Toolbar: [H] [V] [✕]
            var tools = document.createElement('div');
            tools.className = 'merge-tools';

            var btnH = document.createElement('button');
            btnH.innerHTML = hAlignIcons[hAlign] || '&#9866;';
            btnH.title = 'Alinhamento horizontal: ' + hAlign;
            btnH.setAttribute('data-mcol', m.col);
            btnH.setAttribute('data-mrow', m.row);
            btnH.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleMergeAlign(this.closest('table'), parseInt(this.getAttribute('data-mcol')), parseInt(this.getAttribute('data-mrow')), 'h');
            });

            var btnV = document.createElement('button');
            btnV.innerHTML = vAlignLabels[vAlign] || '&#8597;';
            btnV.title = 'Alinhamento vertical: ' + vAlign;
            btnV.setAttribute('data-mcol', m.col);
            btnV.setAttribute('data-mrow', m.row);
            btnV.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleMergeAlign(this.closest('table'), parseInt(this.getAttribute('data-mcol')), parseInt(this.getAttribute('data-mrow')), 'v');
            });

            var btnX = document.createElement('button');
            btnX.className = 'unmerge-btn';
            btnX.innerHTML = '&#10005;';
            btnX.title = 'Separar células';
            btnX.setAttribute('data-mcol', m.col);
            btnX.setAttribute('data-mrow', m.row);
            btnX.addEventListener('click', function(e) {
                e.stopPropagation();
                desfazerMerge(this.closest('table'), parseInt(this.getAttribute('data-mcol')), parseInt(this.getAttribute('data-mrow')));
            });

            tools.appendChild(btnH);
            tools.appendChild(btnV);
            tools.appendChild(btnX);
            masterTd.appendChild(tools);

            // Remover slave tds do DOM
            for (var r = m.row + 1; r < m.row + m.span && r < dataRows.length; r++) {
                var slaveTd = getTdByColumn(dataRows[r], m.col);
                if (slaveTd) slaveTd.remove();
            }
        });
    }

    // Função principal: restaura DOM e re-aplica merges com rowspan real
    function applyMergesVisual(table) {
        var merges = getTableMerges(table);
        var tbody = table.querySelector('.ensaios-tbody');
        if (!tbody) return;
        restoreMergesDOM(tbody);
        applyMergesDOM(tbody, merges);
    }

    // Inicializar merge handlers em todas as tabelas
    document.querySelectorAll('.seccao-ensaios-table').forEach(function(table) {
        initMergeHandlers(table);
        applyMergesVisual(table);
    });

    // Auto-grow textareas nos ensaios
    function autoGrowTextarea(el) {
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }

    // Event delegation para auto-grow em todas as tabelas de ensaios
    document.addEventListener('input', function(e) {
        if (e.target.tagName === 'TEXTAREA' && e.target.closest('.seccao-ensaios-table')) {
            autoGrowTextarea(e.target);
            marcarAlterado();
        }
    });

    // Inicializar auto-grow nas textareas existentes (após carregamento)
    // Só correr auto-grow em textareas com rows=1 (default); rows>1 já têm altura correcta do PHP
    document.querySelectorAll('.seccao-ensaios-table textarea[data-field]').forEach(function(ta) {
        if (parseInt(ta.getAttribute('rows') || '1') <= 1) {
            autoGrowTextarea(ta);
        }
    });

    // ============================================================
    // TIPO DE PARÂMETRO SELECTOR
    // ============================================================
    var _paramTiposCache = <?php
        $stmtAllPt = $db->prepare('SELECT id, nome, slug, colunas, legenda, legenda_tamanho, categorias FROM parametros_tipos WHERE ativo = 1 ORDER BY ordem, id');
        $stmtAllPt->execute();
        echo json_encode($stmtAllPt->fetchAll(PDO::FETCH_ASSOC));
    ?>;

    function abrirSelectorTipoParametro() {
        var modal = document.getElementById('modalSelectorTipo');
        var list = document.getElementById('tipoSelectorList');
        modal.classList.remove('hidden');

        if (_paramTiposCache) {
            renderTipoSelector(_paramTiposCache);
            return;
        }
        list.innerHTML = '<div class="muted" style="text-align:center;">A carregar...</div>';
        fetch('<?= BASE_PATH ?>/api.php?action=get_parametros_tipos').then(function(r){return r.json();}).then(function(data) {
            _paramTiposCache = (data.data && data.data.tipos) || [];
            renderTipoSelector(_paramTiposCache);
        });
    }

    function renderTipoSelector(tipos) {
        var list = document.getElementById('tipoSelectorList');
        if (tipos.length === 0) {
            list.innerHTML = '<div class="muted" style="text-align:center; padding:12px;">Nenhum tipo configurado.</div>';
            return;
        }
        var html = '';
        tipos.forEach(function(t) {
            html += '<button class="btn btn-secondary" style="width:100%; text-align:left; padding:10px 16px;" onclick="selecionarTipoParametro(' + t.id + ')">' + escapeHtml(t.nome) + '</button>';
        });
        list.innerHTML = html;
    }

    function selecionarTipoParametro(tipoId) {
        document.getElementById('modalSelectorTipo').classList.add('hidden');
        var tipo = _paramTiposCache.find(function(t) { return t.id == tipoId; });
        if (!tipo) return;
        // Todos os tipos usam o mesmo fluxo dinâmico
        adicionarSeccaoParametrosCustom(tipo);
    }

    function criarSeccaoParametrosCustom(tipo, dados, idx, nivel) {
        nivel = nivel || 1;
        var block = document.createElement('div');
        block.className = 'seccao-block';
        block.setAttribute('data-seccao-idx', idx);
        block.setAttribute('data-tipo', 'parametros');
        block.setAttribute('data-tipo-id', tipo.id);
        block.setAttribute('data-tipo-slug', tipo.slug);
        block.setAttribute('data-nivel', nivel);

        var cols = [];
        try { cols = typeof tipo.colunas === 'string' ? JSON.parse(tipo.colunas) : (tipo.colunas || []); } catch(e) {}

        var headerHtml =
            '<div class="seccao-header">' +
                '<span class="seccao-numero">' + (idx + 1) + '.</span>' +
                '<input type="text" class="seccao-titulo" value="' + escapeHtml(tipo.nome) + '" placeholder="Título da secção">' +
                '<span class="pill pill-info" style="font-size:10px; padding:2px 8px;">' + escapeHtml(tipo.nome) + '</span>' +
                '<div class="seccao-actions">' +
                    '<button class="btn btn-ghost btn-sm seccao-collapse-btn" onclick="toggleCollapse(this)" title="Colapsar/Expandir">&minus;</button>' +
                    '<button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>' +
                    '<button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>' +
                    '<button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>' +
                '</div>' +
            '</div>';

        block.setAttribute('data-orientacao', tipo.orientacao || 'horizontal');

        var colWidth = Math.floor(90 / (cols.length || 1));
        var tableHtml =
            '<div class="seccao-ensaios-wrap">' +
                '<table class="seccao-ensaios-table" data-param-tipo-id="' + tipo.id + '">' +
                    '<thead><tr>';
        cols.forEach(function(c) {
            tableHtml += '<th style="width:' + (c.largura || colWidth) + '%">' + escapeHtml(c.nome) + '</th>';
        });
        tableHtml += '<th style="width:4%"></th></tr></thead><tbody class="ensaios-tbody">';

        if (dados && dados.length) {
            dados.forEach(function(row) {
                if (row._cat) {
                    tableHtml += criarParamCatRowHtml(row._cat, cols.length);
                } else {
                    tableHtml += criarParamCustomRowHtml(cols, row);
                }
            });
        }

        var legendaHtml = '';
        if (tipo.legenda) {
            var legTam = tipo.legenda_tamanho || 9;
            legendaHtml = '<div class="ensaios-legenda" style="font-size:' + legTam + 'px; color:#666; font-style:italic; margin-top:4px; padding:2px 4px;">' + escapeHtml(tipo.legenda) + '</div>';
        }

        tableHtml +=
                    '</tbody>' +
                '</table>' +
                legendaHtml +
                '<div class="seccao-ensaios-actions">' +
                    '<button class="btn btn-secondary btn-sm" onclick="adicionarParamCatLinha(this, ' + tipo.id + ')">+ Categoria</button>' +
                    '<button class="btn btn-secondary btn-sm" onclick="adicionarParamCustomLinha(this, ' + tipo.id + ')">+ Linha</button>' +
                    '<button class="btn btn-secondary btn-sm" onclick="abrirBancoParamCustom(this, ' + tipo.id + ')">+ Do Banco</button>' +
                '</div>' +
            '</div>';

        block.innerHTML = headerHtml + tableHtml;
        block.querySelector('.seccao-titulo').addEventListener('input', marcarAlterado);
        return { block: block };
    }

    function criarParamCatRowHtml(cat, numCols) {
        return '<tr class="cat-header-row" data-cat="1"><td colspan="' + (numCols + 1) + '" style="background:var(--color-primary-lighter, #e6f4f9); padding:4px 8px; font-weight:600; font-size:12px; color:var(--color-primary, #2596be);">' +
            '<input type="text" class="cat-header-input" value="' + escapeHtml(cat) + '" placeholder="Nome da categoria" style="border:none; background:transparent; font-weight:600; color:var(--color-primary, #2596be); width:calc(100% - 30px); font-size:12px;">' +
            '<button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover" style="float:right;">&times;</button>' +
            '</td></tr>';
    }

    function criarParamCustomRowHtml(cols, valores) {
        valores = valores || {};
        var html = '<tr>';
        cols.forEach(function(c) {
            var val = valores[c.chave] || '';
            html += '<td><textarea rows="1" data-field="' + escapeHtml(c.chave) + '">' + escapeHtml(val) + '</textarea></td>';
        });
        html += '<td><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button></td></tr>';
        return html;
    }

    function adicionarParamCatLinha(btn, tipoId) {
        var tipo = _paramTiposCache ? _paramTiposCache.find(function(t) { return t.id == tipoId; }) : null;
        if (!tipo) return;
        var cols = [];
        try { cols = typeof tipo.colunas === 'string' ? JSON.parse(tipo.colunas) : (tipo.colunas || []); } catch(e) {}
        var tbody = btn.closest('.seccao-ensaios-wrap').querySelector('.ensaios-tbody');
        var tr = document.createElement('tr');
        tr.className = 'cat-header-row';
        tr.setAttribute('data-cat', '1');
        tr.innerHTML = '<td colspan="' + (cols.length + 1) + '" style="background:var(--color-primary-lighter, #e6f4f9); padding:4px 8px; font-weight:600; font-size:12px; color:var(--color-primary, #2596be);"><input type="text" class="cat-header-input" value="" placeholder="Nome da categoria" style="border:none; background:transparent; font-weight:600; color:var(--color-primary, #2596be); width:calc(100% - 30px); font-size:12px;"><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover" style="float:right;">&times;</button></td>';
        tbody.appendChild(tr);
        tr.querySelector('.cat-header-input').focus();
        marcarAlterado();
    }

    function adicionarSeccaoParametrosCustom(tipo, dados) {
        var container = document.getElementById('seccoesContainer');
        var idx = seccaoCounter++;
        var result = criarSeccaoParametrosCustom(tipo, dados || [], idx, _pendingNivel);
        container.appendChild(result.block);
        var empty = document.getElementById('seccoesEmpty');
        if (empty) empty.remove();
        // Auto-grow textareas
        var tbl = result.block.querySelector('.seccao-ensaios-table');
        if (tbl) {
            initColResize(tbl);
            initMergeHandlers(tbl);
            tbl.querySelectorAll('textarea[data-field]').forEach(autoGrowTextarea);
        }
        renumerarSeccoes();
        marcarAlterado();
    }

    function adicionarParamCustomLinha(btn, tipoId) {
        var wrap = btn.closest('.seccao-ensaios-wrap');
        var tbody = wrap.querySelector('.ensaios-tbody');
        // Derivar campos de linha existente (funciona após reload sem cache)
        var fields = [];
        var existingTa = tbody.querySelector('tr:not([data-cat]) textarea[data-field]');
        if (existingTa) {
            existingTa.closest('tr').querySelectorAll('textarea[data-field]').forEach(function(ta) {
                fields.push(ta.getAttribute('data-field'));
            });
        }
        // Fallback: usar cache de tipos
        if (fields.length === 0) {
            var tipo = _paramTiposCache ? _paramTiposCache.find(function(t) { return t.id == tipoId; }) : null;
            if (!tipo) return;
            var cols = [];
            try { cols = JSON.parse(tipo.colunas); } catch(e) { cols = tipo.colunas || []; }
            cols.forEach(function(c) { fields.push(c.chave); });
        }
        var tr = document.createElement('tr');
        fields.forEach(function(f) {
            tr.innerHTML += '<td><textarea rows="1" data-field="' + escapeHtml(f) + '"></textarea></td>';
        });
        tr.innerHTML += '<td><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button></td>';
        tbody.appendChild(tr);
        tr.querySelectorAll('textarea[data-field]').forEach(autoGrowTextarea);
        marcarAlterado();
    }

    function abrirBancoParamCustom(btn, tipoId) {
        var wrap = btn.closest('.seccao-ensaios-wrap');
        fetch('<?= BASE_PATH ?>/api.php?action=get_parametros_banco&tipo_id=' + tipoId).then(function(r){return r.json();}).then(function(data) {
            var params = (data.data && data.data.parametros) || [];
            if (params.length === 0) {
                appAlert('Nenhum registo no banco para este tipo. Adicione registos em Administração > Parâmetros.');
                return;
            }
            var tipo = _paramTiposCache ? _paramTiposCache.find(function(t) { return t.id == tipoId; }) : null;
            if (!tipo) return;
            var cols = [];
            try { cols = typeof tipo.colunas === 'string' ? JSON.parse(tipo.colunas) : (tipo.colunas || []); } catch(e) {}

            // Construir modal com checkboxes
            var old = document.getElementById('bancoPicker');
            if (old) old.remove();
            var overlay = document.createElement('div');
            overlay.id = 'bancoPicker';
            overlay.className = 'modal-overlay';
            overlay.style.cssText = 'display:flex; z-index:9999;';
            var html = '<div class="modal-box" style="max-width:700px; max-height:80vh; display:flex; flex-direction:column;">';
            html += '<div class="modal-header"><h3 style="margin:0;">Selecionar do Banco</h3><button class="modal-close" onclick="document.getElementById(\'bancoPicker\').remove();">&times;</button></div>';
            html += '<div style="padding:8px 16px; border-bottom:1px solid #e5e7eb;"><label style="font-size:13px; cursor:pointer;"><input type="checkbox" id="bancoPickAll" onchange="document.querySelectorAll(\'.banco-pick-item\').forEach(function(c){c.checked=this.checked;}.bind(this));"> <strong>Selecionar todos</strong></label></div>';
            html += '<div class="modal-body" style="overflow-y:auto; flex:1; padding:8px 16px;">';
            html += '<table style="width:100%; font-size:12px; border-collapse:collapse;">';
            // Header
            html += '<thead><tr><th style="width:30px;"></th>';
            cols.forEach(function(c) { html += '<th style="padding:4px 6px; text-align:left; border-bottom:2px solid #e5e7eb;">' + escapeHtml(c.nome) + '</th>'; });
            html += '</tr></thead><tbody>';
            var lastCat = '__NONE__';
            params.forEach(function(p, idx) {
                if (p.categoria && p.categoria !== lastCat) {
                    html += '<tr><td colspan="' + (cols.length + 1) + '" style="padding:4px 8px; font-weight:600; font-size:12px; background:var(--color-primary-lighter, #e6f4f9); color:var(--color-primary, #2596be);">' + escapeHtml(p.categoria) + '</td></tr>';
                    lastCat = p.categoria;
                }
                var vals = {};
                try { vals = typeof p.valores === 'string' ? JSON.parse(p.valores) : (p.valores || {}); } catch(e) {}
                html += '<tr><td style="text-align:center;"><input type="checkbox" class="banco-pick-item" data-idx="' + idx + '" checked></td>';
                cols.forEach(function(c) {
                    html += '<td style="padding:3px 6px; border-bottom:1px solid #f0f0f0; white-space:pre-wrap;">' + escapeHtml(vals[c.chave] || '') + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            html += '<div class="modal-footer"><button class="btn btn-secondary" onclick="document.getElementById(\'bancoPicker\').remove();">Cancelar</button> <button class="btn btn-primary" id="bancoPickConfirm">Inserir selecionados</button></div>';
            html += '</div>';
            overlay.innerHTML = html;
            document.body.appendChild(overlay);

            // Handler do botão Inserir
            document.getElementById('bancoPickConfirm').addEventListener('click', function() {
                var checks = document.querySelectorAll('.banco-pick-item:checked');
                if (checks.length === 0) { appAlert('Selecione pelo menos um registo.'); return; }
                var tbody = wrap.querySelector('.ensaios-tbody');
                var lastCat2 = '__NONE__';
                checks.forEach(function(cb) {
                    var p = params[parseInt(cb.getAttribute('data-idx'))];
                    if (!p) return;
                    if (p.categoria && p.categoria !== lastCat2) {
                        var catTr = document.createElement('tr');
                        catTr.className = 'cat-header-row';
                        catTr.setAttribute('data-cat', '1');
                        catTr.innerHTML = '<td colspan="' + (cols.length + 1) + '" style="background:var(--color-primary-lighter, #e6f4f9); padding:4px 8px; font-weight:600; font-size:12px; color:var(--color-primary, #2596be);"><input type="text" class="cat-header-input" value="' + escapeHtml(p.categoria) + '" style="border:none; background:transparent; font-weight:600; color:var(--color-primary, #2596be); width:calc(100% - 30px); font-size:12px;"><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover" style="float:right;">&times;</button></td>';
                        tbody.appendChild(catTr);
                        lastCat2 = p.categoria;
                    }
                    var vals = {};
                    try { vals = typeof p.valores === 'string' ? JSON.parse(p.valores) : (p.valores || {}); } catch(e) {}
                    var tr = document.createElement('tr');
                    var rowHtml = '';
                    cols.forEach(function(c) {
                        rowHtml += '<td><textarea rows="1" data-field="' + escapeHtml(c.chave) + '">' + escapeHtml(vals[c.chave] || '') + '</textarea></td>';
                    });
                    rowHtml += '<td><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button></td>';
                    tr.innerHTML = rowHtml;
                    tbody.appendChild(tr);
                    tr.querySelectorAll('textarea[data-field]').forEach(autoGrowTextarea);
                });
                marcarAlterado();
                document.getElementById('bancoPicker').remove();
            });
        });
    }

    function toggleCollapse(btn) {
        var block = btn.closest('.seccao-block');
        block.classList.toggle('collapsed');
        btn.innerHTML = block.classList.contains('collapsed') ? '+' : '&minus;';
    }

    function moverSeccao(btn, direction) {
        var block = btn.closest('.seccao-block');
        var container = document.getElementById('seccoesContainer');
        var blocks = Array.from(container.querySelectorAll('.seccao-block'));
        var index = blocks.indexOf(block);
        var targetIndex = index + direction;

        if (targetIndex < 0 || targetIndex >= blocks.length) return;

        // Guardar e remover TinyMCE apenas de secções texto
        blocks.forEach(function(b) {
            if (b.getAttribute('data-tipo') !== 'texto') return;
            var ta = b.querySelector('.seccao-editor');
            if (ta && tinyEditors[ta.id]) {
                tinyEditors[ta.id].save();
            }
        });
        blocks.forEach(function(b) {
            if (b.getAttribute('data-tipo') !== 'texto') return;
            var ta = b.querySelector('.seccao-editor');
            if (ta && tinyEditors[ta.id]) {
                tinymce.get(ta.id).remove();
                delete tinyEditors[ta.id];
            }
        });

        // Mover no DOM
        if (direction === -1) {
            container.insertBefore(block, blocks[targetIndex]);
        } else {
            var next = blocks[targetIndex].nextElementSibling;
            if (next) {
                container.insertBefore(block, next.nextElementSibling);
            } else {
                container.appendChild(block);
            }
        }

        // Re-inicializar TinyMCE apenas para secções texto
        container.querySelectorAll('.seccao-block[data-tipo="texto"]').forEach(function(b) {
            var ta = b.querySelector('.seccao-editor');
            if (ta) initSeccaoEditor(ta.id);
        });

        renumerarSeccoes();
        marcarAlterado();
    }

    function renumerarSeccoes() {
        var container = document.getElementById('seccoesContainer');
        var mainCounter = 0, subCounter = 0;
        container.querySelectorAll('.seccao-block').forEach(function(block) {
            var nivel = parseInt(block.getAttribute('data-nivel')) || 1;
            var numStr;
            if (nivel === 1) {
                mainCounter++;
                subCounter = 0;
                numStr = mainCounter + '.';
            } else {
                subCounter++;
                numStr = mainCounter + '.' + subCounter + '.';
            }
            block.querySelector('.seccao-numero').textContent = numStr;
        });
    }

    // ============================================================
    // DRAG AND DROP SECTIONS
    // ============================================================
    (function() {
        var container = document.getElementById('seccoesContainer');
        if (!container) return;
        var dragBlock = null;

        function initDragHandles() {
            container.querySelectorAll('.seccao-block').forEach(function(block) {
                if (block.getAttribute('data-drag-init')) return;
                block.setAttribute('data-drag-init', '1');
                block.setAttribute('draggable', 'true');

                var header = block.querySelector('.seccao-header');
                if (header && !header.querySelector('.drag-handle')) {
                    var handle = document.createElement('span');
                    handle.className = 'drag-handle';
                    handle.innerHTML = '&#9776;';
                    handle.title = 'Arrastar para reordenar';
                    header.insertBefore(handle, header.firstChild);
                }

                block.addEventListener('dragstart', function(e) {
                    dragBlock = block;
                    block.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    container.querySelectorAll('.seccao-block[data-tipo="texto"]').forEach(function(b) {
                        var ta = b.querySelector('.seccao-editor');
                        if (ta && tinyEditors[ta.id]) tinyEditors[ta.id].save();
                    });
                });

                block.addEventListener('dragend', function() {
                    block.classList.remove('dragging');
                    container.querySelectorAll('.seccao-block').forEach(function(b) { b.classList.remove('drag-over'); });
                    dragBlock = null;
                });

                block.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (block !== dragBlock) block.classList.add('drag-over');
                });

                block.addEventListener('dragleave', function() {
                    block.classList.remove('drag-over');
                });

                block.addEventListener('drop', function(e) {
                    e.preventDefault();
                    if (!dragBlock || dragBlock === block) return;
                    block.classList.remove('drag-over');

                    container.querySelectorAll('.seccao-block[data-tipo="texto"]').forEach(function(b) {
                        var ta = b.querySelector('.seccao-editor');
                        if (ta && tinyEditors[ta.id]) {
                            tinymce.get(ta.id).remove();
                            delete tinyEditors[ta.id];
                        }
                    });

                    var rect = block.getBoundingClientRect();
                    if (e.clientY < rect.top + rect.height / 2) {
                        container.insertBefore(dragBlock, block);
                    } else {
                        container.insertBefore(dragBlock, block.nextSibling);
                    }

                    container.querySelectorAll('.seccao-block[data-tipo="texto"]').forEach(function(b) {
                        var ta = b.querySelector('.seccao-editor');
                        if (ta) initSeccaoEditor(ta.id);
                    });

                    renumerarSeccoes();
                    marcarAlterado();
                });
            });
        }

        initDragHandles();
        var observer = new MutationObserver(function() { setTimeout(initDragHandles, 100); });
        observer.observe(container, { childList: true });
    })();

    // ============================================================
    // IA ASSISTENTE (OpenAI)
    // ============================================================
    var aiCurrentBlock = null;
    var aiCurrentMode = '';

    function abrirAI(btn, mode) {
        aiCurrentBlock = btn.closest('.seccao-block');
        aiCurrentMode = mode;

        var titulo = aiCurrentBlock.querySelector('.seccao-titulo').value || 'Secção';
        var modal = document.getElementById('aiModal');
        var modalTitle = document.getElementById('aiModalTitle');
        var modalLabel = document.getElementById('aiModalLabel');
        var promptInput = document.getElementById('aiPromptInput');

        if (mode === 'sugerir') {
            modalTitle.textContent = 'Sugerir conteúdo para "' + titulo + '"';
            modalLabel.textContent = 'Descreva o que pretende que a IA escreva para esta secção:';
            promptInput.placeholder = 'Ex: Descreve o objetivo desta especificação para rolhas de cortiça natural colmatadas...';
            promptInput.value = '';
        } else {
            modalTitle.textContent = 'Melhorar conteúdo de "' + titulo + '"';
            modalLabel.textContent = 'O que pretende melhorar no conteúdo atual?';
            promptInput.placeholder = 'Ex: Tornar mais técnico e detalhado, adicionar referências a normas ISO...';
            promptInput.value = '';
        }

        modal.classList.remove('hidden');
        promptInput.focus();
    }

    function fecharAIModal() {
        var modal = document.getElementById('aiModal');
        modal.classList.add('hidden');
        // Restaurar estado original do modal
        var body = modal.querySelector('.ai-modal-body');
        var preview = body.querySelector('.ai-result-preview');
        if (preview) preview.remove();
        var ta = body.querySelector('textarea');
        ta.style.display = '';
        ta.value = '';
        var submitBtn = document.getElementById('aiSubmitBtn');
        submitBtn.textContent = 'Gerar';
        submitBtn.disabled = false;
        submitBtn.onclick = executarAI;
        var cancelBtn = modal.querySelector('.ai-modal-footer .btn-secondary');
        cancelBtn.textContent = 'Cancelar';
        aiCurrentBlock = null;
        aiCurrentMode = '';
    }

    function executarAI() {
        if (!aiCurrentBlock || !aiCurrentMode) return;

        var promptInput = document.getElementById('aiPromptInput');
        var prompt = promptInput.value.trim();
        if (!prompt) {
            promptInput.focus();
            showToast('Escreva uma indicação para a IA.', 'warning');
            return;
        }

        var editorId = aiCurrentBlock.querySelector('.seccao-editor').id;
        var titulo = aiCurrentBlock.querySelector('.seccao-titulo').value || '';
        var conteudo = getEditorContent(editorId);

        var submitBtn = document.getElementById('aiSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'A gerar...';

        // Adicionar loading aos botões da secção
        aiCurrentBlock.querySelectorAll('.btn-ai').forEach(function(b) { b.classList.add('loading'); });

        apiPost({
                action: 'ai_assist',
                mode: aiCurrentMode,
                prompt: prompt,
                conteudo: conteudo,
                titulo: titulo
            })
        .then(function(r) { return checkSession(r); })
        .then(function(result) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Gerar';
            aiCurrentBlock.querySelectorAll('.btn-ai').forEach(function(b) { b.classList.remove('loading'); });

            if (result.success && result.data && result.data.content) {
                var newContent = result.data.content;

                // Mostrar preview com opção de aplicar ou descartar
                var body = document.querySelector('#aiModal .ai-modal-body');
                body.querySelector('label').textContent = 'Resultado da IA:';
                body.querySelector('textarea').style.display = 'none';
                var preview = document.createElement('div');
                preview.className = 'ai-result-preview';
                preview.innerHTML = newContent;
                body.appendChild(preview);

                // Mudar botões para Aplicar / Manter atual
                submitBtn.textContent = 'Aplicar sugestão';
                submitBtn.disabled = false;
                submitBtn.onclick = function() {
                    if (tinyEditors[editorId]) {
                        tinyEditors[editorId].setContent(newContent);
                        tinyEditors[editorId].save();
                    } else {
                        document.getElementById(editorId).value = newContent;
                    }
                    fecharAIModal();
                    marcarAlterado();
                    showToast('Sugestão da IA aplicada. <strong>Revise antes de usar em documentos oficiais.</strong>', 'warning', 6000);
                };

                var cancelBtn = document.querySelector('#aiModal .ai-modal-footer .btn-secondary');
                cancelBtn.textContent = 'Manter atual';
            } else {
                showToast(result.error || 'Erro ao gerar conteúdo.', 'error');
            }
        })
        .catch(function(err) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Gerar';
            aiCurrentBlock.querySelectorAll('.btn-ai').forEach(function(b) { b.classList.remove('loading'); });
            showToast('Erro de ligação ao servidor.', 'error');
            console.error(err);
        });
    }

    // Enter no modal = submeter
    document.getElementById('aiPromptInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            executarAI();
        }
    });

    // Escape = fechar
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !document.getElementById('aiModal').classList.contains('hidden')) {
            fecharAIModal();
        }
    });

    // ============================================================
    // CONFIGURAÇÕES VISUAIS
    // ============================================================
    var configVisual = <?= json_encode($configVisual, JSON_UNESCAPED_UNICODE) ?>;
    var orgCores = {
        primaria: <?= json_encode($user['org_cor'] ?? '#2596be') ?>,
        dark: <?= json_encode($user['org_cor_dark'] ?? '#1a7a9e') ?>,
        light: <?= json_encode($user['org_cor_light'] ?? '#e6f4f9') ?>
    };

    // Sync color picker <-> hex input
    function syncColorInputs(colorId, hexId) {
        var colorEl = document.getElementById(colorId);
        var hexEl = document.getElementById(hexId);
        colorEl.addEventListener('input', function() {
            hexEl.value = this.value;
            atualizarConfigPreview();
            marcarAlterado();
        });
        hexEl.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                colorEl.value = this.value;
                atualizarConfigPreview();
                marcarAlterado();
            }
        });
    }
    syncColorInputs('cfg_cor_titulos', 'cfg_cor_titulos_hex');
    syncColorInputs('cfg_cor_subtitulos', 'cfg_cor_subtitulos_hex');
    syncColorInputs('cfg_cor_linhas', 'cfg_cor_linhas_hex');
    syncColorInputs('cfg_cor_nome', 'cfg_cor_nome_hex');

    document.getElementById('cfg_tamanho_titulos').addEventListener('input', function() {
        document.getElementById('cfg_tamanho_titulos_val').textContent = this.value + 'pt';
        atualizarConfigPreview();
        marcarAlterado();
    });

    document.getElementById('cfg_tamanho_subtitulos').addEventListener('input', function() {
        document.getElementById('cfg_tamanho_subtitulos_val').textContent = this.value + 'pt';
        atualizarConfigPreview();
        marcarAlterado();
    });

    document.getElementById('cfg_subtitulos_bold').addEventListener('change', function() {
        atualizarConfigPreview();
        marcarAlterado();
    });

    document.getElementById('cfg_tamanho_nome').addEventListener('input', function() {
        document.getElementById('cfg_tamanho_nome_val').textContent = this.value + 'pt';
        atualizarConfigPreview();
        marcarAlterado();
    });

    function atualizarConfigPreview() {
        var corTitulos = document.getElementById('cfg_cor_titulos').value;
        var corSubtitulos = document.getElementById('cfg_cor_subtitulos').value;
        var corLinhas = document.getElementById('cfg_cor_linhas').value;
        var tamTitulos = document.getElementById('cfg_tamanho_titulos').value;
        var tamSubtitulos = document.getElementById('cfg_tamanho_subtitulos').value;
        var corNome = document.getElementById('cfg_cor_nome').value;
        var tamNome = document.getElementById('cfg_tamanho_nome').value;
        var subBold = document.getElementById('cfg_subtitulos_bold').checked;

        // Preview no tab config - secção title
        var prev = document.getElementById('cfgPreviewTitle');
        prev.style.color = corTitulos;
        prev.style.fontSize = tamTitulos + 'pt';
        prev.style.borderBottomColor = corLinhas;

        // Preview no tab config - subtítulo
        var prevSub = document.getElementById('cfgPreviewSubtitle');
        if (prevSub) {
            prevSub.style.color = corSubtitulos;
            prevSub.style.fontSize = tamSubtitulos + 'pt';
            prevSub.style.fontWeight = subBold ? 'bold' : 'normal';
            prevSub.style.borderBottomColor = corLinhas;
        }

        // Preview no tab config - nome
        var prevNome = document.getElementById('cfgPreviewNome');
        if (prevNome) {
            prevNome.style.color = corNome;
            prevNome.style.fontSize = tamNome + 'pt';
        }

        // Aplicar na sidebar preview
        configVisual.cor_titulos = corTitulos;
        configVisual.cor_subtitulos = corSubtitulos;
        configVisual.cor_linhas = corLinhas;
        configVisual.tamanho_titulos = tamTitulos;
        configVisual.tamanho_subtitulos = tamSubtitulos;
        configVisual.subtitulos_bold = subBold ? '1' : '0';
        configVisual.cor_nome = corNome;
        configVisual.tamanho_nome = tamNome;
        aplicarConfigPreviewSidebar();
    }

    function aplicarConfigPreviewSidebar() {
        // Títulos h4 no preview
        document.querySelectorAll('.preview-body h4').forEach(function(h4) {
            h4.style.color = configVisual.cor_titulos;
            h4.style.borderBottomColor = configVisual.cor_linhas;
            h4.style.fontSize = configVisual.tamanho_titulos + 'pt';
        });
        // Nome/título do documento no preview
        var prevTitulo = document.getElementById('prevTitulo');
        if (prevTitulo) {
            prevTitulo.style.color = configVisual.cor_nome;
            prevTitulo.style.fontSize = configVisual.tamanho_nome + 'pt';
        }
    }

    function recolherConfigVisual() {
        return {
            cor_titulos: document.getElementById('cfg_cor_titulos').value,
            cor_subtitulos: document.getElementById('cfg_cor_subtitulos').value,
            cor_linhas: document.getElementById('cfg_cor_linhas').value,
            tamanho_titulos: document.getElementById('cfg_tamanho_titulos').value,
            tamanho_subtitulos: document.getElementById('cfg_tamanho_subtitulos').value,
            subtitulos_bold: document.getElementById('cfg_subtitulos_bold').checked ? '1' : '0',
            cor_nome: document.getElementById('cfg_cor_nome').value,
            tamanho_nome: document.getElementById('cfg_tamanho_nome').value,
            logo_custom: configVisual.logo_custom || ''
        };
    }

    // Upload de logo
    document.getElementById('cfg_logo_file').addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;

        if (especId === 0) {
            showToast('Guarde a especificação antes de fazer upload do logo.', 'warning');
            this.value = '';
            return;
        }

        var formData = new FormData();
        formData.append('action', 'upload_logo_custom');
        formData.append('especificacao_id', especId);
        formData.append('logo', file);

        apiPostForm(formData)
        .then(function(r) { return checkSession(r); })
        .then(function(result) {
            if (result.success && result.data && result.data.filename) {
                configVisual.logo_custom = result.data.filename;
                document.getElementById('cfgLogoPreview').innerHTML =
                    '<img src="' + BASE_PATH + '/uploads/logos/' + result.data.filename + '" style="max-width:100%; max-height:100%;" alt="Logo">';
                showToast('Logo carregado.', 'success');
                marcarAlterado();
            } else {
                showToast(result.error || 'Erro ao carregar logo.', 'error');
            }
        })
        .catch(function() {
            showToast('Erro de ligação.', 'error');
        });
    });

    function removerLogoCustom() {
        configVisual.logo_custom = '';
        document.getElementById('cfgLogoPreview').innerHTML = '<span class="muted" style="font-size:11px;">Sem logo</span>';
        document.getElementById('cfg_logo_file').value = '';
        marcarAlterado();
    }

    // ============================================================
    // TABS
    // ============================================================
    function activateTab(targetId) {
        document.querySelectorAll('#mainTabs .tab').forEach(function(t) {
            t.classList.remove('active');
        });
        var tabBtn = document.querySelector('#mainTabs .tab[data-tab="' + targetId + '"]');
        if (tabBtn) tabBtn.classList.add('active');
        document.querySelectorAll('.tab-panel').forEach(function(panel) {
            panel.classList.remove('active');
        });
        var panel = document.getElementById('panel-' + targetId);
        if (panel) panel.classList.add('active');
    }
    document.querySelectorAll('#mainTabs .tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var tabId = this.getAttribute('data-tab');
            activateTab(tabId);
            if (tabId === 'historico' && especId) {
                // Marcar histórico como visto — remove badge
                apiPost({ action: 'marcar_historico_visto', especificacao_id: especId });
                var badge = this.querySelector('span');
                if (badge) badge.remove();
            }
        });
    });
    // Restaurar tab via hash (ex: #tab-partilha)
    if (window.location.hash && window.location.hash.startsWith('#tab-')) {
        activateTab(window.location.hash.replace('#tab-', ''));
    }

    // Carregar comentários ao abrir
    if (especId) carregarComentarios();

    // ============================================================
    // TOAST NOTIFICATIONS
    // ============================================================
    function showToast(msg, type, duration) {
        type = type || 'info';
        duration = duration || 3500;
        var container = document.getElementById('toast-container');
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.innerHTML = msg;
        container.appendChild(toast);
        setTimeout(function() { toast.remove(); }, duration);
    }

    // ============================================================
    // MULTI-SELECT DROPDOWN
    // ============================================================
    function toggleMultiSelect(wrapId) {
        var wrap = document.getElementById(wrapId);
        var isOpen = wrap.classList.contains('open');
        // Fechar todos
        document.querySelectorAll('.multi-select-wrap.open').forEach(function(w) {
            w.classList.remove('open');
        });
        if (!isOpen) wrap.classList.add('open');
    }

    function updateMultiLabel(wrapId) {
        var wrap = document.getElementById(wrapId);
        var checked = wrap.querySelectorAll('input[type="checkbox"]:checked');
        var label = wrap.querySelector('.multi-select-label');
        if (checked.length === 0) {
            label.textContent = wrapId === 'fornecedoresWrap' ? 'Todos os fornecedores' : '-- Selecionar produto(s) --';
            label.classList.remove('has-values');
        } else {
            var names = [];
            checked.forEach(function(cb) {
                names.push(cb.parentElement.textContent.trim());
            });
            label.textContent = names.join(', ');
            label.classList.add('has-values');
        }
    }

    function getCheckedValues(wrapId) {
        var wrap = document.getElementById(wrapId);
        var checked = wrap.querySelectorAll('input[type="checkbox"]:checked');
        var values = [];
        checked.forEach(function(cb) { values.push(cb.value); });
        return values;
    }

    function getMultiSelectText(wrapId) {
        var wrap = document.getElementById(wrapId);
        var checked = wrap.querySelectorAll('input[type="checkbox"]:checked');
        if (checked.length === 0) return '';
        var names = [];
        checked.forEach(function(cb) {
            names.push(cb.parentElement.textContent.trim());
        });
        return names.join(', ');
    }

    // Fechar dropdowns ao clicar fora
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.multi-select-wrap')) {
            document.querySelectorAll('.multi-select-wrap.open').forEach(function(w) {
                w.classList.remove('open');
            });
        }
    });

    // Inicializar labels
    if (document.getElementById('produtosWrap')) updateMultiLabel('produtosWrap');
    if (document.getElementById('fornecedoresWrap')) updateMultiLabel('fornecedoresWrap');

    // ============================================================
    // DROPDOWN
    // ============================================================
    function toggleDropdown(id) {
        var menu = document.getElementById(id);
        menu.classList.toggle('show');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
                m.classList.remove('show');
            });
        }
    });

    // ============================================================
    // SAVE INDICATOR
    // ============================================================
    function setSaveState(state, text) {
        var indicator = document.getElementById('saveIndicator');
        var textEl = indicator.querySelector('.save-text');
        indicator.className = 'save-indicator ' + state;
        textEl.textContent = text || state;
    }

    // ============================================================
    // RECOLHER DADOS DO FORMULARIO
    // ============================================================
    function getEditorContent(id) {
        if (tinyEditors[id]) {
            return tinyEditors[id].getContent();
        }
        return document.getElementById(id).value;
    }

    // ============================================================
    // LEGISLAÇÃO - Funções
    // ============================================================
    function abrirSelectorLegConteudo() {
        popularSelectorLeg();
        document.getElementById('modalSelectorLeg').classList.remove('hidden');
    }

    function popularSelectorLeg() {
        var grid = document.getElementById('legSelectorGrid');
        grid.innerHTML = '<div class="muted" style="padding:var(--spacing-md); text-align:center;">A carregar...</div>';
        fetch('<?= BASE_PATH ?>/api.php?action=get_legislacao_banco')
        .then(function(r) { return checkSession(r); })
        .then(function(resp) {
            var legs = resp.data && resp.data.legislacao ? resp.data.legislacao : [];
            if (!resp.success || legs.length === 0) {
                grid.innerHTML = '<div class="muted" style="padding:var(--spacing-md); text-align:center;">Nenhuma legislação no banco. Contacte o administrador.</div>';
                return;
            }
            var html = '';
            legs.forEach(function(leg) {
                var linkHtml = '';
                if (leg.link_url) {
                    var legFullUrl = leg.link_url.startsWith('/') ? '<?= BASE_PATH ?>' + leg.link_url : leg.link_url;
                    linkHtml = ' <a href="' + escapeHtml(legFullUrl) + '" target="_blank" onclick="event.stopPropagation();" title="Ver documento" style="color:var(--primary-color,#2563eb); font-size:13px; text-decoration:none;">&#128279;</a>';
                }
                html += '<label class="ensaio-check-item" style="display:flex; gap:8px; padding:8px 10px; border-bottom:1px solid var(--color-border); cursor:pointer;">' +
                    '<input type="checkbox" name="sel_leg" data-norma="' + escapeHtml(leg.legislacao_norma) + '" data-rolhas="' + escapeHtml(leg.rolhas_aplicaveis || '') + '" data-resumo="' + escapeHtml(leg.resumo || '') + '" data-link_url="' + escapeHtml(leg.link_url || '') + '">' +
                    '<div style="flex:1;">' +
                    '<div style="font-weight:600; font-size:13px;">' + escapeHtml(leg.legislacao_norma) + linkHtml + '</div>' +
                    '<div style="font-size:11px; color:var(--color-muted);">' + escapeHtml(leg.rolhas_aplicaveis || '') + '</div>' +
                    '</div></label>';
            });
            grid.innerHTML = html;
        });
    }

    function confirmarSelectorLeg() {
        var checks = document.querySelectorAll('#legSelectorGrid input[name="sel_leg"]:checked');
        if (checks.length === 0) { appAlert('Selecione pelo menos uma legislação.'); return; }

        var ul = '<ul>';
        checks.forEach(function(cb) {
            ul += '<li>' + escapeHtml(cb.getAttribute('data-norma')) + '</li>';
        });
        ul += '</ul>';
        adicionarSeccaoTexto('Legislação Aplicável', ul);

        document.getElementById('modalSelectorLeg').classList.add('hidden');
        marcarAlterado();
    }

    function adicionarSeccaoTexto(titulo, conteudo, nivel) {
        var container = document.getElementById('seccoesContainer');
        var idx = seccaoCounter++;
        var result = criarSeccao(titulo, conteudo, idx, nivel || _pendingNivel);
        container.appendChild(result.block);
        var empty = document.getElementById('seccoesEmpty');
        if (empty) empty.remove();
        initSeccaoEditor(result.editorId);
        renumerarSeccoes();
    }

    function recolherDados() {
        var data = {
            id: especId,
            numero: document.getElementById('numero').value,
            titulo: document.getElementById('titulo').value,
            idioma: (document.getElementById('idioma') || {}).value || 'pt',
            versao: document.getElementById('versao').value,
            estado: document.getElementById('estado').value,
            tipo_doc: document.getElementById('tipo_doc').value,
            produto_ids: getCheckedValues('produtosWrap'),
            cliente_id: document.getElementById('cliente_id') ? document.getElementById('cliente_id').value : '',
            fornecedor_ids: document.getElementById('fornecedoresWrap') ? getCheckedValues('fornecedoresWrap') : [],
            data_emissao: document.getElementById('data_emissao').value,
            data_revisao: document.getElementById('data_revisao').value,
            data_validade: document.getElementById('data_validade').value,
            senha_publica: (document.getElementById('senha_publica') || {}).value || '',
            codigo_acesso: (document.getElementById('codigo_acesso') || {}).value || '',
            config_visual: JSON.stringify(recolherConfigVisual()),
            legenda_parametros: '',
            legenda_parametros_tamanho: 9,
            seccoes: [],
            parametros: []
        };

        // Legenda de parâmetros (do primeiro bloco de parâmetros encontrado)
        var legInput = document.querySelector('.param-legenda-text');
        var legTamInput = document.querySelector('.param-legenda-tam');
        if (legInput) data.legenda_parametros = legInput.value;
        if (legTamInput) data.legenda_parametros_tamanho = parseInt(legTamInput.value) || 9;

        // Secções dinâmicas (texto + ensaios)
        document.querySelectorAll('#seccoesContainer .seccao-block').forEach(function(block, i) {
            var titulo = block.querySelector('.seccao-titulo').value;
            var tipo = block.getAttribute('data-tipo') || 'texto';
            var conteudo = '';

            if (tipo === 'parametros' || tipo === 'parametros_custom') {
                // Recolher dados de parâmetros (genérico)
                var pcTbl = block.querySelector('.seccao-ensaios-table');
                var pcTbody = pcTbl ? pcTbl.querySelector('.ensaios-tbody') : null;
                var pcRows = [];
                var pcTipoId = block.getAttribute('data-tipo-id') || '';
                var pcTipoSlug = block.getAttribute('data-tipo-slug') || '';
                if (pcTbody) {
                    pcTbody.querySelectorAll('tr').forEach(function(tr) {
                        if (tr.getAttribute('data-cat') === '1') {
                            // Linha de categoria
                            var catInput = tr.querySelector('.cat-header-input');
                            pcRows.push({ _cat: catInput ? catInput.value : '' });
                        } else {
                            var row = {};
                            tr.querySelectorAll('textarea[data-field], input[data-field]').forEach(function(inp) {
                                row[inp.getAttribute('data-field')] = inp.value;
                            });
                            pcRows.push(row);
                        }
                    });
                }
                var pcColWidths = [];
                var pcTbl = block.querySelector('.seccao-ensaios-table');
                var pcThs = pcTbl ? pcTbl.querySelectorAll('thead th') : [];
                var pcTblW = pcTbl ? pcTbl.offsetWidth : 1;
                for (var pi = 0; pi < pcThs.length - 1; pi++) {
                    var w = parseFloat(pcThs[pi].style.width);
                    if (!w || isNaN(w)) w = (pcThs[pi].offsetWidth / pcTblW * 100);
                    pcColWidths.push(Math.round(w * 10) / 10);
                }
                var pcOrientacao = block.getAttribute('data-orientacao') || 'horizontal';
                var pcTable = block.querySelector('.seccao-ensaios-table');
                var pcMerges = pcTable ? getTableMerges(pcTable) : [];
                conteudo = JSON.stringify({ tipo_id: pcTipoId, tipo_slug: pcTipoSlug, colWidths: pcColWidths, rows: pcRows, orientacao: pcOrientacao, merges: pcMerges });
            } else if (tipo === 'ficheiros') {
                var posSelect = block.querySelector('.fic-posicao');
                var grupo = block.getAttribute('data-grupo') || 'default';
                conteudo = JSON.stringify({ posicao: posSelect ? posSelect.value : 'final', grupo: grupo });
            } else {
                var editorEl = block.querySelector('.seccao-editor');
                if (editorEl) {
                    conteudo = getEditorContent(editorEl.id);
                }
            }

            data.seccoes.push({
                titulo: titulo,
                conteudo: conteudo,
                tipo: tipo,
                ordem: i,
                nivel: parseInt(block.getAttribute('data-nivel')) || 1
            });
        });

        return data;
    }

    // ============================================================
    // GUARDAR (AJAX)
    // ============================================================
    function guardarTudo() {
        if (isSaving) return;
        isSaving = true;
        setSaveState('saving', 'A guardar...');

        var data = recolherDados();
        var action = IS_NEW && especId === 0 ? 'criar_especificacao' : 'atualizar_especificacao';

        // 1. Save main spec data
        apiPost({ action: action, data: data })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                var savedId = (result.data && result.data.id) || result.id || especId;
                if (savedId && especId === 0) {
                    especId = savedId;
                    window.history.replaceState(null, '', BASE_PATH + '/especificacao.php?id=' + especId);
                    // Mostrar botões Ver e PDF
                    var btnPdf = document.getElementById('btnPdf');
                    var btnVer = document.getElementById('btnVer');
                    if (btnPdf) { btnPdf.href = BASE_PATH + '/pdf.php?id=' + especId + '&view=1&t=' + Date.now(); btnPdf.style.display = ''; }
                    if (btnVer) { btnVer.href = BASE_PATH + '/ver.php?id=' + especId; btnVer.style.display = ''; }
                }

                // 2. Save sections and parameters
                var promises = [];

                // Save sections
                promises.push(
                    apiPost({
                            action: 'save_seccoes',
                            especificacao_id: especId,
                            seccoes: data.seccoes
                        }).then(function(r) { return checkSession(r); })
                );

                return Promise.all(promises).then(function() {
                    isSaving = false;
                    isDirty = false;
                    setSaveState('saved', 'Guardado');
                    atualizarEstadoPill();
                    showToast('Especificação guardada com sucesso.', 'success');
                });

            } else {
                isSaving = false;
                setSaveState('error', 'Erro ao guardar');
                showToast(result.error || result.message || 'Erro ao guardar.', 'error');
            }
        })
        .catch(function(err) {
            isSaving = false;
            if (err.message === 'SESSION_EXPIRED') return;
            setSaveState('error', 'Erro de ligação');
            showToast('Erro de ligação ao servidor.', 'error');
            console.error(err);
        });
    }

    // ============================================================
    // AUTO-SAVE COM DEBOUNCE
    // ============================================================
    function marcarAlterado() {
        isDirty = true;
        setSaveState('', 'Alterações pendentes');

        if (autoSaveTimer) clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(function() {
            if (isDirty && !isSaving) {
                guardarTudo();
            }
        }, 3000);
    }

    // Adicionar listeners a todos os campos editáveis
    document.querySelectorAll('input:not([readonly]), select, textarea').forEach(function(el) {
        el.addEventListener('input', marcarAlterado);
        el.addEventListener('change', marcarAlterado);
    });

    // ============================================================
    // ESTADO
    // ============================================================
    function alterarEstado(novoEstado) {
        document.getElementById('estado').value = novoEstado;
        atualizarEstadoPill();
        marcarAlterado();

        document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
            m.classList.remove('show');
        });
    }

    function atualizarEstadoPill() {
        var estado = document.getElementById('estado').value;
        var pill = document.getElementById('estadoPill');
        pill.className = 'pill';

        if (estado === 'ativo') {
            pill.classList.add('pill-success');
        } else if (estado === 'rascunho') {
            pill.classList.add('pill-warning');
        } else if (estado === 'em_revisao') {
            pill.classList.add('pill-info');
        } else {
            pill.classList.add('pill-muted');
        }
        pill.textContent = estado === 'em_revisao' ? 'Em Revisão' : (estado.charAt(0).toUpperCase() + estado.slice(1));
    }

    function submeterRevisao() {
        if (!especId) { showToast('Guarde a especificação primeiro.', 'warning'); return; }
        if (isDirty) { showToast('Guarde as alterações antes de submeter.', 'warning'); return; }
        // Abrir modal de seleção de admins
        document.getElementById('modalRevisao').classList.remove('hidden');
    }

    function confirmarSubmissaoRevisao() {
        var adminIds = [];
        document.querySelectorAll('.revisao-admin-cb:checked').forEach(function(cb) {
            adminIds.push(parseInt(cb.value));
        });
        document.getElementById('modalRevisao').classList.add('hidden');
        var baseUrl = window.location.origin + BASE_PATH;
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'submeter_revisao', id: especId, admin_ids: adminIds, base_url: baseUrl })
        })
        .then(function(r) { return checkSession(r); })
        .then(function(data) {
            if (data.success) {
                showToast(data.message || 'Submetida para revisão.', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showValidationErrors(data);
            }
        })
        .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') showToast('Erro de ligação.', 'error'); });
    }

    function showValidationErrors(data) {
        if (data.validation_errors && data.validation_errors.length > 0) {
            var msg = '<strong>' + (data.error || 'Documento incompleto:') + '</strong><ul style="margin:4px 0 0 16px;padding:0">';
            data.validation_errors.forEach(function(e) { msg += '<li>' + e + '</li>'; });
            msg += '</ul>';
            showToast(msg, 'danger', 8000);
        } else {
            showToast(data.error || 'Erro.', 'danger');
        }
    }

    function aprovarEspecificacao() {
        if (!especId) return;
        appConfirm('Aprovar esta especificação?<br>Após aprovação, pode ser publicada (bloqueada).', function() {
            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ action: 'aprovar_especificacao', id: especId })
            })
            .then(function(r) { return checkSession(r); })
            .then(function(data) {
                if (data.success) {
                    showToast('Especificação aprovada!', 'success');
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    showValidationErrors(data);
                }
            })
            .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') showToast('Erro de ligação.', 'error'); });
        });
    }

    function devolverEspecificacao() {
        if (!especId) return;
        var motivo = prompt('Motivo da devolução:');
        if (!motivo) return;
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'devolver_especificacao', id: especId, motivo: motivo })
        })
        .then(function(r) { return checkSession(r); })
        .then(function(data) {
            if (data.success) {
                showToast('Especificação devolvida ao autor.', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(data.error || 'Erro ao devolver.', 'danger');
            }
        })
        .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') showToast('Erro de ligação.', 'error'); });
    }

    // ============================================================
    // REMOVER LINHA (genérico)
    // ============================================================
    function removerLinha(btn) {
        var row = btn.closest('.param-row, .class-row, .defect-row');
        if (row) {
            row.remove();
            marcarAlterado();
        }
    }

    // ============================================================
    // MODAL ALERT
    // ============================================================
    var alertCallback = null;

    function mostrarAlerta(titulo, mensagem, showCancel, callback) {
        document.getElementById('alertTitle').textContent = titulo;
        document.getElementById('alertMessage').textContent = mensagem;
        document.getElementById('alertCancelBtn').style.display = showCancel ? '' : 'none';
        alertCallback = callback || null;
        document.getElementById('modalAlert').classList.remove('hidden');
    }

    function fecharModalAlert() {
        document.getElementById('modalAlert').classList.add('hidden');
        if (alertCallback) {
            alertCallback();
            alertCallback = null;
        }
    }

    // ============================================================
    // FICHEIROS - Secção no conteúdo
    // ============================================================
    function adicionarSeccaoFicheiros() {
        criarSeccaoFicheiros();
    }

    function criarSeccaoFicheiros(nivel, grupo) {
        nivel = nivel || _pendingNivel || 1;
        grupo = grupo || ('g' + Date.now() + Math.random().toString(36).substr(2, 4));
        var container = document.getElementById('seccoesContainer');
        var block = document.createElement('div');
        block.className = 'seccao-block';
        block.setAttribute('data-tipo', 'ficheiros');
        block.setAttribute('data-nivel', nivel);
        block.setAttribute('data-grupo', grupo);

        block.innerHTML =
            '<div class="seccao-header">' +
                '<span class="seccao-numero"></span>' +
                '<input type="text" class="seccao-titulo" value="Ficheiros Anexos" placeholder="Título">' +
                '<span class="pill pill-info" style="font-size:10px; padding:2px 8px;">Ficheiros</span>' +
                '<div class="seccao-actions">' +
                    '<button class="btn btn-ghost btn-sm seccao-collapse-btn" onclick="toggleCollapse(this)" title="Colapsar/Expandir">&minus;</button>' +
                    '<button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>' +
                    '<button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>' +
                    '<button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>' +
                '</div>' +
            '</div>' +
            '<div style="padding: var(--spacing-md);">' +
                '<div style="margin-bottom:12px; display:flex; align-items:center; gap:8px;">' +
                    '<label style="font-size:12px; font-weight:600; color:var(--color-text);">No PDF:</label>' +
                    '<select class="fic-posicao" style="font-size:12px; padding:4px 8px; border:1px solid var(--color-border); border-radius:4px;">' +
                        '<option value="local">Mostrar neste local</option>' +
                        '<option value="final">Mostrar no final do documento</option>' +
                    '</select>' +
                '</div>' +
                '<div class="upload-zone" style="cursor:pointer; padding:20px; border:2px dashed var(--color-border); border-radius:8px; text-align:center;">' +
                    '<div class="icon">&#128206;</div>' +
                    '<p><strong>Arraste ficheiros ou clique para selecionar</strong></p>' +
                    '<p class="muted" style="font-size:12px;">Máx. 50MB. Formatos: PDF, DOC, XLS, JPG, PNG</p>' +
                    '<input type="file" class="fic-file-input" multiple style="display:none" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.bmp,.tif,.tiff,.csv,.txt">' +
                '</div>' +
                '<div class="fic-progress hidden" style="margin-top:8px;">' +
                    '<div class="flex-between"><span class="muted fic-file-name">A enviar...</span><span class="muted fic-percent">0%</span></div>' +
                    '<div class="progress-bar-container"><div class="progress-bar-fill fic-bar" style="width:0%"></div></div>' +
                '</div>' +
                '<ul class="file-list fic-file-list" style="margin-top:8px;"></ul>' +
            '</div>';

        container.appendChild(block);
        var empty = document.getElementById('seccoesEmpty');
        if (empty) empty.remove();
        renumerarSeccoes();
        initUploadListeners(block);
        marcarAlterado();
        return block;
    }

    function initUploadListeners(block) {
        var uploadZone = block.querySelector('.upload-zone');
        var fileInput = block.querySelector('.fic-file-input');
        if (!uploadZone || !fileInput) return;

        uploadZone.addEventListener('click', function() { fileInput.click(); });
        uploadZone.addEventListener('dragover', function(e) { e.preventDefault(); e.stopPropagation(); uploadZone.classList.add('dragover'); });
        uploadZone.addEventListener('dragleave', function(e) { e.preventDefault(); e.stopPropagation(); uploadZone.classList.remove('dragover'); });
        uploadZone.addEventListener('drop', function(e) {
            e.preventDefault(); e.stopPropagation(); uploadZone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) enviarFicheiros(e.dataTransfer.files, block);
        });
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) { enviarFicheiros(this.files, block); this.value = ''; }
        });
    }

    function enviarFicheiros(files, block) {
        if (especId === 0) {
            showToast('Guarde a especificação antes de anexar ficheiros.', 'warning');
            return;
        }
        for (var i = 0; i < files.length; i++) enviarFicheiro(files[i], block);
    }

    function enviarFicheiro(file, block) {
        var progressEl = block.querySelector('.fic-progress');
        var barEl = block.querySelector('.fic-bar');
        var nameEl = block.querySelector('.fic-file-name');
        var percentEl = block.querySelector('.fic-percent');
        var grupo = block.getAttribute('data-grupo') || 'default';

        progressEl.classList.remove('hidden');
        nameEl.textContent = file.name;
        percentEl.textContent = '0%';
        barEl.style.width = '0%';

        var formData = new FormData();
        formData.append('action', 'upload_ficheiro');
        formData.append('especificacao_id', especId);
        formData.append('grupo', grupo);
        formData.append('ficheiro', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', BASE_PATH + '/api.php', true);
        xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                barEl.style.width = pct + '%';
                percentEl.textContent = pct + '%';
            }
        });

        xhr.addEventListener('load', function() {
            progressEl.classList.add('hidden');
            try {
                var result = JSON.parse(xhr.responseText);
                if (result.success) {
                    adicionarFicheiroLista(result.data, block);
                    showToast('Ficheiro "' + file.name + '" enviado.', 'success');
                } else {
                    showToast(result.error || result.message || 'Erro ao enviar ficheiro.', 'error');
                }
            } catch (e) {
                showToast('Erro ao processar resposta do servidor.', 'error');
            }
        });

        xhr.addEventListener('error', function() {
            progressEl.classList.add('hidden');
            showToast('Erro de ligação ao enviar ficheiro.', 'error');
        });

        xhr.send(formData);
    }

    function adicionarFicheiroLista(ficheiro, block) {
        var list = block.querySelector('.fic-file-list');
        var li = document.createElement('li');
        li.className = 'file-item';
        li.setAttribute('data-file-id', ficheiro.id);
        li.innerHTML =
            '<span class="file-name" title="' + escapeHtml(ficheiro.nome_original) + '">&#128196; ' + escapeHtml(ficheiro.nome_original) + '</span>' +
            '<span class="file-size">' + formatFileSize(ficheiro.tamanho) + '</span>' +
            '<span class="muted">' + (ficheiro.data || 'Agora') + '</span>' +
            '<div class="flex gap-sm" style="margin-left:auto;">' +
                '<a href="' + BASE_PATH + '/download.php?id=' + ficheiro.id + '" class="btn btn-ghost btn-sm" title="Descarregar">&#11015;</a>' +
                '<button class="btn btn-danger btn-sm" onclick="removerFicheiro(' + ficheiro.id + ')" title="Remover">&times;</button>' +
            '</div>';
        list.appendChild(li);
    }

    function removerFicheiro(id) {
        appConfirmDanger('Tem a certeza que deseja remover este ficheiro?', function() {
            apiPost({ action: 'remover_ficheiro', id: id })
            .then(function(r) { return checkSession(r); })
            .then(function(result) {
                if (result.success) {
                    var el = document.querySelector('[data-file-id="' + id + '"]');
                    if (el) el.remove();
                    showToast('Ficheiro removido.', 'success');
                } else {
                    showToast(result.message || 'Erro ao remover ficheiro.', 'error');
                }
            })
            .catch(function() {
                showToast('Erro de ligação.', 'error');
            });
        });
    }

    function formatFileSize(bytes) {
        bytes = parseInt(bytes) || 0;
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' B';
    }

    // ============================================================
    // PARTILHA - CODIGO DE ACESSO / COPIAR LINK
    // ============================================================
    function gerarCodigoAcesso() {
        var chars = 'ABCDEF0123456789';
        var code = '';
        for (var i = 0; i < 8; i++) {
            code += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('codigo_acesso').value = code;
        marcarAlterado();
        showToast('Código de acesso gerado: ' + code, 'success');

        atualizarShareLink(code);
    }

    function getBaseUrl() {
        var origin = window.location.origin;
        if (origin.indexOf('localhost') === -1) {
            origin = origin.replace('http://', 'https://').replace('www.', '');
        }
        return origin + BASE_PATH;
    }

    function atualizarShareLink(code) {
        var linkInput = document.getElementById('shareLink');
        if (linkInput) {
            linkInput.value = getBaseUrl() + '/publico.php?code=' + code;
        }
    }

    function copiarLink() {
        var linkInput = document.getElementById('shareLink');
        if (!linkInput || !linkInput.value) return;
        var text = linkInput.value;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                showToast('Link copiado!', 'success');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('Link copiado!', 'success');
        }
    }

    // ============================================================
    // PREVIEW REAL
    // ============================================================
    function abrirPreviewReal() {
        if (especId === 0) {
            showToast('Guarde a especificação primeiro.', 'warning');
            return;
        }
        if (isDirty) {
            showToast('Tem alterações por guardar. A abrir com a última versão guardada.', 'info');
        }
        window.open(BASE_PATH + '/ver.php?id=' + especId, '_blank');
    }

    // ============================================================
    // EMAIL - ABRIR NO CLIENTE
    // ============================================================
    function abrirEmailCliente() {
        var assunto = document.getElementById('email_assunto').value.trim();
        var mensagem = document.getElementById('email_mensagem').value.trim();
        var incluirLink = document.getElementById('email_incluir_link').checked;

        if (!assunto) assunto = 'Caderno de Encargos';

        // Determinar emails reais
        var allEmails = [];
        if (emailUsaBcc) {
            allEmails = emailDataForn.concat(emailDataCli);
        } else {
            var manual = document.getElementById('email_destinatario').value.trim();
            if (manual) allEmails = manual.split(',').map(function(e){ return e.trim(); });
        }

        if (!allEmails.length) {
            showToast('Introduza o email do destinatário.', 'warning');
            document.getElementById('email_destinatario').focus();
            return;
        }

        var corpo = '';
        if (mensagem) corpo += mensagem + '\n\n';

        if (incluirLink) {
            var code = (document.getElementById('codigo_acesso') || {}).value || '';
            if (code) {
                var link = window.location.origin + BASE_PATH + '/publico.php?code=' + code;
                corpo += 'Pode consultar o documento no seguinte link:\n' + link + '\n\n';
            } else {
                showToast('Gere um código de acesso primeiro (tab Partilha) para incluir o link.', 'warning');
                return;
            }
        }

        corpo += 'Com os melhores cumprimentos.';

        var mailto;
        if (emailUsaBcc) {
            mailto = 'mailto:?bcc=' + encodeURIComponent(allEmails.join(','))
                + '&subject=' + encodeURIComponent(assunto)
                + '&body=' + encodeURIComponent(corpo);
        } else {
            mailto = 'mailto:' + encodeURIComponent(allEmails.join(','))
                + '?subject=' + encodeURIComponent(assunto)
                + '&body=' + encodeURIComponent(corpo);
        }

        window.location.href = mailto;
        showToast('A abrir o seu programa de email...', 'info');
    }

    // ============================================================
    // EMAIL - ENVIAR VIA SERVIDOR (SMTP)
    // ============================================================
    function enviarEmailEspec() {
        var destinatario = document.getElementById('email_destinatario').value.trim();
        var assunto = document.getElementById('email_assunto').value.trim();
        var mensagem = document.getElementById('email_mensagem').value.trim();
        var incluirLink = document.getElementById('email_incluir_link').checked;

        if (!destinatario) {
            showToast('Introduza o email do destinatário.', 'warning');
            document.getElementById('email_destinatario').focus();
            return;
        }

        if (especId === 0) {
            showToast('Guarde a especificação antes de enviar email.', 'warning');
            return;
        }

        var btn = document.getElementById('btnEnviarEmail');
        var status = document.getElementById('emailStatus');
        btn.disabled = true;
        btn.textContent = 'A enviar...';
        status.textContent = '';

        apiPost({
                action: 'enviar_email',
                especificacao_id: especId,
                destinatario: destinatario,
                assunto: assunto,
                mensagem: mensagem,
                incluir_link: incluirLink ? 1 : 0
            })
        .then(function(r) { return checkSession(r); })
        .then(function(result) {
            btn.disabled = false;
            btn.textContent = 'Enviar Email';
            if (result.success) {
                showToast('Email enviado com sucesso!', 'success');
                status.textContent = 'Enviado com sucesso';
                status.style.color = 'var(--color-success)';
            } else {
                showToast(result.error || 'Erro ao enviar email.', 'error');
                status.textContent = result.error || 'Erro ao enviar';
                status.style.color = 'var(--color-error)';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Enviar Email';
            showToast('Erro de ligação ao servidor.', 'error');
            status.textContent = 'Erro de ligação';
            status.style.color = 'var(--color-error)';
        });
    }

    // Inicializar share link se código existe
    (function() {
        var el = document.getElementById('codigo_acesso');
        if (el && el.value) {
            atualizarShareLink(el.value);
        }
    })();

    // ============================================================
    // PREVIEW
    // ============================================================
    function atualizarPreview() {
        // Meta
        document.getElementById('prevNumero').textContent = document.getElementById('numero').value || '-';
        document.getElementById('prevVersao').textContent = document.getElementById('versao').value || '-';

        document.getElementById('prevProduto').textContent = getMultiSelectText('produtosWrap') || '-';

        var cliSelect = document.getElementById('cliente_id');
        var prevCliente = document.getElementById('prevCliente');
        if (cliSelect && prevCliente) {
            prevCliente.textContent = cliSelect.options[cliSelect.selectedIndex] ? cliSelect.options[cliSelect.selectedIndex].text : '-';
        }

        var prevFornecedor = document.getElementById('prevFornecedor');
        if (prevFornecedor) {
            prevFornecedor.textContent = document.getElementById('fornecedoresWrap') ? (getMultiSelectText('fornecedoresWrap') || 'Todos') : 'Todos';
        }

        var dataEmissao = document.getElementById('data_emissao').value;
        if (dataEmissao) {
            var parts = dataEmissao.split('-');
            document.getElementById('prevData').textContent = parts[2] + '/' + parts[1] + '/' + parts[0];
        } else {
            document.getElementById('prevData').textContent = '-';
        }

        var estado = document.getElementById('estado').value;
        document.getElementById('prevEstado').textContent = estado.charAt(0).toUpperCase() + estado.slice(1);

        // Título
        document.getElementById('prevTitulo').textContent = document.getElementById('titulo').value || 'Título da Especificação';

        // Secções dinâmicas (texto + ensaios)
        var sectionsHtml = '';
        // Numeração hierárquica para preview
        var prevMainC = 0, prevSubC = 0;
        var prevBlocks = document.querySelectorAll('#seccoesContainer .seccao-block');
        var prevNums = [];
        prevBlocks.forEach(function(b) {
            var niv = parseInt(b.getAttribute('data-nivel') || '1');
            if (niv === 1) { prevMainC++; prevSubC = 0; prevNums.push(prevMainC + '.'); }
            else { prevSubC++; prevNums.push(prevMainC + '.' + prevSubC + '.'); }
        });
        prevBlocks.forEach(function(block, i) {
            var titulo = block.querySelector('.seccao-titulo').value || ('Secção ' + (i + 1));
            var tipo = block.getAttribute('data-tipo') || 'texto';
            var nivel = parseInt(block.getAttribute('data-nivel') || '1');
            var secNum = prevNums[i] || (i + 1) + '.';
            var hColor = nivel === 2 ? (configVisual.cor_subtitulos || configVisual.cor_titulos) : configVisual.cor_titulos;
            var hSize = nivel === 2 ? (configVisual.tamanho_subtitulos || configVisual.tamanho_titulos) : configVisual.tamanho_titulos;
            var hWeight = nivel === 2 ? ((configVisual.subtitulos_bold === '1') ? 'bold' : 'normal') : 'bold';
            var hMargin = nivel === 2 ? ' margin-left:12px;' : '';

            if (tipo === 'ficheiros') {
                // Mostrar lista de ficheiros no preview
                var fileList = block.querySelector('.fic-file-list');
                var fileItems = fileList ? fileList.querySelectorAll('.file-item') : [];
                sectionsHtml += '<h4 style="color:' + hColor + '; border-bottom-color:' + configVisual.cor_linhas + '; font-size:' + hSize + 'pt; font-weight:' + hWeight + ';' + hMargin + '">' + secNum + ' ' + escapeHtml(titulo) + '</h4>';
                if (fileItems.length > 0) {
                    sectionsHtml += '<div style="font-size:9px; margin-bottom:8px;">';
                    fileItems.forEach(function(fi) {
                        var fname = fi.querySelector('.file-name');
                        sectionsHtml += '<div style="padding:2px 0;">&#128196; ' + (fname ? escapeHtml(fname.textContent) : 'Ficheiro') + '</div>';
                    });
                    sectionsHtml += '</div>';
                } else {
                    sectionsHtml += '<div style="font-size:9px; color:#999; margin-bottom:8px;">Sem ficheiros anexados</div>';
                }
            } else if (tipo === 'parametros' || tipo === 'parametros_custom') {
                var tbl = block.querySelector('.seccao-ensaios-table');
                var tbody2 = tbl ? tbl.querySelector('.ensaios-tbody') : null;
                if (tbody2 && tbl) {
                    var ths = tbl.querySelectorAll('thead th');
                    var nCols = ths.length - 1;
                    if (nCols > 0) {
                        sectionsHtml += '<h4 style="color:' + hColor + '; font-size:' + hSize + 'pt; font-weight:' + hWeight + ';' + hMargin + '">' + secNum + ' ' + escapeHtml(titulo) + '</h4>';
                        sectionsHtml += '<table style="width:100%; font-size:9px; border-collapse:collapse; margin-bottom:8px;' + hMargin + '"><thead><tr>';
                        for (var ci = 0; ci < nCols; ci++) {
                            sectionsHtml += '<th style="padding:3px 4px; text-align:left; font-weight:600; background-color:' + configVisual.cor_titulos + '; color:white;">' + escapeHtml(ths[ci].textContent.trim()) + '</th>';
                        }
                        sectionsHtml += '</tr></thead><tbody>';
                        tbody2.querySelectorAll('tr').forEach(function(tr2) {
                            if (tr2.classList.contains('cat-header-row') || tr2.getAttribute('data-cat') === '1') {
                                var catInput = tr2.querySelector('.cat-header-input');
                                sectionsHtml += '<tr><td colspan="' + nCols + '" style="background-color:' + orgCores.light + '; font-weight:600; padding:3px 6px; color:' + orgCores.dark + '; text-align:center;">' + escapeHtml(catInput ? catInput.value : '') + '</td></tr>';
                            } else {
                                sectionsHtml += '<tr>';
                                tr2.querySelectorAll('textarea[data-field]').forEach(function(ta) {
                                    sectionsHtml += '<td style="padding:2px 4px; border-bottom:1px solid #eee;">' + escapeHtml(ta.value) + '</td>';
                                });
                                sectionsHtml += '</tr>';
                            }
                        });
                        sectionsHtml += '</tbody></table>';
                    }
                }
            } else {
                var editorEl = block.querySelector('.seccao-editor');
                if (editorEl) {
                    var conteudo = getEditorContent(editorEl.id);
                    if (conteudo && conteudo.trim()) {
                        sectionsHtml += '<h4 style="color:' + hColor + '; border-bottom-color:' + configVisual.cor_linhas + '; font-size:' + hSize + 'pt; font-weight:' + hWeight + ';' + hMargin + '">' + secNum + ' ' + escapeHtml(titulo) + '</h4>';
                        sectionsHtml += '<div class="preview-section-content" style="' + hMargin + '">' + conteudo + '</div>';
                    }
                }
            }
        });
        document.getElementById('previewSections').innerHTML = sectionsHtml;

        // Parâmetros do banco (não aparecem no preview - são apenas banco de dados)
        document.getElementById('previewParams').innerHTML = '';

        // Classes
        var classRowEls = document.querySelectorAll('#classRows .class-row');
        var classesHtml = '';
        if (classRowEls.length > 0) {
            classesHtml = '<h4>Classes Visuais</h4>';
            classesHtml += '<table><thead><tr><th>Classe</th><th>Defeitos Máx.</th><th>Descrição</th></tr></thead><tbody>';
            classRowEls.forEach(function(row) {
                var inputs = row.querySelectorAll('input');
                if (inputs.length >= 3 && inputs[0].value) {
                    classesHtml += '<tr>';
                    classesHtml += '<td><strong>' + escapeHtml(inputs[0].value) + '</strong></td>';
                    classesHtml += '<td>' + escapeHtml(inputs[1].value) + '%</td>';
                    classesHtml += '<td>' + escapeHtml(inputs[2].value) + '</td>';
                    classesHtml += '</tr>';
                }
            });
            classesHtml += '</tbody></table>';
        }
        document.getElementById('previewClasses').innerHTML = classesHtml;

        // Defeitos
        var defectsHtml = '';
        var hasDefects = false;
        ['Critico', 'Maior', 'Menor'].forEach(function(sev) {
            var rows = document.querySelectorAll('#defectRows' + sev + ' .defect-row');
            if (rows.length > 0) {
                if (!hasDefects) {
                    defectsHtml = '<h4>Defeitos</h4>';
                    hasDefects = true;
                }
                var label = sev === 'Critico' ? 'Críticos' : (sev === 'Maior' ? 'Maiores' : 'Menores');
                defectsHtml += '<p style="font-weight: 600; font-size: var(--font-size-xs); margin: var(--spacing-sm) 0 var(--spacing-xs); color: var(--color-text-secondary);">' + label + '</p>';
                rows.forEach(function(row) {
                    var inputs = row.querySelectorAll('input');
                    if (inputs.length >= 2 && inputs[0].value) {
                        defectsHtml += '<p style="margin: 2px 0; font-size: var(--font-size-xs);"><strong>' + escapeHtml(inputs[0].value) + ':</strong> ' + escapeHtml(inputs[1].value) + '</p>';
                    }
                });
            }
        });
        document.getElementById('previewDefects').innerHTML = defectsHtml;
    }

    // ============================================================
    // UTILIDADES
    // ============================================================
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // ============================================================
    // INICIALIZAÇÃO
    // ============================================================
    // Atualizar preview ao carregar
    atualizarPreview();

    // Atualizar preview quando houver alterações (com debounce)
    var previewTimer = null;
    document.querySelectorAll('input:not([readonly]), select, textarea').forEach(function(el) {
        el.addEventListener('input', function() {
            if (previewTimer) clearTimeout(previewTimer);
            previewTimer = setTimeout(atualizarPreview, 500);
        });
        el.addEventListener('change', function() {
            if (previewTimer) clearTimeout(previewTimer);
            previewTimer = setTimeout(atualizarPreview, 200);
        });
    });

    // Inicializar listeners de upload para todas as secções ficheiros
    (function() {
        document.querySelectorAll('.seccao-block[data-tipo="ficheiros"]').forEach(function(block) {
            initUploadListeners(block);
        });
    })();

    // Carregar ficheiros existentes nas secções certas (por grupo)
    <?php
    if (!empty($espec['ficheiros'])):
        // Agrupar ficheiros por grupo
        $ficPorGrupo = [];
        foreach ($espec['ficheiros'] as $f) {
            $g = $f['grupo'] ?? 'default';
            $ficPorGrupo[$g][] = $f;
        }
        // Se há ficheiros sem secção (grupo default sem secção), criar secção auto
        $temGrupoDefault = false;
        if (!empty($espec['seccoes'])) {
            foreach ($espec['seccoes'] as $sec) {
                if (($sec['tipo'] ?? '') === 'ficheiros') {
                    $conf = json_decode($sec['conteudo'] ?? '{}', true);
                    if (($conf['grupo'] ?? 'default') === 'default') $temGrupoDefault = true;
                }
            }
        }
    ?>
    (function() {
        <?php if (!empty($ficPorGrupo['default']) && !$temGrupoDefault): ?>
        // Ficheiros antigos sem secção - criar secção auto
        var autoBlock = criarSeccaoFicheiros(1, 'default');
        <?php endif; ?>

        <?php foreach ($ficPorGrupo as $grupo => $files): ?>
        (function() {
            var block = document.querySelector('.seccao-block[data-tipo="ficheiros"][data-grupo="<?= sanitize($grupo) ?>"]');
            if (!block) return;
            var list = block.querySelector('.fic-file-list');
            if (!list) return;
            <?php foreach ($files as $f): ?>
            (function() {
                var li = document.createElement('li');
                li.className = 'file-item';
                li.setAttribute('data-file-id', '<?= $f['id'] ?>');
                li.innerHTML =
                    '<span class="file-name" title="<?= sanitize($f['nome_original']) ?>">&#128196; <?= sanitize($f['nome_original']) ?></span>' +
                    '<span class="file-size"><?= formatFileSize($f['tamanho'] ?? 0) ?></span>' +
                    '<span class="muted"><?= formatDate($f['uploaded_at'] ?? '') ?></span>' +
                    '<div class="flex gap-sm" style="margin-left:auto;">' +
                        '<a href="<?= BASE_PATH ?>/download.php?id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm" title="Descarregar">&#11015;</a>' +
                        '<button class="btn btn-danger btn-sm" onclick="removerFicheiro(<?= $f['id'] ?>)" title="Remover">&times;</button>' +
                    '</div>';
                list.appendChild(li);
            })();
            <?php endforeach; ?>
        })();
        <?php endforeach; ?>
        isDirty = false;
        if (typeof atualizarPreview === 'function') atualizarPreview();
    })();
    <?php endif; ?>

    // Modal personalizado para navegação (sem beforeunload nativo)
    var _unsavedPendingUrl = null;

    function verificarSaidaPagina(url) {
        if (!isDirty || versaoBloqueada) return true;
        _unsavedPendingUrl = url || null;
        var m = document.getElementById('unsavedModal');
        m.classList.remove('hidden');
        return false;
    }

    function fecharUnsavedModal() {
        var m = document.getElementById('unsavedModal');
        m.classList.add('hidden');
        _unsavedPendingUrl = null;
    }

    function confirmarSairSemGuardar() {
        isDirty = false;
        var m = document.getElementById('unsavedModal');
        m.classList.add('hidden');
        if (_unsavedPendingUrl) {
            window.location.href = _unsavedPendingUrl;
        } else {
            history.back();
        }
    }

    // Interceptar botão voltar do browser
    history.pushState(null, '', location.href);
    window.addEventListener('popstate', function(e) {
        if (isDirty && !versaoBloqueada) {
            history.pushState(null, '', location.href);
            verificarSaidaPagina(null);
        }
    });

    // Interceptar TODOS os links da página (header, nav, breadcrumbs, etc.)
    document.addEventListener('click', function(e) {
        if (versaoBloqueada || !isDirty) return;
        var link = e.target.closest('a[href]');
        if (!link) return;
        var href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        if (link.target === '_blank') return;
        if (link.getAttribute('onclick')) return; // já tem handler próprio (ex: Voltar)
        e.preventDefault();
        verificarSaidaPagina(href);
    });

    // Atalho de teclado: Ctrl+S para guardar
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            guardarTudo();
        }
    });

    // ============================================================
    // VERSIONAMENTO
    // ============================================================
    var versaoBloqueada = <?= $versaoBloqueada ? 'true' : 'false' ?>;
    var saOutraOrg = <?= $saOutraOrg ? 'true' : 'false' ?>;

    function publicarVersaoUI() {
        if (!especId) { showToast('Guarde a especificação primeiro.', 'warning'); return; }
        if (isDirty) { showToast('Guarde as alterações antes de publicar.', 'warning'); return; }
        var m = document.getElementById('publicarModal');
        m.classList.remove('hidden');
        document.getElementById('publicarNotas').value = '';
        document.getElementById('publicarNotas').focus();
    }
    function confirmarPublicar() {
        var notas = document.getElementById('publicarNotas').value;
        var m = document.getElementById('publicarModal');
        m.classList.add('hidden');
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'publicar_versao', id: especId, notas: notas })
        })
        .then(function(r) { return checkSession(r); })
        .then(function(data) {
            if (data.success) {
                isDirty = false;
                showToast('Versão publicada com sucesso!', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showValidationErrors(data);
            }
        })
        .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') showToast('Erro de ligação.', 'error'); });
    }

    function criarNovaVersaoUI() {
        if (!especId) return;
        appConfirm('Criar uma nova versão editável a partir desta?<br>A versão atual mantém-se bloqueada.', function() {
            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ action: 'nova_versao', id: especId })
            })
            .then(function(r) { return checkSession(r); })
            .then(function(data) {
                if (data.success && data.novo_id) {
                    showToast('Nova versão criada!', 'success');
                    setTimeout(function() {
                        window.location.href = BASE_PATH + '/especificacao.php?id=' + data.novo_id;
                    }, 600);
                } else {
                    showToast(data.error || 'Erro ao criar nova versão.', 'danger');
                }
            })
            .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') showToast('Erro de rede.', 'danger'); });
        }, 'Nova Versão');
    }

    function compararVersoes(outroId, outraVersao) {
        var m = document.getElementById('diffModal');
        m.classList.remove('hidden');
        document.getElementById('diffModalTitle').textContent = 'Comparar: v' + '<?= sanitize($espec['versao']) ?>' + ' vs v' + outraVersao;
        document.getElementById('diffModalBody').innerHTML = '<p class="muted">A carregar diferenças...</p>';

        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'comparar_versoes', id1: especId, id2: outroId })
        })
        .then(function(r) { return checkSession(r); })
        .then(function(data) {
            if (!data.success) { document.getElementById('diffModalBody').innerHTML = '<p class="text-danger">' + (data.error || 'Erro') + '</p>'; return; }
            if (data.total === 0) {
                document.getElementById('diffModalBody').innerHTML = '<p class="muted" style="text-align:center;padding:var(--spacing-lg);">Sem diferenças entre as duas versões.</p>';
                return;
            }
            var html = '<table class="table" style="font-size:13px;"><thead><tr><th>Campo</th><th style="width:40%;">v' + data.v1.versao + ' (atual)</th><th style="width:40%;">v' + data.v2.versao + '</th></tr></thead><tbody>';
            data.diferencas.forEach(function(d) {
                var v1Short = (d.v1 || '').substring(0, 300) + (d.v1 && d.v1.length > 300 ? '...' : '');
                var v2Short = (d.v2 || '').substring(0, 300) + (d.v2 && d.v2.length > 300 ? '...' : '');
                html += '<tr><td><strong>' + d.campo + '</strong></td>';
                html += '<td style="background:#fef2f2;word-break:break-word;">' + escapeHtml(v1Short) + '</td>';
                html += '<td style="background:#f0fdf4;word-break:break-word;">' + escapeHtml(v2Short) + '</td></tr>';
            });
            html += '</tbody></table><p class="muted" style="margin-top:var(--spacing-sm);">' + data.total + ' diferença(s) encontrada(s).</p>';
            document.getElementById('diffModalBody').innerHTML = html;
        })
        .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') document.getElementById('diffModalBody').innerHTML = '<p class="text-danger">Erro de ligação.</p>'; });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ============================================================
    // TRADUÇÃO COM IA
    // ============================================================
    function traduzirEspecificacao() {
        if (!especId) { showToast('Guarde a especificação primeiro.', 'warning'); return; }
        var idiomas = {'en':'Inglês','es':'Espanhol','fr':'Francês','de':'Alemão','it':'Italiano','pt':'Português'};
        var idiomaAtual = document.getElementById('idioma') ? document.getElementById('idioma').value : 'pt';
        var opcoes = [];
        for (var k in idiomas) {
            if (k !== idiomaAtual) opcoes.push(k + ' = ' + idiomas[k]);
        }
        var escolha = prompt('Traduzir para qual idioma?\n\n' + opcoes.join('\n') + '\n\nEscreva o código (ex: en):');
        if (!escolha || !idiomas[escolha.trim().toLowerCase()]) {
            if (escolha) showToast('Idioma inválido.', 'warning');
            return;
        }
        var idiomaDestino = escolha.trim().toLowerCase();
        showToast('A traduzir com IA... pode demorar até 1 minuto.', 'info');
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'traduzir_especificacao', especificacao_id: especId, idioma_destino: idiomaDestino })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                showToast('Tradução criada! A abrir...', 'success');
                setTimeout(function() {
                    window.open(BASE_PATH + '/especificacao.php?id=' + d.data.nova_id, '_blank');
                }, 500);
            } else {
                showToast(d.error || 'Erro na tradução.', 'error');
            }
        })
        .catch(function() { showToast('Erro de ligação.', 'error'); });
    }

    // ============================================================
    // TEMPLATES
    // ============================================================
    function guardarComoTemplate() {
        if (!especId) { showToast('Guarde a especificação primeiro.', 'warning'); return; }
        var html = '<div style="text-align:left; font-size:14px;">';
        html += '<p style="color:#666; margin-bottom:16px;">Guarda a estrutura e conteúdo desta especificação como modelo reutilizável. Ao criar uma nova especificação, poderá aplicar este template para pré-preencher as secções automaticamente.</p>';
        html += '<div style="margin-bottom:12px;"><label style="font-weight:600; display:block; margin-bottom:4px;">Nome do template *</label>';
        html += '<input type="text" id="tpl_nome" placeholder="Ex: Template Embalagens" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;"></div>';
        html += '<div><label style="font-weight:600; display:block; margin-bottom:4px;">Descrição (opcional)</label>';
        html += '<input type="text" id="tpl_desc" placeholder="Breve descrição" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;"></div>';
        html += '</div>';
        appConfirm(html, function() {
            var nome = (document.getElementById('tpl_nome') || {}).value;
            if (!nome || !nome.trim()) { showToast('Preencha o nome do template.', 'warning'); return; }
            var descricao = (document.getElementById('tpl_desc') || {}).value || '';
            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ action: 'save_template', especificacao_id: especId, nome: nome.trim(), descricao: descricao.trim() })
            })
            .then(function(r) { return checkSession(r); })
            .then(function(data) {
                if (data.success) showToast('Template guardado!', 'success');
                else showToast(data.error || 'Erro.', 'danger');
            })
            .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') showToast('Erro de ligação.', 'error'); });
        }, 'Guardar Template');
    }

    <?php if ($isNew): ?>
    // Carregar lista de templates ao abrir nova spec
    (function() {
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'list_templates' })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data && data.data.length > 0) {
                var sel = document.getElementById('templateSelect');
                data.data.forEach(function(t) {
                    var opt = document.createElement('option');
                    opt.value = t.id;
                    opt.textContent = t.nome + (t.descricao ? ' — ' + t.descricao : '');
                    sel.appendChild(opt);
                });
            } else {
                var card = document.getElementById('templateSelector');
                if (card) card.style.display = 'none';
            }
        })
        .catch(function() {});
    })();

    function eliminarTemplateSelecionado() {
        var sel = document.getElementById('templateSelect');
        var tplId = sel.value;
        if (!tplId) { showToast('Selecione um template primeiro.', 'warning'); return; }
        var nome = sel.options[sel.selectedIndex].text;
        appConfirm('Eliminar o template "' + nome + '"? Esta ação não pode ser desfeita.', function() {
            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ action: 'delete_template', template_id: parseInt(tplId) })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    sel.remove(sel.selectedIndex);
                    sel.value = '';
                    showToast('Template eliminado.', 'success');
                    if (sel.options.length <= 1) {
                        var card = document.getElementById('templateSelector');
                        if (card) card.style.display = 'none';
                    }
                } else {
                    showToast(data.error || 'Erro.', 'danger');
                }
            })
            .catch(function() { showToast('Erro de ligação.', 'danger'); });
        }, 'Eliminar');
    }

    function carregarTemplate() {
        var tplId = document.getElementById('templateSelect').value;
        if (!tplId) return;
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'get_template', template_id: parseInt(tplId) })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.data) { showToast(data.error || 'Erro.', 'danger'); return; }
            var d = data.data.dados;
            // Preencher campos de texto
            if (d.titulo) document.getElementById('titulo').value = d.titulo;
            var campos = ['objetivo', 'ambito', 'definicao_material', 'regulamentacao', 'processos', 'embalagem', 'aceitacao', 'observacoes'];
            campos.forEach(function(c) {
                var el = document.getElementById(c);
                if (el && d[c]) el.value = d[c];
            });
            // Preencher secções (limpar existentes e criar novas)
            if (d.seccoes && d.seccoes.length > 0) {
                var container = document.getElementById('seccoesContainer');
                container.innerHTML = '';
                d.seccoes.forEach(function(sec) {
                    adicionarSeccao(sec.tipo || 'texto', sec.titulo, sec.conteudo);
                });
            }
            showToast('Template aplicado!', 'success');
            marcarAlterado();
            document.getElementById('templateSelector').style.display = 'none';
        })
        .catch(function() { showToast('Erro de ligação.', 'error'); });
    }
    <?php endif; ?>

    // --- COMENTÁRIOS ---
    function carregarComentarios() {
        if (!especId) return;
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'list_comentarios', especificacao_id: especId })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return;
            var lista = document.getElementById('listaComentarios');
            var cnt = document.getElementById('comentariosCount');
            if (!lista) return;
            var items = d.comentarios || [];
            cnt.textContent = items.length ? items.length + ' comentário' + (items.length > 1 ? 's' : '') : '';
            if (!items.length) {
                lista.innerHTML = '<p class="muted" style="font-size:13px;">Sem comentários.</p>';
                return;
            }
            var html = '';
            items.forEach(function(c) {
                var dt = new Date(c.created_at);
                var dataStr = dt.toLocaleDateString('pt-PT') + ' ' + dt.toLocaleTimeString('pt-PT', {hour:'2-digit',minute:'2-digit'});
                html += '<div style="border-bottom:1px solid var(--color-border);padding:var(--spacing-sm) 0;">';
                html += '<div style="display:flex;justify-content:space-between;align-items:center;">';
                html += '<strong style="font-size:13px;">' + escapeHtml(c.nome_utilizador) + '</strong>';
                html += '<span class="muted" style="font-size:11px;">' + dataStr + '</span>';
                html += '</div>';
                html += '<p style="margin:4px 0 0;font-size:13px;white-space:pre-wrap;">' + escapeHtml(c.comentario) + '</p>';
                if (c.pode_apagar) {
                    html += '<button class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--color-danger);padding:2px 6px;margin-top:2px;" onclick="apagarComentario(' + c.id + ')">Apagar</button>';
                }
                html += '</div>';
            });
            lista.innerHTML = html;
        });
    }
    function adicionarComentario() {
        var ta = document.getElementById('novoComentario');
        var texto = ta.value.trim();
        if (!texto) { showToast('Escreva um comentário.', 'warning'); return; }
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'add_comentario', especificacao_id: especId, comentario: texto })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) { ta.value = ''; carregarComentarios(); showToast('Comentário adicionado.', 'success'); }
            else showToast(d.error || 'Erro.', 'error');
        })
        .catch(function() { showToast('Erro de ligação.', 'error'); });
    }
    function apagarComentario(id) {
        if (!confirm('Apagar este comentário?')) return;
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'delete_comentario', comentario_id: id })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) { carregarComentarios(); showToast('Comentário apagado.', 'success'); }
            else showToast(d.error || 'Erro.', 'error');
        })
        .catch(function() { showToast('Erro de ligação.', 'error'); });
    }
    function escapeHtml(t) {
        var d = document.createElement('div'); d.textContent = t; return d.innerHTML;
    }

    function reloadToTab(tab) {
        if (isDirty && !versaoBloqueada) {
            if (!confirm('Tem alterações por guardar. Deseja continuar?')) return;
            isDirty = false;
        }
        var url = window.location.pathname + window.location.search;
        url += (url.indexOf('#') > -1 ? '' : '#tab-') + tab;
        window.location.href = url.replace(/#.*/, '#tab-' + tab);
        window.location.reload();
    }
    function verMotivoRejeicao(el) {
        event.preventDefault();
        var nome = el.getAttribute('data-nome');
        var data = el.getAttribute('data-data');
        var motivo = el.getAttribute('data-motivo');
        appAlert('<div style="text-align:left;"><strong>Por:</strong> ' + escapeHtml(nome) + '<br><strong>Data:</strong> ' + escapeHtml(data) + '<br><br><strong>Comentário:</strong><div style="background:#f9fafb; border:1px solid var(--color-border); border-radius:6px; padding:10px; margin-top:6px; white-space:pre-wrap;">' + escapeHtml(motivo) + '</div></div>', 'Detalhe da Decisão');
    }

    function filtrarHistorico() {
        var filtro = document.getElementById('filtroHistTipo').value;
        document.querySelectorAll('#tabelaHistorico tbody tr').forEach(function(tr) {
            tr.style.display = (!filtro || tr.getAttribute('data-tipo') === filtro) ? '' : 'none';
        });
    }

    function adicionarDestinatario() {
        var nome = document.getElementById('dest_nome').value.trim();
        var email = document.getElementById('dest_email').value.trim();
        var tipo = document.getElementById('dest_tipo').value;
        if (!nome || !email) { showToast('Preencha nome e email.', 'warning'); return; }
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'gerar_token', especificacao_id: especId, nome: nome, email: email, tipo: tipo })
        })
        .then(function(r) { return checkSession(r); })
        .then(function(data) {
            if (data.success) {
                showToast('Destinatário adicionado!', 'success');
                reloadToTab('partilha');
            } else {
                showToast(data.error || 'Erro.', 'danger');
            }
        });
    }

    function enviarParaFornecedor(nome, email) {
        if (!email) { showToast('Preencha o email.', 'warning'); return; }
        appConfirm('Enviar link de aceitação para ' + nome + ' (' + email + ')?', function() {
            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ action: 'gerar_token', especificacao_id: especId, nome: nome, email: email, tipo: 'fornecedor', enviar_email: true, base_url: window.location.origin + BASE_PATH })
            })
            .then(function(r) { return checkSession(r); })
            .then(function(data) {
                if (data.success) {
                    showToast(data.email_enviado ? 'Token criado e email enviado!' : 'Token criado (email não enviado).', data.email_enviado ? 'success' : 'warning');
                    setTimeout(function() { reloadToTab('partilha'); }, 800);
                } else {
                    showToast(data.error || 'Erro.', 'danger');
                }
            })
            .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') showToast('Erro.', 'danger'); });
        }, 'Enviar');
    }

    function toggleFornBtn(fornId) {
        var input = document.getElementById('forn_email_' + fornId);
        var btn = document.getElementById('forn_btn_' + fornId);
        if (input && btn) {
            var hasEmail = input.value.trim().length > 0;
            btn.disabled = !hasEmail;
            btn.textContent = hasEmail ? 'Enviar' : 'Falta email';
            btn.className = hasEmail ? 'btn btn-primary btn-sm' : 'btn btn-ghost btn-sm';
        }
    }

    function enviarLinkToken(tokenId) {
        appConfirm('Enviar email com link de aceitação a este destinatário?', function() {
            fetch(BASE_PATH + '/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ action: 'enviar_link_aceitacao', token_id: tokenId, especificacao_id: especId, base_url: window.location.origin + BASE_PATH })
            })
            .then(function(r) { return checkSession(r); })
            .then(function(data) {
                if (data.success) {
                    showToast('Email enviado com sucesso!', 'success');
                    setTimeout(function() { reloadToTab('partilha'); }, 800);
                } else {
                    showToast(data.error || 'Erro ao enviar email.', 'danger');
                }
            })
            .catch(function(err) { if (err.message !== 'SESSION_EXPIRED') showToast('Erro ao enviar.', 'danger'); });
        }, 'Enviar Email');
    }

    function copiarLinkToken(token) {
        var url = getBaseUrl() + '/publico.php?token=' + token;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(function() {
                showToast('Link copiado!', 'success');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = url;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('Link copiado!', 'success');
        }
    }

    function revogarToken(tokenId) {
        appConfirmDanger('Revogar acesso deste destinatário?', function() {
            fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'revogar_token', token_id: tokenId })
        })
        .then(function(r) { return checkSession(r); })
        .then(function(data) {
            if (data.success) {
                showToast('Acesso revogado.', 'success');
                reloadToTab('partilha');
            } else {
                showToast(data.error || 'Erro.', 'danger');
            }
        });
        }, 'Revogar Acesso');
    }

    // Desabilitar edição se versão bloqueada ou super admin a ver outra org
    if (versaoBloqueada || saOutraOrg) {
        document.querySelectorAll('input:not([readonly]):not([type="hidden"]), textarea:not([readonly]), select:not([disabled])').forEach(function(el) {
            if (!saOutraOrg && el.closest('#panel-partilha')) return; // permitir interagir com destinatários (só na própria org)
            el.setAttribute('readonly', true);
            if (el.tagName === 'SELECT') { el.setAttribute('disabled', true); el.removeAttribute('readonly'); }
        });
        document.querySelectorAll('.remove-btn, .add-btn, [onclick*="adicionar"], [onclick*="remover"]').forEach(function(btn) {
            if (!saOutraOrg && btn.closest('#panel-partilha')) return;
            btn.style.display = 'none';
        });
    }

    // === Pedidos ao fornecedor ===
    function adicionarPedido() {
        if (!especId) { showToast('Guarde a especificação primeiro.', 'warning'); return; }
        apiPost({ action: 'save_pedido', especificacao_id: especId, titulo: 'Novo pedido', descricao: '', obrigatorio: 1 })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var container = document.getElementById('pedidos-container');
                if (!container.querySelector('h4')) {
                    container.innerHTML = '<h4 style="font-size:13px; color:#667085; margin-bottom:8px;">Pedidos ao Fornecedor</h4>';
                }
                var div = document.createElement('div');
                div.className = 'card pedido-block';
                div.setAttribute('data-pedido-id', data.id);
                div.style.cssText = 'padding:12px; margin-bottom:8px; border-left:3px solid #f59e0b;';
                div.innerHTML = '<div style="display:flex; justify-content:space-between; align-items:start; gap:8px;">' +
                    '<div style="flex:1;">' +
                    '<input type="text" class="pedido-titulo" value="Novo pedido" placeholder="Título do pedido" style="font-weight:600; width:100%; border:1px solid #e5e7eb; border-radius:4px; padding:4px 8px; font-size:13px;">' +
                    '<textarea class="pedido-descricao" placeholder="Descrição (o que pedir ao fornecedor)" rows="2" style="width:100%; margin-top:4px; border:1px solid #e5e7eb; border-radius:4px; padding:4px 8px; font-size:12px; resize:vertical;"></textarea>' +
                    '<label style="display:flex; align-items:center; gap:4px; font-size:11px; color:#667085; margin-top:4px;"><input type="checkbox" class="pedido-obrigatorio" checked> Obrigatório para aceitar</label>' +
                    '</div>' +
                    '<div style="display:flex; flex-direction:column; gap:4px;">' +
                    '<button class="btn btn-ghost btn-sm" onclick="guardarPedido(' + data.id + ', this)" title="Guardar">&#128190;</button>' +
                    '<button class="btn btn-ghost btn-sm" onclick="removerPedido(' + data.id + ', this)" title="Remover" style="color:#ef4444;">&#128465;</button>' +
                    '</div></div>';
                container.appendChild(div);
                div.querySelector('.pedido-titulo').focus();
                div.querySelector('.pedido-titulo').select();
            } else { showToast(data.error || 'Erro.', 'error'); }
        });
    }

    function guardarPedido(id, btn) {
        var block = btn.closest('.pedido-block');
        var titulo = block.querySelector('.pedido-titulo').value.trim();
        var descricao = block.querySelector('.pedido-descricao').value.trim();
        var obrigatorio = block.querySelector('.pedido-obrigatorio').checked ? 1 : 0;
        if (!titulo) { showToast('Título é obrigatório.', 'warning'); return; }
        apiPost({ action: 'save_pedido', id: id, especificacao_id: especId, titulo: titulo, descricao: descricao, obrigatorio: obrigatorio })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) showToast('Pedido guardado.', 'success');
            else showToast(data.error || 'Erro.', 'error');
        });
    }

    function removerPedido(id, btn) {
        appConfirm('Remover este pedido?', function() {
            apiPost({ action: 'delete_pedido', id: id })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    btn.closest('.pedido-block').remove();
                    showToast('Pedido removido.', 'success');
                } else showToast(data.error || 'Erro.', 'error');
            });
        });
    }

    // Aplicar secções permitidas ao carregar
    atualizarSeccoesPermitidas();
    </script>
    <?php include __DIR__ . '/includes/modals.php'; ?>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
