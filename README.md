# XVI FETECMS — Portal de Inscrição (protótipo UI)

Protótipo HTML/CSS/JS das telas de inscrição da **XVI FETECMS**, separado do sistema legado PHP (`SistemaInscricao`).

> **Contexto completo para IA:** leia [`CONTEXTO_PROJETO.md`](CONTEXTO_PROJETO.md) — histórico, arquitetura, fluxos, correções e convenções.

## Telas

| Arquivo | Descrição |
|---------|-----------|
| `view/cadastro1.html` | Cadastro do orientador — etapa 1 (dados básicos) |
| `view/cadastro2.html` | Cadastro do orientador — etapa 2 (info. acadêmicas) |
| `view/cadastro3.html` | Cadastro do orientador — etapa 3 (endereço) |
| `view/cadastro4.html` | Cadastro / edição do projeto (com pré-visualização do link de vídeo) |
| `view/cadastro5.html` | Cadastro de aluno no projeto |
| `view/cadastro6.html` | Cadastro de coorientador |
| `view/cadastro7.html` | Resumo da inscrição e confirmação de submissão |
| `view/login.html` | Login (placeholder) |
| `view/perfil.html` | Perfil do orientador (placeholder) |

## Como rodar

Coloque a pasta no servidor web (ex.: XAMPP `htdocs`) e acesse:

```
http://localhost/fetecms-portal-inscricao/
```

Ou abra diretamente `view/cadastro1.html` para o fluxo do orientador, ou `view/cadastro4.html` para a área autenticada (protótipo).

## Estrutura

```
├── index.html          → redireciona para cadastro1
├── css/cadastro-fetecms.css
├── js/fetec-layout.js   → menu lateral / mobile
├── js/cadastro-masks.js → máscaras CPF, telefone, CEP
├── js/video-preview.js  → pré-visualização do vídeo do projeto
├── img/logo2022.png
└── view/                → telas HTML
```

## Pré-visualização de vídeo (cadastro do projeto)

No campo **Link do Vídeo/Apresentação** (`cadastro4.html`), ao colar o link o protótipo:

- Aceita **YouTube**, **Vimeo** e **Google Drive** (link público)
- Valida disponibilidade via **oEmbed** (YouTube/Vimeo)
- Exibe **player embutido** para o orientador confirmar que o vídeo está público
- Mostra feedback visual no campo (verde = OK, vermelho = link inválido ou vídeo privado)

## Fluxo do protótipo

1. Orientador: `cadastro1` → `cadastro2` → `cadastro3` → `cadastro4`
2. Projeto: incluir aluno (`cadastro5`), coorientador (`cadastro6`)
3. Submissão: `cadastro4` → **Submeter** → `cadastro7` → confirmar

## Integração futura

Este repositório é **somente front-end estático**. Para produção, integrar com o back-end PHP do `SistemaInscricao` (formulários, sessão, banco de dados).

## Identidade visual

- Roxo institucional: `#43157A`
- Verde: `#007B24`
- Tipografia: Space Grotesk, Inter
- Tailwind CSS via CDN (protótipo)
