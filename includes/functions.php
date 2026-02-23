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
               u.nome as criado_por_nome,
               org.nome as org_nome, org.logo as org_logo
        FROM especificacoes e
        LEFT JOIN clientes c ON e.cliente_id = c.id
        LEFT JOIN utilizadores u ON e.criado_por = u.id
        LEFT JOIN organizacoes org ON e.organizacao_id = org.id
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
function getCategoriasPadrao(?int $orgId = null): array {
    try {
        $db = getDB();
        if ($orgId) {
            $stmt = $db->prepare('SELECT categoria, ensaio, metodo, nivel_especial, nqa, exemplo, unidade FROM ensaios_banco WHERE ativo = 1 AND organizacao_id = ? ORDER BY ordem, categoria, ensaio');
            $stmt->execute([$orgId]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $db->query('SELECT categoria, ensaio, metodo, nivel_especial, nqa, exemplo, unidade FROM ensaios_banco WHERE ativo = 1 ORDER BY ordem, categoria, ensaio')->fetchAll();
        }
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
 * Validar se uma especificação está completa para publicação/submissão
 * Retorna array de erros (vazio = ok)
 */
function validateForPublish(PDO $db, int $especId): array {
    $erros = [];
    $stmt = $db->prepare('SELECT titulo, data_emissao FROM especificacoes WHERE id = ?');
    $stmt->execute([$especId]);
    $e = $stmt->fetch();
    if (!$e) return ['Especificação não encontrada.'];

    if (empty(trim($e['titulo'] ?? ''))) {
        $erros[] = 'Título é obrigatório.';
    }
    if (empty($e['data_emissao'])) {
        $erros[] = 'Data de emissão é obrigatória.';
    }

    // Pelo menos 1 produto
    $stmt = $db->prepare('SELECT COUNT(*) FROM especificacao_produtos WHERE especificacao_id = ?');
    $stmt->execute([$especId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $erros[] = 'Pelo menos 1 produto é obrigatório.';
    }

    // Pelo menos 1 secção com conteúdo
    $stmt = $db->prepare('SELECT COUNT(*) FROM especificacao_seccoes WHERE especificacao_id = ? AND conteudo IS NOT NULL AND conteudo != ""');
    $stmt->execute([$especId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $erros[] = 'Pelo menos 1 secção com conteúdo é obrigatória.';
    }

    return $erros;
}

/**
 * Gera URL de asset com versão baseada na data de modificação do ficheiro
 */
function asset(string $path): string {
    $filePath = __DIR__ . '/../' . ltrim($path, '/');
    $version = file_exists($filePath) ? filemtime($filePath) : time();
    return BASE_PATH . '/' . ltrim($path, '/') . '?v=' . $version;
}

/**
 * Obter cores da organização com defaults
 */
function getOrgColors(?array $org): array {
    return [
        'primaria'       => $org['cor_primaria'] ?? '#2596be',
        'primaria_dark'  => $org['cor_primaria_dark'] ?? '#1a7a9e',
        'primaria_light' => $org['cor_primaria_light'] ?? '#e6f4f9',
    ];
}

/**
 * Parsear config_visual com defaults
 */
function parseConfigVisual($configVisual, string $corPrimaria = '#2596be'): array {
    $defaults = [
        'cor_titulos'       => $corPrimaria,
        'cor_subtitulos'    => $corPrimaria,
        'cor_linhas'        => $corPrimaria,
        'cor_nome'          => $corPrimaria,
        'cor_header_tab'    => $corPrimaria,
        'cor_fundo_tab'     => '#f8f9fa',
        'tamanho_titulos'   => '14',
        'tamanho_subtitulos'=> '12',
        'tamanho_nome'      => '16',
        'tamanho_corpo'     => '11',
        'subtitulos_bold'   => '1',
        'fonte_titulos'     => 'Helvetica',
        'fonte_corpo'       => 'Helvetica',
        'logo_custom'       => '',
    ];
    if (!empty($configVisual)) {
        $parsed = is_string($configVisual) ? json_decode($configVisual, true) : $configVisual;
        if (is_array($parsed)) $defaults = array_merge($defaults, $parsed);
    }
    return $defaults;
}

/**
 * Labels multi-idioma partilhados (ver.php, publico.php, pdf.php)
 */
function getMultiLangLabels(): array {
    return [
        'pt' => ['titulo' => 'Título', 'versao' => 'Versão', 'data_emissao' => 'Data de Emissão', 'data_validade' => 'Data de Validade', 'cliente' => 'Cliente', 'produto' => 'Produto', 'fornecedor' => 'Fornecedor', 'estado' => 'Estado', 'elaborado_por' => 'Elaborado por', 'aprovado_por' => 'Aprovado por', 'observacoes' => 'Observações', 'parametro' => 'Parâmetro', 'especificacao' => 'Especificação', 'metodo' => 'Método', 'tolerancia' => 'Tolerância', 'unidade' => 'Unidade', 'norma_ref' => 'Norma/Ref.', 'categoria' => 'Categoria', 'legenda' => 'Legenda'],
        'en' => ['titulo' => 'Title', 'versao' => 'Version', 'data_emissao' => 'Issue Date', 'data_validade' => 'Expiry Date', 'cliente' => 'Client', 'produto' => 'Product', 'fornecedor' => 'Supplier', 'estado' => 'Status', 'elaborado_por' => 'Prepared by', 'aprovado_por' => 'Approved by', 'observacoes' => 'Notes', 'parametro' => 'Parameter', 'especificacao' => 'Specification', 'metodo' => 'Method', 'tolerancia' => 'Tolerance', 'unidade' => 'Unit', 'norma_ref' => 'Standard/Ref.', 'categoria' => 'Category', 'legenda' => 'Legend'],
        'es' => ['titulo' => 'Título', 'versao' => 'Versión', 'data_emissao' => 'Fecha de Emisión', 'data_validade' => 'Fecha de Caducidad', 'cliente' => 'Cliente', 'produto' => 'Producto', 'fornecedor' => 'Proveedor', 'estado' => 'Estado', 'elaborado_por' => 'Elaborado por', 'aprovado_por' => 'Aprobado por', 'observacoes' => 'Observaciones', 'parametro' => 'Parámetro', 'especificacao' => 'Especificación', 'metodo' => 'Método', 'tolerancia' => 'Tolerancia', 'unidade' => 'Unidad', 'norma_ref' => 'Norma/Ref.', 'categoria' => 'Categoría', 'legenda' => 'Leyenda'],
        'fr' => ['titulo' => 'Titre', 'versao' => 'Version', 'data_emissao' => "Date d'Émission", 'data_validade' => "Date d'Expiration", 'cliente' => 'Client', 'produto' => 'Produit', 'fornecedor' => 'Fournisseur', 'estado' => 'Statut', 'elaborado_por' => 'Préparé par', 'aprovado_por' => 'Approuvé par', 'observacoes' => 'Remarques', 'parametro' => 'Paramètre', 'especificacao' => 'Spécification', 'metodo' => 'Méthode', 'tolerancia' => 'Tolérance', 'unidade' => 'Unité', 'norma_ref' => 'Norme/Réf.', 'categoria' => 'Catégorie', 'legenda' => 'Légende'],
        'de' => ['titulo' => 'Titel', 'versao' => 'Version', 'data_emissao' => 'Ausstellungsdatum', 'data_validade' => 'Ablaufdatum', 'cliente' => 'Kunde', 'produto' => 'Produkt', 'fornecedor' => 'Lieferant', 'estado' => 'Status', 'elaborado_por' => 'Erstellt von', 'aprovado_por' => 'Genehmigt von', 'observacoes' => 'Anmerkungen', 'parametro' => 'Parameter', 'especificacao' => 'Spezifikation', 'metodo' => 'Methode', 'tolerancia' => 'Toleranz', 'unidade' => 'Einheit', 'norma_ref' => 'Norm/Ref.', 'categoria' => 'Kategorie', 'legenda' => 'Legende'],
        'it' => ['titulo' => 'Titolo', 'versao' => 'Versione', 'data_emissao' => 'Data di Emissione', 'data_validade' => 'Data di Scadenza', 'cliente' => 'Cliente', 'produto' => 'Prodotto', 'fornecedor' => 'Fornitore', 'estado' => 'Stato', 'elaborado_por' => 'Preparato da', 'aprovado_por' => 'Approvato da', 'observacoes' => 'Osservazioni', 'parametro' => 'Parametro', 'especificacao' => 'Specifica', 'metodo' => 'Metodo', 'tolerancia' => 'Tolleranza', 'unidade' => 'Unità', 'norma_ref' => 'Norma/Rif.', 'categoria' => 'Categoria', 'legenda' => 'Legenda'],
    ];
}

/**
 * Secções fixas legadas (para especificações sem secções dinâmicas)
 */
function getLegacySections(): array {
    return [
        'objetivo'            => 'Objetivo e Âmbito de Aplicação',
        'ambito'              => 'Introdução',
        'definicao_material'  => 'Definição do Material',
        'regulamentacao'      => 'Regulamentação',
        'processos'           => 'Processos',
        'embalagem'           => 'Embalagem',
        'aceitacao'           => 'Aceitação',
        'arquivo_texto'       => 'Arquivo',
        'indemnizacao'        => 'Indemnização',
        'observacoes'         => 'Observações',
    ];
}

/**
 * Parsear secção de parâmetros (duplicado em especificacao, pdf, ver, publico).
 * Devolve array com: raw, rows, tipo_id, tipo_slug, tipo_nome, colunas, colWidths, legenda, legenda_tamanho, orientacao
 */
function parseParametrosSeccao(PDO $db, array $sec, array $espec = []): array {
    $raw = json_decode($sec['conteudo'] ?? '{}', true);
    $rows = $raw['rows'] ?? [];
    $tipoId = $raw['tipo_id'] ?? '';
    $tipoSlug = $raw['tipo_slug'] ?? '';
    $colWidths = $raw['colWidths'] ?? [];
    $colunas = [];
    $tipoNome = $sec['titulo'] ?? 'Parâmetros';
    $legenda = '';
    $legendaTam = 9;
    $orientacao = $raw['orientacao'] ?? 'horizontal';

    if ($tipoId) {
        $stmt = $db->prepare('SELECT nome, colunas, legenda, legenda_tamanho FROM parametros_tipos WHERE id = ?');
        $stmt->execute([(int)$tipoId]);
        $ptRow = $stmt->fetch();
        if ($ptRow) {
            $colunas = json_decode($ptRow['colunas'], true) ?: [];
            $tipoNome = $ptRow['nome'];
            $legenda = $ptRow['legenda'] ?? '';
            $legendaTam = (int)($ptRow['legenda_tamanho'] ?? 9);
        }
    }

    if (!empty($espec['legenda_parametros'])) { $legenda = $espec['legenda_parametros']; }
    if (!empty($espec['legenda_parametros_tamanho'])) { $legendaTam = (int)$espec['legenda_parametros_tamanho']; }

    if (empty($colunas) && !empty($rows)) {
        $firstDataRow = null;
        foreach ($rows as $pr) { if (!isset($pr['_cat'])) { $firstDataRow = $pr; break; } }
        if ($firstDataRow) {
            foreach (array_keys($firstDataRow) as $k) {
                if ($k !== '_cat') $colunas[] = ['nome' => ucfirst($k), 'chave' => $k];
            }
        }
    }

    return [
        'raw' => $raw, 'rows' => $rows, 'tipo_id' => $tipoId, 'tipo_slug' => $tipoSlug,
        'tipo_nome' => $tipoNome, 'colunas' => $colunas, 'colWidths' => $colWidths,
        'legenda' => $legenda, 'legenda_tamanho' => $legendaTam, 'orientacao' => $orientacao,
    ];
}
