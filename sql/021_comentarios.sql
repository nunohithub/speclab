-- 021: Comentários em especificações

CREATE TABLE IF NOT EXISTS especificacao_comentarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    utilizador_id INT NOT NULL,
    comentario TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_spec (especificacao_id),
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (utilizador_id) REFERENCES utilizadores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
