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

## Próximos passos (cenários autenticados)

Para medir login/listagem/submissão sob carga, é preciso encadear o fluxo de cookie/CSRF do
Sanctum (GET `/sanctum/csrf-cookie` → POST `/api/v1/auth/login` reaproveitando o cookie e o
header `X-XSRF-TOKEN`). Fica como evolução: criar um `k6-auth.js` com esse setup e tokens de
teste dedicados, para não poluir dados reais.
