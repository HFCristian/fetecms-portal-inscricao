#!/usr/bin/env bash
# Retrato do PHP do servidor Bitnami, antes (e depois) da atualização.
#
# Só lê: não instala, não para nem reinicia nada. Grava tudo numa pasta em
# ~/php-diagnostico-AAAAMMDD-HHMMSS e imprime o resumo. Rode antes de compilar
# (é a referência de comparação) e de novo depois de instalar.
#
#   bash scripts/php-bitnami/diagnostico.sh

set -uo pipefail

PHP_BIN="${PHP_BIN:-/opt/bitnami/php/bin/php}"
FPM_BIN="${FPM_BIN:-/opt/bitnami/php/sbin/php-fpm}"
SAIDA="${HOME}/php-diagnostico-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$SAIDA"

secao() { printf '\n== %s\n' "$1"; }

{
    secao "Sistema"
    grep -E '^(PRETTY_NAME|VERSION_CODENAME)=' /etc/os-release
    echo "CPUs: $(nproc)"
    free -h | sed -n '1,3p'
    df -h / /opt /tmp 2>/dev/null | sort -u

    secao "PHP (CLI)"
    command -v php
    "$PHP_BIN" -v
    "$PHP_BIN" --ini
    "$PHP_BIN" -r 'echo "extension_dir: ", ini_get("extension_dir"), PHP_EOL, "API: ", PHP_API_VERSION ?? "", PHP_EOL;' 2>/dev/null
    "$PHP_BIN" -i | grep -E '^(Server API|Thread Safety|Zend Extension Build|PHP Extension Build)'

    secao "PHP-FPM"
    "$FPM_BIN" -v 2>&1 | head -1
    "$FPM_BIN" -tt 2>&1 | grep -E 'NOTICE: (\[global\]|\[www\]|	listen|	user|	group|	pm\b|	pm.max_children)' | head -20

    secao "Servidor web"
    if [ -x /opt/bitnami/apache/bin/httpd ]; then
        /opt/bitnami/apache/bin/httpd -v | head -1
        echo "Módulos PHP no Apache (vazio = PHP-FPM, sem mod_php):"
        /opt/bitnami/apache/bin/httpd -M 2>/dev/null | grep -i php || true
    fi
    [ -x /opt/bitnami/nginx/sbin/nginx ] && /opt/bitnami/nginx/sbin/nginx -v 2>&1

    secao "Serviços Bitnami"
    sudo -n /opt/bitnami/ctlscript.sh status 2>/dev/null || echo "(rode com sudo para ver: sudo /opt/bitnami/ctlscript.sh status)"

    secao "Bibliotecas do binário que vêm de /opt/bitnami"
    ldd "$PHP_BIN" | grep -i bitnami || echo "(nenhuma)"
} 2>&1 | tee "$SAIDA/resumo.txt"

"$PHP_BIN" -m > "$SAIDA/modulos.txt" 2>&1
"$PHP_BIN" -i | grep '^Configure Command' | sed 's/^Configure Command => //' > "$SAIDA/configure.txt"
ls -1 "$("$PHP_BIN" -r 'echo ini_get("extension_dir");')" > "$SAIDA/extensoes-so.txt" 2>&1
"$PHP_BIN" -v | head -1 > "$SAIDA/versao.txt"

printf '\nGravado em %s:\n' "$SAIDA"
printf '  resumo.txt        o que está acima\n'
printf '  modulos.txt       php -m (a lista que precisa continuar igual)\n'
printf '  configure.txt     a linha de configure do Bitnami\n'
printf '  extensoes-so.txt  as extensões .so instaladas\n'
