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
  Encerrado o período de avaliação (`edicoes.avaliacao_encerrada_em`), a tela inicial mostra um
  **aviso de que a avaliação terminou**, com a data e um botão para a aba abaixo — só para quem
  submeteu algum projeto, e só enquanto o período de ajustes não se encerra
  (`GET /ajustes/janela`, `AvisoFimAvaliacao.jsx`).
  Depois da avaliação online ele ainda tem a aba **Ajustes e Pareceres** (`/ajustes`, abaixo de
  *Meus Projetos*): dentro do **período de ajustes** definido pelo admin, abre cada projeto seu e
  encontra num lugar só tudo o que a avaliação disse. O que ele **decide** são as sugestões de
  classificação: **aceita ou desfaz a troca de área/subárea** — aceitar aplica na hora e vira
  registro; a sugestão continua na tela até o fim do prazo, para ele poder mudar de ideia. O que ele
  **lê** são as **etapas da rubrica** agrupadas em pontos fortes, médios e fracos
  (`PareceresOrientadorService`, normalizando cada seção em 0 a 10 e cortando em 8 e 4) e as
  **recomendações escritas** (vídeo e projeto). O avaliador é anônimo ("Avaliador 1",
  "Avaliador 2") e **nota nenhuma aparece** — nem a de cada etapa nem a que o projeto recebeu, que
  não chega sequer no payload: o número é da organização, que o discute no Ranking.
  São **dois portões**, e só um volta a fechar: `edicoes.ajustes_de` **abre a aba** (antes dele ela
  aparece no menu e não abre) e, uma vez aberta, o **parecer fica disponível para sempre** — é a
  devolutiva de um ano de trabalho, e sumir num prazo administrativo apagaria o que ele leva para a
  edição seguinte. O que `ajustes_ate` encerra é a **decisão**: passada a data a classificação não
  muda mais (a lista final está sendo montada em cima dela) e as sugestões viram leitura, marcadas
  com o que ficou valendo. O orientador **demo** tem *modo de teste*, que ignora as datas
  (`AjustesOrientadorService`).
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
    irreversível para tudo que vira nota — depois dele, só o **parecer final** (as duas
    recomendações escritas) ainda pode ser corrigido, com **justificativa obrigatória** e registro
    na trilha. A aba **Avaliados** tem **busca** por título ou área entre as avaliações que ele já
    enviou. As perguntas moram em `app/Support/Rubrica.php` e as respostas na coluna
    JSON `avaliacoes.respostas` — mexer na rubrica não pede migration.
  - **Perfil do avaliador** (`/avaliador/perfil`): cards com **projetos avaliados**, **carga
    horária do certificado** (**2h30 por avaliação concluída**, com **teto de 120 horas** —
    `AvaliadorProfile::MAX_MINUTOS_CERTIFICADO`) e **posição no ranking** de quem
    mais avaliou (só entra quem já concluiu ao menos uma; empate divide a posição), com
    **quantas avaliações tem quem está logo à frente** e quantas faltam para alcançá-lo — o
    número, nunca o nome, e o vizinho, nunca o líder. Na mesma
    tela ele **troca a própria área/subárea — só enquanto o período de avaliação não começou**
    (`Edicao::avaliacaoLiberada()`), porque depois a distribuição já foi feita em cima dela. Ali
    também ficam a **localidade** e o **tamanho de camiseta** (`avaliador_profiles.camiseta`,
    opcional e trocável a qualquer momento — é logística, não distribuição): em branco é resposta,
    e tira o avaliador da encomenda. O campo também aparece no cadastro, e o painel tem um card de
    camiseta para os avaliadores **ativos e não-demo**. Ali fica ainda em que **idiomas** ele pode
    avaliar — Português, Espanhol, Inglês (`avaliador_profiles.idiomas`, JSON; `App\Support\Idiomas`) —,
    uma lista em que **ao menos um é obrigatório**, no cadastro e no perfil: a feira recebe projeto
    de fora, e "nenhum idioma" não é resposta possível para um avaliador. Ele **não entra na
    distribuição**, que segue por área — quem designa o projeto estrangeiro é a organização, à mão.
    O admin o vê em Avaliadores Online (coluna, filtro e CSV), tem um card em Dashboards (onde a
    soma passa do total, porque quem marcou três conta nos três) e três públicos combináveis na
    mala direta, nos avisos e no feedback. Quem se cadastrou antes do campo fica com a lista vazia:
    a tela cobra o preenchimento e os públicos por idioma não o alcançam.
  - **Avaliação presencial** (aba própria, `/avaliador/avaliacao-presencial`, só para quem aceitou
    o presencial): abre **apenas no turno do evento em que a organização o ativou na cabine**, até o
    fim do turno + 30 minutos (`App\Support\JanelaTurnos`); fora disso aparece fechada, com o motivo.
    Recebe projetos pela **distribuição presencial por turno** (admin, Avaliação presencial →
    Distribuição presencial: só projeto credenciado e com estande checado) e pode escolher no
    corredor entre os do turno; avalia num **wizard** como o da online, com o **checklist dos itens
    da checagem** (sem nota). Ensaio: `php artisan demo:avaliacao-presencial`
    ([docs/DEMO_AVALIACAO_PRESENCIAL.md](docs/DEMO_AVALIACAO_PRESENCIAL.md)).
  - Ao **iniciar** uma avaliação o sistema confere se o projeto ainda cabe mais uma: contando as
    **concluídas + em andamento**, se ele já atingiu o **máximo de avaliações da categoria**, o
    avaliador é avisado de que outro chegou antes, o projeto **sai da lista dele** e outro entra no
    lugar (409 `PROJETO_JA_COBERTO`). É o que faz valer a pena designar mais avaliadores do que o
    projeto aceita. A **designação manual do admin** passa por cima dessa trava.
  - Cada projeto passa pelo **mínimo de avaliadores definido pelo admin** (padrão 3), com *match* por
    **subárea** (preferencial), **área** ou **área correlata** — o grupo de áreas irmãs configurado em
    Parametrização → Áreas. Concluída uma avaliação, o avaliador **recebe outro projeto na hora**, e
    ele pode **sortear de novo** o que ainda não abriu (o que está em avaliação e o que o admin
    designou permanecem). Na tela dele, o que a **organização designou** e o que ele **já abriu**
    ficam numa lista própria, **acima** da fila comum, e **nunca são cortados** pelo mínimo por
    avaliador — o limite manda só na fila automática. Antes disso o corte era feito sobre a lista
    inteira ordenada por id, e a designação manual, sendo a mais nova, sumia da tela.
  - **Distribuição automática**: casa subárea do projeto ↔ subárea do avaliador; se não houver,
    cai para a **mesma área**. (Algoritmo ainda a refinar.)
  - Cada projeto fica visível para **no máximo 5 avaliadores**.
  - O **admin pode designar manualmente** projetos a avaliadores, podendo **exceder o limite de 3**.
    Quem **já avaliou** aquele projeto não recebe de novo, e a tela **diz quais foram** em vez de
    sumir com os nomes: os elegíveis são designados e o aviso lista o resto. Designar alguém cuja
    avaliação foi devolvida pelo prazo **revive** aquela avaliação, com o rascunho dele.
- **Admin**: criado **somente por outro admin** (cadastro simples: nome, e-mail, senha). O acesso é
  um **RBAC**: a *rule* é a aba do menu (`App\Enums\AbaAdmin`), o *role* é o **escopo**
  (Parametrização → Escopos de admin — um nome + a lista de abas que ele abre), e cada admin carrega
  **um ou mais escopos por edição**, abrindo a **união** das abas de todos. **Sem escopo algum na
  edição em curso, o admin tem acesso total** — é o comportamento histórico e o que impede uma
  edição nova de trancar a equipe para fora. O bloqueio é de verdade: o menu esconde e o middleware
  `aba:` responde 403. Uma trava impede deixar a edição sem nenhum admin ativo capaz de abrir
  "Administradores". Ao entrar, ele cai numa **Home** (`/admin`) com um botão para cada aba que abre;
  cada linha da aba Administradores tem ainda um botão de **modo demo**, que libera para aquela
  pessoa as funcionalidades presas a data (testar o credenciamento antes do evento, por exemplo);
  a seção **Contas demo**, no fim da mesma aba, faz o mesmo por **orientadores e avaliadores** —
  sem busca ela lista quem já está marcado, com busca procura em toda a base.
  A aba **Dashboards**
  reúne as métricas: projetos totais / submetidos / em rascunho; **projetos por categoria**;
  orientadores; alunos; coorientadores; **camisetas por tamanho** (um card para orientadores, um
  para alunos e um para coorientadores, PP…XG + N.I., via `App\Support\Camisetas`); **alunos por
  classe escolar** (um card para Ensino Fundamental I, um para Fundamental II e um para Ensino
  Médio, cada um quebrado por série + N.I., via `App\Support\ClassesEscolares` — o **técnico
  integrado conta no card do médio**, que por isso vai até o 4º ano); escolas, cidades
  e estados **com projeto cadastrado**.
  Fora dos dois primeiros cards (que existem para mostrar o rascunho), **todo card conta só
  projetos submetidos** — categoria, pessoas e localidades. Orientador entra **uma vez**, tenha
  um ou vários submetidos, e projeto de **orientador demo fica de fora de tudo**
  (`AdminDashboardService`, `Projeto::semDemo()`). A aba **Projetos** guarda o recorte que tem tela
  de detalhe atrás: total, por status, por categoria e as três localidades.
  - **Parametrização → Edições** (`/admin/parametrizacao/edicoes`): as edições da feira. O portal
    roda **várias ao mesmo tempo** — cada projeto pertence a uma (`projetos.edicao_id`) e o
    `EdicaoScope` faz toda consulta enxergar só a que está em escopo, então **trocar de edição
    troca de uma vez** os projetos, os prazos, os limites e as regras. Uma edição é a **padrão**
    (`edicoes.padrao`): vale para quem não escolheu nenhuma, para o cadastro público, para os
    e-mails e para os jobs da fila. Criar a edição do ano seguinte pode **herdar a parametrização**
    da atual (limites, regras de distribuição, designação ao cadastrar — as datas não). Edição
    **padrão ou com projeto não é excluída**. **Qualquer usuário** troca a sua edição pelo seletor
    no topo do menu (`users.edicao_id`; `GET /edicoes`, `PUT /edicoes/atual`) — nulo significa
    "seguir a padrão". `EdicaoService`.
  - **Parametrização → Ordem do menu** (`/admin/parametrizacao/abas`): em que ordem as abas do
    admin aparecem no menu lateral e na Home. A ordem é **da edição** (`edicoes.ordem_abas`), como os
    prazos e os limites, e se arruma arrastando ou pelas setas. É **apresentação**: quem abre o quê
    continua sendo dos escopos, e aba nova no código entra no fim em vez de sumir. `OrdemAbasService`.
  - **Parametrização → Escopos de admin** (`/admin/parametrizacao/escopos`): o CRUD dos perfis de
    acesso — nome + abas do menu. A atribuição fica na aba **Administradores**, nos chips de cada
    linha: um admin pode receber **vários escopos em cada edição** e abre a **união** das abas de
    todos. `EscopoAdminService`, `PUT /admin/admins/{admin}/escopos`.
  - **Parametrização → Datas e períodos** (`/admin/parametrizacao/datas`): **todas** as janelas da
    edição, na ordem em que a feira acontece — inscrições, avaliação online, ajustes do orientador e
    evento (credenciamento). Cada ponta continua tendo o seu endpoint; a tela só reúne os campos, que
    antes estavam espalhados por três telas. Em regra, **campo em branco deixa aquela ponta aberta**;
    as exceções são os **ajustes** e o **credenciamento**, que ficam fechados sem data de início.
    A **janela de inscrição** é a **abertura** (`edicoes.submissoes_de`) e o **prazo de submissão**
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
  - **Parametrização → Avaliação Online** (`/admin/parametrizacao/avaliacao`): os **limites de
    avaliação** (`App\Support\LimitesAvaliacao`) — as datas do período e as do período de ajustes
    mudaram-se para *Datas e períodos*. **Por avaliador**, o mínimo é o
    tamanho da fila e o máximo é o teto de avaliações que ele acumula (em branco = sem teto); **por
    projeto**, o mínimo é o alvo da distribuição e o máximo é quantos avaliadores enxergam o
    projeto — e esse par pode ser definido **por categoria**, seguindo o geral quando fica em branco.
    Encerrado o período (`edicoes.avaliacao_encerrada_em`), o avaliador ainda **lê** os projetos
    designados e o que respondeu, mas não inicia, não salva rascunho e não envia
    (`AvaliacaoFluxoService::podeVer()` vs `podeAvaliar()`); o demo em modo teste ignora as duas
    datas. O **período de ajustes** (`edicoes.ajustes_de`/`ajustes_ate`) é a janela da aba
    **Ajustes e Pareceres** do orientador — **sem data de início ela fica fechada**, ao contrário das outras
    janelas. O "período começou" que trava o cancelamento de submissão e a troca de área do
    avaliador continua sendo só o início (`edicoes.avaliacao_liberada_em`).
  - **Avaliação Online → Algoritmo de distribuição** (`/admin/avaliacao/distribuicao`, aberta pelo
    botão *Abrir configurações* na aba): o **modo de distribuição**, os prazos das designações e os
    limiares que o algoritmo respeita.
    O **modo** (`edicoes.modo_distribuicao`, `App\Enums\ModoDistribuicao`) diz de onde vem a fila do
    avaliador. **Distribuição Total** é o comportamento histórico e o padrão: o admin distribui em
    massa e cada um encontra a fila pronta. **Distribuição por Atividade** monta a fila **no login**
    (`SessaoAvaliadorService`), com o mesmo **mínimo por avaliador**, e a devolve ao bolo quando a
    sessão acaba — assim só ocupa projeto quem está de fato avaliando; nele *Distribuir* e
    *Redistribuir* ficam desabilitados, e o servidor recusa. A sessão acaba de três jeitos, e os três
    devolvem: **sair**, **entrar de novo** (o login limpa o que sobrou) e **sumir** — a varredura por
    inatividade (`edicoes.horas_sessao_avaliador`, padrão 12h) cobre quem só fecha o navegador.
    O **prazo da avaliação aberta** (`edicoes.dias_avaliacao_aberta`, em branco desliga) vale nos
    **dois modos**: passados Y dias sem o avaliador mexer, o projeto volta para a pilha — mas a linha
    **não é apagada**, `avaliacoes.devolvida_em` a esconde das contas de cobertura
    (`App\Models\Scopes\AvaliacaoAtivaScope`) e guarda o rascunho, que ele **retoma** de onde parou
    se o projeto ainda aceitar avaliação. A devolução por prazo entra em Registros em nome do
    sistema; a de fim de sessão, não.
    **Nada disso alcança a designação manual do admin nem a avaliação já assumida**: só a retirada
    explícita, em *Designações*, tira uma dessas (`Avaliacao::scopeDevolvivel()`).
    Os demais limiares: Por **categoria**, o
    admin liga/desliga a participação e define a **faixa de avaliações que o projeto já recebeu**
    (as concluídas; de/até, "até" em branco = sem teto) para ele ainda aceitar avaliador novo — a
    conta é **por projeto**, não pela carga do avaliador: "FUNDECT de 0 a 1" designa só os projetos
    dessa categoria com nenhuma ou uma avaliação recebida. As regras moram em
    `edicoes.distribuicao_regras` (JSON, via `App\Support\RegrasDistribuicao`) e valem para a
    distribuição em massa, para a reposição da fila e para o sorteio; a **designação manual do
    admin passa por cima**. O **piso da fila do avaliador** (`edicoes.piso_fila_avaliador`, padrão 6)
    é a rede dessa regra: ela continua escolhendo quem entra primeiro, e **só quando deixa alguém
    abaixo do piso** uma segunda passada completa a fila **ignorando-a** — vale na distribuição, na
    reposição ao concluir e no botão *Sortear outros projetos*; em branco, não há piso. As
    **designações por projeto** (`edicoes.designacoes_por_projeto`, e o campo *Designações* de cada
    categoria) dizem quantos avaliadores ficam com o projeto **na lista** — outro eixo que o de
    *Avaliações por projeto*, que é quantas avaliações ele pode **receber**. Pode passar do **máximo
    de avaliações** de propósito: com o número justo, um avaliador que não abre a avaliação deixa o
    projeto devendo. Em branco segue o máximo, que cumpria esse papel antes do campo existir; o
    único piso é o mínimo. Quem chega depois de o projeto atingir o máximo de avaliações é avisado e
    trocado no momento de iniciar. Na mesma
    seção ficam **Distribuir avaliações** (completa o que falta, idempotente) e **Redistribuir
    avaliações** (devolve ao bolo tudo que foi apenas designado e sorteia de novo — o que está **em
    avaliação**, o concluído e o designado à mão não se mexem). As duas vão para a **fila** e a tela
    mostra uma **barra de progresso** (`distribuicoes` + `ProcessarDistribuicao`); duas rodadas ao
    mesmo tempo são recusadas. Fecha a seção o toggle **designar ao cadastrar**
    (`edicoes.distribuicao_ao_cadastrar`): ligado, o avaliador que acaba de se cadastrar já sai com a
    fila cheia. Acima de tudo isso está a **cota justa**: a distribuição em massa reparte **em
    rodadas iguais** (ninguém recebe o k+1-ésimo projeto antes de todos os elegíveis terem k) e a
    fila de cada avaliador nunca passa do que a **área comporta dividido pelos avaliadores dela**
    (`FilaAvaliadorService::cotaJusta()`) — com trabalho de sobra a cota some e o teto volta a ser o
    mínimo por avaliador.
  - **Avaliação Online → Designações** (`/admin/avaliacao/designacoes`): **todas** as designações da
    edição numa tabela — projeto, área, avaliador, situação e **há quanto tempo** está com ele —, com
    busca por projeto ou avaliador, filtros (área, categoria, situação, avaliador), ordenação e
    paginação. O admin marca linhas e **retira** a designação: o projeto volta ao bolo e é
    **redesignado na hora** para outro avaliador, pelas prioridades do edital
    (`DistribuicaoService::designarUm()`); sem ninguém elegível ele fica sub-coberto e a tela avisa.
    Cada linha tem ainda **Ver notas**, que abre a nota daquela avaliação **seção por
    seção** da rubrica (com a resposta em palavras e os pontos de cada pergunta), a
    **soma geral** e o parecer escrito. Só avaliação **concluída** tem nota, e **abrir
    fica registrado** em Registros → Notas — a nota decide a lista final e o parecer é
    anônimo para o orientador.
    O botão **Designar** faz o caminho inverso, em massa: o admin marca **um ou mais
    projetos** e **um ou mais avaliadores** e o portal cruza tudo com tudo (3 × 2 = 6
    designações). Quem **já avaliou** aquele projeto é pulado — a tela lista quem ficou
    de fora e por quê — e quem teve a avaliação devolvida pelo prazo é **revivido** com
    o rascunho. No fim, cada avaliador recebe **um** e-mail com a lista do que chegou
    para ele e o admin que designou recebe o resumo da operação
    (`DesignacaoService::designar()`, `NotificacaoDesignacaoService`).
    Sai o que está **designada** e o que está **em avaliação** (descartando o rascunho — é a única
    forma de destravar, já que o avaliador não desiste sozinho); **concluída nunca sai**. Na
    confirmação o admin escolhe o que acontece depois: **redesignar** (o padrão) ou **apenas
    remover**, quando o projeto já tem avaliadores de sobra e repor desfaria o que ele pediu. Cada
    retirada entra em Registros → Avaliação Online com o "de → para" — `(retirada sem reposição)`
    é diferente de `(sem avaliador)`, que é falta de candidato. `DesignacaoService`.
  - **Avaliação Online → Ranking dos projetos → Verificar disparidade**
    (`/admin/avaliacao/disparidade`): duas abas sobre o mesmo material. A **lista de projetos**
    traz aqueles em que a maior e a menor nota se afastaram pelo menos a diferença pedida (a
    **amplitude**, que se explica numa contestação sem estatística), cada um com **Ver notas** — os
    avaliadores lado a lado, nome, nota final e pontos de cada seção da rubrica, com a seção de
    maior discordância destacada — e o atalho para **Designar**. A **identificação de padrões**
    olha para o avaliador e procura quatro sinais, com limiares ajustáveis: **notas infladas**,
    **notas muito abaixo dos colegas** (precisa se repetir em metade dos projetos comparáveis:
    nota baixa isolada é legítima), **respostas repetidas** e **avaliação relâmpago**
    (`avaliacoes.iniciada_em` → `concluida_em`). A lista de projetos fica registrada; a análise de
    padrões, não — é consulta, e os limiares mudam até o corte fazer sentido.
    No diálogo de notas o admin **desconsidera** uma nota, com **justificativa obrigatória**: ela
    continua ali, riscada e com o motivo, mas sai de tudo que classifica (média, ranking, lista
    final, pareceres do orientador e a **cobertura** do projeto) — o certificado e o ranking do
    avaliador continuam contando, porque quem descartou foi a organização. Desfazer também pede
    justificativa, e os dois atos entram em **Registros → Notas**. No mesmo passo ele escolhe o que
    entra no lugar: **nada**, **outro avaliador** (designação manual, com e-mail) ou **ele
    mesmo** — uma avaliação `pela_organizacao`, com a **mesma rubrica**, que conta para o projeto e
    não para a pessoa (fora do ranking de avaliadores e do certificado). Escolhendo avaliar ele
    mesmo, a **rubrica abre antes**: a justificativa fica guardada na própria avaliação
    (`avaliacoes.substitui_avaliacao_id` + `substituicao_motivo`) e a nota antiga só sai da
    classificação **no envio** — a troca é um ato só. Enquanto ele preenche, a nota continua
    contando; desistir no meio não deixa buraco, e o cartão dela oferece a **retomada** do
    rascunho, que é o único caminho de volta ao formulário.
    `VerificacaoDisparidadeService`, `PadroesAvaliacaoService`, `NotasAvaliacaoService`.
  - **Avaliação Online → Listas preliminares e finais** (`/admin/avaliacao/listas-finais`):
    `listas_finais.tipo` separa as **preliminares** (várias convivem; nenhuma define finalista) das
    **finais** (uma só **ativa** por edição — `vigente` —, que vale para toda a etapa presencial).
    Cada lista tem **apelido** (o `nome`) e **descrição**, editáveis a qualquer momento sem
    justificativa. A composição aparece **por grupo categoria+área**: **arrastar** um projeto dentro
    do grupo renumera o código de todos na hora, e o código também pode ser **digitado** — em branco,
    fora do padrão ou fora da sequência ele vira **aviso**; **repetido** barra a geração (e, numa
    lista já gerada, é recusado na hora). Toda lista nasce **rascunho** — da classificação
    (Ranking → *Gerar lista*) ou da **união de listas já geradas**, preliminares e finais, como
    preliminar ou final (`origens`, com os códigos fixados herdados) — e no rascunho o admin inclui e retira **sem
    justificativa**, qualquer projeto submetido (avaliado ou não). **Gerar** fecha: a preliminar fica
    registrada; a final vira a **ativa** e a anterior passa a inativa, **reativável** com
    justificativa. A ativa é a que define os **finalistas** da feira (projetos + alunos + orientador
    + coorientador). Dentro de cada lista o admin **inclui e retira projetos**
    com **justificativa obrigatória** — cada alteração **sobe a versão**, gera um TXT novo (o
    arquivo sai sempre da composição atual, com a numeração refeita) e entra em **Registros → Lista
    final**. `listas_finais` + `lista_final_projetos`, `ListaFinalService`. O detalhe da lista baixa
    ainda os **projetos de pesquisa** num ZIP — todos, ou por seleção individual, de categoria ou de
    área —, em pastas categoria → área e com o código do projeto no nome
    (`ExportacaoProjetosPesquisaService`).
  - **Avaliação Online → Ranking dos projetos → baixar**: o ranking sai em **CSV, Excel (.xlsx),
    PDF e TXT**, sempre no **recorte dos filtros da tela** (área e categoria) — quatro formatos
    porque são quatro usos: planilha para conferir, papel para a reunião, texto para colar num
    e-mail. Cada linha leva posição, projeto, área, categoria, média, número de avaliações e a
    marca **Parcial**; a média por seção da rubrica fica de fora (é a régua de leitura da tela, e
    o detalhe por seção vive no *Ver notas*, em Designações). O `.xlsx` existe ao lado do CSV
    porque grava a média como **número**, somável e ordenável, e sai de um escritor próprio
    (`App\Support\PlanilhaXlsx`, `ZipArchive`) — **sem dependência nova**.
    `GET /admin/avaliacao/ranking/exportar/{csv|xlsx|pdf|txt}`.
  - **Avaliação Online → Ranking dos projetos → Gerar lista final**: exporta em **TXT** o recorte
    que vai para a programação da feira. O admin passa por **três passos** — quantos projetos por
    **categoria**, quantos por **área dentro de cada categoria** e quantas dessas vagas ficam
    reservadas ao **interior** (escola fora da capital do estado, via `cidades.capital`) —, cada
    quantidade em **número fixo ou porcentagem** do recorte acima dela (`App\Support\Cota`): "100 da
    FUNDECT, 20 de agrárias, 70% desses para o interior". Campo em branco não limita; 0 deixa o
    recorte de fora. A reserva do interior é **piso**, não teto: a vaga que ele não preencher volta
    para os demais numa segunda passada, e ela só vale onde a área tem cota. **A cota do interior
    existe apenas na FETECMS FUNDECT** (`Categoria::permiteCotaInterior()`) — é exigência do fomento
    dela; nas demais a lista é só por nota. Entram os **mais bem
    avaliados** que couberem em todas as cotas. O arquivo sai por categoria (FETECMS → FETEC Jr → FETECMS FUNDECT) → área em ordem
    alfabética → título, com o sequencial `001, 002…` reiniciando a cada categoria+área:
    `FET.AGR-001 - Título` / `Escola / Cidade - UF` / alunos em ordem alfabética /
    `Orientador - Orientador(a)`. As siglas de categoria são FET, JR e PIC; as de área saem de
    `areas.sigla` (AGR, BIO, SAU, EXA, HUM, SOC, ENG, LIN), editável em Parametrização → Áreas.
  - **Avaliação Online → Projetos submetidos**: além de *Designar*, cada linha tem **Editar**, que
    abre a correção manual de **categoria, área, subárea, link do vídeo, orientador e
    coorientador**, com a **série de cada aluno** logo abaixo da categoria (só leitura) — é ela que
    diz se a categoria está certa. Trocar o **orientador** troca o **dono** do projeto
    (`projetos.user_id`): a escolha é entre contas de orientador ativas, buscadas no servidor por
    nome ou e-mail (`GET /admin/avaliacao/orientadores/opcoes`), e o dono anterior perde o acesso. O
    **coorientador** não tem conta, então o admin **edita, inclui e remove** (nome, e-mail, CPF e
    telefone), com um registro por campo alterado. É um
    escape do edital (o orientador não mexe depois de submeter), então a **justificativa é
    obrigatória** e cada campo alterado vira um registro em **Registros → Projetos** com o
    "de → para" (`AdminProjetoEdicaoService`). No topo da tela, um **card destacado** soma todas as áreas:
    quantos projetos estão com 0, 1, 2 e 3+ avaliações concluídas, sempre no recorte dos filtros.
  - **Mapa do Evento** (`/admin/mapa`): onde cada projeto apresenta, em três etapas
    que dependem uma da outra e saem todas da **lista final vigente**.
    **Turnos de Apresentação** (`/admin/mapa/turnos`) divide os finalistas entre o
    **turno A (matutino)** e o **B (vespertino)** — o mesmo estande recebe um projeto
    de manhã e outro à tarde, e é assim que 230 estandes acomodam até 460 trabalhos.
    O admin informa a capacidade **de cada turno** e liga as regras que quiser, que
    valem **em ordem de prioridade**: *Justificativa por Vestibular* e *Justificativa
    (Outras)* têm listas próprias (várias por regra, com busca por projeto **ou por
    participante**); as três de localidade — *fora do MS*, *fora da capital* e
    *Campo Grande* — só escolhem o turno. Na justificativa o admin marca o turno em
    que a pessoa **não pode** estar, e o projeto vai para o outro. O que nenhuma
    regra alcança entra no **equilíbrio** 50/50. Capacidade é **teto**: turno cheio
    empurra o projeto para o outro com aviso; lista maior que os dois somados
    **recusa** a geração. Só a **última** lista vale, e o admin move projetos de
    turno à mão — sem justificativa, com registro. Exporta em PDF, TXT e CSV
    (`TurnosApresentacaoService`, `App\Support\RegrasTurnos`).
    **Estandes dos Projetos** (`/admin/mapa/estandes`) diz **em que número** cada um
    fica: uma faixa por categoria, escrita como no papel (`1-4, 7-9, 10-52`), e quem
    não tem faixa ocupa o que sobrou. Faixa pequena demais manda o excedente para os
    livres e avisa; o mesmo estande em duas categorias é recusado. Mandar um projeto
    para um número ocupado **troca os dois de lugar** (`EstandesProjetosService`,
    `App\Support\FaixaEstandes`).
    **Mapa do Evento** (`/admin/mapa/planta`) é a planta do ginásio em SVG: clicar
    num estande mostra quem apresenta nele nos dois turnos. Ela nasce com a prancha
    da montadora (`App\Support\PlantaEvento`) e é **editável e versionada por
    edição** (`mapa_layouts`) — cada gravação é uma versão nova, e restaurar uma
    antiga também. Abre em **tela cheia** (Fullscreen API), para ir ao projetor.
    As **ruas** são os corredores entre as ilhas: **achadas no desenho**
    (`PlantaEvento::ruas()`), não desenhadas à parte — o admin só as batiza, e o
    que se guarda é o nome, então mover um estande reposiciona a rua sozinha.
    Durante a feira a planta **muda de cor** (`App\Enums\SituacaoEstande`,
    `MapaSituacaoService`): aguardando → credenciado → pronto para avaliação → em
    avaliação, o último escurecendo a cada avaliação presencial recebida. Os
    quatro estágios saem de fatos **já registrados com hora**
    (`credenciamentos.finalizado_em`, `checagens_estande.verificado_em`,
    `avaliacoes_presenciais.concluida_em`), e é esse carimbo que dá o **seletor de
    dia**: escolher um dia anterior lê os mesmos fatos com um corte mais cedo e
    devolve o mapa como ele estava no fim daquele dia, sem instantâneo gravado. O
    **turno** é o outro eixo. O mesmo recorte sai em **lista filtrável**
    (credenciamento, checagem, avaliações realizadas ou faltantes) e exporta em
    TXT, CSV e PDF; o dia corrente se recarrega sozinho a cada 30s.
  - **Credenciamento** (`/admin/credenciamento`): o balcão do evento, em duas seções —
    **Credenciar** (os finalistas que ainda não passaram) e **Credenciados** (quem já passou), a
    mesma lista pesquisável com filtro por área e categoria. **Finalista é quem está na lista final
    vigente**; sem lista oficial não há quem credenciar. Ao abrir um projeto, o admin confere
    **documento a documento, pessoa a pessoa** (alunos, orientador e coorientador), marcando
    **presente / ausente / não necessário** — a lista de documentos de cada papel é parametrizável.
    O atendimento pode ser **salvo como rascunho** (o participante saiu para buscar um documento):
    o projeto segue na fila de *Credenciar*, marcado "em rascunho", e a conferência é retomada de
    onde parou. O rascunho tem **dono**: conta temporária só continua o próprio, o admin permanente
    assume o de uma conta temporária direto e o de **outro admin permanente** só com
    **justificativa** — as duas trocas ficam registradas.
    Cada credenciamento grava **quem atendeu e o horário** e entra em Registros → Credenciamento com
    o que ficou ausente. Concluído, ele ainda pode ser **regravado** ou **cancelado** (o projeto
    volta para a fila de *Credenciar*, com **justificativa obrigatória**) — mas mexer no
    credenciamento **de outra conta** exige **admin permanente**: a conta temporária corrige e
    cancela só o que ela mesma registrou (`CredenciamentoService::podeAlterar()`). O **modo demo** (conta demo, botão na aba) faz duas coisas: ignora as datas
    do evento **e** troca a lista oficial pela **lista final de demonstração** da edição
    (`php artisan demo:credenciamento`), então ensaiar o balcão nunca alcança um finalista de
    verdade. O **horário de início** vem preenchido com o momento do atendimento e pode
    ser corrigido: alterado, o **fim vira início + 5 minutos**
    (`CredenciamentoService::MINUTOS_ATENDIMENTO`); intocado, o fim é o instante da conclusão. Ao
    concluir, a tela **lembra os itens a entregar** ao finalista. Só credencia dentro da **janela do
    evento**; fora dela a aba abre em leitura. O **admin demo** tem um *modo de teste* que ignora as
    datas. `CredenciamentoService`.
  - **Credenciamento → Contas temporárias** (`/admin/credenciamento/contas`): acesso de prazo curto
    para quem atende o balcão sem ser da organização. O cadastro é o mesmo do admin (nome, e-mail,
    senha) mais **CPF**, **nome do curso** e a **janela de acesso**: **quando começa** (em branco,
    agora) e **por quantas horas** vale (padrão **5**). Preenchendo o início, a conta nasce
    **agendada** — a equipe inteira é cadastrada dias antes e cada acesso abre sozinho na hora
    marcada; até lá o login é recusado com a data, mas a conta **não** é desativada. A conta é um
    `users` com `role = admin`, e o que a restringe é a linha em `contas_temporarias`:
    `User::ehContaTemporaria()` faz `abasPermitidas()` devolver **só "credenciamento"**, por cima de
    qualquer escopo. Vencido o prazo, ela é **desativada, não apagada** — reativar é informar um
    prazo novo, sem recadastrar nada; a varredura roda ao listar e no login. Uma conta temporária
    **não administra outras contas temporárias**. Em todas as abas com balcão as contas também
    entram **em lote** (modelo Excel → prévia → senhas geradas numa planilha de acesso) e podem ser
    **removidas** — apagadas se nunca atenderam ninguém, arquivadas se já deixaram rastro
    (Sprint 155). `ContaTemporariaService`.
  - **Cerimonial** (`/admin/cerimonial`): a porta das **cerimônias de premiação** — outro momento
    do evento que não o credenciamento, e por isso o check-in **não depende** dele: a ficha diz se
    o projeto passou pelo balcão e não trava quem não passou. A feira faz **mais de uma
    premiação** com o mesmo funcionamento: cada **evento de cerimônia** (`cerimonial_eventos`,
    `/admin/cerimonial/eventos`, só admin permanente) tem um nome e um **público** — as
    categorias dos finalistas que recebe; uma categoria pode estar em mais de um evento. Tudo é
    **por evento**: o check-in (`cerimonial_checkins.evento_id`; quem vai às duas cerimônias entra
    nas duas), a busca, a ficha e o painel. A porta **escolhe o evento** no topo do Check-in
    (lembrado no aparelho; com um só cadastrado ele já vem escolhido), e o crachá de outro público
    é recusado dizendo de quem é. Visão Geral e Premiados têm o mesmo seletor e a opção **Todos**,
    que soma o público de todos os eventos contando cada pessoa uma vez. A **mesma pessoa em mais
    de um papel** (orienta um projeto e coorienta outro) é reconhecida pelo **CPF** — ou pelo e-mail
    com o mesmo nome, quando falta CPF —, e marcar um vínculo dela marca **todos** no evento;
    desfazer também leva todos. Na Visão Geral, a lista de quem falta tem **Marcar chegada**
    (com a cerimônia escolhida e o período aberto). **Check-in** atende
    pelos dois caminhos da fila: o **crachá** da lista final (leitor USB ou câmera — inclusive no
    iPhone —, mesmas três conferências do balcão) **já registra a entrada** daquela pessoa e mostra
    o nome num card de 2 segundos (`POST /admin/cerimonial/checkin-codigo`, `CardCheckin.jsx`); a
    **busca por nome ou CPF**, de quem chega sem crachá, abre a **ficha do projeto**, onde se marca
    de uma vez a equipe que chegou junta — o check-in é **por pessoa**. **Visão
    Geral** traz cinco cards, todos abrindo a lista **nominal** de quem chegou e de quem falta:
    pessoas, projetos (com **parcial** e **completo** separados), premiados, **medalhas** e
    **credenciais**; *Premiados* tem tela própria (`/admin/cerimonial/premiados`), um cartão por
    projeto com um **busto por integrante**, verde para quem entrou. **Medalha segue a pessoa**
    (uma por premiado presente) e **credencial segue o projeto** (as do projeto premiado com
    alguém na sala), contando só `tipo = credencial` — prêmio se anuncia, não se entrega.
    **Premiado** é quem recebeu credencial **ou** prêmio em *Credenciais e Prêmios*. **Contas
    temporárias** (`/admin/cerimonial/contas`) seguem o padrão do credenciamento, setor
    `cerimonial` — e abrem **só o Check-in**, pelo middleware `admin.permanente`. Desfazer um
    check-in exige **justificativa** e entra em Registros → Cerimonial. Janela do **evento**, com
    o mesmo modo demo do balcão; o painel se atualiza por polling no intervalo da edição
    (`edicoes.cerimonial_atualizacao_segundos`, padrão 30s). `CerimonialService`.
  - **Almoxarifado** (`/admin/almoxarifado`): o balcão de guarda da feira. A equipe chega com
    maquete, ferramenta e mochila e precisa de onde deixar isso enquanto circula pelo evento.
    A aba é uma **aba do RBAC** como as outras (escopo *Almoxarifado*), só abre dentro do
    **período do evento** — fora dele fica em leitura — e atende apenas os **finalistas da lista
    vigente**; o **modo demo** ignora as datas, usa a lista de demonstração e mantém os registros
    do ensaio separados dos de verdade. Guardar é um **assistente de quatro passos**: projeto →
    quem está deixando (sempre alguém do projeto) → os itens, **um por linha** → uma tela de
    **conferência** com tudo e o horário, para o admin confirmar com a pessoa antes de finalizar
    (cada bloco tem *Alterar*). Os registros ficam numa **tabela** — projeto, quem deixou,
    quantidade de itens, data, e quem retirou e quando —, com quatro ações por linha:
    **Retirada completa** (tudo o que ainda está guardado), **Retirada parcial** (só os itens
    marcados; o resto sai depois, com outro responsável e outro horário), **Editar** e
    **Excluir**, as duas últimas com **justificativa obrigatória**. Tudo entra em **Registros →
    Almoxarifado**. A aba tem a sua própria seção de **Contas temporárias**
    (`/admin/almoxarifado/contas`), independente da do credenciamento: cada setor cadastra e
    renova só as suas, e a conta criada abre somente aquela aba (`contas_temporarias.setor`).
    `AlmoxarifadoService`.
  - **Parametrização → Dados de demonstração** (`/admin/parametrizacao/demo`): tudo que
    existe no portal para ensaio, num lugar só — contas marcadas `is_demo`, os projetos
    delas (de **todas** as edições), avaliações (inclusive as devolvidas), listas finais
    demo, credenciamentos, guardas do almoxarifado, ajustes e a trilha que isso gerou. Dá
    para **desmarcar** uma conta (os projetos dela voltam a contar), **apagar** um item ou
    **limpar tudo** — e **nada que não esteja marcado como demonstração é apagado aqui**.
    Existe porque cada dado de ensaio sumia de uma tela diferente e ninguém conseguia
    responder "o que ainda é de mentira no sistema?". `DadosDemoService`.
  - **Parametrização → Credenciamento** (`/admin/parametrizacao/credenciamento`): os **itens
    entregues** aos finalistas (`edicoes.itens_credenciamento`). O **período do evento**
    (`edicoes.evento_de`/`evento_ate`, **fechado enquanto não for definido** — credenciar é ato
    presencial) fica em *Datas e períodos*.
  - **Parametrização → Itens do credenciamento e da checagem**
    (`/admin/parametrizacao/itens-conferencia`, `itens_conferencia`, `ItensConferenciaService`): a
    **lista única** do que se confere nos dois balcões. Cada item é **de cada pessoa** (com os papéis:
    aluno, orientador, coorientador) ou **do projeto**, é conferido **no credenciamento**, **na
    checagem** do estande ou nos dois, e pode ser **recebido antes** — o orientador o envia pela aba
    Documentos. No credenciamento o item de pessoa aparece em cada pessoa e o do projeto num bloco
    "Do projeto"; na checagem o do projeto aparece uma vez e o de pessoa com um marcador por pessoa. O
    checklist do avaliador presencial usa só os itens do projeto que não são recebidos antes. Item
    conferido ou com arquivo não é excluído — desative-o. Os dois termos do finalista são **itens do
    sistema** (`chave`): renomeiam-se e ligam/desligam, mas não mudam de abrangência nem se excluem.
  - **Comitê especial** (`/admin/comite`): o deslocamento das equipes durante a feira, em duas
    seções (mais as **Designações** do comitê e a **Premiação** — as credenciais e prêmios nos
    projetos premiados, na mesma base de Avaliação presencial → Credenciais e Prêmios, com a lista de
    premiação em TXT; `ComitePremiacaoController`). **Transporte de comitê** lista quem está com o localizador ligado e o que cada um
    configurou, e traz o botão **Habilitar localização** — um assistente de **seis passos**:
    quantas pessoas estão junto → os **nomes** (opcionais, com a área de cada uma) → o **meio de
    transporte** → o **ponto de partida** → o **destino** (autocomplete do Google Places) e por
    **quanto tempo** o localizador fica ligado (ajustável depois) → a **permissão de localização**
    do aparelho. Ligado, a tela mostra o próprio ponto no mapa, o **caminho recomendado**, a
    **previsão de chegada** e a **próxima orientação**. **Mapa do comitê** mostra todos os grupos
    em tempo real; clicar num ponto abre quantas pessoas estão lá, os nomes, as áreas, a distância
    aproximada e o tempo até o destino. A posição vai e volta **de 5 em 5 segundos** por polling
    (não há WebSocket no projeto). **Privacidade**: guarda-se a última posição e o **trajeto vivo**;
    desligar o localizador — à mão ou pelo vencimento do prazo — **apaga o trajeto**.
    `ComiteTransporteService`, `localizacoes_comite` + `localizacao_comite_pontos`.
  - **Registros** tem dez seções. A décima é **Cerimonial** (`/admin/registros/cerimonial`): quem
    fez check-in na cerimônia de premiação, em que papel, quando e por quem — e cada check-in
    desfeito, com a justificativa. É essa contagem que diz quantas medalhas e quantas credenciais
    a mesa separa, então desfazer um é mexer no que vai ser entregue no palco. A nona é **Notas** (`/admin/registros/notas`):
    cada vez que um admin abre, em Designações, a nota que um avaliador deu a um
    projeto — com o projeto, o avaliador e o valor que estava na tela. A consulta
    não muda nada, mas a nota decide a lista final e o parecer é anônimo para o
    orientador, então quem a lê fica rastreável. A oitava é **Mapa do evento**
    (`turnos_gerados`, `turnos_projeto_movido`, `estandes_gerados`,
    `estande_projeto_movido`): a geração de cada lista, que substitui a anterior por
    inteiro, e cada projeto que o admin move de turno ou de estande à mão. Trocar de
    lugar não pede justificativa (é logística, não escape do edital), mas fica
    registrado: no dia do evento é preciso saber por que um projeto está em outro
    horário do que a lista dizia. As outras sete: **Inscrições**, **Avaliação Online**, **Lista final**
    (`/admin/registros/lista-final` — publicação da lista oficial e cada projeto incluído ou
    retirado, com a justificativa), **Credenciamento** (`/admin/registros/credenciamento` — quem
    credenciou cada finalista, quando e o que ficou ausente), **Almoxarifado**
    (`/admin/registros/almoxarifado` — o material que entrou, o que saiu e para quem, e as
    correções e exclusões de registro), **Projetos**
    (`/admin/registros/projetos`) — as correções do admin e os aceites do orientador, cada um com a
    justificativa — e **Rascunhos** (`/admin/registros/rascunhos`), com o que o admin mexeu numa
    inscrição alheia antes de submetê-la por ela.
  - **Projetos → Projetos por área → Projetos em rascunho** (`/admin/projetos-rascunho`): o botão
    **só aparece depois do prazo de submissão**. Ele lista as inscrições que ficaram em rascunho
    (busca por título/orientador, filtro por área e categoria, quantas pendências faltam) e leva
    o admin às **mesmas telas do orientador** em modo admin (`/admin/projetos-rascunho/{id}/…`),
    onde ele termina de preencher e **submete** mesmo com as inscrições encerradas. O admin **não
    conclui deixando em rascunho**: a saída que fecha o trabalho é a submissão, e ela exige
    **justificativa**. Cada campo que ele altera — projeto, aluno, coorientador ou anexo — vira uma
    linha em Registros → Rascunhos com o "de → para" (`AdminRascunhoService`); o orientador
    editando o próprio rascunho não gera registro nenhum.
  - **Comunicação → Avisos** (`/admin/comunicacao/avisos`): o admin publica um card com **título e mensagem livres**,
    que aparece para os **orientadores ativos** conectados em até ~1 min (polling; não há
    WebSocket no projeto) e pode ser fechado por cada um. **Um ativo por vez** — publicar um
    novo encerra o anterior; dá para encerrar à mão. O texto aceita `{{prazo_inscricoes}}`,
    `{{tempo_restante}}` e `{{inicio_avaliacoes}}` (`AvisoService::VARIAVEIS`) — e, num aviso cujo
    público é inteiro de finalistas, `{{codigo_projeto}}` e `{{turno}}` do projeto de quem lê —, inseridas por
    **botões abaixo do campo, na posição do cursor**, e o formulário abre com um **modelo
    pronto**. As variáveis são resolvidas **na hora da leitura** — "faltam X minutos" é o tempo
    real de quem está lendo. **Relatório** por aviso: quantos viram, fecharam e ainda não viram,
    com a lista nome a nome (filtro por situação, busca e export CSV) e o histórico dos avisos
    anteriores.
  - **Comunicação → Modelos de e-mail** (`/admin/comunicacao/modelos`): o texto dos e-mails que o
    portal manda sozinho — **confirmação de cadastro**, **projeto submetido** e **convite de
    feedback**. O admin edita
    assunto e corpo, insere as variáveis de cada modelo por botões e pode **restaurar o padrão**. O
    texto de fábrica mora no enum `App\Enums\ModeloEmail`; a tabela `modelos_email` guarda **só o
    que foi customizado** (salvar exatamente o padrão apaga a linha). Quem dispara pede a mensagem
    ao `ModeloEmailService` e não sabe de onde o texto veio.
  - **Comunicação → Feedback** (`/admin/comunicacao/feedback`): questionários dirigidos a um recorte
    da base. O admin escolhe os **públicos** (os mesmos recortes combináveis da mala direta) e monta
    as perguntas: **alternativas** — escritas à mão ou copiadas de um modelo pronto
    (`App\Support\ModelosAlternativas`: satisfação, concordância, frequência, Sim/Não, NPS) — ou
    **dissertativas**, com mínimo e máximo em **palavras ou caracteres**. Ao publicar, sai um
    **convite por e-mail** (um job por destinatário, com relatório por endereço e **reenvio só das
    falhas**, como na mala direta) e quem é alcançado vê um **balão** ao entrar no portal: pode
    responder ou fechar. **Fechar dispensa de vez**, mas o questionário continua acessível na lista
    do perfil — quem se arrepender ainda responde. **As respostas são anônimas por construção**:
    `feedback_participacoes` sabe quem viu, dispensou e respondeu; `feedback_respostas` guarda o
    conteúdo **sem `user_id`**, agrupado por um `envio` aleatório que não leva a ninguém. A tela de
    resultados traz a contagem de cada alternativa (**inclusive as zeradas**, que também informam) e
    a lista das respostas escritas sem autor, com export CSV. `FeedbackService`,
    `FeedbackResultadoService`.
  - **Comunicação → Mala direta** (`/admin/mala-direta`): comunicado por e-mail para um recorte da base.
    O admin combina quantos **públicos** quiser (todos, orientadores, avaliadores, orientadores
    com rascunho, com submetido, avaliadores com avaliação **em andamento** ou **concluída**,
    avaliadores por **idioma** declarado) e/ou
    cola uma **lista personalizada** (digitada ou importada de `.csv` com as colunas `email`/`nome`).
    A união é **deduplicada por e-mail**; contas de admin, inativas e demo ficam fora dos públicos.
    Antes de disparar ele vê **quantos recebem**, pode **listar** e **exportar CSV** (nome, e-mail,
    papel, origem, títulos e quantidade de projetos do orientador) e precisa **confirmar a mensagem**.
    O envio vai para a **fila** (um job por destinatário) com **tela de progresso**; o relatório traz
    a situação de cada endereço, o motivo das falhas e o **reenvio só das falhas**. E-mail malformado
    entra como `invalido` no relatório em vez de barrar o disparo. Campos da mala: nome, justificativa,
    solicitante (opcional — metadado interno, não vai no e-mail), assunto e texto. O texto aceita as
    variáveis `{{nome}}` — primeiro nome —, `{{nome_completo}}`, `{{email}}`, `{{projetos}}` e, só
    quando **todo** destinatário é da equipe de um finalista, `{{codigo_projeto}}` e `{{turno}}` (com ou sem espaços
    dentro das chaves; sem nome conhecido, o tratamento vira "participante"), inseridas por **botões
    abaixo do campo, na posição do cursor**. A lista mora em `MalaDiretaService::VARIAVEIS` e chega à
    tela por `GET /admin/mala-direta/opcoes` — é a mesma que o `personalizar()` percorre, então
    acrescentar uma variável lá já a faz aparecer no formulário. O texto é escrito num **editor rico**
    (TipTap): **negrito, itálico, sublinhado, traçado**, listas e **imagens no corpo** (até **5**,
    **10 MB** cada, arrastáveis para reposicionar), mais **anexos** no e-mail (até **10**, **20 MB**
    cada). O corpo vai como **HTML sanitizado** (`App\Support\HtmlEmail`) e as imagens viajam
    **embutidas por CID**, nunca por link; os arquivos ficam em `mala_direta_arquivos`, num disco
    privado, e só são vinculados à mala no disparo. O arquivo precisa **estar no storage** na hora
    do envio: o disparo confere antes de enfileirar e o envio confere de novo, e faltando o
    destinatário vira **falha** com o motivo — nunca um e-mail com anexo de 0 byte, que é o que
    acontecia em silêncio. O upload é no processo web e a leitura é no `queue:work`, então
    `storage/app/private` precisa sobreviver ao deploy e ser o mesmo dos dois
    (ver [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md)).

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
# php artisan demo:ajustes           # projeto-exemplo da aba Ajustes (3 sugestões, 3 avaliadores)
# php artisan demo:pareceres          # projeto já avaliado: níveis e sugestões na aba Ajustes e Pareceres
# php artisan demo:credenciamento     # lista final de demonstração, para ensaiar o balcão
# php artisan demo:avaliacao-presencial # avaliador@fetecms.test pronto para ensaiar a avaliação no estande
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
| 64 | Projetos em rascunho: admin termina e submete depois do prazo (justificativa) + Registros → Rascunhos | ✅ sim | ❌ não (manual do Pedro) | 5 |
| 65 | Painel: 3 cards de alunos por classe escolar, quebrados por série | ✅ sim | ❌ não (manual do Pedro) | 5 |
| 66 | Parametrização → Edições: várias edições, edição padrão e troca de escopo por usuário | ✅ sim | ❌ não (manual do Pedro) | 6 |
| 67 | Escopos de admin: perfis de abas por edição, aplicados no menu e no backend | ✅ sim | ❌ não (manual do Pedro) | 6 |
| 68 | Lista final: cota do interior só na FUNDECT + caixa "Lista Final Oficial" (finalistas registrados) | ✅ sim | ❌ não (manual do Pedro) | 7 |
| 69 | Lista final oficial: incluir/retirar projeto com justificativa, versão nova e auditoria | ✅ sim | ❌ não (manual do Pedro) | 7 |
| 70 | Aba Credenciamento: credenciar/credenciados, conferência de documentos por pessoa e auditoria | ✅ sim | ❌ não (manual do Pedro) | 8 |
| 71 | Credenciamento: horários do atendimento (+5 min no lançamento retroativo) e itens a entregar | ✅ sim | ❌ não (manual do Pedro) | 8 |
| 72 | Comitê especial → Transporte: assistente de 6 passos, localizador a cada 5s, rota e ETA | ✅ sim | ❌ não (manual do Pedro) | 9 |
| 73 | Comitê especial → Mapa do comitê: tempo real e detalhe do ponto (pessoas, áreas, distância) | ✅ sim | ❌ não (manual do Pedro) | 9 |
| 74 | Fix: salvar o período de ajustes quebrava a tela (`liberada_em_input` de `undefined`) | ✅ sim | ❌ não (manual do Pedro) | 10 |
| 75 | Cards de camiseta e de série sem o balde "N.I." | ✅ sim | ❌ não (manual do Pedro) | 10 |
| 76 | RBAC: admin acumula vários escopos por edição (união das abas) | ✅ sim | ❌ não (manual do Pedro) | 11 |
| 77 | Home do admin em `/admin`, com um botão por aba liberada | ✅ sim | ❌ não (manual do Pedro) | 11 |
| 78 | Nova aba **Dashboards**: todos os cards do painel, agrupados por assunto | ✅ sim | ❌ não (manual do Pedro) | 12 |
| 79 | Aba Projetos enxuta: 6 cards (total, status, categoria e as 3 localidades) | ✅ sim | ❌ não (manual do Pedro) | 12 |
| 80 | Parametrização → **Datas e períodos**: todas as janelas da edição numa tela | ✅ sim | ❌ não (manual do Pedro) | 13 |
| 81 | Modo demo por administrador, na aba Administradores | ✅ sim | ❌ não (manual do Pedro) | 13 |
| 82 | Credenciamento → **Contas temporárias** (CPF, curso e prazo; renováveis) | ✅ sim | ❌ não (manual do Pedro) | 14 |
| 83 | Projeto-exemplo da aba Ajustes (`demo:ajustes`) + isolamento dos dados demo | ✅ sim | ❌ não (manual do Pedro) | 14 |
| 84 | Distribuir/Redistribuir na fila, com barra de progresso | ✅ sim | ❌ não (manual do Pedro) | 15 |
| 85 | Piso da fila do avaliador: a regra geral que socorre a regra por categoria | ✅ sim | ❌ não (manual do Pedro) | 15 |
| 86 | Comunicação → **Feedback**: questionário, convite por e-mail e resultados anônimos | ✅ sim | ❌ não (manual do Pedro) | 16 |
| 87 | Contas temporárias em **horas** (padrão 5) + acesso **agendado** (`valido_de`) | ✅ sim | ❌ não (manual do Pedro) | 17 |
| 88 | `demo:credenciamento` + **lista final demo**, usada só no modo demo do balcão | ✅ sim | ❌ não (manual do Pedro) | 17 |
| 89 | Administradores → **Contas demo**: marcar orientador/avaliador como demo | ✅ sim | ❌ não (manual do Pedro) | 18 |
| 90 | Editar projeto (admin): a **série de cada aluno** abaixo da categoria | ✅ sim | ❌ não (manual do Pedro) | 18 |
| 91 | Modelos de e-mail com **editor rico** (formatação, sem imagens) | ✅ sim | ❌ não (manual do Pedro) | 19 |
| 92 | Parametrização → **Ordem do menu**: as abas do admin na ordem da edição | ✅ sim | ❌ não (manual do Pedro) | 19 |
| 93 | Fix: mala direta quebrava em todos os destinatários (`Undefined variable $html`) | ✅ sim | ❌ não (manual do Pedro) | 20 |
| 94 | Fix: caixa "Quem você quer ouvir" vazia quando as opções falhavam em silêncio | ✅ sim | ❌ não (manual do Pedro) | 20 |
| 95 | Distribuição em rodadas iguais + cota justa da fila do avaliador | ✅ sim | ❌ não (manual do Pedro) | 21 |
| 96 | Avaliação online → **Designações**: tabela com o tempo e retirada com redesignação | ✅ sim | ❌ não (manual do Pedro) | 21 |
| 97 | Credenciamento: cancelar + só conta permanente mexe no credenciamento alheio | ✅ sim | ❌ não (manual do Pedro) | 22 |
| 98 | Editar projeto submetido: trocar o orientador e editar/incluir/remover o coorientador | ✅ sim | ❌ não (manual do Pedro) | 22 |
| 99 | Avaliador: busca na aba "Avaliados" | ✅ sim | ❌ não (manual do Pedro) | 23 |
| 100 | Avaliador: editar o parecer final da avaliação enviada, com justificativa | ✅ sim | ❌ não (manual do Pedro) | 23 |
| 101 | Avaliadores Online: botão de áreas abre a linha; demo vira só o frasco | ✅ sim | ❌ não (manual do Pedro) | 24 |
| 102 | E-mails: formatação à prova de worker desatualizado + envio de teste da mala direta | ✅ sim | ❌ não (manual do Pedro) | 25 |
| 103 | Credenciamento: presença por pessoa e retirada de kit por responsável | ✅ sim | ❌ não (manual do Pedro) | 25 |
| 104 | Nova aba **Almoxarifado**: escopo, janela do evento, assistente de guarda e tabela | ✅ sim | ❌ não (manual do Pedro) | 26 |
| 105 | Almoxarifado: retiradas, correção, exclusão, Registros e contas temporárias | ✅ sim | ❌ não (manual do Pedro) | 26 |
| 106 | Designações por projeto: alvo próprio da distribuição, geral e por categoria | ✅ sim | ❌ não (manual do Pedro) | 27 |
| 107 | Iniciar avaliação: trava do projeto já coberto, com troca automática | ✅ sim | ❌ não (manual do Pedro) | 27 |
| 108 | Avaliações x designações por projeto: dois eixos independentes | ✅ sim | ❌ não (manual do Pedro) | 28 |
| 109 | Credenciamento em rascunho, com dono e regra de quem o assume | ✅ sim | ❌ não (manual do Pedro) | 28 |
| 110 | Modo de distribuição: Total × por Atividade (fila montada no login) | ✅ sim | ❌ não (manual do Pedro) | 29 |
| 111 | Designação manual e avaliação aberta imunes à devolução automática | ✅ sim | ❌ não (manual do Pedro) | 29 |
| 112 | Fim de sessão devolve a fila; avaliação parada há Y dias volta à pilha | ✅ sim | ❌ não (manual do Pedro) | 30 |
| 113 | Designação manual avisa quem já avaliou aquele projeto | ✅ sim | ❌ não (manual do Pedro) | 30 |
| 114 | Fix: anexo sumido do storage virava e-mail sem anexo, em silêncio | ✅ sim | ❌ não (manual do Pedro) | 31 |
| 115 | Fix: designação da organização sumia da tela do avaliador; lista separada | ✅ sim | ❌ não (manual do Pedro) | 31 |
| 116 | Merge das branches do dependabot (20 PRs) | ✅ sim | ❌ não (manual do Pedro) | 32 |
| 117 | Parametrização → Dados de demonstração + corte do vazamento demo na avaliação | ✅ sim | ❌ não (manual do Pedro) | 33 |
| 118 | Nova aba **Mapa do Evento** → Turnos de Apresentação (5 regras, equilíbrio, export) | ✅ sim | ❌ não (manual do Pedro) | 33 |
| 119 | Mapa do Evento → Estandes dos Projetos (faixa por categoria, troca de lugar) | ✅ sim | ❌ não (manual do Pedro) | 34 |
| 120 | Mapa do Evento → planta interativa do ginásio, editável e versionada | ✅ sim | ❌ não (manual do Pedro) | 34 |
| 121 | Designações: botão **Designar** (N×N) + e-mail ao avaliador e ao admin | ✅ sim | ❌ não (manual do Pedro) | 35 |
| 122 | Ajustes demo: 3 sugestões de 3 avaliadores em `orientador@fetecms.test` | ✅ sim | ❌ não (manual do Pedro) | 35 |
| 123 | Identificação: QR Code + código de barras por participante da lista final | ✅ sim | ❌ não (manual do Pedro) | 36 |
| 124 | Credenciamento: leitura do crachá (leitor USB ou câmera) abre a ficha | ✅ sim | ❌ não (manual do Pedro) | 36 |
| 125 | Designações: **Ver notas** (seção por seção + soma) e Registros → **Notas** | ✅ sim | ❌ não (manual do Pedro) | 37 |
| 126 | Ranking: **Verificar disparidade** (amplitude das notas, lista registrada, atalho para designar) | ✅ sim | ❌ não (manual do Pedro) | 38 |
| 127 | Orientador: aba **Pareceres** (nota média, observações anônimas, etapas em pontos fortes/médios/fracos) | ✅ sim | ❌ não (manual do Pedro) | 38 |
| 128 | Lista final: **prévia editável** antes do TXT + coorientador no arquivo | ✅ sim | ❌ não (manual do Pedro) | 39 |
| 129 | Orientador: aba **Documentos** — termo de responsabilidade do finalista, com conferência da assinatura gov.br | ✅ sim | ❌ não (manual do Pedro) | 39 |
| 130 | Público **"finalistas sem o termo"** na mala direta, nos avisos e no feedback | ✅ sim | ❌ não (manual do Pedro) | 40 |
| 131 | Avaliador: aba **Presencial** — intenção de avaliar no dia da feira + orientações | ✅ sim | ❌ não (manual do Pedro) | 40 |
| 132 | Nova aba **Avaliação presencial**: checagem de estandes e espelho da checagem | ✅ sim | ❌ não (manual do Pedro) | 41 |
| 133 | Voluntários: contas temporárias com **vários turnos** de uma vez | ✅ sim | ❌ não (manual do Pedro) | 41 |
| 134 | Avaliação presencial → **Credenciais** (vagas de premiação) e lista da cerimônia | ✅ sim | ❌ não (manual do Pedro) | 42 |
| 135 | Avaliação **no estande**: rubrica própria, nota separada, teto de 3 por projeto | ✅ sim | ❌ não (manual do Pedro) | 42 |
| 136 | Contas temporárias: **presença** no primeiro acesso, aprovada ou rejeitada pelo setor | ✅ sim | ❌ não (manual do Pedro) | 43 |
| 137 | Comitê especial → **Designações** restritas aos avaliadores da comissão | ✅ sim | ❌ não (manual do Pedro) | 43 |
| 138 | Retirada de designação: escolher entre **redesignar** e **apenas remover** | ✅ sim | ✅ sim (Pedro, PR #89 → v1.26) | 0 |
| 139 | Disparidade → **Ver notas**: os avaliadores do projeto lado a lado, por seção | ✅ sim | ✅ sim (Pedro, PR #89 → v1.26) | 0 |
| 140 | Disparidade em abas + **Identificação de padrões** (4 sinais por avaliador) | ✅ sim | ✅ sim (Pedro, PR #89 → v1.26) | 0 |
| 141 | **Desconsiderar** a nota de um avaliador, com justificativa e reversível | ✅ sim | ✅ sim (Pedro, PR #89 → v1.26) | 0 |
| 142 | Substituir a nota desconsiderada: outro avaliador ou o **próprio admin** | ✅ sim | ✅ sim (Pedro, PR #89 → v1.26) | 0 |
| 143 | Perfil do avaliador: quantas avaliações tem quem está **logo à frente** | ✅ sim | ✅ sim (Pedro, PR #89 → v1.26) | 0 |
| 144 | Avaliar no lugar da nota: a rubrica abre **antes**, e o envio é que desconsidera | ✅ sim | ✅ sim (Pedro, PR #90) | 0 |
| 145 | `demo:pareceres`: projeto já avaliado, com pareceres em níveis e ajustes para o orientador demo | ✅ sim | ✅ sim (Pedro, PR #91) | 0 |
| 146 | Orientador: abas Ajustes e Pareceres viram **uma só**, e a nota do projeto sai da tela | ✅ sim | ✅ sim (Pedro, PR #92) | 0 |
| 147 | Tela inicial do orientador: aviso de fim da avaliação com atalho para Ajustes e Pareceres | ✅ sim | ✅ sim (Pedro, PR #93 → v1.26.5) | 0 |
| 148 | Fix: menu lateral rola quando a tela é mais baixa que o número de abas | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 149 | Nova aba **Cerimonial**: check-in da premiação, visão geral e contas temporárias | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 150 | Ajustes e Pareceres: o parecer continua visível depois do prazo (só a decisão fecha) | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 151 | Avaliador: tamanho de camiseta (perfil, cadastro e 4º card do painel) | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 152 | Mapa do Evento: ruas nomeadas, tela cheia, cor por situação e lista filtrável | ✅ sim | ❌ não (manual do Pedro) | 2 |
| 153 | Ranking dos projetos: baixar em CSV, Excel, PDF e TXT, no recorte dos filtros | ✅ sim | ✅ sim (Pedro, PR #99 → v1.27.2) | 0 |
| 154 | Avaliador: idiomas em que pode avaliar (cadastro, perfil, tabela, públicos e painel) | ✅ sim | ✅ sim (Pedro, PR #99 → v1.27.2) | 0 |
| 155 | Contas temporárias: cadastro **em lote** (modelo Excel → prévia → senhas geradas) + **remoção** | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 156 | Fix: PDF de etiquetas (QR/barras) sem código e estourando a memória | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 157 | Cadastro manual de projeto (finalista ou credencial de feira afiliada; já credenciado opcional) | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 158 | Mala direta: caixa **Somente finalistas** (equipe inteira) + variável `{{projetos}}` | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 159 | Lista final: código do projeto **fixado** e enviado por e-mail à equipe de cada finalista | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 160 | Lista final: **exportar** (lista nominal, crachás, certificados, contatos, projetos e recorte livre) | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 161 | Credenciamento **fora do prazo** aprovado (data prevista), visível no credenciamento e na avaliação | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 162 | **Suporte no evento**: acompanhante e intérpretes (aba do orientador, aprovação, crachá do acompanhante) | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 163 | Nova aba **Certificados**: avaliadores por fase, participantes (CPF, função, atividade) e declaração nominal | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 164 | Listas **preliminares** (várias) e **finais** (uma ativa): tipo, rascunho editável livre, gerar e reativar | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 165 | Lista final montada pela **união de preliminares** + telas de geração, listas e detalhe | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 166 | Fix (segurança): `league/commonmark` 2.10.1 → 2.10.3 (2 advisories do `composer audit`) | ✅ sim | ✅ sim (Pedro, PR #104 → v1.28) | 0 |
| 167 | Orientador: baixar cada documento enviado e **todos num ZIP**, no resumo do projeto | ✅ sim | ✅ sim (Pedro, PR #105 → v1.29) | 0 |
| 168 | Listas preliminares e finais: **reordenar** o grupo (código renumerado) e **digitar o código** | ✅ sim | ✅ sim (Pedro, PR #105 → v1.29) | 0 |
| 169 | Avaliação presencial: **horário dos turnos** (vale todo dia do evento) e **ativação do avaliador** por turno na cabine | ✅ sim | ✅ sim (Pedro, PR #105 → v1.29) | 0 |
| 170 | **Distribuição presencial** por turno: só credenciado + checado, rodadas iguais, prazo = fim do turno + 30 min | ✅ sim | ✅ sim (Pedro, PR #105 → v1.29) | 0 |
| 171 | Avaliador: aba **Avaliação presencial** (só no turno ativado), wizard da rubrica + checklist dos itens da checagem | ✅ sim | ✅ sim (Pedro, PR #105 → v1.29) | 0 |
| 172 | `demo:avaliacao-presencial`: `avaliador@fetecms.test` pronto para ensaiar avaliação e distribuição | ✅ sim | ✅ sim (Pedro, PR #105 → v1.29) | 0 |
| 173 | Comunicação: grupo **Todos os finalistas** combinável (mala direta) + público de orientadores finalistas (avisos e feedback) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 174 | Orientador: **termo de uso de imagem** (vários arquivos, estudantes assinalados) + card "Agilize o processo!" | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 175 | Credenciamento: **termo de uso de imagem** já marcado por estudante quando enviado online (item do sistema no catálogo) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 176 | Mapa do Evento: termo de imagem no painel do estande, na lista filtrável e nas exportações (online + balcão) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 177 | Listas: código **em branco, fora do padrão ou da sequência** com aviso; **repetido** barra a geração; editar reordena | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 178 | Listas: **arrastar** o projeto dentro do grupo renumera os códigos na hora (sem modo "Reordenar") | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 179 | Listas: montar preliminar **ou** final pela união de listas geradas — **preliminares e finais** —, herdando os códigos fixados | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 180 | Listas: **apelido** e **descrição** na criação, nos cartões e editáveis no detalhe (sem justificativa) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 181 | Parametrização: **lista única** de itens do credenciamento e da checagem (de pessoa ou do projeto, onde conferir, receber antes) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 182 | Credenciamento e checagem lendo a lista única: bloco "Do projeto" no balcão e marcador por pessoa na checagem | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 183 | Aba Documentos genérica: cada item "receber antes" da lista tem a sua seção (um ou mais arquivos, pessoas marcadas) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 184 | O que chegou antes já vem marcado no balcão e na checagem, para qualquer item (o bloco fixo do termo saiu) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 185 | Mala direta: variáveis `{{codigo_projeto}}` e `{{turno}}`, só com destino 100% finalista (aviso na tela e recusa no servidor) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 186 | As mesmas variáveis no modelo "Código do projeto" e nos avisos (resolvidas para quem lê) | ✅ sim | ✅ sim (Pedro, PR #107 → v1.30) | 0 |
| 187 | Lista final: baixar os **projetos de pesquisa** num ZIP (todos ou por seleção individual, de categoria ou de área) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 188 | Etiquetas de identificação: **turno e estande**, papel ao lado do nome, sem projeto nem escola | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 189 | Identificação dos participantes em **ordem alfabética** de nome + **16 etiquetas por folha** (margem ~5 mm, cabeçalho de uma linha) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 190 | Comitê especial → **Premiação**: credenciais e prêmios nos projetos premiados (mesma base) + lista de premiação em TXT | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 191 | Avaliação presencial → **Cadastro de avaliadores** só do evento (senha gerada, fora da avaliação online) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 192 | Credenciais e Prêmios: campo **Informações adicionais** (cadastro, edição no cartão, visível ao comitê) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 193 | Avaliador da comissão especial: aba **Comitê especial** recomenda finalistas para credenciais/prêmios; admins aprovam ou rejeitam | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 194 | Recomendações do comitê: filtro por **título do projeto, área e categoria** (contagem presa ao recorte) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 195 | Cartão da credencial mostra as **aprovadas pelo comitê**, com botão **Premiar** (Premiação e Credenciais e Prêmios) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 196 | **Painel da premiação**: vagas que faltam por credencial + ranking dos premiados por área (admin e comissão, tela cheia) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 197 | Mapa do Evento: **rua da borda esquerda**, ao lado da primeira coluna de estandes (nomeável na configuração) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 198 | Mapa em tela cheia: **faixa da área** em cada estande (cor + sigla, legenda que destaca) e atualização a cada **5 s** com aviso no canto | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 199 | Mapa em tela cheia: **visualizações** Credenciamento, Checagem e Avaliação (cor por nº de avaliações), no canto inferior esquerdo | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 200 | Mapa em tela cheia: **dashboard** das avaliações (por nº recebido, geral e por área; comissão especial por área; barra de progresso) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 201 | Premiação: os **pedidos de aprovação** do comitê dentro do **cartão da credencial** (aprovar/rejeitar ali); a seção separada sai e os filtros recortam os cartões | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 202 | Premiação: **cancelar a premiação** de uma recomendação aprovada (tira a credencial, a recomendação continua aprovada) | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 203 | Premiação: buscar o finalista pelo **código da lista final** (FET.AGR-001) no cartão da credencial e na aba do comitê | ✅ sim | ✅ sim (Pedro, PR #108 → v1.31) | 0 |
| 204 | Câmera do check-in: o vídeo liga no elemento já desenhado (fim do quadro em branco), com aviso de abertura e motivo do erro | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 205 | Câmera no **iPhone** (e Chrome no Windows/Linux): ZXing em WebAssembly servido pelo portal quando não há `BarcodeDetector` | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 206 | Cerimonial: ler o crachá **já registra a entrada**, com o nome num card de 2s (barra no botão Ok) | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 207 | Cerimonial → **Eventos de cerimônia**: nome + público (categorias), check-in e painel por evento | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 208 | Cerimonial: a porta **escolhe o evento** no check-in; Visão Geral e Premiados por evento ou **Todos** | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 209 | Fix: cadastro **em lote** de contas temporárias respondia **500** com o "Início do acesso" fora de dd/mm/aaaa hh:mm | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 210 | Cerimonial: a **mesma pessoa em vários papéis** (CPF, ou e-mail + nome) entra e sai de todos de uma vez | ✅ sim | ❌ não (manual do Pedro) | 1 |
| 211 | Cerimonial → Visão Geral: **Marcar chegada** na lista de quem falta | ✅ sim | ❌ não (manual do Pedro) | 1 |

> **Sprints 210–211 (mesma branch):** o professor que **orienta um projeto e coorienta outro** tem um
> crachá por vínculo (o código é projeto + papel + id), e ler um marcava só aquele: na Visão Geral
> ele aparecia faltando como coorientador. Reimprimir as etiquetas não era opção.
> (a) **Sprint 210** — o portal passou a reconhecer a **mesma pessoa** entre os finalistas do público
> do evento (`CerimonialService::mesmaPessoa()`): com CPF dos dois lados, só o **CPF** decide; sem
> ele (o cadastro manual deixa em branco), vale o **e-mail com o mesmo nome** — o e-mail sozinho não
> basta, porque estudante sem e-mail próprio às vezes é cadastrado com o do professor. Ler o crachá
> de qualquer vínculo, ou marcar na ficha, marca **todos** os vínculos dela no evento — inclusive o
> orientador de dois projetos —, e o card da porta lista o que entrou junto ("Entrada também
> registrada como Coorientador(a) de “Robô seguidor de linha”."). **Desfazer** um leva todos, com a
> mesma justificativa e um registro por vínculo. A ligação **não atravessa eventos**: quem entrou na
> premiação da FETECMS não fica presente na da FETEC Jr. A busca vai direto às três tabelas de
> pessoas pelo CPF e pelo e-mail (`outrosVinculos()`), porque roda a cada crachá lido. Registros →
> Cerimonial diz quando um check-in foi "marcado junto com o vínculo de …".
> (b) **Sprint 211** — na **Visão Geral**, a lista nominal de **quem falta** (cards de pessoas e de
> medalhas) ganhou **Marcar chegada** em cada linha, para qualquer um que entrou sem ser registrado
> na porta. Precisa da cerimônia escolhida (no "Todos" não se sabe em que sala a pessoa entrou) e do
> período do evento aberto; marca pelo mesmo caminho da ficha, então os outros papéis da pessoa vêm
> junto, e o painel se recarrega.
> Back **1469/1469**, front **789/789**, Pint limpo, build OK.
>
> **Sprint 209 (mesma branch):** relato de produção: depois de subir o Excel no cadastro **em lote**
> de contas temporárias, a tela dizia "Erro interno no servidor". A prévia lê o "Início do acesso"
> de cada linha por uma lista de formatos, e o `CarbonImmutable::createFromFormat` **lança
> exceção** quando o texto não bate com o formato — o código esperava `false` e só capturava a
> `ValidationException`. Bastava uma linha com `04/10/2026` (sem hora), `4/10/2026 8:00` (sem o
> zero) ou `04/10/2026 08:00:00` (com segundos, como o LibreOffice e algumas versões do Excel
> gravam) para a prévia inteira cair com 500, em vez de apontar a linha. A leitura de data da
> planilha passou para o `DateTimeImmutable::createFromFormat` nativo, que devolve `false` e conta
> os avisos (`ContaTemporariaService::lerData()`): nada ali lança mais, os três jeitos acima são
> aceitos (a data sem hora é meia-noite), o que não é data vira erro **da linha** e o **31/02**
> continua recusado em vez de virar 03/03 calado. Os turnos usam o mesmo leitor. De quebra, os
> turnos que voltam da prévia na confirmação passaram a ser validados como data — um payload
> adulterado também dava 500. Reproduzido em teste antes da correção.
> Back **1462/1462**, front **784/784**, Pint limpo, build OK.
>
> **Sprints 207–208 (mesma branch):** a feira faz **duas premiações** — FETECMS numa, FETEC Jr e
> FETECMS FUNDECT na outra —, com o mesmo funcionamento e públicos diferentes.
> (a) **Sprint 207** — **eventos de cerimônia** (`cerimonial_eventos`: nome + `categorias`, JSON),
> cadastrados em Cerimonial → **Eventos de cerimônia** (`/admin/cerimonial/eventos`,
> `POST|PUT|DELETE /admin/cerimonial/eventos`, só admin permanente; `GET` também para a conta da
> porta). O público é a lista de **categorias**, e a mesma categoria pode estar em mais de um evento
> (uma cerimônia geral ao lado das duas, por exemplo). Nome repetido na edição é recusado — é ele
> que a porta escolhe. Evento que já recebeu check-in de verdade **não é excluído** (é a memória de
> quem esteve na sala); para mudar quem ele recebe, edita-se o público. O check-in ganhou
> `cerimonial_checkins.evento_id` e a unicidade passou a ser por evento: quem vai às duas
> cerimônias entra nas duas. A migration cria o evento "Cerimônia de premiação", com as três
> categorias, para cada edição que já tinha check-in, e os pendura nele. No servidor, tudo passou a
> receber o evento (`evento_id`, na query): check-in pelo crachá, check-in na ficha, desfazer,
> busca e ficha **exigem** um (422 "Escolha o evento de cerimônia."; evento de outra edição é 404) e
> recusam o projeto fora do público — o crachá diz de quem é: "Ana Paula é de um projeto da FETEC
> Jr, que não está no público de Premiação FETECMS.". Registros → Cerimonial passou a dizer em que
> evento foi cada check-in.
> (b) **Sprint 208** — as telas. O **Check-in** pergunta "Em que cerimônia você está?" no topo, com
> um botão por evento e o público embaixo, e o título mostra o evento escolhido. A escolha fica
> **neste aparelho** (`localStorage`, `useEventoCerimonial`): o tablet da porta da FETECMS passa a
> tarde inteira na FETECMS. Com um evento só ele já vem escolhido; com mais de um, ninguém escolhe
> pelo atendente — a leitura e a busca só aparecem depois da escolha. A ficha abre no evento do
> link (`?evento=`). **Visão Geral** e **Premiados** ganharam o mesmo seletor com **Todos**, o
> padrão, que soma o público de todos os eventos contando cada pessoa **uma vez** (presente se
> entrou em qualquer um). De quebra, um defeito que a separação expôs: o card de premiados e o de
> credenciais contavam **todos** os projetos premiados da edição, mesmo os de fora do público —
> agora só os do evento.
> Back **1462/1462**, front **784/784**, Pint limpo, build OK.
>
> **Sprint 206 (mesma branch):** na porta da cerimônia, **leu, entrou**. Ler o crachá (leitor USB,
> código digitado + Enter ou câmera) deixou de abrir a ficha do projeto: a entrada **daquela
> pessoa** é registrada na hora (`POST /admin/cerimonial/checkin-codigo`,
> `CerimonialService::checkinPorCodigo()`, com as mesmas três conferências do crachá) e um card
> mostra o nome em letra grande, o papel e o projeto. O card **some em 2 segundos**, e o tempo que
> falta é uma barra que vai **preenchendo o botão Ok** (`CardCheckin.jsx`, animação
> `.fetec-preencher`); clicar em Ok fecha antes. Quem **já tinha entrado** recebe um card âmbar com
> a hora da entrada, e nada é gravado de novo. Crachá **recusado** vira um card vermelho com o
> motivo que **não some sozinho** — alguém precisa olhar para aquela pessoa. O card não rouba o
> foco do campo: a próxima bipada cai no lugar certo, e as leituras entram numa **fila** (o leitor
> bipa a pessoa seguinte antes de o servidor responder sobre a anterior, e nenhuma se perde). A
> câmera **fica aberta** entre uma pessoa e outra. A ficha continua sendo o caminho de quem chega
> **sem** crachá, pela busca por nome ou CPF — e a busca aberta passa a mostrar como presente quem
> acabou de entrar pelo crachá. Com o evento fechado, a leitura diz isso em vez de "código não
> reconhecido". O `POST /admin/cerimonial/codigo` (o crachá que só abria a ficha) **saiu**; o
> leitor do Credenciamento continua abrindo a ficha, como antes.
> Back **1450/1450**, front **766/766**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 204–211):** (1) `git push origin feat/cerimonial-camera-e-leitura-direta`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub). **Uma migration**
> (`cerimonial_eventos` + `cerimonial_checkins.evento_id`), **nenhuma variável de `.env`** e
> **uma dependência nova de npm** (`barcode-detector`, que traz o `zxing-wasm`): rode
> `composer install`, `php artisan migrate`, `npm ci && npm run build` no deploy. (1b) **Antes da
> cerimônia, cadastre os eventos** em Cerimonial → Eventos de cerimônia ("Premiação FETECMS" com a
> FETECMS; "Premiação FETEC Jr e FUNDECT" com as duas): sem evento, a porta não registra check-in.
> Se já houver check-in na edição, a migration criou "Cerimônia de premiação" com as três
> categorias — renomeie-o e ajuste o público em vez de criar um terceiro. Cada tablet da porta
> escolhe o seu evento uma vez. (1c) O lote de contas temporárias volta a funcionar com o deploy;
> quem já tentou só precisa subir a planilha de novo. (1d) A ligação da mesma pessoa depende do
> **CPF** igual nos dois cadastros (o do perfil do orientador e o do coorientador): se um deles
> estiver sem CPF, vale o e-mail com o mesmo nome; sem nenhum dos dois, use **Marcar chegada** na
> Visão Geral. (2) A câmera **exige https** — no
> iPhone, sem https o navegador nem oferece a câmera, e a tela agora diz isso. (3) O servidor deve
> entregar o `.wasm` com o tipo `application/wasm` (o nginx atual já faz; sem isso ele carrega mais
> devagar, mas carrega). (4) **No iPhone**, na primeira vez o Safari pergunta se libera a câmera;
> negada, a tela explica o caminho (Ajustes › Safari › Câmera). Vale testar no iPhone XR antes do
> evento — aqui a leitura foi conferida no Chromium sem a API nativa (o mesmo caminho do iPhone),
> mas não num iPhone de verdade. (5) **Na porta, a leitura agora registra direto**: quem bipar um
> crachá para "só conferir" registra a entrada. Para desfazer, abra a ficha pela busca e use
> *Desfazer* (pede justificativa).
>
> **Sprints 204–205 (branch `feat/cerimonial-camera-e-leitura-direta`, saída da `origin/main` @ `c4aa1e8`):**
> a câmera dos dois balcões que leem crachá (Credenciamento e Cerimonial).
> (a) **Sprint 204** — relato: no check-in do cerimonial, "Ler com a câmera" abria um **quadro em
> branco**. O stream era ligado ao `<video>` num `setTimeout(0)` logo depois do `setCamera(true)`, e
> esse timeout podia correr antes de o React desenhar o elemento: `video.current` vinha nulo, a
> câmera ficava ligada e nada aparecia. A câmera virou um componente próprio, `LeitorCamera.jsx`,
> que existe montado **só enquanto está aberta** e faz tudo no efeito de montagem (quando o vídeo já
> está na página); desmontar desliga o aparelho. No caminho: `muted` escrito na **propriedade** (o
> React não o põe no elemento, e o iPhone só toca vídeo sem toque se ele estiver mudo),
> `playsInline`/`autoPlay`, `facingMode` como *ideal* (o notebook sem câmera traseira não falha),
> "Abrindo a câmera…" enquanto acorda, uma mira quando está pronta e o **motivo** quando não abre
> (permissão negada — com o caminho no iPhone —, nenhuma câmera, câmera em uso). O mesmo crachá
> parado na frente conta **uma vez** (`REPETICAO_MS`, 4s sem vê-lo para contar de novo), e enquanto
> a leitura está no servidor nenhum quadro novo é examinado. `LeitorCodigo` (balcão) e
> `LeitorCerimonial` passaram a usá-lo.
> (b) **Sprint 205** — no iPhone (Safari) a tela dizia que o navegador não lia QR. **Nenhum
> navegador do iPhone tem a `BarcodeDetector`** (todos são WebKit), e o Chrome no Windows e no Linux
> a expõe sem motor por trás — provavelmente o quadro em branco de quem testou no computador. O
> detector agora sai de `lib/leitorCamera.js`: o do navegador quando ele lê **QR e Code 128**, e no
> resto o **`barcode-detector`** (dependência nova de npm), a mesma API sobre o ZXing em
> WebAssembly. O `.wasm` (~1 MB, 460 KB com gzip) entra no build como asset e é **servido pelo
> portal**, não pelo CDN padrão da biblioteca; ele e o JS (~44 KB) são baixados **só** quando alguém
> abre a câmera num navegador que precisa deles. O botão da câmera passou a depender só de a câmera
> poder abrir (`getUserMedia` em **contexto seguro**); fora de https a tela diz isso, em vez de
> culpar o aparelho. Conferido no Chromium sem a API nativa, com o crachá gerado pelo
> `IdentificacaoService`: QR e Code 128 lidos, inclusive reduzidos, girados e borrados.
> Back **1445/1445**, front **750/750**, build OK.
>
> **Sprint 203 (mesma branch):** o campo de busca de cada cartão de credencial (Premiação e
> Credenciais e Prêmios) e o da aba **Comitê especial** do avaliador passaram a mostrar e achar o
> finalista pelo **código da lista final vigente** — "FET.AGR-001 — Título"; digitar "agr-001" ou
> "FET.AGR" já filtra. O código também aparece nas linhas do cartão (quem recebeu, pedidos,
> aprovadas, rejeitadas) e nas recomendações do avaliador. `candidatos()` e as linhas trazem
> `codigo` (nulo quando o projeto está sem código na lista).
> Back **1445/1445**, front **735/735**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 201–203):** nenhuma migration, variável de `.env` ou dependência.
> O `GET /api/v1/admin/premiacao/recomendacoes` deixou de existir (as recomendações vêm no
> `GET /admin/comite/premiacao` e no `GET /admin/presencial/credenciais`). Recarregue a página
> para ver os cartões novos.
>
> **Sprints 201–202 (mesma branch):**
> (a) **Sprint 201** — as recomendações do comitê saíram da seção separada e foram para **dentro do
> cartão de cada credencial**, nas duas telas que o usam (Comitê → Premiação e Avaliação presencial →
> Credenciais e Prêmios): os **pedidos de aprovação**, com quem recomendou, quando e a justificativa,
> e os botões **Aprovar**/**Rejeitar**; as **aprovadas**, com **Premiar** (e um *Rejeitar* para
> desaprovar); e as **rejeitadas**, recolhidas em "ver rejeitadas", com *Reconsiderar e aprovar*. No
> lugar da seção ficou a **barra de filtros** no topo — busca pelo **título ou código** do projeto
> (FET.AGR-001), área e categoria —, que recorta só as recomendações dos cartões (o cartão e quem já
> recebeu a credencial aparecem sempre) e mostra quantos pedidos há no recorte. No servidor,
> `CredenciaisService::listar()` passou a trazer `pendentes`, `aprovadas` e `rejeitadas` por
> credencial, aceita os filtros (`FiltroRecomendacoesRequest`) e casa o código pela lista final
> vigente (`codigos()`); o `GET /admin/premiacao/recomendacoes` e o componente da seção saíram — só
> a decisão (`POST .../recomendacoes/{id}/decisao`) continua.
> (b) **Sprint 202** — a aprovada **já premiada** ganha **Cancelar premiação**: depois de uma
> confirmação, a credencial sai do projeto (a vaga volta) e a recomendação **continua aprovada**,
> com o *Premiar* de volta. É a retirada de sempre, sem justificativa — para desaprovar, *Rejeitar*.
>
> **Sprints 199–200 (mesma branch):**
> (a) **Sprint 199** — no **canto inferior esquerdo** da tela cheia, três **visualizações**:
> *Credenciamento* e *Checagem* (verde para quem já passou, branco para quem falta) e *Avaliação*,
> que pinta o estande pela **quantidade de avaliações presenciais concluídas** — branco para
> nenhuma e um azul cada vez mais escuro até o máximo por projeto da edição (até 3, os passos
> validados da escala ordinal). A legenda fica no mesmo canto, com quantos estandes há em cada faixa.
> A faixa da área (Sprint 198) continua por cima de qualquer visualização. Fora da tela cheia o mapa
> segue com a cor de sempre (situação ou ocupação).
> (b) **Sprint 200** — o botão **Dashboard**, ao lado de *Sair da tela cheia*, abre um painel
> sobre o lado direito do mapa (`PainelAvaliacoesMapa.jsx`), no espírito do resumo da avaliação
> online: a **barra** de avaliações feitas sobre a meta (projetos do turno × avaliações por projeto;
> o que passa da meta num projeto não compensa a falta em outro), **projetos por quantidade de
> avaliações recebidas** (0, 1, 2, 3+) no geral e **por área**, e quantos projetos de cada área já
> foram avaliados pela **comissão especial** (avaliação presencial de avaliador marcado como
> comissão). É calculado das mesmas linhas que pintam o mapa (`lib/mapaTelaCheia.js`), então se
> atualiza junto, a cada 5 segundos, e nunca discorda do desenho. Só avaliações **presenciais**.
> Back **1442/1442**, front **737/737**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 197–200):** nenhuma migration, nenhuma variável de `.env`, nenhuma
> dependência. O mapa precisa de **estandes gerados** (Mapa do Evento → Estandes) para pintar
> alguma coisa; a faixa da área usa a **sigla** de Parametrização → Áreas (área sem sigla ganha as
> três primeiras letras do nome e a cor cinza "Outras" se não for uma das oito da feira). A rua da
> borda nasce sem nome: batize-a em *Editar planta*.
>
> **Sprints 197–198 (mesma branch):**
> (a) **Sprint 197** — a **rua da borda esquerda**: a varredura de corredores (`PlantaEvento::ruas()`)
> só achava o vão **entre** duas fileiras, e a rua que passa ao lado da primeira coluna, entre ela e
> a parede, não tem fileira do outro lado. Ela entra agora como uma rua a mais (`borda: true`), meia
> célula à esquerda da primeira coluna (chave `v:-0.5` na prancha padrão) — e, como as outras, anda
> junto se a coluna for movida. Nomeia-se em *Editar planta → Ruas do evento*; o desenho abre uma
> célula a mais à esquerda para o nome caber.
> (b) **Sprint 198** — em **tela cheia** cada estande ganha uma **faixa na cor da área** do projeto,
> na base do quadrado, separada do preenchimento por um fio branco e com a **sigla escrita** (AGR,
> BIO…). A cor é fixa por área (nunca pela posição na lista; área nova cai em cinza "Outras") e foi
> escolhida pelo guia de cores, com validação por script: as oito passam lado a lado, mas num mapa em
> que todas aparecem juntas algumas ficam próximas demais (vermelho × laranja) — por isso a sigla
> escrita e a **legenda que destaca uma área** (clicar apaga as outras). O mapa em tela cheia se
> atualiza **a cada 5 segundos** (fora dela, 30s) e o canto superior esquerdo diz isso — "Ao vivo ·
> atualizado às 14:32:05" —, avisa quando a conexão cai (o mapa fica no último retrato) e quando o
> dia escolhido já acabou. A linha do estande passou a trazer `area_id`, `area_sigla` e
> `avaliacoes_comite` (este usado pelo painel da Sprint 200), e a planta traz a lista `areas`.
>
> **Sprint 196 (mesma branch):** o **Painel da premiação** — a base de Credenciais e Prêmios
> lida de longe, para a mesa da deliberação. Abre por botão na **Premiação do comitê** (admin,
> `/admin/comite/premiacao/painel`, `GET /admin/premiacao/painel`) e na aba **Comitê especial** da
> comissão (`/avaliador/comite/painel`, `GET /avaliador/comite/painel`), só leitura, com **tela
> cheia** e recarga a cada 30s. Traz: quantas **vagas faltam** (só onde há número de vagas — a
> credencial sem limite conta o que deu e não entra na soma), preenchidas de quantas, esgotadas,
> projetos premiados e recomendações **aprovadas aguardando o anexo**; um cartão por credencial
> com a barra de vagas e quem já levou; e o **ranking por área do conhecimento** — em cada área, os
> premiados do que mais recebeu para o que menos, mesma quantidade dividindo a posição, áreas da que
> mais premiou para a que menos. Só credenciais **ativas**, as mesmas da lista em TXT.
> `PainelPremiacaoService`. A comissão passa a enxergar quem recebeu o quê **neste painel** — a aba
> de recomendação continua sem essa informação no formulário.
> Back **1440/1440**, front **725/725**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 194–196):** nenhuma migration nova, nenhuma variável de `.env`,
> nenhuma dependência. Vão no mesmo push das 187–193. Quem estiver com o portal aberto precisa
> recarregar a página para ver os botões novos.
>
> **Sprints 194–195 (mesma branch):**
> (a) **Sprint 194** — a seção **Recomendações do comitê** ganhou o recorte pelo **projeto**: busca
> pelo título, **área** e **categoria** (FETECMS, FETEC Jr, FETECMS FUNDECT), no servidor
> (`GET /admin/premiacao/recomendacoes?q=&area_id=&categoria=`). A contagem de cada situação
> (Pendentes, Aprovadas, Rejeitadas) respeita o mesmo recorte — o número no botão é o que a lista
> mostra ao clicar nele. Os seletores vêm na própria resposta (`opcoes`).
> (b) **Sprint 195** — o **cartão de cada credencial** mostra as recomendações **aprovadas** para
> ela, uma linha por projeto com quem recomendou, e um botão **Premiar** que faz a atribuição de
> sempre (mesmas vagas; desabilitado com a credencial esgotada). A aprovação continua **só
> marcando** — o clique é que premia. Projeto que já recebeu a credencial aparece como *Premiado*.
> Vale nas duas telas do cartão (Comitê → Premiação e Avaliação presencial → Credenciais e Prêmios),
> e as duas metades conversam: decidir uma recomendação recarrega os cartões, e premiar pelo
> cartão recarrega o "já recebeu" da lista. `CredenciaisService::listar()` traz `aprovadas` e
> `aprovadas_pendentes` por credencial.
> Back **1434/1434**, front **717/717**, Pint limpo.
>
> **Sprint 193 (mesma branch):** o avaliador marcado como **comissão especial** ganhou a aba
> **Comitê especial** (`/avaliador/comite`, `AvaliadorComiteController`): para cada credencial ou
> prêmio **ativo** — com as informações adicionais da Sprint 192 — ele **recomenda** finalistas da
> lista vigente, com justificativa opcional, e vê a situação de cada recomendação (aguardando,
> aprovada, rejeitada); enquanto ninguém decidiu, pode retirá-la. **Sem teto** por avaliador nem por
> credencial — o único limite é não repetir a mesma (mesma pessoa, projeto e credencial). Quem não
> é da comissão não vê a aba e recebe 403. O que o projeto já recebeu não vai para ele.
> A organização decide na seção **Recomendações do comitê**, que aparece em Comitê especial →
> Premiação e em Avaliação presencial → Credenciais e Prêmios (`aba:comite,avaliacao_presencial`,
> `/admin/premiacao/recomendacoes`): filtro por situação, quem recomendou e por quê, e **Aprovar** /
> **Rejeitar** — trocáveis depois, com o nome de quem decidiu por último. **Aprovar só marca**: a
> credencial continua sendo anexada no cartão dela, que é onde valem as vagas (duas aprovadas para
> uma vaga não estouram nada). A linha avisa quando o projeto **já recebeu** aquela credencial.
> `recomendacoes_premiacao`, `RecomendacaoPremiacaoService`, `App\Enums\StatusRecomendacao`.
> Back **1432/1432**, front **712/712**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 191–193):** (1) as três sprints estão na mesma branch das
> 187–190 (`feat/premiacao-comite-e-etiquetas`): um push só. **Três migrations novas**
> (`avaliador_profiles.apenas_presencial`/`cadastrado_por`, `credenciais.informacoes` e a tabela
> `recomendacoes_premiacao`), nenhuma variável de `.env` e nenhuma dependência nova. (2) A aba
> **Comitê especial** do avaliador só aparece para quem está marcado como comissão especial em
> Avaliadores Online (a estrela) — marque antes do evento, e quem já estiver logado precisa
> recarregar a página. (3) Para o avaliador cadastrado pelo presencial entrar no comitê, marque-o
> também lá: ele aparece em Avaliadores Online com o selo *Só presencial*. (4) A senha gerada no
> cadastro presencial aparece **uma vez**; perdida, use *Gerar nova senha* na mesma tela.
>
> **Sprints 191–192 (mesma branch):**
> (a) **Sprint 191** — **Avaliação presencial → Cadastro de avaliadores**
> (`/admin/presencial/avaliadores`, `AvaliadorPresencialCadastroService`): a organização cria a conta
> de quem avalia **só no dia da feira**, com os mesmos dados do cadastro público de avaliador menos
> camiseta, idiomas, cidade e estado — e as mesmas validações, inclusive a exclusão mútua com
> orientador e coorientador (`CadastrarAvaliadorPresencialRequest` estende o
> `RegisterAvaliadorRequest`). Sem código de confirmação: a **senha é gerada**
> (`App\Support\SenhaGerada`, a mesma das contas temporárias em lote) e mostrada **uma vez**;
> perdida, gera-se outra na mesma tela (as sessões abertas caem). A conta nasce com o "sim" da aba
> Presencial e com `avaliador_profiles.apenas_presencial`, que a tira da **avaliação online**:
> distribuição em massa, `designarUm`, reposição da fila, sorteio, fila do login, opções e designação
> manual (individual, por área, por subárea, comissão e em massa) — `User::scopeAvaliadorDaOnline()`.
> Para essa pessoa o menu esconde *Avaliações*, a tela inicial abre a avaliação no estande e a
> intenção presencial não pode virar "não". Em Avaliadores Online ela aparece com o selo
> **Só presencial** (é lá que se marca a comissão especial).
> (b) **Sprint 192** — **Informações adicionais** da credencial ou prêmio (`credenciais.informacoes`,
> até 5.000 caracteres): critérios, regras do órgão, o que a vaga cobre. A `descricao` continua sendo
> a linha curta. Entra no cadastro e é **editável no cartão** (Avaliação presencial); o comitê o lê
> na Premiação e, na Sprint 193, ao recomendar. **Não vai** para a lista de premiação em TXT.
> Back **1421/1421**, front **698/698**, Pint limpo.
>
> **Sprints 189–190 (mesma branch):**
> (a) **Sprint 189** — a identificação dos participantes passou a sair em **ordem alfabética de
> nome** (sem acento e sem caixa; empate pelo código), na tela e no PDF — a folha de etiquetas é
> procurada pela pessoa, não pelo projeto. O ZIP de SVGs continua com uma pasta por equipe. A folha
> aproveita mais a página: margem de ~5 mm, cabeçalho numa linha só e **2 colunas × 8 linhas = 16
> etiquetas** (eram 10). Três colunas foram descartadas: o código de barras ficaria fino demais para
> o leitor USB. A folga entre o QR e as barras subiu para 10 pt (zona de silêncio do leitor).
> (b) **Sprint 190** — o **Comitê especial** ganhou a seção **Premiação** (`/admin/comite/premiacao`,
> `ComitePremiacaoController`): quem tem o escopo do comitê anexa e retira as credenciais e prêmios
> nos projetos premiados e baixa a **lista de premiação em TXT** — o mesmo arquivo, byte a byte, de
> Avaliação presencial → Credenciais e Prêmios. A base é **a mesma** (`credencial_projeto`): o que o
> comitê anexa vale no cerimonial e na lista da cerimônia, porque duas listas paralelas acabariam
> discordando no palco. O comitê **não cadastra**: só aparecem as credenciais **ativas**
> (`CredenciaisService::listar(soAtivas: true)`), e as regras de sempre continuam no servidor (só
> finalista, teto de vagas, credencial desativada recusada). A tela traz também a visão inversa —
> **Projetos premiados**, cada um com o que recebeu. O cartão da credencial virou componente
> compartilhado (`CartaoCredencial.jsx`), com os botões de cadastro opcionais.
> Back **1407/1407**, front **690/690**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 187–190):** (1) `git push origin feat/premiacao-comite-e-etiquetas`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub). Esta release **não tem
> migration, variável nova de `.env` nem dependência nova**. (2) **Etiquetas**: o layout mudou de
> novo (16 por folha, QR à esquerda) — imprima uma folha e teste no leitor USB e na câmera antes da
> impressão grande. Turno e estande só aparecem depois de gerados em Mapa do Evento. (3) **Premiação
> no comitê**: quem tem só o escopo do comitê passa a premiar projetos. O cadastro das credenciais
> continua em Avaliação presencial. (4) **ZIP dos projetos de pesquisa**: com a lista inteira ele
> pode passar de 1 GB; o download vai pelo navegador (blob), então vale testar com a lista real e,
> se o proxy cortar por tempo, baixar por categoria.
>
> **Sprints 187–188 (branch `feat/premiacao-comite-e-etiquetas`, saída da `main` @ `6af22e2`):**
> (a) **Sprint 187** — o detalhe de uma lista (preliminar ou final) ganhou a seção **Projetos de
> pesquisa**: o arquivo do projeto de pesquisa (`TipoDocumento::PlanoPesquisa`) de cada projeto da
> lista num ZIP, **de todos** ou **dos escolhidos** — projeto a projeto, ou de uma vez por
> **categoria** (FETECMS, FETEC Jr, FETECMS FUNDECT) ou por **área**, marcações que se somam.
> `POST /admin/avaliacao/listas-finais/{lista}/projetos-pesquisa` (sem `projeto_ids` = a lista
> inteira; POST porque a seleção pode ter centenas de ids), `ExportacaoProjetosPesquisaService`. O
> ZIP sai em pastas **categoria → área**, cada arquivo com o **código do projeto na lista** no nome
> (`FETECMS/Ciências Agrárias/FET.AGR-001 - Título.pdf`). Os arquivos entram **por caminho e sem
> recompressão** — `addFromString` seguraria centenas de PDFs na memória até o `close()`; storage
> remoto é copiado para temporário. Projeto sem o arquivo não some calado: o ZIP leva
> `_projetos-sem-arquivo.txt`; sem arquivo nenhum, é 422 em vez de ZIP vazio.
> (b) **Sprint 188** — a etiqueta passou a ser **da pessoa**: o QR à esquerda na altura toda, o
> nome com o **papel ao lado** ("Ana Paula - aluno(a)"), o **turno e o estande** (Mapa do Evento;
> sem estande vale o turno de Turnos, sem nenhum a linha some) e o código de barras embaixo. Saíram
> o título do projeto e a escola. Nome comprido reduz a fonte (11 → 10 → 9) antes de ser cortado, e
> quando o papel não cabe na última linha a última palavra do nome desce com ele. De quebra, a
> medição de texto do `EtiquetasPdf` estava errada para acento (o `Cpdf` fora do modo Unicode mede
> byte a byte): agora mede pelo texto sem acento, que nas fontes padrão tem a mesma largura.
> Back **1399/1399**, front **683/683**, Pint limpo.
>
> **Sprints 185–186 (mesma branch):** o código e o turno do projeto na comunicação.
> (a) **Sprint 185** — a mala direta ganhou `{{codigo_projeto}}` (o código na lista final ativa) e
> `{{turno}}` (o turno de Mapa do Evento → Turnos), marcadas "só finalistas" nos botões. Elas só
> existem para quem é da **equipe de um projeto finalista**, e por isso a mala que as usa só sai se
> **todo** destinatário for finalista — chegue ele pelo grupo "Todos os finalistas", pelos públicos
> de conta dos finalistas ou pela lista avulsa (o e-mail avulso é conferido contra a equipe dos
> finalistas). A regra é sobre as pessoas resolvidas, e não sobre o nome dos grupos: "Todos os
> orientadores" com um único orientador de fora já barra. Também barra projeto finalista **sem
> código** (o admin apagou na lista) ou **sem turno** (turnos ainda não gerados), conforme a
> variável usada. A prévia devolve o que impediria (`meta.pendencias_finalistas`), a tela mostra o
> erro embaixo do editor e desabilita o envio **antes** de o admin tentar, e o servidor recusa de novo
> (422). Quem está em dois finalistas recebe os dois: "FET.AGR-001, FET.BIO-003" e "FET.AGR-001:
> Turno A (matutino); FET.BIO-003: Turno B (vespertino)". Os dados ficam congelados no disparo
> (`mala_direta_destinatarios.finalista`), como o resto da lista; o **envio de teste** usa valores de
> exemplo, porque os endereços de conferência não são finalistas.
> (b) **Sprint 186** — as mesmas variáveis no modelo de e-mail **"Código do projeto"** (que já sai
> só para a equipe dos finalistas, pela mala direta) e nos **avisos**, onde são resolvidas para
> **quem lê**: cada orientador finalista vê o seu código e o seu turno. Aviso que as usa precisa de um
> público inteiro de finalistas ("Orientadores de projetos finalistas" ou "Finalistas sem o termo") —
> a tela avisa e não publica, e o servidor confere de novo, junto do código e do turno de cada
> projeto. Na prévia do admin elas saem como exemplo.
> Back **1390/1390**, front **674/674**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 185–186):** (1) mais uma migration
> (`mala_direta_destinatarios.finalista`). (2) Antes de usar `{{turno}}`, gere os turnos em Mapa do
> Evento → Turnos; antes de `{{codigo_projeto}}`, confira que nenhum finalista está sem código na
> lista final ativa. (3) Para incluir as variáveis no e-mail do código, edite o modelo "Código do
> projeto" em Comunicação → Modelos de e-mail.
>
> **Sprints 183–184 (mesma branch):** o que o orientador manda antes deixou de ser só os termos.
> (a) **Sprint 183** — a aba **Documentos** não conhece documento nenhum de cor: ela mostra os itens
> da lista única marcados **"receber antes"**, na ordem da lista — os dois termos (itens do sistema)
> e o que mais a organização pedir. Todo item aceita **um ou mais arquivos** (PDF, JPG ou PNG); no
> item de **pessoa**, cada arquivo diz para quem vale, e as pessoas são as dos **papéis do item** —
> agora pode ser orientador e coorientador, não só estudante. Por isso o vínculo virou
> `projeto_documento_pessoas` (papel + id, como o balcão), no lugar de `projeto_documento_alunos`. As
> rotas também ficaram genéricas: `POST /documentos-presenciais/projetos/{projeto}/itens/{item}`,
> `PUT|DELETE /documentos-presenciais/arquivos/{documento}` (as de `/termo` e `/termo-imagem` saíram).
> Os termos guardam o tipo próprio (o público "finalistas sem o termo" e o Mapa do Evento os
> reconhecem); o resto é `documento_evento`. O termo de responsabilidade passou a aceitar mais de um
> arquivo, como os demais — corrigir é remover o errado —, e a conferência gov.br continua aparecendo
> nele.
> (b) **Sprint 184** — o que chegou antes **já vem marcado "Presente"** no credenciamento e na
> checagem, para qualquer item recebido antes, com o arquivo a um clique (`EnviosAntecipadosService`,
> uma consulta por página). Item do projeto está coberto com qualquer arquivo; item de pessoa, pessoa
> a pessoa. A marcação gravada vale mais, como antes. Com isso o bloco fixo **"Termo de
> responsabilidade"** saiu da ficha da checagem e a coluna "Termo" saiu do espelho: o termo é um item
> como os outros, que no espelho aparece marcado com "online" enquanto ninguém o conferiu.
> Back **1377/1377**, front **672/672**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 183–184):** (1) mais uma migration (`projeto_documento_pessoas`,
> que copia os vínculos do termo de imagem e apaga a tabela antiga). (2) Para pedir um documento novo
> aos finalistas, crie o item em Parametrização → Itens do credenciamento e da checagem com **"receber
> antes"** marcado: ele aparece na hora na aba Documentos de todos os orientadores finalistas. Vale
> avisá-los por mala direta (o público "Orientadores de projetos finalistas" existe para isso).
>
> **Sprints 181–182 (mesma branch):** uma lista só para os dois balcões.
> (a) **Sprint 181** — o credenciamento tinha o seu catálogo (`documentos_credenciamento`, um item
> por papel) e a checagem o dela (`itens_checagem_estande`, por estande); a organização cadastrava a
> mesma coisa duas vezes e não tinha onde dizer "este eu quero receber antes". Nasceu
> `itens_conferencia`: cada item diz **de quem é** (de cada pessoa, com os papéis, ou do projeto),
> **onde é conferido** (credenciamento, checagem ou os dois) e se é **recebido antes** pelo portal. A
> tela é um card novo na Parametrização; a lista de documentos saiu de Parametrização →
> Credenciamento (que ficou com os itens a entregar) e o cadastro de itens saiu de Avaliação
> presencial (que aponta para a Parametrização). A migration **preserva tudo**: os itens da checagem
> entram com o mesmo id (as checagens e o checklist do avaliador, que guarda o id, seguem valendo),
> os documentos de mesmo nome em papéis diferentes viram um item com os papéis somados, nome igual
> nos dois catálogos funde num item marcado nos dois lugares, e as marcações do balcão são
> remapeadas. Os dois termos do finalista viram **itens do sistema** (`chave`), com "receber antes"
> ligado; a migration também liga cada arquivo já enviado ao seu item
> (`projeto_documentos.item_conferencia_id`).
> (b) **Sprint 182** — os balcões passaram a ler a lista. No **credenciamento**, o item de pessoa
> aparece em cada pessoa dos papéis dele e o item do projeto num bloco **"Do projeto"**, marcado com o
> próprio projeto no lugar da pessoa (`pessoa_tipo = projeto`). Na **checagem**, o item do projeto
> aparece uma vez, como sempre, e o de pessoa ganha **um marcador por pessoa** (o espelho mostra
> "2/3"). O checklist do **avaliador presencial** continua só com itens do projeto, e sem os que o
> orientador manda antes — documento é assunto da organização. Marcação de item em papel que não é
> dele, ou de pessoa de outro projeto, é descartada nos dois balcões.
> Back **1371/1371**, front **674/674**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 181–182):** (1) **duas migrations** (`itens_conferencia`, que funde
> e **apaga** os dois catálogos antigos depois de copiar tudo, e `projeto_documentos.item_conferencia_id`).
> Vale um backup do banco antes do deploy, como em toda migration que move dado. (2) Depois do deploy,
> confira a lista em Parametrização → Itens do credenciamento e da checagem: documentos com o mesmo
> nome em papéis diferentes viraram um item só, e o termo de responsabilidade passou a ser um item da
> checagem. (3) A rota `/admin/presencial/itens` e as de `/admin/credenciamento/documentos` saíram
> do ar; o cadastro é `/admin/itens-conferencia`.
>
> **Sprints 179–180 (mesma branch):** como as listas nascem e como se chamam.
> (a) **Sprint 179** — a **união** deixou de ser só "preliminares → final". Qualquer lista **já
> gerada** da edição pode ser origem — preliminar, a final ativa ou uma final inativa —, e o admin
> escolhe o **destino**: lista preliminar ou final (`POST /admin/avaliacao/listas/uniao`,
> `ListaFinalService::rascunharDeListas`; a rota antiga `final-de-preliminares` continua, como o caso
> particular). O caso que motivou: montar uma preliminar a partir da final de hoje mais os suplentes
> de outra preliminar. Rascunho continua fora (está sendo revisto) e a lista de ensaio também. A
> união **herda os códigos já fixados** nas origens — a equipe que já recebeu o FET.AGR-007 não pode
> ganhar outro número só porque a lista foi remontada. A prioridade é a final ativa, depois as outras
> finais e por fim as preliminares, da mais nova para a mais antiga; quem não herda ganha o próximo
> número livre do grupo, e a lista já nasce com os códigos fixos. Dois projetos que herdam o mesmo
> código ficam com ele e o aviso de repetido (Sprint 177) barra a geração até o admin decidir. Sem
> nenhum código fixado nas origens, a lista numera como sempre, em ordem alfabética. As `origens`
> passaram a guardar o tipo de cada lista, e a tela diz "Montada das listas: …".
> (b) **Sprint 180** — **apelido e descrição**. Com várias preliminares convivendo, "Lista
> preliminar · XVI FETECMS" repetido não diz o que há em cada uma. O apelido é o `nome` que a lista
> já tinha (a tela passou a chamá-lo assim, e em branco ele volta ao padrão); a **descrição** é nova
> (`listas_finais.descricao`). Os dois aparecem no último passo do assistente do Ranking, no diálogo
> da união, nos cartões da página de listas e no cabeçalho do detalhe, onde se editam a qualquer
> momento — inclusive na lista gerada — **sem justificativa e sem versão nova**
> (`PATCH /admin/avaliacao/listas-finais/{lista}`): é rótulo, não composição. Os registros antigos
> guardam o nome que a lista tinha quando foram escritos.
> Back **1361/1361**, front **668/668**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 179–180):** (1) mais uma migration (`listas_finais.descricao`),
> nenhuma dependência nova, nenhuma variável de `.env`. (2) O botão da página de listas mudou de nome
> para **"Montar a partir de outras listas"** e agora pergunta o destino (preliminar ou final; o
> padrão continua sendo final). (3) Ao remontar a final a partir da ativa, os códigos já enviados às
> equipes vêm junto — confira os avisos no topo antes de gerar.
>
> **Sprints 177–178 (mesma branch):** o código do projeto nas listas preliminares e finais ficou
> nas mãos do admin.
> (a) **Sprint 177** — o código digitado deixou de ser travado pelo padrão. Ele pode ficar **em
> branco** (o projeto fica "sem código"), **fugir do padrão** — não é `CAT.ARE-000` da categoria e
> da área do próprio projeto, como FET.BIO-001 num projeto de Agrárias — ou **fugir da sequência** do
> grupo (001, 002, 005). Os três viram **aviso**: um selo na linha do projeto, um resumo no topo da
> composição e de novo na confirmação de **Gerar**, que gera assim mesmo. O **código repetido** é o
> único que impede: selo e resumo em vermelho, botão Gerar desabilitado e **422** no servidor
> (`gerarLista`), para a preliminar e para a final — dois crachás com o mesmo número no balcão é o
> erro que o código existe para evitar. No **rascunho** o repetido é aceito ao digitar (é assim que o
> admin troca dois códigos de lugar); numa lista **já gerada**, que não tem mais um "Gerar" para
> barrar, ele continua recusado na hora, como antes. Os avisos saem do servidor uma vez
> (`ListaFinalService::avisosDosCodigos`), sobre a composição atual. A sequência é conferida nos
> números **distintos** do grupo — o n-ésimo tem de ser n —, então um salto marca quem está depois
> dele, e um repetido não conta duas vezes como salto. Editar um código muda **só aquele projeto**:
> a lista se reordena pelo código dentro do grupo (ordem natural: -9 antes de -10; sem código vai
> para o fim) e a linha editada pisca no lugar novo. A trava dura que sobrou é de caracteres
> (letras, números, ponto, hífen, barra e sublinhado, até 30), porque o código vai para TXT, CSV e
> e-mail. Quem lê o código para escrever "CÓDIGO - Título" (o `{{projetos}}` da mala direta) pula o
> projeto sem código, e o TXT escreve "(sem código)".
> (b) **Sprint 178** — o modo **Reordenar** (abrir o grupo, mexer, salvar) saiu. Cada projeto tem uma
> **alça de arrastar** e as setas de subir/descer (teclado e celular); ao **soltar**, o grupo inteiro é
> renumerado na ordem nova — na hora no rascunho, com **justificativa** numa lista gerada (que sobe a
> versão e registra cada código que mudou, como antes). O arraste fica **dentro do grupo**
> categoria+área: o prefixo do código é a classificação do projeto, e levá-lo para outro grupo seria
> reclassificá-lo. Renumerar alcança todos do grupo, inclusive o código digitado fora do padrão e o
> projeto sem código. Esbarrar num código igual de outro grupo segue a regra da Sprint 177: aviso no
> rascunho, recusa na lista gerada.
> Back **1350/1350**, front **666/666**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 177–178):** (1) sem migration nem dependência nova. (2) **A regra de
> código mudou de lugar**: o que antes era recusado ao digitar (fora do formato, repetido no rascunho)
> agora é aviso, e o repetido só barra no **Gerar**. Vale testar uma vez num rascunho antes de mexer na
> final ativa. (3) Numa lista com os **códigos já enviados** aos finalistas, arrastar renumera o grupo
> inteiro — a tela já avisa que é preciso reenviar.
>
> **Sprints 175–176 (mesma branch):** o termo de uso de imagem chegou ao balcão e ao mapa.
> (a) **Sprint 175** — o **credenciamento** passou a conhecer o termo. O catálogo de documentos é
> texto livre, então o portal ganhou um jeito de reconhecer um item: `documentos_credenciamento.chave`,
> nulo nos itens da organização e `termo_uso_imagem` no **item do sistema** do estudante. A migration
> **adota** um item de estudante que já fale de imagem ("Termo de imagem", "Autorização de uso de
> imagem") em vez de criar um duplicado; sem nenhum, ele nasce no fim da lista. Na Parametrização ele
> aparece com o selo "preenchido pelo envio online", pode ser desativado e **não pode ser excluído**
> (sem ele o envio antecipado não chegaria ao balcão). Na **ficha**, o estudante coberto por arquivo
> enviado online já chega com o item marcado **Presente**, com o nome do arquivo e o link para
> abri-lo; o atendente confere e **pode mudar** — o que ele gravar passa a valer, inclusive um
> "Ausente" para o arquivo ilegível, e o arquivo continua à mão. Quem não está coberto fica em
> branco, para a autorização em papel. A lista do balcão mostra "Termo de imagem online: 2 de 3"
> antes de abrir a ficha. O `demo:credenciamento` deixou de semear o "Termo de imagem" (o item do
> sistema já existe) e o catálogo "vazio" passou a ignorá-lo.
> (b) **Sprint 176** — o **Mapa do Evento** acompanha o termo, sem mudar a cor do estande (é outro
> eixo: um estande checado pode estar sem termo). A cobertura de cada projeto soma o **envio online**
> e o que o **balcão** registrou num credenciamento concluído — para a organização, quem mostrou o
> papel está tão coberto quanto quem mandou pelo portal (`TermosImagemService::noEvento`). A marcação
> do balcão vale mais que o envio: "presente" cobre, "não necessário" dispensa, "ausente" deixa
> pendente. Os dois fatos têm hora, então o **seletor de dia** funciona igual ao resto do mapa. O
> painel do estande diz "Parcial · 2 de 3 (1 online · 1 no balcão)", quem falta e os arquivos; a
> lista filtrável ganhou o critério **Termo de uso de imagem** (incompleto, completo, parcial,
> pendente — começa em "incompleto", a pergunta da véspera), e TXT, CSV e PDF ganharam a situação,
> os cobertos e os nomes de quem falta. O critério passou a vir com os valores do servidor, em vez de
> o front deduzir o tipo pelo nome.
> Back **1342/1342**, front **660/660**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 175–176):** (1) mais uma migration (`documentos_credenciamento.chave`),
> que **cria ou adota** o item "Termo de uso de imagem" do estudante no catálogo do credenciamento —
> confira em Parametrização → Credenciamento depois do deploy, e renomeie se a organização usa outro
> nome. (2) Se a organização já tinha um item de imagem cadastrado à mão, é ele que vira o item do
> sistema (o primeiro ativo, pela ordem); os outros parecidos ficam como estavam.
>
> **Sprints 173–174 (branch `feat/termo-imagem-e-codigos-lista`, saída da `main` @ `dfef533`):**
> (a) **Sprint 173** — o grupo **"Todos os finalistas"** (estudantes, orientadores e coorientadores
> da lista final ativa) passou a existir nos três canais da Comunicação. Na **mala direta** a caixa
> "Somente finalistas" da Sprint 158 era **exclusiva** — marcá-la apagava os outros públicos da
> tela, embora o servidor sempre soubesse combiná-los. Agora ela é um **card da grade**, combinável
> com os públicos e com os e-mails avulsos, com a contagem de pessoas e de quem fica de fora por não
> ter e-mail; o contrato da API não mudou (o booleano `finalistas` segue ao lado de `publicos`), e a
> prévia passou a dizer de onde veio cada destinatário pelo nome do grupo. **Avisos e feedback** só
> alcançam quem tem conta — o aviso precisa de sessão, a resposta do feedback precisa de login —, e
> estudante e coorientador não têm. Para eles nasceu o público de conta **"Orientadores de projetos
> finalistas"** (`PublicoMala::OrientadoresFinalistas`), que aparece também na mala direta, e as
> duas telas ganharam uma nota dizendo isso em vez de prometer a equipe inteira. Ele e o
> "finalistas sem o termo" leem a mesma lista: a final **ativa** e oficial — preliminar, final
> inativa e a de ensaio não definem finalista.
> (b) **Sprint 174** — a aba **Documentos** do orientador ganhou o **termo de uso de imagem**. Ao
> contrário do termo de responsabilidade (um por projeto, reenviar substitui), ele vem em **um ou
> mais arquivos** — a escola às vezes tem um da turma, às vezes um por estudante — e cada arquivo
> diz **para quais estudantes vale** (`projeto_documento_alunos`, que apaga em cascata dos dois
> lados). A tela mostra quem já está coberto e quem falta, já marca quem falta ao escolher um
> arquivo novo e deixa corrigir os estudantes de um arquivo sem reenviá-lo. Aceita **PDF, JPG ou
> PNG** (a autorização costuma ser assinada à mão pelos responsáveis); a conferência gov.br roda só
> no PDF e só informa. A cobertura mora num leitor em lote, `TermosImagemService` — a mesma
> pergunta ("quem ainda falta?") vai ser feita pelo credenciamento e pelo Mapa do Evento, este a
> cada 30 segundos, então ela é uma consulta por fato e não uma por projeto. No topo da aba, o card
> **"Agilize o processo!"** convida a anexar antes e diz com todas as letras que não enviar não
> prejudica: quem não anexou apresenta a autorização de imagem no credenciamento e o termo de
> responsabilidade na checagem do estande (o texto do laudo de assinatura foi alinhado a isso). Os
> dois termos viraram **documentos do evento** (`TipoDocumento::doEvento()`): não entram mais pelo
> anexo da inscrição e saem da leitura do **avaliador online** também — o presencial já os
> escondia; o online recebia o termo de responsabilidade junto dos anexos.
> Back **1332/1332**, front **658/658**, Pint limpo, build OK.
>
> **Pendências do Pedro (Sprints 173–174):** (1) `git push origin feat/termo-imagem-e-codigos-lista`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o
> deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). A branch inteira tem migrations
> (as de cada bloco de sprints estão nas pendências de cada um), **nenhuma variável nova de `.env`**
> e **nenhuma dependência nova**.
> (2) **A caixa "Somente finalistas" virou o card "Todos os finalistas"**: quem marcava a caixa e
> esperava que ela substituísse os públicos agora precisa desmarcar os outros grupos — ela soma.
> (3) **O avaliador online deixou de ver o termo de responsabilidade** entre os anexos do projeto.
>
> **Sprints 171–172 (mesma branch):**
> (a) **Sprint 171** — o avaliador ganhou a aba **Avaliação presencial**
> (`/avaliador/avaliacao-presencial`), separada da aba *Presencial* — que voltou a ser só a
> intenção, as orientações e um atalho. A aba nova só aparece no menu de quem disse "sim"
> (`avaliador_profiles.presencial` agora vai no `UserResource`, e responder atualiza o menu na
> hora) e, fora do período, **aparece fechada** com o motivo: sem horário dos turnos, procure a
> cabine (turno acontecendo sem ativação), o próximo turno, ou o fim da avaliação. Aberta, mostra
> o turno e o prazo, **Para avaliar** (o que a distribuição ou o admin entregou), **Estandes
> disponíveis** (escolha no corredor, **restrita** ao turno em andamento, a projeto credenciado e
> checado e abaixo do teto) e **Avaliados**. A avaliação virou o **wizard da online**: leitura do
> projeto (resumo, vídeo, documentos — sem o termo do finalista, que é documento do evento com
> dado pessoal; a `ProjetoPolicy` passou a reconhecer o avaliador presencial para o download), um
> passo por seção da rubrica presencial com o balão "?", **nota parcial**, rascunho e um passo a
> mais: o **checklist dos itens da checagem** (`avaliacoes_presenciais.itens_conferidos`,
> presente/ausente, **sem nota** e obrigatório para enviar). Escrever pede o **prazo da ocorrência**
> (fim do turno + 30 min) e a **ativação** nela; na margem termina-se o que se abriu, mas nada novo
> começa. Enviar **repõe a fila** do turno (`DistribuicaoPresencialService::completarFila`). Passado
> o prazo, a avaliação vira leitura, marcada "Prazo encerrado".
> (b) **Sprint 172** — `php artisan demo:avaliacao-presencial` deixa **`avaliador@fetecms.test`**
> pronto para ensaiar: conta demo, presencial aceito, ativado em todos os turnos que ainda não
> terminaram, e três projetos de um orientador demo na lista final de demonstração, com turno,
> estande 901–903, **credenciados e checados** na trilha de ensaio (e três itens de checagem, só
> se o catálogo estiver vazio). O *Modo de teste* do avaliador ignora o relógio e a ativação; o do
> admin, na Distribuição presencial, usa a lista demo e só os avaliadores demo.
> Documentado em [docs/DEMO_AVALIACAO_PRESENCIAL.md](docs/DEMO_AVALIACAO_PRESENCIAL.md).
>
> **Pendências do Pedro (Sprints 167–172):** ~~push + PR~~ — **entrou na `main` pelo PR #105** (v1.29).
> As observações de uso continuam valendo: (1) `git push origin feat/avaliacao-presencial-distribuicao`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o
> deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem migrations**
> (`edicoes.horarios_turnos`/`presencial_fila_avaliador`/`presencial_por_projeto`, a tabela
> `avaliador_turnos_presenciais` e `avaliacoes_presenciais.dia`/`turno`/`itens_conferidos`),
> **nenhuma variável nova de `.env`** e **nenhuma dependência nova**.
> (2) **Antes do evento**, em Avaliação presencial → **Distribuição presencial**: o horário de cada
> turno (vale em todos os dias da janela do evento) e os dois números. Sem horário, a aba do
> avaliador fica fechada para todo mundo.
> (3) **No evento**, a cabine ativa cada avaliador no turno (ou pré-ativa os seguintes). Só depois
> disso ele avalia e a distribuição o alcança. A distribuição só pega projeto **credenciado e com
> o estande checado** — o projeto que ainda não passou pela checagem fica de fora e a tela conta
> quantos.
> (4) **A avaliação presencial antiga (Sprint 135) mudou de regra**: o avaliador que aceitou não
> avalia mais "durante o evento inteiro", e sim no turno em que foi ativado. As designações feitas
> antes desta release (sem turno gravado) continuam valendo no turno do projeto.
> (5) Para ensaiar: `php artisan demo:avaliacao-presencial` e entrar como `avaliador@fetecms.test`
> com o *Modo de teste* ligado. A conta passa a ser **demo** (sai dos números reais).
>
> **Sprints 169–170 (mesma branch):** a avaliação presencial ganhou **turnos** e **distribuição**.
> (a) **Sprint 169** — a agenda e a cabine. O horário de cada turno (`edicoes.horarios_turnos`,
> hh:mm) vale **em todos os dias** da janela do evento; cada dia × turno é uma **ocorrência**
> (`App\Support\JanelaTurnos`), e o prazo de quem avalia nela é o **fim do turno + 30 minutos**
> (`MARGEM_MINUTOS`). Quem disse "sim" ao presencial só avalia depois de passar na **cabine da
> avaliação**: a organização o **ativa** no turno em curso ou **pré-ativa** os seguintes que ele
> escolher (`avaliador_turnos_presenciais`, `TurnosPresenciaisService`,
> `PUT /admin/presencial/avaliadores/{avaliador}/turnos` — substitui a lista inteira). Só se ativa
> quem confirmou o presencial, e só em ocorrência da agenda. A mesma tela guarda os dois números da
> distribuição: projetos por avaliador por turno (`presencial_fila_avaliador`, padrão 5) e
> avaliações por projeto (`presencial_por_projeto`, padrão 3 — o antigo `MAX_POR_PROJETO`, que o
> Mapa do Evento também passou a ler da edição). Os três campos são herdados por edição nova.
> (b) **Sprint 170** — **Avaliação presencial → Distribuição presencial**
> (`/admin/presencial/distribuicao`, `DistribuicaoPresencialService`), uma ocorrência por vez, nos
> moldes da online: **rodadas iguais**, prioridade **subárea → área → área correlata → o resto** e
> teto por projeto. O que o dia acrescenta: só entra projeto **do turno** (Mapa do Evento → Turnos,
> com o estande como reserva), **credenciado** no balcão e com o estande **checado**; só recebe
> quem está **ativado** na ocorrência. Cada avaliação guarda a ocorrência
> (`avaliacoes_presenciais.dia`/`turno`); vencido o prazo sem envio ela **deixa de ocupar vaga**
> (`AvaliacaoPresencial::ocupaVaga()`), e uma distribuição seguinte **reaproveita a linha** com o
> rascunho. **Redistribuir** devolve o que foi apenas designado e sorteia de novo (em avaliação,
> enviado e designado à mão ficam). A **designação manual** passa por cima do teto, mas não do
> credenciamento, da checagem, do turno do projeto e da ativação — a tela diz quem ficou de fora e
> por quê. Turno encerrado não distribui. O modo de teste (conta demo) usa a lista final demo, as
> checagens demo e só os avaliadores demo. Os endpoints antigos de designação sem turno saíram
> (`AvaliacaoPresencialAdminService` foi removido).
>
> **Sprints 167–168 (branch `feat/avaliacao-presencial-distribuicao`, saída da `origin/main` @ `6c92219`):**
> (a) **Sprint 167** — o **resumo do projeto** do orientador listava os documentos enviados só pelo
> nome. Agora cada um tem **Baixar** (a rota autenticada de sempre, `GET /documentos/{id}/download`)
> e o cartão ganhou **Baixar todos (.zip)** (`GET /projetos/{projeto}/documentos/zip`,
> `DocumentoService::zip()`, `ZipArchive` sem dependência nova). No ZIP cada arquivo leva o tipo na
> frente do nome ("Projeto de Pesquisa - plano.pdf"), dois anexos de mesmo nome ganham "(2)" em vez
> de se sobrescrever, e o que sumiu do storage fica de fora — se nada sobrar é **422**, não um ZIP
> vazio. Quem vê o projeto baixa (a mesma Policy `view`), então o admin também baixa.
> (b) **Sprint 168** — a composição de uma lista (preliminar ou final) passou a ser mostrada **por
> grupo categoria+área**, que é onde o código numera. **Reordenar** abre o grupo para arrastar ou
> subir/descer; salvar **renumera o grupo** na ordem nova (`PUT .../listas-finais/{lista}/ordem`,
> `ListaFinalService::reordenarGrupo()`), e o lápis ao lado do código deixa **digitá-lo**
> (`PUT .../projetos/{projeto}/codigo`, `definirCodigo()`): letras, números, ponto e hífen, sem
> espaço, maiúsculas, e **único na lista** — a recusa diz de quem é o código. As duas ações **fixam
> os códigos** da lista (`codigos_congelados_em`), porque a ordem só existe gravada; daí em diante
> quem entra ganha o próximo número livre do grupo — inclusive no rascunho, onde antes um projeto
> novo podia repetir um número fixado. No rascunho vale na hora; na lista gerada pede
> **justificativa**, sobe a versão e cada código que mudou vira um registro
> (`lista_final_codigo_alterado`, "de → para") em Registros → Lista final. Com os códigos já
> enviados, a tela avisa que a equipe ficará com outro número e que é preciso reenviar.
>
> **Sprint 166 (branch `feat/finalistas-suporte-e-certificados`):** o job **security** do CI quebrou no `composer audit`: duas
> advisories em `league/commonmark` ≤ 2.10.1 — *DisallowedRawHtml* contornável quando a tag
> proibida termina o literal de HTML (média, GHSA-97jj-33gv-5xf9) e **negação de serviço** de tempo
> quadrático na extensão de tabelas GFM (alta, GHSA-3q6v-r5mr-hxv8). Ela é **dependência
> transitiva** do `laravel/framework` (`^2.8.1`, que é o Markdown dos e-mails do Laravel), então a
> correção é só o lock: `composer update league/commonmark --with-dependencies` subiu para
> **2.10.3**, levando junto `symfony/polyfill-php80` 1.43. `composer.json` não mudou. `composer audit`
> e `npm audit` (com e sem dev) limpos; back **1272/1272**.
>
> **Sprints 164–165 (mesma branch):** **listas preliminares e finais**. Até aqui toda lista era
> "lista final": nascia rascunho e, publicada, virava a vigente. A organização trabalha com vários
> recortes antes de fechar a feira, e eles não são finalistas de ninguém.
> (a) **Sprint 164** — o modelo. `listas_finais.tipo` (`preliminar`/`final`) + `origens` (JSON das
> preliminares que formaram a final) + `gerada_em`. **Preliminares** convivem sem limite e nenhuma
> define finalista; **finais** têm **uma ativa** por edição (`vigente`, que `ListaFinal::vigente()`
> agora só procura entre as finais). Toda lista nasce **rascunho**, e o rascunho é **edição livre**:
> incluir e retirar sem justificativa, sem subir versão e sem registro — e o candidato é **qualquer
> projeto submetido**, avaliado ou não (o de cadastro manual entra por aqui). **Gerar**
> (`POST .../gerar`, `ListaFinalService::gerarLista()`) fecha: a preliminar fica registrada
> (`lista_preliminar_gerada`); a final vira a ativa e a que estava ativa passa a **inativa** — fica
> no histórico e **volta** por *Tornar ativa* (`POST .../reativar`), com justificativa e registro
> (`lista_final_reativada`). Depois de gerada, mudar a lista continua pedindo justificativa e
> subindo a versão, como antes. Preliminar não manda código nem ganha etiquetas: isso é da final
> ativa. A migration converte o que existe: o publicado vira **final** (a vigente segue ativa, com
> códigos, mapa e crachás intactos) e o rascunho vira **preliminar**. O atalho antigo `oficial` da
> API continua gerando a final na hora; `/publicar` é sinônimo de `/gerar`.
> (b) **Sprint 165** — a final pela **união de preliminares**
> (`POST /admin/avaliacao/listas/final-de-preliminares`): o admin marca as preliminares **já
> geradas** e o portal junta os projetos delas sem repetir, num rascunho de final que ainda se
> edita antes de gerar. Nas telas: o assistente do Ranking (*Gerar lista*) termina escolhendo
> **preliminar** (padrão) ou **final** e sempre abre o rascunho; a página de listas separa **Finais**
> (ativa/inativa, *Tornar ativa*, de que preliminares veio) de **Preliminares**, com o botão *Lista
> final a partir de preliminares*; o detalhe edita na hora no rascunho, gera com confirmação quando é
> final ("vira a ativa") e só mostra identificação e envio de código para a final.
>
> **Sprint 163 (mesma branch):** nasce a aba **Certificados** (`AbaAdmin::Certificados`, do RBAC
> como as demais; `/admin/certificados`, `CertificadosService`). O portal **não emite** o
> certificado: entrega a planilha (Excel ou CSV) com que a organização o emite.
> (a) **Avaliadores por fase.** O certificado da **fase online sai antes da presencial** — há
> avaliador pedindo para processo seletivo com prazo —, então as duas fases são **contadas
> separadas**: nome completo, CPF, e-mail, área de avaliação, projetos avaliados online e
> presencialmente. A carga horária a organização calcula pela quantidade. Conta a avaliação
> **concluída** desta edição (o `whereHas('projeto')` traz o recorte do `EdicaoScope` para a
> contagem), inclusive a que foi desconsiderada depois — a regra do card de certificado do perfil:
> quem descartou foi a organização, o trabalho aconteceu. A separação também aparece **no mesmo
> cadastro do avaliador**: Avaliadores Online ganhou a coluna *Presenciais* (ordenável, e no CSV),
> e o perfil do avaliador diz quantas avaliações presenciais ele tem.
> (b) **Participantes.** Nome completo, CPF, e-mail, **função** e **projeto ou atividade** de oito
> grupos combináveis: estudantes, orientadores e coorientadores (dos **finalistas** ou de **todos os
> submetidos**, com a coluna *Finalista* sim/não), comissão especial de avaliação (inclusive quem
> ainda não avaliou), avaliadores de cada fase (com "Avaliação de N projeto(s)"), **voluntários**
> (contas temporárias não removidas, com o setor e o curso) e a **organização** — os admins, que
> passaram a ter **CPF opcional** no cadastro (`users.cpf`, campo novo na aba Administradores),
> porque até aqui o portal não sabia o CPF de quem organiza a feira. Conta demo fica de fora.
> (c) **Declaração nominal.** Cada avaliador tem *Projetos avaliados*: os títulos por fase e um
> **PDF** (`pdf.declaracao-avaliador`) sem nota nenhuma — o que se declara é o trabalho feito, a
> nota é da organização. E uma planilha com **uma linha por avaliação**, para responder de uma vez
> a vários pedidos.
>
> **Sprints 161–162 (mesma branch):**
> (a) **Sprint 161** — **credenciamento fora do prazo**. Algumas equipes pediram para credenciar
> depois e não estarão no primeiro dia; a organização aprovou, mas a explicação morava numa lista à
> parte, e o balcão e os avaliadores só enxergavam um estande vazio e um projeto "não credenciado".
> Agora a ficha do credenciamento tem **Marcar credenciamento fora do prazo**, com a **data prevista
> de chegada** (opcional) e uma observação (`credenciamentos_fora_prazo`, `ForaPrazoService`). Só
> **admin permanente** marca (rota sob `admin.permanente`): a conta temporária do balcão vê o selo
> mas não aprova exceção. Marcar e retirar entram em Registros → Credenciamento.
> O selo sai de um lugar só — `SinalizacaoProjetoService::para($ids)`, chamado uma vez por página —
> e aparece em todas as telas em que alguém pode estranhar a ausência: lista e ficha do
> credenciamento, checagem e espelho de estandes, avaliações presenciais do admin, a tela do
> **avaliador presencial** (os dele e os disponíveis, e o diálogo da rubrica), a lista filtrável do
> Mapa do Evento (com coluna no CSV). Componente `SinalizacoesProjeto.jsx`.
> (b) **Sprint 162** — **suporte no evento**: acompanhante (estudante neurodivergente ou com
> deficiência), intérprete de Libras e intérprete de outra língua (`suportes_projeto`,
> `TipoSuporte`, `StatusSuporte`). O **orientador** pede na aba nova **Suporte no evento**
> (`/suporte-evento`), para cada projeto seu que está na lista final — mesma janela dos documentos
> do finalista (da lista publicada ao fim do evento), com o modo de teste da conta demo. No
> acompanhante ele cadastra a **pessoa**: nome completo, documento e vínculo com o estudante —
> porque ela entra no evento. A **organização** decide em Credenciamento → **Suporte e
> acessibilidade** (`/admin/credenciamento/suporte`): aprova ou recusa (recusar exige motivo, que o
> orientador lê) e registra, já aprovado, o pedido que chegou por e-mail ou telefone. Mexer num
> pedido já aprovado o devolve para "aguardando" — a organização aprovou o que leu. **Só o
> aprovado** aparece para as equipes (o mesmo selo da Sprint 161), e o **acompanhante aprovado vira
> participante**: código próprio com o papel novo **`S`** (`CodigoParticipante::PAPEL_ACOMPANHANTE`,
> id do pedido e três dígitos do documento), etiqueta no PDF de identificação, linha "Acompanhante
> de Fulano" na lista nominal, leitura do crachá no balcão e bloco *Acompanhantes (conferir o
> documento)* na ficha. De quebra, a conferência dos três dígitos do CPF passou a usar o mesmo
> preenchimento do código (`CodigoParticipante::cpf3()`), senão o crachá de quem não tem CPF — o
> estudante do cadastro manual, o acompanhante com RG — nunca passaria no leitor.
>
> **Sprints 159–160 (mesma branch):**
> (a) **Sprint 159** — **código do projeto aos finalistas**. Com a lista certa no sistema, cada
> equipe recebe por e-mail o código do seu projeto (FET.AGR-001), para o credenciamento e a
> checagem dos estandes não dependerem de alguém achar o título numa lista. Só que o código era
> **recalculado a cada leitura** — a numeração segue a ordem alfabética dentro de categoria+área, e
> incluir um projeto empurrava o número dos que vinham depois. Mandar isso por e-mail seria mandar a
> equipe ao balcão com um número que podia deixar de ser o dela. Agora o código é **fixado**
> (`lista_final_projetos.codigo`, `listas_finais.codigos_congelados_em`) antes do envio: daí em
> diante não muda, e quem entra na lista ganha o **próximo número livre do grupo** (e aparece no fim
> dele, não no meio da ordem alfabética com um número fora de sequência). TXT, identificação,
> exportação e e-mail leem o mesmo código. O envio **é uma mala direta** para a equipe dos
> finalistas (Sprint 158) com o modelo novo **"Código do projeto"** (`ModeloEmail::CodigoProjeto`,
> editável em Modelos de e-mail) e a variável `{{projetos}}` — por isso ganha de graça a fila, o
> relatório por endereço e o reenvio das falhas. Só a lista **vigente e publicada** manda código
> (a prévia ainda pode mudar; a demo não manda e-mail). Há também *Só fixar os códigos*, para
> imprimir crachá antes do e-mail. Registros → Lista final ganhou `lista_final_codigos_fixados` e
> `lista_final_codigos_enviados`. `CodigosFinalistasService`.
> (b) **Sprint 160** — **exportar a lista oficial**. A mesma lista serve à lista nominal do
> credenciamento, aos crachás, aos certificados, ao painel de nomes e aos contatos, e cada uso pede
> outras colunas e outro **nível** (uma linha por pessoa ou por projeto). Em vez de um relatório
> fixo por pedido, a seção *Exportar* do detalhe da lista tem **modelos prontos** — a **lista
> nominal** (nome completo, função, projeto, código, instituição, categoria, área) é o primeiro — e
> um **construtor**: nível, colunas e formato (Excel ou CSV). As colunas saem na ordem pedida, e o
> registro delas mora num lugar só (`ExportacaoListaFinalService::COLUNAS`): acrescentar uma é
> escrever o rótulo e de onde ela sai. Por pessoa há nome, função, CPF, e-mail, telefone, série,
> camiseta, **código do crachá** (o do QR), código do projeto, instituição, cidade, categoria, área,
> estande, turno, origem e credenciamento; por projeto, a equipe numa linha e as contagens.
>
> **Sprints 157–158 (mesma branch):**
> (a) **Sprint 157** — **cadastro manual de projeto** (Avaliação online → Listas finais → *Cadastro
> manual de projetos*, `/admin/avaliacao/projetos-manuais`). Há equipes que vão ao evento sem ter
> passado pela inscrição — a vaga veio de uma **feira afiliada**, ou a organização recebeu só os
> nomes —, e pedir a inscrição agora não cabe no calendário. A tela cadastra o projeto inteiro
> (título, instituição, área/subárea, categoria, orientador, coorientador, estudantes) e o põe
> **direto na lista final vigente**, pelo mesmo `adicionarProjeto()` de sempre — é a lista que o
> faz existir para credenciamento, mapa, crachás e avaliação presencial. Duas escolhas
> independentes, como a organização pediu: a **origem** (*finalista* ou *credencial de feira
> afiliada*, que grava `feira_afiliada` + nome da feira + nº da credencial, os campos que a
> inscrição já tinha) e a caixa **já credenciado no balcão**, que cria o credenciamento concluído
> com a equipe presente e sem conferência de documento (`registrarNoCadastroManual`). O
> **orientador é uma conta**: escolhe-se uma existente, ou o e-mail informado é reaproveitado, ou
> nasce uma conta nova, ativa e com senha aleatória — a pessoa entra por *Esqueci a senha*. E-mail
> de avaliador ou admin é recusado (exclusão mútua). Como a organização costuma ter só o nome,
> **CPF e e-mail de estudante e coorientador viraram opcionais no banco** (e CPF/telefone/nascimento
> do orientador) — os formulários da inscrição continuam exigindo tudo, porque quem decide é o
> FormRequest de cada caminho. `projetos.cadastro_manual` tira o projeto da **avaliação online**:
> o escopo novo `Projeto::avaliacaoOnline()` (= `semDemo` + não manual) substituiu o `semDemo` na
> distribuição, na fila do avaliador, em Projetos submetidos (tabela, cards, filtro de área) e nas
> opções de designação. Criar, editar (alunos sincronizados por id; quem sai é apagado **antes**,
> para o substituto poder herdar o e-mail) e excluir pedem **justificativa** e entram em Registros →
> Projetos (`projeto_cadastro_manual`, `_alterado`, `_excluido`); a inclusão/retirada da lista
> entra também em Registros → Lista final. Sem lista oficial publicada, a tela não cadastra.
> (b) **Sprint 158** — a mala direta ganhou a caixa **Somente finalistas**: estudantes, orientador e
> coorientador dos projetos da **lista final vigente**, deduplicados por e-mail (o orientador de
> dois finalistas recebe uma vez, com os dois títulos). Não é um `PublicoMala`, de propósito: os
> públicos são consultas de **usuário**, compartilhadas com avisos e feedback, e metade da equipe
> finalista não tem conta. A origem fica gravada como `finalistas` (rótulo "Finalistas (equipe
> inteira)") nos públicos da mala e em cada destinatário; o papel de quem não tem conta é
> `estudante`/`coorientador`. A caixa mostra de qual lista são e **quantos ficam de fora por não ter
> e-mail** (o estudante do cadastro manual). Junto nasceu a variável **`{{projetos}}`** — os
> projetos de quem recebe, já com o código da lista final (FET.AGR-001) para os finalistas — e a
> personalização passou a **escapar os valores no HTML**: um título com "<" não vira marcação.
>
> **Sprints 155–156 (branch `feat/finalistas-suporte-e-certificados`, saída da `main` @ `9390a3c`):**
> (a) **Sprint 155** — as **contas temporárias** das quatro abas com balcão (credenciamento,
> almoxarifado, voluntários da avaliação presencial e cerimonial) passaram a nascer **em lote**. A
> equipe do balcão chega numa lista de dezenas de nomes, e cadastrar um por um, cada um com uma
> senha inventada na hora, era o gargalo da véspera. O caminho é o do responsável pela equipe:
> **baixa o modelo** em Excel (cabeçalho só, sem linha de exemplo — exemplo esquecido vira conta;
> CPF e datas em coluna **texto**, senão o Excel come o zero da frente), preenche, devolve em
> `.xlsx` ou `.csv` e vê a **prévia linha a linha** — o que vira conta e o que não passa, com o
> motivo (CPF inválido, e-mail que já tem conta, repetido na própria planilha, janela no passado,
> turno mal escrito). Confirmado, cada conta ganha uma **senha gerada** (10 caracteres sem 0/O/1/l)
> e a **planilha de acesso** (nome, e-mail, senha) é baixada na hora: o portal guarda só o hash,
> então ela existe uma vez. Os campos de início/horas — ou de turnos, nos voluntários — são o
> **padrão** da linha que deixar a coluna em branco. A leitura é nossa
> (`App\Support\LeitorPlanilha`: xlsx com strings compartilhadas, células puladas e data serial do
> Excel; csv com BOM, `;`/`,`/tab e Windows-1252), **sem dependência nova** — o mesmo argumento do
> `PlanilhaXlsx`, que ganhou largura e formato texto por coluna.
> E a conta pode ser **removida**, não só desativada. Desativar continua sendo o reversível; remover
> é para quem não devia estar ali. Conta que **nunca atendeu ninguém** é apagada. A que **já
> deixou rastro** (credenciou, guardou material, fez check-in, conferiu estande) é **arquivada**
> (`contas_temporarias.removida_em`): as fichas apontam para ela e não guardam o nome à parte, então
> o `users` fica — com o login morto (inativa, e-mail trocado por um endereço `.invalid` que libera
> o original, senha embaralhada, sessões encerradas) — e o nome continua respondendo por quem
> atendeu. As rotas das quatro abas viraram uma macro (`Route::contasTemporarias($setor)`).
> (b) **Sprint 156** — relato de produção: o **PDF de etiquetas** da identificação dava erro. Eram
> dois defeitos. O Dompdf **não pinta SVG escrito dentro do HTML**: as etiquetas que saíam vinham
> sem QR e sem barras. E o motor de layout dele custa ~0,2 MB e 20 ms por etiqueta — com 200
> pessoas a memória de 128 MB estourava, e a lista real tem perto de 1.800. As etiquetas passaram a
> ser **desenhadas direto na página** (`App\Support\EtiquetasPdf`, sobre o `Cpdf` que o próprio
> Dompdf já traz): texto nas fontes padrão do PDF e os códigos como retângulos — o QR módulo a
> módulo, o Code 128 barra a barra. Vetor puro, sem GD: **2.000 etiquetas em ~9 s e 43 MB**, 10 por
> folha A4. O QR do PDF usa correção **M** (15%), porque o crachá amassa e risca; o SVG da tela e
> do ZIP não mudou.
>
> **Sprints 153–154 (branch `feat/ranking-download-e-idiomas`, saída da `main` @ `86d28c7`):**
> (a) **Sprint 153** — o **Ranking dos projetos** passou a ser **baixável**. Ele é a tela em que a
> organização discute quem vai para a lista final, e a discussão acontece em reunião: até aqui o
> recorte só existia dentro do navegador de quem o abriu, e o jeito de levá-lo para a mesa era a
> captura de tela. Agora saem quatro formatos, porque são quatro usos diferentes: **CSV** e
> **Excel** para quem confere em planilha, **PDF** para o papel da reunião e **TXT** para colar num
> e-mail. Todos saem do **mesmo recorte de filtros da tela** — baixar o ranking inteiro quando a
> tela mostra uma área só seria entregar outra coisa do que se está vendo —, e o PDF escreve o
> recorte no cabeçalho, porque uma folha de ranking que não diz de que área é não serve na mesa.
> A **média por seção** da rubrica ficou **fora** do arquivo: ela é a régua de leitura da tela, e
> uma coluna por seção transformaria a planilha em algo que não se lê de lado — o detalhe por seção
> continua no *Ver notas*, em Designações. O que vai em toda linha é posição, projeto, área,
> categoria, média, número de avaliações e a marca **Parcial**, que é o que impede alguém de tratar
> o arquivo como classificação final.
> O **Excel** é o formato novo de verdade: o CSV já abria no Excel, mas chega como **texto** — a
> média com vírgula não soma e a coluna ordena alfabeticamente. Para gravar número como número
> nasceu o `App\Support\PlanilhaXlsx`, um escritor mínimo de `.xlsx` (um zip com meia dúzia de
> XMLs, montado com o `ZipArchive` que já vem no PHP). **Nenhuma dependência nova**: o que a tela
> precisa é uma tabela plana, e PhpSpreadsheet traria ~10 MB de vendor para resolver fórmula,
> gráfico e leitura, nada disso usado aqui. O suporte é deliberadamente pequeno — sem fórmula, sem
> segunda aba, sem mesclagem —, e está escrito no arquivo que, precisando de qualquer uma delas, a
> conta vira a favor da biblioteca.
> (b) **Sprint 154** — o avaliador passou a declarar **em que idiomas pode avaliar** (Português,
> Espanhol, Inglês; `avaliador_profiles.idiomas`, JSON). A feira recebe projeto e visitante de
> fora, e até aqui a organização descobria quem conduzia uma avaliação em espanhol perguntando no
> corredor. É **lista**, não escolha única — quem fala três marca os três —, e ao contrário da
> camiseta é **obrigatório ao menos um**: "nenhum idioma" não é resposta possível para um
> avaliador. Nada vem pré-marcado no cadastro, de propósito: caixa já marcada faria a resposta
> passar sem ninguém olhar para ela.
> O idioma **não entra no algoritmo de distribuição**, que continua sendo por área. Ele informa a
> organização, que designa à mão o projeto estrangeiro a quem pode avaliá-lo — misturá-lo à
> distribuição criaria um segundo eixo de match para atender meia dúzia de projetos por edição.
> Aparece em quatro lugares: **cadastro** e **perfil** do avaliador, **coluna e filtro** em
> Avaliadores Online (com a coluna no CSV), **público combinável** em mala direta, avisos e
> feedback, e um **card** em Dashboards. No card a soma das barras **passa** do número grande —
> quem marcou três idiomas conta nos três —, e o rodapé diz isso, senão parece conta errada.
> Quem já estava cadastrado chega com a **lista vazia**, e é assim que se enxerga quem falta: o
> perfil cobra o preenchimento, a tabela do admin marca "Sem idioma informado" e os públicos por
> idioma **não** alcançam essas contas — alcançá-las diria que responderam quando não responderam.
> Back **1214/1214**, front **611/611**, Pint limpo, build OK.
>
> **Sprints 150–152 (branch `feat/cerimonial-mapa-e-ajustes`, seguindo de `329fb38`):**
> (a) **Sprint 150** — a aba **Ajustes e Pareceres** tinha um portão só:
> `edicoes.ajustes_de`/`ajustes_ate` abria e fechava tudo. Passado o prazo, a devolutiva de um ano
> de trabalho **sumia** da tela do orientador — as etapas da rubrica e as recomendações escritas
> junto —, que é justamente o que ele leva para a edição seguinte. Agora são **dois portões**, e só
> um volta a fechar: `leitura` abre a aba quando a organização libera o período e **não fecha
> mais**; `aberta` continua sendo o prazo da **decisão**, porque depois dele a lista final está
> sendo montada em cima da classificação do projeto e mudá-la moveria o trabalho de categoria no
> meio da apuração. As sugestões não somem com os botões: ficam listadas com o que ficou valendo
> ("Em vigor" / "Aceita por você"), que é o que explica, meses depois, por que o projeto está na
> área em que está. Antes do início nada muda — a aba continua fechada, com a data.
> (b) **Sprint 151** — **tamanho de camiseta do avaliador**
> (`avaliador_profiles.camiseta`). Orientador, aluno e coorientador já informavam o seu; o
> avaliador não, e ele também recebe camiseta — a organização descobria esse número por fora do
> portal. É **opcional** no cadastro e no perfil, e **em branco é uma resposta**: quem não quer
> camiseta fica fora da encomenda, em vez de virar um "N.I." que alguém teria de perseguir. No
> painel vira o **quarto card** de camiseta, com o recorte que o avaliador exige: não há projeto
> submetido que o qualifique, então o conjunto é quem está **ativo e não é conta de ensaio**.
> (c) **Sprint 152** — o **Mapa do Evento** deixou de ser só o desenho de onde o projeto fica.
> **As ruas** são os corredores entre as ilhas, e corredor é o que sobra entre duas fileiras: por
> isso são **achadas no desenho** (`PlantaEvento::ruas()`) em vez de desenhadas à parte — o admin
> só as batiza, e o que se guarda é o nome. Assim mover um estande reposiciona a rua sozinha, em
> vez de deixar um rótulo solto onde o corredor não está mais; pedir que ele as desenhasse criaria
> uma segunda verdade, que deixaria de bater com a planta no primeiro estande movido. Na prancha
> de 2026 a detecção acha o corredor central e os oito entre as ilhas. **Tela cheia** pela
> Fullscreen API, para o mapa ir ao projetor.
> **A cor** tem quatro estágios (`App\Enums\SituacaoEstande`) — aguardando, credenciado, pronto
> para avaliação, em avaliação —, todos vindos de fatos que o portal **já registrava com hora**:
> `credenciamentos.finalizado_em`, `checagens_estande.verificado_em` e
> `avaliacoes_presenciais.concluida_em`. Nada é marcado à mão, e é por isso que o mapa nunca
> discorda das outras abas. O último estágio **escurece a cada avaliação recebida**: de longe se vê
> quais estandes ainda esperam avaliador.
> É esse carimbo de hora que dá o **seletor de dia** de graça: escolher um dia anterior é ler os
> mesmos fatos com um corte mais cedo, e o mapa volta a ser o que era no fim daquele dia. Nenhum
> instantâneo precisa ser gravado — e uma correção retroativa aparece no histórico como deveria ter
> aparecido desde o começo, o que um instantâneo congelado esconderia. O **turno** é o outro eixo,
> porque o estande recebe um projeto de manhã e outro à tarde.
> O mesmo recorte sai em **lista filtrável** — credenciamento, checagem, avaliações realizadas ou
> faltantes — e exporta em TXT, CSV e PDF, como as outras telas do mapa. O dia corrente se
> recarrega sozinho a cada 30s; um dia passado não muda mais, então recarregá-lo seria bater no
> servidor à toa. `MapaSituacaoService`.
> Back **1193/1193**, front **597/597**, Pint limpo, build OK.
>
> **Sprints 148–149 (branch `feat/cerimonial-mapa-e-ajustes`, saída da `origin/main` @ `9b35d9f`):**
> (a) **Sprint 148** — o menu lateral do admin **não rolava**. Ele acumulou abas ao longo de 149
> sprints, e numa tela **larga mas baixa** (notebook deitado, projetor 16:9) as últimas ficavam
> para fora da janela, inalcançáveis: a coluna flex crescia além da altura do `nav`, que é `h-full`
> e fixo. A lista de abas passou a rolar sozinha (`flex-1 min-h-0 overflow-y-auto` — sem o
> `min-h-0` o filho não encolhe abaixo do próprio conteúdo e o `overflow` não faz nada dentro de
> uma coluna flex), enquanto a logomarca em cima e **Acesso/Sair/suporte embaixo ficam ancorados**:
> a saída da tela não pode depender de rolagem. A barra tem estilo próprio (`.fetec-scroll`).
> (b) **Sprint 149** — nasce a aba **Cerimonial** (`AbaAdmin::Cerimonial`, do RBAC como as demais),
> a porta da **cerimônia de premiação**. Ela é outro momento do evento que não o credenciamento —
> outra sala, outra fila —, e ali a pergunta é uma só: **quem já está aqui dentro?**. Por isso o
> check-in é **independente**: a ficha mostra se o projeto passou pelo balcão e **não trava** quem
> não passou.
> **Check-in** atende pelos dois caminhos que a fila tem: o **crachá** da lista final (leitor USB
> ou câmera, a mesma leitura e as mesmas três conferências do credenciamento — formato, finalista
> e pessoa) e a **busca por nome ou CPF**, para quem deixou a etiqueta no estande. Os dois
> terminam na **ficha do projeto**, e não num check-in imediato: a equipe chega junta, e marcar
> quatro de uma vez é o que faz a fila andar. O check-in continua sendo **por pessoa** — é gente
> que entra na sala e é gente que recebe medalha.
> **Visão Geral** traz cinco cards, e **todos** abrem a lista nominal de quem chegou e de quem
> falta, que é a pergunta do dia: **pessoas**, **projetos** (parcial e completo são números
> diferentes e os dois importam — o palco chama a equipe inteira, a porta conta quem entrou),
> **premiados**, **medalhas** e **credenciais**. *Premiados* tem ainda uma tela própria
> (`/admin/cerimonial/premiados`): um cartão por projeto com um **busto por integrante**, verde
> para quem já entrou — a pergunta antes de chamar ao palco é visual, e uma tabela de nomes
> obrigaria a ler linha por linha enquanto o locutor espera.
> **Medalha segue a pessoa** e **credencial segue o projeto**: cada premiado presente rende uma
> medalha, e cada projeto premiado com alguém na sala tira da mesa as credenciais dele. Só as
> **credenciais** — prêmio se anuncia, não se entrega em mãos —, e é daí que *Credenciais* virou
> **Credenciais e Prêmios**: a mesma ficha, com o campo `tipo`
> (`App\Enums\TipoCredencial`), e o que já existia é credencial, que era o único tipo que havia.
> **Premiado** é quem recebeu credencial **ou** prêmio, então a aba se apoia no cadastro de
> Avaliação presencial em vez de pedir uma marcação nova.
> A **conta temporária** do setor `cerimonial` **só abre o Check-in**: quem atende a porta não
> precisa saber quantas medalhas há na mesa, nem gere as contas dos colegas. O corte fino dentro da
> aba é o middleware novo **`admin.permanente`** — não o menu escondido: trocar a URL à mão
> responde 403. É a primeira aba em que a conta temporária não faz tudo o que a aba faz.
> **Desfazer** um check-in pede **justificativa**, porque o número decide o que vai ser entregue no
> palco: a linha é apagada (a contagem tem de voltar a bater) e o registro fica, na seção nova
> **Registros → Cerimonial**, com o nome de quem era e o motivo.
> O painel se **atualiza sozinho** por polling (não há WebSocket no projeto) num intervalo **da
> edição** (`edicoes.cerimonial_atualizacao_segundos`, padrão 30s, em branco desliga), escolhido na
> própria tela — ela costuma ficar aberta num tablet enquanto vários balcões registram em paralelo.
> Janela do **evento**, como o credenciamento, com o mesmo **modo de teste**: ignora as datas, usa
> a lista final de demonstração e **isola os check-ins do ensaio** (`cerimonial_checkins.demo`).
> Back **1175/1175**, front **586/586**, Pint limpo, build OK.
>
> **Sprint 147 (branch `feat/aviso-fim-avaliacao`, saída da `origin/main` @ `689c149`):**
> o orientador passou a **ser avisado** de que a avaliação online acabou.
> A aba *Ajustes e Pareceres* existe desde a Sprint 146, mas só ajuda quem abre o portal e olha
> para o menu — e não há por que ele abrir: submeteu em setembro, a feira é em outubro e ninguém
> lhe diz que os avaliadores terminaram. Agora, passado o fim do período
> (`edicoes.avaliacao_encerrada_em`), a tela inicial (*Meus Projetos*) traz um cartão dizendo que a
> avaliação terminou — com a data — e um botão que leva direto à aba.
> O texto do cartão **acompanha o calendário**, porque o fim da avaliação e a abertura dos ajustes
> são duas datas e a organização não as marca no mesmo dia: com o período aberto ele diz o que há
> para fazer e **até quando**; com data marcada mais à frente, **quando** a aba abre; sem data
> nenhuma, que a organização ainda não abriu o período. O botão fica nos três casos — é a mesma aba
> do menu, que explica a situação a quem chega cedo.
> Duas coisas que ele **não** faz. Não aparece para quem ficou só no rascunho: sem projeto
> submetido não houve avaliação, e o aviso seria ruído em cima de quem já perdeu o prazo. E **some**
> quando o período de ajustes se encerra — aí não há mais o que acompanhar, e as decisões tomadas
> continuam valendo. Nota nenhuma aparece nele, pela razão da Sprint 146.
> No servidor nasceu `GET /ajustes/janela`, que devolve **só as datas**: o `GET /ajustes` varre os
> projetos do orientador e as avaliações de cada um para montar a aba, e um aviso não justifica esse
> trabalho a cada visita à tela inicial. A `janela()` do `AjustesOrientadorService` passou a carregar
> junto o fim da avaliação (`avaliacao_encerrada` + rótulo) — que é **factual**, e não segue o modo
> de teste da conta demo: ele adianta a aba, não o calendário da feira, senão o orientador demo leria
> "a avaliação terminou" no meio dela. Falhando a consulta, a tela abre sem o cartão: ele informa,
> não bloqueia.
> **Sem migration, sem dependência nova e sem variável de `.env`.**
> Back **1153/1153**, front **557/557**, Pint limpo, build OK.
>
> **Sprint 146 (branch `feat/ajustes-e-pareceres`, saída da `main` @ `0d1e9f1`):**
> o orientador passou a ter **uma aba só** para o pós-avaliação — **Ajustes e Pareceres**
> (`/ajustes`) —, e ela **não mostra mais nota nenhuma**.
> (a) **A junção.** *Ajustes* e *Pareceres* nasceram separadas, mas falam do mesmo material, do
> mesmo projeto e na mesma janela (`edicoes.ajustes_de`/`ajustes_ate`): a sugestão de área que ele
> decide e a crítica escrita que a motivou saíam de avaliações idênticas, em duas telas. Quem lia o
> parecer numa tinha de trocar de tela para decidir na outra. Agora o projeto aberto traz, na
> ordem: as **sugestões de classificação** (aceitar/desfazer, como antes), as **etapas da rubrica**
> em pontos fortes/médios/fracos e as **recomendações escritas**. O item do menu é um só; `/pareceres`
> **redireciona** para `/ajustes`, para o link já enviado não morrer.
> No servidor a junção é de verdade, e não duas chamadas emendadas na tela: o `GET /ajustes` e o
> `GET /ajustes/projetos/{projeto}` respondem pela aba inteira, e **as rotas `/pareceres` saíram do
> ar** (o `OrientadorParecerController` foi removido). O `AjustesOrientadorService` virou o serviço
> da tela e pede os níveis ao `PareceresOrientadorService`, que ficou sendo só isso — a conversão de
> seção em nível. As **avaliações concluídas são carregadas uma vez** e servem às três metades, o
> que de quebra tira uma consulta por projeto da listagem: antes, `sugestoes()` e `recomendacoes()`
> buscavam cada uma a sua.
> (b) **A nota saiu.** A média do projeto e o "de 10,00" eram o cabeçalho da aba Pareceres; não são
> mais exibidos **nem enviados** — `media` e `nota_maxima` deixaram de existir no payload, como a
> pontuação por seção já não existia desde a Sprint 127. O princípio é o mesmo daquela sprint, agora
> até o fim: o que o orientador precisa é saber **onde** melhorar, e o número é o instrumento com
> que a organização classifica, monta a lista final e trata contestação (Ranking → Verificar
> disparidade). Esconder na tela e mandar no JSON não seria esconder. No lugar dele o cartão do
> projeto diz **quantas avaliações** houve — a informação que ele de fato usa para saber se o
> parecer está completo — e o detalhe abre com "Resultado de N avaliação(ões) concluída(s)".
> Nada muda para o admin nem para o avaliador: as notas continuam onde sempre estiveram (ranking,
> Designações → Ver notas, disparidade), com o registro de quem as abriu.
> (c) **O ensaio acompanhou**: `demo:pareceres` lê o payload novo e a **nota média passou a sair só
> no terminal**, calculada ali — é a prova de que a conversão de `SemearPareceresDemo::PERFIL` em
> respostas respeitou os pesos do edital, e quem lê o terminal é a organização.
> **Sem migration, sem dependência nova e sem variável de `.env`.**
> Back **1149/1149**, front **546/546**, Pint limpo, build OK.
>
> **Sprint 145 (branch `feat/demo-pareceres-ajustes`, saída da `main` @ `94dd9f6`):**
> ensaiar o **pós-avaliação** pelo lado do orientador — as abas **Pareceres** e **Ajustes** —
> antes de a organização abrir o período. O `demo:ajustes` já montava um projeto avaliado, mas
> responde as 17 perguntas da rubrica com **o mesmo valor** (8 / Sim): a aba Ajustes fica correta e
> a aba Pareceres diz **"Ponto forte" nas dez seções**, que é justamente o que não se precisa ver.
> O `php artisan demo:pareceres` monta um segundo projeto em que as notas se distribuem: quatro
> seções fortes, quatro médias e duas fracas (Resultados e Referências), com os três avaliadores
> discordando entre si e as **recomendações escritas cobrando as seções fracas** — parecer que
> elogia o que a nota reprova confunde quem está conferindo a tela.
> O comando não escreve pergunta a pergunta: uma tabela diz **quanto cada seção rendeu, de 0 a 10,
> na cabeça de cada avaliador** (`SemearPareceresDemo::PERFIL`) e a rubrica converte isso em
> resposta — escala de dois em dois nas perguntas "de que modo…", Sim/Não nas outras. É o que faz
> a nota sair coerente com os pesos do edital sem ninguém calcular o 0,5375 de Objetivos à mão, e o
> que prende o nível de cada seção ao corte do `PareceresOrientadorService` (8 forte, 4 médio) — que
> normaliza a seção em 0 a 10, senão o Título, que vale 0,15, nunca seria um ponto forte. No fim o
> comando **imprime os níveis que gerou**, para a conferência não depender de abrir o navegador.
> As contas são as **mesmas quatro** do `demo:ajustes` (e-mails e CPFs), então os dois comandos
> convivem: o orientador demo fica com dois projetos e nenhum cadastro é duplicado. `is_demo` faz o
> trabalho de sempre nos dois sentidos — tira o projeto do painel, do ranking, da lista final e da
> distribuição, e é o que **libera o "Modo de teste"** nas duas abas, que ignora
> `edicoes.ajustes_de`/`ajustes_ate`. **Sem migration, sem dependência nova e sem variável de
> `.env`.**
> Back **1147/1147**, Pint limpo.
>
> > **Sprint 144 (branch `feat/avaliar-antes-de-desconsiderar`, saída da `origin/main` @ `404f42c`):**
> "eu mesmo avalio no lugar desta nota" passou a **começar pela rubrica**.
> A ordem da Sprint 142 era: justificativa → a nota sai da classificação → nasce a avaliação da
> organização → o admin preenche. Ele descartava um parecer **antes de ter lido o projeto**, e
> desistir no meio deixava o pior dos dois mundos — o projeto com uma nota a menos e nada no lugar,
> justamente na semana em que a lista final fecha. Agora o clique abre o **painel de avaliação
> daquele projeto** na hora, e a nota antiga **só sai no envio**: a substituição é um ato só.
> Entre os dois momentos existe uma intenção guardada, e ela **não cabe na tela** — o rascunho é
> retomável, inclusive noutra sessão, e a justificativa escrita lá atrás precisa chegar inteira ao
> registro. Por isso ela mora na própria linha da avaliação da organização
> (`avaliacoes.substitui_avaliacao_id` + `substituicao_motivo`), e não no estado do React.
> O envio é `POST .../formulario/concluir` de sempre, agora numa transação que conclui a avaliação
> **e** grava a desconsideração com aquela justificativa (`NotasAvaliacaoService::enviarSubstituicao`);
> se a nota já tiver saído por outro caminho nesse meio-tempo, o envio passa reto, em vez de contar
> duas vezes o que aconteceu uma. Abrir virou endpoint próprio
> (`POST /admin/avaliacao/avaliacoes/{avaliacao}/avaliar-no-lugar`) e o `tipo: 'admin'` saiu do
> `/desconsiderar` — aquele caminho não existe mais.
> A retomada precisou existir junto: o wizard da organização só se abre pelo diálogo de notas,
> então um rascunho abandonado ficaria inalcançável. O cartão da nota passa a dizer que há uma
> substituição a caminho — com **Continuar a avaliação** para quem a abriu, e só o aviso para os
> outros admins, que não preenchem parecer alheio e não devem abrir o mesmo buraco duas vezes. No
> topo do formulário, um aviso diz **qual nota sai quando ele enviar**: quem preenche precisa ler
> isso antes de responder, não depois.
> De quebra, um defeito que tornava a Sprint 142 inoperante em produção: `desconsiderarNota` em
> `lib/admin.js` recebia a substituição escolhida e **não a mandava** no corpo da requisição, então
> nem "designar outro avaliador" nem "eu mesmo avalio" chegavam ao servidor — desconsiderar sempre
> caía em "apenas desconsiderar". Os testes de tela mockavam esse helper e nunca viam o payload;
> agora há teste de contrato sobre ele (`lib/admin.test.js`).
> Back **1140/1140**, front **550/550**, Pint limpo, build OK.
>
> **Sprints 138–143 (branch `feat/disparidade-e-auditoria-de-notas`, saída da `origin/main` @ `b9e0c0d`):**
> o ciclo da **auditoria da nota** — o que fazer quando o número que decide a lista final não
> merece confiança.
> (a) **Sprint 138** — a retirada de designação passou a perguntar o que acontece com o projeto
> depois. **Redesignar** continua o padrão; desmarcar a reposição na confirmação **apenas remove**
> a designação. São duas intenções diferentes — "esta pessoa não pode ficar com este projeto" e
> "este projeto não precisa mais deste parecer" —, e emendar as duas obrigava o admin a retirar
> duas vezes: uma para tirar o avaliador, outra para tirar o substituto que o algoritmo acabara de
> pôr ali. A trilha distingue os dois casos: `(retirada sem reposição)` não é `(sem avaliador)`,
> que é falta de candidato e pede providência.
> (b) **Sprint 139** — **Verificar disparidade → Ver notas**. A lista diz que a maior e a menor
> nota estão a 5,00 de distância; o diálogo diz **onde** elas se afastaram e **quem** deu cada uma:
> uma coluna por avaliador, com nome, nota final e os pontos de cada seção da rubrica, mais o
> parecer escrito. A seção de maior discordância fica destacada. O detalhe por seção saiu do
> `DesignacaoService` para o `App\Support\DetalheRubrica`, hoje compartilhado; **cada avaliação
> aberta vira um registro** em Registros → Notas, como no Ver notas de uma só.
> (c) **Sprint 140** — a tela ganhou **abas**. A primeira olha para o **projeto** (a lista de
> sempre); a segunda, **Identificação de padrões**, olha para o **avaliador** e procura quatro
> sinais, com limiares ajustáveis na tela: **notas infladas** (média acima do corte, ou nota máxima
> em todas — o certificado fácil), **notas muito abaixo dos colegas**, **respostas repetidas** (a
> mesma resposta em todas as perguntas, ou duas avaliações idênticas) e **avaliação relâmpago**
> (enviada minutos depois de aberta). O segundo sinal exige que a distância se repita em **metade**
> dos projetos comparáveis, e em no mínimo dois: uma nota baixa isolada é legítima — é por causa
> dela que o projeto tem três pareceres — e sozinha puxaria a média, acusando um avaliador
> criterioso. Para o quarto veio `avaliacoes.iniciada_em` (só a primeira abertura grava; as
> concluídas antes disso ficam nulas e saem do sinal, em vez de virar um número inventado). A
> análise **não grava nada**: os limiares são mexidos até o corte fazer sentido, e registrar cada
> tentativa encheria a trilha. `PadroesAvaliacaoService`.
> (d) **Sprint 141** — **desconsiderar a nota** de um avaliador. Ela não é apagada: fica no
> diálogo, riscada, com quem descartou, quando e **por quê** — apagar destruiria a prova
> justamente no caso em que alguém contesta. Justificativa obrigatória nos dois sentidos (desfazer
> também é ato). Sai de tudo que classifica (`Avaliacao::scopeConsiderada()` em ranking, lista
> final, disparidade, pareceres do orientador, reclassificação e **cobertura** — o projeto volta a
> precisar daquele parecer, que é o que abre vaga para o substituto); o **certificado e o ranking
> do avaliador continuam contando**, porque quem descartou foi a organização e o trabalho
> aconteceu. Não virou global scope de propósito: há tantas telas que precisam vê-la quanto telas
> que precisam ignorá-la.
> (e) **Sprint 142** — o que entra no lugar. No mesmo diálogo o admin escolhe: **não repor**
> (legítimo quando há pareceres de sobra), **designar outro avaliador** (designação manual, com o
> e-mail de sempre; quem já avaliou o projeto é recusado, inclusive o dono da nota descartada) ou
> **avaliar ele mesmo agora** — nasce uma avaliação `pela_organizacao`, já aberta, e o **mesmo
> wizard da rubrica** abre na hora. Ela conta para o projeto (média, ranking, lista final) e **não
> conta para a pessoa**: fora do ranking de avaliadores, sem certificado e sem repor fila. O
> `AvaliacaoModal` ganhou uma `api` injetável para servir aos dois lados, e os endpoints do admin
> não têm a trava de data — repor um parecer descartado costuma acontecer depois do prazo, com a
> lista final fechando.
> (f) **Sprint 143** — o card de ranking do perfil do avaliador passou a dizer quantas avaliações
> tem quem está **imediatamente à frente**, e quantas faltam para alcançá-lo. O número, nunca o
> nome: o ranking é interno da organização, e dizer a alguém quem está à frente dele criaria
> constrangimento entre pessoas que precisam trabalhar juntas. É o vizinho, e não o líder — "falta
> 1 para alcançar" move; "o primeiro tem 40", não. Quem lidera lê que ninguém está à frente.
> Back **1135/1135**, front **547/547**, Pint limpo, build OK.
>
> **Sprints 126–137 (branch `feat/avaliacao-presencial`, saída da `main` @ `6897b91`):**
> o ciclo do **dia do evento pelo lado de quem avalia** — mais a devolutiva ao
> orientador e a revisão da lista final.
> (a) **Sprint 126** — Ranking → **Verificar disparidade**. O ranking ordena pela média, e a
> média esconde o desacordo: 9,50 com 4,50 dá o mesmo 7,00 que 7,00 com 7,00, só que no
> primeiro caso o número que decide a lista final não representa nenhum dos dois
> avaliadores. O admin informa a diferença que considera demais (duas casas) e recebe os
> projetos cuja **amplitude** (maior − menor) chegou lá. O critério é a amplitude, e não o
> desvio da média, porque é o que se explica numa contestação sem estatística e porque já
> pega o caso de duas avaliações. Cada lista fica **registrada com os dados congelados**
> (`verificacoes_disparidade`) e entra em Registros; cada item leva ao diálogo de
> **Designar** já com o projeto marcado.
> (b) **Sprint 127** — aba **Pareceres** do orientador. Mostra a nota média de cada projeto
> submetido e as observações escritas, sempre anônimas. As etapas da rubrica aparecem **sem
> nota**: cada seção vira um nível — ponto forte (≥ 8), médio (≥ 4) ou fraco —, normalizado
> em 0 a 10 (sem isso o Título, que vale 0,15, nunca seria um ponto forte). A pontuação das
> seções **não vai no payload**: o que não é enviado não vaza. Janela igual à dos ajustes.
> (c) **Sprint 128** — a lista final passa a ser **vista antes de ser baixada**. Toda geração
> nasce **rascunho** (`listas_finais.rascunho`), aberta na mesma tela de composição, onde o
> admin inclui e retira com justificativa antes de baixar o TXT ou publicar. Ninguém vira
> finalista por causa de um rascunho. `POST /lista-final` passou a responder **201 com a
> prévia** em vez do arquivo. O TXT ganhou o **coorientador** abaixo do orientador.
> (d) **Sprint 129** — aba **Documentos** do orientador: o **termo de responsabilidade** dos
> projetos finalistas, um por projeto, da publicação da lista ao fim do evento. Todo PDF
> passa pelo `AssinaturaPdf`: existe assinatura, o resumo recalculado bate com o assinado (é
> o que pega a página trocada depois) e o certificado diz nome, CPF e se é ICP-Brasil. Ficam
> de fora revogação e cadeia completa — o laudo é informativo, não um portão —, e o arquivo
> **não é recusado** por falhar: um termo assinado à caneta e digitalizado existe.
> (e) **Sprint 130** — público **"finalistas sem o termo"**, disponível na mala direta, nos
> avisos e no feedback. Sem lista final publicada ele fica **vazio**, e não "todo mundo".
> (f) **Sprint 131** — aba **Presencial** do avaliador: ele diz se pretende avaliar no dia da
> feira. A resposta tem **três estados** (`avaliador_profiles.presencial` nullable) — sim,
> não e ainda não respondeu —, e muda-se de ideia até o evento começar. Quem aceita vê as
> orientações da edição (`edicoes.info_avaliacao_presencial`).
> (g) **Sprint 132** — nasce a aba **Avaliação presencial** (`AbaAdmin::AvaliacaoPresencial`)
> com a **checagem de estandes**: a lista dos finalistas com estande e turno, a ficha que
> marca item a item (catálogo `itens_checagem_estande`) e o **espelho**, que põe todos lado a
> lado. O **termo** aparece na ficha já resolvido, com o PDF a um clique — conferi-lo de novo
> em outro lugar seria pedir a mesma volta duas vezes.
> (h) **Sprint 133** — **voluntários**: contas temporárias do setor `avaliacao_presencial`
> com vários **turnos de trabalho** (`conta_temporaria_turnos`). Os turnos não substituem a
> janela — ela vira o **envelope** deles —, e acrescentam a trava fina: **entre** um turno e
> outro a conta existe, está no prazo e não abre.
> (i) **Sprint 134** — **Credenciais**: as vagas de premiação (indicações, bolsas, prêmios),
> anexadas a projetos finalistas, com teto por vaga e a **lista de premiação** em TXT.
> `vagas` em branco é sem teto; reduzir abaixo do que já foi dado é recusado.
> (j) **Sprint 135** — a **avaliação no estande**, com rubrica própria
> (`App\Support\RubricaPresencial`: 8 perguntas, 4 seções, soma 10,00) e nota **separada**,
> que alimenta a premiação e não o ranking online. Avalia quem aceitou, durante o evento; os
> estandes chegam por **designação do admin** e por **escolha no local**, ambos limitados a
> **3 avaliações por projeto** (a designação manual passa por cima).
> (k) **Sprint 136** — **presença** das contas temporárias: a pessoa marca no primeiro acesso
> do turno e o admin do setor **aprova ou rejeita** (rejeitar exige motivo e tira a conta do
> ar). Enquanto não é aprovada, a conta **não abre aba nenhuma** — ter crachá não é estar de
> plantão. As contas que já existiam entraram como aprovadas, para o deploy não trancar um
> balcão em funcionamento.
> (l) **Sprint 137** — **Comitê especial → Designações**: quem administra o comitê designa
> projetos **só** para os avaliadores da comissão especial, com a trava no servidor (trocar o
> id no payload não alcança ninguém de fora). Na aba Avaliação online nada muda.
> De quebra, um teste antigo (`DesignacaoEmMassaTest`) falhava de vez em quando: a busca
> olha nome **e** e-mail, e o factory sorteia endereços — um "bruno.santana@…" entrava no
> filtro por "ana". O e-mail dos avaliadores do teste passou a sair do nome.
> Back **1098/1098**, front **537/537**, Pint limpo, build OK.
>
> **Sprint 125 (mesma branch `feat/mapa-do-evento`):** a tabela de **Designações**
> mostrava a situação de cada avaliação, mas não o que saiu dela — e é a nota que
> decide a lista final. Cada linha ganhou **Ver notas**: um diálogo com a nota
> **seção por seção** da rubrica oficial (o subtotal de cada uma sobre o teto
> dela), cada pergunta com o **rótulo da escala** que o avaliador leu ("8 — Bom",
> "Sim") e os pontos que rendeu, a **soma geral** em destaque e o parecer escrito,
> que é o que explica o número. Pergunta em branco é dita como **não respondida**,
> em vez de virar um zero silencioso; só **avaliação concluída** tem nota, então
> nas outras o botão fica desabilitado dizendo o porquê.
> Abrir a nota **vira registro** na seção nova **Registros → Notas**
> (`notas_visualizadas`), com o projeto, o avaliador e o valor que estava na tela.
> Consulta não muda nada, mas o parecer é **anônimo para o orientador**: saber quem
> leu o quê é o que protege esse sigilo e o que responde a uma contestação sem
> depender da memória de ninguém. Por isso o endpoint é **POST** — num GET, um
> prefetch do navegador ou um F5 gravariam consultas que ninguém fez — e **cada
> abertura** gera uma linha: o registro conta acessos, não avaliações.
> De quebra, a seção **Mapa do evento** dos Registros (criada nas Sprints 118–119)
> ganhou o card e a rota que faltavam no front — os registros existiam e não
> tinham tela.
> Back **978/978**, front **477/477**, Pint limpo, build OK.
>
> **Sprints 117–124 (branch `feat/mapa-do-evento`, saída da `origin/main` @ `3a49ba2`):**
> o ciclo do **dia do evento** — onde cada projeto apresenta, em que estande, e
> como o balcão reconhece quem chega — mais a faxina dos dados de ensaio.
> (a) **Sprint 117** — **Parametrização → Dados de demonstração**. O portal
> acumulou ensaio (contas de treinamento, o projeto-exemplo dos ajustes, a lista
> final demo do balcão, as guardas do almoxarifado) e cada coisa sumia de uma
> tela diferente: ninguém conseguia responder "o que ainda é de mentira aqui
> dentro?". A tela nova responde e deixa limpar — desmarcar uma conta, apagar um
> item ou tudo —, e **nada que não esteja marcado como demonstração sai por ali**
> (`DadosDemoService`). Junto veio o conserto do vazamento que a motivou: o
> projeto de um orientador demo aparecia em *Projetos submetidos* e podia ser
> designado a um avaliador de verdade. As três consultas daquela tela e a de
> *Designações* passaram a aplicar `Projeto::semDemo()`, como o painel, o ranking,
> a lista final e a distribuição já faziam.
> (b) **Sprint 118** — nasce a aba **Mapa do Evento** (`AbaAdmin::Mapa`, do RBAC
> como as demais), com a primeira seção: **Turnos de Apresentação**. A feira tem
> mais finalistas do que estandes — o mesmo estande recebe um projeto de manhã e
> outro à tarde —, e dividir quem apresenta em cada turno era trabalho de
> planilha que não sabia das justificativas. Agora a divisão sai da **lista final
> vigente** por cinco regras **em ordem de prioridade** (vestibular, outras
> justificativas, fora do MS, interior, capital): a primeira que alcança um
> projeto decide o turno dele, e o resto é repartido para os dois turnos ficarem
> equilibrados. A capacidade é **teto**: turno cheio manda o projeto para o outro
> e a tela diz quais foram; não cabendo nos dois somados, a geração é **recusada**
> com o número de lugares que faltam. Só a última lista vale, a troca manual de
> turno não pede justificativa mas fica registrada, e o recorte exporta em PDF,
> TXT e CSV (`TurnosApresentacaoService`, `RegrasTurnos`).
> (c) **Sprint 119** — **Estandes dos Projetos**. A faixa de cada categoria é
> escrita como se escreve no papel (`1-4, 7-9, 10-52`, em `FaixaEstandes`), saem
> **duas listas** (uma por turno) e quem não tem faixa ocupa os números que
> sobraram. O mesmo estande em duas categorias é recusado — seriam dois projetos
> no mesmo lugar físico. Depois de gerada, mandar um projeto para um número
> ocupado **troca os dois de lugar**, e a trilha conta os dois lados da troca.
> (d) **Sprint 120** — a **planta do ginásio**, em SVG e na identidade do portal:
> clicar num estande abre quem apresenta nele **de manhã e à tarde**. O desenho
> começa na prancha da montadora (`PlantaEvento`: 230 estandes em dois blocos, com
> a numeração serpenteante escrita à mão, porque a prancha não segue uma regra
> única) e é **editável e versionado por edição** (`mapa_layouts`) — mover, 
> renumerar, acrescentar e remover, com cada gravação virando uma **versão nova**.
> Restaurar uma antiga também cria versão, para o histórico continuar sendo a
> sequência do que esteve em vigor.
> (e) **Sprint 121** — **Designações → Designar**. O admin marca um ou mais
> projetos e um ou mais avaliadores e o portal cruza tudo com tudo (3 × 2 = 6).
> Quem **já avaliou** aquele projeto é pulado, o resto é designado e a tela lista
> quem ficou de fora e por quê; avaliação devolvida pelo prazo é **revivida** com
> o rascunho. No fim, cada avaliador recebe **um** e-mail com a lista do que
> chegou para ele e o admin recebe o resumo — os dois textos editáveis em
> Comunicação → Modelos de e-mail (`projetos_designados`, `designacao_concluida`).
> (f) **Sprint 122** — o ensaio da aba **Ajustes** passou a ter **três
> avaliadores**: uma sugestão de área, uma de subárea e outra de área que
> **disputa** com a primeira. É o caso difícil que o orientador precisa ver antes
> — aceitar uma desliga a outra —, e com um avaliador só a tela parecia sempre
> consensual. O orientador padrão do `demo:ajustes` agora é
> **`orientador@fetecms.test`**, a conta do seed, marcada como demo.
> (g) **Sprint 123** — **identificação dos participantes** da lista final: um QR
> Code e um código de barras para cada aluno, orientador e coorientador. O código
> é **derivado** (`CodigoParticipante`): ano da edição, id do projeto, três
> dígitos do CPF e o id da pessoa com o prefixo do papel (`A`/`O`/`C`, sem o qual
> os ids das três tabelas colidiriam). **Nada nele vem da composição da lista**,
> então o crachá impresso hoje continua valendo depois de a lista subir de versão.
> Os dois formatos servem aos dois leitores do evento — QR para a câmera, Code 128
> para o leitor USB —, saem em **SVG** (imprime nítido, não depende da extensão
> GD) e são baixáveis como **PDF de etiquetas** e **ZIP** por projeto.
> (h) **Sprint 124** — o balcão passou a **ler o crachá**: campo com foco
> automático (o leitor USB bipa e dá Enter, a ficha abre) e leitura por **câmera**
> onde o navegador tem `BarcodeDetector` — onde não tem, o botão não aparece e a
> tela explica. A conferência é em três camadas: **formato**, **finalista** (a
> lista que vale para aquela pessoa — o modo de teste continua sem alcançar
> finalista de verdade) e **pessoa** (papel+id na equipe e os três dígitos do CPF,
> o que pega o crachá trocado entre colegas).
> Back **970/970**, front **474/474**, Pint limpo, build OK.
>
> **Sprints 110–116 (branch `feat/distribuicao-por-atividade`, saída da `origin/main` @ `5fa3e81`):**
> ciclo do ciclo de vida da designação, mais dois defeitos de produção e as dependências.
> (a) **Sprint 110** — a fila do avaliador só sabia nascer de um jeito: o admin distribuía em
> massa e cada um saía com ela cheia, esperando ele aparecer. Numa feira em que boa parte dos
> cadastrados nunca entra, isso manda projeto para o limbo — três designados, nenhuma avaliação —
> enquanto quem está trabalhando fica sem o que avaliar. Agora a edição escolhe o **modo**
> (`edicoes.modo_distribuicao`, enum `App\Enums\ModoDistribuicao`), na tela do Algoritmo de
> distribuição: **Total** é o comportamento histórico e continua o padrão; **por Atividade** monta
> a fila no **login** (`SessaoAvaliadorService::aoEntrar`), com o mesmo **mínimo por avaliador** de
> sempre — reusar o campo é de propósito, ele já significa "quantos projetos o avaliador enxerga de
> uma vez". Neste modo **Distribuir** e **Redistribuir** ficam desabilitados, com a recusa também no
> servidor: designar em massa criaria filas que a próxima sessão devolveria. O login só designa
> dentro do período de avaliação, e a troca de modo não mexe no que já está designado.
> (b) **Sprint 111** — a garantia de que a **designação manual** não é desfeita existia, mas morava
> numa cláusula solta dentro do rodízio; nada impedia um caminho novo de esquecê-la. A regra ganhou
> um lugar só (`Avaliacao::scopeDevolvivel()`): uma devolução automática só alcança o que o
> algoritmo pôs na fila e ninguém abriu. Ficam de fora, sempre, a designação manual (escape do
> edital — só a retirada explícita do admin, em *Designações*, tira uma dessas) e a avaliação já
> assumida. A redistribuição passou a **relatar o que preservou**, para o admin não ter de acreditar
> na promessa.
> (c) **Sprint 112** — fecha o modo por atividade: a fila que nascia no login agora sabe morrer. A
> **sessão acaba de três jeitos** e os três devolvem — sair pelo menu, entrar de novo (o login limpa
> o que sobrou) e **fechar o navegador**, coberto pela varredura por inatividade
> (`edicoes.horas_sessao_avaliador`, padrão 12h; nunca fica em branco, senão quem some prende os
> projetos para sempre). Junto veio o **prazo da avaliação aberta**
> (`edicoes.dias_avaliacao_aberta`, em branco desliga), que vale nos **dois modos**: avaliação
> começada e largada trava o projeto do mesmo jeito. Passado o prazo o projeto volta na hora para a
> pilha, mas **a linha não é apagada** — `avaliacoes.devolvida_em` a esconde das contas de cobertura
> e guarda dentro dela o que o avaliador respondeu; ele vê a avaliação numa seção própria do painel
> e **retoma de onde parou** se o projeto ainda aceitar avaliação. Esconder as devolvidas é um
> **global scope** (`AvaliacaoAtivaScope`), e não um `whereNull` repetido: as contas de cobertura
> estão espalhadas por meia dúzia de serviços e esquecer uma faria o projeto continuar ocupado
> depois de devolvido. A devolução por prazo entra em Registros em nome do sistema; a de fim de
> sessão não, senão cada logout viraria uma linha.
> (d) **Sprint 113** — designar uma área inteira quase sempre alcança alguém que **já avaliou**
> aquele trabalho, e esse nome sumia em silêncio: o admin lia "2 designações criadas" e ia embora
> achando que a cobertura aconteceu inteira. Agora `designar()` devolve quem ficou de fora e por
> quê, e a tela mostra isso num aviso separado do "deu certo". Os elegíveis são designados
> normalmente. De quebra, conserta um caso que a Sprint 112 abriu: o avaliador com avaliação
> **devolvida pelo prazo** tem uma linha escondida pelo escopo, e o `firstOrCreate` bateria na chave
> única de (projeto, avaliador) — designá-lo agora **revive** aquela avaliação com o rascunho dele.
> (e) **Sprint 114** — defeito de produção: a mala direta chegava **sem anexo**. O disco privado
> roda com `throw => false`, então um arquivo que não está mais em `storage/app/private` devolve
> `null` em silêncio e o `Attachment::fromStorageDisk` monta um anexo de **0 byte**: o e-mail sai, o
> relatório diz "enviado" e o destinatário recebe uma mensagem que promete o edital e não o entrega.
> O teste que existia parava em `assertCount(1, $mensagem->attachments())` — confirmava que o
> Mailable sabia **listar** o anexo, não que a mensagem o **carregava** (a mesma armadilha da Sprint
> 102). Três camadas: o disparo recusa antes de enfileirar (uma mensagem, não 242 falhas); o envio
> lê e confere, e faltando o destinatário vira **falha** com o motivo (*Reenviar falhas* resolve
> depois); e o detalhe da mala passa a **listar os anexos** que foram junto — sem isso, uma mala que
> saiu sem anexo é indistinguível de uma que nunca teve um. A causa raiz é de infraestrutura (o
> upload é no web, a leitura é no `queue:work`), documentada na §deploy.
> (f) **Sprint 115** — defeito de produção: uma avaliadora recebeu projetos designados pelo admin e
> **eles não apareceram** na tela dela. O painel ordenava as avaliações por id e truncava a lista
> inteira no mínimo por avaliador — uma designação manual, que por definição vem depois da
> distribuição, caía fora do corte. Agora a fila é partida antes de cortar: **Designados pela
> organização** (a manual e a que ele já abriu) fica **acima**, com moldura própria e **nunca
> truncada**; o limite manda só na fila automática, que é a que o sorteio troca.
> (g) **Sprint 116** — merge das **20 branches do dependabot**, a mais nova de cada pacote (as
> superadas entraram com `-s ours`, para os PRs fecharem sem reabrir versão vencida). Dois majors:
> **jsdom 29 → 30** (que exige Node `^22.22.2 || ^24.15.0 || >=26` para rodar `npm test`) e
> **concurrently 9 → 10**. A **família TipTap subiu junta** para 3.31.3: o bump isolado do
> `@tiptap/pm` quebrava os peers, porque o `@tiptap/core` fixa o `pm` na versão exata dele.
> Back **892/892**, front **422/422**, Pint limpo, build OK.
>
> **Sprints 108–109 (mesma branch):** os dois números da avaliação ficaram independentes, e o
> balcão ganhou rascunho.
> (a) **Sprint 108** — as Sprints 106–107 deixaram os dois campos se atropelando: *designações* era
> presa ao *máximo por projeto* e a trava do início olhava o *mínimo*. Agora cada um responde a uma
> pergunta só. **Avaliações por projeto** (Parametrização) é quantas avaliações o projeto pode
> **receber**: o mínimo é a cobertura que a feira precisa (faltantes, ranking parcial) e o **máximo
> é o teto** — atingido, contando concluídas **e** em andamento, quem ainda não começou perde o
> projeto ao clicar em iniciar. **Designações por projeto** (Algoritmo) é quantos avaliadores ficam
> com ele **na lista**, e **pode passar do máximo de propósito**: é a sobra que impede um avaliador
> que não abre a avaliação de deixar o projeto devendo. O único piso é o mínimo — designar menos
> gente do que a cobertura exige a tornaria impossível. Em branco, designações segue o **máximo**,
> que era quem cumpria esse papel antes do campo existir; por isso a distribuição em massa passou a
> designar **5** por padrão, e não mais 3. Junto, tudo que perguntava "quantos cabem na lista"
> trocou de fonte: o teto do `designarUm()`, o filtro de candidatos da fila e a capacidade da área
> na cota justa passaram do máximo de avaliações para as designações.
> (b) **Sprint 109** — o atendimento nem sempre termina de uma vez: o aluno esqueceu o RG no ônibus
> e sai para buscar. Um credenciamento com `finalizado_em` nulo virou **rascunho** — o que já foi
> conferido fica guardado, o projeto continua na fila de *Credenciar* marcado "em rascunho, com
> Fulano", e a ficha é retomada de onde parou. O rascunho tem **dono** (`credenciamentos.iniciado_por`,
> que `credenciado_por` não podia representar: ele só é preenchido na conclusão). Ninguém continua o
> atendimento alheio por cima: a **conta temporária** só retoma o próprio — nem o de outra conta
> temporária —, o **admin permanente** assume o de uma conta temporária **sem cerimônia** (o turno
> dela acaba e o projeto não pode ficar preso, então a troca acontece sozinha no primeiro
> salvamento) e o de **outro admin permanente** só depois de *Assumir atendimento* com
> **justificativa**. As duas trocas de mãos entram em Registros → Credenciamento
> (`credenciamento_rascunho_assumido`) com o "de → para".
> Back **843/843**, front **413/413**, Pint limpo, build OK.
>
> **Sprints 106–107 (mesma branch):** a distribuição passou a designar mais gente do que o
> necessário, e a disputa é resolvida na hora de iniciar.
> (a) **Sprint 106** — a distribuição em massa parava no **mínimo por projeto** (padrão 3): o alvo
> dela era a cobertura que a feira precisa. Designar exatamente o necessário, porém, deixa o
> resultado nas mãos de quem não abre a avaliação — 3 designados, 1 avaliação feita. Agora o alvo é
> **número próprio**, definido no **Algoritmo de distribuição**: um valor geral da edição
> (`edicoes.designacoes_por_projeto`) e, por categoria, um campo a mais na tabela de regras
> (`distribuicao_regras[cat].designacoes`, a tela onde ele é editado). Em branco, os dois seguem o
> mínimo por projeto — o comportamento de sempre, então nada muda para quem não configurar. Quem
> resolve o número que vale é o `LimitesAvaliacao::designacoesPorProjeto()`, que o prende entre o
> **mínimo** (abaixo dele o projeto nasceria sub-coberto de propósito) e o **máximo por projeto** da
> categoria, que continua sendo o teto duro de quem enxerga o projeto. Dois efeitos colaterais
> tratados: o relatório de **sub-cobertura** passou a olhar só o mínimo — não alcançar o alvo de
> designações, com a cobertura fechada, é sobra que não coube, não falta —, e o "precisa" da
> **fila do avaliador** passou a perseguir o mesmo alvo, para a fila e a distribuição em massa não
> quererem números diferentes.
> (b) **Sprint 107** — com sobra de designados, o `iniciar` virou o ponto onde a disputa se resolve.
> Antes de abrir, o servidor conta as avaliações do projeto **concluídas + em andamento** (quem já
> abriu está ocupando uma vaga; contar só as concluídas deixaria três pessoas trabalhando no mesmo
> projeto para nada) e compara com o **mínimo da categoria**. Batido o número, a designação é
> **devolvida ao bolo**, a **fila é reposta na hora** e o avaliador recebe o aviso de que outro
> chegou antes — a lista volta já sem o projeto e com o substituto no lugar. A resposta é um **409**
> com `code: PROJETO_JA_COBERTO` (`ProjetoJaCobertoException`), e não um 422: não há nada de errado
> no que ele mandou nem o que corrigir e tentar de novo. A **designação manual do admin** passa por
> cima da trava, como passa por cima das demais regras — quem a fez sabe que está pondo mais um
> avaliador ali. O projeto **não** some da lista sozinho antes do clique: sumir sem explicação é
> pior do que o aviso.
> Back **836/836**, front **410/410**, Pint limpo, build OK.
>
> **Sprints 104–105 (mesma branch):** nasceu a aba **Almoxarifado**, o balcão de guarda da feira.
> (a) **Sprint 104** — a aba, o escopo e o caminho de entrada. `AbaAdmin::Almoxarifado` é uma aba
> como as outras, então quem abre é quem tem um escopo que a lista — e o admin **sem escopo nenhum
> continua com acesso total**, como em todas as demais: fazer desta a exceção trancaria para fora a
> primeira pessoa a configurar os escopos. Ela só opera dentro da **janela do evento**
> (`edicoes.evento_de`/`evento_ate`), a mesma do credenciamento, e fora dela abre em leitura; a
> conta demo tem o *modo de teste*, que ignora as datas, usa a **lista final demo** e **isola os
> registros do ensaio** (`almoxarifado_guardas.demo`) — treinar aqui nunca devolve o material de um
> finalista de verdade. Quem pode deixar material são os **finalistas da lista vigente**. O
> assistente tem **quatro passos** na ordem da conversa no balcão: projeto → quem está deixando
> (sempre alguém do projeto) → os itens, **um por linha** → a **conferência**, que repete tudo com
> data e hora para o admin ler em voz alta antes de gravar; cada bloco do resumo tem um *Alterar*
> que volta ao passo dele sem perder o resto. O item é a unidade porque é o item que é retirado:
> guardar só uma contagem impediria a retirada parcial de dizer o que saiu.
> (b) **Sprint 105** — o que acontece depois. **Retirada completa** leva tudo o que ainda está
> guardado; **parcial**, só o que for marcado — e as duas gravam **quem levou e quando**, escolhido
> de novo a cada retirada, porque quase nunca é quem deixou. **Editar** troca o responsável e a
> composição dos itens com **justificativa obrigatória**; item **já retirado** não é apagado nem
> renomeado, que seria reescrever uma entrega concluída. **Excluir** é **soft delete** com
> justificativa: a linha sai da tela e a trilha continua de pé. As quatro ações entram na seção nova
> **Registros → Almoxarifado**. E o balcão ganhou **contas temporárias próprias**: a coluna
> `contas_temporarias.setor` diz a que aba a conta pertence, `User::abasPermitidas()` devolve
> exatamente essa aba, e cada uma cadastra, renova e encerra **só as suas** — a conta do
> credenciamento não abre o almoxarifado e vice-versa. As contas que já existem nascem
> `credenciamento` e não mudam de comportamento. A tela é a mesma, parametrizada pelo setor.
> Back **823/823**, front **406/406**, Pint limpo, build OK.
>
> **Sprints 102–103 (branch `feat/emails-credenciamento-almoxarifado`, saída da `origin/main` @ `732656a`):**
> (a) **Sprint 102** — o comunicado da mala direta chegou às caixas de entrada com as tags à
> mostra (`<b>`, `<strong>`). A causa é a mesma da Sprint 93 — um `queue:work` com a classe do
> Mailable **antiga** em memória renderizando a view **nova**, lida do disco a cada envio —, mas o
> sintoma mudou: o remendo daquela vez fez a view não quebrar, e ela passou a cair no ramo de texto
> puro, que **escapa** o corpo. Trocou-se um erro barulhento por um defeito silencioso. Agora
> **nenhuma view depende das chaves do `with`**: o formato sai de `$mala->formato` (ou de
> `$formato`, no transacional) e o corpo, da propriedade pública `$corpo` — as duas chegam sempre,
> seja qual for a versão da classe carregada. As chaves do `with` viraram atalho (o callback que
> embute as imagens por CID, o texto já convertido), e faltando qualquer uma o e-mail sai
> **formatado assim mesmo**, no máximo sem imagem. O `MensagemTransacional` parou de sobrescrever
> `corpo` no `with`, e o estilo do bloco do código de 6 dígitos virou `HtmlEmail::ESTILO_DESTAQUE`,
> compartilhado entre o Mailable e a view. Os quatro caminhos de envio foram auditados: mala
> direta, transacional (confirmação de cadastro, projeto submetido, convite de feedback) e o alerta
> de conversas pendentes. Os testes que existiam só verificavam que o **texto** aparecia — passavam
> com as tags escapadas; os novos verificam que `<strong>` chega **como marcação**.
> Junto veio a **conferência antes do disparo**: a caixa *Enviar e-mail de teste para:* com os
> endereços digitados na hora (até 10). O teste cria uma mala marcada `teste` e percorre **a mesma
> fila** do disparo de verdade — um teste renderizado na hora diria que está tudo bem justamente no
> caso que motivou o recurso. Ele **não consome o envio**: os arquivos são **copiados** para a mala
> de teste (linha nova, mesmo arquivo em disco) em vez de vinculados, senão o disparo seguinte
> encontraria imagens e anexos já tomados e sairia sem eles. A mala de teste fica fora da lista de
> disparos, mas o relatório dela abre pelo id e diz o que aconteceu com cada endereço.
> (b) **Sprint 103** — o balcão passou a registrar **quem apareceu** e **quem levou o kit de quem**
> (`credenciamento_pessoas`). Marcar alguém como *Faltou* dispensa os documentos daquela pessoa —
> não se confere o RG de quem não veio — e **não impede** credenciar o projeto: ele fica credenciado
> *com pendências*, que a lista mostra, e quem chegar depois é conferido na mesma ficha. Marcar
> ausente **apaga** o que já tinha sido conferido daquela pessoa, senão a ficha ficaria dizendo que
> o documento de um ausente está presente. Os rótulos são *Compareceu/Faltou* de propósito:
> *Presente/Ausente* já são os rótulos de cada documento no mesmo cartão. O **kit é por pessoa**,
> mas sai no nome de **um responsável**, que precisa ser gente do projeto — um aluno leva o dele e o
> de dois colegas —, e quem sobrou retira depois, com outro responsável e outro horário; por isso o
> relógio e o nome de quem levou ficam em **cada linha**. Kit já retirado não muda de dono. Com o
> credenciamento ainda em aberto a retirada sai junto da conclusão; já concluído, ela tem
> **botão próprio** e **não passa pelo `podeAlterar()`** — ela só acrescenta, e uma conta temporária
> de balcão precisa poder entregar no turno seguinte o kit que outro turno não entregou. Corrigir e
> cancelar continuam restritos como na Sprint 97. Cada retirada vira um registro
> (`credenciamento_kit_retirado`) com quem levou, de quem e quando; a ausência entra no registro do
> próprio credenciamento.
> Back **802/802**, front **396/396**, Pint limpo, build OK.
>
> **Sprints 93–94 (branch `feat/ajustes-distribuicao-designacoes`, saída da `origin/main` @ `385833b`):**
> dois defeitos relatados em produção.
> (a) **Sprint 93** — o disparo de **242 destinatários** falhou em **todos** eles com
> `Undefined variable $html (View: emails/mala-direta.blade.php)`. A causa é operacional: o
> `queue:work` carrega as classes PHP **uma vez** e fica com elas em memória, enquanto o Blade é lido
> do **disco a cada envio** — o worker estava com o `MalaDiretaMensagem` anterior à v1.18 (que ainda
> não mandava `html`/`textoSimples`) renderizando a view nova. O código não impede um worker velho,
> mas a view deixou de depender de chave nenhuma do `with`: `$mala` e `$corpo` são propriedades
> **públicas** do Mailable e chegam sempre, então na falta de `paragrafos` a própria view quebra o
> corpo em parágrafos. `docs/DEPLOY_AWS.md` ganhou o aviso de que o restart do worker (ou
> `php artisan queue:restart`) não é opcional. **Os 242 e-mails não saíram** — depois do deploy, use
> *Reenviar falhas* no relatório da mala.
> (b) **Sprint 94** — a caixa **"Quem você quer ouvir"** aparecia **vazia** para alguns admins. O
> servidor sempre devolve os 8 públicos; quem apagava a lista era a tela: um `.catch` engolia a falha
> de `GET /admin/feedbacks/opcoes` e substituía tudo por `publicos: []`, sem uma palavra. O caso mais
> provável em produção é o 403 do escopo (`aba:comunicacao`) para quem teve o escopo trocado com a
> sessão aberta — o menu ainda mostra a aba porque veio do login. Agora a tela **mostra o motivo**
> com um botão *Tentar de novo*, e uma lista vazia vinda do servidor também é avisada.
>
> **Sprint 101 (mesma branch):** liberar **áreas e subáreas extras** para um avaliador já existia
> desde a Sprint 38, mas o botão era o terceiro da fileira de ações e ninguém o achava. Ele passou a
> **abrir a linha**, e o botão de **demo** — que ocupava o primeiro lugar com ícone + rótulo — virou
> **só o frasco**, no mesmo padrão dos demais. Nada mudou no backend; o texto da tela agora explica
> os dois ícones. Back **785/785**, front **392/392**, Pint limpo, build OK.
>
> **Sprints 99–100 (mesma branch):** o avaliador voltando ao que já enviou.
> (a) **Sprint 99** — **busca** na aba *Avaliados*, por título ou área. O filtro é na própria tela:
> o histórico é pequeno por construção (o certificado tem teto de 120h, ~48 avaliações) e ir ao
> servidor a cada tecla não pagaria a viagem. A busca vale só nessa aba — a fila de trabalho tem o
> tamanho do mínimo por avaliador.
> (b) **Sprint 100** — **editar o parecer final** de uma avaliação já enviada: só as **duas
> recomendações escritas** (vídeo e projeto). As 17 respostas da rubrica e a conferência de
> área/subárea ficam de fora de propósito — mexer nelas mudaria a nota e o ranking depois de
> fechados. Cada campo alterado exige **justificativa** e vira registro
> (`avaliacao_parecer_editado`, autor = o próprio avaliador), porque é esse texto que o orientador
> lê na aba Ajustes. A edição segue o mesmo portão de escrita do resto: `podeAvaliar()`, então
> encerrado o período o botão some e o servidor responde 403.
>
> **Sprints 97–98 (mesma branch):** o admin corrigindo o que já foi fechado.
> (a) **Sprint 97** — o balcão ganhou **Cancelar credenciamento** (apaga a conferência e devolve o
> projeto à fila de *Credenciar*, com justificativa obrigatória) e uma regra de quem pode desfazer:
> mexer no credenciamento **de outra conta** exige **admin permanente**. A conta temporária continua
> corrigindo e cancelando **o que ela mesma registrou** — ela existe para atender o turno dela, não
> para revisar o alheio. A regra é uma só (`podeAlterar()`) e vale tanto para regravar quanto para
> cancelar; a ficha abre em leitura, com o motivo, para quem não pode. O registro do cancelamento é
> gravado **antes** do delete: ele precisa de quem credenciou e de quando, e isso some junto com a
> linha. Tipo novo: `credenciamento_cancelado`.
> (b) **Sprint 98** — o diálogo **Editar projeto** passou a trocar **quem é** o orientador, e não só
> a classificação. Trocar o orientador troca o **dono** (`projetos.user_id`), então a escolha é
> entre contas de orientador **ativas** e a tela avisa que o anterior perde o acesso; a busca é no
> servidor (nome ou e-mail, 20 resultados) porque a base é grande demais para viajar inteira. O
> **coorientador** é outra história: não tem conta, é uma linha de dados do projeto, então dá para
> **editar, incluir e remover** — um registro por campo alterado, com o rótulo do campo abrindo a
> frase. CPF e telefone chegam com máscara e são gravados só com dígitos, como no formulário do
> orientador. Tipos novos: `projeto_orientador` e `projeto_coorientador`.
>
> **Sprints 95–96 (mesma branch):** a distribuição parou de premiar quem chegou primeiro.
> (a) **Sprint 95** — relato do Pedro: avaliadores com 6 projetos e colegas com nenhum. Duas causas,
> duas correções. A `DistribuicaoService::distribuir()` passou a repartir **em rodadas iguais** —
> um teto de carga que sobe uma unidade por passada sobre a lista inteira —, o que também conserta
> um caso antigo: a preferência por **subárea** era comparada ANTES da carga, então o especialista
> levava todos os projetos da subárea dele e o generalista da mesma área ficava a zero. E a
> `FilaAvaliadorService` ganhou a **cota justa**: o alvo da fila é o mínimo por avaliador **limitado**
> por (o que a área comporta ÷ avaliadores que a atendem), divisão para baixo e nunca menor que 1.
> Com projeto de sobra a cota estoura o mínimo e desaparece — o comportamento de sempre; com projeto
> escasso ela reparte. O bolo é a **capacidade** da área (máximo de avaliadores por projeto), não o
> que ainda falta cobrir: fosse dinâmico, uma redistribuição encolheria a fila de todo mundo só
> porque a cobertura já estava feita.
> (b) **Sprint 96** — nova tela **Designações**. Uma tabela com tudo que está na mão de cada
> avaliador e **há quanto tempo** (concluída congela o relógio no fim da avaliação, não no "agora").
> O admin marca e **retira**; cada projeto retirado é redesignado na hora pelo
> `DistribuicaoService::designarUm()` — mesmas prioridades da distribuição, e **nunca de volta para
> quem acabou de sair**. *Em avaliação* pode ser retirada e o rascunho vai junto (a tela avisa em
> quantas linhas isso vai acontecer); *concluída* nem marca. A retirada respeita os limites, então
> quando ninguém cabe o projeto fica sem designação e a resposta diz quais foram — a saída é a
> designação manual, a única que passa por cima. Tipo novo na trilha:
> `avaliacao_designacao_retirada`.
>
> Um teste antigo (`JanelaInscricoesTest`) quebrou sozinho com a passagem do calendário — usava a
> data fixa `2026-09-01` para "ainda não abriu". Virou data relativa.
>
> **Sprints 87–92 (branch `feat/credenciamento-demo-e-menu`, saída da `origin/main` @ `7293ec5`):**
> ciclo de ensaio do balcão, contas de treinamento e ajustes de tela. Um commit a cada duas sprints.
> (a) **Sprint 87** — a janela das **contas temporárias** passou a ser medida em **horas** (padrão
> **5**, o tamanho de um turno de balcão) e ganhou um **início agendável** (`valido_de`): o admin
> cadastra a equipe inteira dias antes e cada acesso abre sozinho na hora marcada. Antes do início a
> conta existe, aparece como *agendada* e o **login é recusado com a data** — ela **não** é
> desativada nesse intervalo, porque quem a bloqueia é a janela, e desativar a faria parecer
> encerrada na lista. As horas contam **a partir do início**, não do cadastro, então "5 horas a
> partir das 8h de sábado" é exatamente o que se digita; início no passado é lido como "vale desde
> já". Renovar virou também o caminho de **reagendar**. `venceuParaLogin` deu lugar a
> `impedimentoDeLogin`, que devolve a mensagem certa para cada uma das duas situações.
> (b) **Sprint 88** — o balcão ficou **ensaiável**. `listas_finais.demo` abre uma **segunda trilha**
> de lista final: a oficial e a de demonstração convivem, cada uma com a sua vigente, e publicar uma
> **não encerra a outra**. O **modo demo** da aba Credenciamento — que já existia e ignorava as datas
> — passa a **trocar entre elas**. O ensaio não alcança finalista de verdade nem forçando `?teste=1`:
> a ficha de um projeto oficial responde 404 em modo demo, e a de um projeto de demonstração responde
> 404 fora dele. `php artisan demo:credenciamento` monta os dados (orientador demo, três projetos com
> equipe e séries, a lista demo e — **só se o catálogo estiver vazio** — a lista de documentos
> padrão). Documentado em [docs/DEMO_CREDENCIAMENTO.md](docs/DEMO_CREDENCIAMENTO.md).
> (c) **Sprint 89** — **Administradores → Contas demo**. O modo demo é a permissão de treinamento do
> portal, mas só dava para ligá-lo em **administradores** (na lista da própria aba) e em
> **avaliadores** (em Avaliadores Online): o **orientador** só nascia demo por linha de comando — e é
> ele quem precisa da aba Ajustes fora do período. A seção nova lista **as contas já marcadas** quando
> não há busca ("quais contas de treinamento existem?") e procura em toda a base de orientadores e
> avaliadores quando há. `GET/PATCH /admin/contas-demo`; a conta de admin é recusada ali de propósito,
> porque o interruptor dela é a linha acima.
> (d) **Sprint 90** — o diálogo **Editar projeto** (Projetos submetidos) mostra a **série de cada
> aluno**, logo abaixo da categoria e **só para leitura**. É a série que diz se a categoria está certa
> — FETEC Jr é do fundamental, FETECMS e FUNDECT do médio —, e conferir uma sem a outra é o caminho
> para corrigir errado. O rótulo sai do servidor (`ClassesEscolares::serieLabel()`), então o técnico
> integrado aparece com nome próprio mesmo contando no card do médio; os alunos vão junto da linha
> (eager load) para o diálogo não abrir com um N+1.
> (e) **Sprint 91** — **Modelos de e-mail** ganharam o **editor rico da mala direta**: negrito,
> itálico, sublinhado, traçado e listas, **sem imagens e sem anexos** (estes e-mails são
> transacionais e curtos, e não há arquivo para subir). `modelos_email.formato` distingue o corpo do
> editor (`html`, **sanitizado na gravação** por `HtmlEmail`) do texto puro de sempre (`texto`, o
> padrão) — nenhum e-mail muda de aparência sozinho, só o que o admin reescrever. O texto guardado
> antes disso **abre já em parágrafos** no editor, e o **código de 6 dígitos continua saindo no bloco
> grande**, agora achado dentro do próprio HTML (`HtmlEmail::destacar()`).
> (f) **Sprint 92** — Parametrização → **Ordem do menu** (`/admin/parametrizacao/abas`). A ordem das
> abas era a do enum, isto é, a ordem em que elas foram nascendo no código — que não acompanha o
> calendário: no mês do evento o Credenciamento deveria abrir o menu; na inscrição, Projetos. Agora
> ela é **da edição** (`edicoes.ordem_abas`), como os prazos e os limites, e vale no menu lateral e na
> Home. A tela reordena arrastando **ou** pelas setas (teclado e celular). A ordenação é de
> **apresentação**: `abasPermitidas()` continua sendo o conjunto que autoriza e o middleware `aba:` só
> pergunta se a aba está lá, então **mudar a ordem nunca muda o acesso**. Aba que a ordem salva não
> cita entra **no fim**, então acrescentar uma aba no código nunca a faz sumir do menu de quem já
> configurou. Não vai para a trilha de Registros: é preferência de tela, e a seção de Registros existe
> para o que muda o resultado da feira.
> Back **751/751**, front **376/376**, Pint limpo, build OK.
>
> **Sprints 74–86 (branch `feat/rbac-dashboards-feedback`, saída da `origin/main` @ `04410ee`):**
> ciclo de acesso, painéis e escuta. Um commit a cada duas sprints.
> (a) **Sprint 74** — o erro relatado em produção ao salvar o período de ajustes. Os helpers
> `definirInicioAjustes`/`definirFimAjustes` desembrulhavam a resposta (`r.data.data`), mas o
> `CampoDataCard` faz `onSalvo(resp.data)`: o card recebia `undefined` e o render seguinte estourava
> em `config.liberada_em_input`. Os dois passam a devolver o envelope inteiro, como as datas da
> avaliação sempre fizeram, e a regressão é coberta no nível do contrato (`lib/admin.test.js`).
> (b) **Sprint 75** — os cards de **camiseta** e de **alunos por série** deixam de mostrar o balde
> **"N.I."**. A remoção é na fonte (`Camisetas`/`ClassesEscolares`); o número grande continua sendo o
> total de pessoas, então a soma da quebra pode agora ficar abaixo dele.
> (c) **Sprint 76** — **RBAC**. A *rule* é a aba (`AbaAdmin`), o *role* é o escopo, e cada admin
> passa a carregar **um ou mais** escopos por edição, abrindo a **união** das abas. A nomenclatura de
> código segue "escopo"/"aba" porque `Role` já nomeia orientador/avaliador/admin. A única mudança de
> esquema é a unicidade de `admin_escopos` — (user, edição) vira (user, edição, escopo) —, sem
> backfill. `PUT /admin/admins/{admin}/escopos` (corpo `escopo_ids`) substitui o antigo `/escopo`, e
> o seletor único da aba Administradores virou um grupo de chips.
> (d) **Sprint 77** — `/admin` vira a **Home**: um botão por aba que os escopos liberam. A aba
> Projetos foi para `/admin/projetos`. A lista de abas saiu do `AppShell` para `lib/abasAdmin.js`,
> compartilhada com a Home.
> (e) **Sprint 78** — nova aba **Dashboards** (`AbaAdmin::Dashboards`), com todos os cards do painel
> agrupados por assunto: Projetos, Pessoas, Camisetas, Alunos por classe escolar e Localidades. Ali o
> card "Projetos (total)" é só número. `GET /admin/dashboard` responde às duas abas.
> (f) **Sprint 79** — a aba **Projetos** fica com seis cards. Os componentes saíram para
> `components/CardsPainel.jsx`; o `verMais` mora na descrição de cada card, e é por isso que o mesmo
> total aparece com atalho numa tela e sem atalho na outra.
> (g) **Sprint 80** — Parametrização → **Datas e períodos**: inscrições, avaliação, ajustes e evento
> na ordem em que a feira acontece. As telas de origem ficaram com o que não é data; a tela
> "Inscrições" era só datas e virou um redirecionamento.
> (h) **Sprint 81** — **modo demo por admin** (`PATCH /admin/admins/{admin}/demo`), o mesmo
> interruptor dos avaliadores. A liberação é em duas etapas de propósito: o modo demo faz aparecer o
> "modo de teste" na tela, e é ele que ignora as datas.
> (i) **Sprint 82** — **contas temporárias de credenciamento**. Cadastro de admin + CPF, curso e
> prazo; a conta é `role = admin`, e o que a restringe é a linha em `contas_temporarias`:
> `abasPermitidas()` devolve só "credenciamento", por cima de qualquer escopo. Vencida, é
> **desativada, não apagada** — renovar é só um prazo novo. A varredura roda ao listar e no login, e
> **uma conta temporária não administra contas temporárias** (senão renovaria o próprio prazo).
> (j) **Sprint 83** — `php artisan demo:ajustes` monta o **projeto-exemplo** da aba Ajustes
> (orientador demo, avaliador demo, projeto submetido e avaliação concluída com sugestões);
> `docs/DEMO_AJUSTES.md` traz o SQL equivalente para produção. Junto, `Projeto::semDemo()` tira os
> projetos de orientador demo do painel, do ranking, da lista final e da distribuição automática — as
> listagens operacionais continuam mostrando tudo.
> (k) **Sprint 84** — **Distribuir/Redistribuir vão para a fila**: o POST responde 202 com o registro
> da rodada (`distribuicoes`) e a tela desenha a **barra de progresso** por polling. Duas rodadas
> simultâneas são recusadas, e reabrir a tela retoma o acompanhamento.
> (l) **Sprint 85** — **piso da fila do avaliador** (`edicoes.piso_fila_avaliador`, padrão 6). A
> regra por categoria continua escolhendo quem entra primeiro; só quando ela deixa alguém abaixo do
> piso é que uma segunda passada completa a fila **ignorando-a**. Vale na distribuição, na reposição
> e no "Sortear outros projetos". O campo ficou na tela do Algoritmo de distribuição, junto da regra
> que ele corrige.
> (m) **Sprint 86** — **Comunicação → Feedback**. O admin monta um questionário (alternativas
> escritas ou de um modelo pronto de `App\Support\ModelosAlternativas`; dissertativas com limite em
> palavras ou caracteres), escolhe os **públicos** (os mesmos da mala direta) e dispara: um job por
> destinatário, com relatório por endereço e reenvio das falhas. Quem é alcançado vê um **balão** ao
> entrar — fechar dispensa de vez, mas o questionário continua acessível pela lista do perfil. As
> **respostas são anônimas por construção**: `feedback_participacoes` sabe quem respondeu,
> `feedback_respostas` guarda o conteúdo **sem `user_id`**, agrupado por um `envio` aleatório. Os
> resultados mostram a contagem de cada alternativa (inclusive as zeradas) e os textos sem autor,
> com export CSV. O convite usa o modelo `feedback_solicitado`, editável em Modelos de e-mail.
> Back **713/713**, front **353/353**, Pint limpo, build OK.
>
> **Sprints 72–73 (mesma branch):** nasceu a aba **Comitê especial**, com o deslocamento das
> equipes durante a feira.
> (a) **Sprint 72** — **Transporte de comitê**. O botão *Habilitar localização* abre um assistente
> de **seis passos** — quantas pessoas, os nomes (opcionais, com a área de cada uma), o meio de
> transporte, o ponto de partida, o destino + o tempo com o localizador ligado, e a permissão de
> localização do aparelho. Ligado, o navegador acompanha a posição e a envia **a cada 5 segundos**
> junto da estimativa de rota calculada ali mesmo; a tela mostra o ponto no mapa, o caminho
> recomendado, a **previsão de chegada** e a **próxima orientação**. Uma sessão ativa por pessoa
> (ligar de novo encerra a anterior), e o tempo pode ser esticado ou encurtado depois. **Privacidade**:
> guarda-se a última posição e o trajeto vivo; desligar — à mão ou pelo vencimento — **apaga o
> trajeto**, e a faxina das sessões vencidas roda em cada leitura do mapa, sem depender de agendador.
> (b) **Sprint 73** — **Mapa do comitê**: todos os grupos ligados, com leitura de 5 em 5 segundos
> (polling; não há WebSocket no projeto). Clicar num ponto abre **quantas pessoas, os nomes, as
> áreas, a distância aproximada e o tempo até o destino**, e o painel acompanha o ponto enquanto ele
> se move. Quem está ligado mas ainda sem posição é contado à parte, não some da tela.
> **Google Maps**: mapa, autocomplete (Places) e rota (Directions) usam a chave
> `VITE_GOOGLE_MAPS_API_KEY`, lida **no build** do front. **Sem a chave nada quebra** — o mapa é
> substituído por um aviso dizendo o que falta e o campo de endereço vira texto livre —, mas o
> Pedro precisa configurá-la e rodar `npm run build` para o recurso funcionar de verdade; o mapa
> real ainda não foi validado em navegador aqui.
> Back **661/661**, front **300/300**, Pint limpo, build OK.
>
> **Sprints 70–71 (mesma branch):** nasceu a aba **Credenciamento**, o balcão do evento.
> (a) **Sprint 70** — duas seções sobre a mesma lista pesquisável: **Credenciar** (pendentes) e
> **Credenciados** (quem já passou), com filtro por área e categoria. **Finalista é quem está na
> lista final vigente** — sem lista oficial a tela avisa que não há ninguém para credenciar. A ficha
> de um projeto monta **cada pessoa × os documentos do papel dela** (alunos, orientador,
> coorientador) e marca **presente / ausente / não necessário** — "não necessário" é decisão
> registrada, diferente de deixar em branco. A lista de documentos é **parametrizável** por papel
> (`documentos_credenciamento`), e o que já foi conferido não pode ser excluído, só desativado. Cada
> credenciamento grava quem atendeu e o horário (`credenciamentos` + `credenciamento_documentos`,
> com nome e papel desnormalizados) e entra na seção nova **Registros → Credenciamento**, listando o
> que ficou ausente. A janela do evento (`edicoes.evento_de`/`evento_ate`) fica **fechada enquanto
> não for definida**, e fora dela a aba abre **em leitura**; o **admin demo** tem um *modo de teste*
> (guardado no `sessionStorage`) que ignora as datas. A aba entrou no enum `AbaAdmin`, então ela
> respeita os escopos da Sprint 67.
> (b) **Sprint 71** — os **horários do atendimento**. O início vem preenchido com o "agora" e é
> editável: **quando o admin o corrige** (lançamento retroativo), o fim passa a ser **início + 5
> minutos** (`CredenciamentoService::MINUTOS_ATENDIMENTO`); quando não mexe, o fim é o instante da
> conclusão. O front só manda `iniciado_em` quando o valor difere do sugerido — é essa diferença que
> sinaliza a alteração. Junto veio a lista de **itens entregues** ao finalista
> (`edicoes.itens_credenciamento`, editável na Parametrização): ao concluir, a ficha mostra o
> lembrete com os itens antes de voltar para a lista.
> Back **645/645**, front **291/291**, Pint limpo, build OK.
>
> **Sprints 68–69 (mesma branch):** a lista final virou um documento vivo.
> (a) **Sprint 68** — a **cota do interior** passou a existir **só na FETECMS FUNDECT**
> (`Categoria::permiteCotaInterior()`): o passo 3 do assistente só mostra as categorias que a
> preveem, e uma reserva enviada para outra categoria é ignorada no servidor. Junto veio a caixa
> **Lista Final Oficial**: marcada, a geração **registra** a lista (`listas_finais` +
> `lista_final_projetos`) como a **vigente** da edição — e os projetos dela, com alunos, orientador
> e coorientador, passam a ser os **finalistas** da feira. Publicar uma nova encerra a anterior;
> várias listas convivem, uma vigente. Sem marcar, nada é registrado: continua sendo só um TXT.
> (b) **Sprint 69** — a composição da lista oficial ficou **editável e auditada**. Em
> `/admin/avaliacao/listas-finais/{id}` o admin **inclui** um projeto avaliado que ficou de fora ou
> **retira** um que está dentro, sempre com **justificativa** (mín. 5 caracteres). Cada alteração
> **sobe a versão** da lista, e o TXT é gerado a partir da composição atual — com a numeração
> `001, 002…` refeita —, então baixar de novo já traz o arquivo novo. Tudo entra na seção nova
> **Registros → Lista final** (`lista_final_oficializada`, `lista_final_projeto_adicionado`,
> `lista_final_projeto_removido`), com o "de → para" e a justificativa. Projeto que entrou à mão
> fica marcado na composição.
> Back **624/624**, front **281/281**, Pint limpo, build OK.
>
> **Sprints 66–67 (mesma branch):** o portal deixou de ser de uma edição só.
> (a) **Sprint 66** — **Edições**. Nasceram `edicoes.padrao` (a edição que vale para quem não
> escolheu nenhuma — cadastro público, e-mails, fila, CLI) e `users.edicao_id` (a que a pessoa está
> vendo). `Edicao::atual()` deixou de ser "a de inscrições abertas" e passou a ser **a edição em
> escopo**: a escolhida pelo usuário autenticado, ou a padrão. Como todo serviço já perguntava por
> ali, trocar de edição troca de uma vez os prazos, os limites e as regras de distribuição. Os
> projetos seguem junto por um **global scope** (`App\Models\Scopes\EdicaoScope`) — que pega de
> carona o `whereHas('projeto', …)` de alunos, anexos e avaliações, então o recorte acompanha sem
> cada consulta precisar saber da edição. Duas válvulas: **sem nenhuma edição cadastrada o escopo
> não filtra nada**, e **projeto com `edicao_id` nulo aparece em todas** (a migration faz o
> backfill, mas o que escapar fica visível para alguém corrigir em vez de sumir). A tela é
> Parametrização → Edições, com criação que **herda a parametrização** da edição atual, troca da
> padrão e exclusão só de edição vazia; o seletor no topo do menu vale para **todos os papéis** e
> recarrega a tela, porque misturar dados de uma edição com ações de outra seria pior.
> (b) **Sprint 67** — **Escopos de admin**. `escopos_admin` (nome + abas) e `admin_escopos`
> (admin × edição × escopo): a mesma pessoa pode cuidar da comunicação num ano e de outra coisa no
> seguinte. As abas são o enum `App\Enums\AbaAdmin`, o menu filtra pelo que vem em
> `UserResource::abas` e o **backend barra de verdade** — as rotas de `/admin` foram reagrupadas
> sob o middleware novo `aba:`, que aceita mais de uma aba para as telas que moram em duas (as
> datas do período de avaliação estão em Parametrização e em Avaliação online). **Admin sem escopo
> na edição tem acesso total**, e uma trava em transação impede deixar a edição sem ninguém ativo
> na aba "Administradores" — é de lá que se conserta qualquer escopo.
> Back **611/611**, front **274/274**, Pint limpo, build OK.
>
> **Sprints 64–65 (branch `feat/edicoes-credenciamento-comite`, saída da `origin/main` @ `c736132`):**
> (a) **Sprint 64** — **Projetos em rascunho**. Em *Projetos por área* nasce o botão **Projetos em
> rascunho**, visível **só depois do prazo de submissão** (`InscricoesService::encerradas()`): antes
> disso o orientador ainda envia sozinho e a organização não entra na inscrição dele. A tela lista
> os rascunhos com dono, equipe e quantas pendências do checklist faltam, e cada linha leva às
> **mesmas telas do orientador** sob `/admin/projetos-rascunho/{id}/…` (as rotas de escrita são as
> do orientador — a Policy já deixa o admin passar e o middleware `inscricoes.abertas` não o barra).
> O botão "Salvar rascunho" some: quem **fecha** o trabalho é a submissão, que passa a exigir
> **justificativa** quando o autor é admin e não é o dono (`POST /projetos/{id}/submeter`). Toda
> alteração que ele faz — campo do projeto, aluno, coorientador ou anexo — vira uma linha na seção
> nova **Registros → Rascunhos**, com o "de → para" e as FKs já resolvidas em nome
> (`AdminRascunhoService`, `TipoRegistro::RascunhoAlteracao`/`RascunhoSubmissao`).
> (b) **Sprint 65** — o painel ganhou **três cards de alunos por classe escolar** (Ensino
> Fundamental I, Fundamental II e Ensino Médio), cada um com a contagem **por série** mais o balde
> **N.I.**, que fecha a soma com o número grande do card (`App\Support\ClassesEscolares`). O
> **técnico integrado é contado no card do Ensino Médio** — é ensino médio na prática e usa os
> mesmos códigos de série, por isso o card vai até o 4º ano. Vale a regra dos demais cards: **só
> projetos submetidos**. De quebra, a `AlunoFactory` passou a gerar `modalidade` e `ano_escolar` em
> **par coerente**, com os mesmos códigos que o formulário grava.
> Back **587/587**, front **261/261**, Pint limpo, build OK.
>
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
> **Pendências do Pedro (Sprints 155–163):** ~~push + PR~~ — **entrou na `main` pelo PR #104** (v1.28).
> As observações de uso continuam valendo: (1) `git push origin feat/finalistas-suporte-e-certificados`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o
> deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem migrations**
> (`contas_temporarias.removida_em`; `projetos.cadastro_manual` e CPF/e-mail **anuláveis** em
> alunos, coorientadores e perfil do orientador; `lista_final_projetos.codigo` + datas do envio em
> `listas_finais`; as tabelas `credenciamentos_fora_prazo` e `suportes_projeto`; `users.cpf`),
> **nenhuma variável nova de `.env`** e **nenhuma dependência nova**. As planilhas usam a extensão
> `zip` do PHP (já exigida pela Sprint 153).
> (2) **Duas abas novas do RBAC**: *Certificados* (admin) — quem tem escopo atribuído não a
> enxerga até você acrescentá-la ao escopo; e *Suporte no evento* (orientador), que aparece para
> todos os orientadores e só tem conteúdo para quem tem projeto na lista final.
> (3) **PDF de etiquetas**: o layout mudou (10 por folha A4, códigos desenhados direto). Vale
> imprimir uma folha e testar no leitor USB e na câmera antes de imprimir as 1.800. Depois de
> **aprovar acompanhantes**, baixe o PDF de novo: eles entram com crachá próprio (papel `S`).
> (4) **Código do projeto**: o envio **fixa** os códigos da lista vigente. Depois disso, ajuste a
> composição por *incluir/retirar* na mesma lista (quem entra ganha o próximo número livre) — **não
> publique uma lista nova**, que nasceria com outra numeração. Vale revisar o texto do modelo
> **"Código do projeto"** em Comunicação → Modelos de e-mail antes de enviar.
> (5) **Cadastro manual**: o orientador criado ali entra por **"Esqueci a senha"** (avise por
> e-mail). Estudante sem CPF fica com "000" nos três dígitos do crachá — o leitor aceita —, mas o
> **CPF faz falta no certificado**: complete quando chegar.
> (6) **Contas temporárias em lote**: as senhas só existem na **planilha de acesso** baixada na
> hora. Quem perder a sua usa "Esqueci a senha". Remover uma conta que já atendeu alguém a
> **arquiva** (o nome fica nos registros, o login morre e o e-mail fica livre).
> (7) **Certificados da organização**: o CPF dos admins nasce em branco — preencha em
> Administradores → editar, antes de exportar o grupo *Organização*.
> (8) **Listas (Sprints 164–165)**: mais uma migration (`listas_finais.tipo`, `origens`,
> `gerada_em`). Depois do deploy, a lista oficial de hoje aparece como **final ativa** e os
> rascunhos antigos como **preliminares** — confira em Avaliação online → Listas preliminares e
> finais. O assistente do Ranking agora começa em **preliminar**: para uma final direto da
> classificação, troque a opção no último passo. **Gerar uma final nova muda os finalistas na
> hora** (credenciamento, mapa, crachás); se os códigos já foram enviados, prefira incluir/retirar
> na final ativa a gerar outra.

> **Pendências do Pedro (Sprints 153–154):** ~~push + PR~~ — **entrou na `main` pelo PR #99**
> (v1.27.2). As observações de uso continuam valendo: (1) `git push origin feat/ranking-download-e-idiomas`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o
> deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem uma migration**
> (`avaliador_profiles.idiomas`), **nenhuma variável nova de `.env`** e **nenhuma dependência
> nova** — o `.xlsx` sai de código nosso, com a extensão `zip` do PHP (confira que ela está
> habilitada no servidor: `php -m | grep zip`).
> (2) **Os idiomas nascem em branco para todos os avaliadores já cadastrados.** O campo é
> obrigatório no cadastro novo e ao salvar o perfil, mas ninguém é bloqueado retroativamente: quem
> já tem conta continua avaliando normalmente e aparece como *Sem idioma informado* na tabela. Para
> fechar o número antes do evento, vale um disparo de mala direta pedindo que atualizem o perfil —
> o público "avaliadores" já existe, e os três públicos por idioma servem para conferir quem já
> respondeu.
> (3) **O idioma não muda a distribuição** — de propósito. Se a organização quiser que ele entre no
> match automático, é outra mudança (e outra conversa: hoje o eixo é área/subárea).
> (4) **O Excel do ranking é um escritor próprio**, pensado para tabela plana. Se algum dia a
> organização pedir planilha com fórmula, gráfico ou mais de uma aba, o caminho é trocar o
> `App\Support\PlanilhaXlsx` por uma biblioteca, não crescê-lo — está escrito no arquivo.
> (5) **Vale abrir um dos quatro arquivos** antes do evento, principalmente o `.xlsx` no Excel de
> verdade: aqui ele foi validado por leitor automatizado e pelos testes, não pelo Excel.

> **Pendências do Pedro (Sprints 148–152):** (1) `git push origin feat/cerimonial-mapa-e-ajustes` + PR
> para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o deploy
> pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem uma migration**
> (`avaliador_profiles.camiseta`), **nenhuma variável nova de `.env`** e **nenhuma dependência
> nova**.
> (2) **O parecer do orientador não fecha mais.** Uma vez aberto o período de ajustes, a aba
> continua acessível para sempre — inclusive das edições passadas, enquanto a pessoa estiver na
> edição em que o período foi aberto. O que encerra no prazo é só a troca de área/subárea.
> (3) **O tamanho de camiseta nasce em branco para todos os avaliadores já cadastrados.** Se a
> encomenda depender desse número, vale um disparo de mala direta pedindo que preencham o perfil —
> o público "avaliadores" já existe.
> (4) **O card novo do painel conta avaliador ativo e não-demo**, e não "avaliador com avaliação
> concluída": quem se cadastrou e não avaliou também vai ao evento. Se a organização quiser o
> recorte mais estreito, é uma linha e eu mudo.
> (5) **As ruas nascem sem nome.** Elas aparecem para batizar em *Editar planta* → *Ruas do
> evento*; o corredor que ficar em branco simplesmente não aparece no desenho. Nomear é salvar uma
> versão nova da planta, como qualquer outra edição dela.
> (6) **A cor do mapa depende do que já existe nas outras abas**: sem lista final publicada não há
> estande alocado, e sem credenciamento/checagem/avaliação registrados tudo fica em "aguardando".
> No dia do evento isso se resolve sozinho.
> (7) O seletor de dia usa a **janela do evento** (Parametrização → Datas e períodos). Sem ela
> definida, o seletor mostra só "hoje" — o mapa funciona igual, mas sem o histórico por dia.

> **Pendências do Pedro (Sprints 148–149):** as migrations desta parte (`credenciais.tipo`, a
> tabela `cerimonial_checkins` e `edicoes.cerimonial_atualizacao_segundos`) vão no **mesmo push**
> das Sprints 150–152, acima — as cinco sprints estão na mesma branch. O resto continua valendo:
> (2) **A aba Cerimonial é do RBAC**: quem já tem escopo atribuído **não a enxerga** até você
> acrescentar "Cerimonial" ao escopo dele (Parametrização → Escopos de admin). Admin sem escopo
> nenhum continua vendo tudo.
> (3) **Antes da cerimônia**: a aba parte da **lista final oficial vigente** e os premiados saem de
> Avaliação presencial → **Credenciais e Prêmios**. Sem lista publicada não há quem receber; sem
> credencial ou prêmio atribuído, os três cards de premiação ficam zerados.
> (4) **A tela de Credenciais mudou de nome e ganhou um campo.** O que está cadastrado hoje
> continua valendo e entrou como **credencial** — o único tipo que existia. Vale revisar se algum
> deles é, na verdade, **prêmio**: a diferença muda o card de *credenciais a separar*, não a lista
> de premiação.
> (5) **A conta temporária do cerimonial só abre o Check-in** — de propósito. Se a organização
> quiser que alguém da porta também veja o painel, a saída é uma conta de admin com o escopo
> Cerimonial, não uma conta temporária.
> (6) **O intervalo de atualização do painel nasce em 30s** e vale por edição. Num evento grande
> vale baixar para 10s; com a tela aberta a tarde inteira num único tablet, subir ou desligar.
> (7) O menu lateral agora **rola**: se a organização usa telas baixas, vale conferir que as abas
> do fim (Registros, Administradores) estão alcançáveis — era esse o relato.

> **Pendências do Pedro (Sprint 147):** ~~push + PR~~ — **entrou na `main` pelo PR #93**
> (`9b35d9f`, v1.26.5). As observações de uso continuam valendo:
> (2) **O aviso depende de a data de fim da avaliação estar preenchida** em Parametrização →
> *Datas e períodos*. Campo em branco significa "a avaliação segue aberta", então o cartão não
> aparece — é a mesma regra que o resto do portal usa para essa data.
> (3) **Ele não segue o modo de teste** da conta demo: o modo adianta a aba, não o calendário.
> Para ver o cartão numa conta de ensaio, marque uma data de encerramento já passada na edição em
> uso — o que vale para todo mundo dela, então prefira uma edição de teste.
> (4) O cartão **some sozinho** quando o período de ajustes termina. Se a intenção for mantê-lo no
> ar depois disso (avisando que o prazo passou), é uma linha e eu mudo.
>
> **Pendências do Pedro (Sprint 146):** (1) `git push origin feat/ajustes-e-pareceres` + PR para a
> `main` (o ambiente do Claude não tem credencial do GitHub). Esta release **não tem migration,
> dependência nova nem variável de `.env`** — é tela e payload.
> (2) **Decisão a confirmar**: o pedido dizia "não mostre para o administrador a nota que o
> projeto recebeu", e a tela em questão é a do **orientador** — foi dela que a nota saiu (na
> tela e no JSON). As telas do **admin** continuam com as notas intactas: Ranking, Designações →
> *Ver notas* e Verificar disparidade. Se o que você queria era tirar a nota de alguma dessas,
> é outra mudança e eu faço na sequência.
> (3) **`/pareceres` deixou de existir** como aba e como rota da API. No portal o endereço antigo
> redireciona para `/ajustes`; se houver algum script ou atalho batendo em
> `GET /api/v1/pareceres`, ele passa a receber 404 — o conteúdo está em `GET /api/v1/ajustes`.
> (4) Para conferir: `php artisan demo:pareceres`, entrar como **`orientador@fetecms.test`** e
> ligar o **Modo de teste** na aba *Ajustes e Pareceres* (agora é uma só). A **nota média** do
> exemplo continua sendo impressa pelo comando, no terminal — é a conferência de que as respostas
> saíram coerentes com os pesos do edital.
>
> **Pendências do Pedro (Sprint 145):** (1) `git push origin feat/demo-pareceres-ajustes` + PR
> para a `main` (o ambiente do Claude não tem credencial do GitHub). Esta release **não tem
> migration, dependência nova nem variável de `.env`** — é um comando de ensaio.
> (2) Para conferir as telas: `php artisan demo:pareceres`, entrar como
> **`orientador@fetecms.test`** e ligar o **Modo de teste** em *Pareceres* e em *Ajustes*. O modo
> de teste depende de a conta estar marcada como demo, e o comando já a marca.
> (3) **Marcar a conta como demo tira os projetos dela dos números** (painel, ranking, lista
> final). Se `orientador@fetecms.test` tiver algum projeto de verdade, desfaça em Parametrização →
> **Dados de demonstração**, ou rode o comando apontando para outra conta
> (`--orientador=ensaio@fetecms.test`).
>
> > **Pendências do Pedro (Sprint 144):** (1) `git push origin feat/avaliar-antes-de-desconsiderar`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o
> deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem migration**
> (`avaliacoes.substitui_avaliacao_id` e `avaliacoes.substituicao_motivo`), **nenhuma variável
> nova de `.env`** e **nenhuma dependência nova**.
> (2) **A substituição feita pela Sprint 142 nunca funcionou em produção**: a escolha de
> substituto não era enviada ao servidor, então todo "desconsiderar" virou "apenas desconsiderar".
> Se algum projeto foi desconsiderado esperando o substituto chegar, ele **ficou sem** — vale
> olhar em Registros → Notas quem foi desconsiderado até aqui e conferir a cobertura desses
> projetos.
> (3) **O fluxo mudou de ordem**: escolher "Eu mesmo avalio agora" **não desconsidera mais na
> hora** — o botão passa a se chamar *Avaliar agora*, a rubrica abre e a nota antiga só sai quando
> a avaliação for **enviada**. Fechar a rubrica no meio não tira nada de ninguém; a avaliação fica
> em rascunho e o cartão da nota mostra *Continuar a avaliação*.
> (4) Enquanto uma substituição está aberta e não enviada, o projeto tem uma avaliação **em
> andamento** a mais em Designações. É esperado: ela vira nota no envio, ou fica esperando o admin
> que a abriu.
>
> **Pendências do Pedro (Sprints 138–143):** (1) `git push origin feat/disparidade-e-auditoria-de-notas`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge,
> o deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem
> migrations** (`avaliacoes.iniciada_em`, as três colunas de `desconsiderada_*` e
> `avaliacoes.pela_organizacao`), **nenhuma variável nova de `.env`** e **nenhuma dependência
> nova**.
> (2) **A duração da avaliação só passa a ser medida a partir do deploy.** O sinal de
> "avaliação relâmpago" ignora tudo que foi concluído antes — a tela mostra "sem registro"
> nessas linhas, em vez de fingir um número. Os outros três sinais valem para a base inteira.
> (3) **Desconsiderar uma nota muda o ranking e a lista final na hora.** Se já houver lista
> final publicada, ela **não** é refeita sozinha: gere uma nova em Ranking → Gerar lista final
> e publique, que a troca fica registrada com justificativa (é o fluxo da Sprint 69).
> (4) **A avaliação preenchida pelo admin** aparece no diálogo de notas como qualquer outra e
> entra na média do projeto. Ela não dá certificado nem posição no ranking de avaliadores — se
> alguém da organização também for avaliador cadastrado, são duas contas diferentes e nada se
> mistura.
> (5) A aba **Identificação de padrões** não acusa ninguém sozinha: ela lista os números. Vale
> combinar com a comissão o que fazer quando um sinal aparece, antes de usá-la no calor do
> fechamento.
>
> **Pendências do Pedro (Sprints 126–137):** (1) `git push origin feat/avaliacao-presencial`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge,
> o deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem
> migrations** (verificações de disparidade, `listas_finais.rascunho`, assinatura nos
> documentos, presencial no avaliador, checagem de estandes, turnos e presença das contas
> temporárias, credenciais e avaliações presenciais), **nenhuma variável nova de `.env`** e
> **nenhuma dependência nova** — a conferência da assinatura usa o OpenSSL que já vem no PHP.
> (2) **A aba Avaliação presencial é do RBAC**: quem já tem escopo atribuído não a enxerga
> até você acrescentá-la ao escopo dele (Parametrização → Escopos de admin). Admin sem escopo
> nenhum continua vendo tudo.
> (3) **A presença passou a valer para todas as contas temporárias.** As que já existem
> entraram como aprovadas, mas as **novas** só abrem a aba depois que alguém aprovar a
> presença na tela de contas do setor. Avise o pessoal do balcão antes do evento.
> (4) **`POST /admin/avaliacao/lista-final` mudou de contrato**: devolve a prévia (201) em
> vez do TXT. Se houver algum script seu batendo nessa rota, o arquivo agora sai de
> `/admin/avaliacao/listas-finais/{id}/arquivo`.
> (5) **Antes do evento**: cadastre os **itens de checagem** do estande e as **credenciais**
> de premiação (as duas listas nascem vazias), escreva as **orientações ao avaliador
> presencial** e confira o **período do evento** — é ele que abre a checagem, o almoxarifado,
> o credenciamento e a avaliação no estande.
> (6) **A conferência da assinatura do gov.br não checa revogação (CRL/OCSP) nem a cadeia
> completa até a raiz da ICP-Brasil** — ela confere que o PDF está assinado, que não foi
> alterado depois e quem assinou. O laudo aparece para a organização; a decisão final
> continua sendo do balcão. Se a exigência for validação forte, vale contratar um serviço
> de validação e trocar só o `App\Support\AssinaturaPdf`.
>
> **Pendências do Pedro (Sprints 117–124):** (1) `git push origin feat/mapa-do-evento`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do
> merge, o deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release
> **tem migrations** (`turnos_apresentacao`, `estandes_projetos`, `mapa_layouts` e as
> colunas novas de `edicoes`), **nenhuma variável nova de `.env`** e **três dependências
> novas de composer** — `dompdf/dompdf` (os PDFs do mapa e das etiquetas),
> `bacon/bacon-qr-code` e `picqer/php-barcode-generator`. Todas são **puro PHP**: não
> exigem a extensão GD, porque tudo sai em SVG. Rode `composer install` no deploy;
> `npm` não mudou.
> (2) **A aba Mapa do Evento é do RBAC**: quem já tem escopo atribuído **não a enxerga**
> até você acrescentar "Mapa do Evento" ao escopo dele (Parametrização → Escopos de
> admin). Admin sem escopo nenhum continua vendo tudo.
> (3) **A ordem das três seções importa**: Turnos → Estandes → Planta. Os estandes
> pedem a lista de turnos, e a planta só mostra ocupação depois dos estandes. Tudo parte
> da **lista final oficial vigente** — sem ela, a primeira tela avisa e não gera nada.
> (4) **A capacidade dos turnos nasce em 0.** Antes da primeira geração, informe quantos
> estandes cada turno tem (na planta de 2026 são **230** nos dois) — senão a geração é
> recusada dizendo que faltam lugares.
> (5) **A planta padrão é a prancha de 03.08.26** (230 estandes). Se a montadora mudar o
> desenho, ajuste na tela e salve: vira a versão vigente e a anterior fica no histórico.
> (6) **Os crachás**: os códigos já existem assim que a lista final é publicada — baixe o
> **PDF de etiquetas** na tela da lista. Vale conferir um código no leitor USB do balcão
> antes do evento (o campo de leitura fica em Credenciamento → Credenciar).
> (7) **`orientador@fetecms.test` virou conta demo** ao rodar `php artisan demo:ajustes`:
> os projetos dela saem do painel, do ranking e da lista final. Para desfazer, use
> Parametrização → **Dados de demonstração** e desmarque a conta.
> (8) O projeto de `orientador.credenciamento@fetecms.test` **não aparece mais** em
> Projetos submetidos nem em Designações — era o vazamento que você relatou. Ele continua
> visível (e apagável) em Parametrização → Dados de demonstração.
>
> **Pendências do Pedro (Sprints 110–116):** (1) `git push origin feat/distribuicao-por-atividade`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o
> deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem migrations**
> (`edicoes.modo_distribuicao`, `edicoes.dias_avaliacao_aberta`, `edicoes.horas_sessao_avaliador`,
> `avaliacoes.atividade_em` e `avaliacoes.devolvida_em`), **nenhuma variável nova de `.env`** e
> **nenhuma dependência nova** além das atualizações do dependabot. O merge do dependabot mexeu no
> `composer.lock` e no `package-lock.json`: rode `composer install` e `npm ci && npm run build` no
> deploy.
> (2) **`npm test` agora pede Node ≥ 22.22.2** (ou ≥ 24.15.0): é o piso do **jsdom 30**, que entrou
> no merge do dependabot. Só afeta quem roda os testes — o build e a produção não usam jsdom.
> (3) **O modo de distribuição nasce em "Total"**, o comportamento de hoje: nada muda até você
> trocar em Avaliação online → Algoritmo de distribuição. Se trocar para **por Atividade**, confira
> antes o **mínimo por avaliador** (é ele que diz quantos projetos cada um recebe ao entrar) e as
> **horas de sessão** (padrão 12h). Nesse modo os botões *Distribuir* e *Redistribuir* ficam
> desabilitados de propósito.
> (4) **O prazo da avaliação aberta nasce em branco, ou seja, desligado.** Ligá-lo (Algoritmo de
> distribuição → Prazos das designações) é o que passa a destravar sozinho projeto preso com quem
> abriu a avaliação e sumiu — hoje isso só sai pela retirada manual em *Designações*.
> (5) **O anexo da mala direta** (Sprint 114): a causa é o arquivo não estar mais em
> `storage/app/private` na hora em que o worker envia. Antes de o próximo disparo com anexo sair,
> confirme que o deploy **preserva `storage/`** entre releases (symlink para um diretório
> compartilhado) e que o `queue:work` roda **na mesma máquina** do upload; com mais de uma máquina,
> aponte o disco para S3. As malas que já saíram sem anexo podem ser reenviadas pelo relatório, em
> *Reenviar falhas*, depois de arrumar isso.
> (6) O relato da avaliadora (Sprint 115) está corrigido: os projetos que você designa à mão agora
> aparecem numa lista destacada, acima da fila comum, e não são mais cortados pelo limite.
>
> **Pendências do Pedro (Sprints 87–92):** (1) `git push origin feat/credenciamento-demo-e-menu` +
> PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o deploy
> pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem migrations**
> (`contas_temporarias.valido_de`, `listas_finais.demo`, `modelos_email.formato` e
> `edicoes.ordem_abas`), **nenhuma variável nova de `.env`** e **nenhuma dependência nova de npm**
> (o editor dos modelos de e-mail é o TipTap que a mala direta já usava).
> (2) **O prazo das contas temporárias virou horas.** As contas existentes não mudam — `expira_em`
> continua o que era —, mas o formulário agora pede horas (padrão 5) e um início opcional. Quem
> quiser uma conta de vários dias digita as horas equivalentes (72, por exemplo) ou usa o
> agendamento.
> (3) **Para ensaiar o credenciamento**: rode `php artisan demo:credenciamento`, marque o admin como
> **demo** na aba Administradores e ligue **Modo demo** na aba Credenciamento. Detalhes em
> [docs/DEMO_CREDENCIAMENTO.md](docs/DEMO_CREDENCIAMENTO.md). Atenção: **com o modo demo ligado o
> balcão não enxerga a lista oficial** — é isso que impede credenciar alguém de verdade por engano.
> (4) **Os modelos de e-mail continuam com o texto que estão** — o formato só vira HTML quando o
> admin salvar no editor novo. Vale abrir um e conferir a formatação antes do primeiro disparo.
> (5) A **ordem do menu** nasce igual à de hoje; mexer nela é opcional e vale por edição.
> **Pendências do Pedro (Sprints 74–86):** (1) `git push origin feat/rbac-dashboards-feedback` + PR
> para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o deploy pela
> §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem migrations** (unicidade de
> `admin_escopos`, `contas_temporarias`, `distribuicoes`, `edicoes.piso_fila_avaliador` e as cinco
> tabelas de feedback). **Nenhuma variável nova de `.env` e nenhuma dependência nova de npm.** A fila
> (`queue:work`) fica **mais importante**: além da mala direta, agora ela roda a distribuição de
> avaliações e os convites de feedback — sem worker, a barra de progresso não anda e os convites não
> saem.
> (2) **A distribuição mudou de forma**: "Distribuir" e "Redistribuir" respondem na hora e trabalham
> em segundo plano. Se a barra ficar parada em "Na fila", o worker é o primeiro lugar a olhar.
> (3) **O piso da fila nasce em 6** para todas as edições. Se a organização quiser que as regras por
> categoria mandem sozinhas (comportamento anterior), basta esvaziar o campo em Avaliação online →
> Algoritmo de distribuição.
> (4) **Escopos de admin continuam opcionais**, agora acumuláveis: sem nenhum atribuído, o admin
> segue com acesso total. A aba nova **Dashboards** é uma permissão à parte — escopos que hoje só
> têm "Projetos" **não abrem** os cards de pessoas e camisetas até receberem "Dashboards" também.
> (5) **Projeto-exemplo dos ajustes**: rode `php artisan demo:ajustes` em produção (ou siga o SQL de
> [docs/DEMO_AJUSTES.md](docs/DEMO_AJUSTES.md)) e entre com o orientador demo para conferir a aba.
> (6) O **modelo de e-mail do feedback** (`feedback_solicitado`) já vem com um texto de fábrica —
> vale revisar em Comunicação → Modelos de e-mail antes do primeiro disparo.
>
> **Pendências do Pedro (Sprints 64–73):** (1) `git push origin feat/edicoes-credenciamento-comite`
> + PR para a `main` (o ambiente do Claude não tem credencial do GitHub) e, depois do merge, o
> deploy pela §11 do [docs/DEPLOY_AWS.md](docs/DEPLOY_AWS.md). Esta release **tem migrations**
> (edição padrão + edição do usuário e backfill dos projetos, escopos de admin, listas finais
> oficiais, credenciamento e localização do comitê) e **uma variável nova de `.env`**:
> **`VITE_GOOGLE_MAPS_API_KEY`** — chave do Google Maps JS API com **Places** e **Directions**
> habilitados, usada pela aba Comitê especial. Ela é lida **no build** (`npm run build`), então
> mudá-la exige recompilar o front; sem ela as telas do comitê abrem e explicam que o mapa está
> indisponível, e **o mapa real ainda não foi validado em navegador** — vale conferir no dia.
> Nenhuma dependência nova de npm. A fila (`queue:work`) continua obrigatória.
> (2) **Configurar a nova aba Credenciamento antes do evento**: em Parametrização → Credenciamento,
> definir o **período do evento** (sem a data de início o balcão fica fechado), os **documentos**
> exigidos de aluno, orientador e coorientador, e os **itens a entregar**. E publicar a **lista
> final oficial** no Ranking dos projetos — é ela que define os finalistas que aparecem no balcão.
> (3) **Escopos de admin são opcionais**: sem nenhum atribuído, todo admin segue com acesso total
> (comportamento de antes). (4) popular as escolas com `php artisan instituicoes:importar` (lê
> `database/data/instituicoes/escolas_ms.csv`; 1888 escolas de MS, todos os 79 municípios casam com
> o catálogo IBGE). As pendências anteriores (`feat/verificacao-email-e-ajustes` e
> `feat/algoritmo-distribuicao-e-lista-final`) já entraram na `main` (v1.18).
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
