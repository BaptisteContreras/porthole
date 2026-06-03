#!/usr/bin/env bash
# Run this script once before starting docker-compose to generate all Harbor
# config files and TLS certificates. Requires: docker, curl, openssl.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARBOR_VERSION="v2.12.0"
HARBOR_TARBALL="harbor-online-installer-${HARBOR_VERSION}.tgz"
HARBOR_URL="https://github.com/goharbor/harbor/releases/download/${HARBOR_VERSION}/${HARBOR_TARBALL}"

echo "=== Harbor Setup (${HARBOR_VERSION}) ==="

# --- Prerequisites ---
for cmd in docker curl openssl; do
    if ! command -v "$cmd" &>/dev/null; then
        echo "ERROR: '$cmd' is required but not found." >&2
        exit 1
    fi
done

if ! docker info &>/dev/null; then
    echo "ERROR: Docker is not running." >&2
    exit 1
fi

# --- Load .env ---
if [ ! -f "${SCRIPT_DIR}/.env" ]; then
    echo "ERROR: ${SCRIPT_DIR}/.env not found." >&2
    echo "  Copy .env.example to .env, fill in the values, then re-run this script." >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${SCRIPT_DIR}/.env"

for var in HARBOR_HOSTNAME HARBOR_ADMIN_PASSWORD HARBOR_DB_PASSWORD \
           HARBOR_CORE_SECRET HARBOR_JOBSERVICE_SECRET HARBOR_CORE_KEY; do
    val="${!var:-}"
    if [ -z "$val" ] || [[ "$val" == *"changeme"* ]] || [[ "$val" == *"replace_with"* ]]; then
        echo "ERROR: $var is not set or still has a placeholder value in .env" >&2
        exit 1
    fi
done

# --- Guard: don't overwrite existing config ---
if [ -d "${SCRIPT_DIR}/common/config" ]; then
    echo "WARNING: ${SCRIPT_DIR}/common/config already exists."
    read -r -p "Overwrite and regenerate all config files? [y/N] " answer
    if [[ "$answer" != [yY] ]]; then
        echo "Aborted."
        exit 0
    fi
    rm -rf "${SCRIPT_DIR}/common/config"
fi

# --- TLS certificates ---
CERTS_DIR="${SCRIPT_DIR}/certs"
mkdir -p "${CERTS_DIR}"

if [ ! -f "${CERTS_DIR}/server.crt" ] || [ ! -f "${CERTS_DIR}/server.key" ]; then
    echo "Generating self-signed TLS certificate for '${HARBOR_HOSTNAME}'..."
    openssl req -newkey rsa:4096 -nodes -sha256 \
        -keyout "${CERTS_DIR}/server.key" \
        -x509 -days 365 \
        -out "${CERTS_DIR}/server.crt" \
        -subj "/CN=${HARBOR_HOSTNAME}" \
        -addext "subjectAltName=DNS:${HARBOR_HOSTNAME},IP:127.0.0.1" \
        2>/dev/null
    echo "  -> ${CERTS_DIR}/server.crt + server.key"
else
    echo "TLS certificate already exists, skipping generation."
fi

# --- Harbor token signing key ---
# docker-compose.yaml mounts ./certs/private_key.pem into the core container.
if [ ! -f "${CERTS_DIR}/private_key.pem" ]; then
    echo "Generating Harbor token signing key..."
    openssl genrsa -traditional -out "${CERTS_DIR}/private_key.pem" 4096 2>/dev/null
    echo "  -> ${CERTS_DIR}/private_key.pem"
else
    echo "Token signing key already exists, skipping generation."
fi

# --- Download Harbor installer ---
TMPDIR=$(mktemp -d)
trap 'sudo rm -rf "${TMPDIR}" 2>/dev/null || rm -rf "${TMPDIR}" 2>/dev/null || true' EXIT

echo "Downloading Harbor ${HARBOR_VERSION} installer..."
curl -fsSL "${HARBOR_URL}" -o "${TMPDIR}/${HARBOR_TARBALL}"
tar -xzf "${TMPDIR}/${HARBOR_TARBALL}" -C "${TMPDIR}"
cd "${TMPDIR}/harbor"

# --- Generate harbor.yml for the prepare script ---
# The prepare script reads this to template all config files.
# Secrets are only used here to generate htpasswd/registry auth entries;
# runtime secrets are injected via environment variables in docker-compose.yaml.
cat > harbor.yml << HARBOREOF
hostname: ${HARBOR_HOSTNAME}

https:
  port: 443
  certificate: ${CERTS_DIR}/server.crt
  private_key: ${CERTS_DIR}/server.key

harbor_admin_password: ${HARBOR_ADMIN_PASSWORD}

database:
  password: ${HARBOR_DB_PASSWORD}
  max_idle_conns: 100
  max_open_conns: 900
  conn_max_lifetime: 5m
  conn_max_idle_time: 0

data_volume: /data

jobservice:
  max_job_workers: 10
  job_loggers:
    - STD_OUTPUT
    - FILE
  logger_sweeper_duration: 1

notification:
  webhook_job_max_retry: 3
  webhook_job_http_client_timeout: 3

log:
  level: info
  local:
    rotate_count: 50
    rotate_size: 200M
    location: /var/log/harbor

_version: 2.12.0

upload_purging:
  enabled: true
  age: 168h
  interval: 24h
  dryrun: false

cache:
  enabled: false
  expire_hours: 24
HARBOREOF

# --- Run Harbor's prepare script ---
# prepare pulls goharbor/prepare:v2.12.0 and generates common/config/ from templates.
echo "Running Harbor prepare script (pulls goharbor/prepare:${HARBOR_VERSION})..."
./prepare

# --- Copy generated config to our docker directory ---
# prepare runs inside a Docker container as root, so generated files are root-owned.
# Use sudo to copy them, then restore ownership to the current user.
echo "Copying generated config files (requires sudo for root-owned files)..."
mkdir -p "${SCRIPT_DIR}/common"
sudo cp -r common/config "${SCRIPT_DIR}/common/"
sudo chown -R "$(id -u):$(id -g)" "${SCRIPT_DIR}/common/"

# Ensure the trust-certificates directory exists (required for bind mount)
mkdir -p "${SCRIPT_DIR}/common/config/shared/trust-certificates"

# Docker Desktop on WSL2 accesses bind-mounted files from the Windows side;
# files must be world-readable or Docker can't map them and creates a directory instead.
find "${SCRIPT_DIR}/common/config/" -type f -exec chmod 644 {} \;
find "${SCRIPT_DIR}/common/config/" -type d -exec chmod 755 {} \;
chmod 644 "${CERTS_DIR}/server.crt" "${CERTS_DIR}/server.key" "${CERTS_DIR}/private_key.pem"

# prepare generates nginx.conf with /etc/cert/ but we mount certs at /etc/nginx/cert/
sed -i 's|ssl_certificate /etc/cert/|ssl_certificate /etc/nginx/cert/|g' \
    "${SCRIPT_DIR}/common/config/nginx/nginx.conf"

# Create conf.d/ directory for nginx include globs
mkdir -p "${SCRIPT_DIR}/common/config/nginx/conf.d"

# Harbor's prepare puts private_key.pem in common/config/core/ (for its own
# docker-compose layout). Our docker-compose.yaml mounts it from ./certs/ instead,
# which we already generated above. No action needed.

echo ""
echo "=== Setup complete ==="
echo ""
echo "Generated files:"
echo "  ${SCRIPT_DIR}/certs/         — TLS cert + token signing key"
echo "  ${SCRIPT_DIR}/common/config/ — Harbor service configs (nginx, registry, core, etc.)"
echo ""
echo "Start Harbor:"
echo "  cd ${SCRIPT_DIR} && docker compose up -d"
