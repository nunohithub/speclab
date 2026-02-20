-- 018: Adicionar campo unidade aos parâmetros e banco de ensaios

ALTER TABLE especificacao_parametros
    ADD COLUMN unidade VARCHAR(50) DEFAULT NULL AFTER amostra_nqa;

ALTER TABLE ensaios_banco
    ADD COLUMN unidade VARCHAR(50) DEFAULT NULL AFTER nqa;

-- Preencher unidades padrão no banco de ensaios
UPDATE ensaios_banco SET unidade = 'mm' WHERE ensaio IN ('Comprimento', 'Diâmetro', 'Ovalidade', 'Capilaridade');
UPDATE ensaios_banco SET unidade = 'kg/dm³' WHERE ensaio LIKE '%massa volúmica%';
UPDATE ensaios_banco SET unidade = '%' WHERE ensaio LIKE '%humidade%' OR ensaio LIKE '%peróxidos%';
UPDATE ensaios_banco SET unidade = 'daN' WHERE ensaio LIKE '%força%extração%';
UPDATE ensaios_banco SET unidade = 'mm' WHERE ensaio LIKE '%recuperação%';
