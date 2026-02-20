<?php
/**
 * SpecLab - Cadernos de Encargos
 * Painel de Administração (Multi-Tenant)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
// Legislacao e Ensaios tabs são acessíveis a todos os utilizadores autenticados
$tab = $_GET['tab'] ?? 'utilizadores';
if (in_array($tab, ['legislacao', 'ensaios'])) {
    requireLogin();
} else {
    requireAdmin();
}

$user = getCurrentUser();
$db = getDB();
$msg = $_GET['msg'] ?? '';
$msgType = $_GET['msg_type'] ?? 'success';

$isSuperAdminUser = isSuperAdmin();
$orgId = $user['org_id'] ?? null;

// Carregar lista de organizações (para selects e tab de organizações)
$organizacoes = [];
$planos = [];
if ($isSuperAdminUser) {
    $organizacoes = $db->query('SELECT * FROM organizacoes ORDER BY nome')->fetchAll();
    // Não expor passwords no HTML/JSON
    foreach ($organizacoes as &$_org) { unset($_org['email_speclab_pass'], $_org['smtp_pass']); }
    unset($_org);
    $planos = getPlanos($db);
}

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        header('Location: ' . BASE_PATH . '/admin.php?msg=' . urlencode('Erro de segurança. Recarregue a página.') . '&msg_type=error');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $isNewUser = ($uid === 0);
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
            // Verificar que o utilizador pertence à organização (exceto super_admin)
            if (!$isSuperAdminUser) {
                $checkStmt = $db->prepare('SELECT organizacao_id FROM utilizadores WHERE id = ?');
                $checkStmt->execute([$uid]);
                $checkOrg = $checkStmt->fetchColumn();
                if ($checkOrg != $orgId) {
                    header('Location: ' . BASE_PATH . '/admin.php?tab=utilizadores&msg=Acesso+negado&msg_type=error');
                    exit;
                }
            }
            $sql = 'UPDATE utilizadores SET nome = ?, username = ?, role = ?, ativo = ?, organizacao_id = ? WHERE id = ?';
            $params = [$nome, $username, $role, $ativo, $userOrgId, $uid];
            $db->prepare($sql)->execute($params);
            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare('UPDATE utilizadores SET password = ? WHERE id = ?')->execute([$hash, $uid]);
            }
        } else {
            if (empty($password)) $password = bin2hex(random_bytes(4));
            $hash = password_hash($password, PASSWORD_DEFAULT);
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
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg'])) {
                $filename = 'assinatura_' . $uid . '_' . time() . '.' . $ext;
                $filepath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['assinatura']['tmp_name'], $filepath)) {
                    if ($ext === 'svg') sanitizeSvg($filepath);
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

        $msgUser = 'Utilizador guardado';
        if ($isNewUser && !empty($password)) {
            // Guardar password na sessão (nunca na URL)
            $_SESSION['temp_new_password'] = $password;
        }
        header('Location: ' . BASE_PATH . '/admin.php?tab=utilizadores&msg=' . urlencode($msgUser));
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
            if (!$isSuperAdminUser) {
                $checkStmt = $db->prepare('SELECT organizacao_id FROM clientes WHERE id = ?');
                $checkStmt->execute([$cid]);
                if ($checkStmt->fetchColumn() != $orgId) {
                    header('Location: ' . BASE_PATH . '/admin.php?tab=clientes&msg=Acesso+negado&msg_type=error');
                    exit;
                }
            }
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
        $descricao = trim($_POST['descricao'] ?? '');

        // Determinar organizacao_id
        if ($isSuperAdminUser) {
            $isGlobal = isset($_POST['global']) ? true : false;
            $produtoOrgId = $isGlobal ? null : (!empty($_POST['organizacao_id']) ? (int)$_POST['organizacao_id'] : null);
        } else {
            $produtoOrgId = $orgId;
        }

        if ($pid) {
            if (!$isSuperAdminUser) {
                $checkStmt = $db->prepare('SELECT organizacao_id FROM produtos WHERE id = ?');
                $checkStmt->execute([$pid]);
                if ($checkStmt->fetchColumn() != $orgId) {
                    header('Location: ' . BASE_PATH . '/admin.php?tab=produtos&msg=Acesso+negado&msg_type=error');
                    exit;
                }
            }
            $db->prepare('UPDATE produtos SET nome=?, descricao=?, organizacao_id=? WHERE id=?')
                ->execute([$nome, $descricao, $produtoOrgId, $pid]);
        } else {
            $db->prepare('INSERT INTO produtos (nome, descricao, organizacao_id) VALUES (?, ?, ?)')
                ->execute([$nome, $descricao, $produtoOrgId]);
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
        $cor_primaria = sanitizeColor(trim($_POST['cor_primaria'] ?? '#2596be'));
        $cor_primaria_dark = sanitizeColor(trim($_POST['cor_primaria_dark'] ?? '#1a7a9e'), '#1a7a9e');
        $cor_primaria_light = sanitizeColor(trim($_POST['cor_primaria_light'] ?? '#e6f4f9'), '#e6f4f9');
        $numeracao_prefixo = trim($_POST['numeracao_prefixo'] ?? 'CE');
        $tem_clientes = isset($_POST['tem_clientes']) ? 1 : 0;
        $tem_fornecedores = isset($_POST['tem_fornecedores']) ? 1 : 0;
        $plano = $_POST['plano'] ?? 'basico';
        $max_utilizadores = (int)($_POST['max_utilizadores'] ?? 5);
        $max_especificacoes = !empty($_POST['max_especificacoes']) ? (int)$_POST['max_especificacoes'] : null;
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        $email_speclab = trim($_POST['email_speclab'] ?? '');
        $email_speclab_pass = trim($_POST['email_speclab_pass'] ?? '');
        $email_permitido_users = isset($_POST['email_permitido_users']) ? 1 : 0;

        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $nome));
            $slug = trim($slug, '-');
        }

        if ($oid) {
            $updateSql = 'UPDATE organizacoes SET nome=?, slug=?, nif=?, morada=?, telefone=?, email=?, website=?, cor_primaria=?, cor_primaria_dark=?, cor_primaria_light=?, numeracao_prefixo=?, tem_clientes=?, tem_fornecedores=?, plano=?, max_utilizadores=?, max_especificacoes=?, ativo=?, email_speclab=?, email_permitido_users=?, updated_at=NOW()';
            $updateParams = [$nome, $slug, $nif, $morada, $telefone, $email, $website, $cor_primaria, $cor_primaria_dark, $cor_primaria_light, $numeracao_prefixo, $tem_clientes, $tem_fornecedores, $plano, $max_utilizadores, $max_especificacoes, $ativo, $email_speclab, $email_permitido_users];
            if ($email_speclab_pass !== '') {
                $updateSql .= ', email_speclab_pass=?';
                $updateParams[] = $email_speclab_pass;
            }
            $updateSql .= ' WHERE id=?';
            $updateParams[] = $oid;
            $db->prepare($updateSql)->execute($updateParams);
        } else {
            // Verificar slug único
            $stmt = $db->prepare('SELECT id FROM organizacoes WHERE slug = ?');
            $stmt->execute([$slug]);
            if ($stmt->fetch()) {
                $slug .= '-' . time();
            }
            $db->prepare('INSERT INTO organizacoes (nome, slug, nif, morada, telefone, email, website, cor_primaria, cor_primaria_dark, cor_primaria_light, numeracao_prefixo, tem_clientes, tem_fornecedores, plano, max_utilizadores, max_especificacoes, ativo, email_speclab, email_speclab_pass, email_permitido_users) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$nome, $slug, $nif, $morada, $telefone, $email, $website, $cor_primaria, $cor_primaria_dark, $cor_primaria_light, $numeracao_prefixo, $tem_clientes, $tem_fornecedores, $plano, $max_utilizadores, $max_especificacoes, $ativo, $email_speclab, $email_speclab_pass, $email_permitido_users]);
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
                    if ($ext === 'svg') sanitizeSvg($uploadDir . $filename);
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
        $fields = ['nome', 'sigla', 'morada', 'telefone', 'email', 'nif', 'contacto', 'certificacoes', 'notas'];
        $values = array_map(fn($f) => trim($_POST[$f] ?? ''), $fields);

        if ($isSuperAdminUser) {
            $fornOrgId = !empty($_POST['organizacao_id']) ? (int)$_POST['organizacao_id'] : $orgId;
        } else {
            $fornOrgId = $orgId;
        }

        if ($fid) {
            if (!$isSuperAdminUser) {
                $checkStmt = $db->prepare('SELECT organizacao_id FROM fornecedores WHERE id = ?');
                $checkStmt->execute([$fid]);
                if ($checkStmt->fetchColumn() != $orgId) {
                    header('Location: ' . BASE_PATH . '/admin.php?tab=fornecedores&msg=Acesso+negado&msg_type=error');
                    exit;
                }
            }
            // Capturar dados anteriores para audit log
            $oldStmt = $db->prepare('SELECT nome, sigla, morada, telefone, email, nif, contacto, certificacoes, notas FROM fornecedores WHERE id = ?');
            $oldStmt->execute([$fid]);
            $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);

            $sql = 'UPDATE fornecedores SET nome=?, sigla=?, morada=?, telefone=?, email=?, nif=?, contacto=?, certificacoes=?, notas=? WHERE id=?';
            $db->prepare($sql)->execute([...$values, $fid]);

            // Registar alterações no log
            $newData = array_combine($fields, $values);
            $changed = [];
            foreach ($fields as $f) {
                if (($oldData[$f] ?? '') !== ($newData[$f] ?? '')) $changed[] = $f;
            }
            if ($changed) {
                $db->prepare('INSERT INTO fornecedores_log (fornecedor_id, acao, campos_alterados, dados_anteriores, dados_novos, alterado_por, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$fid, 'atualizado', implode(', ', $changed), json_encode($oldData), json_encode($newData), $user['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
            }
        } else {
            $sql = 'INSERT INTO fornecedores (nome, sigla, morada, telefone, email, nif, contacto, certificacoes, notas, organizacao_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $db->prepare($sql)->execute([...$values, $fornOrgId]);
            $newFid = (int)$db->lastInsertId();
            // Registar criação no log
            $db->prepare('INSERT INTO fornecedores_log (fornecedor_id, acao, dados_novos, alterado_por, ip_address) VALUES (?, ?, ?, ?, ?)')
                ->execute([$newFid, 'criado', json_encode(array_combine($fields, $values)), $user['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
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

    if ($action === 'save_config' && $isSuperAdminUser) {
        // Campos sensíveis: não gravar se vazio (manter valor atual)
        $sensitiveKeys = ['smtp_pass', 'openai_api_key'];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'cfg_') === 0) {
                $chave = substr($key, 4);
                if (in_array($chave, $sensitiveKeys) && trim($value) === '') continue;
                setConfiguracao($chave, trim($value));
            }
        }
        header('Location: ' . BASE_PATH . '/admin.php?tab=configuracoes&msg=Configura%C3%A7%C3%B5es+guardadas');
        exit;
    }
}

// Org admin: guardar email speclab da organização
if ($action === 'save_org_branding' && $user['role'] === 'org_admin' && $orgId) {
    $cor_primaria = sanitizeColor(trim($_POST['cor_primaria'] ?? '#2596be'));
    $cor_primaria_dark = sanitizeColor(trim($_POST['cor_primaria_dark'] ?? '#1a7a9e'), '#1a7a9e');
    $cor_primaria_light = sanitizeColor(trim($_POST['cor_primaria_light'] ?? '#e6f4f9'), '#e6f4f9');
    $nif = trim($_POST['nif'] ?? '');
    $morada = trim($_POST['morada'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $emailOrg = trim($_POST['email'] ?? '');
    $website = trim($_POST['website'] ?? '');

    $db->prepare('UPDATE organizacoes SET cor_primaria=?, cor_primaria_dark=?, cor_primaria_light=?, nif=?, morada=?, telefone=?, email=?, website=?, updated_at=NOW() WHERE id=?')
        ->execute([$cor_primaria, $cor_primaria_dark, $cor_primaria_light, $nif, $morada, $telefone, $emailOrg, $website, $orgId]);

    // Upload de logo
    if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/logos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg'])) {
            $filename = 'org_' . $orgId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploadDir . $filename)) {
                if ($ext === 'svg') sanitizeSvg($uploadDir . $filename);
                $old = $db->prepare('SELECT logo FROM organizacoes WHERE id = ?');
                $old->execute([$orgId]);
                $oldLogo = $old->fetchColumn();
                if ($oldLogo && file_exists($uploadDir . $oldLogo)) unlink($uploadDir . $oldLogo);
                $db->prepare('UPDATE organizacoes SET logo = ? WHERE id = ?')->execute([$filename, $orgId]);
            }
        }
    }

    // Atualizar sessão com novas cores/logo
    $orgRefresh = $db->prepare('SELECT * FROM organizacoes WHERE id = ?');
    $orgRefresh->execute([$orgId]);
    $orgNew = $orgRefresh->fetch();
    if ($orgNew) setUserSession(getCurrentUser(), $orgNew);

    header('Location: ' . BASE_PATH . '/admin.php?tab=configuracoes&msg=Branding+atualizado');
    exit;
}

if ($action === 'save_org_email' && $user['role'] === 'org_admin' && $orgId) {
    $orgEmailSpeclab = trim($_POST['email_speclab'] ?? '');
    $orgEmailPass = trim($_POST['email_speclab_pass'] ?? '');
    if ($orgEmailSpeclab) {
        $sql = 'UPDATE organizacoes SET email_speclab = ?';
        $params = [$orgEmailSpeclab];
        if ($orgEmailPass !== '') {
            $sql .= ', email_speclab_pass = ?';
            $params[] = $orgEmailPass;
        }
        $sql .= ' WHERE id = ?';
        $params[] = $orgId;
        $db->prepare($sql)->execute($params);
    }
    header('Location: ' . BASE_PATH . '/admin.php?tab=configuracoes&msg=Email+guardado');
    exit;
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
$tabLabels = ['produtos' => 'Produtos', 'clientes' => 'Clientes', 'fornecedores' => 'Fornecedores', 'utilizadores' => 'Utilizadores', 'organizacoes' => 'Organizações', 'legislacao' => 'Legislação', 'ensaios' => 'Ensaios', 'configuracoes' => 'Configurações', 'planos' => 'Planos'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => BASE_PATH . '/dashboard.php'],
    ['label' => $tabLabels[$tab] ?? ucfirst($tab)]
];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração - SpecLab</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/img/favicon.svg">
    <script>var CSRF_TOKEN = '<?= getCsrfToken() ?>';</script>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="container">
        <?php if ($msg): ?>
            <div class="alert <?= $msgType === 'error' ? 'alert-error' : 'alert-success' ?>"><?= sanitize($msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['temp_new_password'])): ?>
            <div class="alert alert-success" style="background:#fef3c7; border-color:#f59e0b; color:#92400e;">
                <strong>Palavra-passe inicial:</strong> <code><?= sanitize($_SESSION['temp_new_password']) ?></code>
                <br><small>Copie agora. Esta informação não será mostrada novamente.</small>
            </div>
            <?php unset($_SESSION['temp_new_password']); ?>
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
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
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

                        <hr style="margin: 18px 0; border: none; border-top: 1px solid #e5e7eb;">
                        <h4 style="color: #2596be; font-size: 14px; margin-bottom: 12px;">Email SpecLab</h4>
                        <p style="font-size: 12px; color: #667085; margin: -8px 0 12px;">Email @speclab.pt desta organização. O administrador da org pode alterar a password nas suas configurações.</p>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Email SpecLab</label>
                                <input type="email" name="email_speclab" id="org_email_speclab" placeholder="org@speclab.pt">
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="email_speclab_pass" id="org_email_speclab_pass" placeholder="Definida pelo admin da org">
                            </div>
                        </div>
                        <div class="form-group">
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" name="email_permitido_users" id="org_email_permitido_users"> Permitir utilizadores enviarem emails
                            </label>
                            <small style="color:#667085;">Se ativo, utilizadores normais (não apenas admins) podem enviar emails</small>
                        </div>

                        <hr style="margin: 18px 0; border: none; border-top: 1px solid #e5e7eb;">
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
                                <?php $roleLabels = ['super_admin' => 'Super Admin', 'org_admin' => 'Administrador', 'user' => 'Utilizador']; ?>
                                <span class="pill <?= $rolePillClass ?>"><?= $roleLabels[$u['role']] ?? sanitize($u['role']) ?></span>
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
                    <?php if (empty($utilizadores)): ?>
                        <tr><td colspan="6" class="muted" style="text-align:center; padding:20px;">Nenhum utilizador encontrado.</td></tr>
                    <?php endif; ?>
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
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
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
                            <label id="user_password_label">Palavra-passe <span class="muted">(deixe vazio para gerar automaticamente)</span></label>
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
                            <input type="file" name="assinatura" id="user_assinatura" accept="image/png,image/jpeg,image/gif,image/svg+xml" style="font-size: 13px;">
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
                    <?php if (empty($clientes)): ?>
                        <tr><td colspan="5" class="muted" style="text-align:center; padding:20px;">Nenhum cliente encontrado.</td></tr>
                    <?php endif; ?>
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
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
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
                            <th>Certificações</th>
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
                            <td><?= !empty($f['certificacoes']) ? sanitize($f['certificacoes']) : '<span class="muted">—</span>' ?></td>
                            <?php if ($isSuperAdminUser): ?>
                                <td><?= sanitize($f['org_nome'] ?? 'Sem org.') ?></td>
                            <?php endif; ?>
                            <td style="white-space:nowrap;">
                                <button class="btn btn-ghost btn-sm" onclick="editFornecedor(<?= htmlspecialchars(json_encode($f)) ?>)">Editar</button>
                                <button class="btn btn-ghost btn-sm" onclick="verHistoricoFornecedor(<?= $f['id'] ?>, '<?= sanitize($f['nome']) ?>')" title="Ver histórico">Histórico</button>
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
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
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
                        <div class="form-group"><label>Certificações</label><input type="text" name="certificacoes" id="fn_certificacoes" placeholder="Ex: ISO 9001, FSSC 22000, FSC..."></div>
                        <div class="form-group"><label>Notas</label><textarea name="notas" id="fn_notas" rows="2" placeholder="Observações internas sobre o fornecedor..."></textarea></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('fornecedorModal').style.display='none'">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Histórico Fornecedor Modal -->
            <div id="historicoFornModal" class="modal-overlay" style="display:none;">
                <div class="modal-box modal-box-lg">
                    <div class="modal-header">
                        <h3>Histórico: <span id="histFornNome"></span></h3>
                        <button class="modal-close" onclick="document.getElementById('historicoFornModal').style.display='none'">&times;</button>
                    </div>
                    <div style="max-height:400px; overflow-y:auto;">
                        <table style="font-size:13px;">
                            <thead><tr><th>Data</th><th>Ação</th><th>Campos alterados</th><th>Por</th></tr></thead>
                            <tbody id="histFornBody"></tbody>
                        </table>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('historicoFornModal').style.display='none'">Fechar</button>
                    </div>
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
                    <?php if (empty($produtos)): ?>
                        <tr><td colspan="5" class="muted" style="text-align:center; padding:20px;">Nenhum produto encontrado.</td></tr>
                    <?php endif; ?>
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
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="action" value="save_produto">
                        <input type="hidden" name="produto_id" id="produto_id" value="0">

                        <?php if ($isSuperAdminUser): ?>
                            <input type="hidden" name="organizacao_id" id="pr_organizacao_id" value="">
                        <?php else: ?>
                            <input type="hidden" name="organizacao_id" value="<?= (int)$orgId ?>">
                        <?php endif; ?>

                        <div class="form-group"><label>Nome</label><input type="text" name="nome" id="pr_nome" required></div>
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
                        <div class="form-group"><label>Link URL <span style="font-weight:normal; color:#667; font-size:12px;">(URL externo ou caminho do ficheiro no servidor)</span></label><input type="text" id="leg_link_url" placeholder="Ex: https://eur-lex.europa.eu/... ou /uploads/legislacao/doc.pdf"></div>
                        <div class="form-group"><label><input type="checkbox" id="leg_ativo" checked> Ativa</label></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary" onclick="guardarLeg()">Guardar</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('legModal').style.display='none';">Cancelar</button>
                    </div>
                </div>
            </div>

            <!-- Modal Visualização Documento Legislação -->
            <div id="legDocModal" class="modal-overlay" style="display:none;">
                <div class="modal-box" style="width:90%; max-width:1100px; height:85vh; display:flex; flex-direction:column;">
                    <div class="modal-header">
                        <h3 id="legDocTitle" style="flex:1; margin-right:12px;"></h3>
                        <button class="btn btn-ghost btn-sm" id="legDocOpenBtn" onclick="" style="margin-right:8px; font-size:12px;">Abrir em nova janela</button>
                        <button class="modal-close" onclick="fecharLegDoc();">&times;</button>
                    </div>
                    <div id="legDocResumo" style="padding:8px 16px; font-size:13px; color:#555; border-bottom:1px solid #e5e7eb; max-height:60px; overflow:auto;"></div>
                    <div id="legDocContent" style="flex:1; overflow:hidden; position:relative;">
                        <iframe id="legDocIframe" style="width:100%; height:100%; border:none; display:none;"></iframe>
                        <div id="legDocFallback" style="display:none; padding:40px; text-align:center;">
                            <p style="font-size:15px; color:#555; margin-bottom:16px;">Este site bloqueia a visualização integrada.</p>
                            <a id="legDocFallbackLink" href="#" target="_blank" class="btn btn-primary">Abrir documento em nova janela</a>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            function abrirLegDoc(url, nome, resumo) {
                var fullUrl = url.startsWith('/') ? '<?= BASE_PATH ?>' + url : url;
                var isPdf = url.toLowerCase().endsWith('.pdf');
                var isLocal = url.startsWith('/');
                document.getElementById('legDocTitle').textContent = nome;
                document.getElementById('legDocResumo').textContent = resumo || '';
                document.getElementById('legDocOpenBtn').onclick = function() { window.open(fullUrl, '_blank'); };
                var iframe = document.getElementById('legDocIframe');
                var fallback = document.getElementById('legDocFallback');
                document.getElementById('legDocFallbackLink').href = fullUrl;
                if (isPdf || isLocal) {
                    iframe.style.display = 'block';
                    fallback.style.display = 'none';
                    iframe.src = fullUrl;
                } else {
                    iframe.style.display = 'none';
                    iframe.src = '';
                    fallback.style.display = 'block';
                }
                document.getElementById('legDocModal').style.display = 'flex';
            }
            function fecharLegDoc() {
                document.getElementById('legDocModal').style.display = 'none';
                document.getElementById('legDocIframe').src = '';
            }
            </script>

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
                        html += '<td><strong>' + esc(r.legislacao_norma) + '</strong>';
                        if (r.link_url) {
                            html += ' <a href="#" onclick="abrirLegDoc(\'' + esc(r.link_url).replace(/'/g,"&#39;") + '\', \'' + esc(r.legislacao_norma).replace(/'/g,"&#39;") + '\', \'' + esc(r.resumo || '').replace(/'/g,"&#39;") + '\'); return false;" title="Ver documento" style="color:var(--primary-color,#2563eb); margin-left:6px; font-size:15px;">&#128279;</a>';
                        }
                        html += '</td>';
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
                document.getElementById('leg_link_url').value = '';
                document.getElementById('leg_ativo').checked = true;
            }
            function editLeg(r) {
                document.getElementById('legModalTitle').textContent = 'Editar Legislação';
                document.getElementById('leg_id').value = r.id;
                document.getElementById('leg_norma').value = r.legislacao_norma || '';
                document.getElementById('leg_rolhas').value = r.rolhas_aplicaveis || '';
                document.getElementById('leg_resumo').value = r.resumo || '';
                document.getElementById('leg_link_url').value = r.link_url || '';
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
                fd.append('link_url', document.getElementById('leg_link_url').value);
                fd.append('ativo', document.getElementById('leg_ativo').checked ? '1' : '0');
                fd.append('csrf_token', CSRF_TOKEN);
                fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { document.getElementById('legModal').style.display = 'none'; carregarLeg(); }
                    else appAlert(data.error || 'Erro ao guardar.');
                });
            }
            function eliminarLeg(id) {
                appConfirmDanger('Eliminar esta legislação?', function() {
                    var fd = new FormData();
                    fd.append('action', 'delete_legislacao_banco');
                    fd.append('id', id);
                    fd.append('csrf_token', CSRF_TOKEN);
                    fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => { if (data.success) carregarLeg(); else appAlert(data.error || 'Erro.'); });
                });
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
                fd.append('csrf_token', CSRF_TOKEN);
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
                        if (s.status === 'verificar') {
                            html += '<div style="display:flex; gap:6px;">';
                            html += '<button class="btn btn-ghost btn-sm" onclick="ignorarSugestao(' + s.id + ')">OK, Verificado</button>';
                            html += '</div>';
                        } else if (s.status !== 'ok') {
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
                appConfirm('Aplicar sugestão da IA para: <strong>' + esc(s.legislacao_norma) + '</strong>?<br>Ação: ' + esc(s.status), function() {
                    var fd = new FormData();
                    fd.append('action', 'aplicar_sugestao_leg');
                    fd.append('id', s.id);
                    fd.append('status', s.status);
                    fd.append('legislacao_norma', s.legislacao_norma);
                    fd.append('rolhas_aplicaveis', s.rolhas_aplicaveis || '');
                    fd.append('resumo', s.resumo || '');
                    fd.append('notas', 'IA: ' + (s.notas || ''));
                    fd.append('csrf_token', CSRF_TOKEN);
                    fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(function(data) {
                        if (data.success) {
                            var el = document.getElementById('sug_' + s.id);
                            if (el) el.innerHTML = '<div style="color:#16a34a; padding:4px;">&#10003; Aplicada (' + esc(data.data.acao) + ')</div>';
                            carregarLeg();
                        } else {
                            appAlert(data.error || 'Erro ao aplicar.');
                        }
                    });
                }, 'Aplicar Sugestão IA');
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
                fd.append('csrf_token', CSRF_TOKEN);
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

        <!-- LEGISLAÇÃO (org_admin / user - read-only) -->
        <?php elseif ($tab === 'legislacao' && !$isSuperAdminUser): ?>
            <div class="flex-between mb-md">
                <h2>Legislação Aplicável</h2>
            </div>
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Legislação / Norma</th>
                            <th>Rolhas a que se aplica</th>
                            <th>Resumo</th>
                        </tr>
                    </thead>
                    <tbody id="legRowsRO">
                        <tr><td colspan="3" class="muted" style="text-align:center; padding:20px;">A carregar...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Modal Visualização Documento -->
            <div id="legDocModal" class="modal-overlay" style="display:none;">
                <div class="modal-box" style="width:90%; max-width:1100px; height:85vh; display:flex; flex-direction:column;">
                    <div class="modal-header">
                        <h3 id="legDocTitle" style="flex:1; margin-right:12px;"></h3>
                        <button class="btn btn-ghost btn-sm" id="legDocOpenBtn" onclick="" style="margin-right:8px; font-size:12px;">Abrir em nova janela</button>
                        <button class="modal-close" onclick="fecharLegDoc();">&times;</button>
                    </div>
                    <div id="legDocResumo" style="padding:8px 16px; font-size:13px; color:#555; border-bottom:1px solid #e5e7eb; max-height:60px; overflow:auto;"></div>
                    <div id="legDocContent" style="flex:1; overflow:hidden; position:relative;">
                        <iframe id="legDocIframe" style="width:100%; height:100%; border:none; display:none;"></iframe>
                        <div id="legDocFallback" style="display:none; padding:40px; text-align:center;">
                            <p style="font-size:15px; color:#555; margin-bottom:16px;">Este site bloqueia a visualização integrada.</p>
                            <a id="legDocFallbackLink" href="#" target="_blank" class="btn btn-primary">Abrir documento em nova janela</a>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            function abrirLegDoc(url, nome, resumo) {
                var fullUrl = url.startsWith('/') ? '<?= BASE_PATH ?>' + url : url;
                var isPdf = url.toLowerCase().endsWith('.pdf');
                var isLocal = url.startsWith('/');
                document.getElementById('legDocTitle').textContent = nome;
                document.getElementById('legDocResumo').textContent = resumo || '';
                document.getElementById('legDocOpenBtn').onclick = function() { window.open(fullUrl, '_blank'); };
                var iframe = document.getElementById('legDocIframe');
                var fallback = document.getElementById('legDocFallback');
                document.getElementById('legDocFallbackLink').href = fullUrl;
                if (isPdf || isLocal) {
                    iframe.style.display = 'block'; fallback.style.display = 'none'; iframe.src = fullUrl;
                } else {
                    iframe.style.display = 'none'; iframe.src = ''; fallback.style.display = 'block';
                }
                document.getElementById('legDocModal').style.display = 'flex';
            }
            function fecharLegDoc() {
                document.getElementById('legDocModal').style.display = 'none';
                document.getElementById('legDocIframe').src = '';
            }
            function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            fetch('<?= BASE_PATH ?>/api.php?action=get_legislacao_banco')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                var rows = (data.data && data.data.legislacao) ? data.data.legislacao : [];
                var tbody = document.getElementById('legRowsRO');
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="muted" style="text-align:center; padding:20px;">Nenhuma legislação registada.</td></tr>';
                    return;
                }
                var html = '';
                rows.forEach(function(r) {
                    html += '<tr>';
                    html += '<td><strong>' + esc(r.legislacao_norma) + '</strong>';
                    if (r.link_url) {
                        html += ' <a href="#" onclick="abrirLegDoc(\'' + esc(r.link_url).replace(/'/g,"&#39;") + '\', \'' + esc(r.legislacao_norma).replace(/'/g,"&#39;") + '\', \'' + esc(r.resumo || '').replace(/'/g,"&#39;") + '\'); return false;" title="Ver documento" style="color:var(--primary-color,#2563eb); margin-left:6px; font-size:15px;">&#128279;</a>';
                    }
                    html += '</td>';
                    html += '<td class="muted" style="font-size:12px; max-width:250px;">' + esc(r.rolhas_aplicaveis || '') + '</td>';
                    html += '<td class="muted" style="font-size:12px; max-width:350px;">' + esc(r.resumo || '') + '</td>';
                    html += '</tr>';
                });
                tbody.innerHTML = html;
            });
            </script>

        <!-- ENSAIOS (super_admin - editável) -->
        <?php elseif ($tab === 'ensaios' && $isSuperAdminUser): ?>
            <div class="flex-between mb-md">
                <h2>Banco de Ensaios</h2>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-secondary" onclick="toggleColunasConfig()">⚙ Configurar Colunas</button>
                    <button class="btn btn-primary" onclick="document.getElementById('ensaioModal').style.display='flex'; resetEnsaioForm();">+ Novo Ensaio</button>
                </div>
            </div>

            <!-- Painel de configuração de colunas (inicialmente oculto) -->
            <div id="colunasConfigPanel" style="display:none; margin-bottom:16px;">
                <div class="card" style="padding:16px;">
                    <div class="flex-between mb-sm">
                        <h3 style="margin:0; font-size:15px;">Configurar Colunas</h3>
                        <button class="btn btn-primary btn-sm" onclick="abrirColunaModal()">+ Nova Coluna</button>
                    </div>
                    <p class="muted" style="font-size:12px; margin-bottom:10px;">Ative/desative colunas e defina quais organizações as veem. Colunas fixas não podem ser eliminadas.</p>
                    <table id="colunasConfigTable" style="width:100%; font-size:13px;">
                        <thead><tr><th style="width:25%;">Nome</th><th style="width:10%;">Tipo</th><th style="width:10%;">Fixa</th><th style="width:25%;">Organizações</th><th style="width:10%;">Ativa</th><th style="width:20%;">Ações</th></tr></thead>
                        <tbody id="colunasConfigRows"><tr><td colspan="6" class="muted" style="text-align:center; padding:12px;">A carregar...</td></tr></tbody>
                    </table>
                    <hr style="margin:16px 0;">
                    <h3 style="margin:0 0 8px; font-size:15px;">Legenda da Tabela de Ensaios (por organização)</h3>
                    <p class="muted" style="font-size:12px; margin-bottom:8px;">Texto livre que aparece por baixo da tabela de ensaios no editor, consulta e PDF.</p>
                    <div class="form-group">
                        <label>Organização</label>
                        <select id="legendaOrgSelect" class="form-control" onchange="carregarLegendaOrg()">
                            <option value="">-- Selecionar --</option>
                            <option value="global">Todas (por defeito)</option>
                            <?php foreach ($organizacoes as $org): ?>
                            <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="legendaOrgFields" style="display:none;">
                        <div class="form-group">
                            <textarea id="saLegendaText" class="form-control" rows="3" placeholder="Ex: NEI - Nível Especial de Inspeção conforme NP2922"></textarea>
                        </div>
                        <div class="form-group" style="display:flex; align-items:center; gap:12px;">
                            <label style="margin:0; white-space:nowrap;">Tamanho (pt):</label>
                            <input type="number" id="saLegendaTamanho" class="form-control" value="9" min="6" max="14" style="width:80px;">
                            <button class="btn btn-primary btn-sm" onclick="guardarLegendaOrg()">Guardar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal nova/editar coluna -->
            <div id="colunaModal" class="modal-overlay" style="display:none; background:rgba(0,0,0,0.6);">
                <div class="modal-box" style="max-width:480px; background:#fff;">
                    <div class="modal-header">
                        <h3 id="colunaModalTitle">Nova Coluna</h3>
                        <button class="modal-close" onclick="document.getElementById('colunaModal').style.display='none';">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="col_id" value="0">
                        <input type="hidden" id="col_campo_fixo" value="">
                        <div class="form-group">
                            <label>Nome da coluna</label>
                            <input type="text" id="col_nome" class="form-control" placeholder="Ex: Tolerância">
                        </div>
                        <div class="form-group" id="col_tipo_group">
                            <label>Tipo</label>
                            <select id="col_tipo" class="form-control">
                                <option value="texto">Texto</option>
                                <option value="numero">Número</option>
                                <option value="sim_nao">Sim/Não</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Ordem</label>
                            <input type="number" id="col_ordem" class="form-control" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label><input type="checkbox" id="col_todas_orgs" checked onchange="document.getElementById('col_orgs_select').style.display = this.checked ? 'none' : 'block';"> Visível para todas as organizações</label>
                        </div>
                        <div id="col_orgs_select" style="display:none;" class="form-group">
                            <label>Organizações</label>
                            <div style="max-height:150px; overflow-y:auto; border:1px solid #d1d5db; border-radius:6px; padding:8px;">
                                <?php foreach ($organizacoes as $org): ?>
                                <label style="display:block; margin-bottom:4px; font-size:13px;">
                                    <input type="checkbox" class="col_org_chk" value="<?= $org['id'] ?>"> <?= htmlspecialchars($org['nome']) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><input type="checkbox" id="col_ativo" checked> Ativa</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="document.getElementById('colunaModal').style.display='none';">Cancelar</button>
                        <button class="btn btn-primary" onclick="guardarColuna()">Guardar</button>
                    </div>
                </div>
            </div>

            <p class="muted" style="font-size:12px; margin-bottom:8px;">Dica: <strong>Cmd+click</strong> (Mac) ou <strong>Ctrl+click</strong> em 2 células da mesma coluna para fundir.</p>
            <div class="card" style="overflow:visible;">
                <table id="bancoEnsaiosTable">
                    <thead><tr><th>Categoria</th><th>Ensaio</th><th>Método/Norma</th><th title="Nível Especial de Inspeção">NEI</th><th title="Nível de Qualidade Aceitável">NQA</th><th>Valor Referência</th><th>Estado</th><th>Ações</th></tr></thead>
                    <tbody id="ensaioRows"><tr><td colspan="8" class="muted" style="text-align:center; padding:20px;">A carregar...</td></tr></tbody>
                </table>
            </div>
            <div id="bancoMergeFloat" style="display:none; position:fixed; z-index:999; background:#fff; border:1px solid #d1d5db; border-radius:6px; padding:4px 8px; box-shadow:0 2px 8px rgba(0,0,0,.15);">
                <button class="btn btn-primary btn-sm" onclick="executarBancoMerge()">Fundir</button>
                <button class="btn btn-ghost btn-sm" onclick="limparBancoSel()">Cancelar</button>
            </div>
            <style>
            #bancoEnsaiosTable td.bm-selected { background:#dbeafe !important; outline:2px solid #3b82f6; }
            #bancoEnsaiosTable td.bm-master { position:relative; background:#f0f9ff; }
            #bancoEnsaiosTable td.bm-master .bm-tools { display:none; position:absolute; top:2px; right:2px; gap:2px; }
            #bancoEnsaiosTable td.bm-master:hover .bm-tools { display:flex; }
            .bm-tools button { font-size:10px; padding:1px 4px; border:1px solid #d1d5db; border-radius:3px; background:#fff; cursor:pointer; line-height:1.2; }
            .bm-tools button:hover { background:#f3f4f6; }
            .bm-tools .bm-unmerge:hover { background:#fee2e2; color:#b42318; }
            #bancoEnsaiosTable { table-layout:fixed; width:100%; }
            #bancoEnsaiosTable th { position:relative; overflow:hidden; text-overflow:ellipsis; }
            #bancoEnsaiosTable td { overflow:hidden; text-overflow:ellipsis; }
            #bancoEnsaiosTable th .bcr-handle { position:absolute; right:-3px; top:0; bottom:0; width:6px; cursor:col-resize; z-index:2; }
            #bancoEnsaiosTable th .bcr-handle:hover, #bancoEnsaiosTable th .bcr-handle.active { background:rgba(0,0,0,0.1); }
            </style>

            <div id="ensaioModal" class="modal-overlay" style="display:none;">
                <div class="modal-box modal-box-lg">
                    <div class="modal-header">
                        <h3 id="ensaioModalTitle">Novo Ensaio</h3>
                        <button class="modal-close" onclick="document.getElementById('ensaioModal').style.display='none';">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="ens_id" value="0">
                        <div class="form-row">
                            <div class="form-group"><label>Categoria</label><input type="text" id="ens_categoria" placeholder="Ex: Físico-Mecânico" list="ensCatList"></div>
                            <div class="form-group"><label>Ensaio</label><input type="text" id="ens_ensaio" placeholder="Ex: Comprimento"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Método / Norma</label><input type="text" id="ens_metodo" placeholder="Ex: ISO 9727-1"></div>
                            <div class="form-group"><label>NEI <span style="font-weight:normal; color:#667; font-size:12px;">(Nível Especial de Inspeção)</span></label><input type="text" id="ens_nivel_especial" placeholder="Ex: S-2, S-4"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>NQA <span style="font-weight:normal; color:#667; font-size:12px;">(Nível de Qualidade Aceitável)</span></label><input type="text" id="ens_nqa" placeholder="Ex: 2,5"></div>
                            <div class="form-group"><label>Valor de Referência</label><input type="text" id="ens_exemplo" placeholder="Ex: ±0.7 mm"></div>
                        </div>
                        <div id="ens_custom_fields"></div>
                        <div class="form-group"><label><input type="checkbox" id="ens_ativo" checked> Ativo</label></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary" onclick="guardarEnsaio()">Guardar</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('ensaioModal').style.display='none';">Cancelar</button>
                    </div>
                </div>
            </div>
            <datalist id="ensCatList"></datalist>

            <script>
            // === CONFIGURAÇÃO DE COLUNAS ===
            var ensaioColunas = [];
            function toggleColunasConfig() {
                var p = document.getElementById('colunasConfigPanel');
                p.style.display = p.style.display === 'none' ? 'block' : 'none';
                if (p.style.display === 'block') carregarColunas();
            }
            function carregarColunas() {
                fetch('<?= BASE_PATH ?>/api.php?action=get_ensaios_colunas').then(function(r){return r.json();}).then(function(data) {
                    ensaioColunas = (data.data && data.data.colunas) || [];
                    renderColunasConfig();
                });
            }
            function renderColunasConfig() {
                var tbody = document.getElementById('colunasConfigRows');
                if (ensaioColunas.length === 0) { tbody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:12px;">Nenhuma coluna.</td></tr>'; return; }
                var tipos = { texto: 'Texto', numero: 'Número', sim_nao: 'Sim/Não' };
                var html = '';
                ensaioColunas.forEach(function(c) {
                    var fixa = c.campo_fixo ? '<span class="pill pill-info" style="font-size:11px;">Fixa</span>' : '<span class="muted" style="font-size:11px;">Custom</span>';
                    var orgs = c.todas_orgs == 1 ? '<span class="muted" style="font-size:12px;">Todas</span>' : (c.org_ids || '<span class="muted" style="font-size:12px;">Nenhuma</span>');
                    var ativa = c.ativo == 1 ? '<span class="pill pill-success">Ativa</span>' : '<span class="pill pill-error">Inativa</span>';
                    html += '<tr>';
                    html += '<td><strong>' + escE(c.nome) + '</strong></td>';
                    html += '<td>' + (tipos[c.tipo] || c.tipo) + '</td>';
                    html += '<td>' + fixa + '</td>';
                    html += '<td>' + orgs + '</td>';
                    html += '<td>' + ativa + '</td>';
                    html += '<td>';
                    html += '<button class="btn btn-ghost btn-sm" onclick=\'editarColuna(' + JSON.stringify(c).replace(/\'/g,"&#39;") + ')\'>Editar</button>';
                    if (!c.campo_fixo) html += ' <button class="btn btn-ghost btn-sm" style="color:#b42318;" onclick="eliminarColuna(' + c.id + ')">Eliminar</button>';
                    html += '</td></tr>';
                });
                tbody.innerHTML = html;
            }
            function abrirColunaModal() {
                document.getElementById('colunaModalTitle').textContent = 'Nova Coluna';
                document.getElementById('col_id').value = '0';
                document.getElementById('col_campo_fixo').value = '';
                document.getElementById('col_nome').value = '';
                document.getElementById('col_tipo').value = 'texto';
                document.getElementById('col_tipo_group').style.display = 'block';
                document.getElementById('col_ordem').value = ensaioColunas.length + 1;
                document.getElementById('col_todas_orgs').checked = true;
                document.getElementById('col_orgs_select').style.display = 'none';
                document.getElementById('col_ativo').checked = true;
                document.querySelectorAll('.col_org_chk').forEach(function(cb) { cb.checked = false; });
                document.getElementById('colunaModal').style.display = 'flex';
            }
            function editarColuna(c) {
                document.getElementById('colunaModalTitle').textContent = 'Editar Coluna';
                document.getElementById('col_id').value = c.id;
                document.getElementById('col_campo_fixo').value = c.campo_fixo || '';
                document.getElementById('col_nome').value = c.nome;
                document.getElementById('col_tipo').value = c.tipo;
                document.getElementById('col_tipo_group').style.display = c.campo_fixo ? 'none' : 'block';
                document.getElementById('col_ordem').value = c.ordem;
                document.getElementById('col_todas_orgs').checked = c.todas_orgs == 1;
                document.getElementById('col_orgs_select').style.display = c.todas_orgs == 1 ? 'none' : 'block';
                document.getElementById('col_ativo').checked = c.ativo == 1;
                var orgIds = c.org_ids ? c.org_ids.split(',') : [];
                document.querySelectorAll('.col_org_chk').forEach(function(cb) {
                    cb.checked = orgIds.indexOf(cb.value) !== -1;
                });
                document.getElementById('colunaModal').style.display = 'flex';
            }
            function guardarColuna() {
                var orgIds = [];
                if (!document.getElementById('col_todas_orgs').checked) {
                    document.querySelectorAll('.col_org_chk:checked').forEach(function(cb) { orgIds.push(parseInt(cb.value)); });
                }
                fetch('<?= BASE_PATH ?>/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({
                        action: 'save_ensaio_coluna',
                        id: parseInt(document.getElementById('col_id').value),
                        nome: document.getElementById('col_nome').value,
                        tipo: document.getElementById('col_tipo').value,
                        ordem: parseInt(document.getElementById('col_ordem').value),
                        todas_orgs: document.getElementById('col_todas_orgs').checked ? 1 : 0,
                        ativo: document.getElementById('col_ativo').checked ? 1 : 0,
                        org_ids: orgIds
                    })
                }).then(function(r){return r.json();}).then(function(data) {
                    if (data.success) {
                        document.getElementById('colunaModal').style.display = 'none';
                        carregarColunas();
                        carregarEnsaios(); // refresh table with new columns
                    } else appAlert(data.error || 'Erro ao guardar coluna.');
                });
            }
            function eliminarColuna(id) {
                appConfirmDanger('Eliminar esta coluna personalizada? Os valores associados serão perdidos.', function() {
                    fetch('<?= BASE_PATH ?>/api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                        body: JSON.stringify({ action: 'delete_ensaio_coluna', id: id })
                    }).then(function(r){return r.json();}).then(function(data) {
                        if (data.success) { carregarColunas(); carregarEnsaios(); }
                        else appAlert(data.error || 'Erro.');
                    });
                });
            }
            function carregarLegendaOrg() {
                var orgId = document.getElementById('legendaOrgSelect').value;
                if (!orgId) { document.getElementById('legendaOrgFields').style.display = 'none'; return; }
                document.getElementById('legendaOrgFields').style.display = 'block';
                var url = orgId === 'global'
                    ? '<?= BASE_PATH ?>/api.php?action=get_ensaios_legenda&global=1'
                    : '<?= BASE_PATH ?>/api.php?action=get_ensaios_legenda&org_id=' + orgId;
                fetch(url).then(function(r){return r.json();}).then(function(data) {
                    document.getElementById('saLegendaText').value = (data.data && data.data.legenda) || '';
                    document.getElementById('saLegendaTamanho').value = (data.data && data.data.tamanho) || 9;
                });
            }
            function guardarLegendaOrg() {
                var orgId = document.getElementById('legendaOrgSelect').value;
                if (!orgId) return;
                var payload = {
                    action: orgId === 'global' ? 'save_ensaios_legenda_global' : 'save_ensaios_legenda',
                    legenda: document.getElementById('saLegendaText').value,
                    tamanho: parseInt(document.getElementById('saLegendaTamanho').value) || 9
                };
                if (orgId !== 'global') payload.org_id = parseInt(orgId);
                fetch('<?= BASE_PATH ?>/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify(payload)
                }).then(function(r){return r.json();}).then(function(data) {
                    if (data.success) appAlert('Legenda guardada.');
                    else appAlert(data.error || 'Erro ao guardar.');
                });
            }

            // === BANCO DE ENSAIOS ===
            function escE(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
            var bancoRows = [], bancoMerges = [], bancoColWidths = null;
            var bancoColunas = [], bancoCustomValues = {};
            var bmSel = { col: null, start: null, end: null }; // merge selection
            var defaultColWidths = null; // calculado dinamicamente

            function carregarEnsaios() {
                Promise.all([
                    fetch('<?= BASE_PATH ?>/api.php?action=get_ensaios_banco&all=1').then(function(r){return r.json();}),
                    fetch('<?= BASE_PATH ?>/api.php?action=get_banco_merges').then(function(r){return r.json();}),
                    fetch('<?= BASE_PATH ?>/api.php?action=get_ensaios_colunas').then(function(r){return r.json();}),
                    fetch('<?= BASE_PATH ?>/api.php?action=get_ensaio_valores_custom').then(function(r){return r.json();})
                ]).then(function(res) {
                    bancoRows = (res[0].data && res[0].data.ensaios) || [];
                    var mData = (res[1].data && res[1].data.merges) || [];
                    if (Array.isArray(mData)) {
                        bancoMerges = mData;
                    } else {
                        bancoMerges = mData.merges || [];
                        bancoColWidths = mData.colWidths || null;
                    }
                    bancoColunas = ((res[2].data && res[2].data.colunas) || []).filter(function(c) { return c.ativo == 1; });
                    bancoCustomValues = (res[3].data && res[3].data.valores) || {};
                    renderBancoTable();
                });
            }

            function renderBancoTable() {
                var tbody = document.getElementById('ensaioRows');
                var totalCols = bancoColunas.length + 2; // +Estado +Ações
                if (bancoRows.length === 0) { tbody.innerHTML = '<tr><td colspan="' + totalCols + '" class="muted" style="text-align:center; padding:20px;">Nenhum ensaio registado.</td></tr>'; return; }

                // Atualizar thead dinamicamente
                var theadHtml = '<tr>';
                bancoColunas.forEach(function(c) {
                    theadHtml += '<th title="' + escE(c.nome) + '">' + escE(c.nome) + '</th>';
                });
                theadHtml += '<th>Estado</th><th>Ações</th></tr>';
                document.querySelector('#bancoEnsaiosTable thead').innerHTML = theadHtml;

                // Build merge maps
                var hidden = {}, spans = {}, aligns = {};
                bancoMerges.forEach(function(m) {
                    var k = m.row + '_' + m.col;
                    spans[k] = m.span;
                    aligns[k] = { h: m.hAlign || 'center', v: m.vAlign || 'middle' };
                    for (var r = m.row + 1; r < m.row + m.span; r++) hidden[r + '_' + m.col] = true;
                });
                var html = '', cats = new Set(), lastCat = '';
                bancoRows.forEach(function(r, idx) {
                    cats.add(r.categoria);
                    var inativo = r.ativo == 0;
                    html += '<tr data-ridx="' + idx + '"' + (inativo ? ' style="opacity:0.5;"' : '') + '>';
                    bancoColunas.forEach(function(col, ci) {
                        var k = idx + '_' + ci;
                        if (hidden[k]) return;
                        var rs = spans[k] ? ' rowspan="' + spans[k] + '"' : '';
                        var isMaster = !!spans[k];
                        var ms = aligns[k] ? 'vertical-align:' + aligns[k].v + ';text-align:' + aligns[k].h + ';' : '';
                        var cls = isMaster ? ' class="bm-master"' : '';
                        var val;
                        if (col.campo_fixo) {
                            // Coluna fixa: ler do ensaio
                            var fval = r[col.campo_fixo] || '';
                            if (col.campo_fixo === 'categoria') {
                                val = r.categoria !== lastCat ? '<strong>' + escE(r.categoria) + '</strong>' : '<span class="muted" style="font-size:12px;">〃</span>';
                                lastCat = r.categoria;
                            } else {
                                val = '<span class="muted" style="font-size:12px;">' + escE(fval) + '</span>';
                            }
                        } else {
                            // Coluna custom: ler do mapa de valores
                            var cv = (bancoCustomValues[r.id] && bancoCustomValues[r.id][col.id]) || '';
                            if (col.tipo === 'sim_nao') {
                                val = cv == '1' ? '<span class="pill pill-success" style="font-size:11px;">Sim</span>' : '<span class="muted" style="font-size:12px;">Não</span>';
                            } else {
                                val = '<span class="muted" style="font-size:12px;">' + escE(cv) + '</span>';
                            }
                        }
                        var tools = '';
                        if (isMaster) {
                            tools = '<div class="bm-tools">' +
                                '<button onclick="toggleBancoAlign(' + idx + ',' + ci + ',\'h\')" title="Alinhar H">&#9776;</button>' +
                                '<button onclick="toggleBancoAlign(' + idx + ',' + ci + ',\'v\')" title="Alinhar V">&#8597;</button>' +
                                '<button class="bm-unmerge" onclick="desfazerBancoMerge(' + idx + ',' + ci + ')" title="Separar">&#10005;</button>' +
                            '</div>';
                        }
                        html += '<td data-col="' + ci + '"' + rs + cls + ' style="' + ms + '">' + val + tools + '</td>';
                    });
                    html += '<td>' + (inativo ? '<span class="pill pill-error">Inativo</span>' : '<span class="pill pill-success">Ativo</span>') + '</td>';
                    html += '<td><button class="btn btn-ghost btn-sm" onclick=\'editEnsaio(' + JSON.stringify(r).replace(/'/g,"&#39;") + ')\'>Editar</button> ';
                    html += '<button class="btn btn-ghost btn-sm" style="color:#b42318;" onclick="eliminarEnsaio(' + r.id + ',' + idx + ')">Eliminar</button></td></tr>';
                });
                tbody.innerHTML = html;
                var dl = document.getElementById('ensCatList'); dl.innerHTML = '';
                cats.forEach(function(c) { var o = document.createElement('option'); o.value = c; dl.appendChild(o); });
                // Aplicar larguras e inicializar resize
                var tbl = document.getElementById('bancoEnsaiosTable');
                var ths = tbl.querySelectorAll('thead th');
                if (!bancoColWidths) {
                    // Larguras automáticas proporcionais
                    var dataColCount = bancoColunas.length;
                    var pct = Math.floor(80 / (dataColCount || 1));
                    bancoColWidths = [];
                    for (var i = 0; i < dataColCount; i++) bancoColWidths.push(pct);
                    bancoColWidths.push(8); // Estado
                    bancoColWidths.push(12); // Ações
                }
                var cw = bancoColWidths;
                for (var i = 0; i < ths.length && i < cw.length; i++) ths[i].style.width = cw[i] + '%';
                initBancoColResize(tbl);
            }

            // --- Column resize ---
            var bcrState = null;
            function initBancoColResize(table) {
                var ths = table.querySelectorAll('thead th');
                for (var i = 0; i < ths.length - 1; i++) {
                    if (ths[i].querySelector('.bcr-handle')) continue;
                    var h = document.createElement('div');
                    h.className = 'bcr-handle';
                    ths[i].appendChild(h);
                    h.addEventListener('mousedown', bcrStart);
                }
            }
            function bcrStart(e) {
                e.preventDefault(); e.stopPropagation();
                var th = e.target.parentElement;
                var table = th.closest('table');
                var ths = table.querySelectorAll('thead th');
                var idx = Array.prototype.indexOf.call(ths, th);
                var thNext = ths[idx + 1];
                if (!thNext) return;
                e.target.classList.add('active');
                bcrState = { table: table, th: th, thNext: thNext, ths: ths, tableW: table.offsetWidth, startX: e.clientX, startW: th.offsetWidth, startNextW: thNext.offsetWidth, handle: e.target };
                document.addEventListener('mousemove', bcrMove);
                document.addEventListener('mouseup', bcrEnd);
            }
            function bcrMove(e) {
                if (!bcrState) return;
                var s = bcrState, diff = e.clientX - s.startX;
                var newW = s.startW + diff, newNextW = s.startNextW - diff;
                var minPx = s.tableW * 0.04;
                if (newW < minPx || newNextW < minPx) return;
                s.th.style.width = (newW / s.tableW * 100).toFixed(1) + '%';
                s.thNext.style.width = (newNextW / s.tableW * 100).toFixed(1) + '%';
            }
            function bcrEnd() {
                if (!bcrState) return;
                bcrState.handle.classList.remove('active');
                // Guardar larguras
                var ths = bcrState.table.querySelectorAll('thead th');
                var tw = bcrState.table.offsetWidth;
                bancoColWidths = [];
                for (var i = 0; i < ths.length; i++) bancoColWidths.push(parseFloat((ths[i].offsetWidth / tw * 100).toFixed(1)));
                bcrState = null;
                document.removeEventListener('mousemove', bcrMove);
                document.removeEventListener('mouseup', bcrEnd);
                salvarBancoMerges();
            }

            // --- Merge selection via Ctrl/Cmd+click ---
            document.getElementById('bancoEnsaiosTable').addEventListener('mousedown', function(e) {
                if (!e.ctrlKey && !e.metaKey) return;
                var td = e.target.closest('td[data-col]');
                if (!td) return;
                var tr = td.closest('tr[data-ridx]');
                if (!tr) return;
                e.preventDefault();
                var col = parseInt(td.getAttribute('data-col'));
                var row = parseInt(tr.getAttribute('data-ridx'));
                if (col >= bancoColunas.length) return;
                if (bmSel.col === null || bmSel.col !== col) {
                    limparBancoSel();
                    bmSel = { col: col, start: row, end: row };
                } else {
                    bmSel.start = Math.min(bmSel.start, row);
                    bmSel.end = Math.max(bmSel.end, row);
                }
                highlightBancoSel();
                updateBancoFloat();
            });
            document.addEventListener('mousedown', function(e) {
                if (e.ctrlKey || e.metaKey) return;
                if (!e.target.closest('#bancoEnsaiosTable') && !e.target.closest('#bancoMergeFloat')) limparBancoSel();
            });

            function highlightBancoSel() {
                document.querySelectorAll('#bancoEnsaiosTable td.bm-selected').forEach(function(el) { el.classList.remove('bm-selected'); });
                if (bmSel.col === null) return;
                for (var r = bmSel.start; r <= bmSel.end; r++) {
                    var td = document.querySelector('#bancoEnsaiosTable tr[data-ridx="' + r + '"] td[data-col="' + bmSel.col + '"]');
                    if (td) td.classList.add('bm-selected');
                }
            }
            function updateBancoFloat() {
                var fl = document.getElementById('bancoMergeFloat');
                if (bmSel.col === null || bmSel.start === bmSel.end) { fl.style.display = 'none'; return; }
                var lastTd = document.querySelector('#bancoEnsaiosTable tr[data-ridx="' + bmSel.end + '"] td[data-col="' + bmSel.col + '"]');
                if (!lastTd) lastTd = document.querySelector('#bancoEnsaiosTable tr[data-ridx="' + bmSel.start + '"] td[data-col="' + bmSel.col + '"]');
                if (!lastTd) { fl.style.display = 'none'; return; }
                var rect = lastTd.getBoundingClientRect();
                fl.style.top = (rect.bottom + 4) + 'px';
                fl.style.left = rect.left + 'px';
                fl.style.display = 'block';
            }
            function limparBancoSel() {
                bmSel = { col: null, start: null, end: null };
                highlightBancoSel();
                document.getElementById('bancoMergeFloat').style.display = 'none';
            }

            // --- Merge/Unmerge ---
            function executarBancoMerge() {
                if (bmSel.col === null || bmSel.start === bmSel.end) return;
                var col = bmSel.col, s = bmSel.start, e = bmSel.end;
                // Remove overlapping merges and expand range
                var newMerges = [];
                bancoMerges.forEach(function(m) {
                    if (m.col === col) {
                        var mEnd = m.row + m.span - 1;
                        if (!(e < m.row || s > mEnd)) { s = Math.min(s, m.row); e = Math.max(e, mEnd); return; }
                    }
                    newMerges.push(m);
                });
                newMerges.push({ col: col, row: s, span: e - s + 1, hAlign: 'center', vAlign: 'middle' });
                bancoMerges = newMerges;
                limparBancoSel();
                salvarBancoMerges();
                renderBancoTable();
            }
            function desfazerBancoMerge(row, col) {
                bancoMerges = bancoMerges.filter(function(m) { return !(m.col === col && m.row === row); });
                salvarBancoMerges();
                renderBancoTable();
            }
            function toggleBancoAlign(row, col, axis) {
                var hCycle = ['left','center','right'], vCycle = ['top','middle','bottom'];
                var cycle = axis === 'h' ? hCycle : vCycle;
                var key = axis === 'h' ? 'hAlign' : 'vAlign';
                bancoMerges.forEach(function(m) {
                    if (m.col === col && m.row === row) {
                        var cur = m[key] || (axis === 'h' ? 'center' : 'middle');
                        m[key] = cycle[(cycle.indexOf(cur) + 1) % cycle.length];
                    }
                });
                salvarBancoMerges();
                renderBancoTable();
            }
            function salvarBancoMerges() {
                fetch('<?= BASE_PATH ?>/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ action: 'save_banco_merges', merges: { merges: bancoMerges, colWidths: bancoColWidths } })
                });
            }

            // --- Ajustar merges ao eliminar linha ---
            function ajustarMergesDelete(delIdx) {
                var newMerges = [];
                bancoMerges.forEach(function(m) {
                    var mEnd = m.row + m.span - 1;
                    if (delIdx < m.row) {
                        newMerges.push({ col: m.col, row: m.row - 1, span: m.span, hAlign: m.hAlign, vAlign: m.vAlign });
                    } else if (delIdx > mEnd) {
                        newMerges.push(m);
                    } else {
                        // delIdx está dentro do merge
                        var newSpan = m.span - 1;
                        if (newSpan > 1) {
                            var newRow = delIdx === m.row ? m.row : m.row;
                            newMerges.push({ col: m.col, row: delIdx <= m.row ? m.row : m.row, span: newSpan, hAlign: m.hAlign, vAlign: m.vAlign });
                        }
                    }
                });
                bancoMerges = newMerges;
                salvarBancoMerges();
            }

            // --- CRUD ---
            function renderCustomFields(ensaioId) {
                var container = document.getElementById('ens_custom_fields');
                var customCols = bancoColunas.filter(function(c) { return !c.campo_fixo; });
                if (customCols.length === 0) { container.innerHTML = ''; return; }
                var html = '<hr style="margin:12px 0;"><p class="muted" style="font-size:12px; margin-bottom:8px;">Campos personalizados:</p><div class="form-row">';
                var vals = ensaioId && bancoCustomValues[ensaioId] ? bancoCustomValues[ensaioId] : {};
                customCols.forEach(function(c) {
                    var v = vals[c.id] || '';
                    if (c.tipo === 'sim_nao') {
                        html += '<div class="form-group"><label><input type="checkbox" class="ens_custom" data-colid="' + c.id + '"' + (v == '1' ? ' checked' : '') + '> ' + escE(c.nome) + '</label></div>';
                    } else {
                        var inputType = c.tipo === 'numero' ? 'number' : 'text';
                        html += '<div class="form-group"><label>' + escE(c.nome) + '</label><input type="' + inputType + '" class="ens_custom" data-colid="' + c.id + '" value="' + escE(v) + '"></div>';
                    }
                });
                html += '</div>';
                container.innerHTML = html;
            }
            function resetEnsaioForm() {
                document.getElementById('ensaioModalTitle').textContent = 'Novo Ensaio';
                document.getElementById('ens_id').value = '0';
                document.getElementById('ens_categoria').value = '';
                document.getElementById('ens_ensaio').value = '';
                document.getElementById('ens_metodo').value = '';
                document.getElementById('ens_nivel_especial').value = '';
                document.getElementById('ens_nqa').value = '';
                document.getElementById('ens_exemplo').value = '';
                document.getElementById('ens_ativo').checked = true;
                renderCustomFields(null);
            }
            function editEnsaio(r) {
                document.getElementById('ensaioModalTitle').textContent = 'Editar Ensaio';
                document.getElementById('ens_id').value = r.id;
                document.getElementById('ens_categoria').value = r.categoria || '';
                document.getElementById('ens_ensaio').value = r.ensaio || '';
                document.getElementById('ens_metodo').value = r.metodo || '';
                document.getElementById('ens_nivel_especial').value = r.nivel_especial || '';
                document.getElementById('ens_nqa').value = r.nqa || '';
                document.getElementById('ens_exemplo').value = r.exemplo || '';
                document.getElementById('ens_ativo').checked = r.ativo != 0;
                renderCustomFields(r.id);
                document.getElementById('ensaioModal').style.display = 'flex';
            }
            function guardarEnsaio() {
                var fd = new FormData();
                fd.append('action', 'save_ensaio_banco');
                fd.append('id', document.getElementById('ens_id').value);
                fd.append('categoria', document.getElementById('ens_categoria').value);
                fd.append('ensaio', document.getElementById('ens_ensaio').value);
                fd.append('metodo', document.getElementById('ens_metodo').value);
                fd.append('nivel_especial', document.getElementById('ens_nivel_especial').value);
                fd.append('nqa', document.getElementById('ens_nqa').value);
                fd.append('exemplo', document.getElementById('ens_exemplo').value);
                fd.append('ativo', document.getElementById('ens_ativo').checked ? '1' : '0');
                fd.append('csrf_token', CSRF_TOKEN);
                fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                .then(function(r){return r.json();})
                .then(function(data) {
                    if (data.success) {
                        // Guardar valores custom
                        var ensaioId = data.data && data.data.id ? data.data.id : document.getElementById('ens_id').value;
                        var customEls = document.querySelectorAll('.ens_custom');
                        var promises = [];
                        customEls.forEach(function(el) {
                            var colId = el.getAttribute('data-colid');
                            var val = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                            promises.push(fetch('<?= BASE_PATH ?>/api.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                                body: JSON.stringify({ action: 'save_ensaio_valor_custom', ensaio_id: parseInt(ensaioId), coluna_id: parseInt(colId), valor: val })
                            }));
                        });
                        Promise.all(promises).then(function() {
                            document.getElementById('ensaioModal').style.display = 'none';
                            carregarEnsaios();
                        });
                    }
                    else appAlert(data.error || 'Erro ao guardar.');
                });
            }
            function eliminarEnsaio(id, rowIdx) {
                appConfirmDanger('Eliminar este ensaio?', function() {
                var fd = new FormData();
                fd.append('action', 'delete_ensaio_banco');
                fd.append('id', id);
                fd.append('csrf_token', CSRF_TOKEN);
                fetch('<?= BASE_PATH ?>/api.php', { method: 'POST', body: fd })
                .then(function(r){return r.json();})
                .then(function(data) {
                    if (data.success) { ajustarMergesDelete(rowIdx); carregarEnsaios(); }
                    else appAlert(data.error || 'Erro.');
                });
                });
            }
            carregarEnsaios();
            </script>

        <!-- ENSAIOS (org_admin / user - read-only) -->
        <?php elseif ($tab === 'ensaios' && !$isSuperAdminUser): ?>
            <div class="flex-between mb-md">
                <h2>Banco de Ensaios</h2>
                <?php if ($user['role'] === 'org_admin'): ?>
                <button class="btn btn-secondary btn-sm" onclick="toggleLegendaPanel()">Legenda da Tabela</button>
                <?php endif; ?>
            </div>

            <?php if ($user['role'] === 'org_admin'): ?>
            <div id="legendaPanel" style="display:none; margin-bottom:16px;">
                <div class="card" style="padding:16px;">
                    <h3 style="margin:0 0 8px; font-size:15px;">Legenda da Tabela de Ensaios</h3>
                    <p class="muted" style="font-size:12px; margin-bottom:8px;">Texto livre que aparece por baixo da tabela de ensaios (editor, consulta e PDF).</p>
                    <div class="form-group">
                        <textarea id="ensaiosLegendaText" class="form-control" rows="3" placeholder="Ex: NEI - Nível Especial de Inspeção conforme NP2922; NQA - Nível de Qualidade Aceitável conforme ISO 2859-1"></textarea>
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; gap:12px;">
                        <label style="margin:0; white-space:nowrap;">Tamanho (pt):</label>
                        <input type="number" id="ensaiosLegendaTamanho" class="form-control" value="9" min="6" max="14" style="width:80px;">
                        <button class="btn btn-primary btn-sm" onclick="guardarEnsaiosLegenda()">Guardar</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="card">
                <style>#bancoEnsaiosRO { table-layout:fixed; width:100%; } #bancoEnsaiosRO td { overflow:hidden; text-overflow:ellipsis; }</style>
                <table id="bancoEnsaiosRO">
                    <thead id="ensaioHeadRO"><tr><td class="muted" style="text-align:center; padding:12px;">A carregar...</td></tr></thead>
                    <tbody id="ensaioRowsRO"></tbody>
                </table>
            </div>
            <script>
            function escE(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
            var roColunasData = [];
            <?php if ($user['role'] === 'org_admin'): ?>
            function toggleLegendaPanel() {
                var p = document.getElementById('legendaPanel');
                p.style.display = p.style.display === 'none' ? 'block' : 'none';
            }
            function guardarEnsaiosLegenda() {
                fetch('<?= BASE_PATH ?>/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= getCsrfToken() ?>' },
                    body: JSON.stringify({
                        action: 'save_ensaios_legenda',
                        legenda: document.getElementById('ensaiosLegendaText').value,
                        tamanho: parseInt(document.getElementById('ensaiosLegendaTamanho').value) || 9
                    })
                }).then(function(r){return r.json();}).then(function(data) {
                    if (data.success) appAlert('Legenda guardada.');
                    else appAlert(data.error || 'Erro ao guardar.');
                });
            }
            // Carregar legenda existente
            fetch('<?= BASE_PATH ?>/api.php?action=get_ensaios_legenda').then(function(r){return r.json();}).then(function(data) {
                if (data.data) {
                    document.getElementById('ensaiosLegendaText').value = data.data.legenda || '';
                    document.getElementById('ensaiosLegendaTamanho').value = data.data.tamanho || 9;
                }
            });
            <?php endif; ?>
            Promise.all([
                fetch('<?= BASE_PATH ?>/api.php?action=get_ensaios_banco').then(function(r){return r.json();}),
                fetch('<?= BASE_PATH ?>/api.php?action=get_banco_merges').then(function(r){return r.json();}),
                fetch('<?= BASE_PATH ?>/api.php?action=get_ensaios_colunas').then(function(r){return r.json();}),
                fetch('<?= BASE_PATH ?>/api.php?action=get_ensaio_valores_custom').then(function(r){return r.json();})
            ]).then(function(res) {
                var rows = (res[0].data && res[0].data.ensaios) || [];
                var mData = (res[1].data && res[1].data.merges) || [];
                var merges, colWidths;
                if (Array.isArray(mData)) { merges = mData; colWidths = null; }
                else { merges = mData.merges || []; colWidths = mData.colWidths || null; }
                var colunas = ((res[2].data && res[2].data.colunas) || []).filter(function(c) { return c.ativo == 1; });
                var customVals = (res[3].data && res[3].data.valores) || {};
                roColunasData = colunas;

                // Thead dinâmico (usa nome_display que inclui legendas custom)
                var theadHtml = '<tr>';
                colunas.forEach(function(c) { var dn = c.nome_display || c.nome; theadHtml += '<th title="' + escE(dn) + '">' + escE(dn) + '</th>'; });
                theadHtml += '</tr>';
                document.getElementById('ensaioHeadRO').innerHTML = theadHtml;

                var tbody = document.getElementById('ensaioRowsRO');
                var totalCols = colunas.length;
                if (rows.length === 0) { tbody.innerHTML = '<tr><td colspan="' + totalCols + '" class="muted" style="text-align:center; padding:20px;">Nenhum ensaio registado.</td></tr>'; return; }
                var hidden = {}, spans = {}, aligns = {};
                merges.forEach(function(m) {
                    var k = m.row + '_' + m.col;
                    spans[k] = m.span;
                    aligns[k] = { h: m.hAlign || 'center', v: m.vAlign || 'middle' };
                    for (var r = m.row + 1; r < m.row + m.span; r++) hidden[r + '_' + m.col] = true;
                });
                var html = '', lastCat = '';
                rows.forEach(function(r, idx) {
                    html += '<tr>';
                    colunas.forEach(function(col, ci) {
                        var k = idx + '_' + ci;
                        if (hidden[k]) return;
                        var rs = spans[k] ? ' rowspan="' + spans[k] + '"' : '';
                        var ms = aligns[k] ? ' style="vertical-align:' + aligns[k].v + ';text-align:' + aligns[k].h + ';"' : '';
                        var val;
                        if (col.campo_fixo) {
                            if (col.campo_fixo === 'categoria') {
                                val = r.categoria !== lastCat ? '<strong>' + escE(r.categoria) + '</strong>' : '<span class="muted" style="font-size:12px;">〃</span>';
                                lastCat = r.categoria;
                            } else {
                                val = '<span class="muted" style="font-size:12px;">' + escE(r[col.campo_fixo] || '') + '</span>';
                            }
                        } else {
                            var cv = (customVals[r.id] && customVals[r.id][col.id]) || '';
                            if (col.tipo === 'sim_nao') {
                                val = cv == '1' ? '<span class="pill pill-success" style="font-size:11px;">Sim</span>' : '<span class="muted" style="font-size:12px;">Não</span>';
                            } else {
                                val = '<span class="muted" style="font-size:12px;">' + escE(cv) + '</span>';
                            }
                        }
                        html += '<td' + rs + ms + '>' + val + '</td>';
                    });
                    html += '</tr>';
                });
                tbody.innerHTML = html;
                // Larguras automáticas
                var tbl = document.getElementById('bancoEnsaiosRO');
                var ths = tbl.querySelectorAll('thead th');
                var pct = Math.floor(100 / (colunas.length || 1));
                var cw = colWidths ? colWidths.slice(0, colunas.length) : null;
                for (var i = 0; i < ths.length; i++) ths[i].style.width = (cw && cw[i] ? cw[i] : pct) + '%';
            });
            </script>

        <!-- CONFIGURAÇÕES -->
        <?php elseif ($tab === 'configuracoes'): ?>
            <h2 class="mb-md">Configurações</h2>

            <?php if ($isSuperAdminUser): ?>
            <div class="card">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
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
                    <h3 style="color: #2596be; font-size: 15px; margin-bottom: 12px;">Email do Super Admin (SMTP)</h3>
                    <p style="font-size: 12px; color: #667085; margin: -8px 0 12px;">Usado apenas pelo super admin. As organizações configuram o seu próprio email.</p>
                    <div class="form-row">
                        <div class="form-group"><label>Servidor SMTP</label><input type="text" name="cfg_smtp_host" value="<?= sanitize(getConfiguracao('smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
                        <div class="form-group"><label>Porta SMTP</label><input type="text" name="cfg_smtp_port" value="<?= sanitize(getConfiguracao('smtp_port', '587')) ?>" placeholder="587"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Utilizador SMTP</label><input type="text" name="cfg_smtp_user" value="<?= sanitize(getConfiguracao('smtp_user')) ?>" placeholder="user@gmail.com"></div>
                        <div class="form-group"><label>Password SMTP</label><input type="password" name="cfg_smtp_pass" value="" placeholder="<?= getConfiguracao('smtp_pass') ? '••••••• (definida)' : 'app password' ?>"><small class="muted">Deixe em branco para manter a atual</small></div>
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
                        <input type="password" name="cfg_openai_api_key" value="" placeholder="<?= getConfiguracao('openai_api_key') ? '••••••• (definida)' : 'sk-...' ?>"><small class="muted">Deixe em branco para manter a atual</small>
                        <small class="muted">Usada para assistente IA, verificação de legislação e chat. Obter em platform.openai.com</small>
                    </div>

                    <div class="mt-lg">
                        <button type="submit" class="btn btn-primary">Guardar Configurações</button>
                    </div>
                </form>
            </div>

            <?php else: ?>
            <!-- Org Admin: branding + email -->
            <?php
                $orgData = $db->prepare('SELECT * FROM organizacoes WHERE id = ?');
                $orgData->execute([$orgId]);
                $orgInfo = $orgData->fetch();
            ?>
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="color: var(--color-primary); font-size: 15px; margin-bottom: 12px;">A Minha Organização</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="save_org_branding">

                    <div class="form-group">
                        <label>Logo da Organização</label>
                        <?php if (!empty($orgInfo['logo'])): ?>
                            <div style="margin-bottom: 8px;">
                                <img src="<?= BASE_PATH ?>/uploads/logos/<?= sanitize($orgInfo['logo']) ?>" alt="Logo" style="max-height: 60px; border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px; background: white;">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/svg+xml" style="font-size: 13px;">
                        <small class="muted">Formatos: PNG, JPG, GIF, SVG</small>
                    </div>

                    <h4 style="color: var(--color-primary); font-size: 14px; margin: 16px 0 12px;">Cores</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Cor Primária</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="color" name="cor_primaria" value="<?= sanitize($orgInfo['cor_primaria'] ?? '#2596be') ?>" style="width: 50px; height: 36px; padding: 2px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Cor Primária Dark</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="color" name="cor_primaria_dark" value="<?= sanitize($orgInfo['cor_primaria_dark'] ?? '#1a7a9e') ?>" style="width: 50px; height: 36px; padding: 2px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Cor Primária Light</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="color" name="cor_primaria_light" value="<?= sanitize($orgInfo['cor_primaria_light'] ?? '#e6f4f9') ?>" style="width: 50px; height: 36px; padding: 2px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer;">
                            </div>
                        </div>
                    </div>

                    <hr style="margin: 18px 0; border: none; border-top: 1px solid #e5e7eb;">
                    <h4 style="color: var(--color-primary); font-size: 14px; margin-bottom: 12px;">Dados da Organização</h4>
                    <div class="form-row">
                        <div class="form-group"><label>NIF</label><input type="text" name="nif" value="<?= sanitize($orgInfo['nif'] ?? '') ?>"></div>
                        <div class="form-group"><label>Telefone</label><input type="text" name="telefone" value="<?= sanitize($orgInfo['telefone'] ?? '') ?>"></div>
                    </div>
                    <div class="form-group"><label>Morada</label><input type="text" name="morada" value="<?= sanitize($orgInfo['morada'] ?? '') ?>"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= sanitize($orgInfo['email'] ?? '') ?>"></div>
                        <div class="form-group"><label>Website</label><input type="url" name="website" value="<?= sanitize($orgInfo['website'] ?? '') ?>" placeholder="https://"></div>
                    </div>

                    <div class="mt-lg">
                        <button type="submit" class="btn btn-primary">Guardar Branding</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3 style="color: var(--color-primary); font-size: 15px; margin-bottom: 12px;">Email SpecLab</h3>
                <p style="font-size: 13px; color: #667085; margin-bottom: 16px;">Configure o email @speclab.pt da sua organização para envio de emails. Apenas precisa do email e password.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="save_org_email">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email SpecLab</label>
                            <input type="email" name="email_speclab" value="<?= sanitize($orgInfo['email_speclab'] ?? '') ?>" placeholder="org@speclab.pt">
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" name="email_speclab_pass" placeholder="<?= !empty($orgInfo['email_speclab']) ? '••••••• (manter atual)' : 'Introduzir password' ?>">
                            <small style="color:#667085;">Deixe em branco para manter a password atual</small>
                        </div>
                    </div>
                    <div class="mt-lg">
                        <button type="submit" class="btn btn-primary">Guardar Email</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
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
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
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
        document.getElementById('org_email_speclab').value = '';
        document.getElementById('org_email_speclab_pass').value = '';
        document.getElementById('org_email_permitido_users').checked = false;
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
        document.getElementById('org_email_speclab').value = o.email_speclab || '';
        document.getElementById('org_email_speclab_pass').value = '';
        document.getElementById('org_email_permitido_users').checked = (o.email_permitido_users == 1);
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
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('<?= BASE_PATH ?>/api.php?action=upload_org_logo', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                location.reload();
            } else {
                appAlert(result.error || 'Erro ao carregar logo.');
            }
        })
        .catch(function() {
            appAlert('Erro ao carregar logo.');
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
        document.getElementById('user_password_label').innerHTML = 'Palavra-passe <span class="muted">(deixe vazio para gerar automaticamente)</span>';
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
        document.getElementById('user_password_label').innerHTML = 'Palavra-passe <span class="muted">(deixe vazio para manter)</span>';
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
        ['nome','sigla','morada','telefone','email','nif','contacto','certificacoes','notas'].forEach(function(f) {
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
        ['nome','sigla','morada','telefone','email','nif','contacto','certificacoes','notas'].forEach(function(field) {
            var el = document.getElementById('fn_' + field);
            if (el) el.value = f[field] || '';
        });
        if (isSuperAdmin) {
            var orgField = document.getElementById('fn_organizacao_id');
            if (orgField) orgField.value = f.organizacao_id || '';
        }
    }

    function verHistoricoFornecedor(fornecedorId, nome) {
        document.getElementById('histFornNome').textContent = nome;
        document.getElementById('histFornBody').innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;">A carregar...</td></tr>';
        document.getElementById('historicoFornModal').style.display = 'flex';
        fetch('<?= BASE_PATH ?>/api.php?action=get_fornecedor_log&fornecedor_id=' + fornecedorId, {
            headers: {'X-CSRF-TOKEN': '<?= getCsrfToken() ?>'}
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data.length) {
                document.getElementById('histFornBody').innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;">Sem histórico registado.</td></tr>';
                return;
            }
            var html = '';
            data.data.forEach(function(log) {
                var acaoLabel = {criado:'Criado', atualizado:'Atualizado', desativado:'Desativado', reativado:'Reativado'}[log.acao] || log.acao;
                var acaoClass = {criado:'pill-success', atualizado:'pill-primary', desativado:'pill-error', reativado:'pill-warning'}[log.acao] || 'pill-muted';
                html += '<tr>';
                html += '<td>' + (log.created_at || '') + '</td>';
                html += '<td><span class="pill ' + acaoClass + '" style="font-size:11px;">' + acaoLabel + '</span></td>';
                html += '<td>' + (log.campos_alterados || '—') + '</td>';
                html += '<td>' + (log.user_nome || 'Sistema') + '</td>';
                html += '</tr>';
            });
            document.getElementById('histFornBody').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('histFornBody').innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;">Erro ao carregar histórico.</td></tr>';
        });
    }

    // ===================================
    // PRODUTOS
    // ===================================

    function resetProdutoForm() {
        document.getElementById('produtoModalTitle').textContent = 'Novo Produto';
        document.getElementById('produto_id').value = '0';
        document.getElementById('pr_nome').value = '';
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

        if (!data.ensaio) { appAlert('Introduza o nome do ensaio.'); return; }

        fetch('<?= BASE_PATH ?>/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
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
                appAlert(result.error || 'Erro ao guardar template.');
            }
        });
    }

    function removerTemplate(id) {
        appConfirmDanger('Remover este template?', function() {
            var produtoId = document.getElementById('tmpl_produto_id').value;
            fetch('<?= BASE_PATH ?>/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ action: 'delete_template', id: id })
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    carregarTemplates(produtoId);
                }
            });
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
    // GLOBAL: Close modal on overlay click or Escape key
    // ===================================
    document.querySelectorAll('.modal-overlay').forEach(function(m) {
        m.addEventListener('click', function(e) { if (e.target === m) m.style.display = 'none'; });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay').forEach(function(m) {
                if (m.style.display !== 'none') m.style.display = 'none';
            });
        }
    });
    </script>
    <?php include __DIR__ . '/includes/modals.php'; ?>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
