-- 008: Suporte a múltiplos produtos e fornecedores por especificação
-- Cria tabelas intermédias (junction tables) para relação muitos-para-muitos

-- Tabela: especificação ↔ produtos
CREATE TABLE IF NOT EXISTS especificacao_produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    produto_id INT NOT NULL,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
    UNIQUE KEY uk_espec_prod (especificacao_id, produto_id),
    INDEX idx_espec (especificacao_id),
    INDEX idx_prod (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela: especificação ↔ fornecedores
CREATE TABLE IF NOT EXISTS especificacao_fornecedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    fornecedor_id INT NOT NULL,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE CASCADE,
    UNIQUE KEY uk_espec_forn (especificacao_id, fornecedor_id),
    INDEX idx_espec (especificacao_id),
    INDEX idx_forn (fornecedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrar dados existentes: copiar produto_id e fornecedor_id para as novas tabelas
INSERT IGNORE INTO especificacao_produtos (especificacao_id, produto_id)
SELECT id, produto_id FROM especificacoes WHERE produto_id IS NOT NULL;

INSERT IGNORE INTO especificacao_fornecedores (especificacao_id, fornecedor_id)
SELECT id, fornecedor_id FROM especificacoes WHERE fornecedor_id IS NOT NULL;
