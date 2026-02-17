-- 007: Tabela de planos com valores padrão configuráveis pelo super_admin
-- Os planos definem limites por omissão para cada organização

CREATE TABLE IF NOT EXISTS planos (
    id VARCHAR(50) PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    max_utilizadores INT NOT NULL DEFAULT 5,
    max_especificacoes INT DEFAULT NULL,
    tem_clientes TINYINT(1) NOT NULL DEFAULT 0,
    tem_fornecedores TINYINT(1) NOT NULL DEFAULT 1,
    preco_mensal DECIMAL(10,2) DEFAULT NULL,
    descricao TEXT DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserir planos padrão
INSERT INTO planos (id, nome, max_utilizadores, max_especificacoes, tem_clientes, tem_fornecedores, descricao, ordem) VALUES
    ('basico', 'Básico', 3, 50, 0, 1, 'Ideal para pequenas empresas. Inclui gestão de fornecedores.', 1),
    ('profissional', 'Profissional', 10, 500, 1, 1, 'Para empresas em crescimento. Inclui clientes e fornecedores.', 2),
    ('enterprise', 'Enterprise', 999, NULL, 1, 1, 'Sem limites. Todas as funcionalidades incluídas.', 3)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);
