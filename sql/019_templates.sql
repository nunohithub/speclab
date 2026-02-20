-- 019: Templates de especificação

CREATE TABLE IF NOT EXISTS especificacao_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    descricao VARCHAR(500) DEFAULT NULL,
    organizacao_id INT DEFAULT NULL,
    dados JSON NOT NULL,
    criado_por INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (organizacao_id),
    FOREIGN KEY (organizacao_id) REFERENCES organizacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (criado_por) REFERENCES utilizadores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
