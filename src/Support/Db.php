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
            self::applyTimezone(self::$pdo);
        }
        return self::$pdo;
    }

    /**
     * Uses the admin's time zone setting (else app.timezone from config) for PHP
     * and for this MySQL session, so NOW()/CURDATE() agree with date().
     */
    private static function applyTimezone(PDO $pdo): void
    {
        $tz = '';
        try {
            $tz = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'timezone'")->fetchColumn();
        } catch (PDOException) {
            // settings table not created yet (fresh install)
        }
        if (!self::isTimezone($tz)) {
            $tz = (string) Config::get('app.timezone', 'Asia/Bangkok');
        }
        if (!self::isTimezone($tz)) {
            $tz = 'Asia/Bangkok';
        }
        date_default_timezone_set($tz);
        $pdo->exec("SET time_zone = '" . (new \DateTimeImmutable())->format('P') . "'");
    }

    public static function isTimezone(string $tz): bool
    {
        return $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true);
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

    /** @var array<string,bool> */
    private static array $columns = [];

    /**
     * Whether a column exists yet: lets a query that reads a column added by a newer
     * migration keep working until an admin has run that migration.
     */
    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (!isset(self::$columns[$key])) {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            self::$columns[$key] = $stmt->fetchColumn() !== false;
        }
        return self::$columns[$key];
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$columns = [];
    }
}
