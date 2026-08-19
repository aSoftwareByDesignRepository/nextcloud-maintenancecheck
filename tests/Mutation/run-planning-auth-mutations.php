#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: office-only planning APIs (KPI JSON, exception board,
 * technician tour scoping).
 */

require __DIR__ . '/harness.php';

runMutations(dirname(__DIR__, 2), 'OpsControllerAuthTest|TourControllerAuthTest|WorkOrderMapperMineScopeContractTest|CapacityControllerAuthTest|SkillControllerAuthTest|MobileCsrfChannelContractTest|MobileControllerCsrfTest|DispatchControllerAuthTest', [
	[
		'name' => 'kpi-office-gate-dropped',
		'file' => 'lib/Controller/OpsController.php',
		'search' => "public function kpi(?string \$days = null): JSONResponse\n\t{\n\t\t\$this->access->requireOffice(\$this->access->currentUserId());",
		'replace' => "public function kpi(?string \$days = null): JSONResponse\n\t{\n\t\t// mutant: office gate removed",
	],
	[
		'name' => 'exceptions-office-gate-dropped',
		'file' => 'lib/Controller/OpsController.php',
		'search' => "public function exceptions(?string \$filter = null, ?string \$limit = null, ?string \$offset = null): JSONResponse\n\t{\n\t\t\$this->access->requireOffice(\$this->access->currentUserId());",
		'replace' => "public function exceptions(?string \$filter = null, ?string \$limit = null, ?string \$offset = null): JSONResponse\n\t{\n\t\t// mutant: office gate removed",
	],
	[
		'name' => 'tour-index-office-check-inverted',
		'file' => 'lib/Controller/TourController.php',
		'search' => 'if (!$this->access->isOffice($uid)) {',
		'replace' => 'if ($this->access->isOffice($uid)) {',
	],
	[
		'name' => 'tour-show-acl-dropped',
		'file' => 'lib/Controller/TourController.php',
		'search' => 'if (!$this->access->isOffice($uid) && (string)($tour[\'techUid\'] ?? \'\') !== $uid) {',
		'replace' => 'if (false && !$this->access->isOffice($uid) && (string)($tour[\'techUid\'] ?? \'\') !== $uid) {',
	],
	[
		'name' => 'helper-like-dropped',
		'file' => 'lib/Db/WorkOrderMapper.php',
		'search' => "\$qb->expr()->like('helper_uids', \$qb->createNamedParameter(\$helperNeedle)),",
		'replace' => '$qb->expr()->eq(\'id\', $qb->createNamedParameter(0, \\PDO::PARAM_INT)),',
	],
	[
		'name' => 'capacity-index-office-gate-dropped',
		'file' => 'lib/Controller/CapacityController.php',
		'search' => "public function index(): JSONResponse\n\t{\n\t\t\$this->access->requireOffice(\$this->access->currentUserId());",
		'replace' => "public function index(): JSONResponse\n\t{\n\t\t// mutant: office gate removed",
	],
	[
		'name' => 'dispatch-board-office-gate-dropped',
		'file' => 'lib/Controller/DispatchController.php',
		'search' => "public function board(?string \$from = null, ?string \$to = null): JSONResponse\n\t{\n\t\t\$this->access->requireOffice(\$this->access->currentUserId());",
		'replace' => "public function board(?string \$from = null, ?string \$to = null): JSONResponse\n\t{\n\t\t// mutant: office gate removed",
	],
	[
		'name' => 'skills-other-uid-gate-dropped',
		'file' => 'lib/Controller/SkillController.php',
		'search' => 'if ($actor !== $uid && !$this->access->isOffice($actor)) {',
		'replace' => 'if (false && $actor !== $uid && !$this->access->isOffice($actor)) {',
	],
	[
		'name' => 'csrf-basic-check-dropped',
		'file' => 'lib/Controller/MobileController.php',
		'search' => 'if ($this->authorizationBasicAuthenticatesCurrentUser()) {',
		'replace' => 'if ($this->request->getHeader(\'Authorization\') !== \'\') {',
	],
	[
		'name' => 'mine-rank-case-dropped',
		'file' => 'lib/Db/WorkOrderMapper.php',
		'search' => ' THEN 0 ELSE 1 END',
		'replace' => ' THEN 1 ELSE 1 END',
	],
]);
