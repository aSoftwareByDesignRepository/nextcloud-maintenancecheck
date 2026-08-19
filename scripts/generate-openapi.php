#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Rebuild tests/fixtures/openapi.json from appinfo/routes.php + RouteAuthInventory.
 */

$appRoot = dirname(__DIR__);
require $appRoot . '/vendor/autoload.php';

$routes = require $appRoot . '/appinfo/routes.php';
$doc = OCA\MaintenanceCheck\Tests\Support\RouteAuthInventory::openapiDocument($routes);
$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$path = $appRoot . '/tests/fixtures/openapi.json';
file_put_contents($path, $json);
echo "Wrote {$path} (" . count($routes['routes']) . " operations)\n";
