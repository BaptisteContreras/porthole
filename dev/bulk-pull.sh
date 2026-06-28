#!/usr/bin/env bash
# Generate Harbor audit log pull events until total reaches TARGET_LOGS.
# Uses parallel docker manifest inspect (bypasses local image cache).
#
# Usage: bash dev/bulk-pull.sh [TARGET_LOGS] [PARALLEL_WORKERS]
#   TARGET_LOGS      default 200000
#   PARALLEL_WORKERS default 50
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/harbor/.env"
CERT_PATH="${SCRIPT_DIR}/harbor/certs/server.crt"
TARGET_LOGS="${1:-200000}"
PARALLEL="${2:-50}"

source "${ENV_FILE}"

HARBOR_HOST="${HARBOR_HOSTNAME:-harbor.local}"
HARBOR_URL="https://${HARBOR_HOST}"
HARBOR_USER="admin"
HARBOR_PASS="${HARBOR_ADMIN_PASSWORD}"
API="${HARBOR_URL}/api/v2.0"

harbor_api() {
    curl -sf --cacert "${CERT_PATH}" -u "${HARBOR_USER}:${HARBOR_PASS}" "$@"
}

current_log_count() {
    curl -s --cacert "${CERT_PATH}" -u "${HARBOR_USER}:${HARBOR_PASS}" \
        "${API}/audit-logs?page_size=1" -D /dev/stderr -o /dev/null 2>&1 \
        | grep -i "x-total-count:" | tr -d '[:space:]' | cut -d: -f2
}

# ---------------------------------------------------------------------------
# Collect all image:tag references from Harbor API
# ---------------------------------------------------------------------------
TMPIMAGES=$(mktemp)
TMPLIST=$(mktemp)
trap 'rm -f "${TMPIMAGES}" "${TMPLIST}"' EXIT

harbor_api "${API}/repositories?page_size=100" \
    | python3 -c "import json,sys; [print(r['name']) for r in json.load(sys.stdin)]" \
    | while IFS= read -r repo; do
        project="${repo%%/*}"
        reponame="${repo##*/}"
        harbor_api "${API}/projects/${project}/repositories/${reponame}/artifacts?page_size=20" \
            | python3 -c "
import json, sys
for a in json.load(sys.stdin):
    for tag in (a.get('tags') or []):
        print('harbor.local/${repo}:' + tag['name'])
" 2>/dev/null || true
    done > "${TMPIMAGES}"

IMAGE_COUNT=$(wc -l < "${TMPIMAGES}")

if [ "${IMAGE_COUNT}" -eq 0 ]; then
    echo "ERROR: no images found in Harbor. Run 'make harbor-seed' first." >&2
    exit 1
fi

echo "Found ${IMAGE_COUNT} image references."

CURRENT=$(current_log_count)
echo "Current audit log count : ${CURRENT}"
echo "Target                  : ${TARGET_LOGS}"

if [ "${CURRENT}" -ge "${TARGET_LOGS}" ]; then
    echo "Already at target. Nothing to do."
    exit 0
fi

NEEDED=$(( TARGET_LOGS - CURRENT ))
echo "Pulls needed            : ${NEEDED}"
echo "Parallel workers        : ${PARALLEL}"
echo ""

# ---------------------------------------------------------------------------
# Generate a list of NEEDED image refs (cycling through available images)
# ---------------------------------------------------------------------------
python3 -c "
import sys
with open('${TMPIMAGES}') as f:
    images = [l.strip() for l in f if l.strip()]
needed = int('${NEEDED}')
for i in range(needed):
    print(images[i % len(images)])
" > "${TMPLIST}"

echo "Starting bulk pull (this may take a while)..."
START=$(date +%s)

xargs -P "${PARALLEL}" -I{} docker manifest inspect {} < "${TMPLIST}" > /dev/null

END=$(date +%s)
ELAPSED=$(( END - START ))

FINAL=$(current_log_count)
echo ""
echo "Done in ${ELAPSED}s."
echo "Final audit log count: ${FINAL}"
