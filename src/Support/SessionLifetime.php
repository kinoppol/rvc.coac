<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How long a signed-in session lasts without use (Settings > ผู้ใช้งาน, admins only).
 *
 * PHP's default keeps a session file 24 minutes after it was last written, and most
 * requests only read the session (public/index.php), so people were signed out mid-shift.
 * Sessions are kept in storage/sessions with the admin's lifetime, and keepAlive()
 * renews the session file and cookie every few minutes while the user is active.
 * The hours are cached in a file because the session starts before the database is used.
 */
final class SessionLifetime
{
    public const DEFAULT_HOURS = 12;
    public const MIN_HOURS = 1;
    public const MAX_HOURS = 720; // 30 days
    private const RENEW_EVERY = 300; // seconds between renewals while active

    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/storage';
    }

    public static function hours(): int
    {
        $raw = @file_get_contents(self::root() . '/cache/session-hours.txt');
        $hours = $raw === false ? self::DEFAULT_HOURS : (int) trim($raw);
        return max(self::MIN_HOURS, min(self::MAX_HOURS, $hours ?: self::DEFAULT_HOURS));
    }

    public static function save(int $hours): void
    {
        $dir = self::root() . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/session-hours.txt', (string) $hours, LOCK_EX);
    }

    /** storage/sessions, created on first use and closed to web access. */
    public static function savePath(): string
    {
        $dir = self::root() . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    /**
     * Signs out a session idle longer than $ttl seconds; otherwise renews it every few
     * minutes (session file time + cookie expiry). Works whether the session is still open
     * (sign-in requests) or was read and closed.
     */
    public static function keepAlive(int $ttl): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }
        $now = time();
        $seen = (int) ($_SESSION['seen_at'] ?? 0);
        if ($seen > 0 && $now - $seen < self::RENEW_EVERY) {
            return;
        }
        $open = session_status() === PHP_SESSION_ACTIVE;
        if (!$open) {
            session_start();
        }
        if ($seen > 0 && $now - $seen > $ttl) {
            $_SESSION = []; // idle too long: the next check asks to sign in again
        } else {
            $_SESSION['seen_at'] = $now;
            $p = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires' => $now + $ttl, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        if (!$open) {
            session_write_close();
        }
    }
}
