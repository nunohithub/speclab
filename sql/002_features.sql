-- ============================================
-- EXI LAB - Cadernos de Encargo
-- Migration 002: Funcionalidades adicionais
-- ============================================

-- 0. Fix produto_id nullable + add data_validade
ALTER TABLE especificacoes MODIFY COLUMN produto_id INT DEFAULT NULL;
ALTER TABLE especificacoes ADD COLUMN data_validade DATE DEFAULT NULL AFTER data_revisao;

-- 1. Tipo de documento (Ficha Técnica vs Caderno Completo)
ALTER TABLE especificacoes
    ADD COLUMN tipo_doc ENUM('caderno', 'ficha_tecnica') NOT NULL DEFAULT 'caderno' AFTER titulo;

-- 2. Template PDF (ficheiro background)
ALTER TABLE especificacoes
    ADD COLUMN template_pdf VARCHAR(255) DEFAULT NULL AFTER observacoes;

-- 3. Assinatura e proteção PDF
ALTER TABLE especificacoes
    ADD COLUMN assinatura_nome VARCHAR(200) DEFAULT NULL AFTER template_pdf,
    ADD COLUMN pdf_protegido TINYINT(1) NOT NULL DEFAULT 0 AFTER assinatura_nome;

-- 4. Fichas técnicas dentro de cadernos
CREATE TABLE IF NOT EXISTS especificacao_fichas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    produto_id INT DEFAULT NULL,
    dados_json JSON DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE SET NULL,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Templates de parâmetros por produto
CREATE TABLE IF NOT EXISTS produto_parametros_template (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    categoria VARCHAR(100) NOT NULL,
    ensaio VARCHAR(200) NOT NULL,
    especificacao_valor VARCHAR(200) DEFAULT NULL,
    metodo VARCHAR(100) DEFAULT NULL,
    amostra_nqa VARCHAR(100) DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
    INDEX idx_produto (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Galeria de fotos (associar fotos a especificações)
ALTER TABLE especificacao_ficheiros
    ADD COLUMN is_foto TINYINT(1) NOT NULL DEFAULT 0 AFTER descricao,
    ADD COLUMN incluir_pdf TINYINT(1) NOT NULL DEFAULT 0 AFTER is_foto,
    ADD COLUMN pagina_pdf INT DEFAULT NULL AFTER incluir_pdf,
    ADD COLUMN legenda VARCHAR(300) DEFAULT NULL AFTER pagina_pdf;

-- 7. Email log
CREATE TABLE IF NOT EXISTS email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    destinatario VARCHAR(300) NOT NULL,
    assunto VARCHAR(300) NOT NULL,
    tipo ENUM('manual', 'automatico') NOT NULL DEFAULT 'manual',
    estado ENUM('enviado', 'erro') NOT NULL DEFAULT 'enviado',
    erro_msg TEXT DEFAULT NULL,
    enviado_por INT DEFAULT NULL,
    enviado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (enviado_por) REFERENCES utilizadores(id) ON DELETE SET NULL,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Configurações de email
INSERT INTO configuracoes (chave, valor, descricao) VALUES
('smtp_host', '', 'Servidor SMTP'),
('smtp_port', '587', 'Porta SMTP'),
('smtp_user', '', 'Utilizador SMTP'),
('smtp_pass', '', 'Password SMTP'),
('smtp_from', '', 'Email remetente'),
('smtp_from_name', 'EXI LAB', 'Nome remetente'),
('email_assinatura', 'EXI LAB - Cadernos de Encargo e Especificações Técnicas', 'Assinatura do email')
ON DUPLICATE KEY UPDATE chave = chave;

-- 9. Assinaturas dos utilizadores
ALTER TABLE utilizadores
    ADD COLUMN assinatura VARCHAR(255) DEFAULT NULL AFTER role;
