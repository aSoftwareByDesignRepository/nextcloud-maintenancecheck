#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SHARED-IDENTITY AC-C-19 — MN ensureLink / soft-link critical path mutations.
 */

require __DIR__ . '/harness.php';

$file = 'lib/Public/CrmFieldCustomerFacade.php';

runMutations(dirname(__DIR__, 2), 'CrmFieldCustomerFacadeTest', [
	[
		'name' => 'ensureLink-skips-pc-conflict-check',
		'file' => $file,
		'search' => "\$other = \$this->customers->findByPcCustomerId(\$pcCustomerId);\n\t\t\t\tif (\$other !== null && (int)\$other->getId() !== \$mnCustomerId) {\n\t\t\t\t\tthrow new ConflictException('link_conflict', 'This ProjectCheck customer is already linked to another field customer.');\n\t\t\t\t}",
		'replace' => "\$other = \$this->customers->findByPcCustomerId(\$pcCustomerId);\n\t\t\t\tif (false && \$other !== null && (int)\$other->getId() !== \$mnCustomerId) {\n\t\t\t\t\tthrow new ConflictException('link_conflict', 'This ProjectCheck customer is already linked to another field customer.');\n\t\t\t\t}",
	],
	[
		'name' => 'soft-link-ui-flag-always-on',
		'file' => $file,
		'search' => "return \$raw === '1' || \$raw === 'true';",
		'replace' => 'return true;',
	],
	[
		'name' => 'as05-consistency-skipped',
		'file' => $file,
		'search' => "if (\$finalPc > 0 && \$finalCrm > 0) {\n\t\t\t\$this->assertCrmPcConsistent(\$actorUid, \$finalCrm, \$finalPc);\n\t\t}",
		'replace' => "if (false && \$finalPc > 0 && \$finalCrm > 0) {\n\t\t\t\$this->assertCrmPcConsistent(\$actorUid, \$finalCrm, \$finalPc);\n\t\t}",
	],
	[
		'name' => 'createFromHub-skips-existing-pc-lookup',
		'file' => $file,
		'search' => "if (\$pcId > 0) {\n\t\t\t\$existing = \$this->customers->findByPcCustomerId(\$pcId);\n\t\t\tif (\$existing !== null) {\n\t\t\t\treturn [\n\t\t\t\t\t'mnCustomerId' => (int)\$existing->getId(),\n\t\t\t\t\t'created' => false,\n\t\t\t\t];\n\t\t\t}\n\t\t}",
		'replace' => "if (false && \$pcId > 0) {\n\t\t\t\$existing = \$this->customers->findByPcCustomerId(\$pcId);\n\t\t\tif (\$existing !== null) {\n\t\t\t\treturn [\n\t\t\t\t\t'mnCustomerId' => (int)\$existing->getId(),\n\t\t\t\t\t'created' => false,\n\t\t\t\t];\n\t\t\t}\n\t\t}",
	],
]);
