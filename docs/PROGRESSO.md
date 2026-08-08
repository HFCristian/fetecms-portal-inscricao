# Progresso — ciclo de ajustes (agosto/2026)

Registro das sprints deste ciclo. Cada épico é um MVP; cada sprint junta dois épicos
e vira uma branch a partir da `main` (encadeamento cumulativo: a sprint N parte da
branch da sprint N−1, para nada se perder enquanto os PRs não são mergeados).

## Escopo do ciclo

| Sprint | Épicos | Branch | Status |
|--------|--------|--------|--------|
| 1 | **E1** Atualização de dependências (dependabot) · **E2** Login: aviso de bloqueio + contador | `feat/sprint1-deps-login-bloqueio` | ✅ concluída |
| 2 | **E3** Dashboard: card "Projetos por categoria" · **E4** Rubrica de avaliação (3 notas + comentários) | `feat/sprint2-dashboard-rubrica` | ⏳ a fazer |
| 3 | **E5** Conferência de classificação (área/subárea) · **E6** Rascunho da avaliação | `feat/sprint3-classificacao-rascunho` | ⏳ a fazer |

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
