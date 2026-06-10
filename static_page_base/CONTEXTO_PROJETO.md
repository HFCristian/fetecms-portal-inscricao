# Contexto completo do projeto — XVI FETECMS Portal de Inscrição (protótipo UI)

> **Propósito deste arquivo:** documentar tudo o que foi feito nas sessões de desenvolvimento com IA (Cursor), para recuperar contexto em conversas futuras. Cole ou referencie este arquivo ao retomar o trabalho.

---

## 1. Visão geral

| Item | Detalhe |
|------|---------|
| **Nome do repositório** | `fetecms-portal-inscricao` |
| **GitHub** | https://github.com/HFCristian/fetecms-portal-inscricao |
| **Conta GitHub** | `HFCristian` |
| **Pasta local (XAMPP)** | `/Applications/XAMPP/xamppfiles/htdocs/fetecms-portal-inscricao` |
| **Sistema legado (origem)** | `/Applications/XAMPP/xamppfiles/htdocs/SistemaInscricao` (PHP) |
| **Tipo** | Protótipo **front-end estático** (HTML + Tailwind CDN + CSS/JS próprios) |
| **Evento** | XVI FETECMS — Feira de Ciência e Tecnologia do MS |
| **Slogan institucional** | *A CIÊNCIA É A PONTE PARA O FUTURO.* |

O protótipo foi **extraído** do `SistemaInscricao` em maio/2026. Os arquivos `view/cadastro1–7.html`, `css/cadastro-fetecms.css`, `js/fetec-layout.js` e `js/cadastro-masks.js` foram **removidos** do sistema legado para evitar duplicação.

---

## 2. Identidade visual

| Elemento | Valor |
|----------|-------|
| Roxo institucional | `#43157A` (`--fetec-roxo`) |
| Roxo hover | `#5A1EB7` |
| Verde | `#007B24` (`--fetec-verde`) |
| Verde hover | `#0F9E3A` |
| Tipografia títulos | Space Grotesk |
| Tipografia corpo | Inter |
| Títulos decorativos (wizard) | Orbitron (override em algumas telas) |
| Ícones | Material Symbols Outlined (Google Fonts CDN) |
| CSS framework | Tailwind CSS 3 via CDN (`?plugins=forms,container-queries`) |
| Sombra padrão cards | `0 4px 24px rgba(67, 21, 122, 0.12)` |

Cores também mapeadas no `tailwind.config` inline de cada HTML (tokens M3-like: `primary-container`, `surface-container-low`, etc.).

---

## 3. Estrutura de arquivos

```
fetecms-portal-inscricao/
├── index.html                 # redirect → view/cadastro1.html
├── README.md                  # documentação resumida
├── CONTEXTO_PROJETO.md        # este arquivo (contexto para IA)
├── .gitignore
├── css/
│   └── cadastro-fetecms.css   # estilos compartilhados
├── js/
│   ├── fetec-layout.js        # shell wizard + área autenticada
│   ├── cadastro-masks.js      # máscaras CPF, telefone, CEP
│   ├── video-preview.js       # pré-visualização link vídeo (cadastro4)
│   └── file-upload-preview.js # pré-visualização upload PDF/DOCX (cadastro4)
├── img/
│   └── logo2022.png           # logo XVI FETECMS
└── view/
    ├── cadastro1.html         # wizard orientador — etapa 1
    ├── cadastro2.html         # wizard orientador — etapa 2
    ├── cadastro3.html         # wizard orientador — etapa 3
    ├── cadastro4.html         # projeto (área autenticada)
    ├── cadastro5.html         # aluno no projeto
    ├── cadastro6.html         # coorientador
    ├── cadastro7.html         # resumo / confirmação submissão
    ├── login.html             # placeholder
    └── perfil.html            # placeholder
```

---

## 4. Fluxos de navegação

### 4.1 Cadastro do orientador (wizard — 3 etapas)

Layout: **card central** + **painel lateral** (desktop) com logo, slogan e progresso.

| Etapa | Arquivo | Conteúdo principal |
|-------|---------|-------------------|
| 1 | `cadastro1.html` | Dados básicos (nome, CPF, e-mail, telefone, gênero com opção “Outro”, etc.) |
| 2 | `cadastro2.html` | Informações acadêmicas (instituição como **select**, vínculo, atestado) |
| 3 | `cadastro3.html` | Endereço (CEP com máscara, cidade, UF) |

**Links:** `cadastro1` → `cadastro2` → `cadastro3` → `cadastro4` (botão “CRIAR CONTA” na etapa 3).

Atributos no `<body>`:
- `data-fetec-wizard="1"` | `"2"` | `"3"`
- Slots HTML: `#fetec-wizard-aside`, `#fetec-wizard-mobile-header`, `#fetec-wizard-progress`

Login no rodapé da etapa 1: `login.html` (antes era `login.php` no sistema legado).

### 4.2 Área autenticada (projeto e integrantes)

Layout: menu injetado por JS em `#fetec-auth-shell` (sidebar desktop + header mobile + nav inferior).

Atributos no `<body>`:
- `data-fetec-auth`
- `data-fetec-auth-active="projetos"` | `"perfil"`
- Classes: `fetec-auth-shell`, `fetec-has-bottom-nav` (mobile)

| Tela | Arquivo | Função |
|------|---------|--------|
| Projeto | `cadastro4.html` | Formulário completo do projeto; atalhos “Incluir aluno” e “Incluir coorientador” |
| Aluno | `cadastro5.html` | Cadastro de estudante vinculado ao projeto |
| Coorientador | `cadastro6.html` | Cadastro de coorientador (máx. 1) |
| Resumo | `cadastro7.html` | Revisão de integrantes + confirmação antes da submissão final |

**Fluxo de submissão:**
```
cadastro4 → [SUBMETER PROJETO] → cadastro7 → [CONFIRMAR SUBMISSÃO] → alert + volta cadastro4
```

### 4.3 Mapa de links entre telas

```
cadastro1 ↔ cadastro2 ↔ cadastro3 → cadastro4
cadastro4 → cadastro5, cadastro6, cadastro7
cadastro5 → cadastro4 (voltar)
cadastro6 → cadastro4 (voltar / cancelar)
cadastro7 → cadastro4, cadastro5, cadastro6, perfil.html (atalhos editar)
Menu (JS) → projetos.html, perfil.html, login.html · Nova inscrição → cadastro4.html
projetos.html → integrantes.html?projeto=N · cadastro4/5/6/7
```

---

## 5. Arquitetura técnica do layout

### 5.1 `js/fetec-layout.js`

Executa no `DOMContentLoaded`:

1. **`initWizard()`** — se `data-fetec-wizard` presente:
   - Preenche aside desktop (`#fetec-wizard-aside`)
   - Header mobile (`#fetec-wizard-mobile-header`)
   - Barra de progresso 3 etapas (`#fetec-wizard-progress`) com links nas etapas concluídas

2. **`initAuth()`** — se `data-fetec-auth` presente:
   - Injeta em `#fetec-auth-shell`:
     - Header fixo mobile (logo + título + logout)
     - Sidebar fixa desktop (`w-64`, 16rem)
     - Bottom nav mobile (Projetos, Perfil)
   - Adiciona classes `fetec-auth-shell` e `fetec-has-bottom-nav` ao body

Logo: `../img/logo2022.png` (caminho relativo a `view/`).

### 5.2 `js/cadastro-masks.js`

Máscaras para inputs (CPF, telefone, CEP) usadas nas telas de formulário.

### 5.3 `js/video-preview.js`

Pré-visualização do campo **Link do Vídeo/Apresentação** em `cadastro4.html`.

**Comportamento:**
- Debounce de 600 ms no input `#link-video`
- Parser de URL: YouTube (`watch`, `youtu.be`, `shorts`, `embed`), Vimeo, Google Drive (`/file/d/ID/`)
- YouTube/Vimeo: validação via **oEmbed** (`youtube.com/oembed`, `vimeo.com/api/oembed.json`) — falha se vídeo privado ou inexistente
- Google Drive: embed direto em `/preview` (sem oEmbed)
- UI: `#fetec-video-preview`, banner de status (loading / ok / error), iframe 16:9
- Borda do input: verde (`.fetec-video-input-wrap--ok`) ou vermelha (`.fetec-video-input-wrap--error`)

**Inclusão na página:**
```html
<script src="../js/video-preview.js"></script>
```

### 5.4 `js/file-upload-preview.js`

Pré-visualização do **Upload de Arquivos (PDF ou DOCX)** em `cadastro4.html`.

**Comportamento:**
- Input `#upload-arquivos` (multiple), dropzone `#fetec-file-dropzone` (clique, drag-and-drop, teclado)
- Valida extensão (`.pdf`, `.docx`) e tamanho (máx. 10 MB)
- **PDF:** iframe com `URL.createObjectURL` para preview inline
- **DOCX:** painel com ícone, nome, tamanho e link de download (sem render de páginas no browser)
- Chips em `#fetec-file-list` — clique para preview; botão remover revoga blob URL
- Borda da dropzone: verde (ok), vermelha (erro), roxa (drag)

**Inclusão na página:**
```html
<script src="../js/file-upload-preview.js"></script>
```

### 5.5 `css/cadastro-fetecms.css`

Principais blocos:

| Classe / seletor | Uso |
|------------------|-----|
| `.fetec-btn`, `.fetec-btn-primary`, `.fetec-btn-success`, `.fetec-btn-outline` | Botões padronizados |
| `.fetec-input`, `.fetec-label` | Campos de formulário |
| `.fetec-main-offset` | `margin-left: 16rem` no desktop (espaço da sidebar) |
| `.fetec-auth-main`, `.fetec-auth-content` | Área principal autenticada |
| `.fetec-card-shadow` | Sombra dos cards |
| `.fetec-wizard-card`, `.fetec-wizard-form-area` | Card do wizard com scroll interno em desktop |
| `.fetec-member-card`, `.fetec-resumo-grid` | Cards de integrantes (cadastro7) |
| `.fetec-quick-action`, `.fetec-status-pill` | Atalhos e badges no resumo |
| `.fetec-video-preview`, `.fetec-video-preview__banner` | Preview do vídeo do projeto |
| `.fetec-file-preview`, `.fetec-file-chip` | Preview de upload PDF/DOCX |
| `body.fetec-auth-shell` | `overflow-x: hidden`; padding-bottom para nav mobile |

**Regra importante:** conteúdo autenticado **não usa `mx-auto`** — fica alinhado à esquerda após o menu (`margin-left: 0; margin-right: auto`) para evitar vão grande entre sidebar e formulário em telas largas.

---

## 6. Conteúdo por tela (detalhes)

### cadastro1 — Dados básicos
- Wizard etapa 1, `data-fetec-wizard="1"`
- Logo no painel lateral
- Campos: nome, CPF, e-mail, telefone, data nascimento, gênero (incl. Outro), etc.
- Link “Entrar” → `login.html`

### cadastro2 — Info. acadêmicas
- `data-fetec-wizard="2"`
- Instituição: **select** (não texto livre) — alinhado ao sistema real
- Atestado de vínculo institucional

### cadastro3 — Endereço
- `data-fetec-wizard="3"`
- CEP, logradouro, número, complemento, bairro, cidade, UF
- Botão verde “CRIAR CONTA” → `cadastro4.html`

### cadastro4 — Projeto
- Formulário extenso: título, instituição, categoria, área, resumo, toggles (continuação, feira afiliada, ODS)
- **Link do Vídeo/Apresentação** (`#link-video`) — `video-preview.js`
- **Upload de Arquivos** (`#upload-arquivos`, PDF/DOCX) — `file-upload-preview.js`
- Banner status “Rascunho”
- Atalhos: Incluir aluno → `cadastro5`, Incluir coorientador → `cadastro6`
- **Submeter** → link para `cadastro7.html` (não submit PHP)
- Breadcrumb desktop opcional (`fetec-page-header`)

### cadastro5 — Aluno
- Contador “2 de 3 cadastrados” (mock)
- Seções: dados pessoais, endereço, documentos
- Voltar → `cadastro4.html`

### cadastro6 — Coorientador
- Alerta: máximo 1 coorientador
- Campos alinhados ao aluno (nome, e-mail, telefone, CPF, nascimento, gênero, camiseta)
- Layout limpo: só `#fetec-auth-shell` + `main` (sem sidebar duplicada no HTML)

### cadastro7 — Resumo da inscrição
Criado para **identificar integrantes** antes da submissão final.

**Seções:**
1. Cabeçalho — “Etapa final”, status “Aguardando confirmação”
2. Alerta — revisar alunos e coorientador opcional
3. Dados do projeto (mock) + link editar → `cadastro4`
4. **Atalhos rápidos:** projeto, alunos (2/3), coorientador, perfil orientador
5. **Integrantes em cards:**
   - Orientador: João da Silva Santos → editar `perfil.html`
   - Aluno 1: Maria Clara Ferreira → `cadastro5`
   - Aluno 2: Pedro Lucas Almeida → `cadastro5`
   - Vaga 3 vazia (opcional) → `cadastro5`
   - Coorientador: Ana Carolina Mendes → `cadastro6`
6. Checklist de submissão
7. Ações: Voltar e editar | Salvar rascunho | Confirmar submissão (JS `confirm` + `alert` protótipo)

**Dados mock do projeto no resumo:**
- Título: *Desenvolvimento de Bioplástico a partir de Casca de Mandioca*
- Instituição: *EE Prof. João Mendes — Campo Grande/MS*
- Categoria: *Ensino Médio*
- Área: *Ciências Agrárias · Biotecnologia*

---

## 7. Problemas corrigidos durante o desenvolvimento

### 7.1 Menus duplicados (cadastro5, cadastro6)
**Causa:** HTML estático com `<aside>`, `<header>` e `<nav>` **e** o mesmo conteúdo injetado por `fetec-layout.js` em `#fetec-auth-shell`.

**Solução:** remover navegação estática; manter apenas:
```html
<div id="fetec-auth-shell"></div>
<main class="fetec-main-offset fetec-auth-main">...</main>
<script src="../js/fetec-layout.js"></script>
```

### 7.2 Scroll indesejado
**Causa:** `min-h-screen` + `overflow-hidden` no `<main>` gerava scroll duplo.

**Solução:** remover overflow no main; `overflow-x: hidden` no body; padding-bottom só para compensar bottom nav no mobile.

### 7.3 Espaço grande entre menu e conteúdo
**Causa:** `max-w-container-max mx-auto` centralizava o conteúdo na área à direita da sidebar.

**Solução:** classe `fetec-auth-content` com alinhamento à esquerda; remover `mx-auto` e paddings duplicados (`px-gutter` + CSS).

### 7.4 HTML quebrado
Tags órfãs (`</header>`, `</motion>`) e blocos duplicados no final de arquivos após edições — corrigidos manualmente.

### 7.5 Unificação wizard etapas 2–3
Etapas 2 e 3 reestruturadas para o mesmo shell da etapa 1 (`data-fetec-wizard` + slots JS).

---

## 8. Comparação com o sistema legado (`SistemaInscricao`)

| Aspecto | Legado | Protótipo |
|---------|--------|-----------|
| Stack | PHP, views em `view/*.php` | HTML estático |
| Cadastro orientador | `view/cadastro.php` (fluxo antigo) | `cadastro1–3.html` |
| Projeto | formulários PHP em `formularios/`, `projetos/` | `cadastro4.html` |
| Autenticação | `login.php`, sessão PHP | `login.html` placeholder |
| Banco / validação | back-end real | nenhum (links e alerts) |

**Integração futura sugerida:**
- Converter HTML → templates PHP em `SistemaInscricao/view/`
- Substituir mocks por dados do banco
- Manter `fetec-layout.js` ou portar para include PHP
- `cadastro7` → action real de submissão com validação de equipe mínima (≥1 aluno, ≤3, ≤1 coorientador)

---

## 9. Separação em repositório (maio/2026)

**Comandos executados:**
1. Cópia dos arquivos para `htdocs/fetecms-portal-inscricao/`
2. `git init` + commit inicial
3. `gh repo create fetecms-portal-inscricao --public --push` na conta `HFCristian`
4. Remoção dos arquivos do protótipo em `SistemaInscricao`

**Ajustes na separação:**
- `login.php` → `login.html`
- `perfil.php` → `perfil.html`
- `index.html` na raiz com redirect para `view/cadastro1.html`

---

## 10. Convenções para continuar o desenvolvimento

### Ao criar nova tela autenticada
```html
<body class="fetec-auth-shell fetec-has-bottom-nav ..." data-fetec-auth data-fetec-auth-active="projetos">
<div id="fetec-auth-shell"></div>
<main class="fetec-main-offset fetec-auth-main">
  <div class="fetec-auth-content py-4 md:py-5">
    <!-- conteúdo -->
  </div>
</main>
<script src="../js/fetec-layout.js"></script>
```

### Ao criar nova etapa do wizard
```html
<body data-fetec-wizard="N" class="...">
  <aside id="fetec-wizard-aside"></aside>
  <div id="fetec-wizard-mobile-header"></div>
  <div id="fetec-wizard-progress"></div>
  ...
<script src="../js/fetec-layout.js"></script>
```

### Não fazer
- Não duplicar `<aside>` / `<nav>` fixos no HTML das telas autenticadas
- Não usar `mx-auto` no container principal da área autenticada (desktop)
- Não misturar `overflow-hidden` com `min-h-screen` no `main` sem necessidade

---

## 11. Prompt útil para retomar na IA

Copie e adapte:

```
Estou trabalhando no protótipo XVI FETECMS (repo fetecms-portal-inscricao).
Leia CONTEXTO_PROJETO.md e README.md.

Stack: HTML estático, Tailwind CDN, css/cadastro-fetecms.css, js/fetec-layout.js.
Fluxo: cadastro1-3 (wizard orientador) → cadastro4 (projeto) → cadastro5/6 (integrantes) → cadastro7 (resumo/submissão).
Layout autenticado: só #fetec-auth-shell + fetec-layout.js (sem nav duplicada no HTML).

[Tarefa específica aqui]
```

---

## 12. Histórico cronológico (sessões IA)

1. **Documentação inicial** — mapeamento das telas do `SistemaInscricao` e prompt de design (identidade XVI FETECMS).
2. **Protótipos HTML** — `cadastro1.html` a `cadastro6.html` com Tailwind e identidade visual.
3. **Unificação de layout** — `cadastro-fetecms.css`, `fetec-layout.js`, wizard unificado etapas 1–3.
4. **Área autenticada** — shell JS para projeto, aluno, coorientador (`cadastro4–6`).
5. **Correções UX** — menus duplicados, scroll, responsividade mobile (bottom nav).
6. **Espaçamento** — alinhamento conteúdo à esquerda após sidebar.
7. **cadastro7** — resumo de integrantes + atalhos de edição + fluxo submeter → confirmar.
8. **Repositório separado** — `HFCristian/fetecms-portal-inscricao`, arquivos removidos do legado.
9. **Preview de vídeo** — validação oEmbed + player embutido (`video-preview.js`).
10. **Preview de arquivos** — PDF inline + DOCX com validação (`file-upload-preview.js`).

---

## 13. Backend Laravel

- Issues: [`BACKLOG_LARAVEL.md`](BACKLOG_LARAVEL.md)
- Especificação API (CRUDs, padronização, BD novo, app): [`docs/ESPECIFICACAO_LARAVEL.md`](docs/ESPECIFICACAO_LARAVEL.md)

---

## 14. Pendências / próximos passos sugeridos

- [ ] Integrar telas ao PHP do `SistemaInscricao`
- [ ] Substituir placeholders `login.html` e `perfil.html`
- [ ] Listagem de alunos com edição individual (URL com ID)
- [ ] Validação real na submissão (equipe mínima, campos obrigatórios)
- [ ] Remover Tailwind CDN → build de produção
- [ ] Tela de sucesso pós-submissão (em vez de `alert`)
- [ ] Commit no `SistemaInscricao` registrando remoção dos protótipos (se usar git lá)

---

*Última atualização: maio/2026 (preview de vídeo e upload PDF/DOCX no cadastro4) — gerado para recuperação de contexto em assistentes de IA.*
