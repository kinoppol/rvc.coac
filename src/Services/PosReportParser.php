<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reads a sales-by-item report exported from an external POS: a Markdown
 * table, or rows pasted from a spreadsheet (tab or comma separated). Columns
 * are found by their Thai/English header, so the order does not matter.
 */
class PosReportParser
{
    public const MAX_LINES = 500;

    /** Checked in this order: "ต้นทุนสินค้า" is a cost column, not a name column. */
    private const COLUMNS = [
        'profit' => '/กำไร|profit|margin/iu',
        'cost' => '/ต้นทุน|cost/iu',
        'qty' => '/จำนวน|qty|quantity|ชิ้น/iu',
        'net' => '/ยอดขาย|สุทธิ|รายได้|net|sales|amount|total/iu',
        'category' => '/หมวด|ประเภท|กลุ่ม|category|group/iu',
        'name' => '/รายการ|สินค้า|ชื่อ|item|product|name/iu',
    ];

    private const THAI_MONTHS = [
        'มกราคม' => 1, 'กุมภาพันธ์' => 2, 'มีนาคม' => 3, 'เมษายน' => 4, 'พฤษภาคม' => 5, 'มิถุนายน' => 6,
        'กรกฎาคม' => 7, 'สิงหาคม' => 8, 'กันยายน' => 9, 'ตุลาคม' => 10, 'พฤศจิกายน' => 11, 'ธันวาคม' => 12,
        'ม.ค.' => 1, 'ก.พ.' => 2, 'มี.ค.' => 3, 'เม.ย.' => 4, 'พ.ค.' => 5, 'มิ.ย.' => 6,
        'ก.ค.' => 7, 'ส.ค.' => 8, 'ก.ย.' => 9, 'ต.ค.' => 10, 'พ.ย.' => 11, 'ธ.ค.' => 12,
    ];

    /**
     * @return array{date:?string, lines:array<int,array{pos_name:string,pos_category:?string,qty:?int,net:float,cost:?float}>, warnings:string[]}
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $rows = [];
        foreach (explode("\n", $text) as $raw) {
            $row = self::cells($raw);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $map = null;
        $delimiter = null;
        $lines = [];
        $warnings = [];
        foreach ($rows as [$delim, $cells]) {
            if (self::isSeparator($cells)) {
                continue;
            }
            $header = self::headerMap($cells);
            if ($header !== null) {
                $map = $header;
                $delimiter = $delim;
                continue;
            }
            // Only rows of the same table as the header (not bullet text with commas around it).
            if ($map === null || $delim !== $delimiter) {
                continue;
            }
            $name = trim(strip_tags(str_replace(['**', '__'], '', $cells[$map['name']] ?? '')));
            if ($name === '' || preg_match('/^(รวม|ยอดรวม|total|grand total)/iu', $name)) {
                continue;
            }
            $net = self::number($cells[$map['net']] ?? null);
            $qty = isset($map['qty']) ? self::number($cells[$map['qty']] ?? null) : null;
            $cost = isset($map['cost']) ? self::number($cells[$map['cost']] ?? null) : null;
            $category = isset($map['category']) ? trim($cells[$map['category']] ?? '') : '';
            if ($net === null && $qty === null) {
                continue;
            }
            if ($qty !== null && abs($qty - round($qty)) > 0.001) {
                $warnings[] = "«{$name}» จำนวน {$qty} ไม่ใช่จำนวนเต็ม ปัดเป็น " . (int) round($qty);
            }
            $lines[] = [
                'pos_name' => mb_substr($name, 0, 191),
                'pos_category' => $category !== '' && $category !== '-' ? mb_substr($category, 0, 191) : null,
                'qty' => $qty !== null ? (int) round($qty) : null,
                'net' => round((float) $net, 2),
                'cost' => $cost !== null ? round($cost, 2) : null,
            ];
        }

        // The same item printed twice (e.g. split by price) is one line here.
        $merged = [];
        foreach ($lines as $l) {
            $key = PosMatcher::key($l['pos_name']);
            if (!isset($merged[$key])) {
                $merged[$key] = $l;
                continue;
            }
            $m = &$merged[$key];
            $m['qty'] = $m['qty'] === null && $l['qty'] === null ? null : (int) $m['qty'] + (int) $l['qty'];
            $m['net'] = round($m['net'] + $l['net'], 2);
            $m['cost'] = $m['cost'] === null && $l['cost'] === null ? null : round((float) $m['cost'] + (float) $l['cost'], 2);
            unset($m);
        }
        $lines = array_values($merged);
        if (count($lines) > self::MAX_LINES) {
            throw new \RuntimeException('รายงานมีรายการเกิน ' . self::MAX_LINES . ' รายการ กรุณาแบ่งนำเข้าทีละส่วน');
        }

        return ['date' => self::findDate($text), 'lines' => $lines, 'warnings' => $warnings];
    }

    /** Splits a table row into [delimiter, cells], or null when the line is not a row. */
    private static function cells(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        if (str_contains($line, '|')) {
            [$delim, $cells] = ['|', explode('|', trim($line, '|'))];
        } elseif (str_contains($line, "\t")) {
            [$delim, $cells] = ["\t", explode("\t", $line)];
        } else {
            [$delim, $cells] = [',', str_getcsv($line, ',', '"', '')];
        }
        $cells = array_map(fn ($c) => trim((string) $c), $cells);
        return count($cells) >= 2 ? [$delim, $cells] : null;
    }

    private static function isSeparator(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== '' && !preg_match('/^:?-{2,}:?$/', $c)) {
                return false;
            }
        }
        return true;
    }

    /** Column index per field when the row is a header (has a name and a sales or qty column). */
    private static function headerMap(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            if (preg_match('/\d{2,}/', $cell) && !preg_match('/\(.*\)/u', $cell)) {
                return null; // data rows carry numbers; headers don't (except "(บาท)")
            }
            foreach (self::COLUMNS as $field => $pattern) {
                if (preg_match($pattern, $cell)) {
                    $map[$field] ??= $i;
                    break;
                }
            }
        }
        if (!isset($map['name']) || (!isset($map['net']) && !isset($map['qty']))) {
            return null;
        }
        // A report with only qty: there is no money column to import.
        if (!isset($map['net'])) {
            return null;
        }
        return $map;
    }

    private static function number(?string $s): ?float
    {
        $s = trim(str_replace([',', '฿', 'บาท', ' ', '*'], '', (string) $s));
        if ($s === '' || $s === '-' || !is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    /** First date in the report text (Thai month names, Buddhist-era years allowed), as Y-m-d. */
    public static function findDate(string $text): ?string
    {
        $months = implode('|', array_map(fn ($m) => preg_quote($m, '/'), array_keys(self::THAI_MONTHS)));
        if (preg_match('/(\d{1,2})\s*(' . $months . ')\s*(\d{4})/u', $text, $m)) {
            return self::ymd((int) $m[3], self::THAI_MONTHS[$m[2]], (int) $m[1]);
        }
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $text, $m)) {
            return self::ymd((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return null;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        if ($y > 2400) {
            $y -= 543;
        }
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
}
