-- =============================================
-- 015: Versionamento + Aceitação de Cliente
-- =============================================

-- 1. Novas colunas na tabela especificacoes
ALTER TABLE especificacoes
    ADD COLUMN versao_numero INT UNSIGNED NOT NULL DEFAULT 1 AFTER versao,
    ADD COLUMN versao_bloqueada TINYINT(1) NOT NULL DEFAULT 0 AFTER versao_numero,
    ADD COLUMN versao_pai_id INT DEFAULT NULL AFTER versao_bloqueada,
    ADD COLUMN grupo_versao VARCHAR(36) NOT NULL DEFAULT '' AFTER versao_pai_id,
    ADD COLUMN notas_versao TEXT DEFAULT NULL AFTER grupo_versao,
    ADD COLUMN publicado_por INT DEFAULT NULL AFTER notas_versao,
    ADD COLUMN publicado_em DATETIME DEFAULT NULL AFTER publicado_por;

ALTER TABLE especificacoes
    ADD INDEX idx_grupo_versao (grupo_versao),
    ADD INDEX idx_versao_bloqueada (versao_bloqueada);

-- 2. Backfill: cada spec existente é o seu próprio grupo (versão 1)
UPDATE especificacoes SET grupo_versao = UUID() WHERE grupo_versao = '';
UPDATE especificacoes SET versao_numero = 1;
-- Specs já 'ativo' ficam bloqueadas
UPDATE especificacoes
    SET versao_bloqueada = 1, publicado_em = updated_at
    WHERE estado = 'ativo';

-- 3. Tabela de tokens individuais por destinatário
CREATE TABLE IF NOT EXISTS especificacao_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    tipo_destinatario ENUM('cliente', 'fornecedor', 'outro') NOT NULL DEFAULT 'outro',
    destinatario_nome VARCHAR(200) DEFAULT NULL,
    destinatario_email VARCHAR(200) DEFAULT NULL,
    permissao ENUM('ver', 'ver_aceitar') NOT NULL DEFAULT 'ver_aceitar',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    expira_em DATETIME DEFAULT NULL,
    primeiro_acesso DATETIME DEFAULT NULL,
    ultimo_acesso DATETIME DEFAULT NULL,
    total_acessos INT NOT NULL DEFAULT 0,
    enviado_em DATETIME DEFAULT NULL,
    criado_por INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (criado_por) REFERENCES utilizadores(id) ON DELETE CASCADE,
    INDEX idx_espec (especificacao_id),
    INDEX idx_email (destinatario_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tabela de aceitações/rejeições
CREATE TABLE IF NOT EXISTS especificacao_aceitacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    token_id INT DEFAULT NULL,
    tipo_decisao ENUM('aceite', 'rejeitado') NOT NULL,
    comentario TEXT DEFAULT NULL,
    nome_signatario VARCHAR(200) DEFAULT NULL,
    cargo_signatario VARCHAR(200) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (token_id) REFERENCES especificacao_tokens(id) ON DELETE SET NULL,
    INDEX idx_espec (especificacao_id),
    INDEX idx_token (token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Log de alterações entre versões
CREATE TABLE IF NOT EXISTS especificacao_alteracoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    versao_anterior_id INT NOT NULL,
    tipo_alteracao ENUM('campo','seccao','parametro','classe','defeito','ficheiro','produto','fornecedor') NOT NULL,
    operacao ENUM('adicionado', 'modificado', 'removido') NOT NULL DEFAULT 'modificado',
    campo VARCHAR(100) DEFAULT NULL,
    valor_anterior TEXT DEFAULT NULL,
    valor_novo TEXT DEFAULT NULL,
    resumo VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (versao_anterior_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Log de acessos por token
CREATE TABLE IF NOT EXISTS especificacao_token_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_id INT NOT NULL,
    especificacao_id INT NOT NULL,
    acao ENUM('view', 'download_pdf', 'aceitar', 'rejeitar') NOT NULL DEFAULT 'view',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (token_id) REFERENCES especificacao_tokens(id) ON DELETE CASCADE,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    INDEX idx_token (token_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
