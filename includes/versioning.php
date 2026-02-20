<?php
/**
 * SpecLab - Versionamento + Aceitação
 * Funções para bloquear versões, clonar specs, tokens e aceitação
 */

/**
 * Bloquear (publicar) uma versão — torna-a imutável
 */
function publicarVersao(PDO $db, int $especId, int $userId, ?string $notas = null): bool {
    $stmt = $db->prepare('SELECT id, versao_bloqueada, estado, aprovado_por FROM especificacoes WHERE id = ?');
    $stmt->execute([$especId]);
    $espec = $stmt->fetch();
    if (!$espec || $espec['versao_bloqueada']) return false;

    // Se ainda não foi aprovado, registar aprovação implícita pelo admin que publica
    $aprovadoPor = $espec['aprovado_por'] ?: $userId;
    $aprovadoEm = $espec['aprovado_por'] ? null : 'NOW()';

    $sql = 'UPDATE especificacoes SET versao_bloqueada = 1, estado = ?, notas_versao = ?, publicado_por = ?, publicado_em = NOW()';
    $params = ['ativo', $notas, $userId];
    if (!$espec['aprovado_por']) {
        $sql .= ', aprovado_por = ?, aprovado_em = NOW()';
        $params[] = $userId;
    }
    $sql .= ' WHERE id = ?';
    $params[] = $especId;

    $db->prepare($sql)->execute($params);
    return true;
}

/**
 * Criar nova versão a partir de uma spec bloqueada
 * Clona todos os dados (seccoes, parametros, classes, defeitos, ficheiros, produtos, fornecedores)
 * Retorna o ID da nova versão ou false
 */
function criarNovaVersao(PDO $db, int $especId, int $userId): int|false {
    $stmt = $db->prepare('SELECT * FROM especificacoes WHERE id = ?');
    $stmt->execute([$especId]);
    $orig = $stmt->fetch();
    if (!$orig) return false;

    $novaVersaoNum = $orig['versao_numero'] + 1;
    $novaVersaoStr = $novaVersaoNum . '.0';
    $novoCodigo = gerarCodigoAcesso();

    // Gerar número único: base-vN (ex: RCV-2026-003-v2)
    $numeroBase = preg_replace('/-v\d+$/', '', $orig['numero']);
    $novoNumero = $numeroBase . '-v' . $novaVersaoNum;

    $db->beginTransaction();
    try {
        // 1. Clonar especificacao
        $db->prepare('INSERT INTO especificacoes
            (numero, titulo, tipo_doc, produto_id, cliente_id, fornecedor_id, versao, versao_numero,
             versao_bloqueada, versao_pai_id, grupo_versao, data_emissao, data_revisao, data_validade,
             estado, objetivo, ambito, definicao_material, regulamentacao, processos, embalagem,
             aceitacao, arquivo_texto, indemnizacao, observacoes, config_visual, legislacao_json,
             template_pdf, assinatura_nome, pdf_protegido, password_acesso, codigo_acesso, criado_por, organizacao_id)
            SELECT ?, titulo, tipo_doc, produto_id, cliente_id, fornecedor_id, ?, ?,
                   0, ?, grupo_versao, CURDATE(), NULL, data_validade,
                   ?, objetivo, ambito, definicao_material, regulamentacao, processos, embalagem,
                   aceitacao, arquivo_texto, indemnizacao, observacoes, config_visual, legislacao_json,
                   template_pdf, assinatura_nome, pdf_protegido, password_acesso, ?, ?, organizacao_id
            FROM especificacoes WHERE id = ?')
            ->execute([$novoNumero, $novaVersaoStr, $novaVersaoNum, $especId, 'rascunho', $novoCodigo, $userId, $especId]);

        $novoId = (int)$db->lastInsertId();

        // 2. Clonar seccoes
        $db->prepare('INSERT INTO especificacao_seccoes (especificacao_id, titulo, conteudo, tipo, ordem)
            SELECT ?, titulo, conteudo, tipo, ordem
            FROM especificacao_seccoes WHERE especificacao_id = ?')
            ->execute([$novoId, $especId]);

        // 3. Clonar parametros
        $cols = getColumnList($db, 'especificacao_parametros', ['id', 'especificacao_id']);
        if ($cols) {
            $db->prepare("INSERT INTO especificacao_parametros (especificacao_id, $cols)
                SELECT ?, $cols FROM especificacao_parametros WHERE especificacao_id = ?")
                ->execute([$novoId, $especId]);
        }

        // 4. Clonar classes
        $db->prepare('INSERT INTO especificacao_classes (especificacao_id, classe, defeitos_max, descricao, ordem)
            SELECT ?, classe, defeitos_max, descricao, ordem
            FROM especificacao_classes WHERE especificacao_id = ?')
            ->execute([$novoId, $especId]);

        // 5. Clonar defeitos
        $db->prepare('INSERT INTO especificacao_defeitos (especificacao_id, nome, tipo, descricao, ordem)
            SELECT ?, nome, tipo, descricao, ordem
            FROM especificacao_defeitos WHERE especificacao_id = ?')
            ->execute([$novoId, $especId]);

        // 6. Clonar ficheiros (referência ao mesmo ficheiro físico)
        $db->prepare('INSERT INTO especificacao_ficheiros (especificacao_id, nome_original, nome_servidor, tipo_ficheiro, tamanho, descricao, is_foto, incluir_pdf, pagina_pdf, legenda)
            SELECT ?, nome_original, nome_servidor, tipo_ficheiro, tamanho, descricao, is_foto, incluir_pdf, pagina_pdf, legenda
            FROM especificacao_ficheiros WHERE especificacao_id = ?')
            ->execute([$novoId, $especId]);

        // 7. Clonar produtos (many-to-many)
        $db->prepare('INSERT INTO especificacao_produtos (especificacao_id, produto_id)
            SELECT ?, produto_id FROM especificacao_produtos WHERE especificacao_id = ?')
            ->execute([$novoId, $especId]);

        // 8. Clonar fornecedores (many-to-many)
        $db->prepare('INSERT INTO especificacao_fornecedores (especificacao_id, fornecedor_id)
            SELECT ?, fornecedor_id FROM especificacao_fornecedores WHERE especificacao_id = ?')
            ->execute([$novoId, $especId]);

        $db->commit();
        return $novoId;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Erro ao clonar versão: ' . $e->getMessage());
        return false;
    }
}

/**
 * Helper: obter lista de colunas de uma tabela excluindo certas
 */
function getColumnList(PDO $db, string $table, array $exclude): string {
    $stmt = $db->query("SHOW COLUMNS FROM $table");
    $cols = [];
    while ($row = $stmt->fetch()) {
        if (!in_array($row['Field'], $exclude)) {
            $cols[] = $row['Field'];
        }
    }
    return implode(', ', $cols);
}

/**
 * Obter todas as versões de um grupo
 */
function getVersoesGrupo(PDO $db, string $grupoVersao): array {
    $stmt = $db->prepare('
        SELECT e.id, e.versao, e.versao_numero, e.estado, e.versao_bloqueada,
               e.notas_versao, e.publicado_em, e.codigo_acesso,
               u.nome as publicado_por_nome,
               (SELECT COUNT(*) FROM especificacao_aceitacoes a WHERE a.especificacao_id = e.id AND a.tipo_decisao = "aceite") as total_aceites,
               (SELECT COUNT(*) FROM especificacao_aceitacoes a WHERE a.especificacao_id = e.id AND a.tipo_decisao = "rejeitado") as total_rejeicoes,
               (SELECT COUNT(*) FROM especificacao_tokens t WHERE t.especificacao_id = e.id AND t.ativo = 1) as total_tokens
        FROM especificacoes e
        LEFT JOIN utilizadores u ON u.id = e.publicado_por
        WHERE e.grupo_versao = ?
        ORDER BY e.versao_numero DESC
    ');
    $stmt->execute([$grupoVersao]);
    return $stmt->fetchAll();
}

/**
 * Gerar token individual para um destinatário
 */
function gerarTokenDestinatario(PDO $db, int $especId, int $userId, string $nome, string $email, string $tipo = 'outro', string $permissao = 'ver_aceitar'): string {
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO especificacao_tokens (especificacao_id, token, tipo_destinatario, destinatario_nome, destinatario_email, permissao, criado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$especId, $token, $tipo, $nome, $email, $permissao, $userId]);
    return $token;
}

/**
 * Obter tokens de uma especificação
 */
function getTokensEspecificacao(PDO $db, int $especId): array {
    $stmt = $db->prepare('
        SELECT t.*,
               a.tipo_decisao, a.nome_signatario, a.cargo_signatario, a.comentario as decisao_comentario, a.created_at as decisao_em
        FROM especificacao_tokens t
        LEFT JOIN especificacao_aceitacoes a ON a.token_id = t.id
        WHERE t.especificacao_id = ? AND t.ativo = 1
        ORDER BY t.created_at DESC
    ');
    $stmt->execute([$especId]);
    return $stmt->fetchAll();
}

/**
 * Registar aceitação/rejeição
 */
function registarDecisao(PDO $db, int $especId, int $tokenId, string $decisao, string $nome, ?string $cargo, ?string $comentario, ?string $assinatura = null): bool {
    // Verificar se já existe decisão para este token
    $stmt = $db->prepare('SELECT id FROM especificacao_aceitacoes WHERE token_id = ?');
    $stmt->execute([$tokenId]);
    if ($stmt->fetch()) return false; // Já decidiu

    $db->prepare('INSERT INTO especificacao_aceitacoes (especificacao_id, token_id, tipo_decisao, nome_signatario, cargo_signatario, assinatura_signatario, comentario, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$especId, $tokenId, $decisao, $nome, $cargo, $assinatura, $comentario, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);

    // Log
    $db->prepare('INSERT INTO especificacao_token_log (token_id, especificacao_id, acao, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([$tokenId, $especId, $decisao === 'aceite' ? 'aceitar' : 'rejeitar', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);

    return true;
}

/**
 * Registar acesso via token
 */
function registarAcessoToken(PDO $db, int $tokenId, int $especId): void {
    // Atualizar token
    $db->prepare('UPDATE especificacao_tokens SET ultimo_acesso = NOW(), total_acessos = total_acessos + 1,
        primeiro_acesso = COALESCE(primeiro_acesso, NOW()) WHERE id = ?')
        ->execute([$tokenId]);

    // Log
    $db->prepare('INSERT INTO especificacao_token_log (token_id, especificacao_id, acao, ip_address, user_agent)
        VALUES (?, ?, "view", ?, ?)')
        ->execute([$tokenId, $especId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
}

/**
 * Obter resumo de aceitação para uma spec
 */
function getResumoAceitacao(PDO $db, int $especId): array {
    $stmt = $db->prepare('
        SELECT
            COUNT(*) as total_tokens,
            SUM(CASE WHEN a.tipo_decisao = "aceite" THEN 1 ELSE 0 END) as aceites,
            SUM(CASE WHEN a.tipo_decisao = "rejeitado" THEN 1 ELSE 0 END) as rejeicoes,
            SUM(CASE WHEN a.id IS NULL THEN 1 ELSE 0 END) as pendentes
        FROM especificacao_tokens t
        LEFT JOIN especificacao_aceitacoes a ON a.token_id = t.id
        WHERE t.especificacao_id = ? AND t.ativo = 1 AND t.permissao = "ver_aceitar"
    ');
    $stmt->execute([$especId]);
    return $stmt->fetch() ?: ['total_tokens' => 0, 'aceites' => 0, 'rejeicoes' => 0, 'pendentes' => 0];
}
