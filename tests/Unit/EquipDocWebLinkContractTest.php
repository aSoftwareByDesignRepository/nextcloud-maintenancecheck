<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * W6-R2 / AC-C16 — web UI must download Files attachments via app API,
 * never deep-link /f/{fileId} (techs without a share would fail silently).
 */
final class EquipDocWebLinkContractTest extends TestCase
{
	public function testEquipmentDocsUseAuthenticatedDownloadNotFilesDeepLink(): void
	{
		$js = file_get_contents(dirname(__DIR__, 2) . '/js/app.js');
		$this->assertIsString($js);
		$this->assertStringContainsString("apiUrl('equipDocs')", $js);
		$this->assertStringContainsString('/download', $js);
		$this->assertStringNotContainsString("/f/' + doc.fileId", $js);
		$this->assertStringNotContainsString('/index.php/f/', $js);

		$page = file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertIsString($page);
		$this->assertStringContainsString("'equipDocs'", $page);
		$this->assertStringContainsString('equip_doc.download', $page);

		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/EquipDocController.php');
		$this->assertMatchesRegularExpression(
			'/#\[NoCSRFRequired\][\s\S]{0,120}function download\(/',
			$ctrl,
			'equip-doc download is opened via <a href> and must be CSRF-exempt',
		);
	}
}
