<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Imports a receipt written as Markdown in small steps, so small or free
 * models never have to handle the whole receipt in one long request:
 * the item table is split here (no AI), the model converts a few rows at a
 * time, and the header/footer text is summarised in a separate short call.
 */
class ReceiptMarkdownImport
{
    public const CHUNK_SIZE = 8;

    private const ITEM_RULES = <<<TXT
    แปลงบรรทัดสินค้าจากใบเสร็จการซื้อสินค้าเข้าร้านด้านล่างเป็น JSON ทีละบรรทัด (บรรทัดละ 1 รายการ ห้ามข้าม ห้ามรวม)
    - "2 * 69.00", "2 x 69.00", "2 @ 69.00" = qty 2, unit_price 69.00
    - barcode = ตัวเลขบาร์โค้ด (เฉพาะตัวเลข ตัดวงเล็บและตัวอักษรท้ายออก) ถ้าไม่มีใช้รหัสสินค้าของร้าน
    - pack_size = จำนวนชิ้นย่อยใน 1 หน่วยที่ซื้อ ดูจากท้ายชื่อ เช่น "X6" = 6, "X12+1" = 12, "1X50" = 50 ถ้าไม่ใช่แพ็คให้ 1
    - product_name = ชื่อสำหรับขายแยกชิ้น (ตัดส่วนบอกจำนวนในแพ็คและสัญลักษณ์นำหน้าเช่น % ออก)
    TXT;

    private const ITEM_FORMAT = <<<TXT
    ตอบกลับเป็น JSON อย่างเดียว ห้ามมีข้อความอื่น:
    {"items":[{"row":1,"name":"ชื่อตามใบเสร็จ","product_name":"ชื่อขายแยกชิ้น","barcode":"","qty":1,"pack_size":1,"unit_price":0.00,"line_total":0.00}]}
    TXT;

    private const SUMMARY_FORMAT = <<<TXT
    ข้อความด้านล่างคือส่วนหัวและส่วนท้ายของใบเสร็จการซื้อสินค้าเข้าร้าน (ตัดตารางรายการสินค้าออกแล้ว)
    อ่านแล้วตอบกลับเป็น JSON อย่างเดียว ห้ามมีข้อความอื่น:
    {"supplier_name":"ชื่อร้านที่ขาย","supplier_tax_id":"","receipt_no":"","receipt_date":"YYYY-MM-DD","subtotal":0.00,"discount":0.00,"vat":0.00,"total":0.00}
    - discount = ส่วนลดท้ายบิลรวมทั้งหมด เป็นตัวเลขบวก
    - total = ยอดสุทธิที่ต้องชำระ, subtotal = ยอดรวมก่อนหักส่วนลด
    - ปี พ.ศ. ให้แปลงเป็น ค.ศ. (ลบ 543) วันที่ในรูป DD-MM-YYYY ให้แปลงเป็น YYYY-MM-DD
    TXT;

    public function __construct(private AiClient $client, private string $model)
    {
    }

    /**
     * Finds the item table (the Markdown table with the most rows).
     * @return array{columns:string, rows:string[], header:string, footer:string}
     */
    public static function split(string $md): array
    {
        $lines = preg_split('/\R/u', $md);
        $tables = [];
        for ($i = 0; $i < count($lines); $i++) {
            if (self::isRow($lines[$i]) && isset($lines[$i + 1]) && self::isSeparator($lines[$i + 1])) {
                $start = $i;
                $rows = [];
                for ($j = $i + 2; $j < count($lines) && self::isRow($lines[$j]); $j++) {
                    $rows[] = trim($lines[$j]);
                }
                $tables[] = ['start' => $start, 'end' => $j, 'columns' => trim($lines[$i]), 'rows' => $rows];
                $i = $j - 1;
            }
        }
        // A receipt summary often has other tables too (receipt details, totals)
        // that can be longer than the item table, so rank by how item-like they look.
        usort($tables, fn ($a, $b) => [self::itemScore($b), count($b['rows'])] <=> [self::itemScore($a), count($a['rows'])]);
        $t = $tables[0] ?? null;
        if (!$t || !$t['rows']) {
            return ['columns' => '', 'rows' => [], 'header' => $md, 'footer' => ''];
        }
        // Total/discount/VAT rows inside the table are bill totals, not items.
        $items = [];
        $totals = [];
        foreach ($t['rows'] as $r) {
            if (self::isTotalRow($r)) {
                $totals[] = $r;
            } else {
                $items[] = $r;
            }
        }
        $footer = trim(implode("\n", array_slice($lines, $t['end'])));
        if ($totals) {
            $footer = trim($t['columns'] . "\n" . implode("\n", $totals) . "\n\n" . $footer);
        }
        return [
            'columns' => $t['columns'],
            'rows' => $items,
            'header' => trim(implode("\n", array_slice($lines, 0, $t['start']))),
            'footer' => $footer,
        ];
    }

    /** A table row whose only text is a label like "รวม", "ส่วนลด", "VAT 7%", "Total". */
    private static function isTotalRow(string $row): bool
    {
        $labels = [];
        foreach (explode('|', trim(trim($row), '|')) as $cell) {
            $cell = trim(str_replace(['*', '_'], '', $cell));
            if (preg_match('/\p{L}/u', $cell)) {
                $labels[] = $cell;
            }
        }
        if (!$labels) {
            return false;
        }
        foreach ($labels as $l) {
            if (!preg_match('/^(รวม(ทั้งสิ้น|ทั้งหมด|เงิน(ทั้งสิ้น)?|สุทธิ|ยอด)?|ยอด(รวม|สุทธิ|ชำระ|เงิน)\S*|ส่วนลด\S*|ภาษี\S*|vat|(sub\s*|grand\s*)?total|net|discount|บาท|ถ้วย|ชิ้น|หน่วย)[^\p{L}]*$/iu', $l)) {
                return false;
            }
        }
        return true;
    }

    /** How much a table looks like a list of purchased items. */
    private static function itemScore(array $t): float
    {
        $cols = array_map('trim', explode('|', trim($t['columns'], " \t|")));
        $head = mb_strtolower($t['columns']);
        $score = 0.0;
        foreach ([
            '/รายการ|สินค้า|ชื่อ|item|description|product/u',
            '/จำนวน|qty|quantity/u',
            '/ราคา|หน่วย|price|unit/u',
            '/รวม|มูลค่า|จำนวนเงิน|amount|total/u',
        ] as $re) {
            if (preg_match($re, $head)) {
                $score += 2;
            }
        }
        // Two-column tables are "label | value" details, not item lines.
        if (count($cols) <= 2) {
            $score -= 4;
        }
        // Item rows carry several numbers (qty, price, line total).
        $numeric = 0;
        foreach ($t['rows'] as $row) {
            if (preg_match_all('/(?<![\d\/.-])\d[\d,]*(?:\.\d+)?(?![\d\/-])/', $row) >= 2) {
                $numeric++;
            }
        }
        return $score + ($t['rows'] ? 3 * $numeric / count($t['rows']) : 0);
    }

    private static function isRow(string $line): bool
    {
        return (bool) preg_match('/^\s*\|.*\|\s*$/u', $line) && !self::isSeparator($line);
    }

    private static function isSeparator(string $line): bool
    {
        return (bool) preg_match('/^\s*\|[\s:|-]+\|\s*$/', $line);
    }

    /**
     * Converts a few table rows with the model; the result has exactly one item per row.
     * @param string[] $rows
     */
    public function readItems(string $columns, array $rows): array
    {
        $numbered = '';
        foreach (array_values($rows) as $i => $row) {
            $numbered .= ($i + 1) . '. ' . $row . "\n";
        }
        $prompt = self::ITEM_RULES . "\n\nหัวตาราง: {$columns}\nบรรทัดสินค้า (" . count($rows) . " บรรทัด):\n{$numbered}\n" . self::ITEM_FORMAT;
        $data = ReceiptExtractor::parseJson($this->ask($prompt));
        $items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
        if (count($items) !== count($rows)) {
            throw new \RuntimeException(sprintf('AI ตอบมา %d รายการ จาก %d บรรทัด', count($items), count($rows)));
        }
        $out = ReceiptExtractor::forSale(ReceiptExtractor::normalize(['items' => $items]))['items'];
        if (count($out) !== count($rows)) {
            throw new \RuntimeException('AI ตอบรายการไม่ครบทุกบรรทัด');
        }
        return $out;
    }

    /** Last resort for one row the model could not read: read the cells directly. */
    public static function parseRow(string $row): array
    {
        $cells = array_map('trim', explode('|', trim(trim($row), '|')));
        $name = '';
        $barcode = '';
        $qty = 1;
        $unit = 0.0;
        $lineTotal = 0.0;
        foreach ($cells as $c) {
            if (preg_match('/^\(?(\d{8,14})\)?/', $c, $m)) {
                $barcode = $m[1];
            } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*[*xX×@]\s*([\d,]+(?:\.\d+)?)$/u', $c, $m)) {
                $qty = (int) round((float) $m[1]);
                $unit = (float) str_replace(',', '', $m[2]);
            } elseif (preg_match('/^-?[\d,]+\.\d{2}$/', $c)) {
                $lineTotal = (float) str_replace(',', '', $c);
            } elseif (preg_match('/\p{L}/u', $c) && mb_strlen($c) > mb_strlen($name)) {
                $name = $c;
            }
        }
        $item = ReceiptExtractor::normalize(['items' => [[
            'name' => $name !== '' ? $name : $row, 'barcode' => $barcode, 'qty' => $qty,
            'unit_price' => $unit, 'line_total' => $lineTotal,
        ]]])['items'][0];
        $item['product_name'] = ltrim($item['product_name'], '%# ');
        return $item;
    }

    /** Supplier, receipt no./date and bill totals from the text around the item table. */
    public function readSummary(string $header, string $footer): array
    {
        $text = mb_substr(trim($header . "\n\n" . $footer), 0, 12000);
        $data = ReceiptExtractor::parseJson($this->ask(self::SUMMARY_FORMAT . "\n\n" . $text));
        $draft = ReceiptExtractor::normalize($data + ['items' => []]);
        $num = fn ($v) => round((float) preg_replace('/[^\d.\-]/', '', (string) ($v ?? 0)), 2);
        return array_intersect_key($draft, array_flip(['supplier_name', 'supplier_tax_id', 'receipt_no', 'receipt_date', 'discount', 'vat', 'total']))
            + ['subtotal' => $num($data['subtotal'] ?? 0)];
    }

    private function ask(string $prompt): string
    {
        $reply = $this->client->chat($this->model, [['role' => 'user', 'content' => $prompt]], [], ['temperature' => 0]);
        $text = is_array($reply['content'] ?? null)
            ? implode('', array_map(fn ($p) => $p['text'] ?? '', $reply['content']))
            : (string) ($reply['content'] ?? '');
        // Reasoning models may wrap their answer in <think>…</think>.
        return preg_replace('~^.*</think>~su', '', $text) ?? $text;
    }
}
