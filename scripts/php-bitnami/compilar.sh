#!/usr/bin/env bash
# Compila o PHP corrigido com a mesma configuração do PHP do Bitnami.
#
# Não toca em /opt/bitnami: baixa o código-fonte, confere o SHA-256 publicado
# pelo php.net, refaz o ./configure do Bitnami e compila em ~/php-build. O
# servidor continua no ar o tempo todo. Quem instala é o instalar.sh.
#
#   bash scripts/php-bitnami/compilar.sh            # 8.3.36 (padrão)
#   VERSAO=8.3.37 bash scripts/php-bitnami/compilar.sh
#
# Por que a mesma série (8.3): as extensões que o Bitnami compilou à parte
# (apcu, imagick, redis…) são da API 20230831 e continuam valendo dentro da
# 8.3. Mudar para 8.4 pediria recompilar cada uma delas.

set -euo pipefail

PASTA="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSAO="${VERSAO:-8.3.36}"
export VERSAO
PHP_BIN="${PHP_BIN:-/opt/bitnami/php/bin/php}"
BASE="${BASE:-$HOME/php-build}"
FONTE="$BASE/php-$VERSAO"
ARQUIVO="php-$VERSAO.tar.xz"
export ARQUIVO

passo() { printf '\n\033[1;35m==> %s\033[0m\n' "$1"; }
falha() { printf '\n\033[1;31mERRO: %s\033[0m\n' "$1" >&2; exit 1; }

[ -x "$PHP_BIN" ] || falha "não achei $PHP_BIN (é um servidor Bitnami?)"
ATUAL="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
[ "${ATUAL%.*}" = "${VERSAO%.*}" ] || falha "o PHP atual é $ATUAL; este script troca só dentro da mesma série (${VERSAO%.*})."
INFO="$("$PHP_BIN" -i)"
grep -q '^Thread Safety => disabled' <<< "$INFO" || falha "o PHP atual é ZTS; este roteiro é para o NTS do Bitnami."

passo "Memória e disco"
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
SWAP_MB=$(awk '/SwapTotal/ {print int($2/1024)}' /proc/meminfo)
LIVRE_MB=$(df -Pm "$HOME" | awk 'NR==2 {print $4}')
echo "RAM ${MEM_MB} MB · swap ${SWAP_MB} MB · livre em $HOME ${LIVRE_MB} MB"
[ "$LIVRE_MB" -ge 2000 ] || falha "preciso de ~2 GB livres em $HOME para compilar."
if [ $((MEM_MB + SWAP_MB)) -lt 2000 ]; then
    falha "menos de 2 GB de RAM+swap: a compilação pode morrer no meio. Crie um swap antes (veja o roteiro, passo 3)."
fi
NPROC=$(nproc)
JOBS=$(( MEM_MB >= 4000 ? NPROC : (MEM_MB >= 1900 && NPROC > 1 ? 2 : 1) ))

passo "Dependências de compilação (apt)"
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    build-essential autoconf bison re2c pkg-config xz-utils curl ca-certificates \
    libxml2-dev libsqlite3-dev libssl-dev zlib1g-dev libbz2-dev libcurl4-openssl-dev \
    libonig-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev libxpm-dev \
    libicu-dev libpq-dev libreadline-dev libedit-dev libsodium-dev libargon2-dev \
    libxslt1-dev libgmp-dev libtidy-dev libldap2-dev libsasl2-dev libkrb5-dev libffi-dev \
    libc-client2007e-dev freetds-dev

passo "Código-fonte do PHP $VERSAO"
mkdir -p "$BASE"
cd "$BASE"
if [ ! -f "$ARQUIVO" ]; then
    curl -fL --retry 3 -o "$ARQUIVO" "https://www.php.net/distributions/$ARQUIVO"
fi
# O SHA-256 vem da API de releases do php.net (a mesma da página de download).
ESPERADO="$(curl -fsS "https://www.php.net/releases/index.php?json&version=$VERSAO" \
    | "$PHP_BIN" -r '$r = json_decode(stream_get_contents(STDIN), true);
        foreach ($r["source"] ?? [] as $s) { if (($s["filename"] ?? "") === getenv("ARQUIVO")) { echo $s["sha256"] ?? ""; } }' )"
[ -n "$ESPERADO" ] || falha "o php.net não devolveu o SHA-256 de $ARQUIVO (a versão existe?)."
OBTIDO="$(sha256sum "$ARQUIVO" | awk '{print $1}')"
[ "$ESPERADO" = "$OBTIDO" ] || falha "SHA-256 não confere: esperado $ESPERADO, obtido $OBTIDO. Apague $BASE/$ARQUIVO e tente de novo."
echo "SHA-256 confere: $OBTIDO"
rm -rf "$FONTE"
tar -xf "$ARQUIVO"

passo "Linha de configure do Bitnami"
LINHA="$(grep '^Configure Command' <<< "$INFO" | sed 's/^Configure Command => //')"
[ -n "$LINHA" ] || falha "não achei a linha de configure no php -i."
echo "$LINHA" > "$BASE/configure-bitnami.txt"
eval "ORIGINAL=( $LINHA )"

# O Bitnami compila numa sandbox (/bitnami/blacksmith-sandox/…) que não existe
# no servidor. O caminho que não existe sai da opção, e a biblioteca passa a
# vir do sistema (os pacotes -dev acima). O resto da linha fica como está.
ARGS=()
REMOVIDOS=()
existe() { [ -e "$1" ]; }
for a in "${ORIGINAL[@]}"; do
    case "$a" in */configure|configure) continue ;; esac

    if [[ "$a" =~ ^(--(with|enable)-[A-Za-z0-9_-]+)=(.*)$ ]]; then
        nome="${BASH_REMATCH[1]}"; valor="${BASH_REMATCH[3]}"
        IFS=',' read -r -a partes <<< "$valor"
        mantidas=()
        for p in "${partes[@]}"; do
            if [[ "$p" == /* ]] && ! existe "$p"; then REMOVIDOS+=("$nome: $p"); else mantidas+=("$p"); fi
        done
        if [ ${#mantidas[@]} -eq 0 ]; then ARGS+=("$nome"); else ARGS+=("$nome=$(IFS=,; echo "${mantidas[*]}")"); fi
    elif [[ "$a" =~ ^([A-Z_]+)=(.*)$ ]]; then
        var="${BASH_REMATCH[1]}"; valor="${BASH_REMATCH[2]}"
        if [[ "$var" == *PATH ]]; then
            IFS=':' read -r -a partes <<< "$valor"; mantidas=()
            for p in "${partes[@]}"; do existe "$p" && mantidas+=("$p") || REMOVIDOS+=("$var: $p"); done
            ARGS+=("$var=$(IFS=:; echo "${mantidas[*]}")")
        else
            novo=()
            for w in $valor; do
                if [[ "$w" =~ ^-[IL](/.*)$ ]] && ! existe "${BASH_REMATCH[1]}"; then REMOVIDOS+=("$var: $w"); else novo+=("$w"); fi
            done
            ARGS+=("$var=${novo[*]}")
        fi
    else
        ARGS+=("$a")
    fi
done

printf '%s\n' "${ARGS[@]}" > "$BASE/configure-usado.txt"
echo "Opções: ${#ARGS[@]} (gravadas em $BASE/configure-usado.txt)"
if [ ${#REMOVIDOS[@]} -gt 0 ]; then
    echo "Caminhos que não existem neste servidor e saíram da linha (a biblioteca vem do sistema):"
    printf '  %s\n' "${REMOVIDOS[@]}"
fi
printf '%s\n' "${ARGS[@]}" | grep -q -- '^--prefix=/opt/bitnami/php$' || falha "a linha não instala em /opt/bitnami/php — pare e me mande o configure-bitnami.txt."

passo "./configure"
cd "$FONTE"
if ! ./configure "${ARGS[@]}" > "$BASE/configure.log" 2>&1; then
    tail -n 25 "$BASE/configure.log"
    falha "o configure parou. A última linha costuma dizer a biblioteca que falta: instale o pacote -dev dela (apt search <nome>-dev) e rode de novo."
fi
grep -E 'WARNING|Unrecognized|unrecognized' "$BASE/configure.log" | sort -u | head -20 || true

passo "make -j$JOBS (demora: de 10 a 40 minutos)"
if ! make -j"$JOBS" > "$BASE/make.log" 2>&1; then
    tail -n 30 "$BASE/make.log"
    falha "a compilação falhou (log completo em $BASE/make.log)."
fi

passo "Conferência do binário novo (ainda fora de /opt/bitnami)"
sapi/cli/php -n -v | head -1
sapi/fpm/php-fpm -n -v | head -1
sapi/cli/php -n -r 'exit(PHP_VERSION === getenv("VERSAO") ? 0 : 1);' || falha "o binário novo não é o $VERSAO."

# Toda extensão do php -m de hoje precisa continuar existindo: embutida no
# binário novo, compilada agora como .so, ou um .so do Bitnami que fica.
EXT_DIR="$("$PHP_BIN" -r 'echo ini_get("extension_dir");')"
NOVAS="$( (sapi/cli/php -n -m; ls modules/*.so 2>/dev/null | xargs -rn1 basename | sed 's/\.so$//'; ls "$EXT_DIR"/*.so 2>/dev/null | xargs -rn1 basename | sed 's/\.so$//') | tr 'A-Z' 'a-z' | sed 's/ /_/g' | sort -u)"
FALTANDO=()
while read -r m; do
    [ -z "$m" ] || [[ "$m" == \[* ]] && continue
    chave="$(echo "$m" | tr 'A-Z' 'a-z' | sed 's/ /_/g; s/^zend_opcache$/opcache/')"
    grep -qx "$chave" <<< "$NOVAS" || FALTANDO+=("$m")
done < <("$PHP_BIN" -m)
if [ ${#FALTANDO[@]} -gt 0 ]; then
    echo "Extensões de hoje que o binário novo não tem: ${FALTANDO[*]}"
    falha "não instale. Me mande este aviso e o $BASE/configure-usado.txt."
fi
echo "Todas as extensões de hoje estão cobertas."

printf '\nPronto para instalar: sudo bash %s/instalar.sh %s\n' "$PASTA" "$FONTE"
