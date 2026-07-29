# Field Pack: MaintenanceCheck vs CRM customers (AC-S2.5)

MaintenanceCheck and CustomerCheck both have a **customer** concept. They are **not** the same database row.

## Why two registers?

| App | Purpose |
|-----|---------|
| **MaintenanceCheck** | Field register: sites, equipment, work orders, tours |
| **CustomerCheck** | CRM: companies, agreements, pipeline, money |

A name match (“Acme GmbH” in both) is **coincidence until you link intentionally**. There is **no silent merge** across apps.

## Optional link path

1. Keep the MN customer for field work (equipment + work orders).
2. In CustomerCheck, open or create the CRM company.
3. Use **Create / link ProjectCheck or TicketCheck customer** on the CRM company form when those apps are installed.
4. Do **not** delete the MN customer hoping CRM will “take over” — visits and WOs stay on the MN row.

Inventory stock issues (F6) reference the **work order**, not the CRM company. Uninstalling InventoryCheck must not block closing a work order.

## Support

More suite context: [Check Productivity Suite](https://nextcloud.software-by-design.de/) · Sponsors: https://github.com/sponsors/aSoftwareByDesignRepository
