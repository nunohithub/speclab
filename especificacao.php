<?php
/**
 * SpecLab - Cadernos de Encargos
 * Editor de Especificação Técnica
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();
$orgId = $user['org_id'];

// Carregar listas para selects (scoped por org)
if (isSuperAdmin()) {
    $produtos = $db->query("SELECT id, nome, tipo FROM produtos WHERE ativo = 1 ORDER BY nome")->fetchAll();
    $clientes = $db->query("SELECT id, nome, sigla FROM clientes WHERE ativo = 1 ORDER BY nome")->fetchAll();
    $fornecedores = $db->query("SELECT id, nome, sigla FROM fornecedores WHERE ativo = 1 ORDER BY nome")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT id, nome, tipo FROM produtos WHERE ativo = 1 AND (organizacao_id IS NULL OR organizacao_id = ?) ORDER BY nome");
    $stmt->execute([$orgId]);
    $produtos = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT id, nome, sigla FROM clientes WHERE ativo = 1 AND organizacao_id = ? ORDER BY nome");
    $stmt->execute([$orgId]);
    $clientes = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT id, nome, sigla FROM fornecedores WHERE ativo = 1 AND organizacao_id = ? ORDER BY nome");
    $stmt->execute([$orgId]);
    $fornecedores = $stmt->fetchAll();
}

// Determinar se é nova especificação ou edição
$isNew = isset($_GET['novo']) && $_GET['novo'] == '1';
$especId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($isNew) {
    // Nova especificação - gerar número e defaults
    $numero = gerarNumeroEspecificacao($db, $orgId);
    $espec = [
        'id' => 0,
        'numero' => $numero,
        'titulo' => '',
        'versao' => '1.0',
        'estado' => 'rascunho',
        'produto_ids' => [],
        'fornecedor_ids' => [],
        'cliente_id' => '',
        'produto_nome' => '',
        'produto_tipo' => '',
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
        'classes' => [],
        'defeitos' => [],
        'config_visual' => null,
        'seccoes' => [
            ['titulo' => 'Objetivo', 'conteudo' => '', 'tipo' => 'texto', 'ordem' => 0],
        ],
        'ficheiros' => [],
    ];
} elseif ($especId > 0) {
    // Editar especificação existente
    $espec = getEspecificacaoCompleta($db, $especId);
    if (!$espec) {
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

// Templates de parâmetros
$categoriasPadrao = getCategoriasPadrao();
$classesPadrao = getClassesPadrao();
$defeitosPadrao = getDefeitosPadrao();
// Config visual (JSON -> array com defaults, usando cores da org)
$orgCor = $user['org_cor'] ?? '#2596be';
$configVisualDefaults = [
    'cor_titulos' => $orgCor,
    'cor_linhas' => $orgCor,
    'cor_nome' => $orgCor,
    'tamanho_titulos' => '14',
    'tamanho_nome' => '16',
    'logo_custom' => '',
];
$configVisual = $configVisualDefaults;
if (!empty($espec['config_visual'])) {
    $cv = is_string($espec['config_visual']) ? json_decode($espec['config_visual'], true) : $espec['config_visual'];
    if (is_array($cv)) {
        $configVisual = array_merge($configVisualDefaults, $cv);
    }
}

$pageTitle = $isNew ? 'Nova Especificação' : 'Editar: ' . sanitize($espec['numero']);
$pageSubtitle = 'Editor de Especificação';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Cadernos de Encargos</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
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

        .editor-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--spacing-sm);
            margin-bottom: var(--spacing-md);
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            background: var(--color-bg, #f3f4f6);
            z-index: 40;
            padding: var(--spacing-sm) 0;
            border-bottom: 1px solid var(--color-border, #e5e7eb);
        }
        .editor-toolbar .left { display: flex; align-items: center; gap: var(--spacing-sm); }
        .editor-toolbar .right { display: flex; align-items: center; gap: var(--spacing-sm); }

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

        /* Secção de ensaios inline (tabela editável) */
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
        .seccao-ensaios-table input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid transparent;
            background: transparent;
            padding: 4px 6px;
            font-size: var(--font-size-xs);
            border-radius: 3px;
            transition: all var(--transition-fast);
        }
        .seccao-ensaios-table input:hover,
        .seccao-ensaios-table input:focus {
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
        .seccao-ensaios-table td.merge-master input {
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
        .seccao-ensaios-table td.merge-slave-last input {
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

        /* Modal seletor de ensaios */
        .ensaios-selector-grid {
            max-height: 50vh;
            overflow-y: auto;
            padding: var(--spacing-sm);
        }
        .ensaios-cat-group {
            margin-bottom: var(--spacing-md);
        }
        .ensaios-cat-title {
            font-weight: 700;
            font-size: var(--font-size-sm);
            color: var(--color-primary);
            padding: var(--spacing-xs) var(--spacing-sm);
            background: var(--color-primary-lighter);
            border-radius: var(--border-radius-sm);
            margin-bottom: var(--spacing-xs);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .ensaios-cat-title button {
            font-size: 11px;
            padding: 2px 8px;
            border: 1px solid var(--color-primary);
            background: white;
            color: var(--color-primary);
            border-radius: 4px;
            cursor: pointer;
        }
        .ensaios-cat-title button:hover {
            background: var(--color-primary);
            color: white;
        }
        .ensaio-check-item {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            padding: 6px var(--spacing-sm);
            border-radius: var(--border-radius-sm);
            font-size: var(--font-size-sm);
            cursor: pointer;
            transition: background var(--transition-fast);
        }
        .ensaio-check-item:hover {
            background: var(--color-bg);
        }
        .ensaio-check-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--color-primary);
        }
        .ensaio-check-item .ensaio-info {
            flex: 1;
        }
        .ensaio-check-item .ensaio-name { font-weight: 500; }
        .ensaio-check-item .ensaio-detail { font-size: var(--font-size-xs); color: var(--color-muted); }

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
            <div class="save-indicator" id="saveIndicator">
                <span class="save-dot"></span>
                <span class="save-text">Pronto</span>
            </div>
            <span class="user-info"><?= sanitize($user['nome']) ?> (<?= $user['role'] ?>)</span>
            <?php if (in_array($user['role'], ['super_admin', 'org_admin'])): ?>
                <a href="<?= BASE_PATH ?>/admin.php" class="btn btn-secondary btn-sm">Admin</a>
            <?php endif; ?>
            <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-ghost btn-sm">Sair</a>
        </div>
    </div>

    <!-- EDITOR TOOLBAR -->
    <div class="container">
        <div class="editor-toolbar no-print">
            <div class="left">
                <a href="<?= BASE_PATH ?>/dashboard.php" class="btn btn-ghost btn-sm" title="Voltar ao Dashboard">&larr; Voltar</a>
                <h2><?= $isNew ? 'Nova Especificação' : 'Editar Especificação' ?></h2>
                <span class="pill <?= $espec['estado'] === 'ativo' ? 'pill-success' : ($espec['estado'] === 'rascunho' ? 'pill-warning' : 'pill-muted') ?>" id="estadoPill">
                    <?= ucfirst($espec['estado']) ?>
                </span>
                <span class="muted" id="specNumero"><?= sanitize($espec['numero']) ?></span>
            </div>
            <div class="right">
                <button class="btn btn-secondary btn-sm" onclick="window.print()" title="Imprimir">Imprimir</button>
                <a href="<?= BASE_PATH ?>/pdf.php?id=<?= $espec['id'] ?>" class="btn btn-secondary btn-sm" target="_blank" title="Exportar PDF" id="btnPdf"<?= $isNew ? ' style="display:none"' : '' ?>>PDF</a>
                <a href="<?= BASE_PATH ?>/ver.php?id=<?= $espec['id'] ?>" class="btn btn-secondary btn-sm" target="_blank" title="Pré-visualizar" id="btnVer"<?= $isNew ? ' style="display:none"' : '' ?>>Ver</a>
                <div class="dropdown">
                    <button class="btn btn-secondary btn-sm" onclick="toggleDropdown('estadoMenu')">Estado</button>
                    <div class="dropdown-menu" id="estadoMenu">
                        <button onclick="alterarEstado('rascunho')">Rascunho</button>
                        <button onclick="alterarEstado('ativo')">Ativo</button>
                        <div class="dropdown-divider"></div>
                        <button onclick="alterarEstado('obsoleto')">Obsoleto</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="guardarTudo()">Guardar</button>
            </div>
        </div>

        <!-- TABS NAVIGATION -->
        <div class="tabs no-print" id="mainTabs">
            <button class="tab active" data-tab="dados-gerais">Dados Gerais</button>
            <button class="tab" data-tab="conteudo">Conteúdo</button>
            <button class="tab" data-tab="parametros">Ensaios</button>
            <button class="tab" data-tab="classes-defeitos">Classes e Defeitos</button>
            <button class="tab" data-tab="legislacao">Legislação</button>
            <button class="tab" data-tab="ficheiros">Ficheiros</button>
            <button class="tab" data-tab="partilha">Partilha</button>
            <button class="tab" data-tab="configuracoes">Configurações</button>
        </div>

        <!-- CONTENT GRID WITH SIDEBAR -->
        <div class="content-grid with-sidebar">
            <!-- MAIN CONTENT -->
            <div class="main-content">

                <!-- TAB 1: DADOS GERAIS -->
                <div class="tab-panel active" id="panel-dados-gerais">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Identificação</span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="tipo_doc">Tipo de Documento</label>
                                <select id="tipo_doc" name="tipo_doc">
                                    <option value="caderno" <?= ($espec['tipo_doc'] ?? 'caderno') === 'caderno' ? 'selected' : '' ?>>Caderno de Encargos (Completo)</option>
                                    <option value="ficha_tecnica" <?= ($espec['tipo_doc'] ?? '') === 'ficha_tecnica' ? 'selected' : '' ?>>Ficha Técnica</option>
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
                            <div class="form-group">
                                <label for="estado">Estado</label>
                                <select id="estado" name="estado">
                                    <option value="rascunho" <?= $espec['estado'] === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                                    <option value="ativo" <?= $espec['estado'] === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                    <option value="obsoleto" <?= $espec['estado'] === 'obsoleto' ? 'selected' : '' ?>>Obsoleto</option>
                                </select>
                            </div>
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
                                            <?= sanitize($p['nome']) ?> (<?= sanitize($p['tipo']) ?>)
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
                </div>

                <!-- TAB 2: CONTEUDO -->
                <div class="tab-panel" id="panel-conteudo">
                    <div class="card" style="padding-bottom: 0;">
                        <div class="card-header">
                            <span class="card-title">Secções do Caderno de Encargos</span>
                        </div>

                        <div id="seccoesContainer">
                            <?php foreach ($espec['seccoes'] as $i => $sec):
                                $secTipo = $sec['tipo'] ?? 'texto';
                            ?>
                                <?php if ($secTipo === 'texto'): ?>
                                <div class="seccao-block" data-seccao-idx="<?= $i ?>" data-tipo="texto">
                                    <div class="seccao-header">
                                        <span class="seccao-numero"><?= $i + 1 ?>.</span>
                                        <input type="text" class="seccao-titulo" value="<?= sanitize($sec['titulo'] ?? '') ?>" placeholder="Título da secção">
                                        <div class="seccao-ai-btns">
                                            <button class="btn-ai" onclick="abrirAI(this, 'sugerir')" title="Sugerir conteúdo com IA"><span class="ai-icon">&#10024;</span> Sugerir</button>
                                            <button class="btn-ai" onclick="abrirAI(this, 'melhorar')" title="Melhorar conteúdo com IA"><span class="ai-icon">&#9998;</span> Melhorar</button>
                                        </div>
                                        <div class="seccao-actions">
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>
                                            <button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>
                                        </div>
                                    </div>
                                    <textarea id="seccao_<?= $i ?>" class="seccao-editor" rows="6" placeholder="Conteúdo da secção..."><?= $sec['conteudo'] ?? '' ?></textarea>
                                </div>
                                <?php else: ?>
                                <?php
                                    $ensaiosRaw = json_decode($sec['conteudo'] ?? '[]', true);
                                    if (isset($ensaiosRaw['rows'])) {
                                        $ensaiosData = $ensaiosRaw['rows'];
                                        $colWidths = $ensaiosRaw['colWidths'] ?? [20, 25, 20, 18, 12];
                                        $mergesData = $ensaiosRaw['merges'] ?? [];
                                    } else {
                                        $ensaiosData = is_array($ensaiosRaw) ? $ensaiosRaw : [];
                                        $colWidths = [20, 25, 20, 18, 12];
                                        $mergesData = [];
                                    }
                                    // Converter para formato 4 colunas
                                    $colShift = (count($colWidths) >= 5) ? 1 : 0;
                                    $editorCw = ($colShift === 1) ? array_slice($colWidths, 1, 4) : array_slice($colWidths, 0, 4);
                                    if (count($editorCw) < 4) $editorCw = [30, 25, 22, 15];
                                    $editorMerges = [];
                                    foreach ($mergesData as $m) {
                                        $nc = ($m['col'] ?? 0) - $colShift;
                                        if ($nc >= 0 && $nc <= 3) {
                                            $editorMerges[] = ['col' => $nc, 'row' => $m['row'], 'span' => $m['span'], 'hAlign' => $m['hAlign'] ?? 'center', 'vAlign' => $m['vAlign'] ?? 'middle'];
                                        }
                                    }
                                    $prevCat = null;
                                ?>
                                <div class="seccao-block" data-seccao-idx="<?= $i ?>" data-tipo="ensaios">
                                    <div class="seccao-header">
                                        <span class="seccao-numero"><?= $i + 1 ?>.</span>
                                        <input type="text" class="seccao-titulo" value="<?= sanitize($sec['titulo'] ?? 'Características Técnicas') ?>" placeholder="Título da secção">
                                        <span class="pill pill-info" style="font-size:10px; padding:2px 8px;">Ensaios</span>
                                        <div class="seccao-actions">
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>
                                            <button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>
                                            <button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>
                                        </div>
                                    </div>
                                    <div class="seccao-ensaios-wrap">
                                        <table class="seccao-ensaios-table" data-merges="<?= sanitize(json_encode($editorMerges)) ?>">
                                            <thead>
                                                <tr>
                                                    <th style="width:<?= $editorCw[0] ?>%">Ensaio</th>
                                                    <th style="width:<?= $editorCw[1] ?>%">Especificação</th>
                                                    <th style="width:<?= $editorCw[2] ?>%">Norma</th>
                                                    <th style="width:<?= $editorCw[3] ?>%">NQA</th>
                                                    <th style="width:5%"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="ensaios-tbody">
                                                <?php foreach ($ensaiosData as $ens):
                                                    $cat = trim($ens['categoria'] ?? '');
                                                    if ($cat !== '' && $cat !== $prevCat):
                                                        $prevCat = $cat;
                                                ?>
                                                <tr class="ensaio-cat-row">
                                                    <td colspan="5"><input type="text" value="<?= sanitize($cat) ?>" data-field="cat-header" class="cat-header-input" placeholder="Categoria"><button class="remove-btn cat-remove-btn" onclick="removerCategoriaEnsaio(this)" title="Remover categoria">&times;</button></td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr>
                                                    <td><input type="text" value="<?= sanitize($ens['ensaio'] ?? '') ?>" data-field="ensaio"></td>
                                                    <td><input type="text" value="<?= sanitize($ens['especificacao'] ?? '') ?>" data-field="especificacao"></td>
                                                    <td><input type="text" value="<?= sanitize($ens['norma'] ?? '') ?>" data-field="norma"></td>
                                                    <td><input type="text" value="<?= sanitize($ens['nqa'] ?? '') ?>" data-field="nqa"></td>
                                                    <td><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <div class="seccao-ensaios-actions">
                                            <button class="btn btn-secondary btn-sm" onclick="adicionarEnsaioLinhaManual(this)">+ Linha</button>
                                            <button class="btn btn-secondary btn-sm" onclick="abrirSelectorEnsaiosParaSeccao(this)">+ Do Banco de Ensaios</button>
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

                        <!-- Barra fixa de ações -->
                        <div class="content-actions-bar">
                            <button class="btn btn-primary btn-sm" onclick="adicionarSeccao()">&#128196; + Secção</button>
                            <button class="btn btn-secondary btn-sm" onclick="abrirSelectorEnsaios()">&#9881; + Ensaios</button>
                            <button class="btn btn-secondary btn-sm" onclick="abrirSelectorLegConteudo()">&#9878; + Legislação</button>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: PARAMETROS -->
                <div class="tab-panel" id="panel-parametros">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Ensaios</span>
                            <div class="flex gap-sm">
                                <button class="btn btn-secondary btn-sm" onclick="abrirModalTemplates()">Adicionar de Template</button>
                                <button class="btn btn-primary btn-sm" onclick="adicionarParametro()">+ Adicionar</button>
                            </div>
                        </div>

                        <div class="param-table" id="paramTable">
                            <div class="param-row header">
                                <span>Categoria</span>
                                <span>Ensaio</span>
                                <span>Especificação</span>
                                <span>Norma</span>
                                <span>NQA</span>
                                <span></span>
                            </div>
                            <div id="paramRows">
                                <?php
                                // Se nova spec sem parâmetros, pré-carregar do template
                                $ensaiosParaMostrar = $espec['parametros'];
                                if (empty($ensaiosParaMostrar) && $isNew) {
                                    $ordem = 0;
                                    foreach ($categoriasPadrao as $cat => $params) {
                                        foreach ($params as $p) {
                                            $ensaiosParaMostrar[] = [
                                                'id' => '',
                                                'categoria' => $cat,
                                                'ensaio' => $p['ensaio'],
                                                'valor_especificado' => $p['exemplo'],
                                                'metodo' => $p['metodo'],
                                                'amostra_nqa' => '',
                                            ];
                                            $ordem++;
                                        }
                                    }
                                }
                                ?>
                                <?php foreach ($ensaiosParaMostrar as $i => $param): ?>
                                    <div class="param-row" data-param-id="<?= $param['id'] ?? '' ?>">
                                        <input type="text" name="param_categoria[]" value="<?= sanitize($param['categoria'] ?? '') ?>" placeholder="Categoria" class="param-field">
                                        <input type="text" name="param_ensaio[]" value="<?= sanitize($param['ensaio'] ?? '') ?>" placeholder="Nome do ensaio" class="param-field">
                                        <input type="text" name="param_especificacao[]" value="<?= sanitize($param['valor_especificado'] ?? $param['especificacao_valor'] ?? '') ?>" placeholder="Valor / Limites" class="param-field">
                                        <input type="text" name="param_metodo[]" value="<?= sanitize($param['metodo'] ?? '') ?>" placeholder="Norma / Método" class="param-field">
                                        <input type="text" name="param_amostra[]" value="<?= sanitize($param['amostra_nqa'] ?? '') ?>" placeholder="NQA" class="param-field">
                                        <button class="remove-btn" onclick="removerLinha(this)" title="Remover">&times;</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if (empty($ensaiosParaMostrar)): ?>
                            <div class="empty-state" id="paramEmpty" style="padding: var(--spacing-xl);">
                                <div class="icon">&#9881;</div>
                                <h3>Sem ensaios definidos</h3>
                                <p class="muted">Adicione ensaios manualmente ou use os templates predefinidos.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- TAB 4: CLASSES E DEFEITOS -->
                <div class="tab-panel" id="panel-classes-defeitos">
                    <!-- Classes Visuais -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Classes Visuais</span>
                            <div class="flex gap-sm">
                                <button class="btn btn-secondary btn-sm" onclick="carregarClassesPadrao()">Carregar Padrão</button>
                                <button class="btn btn-primary btn-sm" onclick="adicionarClasse()">+ Adicionar Classe</button>
                            </div>
                        </div>
                        <div class="class-row header">
                            <span>Classe</span>
                            <span>Defeitos Máx. (%)</span>
                            <span>Descrição</span>
                            <span></span>
                        </div>
                        <div id="classRows">
                            <?php if (!empty($espec['classes'])): ?>
                                <?php foreach ($espec['classes'] as $classe): ?>
                                    <div class="class-row" data-class-id="<?= $classe['id'] ?? '' ?>">
                                        <input type="text" name="class_nome[]" value="<?= sanitize($classe['classe'] ?? '') ?>" placeholder="Nome da classe" class="param-field">
                                        <input type="number" name="class_defeitos[]" value="<?= sanitize($classe['defeitos_max'] ?? '') ?>" placeholder="%" class="param-field">
                                        <input type="text" name="class_descricao[]" value="<?= sanitize($classe['descricao'] ?? '') ?>" placeholder="Descrição" class="param-field">
                                        <button class="remove-btn" onclick="removerLinha(this)" title="Remover">&times;</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($espec['classes'])): ?>
                            <div class="empty-state" id="classEmpty" style="padding: var(--spacing-lg);">
                                <p class="muted">Sem classes definidas. Use o botão "Carregar Padrão" para adicionar as classes standard.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Defeitos -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Definição de Defeitos</span>
                            <div class="flex gap-sm">
                                <button class="btn btn-secondary btn-sm" onclick="carregarDefeitosPadrao()">Carregar Padrão</button>
                                <button class="btn btn-primary btn-sm" onclick="adicionarDefeito()">+ Adicionar Defeito</button>
                            </div>
                        </div>

                        <!-- Críticos -->
                        <div class="defect-group">
                            <div class="defect-group-header critico">Defeitos Críticos</div>
                            <div class="defect-row header">
                                <span>Defeito</span>
                                <span>Descrição</span>
                                <span></span>
                            </div>
                            <div id="defectRowsCritico">
                                <?php if (!empty($espec['defeitos'])):
                                    foreach ($espec['defeitos'] as $defeito):
                                        if (($defeito['severidade'] ?? '') === 'critico'):
                                ?>
                                    <div class="defect-row" data-defect-id="<?= $defeito['id'] ?? '' ?>">
                                        <input type="text" name="defect_nome_critico[]" value="<?= sanitize($defeito['nome'] ?? '') ?>" placeholder="Nome do defeito" class="param-field">
                                        <input type="text" name="defect_desc_critico[]" value="<?= sanitize($defeito['descricao'] ?? '') ?>" placeholder="Descrição" class="param-field">
                                        <button class="remove-btn" onclick="removerLinha(this)" title="Remover">&times;</button>
                                    </div>
                                <?php
                                        endif;
                                    endforeach;
                                endif; ?>
                            </div>
                            <button class="btn btn-ghost btn-sm mt-sm" onclick="adicionarDefeitoSeveridade('critico')">+ Adicionar Crítico</button>
                        </div>

                        <!-- Maiores -->
                        <div class="defect-group">
                            <div class="defect-group-header maior">Defeitos Maiores</div>
                            <div class="defect-row header">
                                <span>Defeito</span>
                                <span>Descrição</span>
                                <span></span>
                            </div>
                            <div id="defectRowsMaior">
                                <?php if (!empty($espec['defeitos'])):
                                    foreach ($espec['defeitos'] as $defeito):
                                        if (($defeito['severidade'] ?? '') === 'maior'):
                                ?>
                                    <div class="defect-row" data-defect-id="<?= $defeito['id'] ?? '' ?>">
                                        <input type="text" name="defect_nome_maior[]" value="<?= sanitize($defeito['nome'] ?? '') ?>" placeholder="Nome do defeito" class="param-field">
                                        <input type="text" name="defect_desc_maior[]" value="<?= sanitize($defeito['descricao'] ?? '') ?>" placeholder="Descrição" class="param-field">
                                        <button class="remove-btn" onclick="removerLinha(this)" title="Remover">&times;</button>
                                    </div>
                                <?php
                                        endif;
                                    endforeach;
                                endif; ?>
                            </div>
                            <button class="btn btn-ghost btn-sm mt-sm" onclick="adicionarDefeitoSeveridade('maior')">+ Adicionar Maior</button>
                        </div>

                        <!-- Menores -->
                        <div class="defect-group">
                            <div class="defect-group-header menor">Defeitos Menores</div>
                            <div class="defect-row header">
                                <span>Defeito</span>
                                <span>Descrição</span>
                                <span></span>
                            </div>
                            <div id="defectRowsMenor">
                                <?php if (!empty($espec['defeitos'])):
                                    foreach ($espec['defeitos'] as $defeito):
                                        if (($defeito['severidade'] ?? '') === 'menor'):
                                ?>
                                    <div class="defect-row" data-defect-id="<?= $defeito['id'] ?? '' ?>">
                                        <input type="text" name="defect_nome_menor[]" value="<?= sanitize($defeito['nome'] ?? '') ?>" placeholder="Nome do defeito" class="param-field">
                                        <input type="text" name="defect_desc_menor[]" value="<?= sanitize($defeito['descricao'] ?? '') ?>" placeholder="Descrição" class="param-field">
                                        <button class="remove-btn" onclick="removerLinha(this)" title="Remover">&times;</button>
                                    </div>
                                <?php
                                        endif;
                                    endforeach;
                                endif; ?>
                            </div>
                            <button class="btn btn-ghost btn-sm mt-sm" onclick="adicionarDefeitoSeveridade('menor')">+ Adicionar Menor</button>
                        </div>
                    </div>
                </div>

                <!-- TAB: LEGISLAÇÃO -->
                <div class="tab-panel" id="panel-legislacao">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Legislação Aplicável</span>
                            <span class="muted">Legislação e normas associadas a esta especificação</span>
                        </div>
                        <table class="legislacao-table" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="padding:8px 10px; text-align:left; font-size:13px; border-bottom:2px solid var(--color-border);">Legislação / Norma</th>
                                    <th style="padding:8px 10px; text-align:left; font-size:13px; border-bottom:2px solid var(--color-border);">Rolhas a que se aplica</th>
                                    <th style="padding:8px 10px; text-align:left; font-size:13px; border-bottom:2px solid var(--color-border);">Resumo</th>
                                    <th style="padding:8px 10px; width:40px; border-bottom:2px solid var(--color-border);"></th>
                                </tr>
                            </thead>
                            <tbody id="legislacaoRows">
                                <?php
                                $legData = [];
                                if (!empty($espec['legislacao_json'])) {
                                    $legParsed = json_decode($espec['legislacao_json'], true);
                                    if (is_array($legParsed)) $legData = $legParsed;
                                }
                                if (empty($legData)): ?>
                                    <tr id="legEmpty"><td colspan="4" class="muted" style="text-align:center; padding:20px;">Nenhuma legislação adicionada. Use o botão abaixo para adicionar.</td></tr>
                                <?php else:
                                    foreach ($legData as $leg): ?>
                                    <tr class="leg-row">
                                        <td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="<?= sanitize($leg['legislacao_norma'] ?? '') ?>" data-field="leg_norma" style="width:100%; border:none; background:transparent; font-weight:600; font-size:13px;"></td>
                                        <td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="<?= sanitize($leg['rolhas_aplicaveis'] ?? '') ?>" data-field="leg_rolhas" style="width:100%; border:none; background:transparent; font-size:12px; color:var(--color-muted);"></td>
                                        <td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="<?= sanitize($leg['resumo'] ?? '') ?>" data-field="leg_resumo" style="width:100%; border:none; background:transparent; font-size:12px; color:var(--color-muted);"></td>
                                        <td style="padding:6px 10px; border-bottom:1px solid var(--color-border); text-align:center;"><button class="remove-btn" onclick="removerLegRow(this)" title="Remover">&times;</button></td>
                                    </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                        <div style="padding:var(--spacing-sm) var(--spacing-md); border-top:1px solid var(--color-border); display:flex; gap:var(--spacing-sm);">
                            <button class="btn btn-secondary btn-sm" onclick="abrirSelectorLegislacao()">+ Adicionar do Banco</button>
                            <button class="btn btn-ghost btn-sm" onclick="adicionarLegManual()">+ Linha Manual</button>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: FICHEIROS -->
                <div class="tab-panel" id="panel-ficheiros">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Ficheiros Anexos</span>
                            <span class="muted">Imagens, documentos, certificados</span>
                        </div>

                        <div class="upload-zone" id="uploadZone">
                            <div class="icon">&#128206;</div>
                            <p><strong>Arraste ficheiros para aqui</strong></p>
                            <p>ou clique para selecionar</p>
                            <p class="muted" style="margin-top: var(--spacing-sm);">Máx. 50MB por ficheiro. Formatos: PDF, DOC, XLS, JPG, PNG</p>
                            <input type="file" id="fileInput" multiple style="display:none" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.bmp,.tif,.tiff,.csv,.txt">
                        </div>

                        <div id="uploadProgress" class="hidden" style="margin-top: var(--spacing-md);">
                            <div class="flex-between">
                                <span class="muted" id="uploadFileName">A enviar...</span>
                                <span class="muted" id="uploadPercent">0%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" id="uploadBar" style="width: 0%"></div>
                            </div>
                        </div>

                        <ul class="file-list" id="fileList">
                            <?php if (!empty($espec['ficheiros'])): ?>
                                <?php foreach ($espec['ficheiros'] as $f): ?>
                                    <li class="file-item" data-file-id="<?= $f['id'] ?>">
                                        <span class="file-name" title="<?= sanitize($f['nome_original']) ?>">
                                            <?= sanitize($f['nome_original']) ?>
                                        </span>
                                        <span class="file-size"><?= formatFileSize($f['tamanho'] ?? 0) ?></span>
                                        <span class="muted"><?= formatDate($f['uploaded_at'] ?? '') ?></span>
                                        <div class="flex gap-sm" style="margin-left: var(--spacing-md);">
                                            <a href="<?= BASE_PATH ?>/api.php?action=download_ficheiro&id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm" title="Descarregar">&#11015;</a>
                                            <button class="btn btn-danger btn-sm" onclick="removerFicheiro(<?= $f['id'] ?>)" title="Remover">&times;</button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>

                        <?php if (empty($espec['ficheiros'])): ?>
                            <div class="empty-state" id="fileEmpty" style="padding: var(--spacing-lg);">
                                <p class="muted">Nenhum ficheiro anexo.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TAB 6: PARTILHA -->
                <div class="tab-panel" id="panel-partilha">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Acesso Público</span>
                            <span class="muted">Gerar link para partilha externa</span>
                        </div>

                        <div class="form-group">
                            <label for="senha_publica">Palavra-passe para acesso público</label>
                            <input type="text" id="senha_publica" name="senha_publica" value="<?= sanitize($espec['senha_publica'] ?? '') ?>" placeholder="Definir palavra-passe (opcional)">
                            <span class="muted" style="display:block; margin-top: var(--spacing-xs);">Se definida, será necessária para aceder ao link público.</span>
                        </div>

                        <div class="form-group">
                            <label>Código de Acesso</label>
                            <div class="flex gap-sm" style="align-items: center;">
                                <input type="text" id="codigo_acesso" name="codigo_acesso" value="<?= sanitize($espec['codigo_acesso'] ?? '') ?>" readonly style="background: var(--color-bg); font-family: monospace; flex:1;">
                                <button class="btn btn-secondary btn-sm" onclick="gerarCodigoAcesso()">Gerar Código</button>
                            </div>
                        </div>

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
                                Gere um código de acesso para criar um link de partilha pública.
                            </div>
                        <?php endif; ?>

                        <div class="alert alert-warning mt-lg">
                            <strong>Nota:</strong> O link público permite que qualquer pessoa com o código (e palavra-passe, se definida) visualize esta especificação. Não permite edição.
                        </div>
                    </div>

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

                    <?php if (!$isNew): ?>
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Enviar por Email</span>
                            <span class="muted">Enviar especificação por email</span>
                        </div>
                        <div class="form-group">
                            <label for="email_destinatario">Destinatário</label>
                            <input type="email" id="email_destinatario" placeholder="email@exemplo.com">
                        </div>
                        <div class="form-group">
                            <label for="email_assunto">Assunto</label>
                            <input type="text" id="email_assunto" value="Caderno de Encargos: <?= sanitize($espec['numero']) ?> - <?= sanitize($espec['titulo']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="email_mensagem">Mensagem personalizada (opcional)</label>
                            <textarea id="email_mensagem" rows="3" placeholder="Adicionar mensagem ao email..."></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="email_incluir_link" checked> Incluir link de visualização online
                                </label>
                            </div>
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="email_anexar_pdf"> Anexar PDF ao email
                                </label>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap: var(--spacing-sm); margin-top: var(--spacing-md);">
                            <button class="btn btn-primary" onclick="enviarEmailEspec()" id="btnEnviarEmail">Enviar Email</button>
                            <span class="muted" id="emailStatus"></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 7: CONFIGURAÇÕES VISUAIS -->
                <div class="tab-panel" id="panel-configuracoes">
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
                                    <input type="range" id="cfg_tamanho_nome" min="12" max="28" value="<?= (int)$configVisual['tamanho_nome'] ?>" style="flex:1;">
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
                                    <input type="range" id="cfg_tamanho_titulos" min="10" max="24" value="<?= (int)$configVisual['tamanho_titulos'] ?>" style="flex:1;">
                                    <span id="cfg_tamanho_titulos_val" style="font-weight:600; min-width:36px; font-size:12px;"><?= (int)$configVisual['tamanho_titulos'] ?>pt</span>
                                </div>
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
                                    <input type="file" id="cfg_logo_file" accept="image/png,image/jpeg,image/svg+xml" style="font-size: var(--font-size-sm);">
                                    <p class="muted" style="margin-top:4px; font-size:11px;">PNG, JPG ou SVG. Tamanho recomendado: 300x150px</p>
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
                        <h3>Pré-visualização</h3>
                        <button class="btn btn-sm" style="background:rgba(255,255,255,0.2); color:white; border:none;" onclick="atualizarPreview()">&#8635; Atualizar</button>
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

    <!-- MODAL: TEMPLATES DE PARAMETROS -->
    <div class="modal-overlay hidden" id="modalTemplates">
        <div class="modal-box modal-box-lg">
            <div class="modal-header">
                <h3>Adicionar Parâmetros de Template</h3>
                <button class="modal-close" onclick="fecharModalTemplates()">&times;</button>
            </div>
            <p class="muted mb-md">Selecione os parâmetros que deseja adicionar:</p>
            <div class="template-grid" id="templateGrid">
                <?php foreach ($categoriasPadrao as $categoria => $params): ?>
                    <div style="grid-column: 1 / -1;">
                        <div class="category-header">
                            <?= sanitize($categoria) ?>
                            <button class="btn btn-sm" style="background:rgba(255,255,255,0.2); color:white; border:none; padding:2px 8px; font-size:11px;" onclick="selecionarCategoria('<?= sanitize($categoria) ?>')">Selecionar todos</button>
                        </div>
                    </div>
                    <?php foreach ($params as $p): ?>
                        <label class="template-item">
                            <input type="checkbox" name="template_param" value="<?= sanitize($categoria) ?>|<?= sanitize($p['ensaio']) ?>|<?= sanitize($p['metodo']) ?>|<?= sanitize($p['exemplo']) ?>">
                            <div class="info">
                                <div class="name"><?= sanitize($p['ensaio']) ?></div>
                                <div class="method"><?= sanitize($p['metodo']) ?> - <?= sanitize($p['exemplo']) ?></div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="fecharModalTemplates()">Cancelar</button>
                <button class="btn btn-primary" onclick="adicionarTemplatesSelecionados()">Adicionar Selecionados</button>
            </div>
        </div>
    </div>

    <!-- BOTÃO FLUTUANTE MERGE -->
    <div class="merge-float-actions" id="mergeFloatActions">
        <button class="btn btn-primary btn-sm" onclick="executarMerge()">Juntar</button>
        <button class="btn btn-ghost btn-sm" onclick="cancelarMergeSelection()" style="padding:2px 6px;">&times;</button>
    </div>

    <!-- MODAL: SELETOR DE ENSAIOS (para secções de conteúdo) -->
    <div class="modal-overlay hidden" id="modalSelectorEnsaios">
        <div class="modal-box modal-box-lg">
            <div class="modal-header">
                <h3>Selecionar Ensaios</h3>
                <button class="modal-close" onclick="fecharSelectorEnsaios()">&times;</button>
            </div>
            <p class="muted mb-md">Escolha os ensaios para incluir nesta secção. Pode selecionar do banco de ensaios ou do template.</p>

            <!-- Tabs: Banco / Template -->
            <div style="display:flex; gap:8px; margin-bottom: var(--spacing-md);">
                <button class="btn btn-sm btn-primary" id="tabEnsaiosBanco" onclick="switchEnsaiosTab('banco')">Banco de Ensaios</button>
                <button class="btn btn-sm btn-secondary" id="tabEnsaiosTemplate" onclick="switchEnsaiosTab('template')">Template Padrão</button>
            </div>

            <div class="ensaios-selector-grid" id="ensaiosBancoGrid">
                <!-- Populado via JS com os ensaios do tab Ensaios -->
                <div class="empty-state" style="padding: var(--spacing-md);">
                    <p class="muted">Os ensaios definidos no tab "Ensaios" aparecerão aqui.</p>
                </div>
            </div>

            <div class="ensaios-selector-grid" id="ensaiosTemplateGrid" style="display:none;">
                <?php foreach ($categoriasPadrao as $categoria => $params): ?>
                    <div class="ensaios-cat-group">
                        <div class="ensaios-cat-title">
                            <?= sanitize($categoria) ?>
                            <button onclick="toggleCatEnsaios(this, '<?= sanitize($categoria) ?>')">Todos</button>
                        </div>
                        <?php foreach ($params as $p): ?>
                            <label class="ensaio-check-item">
                                <input type="checkbox" name="sel_ensaio" data-cat="<?= sanitize($categoria) ?>" data-ensaio="<?= sanitize($p['ensaio']) ?>" data-norma="<?= sanitize($p['metodo']) ?>" data-spec="<?= sanitize($p['exemplo']) ?>">
                                <div class="ensaio-info">
                                    <div class="ensaio-name"><?= sanitize($p['ensaio']) ?></div>
                                    <div class="ensaio-detail"><?= sanitize($p['metodo']) ?> &mdash; <?= sanitize($p['exemplo']) ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="fecharSelectorEnsaios()">Cancelar</button>
                <button class="btn btn-primary" onclick="confirmarSelectorEnsaios()">Adicionar Selecionados</button>
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
    const IS_NEW = <?= $isNew ? 'true' : 'false' ?>;
    let especId = <?= $espec['id'] ?: 0 ?>;
    let autoSaveTimer = null;
    let isDirty = false;
    let isSaving = false;

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
            plugins: 'lists link table code wordcount paste lineheight',
            toolbar: 'fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | lineheight bullist numlist | table link',
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
    // SECÇÕES DINÂMICAS
    // ============================================================
    function criarSeccao(titulo, conteudo, idx) {
        var block = document.createElement('div');
        block.className = 'seccao-block';
        block.setAttribute('data-seccao-idx', idx);
        block.setAttribute('data-tipo', 'texto');

        var headerHtml =
            '<div class="seccao-header">' +
                '<span class="seccao-numero">' + (idx + 1) + '.</span>' +
                '<input type="text" class="seccao-titulo" value="' + escapeHtml(titulo) + '" placeholder="Título da secção">' +
                '<div class="seccao-ai-btns">' +
                    '<button class="btn-ai" onclick="abrirAI(this, \'sugerir\')" title="Sugerir conteúdo com IA"><span class="ai-icon">&#10024;</span> Sugerir</button>' +
                    '<button class="btn-ai" onclick="abrirAI(this, \'melhorar\')" title="Melhorar conteúdo com IA"><span class="ai-icon">&#9998;</span> Melhorar</button>' +
                '</div>' +
                '<div class="seccao-actions">' +
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

    function criarSeccaoEnsaios(titulo, ensaiosArr, idx) {
        var block = document.createElement('div');
        block.className = 'seccao-block';
        block.setAttribute('data-seccao-idx', idx);
        block.setAttribute('data-tipo', 'ensaios');

        var headerHtml =
            '<div class="seccao-header">' +
                '<span class="seccao-numero">' + (idx + 1) + '.</span>' +
                '<input type="text" class="seccao-titulo" value="' + escapeHtml(titulo || 'Características Técnicas') + '" placeholder="Título da secção">' +
                '<span class="pill pill-info" style="font-size:10px; padding:2px 8px;">Ensaios</span>' +
                '<div class="seccao-actions">' +
                    '<button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, -1)" title="Mover acima">&#9650;</button>' +
                    '<button class="btn btn-ghost btn-sm" onclick="moverSeccao(this, 1)" title="Mover abaixo">&#9660;</button>' +
                    '<button class="btn btn-ghost btn-sm seccao-remove-btn" onclick="removerSeccao(this)" title="Remover secção">&times;</button>' +
                '</div>' +
            '</div>';

        var tableHtml =
            '<div class="seccao-ensaios-wrap">' +
                '<table class="seccao-ensaios-table">' +
                    '<thead><tr>' +
                        '<th style="width:30%">Ensaio</th>' +
                        '<th style="width:25%">Especificação</th>' +
                        '<th style="width:22%">Norma</th>' +
                        '<th style="width:15%">NQA</th>' +
                        '<th style="width:5%"></th>' +
                    '</tr></thead>' +
                    '<tbody class="ensaios-tbody">';

        if (ensaiosArr && ensaiosArr.length) {
            var prevCat = null;
            ensaiosArr.forEach(function(ens) {
                var cat = (ens.categoria || '').trim();
                if (cat !== '' && cat !== prevCat) {
                    tableHtml += criarEnsaioCatRowHtml(cat);
                    prevCat = cat;
                }
                tableHtml += criarEnsaioRowHtml(ens.categoria || '', ens.ensaio || '', ens.especificacao || '', ens.norma || '', ens.nqa || '');
            });
        }

        tableHtml +=
                    '</tbody>' +
                '</table>' +
                '<div class="seccao-ensaios-actions">' +
                    '<button class="btn btn-secondary btn-sm" onclick="adicionarEnsaioLinhaManual(this)">+ Linha</button>' +
                    '<button class="btn btn-secondary btn-sm" onclick="abrirSelectorEnsaiosParaSeccao(this)">+ Do Banco de Ensaios</button>' +
                '</div>' +
            '</div>';

        block.innerHTML = headerHtml + tableHtml;
        block.querySelector('.seccao-titulo').addEventListener('input', marcarAlterado);

        return { block: block };
    }

    function criarEnsaioCatRowHtml(cat) {
        return '<tr class="ensaio-cat-row"><td colspan="5"><input type="text" value="' + escapeHtml(cat) + '" data-field="cat-header" class="cat-header-input" placeholder="Categoria"><button class="remove-btn cat-remove-btn" onclick="removerCategoriaEnsaio(this)" title="Remover categoria">&times;</button></td></tr>';
    }

    function criarEnsaioRowHtml(cat, ensaio, spec, norma, nqa) {
        return '<tr>' +
            '<td><input type="text" value="' + escapeHtml(ensaio) + '" data-field="ensaio"></td>' +
            '<td><input type="text" value="' + escapeHtml(spec) + '" data-field="especificacao"></td>' +
            '<td><input type="text" value="' + escapeHtml(norma) + '" data-field="norma"></td>' +
            '<td><input type="text" value="' + escapeHtml(nqa) + '" data-field="nqa"></td>' +
            '<td><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button></td>' +
        '</tr>';
    }

    function removerCategoriaEnsaio(btn) {
        var catRow = btn.closest('.ensaio-cat-row');
        var tbody = catRow.closest('tbody');
        var table = tbody.closest('table');
        // Restaurar DOM primeiro
        restoreMergesDOM(tbody);
        // Remover a cat-row e todas as data rows até o próximo cat-row ou fim
        var next = catRow.nextElementSibling;
        var removedCount = 0;
        var dataRows = getDataRows(tbody);
        var firstDataIdx = -1;
        while (next && !next.classList.contains('ensaio-cat-row')) {
            var toRemove = next;
            if (firstDataIdx < 0) firstDataIdx = dataRows.indexOf(toRemove);
            next = next.nextElementSibling;
            toRemove.remove();
            removedCount++;
        }
        catRow.remove();
        // Ajustar merges
        if (removedCount > 0 && firstDataIdx >= 0) {
            var merges = getTableMerges(table);
            var newMerges = [];
            merges.forEach(function(m) {
                var mEnd = m.row + m.span - 1;
                if (mEnd < firstDataIdx) {
                    newMerges.push(m);
                } else if (m.row >= firstDataIdx + removedCount) {
                    newMerges.push({ col: m.col, row: m.row - removedCount, span: m.span, hAlign: m.hAlign, vAlign: m.vAlign });
                }
                // Merges that overlap the removed range are dropped
            });
            setTableMerges(table, newMerges);
        }
        applyMergesVisual(table);
        marcarAlterado();
    }

    // Adicionar ensaios agrupados por categoria a um tbody existente
    function adicionarEnsaiosComCategorias(tbody, ensaios) {
        // Encontrar categorias já existentes no tbody
        var existingCats = {};
        tbody.querySelectorAll('.ensaio-cat-row input[data-field="cat-header"]').forEach(function(input) {
            existingCats[input.value.trim()] = input.closest('tr');
        });

        ensaios.forEach(function(ens) {
            var cat = (ens.categoria || '').trim();
            var tempDiv = document.createElement('div');

            if (cat !== '' && existingCats[cat]) {
                // Categoria já existe - adicionar row após último row desta categoria
                var catRow = existingCats[cat];
                var insertAfter = catRow;
                var next = catRow.nextElementSibling;
                while (next && !next.classList.contains('ensaio-cat-row')) {
                    insertAfter = next;
                    next = next.nextElementSibling;
                }
                tempDiv.innerHTML = '<table><tbody>' + criarEnsaioRowHtml(cat, ens.ensaio || '', ens.especificacao || '', ens.norma || '', ens.nqa || '') + '</tbody></table>';
                var newTr = tempDiv.querySelector('tr');
                insertAfter.parentNode.insertBefore(newTr, insertAfter.nextSibling);
            } else {
                // Nova categoria
                if (cat !== '') {
                    tempDiv.innerHTML = '<table><tbody>' + criarEnsaioCatRowHtml(cat) + '</tbody></table>';
                    var catTr = tempDiv.querySelector('tr');
                    tbody.appendChild(catTr);
                    existingCats[cat] = catTr;
                }
                tempDiv.innerHTML = '<table><tbody>' + criarEnsaioRowHtml(cat, ens.ensaio || '', ens.especificacao || '', ens.norma || '', ens.nqa || '') + '</tbody></table>';
                var newTr2 = tempDiv.querySelector('tr');
                tbody.appendChild(newTr2);
            }
        });
    }

    function adicionarSeccao() {
        var container = document.getElementById('seccoesContainer');
        var idx = seccaoCounter++;

        var result = criarSeccao('', '', idx);
        container.appendChild(result.block);

        var empty = document.getElementById('seccoesEmpty');
        if (empty) empty.remove();

        initSeccaoEditor(result.editorId);
        renumerarSeccoes();
        marcarAlterado();
        result.block.querySelector('.seccao-titulo').focus();
    }

    function adicionarSeccaoEnsaios(ensaiosArr, titulo) {
        var container = document.getElementById('seccoesContainer');
        var idx = seccaoCounter++;

        var result = criarSeccaoEnsaios(titulo || 'Características Técnicas', ensaiosArr, idx);
        container.appendChild(result.block);

        var empty = document.getElementById('seccoesEmpty');
        if (empty) empty.remove();

        // Inicializar handles de redimensionamento e merge
        var tbl = result.block.querySelector('.seccao-ensaios-table');
        if (tbl) {
            initColResize(tbl);
            initMergeHandlers(tbl);
        }

        renumerarSeccoes();
        marcarAlterado();
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

    // Ensaios table inline functions
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

    function adicionarEnsaioLinhaManual(btn) {
        var tbody = btn.closest('.seccao-block').querySelector('.ensaios-tbody');
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" value="" data-field="ensaio" placeholder="Ensaio"></td>' +
            '<td><input type="text" value="" data-field="especificacao" placeholder="Valor"></td>' +
            '<td><input type="text" value="" data-field="norma" placeholder="Norma"></td>' +
            '<td><input type="text" value="" data-field="nqa" placeholder="NQA"></td>' +
            '<td><button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button></td>';
        tbody.appendChild(tr);
        tr.querySelector('input').focus();
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

    // Mapeamento campo <-> coluna (4 colunas: Ensaio, Espec, Norma, NQA)
    var fieldToCol = { ensaio: 0, especificacao: 1, norma: 2, nqa: 3 };
    var colToField = ['ensaio', 'especificacao', 'norma', 'nqa'];
    var colPlaceholders = { ensaio: 'Ensaio', especificacao: 'Valor', norma: 'Norma', nqa: 'NQA' };

    // Obter apenas as data rows (excluir .ensaio-cat-row)
    function getDataRows(tbody) {
        return Array.from(tbody.querySelectorAll('tr:not(.ensaio-cat-row)'));
    }

    // Obter índice lógico de coluna de um td (funciona com tds em falta por rowspan)
    function getTdColumnIndex(td) {
        var input = td.querySelector('input[data-field]');
        if (input) {
            var f = input.getAttribute('data-field');
            if (f === 'cat-header') return -1; // categoria header, ignorar
            return fieldToCol[f] !== undefined ? fieldToCol[f] : -1;
        }
        if (td.querySelector('.remove-btn:not(.cat-remove-btn)')) return 4;
        return -1;
    }

    // Criar td para uma coluna específica
    function createCellForColumn(col, value) {
        var td = document.createElement('td');
        if (col >= 0 && col <= 3) {
            var field = colToField[col];
            td.innerHTML = '<input type="text" value="' + escapeHtml(value || '') + '" data-field="' + field + '" placeholder="' + colPlaceholders[field] + '">';
        } else {
            td.innerHTML = '<button class="remove-btn" onclick="removerEnsaioLinha(this)" title="Remover">&times;</button>';
        }
        return td;
    }

    // Obter td por coluna lógica numa row (funciona com tds em falta)
    function getTdByColumn(tr, col) {
        var tds = tr.querySelectorAll('td');
        for (var i = 0; i < tds.length; i++) {
            if (getTdColumnIndex(tds[i]) === col) return tds[i];
        }
        return null;
    }

    // Inserir td na posição correta numa row (considerando tds em falta)
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
            if (colIdx >= 4 || colIdx < 0) return;
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
            var input = td ? td.querySelector('input') : null;
            if (input) values.push(input.value.trim());
        }
        var allSame = values.length > 0 && values.every(function(v) { return v === values[0]; });
        var mergedValue = allSame ? values[0] : '';

        // Sincronizar valor em todas as células do merge
        for (var r = startRow; r <= endRow && r < dataRows.length; r++) {
            var td = getTdByColumn(dataRows[r], col);
            var input = td ? td.querySelector('input') : null;
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
        var dataRows = getDataRows(tbody);

        // Recolher valores dos masters antes de resetar
        var masterValues = {}; // chave: "col-dataRowIdx"
        dataRows.forEach(function(tr, rowIdx) {
            tr.querySelectorAll('td[rowspan]').forEach(function(td) {
                var col = getTdColumnIndex(td);
                var span = parseInt(td.getAttribute('rowspan')) || 1;
                if (span > 1 && col >= 0 && col <= 3) {
                    var input = td.querySelector('input');
                    var value = input ? input.value : '';
                    for (var r = rowIdx + 1; r < rowIdx + span && r < dataRows.length; r++) {
                        masterValues[col + '-' + r] = value;
                    }
                }
            });
        });

        // Resetar rowspan e limpar classes/tools (só data rows)
        dataRows.forEach(function(tr) {
            tr.querySelectorAll('td').forEach(function(td) {
                td.classList.remove('merge-master', 'merge-slave', 'merge-slave-last');
                td.style.position = '';
                td.style.verticalAlign = '';
                if (td.hasAttribute('rowspan')) td.removeAttribute('rowspan');
                var input = td.querySelector('input');
                if (input) { input.style.visibility = ''; input.style.textAlign = ''; }
                var tools = td.querySelector('.merge-tools');
                if (tools) tools.remove();
            });
        });

        // Recriar tds em falta (slave cells que foram removidos)
        dataRows.forEach(function(tr, rowIdx) {
            var existingCols = {};
            tr.querySelectorAll('td').forEach(function(td) {
                var col = getTdColumnIndex(td);
                if (col >= 0 && col <= 4) existingCols[col] = true;
            });
            for (var col = 0; col <= 3; col++) {
                if (!existingCols[col]) {
                    var value = masterValues[col + '-' + rowIdx] || '';
                    var newTd = createCellForColumn(col, value);
                    insertTdAtColumn(tr, newTd, col);
                }
            }
            if (!existingCols[4]) {
                var actionTd = createCellForColumn(4, '');
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
            var masterInput = masterTd.querySelector('input');
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

    // Inicializar merge handlers em todas as tabelas existentes
    document.querySelectorAll('.seccao-ensaios-table').forEach(function(table) {
        initMergeHandlers(table);
        applyMergesVisual(table);
    });

    // Ensaios selector modal
    var selectorTargetBlock = null;
    var selectorMode = 'new'; // 'new' = nova secção, 'add' = adicionar a secção existente

    function abrirSelectorEnsaios() {
        selectorMode = 'new';
        selectorTargetBlock = null;
        popularBancoEnsaios();
        limparCheckboxesSelector();
        document.getElementById('modalSelectorEnsaios').classList.remove('hidden');
    }

    function abrirSelectorEnsaiosParaSeccao(btn) {
        selectorMode = 'add';
        selectorTargetBlock = btn.closest('.seccao-block');
        popularBancoEnsaios();
        limparCheckboxesSelector();
        document.getElementById('modalSelectorEnsaios').classList.remove('hidden');
    }

    function fecharSelectorEnsaios() {
        document.getElementById('modalSelectorEnsaios').classList.add('hidden');
    }

    function limparCheckboxesSelector() {
        document.querySelectorAll('#modalSelectorEnsaios input[type="checkbox"]').forEach(function(cb) {
            cb.checked = false;
        });
    }

    function switchEnsaiosTab(tab) {
        var bancoGrid = document.getElementById('ensaiosBancoGrid');
        var templateGrid = document.getElementById('ensaiosTemplateGrid');
        var tabBanco = document.getElementById('tabEnsaiosBanco');
        var tabTemplate = document.getElementById('tabEnsaiosTemplate');

        if (tab === 'banco') {
            bancoGrid.style.display = '';
            templateGrid.style.display = 'none';
            tabBanco.className = 'btn btn-sm btn-primary';
            tabTemplate.className = 'btn btn-sm btn-secondary';
        } else {
            bancoGrid.style.display = 'none';
            templateGrid.style.display = '';
            tabBanco.className = 'btn btn-sm btn-secondary';
            tabTemplate.className = 'btn btn-sm btn-primary';
        }
    }

    function popularBancoEnsaios() {
        var grid = document.getElementById('ensaiosBancoGrid');
        var rows = document.querySelectorAll('#paramRows .param-row');

        if (rows.length === 0) {
            grid.innerHTML = '<div class="empty-state" style="padding: var(--spacing-md);"><p class="muted">Nenhum ensaio no banco. Defina ensaios no tab "Ensaios" primeiro, ou use o Template Padrão.</p></div>';
            return;
        }

        // Group by category
        var cats = {};
        rows.forEach(function(row) {
            var inputs = row.querySelectorAll('input');
            if (inputs.length >= 5 && inputs[1].value.trim()) {
                var cat = inputs[0].value.trim() || 'Sem categoria';
                if (!cats[cat]) cats[cat] = [];
                cats[cat].push({
                    ensaio: inputs[1].value,
                    especificacao: inputs[2].value,
                    norma: inputs[3].value,
                    nqa: inputs[4].value
                });
            }
        });

        var html = '';
        Object.keys(cats).forEach(function(cat) {
            html += '<div class="ensaios-cat-group">';
            html += '<div class="ensaios-cat-title">' + escapeHtml(cat) + '<button onclick="toggleCatEnsaiosBanco(this, \'' + escapeHtml(cat).replace(/'/g, "\\'") + '\')">Todos</button></div>';
            cats[cat].forEach(function(ens) {
                html += '<label class="ensaio-check-item">';
                html += '<input type="checkbox" name="sel_ensaio_banco" data-cat="' + escapeHtml(cat) + '" data-ensaio="' + escapeHtml(ens.ensaio) + '" data-norma="' + escapeHtml(ens.norma) + '" data-spec="' + escapeHtml(ens.especificacao) + '" data-nqa="' + escapeHtml(ens.nqa) + '">';
                html += '<div class="ensaio-info"><div class="ensaio-name">' + escapeHtml(ens.ensaio) + '</div>';
                html += '<div class="ensaio-detail">' + escapeHtml(ens.norma) + ' &mdash; ' + escapeHtml(ens.especificacao) + '</div></div>';
                html += '</label>';
            });
            html += '</div>';
        });

        grid.innerHTML = html;
    }

    function toggleCatEnsaios(btn, cat) {
        var grid = document.getElementById('ensaiosTemplateGrid');
        var checks = grid.querySelectorAll('input[data-cat="' + cat + '"]');
        var allChecked = Array.from(checks).every(function(c) { return c.checked; });
        checks.forEach(function(c) { c.checked = !allChecked; });
    }

    function toggleCatEnsaiosBanco(btn, cat) {
        var grid = document.getElementById('ensaiosBancoGrid');
        var checks = grid.querySelectorAll('input[data-cat="' + cat + '"]');
        var allChecked = Array.from(checks).every(function(c) { return c.checked; });
        checks.forEach(function(c) { c.checked = !allChecked; });
    }

    function confirmarSelectorEnsaios() {
        var ensaios = [];

        // Collect from banco
        document.querySelectorAll('#ensaiosBancoGrid input[name="sel_ensaio_banco"]:checked').forEach(function(cb) {
            ensaios.push({
                categoria: cb.getAttribute('data-cat') || '',
                ensaio: cb.getAttribute('data-ensaio') || '',
                especificacao: cb.getAttribute('data-spec') || '',
                norma: cb.getAttribute('data-norma') || '',
                nqa: cb.getAttribute('data-nqa') || ''
            });
        });

        // Collect from template
        document.querySelectorAll('#ensaiosTemplateGrid input[name="sel_ensaio"]:checked').forEach(function(cb) {
            ensaios.push({
                categoria: cb.getAttribute('data-cat') || '',
                ensaio: cb.getAttribute('data-ensaio') || '',
                especificacao: cb.getAttribute('data-spec') || '',
                norma: cb.getAttribute('data-norma') || '',
                nqa: ''
            });
        });

        if (ensaios.length === 0) {
            alert('Selecione pelo menos um ensaio.');
            return;
        }

        if (selectorMode === 'add' && selectorTargetBlock) {
            // Add rows to existing ensaios section with cat-headers
            var tbody = selectorTargetBlock.querySelector('.ensaios-tbody');
            adicionarEnsaiosComCategorias(tbody, ensaios);
        } else {
            // Create new ensaios section
            adicionarSeccaoEnsaios(ensaios);
        }

        fecharSelectorEnsaios();
        marcarAlterado();
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
        container.querySelectorAll('.seccao-block').forEach(function(block, i) {
            block.querySelector('.seccao-numero').textContent = (i + 1) + '.';
        });
    }

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
        document.getElementById('aiModal').classList.add('hidden');
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

        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'ai_assist',
                mode: aiCurrentMode,
                prompt: prompt,
                conteudo: conteudo,
                titulo: titulo
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Gerar';
            aiCurrentBlock.querySelectorAll('.btn-ai').forEach(function(b) { b.classList.remove('loading'); });

            if (result.success && result.data && result.data.content) {
                var newContent = result.data.content;

                // Inserir conteúdo no editor TinyMCE
                if (tinyEditors[editorId]) {
                    tinyEditors[editorId].setContent(newContent);
                    tinyEditors[editorId].save();
                } else {
                    document.getElementById(editorId).value = newContent;
                }

                fecharAIModal();
                marcarAlterado();
                showToast('Conteúdo gerado com sucesso.', 'success');
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
    syncColorInputs('cfg_cor_linhas', 'cfg_cor_linhas_hex');
    syncColorInputs('cfg_cor_nome', 'cfg_cor_nome_hex');

    document.getElementById('cfg_tamanho_titulos').addEventListener('input', function() {
        document.getElementById('cfg_tamanho_titulos_val').textContent = this.value + 'pt';
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
        var corLinhas = document.getElementById('cfg_cor_linhas').value;
        var tamTitulos = document.getElementById('cfg_tamanho_titulos').value;
        var corNome = document.getElementById('cfg_cor_nome').value;
        var tamNome = document.getElementById('cfg_tamanho_nome').value;

        // Preview no tab config - secção title
        var prev = document.getElementById('cfgPreviewTitle');
        prev.style.color = corTitulos;
        prev.style.fontSize = tamTitulos + 'pt';
        prev.style.borderBottomColor = corLinhas;

        // Preview no tab config - nome
        var prevNome = document.getElementById('cfgPreviewNome');
        if (prevNome) {
            prevNome.style.color = corNome;
            prevNome.style.fontSize = tamNome + 'pt';
        }

        // Aplicar na sidebar preview
        configVisual.cor_titulos = corTitulos;
        configVisual.cor_linhas = corLinhas;
        configVisual.tamanho_titulos = tamTitulos;
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
            cor_linhas: document.getElementById('cfg_cor_linhas').value,
            tamanho_titulos: document.getElementById('cfg_tamanho_titulos').value,
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

        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
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
    document.querySelectorAll('#mainTabs .tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var targetId = this.getAttribute('data-tab');

            document.querySelectorAll('#mainTabs .tab').forEach(function(t) {
                t.classList.remove('active');
            });
            this.classList.add('active');

            document.querySelectorAll('.tab-panel').forEach(function(panel) {
                panel.classList.remove('active');
            });
            document.getElementById('panel-' + targetId).classList.add('active');
        });
    });

    // ============================================================
    // TOAST NOTIFICATIONS
    // ============================================================
    function showToast(msg, type) {
        type = type || 'info';
        var container = document.getElementById('toast-container');
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.textContent = msg;
        container.appendChild(toast);
        setTimeout(function() { toast.remove(); }, 3500);
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
    var legSelectorMode = 'tab'; // 'tab' = adicionar ao tab legislação, 'conteudo' = criar secção texto

    function adicionarLegManual() {
        var tbody = document.getElementById('legislacaoRows');
        var empty = document.getElementById('legEmpty');
        if (empty) empty.remove();
        var tr = document.createElement('tr');
        tr.className = 'leg-row';
        tr.innerHTML = '<td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="" data-field="leg_norma" style="width:100%; border:none; background:transparent; font-weight:600; font-size:13px;" placeholder="Legislação / Norma"></td>' +
            '<td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="" data-field="leg_rolhas" style="width:100%; border:none; background:transparent; font-size:12px; color:var(--color-muted);" placeholder="Rolhas a que se aplica"></td>' +
            '<td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="" data-field="leg_resumo" style="width:100%; border:none; background:transparent; font-size:12px; color:var(--color-muted);" placeholder="Resumo"></td>' +
            '<td style="padding:6px 10px; border-bottom:1px solid var(--color-border); text-align:center;"><button class="remove-btn" onclick="removerLegRow(this)" title="Remover">&times;</button></td>';
        tbody.appendChild(tr);
        marcarAlterado();
    }

    function removerLegRow(btn) {
        var tr = btn.closest('tr');
        tr.remove();
        var tbody = document.getElementById('legislacaoRows');
        if (tbody.querySelectorAll('.leg-row').length === 0) {
            tbody.innerHTML = '<tr id="legEmpty"><td colspan="4" class="muted" style="text-align:center; padding:20px;">Nenhuma legislação adicionada.</td></tr>';
        }
        marcarAlterado();
    }

    function abrirSelectorLegislacao() {
        legSelectorMode = 'tab';
        popularSelectorLeg();
        document.getElementById('modalSelectorLeg').classList.remove('hidden');
    }

    function abrirSelectorLegConteudo() {
        legSelectorMode = 'conteudo';
        popularSelectorLeg();
        document.getElementById('modalSelectorLeg').classList.remove('hidden');
    }

    function popularSelectorLeg() {
        var grid = document.getElementById('legSelectorGrid');
        grid.innerHTML = '<div class="muted" style="padding:var(--spacing-md); text-align:center;">A carregar...</div>';
        fetch('<?= BASE_PATH ?>/api.php?action=get_legislacao_banco')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.legislacao || data.legislacao.length === 0) {
                grid.innerHTML = '<div class="muted" style="padding:var(--spacing-md); text-align:center;">Nenhuma legislação no banco. Contacte o administrador.</div>';
                return;
            }
            var html = '';
            data.legislacao.forEach(function(leg) {
                html += '<label class="ensaio-check-item" style="display:flex; gap:8px; padding:8px 10px; border-bottom:1px solid var(--color-border); cursor:pointer;">' +
                    '<input type="checkbox" name="sel_leg" data-norma="' + escapeHtml(leg.legislacao_norma) + '" data-rolhas="' + escapeHtml(leg.rolhas_aplicaveis || '') + '" data-resumo="' + escapeHtml(leg.resumo || '') + '">' +
                    '<div style="flex:1;">' +
                    '<div style="font-weight:600; font-size:13px;">' + escapeHtml(leg.legislacao_norma) + '</div>' +
                    '<div style="font-size:11px; color:var(--color-muted);">' + escapeHtml(leg.rolhas_aplicaveis || '') + '</div>' +
                    '</div></label>';
            });
            grid.innerHTML = html;
        });
    }

    function confirmarSelectorLeg() {
        var checks = document.querySelectorAll('#legSelectorGrid input[name="sel_leg"]:checked');
        if (checks.length === 0) { alert('Selecione pelo menos uma legislação.'); return; }

        if (legSelectorMode === 'tab') {
            // Adicionar ao tab Legislação como linhas editáveis
            var tbody = document.getElementById('legislacaoRows');
            var empty = document.getElementById('legEmpty');
            if (empty) empty.remove();
            checks.forEach(function(cb) {
                var tr = document.createElement('tr');
                tr.className = 'leg-row';
                tr.innerHTML = '<td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="' + escapeHtml(cb.getAttribute('data-norma')) + '" data-field="leg_norma" style="width:100%; border:none; background:transparent; font-weight:600; font-size:13px;"></td>' +
                    '<td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="' + escapeHtml(cb.getAttribute('data-rolhas')) + '" data-field="leg_rolhas" style="width:100%; border:none; background:transparent; font-size:12px; color:var(--color-muted);"></td>' +
                    '<td style="padding:6px 10px; border-bottom:1px solid var(--color-border);"><input type="text" value="' + escapeHtml(cb.getAttribute('data-resumo')) + '" data-field="leg_resumo" style="width:100%; border:none; background:transparent; font-size:12px; color:var(--color-muted);"></td>' +
                    '<td style="padding:6px 10px; border-bottom:1px solid var(--color-border); text-align:center;"><button class="remove-btn" onclick="removerLegRow(this)" title="Remover">&times;</button></td>';
                tbody.appendChild(tr);
            });
        } else {
            // Criar nova secção de texto com bullet points
            var ul = '<ul>';
            checks.forEach(function(cb) {
                ul += '<li>' + escapeHtml(cb.getAttribute('data-norma')) + '</li>';
            });
            ul += '</ul>';
            adicionarSeccaoTexto('Legislação Aplicável', ul);
        }

        document.getElementById('modalSelectorLeg').classList.add('hidden');
        marcarAlterado();
    }

    function adicionarSeccaoTexto(titulo, conteudo) {
        var container = document.getElementById('seccoesContainer');
        var idx = seccaoCounter++;
        var result = criarSeccao(titulo, conteudo, idx);
        container.appendChild(result.block);
        var empty = document.getElementById('seccoesEmpty');
        if (empty) empty.remove();
        initSeccaoEditor(result.editorId);
        renumerarSeccoes();
    }

    function recolherLegislacao() {
        var rows = document.querySelectorAll('#legislacaoRows .leg-row');
        var arr = [];
        rows.forEach(function(tr) {
            var norma = tr.querySelector('input[data-field="leg_norma"]');
            var rolhas = tr.querySelector('input[data-field="leg_rolhas"]');
            var resumo = tr.querySelector('input[data-field="leg_resumo"]');
            if (norma && norma.value.trim()) {
                arr.push({
                    legislacao_norma: norma.value.trim(),
                    rolhas_aplicaveis: rolhas ? rolhas.value.trim() : '',
                    resumo: resumo ? resumo.value.trim() : ''
                });
            }
        });
        return arr;
    }

    function recolherDados() {
        var data = {
            id: especId,
            numero: document.getElementById('numero').value,
            titulo: document.getElementById('titulo').value,
            versao: document.getElementById('versao').value,
            estado: document.getElementById('estado').value,
            tipo_doc: document.getElementById('tipo_doc').value,
            produto_ids: getCheckedValues('produtosWrap'),
            cliente_id: document.getElementById('cliente_id') ? document.getElementById('cliente_id').value : '',
            fornecedor_ids: document.getElementById('fornecedoresWrap') ? getCheckedValues('fornecedoresWrap') : [],
            data_emissao: document.getElementById('data_emissao').value,
            data_revisao: document.getElementById('data_revisao').value,
            data_validade: document.getElementById('data_validade').value,
            senha_publica: document.getElementById('senha_publica').value,
            codigo_acesso: document.getElementById('codigo_acesso').value,
            config_visual: JSON.stringify(recolherConfigVisual()),
            legislacao_json: JSON.stringify(recolherLegislacao()),
            seccoes: [],
            parametros: [],
            classes: [],
            defeitos: []
        };

        // Secções dinâmicas (texto + ensaios)
        document.querySelectorAll('#seccoesContainer .seccao-block').forEach(function(block, i) {
            var titulo = block.querySelector('.seccao-titulo').value;
            var tipo = block.getAttribute('data-tipo') || 'texto';
            var conteudo = '';

            if (tipo === 'ensaios') {
                // Recolher dados: cat-rows definem a categoria, data rows têm 4 campos
                var tbl = block.querySelector('.seccao-ensaios-table');
                var tbody = tbl ? tbl.querySelector('.ensaios-tbody') : null;
                var merges = tbl ? getTableMerges(tbl) : [];
                var ensaiosArr = [];
                var currentCat = '';
                var dataRowIdx = 0;

                if (tbody) {
                    var allTrs = tbody.querySelectorAll('tr');
                    var dataRows = getDataRows(tbody);
                    allTrs.forEach(function(tr) {
                        if (tr.classList.contains('ensaio-cat-row')) {
                            var catInput = tr.querySelector('input[data-field="cat-header"]');
                            currentCat = catInput ? catInput.value : '';
                        } else {
                            var row = { categoria: currentCat, ensaio: '', especificacao: '', norma: '', nqa: '' };
                            tr.querySelectorAll('input[data-field]').forEach(function(input) {
                                var field = input.getAttribute('data-field');
                                if (row.hasOwnProperty(field)) row[field] = input.value;
                            });
                            // Para colunas merged (slave tds removidos), obter valor do master
                            merges.forEach(function(m) {
                                if (dataRowIdx > m.row && dataRowIdx < m.row + m.span) {
                                    var field = colToField[m.col];
                                    if (field) {
                                        var masterTr = dataRows[m.row];
                                        if (masterTr) {
                                            var masterInput = masterTr.querySelector('input[data-field="' + field + '"]');
                                            if (masterInput) row[field] = masterInput.value;
                                        }
                                    }
                                }
                            });
                            ensaiosArr.push(row);
                            dataRowIdx++;
                        }
                    });
                }

                // Ler larguras das colunas (excluindo coluna de ações)
                var colWidths = [];
                var ths = block.querySelectorAll('.seccao-ensaios-table thead th');
                for (var ci = 0; ci < ths.length - 1; ci++) {
                    colWidths.push(parseFloat(ths[ci].style.width) || 0);
                }
                conteudo = JSON.stringify({ colWidths: colWidths, rows: ensaiosArr, merges: merges });
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
                ordem: i
            });
        });

        // Parâmetros
        document.querySelectorAll('#paramRows .param-row').forEach(function(row) {
            var inputs = row.querySelectorAll('input');
            if (inputs.length >= 5) {
                data.parametros.push({
                    id: row.getAttribute('data-param-id') || '',
                    categoria: inputs[0].value,
                    ensaio: inputs[1].value,
                    valor_especificado: inputs[2].value,
                    metodo: inputs[3].value,
                    amostra_nqa: inputs[4].value
                });
            }
        });

        // Classes
        document.querySelectorAll('#classRows .class-row').forEach(function(row) {
            var inputs = row.querySelectorAll('input');
            if (inputs.length >= 3) {
                data.classes.push({
                    id: row.getAttribute('data-class-id') || '',
                    classe: inputs[0].value,
                    defeitos_max: inputs[1].value,
                    descricao: inputs[2].value
                });
            }
        });

        // Defeitos
        ['critico', 'maior', 'menor'].forEach(function(sev) {
            var containerId = 'defectRows' + sev.charAt(0).toUpperCase() + sev.slice(1);
            document.querySelectorAll('#' + containerId + ' .defect-row').forEach(function(row) {
                var inputs = row.querySelectorAll('input');
                if (inputs.length >= 2) {
                    data.defeitos.push({
                        id: row.getAttribute('data-defect-id') || '',
                        severidade: sev,
                        nome: inputs[0].value,
                        descricao: inputs[1].value
                    });
                }
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
        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, data: data })
        })
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
                    if (btnPdf) { btnPdf.href = BASE_PATH + '/pdf.php?id=' + especId; btnPdf.style.display = ''; }
                    if (btnVer) { btnVer.href = BASE_PATH + '/ver.php?id=' + especId; btnVer.style.display = ''; }
                }

                // 2. Save parameters, classes, defeitos in parallel
                var promises = [];

                if (data.parametros.length > 0 || document.querySelectorAll('#paramRows .param-row').length === 0) {
                    var paramPayload = {
                        action: 'save_parametros',
                        especificacao_id: especId,
                        parametros: data.parametros.map(function(p) {
                            return {
                                categoria: p.categoria,
                                ensaio: p.ensaio,
                                especificacao_valor: p.valor_especificado || p.especificacao_valor || '',
                                metodo: p.metodo,
                                amostra_nqa: p.amostra_nqa,
                                ordem: p.ordem || 0
                            };
                        })
                    };
                    promises.push(
                        fetch(BASE_PATH + '/api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(paramPayload)
                        }).then(function(r) { return r.json(); })
                    );
                }

                if (data.classes.length > 0 || document.querySelectorAll('#classRows .class-row').length === 0) {
                    promises.push(
                        fetch(BASE_PATH + '/api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'save_classes',
                                especificacao_id: especId,
                                classes: data.classes
                            })
                        }).then(function(r) { return r.json(); })
                    );
                }

                if (data.defeitos.length > 0 || true) {
                    var defeitosForApi = data.defeitos.map(function(d) {
                        return {
                            nome: d.nome,
                            tipo: d.severidade || d.tipo,
                            descricao: d.descricao,
                            ordem: d.ordem || 0
                        };
                    });
                    promises.push(
                        fetch(BASE_PATH + '/api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'save_defeitos',
                                especificacao_id: especId,
                                defeitos: defeitosForApi
                            })
                        }).then(function(r) { return r.json(); })
                    );
                }

                // Save sections
                promises.push(
                    fetch(BASE_PATH + '/api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'save_seccoes',
                            especificacao_id: especId,
                            seccoes: data.seccoes
                        })
                    }).then(function(r) { return r.json(); })
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
        } else {
            pill.classList.add('pill-muted');
        }
        pill.textContent = estado.charAt(0).toUpperCase() + estado.slice(1);
    }

    // ============================================================
    // PARAMETROS - ADICIONAR / REMOVER
    // ============================================================
    function criarLinhaParametro(categoria, ensaio, especificacao, metodo, amostra) {
        categoria = categoria || '';
        ensaio = ensaio || '';
        especificacao = especificacao || '';
        metodo = metodo || '';
        amostra = amostra || '';

        var row = document.createElement('div');
        row.className = 'param-row';
        row.setAttribute('data-param-id', '');

        row.innerHTML =
            '<input type="text" name="param_categoria[]" value="' + escapeHtml(categoria) + '" placeholder="Categoria" class="param-field">' +
            '<input type="text" name="param_ensaio[]" value="' + escapeHtml(ensaio) + '" placeholder="Nome do ensaio" class="param-field">' +
            '<input type="text" name="param_especificacao[]" value="' + escapeHtml(especificacao) + '" placeholder="Valor / Limites" class="param-field">' +
            '<input type="text" name="param_metodo[]" value="' + escapeHtml(metodo) + '" placeholder="Norma / Método" class="param-field">' +
            '<input type="text" name="param_amostra[]" value="' + escapeHtml(amostra) + '" placeholder="NQA" class="param-field">' +
            '<button class="remove-btn" onclick="removerLinha(this)" title="Remover">&times;</button>';

        row.querySelectorAll('input, select').forEach(function(el) {
            el.addEventListener('input', marcarAlterado);
            el.addEventListener('change', marcarAlterado);
        });

        return row;
    }

    function adicionarParametro() {
        var container = document.getElementById('paramRows');
        var row = criarLinhaParametro();
        container.appendChild(row);

        var empty = document.getElementById('paramEmpty');
        if (empty) empty.remove();

        marcarAlterado();
        row.querySelector('input').focus();
    }

    // ============================================================
    // CLASSES - ADICIONAR / REMOVER
    // ============================================================
    function criarLinhaClasse(nome, defeitos, descricao) {
        nome = nome || '';
        defeitos = defeitos || '';
        descricao = descricao || '';

        var row = document.createElement('div');
        row.className = 'class-row';
        row.setAttribute('data-class-id', '');
        row.innerHTML =
            '<input type="text" name="class_nome[]" value="' + escapeHtml(nome) + '" placeholder="Nome da classe" class="param-field">' +
            '<input type="number" name="class_defeitos[]" value="' + escapeHtml(String(defeitos)) + '" placeholder="%" class="param-field">' +
            '<input type="text" name="class_descricao[]" value="' + escapeHtml(descricao) + '" placeholder="Descrição" class="param-field">' +
            '<button class="remove-btn" onclick="removerLinha(this)" title="Remover">&times;</button>';

        row.querySelectorAll('input').forEach(function(el) {
            el.addEventListener('input', marcarAlterado);
        });

        return row;
    }

    function adicionarClasse() {
        var container = document.getElementById('classRows');
        var row = criarLinhaClasse();
        container.appendChild(row);

        var empty = document.getElementById('classEmpty');
        if (empty) empty.remove();

        marcarAlterado();
        row.querySelector('input').focus();
    }

    function carregarClassesPadrao() {
        var container = document.getElementById('classRows');
        var classesPadrao = <?= json_encode($classesPadrao) ?>;

        classesPadrao.forEach(function(c) {
            var row = criarLinhaClasse(c.classe, c.defeitos_max, c.descricao);
            container.appendChild(row);
        });

        var empty = document.getElementById('classEmpty');
        if (empty) empty.remove();

        marcarAlterado();
        showToast(classesPadrao.length + ' classes adicionadas.', 'success');
    }

    // ============================================================
    // DEFEITOS - ADICIONAR / REMOVER
    // ============================================================
    function criarLinhaDefeito(severidade, nome, descricao) {
        nome = nome || '';
        descricao = descricao || '';

        var row = document.createElement('div');
        row.className = 'defect-row';
        row.setAttribute('data-defect-id', '');
        row.innerHTML =
            '<input type="text" name="defect_nome_' + severidade + '[]" value="' + escapeHtml(nome) + '" placeholder="Nome do defeito" class="param-field">' +
            '<input type="text" name="defect_desc_' + severidade + '[]" value="' + escapeHtml(descricao) + '" placeholder="Descrição" class="param-field">' +
            '<button class="remove-btn" onclick="removerLinha(this)" title="Remover">&times;</button>';

        row.querySelectorAll('input').forEach(function(el) {
            el.addEventListener('input', marcarAlterado);
        });

        return row;
    }

    function adicionarDefeito() {
        adicionarDefeitoSeveridade('critico');
    }

    function adicionarDefeitoSeveridade(severidade) {
        var containerId = 'defectRows' + severidade.charAt(0).toUpperCase() + severidade.slice(1);
        var container = document.getElementById(containerId);
        var row = criarLinhaDefeito(severidade);
        container.appendChild(row);
        marcarAlterado();
        row.querySelector('input').focus();
    }

    function carregarDefeitosPadrao() {
        var defeitosPadrao = <?= json_encode($defeitosPadrao) ?>;
        var total = 0;

        Object.keys(defeitosPadrao).forEach(function(severidade) {
            var containerId = 'defectRows' + severidade.charAt(0).toUpperCase() + severidade.slice(1);
            var container = document.getElementById(containerId);
            var defeitos = defeitosPadrao[severidade];

            Object.keys(defeitos).forEach(function(nome) {
                var row = criarLinhaDefeito(severidade, nome, defeitos[nome]);
                container.appendChild(row);
                total++;
            });
        });

        marcarAlterado();
        showToast(total + ' defeitos adicionados.', 'success');
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
    // MODAL TEMPLATES
    // ============================================================
    function abrirModalTemplates() {
        document.getElementById('modalTemplates').classList.remove('hidden');
        // Desmarcar todos
        document.querySelectorAll('#templateGrid input[type="checkbox"]').forEach(function(cb) {
            cb.checked = false;
        });
    }

    function fecharModalTemplates() {
        document.getElementById('modalTemplates').classList.add('hidden');
    }

    function selecionarCategoria(categoria) {
        document.querySelectorAll('#templateGrid input[type="checkbox"]').forEach(function(cb) {
            if (cb.value.indexOf(categoria + '|') === 0) {
                cb.checked = !cb.checked;
            }
        });
    }

    function adicionarTemplatesSelecionados() {
        var container = document.getElementById('paramRows');
        var count = 0;

        document.querySelectorAll('#templateGrid input[type="checkbox"]:checked').forEach(function(cb) {
            var parts = cb.value.split('|');
            if (parts.length >= 4) {
                var row = criarLinhaParametro(parts[0], parts[1], parts[3], parts[2], '');
                container.appendChild(row);
                count++;
            }
        });

        var empty = document.getElementById('paramEmpty');
        if (empty) empty.remove();

        fecharModalTemplates();
        marcarAlterado();

        if (count > 0) {
            showToast(count + ' parâmetro(s) adicionado(s).', 'success');
        } else {
            showToast('Nenhum parâmetro selecionado.', 'warning');
        }
    }

    // ============================================================
    // PRODUCT TEMPLATE AUTO-LOAD (multi-produto)
    // ============================================================
    function loadProductTemplates(produtoId) {
        fetch(BASE_PATH + '/api.php?action=load_product_templates&produto_id=' + produtoId)
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success && result.data && result.data.length > 0) {
                var existingRows = document.querySelectorAll('#paramRows .param-row').length;
                var msg = 'O produto selecionado tem ' + result.data.length + ' parâmetro(s) pré-definido(s).';
                if (existingRows > 0) {
                    msg += '\nJá existem ' + existingRows + ' parâmetro(s) definidos.';
                    msg += '\nDeseja adicionar os templates do produto?';
                } else {
                    msg += '\nDeseja carregar estes parâmetros?';
                }

                if (confirm(msg)) {
                    var container = document.getElementById('paramRows');
                    result.data.forEach(function(t) {
                        var row = criarLinhaParametro(
                            t.categoria || '',
                            t.ensaio || '',
                            t.especificacao_valor || '',
                            t.metodo || '',
                            t.amostra_nqa || ''
                        );
                        container.appendChild(row);
                    });

                    var empty = document.getElementById('paramEmpty');
                    if (empty) empty.remove();

                    marcarAlterado();
                    showToast(result.data.length + ' parâmetro(s) do produto carregado(s).', 'success');
                }
            }
        })
        .catch(function(err) {
            console.error('Erro ao verificar templates do produto:', err);
        });
    }

    // Observar mudanças nos checkboxes de produto
    document.querySelectorAll('#produtosWrap input[type="checkbox"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (this.checked) {
                loadProductTemplates(this.value);
            }
        });
    });

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
    // FICHEIROS - UPLOAD
    // ============================================================
    var uploadZone = document.getElementById('uploadZone');
    var fileInput = document.getElementById('fileInput');

    uploadZone.addEventListener('click', function() {
        fileInput.click();
    });

    uploadZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadZone.classList.add('dragover');
    });

    uploadZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadZone.classList.remove('dragover');
    });

    uploadZone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadZone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            enviarFicheiros(e.dataTransfer.files);
        }
    });

    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            enviarFicheiros(this.files);
            this.value = '';
        }
    });

    function enviarFicheiros(files) {
        if (especId === 0) {
            showToast('Guarde a especificação antes de anexar ficheiros.', 'warning');
            return;
        }

        for (var i = 0; i < files.length; i++) {
            enviarFicheiro(files[i]);
        }
    }

    function enviarFicheiro(file) {
        var progressEl = document.getElementById('uploadProgress');
        var barEl = document.getElementById('uploadBar');
        var nameEl = document.getElementById('uploadFileName');
        var percentEl = document.getElementById('uploadPercent');

        progressEl.classList.remove('hidden');
        nameEl.textContent = file.name;
        percentEl.textContent = '0%';
        barEl.style.width = '0%';

        var formData = new FormData();
        formData.append('action', 'upload_ficheiro');
        formData.append('especificacao_id', especId);
        formData.append('ficheiro', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', BASE_PATH + '/api.php', true);

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
                    adicionarFicheiroLista(result.ficheiro);
                    showToast('Ficheiro "' + file.name + '" enviado.', 'success');

                    var empty = document.getElementById('fileEmpty');
                    if (empty) empty.remove();
                } else {
                    showToast(result.message || 'Erro ao enviar ficheiro.', 'error');
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

    function adicionarFicheiroLista(ficheiro) {
        var list = document.getElementById('fileList');
        var li = document.createElement('li');
        li.className = 'file-item';
        li.setAttribute('data-file-id', ficheiro.id);
        li.innerHTML =
            '<span class="file-name" title="' + escapeHtml(ficheiro.nome_original) + '">' + escapeHtml(ficheiro.nome_original) + '</span>' +
            '<span class="file-size">' + formatFileSize(ficheiro.tamanho) + '</span>' +
            '<span class="muted">' + (ficheiro.data || 'Agora') + '</span>' +
            '<div class="flex gap-sm" style="margin-left: var(--spacing-md);">' +
                '<a href="' + BASE_PATH + '/api.php?action=download_ficheiro&id=' + ficheiro.id + '" class="btn btn-ghost btn-sm" title="Descarregar">&#11015;</a>' +
                '<button class="btn btn-danger btn-sm" onclick="removerFicheiro(' + ficheiro.id + ')" title="Remover">&times;</button>' +
            '</div>';
        list.appendChild(li);
    }

    function removerFicheiro(id) {
        if (!confirm('Tem a certeza que deseja remover este ficheiro?')) return;

        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remover_ficheiro', id: id })
        })
        .then(function(r) { return r.json(); })
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

    function atualizarShareLink(code) {
        var linkInput = document.getElementById('shareLink');
        if (linkInput) {
            linkInput.value = window.location.origin + BASE_PATH + '/publico.php?code=' + code;
        }
    }

    function copiarLink() {
        var linkInput = document.getElementById('shareLink');
        if (linkInput && linkInput.value) {
            navigator.clipboard.writeText(linkInput.value).then(function() {
                showToast('Link copiado para a área de transferência!', 'success');
            }).catch(function() {
                linkInput.select();
                document.execCommand('copy');
                showToast('Link copiado!', 'success');
            });
        }
    }

    // ============================================================
    // EMAIL - ENVIAR
    // ============================================================
    function enviarEmailEspec() {
        var destinatario = document.getElementById('email_destinatario').value.trim();
        var assunto = document.getElementById('email_assunto').value.trim();
        var mensagem = document.getElementById('email_mensagem').value.trim();
        var incluirLink = document.getElementById('email_incluir_link').checked;
        var anexarPdf = document.getElementById('email_anexar_pdf').checked;

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

        fetch(BASE_PATH + '/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'enviar_email',
                especificacao_id: especId,
                destinatario: destinatario,
                assunto: assunto,
                mensagem: mensagem,
                incluir_link: incluirLink ? 1 : 0,
                anexar_pdf: anexarPdf ? 1 : 0
            })
        })
        .then(function(r) { return r.json(); })
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
        var code = document.getElementById('codigo_acesso').value;
        if (code) {
            atualizarShareLink(code);
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
        document.querySelectorAll('#seccoesContainer .seccao-block').forEach(function(block, i) {
            var titulo = block.querySelector('.seccao-titulo').value || ('Secção ' + (i + 1));
            var tipo = block.getAttribute('data-tipo') || 'texto';

            if (tipo === 'ensaios') {
                // Renderizar tabela de ensaios no preview (4 colunas + cat headers)
                var tbl = block.querySelector('.seccao-ensaios-table');
                var tbody2 = tbl ? tbl.querySelector('.ensaios-tbody') : null;
                if (tbody2 && tbl) {
                    var dataRowsPrev = getDataRows(tbody2);
                    if (dataRowsPrev.length > 0) {
                    var ths = tbl.querySelectorAll('thead th');
                    var cw = [];
                    for (var ci = 0; ci < ths.length - 1; ci++) { cw.push(parseFloat(ths[ci].style.width) || 20); }
                    var cwTotal = cw.reduce(function(a,b){return a+b;},0);
                    var cwPct = cw.map(function(v){ return (v/cwTotal*100).toFixed(1); });
                    var tblMerges = getTableMerges(tbl);
                    var hiddenMap = {}, spanMap = {}, alignMap = {}, rowInMergePrev = {};
                    tblMerges.forEach(function(m) {
                        var k = m.row + '_' + m.col;
                        spanMap[k] = m.span;
                        alignMap[k] = { h: m.hAlign || 'center', v: m.vAlign || 'middle' };
                        for (var mr = m.row + 1; mr < m.row + m.span; mr++) {
                            hiddenMap[mr + '_' + m.col] = true;
                            rowInMergePrev[mr] = true;
                        }
                    });
                    // Recolher categorias por data row
                    var catForRow = {};
                    var curCat = '';
                    tbody2.querySelectorAll('tr').forEach(function(tr2) {
                        if (tr2.classList.contains('ensaio-cat-row')) {
                            var ci3 = tr2.querySelector('input[data-field="cat-header"]');
                            curCat = ci3 ? ci3.value : '';
                        } else {
                            var drIdx = dataRowsPrev.indexOf(tr2);
                            if (drIdx >= 0) catForRow[drIdx] = curCat;
                        }
                    });
                    // Cat headers no preview
                    var prevCatDisplayed = null;
                    var catHeadersPrev = {};
                    for (var ri = 0; ri < dataRowsPrev.length; ri++) {
                        var rc = catForRow[ri] || '';
                        if (rc !== '' && rc !== prevCatDisplayed && !rowInMergePrev[ri]) {
                            catHeadersPrev[ri] = rc;
                            prevCatDisplayed = rc;
                        }
                    }
                    sectionsHtml += '<h4 style="color:' + configVisual.cor_titulos + '; font-size:' + configVisual.tamanho_titulos + 'pt;">' + (i + 1) + '. ' + escapeHtml(titulo) + '</h4>';
                    sectionsHtml += '<table style="width:100%; font-size:9px; border-collapse:collapse; margin-bottom:8px;"><thead><tr>';
                    sectionsHtml += '<th style="width:' + cwPct[0] + '%; padding:3px 4px; text-align:left; font-weight:600; background-color:' + configVisual.cor_titulos + '; color:white;">Ensaio</th>';
                    sectionsHtml += '<th style="width:' + cwPct[1] + '%; padding:3px 4px; text-align:left; font-weight:600; background-color:' + configVisual.cor_titulos + '; color:white;">Espec.</th>';
                    sectionsHtml += '<th style="width:' + cwPct[2] + '%; padding:3px 4px; text-align:left; font-weight:600; background-color:' + configVisual.cor_titulos + '; color:white;">Norma</th>';
                    sectionsHtml += '<th style="width:' + cwPct[3] + '%; padding:3px 4px; text-align:left; font-weight:600; background-color:' + configVisual.cor_titulos + '; color:white;">NQA</th>';
                    sectionsHtml += '</tr></thead><tbody>';
                    dataRowsPrev.forEach(function(tr, rIdx) {
                        if (catHeadersPrev[rIdx]) {
                            sectionsHtml += '<tr><td colspan="4" style="background-color:' + orgCores.light + '; font-weight:600; padding:3px 6px; color:' + orgCores.dark + '; text-align:center;">' + escapeHtml(catHeadersPrev[rIdx]) + '</td></tr>';
                        }
                        var vals = { 0: '', 1: '', 2: '', 3: '' };
                        tr.querySelectorAll('input[data-field]').forEach(function(input) {
                            var ci2 = fieldToCol[input.getAttribute('data-field')];
                            if (ci2 !== undefined) vals[ci2] = input.value;
                        });
                        tblMerges.forEach(function(m) {
                            if (rIdx > m.row && rIdx < m.row + m.span) {
                                var field = colToField[m.col];
                                var masterTr = dataRowsPrev[m.row];
                                if (masterTr && field) {
                                    var mi = masterTr.querySelector('input[data-field="' + field + '"]');
                                    if (mi) vals[m.col] = mi.value;
                                }
                            }
                        });
                        sectionsHtml += '<tr>';
                        for (var c = 0; c < 4; c++) {
                            var key = rIdx + '_' + c;
                            if (hiddenMap[key]) continue;
                            var rs = spanMap[key] ? ' rowspan="' + spanMap[key] + '"' : '';
                            var rstyle = alignMap[key] ? 'vertical-align:' + alignMap[key].v + '; text-align:' + alignMap[key].h + ';' : '';
                            var val = escapeHtml(vals[c]);
                            var fw = (c === 1) ? ' font-weight:bold;' : '';
                            sectionsHtml += '<td' + rs + ' style="padding:2px 4px; border-bottom:1px solid #eee;' + fw + rstyle + '">' + val + '</td>';
                        }
                        sectionsHtml += '</tr>';
                    });
                    sectionsHtml += '</tbody></table>';
                    }
                }
            } else {
                var editorEl = block.querySelector('.seccao-editor');
                if (editorEl) {
                    var conteudo = getEditorContent(editorEl.id);
                    if (conteudo && conteudo.trim()) {
                        sectionsHtml += '<h4 style="color:' + configVisual.cor_titulos + '; border-bottom-color:' + configVisual.cor_linhas + '; font-size:' + configVisual.tamanho_titulos + 'pt;">' + (i + 1) + '. ' + escapeHtml(titulo) + '</h4>';
                        sectionsHtml += '<div class="preview-section-content">' + conteudo + '</div>';
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

    // Avisar antes de sair se houver alterações pendentes
    window.addEventListener('beforeunload', function(e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = 'Tem alterações por guardar. Deseja sair?';
            return e.returnValue;
        }
    });

    // Atalho de teclado: Ctrl+S para guardar
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            guardarTudo();
        }
    });
    </script>
</body>
</html>
