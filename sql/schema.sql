-- ============================================
-- EXI LAB - Cadernos de Encargo e Especificações
-- Schema MySQL para cPanel/phpMyAdmin
-- ============================================

-- Utilizadores
CREATE TABLE IF NOT EXISTS utilizadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    username VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin por defeito (password: exi2026)
INSERT INTO utilizadores (nome, username, password, role) VALUES
('Administrador', 'admin', '$2y$10$placeholder', 'admin');

-- Clientes
CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    sigla VARCHAR(20) NOT NULL,
    morada VARCHAR(300) DEFAULT NULL,
    telefone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    nif VARCHAR(20) DEFAULT NULL,
    contacto VARCHAR(200) DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Produtos (tipos de rolha/produto)
CREATE TABLE IF NOT EXISTS produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    tipo VARCHAR(100) DEFAULT NULL,
    descricao TEXT DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Produtos por defeito
INSERT INTO produtos (nome, tipo, descricao) VALUES
('Rolha Natural', 'Natural', 'Rolha de cortiça natural cilíndrica'),
('Rolha Colmatada', 'Colmatada', 'Rolha de cortiça natural com colmatagem'),
('Rolha Micro-Aglomerada', 'Micro', 'Rolha de cortiça micro-aglomerada'),
('Rolha Aglomerada', 'Aglomerada', 'Rolha de cortiça aglomerada'),
('Rolha Bartop', 'Bartop', 'Rolha de cortiça com cápsula'),
('Rolha Capsulada', 'Capsulada', 'Rolha com cápsula plástica ou madeira'),
('Rolha 1+1', '1+1', 'Rolha técnica com discos de cortiça natural'),
('Rolha Champanhe', 'Champanhe', 'Rolha aglomerada com discos para espumantes');

-- Especificações (Cadernos de Encargo)
CREATE TABLE IF NOT EXISTS especificacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(50) NOT NULL UNIQUE,
    titulo VARCHAR(300) NOT NULL,
    produto_id INT DEFAULT NULL,
    cliente_id INT DEFAULT NULL,
    versao VARCHAR(20) NOT NULL DEFAULT '1.0',
    data_emissao DATE NOT NULL,
    data_revisao DATE DEFAULT NULL,
    data_validade DATE DEFAULT NULL,
    estado ENUM('rascunho', 'ativo', 'obsoleto') NOT NULL DEFAULT 'rascunho',

    -- Conteúdo principal (secções de texto)
    objetivo TEXT DEFAULT NULL,
    ambito TEXT DEFAULT NULL,
    definicao_material TEXT DEFAULT NULL,
    regulamentacao TEXT DEFAULT NULL,
    processos TEXT DEFAULT NULL,
    embalagem TEXT DEFAULT NULL,
    aceitacao TEXT DEFAULT NULL,
    arquivo_texto TEXT DEFAULT NULL,
    indemnizacao TEXT DEFAULT NULL,
    observacoes TEXT DEFAULT NULL,

    -- Acesso público
    password_acesso VARCHAR(255) DEFAULT NULL,
    codigo_acesso VARCHAR(20) DEFAULT NULL,

    -- Metadata
    criado_por INT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (produto_id) REFERENCES produtos(id),
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    FOREIGN KEY (criado_por) REFERENCES utilizadores(id),
    INDEX idx_estado (estado),
    INDEX idx_produto (produto_id),
    INDEX idx_cliente (cliente_id),
    INDEX idx_codigo (codigo_acesso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Secções personalizadas
CREATE TABLE IF NOT EXISTS especificacao_seccoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    conteudo TEXT DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parâmetros / Características técnicas
CREATE TABLE IF NOT EXISTS especificacao_parametros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    categoria VARCHAR(100) NOT NULL,
    ensaio VARCHAR(200) NOT NULL,
    especificacao_valor VARCHAR(200) DEFAULT NULL,
    metodo VARCHAR(100) DEFAULT NULL,
    amostra_nqa VARCHAR(100) DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Classes visuais
CREATE TABLE IF NOT EXISTS especificacao_classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    classe VARCHAR(50) NOT NULL,
    defeitos_max DECIMAL(5,2) DEFAULT NULL,
    descricao VARCHAR(200) DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Defeitos
CREATE TABLE IF NOT EXISTS especificacao_defeitos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    nome VARCHAR(100) NOT NULL,
    tipo ENUM('critico', 'maior', 'menor') NOT NULL DEFAULT 'maior',
    descricao TEXT DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ficheiros anexos
CREATE TABLE IF NOT EXISTS especificacao_ficheiros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    nome_original VARCHAR(255) NOT NULL,
    nome_servidor VARCHAR(255) NOT NULL,
    tipo_ficheiro VARCHAR(100) DEFAULT NULL,
    tamanho INT DEFAULT 0,
    descricao VARCHAR(300) DEFAULT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log de acessos públicos
CREATE TABLE IF NOT EXISTS acessos_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    especificacao_id INT NOT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    tipo ENUM('view', 'download', 'pdf') NOT NULL DEFAULT 'view',
    accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (especificacao_id) REFERENCES especificacoes(id) ON DELETE CASCADE,
    INDEX idx_espec (especificacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações
CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(100) PRIMARY KEY,
    valor TEXT,
    descricao VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações por defeito
INSERT INTO configuracoes (chave, valor, descricao) VALUES
('empresa_nome', 'EXI LAB', 'Nome da empresa'),
('empresa_morada', '', 'Morada da empresa'),
('empresa_telefone', '', 'Telefone da empresa'),
('empresa_email', '', 'Email da empresa'),
('empresa_nif', '', 'NIF da empresa'),
('numeracao_prefixo', 'CE', 'Prefixo da numeração (CE = Caderno de Encargos)'),
('numeracao_ano', '2026', 'Ano corrente para numeração');
