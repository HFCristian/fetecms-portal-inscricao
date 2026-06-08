# Setup de desenvolvimento

Guia para rodar o **Portal de Inscrição XVI FETECMS** numa máquina de desenvolvimento
a partir de um clone do GitHub. Cobre **Linux (Zorin OS / Ubuntu)** e resume o equivalente
no **Windows**.

> Stack: **Laravel 13 / PHP 8.3+** (API REST + Sanctum) · **React + Vite** (SPA servida pelo
> próprio Laravel, mesma origem) · banco local **SQLite**.

---

## 1. Pré-requisitos

| Ferramenta | Versão | Observação |
|-----------|--------|------------|
| PHP | **8.3+** | com extensões: `sqlite3`, `mbstring`, `xml`, `curl`, `bcmath`, `intl`, `zip` |
| Composer | 2.x | gerenciador de dependências PHP |
| Node.js | **22+** | Vite 8 exige Node 20.19+/22+ |
| npm | 10+ | vem com o Node |
| Git | qualquer | — |

No **Windows** usamos o **Laravel Herd** (traz PHP + Composer). **No Linux não há Herd** —
instale PHP/Composer/Node manualmente (passo 2). Depois do passo 2, o resto (passo 3 em diante)
é **idêntico** nos dois sistemas.

---

## 2. Instalar as dependências do sistema (Zorin OS / Ubuntu)

Zorin OS é baseado em Ubuntu, então usamos `apt`. O PPA do **ondřej** garante um PHP recente.

```bash
# --- PHP 8.3 + extensões ---
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y \
  php8.3 php8.3-cli php8.3-common \
  php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-bcmath php8.3-intl php8.3-zip

php -v   # deve mostrar PHP 8.3.x (ou superior)

# --- Composer ---
sudo apt install -y composer
composer --version

# --- Node.js 22 (via NodeSource) ---
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v && npm -v   # node deve ser v22.x
```

> Se o `apt` instalar um Composer antigo, baixe o oficial em <https://getcomposer.org/download/>.
> Para alternar versões de PHP do sistema: `sudo update-alternatives --config php`.

---

## 3. Configurar o projeto (após `git clone`)

```bash
git clone <URL-do-repo> fetecms-portal-inscricao
cd fetecms-portal-inscricao

# 1) Dependências
composer install
npm install

# 2) Ambiente: cria o .env a partir do exemplo e gera a APP_KEY
cp .env.example .env
php artisan key:generate

# 3) Banco SQLite local (o arquivo é ignorado pelo git, então criamos vazio)
touch database/database.sqlite
php artisan migrate:fresh --seed
```

O `.env.example` já vem pronto para **SQLite + Sanctum SPA em `localhost:8000`**
(`DB_CONNECTION=sqlite`, `SESSION_DOMAIN=localhost`, `SANCTUM_STATEFUL_DOMAINS=localhost:8000,...`).
Normalmente **não precisa editar nada** para o ambiente local.

---

## 4. Rodar (2 terminais)

```bash
# Terminal 1 — API + app Laravel
php artisan serve            # http://localhost:8000

# Terminal 2 — Vite (HMR do React)
npm run dev
```

Acesse **http://localhost:8000**.

> ⚠️ **Use `localhost`, não `127.0.0.1`.** O cookie de sessão do Sanctum está amarrado ao
> domínio `localhost` (`SESSION_DOMAIN`). Acessar por `127.0.0.1:8000` quebra o login.

### Contas demo (criadas pelo seed)

| Papel | E-mail | Senha |
|------|--------|-------|
| Admin | `admin@fetecms.test` | `password` |
| Orientador | `orientador@fetecms.test` | `password` |
| Avaliador | `avaliador@fetecms.test` | `password` |

---

## 5. Testes

```bash
php artisan test     # backend (PHPUnit) — feature, unit, segurança/autorização
npm test             # frontend (Vitest) — componentes React
```

---

## 6. Comandos úteis do dia a dia

```bash
php artisan migrate:fresh --seed   # recria o banco do zero + dados demo
php artisan migrate                # aplica migrations novas
php artisan test --filter=Nome     # roda um teste específico
npm run build                      # build de produção do front (gera public/build)
./vendor/bin/pint --dirty          # padroniza o estilo do PHP alterado
```

---

## 7. Solução de problemas

- **Tela branca / assets não carregam:** quase sempre é o **HMR do Vite** travado ou o
  navegador com cache antigo. Reinicie o `npm run dev` e dê **Ctrl+Shift+R** no navegador.
- **App tenta carregar de um Vite que não existe:** apague o arquivo órfão
  `public/hot` (ele é criado pelo `npm run dev` e aponta para o dev server; se o dev server
  não estiver rodando, o Laravel tenta usá-lo mesmo assim). `rm -f public/hot`.
- **Login não persiste / 419 / CSRF:** confirme que está acessando por `http://localhost:8000`
  (e não `127.0.0.1`) e que rodou `php artisan key:generate`.
- **Permissão negada em `storage/` ou `bootstrap/cache/`:** garanta que seu usuário tem escrita
  nessas pastas: `chmod -R u+rwX storage bootstrap/cache`.
- **Porta 8000 ocupada:** `php artisan serve --port=8001` (lembre de ajustar a URL e, se mudar a
  porta, os domínios em `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` no `.env`).
- **Erro de extensão PHP (ex.: `could not find driver`):** falta a extensão SQLite —
  `sudo apt install php8.3-sqlite3` e reinicie o terminal.

---

## 8. O que **não** vem do GitHub (e por quê)

Estes itens estão no `.gitignore` e são recriados pelos passos acima — **é normal não existirem
após o clone**:

| Item | Como recriar |
|------|--------------|
| `.env` | `cp .env.example .env` + `php artisan key:generate` |
| `database/database.sqlite` (+ dados) | `touch` + `php artisan migrate:fresh --seed` |
| `vendor/` | `composer install` |
| `node_modules/` | `npm install` |
| `public/build` | `npm run build` (ou `npm run dev` em desenvolvimento) |

> **Memória do projeto:** as decisões de arquitetura, papéis, regras de negócio e o histórico de
> sprints estão no **`CLAUDE.md`** (versionado no repo). Ele é lido automaticamente pelo Claude Code
> na outra máquina — **não é preciso "compilar" o chat** para continuar o desenvolvimento.
