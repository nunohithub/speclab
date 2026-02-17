-- Adicionar coluna legislacao_json à tabela especificacoes
ALTER TABLE especificacoes ADD COLUMN legislacao_json LONGTEXT DEFAULT NULL AFTER config_visual;
