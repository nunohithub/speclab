<?php
/**
 * Comprovativo de Decisão (Aceitação/Rejeição)
 * Página imprimível para servir como prova formal
 */
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$tokenId = (int)($_GET['token_id'] ?? 0);
if (!$tokenId) { echo 'Token inválido.'; exit; }

$user = getCurrentUser();
$db = getDB();
$orgId = (int)($user['org_id'] ?? 0);

// Buscar token + decisão
$stmt = $db->prepare('
    SELECT t.*,
           a.tipo_decisao, a.nome_signatario, a.cargo_signatario, a.comentario, a.created_at as decisao_em, a.ip_address, a.user_agent,
           e.numero, e.titulo, e.versao, e.data_emissao, e.organizacao_id,
           o.nome as org_nome, o.logo as org_logo, o.cor_primaria
    FROM especificacao_tokens t
    LEFT JOIN especificacao_aceitacoes a ON a.token_id = t.id
    JOIN especificacoes e ON e.id = t.especificacao_id
    LEFT JOIN organizacoes o ON o.id = e.organizacao_id
    WHERE t.id = ?
');
$stmt->execute([$tokenId]);
$tk = $stmt->fetch();
if (!$tk) { echo 'Registo não encontrado.'; exit; }

// Verificar acesso multi-tenant (a especificação pertence à org do user)
if ($user['role'] !== 'super_admin' && (int)$tk['organizacao_id'] !== $orgId) {
    echo 'Sem permissão.'; exit;
}

$isAceite = ($tk['tipo_decisao'] === 'aceite');
$corDecisao = $isAceite ? '#16a34a' : '#dc2626';
$textoDecisao = $isAceite ? 'ACEITE' : 'REJEITADO';
$corPrimaria = $tk['cor_primaria'] ?: '#2596be';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Comprovativo - <?= htmlspecialchars($tk['numero']) ?></title>
<style>
    @media print { .no-print { display: none !important; } body { margin: 0; } }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #333; padding: 40px; max-width: 800px; margin: 0 auto; }
    .header { display: flex; align-items: center; gap: 16px; border-bottom: 3px solid <?= htmlspecialchars($corPrimaria) ?>; padding-bottom: 16px; margin-bottom: 24px; }
    .header img { height: 50px; }
    .header h1 { font-size: 18px; color: <?= htmlspecialchars($corPrimaria) ?>; }
    .header .org { font-size: 14px; color: #666; }
    .titulo { font-size: 22px; font-weight: 700; margin-bottom: 24px; text-align: center; }
    .decisao { text-align: center; margin: 24px 0; padding: 16px; border: 2px solid <?= $corDecisao ?>; border-radius: 8px; background: <?= $isAceite ? '#f0fdf4' : '#fef2f2' ?>; }
    .decisao .label { font-size: 28px; font-weight: 700; color: <?= $corDecisao ?>; letter-spacing: 2px; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
    th { background: #f9fafb; font-weight: 600; width: 35%; color: #555; }
    .comentario { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; margin: 16px 0; white-space: pre-wrap; font-size: 14px; }
    .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; font-size: 11px; color: #999; text-align: center; }
    .btn-print { display: inline-block; padding: 10px 24px; background: <?= htmlspecialchars($corPrimaria) ?>; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; margin-bottom: 24px; }
    .btn-print:hover { opacity: 0.9; }
</style>
</head>
<body>
<div class="no-print" style="text-align: center;">
    <button class="btn-print" onclick="window.print()">Imprimir Comprovativo</button>
</div>

<div class="header">
    <?php if ($tk['org_logo']): ?>
    <img src="uploads/logos/<?= htmlspecialchars($tk['org_logo']) ?>" alt="Logo">
    <?php endif; ?>
    <div>
        <h1>Comprovativo de Decisão</h1>
        <div class="org"><?= htmlspecialchars($tk['org_nome'] ?? '') ?></div>
    </div>
</div>

<div class="titulo"><?= htmlspecialchars($tk['numero']) ?> — <?= htmlspecialchars($tk['titulo']) ?></div>

<div class="decisao">
    <div class="label"><?= $textoDecisao ?></div>
</div>

<h3 style="font-size:15px; margin: 20px 0 8px; color: #555;">Dados da Especificação</h3>
<table>
    <tr><th>Número</th><td><?= htmlspecialchars($tk['numero']) ?></td></tr>
    <tr><th>Título</th><td><?= htmlspecialchars($tk['titulo']) ?></td></tr>
    <tr><th>Versão</th><td><?= htmlspecialchars($tk['versao']) ?></td></tr>
    <tr><th>Data de Emissão</th><td><?= $tk['data_emissao'] ? date('d/m/Y', strtotime($tk['data_emissao'])) : '-' ?></td></tr>
</table>

<h3 style="font-size:15px; margin: 20px 0 8px; color: #555;">Dados do Destinatário</h3>
<table>
    <tr><th>Nome</th><td><?= htmlspecialchars($tk['destinatario_nome'] ?? '-') ?></td></tr>
    <tr><th>Email</th><td><?= htmlspecialchars($tk['destinatario_email'] ?? '-') ?></td></tr>
    <tr><th>Tipo</th><td><?= ucfirst(htmlspecialchars($tk['tipo_destinatario'])) ?></td></tr>
</table>

<h3 style="font-size:15px; margin: 20px 0 8px; color: #555;">Decisão</h3>
<table>
    <tr><th>Resultado</th><td style="font-weight:700; color:<?= $corDecisao ?>"><?= $textoDecisao ?></td></tr>
    <tr><th>Signatário</th><td><?= htmlspecialchars($tk['nome_signatario'] ?? '-') ?><?= $tk['cargo_signatario'] ? ' (' . htmlspecialchars($tk['cargo_signatario']) . ')' : '' ?></td></tr>
    <tr><th>Data da Decisão</th><td><?= $tk['decisao_em'] ? date('d/m/Y H:i', strtotime($tk['decisao_em'])) : '-' ?></td></tr>
    <tr><th>Endereço IP</th><td><?= htmlspecialchars($tk['ip_address'] ?? '-') ?></td></tr>
</table>

<?php if (!empty($tk['comentario'])): ?>
<h3 style="font-size:15px; margin: 20px 0 8px; color: #555;">Comentário / Motivo</h3>
<div class="comentario"><?= htmlspecialchars($tk['comentario']) ?></div>
<?php endif; ?>

<div class="footer">
    Documento gerado em <?= date('d/m/Y H:i') ?> | <?= htmlspecialchars($tk['org_nome'] ?? 'SpecLab') ?> | Este documento serve como comprovativo da decisão registada no sistema.
</div>
</body>
</html>
