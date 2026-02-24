<?php
/**
 * SpecLab - Cadernos de Encargos
 * Sistema de envio de email
 */

/**
 * Obter configuração SMTP para uma especificação
 * Prioridade: SMTP próprio da organização → SMTP global (speclab.pt)
 */
function getSmtpConfig(PDO $db, int $especificacaoId): array {
    // Tentar SMTP da organização
    $stmt = $db->prepare('
        SELECT o.smtp_host, o.smtp_port, o.smtp_user, o.smtp_pass, o.smtp_from, o.smtp_from_name,
               o.email_speclab, o.email_speclab_pass, o.usar_smtp_speclab, o.nome as org_nome
        FROM especificacoes e
        INNER JOIN organizacoes o ON o.id = e.organizacao_id
        WHERE e.id = ?
    ');
    $stmt->execute([$especificacaoId]);
    $org = $stmt->fetch();

    // 1) SMTP próprio da organização (não usa speclab)
    if ($org && !$org['usar_smtp_speclab'] && !empty($org['smtp_host']) && !empty($org['smtp_user'])) {
        return [
            'host' => $org['smtp_host'],
            'port' => $org['smtp_port'] ?: 587,
            'user' => $org['smtp_user'],
            'pass' => decryptValue($org['smtp_pass'] ?? ''),
            'from' => $org['smtp_from'] ?: $org['smtp_user'],
            'from_name' => $org['smtp_from_name'] ?: $org['org_nome'],
        ];
    }

    // 2) Email speclab.pt da organização (credenciais próprias)
    if ($org && !empty($org['email_speclab']) && !empty($org['email_speclab_pass'])) {
        return [
            'host' => 'mail.speclab.pt',
            'port' => 587,
            'user' => $org['email_speclab'],
            'pass' => decryptValue($org['email_speclab_pass'] ?? ''),
            'from' => $org['email_speclab'],
            'from_name' => $org['org_nome'],
        ];
    }

    // 3) Fallback: SMTP global (super admin)
    $globalHost = getConfiguracao('smtp_host');
    $globalUser = getConfiguracao('smtp_user');
    if (!empty($globalHost) && !empty($globalUser)) {
        return [
            'host' => $globalHost,
            'port' => getConfiguracao('smtp_port', '465'),
            'user' => $globalUser,
            'pass' => decryptValue(getConfiguracao('smtp_pass')),
            'from' => getConfiguracao('smtp_from') ?: $globalUser,
            'from_name' => getConfiguracao('smtp_from_name', 'SpecLab'),
        ];
    }

    // Sem nenhum email configurado
    return ['error' => 'Nenhum email configurado. Configure o SMTP da organização ou o SMTP global nas Configurações.'];
}

/**
 * Envia email com especificação (link ou PDF anexo)
 */
function enviarEmail(PDO $db, int $especificacaoId, string $destinatario, string $assunto, string $corpo, bool $anexarPdf = false, ?int $enviadoPor = null, ?string $bcc = null): array {
    // Determinar SMTP: organização própria ou global (speclab.pt)
    $smtp = getSmtpConfig($db, $especificacaoId);

    if (isset($smtp['error'])) {
        return ['success' => false, 'error' => $smtp['error']];
    }

    $smtpHost = $smtp['host'];
    $smtpPort = (int)$smtp['port'];
    $smtpUser = $smtp['user'];
    $smtpPass = $smtp['pass'];
    $smtpFrom = $smtp['from'];
    $smtpFromName = $smtp['from_name'];

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
        // SSL: verificação ativa por defeito; desativar via SMTP_VERIFY_SSL=false no .env
        $verifySSL = ($_ENV['SMTP_VERIFY_SSL'] ?? getenv('SMTP_VERIFY_SSL') ?: 'true') !== 'false';
        if (!$verifySSL) {
            $mail->SMTPOptions = [
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
            ];
        }

        $mail->setFrom($smtpFrom ?: $smtpUser, $smtpFromName);
        $mail->addAddress($destinatario);
        if ($bcc) {
            $mail->addBCC($bcc);
        }

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

        // Traduzir erros técnicos para mensagens amigáveis
        $msg = $e->getMessage();
        if (stripos($msg, 'Could not authenticate') !== false || stripos($msg, 'Authentication') !== false) {
            $userMsg = 'Erro de autenticação no email. Verifique o email e password nas Configurações.';
        } elseif (stripos($msg, 'connect') !== false || stripos($msg, 'Connection') !== false) {
            $userMsg = 'Não foi possível ligar ao servidor de email. Tente novamente mais tarde.';
        } elseif (stripos($msg, 'recipient') !== false || stripos($msg, 'address') !== false) {
            $userMsg = 'O endereço de email do destinatário parece inválido.';
        } elseif (stripos($msg, 'timeout') !== false) {
            $userMsg = 'O servidor de email não respondeu a tempo. Tente novamente.';
        } else {
            $userMsg = 'Não foi possível enviar o email. Verifique as configurações de email.';
        }
        return ['success' => false, 'error' => $userMsg];
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

/**
 * Enviar link de aceitação a um destinatário (token individual)
 */
function enviarLinkAceitacao(PDO $db, int $especId, int $tokenId, string $baseUrl, ?int $enviadoPor = null): array {
    // Buscar token e especificação
    $stmt = $db->prepare('SELECT t.*, e.titulo, e.numero, e.versao FROM especificacao_tokens t INNER JOIN especificacoes e ON e.id = t.especificacao_id WHERE t.id = ?');
    $stmt->execute([$tokenId]);
    $tk = $stmt->fetch();
    if (!$tk || empty($tk['destinatario_email'])) {
        return ['success' => false, 'error' => 'Destinatário sem email.'];
    }

    $link = $baseUrl . '/publico.php?token=' . $tk['token'];
    $espec = ['titulo' => $tk['titulo'], 'numero' => $tk['numero'], 'versao' => $tk['versao'], 'produto_nome' => '', 'data_emissao' => ''];

    // Corpo do email com botão de aceitação
    $corpo = gerarCorpoEmailAceitacao($espec, $link, $tk['destinatario_nome']);

    // BCC para o remetente quando usa email do servidor (@speclab.pt)
    $bcc = null;
    $smtp = getSmtpConfig($db, $especId);
    if (!isset($smtp['error']) && stripos($smtp['from'] ?? '', '@speclab.pt') !== false && $enviadoPor) {
        $stmtUser = $db->prepare('SELECT email FROM utilizadores WHERE id = ?');
        $stmtUser->execute([$enviadoPor]);
        $senderEmail = $stmtUser->fetchColumn();
        if ($senderEmail && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) $bcc = $senderEmail;
    }

    $assunto = gerarAssuntoEmailAceitacao($espec);

    // Suporte a múltiplos emails (separados por vírgula)
    $emails = array_filter(array_map('trim', explode(',', $tk['destinatario_email'])));
    $enviados = 0;
    $erros = [];
    foreach ($emails as $emailDest) {
        if (!filter_var($emailDest, FILTER_VALIDATE_EMAIL)) continue;
        $r = enviarEmail($db, $especId, $emailDest, $assunto, $corpo, false, $enviadoPor, $bcc);
        if ($r['success']) $enviados++;
        else $erros[] = $emailDest;
    }

    // Marcar token como enviado se pelo menos um foi enviado
    if ($enviados > 0) {
        $db->prepare('UPDATE especificacao_tokens SET enviado_em = NOW() WHERE id = ?')->execute([$tokenId]);
        return ['success' => true, 'message' => "Email enviado para $enviados destinatário(s)."];
    }

    return ['success' => false, 'error' => 'Nenhum email enviado.'];
}

/**
 * Gera corpo HTML para email de aceitação
 */
function gerarCorpoEmailAceitacao(array $espec, string $link, string $nomeDestinatario): string {
    // Ler texto configurável (com fallbacks)
    $corpoTexto = getConfiguracao('email_aceitacao_corpo', 'Foi-lhe enviado o seguinte documento para análise e aprovação. Clique no botão abaixo para consultar o documento e registar a sua decisão (aceitar ou rejeitar).');
    $botaoTexto = getConfiguracao('email_aceitacao_botao', 'Ver e Aprovar Documento');

    // Substituir placeholders
    $placeholders = ['{nome}' => $nomeDestinatario, '{numero}' => $espec['numero'], '{titulo}' => $espec['titulo'], '{versao}' => $espec['versao'], '{link}' => $link];
    $corpoTexto = str_replace(array_keys($placeholders), array_values($placeholders), $corpoTexto);
    $botaoTexto = str_replace(array_keys($placeholders), array_values($placeholders), $botaoTexto);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 20px;">';

    $html .= '<div style="border-bottom: 3px solid #2596be; padding-bottom: 12px; margin-bottom: 20px;">';
    $html .= '<h2 style="color: #2596be; margin: 0;">Documento para Aprovação</h2>';
    $html .= '</div>';

    $html .= '<p>Exmo(a) Sr(a) ' . htmlspecialchars($nomeDestinatario) . ',</p>';
    $html .= '<p>' . nl2br(htmlspecialchars($corpoTexto)) . '</p>';

    $html .= '<div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin: 16px 0;">';
    $html .= '<strong>' . htmlspecialchars($espec['titulo']) . '</strong><br>';
    $html .= '<span style="color: #667085;">Número: ' . htmlspecialchars($espec['numero']) . ' | Versão: ' . htmlspecialchars($espec['versao']) . '</span>';
    $html .= '</div>';

    $html .= '<div style="margin: 24px 0; text-align: center;">';
    $html .= '<a href="' . htmlspecialchars($link) . '" style="display: inline-block; background: #2596be; color: white; padding: 14px 40px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px;">' . htmlspecialchars($botaoTexto) . '</a>';
    $html .= '</div>';

    $html .= '<p style="font-size: 12px; color: #667085;">Se o botão não funcionar, copie este link: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';

    $html .= '<div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 11px; color: #999;">Este email foi enviado automaticamente pela plataforma SpecLab. Este link é pessoal e intransmissível.<br>Powered by <strong>SpecLab</strong> &copy;' . date('Y') . '</div>';

    $html .= '</body></html>';
    return $html;
}

/**
 * Gera assunto do email de aceitação (configurável)
 */
function gerarAssuntoEmailAceitacao(array $espec): string {
    $assunto = getConfiguracao('email_aceitacao_assunto', 'Caderno de Encargos para aprovação: {numero}');
    return str_replace(['{numero}', '{titulo}', '{versao}'], [$espec['numero'], $espec['titulo'], $espec['versao']], $assunto);
}

/**
 * Envia email de confirmação após decisão (aceite/rejeitado) com link permanente
 */
function enviarEmailConfirmacaoDecisao(PDO $db, int $especId, int $tokenId, string $decisao, string $nomeSig, string $baseUrl): array {
    $stmt = $db->prepare('SELECT t.*, e.titulo, e.numero, e.versao FROM especificacao_tokens t INNER JOIN especificacoes e ON e.id = t.especificacao_id WHERE t.id = ?');
    $stmt->execute([$tokenId]);
    $tk = $stmt->fetch();
    if (!$tk || empty($tk['destinatario_email'])) {
        return ['success' => false, 'error' => 'Sem email de destinatário.'];
    }

    $link = $baseUrl . '/publico.php?token=' . $tk['token'];
    $aceite = $decisao === 'aceite';
    $corDecisao = $aceite ? '#16a34a' : '#dc2626';
    $textoDecisao = $aceite ? 'Aceite' : 'Rejeitado';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 20px;">';
    $html .= '<div style="border-bottom: 3px solid ' . $corDecisao . '; padding-bottom: 12px; margin-bottom: 20px;">';
    $html .= '<h2 style="color: ' . $corDecisao . '; margin: 0;">Confirmação de Decisão</h2></div>';
    $html .= '<p>Exmo(a) Sr(a) ' . htmlspecialchars($tk['destinatario_nome']) . ',</p>';
    $html .= '<p>A sua decisão sobre o seguinte documento foi registada com sucesso:</p>';
    $html .= '<div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin: 16px 0;">';
    $html .= '<strong>' . htmlspecialchars($tk['titulo']) . '</strong><br>';
    $html .= '<span style="color: #667085;">Número: ' . htmlspecialchars($tk['numero']) . ' | Versão: ' . htmlspecialchars($tk['versao']) . '</span><br>';
    $html .= '<span style="font-size: 15px; font-weight: 600; color: ' . $corDecisao . ';">Decisão: ' . $textoDecisao . '</span><br>';
    $html .= '<span style="color: #667085;">Por: ' . htmlspecialchars($nomeSig) . ' em ' . date('d/m/Y H:i') . '</span>';
    $html .= '</div>';
    $html .= '<p>Pode consultar o documento a qualquer momento através do link abaixo:</p>';
    $html .= '<div style="margin: 24px 0; text-align: center;">';
    $html .= '<a href="' . htmlspecialchars($link) . '" style="display: inline-block; background: #2596be; color: white; padding: 14px 40px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px;">Consultar Documento</a></div>';
    $html .= '<p style="font-size: 12px; color: #667085;">Guarde este email para consulta futura. Link: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
    $html .= '<div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 11px; color: #999;">Este email foi enviado automaticamente pela plataforma SpecLab.<br>Powered by <strong>SpecLab</strong> &copy;' . date('Y') . '</div>';
    $html .= '</body></html>';

    $assunto = 'Confirmação: Documento ' . $textoDecisao . ' — ' . $tk['numero'];
    return enviarEmail($db, $especId, $tk['destinatario_email'], $assunto, $html);
}

/**
 * Notifica org_admin(s) quando fornecedor/cliente toma decisão sobre especificação
 */
function enviarNotificacaoDecisaoAdmin(PDO $db, int $especId, int $tokenId, string $decisao, string $nomeSig, string $baseUrl): array {
    $stmt = $db->prepare('SELECT t.tipo_destinatario, t.destinatario_nome, t.destinatario_email, e.titulo, e.numero, e.versao, e.organizacao_id FROM especificacao_tokens t INNER JOIN especificacoes e ON e.id = t.especificacao_id WHERE t.id = ?');
    $stmt->execute([$tokenId]);
    $tk = $stmt->fetch();
    if (!$tk) return ['success' => false, 'error' => 'Token não encontrado.'];

    // Buscar admins da organização
    $stmtAdmins = $db->prepare('SELECT nome, email FROM utilizadores WHERE organizacao_id = ? AND role IN (?, ?) AND ativo = 1');
    $stmtAdmins->execute([$tk['organizacao_id'], 'org_admin', 'super_admin']);
    $admins = $stmtAdmins->fetchAll();
    if (empty($admins)) return ['success' => true, 'message' => 'Sem admins para notificar.'];

    $cores = ['aceite' => '#16a34a', 'aceite_com_reservas' => '#d97706', 'rejeitado' => '#dc2626'];
    $textos = ['aceite' => 'Aceite', 'aceite_com_reservas' => 'Aceite com Reservas', 'rejeitado' => 'Rejeitado'];
    $cor = $cores[$decisao] ?? '#667085';
    $texto = $textos[$decisao] ?? $decisao;
    $tipo = ucfirst($tk['tipo_destinatario']);
    $link = rtrim($baseUrl, '/') . '/ver.php?id=' . $especId;

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 20px;">';
    $html .= '<div style="border-bottom: 3px solid ' . $cor . '; padding-bottom: 12px; margin-bottom: 20px;">';
    $html .= '<h2 style="color: ' . $cor . '; margin: 0;">Decisão Recebida — ' . htmlspecialchars($tk['numero']) . '</h2></div>';
    $html .= '<p>Um ' . htmlspecialchars(strtolower($tipo)) . ' tomou uma decisão sobre o caderno de encargos:</p>';
    $html .= '<div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin: 16px 0;">';
    $html .= '<strong>' . htmlspecialchars($tk['titulo']) . '</strong><br>';
    $html .= '<span style="color: #667085;">Número: ' . htmlspecialchars($tk['numero']) . ' | Versão: ' . htmlspecialchars($tk['versao']) . '</span><br>';
    $html .= '<span style="font-size: 15px; font-weight: 600; color: ' . $cor . ';">Decisão: ' . $texto . '</span><br>';
    $html .= '<span style="color: #667085;">' . htmlspecialchars($tipo) . ': ' . htmlspecialchars($tk['destinatario_nome']) . ' (' . htmlspecialchars($tk['destinatario_email']) . ')</span><br>';
    $html .= '<span style="color: #667085;">Assinado por: ' . htmlspecialchars($nomeSig) . ' em ' . date('d/m/Y H:i') . '</span>';
    $html .= '</div>';
    $html .= '<div style="margin: 24px 0; text-align: center;">';
    $html .= '<a href="' . htmlspecialchars($link) . '" style="display: inline-block; background: #2596be; color: white; padding: 14px 40px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px;">Ver Detalhes</a></div>';
    $html .= '<div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 11px; color: #999;">Powered by <strong>SpecLab</strong> &copy;' . date('Y') . '</div>';
    $html .= '</body></html>';

    $assunto = 'Decisão ' . $texto . ' — ' . $tk['numero'] . ' (' . $tipo . ')';
    $enviados = 0;
    foreach ($admins as $admin) {
        if (!$admin['email'] || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) continue;
        $r = enviarEmail($db, $especId, $admin['email'], $assunto, $html);
        if ($r['success']) $enviados++;
    }
    return ['success' => $enviados > 0, 'message' => "$enviados admin(s) notificado(s)."];
}

/**
 * Envia notificação de revisão aos admins selecionados
 */
function enviarNotificacaoRevisao(PDO $db, int $especId, array $adminIds, string $baseUrl, int $submetidoPor): array {
    $stmt = $db->prepare('SELECT titulo, numero, versao FROM especificacoes WHERE id = ?');
    $stmt->execute([$especId]);
    $espec = $stmt->fetch();
    if (!$espec) return ['success' => false, 'error' => 'Especificação não encontrada.'];

    $stmtAutor = $db->prepare('SELECT nome FROM utilizadores WHERE id = ?');
    $stmtAutor->execute([$submetidoPor]);
    $nomeAutor = $stmtAutor->fetchColumn() ?: 'Utilizador';

    $link = rtrim($baseUrl, '/') . '/ver.php?id=' . $especId;
    $enviados = 0;
    $erros = [];

    foreach ($adminIds as $adminId) {
        $stmtAdmin = $db->prepare('SELECT nome, email FROM utilizadores WHERE id = ? AND role IN (?, ?)');
        $stmtAdmin->execute([$adminId, 'org_admin', 'super_admin']);
        $admin = $stmtAdmin->fetch();
        if (!$admin || !$admin['email'] || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) continue;

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 20px;">';
        $html .= '<div style="border-bottom: 3px solid #2596be; padding-bottom: 12px; margin-bottom: 20px;">';
        $html .= '<h2 style="color: #2596be; margin: 0;">Documento para Revisão</h2></div>';
        $html .= '<p>Olá ' . htmlspecialchars($admin['nome']) . ',</p>';
        $html .= '<p><strong>' . htmlspecialchars($nomeAutor) . '</strong> submeteu um documento para a sua revisão e aprovação:</p>';
        $html .= '<div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin: 16px 0;">';
        $html .= '<strong>' . htmlspecialchars($espec['titulo']) . '</strong><br>';
        $html .= '<span style="color: #667085;">Número: ' . htmlspecialchars($espec['numero']) . ' | Versão: ' . htmlspecialchars($espec['versao']) . '</span></div>';
        $html .= '<div style="margin: 24px 0; text-align: center;">';
        $html .= '<a href="' . htmlspecialchars($link) . '" style="display: inline-block; background: #2596be; color: white; padding: 14px 40px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px;">Rever e Aprovar</a></div>';
        $html .= '<p style="font-size: 12px; color: #667085;">Necessita de ter sessão iniciada para aceder ao documento.</p>';
        $html .= '<div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 11px; color: #999;">Powered by <strong>SpecLab</strong> &copy;' . date('Y') . '</div>';
        $html .= '</body></html>';

        $result = enviarEmail($db, $especId, $admin['email'], 'Revisão pendente: ' . $espec['numero'], $html, false, $submetidoPor);

        // Registar notificação
        $db->prepare('INSERT INTO revisao_notificacoes (especificacao_id, admin_id) VALUES (?, ?)')->execute([$especId, $adminId]);

        if ($result['success']) $enviados++;
        else $erros[] = $admin['nome'];
    }

    if ($enviados === 0 && !empty($erros)) return ['success' => false, 'error' => 'Não foi possível notificar nenhum admin.'];
    return ['success' => true, 'message' => "Submetida para revisão. $enviados admin(s) notificado(s)."];
}

/**
 * Envia notificação de publicação aos fornecedores vinculados
 */
function enviarNotificacaoPublicacao(PDO $db, int $especId, string $baseUrl, int $publicadoPor): array {
    $stmt = $db->prepare('SELECT titulo, numero, versao, codigo_acesso FROM especificacoes WHERE id = ?');
    $stmt->execute([$especId]);
    $espec = $stmt->fetch();
    if (!$espec) return ['success' => false, 'error' => 'Especificação não encontrada.'];

    $stmtForn = $db->prepare('
        SELECT DISTINCT f.nome, f.email
        FROM especificacao_fornecedores ef
        INNER JOIN fornecedores f ON ef.fornecedor_id = f.id
        WHERE ef.especificacao_id = ? AND f.email IS NOT NULL AND f.email != ""
    ');
    $stmtForn->execute([$especId]);
    $fornecedores = $stmtForn->fetchAll();

    if (empty($fornecedores)) return ['success' => true, 'message' => 'Publicada. Nenhum fornecedor para notificar.'];

    $link = $espec['codigo_acesso']
        ? rtrim($baseUrl, '/') . '/publico.php?code=' . $espec['codigo_acesso']
        : rtrim($baseUrl, '/') . '/ver.php?id=' . $especId;

    $enviados = 0;
    foreach ($fornecedores as $forn) {
        if (!filter_var($forn['email'], FILTER_VALIDATE_EMAIL)) continue;

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 20px;">';
        $html .= '<div style="border-bottom: 3px solid #2596be; padding-bottom: 12px; margin-bottom: 20px;">';
        $html .= '<h2 style="color: #2596be; margin: 0;">Novo Caderno de Encargos Publicado</h2></div>';
        $html .= '<p>Olá ' . htmlspecialchars($forn['nome']) . ',</p>';
        $html .= '<p>Foi publicada uma nova versão do caderno de encargos:</p>';
        $html .= '<div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin: 16px 0;">';
        $html .= '<strong>' . htmlspecialchars($espec['titulo']) . '</strong><br>';
        $html .= '<span style="color: #667085;">Número: ' . htmlspecialchars($espec['numero']) . ' | Versão: ' . htmlspecialchars($espec['versao']) . '</span></div>';
        $html .= '<div style="margin: 24px 0; text-align: center;">';
        $html .= '<a href="' . htmlspecialchars($link) . '" style="display: inline-block; background: #2596be; color: white; padding: 14px 40px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px;">Consultar Documento</a></div>';
        $html .= '<div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 11px; color: #999;">Powered by <strong>SpecLab</strong> &copy;' . date('Y') . '</div>';
        $html .= '</body></html>';

        $result = enviarEmail($db, $especId, $forn['email'], 'Caderno de Encargos Publicado: ' . $espec['numero'], $html, false, $publicadoPor);
        if ($result['success']) $enviados++;
    }

    return ['success' => true, 'message' => "$enviados fornecedor(es) notificado(s)."];
}
