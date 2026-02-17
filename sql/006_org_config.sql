-- 006: Configuração de módulos por organização
-- tem_clientes: organização gere os seus próprios clientes
-- tem_fornecedores: organização gere fornecedores (default: sim)

ALTER TABLE organizacoes
    ADD COLUMN tem_clientes TINYINT(1) NOT NULL DEFAULT 0 AFTER numeracao_prefixo,
    ADD COLUMN tem_fornecedores TINYINT(1) NOT NULL DEFAULT 1 AFTER tem_clientes;
