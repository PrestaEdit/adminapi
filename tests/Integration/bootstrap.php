<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$psRoot = getenv('PS_ROOT') ?: '/var/www/html';
require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';
