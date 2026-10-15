<?php

declare(strict_types=1);

/**
 * Root entry point. Not part of the app itself (the real app lives under
 * /public) — this just checks whether the system has been installed yet
 * and sends the browser to the right place, so users can bookmark the
 * project root instead of /public/install.php or /public/.
 */

require __DIR__ . '/vendor/autoload.php';

use App\Support\Config;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$target = Config::isInstalled()
    ? $base . '/public/'
    : $base . '/public/install.php';

header('Location: ' . $target);
exit;
