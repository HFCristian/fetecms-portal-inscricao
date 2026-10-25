#!/usr/bin/env bash
# Instala o PHP compilado pelo compilar.sh em /opt/bitnami/php.
#
# Antes de tudo copia o PHP de hoje para /opt/bitnami/php-backup-<versão>-<data>.
# Para o PHP-FPM, roda o `make install`, sobe o PHP-FPM de novo e confere. Se o
# PHP-FPM não subir, volta sozinho para o backup. O site fica fora do ar só
# entre o stop e o start (cerca de um minuto).
#
#   sudo bash scripts/php-bitnami/instalar.sh ~/php-build/php-8.3.36

set -euo pipefail

FONTE="${1:-}"
BITNAMI=/opt/bitnami
PHP_DIR="$BITNAMI/php"
CTL="$BITNAMI/ctlscript.sh"

passo() { printf '\n\033[1;35m==> %s\033[0m\n' "$1"; }
falha() { printf '\n\033[1;31mERRO: %s\033[0m\n' "$1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || falha "rode com sudo."
[ -n "$FONTE" ] && [ -x "$FONTE/sapi/cli/php" ] || falha "informe a pasta compilada: sudo bash $0 ~/php-build/php-8.3.36"
[ -x "$CTL" ] || falha "não achei $CTL."

NOVA="$("$FONTE/sapi/cli/php" -n -r 'echo PHP_VERSION;')"
ATUAL="$("$PHP_DIR/bin/php" -r 'echo PHP_VERSION;')"
BACKUP="$BITNAMI/php-backup-$ATUAL-$(date +%Y%m%d-%H%M%S)"
echo "PHP atual: $ATUAL · novo: $NOVA"
[ "$NOVA" != "$ATUAL" ] || falha "o PHP $NOVA já está instalado."

# Apache com mod_php precisa de restart do Apache; com PHP-FPM, não.
MOD_PHP=0
if [ -x "$BITNAMI/apache/bin/httpd" ] && "$BITNAMI/apache/bin/httpd" -M 2>/dev/null | grep -qi 'php[0-9_]*_module'; then
    MOD_PHP=1
fi

reverter() {
    printf '\n\033[1;31m==> Voltando para o PHP %s (backup)\033[0m\n' "$ATUAL"
    "$CTL" stop php-fpm || true
    mv "$PHP_DIR" "$BITNAMI/php-falhou-$NOVA-$(date +%Y%m%d-%H%M%S)"
    cp -a "$BACKUP" "$PHP_DIR"
    "$CTL" start php-fpm
    [ "$MOD_PHP" -eq 1 ] && "$CTL" restart apache
    "$PHP_DIR/bin/php" -v | head -1
    falha "a instalação foi desfeita; o site está no PHP $ATUAL. Me mande o $BITNAMI/php/var/log/php-fpm.log."
}

passo "Backup em $BACKUP"
cp -a "$PHP_DIR" "$BACKUP"
du -sh "$BACKUP"

passo "Parando o PHP-FPM"
"$CTL" stop php-fpm

passo "make install"
if ! (cd "$FONTE" && make install > "$FONTE/install.log" 2>&1); then
    tail -n 20 "$FONTE/install.log"
    # O PEAR é o último alvo e baixa da internet: se só ele falhou, o PHP já está no lugar.
    if "$PHP_DIR/bin/php" -r 'exit(PHP_VERSION === $argv[1] ? 0 : 1);' "$NOVA" 2>/dev/null; then
        echo "Aviso: o make install terminou com erro, mas o PHP $NOVA já está instalado (provavelmente só o PEAR)."
    else
        reverter
    fi
fi

passo "Subindo o PHP-FPM"
"$PHP_DIR/sbin/php-fpm" -t || reverter
"$CTL" start php-fpm || reverter
[ "$MOD_PHP" -eq 1 ] && { "$CTL" restart apache || reverter; }
sleep 2
"$CTL" status || true

passo "Conferência"
"$PHP_DIR/bin/php" -v | head -1
"$PHP_DIR/sbin/php-fpm" -v | head -1
"$PHP_DIR/bin/php" -r 'exit(PHP_VERSION === $argv[1] ? 0 : 1);' "$NOVA" || reverter

"$BACKUP/bin/php" -m > /tmp/php-m-antes.txt
"$PHP_DIR/bin/php" -m > /tmp/php-m-depois.txt
if diff -u /tmp/php-m-antes.txt /tmp/php-m-depois.txt; then
    echo "php -m: as mesmas extensões de antes."
else
    echo "Aviso: a lista de extensões mudou (acima). Confira antes de seguir."
fi

cat <<FIM

PHP $NOVA instalado. Backup do $ATUAL em $BACKUP.

Falta (como o usuário bitnami, na pasta do portal):
  php artisan optimize:clear && php artisan optimize
  php artisan queue:restart          # o worker da fila sobe de novo já no PHP novo
  curl -sI https://SEU-DOMINIO/ | head -1

Para voltar ao $ATUAL: sudo bash $(dirname "$0")/reverter.sh $BACKUP
FIM
