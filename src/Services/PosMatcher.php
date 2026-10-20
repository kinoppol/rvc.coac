<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AiProviderRepo;
use PDO;

/**
 * Pairs item names from an external POS report with products in this system.
 * A name paired before is reused as is (status "saved"). Other names get a
 * short list of candidates by name similarity, equal cost and equal price;
 * an AI model then picks the one that is really the same item (or none),
 * and the user confirms, changes or adds a new product before importing.
 */
class PosMatcher
{
    public const CANDIDATES = 6;
    private const AI_BATCH = 50;
    /**
     * Name similarity needed for a suggestion without AI. Lower ones are too
     * often another flavour or size of the same brand ("สติงค์ ส้ม" / "สติงค์ แดง").
     */
    private const RULE_MIN_NAME = 0.7;

    private ?array $products = null;

    public function __construct(private PDO $db)
    {
    }

    /** Lookup key for a POS name: case, spaces and punctuation do not matter. */
    public static function key(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $k = (string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $s);
        return mb_substr($k !== '' ? $k : $s, 0, 191);
    }

    /** Dice coefficient on character bigrams, boosted when one name contains the other. */
    public static function similarity(string $a, string $b): float
    {
        $a = self::key($a);
        $b = self::key($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $grams = function (string $s): array {
            $chars = mb_str_split($s);
            if (count($chars) < 2) {
                return [$s => 1];
            }
            $out = [];
            for ($i = 0; $i < count($chars) - 1; $i++) {
                $g = $chars[$i] . $chars[$i + 1];
                $out[$g] = ($out[$g] ?? 0) + 1;
            }
            return $out;
        };
        $ga = $grams($a);
        $gb = $grams($b);
        $common = 0;
        foreach ($ga as $g => $n) {
            $common += min($n, $gb[$g] ?? 0);
        }
        $dice = 2 * $common / (array_sum($ga) + array_sum($gb));
        $la = mb_strlen($a);
        $lb = mb_strlen($b);
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $dice = max($dice, 0.55 + 0.4 * min($la, $lb) / max($la, $lb));
        }
        return round(min($dice, 0.99), 3);
    }

    /**
     * @param array<int,array{pos_name:string,pos_category:?string,qty:?int,net:float,cost:?float}> $lines
     * @return array{lines:array, ai:array{used:bool, model:?string, notice:?string}}
     */
    public function analyze(array $lines, array $user, ?int $modelRowId = null): array
    {
        $saved = $this->savedMappings(array_map(fn ($l) => self::key($l['pos_name']), $lines));
        $out = [];
        $pending = [];
        foreach ($lines as $i => $l) {
            $qty = (int) ($l['qty'] ?? 0);
            $row = $l + [
                'unit_price' => $qty > 0 ? round($l['net'] / $qty, 2) : null,
                'unit_cost' => $qty > 0 && $l['cost'] !== null ? round($l['cost'] / $qty, 2) : null,
                'status' => 'none',
                'match' => null,
                'candidates' => [],
            ];
            $map = $saved[self::key($l['pos_name'])] ?? null;
            if ($map && ($map['action'] === 'skip' || $map['product'])) {
                $row['status'] = 'saved';
                $row['match'] = [
                    'action' => $map['action'],
                    'product' => $map['product'],
                    'by' => 'saved',
                    'reason' => 'จับคู่ไว้แล้ว (ใช้มา ' . (int) $map['times_used'] . ' ครั้ง)',
                ];
                $out[$i] = $row;
                continue;
            }
            $row['candidates'] = $this->candidates($row);
            $top = $row['candidates'][0] ?? null;
            if ($top && $top['name_score'] >= 1.0) {
                $row['status'] = 'suggested';
                $row['match'] = ['action' => 'product', 'product' => $top['product'], 'by' => 'rule', 'reason' => 'ชื่อตรงกับสินค้าในระบบ', 'confidence' => 1];
            } elseif ($row['candidates']) {
                $pending[] = $i;
            }
            $out[$i] = $row;
        }

        $ai = ['used' => false, 'model' => null, 'notice' => null];
        $picked = [];
        if ($pending) {
            try {
                [$picked, $ai] = $this->askAi($out, $pending, $user, $modelRowId);
            } catch (\RuntimeException $e) {
                $ai['notice'] = 'ผู้ช่วย AI ขัดข้อง: ' . $e->getMessage() . ' — ใช้การจับคู่จากชื่อและราคาทุนแทน';
            }
        }
        foreach ($pending as $i) {
            $row = &$out[$i];
            if (array_key_exists($i, $picked)) {
                $p = $picked[$i];
                if ($p['product_id'] !== null) {
                    $cand = current(array_filter($row['candidates'], fn ($c) => $c['product']['id'] === $p['product_id']));
                    $row['status'] = 'suggested';
                    $row['match'] = ['action' => 'product', 'product' => $cand['product'], 'by' => 'ai', 'reason' => $p['reason'], 'confidence' => $p['confidence']];
                } else {
                    $row['ai_note'] = $p['reason'] ?: 'AI ไม่พบสินค้าที่ตรงกัน';
                }
            } elseif ($row['candidates'][0]['name_score'] >= self::RULE_MIN_NAME) {
                // No AI, or AI left this line out: fall back to the best local score.
                $c = $row['candidates'][0];
                $row['status'] = 'suggested';
                $row['match'] = ['action' => 'product', 'product' => $c['product'], 'by' => 'rule', 'reason' => self::ruleReason($c), 'confidence' => min($c['score'], 1)];
            }
            unset($row);
        }

        return ['lines' => array_values($out), 'ai' => $ai];
    }

    /** Products sold here, scored against one POS line; best first. */
    private function candidates(array $line): array
    {
        $category = (string) ($line['pos_category'] ?? '');
        $consignmentHint = mb_strpos($category, 'ฝาก') !== false;
        // "003 ขายฝาก ครูออย" → "ครูออย": who the item belongs to.
        $owner = trim((string) preg_replace('/^[\d\s.\-]*|ขายฝาก|ฝากขาย/u', '', $category));
        $scored = [];
        foreach ($this->products() as $p) {
            $nameScore = self::similarity($line['pos_name'], $p['name']);
            $costMatch = $line['unit_cost'] !== null && $line['unit_cost'] > 0 && abs($line['unit_cost'] - $p['cost_price']) < 0.01;
            $priceMatch = $line['unit_price'] !== null && abs($line['unit_price'] - $p['sale_price']) < 0.01;
            if ($nameScore < 0.3 && !($costMatch && $priceMatch && $nameScore >= 0.15)) {
                continue;
            }
            $ownerMatch = $owner !== '' && $p['consignor_name'] !== null
                && (str_contains(self::key($p['consignor_name']), self::key($owner)) || self::similarity($owner, $p['consignor_name']) >= 0.5);
            $score = $nameScore + ($costMatch ? 0.25 : 0) + ($priceMatch ? 0.1 : 0) + ($ownerMatch ? 0.15 : 0)
                - ($consignmentHint && $p['consignor_id'] === null ? 0.05 : 0);
            $scored[] = [
                'product' => $p,
                'score' => round($score, 3),
                'name_score' => $nameScore,
                'cost_match' => $costMatch,
                'price_match' => $priceMatch,
                'owner_match' => $ownerMatch,
            ];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, self::CANDIDATES);
    }

    private static function ruleReason(array $c): string
    {
        $parts = ['ชื่อคล้ายกัน ' . (int) round($c['name_score'] * 100) . '%'];
        if ($c['cost_match']) {
            $parts[] = 'ราคาทุนเท่ากัน';
        }
        if ($c['price_match']) {
            $parts[] = 'ราคาขายเท่ากัน';
        }
        if ($c['owner_match']) {
            $parts[] = 'ผู้ฝากตรงกับหมวด';
        }
        return implode(', ', $parts);
    }

    /** @return array{0:array<int,array{product_id:?int,confidence:float,reason:string}>,1:array} */
    private function askAi(array $rows, array $pending, array $user, ?int $modelRowId): array
    {
        $repo = new AiProviderRepo($this->db);
        $resolved = $modelRowId ? $repo->resolveEnabledModel($modelRowId, $user['role']) : $repo->pickCheapest(false, $user['role']);
        if (!$resolved) {
            return [[], ['used' => false, 'model' => null, 'notice' => 'ยังไม่มีโมเดลผู้ช่วย AI ที่เปิดให้บทบาทของคุณใช้ — ใช้การจับคู่จากชื่อและราคาทุนแทน']];
        }
        [$provider, $model] = $resolved;
        $client = new AiClient($provider['base_url'], $repo->apiKey($provider), $repo->extraHeaders($provider), 150);
        set_time_limit(300);

        $usage = new AiUsageService($this->db, new SettingsService($this->db));
        $started = microtime(true);
        $picked = [];
        $chars = 0;
        try {
            foreach (array_chunk($pending, self::AI_BATCH) as $chunk) {
                $reply = $client->chat($model['model_id'], [['role' => 'user', 'content' => $this->prompt($rows, $chunk)]], [], ['temperature' => 0]);
                $text = is_array($reply['content'] ?? null)
                    ? implode('', array_map(fn ($p) => $p['text'] ?? '', $reply['content']))
                    : (string) ($reply['content'] ?? '');
                $chars += mb_strlen($text);
                foreach (self::parseReply($text) as $m) {
                    $i = (int) ($m['i'] ?? -1);
                    if (!in_array($i, $chunk, true)) {
                        continue;
                    }
                    $pid = isset($m['product_id']) && $m['product_id'] !== null ? (int) $m['product_id'] : null;
                    // Only a product from this line's own candidates; anything else is a made-up id.
                    $ids = array_map(fn ($c) => $c['product']['id'], $rows[$i]['candidates']);
                    if ($pid !== null && !in_array($pid, $ids, true)) {
                        continue;
                    }
                    $picked[$i] = [
                        'product_id' => $pid,
                        'confidence' => max(0, min(1, (float) ($m['confidence'] ?? 0.5))),
                        'reason' => mb_substr(trim((string) ($m['reason'] ?? '')), 0, 120),
                    ];
                }
            }
        } catch (\RuntimeException $e) {
            $this->logUsage($usage, $user, $model, $provider, count($pending), $chars, $started, $e->getMessage());
            throw $e;
        }
        $this->logUsage($usage, $user, $model, $provider, count($pending), $chars, $started, null);
        return [$picked, ['used' => true, 'model' => $model['display_name'] ?: $model['model_id'], 'notice' => null]];
    }

    private function logUsage(AiUsageService $usage, array $user, array $model, array $provider, int $count, int $chars, float $started, ?string $error): void
    {
        try {
            $usage->log([
                'user_id' => (int) $user['id'],
                'channel' => 'pos_match',
                'category' => 'markdown',
                'prompt' => "จับคู่รายการขายจาก POS ภายนอก {$count} รายการ (หน้านำเข้ายอดขาย)",
                'model_row_id' => (int) $model['id'],
                'model_name' => $model['model_id'],
                'provider_name' => $provider['name'],
                'response_chars' => $chars,
                'status' => $error === null ? 'ok' : 'error',
                'error_message' => $error,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            error_log('AI usage log failed: ' . $e->getMessage());
        }
    }

    private function prompt(array $rows, array $chunk): string
    {
        $data = [];
        foreach ($chunk as $i) {
            $r = $rows[$i];
            $data[] = json_encode([
                'i' => $i,
                'pos_name' => $r['pos_name'],
                'category' => $r['pos_category'],
                'qty' => $r['qty'],
                'unit_price' => $r['unit_price'],
                'unit_cost' => $r['unit_cost'],
                'candidates' => array_map(fn ($c) => [
                    'product_id' => $c['product']['id'],
                    'name' => $c['product']['name'],
                    'owner' => $c['product']['consignor_name'] ?? 'สินค้าของร้าน',
                    'cost' => $c['product']['cost_price'],
                    'price' => $c['product']['sale_price'],
                ], $r['candidates']),
            ], JSON_UNESCAPED_UNICODE);
        }
        return <<<TXT
        งาน: จับคู่ "รายการขายจากระบบ POS ภายนอก" ซึ่งไม่มีบาร์โค้ดหรือรหัสสินค้า กับสินค้าในฐานข้อมูลร้านค้าสหการ
        แต่ละรายการมี candidates ที่ระบบคัดมาให้แล้ว ให้เลือก product_id จาก candidates ของรายการนั้นเท่านั้น
        หลักการพิจารณา:
        - ชื่อต้องหมายถึงสินค้าเดียวกัน แม้สะกดต่าง เว้นวรรคต่าง ย่อ/เต็ม หรือพิมพ์ผิดเล็กน้อย
        - รส สี ขนาด ปริมาณ ต้องตรงกัน ถ้าต่างกันชัดเจน (เช่น "มิรินด้า น้ำเขียว" กับ "มิรินด้า น้ำแดง") ห้ามจับคู่
        - ราคาทุนต่อชิ้น (unit_cost เทียบ cost) ที่เท่ากันเป็นหลักฐานสำคัญ ราคาขายต่อชิ้นที่เท่ากันช่วยยืนยัน
        - หมวดหมู่ที่มีคำว่า "ขายฝาก" และชื่อคน (เช่น "003 ขายฝาก ครูออย") ควรตรงกับ owner ของสินค้า
        - ถ้าไม่มี candidate ใดเป็นสินค้าเดียวกันจริง ให้ product_id = null (ผู้ใช้จะเพิ่มเป็นสินค้าใหม่)
        - confidence 0–1 ตามความมั่นใจ, reason เหตุผลสั้น ๆ ภาษาไทยไม่เกิน 60 ตัวอักษร
        ตอบเป็น JSON อย่างเดียว ห้ามมีข้อความอื่น ตอบให้ครบทุกรายการ:
        {"matches":[{"i":0,"product_id":123,"confidence":0.9,"reason":"ชื่อเดียวกัน ราคาทุนเท่ากัน"}]}

        รายการ (หนึ่งบรรทัดต่อหนึ่งรายการ):
        TXT . "\n" . implode("\n", $data);
    }

    private static function parseReply(string $text): array
    {
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $text = $m[1];
        } elseif (($start = strpos($text, '{')) !== false && ($end = strrpos($text, '}')) !== false) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $data = json_decode($text, true);
        if (!is_array($data) || !is_array($data['matches'] ?? null)) {
            throw new \RuntimeException('AI ตอบกลับในรูปแบบที่ไม่ถูกต้อง');
        }
        return array_filter($data['matches'], 'is_array');
    }

    /** @return array<string,array> saved pairs by POS key, with the paired product */
    public function savedMappings(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (!$keys) {
            return [];
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare("SELECT * FROM pos_item_mappings WHERE pos_key IN ($in)");
        $stmt->execute($keys);
        $out = [];
        foreach ($stmt->fetchAll() as $m) {
            $m['product'] = $m['product_id'] !== null ? $this->product((int) $m['product_id']) : null;
            $out[$m['pos_key']] = $m;
        }
        return $out;
    }

    public function product(int $id): ?array
    {
        foreach ($this->products() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /** Every product (any stock), as shown in the review table. */
    private function products(): array
    {
        if ($this->products === null) {
            $rows = $this->db->query(
                'SELECT p.id, p.name, p.barcode, p.cost_price, p.sale_price, p.qty_stock, p.consignor_id, c.name AS consignor_name
                 FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id'
            )->fetchAll();
            $this->products = array_map([self::class, 'castProduct'], $rows);
        }
        return $this->products;
    }

    public static function castProduct(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'barcode' => $p['barcode'],
            'cost_price' => (float) $p['cost_price'],
            'sale_price' => (float) $p['sale_price'],
            'qty_stock' => (int) $p['qty_stock'],
            'consignor_id' => $p['consignor_id'] !== null ? (int) $p['consignor_id'] : null,
            'consignor_name' => $p['consignor_name'],
        ];
    }

    /** Product picker in the review table: any stock level, name or barcode. */
    public function search(string $q): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.name, p.barcode, p.cost_price, p.sale_price, p.qty_stock, p.consignor_id, c.name AS consignor_name
             FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE p.name LIKE ? OR p.barcode LIKE ?
             ORDER BY p.qty_stock > 0 DESC, p.name ASC LIMIT 20'
        );
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        return array_map([self::class, 'castProduct'], $stmt->fetchAll());
    }
}
