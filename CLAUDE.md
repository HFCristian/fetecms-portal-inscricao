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
    **Ajustes** do orientador — **sem data de início ela fica fechada**, ao contrário das outras
    janelas. O "período começou" que trava o cancelamento de submissão e a troca de área do
    avaliador continua sendo só o início (`edicoes.avaliacao_liberada_em`).
  - **Avaliação Online → Algoritmo de distribuição** (`/admin/avaliacao/distribuicao`, aberta pelo
    botão *Abrir configurações* na aba): os limiares que o algoritmo respeita. Por **categoria**, o
    admin liga/desliga a participação e define a **faixa de avaliações que o projeto já recebeu**
    (as concluídas; de/até, "até" em branco = sem teto) para ele ainda aceitar avaliador novo — a
    conta é **por projeto**, não pela carga do avaliador: "FUNDECT de 0 a 1" designa só os projetos
    dessa categoria com nenhuma ou uma avaliação recebida. As regras moram em
    `edicoes.distribuicao_regras` (JSON, via `App\Support\RegrasDistribuicao`) e valem para a
    distribuição em massa, para a reposição da fila e para o sorteio; a **designação manual do
    admin passa por cima**. O **piso da fila do avaliador** (`edicoes.piso_fila_avaliador`, padrão 6)
    é a rede dessa regra: ela continua escolhendo quem entra primeiro, e **só quando deixa alguém
    abaixo do piso** uma segunda passada completa a fila **ignorando-a** — vale na distribuição, na
    reposição ao concluir e no botão *Sortear outros projetos*; em branco, não há piso. Na mesma
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
    Sai o que está **designada** e o que está **em avaliação** (descartando o rascunho — é a única
    forma de destravar, já que o avaliador não desiste sozinho); **concluída nunca sai**. Cada
    retirada entra em Registros → Avaliação Online com o "de → para". `DesignacaoService`.
  - **Avaliação Online → Listas finais oficiais** (`/admin/avaliacao/listas-finais`): as listas
    geradas com a caixa **Lista Final Oficial** marcada ficam registradas. A **vigente** da edição
    é a que define os **finalistas** da feira (projetos + alunos + orientador + coorientador);
    publicar uma nova encerra a anterior. Dentro de cada lista o admin **inclui e retira projetos**
    com **justificativa obrigatória** — cada alteração **sobe a versão**, gera um TXT novo (o
    arquivo sai sempre da composição atual, com a numeração refeita) e entra em **Registros → Lista
    final**. `listas_finais` + `lista_final_projetos`, `ListaFinalService`.
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
  - **Credenciamento** (`/admin/credenciamento`): o balcão do evento, em duas seções —
    **Credenciar** (os finalistas que ainda não passaram) e **Credenciados** (quem já passou), a
    mesma lista pesquisável com filtro por área e categoria. **Finalista é quem está na lista final
    vigente**; sem lista oficial não há quem credenciar. Ao abrir um projeto, o admin confere
    **documento a documento, pessoa a pessoa** (alunos, orientador e coorientador), marcando
    **presente / ausente / não necessário** — a lista de documentos de cada papel é parametrizável.
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
    **não administra outras contas temporárias**. `ContaTemporariaService`.
  - **Parametrização → Credenciamento** (`/admin/parametrizacao/credenciamento`): os **itens
    entregues** aos finalistas (`edicoes.itens_credenciamento`) e a **lista de documentos** exigida
    de cada papel (`documentos_credenciamento`, catálogo do portal). Documento já conferido em algum
    credenciamento não é excluído — desative-o. O **período do evento**
    (`edicoes.evento_de`/`evento_ate`, **fechado enquanto não for definido** — credenciar é ato
    presencial) fica em *Datas e períodos*.
  - **Comitê especial** (`/admin/comite`): o deslocamento das equipes durante a feira, em duas
    seções. **Transporte de comitê** lista quem está com o localizador ligado e o que cada um
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
  - **Registros** tem seis seções: **Inscrições**, **Avaliação Online**, **Lista final**
    (`/admin/registros/lista-final` — publicação da lista oficial e cada projeto incluído ou
    retirado, com a justificativa), **Credenciamento** (`/admin/registros/credenciamento` — quem
    credenciou cada finalista, quando e o que ficou ausente), **Projetos**
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
    `{{tempo_restante}}` e `{{inicio_avaliacoes}}` (`AvisoService::VARIAVEIS`), inseridas por
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
# php artisan demo:ajustes           # projeto-exemplo da aba Ajustes do orientador demo
# php artisan demo:credenciamento     # lista final de demonstração, para ensaiar o balcão
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
