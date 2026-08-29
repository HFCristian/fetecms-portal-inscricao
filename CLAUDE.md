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

**Confirmação de e-mail (orientador e avaliador):** o formulário público **não cria a conta**. Ele
vira uma linha em `cadastros_pendentes` (payload em JSON, senha **já hasheada**) e um **código de 6
dígitos** vai por e-mail — válido por **15 minutos**, com **5 tentativas** e reenvio a cada 60s. A
conta nasce em `POST /cadastros/{token}/confirmar`, que já autentica a sessão. Como nada ocupa
`users`/`orientador_profiles` antes disso, **o mesmo CPF pode ser cadastrado de novo** por quem errou
o endereço — e a tela mostra o e-mail digitado, com a opção de **corrigi-lo sem refazer o formulário**
(`ConfirmacaoCadastroService`). Todo campo de e-mail do portal perde os espaços (de qualquer posição,
inclusive o não-quebrável do copiar/colar) antes de ser gravado — trait `NormalizaEmail`.

- **Orientador**: cadastro completo (wizard 3 etapas) → **confirmação do e-mail por código de 6
  dígitos** → lista de projetos → cadastro de projeto (salvável como **rascunho**) → alunos →
  coorientador opcional → resumo → **submissão irreversível** (que dispara o comprovante por e-mail).
  Depois da avaliação online ele ainda tem a aba **Ajustes** (`/ajustes`, abaixo de *Meus Projetos*):
  dentro do **período de ajustes** definido pelo admin, vê o que os avaliadores sugeriram em cada
  projeto seu e **aceita ou desfaz a troca de área/subárea** — aceitar aplica na hora e vira registro;
  a sugestão continua na tela até o fim do prazo, para ele poder mudar de ideia. As **recomendações
  escritas** (vídeo e projeto) aparecem junto, só para leitura, e o avaliador é anônimo
  ("Avaliador 1", "Avaliador 2"). Fora do período a aba aparece no menu mas não abre; o orientador
  **demo** tem *modo de teste*, que ignora as datas (`AjustesOrientadorService`).
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
    horária do certificado** (**2h30 por avaliação concluída**, com **teto de 120 horas** —
    `AvaliadorProfile::MAX_MINUTOS_CERTIFICADO`) e **posição no ranking** de quem
    mais avaliou (só entra quem já concluiu ao menos uma; empate divide a posição). Na mesma
    tela ele **troca a própria área/subárea — só enquanto o período de avaliação não começou**
    (`Edicao::avaliacaoLiberada()`), porque depois a distribuição já foi feita em cima dela.
  - Cada projeto passa pelo **mínimo de avaliadores definido pelo admin** (padrão 3), com *match* por
    **subárea** (preferencial), **área** ou **área correlata** — o grupo de áreas irmãs configurado em
    Parametrização → Áreas. Concluída uma avaliação, o avaliador **recebe outro projeto na hora**, e
    ele pode **sortear de novo** o que ainda não abriu (o que está em avaliação e o que o admin
    designou permanecem).
  - **Distribuição automática**: casa subárea do projeto ↔ subárea do avaliador; se não houver,
    cai para a **mesma área**. (Algoritmo ainda a refinar.)
  - Cada projeto fica visível para **no máximo 5 avaliadores**.
  - O **admin pode designar manualmente** projetos a avaliadores, podendo **exceder o limite de 3**.
- **Admin**: criado **somente por outro admin** (cadastro simples: nome, e-mail, senha). Dashboard
  com as métricas: projetos totais / submetidos / em rascunho; **projetos por categoria**;
  orientadores; alunos; coorientadores; **camisetas por tamanho** (um card para orientadores, um
  para alunos e um para coorientadores, PP…XG + N.I., via `App\Support\Camisetas`); escolas, cidades
  e estados **com projeto cadastrado**.
  Fora dos dois primeiros cards (que existem para mostrar o rascunho), **todo card conta só
  projetos submetidos** — categoria, pessoas e localidades. Orientador entra **uma vez**, tenha
  um ou vários submetidos (`AdminDashboardService`).
  - **Parametrização → Inscrições** (`/admin/parametrizacao/inscricoes`): a **janela de
    inscrição** da edição — **abertura** (`edicoes.submissoes_de`) e **prazo de submissão**
    (`edicoes.submissoes_ate`), ambos hora de parede de Campo Grande. Fora da janela a área do
    orientador fica **só de leitura** — não cria, não edita (projeto, alunos, coorientador,
    anexos), não submete, não cancela a submissão e não exclui; quem cancelou o envio para
    editar **não reenvia** depois do prazo. **Antes da abertura** o cadastro público de
    orientador também fica fechado (o de avaliador não). O admin passa por cima (escape do
    edital). A regra mora no `InscricoesService` e é aplicada pelos middlewares
    `inscricoes.abertas` (rotas que escrevem em projeto; GET sempre passa) e
    `inscricoes.iniciadas` (cadastro de orientador), além do `SubmissaoService` (que também
    explica o motivo em `pode_desfazer`/`impedimentos_desfazer`). Cada ponta sem data fica
    aberta; a tela de cadastro lê `GET /inscricoes/publico` para avisar antes da abertura.
  - **Parametrização → Avaliação Online** (`/admin/parametrizacao/avaliacao`): **início**
    (`edicoes.avaliacao_liberada_em`) e **fim** (`edicoes.avaliacao_encerrada_em`) do período, mais
    os **limites de avaliação** (`App\Support\LimitesAvaliacao`): **por avaliador**, o mínimo é o
    tamanho da fila e o máximo é o teto de avaliações que ele acumula (em branco = sem teto); **por
    projeto**, o mínimo é o alvo da distribuição e o máximo é quantos avaliadores enxergam o
    projeto — e esse par pode ser definido **por categoria**, seguindo o geral quando fica em branco.
    Encerrado, o avaliador ainda **lê** os projetos designados e o que respondeu, mas não
    inicia, não salva rascunho e não envia (`AvaliacaoFluxoService::podeVer()` vs
    `podeAvaliar()`); o demo em modo teste ignora as duas datas. Na mesma tela ficam o **início** e
    o **fim do período de ajustes** (`edicoes.ajustes_de`/`ajustes_ate`), a janela da aba **Ajustes**
    do orientador — **sem data de início ela fica fechada**, ao contrário das outras janelas. O "período começou" que trava
    o cancelamento de submissão e a troca de área do avaliador continua sendo só o início.
  - **Avaliação Online → Algoritmo de distribuição** (`/admin/avaliacao/distribuicao`, aberta pelo
    botão *Abrir configurações* na aba): os limiares que o algoritmo respeita. Por **categoria**, o
    admin liga/desliga a participação e define a **faixa de avaliações que o projeto já recebeu**
    (as concluídas; de/até, "até" em branco = sem teto) para ele ainda aceitar avaliador novo — a
    conta é **por projeto**, não pela carga do avaliador: "FUNDECT de 0 a 1" designa só os projetos
    dessa categoria com nenhuma ou uma avaliação recebida. As regras moram em
    `edicoes.distribuicao_regras` (JSON, via `App\Support\RegrasDistribuicao`) e valem para a
    distribuição em massa, para a reposição da fila e para o sorteio; a **designação manual do
    admin passa por cima**. Na mesma seção ficam **Distribuir avaliações** (completa o que falta,
    idempotente) e **Redistribuir avaliações** (devolve ao bolo tudo que foi apenas designado e
    sorteia de novo — o que está **em avaliação**, o concluído e o designado à mão não se mexem),
    mais o toggle **designar ao cadastrar** (`edicoes.distribuicao_ao_cadastrar`): ligado, o
    avaliador que acaba de se cadastrar já sai com a fila cheia.
  - **Avaliação Online → Ranking dos projetos → Gerar lista final**: exporta em **TXT** o recorte
    que vai para a programação da feira. O admin passa por **três passos** — quantos projetos por
    **categoria**, quantos por **área dentro de cada categoria** e quantas dessas vagas ficam
    reservadas ao **interior** (escola fora da capital do estado, via `cidades.capital`) —, cada
    quantidade em **número fixo ou porcentagem** do recorte acima dela (`App\Support\Cota`): "100 da
    FUNDECT, 20 de agrárias, 70% desses para o interior". Campo em branco não limita; 0 deixa o
    recorte de fora. A reserva do interior é **piso**, não teto: a vaga que ele não preencher volta
    para os demais numa segunda passada, e ela só vale onde a área tem cota. Entram os **mais bem
    avaliados** que couberem em todas as cotas. O arquivo sai por categoria (FETECMS → FETEC Jr → FETECMS FUNDECT) → área em ordem
    alfabética → título, com o sequencial `001, 002…` reiniciando a cada categoria+área:
    `FET.AGR-001 - Título` / `Escola / Cidade - UF` / alunos em ordem alfabética /
    `Orientador - Orientador(a)`. As siglas de categoria são FET, JR e PIC; as de área saem de
    `areas.sigla` (AGR, BIO, SAU, EXA, HUM, SOC, ENG, LIN), editável em Parametrização → Áreas.
  - **Avaliação Online → Projetos submetidos**: além de *Designar*, cada linha tem **Editar**, que
    abre a correção manual de **categoria, área, subárea e link do vídeo**. É um escape do edital (o
    orientador não mexe depois de submeter), então a **justificativa é obrigatória** e cada campo
    alterado vira um registro em **Registros → Projetos** com o "de → para"
    (`AdminProjetoEdicaoService`). No topo da tela, um **card destacado** soma todas as áreas:
    quantos projetos estão com 0, 1, 2 e 3+ avaliações concluídas, sempre no recorte dos filtros.
  - **Registros** tem três seções: **Inscrições**, **Avaliação Online** e **Projetos**
    (`/admin/registros/projetos`) — esta última com as correções do admin e os aceites do orientador,
    cada um com a justificativa.
  - **Comunicação → Avisos** (`/admin/comunicacao/avisos`): o admin publica um card com **título e mensagem livres**,
    que aparece para os **orientadores ativos** conectados em até ~1 min (polling; não há
    WebSocket no projeto) e pode ser fechado por cada um. **Um ativo por vez** — publicar um
    novo encerra o anterior; dá para encerrar à mão. O texto aceita `{{prazo_inscricoes}}`,
    `{{tempo_restante}}` e `{{inicio_avaliacoes}}` (`AvisoService::VARIAVEIS`), inseridas por
    **botões abaixo do campo, na posição do cursor**, e o formulário abre com um **modelo
    pronto**. As variáveis são resolvidas **na hora da leitura** — "faltam X minutos" é o tempo
    real de quem está lendo. **Relatório** por aviso: quantos viram, fecharam e ainda não viram,
    com a lista nome a nome (filtro por situação, busca e export CSV) e o histórico dos avisos
    anteriores.
  - **Comunicação → Modelos de e-mail** (`/admin/comunicacao/modelos`): o texto dos e-mails que o
    portal manda sozinho — **confirmação de cadastro** e **projeto submetido**. O admin edita
    assunto e corpo, insere as variáveis de cada modelo por botões e pode **restaurar o padrão**. O
    texto de fábrica mora no enum `App\Enums\ModeloEmail`; a tabela `modelos_email` guarda **só o
    que foi customizado** (salvar exatamente o padrão apaga a linha). Quem dispara pede a mensagem
    ao `ModeloEmailService` e não sabe de onde o texto veio.
  - **Comunicação → Mala direta** (`/admin/mala-direta`): comunicado por e-mail para um recorte da base.
    O admin combina quantos **públicos** quiser (todos, orientadores, avaliadores, orientadores
    com rascunho, com submetido, avaliadores com avaliação **em andamento** ou **concluída**) e/ou
    cola uma **lista personalizada** (digitada ou importada de `.csv` com as colunas `email`/`nome`).
    A união é **deduplicada por e-mail**; contas de admin, inativas e demo ficam fora dos públicos.
    Antes de disparar ele vê **quantos recebem**, pode **listar** e **exportar CSV** (nome, e-mail,
    papel, origem, títulos e quantidade de projetos do orientador) e precisa **confirmar a mensagem**.
    O envio vai para a **fila** (um job por destinatário) com **tela de progresso**; o relatório traz
    a situação de cada endereço, o motivo das falhas e o **reenvio só das falhas**. E-mail malformado
    entra como `invalido` no relatório em vez de barrar o disparo. Campos da mala: nome, justificativa,
    solicitante (opcional — metadado interno, não vai no e-mail), assunto e texto. O texto aceita as
    variáveis `{{nome}}` — primeiro nome —, `{{nome_completo}}` e `{{email}}` (com ou sem espaços
    dentro das chaves; sem nome conhecido, o tratamento vira "participante"), inseridas por **botões
    abaixo do campo, na posição do cursor**. A lista mora em `MalaDiretaService::VARIAVEIS` e chega à
    tela por `GET /admin/mala-direta/opcoes` — é a mesma que o `personalizar()` percorre, então
    acrescentar uma variável lá já a faz aparecer no formulário. O texto é escrito num **editor rico**
    (TipTap): **negrito, itálico, sublinhado, traçado**, listas e **imagens no corpo** (até **5**,
    **10 MB** cada, arrastáveis para reposicionar), mais **anexos** no e-mail (até **10**, **20 MB**
    cada). O corpo vai como **HTML sanitizado** (`App\Support\HtmlEmail`) e as imagens viajam
    **embutidas por CID**, nunca por link; os arquivos ficam em `mala_direta_arquivos`, num disco
    privado, e só são vinculados à mala no disparo.

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
| 19 | Perfil do avaliador: remoção do campo "Limite de avaliações" | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 20 | Projetos submetidos (admin): busca de avaliador por nome, lista alfabética, áreas compactáveis e ordenação por métrica | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 21 | Avaliadores por área (admin): áreas compactáveis e ordenação por métrica | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 22 | Aba "Inscrições" + prazo de submissão (bloqueia submeter/cancelar/editar depois da data) | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 23 | Avisos na tela: composição (título/mensagem/variáveis/modelo) + card no orientador | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 24 | Avisos: relatório de quem viu/fechou/não viu + histórico + export CSV | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 25 | Avaliadores Online: cards + gráfico da aba "Avaliadores" migrados para a tela e remoção da aba | ✅ sim | ❌ não (manual do Pedro) | 3 |
| 26 | Parametrização: janela de inscrição (abertura + prazo) e período de avaliação (início + fim) | ✅ sim | ❌ não (manual do Pedro) | 3 |
| 27 | Aba Comunicação (mala direta + avisos) e remoção da aba Inscrições | ✅ sim | ❌ não (manual do Pedro) | 3 |
| 28 | Projetos por área: cards por área (submetidos/rascunho) + áreas compactáveis | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 29 | Aba "Acesso": e-mail e senha na mesma tela + menu do admin reordenado | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 30 | Painel do admin: categoria, orientadores, alunos e coorientadores contam só projetos submetidos | ✅ sim | ✅ sim (Pedro, PR #59) | 0 |
| 31 | Áreas correlatas: `areas.grupo_correlato` + fallback da distribuição para área irmã | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 32 | Parametrização → Avaliação Online: mínimo de avaliações por avaliador e por projeto | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 33 | Painel do avaliador: abas "A avaliar" e "Avaliados" | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 34 | Reposição automática da fila ao concluir (prioridades área+subárea → área → correlata → sorteio) | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 35 | Botão "Sortear outros projetos" (só as designadas não iniciadas) | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 36 | Registros em duas seções (Inscrições / Avaliação Online) + log das parametrizações | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 37 | Avaliadores Online: tabela única com busca, filtro, ordenação e CSV | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 38 | Avaliador: comissão especial + áreas/subáreas extras liberadas pelo admin | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 39 | Projetos submetidos: tabela única com busca, filtros, ordenação e CSV | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 40 | Projetos submetidos: cards de resumo por área (0/1/2/3+ avaliações), presos aos filtros | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 41 | Designação ao comitê especial (completa ou selecionada) | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 42 | Ranking dos projetos: filtro por categoria | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 43 | Ranking dos avaliadores (nome, área, números e localidade) + estado/cidade no avaliador | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 44 | Comissão especial como público da mala direta | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 45 | Avisos com públicos combináveis (moldes da mala direta) | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 46 | Avisos com data de expiração na tela | ✅ sim | ✅ sim (Pedro, PR #60 → v1.17) | 0 |
| 47 | Algoritmo de distribuição: seção própria, regras por categoria e "Distribuir" movido para lá | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 48 | Redistribuir avaliações + toggle "designar ao cadastrar avaliador" | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 49 | Ranking: "Gerar lista final" em TXT (cotas por total/categoria/área) + sigla da área | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 50 | Parametrização: mínimo E máximo por avaliador e por projeto (por categoria) | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 51 | Algoritmo de distribuição em tela própria (`/admin/avaliacao/distribuicao`), aberta por botão na aba | ✅ sim | ❌ não (manual do Pedro) | 3 |
| 52 | Painel: 3 cards de camiseta (orientadores, alunos, coorientadores) por tamanho | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 53 | Confirmação de e-mail no cadastro (código de 6 dígitos, cadastro pendente, CPF liberado, trim do e-mail) | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 54 | Comunicação → Modelos de e-mail: admin edita assunto/texto dos e-mails automáticos | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 55 | Comprovante de submissão por e-mail (título, data e categoria) | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 56 | Projetos submetidos: admin corrige categoria, área, subárea e vídeo (justificativa obrigatória) | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 57 | Registros → Projetos: seção própria para as correções do admin | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 58 | Perfil do avaliador: teto de 120h no certificado | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 59 | Lista final: cotas aninhadas categoria → área → interior, em número ou porcentagem | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 60 | Projetos submetidos: card destacado com o total geral por número de avaliações | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 61 | Aba "Ajustes" do orientador: aceitar/desfazer as sugestões dos avaliadores no período parametrizável | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 62 | Mala direta: editor de texto rico (negrito, itálico, sublinhado, traçado, listas) + imagens no corpo | ✅ sim | ❌ não (manual do Pedro) | 4 |
| 63 | Mala direta: anexos no e-mail (até 10, 20 MB cada) | ✅ sim | ❌ não (manual do Pedro) | 4 |

> **Sprints 62–63 (mesma branch):** a mala direta ganhou editor e arquivos.
> (a) **Sprint 62** — o campo de texto virou um **editor rico** (`EditorTexto.jsx`, TipTap —
> dependência nova) com **negrito, itálico, sublinhado, traçado**, listas e **imagens no corpo**
> (até **5**, **10 MB** cada), arrastáveis para reposicionar. O corpo passa a ser **HTML**
> (`malas_diretas.formato`), **sanitizado na gravação** por `App\Support\HtmlEmail` — só passa
> uma lista curta de tags e atributos, e `script`/`style`/`on*`/`javascript:` somem. As variáveis
> (`{{nome}}`…) continuam sendo inseridas na posição do cursor, agora pelo editor. No e-mail, cada
> imagem vai **embutida (CID)**, nunca por link para o portal — imagem remota costuma ser bloqueada
> na caixa de entrada —, e a versão text/plain é gerada do HTML.
> (b) **Sprint 63** — **anexos**: até **10** arquivos de **20 MB** cada (imagens, PDF, documentos,
> planilhas, .zip), listados no formulário com tamanho e remoção. Os dois tipos moram em
> `mala_direta_arquivos`: sobem **antes** de a mala existir, ficam soltos até o disparo (que os
> vincula) e o que nunca for usado é apagado na faxina do upload seguinte. O disco é o **privado** —
> a prévia no painel é servida por rota autenticada (`GET /admin/mala-direta/arquivos/{id}`).
> Back **575/575**, front **254/254**, Pint limpo, build OK.
>
> **Sprints 60–61 (mesma branch):**
> (a) **Sprint 60** — "Projetos submetidos" ganhou, acima dos cards por área, um **card destacado**
> (fundo roxo) com o **total geral**: quantos projetos do recorte estão com 0, 1, 2 e 3+ avaliações
> concluídas. Ele responde aos mesmos filtros da tabela (`meta.resumo_geral`).
> (b) **Sprint 61** — o orientador ganhou a aba **Ajustes** (`/ajustes`, abaixo de *Meus Projetos*).
> Terminada a avaliação online, dentro do **período de ajustes** (`edicoes.ajustes_de`/`ajustes_ate`,
> em Parametrização → Avaliação Online), ele abre cada projeto e vê o que os avaliadores sugeriram:
> **aceita ou desfaz a troca de área/subárea** — aceitar aplica no projeto **na hora** e entra em
> Registros → Projetos no nome dele — e lê as **recomendações escritas** (vídeo e projeto), que são
> só leitura. A sugestão **nunca some**: fica na tela, marcada como *em vigor*, até o fim do prazo,
> então dá para mudar de ideia. Só uma sugestão do mesmo tipo vale por vez (aceitar outra desliga a
> anterior) e desfazer devolve o valor anterior, guardado em `projeto_ajustes`. O avaliador é
> **anônimo** para o orientador ("Avaliador 1", "Avaliador 2"). Sem data de início a aba fica
> **fechada** — aparece no menu e explica o motivo ao abrir; o **orientador demo** tem o mesmo
> *modo de teste* do avaliador demo, que ignora as datas. `AjustesOrientadorService`,
> `GET/POST /ajustes/...`, `PATCH /admin/avaliacao/ajustes`.
> Back **567/567**, front **249/249**, Pint limpo, build OK.
>
> **Sprints 58–59 (mesma branch):**
> (a) **Sprint 58** — o certificado do avaliador tem **teto de 120 horas**
> (`AvaliadorProfile::MAX_MINUTOS_CERTIFICADO`). A carga exibida satura ali, o card diz "máximo de
> 120h" e, quem chega no teto, "Limite máximo de 120h atingido". Avaliar além disso continua
> valendo para o ranking — só o certificado não passa.
> (b) **Sprint 59** — **Gerar lista final** virou um **assistente de três passos**, na ordem em que
> a organização decide: **categoria → área (dentro da categoria) → interior (dentro da área)**.
> Cada quantidade é **número fixo ou porcentagem** do recorte que a contém, então "100 da FUNDECT,
> 20 de agrárias, 70% desses para o interior" é exatamente o que se digita. As cotas de área agora
> valem **por categoria** (antes eram globais). A reserva do interior é **piso**: uma segunda
> passada devolve aos demais a vaga que o interior não preencheu, e ela só existe onde a área tem
> cota — sem número fechado não há o que reservar. "Interior" é a cidade da escola que **não é a
> capital do estado**, via a coluna nova `cidades.capital` (backfill das 27 capitais, `App\Support\Capitais`).
> A leitura das cotas mora em `App\Support\Cota`.
> Back **558/558**, front **244/244**, Pint limpo, build OK.
>
> **Sprints 56–57 (mesma branch):** o admin passou a poder corrigir a inscrição de outra pessoa.
> (a) **Sprint 56** — na tabela de **Projetos submetidos**, cada linha ganhou **Editar** ao lado de
> *Designar*: um diálogo com **categoria, área, subárea e link do vídeo** e uma **justificativa
> obrigatória**. É um escape do edital (o orientador não mexe depois de submeter), então cada
> **campo alterado** vira um registro com o "de → para" e a justificativa; campo sem mudança não
> gera registro. Trocar a área **sem informar a subárea limpa a subárea**, porque a antiga pertence
> a outra árvore, e a subárea é validada contra a área que vai valer depois da correção.
> `PATCH /admin/avaliacao/projetos/{projeto}` (`AdminProjetoEdicaoService`).
> (b) **Sprint 57** — **Registros** ganhou a terceira seção, **Projetos**
> (`/admin/registros/projetos`), com os quatro tipos novos do `TipoRegistro`
> (`projeto_categoria`, `projeto_area`, `projeto_subarea`, `projeto_video`), os mesmos filtros das
> outras seções e o CSV descrevendo "de → para · justificativa".
> Back **553/553**, front **240/240**, Pint limpo, build OK.
>
> **Sprints 54–55 (mesma branch):** os e-mails automáticos do portal ganharam dono.
> (a) **Sprint 54** — **Comunicação → Modelos de e-mail** (`/admin/comunicacao/modelos`): o admin
> edita **assunto e texto** de cada e-mail automático, com os botões de variável inserindo na
> posição do cursor e um **Restaurar padrão**. O padrão mora no enum `App\Enums\ModeloEmail`
> (label, descrição, texto de fábrica e variáveis aceitas) e a tabela `modelos_email` guarda **só
> o que foi customizado** — salvar exatamente o texto padrão apaga a linha, então o modelo volta a
> acompanhar mudanças futuras de fábrica. Quem dispara pede a mensagem ao `ModeloEmailService` e
> não sabe de onde o texto veio. `GET/PUT/DELETE /admin/modelos-email/{modelo}`.
> (b) **Sprint 55** — submeter o projeto manda o **comprovante por e-mail** ao orientador, com
> **título, data, hora e categoria** (`NotificacaoProjetoService`, modelo `projeto_submetido`). O
> envio vai para a **fila** e qualquer falha é engolida com log: a submissão é irreversível e já
> está gravada, então servidor de e-mail fora do ar não pode derrubar a resposta. Só a requisição
> que efetivou a submissão envia — reenviar o POST não gera segundo comprovante.
> Back **546/546**, front **237/237**, Pint limpo, build OK.
>
> **Sprints 52–53 (branch `feat/verificacao-email-e-ajustes`, saída da `origin/main` @ `60b8e27`):**
> (a) **Sprint 52** — o painel do admin ganhou **três cards de camiseta** (orientadores, alunos e
> coorientadores), cada um com a contagem por tamanho **PP, P, M, G, GG, XG + N.I.**. A conta vem
> de `App\Support\Camisetas` e segue a regra dos demais cards: **só projetos submetidos**, com o
> orientador contando uma vez. O balde **N.I.** é o total menos os tamanhos conhecidos, então a
> linha sempre fecha com o número grande do card.
> (b) **Sprint 53** — **confirmação de e-mail** no cadastro de orientador e de avaliador. O
> formulário **não cria mais a conta**: ele vira uma linha em `cadastros_pendentes` (payload em
> JSON, **senha já hasheada**) e um **código de 6 dígitos** vai por e-mail, válido por **15
> minutos**, com **5 tentativas** e reenvio a cada 60s. A conta nasce em
> `POST /cadastros/{token}/confirmar`, que já loga a sessão. Como nada ocupa
> `users`/`orientador_profiles` antes disso, **o mesmo CPF pode ser cadastrado de novo** por quem
> errou o e-mail — e quem errou também **corrige o endereço na própria tela**
> (`PATCH /cadastros/{token}/email`), sem refazer o formulário. A corrida por e-mail/CPF é
> reconferida na hora de confirmar. Junto veio o **trim de e-mail**: o trait
> `App\Http\Requests\Concerns\NormalizaEmail` tira espaço de **qualquer posição** (inclusive o
> não-quebrável do copiar/colar) em todo formulário que grava e-mail, e na lista personalizada da
> mala direta. Front: `ConfirmacaoEmail.jsx`, usado pelas duas telas de cadastro.
> Back **538/538**, front **234/234**, Pint limpo, build OK.
>
> **Sprint 51 (mesma branch):** a seção **Algoritmo de distribuição** saiu da landing de
> "Avaliação online" e virou tela própria em `/admin/avaliacao/distribuicao`
> (`AvaliacaoDistribuicao.jsx`), aberta pelo botão **Abrir configurações**. Ela reúne as regras por
> categoria, o toggle de designação ao cadastrar e as ações de **distribuir** e **redistribuir**; a
> aba volta a ser só a janela de datas mais os cards de acompanhamento. Junto, a redação da faixa
> ficou explícita: a contagem é de **avaliações recebidas pelo projeto**, não do trabalho do
> avaliador. Back **527/527**, front **229/229**, Pint limpo, build OK.
>
> **Sprints 49–50 (mesma branch `feat/algoritmo-distribuicao-e-lista-final`):**
> (a) **Sprint 49** — **Gerar lista final** no Ranking dos projetos. O admin escolhe um **total**,
> uma cota **por categoria** e uma **por área** (campo em branco não limita; 0 deixa o recorte de
> fora); entram os **mais bem avaliados** que couberem em todas as cotas ao mesmo tempo. O arquivo
> **TXT** sai agrupado por **categoria** (FETECMS → FETEC Jr → FETECMS FUNDECT) → **área** em ordem
> alfabética → **título**, com o sequencial `001, 002…` reiniciando a cada categoria+área e os
> alunos em ordem alfabética. Formato de cada bloco: `FET.AGR-001 - Título`, `Escola / Cidade - UF`,
> os alunos e `Orientador - Orientador(a)`. Para isso a área ganhou **`areas.sigla`**
> (AGR, BIO, SAU, EXA, HUM, SOC, ENG, LIN), com backfill pelo nome e edição em Parametrização →
> Áreas (`PATCH /admin/areas/{area}/sigla`); área sem sigla cai para as três primeiras letras do
> nome. Tudo no `ListaFinalService`; `GET /admin/avaliacao/lista-final/opcoes` alimenta o diálogo e
> `POST /admin/avaliacao/lista-final` devolve o TXT.
> (b) **Sprint 50** — os mínimos viraram **pares mínimo/máximo** (`App\Support\LimitesAvaliacao`,
> lido de uma vez por operação para não consultar a edição projeto a projeto):
> **por avaliador**, o mínimo continua sendo o tamanho da fila e o novo
> `edicoes.avaliacoes_max_por_avaliador` é o **teto de avaliações que ele acumula** — em branco,
> sem teto, que é o comportamento de sempre; **por projeto**, `edicoes.avaliacoes_max_por_projeto`
> substitui a constante `Avaliacao::TETO_POR_PROJETO` e o par pode ser definido **por categoria**
> em `edicoes.avaliacoes_por_categoria` (em branco, a categoria segue o geral). Distribuição,
> reposição da fila, colunas de *faltantes*, cards de resumo e o "completo" do ranking passaram a
> usar o número da **categoria do projeto**; onde o resumo precisa de um número só, ele diz
> "mínimo da categoria" quando elas divergem. Três tipos novos na trilha de Registros.
> Back **526/526**, front **228/228**, Pint limpo, build OK.
>
> **Sprints 47–48 (branch `feat/algoritmo-distribuicao-e-lista-final`, saída da `origin/main` @ `a9bb314`):**
> a aba **Avaliação online** ganhou a seção **Algoritmo de distribuição**, com os limiares que o
> admin ajusta e as duas ações de distribuição.
> (a) **Sprint 47** — **regras por categoria** (`edicoes.distribuicao_regras`, JSON, lidas pelo
> value object `App\Support\RegrasDistribuicao`): cada categoria tem liga/desliga e uma **faixa de
> avaliações já concluídas** (`de`/`até`, `até` em branco = sem teto) em que o projeto ainda aceita
> avaliador novo — é assim que se pede "só FETEC Jr com 0 avaliações" ou "só FUNDECT". A regra vale
> para a `DistribuicaoService` **e** para a `FilaAvaliadorService` (reposição, sorteio e rodízio); a
> **designação manual do admin passa por cima**. O padrão (tudo ligado, sem faixa) é o comportamento
> histórico, então nada muda para quem não configurar. O card **Distribuir avaliações** mudou de
> lugar para dentro da seção e passou a relatar quantos projetos ficaram de fora pela regra
> (`ignorados_pela_regra`). `GET/PATCH /admin/avaliacao/distribuicao`.
> (b) **Sprint 48** — **`POST /admin/avaliacao/redistribuir`**: cada avaliador ativo devolve ao bolo
> o que **ainda não abriu** e recebe outros no lugar (`FilaAvaliadorService::roletar` para todos +
> uma passada da distribuição para completar a cobertura). **Em avaliação, concluída e designação
> manual não se mexem**; sem alternativa, o mesmo projeto volta — a fila nunca encolhe. E o toggle
> **`edicoes.distribuicao_ao_cadastrar`**: ligado, quem termina o cadastro de avaliador já sai com a
> fila cheia (`AvaliadorService::register` chama a reposição), pelas mesmas regras.
> As duas mudanças entram na trilha de **Registros → Avaliação Online**
> (`avaliacao_regra_distribuicao` e `avaliacao_designacao_ao_cadastrar`, com o "de → para").
> Back **511/511**, front **225/225**, Pint limpo, build OK.
>
> **Sprints 31–46 (branch `feat/designacao-e-comissao-especial`, saída da `origin/main` @ `71bafa8`):**
> ciclo de designação, comissão especial e comunicação segmentada. Um commit a cada duas sprints.
> (a) **Sprint 31** — **áreas correlatas**: `areas.grupo_correlato` (enum `GrupoCorrelato`: *vida*,
> *exatas_engenharias*, *humanidades*), com backfill pelo nome e seleção pelo admin em
> Parametrização → Áreas (`PATCH /admin/areas/{area}/correlacao`). A `DistribuicaoService` casa
> subárea → área → **área irmã**: o projeto só sai do próprio grupo quando a área se esgota.
> Área sem grupo não tem irmã.
> (b) **Sprint 32** — os mínimos deixaram de ser constantes: `edicoes.avaliacoes_min_por_avaliador`
> (que é também **quantos projetos o avaliador vê**) e `edicoes.avaliacoes_min_por_projeto` (alvo da
> distribuição e base de "faltantes"), editáveis em Parametrização → Avaliação Online
> (`PATCH /admin/avaliacao/minimos`, componente `CampoNumeroCard`).
> (c) **Sprint 33** — o painel do avaliador separou **"A avaliar"** de **"Avaliados"**: a API devolve
> `projetos` (fila limitada ao mínimo) e `concluidos` (histórico, com data e nota).
> (d) **Sprint 34** — **`FilaAvaliadorService`**: concluída uma avaliação, a fila é completada na hora
> pela prioridade do edital — área+subárea → área → correlata → **sorteio** (quando tudo que se
> encaixa já bateu o mínimo, com os abaixo do mínimo na frente). Dentro da faixa entra o projeto com
> menos avaliações recebidas e em andamento; respeita `Avaliacao::TETO_POR_PROJETO` (5) e o bloqueio
> individual; demo e inativo ficam de fora.
> (e) **Sprint 35** — **`POST /avaliacao/roletar`** devolve ao bolo o que o avaliador ainda não abriu e
> puxa outros. O que está **em avaliação** e o que o **admin designou** (nova coluna
> `avaliacoes.designacao_manual`) não entram no sorteio; sem alternativa, os mesmos voltam.
> (f) **Sprint 36** — **Registros** virou landing com duas seções: `/admin/registros/inscricoes` e
> `/admin/registros/avaliacao`. `TipoRegistro` ganhou seção e quatro tipos (início, fim e os dois
> mínimos), cada mudança com o "de → para" e o autor; salvar o mesmo valor não gera registro.
> (g) **Sprint 37** — **Avaliadores Online** virou **uma tabela só**: busca por nome/e-mail, filtro por
> área e situação, ordenação por qualquer coluna (inclusive **data de cadastro**), paginação e CSV.
> A designação passou a usar `GET /admin/avaliacao/avaliadores/opcoes`.
> (h) **Sprint 38** — o item do avaliador ganhou **comissão especial**
> (`avaliador_profiles.comissao_especial`, estilo favoritar) e **áreas extras**
> (`avaliador_areas_extras`): o admin libera outras áreas/subáreas, que valem como próprias na
> distribuição e na reposição. Só o admin escreve os dois.
> (i) **Sprint 39** — **Projetos submetidos** virou tabela única (busca por título, filtro por área e
> categoria, ordenação, CSV), com o botão Designar em cada linha.
> (j) **Sprint 40** — **cards de resumo por área** no topo daquela tela: quantos projetos estão com
> 0, 1, 2 e 3+ avaliações concluídas; respondem aos mesmos filtros da tabela.
> (k) **Sprint 41** — designação **ao comitê especial**, inteira ou por seleção
> (`tipo=comissao` + `avaliador_ids`).
> (l) **Sprint 42** — **ranking dos projetos** com filtro por **categoria**, cruzando com o de área.
> (m) **Sprint 43** — nova seção **Ranking dos avaliadores** (nome, área, concluídas, em avaliação,
> cidade/UF), com empate dividindo a posição e demo fora. Para isso, o avaliador ganhou
> **estado/cidade** (opcionais) no cadastro e em `/avaliador/perfil`.
> (n) **Sprint 44** — **comissão especial** virou público da **mala direta**. A consulta de públicos
> saiu para o `PublicoUsuariosService`, hoje compartilhado com os avisos.
> (o) **Sprints 45–46** — **avisos segmentados**: `avisos.publicos` (os mesmos recortes da mala
> direta, combináveis) e `avisos.expira_em`. Vários avisos convivem no ar, um por público, e cada
> pessoa vê **o mais recente que a alcança**; republicar para a mesma seleção encerra o anterior.
> O card passou a valer também para o **avaliador**, e o relatório usa o público do próprio aviso.
> Back **501/501**, front **223/223**, Pint limpo, build OK.
>
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
> **Pendências do Pedro:** (1) `git push origin feat/verificacao-email-e-ajustes` + PR para a `main`
> (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o deploy pela §11 do
> [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem migrations** (cadastros pendentes,
> modelos de e-mail, capital das cidades, período/decisões de ajuste e arquivos da mala direta),
> **nenhuma variável nova de `.env`** e uma **dependência nova de npm** (TipTap) — o deploy precisa
> de `npm ci && npm run build`. A fila (`queue:work`) continua obrigatória e agora também entrega o
> comprovante de submissão. **O envio de e-mail deixou de ser opcional**: sem SMTP configurado
> (`MAIL_MAILER`), ninguém conclui o cadastro, porque o código de confirmação não chega.
> A pendência anterior (`feat/algoritmo-distribuicao-e-lista-final`) segue aguardando push; (2) popular as escolas com
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
> (e) O corpo aceita `{{nome}}` (primeiro nome), `{{nome_completo}}` e `{{email}}`; o **solicitante é metadado
> interno** e não aparece para o destinatário. Layout próprio em `emails/mala-direta`
> (HTML + versão texto), sem o tema markdown do Laravel.
> (f) **Exige `php artisan queue:work` no deploy** — sem worker a mala fica em "Enviando".
> Back **354/354**, front **136/136**, Pint limpo, build OK.
>
> **Sprints 19–21 (branch `feat/inscricoes-e-avisos`, saída da `origin/main` @ `4980136`):**
> (a) **Sprint 19** — o card "Seus dados" do `/avaliador/perfil` não mostra mais o
> **limite de avaliações**, e o payload de `GET /avaliador/perfil` deixou de expor
> `limite_avaliacoes`/`max_por_avaliador`. O recurso continua vivo para o admin
> (botão de cadeado em "Avaliadores por área") e na distribuição automática.
> (b) **Sprint 20** — "Projetos submetidos": a designação escolhe o alvo por **busca
> digitada** (`BuscaCombobox`, mesma UX do `SubareaCombobox`, sem criar), com a lista de
> avaliadores **achatada e em ordem alfabética** (`localeCompare` pt-BR — o agrupamento por
> área da API deixava a busca fora de ordem). Cada área virou uma **lista compactável** com
> **ordenação própria** por realizadas/em avaliação/faltantes, nos dois sentidos.
> (c) **Sprint 21** — mesma lista compactável + ordenação em "Avaliadores por área"
> (em avaliação/já avaliou/faltam).
> (d) O acordeão e a ordenação moram no componente compartilhado `GrupoArea`
> (`useAreasAbertas` + `BotoesExpandir` para "Expandir/Recolher todas"); empate na métrica
> cai para a ordem alfabética. Áreas começam **fechadas**.
> Back **357/357**, front **149/149**, Pint limpo, build OK.
>
> **Sprints 22–24 (mesma branch `feat/inscricoes-e-avisos`):** nasceu a aba **Inscrições**
> (2º item do menu do admin, `/admin/inscricoes`).
> (a) **Sprint 22** — **prazo de submissão** em `edicoes.submissoes_ate`. Depois dele a área do
> orientador fica só de leitura: `InscricoesService` decide, o middleware `inscricoes.abertas`
> barra tudo que escreve em projeto (422 `INSCRICOES_ENCERRADAS`; GET passa; admin passa) e o
> `SubmissaoService` ganhou o mesmo motivo, então `pode_desfazer` já some sozinho na listagem.
> Na tela do orientador, `PrazoInscricoes` lembra a data enquanto está aberto e explica o
> encerramento depois — "Nova inscrição", "Continuar edição" e "Excluir" ficam desabilitados.
> (b) **Sprint 23** — **avisos na tela** (`avisos` + `aviso_visualizacoes`). Título e mensagem
> livres, com botões de variável e modelo pronto; as variáveis são resolvidas na leitura
> (`AvisoService::personalizar`), então `{{tempo_restante}}` conta o tempo de quem lê. O
> `AvisoCard` no `AppShell` só roda para orientador, faz polling de 60s, registra o "visto"
> quando o card entra na tela e o "fechado" quando a pessoa dispensa.
> (c) **Sprint 24** — **relatório**: `GET /admin/avisos` (histórico com contagens),
> `/admin/avisos/{id}` (números), `/leitores` (nome a nome, filtro por situação e busca) e
> `/exportar` (CSV UTF-8 com BOM, separador `;`). A lista é o público de hoje **mais** quem já
> registrou visualização, para uma conta desativada depois de ler não sumir do relatório.
> Back **405/405**, front **180/180**, Pint limpo, build OK.
>
> **Sprints 25–27 (branch `feat/reorganizacao-abas`, saída da `main` @ `b13759c`):** reorganização
> das abas do admin.
> (a) **Sprint 25** — a aba "Avaliadores" **deixou de existir**: os três cards (total/ativos/
> inativos) e o gráfico por área viraram o componente `PanoramaAvaliadores`, no topo de
> "Avaliadores por área", que passou a se chamar **Avaliadores Online**. O endpoint
> `GET /admin/avaliadores` continua o mesmo.
> (b) **Sprint 26** — **Parametrização** ganhou os cards **Inscrições** e **Avaliação Online**.
> Nasceram `edicoes.submissoes_de` (abertura) e `edicoes.avaliacao_encerrada_em` (fim), com
> validação de ordem nos dois pares. Antes da abertura, além do projeto, o **cadastro de
> orientador** fica fechado (`inscricoes.iniciadas` + `GET /inscricoes/publico` para a tela de
> cadastro avisar). Encerrada a avaliação, o avaliador fica em **leitura** (`podeVer` ≠
> `podeAvaliar`; `AvaliacaoModal` recebe `somenteLeitura`). Os quatro campos de data usam o
> mesmo `CampoDataCard`. A aba "Avaliação online" só mostra um resumo das datas, com link para
> a Parametrização.
> (c) **Sprint 27** — a aba "Mala direta" virou **Comunicação** (landing com dois cards:
> *Mala direta* e *Avisos*); a aba **Inscrições sumiu** e sua tela de avisos virou
> `AdminAvisos` em `/admin/comunicacao/avisos` (relatório em `/admin/comunicacao/avisos/:id`).
> Back **424/424**, front **187/187**, Pint limpo, build OK.
>
> **Sprints 28–29 (mesma branch `feat/reorganizacao-abas`):**
> (a) **Sprint 28** — "Projetos por área" (o *Ver mais* do card de projetos) ganhou **um card por
> área do conhecimento** no topo, com **submetidos e rascunho** de cada uma (inclusive a dos
> projetos sem área), e as **áreas viraram listas compactáveis** (`GrupoArea` + `BotoesExpandir`,
> como em "Projetos submetidos"), com os projetos em ordem alfabética de título. Cada grupo de
> `GET /admin/projetos-por-area` passou a trazer `submetidos`/`rascunho` (o formato da resposta
> segue sendo a lista de áreas).
> (b) **Sprint 29** — "Alterar e-mail" e "Alterar senha" viraram **uma tela só**, `/acesso`
> (`Acesso.jsx`, dois formulários), no lugar do antigo item "Alterar senha" no rodapé do menu
> dos três papéis. `/alterar-email` e `/alterar-senha` **redirecionam** para ela. O menu do
> admin foi reordenado para **Projetos · Avaliação online · Comunicação · Suporte ·
> Parametrização · Administradores · Registros**.
> Back **425/425**, front **191/191**, Pint limpo, build OK.
>
> **Sprint 30 (branch `fix/cards-admin-somente-submetidos`, saída da `origin/main` @ `35b6561`):**
> os cards **Projetos por categoria**, **Orientadores**, **Alunos** e **Coorientadores** do painel
> passaram a contar **apenas projetos submetidos**, como os de escolas/cidades/estados já faziam.
> (a) `projetos_categoria` filtra `status = submetido` (só `submetido` mesmo — `aprovado`/
> `rejeitado` ficam de fora, igual ao card "Projetos por status").
> (b) **Orientadores** virou a contagem **distinta** de quem tem ≥ 1 submetido (`whereHas`), então
> quem só tem rascunho ou nenhum projeto sai do card, e quem tem vários continua valendo 1.
> (c) **Alunos**/**Coorientadores** contam por `whereHas('projeto', submetido)` — de quebra, gente
> de projeto excluído (soft delete) deixou de contar.
> (d) O recorte por **gênero** usa exatamente o mesmo conjunto de cada card, então a soma
> F + M + outros continua fechando com o número grande.
> (e) **Sem mudança de contrato nem de tela**: o payload de `GET /admin/dashboard` tem as mesmas
> chaves e os rótulos dos cards ficaram como estavam (decisão do Pedro).
> Back **426/426**, front **191/191**, Pint limpo, build OK.

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
