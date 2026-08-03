<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Service;

use OCA\MaintenanceCheck\Service\EquipmentClassService;
use OCA\MaintenanceCheck\Service\MobileCapabilities;
use PHPUnit\Framework\TestCase;

final class W7CapabilitiesAndClassesContractTest extends TestCase
{
	public function testCapabilitiesAdvertiseW7Flags(): void
	{
		$caps = MobileCapabilities::current();
		$this->assertTrue($caps['inspectionObligations']);
		$this->assertTrue($caps['inspectionResults']);
		$this->assertTrue($caps['defectFollowUp']);
		$this->assertTrue($caps['inspectionEvidencePdf']);
	}

	public function testSeedDefinesAtLeastSixClasses(): void
	{
		$this->assertGreaterThanOrEqual(6, count(EquipmentClassService::SEED));
		$codes = array_column(EquipmentClassService::SEED, 'code');
		$this->assertContains('portable_electrical', $codes);
		$this->assertContains('ladder', $codes);
		$this->assertContains('fire_extinguisher', $codes);
	}

	public function testL10nBanListHasNoLegalOverclaim(): void
	{
		$root = dirname(__DIR__, 3) . '/l10n';
		if (!is_dir($root)) {
			$this->markTestSkipped('l10n directory missing');
		}
		$banned = [
			'rechtskonform',
			'DGUV-zertifiziert',
			'Konformitätsbescheinigung',
			'BG-approved',
			'certificate of compliance',
			'compliance certificate',
		];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($iterator as $file) {
			if (!$file->isFile() || !preg_match('/\.(js|json)$/', $file->getFilename())) {
				continue;
			}
			$raw = file_get_contents($file->getPathname());
			$this->assertNotFalse($raw);
			foreach ($banned as $needle) {
				$this->assertStringNotContainsStringIgnoringCase(
					$needle,
					$raw,
					$file->getPathname() . ' must not contain ' . $needle,
				);
			}
			// Filename / product copy must not sell "Zertifikat" as the PDF product name.
			if (str_contains(strtolower($raw), 'zertifikat') && str_contains(strtolower($raw), 'prüfnachweis')) {
				$this->fail('Avoid pairing Zertifikat with Prüfnachweis claims in ' . $file->getPathname());
			}
		}
		$this->addToAssertionCount(1);
	}
}
