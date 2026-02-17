-- ============================================
-- EXI LAB - Cadernos de Encargo
-- Migration 004: Sistema Multi-Tenant (Organizações)
-- ============================================

-- 1. Tabela de organizações
CREATE TABLE IF NOT EXISTS organizacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    logo VARCHAR(255) DEFAULT NULL,
    cor_primaria VARCHAR(7) NOT NULL DEFAULT '#2596be',
    cor_primaria_dark VARCHAR(7) NOT NULL DEFAULT '#1a7a9e',
    cor_primaria_light VARCHAR(7) NOT NULL DEFAULT '#e6f4f9',
    nif VARCHAR(20) DEFAULT NULL,
    morada VARCHAR(300) DEFAULT NULL,
    telefone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    numeracao_prefixo VARCHAR(10) NOT NULL DEFAULT 'CE',
    smtp_host VARCHAR(255) DEFAULT NULL,
    smtp_port INT DEFAULT NULL,
    smtp_user VARCHAR(255) DEFAULT NULL,
    smtp_pass VARCHAR(255) DEFAULT NULL,
    smtp_from VARCHAR(255) DEFAULT NULL,
    smtp_from_name VARCHAR(255) DEFAULT NULL,
    email_assinatura TEXT DEFAULT NULL,
    plano ENUM('basico', 'profissional', 'enterprise') NOT NULL DEFAULT 'basico',
    max_utilizadores INT NOT NULL DEFAULT 5,
    max_especificacoes INT DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Inserir organização default (EXI LAB)
INSERT INTO organizacoes (id, nome, slug, cor_primaria, cor_primaria_dark, cor_primaria_light, numeracao_prefixo, ativo)
VALUES (1, 'EXI LAB', 'exi-lab', '#2596be', '#1a7a9e', '#e6f4f9', 'CE', 1);

-- 3. Alterar roles dos utilizadores e adicionar organizacao_id
-- Primeiro: adicionar novos roles mantendo 'admin' temporariamente
ALTER TABLE utilizadores
    MODIFY COLUMN role ENUM('admin', 'super_admin', 'org_admin', 'user') NOT NULL DEFAULT 'user';

-- 4. Migrar utilizadores existentes: admin -> super_admin
UPDATE utilizadores SET role = 'super_admin' WHERE role = 'admin';

-- 5. Remover 'admin' do ENUM e adicionar organizacao_id
ALTER TABLE utilizadores
    MODIFY COLUMN role ENUM('super_admin', 'org_admin', 'user') NOT NULL DEFAULT 'user',
    ADD COLUMN organizacao_id INT DEFAULT NULL AFTER assinatura,
    ADD FOREIGN KEY (organizacao_id) REFERENCES organizacoes(id) ON DELETE SET NULL;

-- 6. Atribuir todos os utilizadores à org default
UPDATE utilizadores SET organizacao_id = 1 WHERE organizacao_id IS NULL;

-- 5. Adicionar organizacao_id às especificações
ALTER TABLE especificacoes
    ADD COLUMN organizacao_id INT DEFAULT NULL AFTER criado_por,
    ADD FOREIGN KEY (organizacao_id) REFERENCES organizacoes(id) ON DELETE SET NULL;

UPDATE especificacoes SET organizacao_id = 1 WHERE organizacao_id IS NULL;

-- 6. Adicionar organizacao_id aos clientes
ALTER TABLE clientes
    ADD COLUMN organizacao_id INT DEFAULT NULL AFTER ativo,
    ADD FOREIGN KEY (organizacao_id) REFERENCES organizacoes(id) ON DELETE SET NULL;

UPDATE clientes SET organizacao_id = 1 WHERE organizacao_id IS NULL;

-- 7. Adicionar organizacao_id aos produtos (NULL = global/partilhado)
ALTER TABLE produtos
    ADD COLUMN organizacao_id INT DEFAULT NULL AFTER ativo,
    ADD FOREIGN KEY (organizacao_id) REFERENCES organizacoes(id) ON DELETE SET NULL;
-- Produtos existentes ficam NULL (globais, visíveis por todas as orgs)

-- 8. Adicionar organizacao_id aos templates de parâmetros
ALTER TABLE produto_parametros_template
    ADD COLUMN organizacao_id INT DEFAULT NULL,
    ADD FOREIGN KEY (organizacao_id) REFERENCES organizacoes(id) ON DELETE SET NULL;

-- 9. Ajustar UNIQUE do número: permitir mesmo número em orgs diferentes
ALTER TABLE especificacoes DROP INDEX numero;
ALTER TABLE especificacoes ADD UNIQUE INDEX idx_numero_org (numero, organizacao_id);

-- 10. Índices para performance
ALTER TABLE utilizadores ADD INDEX idx_org (organizacao_id);
ALTER TABLE especificacoes ADD INDEX idx_org (organizacao_id);
ALTER TABLE clientes ADD INDEX idx_org (organizacao_id);
ALTER TABLE produtos ADD INDEX idx_org (organizacao_id);
