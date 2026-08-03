<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use OCA\MaintenanceCheck\Db\WoSignatureMapper;
use OCA\MaintenanceCheck\Db\WorkOrder;
use OCA\MaintenanceCheck\Db\WorkOrderMapper;
use OCA\MaintenanceCheck\Exception\ConflictException;
use OCP\IL10N;

/**
 * W3 PDFs (CORE §12.7):
 *
 * - Job pack: printable brief for an OPEN work order (van pack) — checklist
 *   with empty boxes, kit picking list, skills, site address. Never labelled Servicebericht.
 * - Servicebericht: the customer-facing report of a DONE work order — results,
 *   times, photo thumbnails (max 12), signature (or the explicit "not captured" note).
 *   409 `wo_not_done` while the WO is not done.
 *
 * Dompdf runs with remote fetch disabled (SSRF-safe); images are embedded as
 * data URIs from appdata only. Every dynamic string is escaped.
 */
class WoPdfService
{
	/** CORE §12.7 / UC-SB — thumbnail budget in the Servicebericht. */
	public const MAX_REPORT_PHOTOS = 12;

	public function __construct(
		private readonly WorkOrderMapper $workOrders,
		private readonly WorkOrderService $workOrderService,
		private readonly WoSignatureMapper $signatures,
		private readonly EvidenceStorage $storage,
		private readonly IL10N $l,
	) {
	}

	/**
	 * @return array{filename: string, mime: string, content: string}
	 */
	public function jobPack(int $workOrderId): array
	{
		$wo = $this->workOrders->findById($workOrderId);
		if ($wo->isTerminal()) {
			throw new ConflictException('invalid_status', 'Job packs are for open work orders. Use the Servicebericht for closed ones.');
		}
		$detail = $this->workOrderService->get($workOrderId);
		$html = $this->buildHtml($detail, false);
		return $this->render($html, 'job-pack-' . (string)$detail['number']);
	}

	/**
	 * @return array{filename: string, mime: string, content: string}
	 */
	public function servicebericht(int $workOrderId): array
	{
		$wo = $this->workOrders->findById($workOrderId);
		if ($wo->getStatus() !== WorkOrder::STATUS_DONE) {
			throw new ConflictException('wo_not_done', 'The Servicebericht is available once the work order is done.');
		}
		$detail = $this->workOrderService->get($workOrderId);
		$html = $this->buildHtml($detail, true);
		return $this->render($html, 'servicebericht-' . (string)$detail['number']);
	}

	/**
	 * W7 Prüfnachweis / Inspection evidence report (CORE §21 W7-R8).
	 * Never labelled as Zertifikat / Konformitätsbescheinigung.
	 *
	 * @return array{filename: string, mime: string, content: string}
	 */
	public function inspectionEvidence(int $workOrderId): array
	{
		$wo = $this->workOrders->findById($workOrderId);
		if ($wo->getStatus() !== WorkOrder::STATUS_DONE) {
			throw new ConflictException('wo_not_done', 'Inspection evidence is available once the work order is done.');
		}
		if ($wo->getKind() !== WorkOrder::KIND_INSPECTION) {
			throw new ConflictException('invalid_kind', 'Inspection evidence is only for inspection work orders.');
		}
		$detail = $this->workOrderService->get($workOrderId);
		$html = $this->buildInspectionEvidenceHtml($detail);
		return $this->render($html, 'pruefnachweis-' . (string)$detail['number']);
	}

	/**
	 * @param array<string, mixed> $detail
	 */
	private function buildInspectionEvidenceHtml(array $detail): string
	{
		$e = static fn (?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$title = $this->l->t('Inspection evidence report');
		$disclaimer = $this->l->t(
			'This document is a work record (Arbeitsnachweis). It is not a certificate, conformity declaration, or legal compliance statement.',
		);
		$number = $e((string)($detail['number'] ?? ''));
		$result = $e((string)($detail['result'] ?? ''));
		$inspector = $e((string)($detail['inspectorName'] ?? ''));
		$note = $e((string)($detail['inspectorNote'] ?? ''));
		$equip = $e((string)($detail['equipmentLabel'] ?? ''));
		$photosById = [];
		foreach (is_array($detail['photos'] ?? null) ? $detail['photos'] : [] as $photo) {
			if (is_array($photo) && isset($photo['id'])) {
				$photosById[(int)$photo['id']] = $photo;
			}
		}
		$rows = '';
		$linkedThumbs = '';
		foreach (($detail['defects'] ?? []) as $defect) {
			if (!is_array($defect)) {
				continue;
			}
			$photoId = isset($defect['photoFileId']) ? (int)$defect['photoFileId'] : 0;
			$photoCell = '—';
			if ($photoId > 0 && isset($photosById[$photoId])) {
				$photo = $photosById[$photoId];
				$photoCell = $e((string)($photo['originalName'] ?? $photo['fileName'] ?? ('#' . $photoId)));
				try {
					$bytes = $this->storage->readPhoto((int)$detail['id'], (string)$photo['fileName']);
					$mime = (string)($photo['mime'] ?? 'image/jpeg');
					$linkedThumbs .= '<img alt="" src="data:' . $e($mime) . ';base64,'
						. base64_encode($bytes) . '" style="max-width:120px;max-height:90px;margin:4px"/>';
				} catch (\Throwable) {
					// Soft-fail: row still lists the linked photo id.
				}
			} elseif ($photoId > 0) {
				$photoCell = '#' . $photoId;
			}
			$rows .= '<tr><td>' . $e((string)($defect['code'] ?? '')) . '</td><td>'
				. $e((string)($defect['body'] ?? '')) . '</td><td>' . $photoCell . '</td></tr>';
		}
		if ($rows === '') {
			$rows = '<tr><td colspan="3">' . $e($this->l->t('No defects recorded.')) . '</td></tr>';
		}
		$linkedHtml = $linkedThumbs !== ''
			? '<h2>' . $e($this->l->t('Defect photos')) . '</h2><div class="thumbs">' . $linkedThumbs . '</div>'
			: '';
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e($title) . '</title>'
			. '<style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111}'
			. 'h1{font-size:18px}table{width:100%;border-collapse:collapse;margin-top:12px}'
			. 'td,th{border:1px solid #ccc;padding:6px;text-align:left}'
			. '.disclaimer{margin-top:24px;padding:10px;border:1px solid #999;font-size:11px}</style></head><body>'
			. '<h1>' . $e($title) . '</h1>'
			. '<p><strong>' . $e($this->l->t('Work order')) . ':</strong> ' . $number . '</p>'
			. '<p><strong>' . $e($this->l->t('Equipment')) . ':</strong> ' . $equip . '</p>'
			. '<p><strong>' . $e($this->l->t('Result')) . ':</strong> ' . $result . '</p>'
			. '<p><strong>' . $e($this->l->t('Inspector')) . ':</strong> ' . $inspector . '</p>'
			. ($note !== '' ? '<p><strong>' . $e($this->l->t('Inspector note')) . ':</strong> ' . $note . '</p>' : '')
			. '<h2>' . $e($this->l->t('Defects')) . '</h2>'
			. '<table><thead><tr><th>' . $e($this->l->t('Code')) . '</th><th>' . $e($this->l->t('Description')) . '</th><th>' . $e($this->l->t('Photo')) . '</th></tr></thead><tbody>'
			. $rows . '</tbody></table>'
			. $linkedHtml
			. '<div class="disclaimer">' . $e($disclaimer) . '</div>'
			. '</body></html>';
	}

	// ── Internals ───────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $detail
	 */
	private function buildHtml(array $detail, bool $isReport): string
	{
		$e = static fn (?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$dash = '&#8212;';

		$title = $isReport ? $this->l->t('Service report') : $this->l->t('Job pack');
		$number = $e((string)$detail['number']);

		$metaRows = '';
		$meta = [
			[$this->l->t('Customer'), (string)($detail['customerName'] ?? '')],
			[$this->l->t('Site'), (string)($detail['siteName'] ?? '')],
			[$this->l->t('Address'), (string)($detail['siteAddress'] ?? '')],
			[$this->l->t('Equipment'), (string)($detail['equipmentLabel'] ?? '')],
			[$this->l->t('Serial number'), (string)($detail['equipmentSerialNo'] ?? '')],
			[$this->l->t('Title'), (string)($detail['title'] ?? '')],
			[$this->l->t('Kind'), (string)($detail['kind'] ?? '')],
			[$this->l->t('Priority'), (string)($detail['priority'] ?? '')],
			[$this->l->t('Due on'), (string)($detail['dueOn'] ?? '')],
			[$this->l->t('Assigned to'), (string)($detail['primaryUserId'] ?? '')],
		];
		if ($isReport) {
			$doneOn = (string)($detail['doneOn'] ?? '');
			if ($doneOn === '' && !empty($detail['completedAt'])) {
				$doneOn = gmdate('Y-m-d', (int)$detail['completedAt']);
			}
			$actualMinutes = $detail['actualMinutes'] ?? null;
			if ($actualMinutes === null
				&& !empty($detail['startedAt'])
				&& !empty($detail['completedAt'])
				&& (int)$detail['completedAt'] >= (int)$detail['startedAt']
			) {
				$actualMinutes = (int)floor(((int)$detail['completedAt'] - (int)$detail['startedAt']) / 60);
			}
			$meta[] = [$this->l->t('Completed on'), $doneOn];
			$meta[] = [$this->l->t('Duration (minutes)'), $actualMinutes !== null ? (string)$actualMinutes : ''];
		}
		foreach ($meta as [$label, $value]) {
			$metaRows .= '<tr><th>' . $e($label) . '</th><td>' . ($value !== '' ? $e($value) : $dash) . '</td></tr>';
		}

		$checklistHtml = '';
		$items = is_array($detail['checklist'] ?? null) ? $detail['checklist'] : [];
		if ($items !== []) {
			$rows = '';
			foreach ($items as $item) {
				if (($item['visible'] ?? true) === false) {
					continue;
				}
				$label = $e((string)($item['label'] ?? ''));
				$required = ($item['requiredEffective'] ?? $item['required'] ?? false)
					? ' <span class="req">*</span>' : '';
				if ($isReport) {
					$result = (string)($item['result'] ?? '');
					$resultLabel = match ($result) {
						'ok' => $this->l->t('OK'),
						'fail' => $this->l->t('Fail'),
						'na' => $this->l->t('N/A'),
						default => '',
					};
					$cls = $result !== '' ? ' class="r-' . $e($result) . '"' : '';
					$note = trim((string)($item['note'] ?? ''));
					$noteHtml = $note !== '' ? '<div class="note">' . $e($note) . '</div>' : '';
					$rows .= '<tr><td' . $cls . '>' . ($resultLabel !== '' ? $e($resultLabel) : $dash) . '</td>'
						. '<td>' . $label . $required . $noteHtml . '</td></tr>';
				} else {
					$rows .= '<tr><td class="box">&#9744;</td><td>' . $label . $required . '</td></tr>';
				}
			}
			if ($rows !== '') {
				$checklistHtml = '<h2>' . $e($this->l->t('Checklist')) . '</h2>'
					. '<table class="list"><tbody>' . $rows . '</tbody></table>';
			}
		}

		$kitHtml = '';
		$kit = $detail['kit'] ?? null;
		if (!$isReport && is_array($kit) && is_array($kit['lines'] ?? null) && $kit['lines'] !== []) {
			$rows = '';
			foreach ($kit['lines'] as $line) {
				$rows .= '<tr><td class="box">&#9744;</td>'
					. '<td>' . $e((string)($line['label'] ?? '')) . '</td>'
					. '<td>' . $e((string)($line['kind'] ?? '')) . '</td>'
					. '<td class="num">' . $e((string)($line['qtyRequired'] ?? '')) . '</td></tr>';
			}
			$kitHtml = '<h2>' . $e($this->l->t('Kit — parts and tools to pack')) . '</h2>'
				. '<table class="list"><tbody>' . $rows . '</tbody></table>';
		}

		$skillsHtml = '';
		$skills = is_array($detail['requiredSkills'] ?? null) ? $detail['requiredSkills'] : [];
		if (!$isReport && $skills !== []) {
			$names = array_map(static fn (array $s): string => (string)($s['name'] ?? ''), $skills);
			$skillsHtml = '<h2>' . $e($this->l->t('Required skills')) . '</h2><p>'
				. $e(implode(', ', array_filter($names))) . '</p>';
		}

		$notesHtml = '';
		$description = trim((string)($detail['description'] ?? ''));
		if ($description !== '') {
			$notesHtml = '<h2>' . $e($this->l->t('Description')) . '</h2><p>' . nl2br($e($description)) . '</p>';
		}

		$signatureHtml = '';
		if ($isReport) {
			$signature = $detail['signature'] ?? null;
			$signatureImg = '';
			if (is_array($signature)) {
				try {
					$png = $this->storage->readSignature((int)$detail['id'], (string)$signature['fileName']);
					$signatureImg = '<img class="sig" src="data:image/png;base64,' . base64_encode($png) . '" alt=""/>';
				} catch (\Throwable) {
					// dangling row → treat as not captured
					$signatureImg = '';
				}
			}
			if ($signatureImg !== '') {
				$signer = trim((string)($signature['signerName'] ?? ''));
				$signatureHtml = '<h2>' . $e($this->l->t('Signature')) . '</h2>' . $signatureImg
					. ($signer !== '' ? '<div class="signer">' . $e($signer) . '</div>' : '');
			} else {
				$signatureHtml = '<h2>' . $e($this->l->t('Signature')) . '</h2><p class="muted">'
					. $e($this->l->t('Signature: not captured')) . '</p>';
			}
		}

		$photosHtml = '';
		if ($isReport) {
			$photos = is_array($detail['photos'] ?? null) ? $detail['photos'] : [];
			$totalPhotos = count($photos);
			$shown = array_slice($photos, 0, self::MAX_REPORT_PHOTOS);
			$thumbs = '';
			foreach ($shown as $photo) {
				if (!is_array($photo)) {
					continue;
				}
				try {
					$bytes = $this->storage->readPhoto((int)$detail['id'], (string)$photo['fileName']);
					$mime = (string)($photo['mime'] ?? 'image/jpeg');
					if (!preg_match('#^image/(jpeg|png|webp)$#', $mime)) {
						$mime = 'image/jpeg';
					}
					$thumbs .= '<img class="thumb" src="data:' . $e($mime) . ';base64,'
						. base64_encode($bytes) . '" alt=""/>';
				} catch (\Throwable) {
					// skip missing/corrupt binaries
				}
			}
			if ($thumbs !== '' || $totalPhotos > 0) {
				$photosHtml = '<h2>' . $e($this->l->t('Photos')) . '</h2><div class="thumbs">' . $thumbs . '</div>';
				if ($totalPhotos > self::MAX_REPORT_PHOTOS) {
					$photosHtml .= '<p class="muted">' . $e($this->l->t(
						'Showing %1$s of %2$s photos.',
						[(string)self::MAX_REPORT_PHOTOS, (string)$totalPhotos],
					)) . '</p>';
				}
			}
		}

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
			body{font-family:DejaVu Sans,sans-serif;font-size:10pt;color:#1a1a1a}
			h1{font-size:16pt;margin:0 0 2pt}
			h2{font-size:12pt;margin:14pt 0 4pt;border-bottom:1px solid #999;padding-bottom:2pt}
			.no{color:#555;font-size:11pt;margin-bottom:10pt}
			table.meta{border-collapse:collapse;width:100%}
			table.meta th{text-align:left;width:34%;padding:3pt 6pt;color:#444;font-weight:normal;vertical-align:top}
			table.meta td{padding:3pt 6pt;vertical-align:top}
			table.list{border-collapse:collapse;width:100%}
			table.list td{border-bottom:1px solid #ddd;padding:4pt 6pt;vertical-align:top}
			td.box{width:18pt;font-size:12pt}
			td.num{text-align:right;width:12%}
			.req{color:#b00}
			.note{color:#555;font-size:9pt;margin-top:2pt}
			.r-ok{color:#0a600a;font-weight:bold;width:16%}
			.r-fail{color:#b00000;font-weight:bold;width:16%}
			.r-na{color:#666;width:16%}
			img.sig{max-width:220pt;max-height:90pt;border-bottom:1px solid #333}
			img.thumb{max-width:110pt;max-height:80pt;margin:4pt 6pt 4pt 0;border:1px solid #ccc}
			.thumbs{line-height:0}
			.signer{color:#444;font-size:9pt;margin-top:2pt}
			.muted{color:#777}
		</style></head><body>
			<h1>' . $e($title) . '</h1>
			<div class="no">' . $number . '</div>
			<table class="meta"><tbody>' . $metaRows . '</tbody></table>'
			. $notesHtml . $checklistHtml . $kitHtml . $skillsHtml . $photosHtml . $signatureHtml
			. '</body></html>';
	}

	/**
	 * @return array{filename: string, mime: string, content: string}
	 */
	private function render(string $html, string $nameHint): array
	{
		$options = new Options();
		$options->set('isRemoteEnabled', false);
		$options->set('isHtml5ParserEnabled', true);
		$options->set('defaultFont', 'DejaVu Sans');
		$dompdf = new Dompdf($options);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('A4', 'portrait');
		$dompdf->render();
		$content = $dompdf->output();
		if (!is_string($content) || $content === '') {
			throw new \RuntimeException('pdf_empty');
		}
		$safe = preg_replace('/[^A-Za-z0-9\-_.]/', '_', $nameHint) ?: 'work-order';
		return ['filename' => $safe . '.pdf', 'mime' => 'application/pdf', 'content' => $content];
	}
}
