-- 020: Campo de idioma na especificação

ALTER TABLE especificacoes
    ADD COLUMN idioma VARCHAR(5) NOT NULL DEFAULT 'pt' AFTER titulo;
