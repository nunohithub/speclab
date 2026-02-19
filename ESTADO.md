# SpecLab - Estado de Atualização

**Última atualização:** 2026-02-19

---

## Funcionalidades Implementadas

### Core
- [x] Autenticação multi-tenant (organizações)
- [x] Dashboard com listagem de especificações
- [x] Criação/edição de Cadernos de Encargos
- [x] Multi-produto e multi-fornecedor por especificação
- [x] Numeração automática de especificações
- [x] Sistema de permissões (superadmin, admin, user)

### Versionamento e Aceitação Formal
- [x] Publicar/bloquear versão (impede edição)
- [x] Criar nova versão a partir de publicada (clona tudo)
- [x] Histórico de versões no tab Partilha
- [x] Tokens individuais por destinatário (link pessoal)
- [x] Aceitação formal: aceitar ou rejeitar com nome, cargo e comentário
- [x] Resumo de aceitação (aceites, rejeições, pendentes)
- [x] Envio de link de aceitação por email

### Email SMTP
- [x] SMTP inteligente: SMTP próprio da organização ou global speclab.pt
- [x] Email de aceitação com botão "Ver e Aprovar Documento"
- [x] Email genérico com link público ou mensagem personalizada
- [x] Suporte BCC para múltiplos destinatários
- [x] Log de emails enviados (email_log)

### Editor de Especificações (especificacao.php)
- [x] Secções de texto (TinyMCE 6)
- [x] Secções de ensaios com tabela editável
- [x] 4 colunas de dados: Ensaio, Especificação, Norma, NQA
- [x] Categoria como linha header (não coluna) — agrupamento visual
- [x] Redimensionamento de colunas (drag handles)
- [x] Merge de células com rowspan real (não CSS-only)
- [x] Alinhamento horizontal e vertical em merge cells
- [x] Adicionar ensaios do banco por categoria
- [x] Adicionar linhas manuais
- [x] Remover linhas e categorias
- [x] Secção de defeitos (crítico, maior, menor)
- [x] Configurações visuais (cores, tamanhos, logo)
- [x] Pré-visualização do documento em tempo real
- [x] Ficheiros anexos
- [x] Cores dinâmicas da organização (CSS variables)
- [x] Botões toolbar com outline-primary (cor da org)

### Tab Partilha (reorganizado)
- [x] Histórico de Versões (topo)
- [x] Aceitação Formal (destinatários com tokens, só em versão publicada)
- [x] Partilha Rápida (email + link de consulta num só card)
- [x] Link de Consulta com descrição clara da utilidade
- [x] Botão Copiar robusto (fallback para HTTP)

### Visualização (ver.php)
- [x] Visualização online do Caderno de Encargos
- [x] Estilo limpo com cores da organização
- [x] Linha de títulos com cor primária + texto branco
- [x] Categoria como header row com cor clara + texto centrado
- [x] Merge cells com rowspan
- [x] Suporte a impressão (CSS @media print)

### PDF (pdf.php)
- [x] Geração via mPDF (quando instalado)
- [x] Fallback HTML para impressão via browser
- [x] Estilo consistente com ver.php
- [x] Cores da organização (definidas pelo superadmin)
- [x] Cabeçalho e rodapé com logo e dados da organização
- [x] Merge cells com rowspan

### Link Público (publico.php)
- [x] Partilha via link sem autenticação (código de acesso)
- [x] Acesso via token individual (aceitação formal)
- [x] Formulário de aceitação (aceitar/rejeitar com dados)
- [x] Estilo idêntico ao ver.php
- [x] Cores da organização

### Administração (admin.php)
- [x] Gestão de organizações (cores, logo, funcionalidades)
- [x] Gestão de utilizadores
- [x] Banco de ensaios por categoria
- [x] Configurações globais
- [x] Configuração SMTP (global e por organização)

### Deploy Produção
- [x] Servidor speclab.pt (cPanel, WebTuga)
- [x] PHP 8.2 via .htaccess AddHandler
- [x] BD MySQL: exipt_speclab
- [x] SMTP funcional via mail.speclab.pt:587
- [x] SSL cert fix (verify_peer desativado para shared hosting)

---

## Ficheiros Principais

| Ficheiro | Descrição |
|----------|-----------|
| `especificacao.php` | Editor principal (PHP + JS inline) |
| `ver.php` | Visualização online autenticada |
| `pdf.php` | Geração de PDF (mPDF + fallback HTML) |
| `publico.php` | Visualização pública + aceitação formal |
| `dashboard.php` | Lista de especificações |
| `admin.php` | Painel de administração |
| `api.php` | Endpoints AJAX |
| `includes/auth.php` | Autenticação e sessões |
| `includes/functions.php` | Funções auxiliares |
| `includes/email.php` | SMTP inteligente + templates email |
| `includes/versioning.php` | Publicar, nova versão, tokens, aceitação |
| `config/database.php` | Configuração BD (não versionado) |

---

## Stack Técnica

- **Backend:** PHP 8.3 (vanilla, sem framework)
- **Base de dados:** MySQL 8.0 (local) / MySQL 5.7+ (servidor)
- **Frontend:** HTML/CSS/JS vanilla
- **Editor rich text:** TinyMCE 6
- **PDF:** mPDF 8.2
- **Email:** PHPMailer 6.12
- **Servidor local:** MAMP
- **Servidor produção:** speclab.pt (cPanel, PHP 8.2)

---

## Notas de Configuração

- `config/database.php` não está no repositório (contém credenciais)
- Cores da organização são definidas pelo superadmin e propagam automaticamente para editor, ver, pdf e link público via CSS variables
- A pasta `uploads/` está ignorada no git (contém ficheiros dos utilizadores)
- Servidor produção: config BD diferente (localhost, exipt_speclab), BASE_PATH vazio
- Workflow: editar local → copiar via FTP (Cyberduck) → servidor speclab.pt
