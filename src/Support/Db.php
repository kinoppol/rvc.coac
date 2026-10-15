<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;

class Db
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $cfg = Config::get('db');
            self::$pdo = self::connect($cfg);
        }
        return self::$pdo;
    }

    /**
     * Build a fresh PDO connection from raw params (used by the installer
     * before config/config.php exists, and for the "test connection" step).
     */
    public static function connect(array $cfg, bool $selectDatabase = true): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=%s',
            $cfg['host'],
            $cfg['port'] ?? '3306',
            $cfg['charset'] ?? 'utf8mb4'
        );
        if ($selectDatabase && !empty($cfg['database'])) {
            $dsn .= ';dbname=' . $cfg['database'];
        }

        return new PDO($dsn, $cfg['username'], $cfg['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
