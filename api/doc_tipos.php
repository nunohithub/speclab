<?php
/**
 * Handler: Doc Tipos (configuração de tipos de documento)
 * Actions: save_doc_tipo, get_doc_tipo_seccoes
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody
 */

switch ($action) {

    case 'save_doc_tipo':
        if ($user['role'] !== 'super_admin') jsonError('Apenas super admin pode alterar tipos de documento.');
        $id = (int)($jsonBody['id'] ?? 0);
        $nome = trim($jsonBody['nome'] ?? '');
        $ativo = (int)($jsonBody['ativo'] ?? 1);
        $seccoes = $jsonBody['seccoes'] ?? [];

        if (!$nome) jsonError('Nome é obrigatório.');
        if (!is_array($seccoes)) $seccoes = [];

        // Validar que só contém secções válidas
        $validas = ['texto', 'parametros', 'legislacao', 'ficheiros', 'ensaios'];
        $seccoes = array_values(array_intersect($seccoes, $validas));
        $seccoesJson = json_encode($seccoes);

        if ($id > 0) {
            $db->prepare('UPDATE doc_tipos SET nome = ?, seccoes = ?, ativo = ? WHERE id = ?')
               ->execute([$nome, $seccoesJson, $ativo, $id]);
        } else {
            $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($nome));
            $slug = preg_replace('/_+/', '_', trim($slug, '_'));
            $db->prepare('INSERT INTO doc_tipos (slug, nome, seccoes, ativo) VALUES (?, ?, ?, ?)')
               ->execute([$slug, $nome, $seccoesJson, $ativo]);
        }
        jsonSuccess('Tipo de documento guardado.');
        break;

    case 'get_doc_tipo_seccoes':
        $slug = $jsonBody['slug'] ?? $_GET['slug'] ?? '';
        if (!$slug) jsonError('Slug é obrigatório.');
        $stmt = $db->prepare('SELECT seccoes FROM doc_tipos WHERE slug = ? AND ativo = 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!$row) {
            // Fallback: tudo permitido
            echo json_encode(['success' => true, 'seccoes' => ['texto','parametros','legislacao','ficheiros','ensaios']]);
            exit;
        }
        echo json_encode(['success' => true, 'seccoes' => json_decode($row['seccoes'], true)]);
        exit;
}
