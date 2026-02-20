-- 022: Partilha interna de rascunhos

ALTER TABLE especificacoes
    ADD COLUMN partilha_interna TINYINT(1) NOT NULL DEFAULT 0 AFTER codigo_acesso;
