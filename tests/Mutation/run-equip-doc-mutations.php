#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: EquipDocService Files ACL (W6-R2 confused-deputy).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/EquipDocService.php';

runMutations(dirname(__DIR__, 2), 'EquipDocServiceTest', [
	[
		'name' => 'readable-check-dropped',
		'file' => $file,
		'search' => "\$this->assertReadableFile(\$uid, \$fileId);\n\t\t}",
		'replace' => "// mutant: Files ACL dropped\n\t\t}",
	],
	[
		'name' => 'empty-nodes-accepted',
		'file' => $file,
		'search' => "if (\$nodes === []) {\n\t\t\tthrow new ValidationException('validation_failed', 'fileId is not readable in your Files.', [\n\t\t\t\t['field' => 'fileId', 'code' => 'file_not_readable'],\n\t\t\t]);\n\t\t}",
		'replace' => "if (false && \$nodes === []) {\n\t\t\tthrow new ValidationException('validation_failed', 'fileId is not readable in your Files.', [\n\t\t\t\t['field' => 'fileId', 'code' => 'file_not_readable'],\n\t\t\t]);\n\t\t}",
	],
	[
		'name' => 'folder-accepted-as-file',
		'file' => $file,
		'search' => "if (\$node->getType() === FileInfo::TYPE_FOLDER) {\n\t\t\tthrow new ValidationException('validation_failed', 'fileId must point to a file, not a folder.', [\n\t\t\t\t['field' => 'fileId', 'code' => 'not_a_file'],\n\t\t\t]);\n\t\t}",
		'replace' => "if (false && \$node->getType() === FileInfo::TYPE_FOLDER) {\n\t\t\tthrow new ValidationException('validation_failed', 'fileId must point to a file, not a folder.', [\n\t\t\t\t['field' => 'fileId', 'code' => 'not_a_file'],\n\t\t\t]);\n\t\t}",
	],
	[
		'name' => 'download-skips-missing-file',
		'file' => $file,
		'search' => "if (\$fileId === null || \$fileId <= 0) {\n\t\t\tthrow new ValidationException('validation_failed', 'This document has no Files attachment — open the external URL instead.', [\n\t\t\t\t['field' => 'fileId', 'code' => 'no_file'],\n\t\t\t]);\n\t\t}",
		'replace' => "if (false && (\$fileId === null || \$fileId <= 0)) {\n\t\t\tthrow new ValidationException('validation_failed', 'This document has no Files attachment — open the external URL instead.', [\n\t\t\t\t['field' => 'fileId', 'code' => 'no_file'],\n\t\t\t]);\n\t\t}",
	],
	[
		'name' => 'materialise-on-create-dropped',
		'file' => $file,
		'search' => "if (\$sourceFile !== null) {\n\t\t\t\$this->storage->storeFromFile((int)\$inserted->getId(), \$sourceFile);\n\t\t}",
		'replace' => "if (false && \$sourceFile !== null) {\n\t\t\t\$this->storage->storeFromFile((int)\$inserted->getId(), \$sourceFile);\n\t\t}",
	],
]);
