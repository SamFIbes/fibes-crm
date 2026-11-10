#!/bin/sh
# Same sandbox as run-boot.sh, for docker/entrypoint.prod.sh (the plain
# Dockerfile's production target). Kept separate so run-boot.sh stays identical
# to main's copy. Runs the entrypoint in a user namespace with stubbed php, su, and
# supervisord. Prints the entrypoint's exit code, stdout, stderr, and every
# php invocation so tests can see migrate versus db:seed.
set -eu

kind="${1:?prod}"
seed="${2:?unset, true, or false}"
migrations="${3:?unset, true, or false}"

case "$kind" in
    prod) ;;
    *) echo "Unknown entrypoint: $kind" >&2; exit 2 ;;
esac

case "$seed" in
    unset|true|false) ;;
    *) echo "Unknown FIBES_RUN_SEED value: $seed" >&2; exit 2 ;;
esac

case "$migrations" in
    unset|true|false) ;;
    *) echo "Unknown FIBES_RUN_MIGRATIONS value: $migrations" >&2; exit 2 ;;
esac

repo=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/app" "$work/ov/upper/bin" "$work/ov/work"
: > "$work/cmds.log"
: > "$work/stdout"
: > "$work/stderr"

cat > "$work/bin/php" << 'EOF'
#!/bin/sh
printf '%s\n' "$*" >> "$FIBES_TEST_LOG"
exit 0
EOF

cat > "$work/bin/su" << 'EOF'
#!/bin/sh
prev=""
cmd=""
for arg in "$@"; do
    if [ "$prev" = "-c" ]; then
        cmd=$arg
    fi
    prev=$arg
done
if [ -n "$cmd" ]; then
    sh -c "$cmd"
fi
exit 0
EOF

cat > "$work/bin/chown" << 'EOF'
#!/bin/sh
exit 0
EOF

cat > "$work/bin/envsubst" << 'EOF'
#!/bin/sh
cat >/dev/null
exit 0
EOF

cat > "$work/bin/apache2-foreground" << 'EOF'
#!/bin/sh
exit 0
EOF

cat > "$work/supervisord" << 'EOF'
#!/bin/sh
exit 0
EOF

chmod 755 "$work/bin/php" "$work/bin/su" "$work/bin/chown" "$work/bin/envsubst" "$work/bin/apache2-foreground" "$work/supervisord"

cat > "$work/inner.sh" << 'EOF'
#!/bin/bash
set -eu
repo="$1"
work="$2"
kind="$3"

mount -t tmpfs tmpfs /etc
mount -t tmpfs tmpfs /var
mkdir -p /etc/nginx/conf.d /etc/apache2/mods-enabled /var/www/html
printf 'listen ${PORT};\n' > /etc/nginx/fibes.conf.template
printf 'Listen 80\n' > /etc/apache2/ports.conf
printf 'prefork\n' > /etc/apache2/mods-enabled/mpm_prefork.load

mount -t overlay overlay -o lowerdir=/usr,upperdir="$work/ov/upper",workdir="$work/ov/work" /usr
cp "$work/supervisord" /usr/bin/supervisord
chmod 755 /usr/bin/supervisord

export PATH="$work/bin:$PATH"
export FIBES_TEST_LOG="$work/cmds.log"
export FIBES_APP_DIR="$work/app"
export PORT=10000
export APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
export DB_HOST="127.0.0.1"
export DB_PORT="5432"
export DB_DATABASE="fibes"
export DB_USERNAME="fibes"
export DB_PASSWORD="s3cure-db-password"
export ADMIN_PASSWORD="CorrectHorse12"
unset DATABASE_URL DATABASE_PRIVATE_URL || true

set +e
if [ "$kind" = "prod" ]; then
    sh "$repo/docker/entrypoint.prod.sh" true >"$work/stdout" 2>"$work/stderr"
else
    echo "unknown kind: $kind" >&2
    exit 2
fi
status=$?
set -e
printf '%s\n' "$status" > "$work/exit"
EOF
chmod 755 "$work/inner.sh"

unset FIBES_RUN_SEED FIBES_RUN_MIGRATIONS || true
if [ "$seed" != "unset" ]; then
    export FIBES_RUN_SEED="$seed"
fi
if [ "$migrations" != "unset" ]; then
    export FIBES_RUN_MIGRATIONS="$migrations"
fi

unshare --user --map-root-user --mount bash "$work/inner.sh" "$repo" "$work" "$kind"

echo "---ENTRY-EXIT---"
cat "$work/exit"
echo "---ENTRY-STDOUT---"
cat "$work/stdout"
echo "---ENTRY-STDERR---"
cat "$work/stderr"
echo "---CMDS---"
cat "$work/cmds.log"
