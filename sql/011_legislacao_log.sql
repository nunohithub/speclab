-- Histórico de alterações à legislação
CREATE TABLE IF NOT EXISTS legislacao_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    legislacao_id INT NOT NULL,
    acao VARCHAR(50) NOT NULL COMMENT 'criada, corrigida, atualizada, desativada, eliminada',
    dados_anteriores TEXT COMMENT 'JSON snapshot antes da alteração',
    dados_novos TEXT COMMENT 'JSON snapshot depois da alteração',
    notas TEXT COMMENT 'Motivo ou notas da IA',
    alterado_por INT NOT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
