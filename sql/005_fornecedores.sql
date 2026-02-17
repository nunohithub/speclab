-- ============================================
-- EXI LAB - Cadernos de Encargo
-- Migration 005: Fornecedores por Organização
-- ============================================

-- 1. Tabela de fornecedores (por organização)
CREATE TABLE IF NOT EXISTS fornecedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    sigla VARCHAR(20) DEFAULT NULL,
    morada VARCHAR(300) DEFAULT NULL,
    telefone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    nif VARCHAR(20) DEFAULT NULL,
    contacto VARCHAR(200) DEFAULT NULL,
    organizacao_id INT DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organizacao_id) REFERENCES organizacoes(id) ON DELETE SET NULL,
    INDEX idx_org (organizacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Adicionar fornecedor_id às especificações (opcional - NULL = todos os fornecedores)
ALTER TABLE especificacoes
    ADD COLUMN fornecedor_id INT DEFAULT NULL AFTER cliente_id,
    ADD FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE SET NULL;

ALTER TABLE especificacoes ADD INDEX idx_fornecedor (fornecedor_id);
