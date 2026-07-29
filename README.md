# MaintenanceCheck

Maintenance planning for organisations on Nextcloud: customers, equipment, visit plans, and due boards — with optional mobile seats.

**Standalone repository:** [github.com/aSoftwareByDesignRepository/nextcloud-maintenancecheck](https://github.com/aSoftwareByDesignRepository/nextcloud-maintenancecheck)  
App ID: **`maintenancecheck`**. Clone path:

```bash
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-maintenancecheck.git /path/to/nextcloud/apps/maintenancecheck
```

## Requirements

- Nextcloud 32–34
- PHP 8.2–8.5
- MySQL/MariaDB or PostgreSQL

## Development

```bash
cd nextcloud
docker compose exec nextcloud bash -lc 'cd /var/www/html/custom_apps/maintenancecheck && composer install'
```

Planning docs live in the parent workspace under `planning/app-ideas/maintenancecheck/` (not shipped in this repo).

## Field Pack: dual customers (AC-S2.5)

MaintenanceCheck customers are **not** CustomerCheck CRM companies. Matching names do not merge automatically.

See [docs/FIELD-DUAL-CUSTOMER.md](docs/FIELD-DUAL-CUSTOMER.md) for the optional link path and what to tell technicians vs office staff.
