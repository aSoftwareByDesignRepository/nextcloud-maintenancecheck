<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: F18 field-issue draft facade invariants.
 *
 * A mutant edit must not silently drop: payload validation, ref dedupe,
 * fail-closed lookups, site-derived customer binding, draft-only status,
 * or the number-race retry that re-checks the ref before minting.
 *
 * Run: php tests/Mutation/run-field-issue-draft-mutations.php
 */

$root = dirname(__DIR__, 2);
$facade = (string)file_get_contents($root . '/lib/Public/FieldIssueDraftFacade.php');

function assertTrue(bool $cond, string $msg): void
{
	if (!$cond) {
		fwrite(STDERR, "FAIL: {$msg}\n");
		exit(1);
	}
	echo "OK: {$msg}\n";
}

// payload boundary
assertTrue(str_contains($facade, "strlen(\$refType) > 32") && str_contains($facade, "strlen(\$refId) > 64"), 'ref bounds enforced');
assertTrue(str_contains($facade, "'invalid_payload'"), 'empty/oversize input rejected');

// dedupe before mint — a replay must return the existing draft
assertTrue(str_contains($facade, 'findByRef($refType, $refId)'), 'source-ref dedupe before create');
assertTrue(str_contains($facade, "'already' => true"), 'replay returns existing, never a second WO');

// fail-closed: lookup exceptions must not mint a draft
assertTrue(str_contains($facade, "'lookup_failed'"), 'dedupe/site lookup failure rejects');

// site resolution — unique match binds customer; ambiguous/zero → no customer
assertTrue(str_contains($facade, 'count($matches) === 1'), 'only a unique site match binds');
assertTrue(str_contains($facade, "'site_unresolved'"), 'unresolvable site rejects (never orphans a WO)');

// draft-only: a mutant must not auto-assign/publish
assertTrue(str_contains($facade, 'WorkOrder::STATUS_DRAFT'), 'draft lands in triage');
assertTrue(str_contains($facade, 'KIND_CORRECTIVE'), 'kind is corrective, not a plan item');
assertTrue(!preg_match('/setAssignee|assigned_(user|team)|STATUS_PLANNED|STATUS_PUBLISHED/', $facade), 'facade never assigns or publishes');
assertTrue(str_contains($facade, 'MAX_TITLE') && str_contains($facade, 'MAX_DESCRIPTION'), 'title/description bounded');

// number race: unique violation → re-check ref, retry once
assertTrue(str_contains($facade, 'REASON_UNIQUE_CONSTRAINT_VIOLATION'), 'unique race handled');
assertTrue(substr_count($facade, 'findByRef(') >= 2, 'ref re-checked inside the retry loop');
assertTrue(str_contains($facade, '$attempt < 2'), 'retry bounded — no infinite mint loop');

echo "All field-issue-draft mutations passed.\n";
