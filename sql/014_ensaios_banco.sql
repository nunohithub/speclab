-- 014: Criar tabela ensaios_banco (mover do hardcoded para BD)
CREATE TABLE IF NOT EXISTS ensaios_banco (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categoria VARCHAR(100) NOT NULL,
    ensaio VARCHAR(200) NOT NULL,
    metodo VARCHAR(200) DEFAULT '',
    exemplo VARCHAR(200) DEFAULT '',
    ativo TINYINT(1) DEFAULT 1,
    ordem INT DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO ensaios_banco (categoria, ensaio, metodo, exemplo, ordem) VALUES
('Físico-Mecânico', 'Comprimento', 'ISO 9727-1', '±0.7 mm', 1),
('Físico-Mecânico', 'Diâmetro', 'ISO 9727-1', '±0.5 mm', 2),
('Físico-Mecânico', 'Ovalidade', 'ISO 9727-1', '≤0.5 mm', 3),
('Físico-Mecânico', 'Massa volúmica aparente', 'ISO 9727-3', '130-270 kg/m³', 4),
('Físico-Mecânico', 'Humidade', 'ISO 9727-3', '5-8%', 5),
('Físico-Mecânico', 'Força de extração', 'ISO 9727-5', '15-40 daN', 6),
('Físico-Mecânico', 'Estanquicidade líquida', 'ISO 9727-6', '100% ≥1.5 bar', 7),
('Físico-Mecânico', 'Recuperação elástica', 'ISO 9727-7', '≥96%', 8),
('Físico-Mecânico', 'Capilaridade', 'Método interno', '0 mm', 9),
('Químico', 'Resíduos de peróxidos', 'Método interno', '≤0.2 mg/rolha', 10),
('Químico', 'Resíduos sólidos totais', 'Método interno', '≤1.0 mg/rolha', 11),
('Químico', 'Absorção', 'Método interno', '≤10-40%', 12),
('Microbiologia', 'Bactérias totais', 'NP ISO 10718', '≤4 UFC', 13),
('Microbiologia', 'Leveduras', 'NP ISO 10718', '≤4 UFC', 14),
('Microbiologia', 'Fungos', 'NP ISO 10718', '≤4 UFC', 15),
('Sensorial', 'Análise de odor', 'ISO 22308', 'i≤1', 16),
('Sensorial', '2,4,6-TCA', 'ISO 20752', '≤0.5-1.5 ng/L', 17),
('Sensorial', 'Aromas desagradáveis', 'ISO 22308', '<1%', 18),
('Cromatografia', 'GC-SPME (compostos voláteis)', 'ISO 20752', 'Ver limite', 19),
('Cromatografia', 'Geosmina', 'Método interno', 'Deteção', 20),
('Cromatografia', 'Guaiacol', 'Método interno', 'Deteção', 21),
('Cromatografia', '2-MIB', 'Método interno', 'Deteção', 22),
('Visual', 'Classe visual', 'ISO 16419', 'Extra a 4º', 23),
('Visual', 'Defeitos (%)', 'ISO 16419', '6-12%', 24);
