# Accessibility / UAT artifacts (AC-19 / AC-20)

## AC-19 — automated + documented keyboard pass

Automated: `npx playwright test tests/e2e/a11y-smoke.spec.js tests/e2e/uj-journeys.spec.js`
(axe-core on due, customers, equipment, visits, catalogs, settings, customer detail,
plan dialog; WCAG 2.1 A/AA tags; zero serious/critical).

Manual keyboard / screen-reader checklist (release gate — run once per release):

1. Tab order matches visual order on Due board (nav → filters → visit actions).
2. Complete dialog: focus lands in `done_on`; Esc closes; focus returns to trigger.
3. Force-delete dialog: Delete stays disabled until checkbox checked; Esc cancels.
4. Overdue / status never colour-only (badge text + icon present).
5. 320 px viewport: no horizontal page scroll; tables may scroll inside their container.
6. Screen reader: one `h1` per page; toasts announced via `aria-live="polite"`.

Sign-off: record date + tester in the release notes when shipping.

## AC-20 — §2a MobilityCheck side-by-side

```bash
npx playwright test tests/e2e/uat-screenshots.spec.js --project=chromium-1280
```

Outputs land in this folder:

- `maintenancecheck-due-board-1280.png`
- `mobilitycheck-shell-1280.png` (when MobilityCheck is enabled)

Auditor verdict target: &lt;30 s = “another Check app”.

## MSI (SPEC §14.4)

```bash
# Prefer Docker for DB-backed mutation runners:
docker compose exec nextcloud bash -lc \
  'cd /var/www/html/custom_apps/maintenancecheck && php tests/Mutation/msi-report.php'
```

Writes `tests/Mutation/msi-summary.json` with hotspot MSI ≥ 90 and overall ≥ 80.
Surviving mutants must be triaged in release notes (none allowed to ship).
