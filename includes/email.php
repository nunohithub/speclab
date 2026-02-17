<?php
/**
 * SpecLab - Cadernos de Encargos
 * Sistema de envio de email
 */

/**
 * Envia email com especificação (link ou PDF anexo)
 */
function enviarEmail(PDO $db, int $especificacaoId, string $destinatario, string $assunto, string $corpo, bool $anexarPdf = false, ?int $enviadoPor = null): array {
    $smtpHost = getConfiguracao('smtp_host');
    $smtpPort = (int)getConfiguracao('smtp_port', '587');
    $smtpUser = getConfiguracao('smtp_user');
    $smtpPass = getConfiguracao('smtp_pass');
    $smtpFrom = getConfiguracao('smtp_from');
    $smtpFromName = getConfiguracao('smtp_from_name', 'SpecLab');

    if (empty($smtpHost) || empty($smtpUser)) {
        return ['success' => false, 'error' => 'Configuração SMTP não definida. Configure em Admin > Configurações.'];
    }

    // Verificar se PHPMailer está disponível
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        return ['success' => false, 'error' => 'PHPMailer não instalado. Execute install.php para instalar.'];
    }

    require_once $autoload;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = $smtpPort == 465 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($smtpFrom ?: $smtpUser, $smtpFromName);
        $mail->addAddress($destinatario);

        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body = $corpo;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corpo));

        // Anexar PDF se solicitado
        if ($anexarPdf) {
            $pdfPath = gerarPdfTemp($db, $especificacaoId);
            if ($pdfPath && file_exists($pdfPath)) {
                $espec = $db->prepare('SELECT numero FROM especificacoes WHERE id = ?');
                $espec->execute([$especificacaoId]);
                $num = $espec->fetchColumn() ?: 'especificacao';
                $filename = 'Caderno_Encargos_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $num) . '.pdf';
                $mail->addAttachment($pdfPath, $filename);
            }
        }

        $mail->send();

        // Registar log
        $stmt = $db->prepare('INSERT INTO email_log (especificacao_id, destinatario, assunto, tipo, estado, enviado_por) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$especificacaoId, $destinatario, $assunto, 'manual', 'enviado', $enviadoPor]);

        return ['success' => true, 'message' => 'Email enviado com sucesso.'];

    } catch (Exception $e) {
        // Registar erro
        $stmt = $db->prepare('INSERT INTO email_log (especificacao_id, destinatario, assunto, tipo, estado, erro_msg, enviado_por) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$especificacaoId, $destinatario, $assunto, 'manual', 'erro', $e->getMessage(), $enviadoPor]);

        return ['success' => false, 'error' => 'Erro ao enviar: ' . $e->getMessage()];
    }
}

/**
 * Gera corpo HTML do email para envio de especificação
 */
function gerarCorpoEmail(array $espec, string $linkPublico = ''): string {
    $empresaNome = getConfiguracao('empresa_nome', 'SpecLab');
    $assinatura = getConfiguracao('email_assinatura', '');

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; color: #111827; max-width: 600px; margin: 0 auto;">';

    $html .= '<div style="border-bottom: 3px solid #2596be; padding-bottom: 12px; margin-bottom: 20px;">';
    $html .= '<h2 style="color: #2596be; margin: 0;">Caderno de Encargos</h2>';
    $html .= '<p style="color: #667085; font-size: 13px; margin: 4px 0 0;">' . htmlspecialchars($empresaNome) . '</p>';
    $html .= '</div>';

    $html .= '<h3 style="margin: 0 0 8px;">' . htmlspecialchars($espec['titulo']) . '</h3>';
    $html .= '<table style="font-size: 13px; color: #374151; margin-bottom: 20px;">';
    $html .= '<tr><td style="padding: 3px 12px 3px 0; color: #667085;">Número:</td><td><strong>' . htmlspecialchars($espec['numero']) . '</strong></td></tr>';
    $html .= '<tr><td style="padding: 3px 12px 3px 0; color: #667085;">Versão:</td><td>' . htmlspecialchars($espec['versao']) . '</td></tr>';
    $html .= '<tr><td style="padding: 3px 12px 3px 0; color: #667085;">Produto:</td><td>' . htmlspecialchars($espec['produto_nome'] ?? '-') . '</td></tr>';
    $html .= '<tr><td style="padding: 3px 12px 3px 0; color: #667085;">Data:</td><td>' . formatDate($espec['data_emissao']) . '</td></tr>';
    $html .= '</table>';

    if ($linkPublico) {
        $html .= '<div style="margin: 20px 0; text-align: center;">';
        $html .= '<a href="' . htmlspecialchars($linkPublico) . '" style="display: inline-block; background: #2596be; color: white; padding: 12px 32px; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 14px;">Ver Documento Online</a>';
        $html .= '</div>';
        $html .= '<p style="font-size: 12px; color: #667085; text-align: center;">Ou copie este link: <a href="' . htmlspecialchars($linkPublico) . '">' . htmlspecialchars($linkPublico) . '</a></p>';
    }

    $html .= '<div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #999;">';
    $html .= htmlspecialchars($assinatura ?: $empresaNome);
    $html .= '</div>';

    $html .= '</body></html>';
    return $html;
}

/**
 * Gera PDF temporário para anexar ao email
 */
function gerarPdfTemp(PDO $db, int $especificacaoId): ?string {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return null;

    require_once $autoload;
    require_once __DIR__ . '/functions.php';

    $data = getEspecificacaoCompleta($db, $especificacaoId);
    if (!$data) return null;

    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 25,
            'margin_bottom' => 20,
            'default_font' => 'dejavusans',
            'default_font_size' => 10,
            'tempDir' => sys_get_temp_dir(),
        ]);

        $mpdf->SetTitle(htmlspecialchars($data['titulo']));

        // Simplified HTML for email attachment
        $html = '<h1 style="color:#2596be; font-size:16pt;">' . htmlspecialchars($data['titulo']) . '</h1>';
        $html .= '<p style="color:#667085; font-size:9pt;">' . htmlspecialchars($data['numero']) . ' | Versão ' . htmlspecialchars($data['versao']) . '</p>';
        $html .= '<hr style="border-color:#2596be;">';

        $sections = [
            'objetivo' => '1. Objetivo', 'definicao_material' => '3. Definição do Material',
            'regulamentacao' => '4. Regulamentação', 'observacoes' => '10. Observações',
        ];
        foreach ($sections as $key => $title) {
            if (!empty($data[$key])) {
                $html .= '<h2 style="color:#2596be; font-size:11pt; border-bottom:1px solid #e6f4f9; padding-bottom:2mm;">' . $title . '</h2>';
                $html .= '<p style="font-size:10pt; white-space:pre-wrap;">' . nl2br(htmlspecialchars($data[$key])) . '</p>';
            }
        }

        $mpdf->WriteHTML($html);

        $tempFile = tempnam(sys_get_temp_dir(), 'exi_pdf_');
        $mpdf->Output($tempFile, \Mpdf\Output\Destination::FILE);
        return $tempFile;

    } catch (Exception $e) {
        return null;
    }
}
