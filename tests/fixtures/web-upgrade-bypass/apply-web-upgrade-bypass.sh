#!/usr/bin/env bash
# Apply NC 34+ web-upgrade acknowledgement bypass inside the running Nextcloud
# container volume. Stock Nextcloud ignores the “Upgrade via web on my own risk”
# ack when upgrade.disable-web=true, so the button reloads the same CLI page.
#
# Safe to re-run (idempotent). Does not change upgrade.disable-web itself.
#
# Usage (from nextcloud/):
#   bash docker/patches/apply-web-upgrade-bypass.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="${ROOT}/docker-compose.yml"
SERVICE="${NEXTCLOUD_SERVICE:-nextcloud}"
PATCH_PHP="${ROOT}/docker/patches/apply-web-upgrade-bypass.php"

if [[ ! -f "${COMPOSE_FILE}" ]]; then
	echo "ERROR: docker-compose.yml not found at ${COMPOSE_FILE}" >&2
	exit 1
fi
if [[ ! -f "${PATCH_PHP}" ]]; then
	echo "ERROR: missing ${PATCH_PHP}" >&2
	exit 1
fi
if ! docker compose -f "${COMPOSE_FILE}" ps --status running --services 2>/dev/null | grep -qx "${SERVICE}"; then
	echo "ERROR: service '${SERVICE}' is not running" >&2
	exit 1
fi

# Copy into the container (volume is not bind-mounted for core), then execute.
docker compose -f "${COMPOSE_FILE}" cp "${PATCH_PHP}" "${SERVICE}:/tmp/apply-web-upgrade-bypass.php"
docker compose -f "${COMPOSE_FILE}" exec -T "${SERVICE}" php /tmp/apply-web-upgrade-bypass.php
docker compose -f "${COMPOSE_FILE}" exec -T "${SERVICE}" rm -f /tmp/apply-web-upgrade-bypass.php

# Clear PHP OPcache so base.php / UpdateController changes take effect immediately.
docker compose -f "${COMPOSE_FILE}" exec -T "${SERVICE}" php -r \
	'if (function_exists("opcache_reset")) { opcache_reset(); echo "opcache_reset ok\n"; } else { echo "opcache not enabled\n"; }' \
	|| true

echo "Done. Reload the Update needed page and use “Upgrade via web on my own risk”."
