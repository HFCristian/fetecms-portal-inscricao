# Atualizar o PHP do servidor Bitnami (PHP2Shell)

> Sprint 261. Servidor: Bitnami no Lightsail (`bitnami@ip-172-26-0-201`, portal em `~/htdocs/fetecms`),
> hoje com **PHP 8.3.22**. Alvo: **PHP 8.3.36**.

## Por que

Em 21/10/2026 o PHP publicou correções para **CVE-2026-109618** e **CVE-2026-109619** ("PHP2Shell"):
duas falhas de memória no núcleo do PHP que permitem **execução remota de código sem login** em qualquer
PHP que atende requisições web (PHP-FPM, mod_php, php-cgi). CVSS 9,2. Há prova de conceito pública.

Versões corrigidas: **8.2.35, 8.3.36, 8.4.27 e 8.5.12**. Tudo abaixo disso, na mesma série, está
vulnerável — inclusive o 8.3.22 do servidor. Mexer na configuração só reduz a exposição; o conserto é
atualizar e reiniciar todos os processos do PHP.

## Por que recompilar (e não `apt upgrade`)

O Bitnami não instala o PHP pelo `apt`: ele traz um PHP compilado por ele em `/opt/bitnami/php`. Não há
pacote para atualizar. O caminho escolhido é compilar o **8.3.36 com a mesma linha de `configure` do
Bitnami**, no mesmo lugar. Fica tudo como está: `php.ini`, pools do PHP-FPM, Apache/Nginx, caminhos.

Ficar na série **8.3** importa: as extensões que o Bitnami compilou à parte (apcu, imagick, redis…) são
da API `20230831` e continuam valendo em qualquer 8.3.x. Ir para 8.4 pediria recompilar cada uma.

O preço: cada correção futura do PHP pede outra compilação (os mesmos scripts, com `VERSAO=`). A série
8.3 recebe correções de segurança até **31/12/2027**.

## O que você vai precisar

- **30 a 60 minutos**. A compilação (10 a 40 min) roda **com o site no ar**. O site só sai do ar na
  instalação, por **cerca de 1 minuto** (para o PHP-FPM, instala, sobe). Faça fora do horário do evento.
- **2 GB livres** em disco e **2 GB de RAM + swap** (o script confere).
- Os scripts em `scripts/php-bitnami/` deste repositório no servidor (`git pull` depois do merge, ou
  copie a pasta com `scp`).

| Script | Faz | Mexe no servidor? |
|---|---|---|
| `diagnostico.sh` | retrato do PHP de hoje (versão, extensões, configure, PHP-FPM) | não, só lê |
| `compilar.sh` | baixa o 8.3.36, confere o SHA-256 do php.net, compila em `~/php-build` | instala pacotes `-dev` pelo apt; não toca em `/opt/bitnami` |
| `instalar.sh` | backup de `/opt/bitnami/php`, instala, sobe o PHP-FPM, confere; **volta sozinho** se o PHP-FPM não subir | sim |
| `reverter.sh` | volta para o backup | sim |

## Passo a passo

### 1. Snapshot da instância (a rede de segurança de verdade)

No console do Lightsail: **Instâncias → (a instância) → Snapshots → Criar snapshot**. Espere ficar
"Disponível". Se tudo der errado, é daqui que se volta.

### 2. Diagnóstico

```bash
cd ~/htdocs/fetecms
bash scripts/php-bitnami/diagnostico.sh
```

Ele grava uma pasta `~/php-diagnostico-<data>`. **Me mande o `resumo.txt` e o `configure.txt`** antes de
compilar: com eles eu confiro a linha de configure e as extensões do seu servidor.

Conferências rápidas no resumo:

- `Server API` do CLI e o PHP-FPM respondendo `8.3.22`.
- "Módulos PHP no Apache" **vazio** = o site usa PHP-FPM (o normal no Bitnami). Se aparecer
  `php_module`, é mod_php — o caso mais grave da falha; o `instalar.sh` reinicia o Apache nesse caso.

### 3. Swap (só se a instância tiver menos de 2 GB de RAM)

```bash
free -h
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
```

Depois da atualização: `sudo swapoff /swapfile && sudo rm /swapfile`.

### 4. Compilar (site no ar)

```bash
cd ~/htdocs/fetecms
bash scripts/php-bitnami/compilar.sh
```

O script:

1. instala as bibliotecas de compilação (`build-essential`, `libxml2-dev`, `libssl-dev`, `libicu-dev`,
   `libpq-dev`…);
2. baixa `php-8.3.36.tar.xz` do php.net e **confere o SHA-256** publicado na API de releases — se não
   bater, para;
3. lê a linha de configure do PHP de hoje (`php -i`). O Bitnami compila numa sandbox
   (`/bitnami/blacksmith-sandox/…`) que não existe no servidor: os caminhos que não existem saem da
   linha, e a biblioteca passa a vir do sistema. O script lista o que tirou;
4. roda `./configure` e `make`;
5. confere o binário novo **antes de instalar**: versão 8.3.36 e **todas as extensões do `php -m` de
   hoje** presentes. Faltando alguma, ele para e diz qual.

Termina com a linha exata do próximo passo. Logs em `~/php-build/` (`configure.log`, `make.log`).

### 5. Instalar (cerca de 1 minuto fora do ar)

```bash
sudo bash scripts/php-bitnami/instalar.sh ~/php-build/php-8.3.36
```

O script copia `/opt/bitnami/php` para `/opt/bitnami/php-backup-8.3.22-<data>`, para o PHP-FPM
(`ctlscript.sh stop php-fpm`), roda `make install`, testa a configuração (`php-fpm -t`) e sobe o
PHP-FPM. **Se o PHP-FPM não subir, ele volta sozinho para o backup.** No fim compara o `php -m` de antes
e de depois.

O `make install` não sobrescreve `php.ini` nem os pools do PHP-FPM (ele grava só os `*.default`).

### 6. Depois de instalar

```bash
cd ~/htdocs/fetecms
php -v                                # PHP 8.3.36
php artisan optimize:clear && php artisan optimize
php artisan queue:restart             # o worker da fila sobe de novo já no PHP novo
curl -sI https://SEU-DOMINIO/ | head -1
bash scripts/php-bitnami/diagnostico.sh   # o "depois", para comparar com o "antes"
```

Se o worker da fila roda por supervisor ou systemd, reinicie o serviço dele também: um processo que
ficou de pé ainda roda o 8.3.22 em memória.

Teste no navegador: login, a aba Certificados, um upload e um download. Olhe o log:
`sudo tail -n 50 /opt/bitnami/php/var/log/php-fpm.log` e `storage/logs/laravel.log`.

### 7. Se algo der errado

```bash
ls -d /opt/bitnami/php-backup-*
sudo bash scripts/php-bitnami/reverter.sh /opt/bitnami/php-backup-8.3.22-AAAAMMDD-HHMMSS
php artisan queue:restart
```

Se nem o reverter resolver: restaure o **snapshot** do passo 1.

### 8. Limpeza (uma semana depois, com tudo estável)

```bash
sudo rm -rf /opt/bitnami/php-backup-8.3.22-* ~/php-build
```

## Problemas comuns

| Sintoma | O que fazer |
|---|---|
| `configure` para em "Cannot find …" / "Package … not found" | instale o `-dev` da biblioteca (`apt search <nome>-dev`) e rode o `compilar.sh` de novo |
| "Extensões de hoje que o binário novo não tem: …" | não instale; me mande o aviso e o `~/php-build/configure-usado.txt` |
| `make` morre sem mensagem (`Killed`) | falta memória: crie o swap do passo 3 |
| 502/503 depois de instalar | `sudo /opt/bitnami/ctlscript.sh status`; `sudo tail /opt/bitnami/php/var/log/php-fpm.log`; se não resolver, `reverter.sh` |
| Aviso de PEAR no fim do `make install` | inofensivo: o PEAR é o último passo e o PHP já está instalado (o script confere) |

## Próximas correções

Saiu um 8.3.37? Os mesmos passos, com a versão na frente:

```bash
VERSAO=8.3.37 bash scripts/php-bitnami/compilar.sh
sudo bash scripts/php-bitnami/instalar.sh ~/php-build/php-8.3.37
```

E atualize `config.platform.php` no `composer.json` para a mesma versão (o repositório já está em
`8.3.36`), para o Composer nunca resolver um pacote que peça mais do que o servidor tem.

A longo prazo, vale sair do PHP compilado à mão: um PHP do `apt` (pacotes do Sury) ou uma instância
nova com o PHP do sistema recebe correção com `apt upgrade`. É o que o
[DEPLOY_AWS.md](DEPLOY_AWS.md) descreve para a AWS.
