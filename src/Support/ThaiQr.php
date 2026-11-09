<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What the POS's Thai QR Payment is made from: a PromptPay number (phone, national / tax ID,
 * e-Wallet ID) or the text of the store's own merchant QR-Code (EMVCo, tag 29 or 30). Generating
 * the code with the amount happens where it is shown: public/assets/js/thai-qr.js and the app's
 * domain/PromptPay.kt, so it works offline in the app.
 */
final class ThaiQr
{
    /** The target in its stored form (digits only, or the QR's text), or null if it cannot be paid to. */
    public static function normalize(string $input): ?string
    {
        $input = trim($input);
        if (str_starts_with($input, '000201')) {
            return self::validPayload($input) ? $input : null;
        }
        $digits = preg_replace('/[\s\-]/', '', $input);
        if (preg_match('/^0\d{9}$/', $digits) || preg_match('/^\d{13}$/', $digits) || preg_match('/^\d{15}$/', $digits)) {
            return $digits;
        }
        return null;
    }

    /** A merchant QR's store code (tag 30's reference 1), printed under the code like on the store's sticker. */
    public static function merchantCode(string $target): ?string
    {
        if (!str_starts_with($target, '000201')) {
            return null;
        }
        $bill = self::fields($target)['30'] ?? null;
        return $bill === null ? null : ((self::fields($bill) ?? [])['02'] ?? null);
    }

    public static function crc16(string $text): string
    {
        $crc = 0xFFFF;
        foreach (str_split($text) as $ch) {
            $crc ^= ord($ch) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return sprintf('%04X', $crc);
    }

    private static function validPayload(string $text): bool
    {
        if (!preg_match('/^[\x20-\x7E]+$/', $text) || strlen($text) > 512) {
            return false;
        }
        $f = self::fields($text);
        return $f !== null
            && isset($f['01'], $f['63'])
            && ($f['53'] ?? '') === '764'
            && (isset($f['29']) || isset($f['30']))
            && substr($text, -8, 4) === '6304'
            && strtoupper(substr($text, -4)) === self::crc16(substr($text, 0, -4));
    }

    /** @return array<string,string>|null tag => value; null when the text is not well-formed */
    private static function fields(string $text): ?array
    {
        $out = [];
        for ($i = 0, $n = strlen($text); $i < $n;) {
            $len = substr($text, $i + 2, 2);
            if (!ctype_digit($len) || $i + 4 + (int) $len > $n) {
                return null;
            }
            $out[substr($text, $i, 2)] = substr($text, $i + 4, (int) $len);
            $i += 4 + (int) $len;
        }
        return $out;
    }
}
