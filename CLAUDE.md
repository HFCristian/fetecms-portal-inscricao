# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Importante:** este arquivo NUNCA deve entrar no `.gitignore` — ele fica versionado e
> visível no repositório online. Ao gerar/editar `.gitignore`, garanta que `CLAUDE.md` não
> seja ignorado.

## O que é o projeto

Plataforma de submissão de projetos da feira de ciências **XVI FETECMS**. Está sendo
(re)construída como:

- **Backend**: **Laravel 11+ / PHP 8.2+**, **API REST `/api/v1`** autenticada com **Laravel Sanctum**.
- **Frontend web**: **SPA React** (via **Vite**) servido pelo próprio Laravel no mesmo domínio
  (auth do web por **cookie/CSRF** do Sanctum).
- **Mobile** (em breve): app nativo consumindo a **mesma** `/api/v1` via **token Bearer** do Sanctum.

A regra de negócio mora em **Services**, então os controllers (web/mobile) são finos e a lógica
nunca duplica.

### Estado atual

Fase de **bootstrap**. O que existe hoje no repo é o **protótipo estático** em
[static_page_base/](static_page_base/) (HTML + Tailwind via CDN + JS puro), que serve como
**referência visual** — a SPA React deve reproduzir essas telas o mais fielmente possível.
Ainda **não há** projeto Laravel, `composer.json` nem `package.json` na raiz.

## Decisões de arquitetura (travadas)

| Tema | Decisão |
|------|---------|
| Padrão de API | REST JSON, versionada `/api/v1`; envelope `{ "data", "meta" }`; erro `{ "message", "errors", "code" }` |
| Auth | Sanctum — **cookie/CSRF** para o SPA web (mesma origem) e **token** para o mobile |
| Camadas | `FormRequest` → `Controller` (thin) → `Service` → `Model`, com `Policy` e `API Resource`; Enums em `app/Enums` |
| Autorização | Policies por dono do recurso (orientador só acessa/edita/submete os próprios projetos) |
| Banco local | **SQLite** (arquivo único, zero instalação) |
| Banco produção | **PostgreSQL** (AWS RDS); migrations escritas de forma agnóstica de SGBD |
| Frontend build | **Vite + Tailwind compilado** (sem CDN); React + JSX |
| i18n / tempo | locale `pt_BR`; timezone `America/Campo_Grande`; datas ISO 8601 |
| Ambiente local | **Laravel Herd** (Windows) — traz PHP 8.x + Composer + servidor |

Documentos de referência detalhados (modelo de dados, CRUDs, validações, checklist de submissão):
[docs/ESPECIFICACAO_LARAVEL.md](static_page_base/docs/ESPECIFICACAO_LARAVEL.md) ·
[BACKLOG_LARAVEL.md](static_page_base/BACKLOG_LARAVEL.md) ·
[CONTEXTO_PROJETO.md](static_page_base/CONTEXTO_PROJETO.md).

## Papéis (roles) e regras de negócio

Tabela `users` única com coluna `role`: **`orientador`**, **`avaliador`**, **`admin`**.

- **Orientador**: cadastro completo (wizard 3 etapas) → lista de projetos → cadastro de projeto
  (salvável como **rascunho**) → alunos → coorientador opcional → resumo → **submissão irreversível**.
- **Avaliador (online)**: mesmo login do orientador; botão de cadastro **abaixo** do de orientador
  na tela de login. **Exclusão mútua**: quem é orientador NÃO pode ser avaliador, e vice-versa
  (validar no cadastro, em ambos os sentidos), pois o avaliador avalia projetos **submetidos**.
  Workflow de avaliação (Sprint 4):
  - Após uma **data definida pelo admin**, o avaliador acessa e vê a tela com os projetos **designados** a ele.
  - Vê **até 3 projetos** designados automaticamente; ao **iniciar** uma avaliação **não pode cancelar**
    e trocar de projeto — só o **admin** pode cancelar/reverter.
  - A avaliação permite **ler o projeto inteiro** e dar uma **nota de 1 a 10**.
  - Cada projeto passa por **≥ 3 avaliadores**, com *match* por **subárea** (preferencial) ou **área**.
  - **Distribuição automática**: casa subárea do projeto ↔ subárea do avaliador; se não houver,
    cai para a **mesma área**. (Algoritmo ainda a refinar.)
  - Cada projeto fica visível para **no máximo 5 avaliadores**.
  - O **admin pode designar manualmente** projetos a avaliadores, podendo **exceder o limite de 3**.
- **Admin**: criado **somente por outro admin** (cadastro simples: nome, e-mail, senha). Dashboard
  com 9 métricas: projetos totais / submetidos / em rascunho; orientadores; alunos; coorientadores;
  escolas, cidades e estados **com projeto cadastrado**.

Regras-chave:
- **Equipe: 1 a 4 alunos por projeto, condicionado à categoria** — *FETEC Jr* permite até 4;
  *FETECMS* e *FETECMS FUNDECT* permitem até 3; sempre mínimo 1.
- **Máx. 1 coorientador** por projeto (opcional; sem campos escolares).
- Status do projeto: `rascunho` → `submetido` (e depois `aprovado`/`rejeitado`). Após submeter, sem volta.
- Checklist de submissão centralizado num `ProjetoChecklistService` (ver ESPECIFICACAO §8).

## Segurança (requisito explícito do cliente)

- **Senhas**: hash nativo do Laravel (bcrypt/Argon2id) — **já inclui salt aleatório por senha**;
  nunca armazenar/gerenciar salt manualmente nem guardar senha em claro.
- **Autorização**: Policies impedem um usuário de **ver/editar/submeter** projeto de outro.
- **Web**: CSRF nativo + cookies HttpOnly (Sanctum SPA). **Mobile**: tokens Sanctum.
- **Rate limiting** em login/registro/upload; validação server-side em todo input; proteção de
  mass assignment (`$fillable`); URLs assinadas/temporárias para download de arquivos.

## Comandos (disponíveis após o bootstrap do Sprint 1)

```powershell
# Backend
php artisan serve                 # sobe a API/app em http://localhost:8000
php artisan migrate               # roda migrations (SQLite local)
php artisan migrate:fresh --seed  # recria o banco e roda seeders
php artisan test                  # testes (Pest/PHPUnit)
php artisan test --filter=Nome    # roda um teste específico

# Frontend (Vite)
npm run dev                       # dev server com HMR
npm run build                     # build de produção
```

Node 22 + npm já estão instalados na máquina. PHP/Composer vêm do Herd.

## Frontend / design system (reproduzir o protótipo)

A SPA deve manter a identidade visual de [static_page_base/](static_page_base/):

- Cores: roxo `#43157A` (`primary-container`), roxo base `#2a0058`, verde `#006e1f`/`#007B24`,
  erro `#ba1a1a`. Fontes: **Space Grotesk** (títulos), **Inter** (corpo), **Orbitron** (decorativo),
  Material Symbols Outlined (ícones). Sombra de card: `0 4px 24px rgba(67,21,122,0.12)`.
- O protótipo usa Tailwind via CDN com `tailwind.config` inline e classes utilitárias próprias
  (`.fetec-btn`, `.fetec-input`, `.fetec-member-card`, `.fetec-status-pill`, etc.) em
  `static_page_base/css/cadastro-fetecms.css`. Ao migrar para Vite, portar essas cores/tokens
  para `tailwind.config.js` e os componentes para CSS/componentes React equivalentes.
- Os comportamentos de JS do protótipo (tags de palavra-chave, wizard, preview de vídeo/arquivo)
  viram componentes React.

## Fluxo de trabalho por sprint (política do cliente)

O desenvolvimento avança em **sprints de 2 épicos**, entregando um MVP sempre que possível.
Ao **final de cada sprint**:

1. **Rodar os testes** (unitários, feature, segurança/autorização; responsividade e carga quando
   aplicável) e registrar o resultado.
2. **Commitar** as mudanças na branch **`changes`** (já é a branch ativa).
   - Mensagem padrão: `feat(sprintN): <resumo dos 2 épicos>` + rodapé `Co-Authored-By`.
3. **Push é manual** (feito pelo Pedro). **Exceção**: se houver **3 sprints seguidas sem push**,
   o Claude pode fazer `git push origin changes` diretamente.
4. Entregar ao Pedro um **overview** da sprint: o que foi feito, orientações, dúvidas e **ações
   a realizar antes da próxima sprint**.

Manter o registro abaixo atualizado a cada sprint para auditar a regra das "3 sprints sem push":

| Sprint | Épicos | Commit feito | Push feito? | Sprints desde o último push |
|--------|--------|--------------|-------------|------------------------------|
| 1 | E0 Fundação + E1 Auth/perfil orientador | ✅ sim | ❌ não (manual do Pedro) | 1 |

### Roadmap de sprints (proposto)

- **Sprint 1** — E0 Fundação + E1 Auth & perfil do orientador.
- **Sprint 2** — E2 Catálogos + E3 Projetos (CRUD/rascunho).
- **Sprint 3** — E4 Integrantes (alunos 1–4 por categoria, coorientador) + E5 Uploads.
- **Sprint 4** — E6 Submissão & checklist (irreversível) + E7 Avaliador (cadastro/login + exclusão mútua).
- **Sprint 5** — E8 Admin & dashboard (9 métricas) + E9 Qualidade/segurança/carga.

## Convenções ao desenvolver

- Controllers finos; lógica em Services; validação em FormRequests (com `prepareForValidation`
  para limpar CPF/telefone); respostas via API Resources (nunca expor path interno de arquivo).
- Mensagens de validação em `pt_BR`.
- Testes acompanham cada feature na própria sprint (não deixar para o fim do projeto).
