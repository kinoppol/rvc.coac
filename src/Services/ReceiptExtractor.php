<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reads a Thai short-form tax receipt (ใบเสร็จอย่างย่อ) from one or more
 * photos with a vision model and returns structured purchase data.
 * Long receipts are often photographed in several overlapping pieces, so
 * the images are treated as consecutive parts of a single receipt.
 */
class ReceiptExtractor
{
    public const INSTRUCTIONS = <<<TXT
    รูปที่แนบมาคือ "ใบเสร็จรับเงิน/ใบกำกับภาษีอย่างย่อ" จากการซื้อสินค้าเข้าร้าน
    ถ้ามีหลายรูป ให้ถือว่าเป็นใบเสร็จใบเดียวกันที่ถ่ายต่อกันจากบนลงล่างตามลำดับรูป ขอบรูปที่ต่อกันมักถ่ายซ้อนทับกัน
    ให้ตัดรายการที่ซ้ำจากส่วนที่ซ้อนทับออก (รายการเดียวกัน ราคาเดียวกัน ที่อยู่ท้ายรูปก่อนและต้นรูปถัดไป) แต่ถ้าใบเสร็จพิมพ์สินค้าเดียวกันซ้ำหลายบรรทัดจริง ๆ ให้นับตามจริง
    อ่านทุกบรรทัดสินค้าให้ครบ: ชื่อสินค้า จำนวน ราคาต่อหน่วย ราคารวมของบรรทัด และบาร์โค้ดถ้ามีพิมพ์ไว้
    - รูปแบบ "2 @ 15.00" หรือ "2 x 15.00" หมายถึง จำนวน 2 ราคาหน่วยละ 15.00
    - บรรทัดส่วนลดที่อยู่ใต้สินค้า ให้หักออกจากราคารวมของสินค้านั้น
    - สินค้าที่ซื้อยกแพ็ค/ลัง/โหล แต่ร้านขายแยกชิ้น ให้ระบุ pack_size = จำนวนชิ้นย่อยใน 1 หน่วยที่ซื้อ
      สังเกตจากชื่อ เช่น "X12", "x 12", "*6", "×24", "แพ็ค 6", "6 ซอง" (ถ้าไม่ใช่แพ็คให้ pack_size = 1)
      qty และ unit_price ยังเป็นจำนวนแพ็คและราคาต่อแพ็คตามที่พิมพ์ในใบเสร็จ
      barcode ของสินค้าแพ็คคือบาร์โค้ดของแพ็ค ให้ใส่ตามที่พิมพ์ (ระบบเก็บไว้อ้างอิง)
      ถ้าใบเสร็จไม่มีบาร์โค้ด ให้ใช้ "รหัสสินค้า" ของร้านค้าแทน (ห้ามใช้ลำดับ จำนวน หรือรหัส ภ.พ./VAT)
    - product_name คือชื่อสินค้าสำหรับขายแยกชิ้น (ตัดส่วนบอกจำนวนในแพ็คออก เช่น "เมล็ดทานตะวัน 15 ก.X12" → "เมล็ดทานตะวัน 15 ก.")
    - ปี พ.ศ. ให้แปลงเป็น ค.ศ. (ลบ 543) วันที่ใช้รูปแบบ YYYY-MM-DD
    - ตัวเลขที่อ่านไม่ชัดให้ใส่ค่าที่น่าจะถูกที่สุด และบันทึกไว้ใน warnings
    - ตรวจผลรวมราคาทุกบรรทัดเทียบกับยอดสุทธิที่พิมพ์ในใบเสร็จ ถ้าไม่ตรงให้แจ้งใน warnings
    - unit_price/line_total ใส่ตามที่พิมพ์ (แม้ยังไม่รวม VAT) และใส่ยอด VAT ใน vat ระบบจะบวก VAT เข้าราคาทุนให้เอง
    TXT;

    private const FORMAT = <<<TXT
    ตอบกลับเป็น JSON อย่างเดียว ห้ามมีข้อความอื่น ตามโครงสร้างนี้:
    {"supplier_name":"ชื่อร้านที่ขาย","supplier_tax_id":"เลขประจำตัวผู้เสียภาษี หรือ null","receipt_no":"เลขที่ใบเสร็จ หรือ null","receipt_date":"YYYY-MM-DD หรือ null",
     "items":[{"name":"ชื่อสินค้าตามใบเสร็จ","product_name":"ชื่อสำหรับขายแยกชิ้น","barcode":"หรือ null","qty":1,"pack_size":1,"unit_price":0.00,"line_total":0.00}],
     "discount":0.00,"vat":0.00,"total":0.00,"warnings":["ข้อสังเกต"]}
    TXT;

    public function __construct(private AiClient $client, private string $model)
    {
    }

    /** @param string[] $dataUrls images in reading order */
    public function extract(array $dataUrls, string $hint = ''): array
    {
        $content = [[
            'type' => 'text',
            'text' => self::INSTRUCTIONS . "\nจำนวนรูป: " . count($dataUrls) . ($hint !== '' ? "\nหมายเหตุจากผู้ใช้: {$hint}" : '') . "\n\n" . self::FORMAT,
        ]];
        foreach ($dataUrls as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }
        $reply = $this->client->chat($this->model, [['role' => 'user', 'content' => $content]], [], ['temperature' => 0]);
        $text = is_array($reply['content'] ?? null)
            ? implode('', array_map(fn ($p) => $p['text'] ?? '', $reply['content']))
            : (string) ($reply['content'] ?? '');

        return self::forSale(self::normalize(self::parseJson($text)));
    }

    /** Lines read from a receipt are goods for sale; only the user marks a line as a business supply. */
    public static function forSale(array $draft): array
    {
        $draft['items'] = array_map(fn ($it) => ['item_type' => null] + array_diff_key($it, ['item_type' => 0]), $draft['items']);
        return $draft;
    }

    public static function parseJson(string $text): array
    {
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $text = $m[1];
        } elseif (($start = strpos($text, '{')) !== false && ($end = strrpos($text, '}')) !== false) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new \RuntimeException('AI อ่านใบเสร็จแล้วตอบกลับในรูปแบบที่ไม่ถูกต้อง ลองใหม่หรือเลือกโมเดลอื่น');
        }
        return $data;
    }

    /** Pack size written into a product name, e.g. "…15 ก.X12" → 12, "…56ก*6" → 6. */
    public const PACK_PATTERN = '/\s*(?|[xX×*]\s*(\d{1,3})|(\d{1,3})\s*(?:กล่อง|ขวด|ซอง|กระป๋อง|ชิ้น|ถุง|ห่อ))\s*$/u';

    /** @return array{0:string,1:int} [per-piece name, pack size] */
    public static function splitPack(string $name): array
    {
        if (preg_match(self::PACK_PATTERN, $name, $m) && (int) $m[1] > 1) {
            return [rtrim(preg_replace(self::PACK_PATTERN, '', $name), ' -/'), (int) $m[1]];
        }
        return [$name, 1];
    }

    /**
     * Receipts that print prices before VAT (lines + VAT = total): spreads the VAT
     * over every line (and the bill discount) so costs include it and the lines
     * add up to the printed total. Receipts whose lines already include VAT are
     * left alone. Returns the VAT added, or 0.
     */
    private static function addVat(array &$items, float &$discount, float &$vat, float $total): float
    {
        $sum = round(array_sum(array_column($items, 'line_total')), 2);
        $net = round($sum - $discount, 2);
        if ($total <= 0 || $net <= 0 || abs($net - $total) <= 0.009) {
            return 0;
        }
        $gap = round($total - $net, 2);
        // VAT printed and the gap is that VAT, or no VAT read but the gap is 7%.
        $isVat = $vat > 0 ? abs($gap - $vat) <= 0.05 : abs($gap - $net * 0.07) <= max(0.05, $net * 0.0005);
        if (!$isVat) {
            return 0;
        }
        $factor = $total / $net;
        $biggest = 0;
        foreach ($items as $i => &$it) {
            $it['line_total'] = round($it['line_total'] * $factor, 2);
            $it['unit_cost'] = round($it['line_total'] / $it['qty'], 2);
            if ($it['line_total'] > $items[$biggest]['line_total']) {
                $biggest = $i;
            }
        }
        unset($it);
        $discount = round($discount * $factor, 2);
        // Rounding leftovers go to the largest line so the lines match the printed total exactly.
        $diff = round($total - (array_sum(array_column($items, 'line_total')) - $discount), 2);
        if ($diff != 0.0) {
            $items[$biggest]['line_total'] = round($items[$biggest]['line_total'] + $diff, 2);
            $items[$biggest]['unit_cost'] = round($items[$biggest]['line_total'] / $items[$biggest]['qty'], 2);
        }
        $vat = $gap;
        return $gap;
    }

    /** Cleans types/values so the purchase form can use them directly. */
    public static function normalize(array $d): array
    {
        $num = fn ($v) => is_numeric($v) ? (float) $v : (float) preg_replace('/[^\d.\-]/', '', (string) $v);
        $str = fn ($v) => ($v === null || strtolower(trim((string) $v)) === 'null') ? '' : trim((string) $v);

        $items = [];
        foreach ((array) ($d['items'] ?? []) as $it) {
            if (!is_array($it) || $str($it['name'] ?? '') === '') {
                continue;
            }
            $qty = max(1, (int) round($num($it['qty'] ?? 1)));
            $lineTotal = round($num($it['line_total'] ?? 0), 2);
            $unit = round($num($it['unit_price'] ?? 0), 2);
            if ($unit <= 0 && $lineTotal > 0) {
                $unit = round($lineTotal / $qty, 2);
            }
            if ($lineTotal <= 0) {
                $lineTotal = round($unit * $qty, 2);
            }
            $name = $str($it['name']);
            [$guessName, $guessPack] = self::splitPack($name);
            $pack = (int) round($num($it['pack_size'] ?? 0));
            // A model often answers pack_size = 1 even when the name says "x 36"; trust the name then.
            $pack = $pack > 1 ? min($pack, 1000) : $guessPack;
            $productName = $str($it['product_name'] ?? '');
            if ($productName === '') {
                $productName = $pack > 1 ? $guessName : $name;
            }
            $type = $str($it['item_type'] ?? '');
            $item = [
                'name' => $name,
                'product_name' => $productName,
                'item_type' => in_array($type, ['stock', 'supply'], true) ? $type : null,
                // Under 4 digits is a line no./qty/VAT code, never a barcode or SKU.
                'barcode' => (fn ($b) => strlen($b) >= 4 ? $b : '')(preg_replace('/\D/', '', $str($it['barcode'] ?? ''))),
                'qty' => $qty,
                'pack_size' => $pack,
                'unit_cost' => $unit,
                'line_total' => $lineTotal,
            ];
            if (!empty($it['sale_price']) && $num($it['sale_price']) > 0) {
                $item['sale_price'] = round($num($it['sale_price']), 2);
            }
            $items[] = $item;
        }

        $date = $str($d['receipt_date'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && (int) $m[1] > 2400) {
            $date = ((int) $m[1] - 543) . "-{$m[2]}-{$m[3]}"; // model left it in พ.ศ.
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = '';
        }

        $warnings = array_values(array_filter(array_map($str, (array) ($d['warnings'] ?? []))));
        $sum = round(array_sum(array_column($items, 'line_total')), 2);
        $discount = round(abs($num($d['discount'] ?? 0)), 2);
        $total = round($num($d['total'] ?? 0), 2);
        $vat = round($num($d['vat'] ?? 0), 2);
        if ($added = self::addVat($items, $discount, $vat, $total)) {
            $warnings[] = sprintf('ราคาสินค้าในใบเสร็จยังไม่รวม VAT ฿%s — บวก VAT เข้าในราคาทุนของทุกรายการแล้ว', number_format($added, 2));
            $sum = round(array_sum(array_column($items, 'line_total')), 2);
        }
        if ($total > 0 && abs(($sum - $discount) - $total) > 0.009) {
            $warnings[] = sprintf('ผลรวมรายการ ฿%s (หักส่วนลดแล้ว ฿%s) ไม่ตรงกับยอดสุทธิในใบเสร็จ ฿%s กรุณาตรวจสอบ',
                number_format($sum, 2), number_format($sum - $discount, 2), number_format($total, 2));
        }

        return [
            'supplier_name' => $str($d['supplier_name'] ?? ''),
            'supplier_tax_id' => preg_replace('/\D/', '', $str($d['supplier_tax_id'] ?? '')) ?: '',
            'receipt_no' => $str($d['receipt_no'] ?? ''),
            'receipt_date' => $date,
            'items' => $items,
            'discount' => $discount,
            'vat' => $vat,
            'total' => $total,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
