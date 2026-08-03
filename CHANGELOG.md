# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.2.6 - 2026-08-02

### Changed

- Packaging release: version bump for ready4upload / production archive (local install already at 1.2.5).

## 1.2.5 - 2026-08-02

### Added

- **Companion API floor:** bootstrap exposes `maintenancecheck.companion.min` (`1`) plus inspection obligation / result / defect follow-up / evidence PDF capability flags.
- **Mobile visit detail:** `GET /mobile/v1/visits/{id}`; due board supports kind/filter query resolution (`DueQueryKind`).
- **Mobile exceptions:** `GET /mobile/v1/exceptions` for field ops alerts.

### Fixed

- Visit completion guards for inspection work orders; overdue reminder and KPI/exception board hardening; expanded visit/mobile gate tests.

## 1.2.4 - 2026-08-02

### Added
- Defect code picker (failure-code catalog + free-text Other) on web and companion inspection Done (UC-PRUEF).
- Postgres W7 UNIQUE(source_wo_id) smoke script (`scripts/run-postgres-w7-followup.php`).
- Playwright axe coverage for the inspection Done dialog.

### Fixed
- Web equipment documents download via `/api/equip-docs/{id}/download` (never `/f/{fileId}`).
- Companion mock Done gate mirrors inspection result/inspector/defects; mutate:core covers openOrCreate idempotency.

## 1.2.3 - 2026-08-02

### Added
- Mobile API `POST /mobile/v1/visits/{visitId}/work-orders` — idempotent open-or-create for field Prüfungen (UC-PRUEF) when office did not pre-create a work order.
- Companion 1.2.1: Start inspection work order from Visit detail (no more Refresh-only dead-end).
- Web due board: techs can Create inspection work order (not office-only Refresh).

### Fixed
- AC-W6-2 preferred window asserted on corrective intake create/get enrichment.
- MSI summary refreshed (100% hotspots / services).

## 1.2.2 - 2026-08-02

### Fixed
- Equipment document Files attachments are materialised into appdata on attach so seated technicians can download manuals without a separate Files share (W6-R2 / AC-C16).
- Bachus due board: empty buckets hidden; one-tap Complete; overflow More menu styled.

### Security
- Attach still requires actor Files ACL; download prefers materialised blobs (no confused-deputy raw fileId read as another user).

## 1.2.1 - 2026-08-01

### Added

- **W7 Prüfpflichten depth:** equipment classes (≥6 DE+EN), inspection obligations, pass/fail/conditional results + defects, Prüfnachweis PDF (evidence pack — not a certificate), due-board Prüfungen filter, KPI inspection metrics, DE+EN seed packs (portable electrical, ladders, fire extinguisher).
- Optional policy **`failBlocksRoll`** (CORE I2, default off): fail inspection Done closes the visit without rolling the next due.
- Unique `source_wo_id` for idempotent auto-corrective follow-up under concurrency; defect photo ownership (`mn_wo_photos.id`).
- Companion P5 surfaces: Prüfungen filter, inspection Done sheet, obligations read, evidence PDF share (Store GA still gated).

### Changed

- Inspection obligation visits cannot be closed via plain Complete/Skip — server returns `inspection_requires_work_order`; UI routes to inspection work orders (CORE I3 / AC-W7-3…5).

### Security

- Legal-overclaim ban-list script for l10n + packs; defect `photoFileId` must belong to the work order.

## 1.1.0 - 2026-08-01

### Added

- **W6 field-ops hardening:** request intake on work orders, site access notes / preferred window, equipment warranty end + documents, failure-code catalog (policy off/warn/required), job-duration evidence minutes, append-only WO comments, Ops KPI snapshot + CSV, exception board, overdue Nextcloud Notifications (hourly, once per entity per day).
- Mobile API capability flags: `requestIntake`, `failureCodes`, `laborMinutes`, `woComments`, `equipmentDocs`, `opsAlerts`.
- Mobile endpoints for WO comments, equipment docs, and failure codes.

## 1.0.4 - 2026-07-31

### Changed

- Production upload packaging: `appinfo/version` synced with `info.xml`, ready4upload builder support, and Dompdf shipped via `composer install --no-dev` in the archive (PDF Servicebericht / job-pack).

## 1.0.3 - 2026-07-29

### Added

- Licensing, companion APIs, and expanded test coverage (initial 1.0.3 ship).
