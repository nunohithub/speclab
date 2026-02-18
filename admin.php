<?php
/**
 * SpecLab - Cadernos de Encargos
 * Painel de Administração (Multi-Tenant)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$user = getCurrentUser();
$db = getDB();

$tab = $_GET['tab'] ?? 'utilizadores';
$msg = $_GET['msg'] ?? '';

$isSuperAdminUser = isSuperAdmin();
$orgId = $user['org_id'] ?? null;

// Carregar lista de organizações (para selects e tab de organizações)
$organizacoes = [];
$planos = [];
if ($isSuperAdminUser) {
    $organizacoes = $db->query('SELECT * FROM organizacoes ORDER BY nome')->fetchAll();
    $planos = getPlanos($db);
}

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        $password = $_POST['password'] ?? '';

        // Determinar organizacao_id
        if ($isSuperAdminUser) {
            $userOrgId = !empty($_POST['organizacao_id']) ? (int)$_POST['organizacao_id'] : null;
        } else {
            // org_admin pode apenas criar utilizadores na sua organização
            $userOrgId = $orgId;
        }

        // Segurança: org_admin não pode criar super_admin
        if (!$isSuperAdminUser && $role === 'super_admin') {
            $role = 'user';
        }

        // Verificar limite de utilizadores ao criar novo (não ao editar)
        if (!$uid && $userOrgId) {
            $limiteCheck = podeCriarUtilizador($db, $userOrgId);
            if (!$limiteCheck['ok']) {
                header('Location: ' . BASE_PATH . '/admin.php?tab=utilizadores&msg=' . urlencode($limiteCheck['msg']));
                exit;
            }
        }

        if ($uid) {
            $sql = 'UPDATE utilizadores SET nome = ?, username = ?, role = ?, ativo = ?, organizacao_id = ? WHERE id = ?';
            $params = [$nome, $username, $role, $ativo, $userOrgId, $uid];
            $db->prepare($sql)->execute($params);
            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare('UPDATE utilizadores SET password = ? WHERE id = ?')->execute([$hash, $uid]);
            }
        } else {
            $hash = password_hash($password ?: 'exi2026', PASSWORD_DEFAULT);
            $db->prepare('INSERT INTO utilizadores (nome, username, password, role, ativo, organizacao_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$nome, $username, $hash, $role, $ativo, $userOrgId]);
            $uid = (int)$db->lastInsertId();
        }

        // Processar upload de assinatura
        if (!empty($_FILES['assinatura']['name']) && $_FILES['assinatura']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/assinaturas/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['assinatura']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'])) {
                $filename = 'assinatura_' . $uid . '_' . time() . '.' . $ext;
                $filepath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['assinatura']['tmp_name'], $filepath)) {
                    // Remover assinatura anterior
                    $old = $db->prepare('SELECT assinatura FROM utilizadores WHERE id = ?');
                    $old->execute([$uid]);
                    $oldFile = $old->fetchColumn();
                    if ($oldFile && file_exists($uploadDir . $oldFile)) {
                        unlink($uploadDir . $oldFile);
                    }
                    $db->prepare('UPDATE utilizadores SET assinatura = ? WHERE id = ?')->execute([$filename, $uid]);
                }
            }
        } elseif (!empty($_POST['remover_assinatura']) && $_POST['remover_assinatura'] === '1') {
            $old = $db->prepare('SELECT assinatura FROM utilizadores WHERE id = ?');
            $old->execute([$uid]);
            $oldFile = $old->fetchColumn();
            if ($oldFile) {
                $uploadDir = __DIR__ . '/uploads/assinaturas/';
                if (file_exists($uploadDir . $oldFile)) {
                    unlink($uploadDir . $oldFile);
                }
                $db->prepare('UPDATE utilizadores SET assinatura = NULL WHERE id = ?')->execute([$uid]);
            }
        }

        header('Location: ' . BASE_PATH . '/admin.php?tab=utilizadores&msg=Utilizador+guardado');
        exit;
    }

    if ($action === 'save_cliente') {
        $cid = (int)($_POST['cliente_id'] ?? 0);
        $fields = ['nome', 'sigla', 'morada', 'telefone', 'email', 'nif', 'contacto'];
        $values = array_map(fn($f) => trim($_POST[$f] ?? ''), $fields);

        // Determinar organizacao_id
        if ($isSuperAdminUser) {
            $clienteOrgId = !empty($_POST['organizacao_id']) ? (int)$_POST['organizacao_id'] : $orgId;
        } else {
            $clienteOrgId = $orgId;
        }

        if ($cid) {
            $sql = 'UPDATE clientes SET nome=?, sigla=?, morada=?, telefone=?, email=?, nif=?, contacto=? WHERE id=?';
            $db->prepare($sql)->execute([...$values, $cid]);
        } else {
            $sql = 'INSERT INTO clientes (nome, sigla, morada, telefone, email, nif, contacto, organizacao_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $db->prepare($sql)->execute([...$values, $clienteOrgId]);
        }
        header('Location: ' . BASE_PATH . '/admin.php?tab=clientes&msg=Cliente+guardado');
        exit;
    }

    if ($action === 'save_produto') {
        $pid = (int)($_POST['produto_id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $tipo = trim($_POST['tipo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');

        // Determinar organizacao_id
        if ($isSuperAdminUser) {
            $isGlobal = isset($_POST['global']) ? true : false;
            $produtoOrgId = $isGlobal ? null : (!empty($_POST['organizacao_id']) ? (int)$_POST['organizacao_id'] : null);
        } else {
            $produtoOrgId = $orgId;
        }

        if ($pid) {
            $db->prepare('UPDATE produtos SET nome=?, tipo=?, descricao=?, organizacao_id=? WHERE id=?')
                ->execute([$nome, $tipo, $descricao, $produtoOrgId, $pid]);
        } else {
            $db->prepare('INSERT INTO produtos (nome, tipo, descricao, organizacao_id) VALUES (?, ?, ?, ?)')
                ->execute([$nome, $tipo, $descricao, $produtoOrgId]);
        }
        header('Location: ' . BASE_PATH . '/admin.php?tab=produtos&msg=Produto+guardado');
        exit;
    }

    if ($action === 'save_organizacao' && $isSuperAdminUser) {
        $oid = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $nif = trim($_POST['nif'] ?? '');
        $morada = trim($_POST['morada'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $cor_primaria = trim($_POST['cor_primaria'] ?? '#2596be');
        $cor_primaria_dark = trim($_POST['cor_primaria_dark'] ?? '#1a7a9e');
        $cor_primaria_light = trim($_POST['cor_primaria_light'] ?? '#e6f4f9');
        $numeracao_prefixo = trim($_POST['numeracao_prefixo'] ?? 'CE');
        $tem_clientes = isset($_POST['tem_clientes']) ? 1 : 0;
        $tem_fornecedores = isset($_POST['tem_fornecedores']) ? 1 : 0;
        $plano = $_POST['plano'] ?? 'basico';
        $max_utilizadores = (int)($_POST['max_utilizadores'] ?? 5);
        $max_especificacoes = !empty($_POST['max_especificacoes']) ? (int)$_POST['max_especificacoes'] : null;
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $nome));
            $slug = trim($slug, '-');
        }

        if ($oid) {
            $db->prepare('UPDATE organizacoes SET nome=?, slug=?, nif=?, morada=?, telefone=?, email=?, website=?, cor_primaria=?, cor_primaria_dark=?, cor_primaria_light=?, numeracao_prefixo=?, tem_clientes=?, tem_fornecedores=?, plano=?, max_utilizadores=?, max_especificacoes=?, ativo=?, updated_at=NOW() WHERE id=?')
                ->execute([$nome, $slug, $nif, $morada, $telefone, $email, $website, $cor_primaria, $cor_primaria_dark, $cor_primaria_light, $numeracao_prefixo, $tem_clientes, $tem_fornecedores, $plano, $max_utilizadores, $max_especificacoes, $ativo, $oid]);
        } else {
            // Verificar slug único
            $stmt = $db->prepare('SELECT id FROM organizacoes WHERE slug = ?');
            $stmt->execute([$slug]);
            if ($stmt->fetch()) {
                $slug .= '-' . time();
            }
            $db->prepare('INSERT INTO organizacoes (nome, slug, nif, morada, telefone, email, website, cor_primaria, cor_primaria_dark, cor_primaria_light, numeracao_prefixo, tem_clientes, tem_fornecedores, plano, max_utilizadores, max_especificacoes, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$nome, $slug, $nif, $morada, $telefone, $email, $website, $cor_primaria, $cor_primaria_dark, $cor_primaria_light, $numeracao_prefixo, $tem_clientes, $tem_fornecedores, $plano, $max_utilizadores, $max_especificacoes, $ativo]);
            $oid = (int)$db->lastInsertId();
        }

        // Upload de logo
        if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/logos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg'])) {
                $filename = 'org_' . $oid . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploadDir . $filename)) {
                    // Remover logo anterior
                    $old = $db->prepare('SELECT logo FROM organizacoes WHERE id = ?');
                    $old->execute([$oid]);
                    $oldLogo = $old->fetchColumn();
                    if ($oldLogo && file_exists($uploadDir . $oldLogo)) {
                        unlink($uploadDir . $oldLogo);
                    }
                    $db->prepare('UPDATE organizacoes SET logo = ? WHERE id = ?')->execute([$filename, $oid]);
                }
            }
        }

        header('Location: ' . BASE_PATH . '/admin.php?tab=organizacoes&msg=Organiza%C3%A7%C3%A3o+guardada');
        exit;
    }

    if ($action === 'save_fornecedor') {
        $fid = (int)($_POST['fornecedor_id'] ?? 0);
        $fields = ['nome', 'sigla', 'morada', 'telefone', 'email', 'nif', 'contacto'];
        $values = array_map(fn($f) => trim($_POST[$f] ?? ''), $fields);

        if ($isSuperAdminUser) {
            $fornOrgId = !empty($_POST['organizacao_id']) ? (int)$_POST['organizacao_id'] : $orgId;
        } else {
            $fornOrgId = $orgId;
        }

        if ($fid) {
            $sql = 'UPDATE fornecedores SET nome=?, sigla=?, morada=?, telefone=?, email=?, nif=?, contacto=? WHERE id=?';
            $db->prepare($sql)->execute([...$values, $fid]);
        } else {
            $sql = 'INSERT INTO fornecedores (nome, sigla, morada, telefone, email, nif, contacto, organizacao_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $db->prepare($sql)->execute([...$values, $fornOrgId]);
        }
        header('Location: ' . BASE_PATH . '/admin.php?tab=fornecedores&msg=Fornecedor+guardado');
        exit;
    }

    if ($action === 'save_plano' && $isSuperAdminUser) {
        $planoId = trim($_POST['plano_id'] ?? '');
        $planoNome = trim($_POST['plano_nome'] ?? '');
        $planoMaxUsers = (int)($_POST['plano_max_utilizadores'] ?? 5);
        $planoMaxSpecs = !empty($_POST['plano_max_especificacoes']) ? (int)$_POST['plano_max_especificacoes'] : null;
        $planoTemClientes = isset($_POST['plano_tem_clientes']) ? 1 : 0;
        $planoTemFornecedores = isset($_POST['plano_tem_fornecedores']) ? 1 : 0;
        $planoPreco = !empty($_POST['plano_preco_mensal']) ? (float)$_POST['plano_preco_mensal'] : null;
        $planoDescricao = trim($_POST['plano_descricao'] ?? '');
        $planoOrdem = (int)($_POST['plano_ordem'] ?? 0);

        if ($planoId && $planoNome) {
            $db->prepare('INSERT INTO planos (id, nome, max_utilizadores, max_especificacoes, tem_clientes, tem_fornecedores, preco_mensal, descricao, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome=?, max_utilizadores=?, max_especificacoes=?, tem_clientes=?, tem_fornecedores=?, preco_mensal=?, descricao=?, ordem=?')
                ->execute([$planoId, $planoNome, $planoMaxUsers, $planoMaxSpecs, $planoTemClientes, $planoTemFornecedores, $planoPreco, $planoDescricao, $planoOrdem,
                           $planoNome, $planoMaxUsers, $planoMaxSpecs, $planoTemClientes, $planoTemFornecedores, $planoPreco, $planoDescricao, $planoOrdem]);
        }
        header('Location: ' . BASE_PATH . '/admin.php?tab=planos&msg=Plano+guardado');
        exit;
    }

    if ($action === 'save_config') {
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'cfg_') === 0) {
                $chave = substr($key, 4);
                setConfiguracao($chave, trim($value));
            }
        }
        header('Location: ' . BASE_PATH . '/admin.php?tab=configuracoes&msg=Configura%C3%A7%C3%B5es+guardadas');
        exit;
    }
}

// Carregar dados filtrados por organização
if ($isSuperAdminUser) {
    // Super admin vê tudo, pode filtrar por organização
    $filterOrg = isset($_GET['org']) && $_GET['org'] !== '' ? (int)$_GET['org'] : null;

    if ($filterOrg) {
        $utilizadores = $db->prepare('SELECT u.*, o.nome as org_nome FROM utilizadores u LEFT JOIN organizacoes o ON u.organizacao_id = o.id WHERE u.organizacao_id = ? ORDER BY u.nome');
        $utilizadores->execute([$filterOrg]);
        $utilizadores = $utilizadores->fetchAll();

        $clientes = $db->prepare('SELECT c.*, o.nome as org_nome FROM clientes c LEFT JOIN organizacoes o ON c.organizacao_id = o.id WHERE c.organizacao_id = ? ORDER BY c.nome');
        $clientes->execute([$filterOrg]);
        $clientes = $clientes->fetchAll();

        $produtos = $db->prepare('SELECT p.*, o.nome as org_nome FROM produtos p LEFT JOIN organizacoes o ON p.organizacao_id = o.id WHERE p.organizacao_id IS NULL OR p.organizacao_id = ? ORDER BY p.nome');
        $produtos->execute([$filterOrg]);
        $produtos = $produtos->fetchAll();

        $fornecedores_list = $db->prepare('SELECT f.*, o.nome as org_nome FROM fornecedores f LEFT JOIN organizacoes o ON f.organizacao_id = o.id WHERE f.organizacao_id = ? ORDER BY f.nome');
        $fornecedores_list->execute([$filterOrg]);
        $fornecedores_list = $fornecedores_list->fetchAll();
    } else {
        $utilizadores = $db->query('SELECT u.*, o.nome as org_nome FROM utilizadores u LEFT JOIN organizacoes o ON u.organizacao_id = o.id ORDER BY u.nome')->fetchAll();
        $clientes = $db->query('SELECT c.*, o.nome as org_nome FROM clientes c LEFT JOIN organizacoes o ON c.organizacao_id = o.id ORDER BY c.nome')->fetchAll();
        $produtos = $db->query('SELECT p.*, o.nome as org_nome FROM produtos p LEFT JOIN organizacoes o ON p.organizacao_id = o.id ORDER BY p.nome')->fetchAll();
        $fornecedores_list = $db->query('SELECT f.*, o.nome as org_nome FROM fornecedores f LEFT JOIN organizacoes o ON f.organizacao_id = o.id ORDER BY f.nome')->fetchAll();
    }
} else {
    // org_admin vê apenas da sua organização
    $stmt = $db->prepare('SELECT u.*, o.nome as org_nome FROM utilizadores u LEFT JOIN organizacoes o ON u.organizacao_id = o.id WHERE u.organizacao_id = ? ORDER BY u.nome');
    $stmt->execute([$orgId]);
    $utilizadores = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT c.*, o.nome as org_nome FROM clientes c LEFT JOIN organizacoes o ON c.organizacao_id = o.id WHERE c.organizacao_id = ? ORDER BY c.nome');
    $stmt->execute([$orgId]);
    $clientes = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT p.*, o.nome as org_nome FROM produtos p LEFT JOIN organizacoes o ON p.organizacao_id = o.id WHERE p.organizacao_id IS NULL OR p.organizacao_id = ? ORDER BY p.nome');
    $stmt->execute([$orgId]);
    $produtos = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT f.*, o.nome as org_nome FROM fornecedores f LEFT JOIN organizacoes o ON f.organizacao_id = o.id WHERE f.organizacao_id = ? ORDER BY f.nome');
    $stmt->execute([$orgId]);
    $fornecedores_list = $stmt->fetchAll();
}

// Variáveis para o header
$pageTitle = 'Cadernos de Encargos';
$pageSubtitle = 'Sistema de Especificações Técnicas';
$showNav = true;
$activeNav = $tab;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração - SpecLab</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container">
        <?php if ($msg): ?>
            <div class="alert alert-success"><?= sanitize($msg) ?></div>
        <?php endif; ?>

        <!-- ORGANIZAÇÕES (super_admin only) -->
        <?php if ($tab === 'organizacoes' && $isSuperAdminUser): ?>
            <div class="flex-between mb-md">
                <h2>Organizações</h2>
                <button class="btn btn-primary" onclick="document.getElementById('orgModal').style.display='flex'; resetOrgForm();">+ Nova Organização</button>
            </div>
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Logo</th>
                            <th>Nome</th>
                            <th>Slug</th>
                            <th>NIF</th>
                            <th>Plano</th>
                            <th>Utilizadores</th>
                            <th>Estado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($organizacoes as $org): ?>
                        <tr>
                            <td>
                                <?php if (!empty($org['logo'])): ?>
                                    <img src="<?= BASE_PATH ?>/uploads/logos/<?= sanitize($org['logo']) ?>" alt="" style="height: 28px; border-radius: 4px;">
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= sanitize($org['nome']) ?></strong>
                                <?php if (!empty($org['cor_primaria'])): ?>
                                    <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:<?= sanitize($org['cor_primaria']) ?>; vertical-align:middle; margin-left:6px;"></span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= sanitize($org['slug'] ?? '') ?></code></td>
                            <td><?= sanitize($org['nif'] ?? '') ?></td>
                            <td><span class="pill pill-primary"><?= sanitize($org['plano'] ?? 'basico') ?></span></td>
                            <?php
                                $orgUserCount = contarUtilizadoresOrg($db, (int)$org['id']);
                                $orgMaxUsers = (int)($org['max_utilizadores'] ?? 5);
                                $usersClass = ($orgUserCount >= $orgMaxUsers) ? 'color:#b42318;font-weight:600;' : '';
                            ?>
                            <td style="<?= $usersClass ?>"><?= $orgUserCount ?>/<?= $orgMaxUsers ?></td>
                            <td><span class="pill <?= ($org['ativo'] ?? 1) ? 'pill-success' : 'pill-error' ?>"><?= ($org['ativo'] ?? 1) ? 'Ativo' : 'Inativo' ?></span></td>
                            <td>
                                <button class="btn btn-ghost btn-sm" onclick="editOrg(<?= htmlspecialchars(json_encode($org)) ?>)">Editar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Organização Modal -->
            <div id="orgModal" class="modal-overlay" style="display:none;">
                <div class="modal-box modal-box-lg">
                    <div class="modal-header">
                        <h3 id="orgModalTitle">Nova Organização</h3>
                        <button class="modal-close" onclick="document.getElementById('orgModal').style.display='none'">&times;</button>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_organizacao">
                        <input type="hidden" name="id" id="org_id" value="0">

                        <div class="form-row">
                            <div class="form-group">
                                <label>Nome *</label>
                                <input type="text" name="nome" id="org_nome" required>
                            </div>
                            <div class="form-group">
                                <label>Slug *</label>
                                <input type="text" name="slug" id="org_slug" required placeholder="ex: minha-empresa">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>NIF</label>
                                <input type="text" name="nif" id="org_nif">
                            </div>
                            <div class="form-group">
                                <label>Telefone</label>
                                <input type="text" name="telefone" id="org_telefone">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Morada</label>
                            <input type="text" name="morada" id="org_morada">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" id="org_email">
                            </div>
                            <div class="form-group">
                                <label>Website</label>
                                <input type="url" name="website" id="org_website" placeholder="https://">
                            </div>
                        </div>

                        <hr style="margin: 18px 0; border: none; border-top: 1px solid #e5e7eb;">
                        <h4 style="color: #2596be; font-size: 14px; margin-bottom: 12px;">Branding</h4>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Cor Primária</label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="color" name="cor_primaria" id="org_cor_primaria" value="#2596be" style="width: 50px; height: 36px; padding: 2px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer;">
                                    <input type="text" id="org_cor_primaria_text" value="#2596be" style="width: 100px; font-size: 13px;" onchange="document.getElementById('org_cor_primaria').value = this.value;">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Cor Primária Dark</label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="color" name="cor_primaria_dark" id="org_cor_primaria_dark" value="#1a7a9e" style="width: 50px; height: 36px; padding: 2px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer;">
                                    <input type="text" id="org_cor_primaria_dark_text" value="#1a7a9e" style="width: 100px; font-size: 13px;" onchange="document.getElementById('org_cor_primaria_dark').value = this.value;">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Cor Primária Light</label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="color" name="cor_primaria_light" id="org_cor_primaria_light" value="#e6f4f9" style="width: 50px; height: 36px; padding: 2px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer;">
                                    <input type="text" id="org_cor_primaria_light_text" value="#e6f4f9" style="width: 100px; font-size: 13px;" onchange="document.getElementById('org_cor_primaria_light').value = this.value;">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Logo da Organização</label>
                            <div id="org_logo_preview" style="margin-bottom: 8px; display: none;">
                                <img id="org_logo_img" src="" alt="Logo" style="max-height: 60px; border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px; background: white;">
                            </div>
                            <input type="file" name="logo_file" id="org_logo_file" accept="image/png,image/jpeg,image/gif,image/svg+xml" style="font-size: 13px;">
                            <small class="muted">Formatos: PNG, JPG, GIF, SVG</small>
                        </div>

                        <hr style="margin: 18px 0; border: none; border-top: 1px solid #e5e7eb;">
                        <h4 style="color: #2596be; font-size: 14px; margin-bottom: 12px;">Plano e Limites</h4>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Prefixo Numeração</label>
                                <input type="text" name="numeracao_prefixo" id="org_numeracao_prefixo" value="CE" placeholder="CE">
                            </div>
                        </div>

                        <h4 style="color: #2596be; font-size: 14px; margin: 12px 0;">Módulos Disponíveis</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="tem_clientes" id="org_tem_clientes"> Gestão de Clientes
                                </label>
                                <small style="color:#667085;">A organização pode gerir os seus próprios clientes</small>
                            </div>
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="tem_fornecedores" id="org_tem_fornecedores" checked> Gestão de Fornecedores
                                </label>
                                <small style="color:#667085;">A organização pode gerir fornecedores</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Plano</label>
                                <select name="plano" id="org_plano">
                                    <option value="basico">Básico</option>
                                    <option value="profissional">Profissional</option>
                                    <option value="enterprise">Enterprise</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Max. Utilizadores</label>
                                <input type="number" name="max_utilizadores" id="org_max_utilizadores" min="1" value="5">
                            </div>
                            <div class="form-group">
                                <label>Max. Especificações</label>
                                <input type="number" name="max_especificacoes" id="org_max_especificacoes" min="1" value="100">
                            </div>
                        </div>

                        <div class="form-group">
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" name="ativo" id="org_ativo" checked> Organização Ativa
                            </label>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('orgModal').style.display='none'">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>

        <!-- UTILIZADORES -->
        <?php elseif ($tab === 'utilizadores'): ?>
            <div class="flex-between mb-md">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <h2>Utilizadores</h2>
                    <?php if ($isSuperAdminUser): ?>
                        <form method="GET" style="display: flex; align-items: center; gap: 8px;">
                            <input type="hidden" name="tab" value="utilizadores">
                            <select name="org" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                                <option value="">Todas as Organizações</option>
                                <?php foreach ($organizacoes as $org): ?>
                                    <option value="<?= $org['id'] ?>" <?= (isset($_GET['org']) && (int)$_GET['org'] === (int)$org['id']) ? 'selected' : '' ?>><?= sanitize($org['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>
                <button class="btn btn-primary" onclick="document.getElementById('userModal').style.display='flex'; resetUserForm();">+ Novo Utilizador</button>
            </div>
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Username</th>
                            <th>Perfil</th>
                            <?php if ($isSuperAdminUser): ?><th>Organização</th><?php endif; ?>
                            <th>Estado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($utilizadores as $u): ?>
                        <tr>
                            <td><strong><?= sanitize($u['nome']) ?></strong></td>
                            <td><?= sanitize($u['username']) ?></td>
                            <td>
                                <?php
                                $rolePillClass = 'pill-muted';
                                if ($u['role'] === 'super_admin') $rolePillClass = 'pill-error';
                                elseif ($u['role'] === 'org_admin') $rolePillClass = 'pill-primary';
                                ?>
                                <span class="pill <?= $rolePillClass ?>"><?= sanitize($u['role']) ?></span>
                            </td>
                            <?php if ($isSuperAdminUser): ?>
                                <td><?= sanitize($u['org_nome'] ?? 'Sem org.') ?></td>
                            <?php endif; ?>
                            <td><span class="pill <?= $u['ativo'] ? 'pill-success' : 'pill-error' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                            <td>
                                <button class="btn btn-ghost btn-sm" onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)">Editar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- User Modal -->
            <div id="userModal" class="modal-overlay" style="display:none;">
                <div class="modal-box">
                    <div class="modal-header">
                        <h3 id="userModalTitle">Novo Utilizador</h3>
                        <button class="modal-close" onclick="document.getElementById('userModal').style.display='none'">&times;</button>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_user">
                        <input type="hidden" name="user_id" id="user_id" value="0">
                        <div class="form-group">
                            <label>Nome</label>
                            <input type="text" name="nome" id="user_nome" required>
                        </div>
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="username" id="user_username" required>
                        </div>
                        <div class="form-group">
                            <label>Password <span class="muted">(deixe vazio para manter)</span></label>
                            <input type="password" name="password" id="user_password">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Perfil</label>
                                <select name="role" id="user_role">
                                    <option value="user">Utilizador</option>
                                    <option value="org_admin">Admin Organização</option>
                                    <?php if ($isSuperAdminUser): ?>
                                        <option value="super_admin">Super Admin</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="ativo" id="user_ativo" checked> Ativo
                                </label>
                            </div>
                        </div>

                        <?php if ($isSuperAdminUser): ?>
                            <div class="form-group">
                                <label>Organização</label>
                                <select name="organizacao_id" id="user_organizacao_id">
                                    <option value="">Sem Organização</option>
                                    <?php foreach ($organizacoes as $org): ?>
                                        <option value="<?= $org['id'] ?>"><?= sanitize($org['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="organizacao_id" value="<?= (int)$orgId ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Assinatura Digital <span class="muted">(imagem PNG/JPG, fundo transparente recomendado)</span></label>
                            <input type="file" name="assinatura" id="user_assinatura" accept="image/png,image/jpeg,image/gif" style="font-size: 13px;">
                            <div id="user_assinatura_preview" style="margin-top: 8px; display: none;">
                                <img id="user_assinatura_img" src="" alt="Assinatura" style="max-height: 60px; border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px; background: white;">
                                <button type="button" class="btn btn-danger btn-sm" onclick="removerAssinatura()" style="margin-left: 8px;">Remover</button>
                                <input type="hidden" name="remover_assinatura" id="remover_assinatura" value="0">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('userModal').style.display='none'">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>

        <!-- CLIENTES -->
        <?php elseif ($tab === 'clientes'): ?>
            <div class="flex-between mb-md">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <h2>Clientes</h2>
                    <?php if ($isSuperAdminUser): ?>
                        <form method="GET" style="display: flex; align-items: center; gap: 8px;">
                            <input type="hidden" name="tab" value="clientes">
                            <select name="org" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                                <option value="">Todas as Organizações</option>
                                <?php foreach ($organizacoes as $org): ?>
                                    <option value="<?= $org['id'] ?>" <?= (isset($_GET['org']) && (int)$_GET['org'] === (int)$org['id']) ? 'selected' : '' ?>><?= sanitize($org['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>
                <button class="btn btn-primary" onclick="document.getElementById('clienteModal').style.display='flex'; resetClienteForm();">+ Novo Cliente</button>
            </div>
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Sigla</th>
                            <th>Email</th>
                            <th>Telefone</th>
                            <th>NIF</th>
                            <?php if ($isSuperAdminUser): ?><th>Organização</th><?php endif; ?>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clientes as $c): ?>
                        <tr>
                            <td><strong><?= sanitize($c['nome']) ?></strong></td>
                            <td><span class="pill pill-primary"><?= sanitize($c['sigla']) ?></span></td>
                            <td><?= sanitize($c['email'] ?? '') ?></td>
                            <td><?= sanitize($c['telefone'] ?? '') ?></td>
                            <td><?= sanitize($c['nif'] ?? '') ?></td>
                            <?php if ($isSuperAdminUser): ?>
                                <td><?= sanitize($c['org_nome'] ?? 'Sem org.') ?></td>
                            <?php endif; ?>
                            <td>
                                <button class="btn btn-ghost btn-sm" onclick="editCliente(<?= htmlspecialchars(json_encode($c)) ?>)">Editar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Cliente Modal -->
            <div id="clienteModal" class="modal-overlay" style="display:none;">
                <div class="modal-box modal-box-lg">
                    <div class="modal-header">
                        <h3 id="clienteModalTitle">Novo Cliente</h3>
                        <button class="modal-close" onclick="document.getElementById('clienteModal').style.display='none'">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_cliente">
                        <input type="hidden" name="cliente_id" id="cliente_id" value="0">

                        <?php if ($isSuperAdminUser): ?>
                            <input type="hidden" name="organizacao_id" id="cl_organizacao_id" value="">
                        <?php else: ?>
                            <input type="hidden" name="organizacao_id" value="<?= (int)$orgId ?>">
                        <?php endif; ?>

                        <div class="form-row">
                            <div class="form-group"><label>Nome</label><input type="text" name="nome" id="cl_nome" required></div>
                            <div class="form-group"><label>Sigla</label><input type="text" name="sigla" id="cl_sigla" required></div>
                        </div>
                        <div class="form-group"><label>Morada</label><input type="text" name="morada" id="cl_morada"></div>
                        <div class="form-row">
                            <div class="form-group"><label>Telefone</label><input type="text" name="telefone" id="cl_telefone"></div>
                            <div class="form-group"><label>Email</label><input type="email" name="email" id="cl_email"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>NIF</label><input type="text" name="nif" id="cl_nif"></div>
                            <div class="form-group"><label>Contacto</label><input type="text" name="contacto" id="cl_contacto"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('clienteModal').style.display='none'">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>

        <!-- FORNECEDORES -->
        <?php elseif ($tab === 'fornecedores'): ?>
            <div class="flex-between mb-md">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <h2>Fornecedores</h2>
                    <?php if ($isSuperAdminUser): ?>
                        <form method="GET" style="display: flex; align-items: center; gap: 8px;">
                            <input type="hidden" name="tab" value="fornecedores">
                            <select name="org" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                                <option value="">Todas as Organizações</option>
                                <?php foreach ($organizacoes as $org): ?>
                                    <option value="<?= $org['id'] ?>" <?= (isset($_GET['org']) && (int)$_GET['org'] === (int)$org['id']) ? 'selected' : '' ?>><?= sanitize($org['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>
                <button class="btn btn-primary" onclick="document.getElementById('fornecedorModal').style.display='flex'; resetFornecedorForm();">+ Novo Fornecedor</button>
            </div>
            <div class="card">
                <?php if (empty($fornecedores_list)): ?>
                    <div class="empty-state">
                        <div class="icon">&#128666;</div>
                        <h3>Nenhum fornecedor registado</h3>
                        <p class="muted">Adicione fornecedores para os associar às especificações.</p>
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Sigla</th>
                            <th>Email</th>
                            <th>Telefone</th>
                            <th>NIF</th>
                            <?php if ($isSuperAdminUser): ?><th>Organização</th><?php endif; ?>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fornecedores_list as $f): ?>
                        <tr>
                            <td><strong><?= sanitize($f['nome']) ?></strong></td>
                            <td><span class="pill pill-primary"><?= sanitize($f['sigla'] ?? '') ?></span></td>
                            <td><?= sanitize($f['email'] ?? '') ?></td>
                            <td><?= sanitize($f['telefone'] ?? '') ?></td>
                            <td><?= sanitize($f['nif'] ?? '') ?></td>
                            <?php if ($isSuperAdminUser): ?>
                                <td><?= sanitize($f['org_nome'] ?? 'Sem org.') ?></td>
                            <?php endif; ?>
                            <td>
                                <button class="btn btn-ghost btn-sm" onclick="editFornecedor(<?= htmlspecialchars(json_encode($f)) ?>)">Editar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Fornecedor Modal -->
            <div id="fornecedorModal" class="modal-overlay" style="display:none;">
                <div class="modal-box modal-box-lg">
                    <div class="modal-header">
                        <h3 id="fornecedorModalTitle">Novo Fornecedor</h3>
                        <button class="modal-close" onclick="document.getElementById('fornecedorModal').style.display='none'">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_fornecedor">
                        <input type="hidden" name="fornecedor_id" id="fornecedor_id" value="0">

                        <?php if ($isSuperAdminUser): ?>
                            <input type="hidden" name="organizacao_id" id="fn_organizacao_id" value="">
                        <?php else: ?>
                            <input type="hidden" name="organizacao_id" value="<?= (int)$orgId ?>">
                        <?php endif; ?>

                        <div class="form-row">
                            <div class="form-group"><label>Nome *</label><input type="text" name="nome" id="fn_nome" required></div>
                            <div class="form-group"><label>Sigla</label><input type="text" name="sigla" id="fn_sigla"></div>
                        </div>
                        <div class="form-group"><label>Morada</label><input type="text" name="morada" id="fn_morada"></div>
                        <div class="form-row">
                            <div class="form-group"><label>Telefone</label><input type="text" name="telefone" id="fn_telefone"></div>
                            <div class="form-group"><label>Email</label><input type="email" name="email" id="fn_email"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>NIF</label><input type="text" name="nif" id="fn_nif"></div>
                            <div class="form-group"><label>Contacto</label><input type="text" name="contacto" id="fn_contacto"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('fornecedorModal').style.display='none'">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>

        <!-- PRODUTOS -->
        <?php elseif ($tab === 'produtos'): ?>
            <div class="flex-between mb-md">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <h2>Produtos</h2>
                    <?php if ($isSuperAdminUser): ?>
                        <form method="GET" style="display: flex; align-items: center; gap: 8px;">
                            <input type="hidden" name="tab" value="produtos">
                            <select name="org" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                                <option value="">Todas as Organizações</option>
                                <?php foreach ($organizacoes as $org): ?>
                                    <option value="<?= $org['id'] ?>" <?= (isset($_GET['org']) && (int)$_GET['org'] === (int)$org['id']) ? 'selected' : '' ?>><?= sanitize($org['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>
                <button class="btn btn-primary" onclick="document.getElementById('produtoModal').style.display='flex'; resetProdutoForm();">+ Novo Produto</button>
            </div>
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Descrição</th>
                            <?php if ($isSuperAdminUser): ?><th>Organização</th><?php endif; ?>
                            <th>Estado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($produtos as $p): ?>
                        <tr>
                            <td><strong><?= sanitize($p['nome']) ?></strong></td>
                            <td><span class="pill pill-primary"><?= sanitize($p['tipo'] ?? '') ?></span></td>
                            <td class="muted"><?= sanitize($p['descricao'] ?? '') ?></td>
                            <?php if ($isSuperAdminUser): ?>
                                <td>
                                    <?php if (empty($p['organizacao_id'])): ?>
                                        <span class="pill pill-muted">Global</span>
                                    <?php else: ?>
                                        <?= sanitize($p['org_nome'] ?? '') ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><span class="pill <?= $p['ativo'] ? 'pill-success' : 'pill-error' ?>"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                            <td>
                                <button class="btn btn-ghost btn-sm" onclick="editProduto(<?= htmlspecialchars(json_encode($p)) ?>)">Editar</button>
                                <button class="btn btn-ghost btn-sm" onclick="gerirTemplates(<?= $p['id'] ?>, '<?= sanitize($p['nome']) ?>')" title="Gerir parâmetros template">Templates</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Produto Modal -->
            <div id="produtoModal" class="modal-overlay" style="display:none;">
                <div class="modal-box">
                    <div class="modal-header">
                        <h3 id="produtoModalTitle">Novo Produto</h3>
                        <button class="modal-close" onclick="document.getElementById('produtoModal').style.display='none'">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_produto">
                        <input type="hidden" name="produto_id" id="produto_id" value="0">

                        <?php if ($isSuperAdminUser): ?>
                            <input type="hidden" name="organizacao_id" id="pr_organizacao_id" value="">
                        <?php else: ?>
                            <input type="hidden" name="organizacao_id" value="<?= (int)$orgId ?>">
                        <?php endif; ?>

                        <div class="form-group"><label>Nome</label><input type="text" name="nome" id="pr_nome" required></div>
                        <div class="form-group"><label>Tipo</label><input type="text" name="tipo" id="pr_tipo" placeholder="Ex: Natural, Micro, Colmatada..."></div>
                        <div class="form-group"><label>Descrição</label><textarea name="descricao" id="pr_descricao" rows="3"></textarea></div>

                        <?php if ($isSuperAdminUser): ?>
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="global" id="pr_global" onchange="toggleProdutoOrg(this.checked)"> Produto Global (disponível para todas as organizações)
                                </label>
                            </div>
                        <?php endif; ?>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('produtoModal').style.display='none'">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Product Parameter Templates Modal -->
            <div id="templateModal" class="modal-overlay" style="display:none;">
                <div class="modal-box modal-box-lg">
                    <div class="modal-header">
                        <h3>Templates de Parâmetros: <span id="tmpl_produto_nome"></span></h3>
                        <button class="modal-close" onclick="document.getElementById('templateModal').style.display='none'">&times;</button>
                    </div>
                    <input type="hidden" id="tmpl_produto_id" value="0">
                    <div id="tmpl_rows" style="max-height: 400px; overflow-y: auto; margin-bottom: 16px;">
                        <div style="text-align: center; padding: 20px; color: #667085;">A carregar...</div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 16px;">
                        <select id="tmpl_categoria" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                            <option value="">Categoria</option>
                            <option value="Físico-Mecânico">Físico-Mecânico</option>
                            <option value="Químico">Químico</option>
                            <option value="Microbiologia">Microbiologia</option>
                            <option value="Sensorial">Sensorial</option>
                            <option value="Cromatografia">Cromatografia</option>
                            <option value="Visual">Visual</option>
                        </select>
                        <input type="text" id="tmpl_ensaio" placeholder="Ensaio" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; flex: 1;">
                        <input type="text" id="tmpl_especificacao" placeholder="Especificação" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; flex: 1;">
                        <input type="text" id="tmpl_metodo" placeholder="Método" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; width: 120px;">
                        <input type="text" id="tmpl_nqa" placeholder="NQA" style="padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; width: 80px;">
                        <button class="btn btn-primary btn-sm" onclick="adicionarTemplate()">+</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('templateModal').style.display='none'">Fechar</button>
                    </div>
                </div>
            </div>

        <!-- LEGISLAÇÃO (super_admin only) -->
        <?php elseif ($tab === 'legislacao' && $isSuperAdminUser): ?>
            <div class="flex-between mb-md">
                <h2>Banco de Legislação</h2>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary" id="btnVerificarIA" onclick="verificarLegIA()">&#9878; Verificar com IA</button>
                    <button class="btn btn-secondary" onclick="toggleHistorico()">&#128196; Histórico</button>
                    <button class="btn btn-primary" onclick="document.getElementById('legModal').style.display='flex'; resetLegForm();">+ Nova Legislação</button>
                </div>
            </div>

            <!-- Resultados IA -->
            <div id="legAiResults" style="display:none;" class="mb-md">
                <div class="card" style="border-left:4px solid var(--color-primary);">
                    <div class="flex-between mb-sm">
                        <h3 style="margin:0;">Resultados da Verificação IA</h3>
                        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('legAiResults').style.display='none'">&times; Fechar</button>
                    </div>
                    <div id="legAiResultsContent" style="max-height:500px; overflow-y:auto;">
                    </div>
                </div>
            </div>

            <!-- Histórico -->
            <div id="legHistorico" style="display:none;" class="mb-md">
                <div class="card">
                    <div class="flex-between mb-sm">
                        <h3 style="margin:0;">Histórico de Alterações</h3>
                        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('legHistorico').style.display='none'">&times; Fechar</button>
                    </div>
                    <div id="legHistoricoContent" style="max-height:400px; overflow-y:auto; font-size:13px;">
                    </div>
                </div>
            </div>

            <!-- Tabela principal -->
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Legislação / Norma</th>
                            <th>Rolhas a que se aplica</th>
                            <th>Resumo</th>
                            <th>Estado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="legRows">
                        <tr><td colspan="5" class="muted" style="text-align:center; padding:20px;">A carregar...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Chat IA -->
            <div class="card mt-md">
                <h3 style="margin:0 0 12px 0;">Perguntar à IA sobre Legislação</h3>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="legChatInput" placeholder="Ex: A norma REACH aplica-se a rolhas naturais sem tratamento?" style="flex:1;" onkeydown="if(event.key==='Enter')enviarChatLeg()">
                    <button class="btn btn-primary" id="btnChatLeg" onclick="enviarChatLeg()">Enviar</button>
                </div>
                <div id="legChatResposta" style="display:none; margin-top:12px; padding:12px; background:#f8f9fa; border-radius:8px; font-size:13px; line-height:1.6; white-space:pre-wrap;"></div>
            </div>

            <!-- Legislação Modal -->
            <div id="legModal" class="modal-overlay" style="display:none;">
                <div class="modal-box modal-box-lg">
                    <div class="modal-header">
                        <h3 id="legModalTitle">Nova Legislação</h3>
                        <button class="modal-close" onclick="document.getElementById('legModal').style.display='none';">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="leg_id" value="0">
                        <div class="form-group"><label>Legislação / Norma</label><input type="text" id="leg_norma" placeholder="Ex: Reg. (CE) 1935/2004"></div>
                        <div class="form-group"><label>Rolhas a que se aplica</label><textarea id="leg_rolhas" rows="2" placeholder="Ex: Todas: natural, colmatada..."></textarea></div>
                        <div class="form-group"><label>Resumo do que estabelece</label><textarea id="leg_resumo" rows="3" placeholder="Resumo da legislação..."></textarea></div>
                        <div class="form-group"><label><input type="checkbox" id="leg_ativo" checked> Ativa</label></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary" onclick="guardarLeg()">Guardar</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('legModal').style.display='none';">Cancelar</button>
                    </div>
                </div>
            </div>

            <script>
            function carregarLeg() {
                fetch('<?= BASE_PATH ?>/api.php?action=get_legislacao_banco&all=1')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    var rows = (data.data && data.data.legislacao) ? data.data.legislacao : [];
                    var tbody = document.getElementById('legRows');
                    if (rows.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center; padding:20px;">Nenhuma legislação registada.</td></tr>';
                        return;
                    }
                    var html = '';
                    rows.forEach(function(r) {
                        var inativa = r.ativo !== undefined && (r.ativo == 0 || r.ativo === '0');
                        var rowStyle = inativa ? ' style="opacity:0.5; text-decoration:line-through;"' : '';
                        html += '<tr' + rowStyle + '>';
                        html += '<td><strong>' + esc(r.legislacao_norma) + '</strong></td>';
                        html += '<td class="muted" style="font-size:12px; max-width:250px;">' + esc(r.rolhas_aplicaveis || '') + '</td>';
                        html += '<td class="muted" style="font-size:12px; max-width:350px;">' + esc(r.resumo || '') + '</td>';
                        html += '<td>' + (inativa ? '<span class="pill pill-error">Inativa</span>' : '<span class="pill pill-success">Ativa</span>') + '</td>';
                        html += '<td>';
                        if (!inativa) {
                            html += '<button class="btn btn-ghost btn-sm" onclick=\'editLeg(' + JSON.stringify(r).replace(/'/g, "&#39;") + ')\'>Editar</button> ';
                        }
                        html += '<button class="btn btn-ghost btn-sm" style="color:#b42318;" onclick="eliminarLeg(' + r.id + ')">Eliminar</button>';
                        html += '</td></tr>';
                    });
                    tbody.innerHTML = html;
                });
            }
            function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            // --- CRUD ---
            function resetLegForm() {
                document.getElementById('legModalTitle').textContent = 'Nova Legislação';
                document.getElementById('leg_id').value = '0';
                document.getElementById('leg_norma').value = '';
                document.getElementById('leg_rolhas').value = '';
                document.getElementById('leg_resumo').value = '';
                document.getElementById('leg_ativo').checked = true;
            }
            function editLeg(r) {
                document.getElementById('legModalTitle').textContent = 'Editar Legislação';
                document.getElementById('leg_id').value = r.id;
                document.getElementById('leg_norma').value = r.legislacao_norma || '';
                document.getElementById('leg_rolhas').value = r.rolhas_aplicaveis || '';
                document.getElementById('leg_resumo').value = r.resumo || '';
                document.getElementById('leg_ativo').checked = r.ativo != 0;
                document.getElementById('legModal').style.display = 'flex';
            }
            function guardarLeg() {
                var fd = new FormData();
                fd.append('action', 'save_legislacao_banco');
                fd.append('id', document.getElementById('leg_id').value);
                fd.append('legislacao_norma', document.getElementById('leg_norma').value);
                fd.append('rolhas_aplicaveis', document.getElementById('leg_rolhas').value);
                fd.append('resumo', document.getElementById('leg_resumo').value);
                fd.append('ativo', document.getElementById('leg_ativo').checked ? '1' : '0');
                fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { document.getElementById('legModal').style.display = 'none'; carregarLeg(); }
                    else alert(data.error || 'Erro ao guardar.');
                });
            }
            function eliminarLeg(id) {
                if (!confirm('Eliminar esta legislação?')) return;
                var fd = new FormData();
                fd.append('action', 'delete_legislacao_banco');
                fd.append('id', id);
                fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => { if (data.success) carregarLeg(); else alert(data.error || 'Erro.'); });
            }

            // --- VERIFICAÇÃO IA ---
            function verificarLegIA() {
                var btn = document.getElementById('btnVerificarIA');
                btn.disabled = true;
                btn.textContent = 'A verificar...';
                var panel = document.getElementById('legAiResults');
                var content = document.getElementById('legAiResultsContent');
                content.innerHTML = '<div class="muted" style="text-align:center; padding:20px;">A consultar a IA... pode demorar até 30 segundos.</div>';
                panel.style.display = 'block';

                var fd = new FormData();
                fd.append('action', 'verificar_legislacao_ai');
                fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                .then(function(r) {
                    if (!r.ok) return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 300)); });
                    return r.json();
                })
                .then(function(data) {
                    btn.disabled = false;
                    btn.innerHTML = '&#9878; Verificar com IA';
                    if (!data.success) { content.innerHTML = '<div style="color:#b42318; padding:12px;">' + esc(data.error || 'Erro') + '</div>'; return; }
                    var sugs = data.data.sugestoes || [];
                    if (sugs.length === 0) { content.innerHTML = '<div class="muted" style="padding:12px;">Sem resultados.</div>'; return; }
                    var html = '';
                    var statusLabels = { ok: 'OK', corrigir: 'Corrigir', atualizada: 'Atualizada', revogada: 'Revogada', verificar: 'Verificar' };
                    var statusPills = { ok: 'pill-success', corrigir: 'pill-warning', atualizada: 'pill-primary', revogada: 'pill-error', verificar: 'pill-muted' };
                    sugs.forEach(function(s) {
                        var pillClass = statusPills[s.status] || 'pill-muted';
                        var label = statusLabels[s.status] || s.status;
                        html += '<div style="padding:12px; border-bottom:1px solid var(--color-border);" id="sug_' + s.id + '">';
                        html += '<div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">';
                        html += '<span class="pill ' + pillClass + '">' + label + '</span>';
                        html += '<strong>' + esc(s.legislacao_norma) + '</strong>';
                        html += '</div>';
                        html += '<div class="muted" style="font-size:12px; margin-bottom:8px;">' + esc(s.notas || '') + '</div>';
                        if (s.status !== 'ok') {
                            html += '<div style="display:flex; gap:6px;">';
                            html += '<button class="btn btn-primary btn-sm" onclick=\'aplicarSugestao(' + JSON.stringify(s).replace(/'/g, "&#39;") + ')\'>Aplicar</button>';
                            html += '<button class="btn btn-ghost btn-sm" onclick="ignorarSugestao(' + s.id + ')">Ignorar</button>';
                            html += '</div>';
                        }
                        html += '</div>';
                    });
                    content.innerHTML = html;
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.innerHTML = '&#9878; Verificar com IA';
                    content.innerHTML = '<div style="color:#b42318; padding:12px;">' + esc(err.message || 'Erro de ligação.') + '</div>';
                });
            }
            function aplicarSugestao(s) {
                if (!confirm('Aplicar sugestão da IA para: ' + s.legislacao_norma + '?\nAção: ' + s.status)) return;
                var fd = new FormData();
                fd.append('action', 'aplicar_sugestao_leg');
                fd.append('id', s.id);
                fd.append('status', s.status);
                fd.append('legislacao_norma', s.legislacao_norma);
                fd.append('rolhas_aplicaveis', s.rolhas_aplicaveis || '');
                fd.append('resumo', s.resumo || '');
                fd.append('notas', 'IA: ' + (s.notas || ''));
                fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function(data) {
                    if (data.success) {
                        var el = document.getElementById('sug_' + s.id);
                        if (el) el.innerHTML = '<div style="color:#16a34a; padding:4px;">&#10003; Aplicada (' + esc(data.data.acao) + ')</div>';
                        carregarLeg();
                    } else {
                        alert(data.error || 'Erro ao aplicar.');
                    }
                });
            }
            function ignorarSugestao(id) {
                var el = document.getElementById('sug_' + id);
                if (el) el.innerHTML = '<div class="muted" style="padding:4px;">Ignorada</div>';
            }

            // --- CHAT IA ---
            function enviarChatLeg() {
                var input = document.getElementById('legChatInput');
                var btn = document.getElementById('btnChatLeg');
                var resp = document.getElementById('legChatResposta');
                var pergunta = input.value.trim();
                if (!pergunta) return;
                btn.disabled = true;
                btn.textContent = 'A pensar...';
                resp.style.display = 'block';
                resp.textContent = 'A consultar a IA...';
                var fd = new FormData();
                fd.append('action', 'chat_legislacao');
                fd.append('pergunta', pergunta);
                fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function(data) {
                    btn.disabled = false;
                    btn.textContent = 'Enviar';
                    if (data.success && data.data.resposta) {
                        resp.textContent = data.data.resposta;
                    } else {
                        resp.textContent = 'Erro: ' + (data.error || 'Sem resposta.');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Enviar';
                    resp.textContent = 'Erro de ligação.';
                });
            }

            // --- HISTÓRICO ---
            function toggleHistorico() {
                var panel = document.getElementById('legHistorico');
                if (panel.style.display === 'none') {
                    panel.style.display = 'block';
                    carregarHistorico();
                } else {
                    panel.style.display = 'none';
                }
            }
            function carregarHistorico() {
                var content = document.getElementById('legHistoricoContent');
                content.innerHTML = '<div class="muted" style="text-align:center; padding:12px;">A carregar...</div>';
                fetch('<?= BASE_PATH ?>/api.php?action=get_legislacao_log')
                .then(r => r.json())
                .then(function(data) {
                    if (!data.success) { content.innerHTML = '<div class="muted">Erro.</div>'; return; }
                    var logs = data.data.log || [];
                    if (logs.length === 0) { content.innerHTML = '<div class="muted" style="padding:12px;">Nenhuma alteração registada.</div>'; return; }
                    var html = '<table style="width:100%; font-size:12px;"><thead><tr><th>Data</th><th>Ação</th><th>Notas</th><th>Por</th></tr></thead><tbody>';
                    var acaoPills = { criada: 'pill-success', corrigida: 'pill-warning', atualizada: 'pill-primary', desativada: 'pill-error', eliminada: 'pill-error' };
                    logs.forEach(function(l) {
                        var pill = acaoPills[l.acao] || 'pill-muted';
                        html += '<tr>';
                        html += '<td style="white-space:nowrap;">' + esc(l.criado_em || '') + '</td>';
                        html += '<td><span class="pill ' + pill + '">' + esc(l.acao) + '</span></td>';
                        html += '<td style="max-width:400px;">' + esc(l.notas || '-') + '</td>';
                        html += '<td>' + esc(l.utilizador_nome || '?') + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    content.innerHTML = html;
                });
            }

            carregarLeg();
            </script>

        <!-- CONFIGURAÇÕES -->
        <?php elseif ($tab === 'configuracoes'): ?>
            <h2 class="mb-md">Configurações</h2>
            <div class="card">
                <form method="POST">
                    <input type="hidden" name="action" value="save_config">
                    <div class="form-row">
                        <div class="form-group"><label>Nome da Empresa</label><input type="text" name="cfg_empresa_nome" value="<?= sanitize(getConfiguracao('empresa_nome', 'SpecLab')) ?>"></div>
                        <div class="form-group"><label>NIF</label><input type="text" name="cfg_empresa_nif" value="<?= sanitize(getConfiguracao('empresa_nif')) ?>"></div>
                    </div>
                    <div class="form-group"><label>Morada</label><input type="text" name="cfg_empresa_morada" value="<?= sanitize(getConfiguracao('empresa_morada')) ?>"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Telefone</label><input type="text" name="cfg_empresa_telefone" value="<?= sanitize(getConfiguracao('empresa_telefone')) ?>"></div>
                        <div class="form-group"><label>Email</label><input type="text" name="cfg_empresa_email" value="<?= sanitize(getConfiguracao('empresa_email')) ?>"></div>
                    </div>
                    <hr style="margin: 18px 0; border: none; border-top: 1px solid #e5e7eb;">
                    <div class="form-row">
                        <div class="form-group"><label>Prefixo Numeração</label><input type="text" name="cfg_numeracao_prefixo" value="<?= sanitize(getConfiguracao('numeracao_prefixo', 'CE')) ?>"></div>
                        <div class="form-group"><label>Ano Numeração</label><input type="text" name="cfg_numeracao_ano" value="<?= sanitize(getConfiguracao('numeracao_ano', date('Y'))) ?>"></div>
                    </div>

                    <hr style="margin: 18px 0; border: none; border-top: 1px solid #e5e7eb;">
                    <h3 style="color: #2596be; font-size: 15px; margin-bottom: 12px;">Configuração de Email (SMTP)</h3>
                    <div class="form-row">
                        <div class="form-group"><label>Servidor SMTP</label><input type="text" name="cfg_smtp_host" value="<?= sanitize(getConfiguracao('smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
                        <div class="form-group"><label>Porta SMTP</label><input type="text" name="cfg_smtp_port" value="<?= sanitize(getConfiguracao('smtp_port', '587')) ?>" placeholder="587"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Utilizador SMTP</label><input type="text" name="cfg_smtp_user" value="<?= sanitize(getConfiguracao('smtp_user')) ?>" placeholder="user@gmail.com"></div>
                        <div class="form-group"><label>Password SMTP</label><input type="password" name="cfg_smtp_pass" value="<?= sanitize(getConfiguracao('smtp_pass')) ?>" placeholder="app password"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Email Remetente</label><input type="text" name="cfg_smtp_from" value="<?= sanitize(getConfiguracao('smtp_from')) ?>" placeholder="noreply@empresa.pt"></div>
                        <div class="form-group"><label>Nome Remetente</label><input type="text" name="cfg_smtp_from_name" value="<?= sanitize(getConfiguracao('smtp_from_name', 'SpecLab')) ?>"></div>
                    </div>
                    <div class="form-group"><label>Assinatura do Email</label><textarea name="cfg_email_assinatura" rows="2"><?= sanitize(getConfiguracao('email_assinatura', 'SpecLab - Cadernos de Encargos e Especificações Técnicas')) ?></textarea></div>

                    <hr style="margin: 18px 0; border: none; border-top: 1px solid #e5e7eb;">
                    <h3 style="color: #2596be; font-size: 15px; margin-bottom: 12px;">Inteligência Artificial (OpenAI)</h3>
                    <div class="form-group">
                        <label>Chave API OpenAI</label>
                        <input type="password" name="cfg_openai_api_key" value="<?= sanitize(getConfiguracao('openai_api_key')) ?>" placeholder="sk-...">
                        <small class="muted">Usada para assistente IA, verificação de legislação e chat. Obter em platform.openai.com</small>
                    </div>

                    <div class="mt-lg">
                        <button type="submit" class="btn btn-primary">Guardar Configurações</button>
                    </div>
                </form>
            </div>
        <!-- PLANOS (super_admin only) -->
        <?php elseif ($tab === 'planos' && $isSuperAdminUser): ?>
            <div class="flex-between mb-md">
                <h2>Planos</h2>
            </div>
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Max. Utilizadores</th>
                            <th>Max. Especificações</th>
                            <th>Clientes</th>
                            <th>Fornecedores</th>
                            <th>Preço/mês</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($planos as $pl): ?>
                        <tr>
                            <td><code><?= sanitize($pl['id']) ?></code></td>
                            <td><strong><?= sanitize($pl['nome']) ?></strong></td>
                            <td><?= (int)$pl['max_utilizadores'] ?></td>
                            <td><?= $pl['max_especificacoes'] ? (int)$pl['max_especificacoes'] : '<span class="muted">Ilimitado</span>' ?></td>
                            <td><?= $pl['tem_clientes'] ? '<span class="pill pill-success">Sim</span>' : '<span class="pill pill-muted">Não</span>' ?></td>
                            <td><?= $pl['tem_fornecedores'] ? '<span class="pill pill-success">Sim</span>' : '<span class="pill pill-muted">Não</span>' ?></td>
                            <td><?= $pl['preco_mensal'] ? number_format((float)$pl['preco_mensal'], 2, ',', '.') . ' €' : '<span class="muted">-</span>' ?></td>
                            <td>
                                <button class="btn btn-ghost btn-sm" onclick="editPlano(<?= htmlspecialchars(json_encode($pl)) ?>)">Editar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($planos)): ?>
                        <tr><td colspan="8" class="muted" style="text-align:center; padding:20px;">Nenhum plano configurado. A tabela de planos será criada automaticamente.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Plano Modal -->
            <div id="planoModal" class="modal-overlay" style="display:none;">
                <div class="modal-box">
                    <div class="modal-header">
                        <h3 id="planoModalTitle">Editar Plano</h3>
                        <button class="modal-close" onclick="document.getElementById('planoModal').style.display='none'">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_plano">
                        <input type="hidden" name="plano_id" id="pl_id" value="">

                        <div class="form-row">
                            <div class="form-group">
                                <label>Nome do Plano</label>
                                <input type="text" name="plano_nome" id="pl_nome" required>
                            </div>
                            <div class="form-group">
                                <label>Ordem</label>
                                <input type="number" name="plano_ordem" id="pl_ordem" min="0" value="0">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Max. Utilizadores</label>
                                <input type="number" name="plano_max_utilizadores" id="pl_max_utilizadores" min="1" value="5">
                            </div>
                            <div class="form-group">
                                <label>Max. Especificações <span class="muted">(vazio = ilimitado)</span></label>
                                <input type="number" name="plano_max_especificacoes" id="pl_max_especificacoes" min="1">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="plano_tem_clientes" id="pl_tem_clientes"> Gestão de Clientes
                                </label>
                            </div>
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="plano_tem_fornecedores" id="pl_tem_fornecedores" checked> Gestão de Fornecedores
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Preço Mensal (€) <span class="muted">(opcional)</span></label>
                            <input type="number" name="plano_preco_mensal" id="pl_preco_mensal" min="0" step="0.01">
                        </div>

                        <div class="form-group">
                            <label>Descrição</label>
                            <textarea name="plano_descricao" id="pl_descricao" rows="2"></textarea>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('planoModal').style.display='none'">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
    var isSuperAdmin = <?= $isSuperAdminUser ? 'true' : 'false' ?>;
    var currentOrgId = <?= $orgId ? (int)$orgId : 'null' ?>;

    // ===================================
    // ORGANIZAÇÕES
    // ===================================

    function resetOrgForm() {
        document.getElementById('orgModalTitle').textContent = 'Nova Organização';
        document.getElementById('org_id').value = '0';
        document.getElementById('org_nome').value = '';
        document.getElementById('org_slug').value = '';
        document.getElementById('org_nif').value = '';
        document.getElementById('org_morada').value = '';
        document.getElementById('org_telefone').value = '';
        document.getElementById('org_email').value = '';
        document.getElementById('org_website').value = '';
        document.getElementById('org_cor_primaria').value = '#2596be';
        document.getElementById('org_cor_primaria_text').value = '#2596be';
        document.getElementById('org_cor_primaria_dark').value = '#1a7a9e';
        document.getElementById('org_cor_primaria_dark_text').value = '#1a7a9e';
        document.getElementById('org_cor_primaria_light').value = '#e6f4f9';
        document.getElementById('org_cor_primaria_light_text').value = '#e6f4f9';
        document.getElementById('org_numeracao_prefixo').value = 'CE';
        document.getElementById('org_tem_clientes').checked = false;
        document.getElementById('org_tem_fornecedores').checked = true;
        document.getElementById('org_plano').value = 'basico';
        document.getElementById('org_max_utilizadores').value = '5';
        document.getElementById('org_max_especificacoes').value = '100';
        document.getElementById('org_ativo').checked = true;
        document.getElementById('org_logo_preview').style.display = 'none';
        document.getElementById('org_logo_file').value = '';
    }

    function editOrg(o) {
        document.getElementById('orgModal').style.display = 'flex';
        document.getElementById('orgModalTitle').textContent = 'Editar Organização';
        document.getElementById('org_id').value = o.id;
        document.getElementById('org_nome').value = o.nome || '';
        document.getElementById('org_slug').value = o.slug || '';
        document.getElementById('org_nif').value = o.nif || '';
        document.getElementById('org_morada').value = o.morada || '';
        document.getElementById('org_telefone').value = o.telefone || '';
        document.getElementById('org_email').value = o.email || '';
        document.getElementById('org_website').value = o.website || '';

        var cor = o.cor_primaria || '#2596be';
        var corDark = o.cor_primaria_dark || '#1a7a9e';
        var corLight = o.cor_primaria_light || '#e6f4f9';
        document.getElementById('org_cor_primaria').value = cor;
        document.getElementById('org_cor_primaria_text').value = cor;
        document.getElementById('org_cor_primaria_dark').value = corDark;
        document.getElementById('org_cor_primaria_dark_text').value = corDark;
        document.getElementById('org_cor_primaria_light').value = corLight;
        document.getElementById('org_cor_primaria_light_text').value = corLight;

        document.getElementById('org_numeracao_prefixo').value = o.numeracao_prefixo || 'CE';
        document.getElementById('org_tem_clientes').checked = (o.tem_clientes == 1);
        document.getElementById('org_tem_fornecedores').checked = (o.tem_fornecedores == 1);
        document.getElementById('org_plano').value = o.plano || 'basico';
        document.getElementById('org_max_utilizadores').value = o.max_utilizadores || '5';
        document.getElementById('org_max_especificacoes').value = o.max_especificacoes || '100';
        document.getElementById('org_ativo').checked = (o.ativo == 1);
        document.getElementById('org_logo_file').value = '';

        if (o.logo) {
            document.getElementById('org_logo_preview').style.display = 'block';
            document.getElementById('org_logo_img').src = '<?= BASE_PATH ?>/uploads/logos/' + o.logo;
        } else {
            document.getElementById('org_logo_preview').style.display = 'none';
        }
    }

    // Sincronizar color pickers com campos de texto
    document.addEventListener('DOMContentLoaded', function() {
        var colorPairs = [
            ['org_cor_primaria', 'org_cor_primaria_text'],
            ['org_cor_primaria_dark', 'org_cor_primaria_dark_text'],
            ['org_cor_primaria_light', 'org_cor_primaria_light_text']
        ];
        colorPairs.forEach(function(pair) {
            var picker = document.getElementById(pair[0]);
            var text = document.getElementById(pair[1]);
            if (picker && text) {
                picker.addEventListener('input', function() { text.value = picker.value; });
                text.addEventListener('input', function() {
                    if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                        picker.value = text.value;
                    }
                });
            }
        });

        // Upload de logo via AJAX após submissão do formulário de organização
        var orgForm = document.querySelector('#orgModal form');
        if (orgForm) {
            orgForm.addEventListener('submit', function(e) {
                var logoFile = document.getElementById('org_logo_file');
                var orgIdField = document.getElementById('org_id');

                // Se há ficheiro de logo e é edição (org_id > 0), fazer upload separado
                if (logoFile && logoFile.files.length > 0 && orgIdField.value !== '0') {
                    // Guardar referência para upload após submissão
                    sessionStorage.setItem('pending_logo_org_id', orgIdField.value);
                }
            });
        }

        // Verificar se há upload de logo pendente
        var pendingLogoOrgId = sessionStorage.getItem('pending_logo_org_id');
        if (pendingLogoOrgId) {
            sessionStorage.removeItem('pending_logo_org_id');
            // O upload será tratado na próxima carga - é mais seguro fazer pelo modal
        }
    });

    // Upload de logo separado via AJAX
    function uploadOrgLogo(orgId) {
        var fileInput = document.getElementById('org_logo_file');
        if (!fileInput || !fileInput.files.length) return;

        var formData = new FormData();
        formData.append('logo', fileInput.files[0]);
        formData.append('organizacao_id', orgId);

        fetch('<?= BASE_PATH ?>/api.php?action=upload_org_logo', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                location.reload();
            } else {
                alert(result.error || 'Erro ao carregar logo.');
            }
        })
        .catch(function() {
            alert('Erro ao carregar logo.');
        });
    }

    // ===================================
    // UTILIZADORES
    // ===================================

    function resetUserForm() {
        document.getElementById('userModalTitle').textContent = 'Novo Utilizador';
        document.getElementById('user_id').value = '0';
        document.getElementById('user_nome').value = '';
        document.getElementById('user_username').value = '';
        document.getElementById('user_password').value = '';
        document.getElementById('user_role').value = 'user';
        document.getElementById('user_ativo').checked = true;
        document.getElementById('user_assinatura').value = '';
        document.getElementById('user_assinatura_preview').style.display = 'none';
        document.getElementById('remover_assinatura').value = '0';
        if (isSuperAdmin) {
            var orgSelect = document.getElementById('user_organizacao_id');
            if (orgSelect) orgSelect.value = '';
        }
    }

    function removerAssinatura() {
        document.getElementById('remover_assinatura').value = '1';
        document.getElementById('user_assinatura_preview').style.display = 'none';
        document.getElementById('user_assinatura').value = '';
    }

    function editUser(u) {
        document.getElementById('userModal').style.display = 'flex';
        document.getElementById('userModalTitle').textContent = 'Editar Utilizador';
        document.getElementById('user_id').value = u.id;
        document.getElementById('user_nome').value = u.nome;
        document.getElementById('user_username').value = u.username;
        document.getElementById('user_password').value = '';
        document.getElementById('user_role').value = u.role;
        document.getElementById('user_ativo').checked = u.ativo == 1;
        document.getElementById('remover_assinatura').value = '0';
        document.getElementById('user_assinatura').value = '';

        if (isSuperAdmin) {
            var orgSelect = document.getElementById('user_organizacao_id');
            if (orgSelect) orgSelect.value = u.organizacao_id || '';
        }

        if (u.assinatura) {
            document.getElementById('user_assinatura_preview').style.display = 'block';
            document.getElementById('user_assinatura_img').src = '<?= BASE_PATH ?>/uploads/assinaturas/' + u.assinatura;
        } else {
            document.getElementById('user_assinatura_preview').style.display = 'none';
        }
    }

    // ===================================
    // CLIENTES
    // ===================================

    function resetClienteForm() {
        document.getElementById('clienteModalTitle').textContent = 'Novo Cliente';
        document.getElementById('cliente_id').value = '0';
        ['nome','sigla','morada','telefone','email','nif','contacto'].forEach(function(f) {
            var el = document.getElementById('cl_' + f);
            if (el) el.value = '';
        });
        if (isSuperAdmin) {
            var orgField = document.getElementById('cl_organizacao_id');
            if (orgField) orgField.value = currentOrgId || '';
        }
    }

    function editCliente(c) {
        document.getElementById('clienteModal').style.display = 'flex';
        document.getElementById('clienteModalTitle').textContent = 'Editar Cliente';
        document.getElementById('cliente_id').value = c.id;
        ['nome','sigla','morada','telefone','email','nif','contacto'].forEach(function(f) {
            var el = document.getElementById('cl_' + f);
            if (el) el.value = c[f] || '';
        });
        if (isSuperAdmin) {
            var orgField = document.getElementById('cl_organizacao_id');
            if (orgField) orgField.value = c.organizacao_id || '';
        }
    }

    // ===================================
    // FORNECEDORES
    // ===================================

    function resetFornecedorForm() {
        document.getElementById('fornecedorModalTitle').textContent = 'Novo Fornecedor';
        document.getElementById('fornecedor_id').value = '0';
        ['nome','sigla','morada','telefone','email','nif','contacto'].forEach(function(f) {
            var el = document.getElementById('fn_' + f);
            if (el) el.value = '';
        });
        if (isSuperAdmin) {
            var orgField = document.getElementById('fn_organizacao_id');
            if (orgField) orgField.value = currentOrgId || '';
        }
    }

    function editFornecedor(f) {
        document.getElementById('fornecedorModal').style.display = 'flex';
        document.getElementById('fornecedorModalTitle').textContent = 'Editar Fornecedor';
        document.getElementById('fornecedor_id').value = f.id;
        ['nome','sigla','morada','telefone','email','nif','contacto'].forEach(function(field) {
            var el = document.getElementById('fn_' + field);
            if (el) el.value = f[field] || '';
        });
        if (isSuperAdmin) {
            var orgField = document.getElementById('fn_organizacao_id');
            if (orgField) orgField.value = f.organizacao_id || '';
        }
    }

    // ===================================
    // PRODUTOS
    // ===================================

    function resetProdutoForm() {
        document.getElementById('produtoModalTitle').textContent = 'Novo Produto';
        document.getElementById('produto_id').value = '0';
        document.getElementById('pr_nome').value = '';
        document.getElementById('pr_tipo').value = '';
        document.getElementById('pr_descricao').value = '';
        if (isSuperAdmin) {
            var globalCb = document.getElementById('pr_global');
            if (globalCb) {
                globalCb.checked = false;
            }
            var orgField = document.getElementById('pr_organizacao_id');
            if (orgField) orgField.value = currentOrgId || '';
        }
    }

    function editProduto(p) {
        document.getElementById('produtoModal').style.display = 'flex';
        document.getElementById('produtoModalTitle').textContent = 'Editar Produto';
        document.getElementById('produto_id').value = p.id;
        document.getElementById('pr_nome').value = p.nome;
        document.getElementById('pr_tipo').value = p.tipo || '';
        document.getElementById('pr_descricao').value = p.descricao || '';
        if (isSuperAdmin) {
            var isGlobal = !p.organizacao_id;
            var globalCb = document.getElementById('pr_global');
            if (globalCb) {
                globalCb.checked = isGlobal;
            }
            var orgField = document.getElementById('pr_organizacao_id');
            if (orgField) orgField.value = p.organizacao_id || '';
        }
    }

    function toggleProdutoOrg(isGlobal) {
        var orgField = document.getElementById('pr_organizacao_id');
        if (orgField) {
            if (isGlobal) {
                orgField.value = '';
            } else {
                orgField.value = currentOrgId || '';
            }
        }
    }

    // ===================================
    // TEMPLATES
    // ===================================

    function gerirTemplates(produtoId, produtoNome) {
        document.getElementById('tmpl_produto_id').value = produtoId;
        document.getElementById('tmpl_produto_nome').textContent = produtoNome;
        document.getElementById('templateModal').style.display = 'flex';
        carregarTemplates(produtoId);
    }

    function carregarTemplates(produtoId) {
        fetch('<?= BASE_PATH ?>/api.php?action=get_templates&produto_id=' + produtoId)
        .then(function(r) { return r.json(); })
        .then(function(result) {
            var container = document.getElementById('tmpl_rows');
            if (result.success && result.data && result.data.length > 0) {
                var html = '<table style="width:100%; font-size:13px; border-collapse:collapse;">';
                html += '<thead><tr style="background:#f3f4f6;"><th style="padding:6px 8px; text-align:left;">Categoria</th><th style="padding:6px 8px; text-align:left;">Ensaio</th><th style="padding:6px 8px; text-align:left;">Especificação</th><th style="padding:6px 8px; text-align:left;">Método</th><th style="padding:6px 8px; text-align:left;">NQA</th><th style="padding:6px 8px;"></th></tr></thead><tbody>';
                result.data.forEach(function(t) {
                    html += '<tr style="border-bottom:1px solid #e5e7eb;">';
                    html += '<td style="padding:6px 8px;">' + (t.categoria || '') + '</td>';
                    html += '<td style="padding:6px 8px;">' + (t.ensaio || '') + '</td>';
                    html += '<td style="padding:6px 8px;"><strong>' + (t.especificacao_valor || '') + '</strong></td>';
                    html += '<td style="padding:6px 8px;">' + (t.metodo || '') + '</td>';
                    html += '<td style="padding:6px 8px;">' + (t.amostra_nqa || '') + '</td>';
                    html += '<td style="padding:6px 8px;"><button class="btn btn-danger btn-sm" onclick="removerTemplate(' + t.id + ')">x</button></td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div style="text-align:center; padding:20px; color:#667085;">Sem templates. Adicione parâmetros abaixo.</div>';
            }
        })
        .catch(function() {
            document.getElementById('tmpl_rows').innerHTML = '<div style="text-align:center; padding:20px; color:#b42318;">Erro ao carregar templates.</div>';
        });
    }

    function adicionarTemplate() {
        var produtoId = document.getElementById('tmpl_produto_id').value;
        var data = {
            action: 'save_template',
            produto_id: produtoId,
            categoria: document.getElementById('tmpl_categoria').value,
            ensaio: document.getElementById('tmpl_ensaio').value,
            especificacao_valor: document.getElementById('tmpl_especificacao').value,
            metodo: document.getElementById('tmpl_metodo').value,
            amostra_nqa: document.getElementById('tmpl_nqa').value
        };

        if (!data.ensaio) { alert('Introduza o nome do ensaio.'); return; }

        fetch('<?= BASE_PATH ?>/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                document.getElementById('tmpl_ensaio').value = '';
                document.getElementById('tmpl_especificacao').value = '';
                document.getElementById('tmpl_metodo').value = '';
                document.getElementById('tmpl_nqa').value = '';
                carregarTemplates(produtoId);
            } else {
                alert(result.error || 'Erro ao guardar template.');
            }
        });
    }

    function removerTemplate(id) {
        if (!confirm('Remover este template?')) return;
        var produtoId = document.getElementById('tmpl_produto_id').value;
        fetch('<?= BASE_PATH ?>/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_template', id: id })
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                carregarTemplates(produtoId);
            }
        });
    }

    // ===================================
    // PLANOS
    // ===================================

    var planosConfig = <?= json_encode($planos ?: []) ?>;

    function editPlano(p) {
        document.getElementById('planoModal').style.display = 'flex';
        document.getElementById('planoModalTitle').textContent = 'Editar Plano: ' + p.nome;
        document.getElementById('pl_id').value = p.id;
        document.getElementById('pl_nome').value = p.nome || '';
        document.getElementById('pl_max_utilizadores').value = p.max_utilizadores || '5';
        document.getElementById('pl_max_especificacoes').value = p.max_especificacoes || '';
        document.getElementById('pl_tem_clientes').checked = (p.tem_clientes == 1);
        document.getElementById('pl_tem_fornecedores').checked = (p.tem_fornecedores == 1);
        document.getElementById('pl_preco_mensal').value = p.preco_mensal || '';
        document.getElementById('pl_descricao').value = p.descricao || '';
        document.getElementById('pl_ordem').value = p.ordem || '0';
    }

    // Auto-fill limites ao mudar plano no formulário de organização
    var orgPlanoSelect = document.getElementById('org_plano');
    if (orgPlanoSelect) {
        orgPlanoSelect.addEventListener('change', function() {
            var selectedPlano = this.value;
            var plano = planosConfig.find(function(p) { return p.id === selectedPlano; });
            if (plano) {
                document.getElementById('org_max_utilizadores').value = plano.max_utilizadores || '5';
                document.getElementById('org_max_especificacoes').value = plano.max_especificacoes || '';
                document.getElementById('org_tem_clientes').checked = (plano.tem_clientes == 1);
                document.getElementById('org_tem_fornecedores').checked = (plano.tem_fornecedores == 1);
            }
        });
    }

    // ===================================
    // GLOBAL: Close modal on overlay click
    // ===================================
    document.querySelectorAll('.modal-overlay').forEach(function(m) {
        m.addEventListener('click', function(e) { if (e.target === m) m.style.display = 'none'; });
    });
    </script>
</body>
</html>
