-- 017: Fluxo de aprovação interna
-- Adiciona estado em_revisao e campos de aprovação

-- 1. Novo estado em_revisao
ALTER TABLE especificacoes
    MODIFY COLUMN estado ENUM('rascunho','em_revisao','ativo','obsoleto') NOT NULL DEFAULT 'rascunho';

-- 2. Campos de aprovação
ALTER TABLE especificacoes
    ADD COLUMN aprovado_por INT DEFAULT NULL AFTER publicado_em,
    ADD COLUMN aprovado_em DATETIME DEFAULT NULL AFTER aprovado_por,
    ADD COLUMN motivo_devolucao TEXT DEFAULT NULL AFTER aprovado_em,
    ADD FOREIGN KEY (aprovado_por) REFERENCES utilizadores(id) ON DELETE SET NULL;
