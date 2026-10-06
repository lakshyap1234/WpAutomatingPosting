#!/usr/bin/env bash
# One-time setup of the two local sites (see dev/README.md). Safe to run again.
#   dev/setup.sh              certificates, dev/.env, containers, WordPress, plugins, users
#   dev/setup.sh --add-hosts  also add agency.test/client.test to /etc/hosts (uses sudo)
set -euo pipefail
DEV="$(cd "$(dirname "$0")" && pwd)"
cd "$DEV"
HOSTS=(agency.test client.test)

say() { printf '\n==> %s\n' "$*"; }
die() { printf '\ndev/setup.sh: %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null || die "Docker is needed (Docker Desktop, OrbStack, or Docker Engine)."
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is needed (the 'docker compose' command)."
command -v mkcert >/dev/null || die "mkcert is needed for the local HTTPS certificates: https://github.com/FiloSottile/mkcert#installation"

# 1. Hostnames: the browser must reach the same names the containers use.
missing=()
for h in "${HOSTS[@]}"; do
  grep -Eq "^[[:space:]]*127\.0\.0\.1[[:space:]].*\b${h//./\\.}\b" /etc/hosts || missing+=("$h")
done
if (( ${#missing[@]} )); then
  if [[ "${1:-}" == --add-hosts ]]; then
    say "Adding ${missing[*]} to /etc/hosts (sudo)"
    printf '127.0.0.1 %s\n' "${missing[@]}" | sudo tee -a /etc/hosts >/dev/null
  else
    die "Add this line to /etc/hosts (or run dev/setup.sh --add-hosts):
    127.0.0.1 ${missing[*]}"
  fi
fi

# 2. HTTPS: one certificate for both names, from mkcert's local CA (trusted by your
#    browser after 'mkcert -install'; the agency container gets rootCA.pem).
say "Certificates"
mkdir -p certs
mkcert -install >/dev/null 2>&1 || mkcert -install
if [[ ! -f certs/dev.pem ]]; then
  mkcert -cert-file certs/dev.pem -key-file certs/dev-key.pem "${HOSTS[@]}"
fi
cp "$(mkcert -CAROOT)/rootCA.pem" certs/rootCA.pem
chmod 644 certs/*.pem

# 3. dev/.env: the Publisher's encryption key (CPUB_PUBLISHER_KEY, 32 random bytes) and
#    the admin password. Made once; delete dev/.env to start over with new ones.
if [[ ! -f .env ]]; then
  say "dev/.env"
  key="$(openssl rand -base64 32)"
  pass="$(openssl rand -hex 8)"
  cat > .env <<ENV
# Local development only. Never reuse these anywhere else.
CPUB_PUBLISHER_KEY=$key
ADMIN_PASSWORD=$pass
# 1 = an offline stand-in for the AI that knows content-publisher/samples/*.txt;
# 0 = the real AI, with the key you enter under Content Publisher > Settings.
CPUB_DEV_RECORDED_AI=1
ENV
fi
set -a; . ./.env; set +a

# 4. Containers.
say "Starting the containers"
docker compose up -d
for site in agency client; do
  for _ in $(seq 1 60); do
    docker compose exec -T "$site" test -f /var/www/html/wp-config.php 2>/dev/null && break
    sleep 2
  done
done

wp() { docker compose run --rm -T "wp-$1" wp "${@:2}"; }

# 5. WordPress, plugins and people.
install_site() {
  local site=$1 title=$2
  if ! wp "$site" core is-installed >/dev/null 2>&1; then
    say "Installing WordPress on https://$site.test"
    wp "$site" core install --url="https://$site.test" --title="$title" \
      --admin_user=admin --admin_password="$ADMIN_PASSWORD" --admin_email="admin@$site.test" --skip-email
  fi
  wp "$site" rewrite structure '/%postname%/' >/dev/null
}
user() { # site login role display-name
  wp "$1" user get "$2" --field=ID >/dev/null 2>&1 ||
    wp "$1" user create "$2" "$2@$1.test" --role="$3" --display_name="$4" --user_pass="$ADMIN_PASSWORD" >/dev/null
}

install_site agency "Agency (local)"
install_site client "Client (local)"

say "Plugins"
wp agency plugin activate content-publisher
wp client plugin activate content-publisher-connector

say "Users (password: the admin password)"
user agency rita editor "Rita Reviewer"       # an agency reviewer (Editors can review and send)
user client sara editor "Sara Editor"         # posts can appear under her (an Editor)
user client omar author "Omar Author"         # ... or under him (an Author)

cat <<DONE

Ready.
  Agency  https://agency.test/wp-admin   admin / $ADMIN_PASSWORD
  Client  https://client.test/wp-admin   admin / $ADMIN_PASSWORD
Next: dev/README.md, "Connect and send a draft".
DONE
