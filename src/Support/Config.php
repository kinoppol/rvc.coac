<?php

declare(strict_types=1);

namespace App\Support;

class Config
{
    private static ?array $data = null;

    public static function isInstalled(): bool
    {
        return is_file(self::configPath()) && is_file(self::lockPath());
    }

    public static function configPath(): string
    {
        return dirname(__DIR__, 2) . '/config/config.php';
    }

    public static function lockPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/installed.lock';
    }

    public static function all(): array
    {
        if (self::$data === null) {
            if (!is_file(self::configPath())) {
                throw new \RuntimeException('ยังไม่ได้ติดตั้งระบบ กรุณาเปิด install.php ก่อน');
            }
            self::$data = require self::configPath();
        }
        return self::$data;
    }

    public static function get(string $dotKey, mixed $default = null): mixed
    {
        $parts = explode('.', $dotKey);
        $val = self::all();
        foreach ($parts as $p) {
            if (!is_array($val) || !array_key_exists($p, $val)) {
                return $default;
            }
            $val = $val[$p];
        }
        return $val;
    }

    public static function write(array $data): void
    {
        $dir = dirname(self::configPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $php = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        file_put_contents(self::configPath(), $php);
        self::$data = $data;
    }
}
