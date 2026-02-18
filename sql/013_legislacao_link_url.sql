-- 013: Adicionar coluna link_url à tabela legislacao_banco
ALTER TABLE legislacao_banco ADD COLUMN link_url VARCHAR(500) DEFAULT NULL AFTER resumo;

-- Inserir URLs para cada norma existente
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A32004R1935' WHERE id = 1;
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A32006R2023' WHERE id = 2;
UPDATE legislacao_banco SET link_url = 'https://rm.coe.int/16805db887' WHERE id = 3;
UPDATE legislacao_banco SET link_url = 'https://rm.coe.int/09000016809fe04a' WHERE id = 4;
UPDATE legislacao_banco SET link_url = '/uploads/legislacao/edqm_cork_2025.pdf' WHERE id = 5;
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A32011R0010' WHERE id = 6;
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=CELEX%3A32024R3190' WHERE id = 7;
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A32006R1907' WHERE id = 8;
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A32008R1272' WHERE id = 9;
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A32012R0528' WHERE id = 10;
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A32008R1333' WHERE id = 11;
UPDATE legislacao_banco SET link_url = 'https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A31994L0062' WHERE id = 12;
UPDATE legislacao_banco SET link_url = 'https://rm.coe.int/16805db8c5' WHERE id = 13;
