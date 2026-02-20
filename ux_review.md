# Auditoria UX Completa — SpecLab

## Contexto de Avaliacao
Avaliado como ferramenta usada em **licitacoes publicas e especificacoes tecnicas industriais**.
Criterios: confianca, formalidade, consistencia e robustez documental.

---

## 1. PROBLEMAS IDENTIFICADOS

### ALTA prioridade

| # | Problema | Onde | Impacto |
|---|---------|------|---------|
| A1 | **Sem validacao de campos obrigatorios** — pode publicar spec sem titulo, sem produto, sem seccoes | especificacao.php | Documentos incompletos em contexto formal |
| A2 | **Sem pre-visualizacao do PDF** — utilizador nao sabe como fica antes de enviar | especificacao.php toolbar | Perda de confianca, retrabalho |
| A3 | **Estado "Em Revisao" sem explicacao** — utilizador nao sabe o que significa, quem revisa, quando acaba | toolbar + dashboard | Confusao no workflow |
| A4 | **Dois mecanismos de partilha confusos** — codigo de acesso vs token individual nao sao diferenciados na UI | tab Partilha | Utilizador nao sabe qual usar |
| A5 | **Tabela de ensaios complexa** — merge, resize, categorias sem instrucoes claras | tab Conteudo | Erros na tabela mais critica do documento |
| A6 | **PDF pode quebrar com tabelas complexas** — ensaios com merge cells podem ultrapassar margens | pdf.php | Documento final ilegivel |
| A7 | **IA pode gerar conteudo ficticio** — sem aviso de que o conteudo deve ser verificado | botoes Sugerir/Melhorar | Risco legal em documentos formais |

### MEDIA prioridade

| # | Problema | Onde | Impacto |
|---|---------|------|---------|
| M1 | **Sem checklist pre-publicacao** — nada impede publicar spec vazia | publicarVersaoUI() | Versoes oficiais incompletas |
| M2 | **Templates sem preview** — utilizador aplica template sem saber o que contem | selector template | Escolha cega |
| M3 | **Anexos sem preview** — ficheiros carregados nao mostram miniatura nem tamanho | tab Conteudo ficheiros | Dificil gerir anexos |
| M4 | **Email sem preview** — utilizador nao ve como fica o email antes de enviar | tab Partilha | Emails mal formatados |
| M5 | **Sem indicador de progresso do documento** — nao ha barra ou percentagem de completude | editor | Utilizador nao sabe o que falta |
| M6 | **Diferencas de layout web vs PDF** — fontes, espacamento, cores podem diferir | ver.php vs pdf.php | Expectativas nao correspondidas |
| M7 | **Aceitacao sem ver documento** — destinatario pode aceitar/rejeitar sem rever a spec | publico.php | Aceitacoes invalidas |
| M8 | **Sem reenvio de convites** — uma vez enviado, nao ha botao para reenviar | tab Partilha | Convites perdidos no email |

### BAIXA prioridade

| # | Problema | Onde | Impacto |
|---|---------|------|---------|
| B1 | **Sem undo/redo** nas seccoes de texto | editor TinyMCE | Perda acidental de conteudo |
| B2 | **Sem reordenacao de ficheiros** por drag | lista ficheiros | Ordem arbitraria nos anexos |
| B3 | **Sem atalhos de teclado documentados** | toda a app | Produtividade reduzida |
| B4 | **Sem dark mode** | CSS | Conforto visual |
| B5 | **Sem numeracao de pagina no conteudo** | ver.php | Referencia cruzada dificil |

---

## 2. SUGESTOES CONCRETAS

### Confianca Documental (para licitacoes)

1. **Selo de versao oficial** — quando uma spec e publicada, mostrar badge claro "Versao Oficial v2.0 — Publicada em 20/02/2026" com icone de cadeado
2. **Marca de agua RASCUNHO** — no PDF e na pagina web quando estado != ativo (ja parcialmente feito em publico.php, falta em ver.php e pdf.php)
3. **Assinatura digital visual** — mostrar bloco de assinatura com data/hora no rodape de todas as versoes publicadas
4. **Historico de alteracoes** — log visivel de quem alterou o que e quando
5. **Hash de integridade** — mostrar checksum do PDF para verificacao

### Fluxo de Criacao

6. **Wizard passo-a-passo para novos** — Dados Gerais > Conteudo > Ensaios > Classes > Partilha (com indicador de progresso)
7. **Campos obrigatorios visuais** — asterisco vermelho + validacao ao guardar
8. **Pre-publicacao checklist** — modal com lista: titulo ok, produto definido, pelo menos 1 seccao, etc.
9. **Template preview** — ao passar o rato sobre template, mostrar resumo do conteudo

### IA

10. **Aviso legal na IA** — "Conteudo gerado por IA. Revise antes de usar em documentos oficiais."
11. **Botao Aceitar/Rejeitar** — apos IA gerar, mostrar preview com botoes ao inves de substituir diretamente
12. **IA nos ensaios** — sugerir parametros padrao com base no tipo de produto/rolha

---

## 3. MELHORIAS RAPIDAS (1-2h cada)

| # | Melhoria | Esforco | Ficheiro |
|---|---------|---------|----------|
| R1 | Adicionar `required` visual nos campos titulo, produto | 30min | especificacao.php CSS |
| R2 | Aviso "Conteudo gerado por IA" em toast apos cada sugestao | 15min | especificacao.php JS |
| R3 | Marca de agua "RASCUNHO" no PDF | 1h | pdf.php |
| R4 | Mostrar tamanho dos ficheiros na lista de anexos | 30min | especificacao.php JS |
| R5 | Tooltip nos estados (Rascunho = "Em edicao, so voce ve") | 30min | especificacao.php + dashboard.php |
| R6 | Botao "Pre-visualizar PDF" que abre pdf.php em nova aba | 15min | ja existe como "PDF" na toolbar |
| R7 | Confirmar antes de apagar seccao/ficheiro | 15min | especificacao.php JS |
| R8 | Placeholder informativo nos campos de texto rico | 30min | especificacao.php TinyMCE config |

---

## 4. MELHORIAS ESTRUTURAIS (dias/semanas)

| # | Melhoria | Esforco | Impacto |
|---|---------|---------|---------|
| E1 | **Wizard de criacao** com stepper visual | 2-3 dias | Alto — guia o utilizador |
| E2 | **Sistema de validacao pre-publicacao** com checklist | 1-2 dias | Alto — impede documentos incompletos |
| E3 | **Preview inline do PDF** (iframe ou modal) | 1 dia | Alto — confianca no resultado final |
| E4 | **Refactor da tabela de ensaios** com UI mais clara (botoes +Linha, +Categoria visiveis) | 2-3 dias | Alto — tabela mais usada |
| E5 | **Log de auditoria** (quem editou, quando, o que mudou) | 2 dias | Alto para licitacoes |
| E6 | **Sistema de notificacoes** (email quando spec muda estado, quando aceitacao chega) | 2-3 dias | Medio — automatizacao |
| E7 | **Comparacao visual web vs PDF** lado a lado | 1-2 dias | Medio — consistencia |
| E8 | **Exportacao Word/DOCX** alem de PDF | 2-3 dias | Medio — compatibilidade |

---

## 5. TESTES REAIS (cenarios de utilizador)

### Teste 1: "Criar caderno de encargos completo para rolha natural"
**Passos:** Dashboard > Nova Especificacao > Preencher dados > Adicionar 3 seccoes (Objetivo, Especificacao, Ensaios) > Adicionar tabela de ensaios com 10 parametros > Adicionar 2 classes > Guardar > Gerar PDF
**O que verificar:**
- O PDF tem TODOS os dados preenchidos?
- As tabelas de ensaios estao corretamente formatadas?
- As cores/fontes sao consistentes entre web e PDF?
- O numero e versao estao corretos?

### Teste 2: "Traduzir caderno para ingles e enviar a fornecedor"
**Passos:** Abrir spec existente > Traduzir para EN > Verificar traducao > Tab Partilha > Adicionar destinatario > Enviar email > Verificar link publico
**O que verificar:**
- Todos os campos foram traduzidos (incluindo rotulos do PDF)?
- O email chegou com link funcional?
- O fornecedor consegue ver e aceitar?
- O PDF em ingles tem os rotulos corretos?

### Teste 3: "Publicar versao e criar nova revisao"
**Passos:** Abrir spec rascunho > Submeter para Revisao > Login como admin > Aprovar > Publicar > Criar Nova Versao > Editar > Publicar v2
**O que verificar:**
- O workflow de aprovacao funciona?
- A versao 1 fica bloqueada (nao editavel)?
- A versao 2 herda todo o conteudo?
- O historico de versoes mostra ambas?

### Teste 4: "Fornecedor recebe, revisa e aceita especificacao"
**Passos:** Receber email > Abrir link > Introduzir password (se aplicavel) > Ler documento > Preencher formulario de aceitacao > Assinar > Submeter
**O que verificar:**
- O documento e legivel e completo?
- O formulario de aceitacao e claro?
- A assinatura e guardada corretamente?
- O criador ve a aceitacao no painel?

### Teste 5: "Auditoria de especificacao existente num contexto de licitacao publica"
**Passos:** Abrir spec publicada > Verificar dados completos > Exportar PDF > Verificar conformidade > Comparar com versao anterior > Verificar aceitacoes
**O que verificar:**
- O PDF tem aspeto profissional e formal?
- A numeracao e datas estao corretas?
- Ha indicacao clara de quem aprovou e quando?
- O documento e auto-suficiente (nao precisa de contexto externo)?
- Ha rastreabilidade das alteracoes entre versoes?

---

## 6. PONTUACAO GERAL

| Criterio | Nota (1-10) | Comentario |
|----------|-------------|-----------|
| **Confianca documental** | 6/10 | Falta validacao pre-publicacao, marca de agua, log de auditoria |
| **Formalidade** | 7/10 | PDF profissional mas sem controlo de versao robusto |
| **Consistencia web/PDF** | 6/10 | Diferencas de fonte, espacamento e layout |
| **Robustez** | 5/10 | Sem validacoes, pode publicar documentos incompletos |
| **Facilidade de uso** | 6/10 | Funcionalidades ricas mas UI complexa (especialmente ensaios) |
| **IA** | 7/10 | Funciona bem mas sem guardrails para contexto formal |
| **Partilha/Colaboracao** | 7/10 | Boa base mas dois mecanismos confusos |
| **MEDIA GERAL** | **6.3/10** | Bom MVP, precisa de refinamento para uso em licitacoes |

---

## 7. PRIORIDADES RECOMENDADAS (proximos passos)

1. **Validacao pre-publicacao** (checklist) — impede documentos incompletos
2. **Marca de agua RASCUNHO no PDF** — clareza de estado
3. **Aviso IA** — "Revise o conteudo antes de usar"
4. **Tooltips nos estados** — explicar workflow ao utilizador
5. **Tamanho de ficheiros visivel** nos anexos
