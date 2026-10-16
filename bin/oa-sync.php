<?php

declare(strict_types=1);

/**
 * Sync the RVC-ONE user directory (CLI / cron).
 *
 *   php bin/oa-sync.php          incremental: users changed since the last successful run
 *   php bin/oa-sync.php --full   pull everyone again
 *
 * Suggested crontab (every 30 minutes, plus a nightly full sync):
 *   0,30 * * * * php /var/www/web/bin/oa-sync.php >> /var/www/web/storage/logs/oa-sync.log 2>&1
 *   15 2 * * *   php /var/www/web/bin/oa-sync.php --full >> /var/www/web/storage/logs/oa-sync.log 2>&1
 */

use App\Services\OaApiException;
use App\Services\OaUserSync;
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
date_default_timezone_set(Config::get('app.timezone', 'Asia/Bangkok'));
ini_set('error_log', ''); // [oa-sync] lines go to stderr → the cron log

$full = in_array('--full', $argv, true);
$started = microtime(true);

try {
    $r = (new OaUserSync(Db::connection()))->run($full);
} catch (OaApiException $e) {
    fwrite(STDERR, date('c') . ' sync failed: ' . $e->getMessage() . "\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, date('c') . ' sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "%s %s sync ok in %.1fs: pages=%d fetched=%d added=%d updated=%d suspended=%d since=%s next_since=%s\n",
    date('c'), $r['mode'], microtime(true) - $started, $r['pages'], $r['fetched'],
    $r['added'], $r['updated'], $r['suspended'], $r['since'] ?? '-', $r['server_time'] ?? '-'
);
