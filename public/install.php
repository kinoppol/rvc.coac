<?php

declare(strict_types=1);

$root = dirname(__DIR__);

// Composer packages must be installed before anything else can load.
// Check this first so the user gets instructions instead of a PHP fatal error.
if (!is_file($root . '/vendor/autoload.php')) {
    renderMissingVendor();
    exit;
}

require $root . '/vendor/autoload.php';

use App\Support\Config;
use App\Support\Db;
use App\Support\MigrationRunner;

session_start();

$step = $_GET['step'] ?? 'requirements';
$errors = [];
$notice = null;

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Probes the real web server: a request for an /api path must reach index.php
 * (JSON reply). Without mod_rewrite or AllowOverride, Apache answers with its
 * HTML 404 page instead. Works on any Apache setup, unlike apache_get_modules().
 */
function probeRewrite(): array
{
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/public/install.php'), '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // /api/auth/me is a real JSON endpoint (401 when logged out). Unknown paths get the SPA HTML instead.
    $url = $scheme . '://' . $host . $scriptDir . '/api/auth/me';

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'detail' => 'ตรวจไม่ได้เพราะไม่มี curl'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'detail' => 'เรียก ' . $url . ' ไม่ได้: ' . $err];
    }
    $headerEnd = strpos((string) $raw, "\r\n\r\n");
    $headers = $headerEnd === false ? '' : substr((string) $raw, 0, $headerEnd);
    $isJson = stripos($headers, 'Content-Type: application/json') !== false;
    return [
        'ok' => $isJson,
        'detail' => $isJson
            ? 'คำขอ /api ถูกส่งไปที่ index.php แล้ว'
            : 'คำขอ /api ไม่ถูก rewrite (ได้หน้า HTML) — เปิด mod_rewrite และตั้ง AllowOverride All ให้โฟลเดอร์ htdocs',
    ];
}

function checkRequirements(string $root): array
{
    $extensions = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo', 'session', 'curl'];
    $extChecks = [];
    foreach ($extensions as $ext) {
        $extChecks[] = ['label' => "PHP extension: $ext", 'ok' => extension_loaded($ext)];
    }

    $writable = [
        'config/' => $root . '/config',
        'storage/' => $root . '/storage',
        'storage/logs/' => $root . '/storage/logs',
        'storage/cache/' => $root . '/storage/cache',
        'storage/uploads/' => $root . '/storage/uploads',
    ];
    $dirChecks = [];
    foreach ($writable as $label => $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        $dirChecks[] = ['label' => "เขียนไฟล์ได้: $label", 'ok' => is_dir($path) && is_writable($path)];
    }

    $packageChecks = [];
    foreach ([
        'Composer package: slim/slim' => 'Slim\\Factory\\AppFactory',
        'Composer package: slim/psr7' => 'Slim\\Psr7\\Factory\\ResponseFactory',
    ] as $label => $class) {
        $packageChecks[] = ['label' => $label, 'ok' => class_exists($class)];
    }

    $rewrite = probeRewrite();
    $rewriteChecks = [
        ['label' => 'Apache mod_rewrite / .htaccess (' . $rewrite['detail'] . ')', 'ok' => $rewrite['ok']],
    ];

    $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
    $checks = array_merge(
        [['label' => 'PHP >= 8.1 (พบ ' . PHP_VERSION . ')', 'ok' => $phpOk]],
        $extChecks,
        $packageChecks,
        $rewriteChecks,
        $dirChecks
    );

    $allOk = true;
    foreach ($checks as $c) {
        if (!$c['ok']) {
            $allOk = false;
        }
    }

    return ['checks' => $checks, 'ok' => $allOk];
}

$alreadyInstalled = Config::isInstalled();
$repairMode = isset($_GET['repair']) && $_GET['repair'] === '1';

// ---- Guard: if already installed and not explicitly repairing, show status page ----
if ($alreadyInstalled && !$repairMode && $step !== 'do-repair') {
    $cfg = require Config::configPath();
    $dbOk = false;
    $dbError = null;
    $pendingCount = 0;
    try {
        $pdo = Db::connect($cfg['db']);
        $dbOk = true;
        $runner = new MigrationRunner();
        $pendingCount = count($runner->pendingNames($pdo));
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
    }
    $req = checkRequirements($root);
    renderLayout('ระบบติดตั้งแล้ว', function () use ($dbOk, $dbError, $pendingCount, $req) {
        ?>
        <div class="card">
            <h1>✅ ระบบติดตั้งแล้ว</h1>
            <p class="muted">พบไฟล์ config/config.php และ storage/installed.lock อยู่แล้ว ระบบพร้อมใช้งาน</p>
            <ul class="status-list">
                <li class="<?= $dbOk ? 'ok' : 'bad' ?>">
                    การเชื่อมต่อฐานข้อมูล: <?= $dbOk ? 'สำเร็จ' : 'ล้มเหลว - ' . h((string) $dbError) ?>
                </li>
                <li class="<?= $req['ok'] ? 'ok' : 'bad' ?>">
                    ความพร้อมของเซิร์ฟเวอร์ (PHP/นามสกุล/สิทธิ์เขียนไฟล์): <?= $req['ok'] ? 'ผ่านทั้งหมด' : 'มีรายการไม่ผ่าน' ?>
                </li>
                <li class="<?= $pendingCount === 0 ? 'ok' : 'warn' ?>">
                    Migrations ที่ยังไม่ได้รัน: <?= (int) $pendingCount ?> รายการ
                    <?php if ($pendingCount > 0): ?>
                        — ไปที่เมนู "จัดการฐานข้อมูล (Migrations)" ในระบบเพื่อรัน
                    <?php endif; ?>
                </li>
            </ul>
            <div class="actions">
                <a class="btn" href="./">เข้าสู่ระบบ</a>
                <a class="btn secondary" href="?step=do-repair">ซ่อมแซม / ตั้งค่าใหม่</a>
            </div>
            <p class="fineprint">
                "ซ่อมแซม / ตั้งค่าใหม่" จะพาไปยังขั้นตอนติดตั้งอีกครั้งเพื่อแก้ไขการตั้งค่าฐานข้อมูลหรือรัน migration ที่ค้างอยู่
                <b>จะไม่ลบข้อมูลที่มีอยู่</b> (migration runner จะข้ามรายการที่รันไปแล้วเสมอ)
            </p>
        </div>
        <?php
    });
    exit;
}

// ---- Step: requirements ----
if ($step === 'requirements') {
    $req = checkRequirements($root);
    renderLayout('ตรวจสอบความพร้อมของระบบ', function () use ($req, $repairMode) {
        ?>
        <div class="card">
            <h1>1. ตรวจสอบความพร้อมของเซิร์ฟเวอร์</h1>
            <?php if ($repairMode): ?>
                <p class="notice">โหมดซ่อมแซม: การตั้งค่าเดิมจะถูกใช้เป็นค่าเริ่มต้นในขั้นถัดไป และจะไม่ลบข้อมูลที่มีอยู่</p>
            <?php endif; ?>
            <ul class="status-list">
                <?php foreach ($req['checks'] as $c): ?>
                    <li class="<?= $c['ok'] ? 'ok' : 'bad' ?>"><?= h($c['label']) ?>: <?= $c['ok'] ? 'ผ่าน' : 'ไม่ผ่าน' ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="actions">
                <?php if ($req['ok']): ?>
                    <a class="btn" href="?step=db<?= $repairMode ? '&repair=1' : '' ?>">ถัดไป: ตั้งค่าฐานข้อมูล →</a>
                <?php else: ?>
                    <a class="btn secondary" href="?step=requirements<?= $repairMode ? '&repair=1' : '' ?>">ตรวจสอบอีกครั้ง</a>
                    <p class="bad">กรุณาแก้ไขรายการที่ไม่ผ่านก่อนดำเนินการต่อ</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    });
    exit;
}

// ---- Step: db connection ----
if ($step === 'db') {
    $existing = $alreadyInstalled ? (require Config::configPath())['db'] : [
        'host' => '127.0.0.1', 'port' => '3306', 'database' => 'rvc_coac', 'username' => 'root', 'password' => '',
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $db = [
            'host' => trim($_POST['host'] ?? ''),
            'port' => trim($_POST['port'] ?? '3306'),
            'database' => trim($_POST['database'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
            'charset' => 'utf8mb4',
        ];
        try {
            $pdo = Db::connect($db, false);
            $dbNameEsc = str_replace('`', '``', $db['database']);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbNameEsc` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $appCfg = Config::isInstalled() ? (require Config::configPath())['app'] : [
                'name' => 'ระบบฝากขาย ร้านค้าสหการ',
                'timezone' => 'Asia/Bangkok',
                'key' => bin2hex(random_bytes(32)),
            ];
            Config::write(['db' => $db, 'app' => $appCfg]);

            header('Location: ?step=migrate' . ($repairMode ? '&repair=1' : ''));
            exit;
        } catch (\Throwable $e) {
            $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage();
            $existing = $db;
        }
    }

    renderLayout('ตั้งค่าฐานข้อมูล', function () use ($existing, $errors, $repairMode) {
        ?>
        <div class="card">
            <h1>2. ตั้งค่าการเชื่อมต่อฐานข้อมูล (MariaDB)</h1>
            <?php foreach ($errors as $err): ?><p class="bad"><?= h($err) ?></p><?php endforeach; ?>
            <form method="post">
                <label>Host <input name="host" value="<?= h((string) $existing['host']) ?>" required></label>
                <label>Port <input name="port" value="<?= h((string) $existing['port']) ?>" required></label>
                <label>ชื่อฐานข้อมูล <input name="database" value="<?= h((string) $existing['database']) ?>" required></label>
                <label>ผู้ใช้ฐานข้อมูล <input name="username" value="<?= h((string) $existing['username']) ?>" required></label>
                <label>รหัสผ่าน <input type="password" name="password" value="<?= h((string) $existing['password']) ?>"></label>
                <div class="actions">
                    <button class="btn" type="submit">ทดสอบการเชื่อมต่อและถัดไป →</button>
                </div>
            </form>
        </div>
        <?php
    });
    exit;
}

// ---- Step: run migrations ----
if ($step === 'migrate') {
    if (!is_file(Config::configPath())) {
        header('Location: ?step=db' . ($repairMode ? '&repair=1' : ''));
        exit;
    }
    $cfg = require Config::configPath();
    $ran = [];
    $migrateError = null;
    try {
        $pdo = Db::connect($cfg['db']);
        $runner = new MigrationRunner();
        $ran = $runner->runPending($pdo);
    } catch (\Throwable $e) {
        $migrateError = $e->getMessage();
    }

    $adminExists = false;
    if (!$migrateError) {
        try {
            $pdo = Db::connect($cfg['db']);
            $adminExists = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $migrateError = $e->getMessage();
        }
    }

    renderLayout('ติดตั้งโครงสร้างฐานข้อมูล', function () use ($ran, $migrateError, $adminExists, $repairMode) {
        ?>
        <div class="card">
            <h1>3. ติดตั้ง/ปรับปรุงโครงสร้างฐานข้อมูล</h1>
            <?php if ($migrateError): ?>
                <p class="bad">เกิดข้อผิดพลาด: <?= h($migrateError) ?></p>
                <div class="actions"><a class="btn secondary" href="?step=db<?= $repairMode ? '&repair=1' : '' ?>">← กลับไปแก้ไขการตั้งค่าฐานข้อมูล</a></div>
            <?php else: ?>
                <p class="ok">รัน migration สำเร็จ <?= count($ran) ?> รายการใหม่ (รายการที่เคยรันแล้วจะถูกข้ามเสมอ)</p>
                <?php if (!empty($ran)): ?>
                    <ul class="status-list">
                        <?php foreach ($ran as $name): ?><li class="ok"><?= h($name) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="actions">
                    <?php if ($adminExists && !$repairMode): ?>
                        <a class="btn" href="?step=finish">ถัดไป: เสร็จสิ้น →</a>
                    <?php elseif ($adminExists): ?>
                        <a class="btn" href="?step=admin&repair=1">ถัดไป: ตั้งรหัสผ่านผู้ดูแลระบบ →</a>
                    <?php else: ?>
                        <a class="btn" href="?step=admin<?= $repairMode ? '&repair=1' : '' ?>">ถัดไป: สร้างบัญชีผู้ดูแลระบบ →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    });
    exit;
}

// ---- Step: create admin account / reset admin password ----
if ($step === 'admin') {
    $cfg = require Config::configPath();
    $pdo = Db::connect($cfg['db']);
    $admins = $pdo->query("SELECT id, username, full_name FROM users WHERE role='admin' ORDER BY id ASC")->fetchAll();
    $adminExists = count($admins) > 0;

    if ($adminExists && !$repairMode) {
        header('Location: ?step=finish');
        exit;
    }

    $mode = $_POST['mode'] ?? ($_GET['mode'] ?? ($adminExists ? 'reset' : 'create'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'reset') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if (!$userId || strlen($password) < 8) {
            $errors[] = 'กรุณาเลือกบัญชีและตั้งรหัสผ่านอย่างน้อย 8 ตัวอักษร';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, active = 1, updated_at = NOW() WHERE id = ? AND role = \'admin\'');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            header('Location: ?step=finish');
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');

        if ($username === '' || strlen($password) < 8 || $fullName === '') {
            $errors[] = 'กรุณากรอกข้อมูลให้ครบ และรหัสผ่านอย่างน้อย 8 ตัวอักษร';
        } else {
            $dupCheck = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
            $dupCheck->execute([$username]);
            if ($dupCheck->fetchColumn()) {
                $errors[] = 'มีชื่อผู้ใช้นี้อยู่แล้ว กรุณาเลือกชื่ออื่น';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, full_name, role, active, created_at, updated_at)
                     VALUES (?, ?, ?, \'admin\', 1, NOW(), NOW())'
                );
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
                header('Location: ?step=finish');
                exit;
            }
        }
    }

    renderLayout($adminExists ? 'ตั้งรหัสผ่านผู้ดูแลระบบ' : 'สร้างบัญชีผู้ดูแลระบบ', function () use ($errors, $admins, $adminExists, $mode) {
        ?>
        <div class="card">
            <h1>4. <?= $adminExists ? 'ตั้งรหัสผ่านผู้ดูแลระบบ (Admin)' : 'สร้างบัญชีผู้ดูแลระบบ (Admin)' ?></h1>
            <?php foreach ($errors as $err): ?><p class="bad"><?= h($err) ?></p><?php endforeach; ?>

            <?php if ($adminExists): ?>
                <div class="actions" style="margin-bottom:16px">
                    <a class="btn <?= $mode === 'reset' ? '' : 'secondary' ?>" href="?step=admin&repair=1&mode=reset">ตั้งรหัสผ่านใหม่ให้บัญชีเดิม</a>
                    <a class="btn <?= $mode === 'create' ? '' : 'secondary' ?>" href="?step=admin&repair=1&mode=create">สร้างบัญชีผู้ดูแลใหม่</a>
                </div>
            <?php endif; ?>

            <?php if ($mode === 'reset'): ?>
                <form method="post">
                    <input type="hidden" name="mode" value="reset">
                    <label>เลือกบัญชีผู้ดูแลระบบ
                        <select name="user_id" required>
                            <?php foreach ($admins as $a): ?>
                                <option value="<?= (int) $a['id'] ?>"><?= h($a['username']) ?> (<?= h($a['full_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร) <input type="password" name="password" required></label>
                    <div class="actions"><button class="btn" type="submit">ตั้งรหัสผ่านใหม่และเสร็จสิ้น →</button></div>
                </form>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="mode" value="create">
                    <label>ชื่อ-นามสกุล <input name="full_name" required></label>
                    <label>ชื่อผู้ใช้ <input name="username" required></label>
                    <label>รหัสผ่าน (อย่างน้อย 8 ตัวอักษร) <input type="password" name="password" required></label>
                    <div class="actions"><button class="btn" type="submit">สร้างบัญชีและเสร็จสิ้น →</button></div>
                </form>
            <?php endif; ?>
        </div>
        <?php
    });
    exit;
}

// ---- Step: finish ----
if ($step === 'finish') {
    if (!is_file(Config::lockPath())) {
        file_put_contents(Config::lockPath(), date('c'));
    }
    renderLayout('ติดตั้งเสร็จสมบูรณ์', function () {
        ?>
        <div class="card">
            <h1>🎉 ติดตั้งเสร็จสมบูรณ์</h1>
            <p>ระบบพร้อมใช้งานแล้ว เข้าสู่ระบบด้วยบัญชีผู้ดูแลระบบที่สร้างไว้</p>
            <div class="actions"><a class="btn" href="./">เข้าสู่ระบบ →</a></div>
        </div>
        <?php
    });
    exit;
}

// ---- Step: do-repair (unlock without touching data) ----
if ($step === 'do-repair') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'ยืนยัน') {
        header('Location: ?step=requirements&repair=1');
        exit;
    }
    renderLayout('ยืนยันการซ่อมแซม', function () {
        ?>
        <div class="card">
            <h1>ยืนยันการเข้าสู่โหมดซ่อมแซม</h1>
            <p class="notice">โหมดนี้จะพาไปแก้ไขการตั้งค่าฐานข้อมูลและรัน migration ที่ค้างอยู่เท่านั้น ข้อมูลที่มีอยู่จะไม่ถูกลบ</p>
            <form method="post">
                <label>พิมพ์คำว่า <b>ยืนยัน</b> เพื่อดำเนินการต่อ <input name="confirm" required></label>
                <div class="actions"><button class="btn" type="submit">เข้าสู่โหมดซ่อมแซม</button></div>
            </form>
        </div>
        <?php
    });
    exit;
}

header('Location: ?step=requirements');
exit;

function renderMissingVendor(): void
{
    renderLayout('ยังไม่ได้ติดตั้ง Composer packages', function () {
        ?>
        <div class="card">
            <h1>ยังไม่ได้ติดตั้งไลบรารี (Composer)</h1>
            <p>ไม่พบโฟลเดอร์ <b>vendor/</b> ระบบต้องติดตั้งไลบรารีที่ใช้งาน (Slim Framework) ก่อน</p>
            <p class="muted">เปิด Terminal ที่โฟลเดอร์โปรเจกต์ (rvc.coac) แล้วรันคำสั่งนี้ จากนั้นกลับมาหน้านี้แล้วรีเฟรช</p>
            <pre style="background:#f8fafc;border:1px solid var(--border);border-radius:9px;padding:12px;overflow:auto;font-size:13.5px">composer install --no-dev --optimize-autoloader</pre>
            <p class="muted" style="font-size:13px">ถ้ายังไม่มี Composer ให้ดาวน์โหลดได้จาก getcomposer.org แล้วติดตั้ง (หรือใช้ไฟล์ composer.phar ที่มีในโปรเจกต์: <code>php composer.phar install --no-dev --optimize-autoloader</code>)</p>
            <div class="actions"><a class="btn" href="">ตรวจสอบอีกครั้ง</a></div>
        </div>
        <?php
    });
}

function renderLayout(string $title, callable $body): void
{
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> — ตัวติดตั้งระบบฝากขาย ร้านค้าสหการ</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root { --pri: #1552d1; --bg: #f3f5fa; --surface: #fff; --border: #e2e7f0; --text: #111827; --muted: #64748b; }
            * { box-sizing: border-box; }
            body { margin: 0; padding: 40px 16px; background: var(--bg); font-family: Sarabun, "Noto Sans Thai", system-ui, sans-serif; color: var(--text); }
            .card { max-width: 560px; margin: 0 auto; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 28px 30px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
            h1 { font-size: 19px; margin: 0 0 16px; }
            .muted { color: var(--muted); font-size: 13.5px; }
            .fineprint { color: var(--muted); font-size: 12px; margin-top: 18px; }
            .status-list { list-style: none; padding: 0; margin: 0 0 18px; display: flex; flex-direction: column; gap: 7px; font-size: 14px; }
            .status-list li { padding: 9px 12px; border-radius: 8px; background: #f8fafc; }
            .status-list li.ok { background: #eafaf0; color: #0f8a4d; }
            .status-list li.bad { background: #fdecec; color: #c22b2b; }
            .status-list li.warn { background: #fff7e6; color: #9a6700; }
            .ok { color: #0f8a4d; }
            .bad { color: #c22b2b; }
            .notice { background: #eef3fd; border: 1px solid #c7dafc; border-radius: 10px; padding: 10px 14px; font-size: 13.5px; }
            form { display: flex; flex-direction: column; gap: 14px; margin-top: 10px; }
            label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; }
            input { border: 1px solid #d6dce8; background: #fbfcfe; border-radius: 9px; padding: 10px 12px; font-size: 14px; font-family: inherit; font-weight: 400; }
            .actions { margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap; }
            .btn { background: var(--pri); color: #fff; border: 0; border-radius: 9px; padding: 11px 18px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
            .btn.secondary { background: transparent; color: var(--text); border: 1px solid #d6dce8; }
        </style>
    </head>
    <body>
        <?php $body(); ?>
    </body>
    </html>
    <?php
}
