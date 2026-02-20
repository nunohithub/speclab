<?php
/**
 * SpecLab - Cadernos de Encargos
 * Funções auxiliares de negócio
 */

/**
 * Obter especificação completa com todos os dados relacionados
 */
function getEspecificacaoCompleta(PDO $db, int $id): ?array {
    $stmt = $db->prepare('
        SELECT e.*,
               c.nome as cliente_nome, c.sigla as cliente_sigla, c.email as cliente_email,
               u.nome as criado_por_nome
        FROM especificacoes e
        LEFT JOIN clientes c ON e.cliente_id = c.id
        LEFT JOIN utilizadores u ON e.criado_por = u.id
        WHERE e.id = ?
    ');
    $stmt->execute([$id]);
    $espec = $stmt->fetch();
    if (!$espec) return null;

    // Produtos (muitos-para-muitos)
    $stmt = $db->prepare('
        SELECT p.id, p.nome, p.tipo
        FROM especificacao_produtos ep
        INNER JOIN produtos p ON ep.produto_id = p.id
        WHERE ep.especificacao_id = ?
        ORDER BY p.nome
    ');
    $stmt->execute([$id]);
    $espec['produtos_lista'] = $stmt->fetchAll();
    $nomesProd = array_column($espec['produtos_lista'], 'nome');
    $espec['produto_nome'] = $nomesProd ? implode(', ', $nomesProd) : null;
    $espec['produto_tipo'] = null;
    $espec['produto_ids'] = array_column($espec['produtos_lista'], 'id');

    // Fornecedores (muitos-para-muitos)
    $stmt = $db->prepare('
        SELECT f.id, f.nome, f.sigla, f.email
        FROM especificacao_fornecedores ef
        INNER JOIN fornecedores f ON ef.fornecedor_id = f.id
        WHERE ef.especificacao_id = ?
        ORDER BY f.nome
    ');
    $stmt->execute([$id]);
    $espec['fornecedores_lista'] = $stmt->fetchAll();
    $nomesForn = array_column($espec['fornecedores_lista'], 'nome');
    $espec['fornecedor_nome'] = $nomesForn ? implode(', ', $nomesForn) : null;
    $espec['fornecedor_sigla'] = count($espec['fornecedores_lista']) === 1 ? $espec['fornecedores_lista'][0]['sigla'] : null;
    $espec['fornecedor_ids'] = array_column($espec['fornecedores_lista'], 'id');

    // Parâmetros
    $stmt = $db->prepare('SELECT * FROM especificacao_parametros WHERE especificacao_id = ? ORDER BY ordem, id');
    $stmt->execute([$id]);
    $espec['parametros'] = $stmt->fetchAll();

    // Classes visuais
    $stmt = $db->prepare('SELECT * FROM especificacao_classes WHERE especificacao_id = ? ORDER BY ordem, id');
    $stmt->execute([$id]);
    $espec['classes'] = $stmt->fetchAll();

    // Defeitos
    $stmt = $db->prepare('SELECT * FROM especificacao_defeitos WHERE especificacao_id = ? ORDER BY ordem, id');
    $stmt->execute([$id]);
    $espec['defeitos'] = $stmt->fetchAll();

    // Secções personalizadas
    $stmt = $db->prepare('SELECT * FROM especificacao_seccoes WHERE especificacao_id = ? ORDER BY ordem, id');
    $stmt->execute([$id]);
    $espec['seccoes'] = $stmt->fetchAll();

    // Ficheiros
    $stmt = $db->prepare('SELECT * FROM especificacao_ficheiros WHERE especificacao_id = ? ORDER BY uploaded_at DESC');
    $stmt->execute([$id]);
    $espec['ficheiros'] = $stmt->fetchAll();

    return $espec;
}

/**
 * Obter categorias de parâmetros padrão baseadas nos cadernos de encargo analisados
 */
function getCategoriasPadrao(): array {
    try {
        $db = getDB();
        $rows = $db->query('SELECT categoria, ensaio, metodo, nivel_especial, nqa, exemplo, unidade FROM ensaios_banco WHERE ativo = 1 ORDER BY ordem, categoria, ensaio')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['categoria']][] = [
                'ensaio' => $row['ensaio'],
                'metodo' => $row['metodo'],
                'nivel_especial' => $row['nivel_especial'] ?? '',
                'nqa' => $row['nqa'] ?? '',
                'exemplo' => $row['exemplo'],
                'unidade' => $row['unidade'] ?? '',
            ];
        }
        return $result;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Obter defeitos padrão
 */
function getDefeitosPadrao(): array {
    return [
        'critico' => [
            'Ano Seco severo' => 'Defeito de madeira seca que compromete vedação',
            'Bicho' => 'Galerias de inseto que atravessam a rolha',
            'Fenda total' => 'Fenda que divide completamente a rolha',
        ],
        'maior' => [
            'Ano Seco' => 'Presença de madeira seca',
            'Costa' => 'Presença de casca exterior',
            'Caleira' => 'Sulco de sobreposição de broca',
            'Verde' => 'Células saturadas de água',
            'Fenda vertical' => 'Fenda na direção axial',
            'Fenda horizontal' => 'Fenda na direção radial',
        ],
        'menor' => [
            'Rugosidade' => 'Superfície irregular',
            'Descoloração' => 'Alteração de cor',
            'Split' => 'Separação no topo da rolha',
            'Prego' => 'Inclusões de lenhina',
            'Mal Colmatada' => 'Colmatagem defeituosa',
        ],
    ];
}

/**
 * Classes visuais padrão
 */
function getClassesPadrao(): array {
    return [
        ['classe' => 'Extra', 'defeitos_max' => 6, 'descricao' => 'Qualidade premium'],
        ['classe' => 'Super', 'defeitos_max' => 8, 'descricao' => 'Qualidade superior'],
        ['classe' => '1º', 'defeitos_max' => 10, 'descricao' => 'Primeira qualidade'],
        ['classe' => '2º', 'defeitos_max' => 12, 'descricao' => 'Segunda qualidade'],
        ['classe' => '3º', 'defeitos_max' => 15, 'descricao' => 'Terceira qualidade'],
        ['classe' => '4º', 'defeitos_max' => 20, 'descricao' => 'Quarta qualidade'],
        ['classe' => 'Colmatada A', 'defeitos_max' => 8, 'descricao' => 'Colmatada grau A'],
        ['classe' => 'Colmatada B', 'defeitos_max' => 10, 'descricao' => 'Colmatada grau B'],
        ['classe' => 'Colmatada C', 'defeitos_max' => 12, 'descricao' => 'Colmatada grau C'],
    ];
}

/**
 * Plano de amostragem NP 2922
 */
function getPlanoAmostragem(): array {
    return [
        ['lote_min' => 1, 'lote_max' => 1200, 'amostra' => 80],
        ['lote_min' => 1201, 'lote_max' => 3200, 'amostra' => 125],
        ['lote_min' => 3201, 'lote_max' => 10000, 'amostra' => 200],
        ['lote_min' => 10001, 'lote_max' => 35000, 'amostra' => 315],
        ['lote_min' => 35001, 'lote_max' => 150000, 'amostra' => 500],
        ['lote_min' => 150001, 'lote_max' => 500000, 'amostra' => 800],
        ['lote_min' => 500001, 'lote_max' => 999999999, 'amostra' => 1250],
    ];
}

/**
 * Valores NQA (Nível de Qualidade Aceitável) conforme NP 2922
 * Usados no select de parâmetros
 */
function getNQAValues(): array {
    return [
        '0,010', '0,015', '0,025', '0,040', '0,065',
        '0,10', '0,15', '0,25', '0,40', '0,65',
        '1,0', '1,5', '2,5', '4,0', '6,5',
        '10', '15', '25', '40', '65',
        '100', '150', '250', '400', '650', '1000',
    ];
}

/**
 * Regulamentação padrão
 */
function getRegulamentacaoPadrao(): string {
    return "• Código Internacional das Práticas Rolheiras (CIPR)\n"
         . "• Regulamento (CE) 1935/2004 - Materiais em contacto com alimentos\n"
         . "• Resolução AP(2004)2 - Rolhas e produtos em contacto com alimentos\n"
         . "• Resolução AP(2004)5 - Silicones para contacto com alimentos\n"
         . "• Regulamento (CE) 2023/2006 - Boas práticas de fabrico\n"
         . "• Regulamento 10/2011 - Materiais plásticos para contacto com alimentos\n"
         . "• Diretiva 2008/95/CE - Marcas registadas";
}

// =============================================
// GUARDAR RELAÇÕES MUITOS-PARA-MUITOS
// =============================================

/**
 * Guardar produtos associados a uma especificação
 */
function saveEspecProdutos(PDO $db, int $especId, array $produtoIds): void {
    $db->prepare('DELETE FROM especificacao_produtos WHERE especificacao_id = ?')->execute([$especId]);
    if (!empty($produtoIds)) {
        $stmt = $db->prepare('INSERT INTO especificacao_produtos (especificacao_id, produto_id) VALUES (?, ?)');
        foreach ($produtoIds as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) {
                $stmt->execute([$especId, $pid]);
            }
        }
    }
}

/**
 * Guardar fornecedores associados a uma especificação
 */
function saveEspecFornecedores(PDO $db, int $especId, array $fornecedorIds): void {
    $db->prepare('DELETE FROM especificacao_fornecedores WHERE especificacao_id = ?')->execute([$especId]);
    if (!empty($fornecedorIds)) {
        $stmt = $db->prepare('INSERT INTO especificacao_fornecedores (especificacao_id, fornecedor_id) VALUES (?, ?)');
        foreach ($fornecedorIds as $fid) {
            $fid = (int)$fid;
            if ($fid > 0) {
                $stmt->execute([$especId, $fid]);
            }
        }
    }
}

// =============================================
// VERIFICAÇÃO DE LIMITES DO PLANO
// =============================================

/**
 * Obter dados do plano de uma organização
 */
function getOrgPlano(PDO $db, int $orgId): array {
    $stmt = $db->prepare('
        SELECT o.plano, o.max_utilizadores, o.max_especificacoes,
               COALESCE(p.nome, o.plano) as plano_nome
        FROM organizacoes o
        LEFT JOIN planos p ON o.plano = p.id
        WHERE o.id = ?
    ');
    $stmt->execute([$orgId]);
    $data = $stmt->fetch();
    return $data ?: ['plano' => 'basico', 'max_utilizadores' => 5, 'max_especificacoes' => null, 'plano_nome' => 'Básico'];
}

/**
 * Contar utilizadores ativos de uma organização
 */
function contarUtilizadoresOrg(PDO $db, int $orgId): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM utilizadores WHERE organizacao_id = ? AND ativo = 1');
    $stmt->execute([$orgId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Contar especificações de uma organização
 */
function contarEspecificacoesOrg(PDO $db, int $orgId): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM especificacoes WHERE organizacao_id = ?');
    $stmt->execute([$orgId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Verificar se organização pode adicionar mais utilizadores
 * Retorna ['ok' => bool, 'atual' => int, 'max' => int|null, 'msg' => string]
 */
function podeCriarUtilizador(PDO $db, int $orgId): array {
    $plano = getOrgPlano($db, $orgId);
    $atual = contarUtilizadoresOrg($db, $orgId);
    $max = (int)$plano['max_utilizadores'];

    if ($max > 0 && $atual >= $max) {
        return [
            'ok' => false,
            'atual' => $atual,
            'max' => $max,
            'msg' => "Limite atingido: {$atual}/{$max} utilizadores (plano {$plano['plano_nome']})"
        ];
    }
    return ['ok' => true, 'atual' => $atual, 'max' => $max, 'msg' => ''];
}

/**
 * Verificar se organização pode criar mais especificações
 * Retorna ['ok' => bool, 'atual' => int, 'max' => int|null, 'msg' => string]
 */
function podeCriarEspecificacao(PDO $db, int $orgId): array {
    $plano = getOrgPlano($db, $orgId);
    $atual = contarEspecificacoesOrg($db, $orgId);
    $max = $plano['max_especificacoes'];

    if ($max !== null && $max > 0 && $atual >= (int)$max) {
        return [
            'ok' => false,
            'atual' => $atual,
            'max' => (int)$max,
            'msg' => "Limite atingido: {$atual}/{$max} especificações (plano {$plano['plano_nome']})"
        ];
    }
    return ['ok' => true, 'atual' => $atual, 'max' => $max ? (int)$max : null, 'msg' => ''];
}

/**
 * Obter todos os planos ativos (para admin)
 */
function getPlanos(PDO $db): array {
    try {
        return $db->query('SELECT * FROM planos WHERE ativo = 1 ORDER BY ordem')->fetchAll();
    } catch (Exception $e) {
        // Tabela pode não existir ainda
        return [];
    }
}

/**
 * Gera URL de asset com versão baseada na data de modificação do ficheiro
 */
function asset(string $path): string {
    $filePath = __DIR__ . '/../' . ltrim($path, '/');
    $version = file_exists($filePath) ? filemtime($filePath) : time();
    return BASE_PATH . '/' . ltrim($path, '/') . '?v=' . $version;
}
