-- 016: Certificações de fornecedores + histórico de alterações
-- Adiciona campos de certificação e cria tabela de audit log

-- 1. Campos de certificação nos fornecedores
ALTER TABLE fornecedores
    ADD COLUMN certificacoes VARCHAR(500) DEFAULT NULL AFTER contacto,
    ADD COLUMN notas TEXT DEFAULT NULL AFTER certificacoes,
    ADD COLUMN updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- 2. Tabela de audit log de fornecedores
CREATE TABLE IF NOT EXISTS fornecedores_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fornecedor_id INT NOT NULL,
    acao ENUM('criado', 'atualizado', 'desativado', 'reativado') NOT NULL,
    campos_alterados TEXT DEFAULT NULL,
    dados_anteriores JSON DEFAULT NULL,
    dados_novos JSON DEFAULT NULL,
    alterado_por INT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fornecedor (fornecedor_id),
    INDEX idx_data (created_at),
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE CASCADE,
    FOREIGN KEY (alterado_por) REFERENCES utilizadores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
