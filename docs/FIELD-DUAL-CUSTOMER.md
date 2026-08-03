# Field register vs other business apps (AC-S2.5)

MaintenanceCheck keeps a **field register**: sites, equipment, work orders and tours.

Other Nextcloud business apps may also have an organisation or company concept. Those are **not** the same database rows. A matching name (“Acme GmbH” in two apps) is coincidence until you link records on purpose. There is **no silent merge** across apps.

## Practical guidance

1. Keep the MaintenanceCheck customer for field work (equipment and work orders).
2. If you also run a CRM or related Check app, create or open the organisation there separately.
3. Do **not** delete the MaintenanceCheck customer hoping another app will “take over” — visits and work orders stay on the MaintenanceCheck row.
4. Optional stock movements after a work order closes refer to the **work order**, not a CRM company. Closing a job must never depend on another app being installed.

## Support

Suite overview: [Check Productivity Suite](https://nextcloud.software-by-design.de/) · Sponsors: https://github.com/sponsors/aSoftwareByDesignRepository
