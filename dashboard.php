<?php
/**
 * SpecLab - Cadernos de Encargos
 * Dashboard Principal (Multi-Tenant)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = getCurrentUser();
$db = getDB();
$orgId = $user['org_id'];
$isSA = isSuperAdmin();

// Estatísticas (scoped por org, super_admin vê tudo)
if ($isSA) {
    $stats['total'] = (int)$db->query("SELECT COUNT(*) FROM especificacoes")->fetchColumn();
    $stats['ativos'] = (int)$db->query("SELECT COUNT(*) FROM especificacoes WHERE estado = 'ativo'")->fetchColumn();
    $stats['rascunhos'] = (int)$db->query("SELECT COUNT(*) FROM especificacoes WHERE estado = 'rascunho'")->fetchColumn();
    $stats['clientes'] = (int)$db->query("SELECT COUNT(*) FROM clientes WHERE ativo = 1")->fetchColumn();
    $stats['produtos'] = (int)$db->query("SELECT COUNT(*) FROM produtos WHERE ativo = 1")->fetchColumn();
} else {
    $stmt = $db->prepare("SELECT COUNT(*) FROM especificacoes WHERE organizacao_id = ?");
    $stmt->execute([$orgId]);
    $stats['total'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM especificacoes WHERE estado = 'ativo' AND organizacao_id = ?");
    $stmt->execute([$orgId]);
    $stats['ativos'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM especificacoes WHERE estado = 'rascunho' AND organizacao_id = ?");
    $stmt->execute([$orgId]);
    $stats['rascunhos'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM clientes WHERE ativo = 1 AND organizacao_id = ?");
    $stmt->execute([$orgId]);
    $stats['clientes'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM produtos WHERE ativo = 1 AND (organizacao_id IS NULL OR organizacao_id = ?)");
    $stmt->execute([$orgId]);
    $stats['produtos'] = (int)$stmt->fetchColumn();
}

// Filtros
$filtro_estado = $_GET['estado'] ?? '';
$filtro_produto = $_GET['produto'] ?? '';
$filtro_cliente = $_GET['cliente'] ?? '';
$filtro_fornecedor = $_GET['fornecedor'] ?? '';
$filtro_org = $_GET['org'] ?? '';
$search = $_GET['q'] ?? '';

// Query especificações (scoped)
$where = [];
$params = [];

if (!$isSA) {
    $where[] = 'e.organizacao_id = ?';
    $params[] = $orgId;
} elseif ($filtro_org) {
    $where[] = 'e.organizacao_id = ?';
    $params[] = (int)$filtro_org;
}

if ($filtro_estado) {
    $where[] = 'e.estado = ?';
    $params[] = $filtro_estado;
}
if ($filtro_produto) {
    $where[] = 'e.id IN (SELECT especificacao_id FROM especificacao_produtos WHERE produto_id = ?)';
    $params[] = $filtro_produto;
}
if ($filtro_cliente) {
    $where[] = 'e.cliente_id = ?';
    $params[] = $filtro_cliente;
}
if ($filtro_fornecedor) {
    $where[] = 'e.id IN (SELECT especificacao_id FROM especificacao_fornecedores WHERE fornecedor_id = ?)';
    $params[] = $filtro_fornecedor;
}
if ($search) {
    $where[] = '(e.titulo LIKE ? OR e.numero LIKE ? OR c.nome LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT e.*,
           (SELECT GROUP_CONCAT(p.nome ORDER BY p.nome SEPARATOR ', ')
            FROM especificacao_produtos ep
            INNER JOIN produtos p ON ep.produto_id = p.id
            WHERE ep.especificacao_id = e.id) as produto_nome,
           (SELECT GROUP_CONCAT(f.nome ORDER BY f.nome SEPARATOR ', ')
            FROM especificacao_fornecedores ef
            INNER JOIN fornecedores f ON ef.fornecedor_id = f.id
            WHERE ef.especificacao_id = e.id) as fornecedor_nome,
           c.nome as cliente_nome, c.sigla as cliente_sigla,
           u.nome as criado_por_nome,
           org.nome as org_nome
    FROM especificacoes e
    LEFT JOIN clientes c ON e.cliente_id = c.id
    LEFT JOIN utilizadores u ON e.criado_por = u.id
    LEFT JOIN organizacoes org ON e.organizacao_id = org.id
    $whereSQL
    ORDER BY e.updated_at DESC
");
$stmt->execute($params);
$especificacoes = $stmt->fetchAll();

// Listas para filtros (scoped)
if ($isSA) {
    $produtos = $db->query("SELECT id, nome FROM produtos WHERE ativo = 1 ORDER BY nome")->fetchAll();
    $clientes = $db->query("SELECT id, nome, sigla FROM clientes WHERE ativo = 1 ORDER BY nome")->fetchAll();
    $fornecedores = $db->query("SELECT id, nome, sigla FROM fornecedores WHERE ativo = 1 ORDER BY nome")->fetchAll();
    $organizacoes = $db->query("SELECT id, nome FROM organizacoes WHERE ativo = 1 ORDER BY nome")->fetchAll();
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

    $organizacoes = [];
}

// Alertas de validade (specs ativas com data_validade próxima ou ultrapassada)
$alertasValidade = ['expiradas' => [], 'a_expirar' => []];
foreach ($especificacoes as $e) {
    if ($e['estado'] === 'ativo' && !empty($e['data_validade'])) {
        $validade = strtotime($e['data_validade']);
        $hoje = strtotime('today');
        $em30dias = strtotime('+30 days');
        if ($validade < $hoje) {
            $alertasValidade['expiradas'][] = $e;
        } elseif ($validade <= $em30dias) {
            $alertasValidade['a_expirar'][] = $e;
        }
    }
}
$totalAlertas = count($alertasValidade['expiradas']) + count($alertasValidade['a_expirar']);

$pageTitle = 'Cadernos de Encargos';
$pageSubtitle = 'Sistema de Especificações Técnicas';
$showNav = true;
$activeNav = 'especificacoes';
$breadcrumbs = [['label' => 'Dashboard']];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Cadernos de Encargos</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/img/favicon.svg">
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container">
        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Especificações</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['ativos'] ?></div>
                <div class="stat-label">Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['rascunhos'] ?></div>
                <div class="stat-label">Rascunhos</div>
            </div>
            <?php if ($isSA || !empty($_SESSION['org_tem_clientes'])): ?>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['clientes'] ?></div>
                <div class="stat-label">Clientes</div>
            </div>
            <?php endif; ?>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['produtos'] ?></div>
                <div class="stat-label">Produtos</div>
            </div>
        </div>

        <!-- ALERTAS DE VALIDADE -->
        <?php if ($totalAlertas > 0): ?>
        <div class="no-print" style="margin-bottom: var(--spacing-md);">
            <?php if (count($alertasValidade['expiradas']) > 0): ?>
            <div class="alert alert-error" style="margin-bottom: var(--spacing-sm);">
                <strong><?= count($alertasValidade['expiradas']) ?> especificação(ões) expirada(s):</strong>
                <?php foreach ($alertasValidade['expiradas'] as $exp): ?>
                <a href="<?= BASE_PATH ?>/especificacao.php?id=<?= $exp['id'] ?>" style="display:inline-block; margin:2px 4px; text-decoration:underline; color:inherit;"><?= sanitize($exp['numero']) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (count($alertasValidade['a_expirar']) > 0): ?>
            <div class="alert alert-warning" style="margin-bottom: var(--spacing-sm);">
                <strong><?= count($alertasValidade['a_expirar']) ?> especificação(ões) a expirar em 30 dias:</strong>
                <?php foreach ($alertasValidade['a_expirar'] as $exp): ?>
                <a href="<?= BASE_PATH ?>/especificacao.php?id=<?= $exp['id'] ?>" style="display:inline-block; margin:2px 4px; text-decoration:underline; color:inherit;"><?= sanitize($exp['numero']) ?> (<?= date('d/m/Y', strtotime($exp['data_validade'])) ?>)</a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- SEARCH & FILTERS -->
        <div class="search-filters no-print">
            <form method="GET">
                <div class="sf-top">
                    <input type="search" name="q" placeholder="Pesquisar especificações..." value="<?= sanitize($search) ?>" class="sf-search" oninput="filtrarTabela()" id="filtroSearch">
                    <a href="<?= BASE_PATH ?>/especificacao.php?novo=1" class="btn btn-primary">+ Nova Especificação</a>
                </div>
                <div class="sf-filters">
                    <select name="estado" id="filtroEstado" onchange="filtrarTabela()">
                        <option value="">Estado</option>
                        <option value="rascunho" <?= $filtro_estado === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                        <option value="em_revisao" <?= $filtro_estado === 'em_revisao' ? 'selected' : '' ?>>Em Revisão</option>
                        <option value="ativo" <?= $filtro_estado === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                        <option value="obsoleto" <?= $filtro_estado === 'obsoleto' ? 'selected' : '' ?>>Obsoleto</option>
                    </select>
                    <select name="produto" onchange="filtrarTabela()">
                        <option value="">Produto</option>
                        <?php foreach ($produtos as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $filtro_produto == $p['id'] ? 'selected' : '' ?>><?= sanitize($p['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isSA || !empty($_SESSION['org_tem_clientes'])): ?>
                    <select name="cliente" onchange="filtrarTabela()">
                        <option value="">Cliente</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $filtro_cliente == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?php if ($isSA || !empty($_SESSION['org_tem_fornecedores'])): ?>
                    <select name="fornecedor" onchange="filtrarTabela()">
                        <option value="">Fornecedor</option>
                        <?php foreach ($fornecedores as $fn): ?>
                            <option value="<?= $fn['id'] ?>" <?= $filtro_fornecedor == $fn['id'] ? 'selected' : '' ?>><?= sanitize($fn['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?php if ($isSA && !empty($organizacoes)): ?>
                        <select name="org" onchange="filtrarTabela()">
                            <option value="">Organização</option>
                            <?php foreach ($organizacoes as $o): ?>
                                <option value="<?= $o['id'] ?>" <?= $filtro_org == $o['id'] ? 'selected' : '' ?>><?= sanitize($o['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <a href="<?= BASE_PATH ?>/dashboard.php" class="btn btn-ghost btn-sm" id="btnLimparFiltros" style="display:none;" onclick="limparFiltros(); return false;">Limpar filtros</a>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="card">
            <?php if (empty($especificacoes)): ?>
                <div class="empty-state">
                    <div class="icon">&#128196;</div>
                    <h3>Nenhuma especificação encontrada</h3>
                    <p class="muted">Crie a primeira especificação clicando no botão acima.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Título</th>
                                <th>Produto</th>
                                <?php if ($isSA || !empty($_SESSION['org_tem_clientes'])): ?><th>Cliente</th><?php endif; ?>
                                <?php if ($isSA || !empty($_SESSION['org_tem_fornecedores'])): ?><th>Fornecedor</th><?php endif; ?>
                                <?php if ($isSA): ?><th>Org</th><?php endif; ?>
                                <th>Versão</th>
                                <th>Data</th>
                                <th>Estado</th>
                                <th style="width:120px">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($especificacoes as $e): ?>
                                <tr data-estado="<?= $e['estado'] ?>" data-produto="<?= sanitize($e['produto_nome'] ?? '') ?>" data-cliente="<?= sanitize($e['cliente_nome'] ?? '') ?>" data-fornecedor="<?= sanitize($e['fornecedor_nome'] ?? '') ?>" data-org="<?= (int)($e['organizacao_id'] ?? 0) ?>" data-search="<?= strtolower(sanitize($e['numero'] . ' ' . $e['titulo'] . ' ' . ($e['cliente_nome'] ?? '') . ' ' . ($e['produto_nome'] ?? ''))) ?>">
                                    <td><strong><?= sanitize($e['numero']) ?></strong></td>
                                    <td>
                                        <a href="<?= BASE_PATH ?>/especificacao.php?id=<?= $e['id'] ?>">
                                            <?= sanitize($e['titulo']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="pill pill-primary"><?= sanitize($e['produto_nome'] ?? '-') ?></span>
                                    </td>
                                    <?php if ($isSA || !empty($_SESSION['org_tem_clientes'])): ?>
                                        <td><?= sanitize($e['cliente_nome'] ?? '-') ?></td>
                                    <?php endif; ?>
                                    <?php if ($isSA || !empty($_SESSION['org_tem_fornecedores'])): ?>
                                        <td><?= $e['fornecedor_nome'] ? sanitize($e['fornecedor_nome']) : 'Todos' ?></td>
                                    <?php endif; ?>
                                    <?php if ($isSA): ?>
                                        <td><span class="pill pill-muted"><?= sanitize($e['org_nome'] ?? '-') ?></span></td>
                                    <?php endif; ?>
                                    <td>
                                        v<?= sanitize($e['versao']) ?>
                                        <?php if (!empty($e['versao_bloqueada'])): ?>
                                            <span class="pill pill-info" style="font-size:10px;">Publicada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatDate($e['data_emissao']) ?></td>
                                    <td>
                                        <?php
                                        $estadoClass = ['rascunho' => 'pill-warning', 'em_revisao' => 'pill-info', 'ativo' => 'pill-success', 'obsoleto' => 'pill-muted'];
                                        $estadoLabel = ['rascunho' => 'Rascunho', 'em_revisao' => 'Em Revisão', 'ativo' => 'Ativo', 'obsoleto' => 'Obsoleto'];
                                        ?>
                                        <span class="pill <?= $estadoClass[$e['estado']] ?? 'pill-muted' ?>">
                                            <?= $estadoLabel[$e['estado']] ?? $e['estado'] ?>
                                        </span>
                                        <?php if ($e['estado'] === 'ativo' && !empty($e['data_validade'])):
                                            $valTs = strtotime($e['data_validade']);
                                            if ($valTs < strtotime('today')): ?>
                                            <span class="pill pill-danger" style="font-size:10px;" title="Expirada em <?= date('d/m/Y', $valTs) ?>">Expirada</span>
                                            <?php elseif ($valTs <= strtotime('+30 days')): ?>
                                            <span class="pill pill-warning" style="font-size:10px;" title="Expira em <?= date('d/m/Y', $valTs) ?>">Expira breve</span>
                                            <?php endif; endif; ?>
                                    </td>
                                    <td>
                                        <div class="flex gap-sm">
                                            <a href="<?= BASE_PATH ?>/especificacao.php?id=<?= $e['id'] ?>" class="btn btn-ghost btn-sm" title="Editar">&#9998;</a>
                                            <a href="<?= BASE_PATH ?>/ver.php?id=<?= $e['id'] ?>" class="btn btn-ghost btn-sm" title="Ver" target="_blank">&#128065;</a>
                                            <a href="<?= BASE_PATH ?>/pdf.php?id=<?= $e['id'] ?>" class="btn btn-ghost btn-sm" title="PDF" target="_blank">&#128196;</a>
                                            <button class="btn btn-ghost btn-sm" title="Duplicar" onclick="duplicarEspec(<?= $e['id'] ?>, '<?= sanitize($e['numero']) ?>')">&#128203;</button>
                                            <?php if ($e['codigo_acesso']): ?>
                                                <button class="btn btn-ghost btn-sm" title="Copiar link público"
                                                    onclick="copyShareLink('<?= $e['codigo_acesso'] ?>')">&#128279;</button>
                                            <?php endif; ?>
                                            <?php
                                            $podeEliminar = ((int)($e['criado_por'] ?? 0) === (int)$user['id'])
                                                || (in_array($user['role'], ['org_admin', 'super_admin']) && ($e['organizacao_id'] ?? null) == $user['org_id']);
                                            if ($podeEliminar): ?>
                                                <button class="btn btn-ghost btn-sm" title="Eliminar" style="color:#dc2626;" onclick="eliminarEspec(<?= $e['id'] ?>, '<?= sanitize($e['numero']) ?>')">&#128465;</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="toast-container" class="toast-container"></div>

    <script>
    function copyShareLink(code) {
        const url = window.location.origin + '<?= BASE_PATH ?>/publico.php?code=' + code;
        navigator.clipboard.writeText(url).then(() => {
            showToast('Link copiado!', 'success');
        });
    }

    function showToast(msg, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.textContent = msg;
        container.appendChild(toast);
        setTimeout(() => { toast.remove(); }, 3000);
    }

    function duplicarEspec(id, numero) {
        appConfirm('Duplicar a especificação ' + numero + '?<br>Será criada uma cópia com novo número.', function() {

        fetch('<?= BASE_PATH ?>/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= getCsrfToken() ?>' },
            body: JSON.stringify({ action: 'duplicate_especificacao', id: id })
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                var newId = (result.data && result.data.id) || result.id;
                showToast('Especificação duplicada com sucesso.', 'success');
                if (newId) {
                    setTimeout(function() {
                        window.location.href = '<?= BASE_PATH ?>/especificacao.php?id=' + newId;
                    }, 500);
                } else {
                    setTimeout(function() { window.location.reload(); }, 500);
                }
            } else {
                showToast(result.error || 'Erro ao duplicar.', 'error');
            }
        })
        .catch(function() {
            showToast('Erro de ligação ao servidor.', 'error');
        });
        });
    }

    function eliminarEspec(id, numero) {
        appConfirmDanger('Eliminar permanentemente a especificação <strong>' + numero + '</strong>?<br>Esta ação não pode ser revertida.', function() {
            fetch('<?= BASE_PATH ?>/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= getCsrfToken() ?>' },
                body: JSON.stringify({ action: 'delete_especificacao', id: id })
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    showToast('Especificação eliminada.', 'success');
                    setTimeout(function() { window.location.reload(); }, 500);
                } else {
                    showToast(result.error || 'Erro ao eliminar.', 'error');
                }
            })
            .catch(function() { showToast('Erro de ligação ao servidor.', 'error'); });
        }, 'Eliminar Especificação');
    }
    function filtrarTabela() {
        var search = (document.getElementById('filtroSearch').value || '').toLowerCase();
        var estado = document.getElementById('filtroEstado').value;
        var rows = document.querySelectorAll('table tbody tr[data-estado]');
        var visivel = 0;

        rows.forEach(function(tr) {
            var show = true;
            if (estado && tr.getAttribute('data-estado') !== estado) show = false;
            if (search && tr.getAttribute('data-search').indexOf(search) === -1) show = false;
            tr.style.display = show ? '' : 'none';
            if (show) visivel++;
        });

        // Mostrar/ocultar botão limpar
        var btnLimpar = document.getElementById('btnLimparFiltros');
        btnLimpar.style.display = (search || estado) ? '' : 'none';

        // Contador
        var counter = document.getElementById('filtroCounter');
        if (!counter) {
            counter = document.createElement('span');
            counter.id = 'filtroCounter';
            counter.className = 'muted';
            counter.style.fontSize = '12px';
            counter.style.marginLeft = '8px';
            btnLimpar.parentNode.appendChild(counter);
        }
        counter.textContent = (search || estado) ? visivel + ' de ' + rows.length + ' especificações' : '';
    }

    function limparFiltros() {
        document.getElementById('filtroSearch').value = '';
        document.getElementById('filtroEstado').value = '';
        filtrarTabela();
    }

    // Aplicar filtros iniciais se houver
    <?php if ($search || $filtro_estado): ?>
    document.addEventListener('DOMContentLoaded', filtrarTabela);
    <?php endif; ?>
    </script>
    <?php include __DIR__ . '/includes/modals.php'; ?>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
