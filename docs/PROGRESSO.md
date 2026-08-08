# Progresso — ciclo de ajustes (agosto/2026)

Registro das sprints deste ciclo. Cada épico é um MVP; cada sprint junta dois épicos
e vira uma branch a partir da `main` (encadeamento cumulativo: a sprint N parte da
branch da sprint N−1, para nada se perder enquanto os PRs não são mergeados).

## Escopo do ciclo

| Sprint | Épicos | Branch | Status |
|--------|--------|--------|--------|
| 1 | **E1** Atualização de dependências (dependabot) · **E2** Login: aviso de bloqueio + contador | `feat/sprint1-deps-login-bloqueio` | ✅ concluída |
| 2 | **E3** Dashboard: card "Projetos por categoria" · **E4** Rubrica de avaliação (3 notas + comentários) | `feat/sprint2-dashboard-rubrica` | ✅ concluída |
| 3 | **E5** Conferência de classificação (área/subárea) · **E6** Rascunho da avaliação | `feat/sprint3-classificacao-rascunho` | ✅ concluída |

### Decisões travadas (definidas com o Pedro antes de começar)

- **"Categoria" e "subcategoria" na avaliação** = **Área do Conhecimento** e **Subárea**
  (não o enum `Categoria` FETEC Jr/FETECMS/FUNDECT). Usam os mesmos controles dos outros
  formulários: `Select` de áreas do catálogo e `SubareaCombobox`.
- **Nota final da avaliação** = **soma dos 3 quesitos**, escala **0 a 30** (vídeo + resumo +
  projeto de pesquisa, cada um 0–10). A coluna `nota` passa a guardar a soma.
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
