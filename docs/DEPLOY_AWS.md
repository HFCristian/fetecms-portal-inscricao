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

> ⚠️ **`storage/app/private` precisa sobreviver ao deploy e ser o mesmo do worker.**
> É onde ficam os anexos e as imagens da mala direta. O upload acontece no processo web
> e a leitura acontece no `queue:work`, na hora do envio — se o deploy trocar o diretório
> (release nova sem symlink para um storage compartilhado) ou se a fila rodar noutra
> máquina, o arquivo some entre um e outro. Antes da Sprint 114 isso saía **em silêncio**:
> o disco privado roda com `throw => false`, o e-mail ia com um anexo de 0 byte e o
> relatório dizia "enviado". Hoje o disparo é recusado na hora e, se o arquivo sumir
> depois, o destinatário vira **falha** com o motivo — use *Reenviar falhas* depois de
> arrumar o storage. Em servidor único, mantenha `storage/` como diretório compartilhado
> entre releases; em mais de uma máquina, aponte o disco para S3.

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
SESSION_LIFETIME=300              # 5 h sem atividade; `sessoes:limpar` apaga o que passar disso
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

**Agendador** (o `schedule:run` do Laravel): roda a faxina das sessões a cada 15 minutos
(`sessoes:limpar`) e o aviso diário do chat. Sem ele, as sessões ainda são limpas pelo sorteio do
Laravel (2% das requisições), mas sem hora certa.

```bash
echo '* * * * * www-data cd /var/www/fetecms && php artisan schedule:run >> /dev/null 2>&1' \
  | sudo tee /etc/cron.d/fetecms
```

Com mais de uma EC2 o cron pode rodar em todas: a faxina usa `onOneServer()` e só uma instância a
executa por vez.

**PHP-FPM** — o pool padrão do Ubuntu vem com **`pm.max_children = 5`**: cinco requisições ao mesmo
tempo por instância. O portal tem várias telas que se atualizam sozinhas (mapa em tela cheia a cada
5 s, cerimonial, chat, avisos), e cinco processos esgotam rápido no dia do evento — a sexta
requisição espera na fila do Nginx até dar 504. Ajuste em `/etc/php/8.4/fpm/pool.d/www.conf`
(conta de ~50 MB por processo; numa `t3.small` com 2 GB sobra para o resto):

```ini
pm = dynamic
pm.max_children = 24
pm.start_servers = 6
pm.min_spare_servers = 4
pm.max_spare_servers = 10
pm.max_requests = 500            ; recicla o processo e devolve memória vazada
request_terminate_timeout = 300  ; uma requisição presa não segura o processo para sempre
pm.status_path = /fpm-status     ; ver a fila: curl localhost/fpm-status (só de dentro do EC2)
```

O teto de conexões do RDS precisa caber: `instâncias × pm.max_children + workers da fila` abaixo do
`max_connections` (o `sessoes:diagnostico` mostra os dois números). No Nginx, exponha o status só
para a própria máquina e dê às exportações grandes (ZIP de projetos, PDFs) mais tempo que os 60 s
padrão:

```nginx
location = /fpm-status { allow 127.0.0.1; deny all; include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $fastcgi_script_name; fastcgi_pass unix:/run/php/php8.4-fpm.sock; }

# dentro do `location ~ \.php$`:
fastcgi_read_timeout 300;
```

O **idle timeout do ALB** (60 s por padrão) também corta uma exportação longa: suba para 300 s em
*Load balancer → Attributes* se o ZIP da lista final passar de um minuto.

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

> ⚠️ **O `restart` do worker não é opcional.** O `queue:work` carrega as classes PHP uma vez e
> fica com elas em memória, enquanto as views Blade são lidas do disco a cada envio. Um worker
> antigo rodando com views novas gera erro em **todos** os e-mails da fila — foi o que produziu o
> `Undefined variable $html` na mala direta da v1.18 — e, depois que as views passaram a se
> defender disso, entregou os e-mails seguintes **sem formatação**, com `<strong>` aparecendo como
> texto na caixa de entrada. Desde a Sprint 102 as views derivam tudo das propriedades públicas do
> Mailable, então nem uma coisa nem outra acontece; ainda assim, um worker desatualizado continua
> rodando **a lógica antiga** de tudo o mais que a fila processa. Se o serviço tiver outro nome
> (instalação Bitnami, supervisor etc.), rode ao menos `php artisan queue:restart` no fim do
> deploy: ele pede a saída ordenada de qualquer worker em execução.
>
> Para conferir sem apostar: em **Comunicação → Mala direta**, marque *Enviar e-mail de teste
> para:*, informe um endereço seu e mande. O teste percorre a mesma fila do disparo, então uma
> mensagem que chega formatada é prova de que o worker está no ar e atualizado.

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
- [ ] Cron do `schedule:run` instalado (§9) e `php artisan sessoes:diagnostico` sem pontos de atenção.
- [ ] Pool do PHP-FPM ajustado (§9) — o padrão de 5 processos não aguenta o dia do evento.
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

### Travamentos, quedas e "a sessão caiu" (Sprint 244)

Comece pelo raio-X, **em cada instância**:

```bash
cd /var/www/fetecms && php artisan sessoes:diagnostico
```

Ele mostra a configuração da sessão, a tabela `sessions` (quantas, de quem, há quanto tempo), o
cache e a fila em banco e, no PostgreSQL, conexões e locks — e termina com os **pontos de atenção**.
O que procurar, do mais provável para o menos:

1. **PHP-FPM sem processo livre** (a causa mais comum de "travou" e de 502/504 em pico):
   ```bash
   sudo grep -c "max_children" /var/log/php8.4-fpm.log          # "server reached pm.max_children"
   sudo grep -E "upstream timed out|Resource temporarily unavailable|connect\(\) to unix" \
     /var/log/nginx/error.log | tail -n 20
   sudo awk '{print $9}' /var/log/nginx/access.log | grep -E '^(429|499|502|504)$' | sort | uniq -c
   ```
   `max_children` no log do FPM ou `Resource temporarily unavailable` no Nginx = faltou processo:
   ajuste o pool (§9). **502** = o FPM caiu ou o socket encheu; **504** = a requisição passou do
   tempo; **499** = o usuário desistiu de esperar (lentidão, não erro).
2. **429 em quem está logado**: toda a `/api/v1` tem limite de **120 requisições por minuto por
   conta**. A mesma conta aberta em vários aparelhos (o tablet do balcão, o mapa em tela cheia, o
   notebook da organização) divide esse limite, e as telas que se atualizam sozinhas passam a falhar
   — parece queda, e é o limite. O diagnóstico aponta a conta com 4 ou mais sessões na última hora.
   Saída: uma conta por aparelho (as contas temporárias existem para isso) ou subir o
   `throttle:120,1` de `routes/api.php`.
3. **`APP_KEY` diferente entre instâncias**: o cookie criptografado numa EC2 não abre na outra, e o
   ALB alterna as duas — o usuário "cai" sem padrão e vê 419. Compare a impressão digital que o
   diagnóstico imprime em cada instância; tem de ser igual. O mesmo vale para o `.env` em geral
   (`SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`): mudou, rode `optimize:clear && optimize` em todas.
4. **Banco no limite**: conexões perto do `max_connections`, conexão `idle in transaction` ou
   consulta esperando lock aparecem nos pontos de atenção. No console da AWS, olhe
   `DatabaseConnections`, `CPUUtilization` e `FreeableMemory` do RDS no horário do relato.
5. **Fila parada**: job pendente há mais de 15 min = `queue:work` fora do ar
   (`sudo systemctl status fetecms-queue`). Não derruba o portal, mas e-mail e distribuição param.
6. **Erros do Laravel** no horário do relato:
   ```bash
   grep -E "SQLSTATE|TokenMismatch|Allowed memory|Maximum execution time" storage/logs/laravel.log | tail -n 30
   ```

**Tempo de vida e faxina da sessão.** A sessão dura **5 horas sem atividade**
(`SESSION_LIFETIME=300`) — um turno de balcão inteiro — e `php artisan sessoes:limpar` (agendado de
15 em 15 minutos, §9) apaga do banco a que passou disso e o cache vencido. Rodar à mão é seguro:
sessão expirada já não autentica ninguém. `--horas=N` usa outro corte.

**O que o usuário vê quando a sessão cai.** Até a Sprint 244 a tela continuava aberta e cada ação
falhava em silêncio. Agora o 419 (token CSRF trocado por login ou logout em outra aba) renova o
cookie e repete o pedido uma vez, sem o usuário perceber; se a sessão acabou de verdade (401), o
portal leva ao login com o aviso "Sua sessão expirou". Relato de "travou" que vira "fui mandado para o
login" é sessão; relato de "travou" que continua igual é capacidade (itens 1, 2 e 4).
