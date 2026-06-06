#!/usr/bin/env bash
# Populate local Harbor with 3-10 projects and 1-3 image tags each.
# Also pulls each image to generate audit-log pull events for porthole reports.
#
# Prerequisites: Harbor must be running (make harbor-up).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/harbor/.env"
CERT_PATH="${SCRIPT_DIR}/harbor/certs/server.crt"

# ---------------------------------------------------------------------------
# Load env
# ---------------------------------------------------------------------------

if [ ! -f "${ENV_FILE}" ]; then
    echo "ERROR: ${ENV_FILE} not found. Run 'make harbor-setup' first." >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${ENV_FILE}"

HARBOR_HOST="${HARBOR_HOSTNAME:-harbor.local}"
HARBOR_URL="https://${HARBOR_HOST}"
HARBOR_USER="admin"
HARBOR_PASS="${HARBOR_ADMIN_PASSWORD}"
API="${HARBOR_URL}/api/v2.0"

# ---------------------------------------------------------------------------
# Ensure harbor.local resolves to 127.0.0.1
# ---------------------------------------------------------------------------

if ! grep -q "${HARBOR_HOST}" /etc/hosts 2>/dev/null; then
    echo "Adding ${HARBOR_HOST} -> 127.0.0.1 to /etc/hosts (requires sudo)..."
    echo "127.0.0.1 ${HARBOR_HOST}" | sudo tee -a /etc/hosts > /dev/null
    echo "  Done."
fi

# ---------------------------------------------------------------------------
# Make Docker trust Harbor's self-signed certificate
# ---------------------------------------------------------------------------

DOCKER_CERT_DIR="/etc/docker/certs.d/${HARBOR_HOST}"
if [ ! -f "${DOCKER_CERT_DIR}/ca.crt" ]; then
    echo "Installing Harbor cert into Docker trust store (requires sudo)..."
    sudo mkdir -p "${DOCKER_CERT_DIR}"
    sudo cp "${CERT_PATH}" "${DOCKER_CERT_DIR}/ca.crt"
    echo "  -> ${DOCKER_CERT_DIR}/ca.crt"
fi

# ---------------------------------------------------------------------------
# Wait for Harbor to be ready
# ---------------------------------------------------------------------------

echo "Waiting for Harbor to be ready..."
for i in $(seq 1 30); do
    if curl -sf --cacert "${CERT_PATH}" "${API}/ping" > /dev/null 2>&1; then
        echo "  Harbor is up."
        break
    fi
    if [ "${i}" -eq 30 ]; then
        echo "ERROR: Harbor did not respond after 30 attempts. Is it running?" >&2
        echo "  Run: make harbor-up" >&2
        exit 1
    fi
    sleep 2
done

# ---------------------------------------------------------------------------
# Docker login
# ---------------------------------------------------------------------------

echo "Logging in to ${HARBOR_HOST}..."
echo "${HARBOR_PASS}" | docker login "${HARBOR_HOST}" \
    -u "${HARBOR_USER}" --password-stdin 2>/dev/null
echo "  Logged in."

# ---------------------------------------------------------------------------
# Seed data
# ---------------------------------------------------------------------------

ALL_PROJECTS=("myapp" "backend" "frontend" "tools" "data" "infra" "platform" "services" "core" "monitoring")

# Pick 3-10 projects randomly
NUM_PROJECTS=$(( RANDOM % 8 + 3 ))
mapfile -t PROJECTS < <(printf '%s\n' "${ALL_PROJECTS[@]}" | shuf | head -n "${NUM_PROJECTS}")

echo ""
echo "=== Seeding ${NUM_PROJECTS} projects ==="

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

harbor_api() {
    curl -sf --cacert "${CERT_PATH}" \
        -u "${HARBOR_USER}:${HARBOR_PASS}" \
        "$@"
}

create_project() {
    local name="$1"
    local http_code
    http_code=$(curl -s -o /dev/null -w "%{http_code}" \
        --cacert "${CERT_PATH}" \
        -u "${HARBOR_USER}:${HARBOR_PASS}" \
        -X POST "${API}/projects" \
        -H "Content-Type: application/json" \
        -d "{\"project_name\": \"${name}\", \"public\": true}")
    case "${http_code}" in
        201) echo "  [created]  ${name}" ;;
        409) echo "  [exists]   ${name}" ;;
        *)   echo "  [WARNING]  ${name} — HTTP ${http_code}" >&2 ;;
    esac
}

build_and_push() {
    local project="$1" image="$2" tag="$3"
    local full="${HARBOR_HOST}/${project}/${image}:${tag}"

    docker build -q -t "${full}" - <<DOCKERFILE
FROM alpine:3.21
LABEL porthole.project="${project}" porthole.image="${image}" porthole.tag="${tag}"
RUN printf '%s\n' "project=${project}" "image=${image}" "tag=${tag}" > /version.txt
CMD ["cat", "/version.txt"]
DOCKERFILE

    docker push -q "${full}"
    echo "    pushed  ${full}"
}

pull_image() {
    local project="$1" image="$2" tag="$3"
    local full="${HARBOR_HOST}/${project}/${image}:${tag}"
    # docker pull skips the registry when layers are already in the local cache
    # (Docker reconstructs from its content-addressable store without a network
    # round-trip), so it never generates a Harbor audit log entry.
    # docker manifest inspect always fetches the manifest from the remote registry
    # (it has no local cache), handles the Bearer token exchange automatically,
    # and is guaranteed to produce a pull event in Harbor's audit log.
    docker manifest inspect "${full}" > /dev/null
}

# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------

echo ""

for project in "${PROJECTS[@]}"; do
    create_project "${project}"

    image="${project}-app"

    NUM_TAGS=$(( RANDOM % 3 + 1 ))
    echo "  Pushing ${NUM_TAGS} tag(s) for ${project}/${image}..."

    for v in $(seq 1 "${NUM_TAGS}"); do
        tag="v${v}.0.0"
        build_and_push "${project}" "${image}" "${tag}"

        # Pull 1-20 times. Take the minimum of two independent rolls so lower
        # counts are more likely — produces a realistic long-tail distribution.
        NUM_PULLS=$(( RANDOM % 20 + 1 ))
        ROLL2=$(( RANDOM % 20 + 1 ))
        [ "${ROLL2}" -lt "${NUM_PULLS}" ] && NUM_PULLS="${ROLL2}"
        echo "    pulling ${NUM_PULLS}x to seed audit log..."
        for _ in $(seq 1 "${NUM_PULLS}"); do
            pull_image "${project}" "${image}" "${tag}"
        done
    done
    echo ""
done

echo "=== Seed complete ==="
echo ""
echo "  Harbor UI : ${HARBOR_URL}"
echo "  Login     : admin / ${HARBOR_PASS}"
echo ""
echo "  Run a report:"
echo "    make report"