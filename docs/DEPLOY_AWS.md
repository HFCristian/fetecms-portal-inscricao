# Deploy na AWS — EC2 + ELB + RDS

Guia de produção do **Portal de Inscrição XVI FETECMS**: aplicação Laravel + SPA React rodando em
**EC2**, atrás de um **Application Load Balancer (ALB)**, com banco **PostgreSQL no RDS** e uploads
no **S3**.

> Stack de produção: PHP 8.4 (PHP-FPM) + Nginx · PostgreSQL 16 (RDS) · S3 · ALB com TLS (ACM).
> O app é **stateless por instância** (sessão, cache e fila no banco; arquivos no S3), então escala
> horizontalmente atrás do ALB.

---

## 1. Arquitetura

```
                       Internet
                          │  HTTPS (443)
                 ┌────────▼─────────┐
   Route 53 ───▶ │  ALB (público)   │  cert TLS via ACM; :80 → redirect :443
                 │  health: /up     │
                 └────────┬─────────┘
                  HTTP (80) │  (apenas do SG do ALB)
            ┌──────────────┼──────────────┐
   ┌────────▼───────┐            ┌────────▼───────┐   Auto Scaling Group
   │ EC2 (privada)  │   ...      │ EC2 (privada)  │   Nginx + PHP-FPM + app
   │ Nginx+PHP-FPM  │            │ Nginx+PHP-FPM  │
   └───┬────────┬───┘            └───┬────────┬───┘
       │ :5432  │ :443               │        │
   ┌───▼──────┐ │ ┌──────────────────▼─┐    ┌─▼────────────┐
   │ RDS      │ └▶│ S3 (uploads/docs)  │    │ (logs, etc.) │
   │ Postgres │   └────────────────────┘    └──────────────┘
   └──────────┘   (subnets privadas; SG só libera o necessário)
```

**Princípio de segurança:** só o ALB é público. EC2 e RDS ficam em **subnets privadas**; os
*security groups* (SG) liberam apenas o tráfego mínimo entre as camadas (detalhe na §8).

---

## 2. Pré-requisitos

- Conta AWS com permissão para EC2, RDS, ELB, S3, ACM, IAM e VPC.
- Um **domínio** (ex.: `app.fetecms.org`) — idealmente no Route 53.
- **Certificado TLS no ACM** para o domínio (na mesma região do ALB).
- Uma **VPC** com pelo menos 2 subnets públicas (ALB) e 2 privadas (EC2/RDS) em AZs distintas.
- Chave SSH para acessar o EC2 (ou usar **SSM Session Manager**, recomendado — dispensa porta 22).

---

## 3. RDS PostgreSQL

1. **RDS → Create database → PostgreSQL** (16.x). Template *Production* (Multi-AZ) ou *Dev/Test*.
2. **Credenciais:** usuário `fetecms` e uma senha forte (guarde no **AWS Secrets Manager**).
3. **Conectividade:** mesma VPC; **Public access = No**; subnets privadas.
4. **Security group** `sg-rds`: por enquanto sem regras de entrada (criamos na §8).
5. Crie o banco inicial `fetecms` (campo *Initial database name*).
6. Anote o **endpoint**: `fetecms-prod.xxxx.us-east-1.rds.amazonaws.com:5432`.

> Migrations são agnósticas de SGBD — as mesmas que rodam em SQLite local rodam no Postgres.

---

## 4. S3 para uploads (obrigatório com >1 EC2)

Com várias instâncias atrás do ALB, o disco local **não é compartilhado**: um documento enviado
numa instância não existe na outra. Por isso os uploads vão para o S3.

1. **S3 → Create bucket**: `fetecms-prod-uploads` (mesma região; *Block all public access* = ON).
2. Crie uma **IAM Role** para o EC2 (instance profile) com política mínima de acesso ao bucket
   (`s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`, `s3:ListBucket` restritos ao bucket).
3. Anexe a role ao EC2 (assim **não** precisa de `AWS_ACCESS_KEY_ID/SECRET` no `.env` — usa a role).

> Downloads continuam autenticados/assinados pela aplicação (URLs temporárias); o bucket fica
> privado.

---

## 5. EC2 — provisionar a instância

1. **EC2 → Launch instance**: Ubuntu 24.04 LTS, `t3.small`+ (ajuste à carga). Subnet **privada**.
2. **IAM instance profile:** a role do S3 (§4).
3. **Security group** `sg-ec2`: entrada só na §8 (porta 80 a partir do `sg-alb`).
4. Acesse por **SSM Session Manager** (sem abrir SSH) e instale a stack:

```bash
sudo apt-get update
sudo add-apt-repository -y ppa:ondrej/php && sudo apt-get update
sudo apt-get install -y nginx unzip git \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-bcmath php8.4-intl php8.4-zip

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 22 (para buildar a SPA)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt-get install -y nodejs
```

---

## 6. Deploy da aplicação

```bash
sudo mkdir -p /var/www/fetecms && sudo chown $USER:www-data /var/www/fetecms
git clone <URL-do-repo> /var/www/fetecms
cd /var/www/fetecms

# 1) Dependências de produção
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build          # gera public/build (assets da SPA)

# 2) Ambiente
cp .env.example .env             # depois edite conforme a §7
php artisan key:generate         # gera a APP_KEY

# 3) Banco e storage
php artisan migrate --force      # aplica as migrations no RDS
php artisan storage:link

# 4) Cache de produção (config/rotas/views)
php artisan optimize

# 5) Permissões de escrita do framework
sudo chown -R www-data:www-data storage bootstrap/cache
```

> ⚠️ **`php artisan optimize` cacheia o `.env`.** Sempre que mudar o `.env`, rode
> `php artisan optimize:clear && php artisan optimize` — senão a alteração é ignorada
> (mesmo sintoma do bug de sessão local).

---

## 7. `.env` de produção (valores que importam)

```dotenv
APP_NAME="XVI FETECMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.fetecms.org

# Banco — endpoint do RDS (§3)
DB_CONNECTION=pgsql
DB_HOST=fetecms-prod.xxxx.us-east-1.rds.amazonaws.com
DB_PORT=5432
DB_DATABASE=fetecms
DB_USERNAME=fetecms
DB_PASSWORD=<segredo do Secrets Manager>

# Estado compartilhado entre instâncias (via RDS) — mantém o app stateless
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Sessão/Sanctum atrás do ALB com HTTPS
SESSION_SECURE_COOKIE=true        # cookie só em HTTPS
SESSION_DOMAIN=null               # host-only (use .fetecms.org só se houver subdomínios)
SANCTUM_STATEFUL_DOMAINS=app.fetecms.org
FRONTEND_URL=https://app.fetecms.org

# Uploads no S3 (a role do EC2 fornece as credenciais — não preencha as chaves)
FILESYSTEM_DISK=s3
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=fetecms-prod-uploads
AWS_USE_PATH_STYLE_ENDPOINT=false

MAIL_MAILER=ses                   # ou smtp; configure o remetente verificado
```

Pontos críticos para o app **atrás do ALB** (já tratados no código — `bootstrap/app.php` chama
`trustProxies(at: '*')`):

- O ALB termina o TLS e encaminha em HTTP com `X-Forwarded-Proto: https`. Sem confiar no proxy, o
  Laravel acharia que é HTTP → cookies não-Secure, redirects `http://`, CSRF/sessão quebrados.
- `trustProxies(at: '*')` é seguro **porque** o `sg-ec2` só aceita tráfego do `sg-alb` (§8) — o EC2
  nunca recebe requisição direta da internet.

---

## 8. Security groups (a “fiação” entre as camadas)

| SG | Entrada (inbound) | Origem |
|----|-------------------|--------|
| `sg-alb` | TCP **443** e **80** | `0.0.0.0/0` (internet) |
| `sg-ec2` | TCP **80** | **`sg-alb`** (somente o ALB) |
| `sg-rds` | TCP **5432** | **`sg-ec2`** (somente as instâncias) |

Referencie **SG por SG** (não por IP): em `sg-rds`, a regra de entrada `5432` tem como *source* o
`sg-ec2`. Assim qualquer EC2 do Auto Scaling Group acessa o RDS sem hardcode de IP, e nada mais
alcança o banco.

**Testar a conexão EC2 → RDS** (de dentro do EC2):

```bash
sudo apt-get install -y postgresql-client
psql "host=fetecms-prod.xxxx.us-east-1.rds.amazonaws.com port=5432 dbname=fetecms user=fetecms" -c '\conninfo'
```

Conectou = SG e rota OK. Recusou/timeout = revise o `sg-rds` (source = `sg-ec2`) e as subnets.

---

## 9. Nginx + PHP-FPM + fila

**`/etc/nginx/sites-available/fetecms`** (serve a SPA buildada a partir de `public/`):

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/fetecms/public;
    index index.php;

    # Health check do ALB (Laravel responde 200 em /up)
    location = /up { try_files $uri /index.php?$query_string; }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }
    client_max_body_size 25M;   # uploads de PDF/DOCX
}
```

```bash
sudo ln -s /etc/nginx/sites-available/fetecms /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

**Worker de fila** (jobs em `QUEUE_CONNECTION=database`) via systemd:

```ini
# /etc/systemd/system/fetecms-queue.service
[Unit]
Description=FETECMS queue worker
After=network.target
[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/fetecms/artisan queue:work --tries=3 --timeout=90 --sleep=3
[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now fetecms-queue
```

---

## 10. ALB (Application Load Balancer)

1. **Target group** `tg-fetecms`: tipo *Instances*, protocolo **HTTP :80**.
   - **Health check:** path **`/up`**, *Success codes* `200`. (rota nativa do Laravel).
   - Registre as instâncias EC2 (ou aponte para o Auto Scaling Group).
2. **Load Balancer → Application Load Balancer** `alb-fetecms` (internet-facing, subnets públicas,
   SG `sg-alb`).
3. **Listeners:**
   - **HTTPS :443** → *forward* para `tg-fetecms`; **certificado do ACM** (§2).
   - **HTTP :80** → *redirect* 301 para HTTPS.
4. **Route 53:** registro **A (alias)** do domínio → DNS do ALB.

Pronto: `https://app.fetecms.org` chega no ALB, que distribui para as EC2.

---

## 11. Atualizações (deploy contínuo)

Em cada release, em cada instância (ou via pipeline/SSM Run Command / CodeDeploy):

```bash
cd /var/www/fetecms
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear && php artisan optimize
sudo systemctl reload php8.4-fpm
sudo systemctl restart fetecms-queue
```

> Para **zero-downtime**, faça *rolling deploy* (drene uma instância no target group, atualize,
> re-registre) ou use deploy por imagem/AMI nova no Auto Scaling Group. Rode `migrate --force`
> uma vez por release (idempotente), preferencialmente antes do rollout.

---

## 12. Checklist de go-live

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` gerada.
- [ ] `https://app.fetecms.org/up` → **200** pelo ALB.
- [ ] Login persiste (sessão no RDS; cookie Secure; `SANCTUM_STATEFUL_DOMAINS` = domínio real).
- [ ] Upload de documento vai para o **S3** e o download autenticado funciona em **qualquer** instância.
- [ ] `sg-rds` só aceita `sg-ec2`; `sg-ec2` só aceita `sg-alb`; RDS sem acesso público.
- [ ] Backups automáticos do RDS habilitados; Multi-AZ se for crítico.
- [ ] Carga validada (`load/k6-concurrency.js` apontando para o domínio) dentro dos thresholds.
- [ ] CI verde na `main` (lint + testes + build) — `.github/workflows/ci.yml`.

---

## 13. Troubleshooting

- **419 / sessão não persiste:** `SANCTUM_STATEFUL_DOMAINS` precisa do domínio (sem `https://`);
  `SESSION_SECURE_COOKIE=true` exige HTTPS de ponta a ponta; confirme `trustProxies` ativo.
- **Mixed content / redirect para http://:** o proxy não está sendo confiado — cheque o ALB
  enviando `X-Forwarded-Proto` e o `trustProxies(at: '*')`.
- **Upload some entre requests:** `FILESYSTEM_DISK` ainda é `local` — troque para `s3`.
- **Mudei o `.env` e nada mudou:** faltou `php artisan optimize:clear` (config cacheada).
- **`could not find driver`:** falta `php8.4-pgsql` no EC2.
- **Timeout no banco:** regra `5432` do `sg-rds` deve ter *source* = `sg-ec2`, não um IP.
