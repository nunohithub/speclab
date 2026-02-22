<?php
/**
 * Handler: Ensaios
 * Actions: get_ensaios_banco, save_ensaio_banco, delete_ensaio_banco,
 *          get_banco_merges, save_banco_merges, get_ensaios_colunas,
 *          save_ensaio_coluna, delete_ensaio_coluna, save_ensaio_valor_custom,
 *          get_ensaio_valores_custom, save_colunas_legendas,
 *          save_ensaios_legenda, get_ensaios_legenda, save_ensaios_legenda_global
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // GET ENSAIOS BANCO
    // ===================================================================
    case 'get_ensaios_banco':
        if (isset($_GET['all']) && isSuperAdmin()) {
            $stmt = $db->prepare('SELECT * FROM ensaios_banco WHERE organizacao_id = ? ORDER BY ordem, categoria, ensaio');
            $stmt->execute([$user['org_id']]);
        } elseif (isSuperAdmin()) {
            $stmt = $db->prepare('SELECT id, categoria, ensaio, metodo, nivel_especial, nqa, exemplo FROM ensaios_banco WHERE ativo = 1 AND organizacao_id = ? ORDER BY ordem, categoria, ensaio');
            $stmt->execute([$user['org_id']]);
        } else {
            $stmt = $db->prepare('SELECT id, categoria, ensaio, metodo, nivel_especial, nqa, exemplo FROM ensaios_banco WHERE ativo = 1 AND organizacao_id = ? ORDER BY ordem, categoria, ensaio');
            $stmt->execute([$user['org_id']]);
        }
        jsonSuccess('OK', ['ensaios' => $stmt->fetchAll()]);
        break;

    // ===================================================================
    // SAVE ENSAIO BANCO
    // ===================================================================
    case 'save_ensaio_banco':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $eid = (int)($_POST['id'] ?? 0);
        $cat = trim($_POST['categoria'] ?? '');
        $ens = trim($_POST['ensaio'] ?? '');
        $met = trim($_POST['metodo'] ?? '');
        $niv = trim($_POST['nivel_especial'] ?? '');
        $nqa = trim($_POST['nqa'] ?? '');
        $ex = trim($_POST['exemplo'] ?? '');
        $ativoE = (int)($_POST['ativo'] ?? 1);
        if (!$cat || !$ens) jsonError('Categoria e ensaio sao obrigatorios.');
        if ($eid > 0) {
            $stmt = $db->prepare('UPDATE ensaios_banco SET categoria = ?, ensaio = ?, metodo = ?, nivel_especial = ?, nqa = ?, exemplo = ?, ativo = ? WHERE id = ? AND organizacao_id = ?');
            $stmt->execute([$cat, $ens, $met, $niv, $nqa, $ex, $ativoE, $eid, $user['org_id']]);
        } else {
            $stmtMax = $db->prepare('SELECT COALESCE(MAX(ordem),0)+1 FROM ensaios_banco WHERE organizacao_id = ?');
            $stmtMax->execute([$user['org_id']]);
            $maxOrdem = $stmtMax->fetchColumn();
            $stmt = $db->prepare('INSERT INTO ensaios_banco (categoria, ensaio, metodo, nivel_especial, nqa, exemplo, ativo, ordem, organizacao_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$cat, $ens, $met, $niv, $nqa, $ex, $ativoE, $maxOrdem, $user['org_id']]);
            $eid = (int)$db->lastInsertId();
        }
        jsonSuccess('Ensaio guardado.', ['id' => $eid]);
        break;

    // ===================================================================
    // DELETE ENSAIO BANCO
    // ===================================================================
    case 'delete_ensaio_banco':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $eid = (int)($_POST['id'] ?? 0);
        if ($eid > 0) {
            $db->prepare('DELETE FROM ensaios_banco WHERE id = ? AND organizacao_id = ?')->execute([$eid, $user['org_id']]);
        }
        jsonSuccess('Ensaio eliminado.');
        break;

    // ===================================================================
    // GET BANCO MERGES
    // ===================================================================
    case 'get_banco_merges':
        $stmt = $db->prepare("SELECT valor FROM configuracoes WHERE chave = 'banco_ensaios_merges'");
        $stmt->execute();
        $val = json_decode($stmt->fetchColumn() ?: '[]', true);
        // Compat: pode ser array legado (so merges) ou objeto {merges, colWidths}
        if (isset($val['merges'])) {
            jsonSuccess('OK', ['merges' => $val]);
        } else {
            jsonSuccess('OK', ['merges' => is_array($val) ? $val : []]);
        }
        break;

    // ===================================================================
    // SAVE BANCO MERGES
    // ===================================================================
    case 'save_banco_merges':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $merges = json_encode($jsonBody['merges'] ?? []);
        $stmt = $db->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'banco_ensaios_merges'");
        $stmt->execute([$merges]);
        jsonSuccess('Merges guardados.');
        break;

    // ===================================================================
    // GET ENSAIOS COLUNAS
    // ===================================================================
    case 'get_ensaios_colunas':
        $orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : ($user['org_id'] ?? 0);
        $stmt = $db->query('SELECT c.*, GROUP_CONCAT(DISTINCT co.org_id) as org_ids FROM ensaios_colunas c LEFT JOIN ensaios_colunas_org co ON co.coluna_id = c.id GROUP BY c.id ORDER BY c.ordem');
        $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Carregar nomes custom da org
        $nomesCustom = [];
        if ($orgId) {
            $stmtNc = $db->prepare('SELECT coluna_id, nome_custom FROM ensaios_colunas_org WHERE org_id = ? AND nome_custom IS NOT NULL AND nome_custom != ""');
            $stmtNc->execute([$orgId]);
            foreach ($stmtNc->fetchAll(PDO::FETCH_ASSOC) as $nc) {
                $nomesCustom[$nc['coluna_id']] = $nc['nome_custom'];
            }
        }
        // Se nao e super admin, filtrar so as visiveis para a org
        if (!isSuperAdmin() && $orgId) {
            $colunas = array_values(array_filter($colunas, function($c) use ($orgId) {
                if ($c['todas_orgs']) return $c['ativo'];
                $ids = $c['org_ids'] ? explode(',', $c['org_ids']) : [];
                return $c['ativo'] && in_array($orgId, $ids);
            }));
        }
        // Aplicar nomes custom
        foreach ($colunas as &$c) {
            $c['nome_custom'] = $nomesCustom[$c['id']] ?? '';
            $c['nome_display'] = $c['nome_custom'] ?: $c['nome'];
        }
        unset($c);
        jsonSuccess('OK', ['colunas' => $colunas]);
        break;

    // ===================================================================
    // SAVE ENSAIO COLUNA
    // ===================================================================
    case 'save_ensaio_coluna':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $cid = (int)($jsonBody['id'] ?? 0);
        $nome = trim($jsonBody['nome'] ?? '');
        $tipo = $jsonBody['tipo'] ?? 'texto';
        $ordem = (int)($jsonBody['ordem'] ?? 0);
        $todasOrgs = (int)($jsonBody['todas_orgs'] ?? 1);
        $ativo = (int)($jsonBody['ativo'] ?? 1);
        $orgIds = $jsonBody['org_ids'] ?? [];
        if (!$nome) jsonError('Nome da coluna e obrigatorio.');
        if ($cid > 0) {
            // Nao permitir alterar campo_fixo
            $stmt = $db->prepare('UPDATE ensaios_colunas SET nome = ?, tipo = ?, ordem = ?, todas_orgs = ?, ativo = ? WHERE id = ?');
            $stmt->execute([$nome, $tipo, $ordem, $todasOrgs, $ativo, $cid]);
        } else {
            $stmt = $db->prepare('INSERT INTO ensaios_colunas (nome, campo_fixo, tipo, ordem, todas_orgs, ativo) VALUES (?, NULL, ?, ?, ?, ?)');
            $stmt->execute([$nome, $tipo, $ordem, $todasOrgs, $ativo]);
            $cid = (int)$db->lastInsertId();
        }
        // Gerir orgs associadas
        $db->prepare('DELETE FROM ensaios_colunas_org WHERE coluna_id = ?')->execute([$cid]);
        if (!$todasOrgs && !empty($orgIds)) {
            $ins = $db->prepare('INSERT INTO ensaios_colunas_org (coluna_id, org_id) VALUES (?, ?)');
            foreach ($orgIds as $oid) $ins->execute([$cid, (int)$oid]);
        }
        jsonSuccess('Coluna guardada.', ['id' => $cid]);
        break;

    // ===================================================================
    // DELETE ENSAIO COLUNA
    // ===================================================================
    case 'delete_ensaio_coluna':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $cid = (int)($jsonBody['id'] ?? 0);
        if ($cid <= 0) jsonError('ID invalido.');
        // Nao permitir eliminar colunas fixas
        $fixo = $db->prepare('SELECT campo_fixo FROM ensaios_colunas WHERE id = ?');
        $fixo->execute([$cid]);
        if ($fixo->fetchColumn()) jsonError('Nao e possivel eliminar colunas fixas. Pode desativa-las.');
        $db->prepare('DELETE FROM ensaios_colunas WHERE id = ?')->execute([$cid]);
        jsonSuccess('Coluna eliminada.');
        break;

    // ===================================================================
    // SAVE ENSAIO VALOR CUSTOM
    // ===================================================================
    case 'save_ensaio_valor_custom':
        if (!isSuperAdmin()) jsonError('Acesso negado.', 403);
        $ensaioId = (int)($jsonBody['ensaio_id'] ?? 0);
        $colunaId = (int)($jsonBody['coluna_id'] ?? 0);
        $valor = trim($jsonBody['valor'] ?? '');
        if (!$ensaioId || !$colunaId) jsonError('Dados invalidos.');
        $stmt = $db->prepare('INSERT INTO ensaios_valores_custom (ensaio_id, coluna_id, valor) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
        $stmt->execute([$ensaioId, $colunaId, $valor]);
        jsonSuccess('Valor guardado.');
        break;

    // ===================================================================
    // GET ENSAIO VALORES CUSTOM
    // ===================================================================
    case 'get_ensaio_valores_custom':
        $stmt = $db->query('SELECT ensaio_id, coluna_id, valor FROM ensaios_valores_custom');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[$r['ensaio_id']][$r['coluna_id']] = $r['valor'];
        jsonSuccess('OK', ['valores' => $map]);
        break;

    // ===================================================================
    // SAVE COLUNAS LEGENDAS
    // ===================================================================
    case 'save_colunas_legendas':
        if ($user['role'] !== 'super_admin' && $user['role'] !== 'org_admin') jsonError('Acesso negado.', 403);
        $orgId = (int)($user['org_id'] ?? 0);
        if (!$orgId) jsonError('Organizacao nao definida.');
        $legendas = $jsonBody['legendas'] ?? [];
        foreach ($legendas as $leg) {
            $colId = (int)($leg['coluna_id'] ?? 0);
            $nomeCustom = trim($leg['nome_custom'] ?? '');
            if (!$colId) continue;
            // Upsert: se ja existe registo para esta org+coluna, atualizar; senao inserir
            $exists = $db->prepare('SELECT COUNT(*) FROM ensaios_colunas_org WHERE coluna_id = ? AND org_id = ?');
            $exists->execute([$colId, $orgId]);
            if ($exists->fetchColumn() > 0) {
                $db->prepare('UPDATE ensaios_colunas_org SET nome_custom = ? WHERE coluna_id = ? AND org_id = ?')->execute([$nomeCustom ?: null, $colId, $orgId]);
            } else {
                $db->prepare('INSERT INTO ensaios_colunas_org (coluna_id, org_id, nome_custom) VALUES (?, ?, ?)')->execute([$colId, $orgId, $nomeCustom ?: null]);
            }
        }
        jsonSuccess('Legendas guardadas.');
        break;

    // ===================================================================
    // SAVE ENSAIOS LEGENDA
    // ===================================================================
    case 'save_ensaios_legenda':
        if ($user['role'] !== 'super_admin' && $user['role'] !== 'org_admin') jsonError('Acesso negado.', 403);
        $orgId = (int)($jsonBody['org_id'] ?? $user['org_id'] ?? 0);
        if ($user['role'] === 'org_admin') $orgId = (int)$user['org_id'];
        if (!$orgId) jsonError('Organizacao nao definida.');
        $legenda = trim($jsonBody['legenda'] ?? '');
        $tamanho = (int)($jsonBody['tamanho'] ?? 9);
        if ($tamanho < 6) $tamanho = 6;
        if ($tamanho > 14) $tamanho = 14;
        $db->prepare('UPDATE organizacoes SET ensaios_legenda = ?, ensaios_legenda_tamanho = ? WHERE id = ?')->execute([$legenda ?: null, $tamanho, $orgId]);
        jsonSuccess('Legenda guardada.');
        break;

    // ===================================================================
    // GET ENSAIOS LEGENDA
    // ===================================================================
    case 'get_ensaios_legenda':
        if (isset($_GET['global']) && $_GET['global'] == '1') {
            $stmt = $db->prepare("SELECT valor FROM configuracoes WHERE chave = 'ensaios_legenda_global'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $gData = $row ? json_decode($row['valor'], true) : [];
            jsonSuccess('OK', ['legenda' => $gData['legenda'] ?? '', 'tamanho' => (int)($gData['tamanho'] ?? 9)]);
        }
        $orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : ($user['org_id'] ?? 0);
        if (!$orgId) jsonSuccess('OK', ['legenda' => '', 'tamanho' => 9]);
        $stmt = $db->prepare('SELECT ensaios_legenda, ensaios_legenda_tamanho FROM organizacoes WHERE id = ?');
        $stmt->execute([$orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        jsonSuccess('OK', ['legenda' => $row['ensaios_legenda'] ?? '', 'tamanho' => (int)($row['ensaios_legenda_tamanho'] ?? 9)]);
        break;

    // ===================================================================
    // SAVE ENSAIOS LEGENDA GLOBAL
    // ===================================================================
    case 'save_ensaios_legenda_global':
        if ($user['role'] !== 'super_admin') jsonError('Acesso negado.', 403);
        $legenda = trim($jsonBody['legenda'] ?? '');
        $tamanho = (int)($jsonBody['tamanho'] ?? 9);
        if ($tamanho < 6) $tamanho = 6;
        if ($tamanho > 14) $tamanho = 14;
        $valor = json_encode(['legenda' => $legenda, 'tamanho' => $tamanho]);
        $stmt = $db->prepare("SELECT id FROM configuracoes WHERE chave = 'ensaios_legenda_global'");
        $stmt->execute();
        if ($stmt->fetch()) {
            $db->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'ensaios_legenda_global'")->execute([$valor]);
        } else {
            $db->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ensaios_legenda_global', ?)")->execute([$valor]);
        }
        jsonSuccess('Legenda global guardada.');
        break;
}
