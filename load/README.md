# Testes de carga (k6)

Testes de carga da API da XVI FETECMS usando [k6](https://k6.io) (gratuito, open-source).

## Instalar o k6

- **Windows:** `winget install k6 --source winget` (ou `choco install k6`)
- Outros: https://k6.io/docs/get-started/installation/

> Nada é instalado automaticamente — instale o k6 quando quiser rodar.

## Rodar

Com a aplicação no ar (`php artisan serve` ou `composer run dev`):

```bash
k6 run load/k6-smoke.js
# apontando para outra URL:
k6 run -e BASE_URL=http://localhost:8000 load/k6-smoke.js
```

## O que o `k6-smoke.js` faz

- Sobe até **20 usuários virtuais** por ~1m40s.
- Exercita os endpoints **públicos** (health + catálogos), que são os mais acessados durante o
  preenchimento do formulário de projeto.
- **Thresholds**: falha < 1% e p95 < 500ms (ajuste conforme a infra de produção/AWS).

## Cenários autenticados e de concorrência (`k6-concurrency.js`)

Encadeia o fluxo de cookie/CSRF do Sanctum (GET `/sanctum/csrf-cookie` → POST `/api/v1/auth/login`)
e roda dois cenários:

| Cenário | O que faz | Quando roda |
|---------|-----------|-------------|
| `auth_read_load` | login + `GET /projetos` com até 15 VUs | sempre |
| `submit_race` | 20 VUs disparam `POST /submeter` no **mesmo** projeto | só com `PROJETO_ID` |

```bash
# carga autenticada de leitura (usa o seed orientador@fetecms.test / password)
k6 run load/k6-concurrency.js

# inclui o teste de corrida da submissão (use um rascunho COMPLETO do orientador)
k6 run -e PROJETO_ID=12 load/k6-concurrency.js

# produção/staging:
k6 run -e BASE_URL=https://app.exemplo.org -e EMAIL=... -e PASSWORD=... load/k6-concurrency.js
```

> O host de `BASE_URL` precisa estar em `SANCTUM_STATEFUL_DOMAINS` — o script já manda o header
> `Origin` para ser tratado como request "stateful" do SPA.

## Race conditions e fault injection

Pontos de corrida auditados e **protegidos por trava de linha** (`lockForUpdate` → `SELECT … FOR
UPDATE` no PostgreSQL de produção; no-op inócuo no SQLite local):

- **Submissão irreversível** (`ProjetoSubmissaoController::submeter`): a transação trava a linha do
  projeto e revalida o status; submissões concorrentes não reprocessam — só a primeira efetiva.
  Invariante validado pelo `submit_race`: nunca 5xx, sempre `200` (idempotente) ou `422`.
- **Limite de alunos por categoria** (`AlunoService::adicionar`): a contagem e o `INSERT` rodam sob
  a trava do projeto, impedindo que duas requisições simultâneas furem o teto (Jr=4, demais=3).
- **Exclusão mútua orientador/avaliador**: além da checagem na aplicação, há `UNIQUE` em
  `users.email` como backstop contra cadastro duplicado em corrida.

> **Importante:** as travas só são *exercitadas de verdade* contra PostgreSQL. Rode o `submit_race`
> e um cenário equivalente de `POST /alunos` apontando para um ambiente com RDS para validar de
> ponta a ponta. No SQLite as escritas já são serializadas, então o teste passa sem provar a trava.

**Fault injection sugerido (staging):** derrubar o RDS no meio de uma submissão (a transação faz
rollback e o status permanece `rascunho`); cortar a rede durante upload de documento; estourar o
rate limit de login (`throttle:6,1`) e de registro/upload; subir latência do banco e observar os
thresholds de p95.
