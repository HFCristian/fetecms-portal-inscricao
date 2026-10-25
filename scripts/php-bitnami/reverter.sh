#!/usr/bin/env bash
# Volta o PHP do Bitnami para um backup feito pelo instalar.sh.
#
#   sudo bash scripts/php-bitnami/reverter.sh /opt/bitnami/php-backup-8.3.22-AAAAMMDD-HHMMSS

set -euo pipefail

BACKUP="${1:-}"
BITNAMI=/opt/bitnami
CTL="$BITNAMI/ctlscript.sh"

[ "$(id -u)" -eq 0 ] || { echo "Rode com sudo." >&2; exit 1; }
[ -n "$BACKUP" ] && [ -x "$BACKUP/bin/php" ] || { echo "Informe o backup: ls -d $BITNAMI/php-backup-*" >&2; exit 1; }

ATUAL="$("$BITNAMI/php/bin/php" -r 'echo PHP_VERSION;' 2>/dev/null || echo desconhecida)"
"$CTL" stop php-fpm || true
mv "$BITNAMI/php" "$BITNAMI/php-revertido-$ATUAL-$(date +%Y%m%d-%H%M%S)"
cp -a "$BACKUP" "$BITNAMI/php"
"$CTL" start php-fpm
if [ -x "$BITNAMI/apache/bin/httpd" ] && "$BITNAMI/apache/bin/httpd" -M 2>/dev/null | grep -qi 'php[0-9_]*_module'; then
    "$CTL" restart apache
fi
"$BITNAMI/php/bin/php" -v | head -1
echo "Lembre: php artisan queue:restart (na pasta do portal)."
