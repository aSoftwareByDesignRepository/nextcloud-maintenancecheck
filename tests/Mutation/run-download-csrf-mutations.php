#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: href-based downloads must keep #[NoCSRFRequired], and
 * work-order mutations must NOT gain a CSRF exemption by accident.
 */

require __DIR__ . '/harness.php';

$wo = 'lib/Controller/WorkOrderController.php';
$equip = 'lib/Controller/EquipDocController.php';
$ops = 'lib/Controller/OpsController.php';
$proc = 'lib/Controller/ProcedureController.php';

runMutations(dirname(__DIR__, 2), 'DownloadCsrfPostureContractTest|WorkOrderControllerPdfTest', [
	[
		'name' => 'job-pack-csrf-exemption-dropped',
		'file' => $wo,
		'search' => "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function jobPackPdf(int \$id): DataDownloadResponse",
		'replace' => "#[NoAdminRequired]\n\tpublic function jobPackPdf(int \$id): DataDownloadResponse",
	],
	[
		'name' => 'servicebericht-csrf-exemption-dropped',
		'file' => $wo,
		'search' => "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function serviceberichtPdf(int \$id): DataDownloadResponse",
		'replace' => "#[NoAdminRequired]\n\tpublic function serviceberichtPdf(int \$id): DataDownloadResponse",
	],
	[
		'name' => 'inspection-evidence-csrf-exemption-dropped',
		'file' => $wo,
		'search' => "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function inspectionEvidencePdf(int \$id): DataDownloadResponse",
		'replace' => "#[NoAdminRequired]\n\tpublic function inspectionEvidencePdf(int \$id): DataDownloadResponse",
	],
	[
		'name' => 'photo-download-csrf-exemption-dropped',
		'file' => $wo,
		'search' => "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function downloadPhoto(int \$id, int \$photoId): DataDownloadResponse",
		'replace' => "#[NoAdminRequired]\n\tpublic function downloadPhoto(int \$id, int \$photoId): DataDownloadResponse",
	],
	[
		'name' => 'equip-doc-download-csrf-exemption-dropped',
		'file' => $equip,
		'search' => "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function download(int \$id): DataDownloadResponse",
		'replace' => "#[NoAdminRequired]\n\tpublic function download(int \$id): DataDownloadResponse",
	],
	[
		'name' => 'kpi-csv-csrf-exemption-dropped',
		'file' => $ops,
		'search' => "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function kpiCsv(?string \$days = null): DataDownloadResponse",
		'replace' => "#[NoAdminRequired]\n\tpublic function kpiCsv(?string \$days = null): DataDownloadResponse",
	],
	[
		'name' => 'procedure-export-csrf-exemption-dropped',
		'file' => $proc,
		'search' => "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function exportPack(?string \$pack = null, ?string \$vertical = null): DataDownloadResponse",
		'replace' => "#[NoAdminRequired]\n\tpublic function exportPack(?string \$pack = null, ?string \$vertical = null): DataDownloadResponse",
	],
	[
		'name' => 'create-mutation-csrf-exempted',
		'file' => $wo,
		'search' => "#[NoAdminRequired]\n\tpublic function create(): JSONResponse",
		'replace' => "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function create(): JSONResponse",
	],
	[
		'name' => 'job-pack-skips-acl-get',
		'file' => $wo,
		'search' => "public function jobPackPdf(int \$id): DataDownloadResponse\n\t{\n\t\t\$uid = \$this->access->currentUserId();\n\t\t\$this->workOrders->get(\$id, \$uid);\n\t\t\$pdf = \$this->pdf->jobPack(\$id);",
		'replace' => "public function jobPackPdf(int \$id): DataDownloadResponse\n\t{\n\t\t\$uid = \$this->access->currentUserId();\n\t\t// mutant: ACL get skipped\n\t\t\$pdf = \$this->pdf->jobPack(\$id);",
	],
]);
