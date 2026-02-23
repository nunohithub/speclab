<?php
/**
 * Handler: Versoes (versionamento)
 * Actions: comparar_versoes, publicar_versao, nova_versao
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    // ===================================================================
    // COMPARAR VERSOES
    // ===================================================================
    case 'comparar_versoes':
        $id1 = (int)($jsonBody['id1'] ?? $_GET['id1'] ?? 0);
        $id2 = (int)($jsonBody['id2'] ?? $_GET['id2'] ?? 0);
        if ($id1 <= 0 || $id2 <= 0) jsonError('IDs invalidos.');
        checkSaOrgAccess($db, $user, $id1);
        checkSaOrgAccess($db, $user, $id2);

        // Verificar que pertencem ao mesmo grupo
        $stmt = $db->prepare('SELECT id, grupo_versao, versao, titulo, estado, objetivo, ambito, definicao_material, regulamentacao, processos, embalagem, aceitacao, observacoes FROM especificacoes WHERE id IN (?, ?)');
        $stmt->execute([$id1, $id2]);
        $specs = $stmt->fetchAll(PDO::FETCH_UNIQUE);
        if (count($specs) !== 2) jsonError('Especificacoes nao encontradas.');
        if ($specs[$id1]['grupo_versao'] !== $specs[$id2]['grupo_versao']) jsonError('As versoes nao pertencem ao mesmo grupo.');

        $camposComparar = ['titulo', 'objetivo', 'ambito', 'definicao_material', 'regulamentacao', 'processos', 'embalagem', 'aceitacao', 'observacoes'];
        $camposLabels = ['titulo' => 'Titulo', 'objetivo' => 'Objetivo', 'ambito' => 'Ambito', 'definicao_material' => 'Definicao do Material', 'regulamentacao' => 'Regulamentacao', 'processos' => 'Processos', 'embalagem' => 'Embalagem', 'aceitacao' => 'Aceitacao', 'observacoes' => 'Observacoes'];

        $diferencas = [];
        foreach ($camposComparar as $campo) {
            $v1 = trim($specs[$id1][$campo] ?? '');
            $v2 = trim($specs[$id2][$campo] ?? '');
            if ($v1 !== $v2) {
                $diferencas[] = ['campo' => $camposLabels[$campo], 'v1' => $v1, 'v2' => $v2];
            }
        }

        // Comparar seccoes
        $sec1 = $db->prepare('SELECT titulo, conteudo FROM especificacao_seccoes WHERE especificacao_id = ? ORDER BY ordem');
        $sec1->execute([$id1]); $seccoes1 = $sec1->fetchAll(PDO::FETCH_ASSOC);
        $sec2 = $db->prepare('SELECT titulo, conteudo FROM especificacao_seccoes WHERE especificacao_id = ? ORDER BY ordem');
        $sec2->execute([$id2]); $seccoes2 = $sec2->fetchAll(PDO::FETCH_ASSOC);

        $maxSec = max(count($seccoes1), count($seccoes2));
        for ($i = 0; $i < $maxSec; $i++) {
            $s1 = $seccoes1[$i] ?? null;
            $s2 = $seccoes2[$i] ?? null;
            if ($s1 && !$s2) {
                $diferencas[] = ['campo' => 'Seccao: ' . ($s1['titulo'] ?: 'Sem titulo'), 'v1' => strip_tags($s1['conteudo']), 'v2' => '(removida)'];
            } elseif (!$s1 && $s2) {
                $diferencas[] = ['campo' => 'Seccao: ' . ($s2['titulo'] ?: 'Sem titulo'), 'v1' => '(nova)', 'v2' => strip_tags($s2['conteudo'])];
            } elseif ($s1['titulo'] !== $s2['titulo'] || $s1['conteudo'] !== $s2['conteudo']) {
                $diferencas[] = ['campo' => 'Seccao: ' . ($s1['titulo'] ?: $s2['titulo'] ?: 'Sem titulo'), 'v1' => strip_tags($s1['conteudo']), 'v2' => strip_tags($s2['conteudo'])];
            }
        }

        echo json_encode([
            'success' => true,
            'v1' => ['id' => $id1, 'versao' => $specs[$id1]['versao']],
            'v2' => ['id' => $id2, 'versao' => $specs[$id2]['versao']],
            'diferencas' => $diferencas,
            'total' => count($diferencas)
        ]);
        exit;

    // ===================================================================
    // PUBLICAR VERSAO
    // ===================================================================
    case 'publicar_versao':
        $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) jsonError('ID invalido.');
        checkSaOrgAccess($db, $user, $id);
        $notas = sanitize($jsonBody['notas'] ?? $_POST['notas'] ?? '');
        $errosVal = validateForPublish($db, $id);
        if (!empty($errosVal)) {
            echo json_encode(['success' => false, 'error' => 'Documento incompleto:', 'validation_errors' => $errosVal]);
            exit;
        }
        if (!publicarVersao($db, $id, $user['id'], $notas ?: null)) {
            jsonError('Nao foi possivel publicar. Versao ja bloqueada ou nao encontrada.');
        }
        // Notificar fornecedores
        require_once __DIR__ . '/../includes/email.php';
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . BASE_PATH;
        $notifResult = enviarNotificacaoPublicacao($db, $id, $baseUrl, $user['id']);
        jsonSuccess('Versao publicada.' . ($notifResult['message'] ? ' ' . $notifResult['message'] : ''));
        break;

    // ===================================================================
    // NOVA VERSAO
    // ===================================================================
    case 'nova_versao':
        $id = (int)($jsonBody['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) jsonError('ID invalido.');
        checkSaOrgAccess($db, $user, $id);
        $novoId = criarNovaVersao($db, $id, $user['id']);
        if (!$novoId) jsonError('Erro ao criar nova versao.');
        echo json_encode(['success' => true, 'novo_id' => $novoId]);
        exit;
}
