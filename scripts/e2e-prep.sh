#!/usr/bin/env bash
# Prepare the shared Nextcloud Docker stack for MaintenanceCheck Playwright E2E.
# Run from: nextcloud/apps/maintenancecheck/scripts/e2e-prep.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
NC_DIR="${ROOT}/nextcloud"
ENV_FILE="$(cd "$(dirname "$0")/.." && pwd)/tests/e2e/.env"

if [[ -f "${ENV_FILE}" ]]; then
	# shellcheck disable=SC1090
	set -a
	# shellcheck disable=SC1091
	source "${ENV_FILE}"
	set +a
fi

ADMIN_USER="${NC_ADMIN_USER:-admin}"
ADMIN_PASS="${NC_ADMIN_PASS:-E2eAdminT3st!}"
E2E_USER="${NC_E2E_USER:-mn_e2e}"
E2E_PASS="${NC_E2E_PASS:-Mn-E2e-Pass-7!xK}"

cd "${NC_DIR}"

if ! docker compose ps --status running --services 2>/dev/null | grep -qx nextcloud; then
	echo "error: nextcloud service is not running (cd nextcloud && docker compose up -d)" >&2
	exit 1
fi

echo "Resetting admin password for ${ADMIN_USER}…"
docker compose exec -T -u www-data -e OC_PASS="${ADMIN_PASS}" nextcloud \
	php occ user:resetpassword "${ADMIN_USER}" --password-from-env

echo "Ensuring E2E user ${E2E_USER}…"
if docker compose exec -T -u www-data nextcloud php occ user:info "${E2E_USER}" >/dev/null 2>&1; then
	docker compose exec -T -u www-data -e OC_PASS="${E2E_PASS}" nextcloud \
		php occ user:resetpassword "${E2E_USER}" --password-from-env
else
	docker compose exec -T -u www-data -e OC_PASS="${E2E_PASS}" nextcloud \
		php occ user:add --password-from-env --display-name "MN E2E" --group admin "${E2E_USER}" \
		|| docker compose exec -T -u www-data -e OC_PASS="${E2E_PASS}" nextcloud \
			php occ user:add --password-from-env --display-name "MN E2E" "${E2E_USER}"
fi

echo "Clearing bruteforce for loopback…"
docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset 127.0.0.1 || true
docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset ::1 || true
GATEWAY="$(docker compose exec -T nextcloud sh -c "ip route 2>/dev/null | awk '/default/ {print \$3; exit}'" || true)"
if [[ -n "${GATEWAY}" ]]; then
	echo "Clearing bruteforce for compose gateway ${GATEWAY}…"
	docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset "${GATEWAY}" || true
fi

echo "Opening MaintenanceCheck access for local E2E…"
docker compose exec -T -u www-data nextcloud \
	php occ config:app:set maintenancecheck access_restriction_enabled --value=0 || true
docker compose exec -T -u www-data nextcloud \
	php occ config:app:set maintenancecheck access_allowed_user_ids --value='[]' || true

# Fail closed if the instance still wants an upgrade — Playwright would land on
# "Update needed" instead of the app (large-instance web updater gate).
STATUS_JSON="$(curl -fsS "${NC_BASE_URL:-http://127.0.0.1:8081}/status.php" || true)"
if echo "${STATUS_JSON}" | grep -q '"needsDbUpgrade":true'; then
	echo "error: Nextcloud needsDbUpgrade=true — run: docker compose exec -u www-data nextcloud php -d memory_limit=2048M occ upgrade" >&2
	exit 1
fi
if echo "${STATUS_JSON}" | grep -q '"maintenance":true'; then
	echo "error: Nextcloud maintenance mode is on — run: docker compose exec -u www-data nextcloud php occ maintenance:mode --off" >&2
	exit 1
fi

echo "E2E prep done. Try NC_E2E_USER=${E2E_USER} or NC_ADMIN_USER=${ADMIN_USER}."
