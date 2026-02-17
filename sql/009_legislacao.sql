-- Banco de legislação (gerido pelo superadmin)
CREATE TABLE IF NOT EXISTS legislacao_banco (
    id INT AUTO_INCREMENT PRIMARY KEY,
    legislacao_norma VARCHAR(255) NOT NULL,
    rolhas_aplicaveis TEXT,
    resumo TEXT,
    ativo TINYINT(1) DEFAULT 1,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed data
INSERT INTO legislacao_banco (legislacao_norma, rolhas_aplicaveis, resumo) VALUES
('Reg. (CE) 1935/2004', 'Todas: natural, colmatada, bartop (plástico/madeira), microaglomerada', 'Quadro geral de materiais em contacto com alimentos. Obriga à inércia (não contaminar, não alterar composição nem características sensoriais), rastreabilidade (Art. 17) e responsabilidade ao longo da cadeia.'),
('Reg. (CE) 2023/2006 (GMP)', 'Todas', 'Obriga a sistema documentado de Boas Práticas de Fabrico: controlo de matérias-primas, procedimentos escritos, registos, gestão de não conformidades e validações.'),
('ResAP(2004)2 (CoE – Cortiça)', 'Natural, colmatada, bartop (corpo em cortiça), microaglomerada', 'Referência técnica específica para artigos de cortiça em contacto com alimentos: requisitos de pureza, controlo de contaminantes e critérios gerais de segurança.'),
('CM/Res(2020)9 (CoE)', 'Todas', 'Enquadramento actualizado do Conselho da Europa para FCM; estrutura técnica de avaliação de segurança e suporte a guias específicos.'),
('Guia Técnico EDQM (2025)', 'Todas as rolhas com cortiça', 'Documento técnico de apoio: recomenda ensaios, critérios de avaliação, controlo de substâncias e abordagem harmonizada de conformidade.'),
('Reg. (UE) 10/2011 (Plásticos FCM)', 'Colmatada, microaglomerada, bartop com cabeça plástica (e qualquer rolha com componentes poliméricos)', 'Lista positiva de substâncias autorizadas, limites de migração específica (SML) e global (OML), condições de ensaio e requisitos de declaração de conformidade para materiais plásticos.'),
('Reg. (UE) 2024/3190 (Bisfenóis)', 'Apenas se existirem componentes plásticos/ligantes com risco de bisfenóis', 'Restringe/proíbe BPA e outros bisfenóis em FCM; impõe limites muito baixos e obriga a reformulação quando aplicável.'),
('Reg. (CE) 1907/2006 (REACH)', 'Todas, quando existam colas, revestimentos, tratamentos ou auxiliares químicos', 'Registo e comunicação de substâncias químicas; gestão de SVHC; obrigações na cadeia de fornecimento e manutenção de Fichas de Dados de Segurança.'),
('Reg. (CE) 1272/2008 (CLP)', 'Todas, se forem utilizados produtos químicos no processo', 'Classificação, rotulagem e gestão segura de substâncias/misturas químicas usadas no fabrico.'),
('Reg. (UE) 528/2012 (Biocidas)', 'Todas, se forem utilizados biocidas (desinfecção/tratamentos)', 'Regula autorização, colocação no mercado e utilização de produtos biocidas; exige cumprimento das condições de uso e registos.'),
('Reg. (CE) 1333/2008 (Aditivos)', 'Quando existam substâncias de dupla utilização (ex.: E153)', 'Lista de aditivos alimentares autorizados e respectivas condições; relevante quando uma substância usada no material tem também estatuto de aditivo alimentar.'),
('Directiva 94/62/CE (Embalagens)', 'Todas', 'Limita o teor total de metais pesados em embalagens (<100 ppm) e estabelece requisitos ambientais.'),
('ResAP(2004)5 (CoE – Silicones)', 'Rolhas com tratamento ou componentes em silicone', 'Define requisitos de segurança e critérios para silicones utilizados em contacto com alimentos.');
