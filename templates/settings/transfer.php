<?php

declare(strict_types=1);

/**
 * App settings sub-page: Transfer configuration (test → production).
 *
 * Thin dispatcher wrapper: the synced config-transfer pack ships its
 * canonical partial at templates/parts/settings/transfer.php (the pack's
 * fixed target for every consumer). MaintenanceCheck settings underpages
 * dispatch through templates/settings/<section>.php, so this file bridges
 * the two — it renders the synced partial unchanged.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/../parts/settings/transfer.php';
