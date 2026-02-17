-- Migration 003: Adicionar config_visual às especificações
-- Permite personalizar cores, tamanho de títulos e logo por especificação

ALTER TABLE especificacoes ADD COLUMN config_visual JSON DEFAULT NULL AFTER observacoes;
