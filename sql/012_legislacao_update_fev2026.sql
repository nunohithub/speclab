-- Atualização da legislação com dados corrigidos e detalhados — fevereiro 2026
-- Fonte: Mapeamento por Tipo de Rolha (Natural, Colmatada, Micro, Champanhe, Bartop plást., Bartop mad.)

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ Micro: ✓ Champanhe: ✓ Bartop plást.: ✓ Bartop mad.: ✓ — Regulamento-quadro, aplica-se a todas sem exceção.',
    resumo = 'Quadro geral de materiais em contacto com alimentos. Obriga à inércia (não contaminar, não alterar composição nem características sensoriais), rastreabilidade (Art. 17) e responsabilidade ao longo da cadeia.'
WHERE id = 1;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ Micro: ✓ Champanhe: ✓ Bartop plást.: ✓ Bartop mad.: ✓ — Todas, obrigatório para qualquer fabricante de FCM.',
    resumo = 'Obriga a sistema documentado de Boas Práticas de Fabrico: controlo de matérias-primas, procedimentos escritos, registos, gestão de não conformidades e validações.'
WHERE id = 2;

UPDATE legislacao_banco SET
    legislacao_norma = 'ResAP(2004)2 (CoE – Cortiça) — SUBSTITUÍDA pelo Guia EDQM (2025)',
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ (parte cortiça) Micro: ✓ (parte cortiça) Champanhe: ✓ (corpo + discos) Bartop plást.: ✓ (só corpo cortiça) Bartop mad.: ✓ (só corpo cortiça) — Aplica-se apenas à parte de cortiça. Cabeças excluídas.',
    resumo = 'Referência técnica para artigos de cortiça em contacto com alimentos: pureza, contaminantes, segurança. Já não vigente — substituída pelo Guia Técnico EDQM (set. 2025).'
WHERE id = 3;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ Micro: ✓ Champanhe: ✓ Bartop plást.: ✓ Bartop mad.: ✓ — Todas, enquadramento geral do CoE para todos os FCM.',
    resumo = 'Enquadramento atualizado do Conselho da Europa para FCM; estrutura técnica de avaliação de segurança e suporte a guias específicos (incluindo o novo guia de cortiça).'
WHERE id = 4;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ (parte cortiça) Micro: ✓ (parte cortiça) Champanhe: ✓ (corpo + discos) Bartop plást.: ✓ (só corpo) Bartop mad.: ✓ (só corpo cortiça) — Exclui componentes não-cortiça.',
    resumo = 'Ensaios, critérios de avaliação, controlo de substâncias, abordagem harmonizada. Lista de substâncias avaliadas. Substitui a ResAP(2004)2 e o Policy Statement de 2007.'
WHERE id = 5;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✗ Colmatada: Depende* Micro: ✓ (ligante PU é polimérico) Champanhe: ✓ (ligante PU + colas) Bartop plást.: ✓ (cabeça plástica + cola) Bartop mad.: Depende* — *Colmatada: só se ligante/revestimento for plástico. *Bartop mad.: aplica-se se cola contiver componentes poliméricos.',
    resumo = 'Lista positiva de substâncias autorizadas, limites de migração específica (SML) e global (OML), condições de ensaio e declaração de conformidade para materiais plásticos.'
WHERE id = 6;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✗ (salvo se trat. superfície c/ bisfenóis) Colmatada: ✓ (ligantes/revestimentos podem conter bisfenóis) Micro: ✓ (ligante PU pode derivar de BADGE/BPA) Champanhe: ✓ (colas + ligante PU) Bartop plást.: ✓ (plástico + adesivos) Bartop mad.: ✓ (adesivos) — Âmbito: plásticos, vernizes, revestimentos, tintas, adesivos, resinas, silicones e borrachas.',
    resumo = 'Proíbe BPA e impõe limites para outros bisfenóis perigosos em FCM; obriga a reformulação. Em vigor desde 20/01/2025.'
WHERE id = 7;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ (produtos de lavagem/tratamento) Colmatada: ✓ (ligantes, revestimentos, produtos químicos) Micro: ✓ (ligante PU, aditivos) Champanhe: ✓ (colas, ligante PU) Bartop plást.: ✓ (plástico + colas) Bartop mad.: ✓ (colas, tratamento madeira) — Todas usam substâncias químicas, REACH aplicável a todas.',
    resumo = 'Registo e comunicação de substâncias químicas; gestão de SVHC; obrigações na cadeia de fornecimento e manutenção de Fichas de Dados de Segurança.'
WHERE id = 8;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ Micro: ✓ Champanhe: ✓ Bartop plást.: ✓ Bartop mad.: ✓ — Todas utilizam produtos químicos no fabrico (lavagem, tratamentos, colas, ligantes, etc.).',
    resumo = 'Classificação, rotulagem e gestão segura de substâncias/misturas químicas usadas no fabrico.'
WHERE id = 9;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ Micro: ✓ Champanhe: ✓ Bartop plást.: ✓ Bartop mad.: ✓ — Todas, processos de desinfeção/lavagem utilizam tipicamente biocidas (ex.: peróxido de hidrogénio, ácido peracético).',
    resumo = 'Regula autorização, colocação no mercado e utilização de produtos biocidas; exige cumprimento das condições de uso e registos.'
WHERE id = 10;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✗ Colmatada: ✓ (E153 — carvão vegetal na colmatação) Micro: Possível (se usar aditivos de dupla utilização) Champanhe: Possível Bartop plást.: Possível Bartop mad.: ✗ — Relevante sobretudo para colmatadas (E153). Noutros tipos, só com substâncias de duplo estatuto.',
    resumo = 'Lista de aditivos alimentares autorizados e respetivas condições; relevante quando uma substância usada no material tem também estatuto de aditivo alimentar.'
WHERE id = 11;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ Micro: ✓ Champanhe: ✓ Bartop plást.: ✓ Bartop mad.: ✓ — Todas, a rolha é componente de embalagem.',
    resumo = 'Limita o teor total de metais pesados em embalagens (<100 ppm) e estabelece requisitos ambientais.'
WHERE id = 12;

UPDATE legislacao_banco SET
    rolhas_aplicaveis = 'Natural: ✓ Colmatada: ✓ Micro: ✓ Champanhe: ✓ Bartop plást.: ✓ Bartop mad.: ✗ (tipicamente) — A maioria das rolhas recebe tratamento de superfície com silicone para lubrificação. Bartop madeira pode não ter.',
    resumo = 'Define requisitos de segurança e critérios para silicones utilizados em contacto com alimentos.'
WHERE id = 13;
