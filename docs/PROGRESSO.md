# Progresso — ciclo de ajustes (agosto/2026)

Registro das sprints deste ciclo. Cada épico é um MVP; cada sprint junta dois épicos
e vira uma branch a partir da `main` (encadeamento cumulativo: a sprint N parte da
branch da sprint N−1, para nada se perder enquanto os PRs não são mergeados).

> **Branch única para o push:** `feat/ciclo-ago2026` contém **todo** o ciclo (sprints 1–5).
> As branches por sprint continuam existindo como histórico, mas basta subir essa.
>
> ```
> git push -u origin feat/ciclo-ago2026
> ```

## Escopo do ciclo

| Sprint | Épicos | Branch | Status |
|--------|--------|--------|--------|
| 1 | **E1** Atualização de dependências (dependabot) · **E2** Login: aviso de bloqueio + contador | `feat/sprint1-deps-login-bloqueio` | ✅ concluída |
| 2 | **E3** Dashboard: card "Projetos por categoria" · **E4** Rubrica de avaliação (3 notas + comentários) | `feat/sprint2-dashboard-rubrica` | ✅ concluída |
| 3 | **E5** Conferência de classificação (área/subárea) · **E6** Rascunho da avaliação | `feat/sprint3-classificacao-rascunho` | ✅ concluída |
| 4 | **E7** Painel de reclassificações sugeridas · **E8** Ranking dos projetos avaliados | `feat/ciclo-ago2026` | ✅ concluída |
| 5 | **E9** Aceitar sugestão por projeto · **E10** Aceite em lote com seleção múltipla | `feat/ciclo-ago2026` | ✅ concluída |

### Decisões travadas (definidas com o Pedro antes de começar)

- **"Categoria" e "subcategoria" na avaliação** = **Área do Conhecimento** e **Subárea**
  (não o enum `Categoria` FETEC Jr/FETECMS/FUNDECT). Usam os mesmos controles dos outros
  formulários: `Select` de áreas do catálogo e `SubareaCombobox`.
- **Nota final da avaliação** = **soma dos 3 quesitos**, escala **0 a 30** (vídeo + resumo +
  projeto de pesquisa, cada um 0–10). A coluna `nota` passa a guardar a soma.
  > **Revisto depois (Sprint 15):** cada quesito virou uma **escala Likert de 5 pontos**
  > (1 = muito insatisfeito … 5 = muito satisfeito), com nota final **3 a 15**, e projetos
  > com documento de continuação ganharam um 4º quesito que entra pela **média** com o
  > projeto de pesquisa. Ver o registro da Sprint 15 no `CLAUDE.md`.
  >
  > **Revisto de novo (Sprint 16):** os 3 quesitos deram lugar às **17 perguntas do
  > documento oficial** ("Perguntas de Avaliação FETECMS"), em 10 seções com **pesos
  > diferentes**, e a nota final passou a **0 a 10**. O quesito do projeto de continuação
  > **deixou de existir**. Ver a seção do ajuste no fim deste arquivo.
- **Dependabot** entrou como Épico 1 da Sprint 1, na própria branch da sprint.
- **Branches cumulativas** entre sprints.

---

## Sprint 1 — Dependências + bloqueio de login

**Branch:** `feat/sprint1-deps-login-bloqueio` (a partir de `origin/main` @ `7a292ff`, v1.10.1)

### E1 — Atualização de dependências (dependabot)

As 9 branches abertas pelo dependabot foram avaliadas e **todas mergeadas** — são
atualizações *patch/minor* dentro das faixas já declaradas no `composer.json`/`package.json`,
sem breaking change:

| Pacote | De → Para |
|--------|-----------|
| `laravel/framework` | 13.17.0 → **13.23.0** |
| `laravel/sanctum` | 4.3.2 → **4.3.3** |
| `laravel/pint` (dev) | 1.29.3 → **1.30.3** |
| `nunomaduro/collision` (dev) | 8.9.4 → **8.9.5** |
| `axios` | 1.17.0 → **1.18.0** |
| `react-router-dom` | 7.17.0 → **7.18.0** |
| `tailwindcss` (dev) | 4.3.0 → **4.3.1** |
| `@tailwindcss/vite` (dev) | 4.3.0 → **4.3.1** |
| `vitest` (dev) | 4.1.8 → **4.1.9** |

Notas:

- As branches antigas de `laravel/framework` (13.16.1 / 13.18.0 / 13.19.0) já tinham sido
  substituídas pela 13.23.0 e sumiram no `git fetch --prune` — não há nada a fazer com elas.
- O merge do `sanctum` conflitou no `composer.lock` (três branches mexendo no mesmo arquivo);
  resolvido regerando o lock com `composer update laravel/sanctum --no-install`.
- Os 5 PRs de npm partiam de uma base mais antiga; depois dos merges rodei `npm install` para
  reconciliar a árvore e commitei o `package-lock.json` resultante.

### E2 — Login: aviso de bloqueio + contagem regressiva

**Problema:** o bloqueio vinha do `throttle:6,1` da rota. O usuário via só *"Muitas requisições
em pouco tempo"*, sem saber quanto esperar — e o limite era **por IP**, então numa escola atrás
de NAT o sexto professor a entrar no mesmo minuto era barrado por causa dos outros.

**O que passou a valer:**

- **Bloqueio por e-mail + IP contando apenas falhas** (`AuthService::MAX_TENTATIVAS = 5`,
  `BLOQUEIO_SEGUNDOS = 60`). Um login válido zera o contador. Enquanto durar o bloqueio, nem a
  senha correta passa.
- **`throttle` da rota de login: 6 → 30/min**, agora só como rede anti-abuso automatizado. O
  controle que importa para o usuário é o de falhas por conta.
- **Resposta 429 informativa:** `{ "message": "Tentativas de login em excesso. Tente novamente
  em 1 minuto.", "retry_after": 60 }` + header `Retry-After` preservado (serve para o app mobile).
- **Tela de login:** painel de bloqueio com o motivo, o tempo necessário, **contador mm:ss até
  00:00** e barra de progresso. O botão fica desabilitado exibindo `AGUARDE 00:47` e, ao zerar,
  volta sozinho com o aviso *"A espera terminou. Você já pode tentar entrar novamente."*
- O contador recalcula a partir do relógio a cada tick, então continua certo mesmo se o
  navegador atrasar o timer com a aba em segundo plano.

**Arquivos principais:** `app/Support/Tempo.php` (novo), `app/Services/AuthService.php`,
`app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Controllers/Api/V1/AuthController.php`,
`bootstrap/app.php`, `routes/api.php`, `lang/pt_BR/auth.php`,
`resources/js/components/ui.jsx` (`useContagemRegressiva`, `formatarMmSs`),
`resources/js/lib/auth.jsx`, `resources/js/pages/Login.jsx`.

### Resultado dos testes

| Verificação | Antes | Depois |
|-------------|-------|--------|
| Backend (PHPUnit) | 218/218 | **232/232** (714 asserções) |
| Frontend (Vitest) | 36/36 | **39/39** |
| Pint | limpo | **limpo** |
| `npm run build` | OK | **OK** |

Novos testes: `LoginTest` (4 de bloqueio), `ErrosPtBrTest` (2 de 429 em pt_BR),
`TempoTest` (9 casos), `Login.test.jsx` (3, incluindo o contador com *fake timers*).

### O que o Pedro precisa fazer

0. **Fazer o push das branches** — este ambiente não tem credencial de push (nem HTTPS nem
   chave SSH autorizada no `HFCristian/fetecms-portal-inscricao`), então as branches das sprints
   estão **commitadas localmente** e aguardando:
   `git push -u origin feat/sprint1-deps-login-bloqueio`
1. **Abrir/mergear o PR** da branch `feat/sprint1-deps-login-bloqueio` na `main`. Ao mergear,
   os 9 PRs do dependabot fecham sozinhos (os commits deles estão na branch).
2. **Conferir a política de bloqueio** — hoje 5 falhas / 60 s. Se achar rígido ou frouxo para o
   público da feira, é só ajustar as duas constantes em `app/Services/AuthService.php`.
3. **Em produção, conferir o `CACHE_STORE`.** O contador de tentativas vive no cache. Com
   `file` (padrão) funciona numa instância só; se um dia houver mais de um EC2 atrás do ALB, o
   limite precisa de um store compartilhado (Redis/banco) para valer globalmente.

### Sugestões (fora do escopo, para você decidir)

- **Bloqueio progressivo:** hoje é sempre 60 s. Dá para escalonar (1 min → 5 min → 15 min) a
  cada rodada de falhas, o que atrapalha bem mais um ataque de força bruta sem incomodar quem
  só errou a senha.
- **Aplicar o mesmo painel de contagem** na tela de "Esqueci minha senha", que também tem
  `throttle:6,1` — o componente e o hook já estão prontos e reutilizáveis.

---

## Sprint 2 — Card por categoria + rubrica de avaliação

**Branch:** `feat/sprint2-dashboard-rubrica` (a partir de `feat/sprint1-deps-login-bloqueio`)

### E3 — Dashboard: card "Projetos por categoria"

Novo card no painel do admin, **no mesmo formato do card de orientadores** (contagens lado a
lado + legenda embaixo) e posicionado **logo antes dele**. Mostra quantos projetos cadastrados
existem em cada categoria da feira: **FETEC Jr**, **FETECMS** e **FETECMS FUNDECT**.

- `AdminDashboardService::porCategoria()` agrupa por `categoria` e devolve **sempre as três
  categorias na ordem do enum**, inclusive as zeradas — o card não "encolhe" no começo da feira.
- Conta rascunho **e** submetido (mesmo critério do card "Projetos (total)").
- ⚠️ **Rascunho ainda sem categoria escolhida não entra em nenhuma coluna**, então a soma das
  três pode ficar abaixo do "Projetos (total)". Se preferir, dá para acrescentar uma coluna
  "Sem categoria" — é só pedir.
- O bloco de contagens do card de gênero virou o componente `Breakdown`, reaproveitado pelos dois.

### E4 — Rubrica de avaliação (3 quesitos + comentários, nota 0–30)

A avaliação deixou de ser uma nota única 1–10:

| Quesito | Nota | Comentários |
|---------|------|-------------|
| Vídeo de apresentação | 0–10, **obrigatório** | opcional |
| Resumo do projeto | 0–10, **obrigatório** | opcional |
| Projeto de pesquisa | 0–10, **obrigatório** | opcional |

- **Nota final = soma dos três (0 a 30)**, calculada no `AvaliacaoFluxoService`. O cliente não
  envia a nota final: um POST tentando forçar `nota: 30` é ignorado (tem teste para isso).
- A coluna `nota` **não mudou de tipo** — `unsignedTinyInteger` vai até 255, então 30 cabe. A
  migration só acrescenta os 6 campos novos da rubrica.
- **Modal do avaliador:** formulário dos três quesitos com total ao vivo ("Nota final 24 de 30"),
  botão de envio liberado só com os três preenchidos, **confirmação antes do envio irreversível**
  e visão em leitura depois de concluída (notas + comentários).
- `AvaliadorHome` passa a exibir **"nota x/30"**, com o máximo vindo da API (`nota_maxima`).

**Arquivos principais:** `database/migrations/2026_08_08_100000_add_rubrica_to_avaliacoes_table.php`,
`app/Models/Avaliacao.php`, `app/Services/AvaliacaoFluxoService.php`,
`app/Http/Requests/Avaliador/ConcluirAvaliacaoRequest.php` (novo),
`app/Http/Controllers/Api/V1/AvaliadorAvaliacaoController.php`,
`app/Services/AdminDashboardService.php`, `resources/js/components/AvaliacaoModal.jsx`,
`resources/js/pages/AdminHome.jsx`, `resources/js/pages/AvaliadorHome.jsx`.

### Resultado dos testes

| Verificação | Sprint 1 | Sprint 2 |
|-------------|----------|----------|
| Backend (PHPUnit) | 232/232 | **239/239** (752 asserções) |
| Frontend (Vitest) | 39/39 | **46/46** (17 arquivos) |
| Pint | limpo | **limpo** |
| `npm run build` | OK | **OK** |

Novos testes: `AdminTest` (contagem por categoria, com ordem do enum e categoria zerada),
`AdminHome.test.jsx` (colunas do card + posição antes de Orientadores),
`AvaliacaoFluxoTest` (soma, nota final imune ao cliente, nota 0 válida, comentários opcionais,
comentário em branco → nulo, obrigatoriedade e faixa dos quesitos),
`AvaliacaoModal.test.jsx` (novo: rubrica, liberação do envio, payload enviado, leitura).

### O que o Pedro precisa fazer

1. **Rodar a migration** ao subir o código: `php artisan migrate`.
2. **Avaliações antigas.** Se já existir alguma avaliação concluída no banco de produção, ela
   ficará com `nota` na escala velha (1–10) e sem os quesitos. Como a avaliação ainda não foi
   liberada para valer, o mais simples é limpar as de teste. Se preferir manter, me avise que
   escrevo a migration de conversão.
3. **Conferir os rótulos dos quesitos** ("Vídeo de apresentação", "Resumo do projeto",
   "Projeto de pesquisa") — são o texto que o avaliador vê.

### Sugestões (fora do escopo, para você decidir)

- **Peso por quesito.** Hoje os três valem igual (soma simples, 0–30). Se a comissão quiser dar
  mais peso ao projeto de pesquisa, dá para parametrizar sem mexer na tela do avaliador.
- **Relatório para o orientador.** Os comentários por quesito são um retorno valioso; vale
  decidir se e quando o orientador poderá lê-los (hoje ficam só para a organização).
- **Coluna "Sem categoria"** no card novo, caso queira que as três colunas fechem com o total.

---

## Sprint 3 — Conferência de classificação + rascunho

**Branch:** `feat/sprint3-classificacao-rascunho` (a partir de `feat/sprint2-dashboard-rubrica`)

### E5 — Conferência da classificação (área e subárea)

O avaliador agora também confere se o projeto está classificado no lugar certo:

| Pergunta | Obrigatoriedade | Sugestão |
|----------|-----------------|----------|
| A **área do conhecimento** está correta? | **Obrigatória** para enviar | Se responder "não", **sugerir a área correta é obrigatório** |
| A **subárea** está correta? | **Opcional** (pode deixar em branco) | Se responder "não", **sugerir a subárea correta é obrigatório** |

- As listas são as **do catálogo global**, exatamente os controles dos formulários do orientador:
  `Select` de áreas e `SubareaCombobox` (com "digite/crie", que cria a subárea global na hora).
- As subáreas listadas seguem a **área que vale para o projeto** — a sugerida, se houver; senão
  a atual. Trocar a área sugerida limpa a subárea escolhida, para não sobrar combinação inválida.
- **A sugestão precisa ser diferente da classificação atual** (sugerir a mesma área não corrige
  nada) — validado no servidor e a área atual nem aparece na lista.
- Marcar como "correta" **descarta a sugestão** salva antes em rascunho, então não sobra
  sugestão órfã de uma resposta que o avaliador mudou de ideia.
- As sugestões apontam para o catálogo com `nullOnDelete`: se o admin mesclar/excluir uma área
  em Parametrização, as avaliações já feitas não são derrubadas.

### E6 — Rascunho da avaliação

- Novo endpoint **`POST /api/v1/avaliacao/{id}/rascunho`**: salva o preenchimento parcial sem
  enviar. **Nada é obrigatório** no rascunho, mas o que vier é validado (nota de 0 a 10,
  comentário até 2000 caracteres, sugestão existente no catálogo).
- A avaliação continua **`em_andamento`**; `rascunho_em` guarda o último salvamento e é
  **zerado ao enviar**. Reabrir o modal traz tudo preenchido de volta.
- No modal, o botão **"Salvar rascunho"** fica ao lado de "Enviar avaliação" e funciona mesmo
  com o formulário incompleto (o de enviar continua bloqueado até completar).
- Não dá para salvar rascunho antes de iniciar nem depois de enviada.
- Os dois `FormRequest` (rascunho e conclusão) herdam de `AvaliacaoRequest`, que alterna entre
  "tudo opcional" e "quesitos + área obrigatórios" — regras de faixa e consistência escritas uma vez só.

**Arquivos principais:** `database/migrations/2026_08_08_110000_add_classificacao_e_rascunho_to_avaliacoes_table.php`,
`app/Http/Requests/Avaliador/AvaliacaoRequest.php` (novo, base),
`app/Http/Requests/Avaliador/RascunhoAvaliacaoRequest.php` (novo),
`app/Http/Requests/Avaliador/ConcluirAvaliacaoRequest.php`, `app/Services/AvaliacaoFluxoService.php`,
`app/Models/Avaliacao.php`, `app/Http/Controllers/Api/V1/AvaliadorAvaliacaoController.php`,
`routes/api.php`, `resources/js/components/AvaliacaoModal.jsx`, `resources/js/lib/avaliacao.js`.

### Resultado dos testes

| Verificação | Sprint 2 | Sprint 3 |
|-------------|----------|----------|
| Backend (PHPUnit) | 239/239 | **254/254** (809 asserções) |
| Frontend (Vitest) | 46/46 | **54/54** (17 arquivos) |
| Pint | limpo | **limpo** |
| `npm run build` | OK | **OK** |

Novos testes: `AvaliacaoFluxoTest` (+15: sugestão obrigatória quando incorreta, sugestão
diferente da atual, sugestão precisa existir no catálogo, subárea opcional, descarte de sugestão
órfã, rascunho parcial/recuperado/validado/barrado antes de iniciar e depois de enviado, marca de
rascunho limpa ao enviar, rascunho de outro avaliador barrado) e `AvaliacaoModal.test.jsx` (+8).

**Verificação extra:** rodei a migration num banco SQLite com uma avaliação antiga já gravada.
Como o SQLite reconstrói a tabela para criar as FKs, confirmei que a linha sobrevive intacta
(`nota`, `status`, `projeto_id`) e que as 6 colunas novas aparecem.

### O que o Pedro precisa fazer

1. **Push das três branches** (nesta ordem, são cumulativas):
   ```
   git push -u origin feat/sprint1-deps-login-bloqueio
   git push -u origin feat/sprint2-dashboard-rubrica
   git push -u origin feat/sprint3-classificacao-rascunho
   ```
   Se preferir um PR só, o da Sprint 3 já contém tudo.
2. **Rodar `php artisan migrate`** ao subir (duas migrations novas).
3. **Decidir sobre as avaliações antigas** (ver Sprint 2, item 2): se houver alguma concluída no
   banco, ela fica com `nota` na escala velha (1–10), sem quesitos e sem conferência de área.
4. **Validar o fluxo com a comissão avaliadora** — vale abrir a tela com um avaliador de teste e
   conferir se os textos das perguntas ("A área do conhecimento está correta?") estão como eles
   esperam.

### Sugestões (fora do escopo, para você decidir)

- **Tela do admin para as sugestões de reclassificação.** O avaliador agora sinaliza área/subárea
  erradas, mas ninguém vê isso reunido em lugar nenhum. Uma lista "projetos com reclassificação
  sugerida" (com quantos avaliadores concordam) fecharia o ciclo — e casaria com a Parametrização
  que já existe.
- **Autosalvar o rascunho** a cada N segundos, além do botão manual, para o avaliador não perder
  o preenchimento se a sessão cair no meio de uma leitura longa.
- **Distribuição por subárea:** se muitos projetos vierem reclassificados, vale reprocessar a
  designação de avaliadores depois da correção.

---

## Sprint 4 — Painéis do admin: reclassificações e ranking

**Branch:** `feat/ciclo-ago2026` (consolidada — contém as sprints 1, 2 e 3)

Dois cards novos em **Avaliação online**, no mesmo padrão dos de "Avaliadores por área" e
"Projetos submetidos" (a grade virou 2×2).

### E7 — Reclassificações sugeridas (`/admin/avaliacao/reclassificacoes`)

Fecha o ciclo aberto na Sprint 3: o avaliador aponta área/subárea errada e agora o admin vê isso
reunido num lugar só.

- Lista os projetos em que **algum avaliador concluiu a avaliação marcando área ou subárea como
  incorreta**, agrupados por projeto e ordenados por quem tem mais sugestões.
- Mostra a classificação **atual** do projeto, cada sugestão individual (avaliador + data) e,
  em destaque, o **consenso**: a opção mais votada com a contagem (ex.: *Área: Ciências
  Biológicas (2)*).
- **Filtros:** nome do projeto (trecho do título), área do conhecimento atual e período da
  **data de avaliação** (de/até, ambos inclusivos). Período invertido é rejeitado com 422.
- Avaliações ainda **em andamento não aparecem** — um rascunho que marcou a área como errada não
  vira sugestão até ser enviado.

### E8 — Ranking dos projetos (`/admin/avaliacao/ranking`)

- Projetos com **ao menos uma avaliação concluída**, ordenados pela **média das notas finais**
  (0 a 30), com as médias de cada quesito (vídeo, resumo, pesquisa) ao lado.
- **Empate na média** divide a posição e é desfeito por quem tem **mais avaliações** (média mais
  confiável); o último critério é o título.
- Quem ainda não chegou a **3 avaliações** aparece marcado como **parcial**, com um aviso no topo
  dizendo quantos são — a posição desses ainda vai mudar.
- **Filtro por área**, porque projetos de áreas diferentes não competem entre si. (Isso não estava
  no pedido; incluí porque um ranking geral misturando áreas não serve para premiação — se
  preferir sem, é só remover o select.)
- Pódio com medalhas 🥇🥈🥉; do 4º em diante, o número.

### Data da avaliação

Para o filtro por período existir de verdade, criei **`concluida_em`** na tabela `avaliacoes`,
preenchida no envio. `updated_at` não servia como "data da avaliação" porque muda em qualquer
escrita posterior. A migration preenche o histórico das já concluídas com o `updated_at` delas.

Também separei `StatusAvaliacao::MIN_POR_PROJETO` de `MAX_POR_AVALIADOR`: mesmo valor (3), mas
"mínimo de avaliações por projeto" e "teto de avaliações por avaliador" são regras diferentes e
estavam compartilhando a mesma constante.

**Arquivos principais:** `database/migrations/2026_08_08_120000_add_concluida_em_to_avaliacoes_table.php`,
`app/Services/AdminAvaliacaoService.php`, `app/Http/Controllers/Api/V1/AdminAvaliacaoController.php`,
`app/Enums/StatusAvaliacao.php`, `routes/api.php`, `resources/js/lib/admin.js`,
`resources/js/pages/AvaliacaoReclassificacoes.jsx` (novo), `resources/js/pages/AvaliacaoRanking.jsx` (novo),
`resources/js/pages/AdminAvaliacaoOnline.jsx`, `resources/js/Root.jsx`.

### Resultado dos testes

| Verificação | Sprint 3 | Sprint 4 |
|-------------|----------|----------|
| Backend (PHPUnit) | 254/254 | **270/270** (869 asserções) |
| Frontend (Vitest) | 54/54 | **65/65** (19 arquivos) |
| Pint | limpo | **limpo** |
| `npm run build` | OK | **OK** |

Novos testes: `AdminReclassificacaoRankingTest` (16 — consenso, sugestão de subárea, projeto sem
sugestão fora da lista, avaliação em andamento fora da lista, os três filtros, período invertido,
ordenação, médias por quesito, marca de parcial, desempate, filtro de área e restrição ao admin),
`AvaliacaoReclassificacoes.test.jsx` (5) e `AvaliacaoRanking.test.jsx` (6).

### Verificação com o app rodando

Subi o projeto localmente e dirigi a interface com Chromium headless, logado como admin:
dashboard com o card de categoria, os 4 cards em Avaliação online, a lista de reclassificações
com o consenso, os filtros de nome e de área, o ranking com pódio e o selo "parcial", o filtro de
área do ranking e o bloqueio de login com o contador em 00:57. Nenhum erro de console além dos
401 esperados do `/auth/me` na tela de login sem sessão.

> **Portas:** 8000 e 5173 estavam ocupadas por outro app na máquina, então rodei em
> `http://127.0.0.1:8001` (Vite subiu sozinho na 5174). Para a sessão do Sanctum funcionar nessa
> porta, subi com `SANCTUM_STATEFUL_DOMAINS` e `APP_URL` apontando para a 8001 — no seu ambiente,
> com as portas livres, o `.env` normal funciona sem nenhum ajuste.

### O que o Pedro precisa fazer

1. **Push único:** `git push -u origin feat/ciclo-ago2026`.
2. **`php artisan migrate`** — agora são **três** migrations do ciclo (rubrica, classificação +
   rascunho, `concluida_em`).
3. **Limpar os dados de demonstração**, se quiser. Para conferir as telas eu semeei 4 projetos
   `[DEMO]`, 5 avaliadores `*.demo@fetecms.test` e 10 avaliações no seu SQLite local, e **redefini
   a senha do `admin@fetecms.test` para `password`** (a antiga era desconhecida). O banco de antes
   está salvo em `database.pos-migrations.bak` no scratchpad da sessão; para zerar só os demos:
   ```
   php artisan tinker --execute="App\Models\Projeto::where('titulo','like','[DEMO]%')->delete(); App\Models\User::where('email','like','%.demo@fetecms.test')->delete();"
   ```

### Sugestões (fora do escopo, para você decidir)

- **Ação a partir da reclassificação:** hoje a tela informa, mas não age. Um botão "aplicar
  sugestão" que reclassifica o projeto direto (e opcionalmente redistribui os avaliadores) evitaria
  o trabalho manual quando o consenso é claro.
- **Exportar o ranking em CSV/PDF** para a comissão premiadora.
- **Empate no ranking:** hoje projetos com a mesma média dividem a posição. Se o edital exigir
  desempate, vale definir o critério (nota do projeto de pesquisa? menor desvio entre avaliadores?).

---

## Sprint 5 — Aceitar as reclassificações

**Branch:** `feat/ciclo-ago2026` (mesma branch consolidada)

A tela de reclassificações deixa de só informar e passa a **agir** — era a sugestão que eu tinha
deixado no fim da Sprint 4.

### E9 — Aplicar a sugestão de um projeto

- Botão **"Aplicar sugestão"** em cada projeto, ao lado do consenso. Aplica **área e/ou subárea**
  (o que houver de consenso) e pede confirmação mostrando exatamente a troca: *"Área → Ciências
  Biológicas"*.
- Só o botão acionado entra em carregamento; os demais ficam desabilitados até terminar.

### E10 — Aceite em lote

- Caixa **"Aceitar várias sugestões de uma vez"** troca os botões individuais por caixas de
  seleção.
- Por projeto, **área e subárea são independentes** — dá para marcar as duas no mesmo projeto,
  como você pediu.
- **"Selecionar todos"** marca a sugestão mais votada de cada campo em todos os projetos da lista
  (respeitando os filtros ativos); desmarcar limpa tudo.
- Quando um projeto tem **sugestões divergentes** (ex.: 2 avaliadores dizem Biológicas, 1 diz
  Saúde), aparece um **select para escolher qual aceitar**, com a contagem de votos e a mais
  votada pré-selecionada.
- O lote inteiro vai numa **única transação**: se um item for inválido, nada é aplicado.

### Regras que valem a pena saber

- O endpoint **só aceita valores que algum avaliador realmente sugeriu** para aquele projeto.
  É "aceitar sugestão", não edição livre — para reclassificar à mão, o caminho continua sendo a
  edição do projeto.
- **Trocar a área limpa a subárea** quando ela não pertence à nova área (subárea vive sob uma área
  só). A resposta avisa: *"A subárea de N projeto(s) foi limpa por não pertencer à nova área."*
- **A sugestão aplicada some da lista sem precisar de flag no banco:** ela passa a apontar para a
  classificação atual do projeto, então deixa de ser uma troca pendente. Sugestões **divergentes
  continuam listadas** — se 2 pediram Biológicas e 1 pediu Saúde, aplicar Biológicas mantém o
  projeto na tela com a sugestão de Saúde ainda em aberto.

**Arquivos principais:** `app/Services/AdminAvaliacaoService.php` (`aplicarReclassificacoes`),
`app/Http/Requests/Admin/AplicarReclassificacaoRequest.php` (novo),
`app/Http/Controllers/Api/V1/AdminAvaliacaoController.php`, `routes/api.php`,
`resources/js/lib/admin.js`, `resources/js/pages/AvaliacaoReclassificacoes.jsx`.

### Resultado dos testes

| Verificação | Sprint 4 | Sprint 5 |
|-------------|----------|----------|
| Backend (PHPUnit) | 270/270 | **282/282** (917 asserções) |
| Frontend (Vitest) | 65/65 | **77/77** (19 arquivos) |
| Pint | limpo | **limpo** |
| `npm run build` | OK | **OK** |

Novos testes: `AdminReclassificacaoRankingTest` (+12 — aplica área, aplica área+subárea juntas,
lote com vários projetos, limpeza da subárea incompatível, sugestão aplicada some da lista,
divergente continua pendente, recusa área não sugerida, lote inválido não aplica nada, item vazio,
lista vazia, restrição ao admin, opções distintas com votos) e `AvaliacaoReclassificacoes.test.jsx`
(+12 — botão por projeto, cancelar, erro da API, carregamento isolado por botão, modo lote,
escolha entre opções divergentes, selecionar todos, área+subárea juntas, botão bloqueado sem
seleção, desmarcar todos, aviso de subárea limpa).

**Verificado no app rodando:** apliquei a sugestão de subárea do projeto de abelhas pela interface
— a mensagem confirmou, o projeto saiu da lista e o banco passou de *Botânica* para *Ecologia*.

### O que o Pedro precisa fazer

1. **Push único:** `git push -u origin feat/ciclo-ago2026` (nada mudou aqui — a Sprint 5 entrou na
   mesma branch).
2. Nada de migration nova nesta sprint.

### Sugestões (fora do escopo, para você decidir)

- **Redistribuir avaliadores depois de reclassificar.** Se um projeto muda de área, os avaliadores
  designados podem não ser mais os certos. Hoje a designação fica como está; um aviso na tela (ou
  um `avaliacao:distribuir` sugerido depois do lote) fecharia essa ponta.
- **Registrar quem aplicou e quando.** Hoje a troca não deixa rastro além do próprio projeto. Um
  log simples ajudaria a auditar reclassificações em ano de feira movimentado.


---

## Ajuste pós-ciclo — rubrica oficial da FETECMS

**Branch:** `feat/rubrica-fetecms-2025` (a partir de `origin/main` @ `227377c`, v1.13)

A comissão entregou o documento **"Perguntas de Avaliação FETECMS"** com as perguntas, as
orientações ao avaliador, a métrica de cada uma e a nota máxima por pergunta. A tela de
avaliação passou a ser exatamente esse documento.

### O que mudou

- **A rubrica de 3 quesitos saiu.** No lugar entraram **17 perguntas pontuadas** distribuídas
  em **10 seções** (Título, Resumo, Introdução, Objetivos, Metodologia, Resultados e discussão,
  Conclusão, Referências, Geral — projeto e Vídeo), mais a conferência de área/subárea
  ("Geral — início", que não vale ponto) e o passo final de recomendações.
- **Duas métricas**, como no documento: escala de **0 a 10 de dois em dois** (Não possui,
  Muito ruim, Ruim, Regular, Bom, Muito bom) e **Sim/Não** (Sim vale o peso cheio, Não vale 0).
- **Peso por pergunta.** A nota final é a **soma ponderada**: cada pergunta rende a fração da
  resposta vezes o seu peso. O total fecha **10,00** — 8,0 do projeto de pesquisa e 2,0 do
  vídeo, como a tabela de fechamento do documento.
- **Balão de dúvida ("?")** ao lado de cada pergunta com a coluna *Orientações para o Avaliador*.
- **Wizard por seção:** o avaliador percorre um passo por seção, com Voltar/Avançar, atalhos
  para qualquer seção no topo, nota parcial e contagem de respondidas no rodapé. **Salvar
  rascunho continua disponível em qualquer passo.**
- **Recomendações em dois campos** (a pergunta descritiva do documento): um sobre o **vídeo**,
  junto das perguntas do vídeo, e um sobre o **projeto**, no passo final. Ambos opcionais.
- **A avaliação do projeto de continuação foi removida** — o documento continua na leitura do
  avaliador (lista de anexos), mas não é pontuado à parte, então o teto é o mesmo para todo
  projeto sem precisar de média.
- **Ranking do admin:** as médias por quesito viraram **médias por seção**, cada uma com o teto
  da seção ao lado.

### Como ficou no banco

`respostas` (JSON, `chave da pergunta => valor`) substitui as colunas `nota_video`,
`nota_resumo`, `nota_pesquisa` e `nota_continuidade`. O conjunto de perguntas mora no catálogo
`App\Support\Rubrica`, não na estrutura da tabela — mexer na rubrica não pede migration.
`comentario_video` sobreviveu (virou a recomendação sobre o vídeo) e `comentario_projeto`
nasceu para a recomendação final. A coluna `nota` virou `decimal(5,2)`.

**Avaliações já concluídas** ficam só com a nota final, **reescalada de 0–15 para 0–10**: não há
tradução possível das respostas antigas para as perguntas novas. Elas entram na média geral do
ranking, mas não nas médias por seção.

### Decisões tomadas na implementação

- **Resultados e discussão usa 1/3 por pergunta**, e não os 0,33 da coluna do documento. Com
  0,33 a seção fecharia 0,99 e o total 9,99; a tabela da última página diz **1,0** e **10,00**.
- **A conferência de área/subárea continua em duas perguntas** (área obrigatória com sugestão,
  subárea opcional com sugestão), como já era — é o que alimenta o painel de reclassificações
  do admin. O documento junta as duas numa pergunta só, que vale 0 de qualquer forma.
- **"A partir do vídeo, de que modo os integrantes demonstram domínio do tema?" ficou sem balão
  de ajuda**: a coluna de orientações do documento traz `????` nessa linha.

**Arquivos principais:** `app/Support/Rubrica.php` (novo — o documento em código),
`app/Models/Avaliacao.php`, `app/Http/Requests/Avaliador/AvaliacaoRequest.php`,
`app/Services/AvaliacaoFluxoService.php`, `app/Services/AdminAvaliacaoService.php`,
`app/Http/Controllers/Api/V1/AvaliadorAvaliacaoController.php`,
`database/migrations/2026_08_21_100000_substituir_rubrica_pelas_perguntas_fetecms.php`,
`resources/js/components/AvaliacaoModal.jsx`, `resources/js/components/AjudaBalao.jsx` (novo),
`resources/js/components/EscalaResposta.jsx` (era `EscalaLikert.jsx`),
`resources/js/pages/AvaliacaoRanking.jsx`.

### Resultado dos testes

| Verificação | Antes | Agora |
|-------------|-------|-------|
| Backend (PHPUnit) | 320/320 | **322/322** (1083 asserções) |
| Frontend (Vitest) | 96/96 | **98/98** (23 arquivos) |
| Pint | limpo | **limpo** |
| `npm run build` | OK | **OK** |

Migration verificada com dados da rubrica antiga: uma avaliação concluída com nota 15 virou
**10,00**, o rascunho continuou sem nota e o comentário do vídeo foi preservado. O `down()`
recria as colunas antigas e desfaz a reescala.

### O que o Pedro precisa fazer

1. **Rodar a migration:** `php artisan migrate` (as avaliações concluídas serão reescaladas).
2. **Push:** `git push -u origin feat/rubrica-fetecms-2025`.
3. **Conferir os textos das perguntas** com a comissão — foram transcritos do PDF sem edição,
   inclusive a pontuação e a concordância originais.

### Pendências vindas do próprio documento

- **Falta a orientação da pergunta de "domínio do tema" no vídeo** (`????` no PDF). Assim que a
  comissão mandar o texto, é uma linha em `Rubrica::SECOES` — sem migration.


---

## Perfil do avaliador

**Branch:** `feat/rubrica-fetecms-2025` (mesma do ajuste da rubrica)

O menu do avaliador só tinha "Avaliações". Ganhou **Perfil** (`/avaliador/perfil`), espelhando
o que o orientador já tem.

### Estatísticas (três cards)

| Card | O que mostra |
|------|--------------|
| **Projetos avaliados** | avaliações **concluídas** (as em andamento não contam), com o total designado a ele no detalhe |
| **Certificado** | carga horária acumulada — **2h30 por avaliação concluída** (`AvaliadorProfile::MINUTOS_POR_AVALIACAO`), formatada como `7h30` / `5h` |
| **No ranking de avaliadores** | posição por número de projetos avaliados, entre os avaliadores que já concluíram ao menos um |

Decisões do ranking:

- **Só entra quem já concluiu alguma avaliação.** Uma lista de zeros não classifica ninguém, e
  mostrar "42º de 42" para quem ainda não começou não ajuda. Sem avaliações, o card exibe `—`
  com o convite para concluir a primeira.
- **Empate divide a posição**, como no ranking de projetos: dois avaliadores com 3 avaliações
  ficam ambos em 1º e o seguinte em 3º. O card diz "posição dividida" nesse caso.
- **O avaliador demo não é tratado à parte.** As avaliações de teste dele entram na conta como
  qualquer outra — o admin já tem "limpar testes" para zerá-las.

### Troca de área/subárea

Liberada **só enquanto o período de avaliação não começou** (`Edicao::avaliacaoLiberada()`),
porque depois disso a distribuição já foi feita em cima da classificação do avaliador. A regra
vive em `AvaliadorService::podeTrocarClassificacao()` e é checada nos dois lados: a tela some
com o formulário e o `PUT` responde 422.

- **Vale também para o avaliador demo.** O modo teste adianta a *avaliação*, não a troca de área.
- **Trocar de área não refaz as designações** que o admin já fez. Como a troca pode acontecer
  com projetos já designados (o admin pode designar antes da liberação), a tela avisa quando
  isso ocorre em vez de bloquear — refazer designação é do admin.
- **Subárea continua opcional** e pode ser criada na hora pelo combobox, como no cadastro.
  Trocar de área **limpa** a subárea anterior, que era de outra área.

**Arquivos principais:** `app/Http/Controllers/Api/V1/AvaliadorPerfilController.php` (novo),
`app/Http/Requests/Avaliador/AtualizarClassificacaoRequest.php` (novo),
`app/Services/AvaliadorService.php` (`estatisticas`, `podeTrocarClassificacao`,
`atualizarClassificacao`), `app/Support/Tempo.php` (`cargaHoraria`),
`app/Models/AvaliadorProfile.php` (`MINUTOS_POR_AVALIACAO`), `routes/api.php`,
`resources/js/pages/AvaliadorPerfil.jsx` (novo), `resources/js/lib/avaliador.js` (novo),
`resources/js/components/AppShell.jsx`, `resources/js/Root.jsx`.

### Resultado dos testes

| Verificação | Antes | Agora |
|-------------|-------|-------|
| Backend (PHPUnit) | 322/322 | **337/337** (1141 asserções) |
| Frontend (Vitest) | 98/98 | **108/108** (24 arquivos) |
| Pint | limpo | **limpo** |
| `npm run build` | OK | **OK** |

Novos testes: `AvaliadorPerfilTest` (+15 — contagem e carga horária, hora cheia sem minutos,
em andamento fora da conta, posição, empate, quem não avaliou, dados do perfil, restrição por
papel, troca de área, limpeza da subárea, subárea de outra área, área obrigatória/inexistente,
trava depois da liberação, demo também travado) e `AvaliadorPerfil.test.jsx` (+10).

### Sugestões (fora do escopo, para você decidir)

- **Emitir o certificado em PDF.** A carga horária já está calculada; falta o documento com o
  nome do avaliador, as horas e a assinatura da organização.
- **Ranking visível para o avaliador.** Hoje ele vê só a própria posição. Um quadro com os
  primeiros colocados (com ou sem nomes) pode estimular a conclusão das avaliações.
