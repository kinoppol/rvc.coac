<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Symmetric encryption for secrets stored in the database (e.g. AI provider
 * API keys), keyed off config app.key so a DB dump alone doesn't leak them.
 */
class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $appKey = (string) Config::get('app.key', '');
        if ($appKey === '') {
            throw new \RuntimeException('ยังไม่ได้ตั้งค่า app.key ใน config/config.php');
        }
        return hash('sha256', 'ai-secrets|' . $appKey, true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('เข้ารหัสข้อมูลไม่สำเร็จ');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $encoded): string
    {
        if ($encoded === null || $encoded === '') {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
