<?php
/**
 * Handler: Ficheiros
 * Actions: upload_ficheiro, delete_ficheiro, download_ficheiro, list_ficheiros, upload_logo_custom
 *
 * Variables available from parent api.php: $db, $user, $action, $jsonBody, $allowedExtensions
 */

switch ($action) {

    // ===================================================================
    // UPLOAD FICHEIRO
    // ===================================================================
    case 'upload_ficheiro':
        $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
        if ($especificacao_id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $especificacao_id, $user);

        if (!isset($_FILES['ficheiro']) || $_FILES['ficheiro']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'O ficheiro excede o tamanho maximo permitido pelo servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'O ficheiro excede o tamanho maximo permitido pelo formulario.',
                UPLOAD_ERR_PARTIAL    => 'O ficheiro foi apenas parcialmente enviado.',
                UPLOAD_ERR_NO_FILE    => 'Nenhum ficheiro foi enviado.',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretorio temporario em falta.',
                UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever o ficheiro no disco.',
                UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensao do servidor.',
            ];
            $errorCode = $_FILES['ficheiro']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMsg  = $uploadErrors[$errorCode] ?? 'Erro desconhecido no upload.';
            jsonError($errorMsg);
        }

        $file = $_FILES['ficheiro'];

        // Validar tamanho
        if ($file['size'] > MAX_UPLOAD_SIZE) {
            jsonError('O ficheiro excede o tamanho maximo de ' . formatFileSize(MAX_UPLOAD_SIZE) . '.');
        }

        // Validar extensao
        $originalName = $file['name'];
        $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            jsonError('Tipo de ficheiro nao permitido. Extensoes permitidas: ' . implode(', ', $allowedExtensions));
        }

        // Validar MIME type real do ficheiro
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMimeType = $finfo->file($file['tmp_name']);
        $allowedMimes = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'pdf'  => ['application/pdf'],
            'doc'  => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls'  => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'txt'  => ['text/plain'],
            'csv'  => ['text/csv', 'text/plain'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
        ];
        if (isset($allowedMimes[$extension]) && !in_array($realMimeType, $allowedMimes[$extension])) {
            jsonError('Tipo de ficheiro invalido (MIME type nao corresponde a extensao).');
        }

        // Criar diretorio de uploads se nao existir
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }

        // Gerar nome unico
        $uniqueName = uniqid('file_', true) . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destPath   = UPLOAD_DIR . $uniqueName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            jsonError('Erro ao guardar o ficheiro no servidor.');
        }

        // Optimize images (resize large photos, compress)
        $imageExts = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($extension, $imageExts) && function_exists('imagecreatefromjpeg')) {
            $maxWidth = 2000;
            $maxHeight = 2000;
            $quality = 85;

            $imageInfo = @getimagesize($destPath);
            if ($imageInfo) {
                $origWidth = $imageInfo[0];
                $origHeight = $imageInfo[1];
                $mimeType = $imageInfo['mime'];

                if ($origWidth > $maxWidth || $origHeight > $maxHeight) {
                    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
                    $newWidth = (int)($origWidth * $ratio);
                    $newHeight = (int)($origHeight * $ratio);

                    $srcImage = null;
                    switch ($mimeType) {
                        case 'image/jpeg': $srcImage = @imagecreatefromjpeg($destPath); break;
                        case 'image/png':  $srcImage = @imagecreatefrompng($destPath); break;
                        case 'image/gif':  $srcImage = @imagecreatefromgif($destPath); break;
                    }

                    if ($srcImage) {
                        $dstImage = imagecreatetruecolor($newWidth, $newHeight);

                        // Preserve transparency for PNG
                        if ($mimeType === 'image/png') {
                            imagealphablending($dstImage, false);
                            imagesavealpha($dstImage, true);
                        }

                        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

                        switch ($mimeType) {
                            case 'image/jpeg': imagejpeg($dstImage, $destPath, $quality); break;
                            case 'image/png':  imagepng($dstImage, $destPath, 6); break;
                            case 'image/gif':  imagegif($dstImage, $destPath); break;
                        }

                        imagedestroy($srcImage);
                        imagedestroy($dstImage);

                        // Update file size after optimization
                        $file['size'] = filesize($destPath);
                    }
                } elseif ($mimeType === 'image/jpeg' && $file['size'] > 500000) {
                    // Compress large JPEGs even if dimensions are OK
                    $srcImage = @imagecreatefromjpeg($destPath);
                    if ($srcImage) {
                        imagejpeg($srcImage, $destPath, $quality);
                        imagedestroy($srcImage);
                        $file['size'] = filesize($destPath);
                    }
                }
            }
        }

        // Inserir na base de dados
        $grupo = trim($_POST['grupo'] ?? 'default');
        if ($grupo === '') $grupo = 'default';
        $stmt = $db->prepare('
            INSERT INTO especificacao_ficheiros
                (especificacao_id, grupo, nome_original, nome_servidor, tamanho, tipo_ficheiro, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $especificacao_id,
            $grupo,
            $originalName,
            $uniqueName,
            $file['size'],
            $file['type'],
        ]);

        $ficheiroId = (int)$db->lastInsertId();

        // Atualizar timestamp da especificacao
        $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
        $stmt->execute([$especificacao_id]);

        jsonSuccess('Ficheiro enviado com sucesso.', [
            'id'             => $ficheiroId,
            'nome_original'  => $originalName,
            'nome_servidor'  => $uniqueName,
            'tamanho'        => $file['size'],
            'tamanho_fmt'    => formatFileSize($file['size']),
            'tipo_mime'      => $file['type'],
        ]);
        break;

    // ===================================================================
    // DELETE FICHEIRO
    // ===================================================================
    case 'delete_ficheiro':
        $ficheiro_id = (int)($_POST['ficheiro_id'] ?? $_POST['id'] ?? 0);
        if ($ficheiro_id <= 0) {
            jsonError('ID do ficheiro invalido.');
        }

        // Obter informacao do ficheiro
        $stmt = $db->prepare('SELECT * FROM especificacao_ficheiros WHERE id = ?');
        $stmt->execute([$ficheiro_id]);
        $ficheiro = $stmt->fetch();

        if (!$ficheiro) {
            jsonError('Ficheiro nao encontrado.', 404);
        }

        verifySpecAccess($db, (int)$ficheiro['especificacao_id'], $user);

        // Remover do disco
        $filePath = UPLOAD_DIR . $ficheiro['nome_servidor'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Remover da base de dados
        $stmt = $db->prepare('DELETE FROM especificacao_ficheiros WHERE id = ?');
        $stmt->execute([$ficheiro_id]);

        // Atualizar timestamp da especificacao
        $stmt = $db->prepare('UPDATE especificacoes SET updated_at = NOW() WHERE id = ?');
        $stmt->execute([$ficheiro['especificacao_id']]);

        jsonSuccess('Ficheiro eliminado com sucesso.');
        break;

    // ===================================================================
    // DOWNLOAD FICHEIRO (via GET)
    // ===================================================================
    case 'download_ficheiro':
        $fid = (int)($_GET['id'] ?? 0);
        if ($fid <= 0) jsonError('ID invalido.');

        $stmt = $db->prepare('SELECT * FROM especificacao_ficheiros WHERE id = ?');
        $stmt->execute([$fid]);
        $f = $stmt->fetch();
        if (!$f) jsonError('Ficheiro nao encontrado.', 404);

        // Verificar acesso multi-tenant
        verifySpecAccess($db, (int)$f['especificacao_id'], $user);

        $filepath = UPLOAD_DIR . $f['nome_servidor'];
        if (!file_exists($filepath)) jsonError('Ficheiro nao encontrado no servidor.', 404);

        $safeFilename = str_replace(["\r", "\n", '"'], '', $f['nome_original']);
        header('Content-Type: ' . ($f['tipo_ficheiro'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;

    // ===================================================================
    // UPLOAD LOGO PERSONALIZADO
    // ===================================================================
    case 'upload_logo_custom':
        $especificacao_id = (int)($_POST['especificacao_id'] ?? 0);
        if ($especificacao_id <= 0) {
            jsonError('ID da especificacao invalido.');
        }

        verifySpecAccess($db, $especificacao_id, $user);

        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            jsonError('Nenhum ficheiro enviado.');
        }

        $file = $_FILES['logo'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
            jsonError('Formato invalido. Use PNG ou JPG.');
        }

        // Validar MIME type real do ficheiro
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMimeType = $finfo->file($file['tmp_name']);
        $logoMimes = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];
        if (isset($logoMimes[$ext]) && !in_array($realMimeType, $logoMimes[$ext])) {
            jsonError('Tipo de ficheiro invalido (MIME type nao corresponde a extensao).');
        }

        $logosDir = UPLOAD_DIR . 'logos/';
        if (!is_dir($logosDir)) {
            mkdir($logosDir, 0755, true);
        }

        $filename = 'logo_' . $especificacao_id . '_' . time() . '.' . $ext;
        $destPath = $logosDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            jsonError('Erro ao guardar o ficheiro.');
        }

        // Guardar na config_visual da especificacao
        $stmt = $db->prepare('SELECT config_visual FROM especificacoes WHERE id = ?');
        $stmt->execute([$especificacao_id]);
        $currentConfig = $stmt->fetchColumn();
        $cv = $currentConfig ? json_decode($currentConfig, true) : [];
        if (!is_array($cv)) $cv = [];

        // Remover logo antigo se existir (basename para prevenir path traversal)
        if (!empty($cv['logo_custom']) && file_exists($logosDir . basename($cv['logo_custom']))) {
            unlink($logosDir . basename($cv['logo_custom']));
        }

        $cv['logo_custom'] = $filename;

        $stmt = $db->prepare('UPDATE especificacoes SET config_visual = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([json_encode($cv, JSON_UNESCAPED_UNICODE), $especificacao_id]);

        jsonSuccess('Logo carregado.', ['filename' => $filename]);
        break;
}
