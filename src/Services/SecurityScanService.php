<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Db;
use App\Support\RepairUnlock;
use PDO;

/**
 * Settings > ตรวจสอบความปลอดภัย (admins): looks for signs that someone has planted a web shell
 * (a PHP file that runs commands sent over the web, e.g. after uploading "php2shell") or could:
 *
 * - known web-shell code (eval of decoded text, commands built from request input, ...)
 * - PHP files where the system keeps none (storage/, uploads, public/assets ...) and images
 *   carrying PHP code
 * - files changed / added / removed since the admin's saved baseline (file fingerprints)
 * - PHP and server settings that make an attack easier
 * - folders that should be closed to the web but answer (probed over HTTP)
 * - the installer's repair mode and the admin accounts
 *
 * Read only: nothing is changed except the baseline file, when the admin saves it.
 */
final class SecurityScanService
{
    private const MAX_BYTES = 4_000_000;
    private const PHP_EXT = '/\.(php\d?|phtml|phar|pht|phps|inc)$/i';
    // Where PHP belongs. Anything else that runs as PHP was not put there by the system.
    private const PHP_ALLOWED = ['#^src/#', '#^migrations/#', '#^vendor/#', '#^bin/#', '#^config/config(\.sample)?\.php$#', '#^public/(index|install)\.php$#', '#^index\.php$#'];
    // Folders closed to the web by their own .htaccess (the root .htaccess turns them away too).
    private const DENY_HTACCESS = ['config', 'storage', 'src', 'migrations', 'project', 'bin', 'docs'];
    private const KNOWN_HTACCESS = ['.htaccess', 'public/.htaccess', 'config/.htaccess', 'storage/.htaccess', 'src/.htaccess', 'migrations/.htaccess', 'project/.htaccess', 'bin/.htaccess', 'storage/sessions/.htaccess'];
    // Not walked: Git internals, sessions (tokens) and the generated files under cache.
    private const SKIP_DIRS = ['.git', 'node_modules', 'storage/sessions', QuarantineService::DIR, BackupService::DIR];

    private const INPUT = '\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)|php:\/\/input|getallheaders\s*\(';
    private string $root;

    public function __construct(private PDO $db)
    {
        $this->root = str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /** @return array<int, array{rule: string, re: string, level: string}> */
    private static function signatures(): array
    {
        $in = self::INPUT;
        return [
            ['rule' => 'eval/assert กับข้อความที่ถอดรหัสหรือข้อมูลจากผู้ใช้', 'level' => 'bad',
                're' => '/\b(?:eval|assert)\s*\(\s*@?\s*(?:(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|urldecode|rawurldecode|convert_uudecode)\s*\(|' . $in . ')/i'],
            ['rule' => 'รันคำสั่งระบบปฏิบัติการจากข้อมูลที่ส่งมาทางเว็บ', 'level' => 'bad',
                're' => '/\b(?:system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\([^;]{0,120}(?:' . $in . ')/i'],
            ['rule' => 'เรียกฟังก์ชันตามชื่อที่ผู้ใช้ส่งมา', 'level' => 'bad',
                're' => '/(?:' . $in . ')\s*\[[^\]]{0,40}\]\s*\(/i'],
            ['rule' => 'backtick (รันคำสั่ง) กับข้อมูลจากผู้ใช้', 'level' => 'bad',
                're' => '/`[^`\n]{0,120}(?:' . $in . ')[^`\n]{0,120}`/i'],
            ['rule' => 'include/require ไฟล์ตามข้อมูลจากผู้ใช้', 'level' => 'bad',
                're' => '/\b(?:include|require)(?:_once)?\s*\(?\s*[^;\n]{0,60}(?:' . $in . ')/i'],
            ['rule' => 'เขียนไฟล์ด้วยข้อมูลที่ส่งมาทางเว็บโดยตรง', 'level' => 'warn',
                're' => '/\b(?:file_put_contents|fwrite|fputs|move_uploaded_file|copy)\s*\([^;]{0,160}(?:' . $in . ')/i'],
            ['rule' => 'preg_replace แบบ /e (รันโค้ดได้)', 'level' => 'bad',
                're' => '/\bpreg_replace\s*\(\s*([\'"])([^\w\s\\\\])[^\n]{0,200}?\2[imsxuADSUXJ]*e[imsxuADSUXJ]*\1/'],
            ['rule' => 'create_function (สร้างโค้ดจากข้อความ)', 'level' => 'warn',
                're' => '/\bcreate_function\s*\(/i'],
            ['rule' => 'ข้อความเข้ารหัสยาวผิดปกติถูกถอดรหัสในโค้ด', 'level' => 'bad',
                're' => '/\b(?:base64_decode|gzinflate|gzuncompress|str_rot13|hex2bin)\s*\(\s*[\'"][A-Za-z0-9+\/=\\\\x]{400,}/'],
            ['rule' => 'ซ่อนชื่อฟังก์ชันด้วย chr() ต่อกันยาว', 'level' => 'warn',
                're' => '/(?:chr\s*\(\s*\d+\s*\)\s*\.\s*){8,}/i'],
            ['rule' => 'ชื่อ web shell ที่รู้จัก', 'level' => 'bad',
                're' => '/\b(?:FilesMan|b374k|c99shell|r57shell|weevely|IndoXploit|AlfaShell|p0wny|WSO\s?[0-9]\.[0-9]|php2shell|antsword|behinder|godzilla)\b/i'],
        ];
    }

    /**
     * Web-shell patterns found in PHP source. Comments are blanked first (line breaks kept, so
     * line numbers hold): libraries document $_SERVER / $_POST in prose ("REQUIRED to originate
     * from $_SERVER"), and code in a comment never runs anyway.
     *
     * @return array<int, array{line: int, rule: string, level: string, code: string}>
     */
    public static function findings(string $text, bool $php): array
    {
        if ($php && str_contains($text, '<?')) {
            $text = self::withoutComments($text);
        }
        $out = [];
        foreach (self::signatures() as $s) {
            if (preg_match_all($s['re'], $text, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach (array_slice($m[0], 0, 3) as [$match, $offset]) {
                    $out[] = [
                        'line' => substr_count($text, "\n", 0, $offset) + 1,
                        'rule' => $s['rule'],
                        'level' => $s['level'],
                        'code' => mb_strimwidth(preg_replace('/\s+/', ' ', (string) $match), 0, 160, '…'),
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * PHP code inside an image or other data file. Only a real opening tag counts: "<?php" then
     * a space, or "<?=" then a variable / backtick / function name. A bare "<?=" turns up by
     * chance in the compressed bytes of ordinary photos.
     */
    public const EMBEDDED_CODE = '/<\?php[\s\/]|<\?=\s*[\$`a-z_]/i';

    public static function hasEmbeddedCode(string $raw): bool
    {
        return preg_match(self::EMBEDDED_CODE, $raw) === 1;
    }

    private static function withoutComments(string $text): string
    {
        try {
            $tokens = token_get_all($text);
        } catch (\Throwable) {
            return $text;
        }
        $out = '';
        foreach ($tokens as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                $out .= str_repeat("\n", substr_count($t[1], "\n"));
            } else {
                $out .= is_array($t) ? $t[1] : $t;
            }
        }
        return $out;
    }

    public function run(array $server): array
    {
        @set_time_limit(180);
        $started = microtime(true);
        $files = $this->files();

        $sections = [
            $this->scanCode($files),
            $this->strayFiles($files),
            $this->integrity($files),
            $this->phpSettings($server),
            $this->exposure($server),
            $this->repairState(),
            $this->accounts(),
        ];
        $counts = ['bad' => 0, 'warn' => 0, 'ok' => 0, 'info' => 0];
        foreach ($sections as $s) {
            foreach ($s['checks'] as $c) {
                $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
            }
        }
        return [
            'scanned_at' => date('Y-m-d H:i:s'),
            'seconds' => round(microtime(true) - $started, 1),
            'file_count' => count($files),
            'counts' => $counts,
            'sections' => $sections,
            'quarantine' => (new QuarantineService())->list(),
        ];
    }

    // ---------------------------------------------------------------- files

    /** @return array<string, string> relative path => absolute path, every file except SKIP_DIRS */
    private function files(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $f) {
                    $rel = $this->rel($f->getPathname());
                    return !in_array($rel, self::SKIP_DIRS, true);
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[$this->rel($f->getPathname())] = $f->getPathname();
            }
        }
        ksort($out);
        return $out;
    }

    private function rel(string $path): string
    {
        return ltrim(substr(str_replace('\\', '/', $path), strlen($this->root)), '/');
    }

    private static function check(string $status, string $title, string $detail = '', array $items = []): array
    {
        return ['status' => $status, 'title' => $title, 'detail' => $detail, 'items' => $items];
    }

    // ---------------------------------------------------------------- 1. web-shell code

    private function scanCode(array $files): array
    {
        $hits = [];
        $imageHits = [];
        $scanned = 0;
        foreach ($files as $rel => $abs) {
            $isPhp = preg_match(self::PHP_EXT, $rel) === 1;
            // Uploaded/static files: PHP hidden inside an image or text file (run via an include).
            $isData = !$isPhp && preg_match('#^(storage|public/assets)/#', $rel) === 1;
            if ((!$isPhp && !$isData) || filesize($abs) > self::MAX_BYTES) {
                continue;
            }
            $text = @file_get_contents($abs);
            if ($text === false) {
                continue;
            }
            $scanned++;
            if ($isData) {
                if (self::hasEmbeddedCode($text)) {
                    $imageHits[] = ['file' => $rel, 'path' => $rel, 'note' => 'พบโค้ด PHP ในไฟล์ที่ไม่ใช่ PHP (' . self::size((int) filesize($abs)) . ')'];
                }
                continue;
            }
            // The scanner holds the patterns themselves; it is not a finding.
            if ($rel === 'src/Services/SecurityScanService.php') {
                continue;
            }
            foreach (self::findings($text, true) as $f) {
                $hits[] = ['file' => $rel, 'path' => $rel] + $f + ['modified' => date('Y-m-d H:i', (int) filemtime($abs))];
            }
        }
        $badHits = array_filter($hits, fn ($h) => $h['level'] === 'bad');
        $checks = [
            self::check(
                $badHits ? 'bad' : ($hits ? 'warn' : 'ok'),
                'รูปแบบโค้ด web shell ในไฟล์ PHP',
                $hits
                    ? 'พบ ' . count($hits) . ' จุด — เปิดดูไฟล์ ถ้าไม่ใช่โค้ดของระบบหรือไลบรารีที่รู้จัก ให้ลบไฟล์ เปลี่ยนรหัสผ่านฐานข้อมูลและผู้ดูแลระบบ และตรวจ log ของเว็บเซิร์ฟเวอร์ว่าไฟล์ถูกวางเมื่อไร'
                    : "ตรวจ {$scanned} ไฟล์ ไม่พบ eval ข้อความเข้ารหัส การรันคำสั่งจากข้อมูลผู้ใช้ หรือชื่อ web shell ที่รู้จัก",
                $hits
            ),
            self::check(
                $imageHits ? 'bad' : 'ok',
                'โค้ด PHP แฝงในรูปภาพ / ไฟล์อัปโหลด / ไฟล์หน้าเว็บ',
                $imageHits ? 'ไฟล์ในโฟลเดอร์ข้อมูลไม่ควรมีโค้ด PHP — ระบบบันทึกเฉพาะรูปภาพที่ตรวจแล้ว ไฟล์เหล่านี้อาจถูกวางโดยผู้โจมตี' : 'ไม่พบโค้ด PHP ใน storage/ และ public/assets/',
                $imageHits
            ),
        ];
        return ['key' => 'code', 'title' => 'ค้นหา web shell (เช่น php2shell)', 'checks' => $checks];
    }

    // ---------------------------------------------------------------- 2. stray files

    private function strayFiles(array $files): array
    {
        $stray = [];
        $config = [];
        $htaccess = [];
        foreach ($files as $rel => $abs) {
            $base = basename($rel);
            if (preg_match(self::PHP_EXT, $rel) === 1) {
                $ok = false;
                foreach (self::PHP_ALLOWED as $re) {
                    if (preg_match($re, $rel) === 1) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    $stray[] = ['file' => $rel, 'path' => $rel, 'note' => 'แก้ไขล่าสุด ' . date('Y-m-d H:i', (int) filemtime($abs)) . ' · ' . self::size((int) filesize($abs))];
                }
            }
            // .user.ini / php.ini can make PHP load an attacker's file before every page (auto_prepend_file).
            if (in_array(strtolower($base), ['.user.ini', 'php.ini'], true) && !str_starts_with($rel, 'vendor/')) {
                $text = (string) @file_get_contents($abs);
                $config[] = ['file' => $rel, 'path' => $rel, 'note' => preg_match('/auto_(pre|ap)pend_file/i', $text) ? 'มี auto_prepend/append_file' : 'ตั้งค่า PHP เฉพาะโฟลเดอร์'];
            }
            if ($base === '.htaccess' && !in_array($rel, self::KNOWN_HTACCESS, true) && !str_starts_with($rel, 'vendor/')) {
                $htaccess[] = ['file' => $rel, 'path' => $rel, 'note' => 'แก้ไขล่าสุด ' . date('Y-m-d H:i', (int) filemtime($abs))];
            }
        }
        $missingDeny = [];
        foreach (self::DENY_HTACCESS as $dir) {
            $text = (string) @file_get_contents($this->root . '/' . $dir . '/.htaccess');
            if (is_dir($this->root . '/' . $dir) && stripos($text, 'Require all denied') === false) {
                $missingDeny[] = ['file' => $dir . '/.htaccess', 'note' => 'ไม่มี "Require all denied"'];
            }
        }
        $rootHt = (string) @file_get_contents($this->root . '/.htaccess');
        if (!str_contains($rootHt, '(\.git|')) {
            $missingDeny[] = ['file' => '.htaccess', 'note' => 'ไม่มีกฎปิด .git / config / storage / src'];
        }
        $pubHt = (string) @file_get_contents($this->root . '/public/.htaccess');
        if (!str_contains($pubHt, '(?!(index|install)')) {
            $missingDeny[] = ['file' => 'public/.htaccess', 'note' => 'ไม่มีกฎปฏิเสธไฟล์ PHP อื่นนอกจาก index.php / install.php'];
        }

        return ['key' => 'files', 'title' => 'ไฟล์ที่ไม่ควรมีในระบบ', 'checks' => [
            self::check($stray ? 'bad' : 'ok', 'ไฟล์ PHP นอกตำแหน่งของระบบ',
                $stray ? 'ระบบมีไฟล์ PHP เฉพาะใน src/ migrations/ vendor/ bin/ config/ และ public/index.php, public/install.php — ไฟล์เหล่านี้ไม่ใช่ของระบบ ตรวจแล้วลบออก' : 'ไม่พบไฟล์ PHP ใน storage/ public/assets/ หรือที่อื่นนอกตำแหน่งของระบบ',
                $stray),
            self::check($config ? 'bad' : 'ok', 'ไฟล์ .user.ini / php.ini ที่วางเพิ่ม',
                $config ? 'ไฟล์เหล่านี้สั่ง PHP ให้โหลดไฟล์อื่นก่อนทุกหน้าได้ ระบบไม่ได้ใช้ ตรวจแล้วลบออก' : 'ไม่พบ', $config),
            self::check($htaccess ? 'warn' : 'ok', '.htaccess ที่ไม่ใช่ของระบบ',
                $htaccess ? '.htaccess สั่งให้ไฟล์นามสกุลอื่น (เช่น .jpg) ทำงานเป็น PHP ได้ ตรวจเนื้อหาว่าใครสร้าง' : 'มีเฉพาะ .htaccess ของระบบ', $htaccess),
            self::check($missingDeny ? 'bad' : 'ok', 'กฎปิดโฟลเดอร์ภายในจากเว็บ (.htaccess)',
                $missingDeny ? 'ไฟล์ .htaccess ถูกลบหรือแก้ — ดึงโค้ดล่าสุดจาก Git มาวางใหม่' : 'config/ storage/ src/ migrations/ bin/ project/ .git ปิดจากเว็บ และ public/ รันได้เฉพาะ index.php กับ install.php',
                $missingDeny),
        ]];
    }

    // ---------------------------------------------------------------- 3. integrity

    private static function baselinePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/security-baseline.json';
    }

    /** Files whose content matters: code, pages, server rules (not data, logs or uploads). */
    private function fingerprints(array $files): array
    {
        $out = [];
        foreach ($files as $rel => $abs) {
            if (preg_match('#^storage/(uploads|logs|cache|sessions|backups)/#', $rel) || $rel === 'storage/security-baseline.json' || $rel === 'storage/repair-unlock.txt') {
                continue;
            }
            if (preg_match('/(\.(php\d?|phtml|phar|pht|inc|js|mjs|html?|css)|\/?\.htaccess|\.user\.ini)$/i', $rel) !== 1) {
                continue;
            }
            $out[$rel] = (string) @hash_file('sha256', $abs);
        }
        return $out;
    }

    public function saveBaseline(int $userId): array
    {
        $prints = $this->fingerprints($this->files());
        $data = ['saved_at' => date('Y-m-d H:i:s'), 'saved_by' => $userId, 'files' => $prints];
        if (@file_put_contents(self::baselinePath(), json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new \RuntimeException('เขียนไฟล์ storage/security-baseline.json ไม่ได้');
        }
        return ['saved_at' => $data['saved_at'], 'file_count' => count($prints)];
    }

    private function integrity(array $files): array
    {
        $now = $this->fingerprints($files);
        // Recently changed code, useful even before a baseline exists.
        $recent = [];
        foreach ($now as $rel => $_) {
            $t = (int) filemtime($files[$rel]);
            if ($t > time() - 3 * 86400 && preg_match(self::PHP_EXT, $rel) && !str_starts_with($rel, 'vendor/')) {
                $recent[] = ['file' => $rel, 'path' => $rel, 'note' => 'แก้ไข ' . date('Y-m-d H:i', $t), 't' => $t];
            }
        }
        usort($recent, fn ($a, $b) => $b['t'] <=> $a['t']);
        $recent = array_map(fn ($r) => ['file' => $r['file'], 'path' => $r['path'], 'note' => $r['note']], array_slice($recent, 0, 50));
        $recentCheck = self::check('info', 'ไฟล์ PHP ที่แก้ไขใน 3 วันล่าสุด', $recent ? 'ถ้าช่วงนี้ไม่ได้อัปเดตระบบ ไฟล์ที่ถูกแก้คือสิ่งที่ต้องตรวจ' : 'ไม่มี', $recent);

        $raw = @file_get_contents(self::baselinePath());
        $base = $raw === false ? null : json_decode($raw, true);
        if (!is_array($base) || !is_array($base['files'] ?? null)) {
            return ['key' => 'integrity', 'title' => 'ความครบถ้วนของไฟล์ระบบ', 'baseline' => null, 'checks' => [
                self::check('warn', 'ยังไม่มีฐานเทียบไฟล์', 'กด "บันทึกฐานเทียบไฟล์" ตอนที่มั่นใจว่าระบบสะอาด (เช่น หลังอัปเดตจาก Git) — การสแกนครั้งต่อไปจะบอกได้ว่าไฟล์ใดถูกแก้ เพิ่ม หรือลบ'),
                $recentCheck,
            ]];
        }
        $old = $base['files'];
        $changed = $added = $removed = [];
        foreach ($now as $rel => $hash) {
            if (!isset($old[$rel])) {
                $added[] = ['file' => $rel, 'path' => $rel, 'note' => 'เพิ่ม ' . date('Y-m-d H:i', (int) filemtime($files[$rel]))];
            } elseif ($old[$rel] !== $hash) {
                $changed[] = ['file' => $rel, 'path' => $rel, 'note' => 'แก้ไข ' . date('Y-m-d H:i', (int) filemtime($files[$rel]))];
            }
        }
        foreach ($old as $rel => $_) {
            if (!isset($now[$rel])) {
                $removed[] = ['file' => $rel, 'note' => 'หายไป'];
            }
        }
        $phpNew = array_filter($added, fn ($a) => preg_match(self::PHP_EXT, $a['file']) === 1);
        $diff = count($changed) + count($added) + count($removed);
        return ['key' => 'integrity', 'title' => 'ความครบถ้วนของไฟล์ระบบ', 'baseline' => ['saved_at' => $base['saved_at'] ?? null, 'file_count' => count($old)], 'checks' => [
            self::check(
                $phpNew ? 'bad' : ($diff ? 'warn' : 'ok'),
                'เทียบกับฐานเทียบ (' . ($base['saved_at'] ?? '-') . ')',
                $diff
                    ? "แก้ไข " . count($changed) . " · เพิ่ม " . count($added) . " · หายไป " . count($removed) . " ไฟล์ — ถ้าเป็นการอัปเดตระบบตามปกติ ตรวจแล้วกดบันทึกฐานเทียบใหม่ ถ้าไม่ได้อัปเดต ไฟล์เหล่านี้ถูกแก้โดยผู้อื่น"
                    : 'ไฟล์ทั้ง ' . count($now) . ' ไฟล์ตรงกับฐานเทียบ',
                array_slice(array_merge($added, $changed, $removed), 0, 200)
            ),
            $recentCheck,
        ]];
    }

    // ---------------------------------------------------------------- 4. PHP & server settings

    private function phpSettings(array $server): array
    {
        $on = fn (string $k) => in_array(strtolower((string) ini_get($k)), ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true);
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        $dangerous = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];
        $open = array_values(array_diff($dangerous, $disabled));
        $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || str_contains((string) ($server['HTTP_CF_VISITOR'] ?? ''), 'https');
        $ver = PHP_VERSION;

        $checks = [
            self::check($open ? 'warn' : 'ok', 'ปิดฟังก์ชันรันคำสั่งระบบ (disable_functions)',
                $open
                    ? 'ยังเปิดอยู่: ' . implode(', ', $open) . ' — ระบบนี้ไม่ได้ใช้ ถ้าปิดใน php.ini (disable_functions = ' . implode(',', $dangerous) . ') web shell ที่หลุดเข้ามาจะรันคำสั่งระบบไม่ได้'
                    : 'ปิดครบแล้ว web shell จะรันคำสั่งระบบไม่ได้'),
            self::check($on('allow_url_include') ? 'bad' : 'ok', 'allow_url_include', $on('allow_url_include') ? 'เปิดอยู่ — include ไฟล์จาก URL ภายนอกได้ ควรปิด' : 'ปิดอยู่'),
            self::check($on('display_errors') ? 'warn' : 'ok', 'display_errors', $on('display_errors') ? 'เปิดอยู่ — ข้อความผิดพลาดแสดงตำแหน่งไฟล์และโครงสร้างระบบให้ผู้ใช้เห็น ควรปิดบนเครื่องจริง' : 'ปิดอยู่'),
            self::check($on('expose_php') ? 'info' : 'ok', 'expose_php', $on('expose_php') ? 'เปิดอยู่ — บอกเวอร์ชัน PHP ใน header (X-Powered-By) ควรปิด' : 'ปิดอยู่'),
            self::check(
                version_compare($ver, '8.2.0', '<') ? 'bad' : (version_compare($ver, '8.3.0', '<') ? 'warn' : 'ok'),
                'เวอร์ชัน PHP ' . $ver,
                version_compare($ver, '8.2.0', '<') ? 'หมดระยะแก้ช่องโหว่แล้ว ควรอัปเดต' : (version_compare($ver, '8.3.0', '<') ? 'ใกล้หมดระยะแก้ช่องโหว่ วางแผนอัปเดต' : 'ยังได้รับการแก้ช่องโหว่')
            ),
            self::check(ini_get('open_basedir') ? 'ok' : 'info', 'open_basedir', ini_get('open_basedir') ? (string) ini_get('open_basedir') : 'ไม่ได้จำกัด — ถ้าตั้งเป็นโฟลเดอร์ของระบบ web shell จะอ่านไฟล์นอกโฟลเดอร์ไม่ได้'),
            self::check($https ? 'ok' : 'warn', 'HTTPS', $https ? 'เชื่อมต่อแบบเข้ารหัส' : 'การเชื่อมต่อนี้ไม่ใช่ HTTPS — รหัสผ่านและ session ถูกดักได้ในเครือข่าย'),
        ];
        $cfg = $this->root . '/config/config.php';
        if (is_file($cfg) && DIRECTORY_SEPARATOR === '/') {
            $perm = fileperms($cfg) & 0777;
            $checks[] = self::check(($perm & 0004) ? 'warn' : 'ok', 'สิทธิ์ไฟล์ config/config.php (' . decoct($perm) . ')',
                ($perm & 0004) ? 'ผู้ใช้อื่นบนเครื่องอ่านรหัสผ่านฐานข้อมูลได้ ตั้งเป็น 640 หรือ 600' : 'ผู้ใช้อื่นบนเครื่องอ่านไม่ได้');
        }
        return ['key' => 'php', 'title' => 'การตั้งค่า PHP และเว็บเซิร์ฟเวอร์', 'checks' => $checks];
    }

    // ---------------------------------------------------------------- 5. exposure over HTTP

    private function exposure(array $server): array
    {
        if (!function_exists('curl_multi_init')) {
            return ['key' => 'web', 'title' => 'ทดสอบเข้าถึงจากเว็บ', 'checks' => [self::check('info', 'ทดสอบไม่ได้', 'ไม่มี PHP curl')]];
        }
        $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($server['HTTP_HOST'] ?? 'localhost'));
        // SCRIPT_NAME is <project>/public/index.php: the project root is two levels up.
        $base = ($https ? 'https' : 'http') . '://' . $host . rtrim(str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/public/index.php'), 2)), '/');
        $probes = [
            '.git/config' => 'ประวัติโค้ด Git (ดาวน์โหลดโค้ดทั้งระบบได้)',
            'config/config.php' => 'ไฟล์ตั้งค่า (รหัสผ่านฐานข้อมูล)',
            'config/config.sample.php' => 'ตัวอย่างไฟล์ตั้งค่า',
            'storage/logs/' => 'log ของระบบ',
            'storage/uploads/' => 'ไฟล์ที่อัปโหลด',
            'storage/security-baseline.json' => 'ฐานเทียบไฟล์',
            'src/Support/Config.php' => 'ซอร์สโค้ด',
            'migrations/' => 'ไฟล์ migration',
            'vendor/composer/installed.json' => 'รายชื่อไลบรารีและเวอร์ชัน',
            'composer.lock' => 'รายชื่อไลบรารีและเวอร์ชัน',
            'public/.htaccess' => 'กฎของเว็บเซิร์ฟเวอร์',
            'public/install.php?step=admin&repair=1' => 'ตัวติดตั้ง: ตั้งรหัสผ่านผู้ดูแลระบบ',
        ];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($probes as $path => $_) {
            $ch = curl_init($base . '/' . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'rvc-coac-security-check',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$path] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $items = [];
        $open = 0;
        $failed = 0;
        foreach ($handles as $path => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = (string) curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($code === 0) {
                $failed++;
                $items[] = ['file' => $path, 'note' => 'เชื่อมต่อไม่ได้', 'level' => 'info'];
                continue;
            }
            $exposed = $code >= 200 && $code < 300;
            if ($path === 'public/install.php?step=admin&repair=1') {
                $exposed = $exposed && str_contains($body, 'name="password"');
            } elseif ($exposed && str_ends_with($path, '/') && !preg_match('/Index of|<a href/i', $body)) {
                $exposed = strlen($body) > 0 && !str_contains($body, '<div id="root"');
            }
            if ($exposed) {
                $open++;
            }
            $items[] = ['file' => $path, 'note' => "HTTP {$code} · " . ($exposed ? 'เปิดได้ ⚠ ' : 'ปิดแล้ว · ') . $probes[$path], 'level' => $exposed ? 'bad' : 'ok'];
        }
        curl_multi_close($mh);

        $check = self::check(
            $open ? 'bad' : ($failed === count($probes) ? 'info' : 'ok'),
            'ไฟล์และโฟลเดอร์ภายในที่เปิดจากเว็บได้',
            $open
                ? "เปิดได้ {$open} รายการ — ตรวจว่าเว็บเซิร์ฟเวอร์อ่าน .htaccess (AllowOverride All และเปิด mod_rewrite) หรือตั้ง DocumentRoot ให้ชี้ที่โฟลเดอร์ public/"
                : ($failed === count($probes) ? "เรียก {$base} จากเซิร์ฟเวอร์ไม่ได้ ทดสอบเองจากเบราว์เซอร์ภายนอก" : "ทดสอบจาก {$base} — ปิดทั้งหมด"),
            $items
        );
        return ['key' => 'web', 'title' => 'ทดสอบเข้าถึงจากเว็บ', 'checks' => [$check]];
    }

    // ---------------------------------------------------------------- 6. installer

    private function repairState(): array
    {
        $until = RepairUnlock::until();
        $installer = $this->root . '/public/install.php';
        $guarded = str_contains((string) @file_get_contents($installer), 'RepairUnlock::active()');
        return ['key' => 'repair', 'title' => 'ตัวติดตั้ง (install.php)', 'repair_until' => $until ? date('Y-m-d H:i:s', $until) : null, 'checks' => [
            self::check($guarded ? 'ok' : 'bad', 'ล็อกโหมดซ่อมแซมหลังติดตั้ง',
                $guarded ? 'ตั้งรหัสผ่านผู้ดูแลระบบหรือแก้การเชื่อมต่อฐานข้อมูลผ่าน install.php ได้เฉพาะตอนเปิดโหมดซ่อมแซม' : 'install.php ไม่มีการล็อก — ใครก็ตั้งรหัสผ่านผู้ดูแลระบบใหม่ได้ อัปเดตไฟล์จาก Git'),
            self::check($until ? 'warn' : 'ok', 'โหมดซ่อมแซม',
                $until ? 'เปิดอยู่ถึง ' . date('Y-m-d H:i', $until) . ' — ระหว่างนี้ใครเปิด install.php ก็ตั้งรหัสผ่านผู้ดูแลระบบได้ ปิดทันทีเมื่อซ่อมเสร็จ' : 'ปิดอยู่'),
        ]];
    }

    // ---------------------------------------------------------------- 7. accounts

    private function accounts(): array
    {
        $cols = 'id, username, full_name, created_at' . (Db::hasColumn($this->db, 'users', 'last_login_at') ? ', last_login_at' : '');
        $admins = $this->db->query("SELECT {$cols} FROM users WHERE role = 'admin' AND active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map(fn ($a) => [
            'file' => $a['username'] . ' — ' . $a['full_name'],
            'note' => 'สร้าง ' . substr((string) $a['created_at'], 0, 16) . (array_key_exists('last_login_at', $a) ? ' · เข้าระบบล่าสุด ' . ($a['last_login_at'] ? substr((string) $a['last_login_at'], 0, 16) : '-') : ''),
        ], $admins);
        $recentAdmins = array_filter($admins, fn ($a) => strtotime((string) $a['created_at']) > time() - 7 * 86400);

        $changes = $this->db->query(
            "SELECT a.created_at, a.table_name, a.action, a.record_id, a.ip_address, a.new_data, u.username
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.table_name IN ('users', 'role_permissions', 'security') AND a.created_at >= NOW() - INTERVAL 14 DAY
             ORDER BY a.id DESC LIMIT 30"
        )->fetchAll(PDO::FETCH_ASSOC);
        $changeItems = array_map(fn ($c) => [
            'file' => substr((string) $c['created_at'], 0, 16) . ' · ' . $c['table_name'] . ' #' . $c['record_id'] . ' '
                // Events outside create/update/delete/cancel are logged as an update naming the event (AuditLogger).
                . ((json_decode((string) $c['new_data'], true) ?: [])['event'] ?? $c['action']),
            'note' => 'โดย ' . ($c['username'] ?? '-') . ($c['ip_address'] ? ' · IP ' . $c['ip_address'] : ''),
        ], $changes);

        return ['key' => 'accounts', 'title' => 'บัญชีผู้ดูแลระบบ', 'checks' => [
            self::check($recentAdmins ? 'warn' : 'info', 'ผู้ดูแลระบบที่ใช้งานอยู่ ' . count($admins) . ' บัญชี',
                $recentAdmins ? 'มีบัญชีผู้ดูแลระบบสร้างใน 7 วันล่าสุด — ตรวจว่าเป็นบัญชีที่ตั้งใจสร้าง' : 'ตรวจว่าทุกบัญชีเป็นของผู้ที่ควรมีสิทธิ์ ปิดบัญชีที่ไม่ใช้แล้ว', $items),
            self::check('info', 'การแก้ไขผู้ใช้ / สิทธิ์ / ความปลอดภัย 14 วันล่าสุด', $changeItems ? '' : 'ไม่มี', $changeItems),
        ]];
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B');
    }
}
