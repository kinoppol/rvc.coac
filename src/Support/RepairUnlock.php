<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The installer (public/install.php) can reset the admin password and rewrite the database
 * settings, so once the system is installed it only works while repair is unlocked: by an
 * admin from the security page (for a short time), or by someone with access to the server
 * creating storage/repair-unlock.txt by hand. Without it, anyone could take the system over.
 */
final class RepairUnlock
{
    public const MINUTES = 30;

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/storage/repair-unlock.txt';
    }

    /** Unlocked: the file holds a time still to come, or was made by hand (no time, under a day old). */
    public static function active(): bool
    {
        $file = self::path();
        if (!is_file($file)) {
            return false;
        }
        $until = (int) trim((string) @file_get_contents($file));
        if ($until > 0) {
            return $until > time();
        }
        return filemtime($file) > time() - 86400;
    }

    /** @return int the time repair locks again */
    public static function open(int $minutes = self::MINUTES): int
    {
        $until = time() + $minutes * 60;
        if (@file_put_contents(self::path(), (string) $until, LOCK_EX) === false) {
            throw new \RuntimeException('เขียนไฟล์ storage/repair-unlock.txt ไม่ได้');
        }
        return $until;
    }

    public static function close(): void
    {
        if (is_file(self::path())) {
            @unlink(self::path());
        }
    }

    public static function until(): ?int
    {
        if (!self::active()) {
            return null;
        }
        $until = (int) trim((string) @file_get_contents(self::path()));
        return $until > 0 ? $until : filemtime(self::path()) + 86400;
    }
}
