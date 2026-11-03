<?php

declare(strict_types=1);

/**
 * Record today's disk space reading (CLI / scheduled task). The system also records one by itself
 * after the first signed-in request of each day; this covers days nobody opens it.
 *
 *   php bin/disk-usage.php
 *
 * Suggested crontab (daily at 23:55):
 *   55 23 * * * php /var/www/web/bin/disk-usage.php >> /var/www/web/storage/logs/disk-usage.log 2>&1
 * Windows (XAMPP): Task Scheduler, daily, program C:\xampp\php\php.exe, argument C:\xampp\htdocs\rvc.coac\bin\disk-usage.php
 */

use App\Services\DiskUsageService;
use App\Support\Config;
use App\Support\Db;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

if (!Config::isInstalled()) {
    fwrite(STDERR, "System not installed yet\n");
    exit(1);
}

try {
    $m = (new DiskUsageService(Db::connection()))->record();
} catch (\Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n");
    exit(1);
}
$gb = fn (int $b) => number_format($b / 1073741824, 2) . ' GB';
echo date('Y-m-d H:i:s') . ' disk ' . $gb($m['disk_total']) . ', free ' . $gb($m['disk_free'])
    . ', uploads ' . $gb($m['uploads_bytes']) . ', database ' . $gb($m['db_bytes']) . "\n";
