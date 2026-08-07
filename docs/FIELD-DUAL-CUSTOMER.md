# Field register vs other business apps (AC-S2.5)

MaintenanceCheck keeps a **field register**: sites, equipment, work orders and tours.

Other Nextcloud business apps may also have an organisation or company concept. Those are **not** the same database rows. A matching name (“Acme GmbH” in two apps) is coincidence until you link records on purpose. There is **no silent merge** across apps.

## Optional soft links (SHARED-IDENTITY W3)

Field customers may store nullable soft links:

- `pc_customer_id` → commercial customer in the hours/billing app
- `crm_company_id` → company in the CRM hub app

Unique when set (one MN customer per sibling id). Links are **optional**: work orders and equipment stay on the MaintenanceCheck customer id even when links are null. Creating a work order never requires a soft link.

UI flag `mn_soft_link_ui` (default on) shows link/unlink controls. Set to `0` to hide without dropping columns.

From the CRM hub 360 you can create a field customer (copies name/address once and sets soft links when known).

## Practical guidance

1. Keep the MaintenanceCheck customer for field work (equipment and work orders).
2. If you also run a CRM or related Check app, use soft-link or create-from-hub instead of retyping — still no silent merge.
3. Do **not** delete the MaintenanceCheck customer hoping another app will “take over” — visits and work orders stay on the MaintenanceCheck row.
4. Optional stock movements after a work order closes refer to the **work order**, not a CRM company. Closing a job must never depend on another app being installed.
5. Local MN address may diverge after link (field vs billing). Refresh-from-link is optional and out of MVP.

## GDPR / erase

Deleting a MaintenanceCheck customer removes that row and its soft-link columns. It does **not** delete commercial customers, invoices, or CRM companies in other apps. Soft links are local pointers only.

## Support

Diagnosis for “two Acme” tickets: see the CRM hub identity support runbook under `customercheck/docs/IDENTITY-SUPPORT-RUNBOOK.md` (path only — keep apps separate). · Suite overview: [Check Productivity Suite](https://nextcloud.software-by-design.de/) · Sponsors: https://github.com/sponsors/aSoftwareByDesignRepository
