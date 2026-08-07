<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Tests\Unit\Controller;

use OCA\MaintenanceCheck\Controller\WorkOrderController;
use OCA\MaintenanceCheck\Exception\NotFoundException;
use OCA\MaintenanceCheck\Exception\PermissionDeniedException;
use OCA\MaintenanceCheck\Service\AccessControlService;
use OCA\MaintenanceCheck\Service\SkillService;
use OCA\MaintenanceCheck\Service\WoChecklistService;
use OCA\MaintenanceCheck\Service\WoCommentService;
use OCA\MaintenanceCheck\Service\WoEvidenceService;
use OCA\MaintenanceCheck\Service\WoPdfService;
use OCA\MaintenanceCheck\Service\WorkOrderService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * PDF download controllers: ACL before render, binary envelope shape.
 * CSRF exemption is asserted in DownloadCsrfPostureContractTest.
 */
final class WorkOrderControllerPdfTest extends TestCase
{
	private function controller(
		AccessControlService $access,
		WorkOrderService $workOrders,
		WoPdfService $pdf,
		?WoEvidenceService $evidence = null,
	): WorkOrderController {
		return new WorkOrderController(
			$this->createMock(IRequest::class),
			$access,
			$workOrders,
			$this->createMock(WoChecklistService::class),
			$evidence ?? $this->createMock(WoEvidenceService::class),
			$this->createMock(SkillService::class),
			$pdf,
			$this->createMock(WoCommentService::class),
		);
	}

	public function testJobPackPdfGatesOnWorkOrderAclThenDownloads(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->expects($this->once())->method('get')->with(42, 'tech1')
			->willReturn(['id' => 42, 'number' => 'WO-42']);
		$pdf = $this->createMock(WoPdfService::class);
		$pdf->expects($this->once())->method('jobPack')->with(42)->willReturn([
			'content' => '%PDF-1.4 jobpack',
			'filename' => 'job-pack-WO-42.pdf',
			'mime' => 'application/pdf',
		]);

		$res = $this->controller($access, $workOrders, $pdf)->jobPackPdf(42);
		$this->assertInstanceOf(DataDownloadResponse::class, $res);
		$this->assertSame('%PDF-1.4 jobpack', $res->render());
		$headers = $res->getHeaders();
		$this->assertSame('application/pdf', $headers['Content-Type'] ?? null);
		$this->assertStringContainsString('job-pack-WO-42.pdf', (string)($headers['Content-Disposition'] ?? ''));
	}

	public function testJobPackPdfDoesNotRenderWhenAclDenies(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('outsider');
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->expects($this->once())->method('get')->with(7, 'outsider')
			->willThrowException(new PermissionDeniedException('Not allowed'));
		$pdf = $this->createMock(WoPdfService::class);
		$pdf->expects($this->never())->method('jobPack');

		$this->expectException(PermissionDeniedException::class);
		$this->controller($access, $workOrders, $pdf)->jobPackPdf(7);
	}

	public function testServiceberichtPdfUsesFilenameFallbackKeys(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('office1');
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->method('get')->willReturn(['id' => 3]);
		$pdf = $this->createMock(WoPdfService::class);
		$pdf->method('servicebericht')->with(3)->willReturn([
			'content' => '%PDF-sb',
			'name' => 'servicebericht-3.pdf',
			'contentType' => 'application/pdf',
		]);

		$res = $this->controller($access, $workOrders, $pdf)->serviceberichtPdf(3);
		$this->assertInstanceOf(DataDownloadResponse::class, $res);
		$this->assertSame('%PDF-sb', $res->render());
		$this->assertStringContainsString('servicebericht-3.pdf', (string)($res->getHeaders()['Content-Disposition'] ?? ''));
	}

	public function testInspectionEvidencePdfPropagatesNotFound(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->method('get')->willReturn(['id' => 9, 'kind' => 'inspection']);
		$pdf = $this->createMock(WoPdfService::class);
		$pdf->method('inspectionEvidence')->with(9)
			->willThrowException(new NotFoundException('No evidence'));

		$this->expectException(NotFoundException::class);
		$this->controller($access, $workOrders, $pdf)->inspectionEvidencePdf(9);
	}

	public function testDownloadPhotoGatesAclBeforeRead(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->expects($this->once())->method('get')->with(5, 'tech1')->willReturn(['id' => 5]);
		$evidence = $this->createMock(WoEvidenceService::class);
		$evidence->expects($this->once())->method('readPhoto')->with(5, 11)->willReturn([
			'content' => 'IMG',
			'name' => 'shot.jpg',
			'mime' => 'image/jpeg',
		]);
		$pdf = $this->createMock(WoPdfService::class);

		$res = $this->controller($access, $workOrders, $pdf, $evidence)->downloadPhoto(5, 11);
		$this->assertInstanceOf(DataDownloadResponse::class, $res);
		$this->assertSame('IMG', $res->render());
	}

	public function testDownloadSignatureGatesAclBeforeRead(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('tech1');
		$workOrders = $this->createMock(WorkOrderService::class);
		$workOrders->expects($this->once())->method('get')->with(5, 'tech1')->willReturn(['id' => 5]);
		$evidence = $this->createMock(WoEvidenceService::class);
		$evidence->expects($this->once())->method('readSignature')->with(5)->willReturn([
			'content' => 'PNG',
			'name' => 'sig.png',
			'mime' => 'image/png',
		]);
		$pdf = $this->createMock(WoPdfService::class);

		$res = $this->controller($access, $workOrders, $pdf, $evidence)->downloadSignature(5);
		$this->assertInstanceOf(DataDownloadResponse::class, $res);
		$this->assertSame('PNG', $res->render());
	}
}
