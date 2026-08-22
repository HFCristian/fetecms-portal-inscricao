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
  - A avaliação permite **ler o projeto inteiro** e responder à **rubrica oficial da FETECMS**
    (documento "Perguntas de Avaliação"): **17 perguntas pontuadas** em **10 seções**, cada
    uma com **peso próprio**, respondidas por **escala de 0 a 10 de dois em dois** (Não possui,
    Muito ruim, Ruim, Regular, Bom, Muito bom) ou por **Sim/Não** (Sim = peso cheio). A **nota
    final é a soma ponderada (0 a 10)**, calculada no servidor — 8,0 do projeto de pesquisa e
    2,0 do vídeo. Cada pergunta traz as *Orientações para o Avaliador* num **balão de dúvida
    ("?")**, e a tela é um **wizard: um passo por seção**. O avaliador também **confere a
    classificação**: se a **área** está correta (obrigatório; se não, sugere a correta) e se a
    **subárea** está correta (opcional; quem marcar como incorreta precisa sugerir a correta).
    Fecha com **dois campos descritivos opcionais** (recomendações sobre o vídeo e sobre o
    projeto). A avaliação pode ser **salva como rascunho** a qualquer momento; o envio continua
    irreversível. As perguntas moram em `app/Support/Rubrica.php` e as respostas na coluna
    JSON `avaliacoes.respostas` — mexer na rubrica não pede migration.
  - **Perfil do avaliador** (`/avaliador/perfil`): cards com **projetos avaliados**, **carga
    horária do certificado** (**2h30 por avaliação concluída**) e **posição no ranking** de quem
    mais avaliou (só entra quem já concluiu ao menos uma; empate divide a posição). Na mesma
    tela ele **troca a própria área/subárea — só enquanto o período de avaliação não começou**
    (`Edicao::avaliacaoLiberada()`), porque depois a distribuição já foi feita em cima dela.
  - Cada projeto passa por **≥ 3 avaliadores**, com *match* por **subárea** (preferencial) ou **área**.
  - **Distribuição automática**: casa subárea do projeto ↔ subárea do avaliador; se não houver,
    cai para a **mesma área**. (Algoritmo ainda a refinar.)
  - Cada projeto fica visível para **no máximo 5 avaliadores**.
  - O **admin pode designar manualmente** projetos a avaliadores, podendo **exceder o limite de 3**.
- **Admin**: criado **somente por outro admin** (cadastro simples: nome, e-mail, senha). Dashboard
  com as métricas: projetos totais / submetidos / em rascunho; **projetos por categoria**;
  orientadores; alunos; coorientadores; escolas, cidades e estados **com projeto cadastrado**.
  - **Mala direta** (`/admin/mala-direta`): comunicado por e-mail para um recorte da base.
    O admin combina quantos **públicos** quiser (todos, orientadores, avaliadores, orientadores
    com rascunho, com submetido, avaliadores com avaliação **em andamento** ou **concluída**) e/ou
    cola uma **lista personalizada** (digitada ou importada de `.csv` com as colunas `email`/`nome`).
    A união é **deduplicada por e-mail**; contas de admin, inativas e demo ficam fora dos públicos.
    Antes de disparar ele vê **quantos recebem**, pode **listar** e **exportar CSV** (nome, e-mail,
    papel, origem, títulos e quantidade de projetos do orientador) e precisa **confirmar a mensagem**.
    O envio vai para a **fila** (um job por destinatário) com **tela de progresso**; o relatório traz
    a situação de cada endereço, o motivo das falhas e o **reenvio só das falhas**. E-mail malformado
    entra como `invalido` no relatório em vez de barrar o disparo. Campos da mala: nome, justificativa,
    solicitante (opcional — metadado interno, não vai no e-mail), assunto e texto (aceita `{{nome}}`
    e `{{email}}`).

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
php artisan queue:work            # processa a fila (envio da mala direta) — exigido no deploy

# Frontend (Vite)
npm run dev                       # dev server com HMR
npm run build                     # build de produção
npm test                          # testes de componente (Vitest)

# Carga (k6 — instalar separadamente): k6 run load/k6-smoke.js
# Admin padrão (seed): admin@fetecms.test / password
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

**Commitar é responsabilidade do Claude:** **toda alteração deve ser commitada** na branch
**`changes`** (já é a branch ativa) assim que concluída — não deixar mudança sem commit nem
adiar para o fim da sprint. Mensagem no padrão convencional (`feat(...)`, `fix(...)`,
`docs(...)`, …) + rodapé `Co-Authored-By`. O **Pedro fica responsável apenas pelo push**.

Ao **final de cada sprint** (com os commits já feitos ao longo do caminho):

1. **Rodar os testes** (unitários, feature, segurança/autorização; responsividade e carga quando
   aplicável) e registrar o resultado.
2. **Push é manual** (feito pelo Pedro). **Exceção**: se houver **3 sprints seguidas sem push**,
   o Claude pode fazer `git push origin changes` diretamente.
3. Entregar ao Pedro um **overview** da sprint: o que foi feito, orientações, dúvidas e **ações
   a realizar antes da próxima sprint**.

Manter o registro abaixo atualizado a cada sprint para auditar a regra das "3 sprints sem push":

| Sprint | Épicos | Commit feito | Push feito? | Sprints desde o último push |
|--------|--------|--------------|-------------|------------------------------|
| 1 | E0 Fundação + E1 Auth/perfil orientador | ✅ sim | ✅ sim (Pedro) | 0 |
| 2 | E2 Catálogos + E3 Projetos (CRUD/rascunho) | ✅ sim | ✅ sim (Pedro) | 0 |
| 3 | E4 Integrantes (alunos 1–4 + coorientador) + E5 Uploads | ✅ sim | ✅ sim (Pedro) | 0 |
| 4 | E6 Submissão & checklist (irreversível) + E7 Avaliador (cadastro/login + exclusão mútua) | ✅ sim | ✅ sim (Pedro) | 0 |
| 5 | E8 Admin & dashboard (9 métricas) + E9 Qualidade/segurança/carga | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 6 | Localidades: cidades do Brasil (IBGE) + endereço do orientador por FK + máscara de CEP | ✅ sim | ✅ sim (Pedro) | 0 |
| 7 | Catálogo unificado área/subárea: combobox digite/cria + criação global + unificação do orientador + remove Multidisciplinar | ✅ sim | ✅ sim (Pedro) | 0 |
| 8 | Admin Parametrização: renomear/mesclar/excluir áreas e subáreas (reatribui referências) | ✅ sim | ✅ sim (Pedro) | 0 |
| 9 | Erros 100% em pt_BR + favicon (logo2026.png) + rodapé com e-mail de suporte | ✅ sim | ✅ sim (Pedro) | 0 |
| 10 | Instituições: importar escolas_ms.csv + combobox "digite/crie" (criação global) no orientador e projeto | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 11 | E1 Dependências (dependabot: 9 PRs) + E2 Login: aviso de bloqueio + contador regressivo | ✅ sim | ❌ não (sem credencial no ambiente) | 1 |
| 12 | E3 Dashboard: card projetos por categoria + E4 Rubrica de avaliação (3 quesitos, nota 0–30) | ✅ sim | ❌ não (sem credencial no ambiente) | 2 |
| 13 | E5 Conferência de área/subárea + E6 Rascunho da avaliação | ✅ sim | ❌ não (sem credencial no ambiente) | 3 |
| 14 | E7 Troca de e-mail (todos os papéis) + E8 Desfazer submissão & trilha de registros | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 15 | E9 Cadastro do avaliador (pós-graduação em andamento) + E10 Card de avaliação (preview do vídeo, quesito de continuidade, escala Likert) | ✅ sim | ✅ sim (Pedro, PR #53 → v1.13) | 0 |
| 16 | Rubrica oficial da FETECMS (17 perguntas em 10 seções, pesos, balão "?", wizard) + remoção da avaliação do projeto de continuidade | ✅ sim | ✅ sim (Pedro, PR #54 → v1.14) | 0 |
| 17 | Perfil do avaliador: cards de estatística (avaliados, certificado 2h30/avaliação, posição no ranking) + troca da própria área fora do período de avaliação | ✅ sim | ✅ sim (Pedro, PR #54 → v1.14) | 0 |
| 18 | Mala direta: públicos + lista personalizada (CSV), prévia com contagem/listagem/export, disparo pela fila com progresso e relatório de falhas | ✅ sim | ❌ não (manual do Pedro) | 1 |

> **Estado atual:** ciclo de ajustes pós-v1 (Sprints 6–10) **concluído e verde** — back 110/110,
> front 11/11, Pint limpo, build OK (estado integrado, já com a refatoração visual do Pedro).
> A Sprint 10 ficou versionada **junto** das correções visuais do Pedro no commit `f502e4f`
> (criação de admin movida para a página `AdminManager` em `/admin/gerir-admins`).
> **Ajustes finos pós-ciclo:** favicon multi-tamanho gerado da logo (`c47859f`); instituição com
> dedup por **(nome + cidade)** — permite mesmo nome em cidades diferentes, com diálogo de criação
> (nome + estado→cidade + tipo) no combobox.
> **Ajustes recentes (nesta sessão):** (a) instituição do **aluno** (Integrantes) agora usa o
> `InstituicaoCombobox` (busca em todo o catálogo + criação global), igual a orientador/projeto;
> (b) vínculo institucional do orientador ganhou **"Professor Convocado"**;
> (c) **Parametrização** virou landing com 2 cards — **Áreas e subáreas** (`/admin/parametrizacao/areas`)
> e **Escolas** (`/admin/parametrizacao/escolas`): admin busca, **renomeia, mescla** (reatribui
> projetos/alunos/orientadores) e **exclui** instituições sem uso (`InstituicaoAdminService`/Controller,
> rotas `admin/instituicoes`). Back **117/117**, front 11/11, Pint limpo, build OK.
> **Pendências do Pedro:** (1) `git push origin changes`; (2) popular as escolas com
> `php artisan instituicoes:importar` (lê `database/data/instituicoes/escolas_ms.csv`; 1888 escolas
> de MS, todos os 79 municípios casam com o catálogo IBGE).
>
> **Sprint 14 (branch `feat/conta-email-e-registros`, saída da `origin/main`):**
> (a) **Troca de e-mail** em `PUT /auth/email` para orientador, avaliador e admin, com tela
> `/alterar-email` no menu de todos os papéis; o perfil do orientador não edita mais o campo
> (aponta para a tela dedicada) para toda troca passar pelo caminho auditado.
> (b) **Desfazer a submissão**: o orientador **cancela** (`POST /projetos/{id}/cancelar-submissao`,
> volta a rascunho) ou **exclui** (`DELETE /projetos/{id}`, soft delete) — só enquanto **nenhuma
> avaliação foi iniciada** (`em_andamento`/`concluida`) e o **período de avaliação não começou**
> (`Edicao::avaliacaoLiberada()`); fora da janela, 422 com o motivo. Regras no `SubmissaoService`;
> o admin passa por cima (escape do edital) e a ação fica registrada no nome dele.
> (c) **Trilha de registros** (`registros_atividade` + `RegistroAtividadeService`): submissão,
> cancelamento, exclusão e troca de e-mail, com autor/projeto **desnormalizados** para sobreviver
> ao delete. A migration **reconstrói o histórico** pelo `submitted_at`. Painel `/admin/registros`
> filtra por tipo, período e busca, e exporta **CSV** (UTF-8 com BOM, separador `;`) do mesmo recorte.
> Back **311/311**, front **88/88**, Pint limpo, build OK.
>
> **Sprint 15 (branch `feat/avaliador-likert-continuidade`, saída da `main`):**
> (a) **Cadastro do avaliador**: a titulação passa a trazer a **situação** (`Especialização/
> Mestrado/Doutorado` × `em andamento`/`concluído`) e é validada contra
> `AvaliadorProfile::TITULACOES`; aviso no formulário e no login deixam explícito que
> **pós-graduação em curso já habilita**.
> (b) **Card de avaliação**: o **vídeo é embutido** logo abaixo do link (mesmo `VideoPreview`
> do orientador), então o avaliador não precisa abrir o link.
> (c) **Projeto de continuação**: quando o projeto tem o documento anexado
> (`Projeto::temProjetoDeContinuacao()`), a rubrica ganha um **4º quesito**. Ele não soma à
> parte — o quesito "projeto de pesquisa" entra na nota pela **média** entre os dois
> documentos, então o **teto continua 15** para todo projeto e o ranking segue comparável
> (a coluna `nota` virou `decimal(4,1)` por causa do meio ponto).
> (d) **Escala Likert de 5 pontos** (1 = muito insatisfeito … 5 = muito satisfeito) no lugar
> do 0–10; a escala vem do backend (`Avaliacao::ESCALA`) e o front só desenha. **Nota final
> 3–15**. A migration **reescala proporcionalmente** as avaliações já concluídas.
> (e) **Dependências**: as **9 branches do dependabot** foram mescladas nesta mesma branch —
> composer (`laravel/framework` 13.25.0, `laravel/pao` 1.1.4, `laravel/pint` 1.30.5,
> `mockery/mockery` 1.6.13) e npm (`axios` 1.19.0, `react` 19.2.8, `tailwindcss` 4.3.3,
> `vitest` 4.1.10, `@testing-library/jest-dom` **7.0.0**, único major). Dois pares foram
> alinhados à mão porque andam juntos: `react-dom` → 19.2.8 e `@tailwindcss/vite` → 4.3.3
> (o plugin fixa a versão exata do `tailwindcss`).
> Back **320/320**, front **96/96**, Pint limpo, build OK.
> A Sprint 15 entrou na `main` pelo PR **#53** (v1.13).
>
> **Sprint 16 (branch `feat/rubrica-fetecms-2025`, saída da `origin/main` @ `227377c`):**
> a tela de avaliação passou a ser o documento **"Perguntas de Avaliação FETECMS"**.
> (a) **17 perguntas pontuadas em 10 seções**, com o peso de cada uma; a nota final virou a
> **soma ponderada de 0 a 10** (8,0 projeto de pesquisa + 2,0 vídeo). A seção *Resultados e
> discussão* usa **1/3 por pergunta** (e não os 0,33 da coluna do PDF) para fechar 1,00 na
> seção e 10,00 no total, como manda a tabela da última página.
> (b) **Duas métricas**: escala **0–10 de dois em dois** (Não possui … Muito bom) e **Sim/Não**
> (Sim = peso cheio). A Likert de 5 pontos da Sprint 15 saiu.
> (c) **Balão de dúvida "?"** por pergunta com as *Orientações para o Avaliador*; a pergunta de
> "domínio do tema" no vídeo ficou **sem balão** (o PDF traz `????` nessa linha).
> (d) **Wizard**: um passo por seção, com atalhos no topo, nota parcial e contagem de
> respondidas no rodapé — e **rascunho salvável em qualquer passo**.
> (e) **Recomendações em dois campos opcionais**: sobre o **vídeo** (junto das perguntas do
> vídeo) e sobre o **projeto** (passo final).
> (f) **A avaliação do projeto de continuidade foi removida**: o documento segue na leitura do
> avaliador, mas não é pontuado à parte — o teto é o mesmo para todo projeto, sem média.
> (g) **Banco**: `respostas` (JSON) substitui `nota_video`/`nota_resumo`/`nota_pesquisa`/
> `nota_continuidade`; `comentario_video` virou a recomendação do vídeo e `comentario_projeto`
> nasceu; `nota` virou `decimal(5,2)`. A migration **reescala de 0–15 para 0–10** as avaliações
> já concluídas (as respostas antigas não têm tradução para as perguntas novas).
> (h) **Ranking do admin**: médias por **seção** no lugar das médias por quesito.
> Back **322/322**, front **98/98**, Pint limpo, build OK.
> As Sprints 16 e 17 entraram na `main` pelo PR **#54** (v1.14).
>
> **Sprint 17 (mesma branch `feat/rubrica-fetecms-2025`):** o avaliador ganhou uma seção
> **Perfil** no menu (`/avaliador/perfil`).
> (a) **Três cards de estatística**: projetos avaliados (avaliações concluídas), carga horária
> do certificado (**2h30 por avaliação**, `AvaliadorProfile::MINUTOS_POR_AVALIACAO`) e posição
> no **ranking de avaliadores** por número de projetos avaliados. Entra no ranking quem já
> concluiu ao menos uma; quem empata **divide a posição** (dois em 1º, ninguém em 2º).
> (b) **Troca da própria área/subárea**, permitida **só fora do período de avaliação** — a
> regra é `AvaliadorService::podeTrocarClassificacao()` e vale inclusive para o avaliador demo
> (o modo teste adianta a avaliação, não a troca de área). Liberado o período, a tela mostra a
> área em leitura com o motivo. Trocar de área **não refaz as designações** já feitas pelo
> admin — a tela avisa quando há projetos designados.
> (c) `GET /avaliador/perfil` e `PUT /avaliador/perfil/classificacao`.
> Back **337/337**, front **108/108**, Pint limpo, build OK.
>
> **Sprint 18 (branch `feat/mala-direta`, saída da `origin/main` @ `912761a`):** o admin ganhou
> a seção **Mala direta** no menu.
> (a) **Públicos combináveis** (`PublicoMala`) + **lista personalizada** digitada (aceita
> `Nome <email>`) ou importada de `.csv` (`email`/`nome`, separador `;`, `,` ou tab, com ou
> sem cabeçalho). A união é deduplicada por e-mail guardando **todas as origens**; admin,
> conta inativa e conta demo nunca entram nos públicos.
> (b) **Prévia** (`POST /admin/mala-direta/previa`): quantos recebem, quantos são inválidos,
> o total de cada público, listagem paginada e **export CSV** com os projetos do orientador.
> (c) **Disparo** com confirmação da mensagem na tela. A lista vira **snapshot**
> (`mala_direta_destinatarios`) — o relatório precisa dizer para quem foi mesmo que a pessoa
> troque de e-mail depois. **Um job por destinatário** (`EnviarMalaDireta`, 3 tentativas): a
> recusa de um servidor vira uma linha de falha e não derruba o resto.
> (d) **Progresso** por polling e **relatório** por situação (`enviado`/`falha`/`invalido`),
> com o motivo de cada problema, **reenvio só das falhas** e export CSV.
> (e) O corpo aceita `{{nome}}` (primeiro nome) e `{{email}}`; o **solicitante é metadado
> interno** e não aparece para o destinatário. Layout próprio em `emails/mala-direta`
> (HTML + versão texto), sem o tema markdown do Laravel.
> (f) **Exige `php artisan queue:work` no deploy** — sem worker a mala fica em "Enviando".
> Back **354/354**, front **136/136**, Pint limpo, build OK.

### Roadmap de sprints (proposto)

- **Sprint 1** — E0 Fundação + E1 Auth & perfil do orientador.
- **Sprint 2** — E2 Catálogos + E3 Projetos (CRUD/rascunho).
- **Sprint 3** — E4 Integrantes (alunos 1–4 por categoria, coorientador) + E5 Uploads.
- **Sprint 4** — E6 Submissão & checklist (irreversível) + E7 Avaliador (cadastro/login + exclusão mútua).
- **Sprint 5** — E8 Admin & dashboard (9 métricas) + E9 Qualidade/segurança/carga.

#### Ajustes pós-v1 (Sprints 6–10)

- **Sprint 6** — Localidades: semear todas as cidades do Brasil (IBGE) + endereço do orientador
  por FK (cascata estado→cidade no cadastro e no perfil, espelhando o projeto) + máscara de CEP.
- **Sprint 7** — Catálogo unificado de área/subárea: combobox "digite/crie" reutilizável, criação
  global de subárea (dedupe + rate limit), unificação do cadastro do orientador no catálogo,
  remoção de "Multidisciplinar".
- **Sprint 8** — Admin **Parametrização** (menu lateral): renomear, **mesclar** (reatribui
  referências) e excluir áreas/subáreas.
- **Sprint 9** — Erros 100% em pt_BR (auditoria + testes) + favicon + rodapé com e-mail de suporte
  (`fetecms@gmail.com`).
- **Sprint 10** — Instituições de ensino: importar a lista de MS (federais/estaduais/municipais/
  particulares) de `escolas_ms.csv` (colunas `MUNICÍPIO, ZONA, CÓDIGO DO INEP, UNIDADE ESCOLAR,
  TIPO`) + **combobox "digite/crie"** de instituição no cadastro do orientador e no projeto
  (criação global, como as subáreas).

#### Ciclo pós-avaliação

- **Sprint 18** — Mala direta do admin (públicos, lista personalizada/CSV, prévia, disparo pela
  fila, progresso e relatório de falhas).

**Decisões travadas (deste ciclo):** endereço sempre por FK no Brasil (texto livre só fora do
Brasil); área/subárea sempre do **mesmo catálogo** em todos os formulários; subárea criada por
usuário fica **global na hora** (com limpeza/mescla pelo admin em Parametrização); subárea é
**opcional** em todo formulário.

## Convenções ao desenvolver

- Controllers finos; lógica em Services; validação em FormRequests (com `prepareForValidation`
  para limpar CPF/telefone); respostas via API Resources (nunca expor path interno de arquivo).
- Mensagens de validação em `pt_BR`.
- Testes acompanham cada feature na própria sprint (não deixar para o fim do projeto).
