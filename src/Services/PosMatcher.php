<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AiProviderRepo;
use PDO;

/**
 * Pairs item names from an external POS report with products in this system.
 * A name paired before is reused as is (status "saved"). Other names get a
 * short list of candidates by name similarity, equal cost and equal price;
 * then an AI model (a few lines per request, driven by the page) looks for
 * the product that is really the same item: it sees the candidates and can
 * search the whole product database itself, since POS names are often
 * spelled or ordered differently ("กาโดะรสส้ม" ↔ "กาโตะ น้ำส้ม 25% …").
 * The user confirms, changes or adds a new product before importing.
 */
class PosMatcher
{
    public const CANDIDATES = 6;
    /** Lines per AI request; the page sends them in batches and shows progress. */
    public const AI_BATCH = 10;
    private const MAX_TOOL_ROUNDS = 6;
    private const SEARCH_LIMIT = 10;
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

    /** @return array<string,int> character bigram counts of a key */
    private static function grams(string $key): array
    {
        $chars = mb_str_split($key);
        if (count($chars) < 2) {
            return [$key => 1];
        }
        $out = [];
        for ($i = 0; $i < count($chars) - 1; $i++) {
            $g = $chars[$i] . $chars[$i + 1];
            $out[$g] = ($out[$g] ?? 0) + 1;
        }
        return $out;
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
        $ga = self::grams($a);
        $gb = self::grams($b);
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
     * How well a POS name (or a search phrase) is found inside a product name:
     * the share of its bigrams that the product name has. A short POS name
     * against a long product name ("กาแฟเบอร์ดีแดง" ↔ "เบอร์ดี้ กาแฟปรุงสำเร็จ…
     * โรบัสต้า 170 มล.") scores low on Dice but high here, whatever the word order.
     */
    public static function coverage(string $needle, string $haystack): float
    {
        $n = self::key($needle);
        $h = self::key($haystack);
        if (mb_strlen($n) < 3 || $h === '') {
            return 0.0;
        }
        $gn = self::grams($n);
        $gh = self::grams($h);
        $hit = 0;
        foreach ($gn as $g => $c) {
            $hit += min($c, $gh[$g] ?? 0);
        }
        return round($hit / array_sum($gn), 3);
    }

    /** Name score used for ranking: similarity, or a discounted coverage. */
    public static function nameScore(string $posName, string $productName): float
    {
        return max(self::similarity($posName, $productName), round(0.85 * self::coverage($posName, $productName), 3));
    }

    /**
     * Local pass only (fast): saved pairs, exact names, and candidates. Lines
     * marked ai_pending are then sent to suggest() by the page, a few at a time.
     *
     * @param array<int,array{pos_name:string,pos_category:?string,qty:?int,net:float,cost:?float}> $lines
     * @return array{lines:array, ai:array{available:bool, model:?string, tools:bool}}
     */
    public function analyze(array $lines, array $user): array
    {
        $saved = $this->savedMappings(array_map(fn ($l) => self::key($l['pos_name']), $lines));
        $out = [];
        foreach ($lines as $i => $l) {
            $row = self::withUnits($l) + ['status' => 'none', 'match' => null, 'candidates' => [], 'ai_pending' => false];
            $map = $saved[self::key($l['pos_name'])] ?? null;
            if ($map && ($map['action'] === 'skip' || $map['product'])) {
                $row['status'] = 'saved';
                $row['match'] = [
                    'action' => $map['action'],
                    'product' => $map['product'],
                    'by' => 'saved',
                    'reason' => 'จับคู่ไว้แล้ว (ใช้มา ' . (int) $map['times_used'] . ' ครั้ง)',
                ];
                $out[] = $row;
                continue;
            }
            $row['candidates'] = $this->candidates($row);
            $top = $row['candidates'][0] ?? null;
            if ($top && $top['name_score'] >= 1.0) {
                $row['status'] = 'suggested';
                $row['match'] = ['action' => 'product', 'product' => $top['product'], 'by' => 'rule', 'reason' => 'ชื่อตรงกับสินค้าในระบบ', 'confidence' => 1];
            } else {
                $row['ai_pending'] = true;
                // Shown until the AI answers (or if it can't run).
                if ($top && $top['name_score'] >= self::RULE_MIN_NAME) {
                    $row['status'] = 'suggested';
                    $row['match'] = ['action' => 'product', 'product' => $top['product'], 'by' => 'rule', 'reason' => self::ruleReason($top), 'confidence' => min($top['score'], 1)];
                }
            }
            $out[] = $row;
        }

        $model = $this->resolveModel($user, null);
        return ['lines' => $out, 'ai' => [
            'available' => $model !== null,
            'model' => $model ? ($model[1]['display_name'] ?: $model[1]['model_id']) : null,
            'tools' => $model ? (bool) $model[1]['supports_tools'] : false,
        ]];
    }

    private static function withUnits(array $l): array
    {
        $qty = (int) ($l['qty'] ?? 0);
        $cost = isset($l['cost']) && $l['cost'] !== null && $l['cost'] !== '' ? (float) $l['cost'] : null;
        return [
            'pos_name' => trim((string) $l['pos_name']),
            'pos_category' => isset($l['pos_category']) && $l['pos_category'] !== '' ? (string) $l['pos_category'] : null,
            'qty' => $qty > 0 ? $qty : null,
            'net' => round((float) ($l['net'] ?? 0), 2),
            'cost' => $cost,
            'unit_price' => $qty > 0 ? round((float) $l['net'] / $qty, 2) : null,
            'unit_cost' => $qty > 0 && $cost !== null ? round($cost / $qty, 2) : null,
        ];
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
            $nameScore = self::nameScore($line['pos_name'], $p['name']);
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

    /** @return array{0:array,1:array}|null [provider, model] */
    private function resolveModel(array $user, ?int $modelRowId): ?array
    {
        try {
            $repo = new AiProviderRepo($this->db);
            return $modelRowId ? $repo->resolveEnabledModel($modelRowId, $user['role']) : $repo->pickCheapest(false, $user['role']);
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Asks the AI about a few lines. Models with tool calling may search the
     * product database as often as they need; others pick from the candidates.
     *
     * @param array<int,array{i:int,pos_name:string,pos_category?:?string,qty?:?int,net:float,cost?:?float}> $lines
     * @return array{matches:array<int,array{i:int,product:?array,confidence:float,reason:string}>, model:string, searches:int}
     */
    public function suggest(array $lines, array $user, ?int $modelRowId = null): array
    {
        $resolved = $this->resolveModel($user, $modelRowId);
        if (!$resolved) {
            throw new \RuntimeException('ยังไม่มีโมเดลผู้ช่วย AI ที่เปิดให้บทบาทของคุณใช้');
        }
        [$provider, $model] = $resolved;
        $repo = new AiProviderRepo($this->db);
        $client = new AiClient($provider['base_url'], $repo->apiKey($provider), $repo->extraHeaders($provider), 120);
        set_time_limit(300);

        $rows = [];
        foreach (array_slice($lines, 0, self::AI_BATCH) as $l) {
            $row = self::withUnits($l);
            $row['candidates'] = $this->candidates($row);
            $rows[(int) $l['i']] = $row;
        }
        $useTools = (bool) $model['supports_tools'];

        $usage = new AiUsageService($this->db, new SettingsService($this->db));
        $started = microtime(true);
        $searches = 0;
        $chars = 0;
        try {
            $messages = [['role' => 'user', 'content' => $this->prompt($rows, $useTools)]];
            $tools = $useTools ? [self::searchTool()] : [];
            $text = '';
            for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
                $last = $round === self::MAX_TOOL_ROUNDS;
                if ($last && $tools) {
                    $messages[] = ['role' => 'user', 'content' => 'หยุดค้นหาแล้ว ตอบผลการจับคู่เป็น JSON ตามรูปแบบที่กำหนดเลย'];
                }
                $reply = self::normalizeReply($client->chat($model['model_id'], $messages, $last ? [] : $tools, ['temperature' => 0]));
                $chars += mb_strlen($reply['content']);
                if (empty($reply['tool_calls'])) {
                    $text = $reply['content'];
                    break;
                }
                $messages[] = $reply;
                foreach ($reply['tool_calls'] as $call) {
                    $args = json_decode($call['function']['arguments'], true) ?: [];
                    $result = $call['function']['name'] === 'search_products'
                        ? ['products' => $this->searchFuzzy((string) ($args['query'] ?? ''), $args['cost'] ?? null, $args['price'] ?? null)]
                        : ['error' => 'ไม่มีเครื่องมือนี้'];
                    $searches++;
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
                }
            }
            $matches = $this->readMatches($text, $rows, $useTools);
        } catch (\RuntimeException $e) {
            $this->logUsage($usage, $user, $model, $provider, count($rows), $searches, $chars, $started, $e->getMessage());
            throw $e;
        }
        $this->logUsage($usage, $user, $model, $provider, count($rows), $searches, $chars, $started, null);
        return ['matches' => $matches, 'model' => $model['display_name'] ?: $model['model_id'], 'searches' => $searches];
    }

    private static function searchTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_products',
                'description' => 'ค้นหาสินค้าในฐานข้อมูลร้าน (ค้นแบบคลุมเครือ สะกดต่างเล็กน้อยก็เจอ) ใช้ค้นด้วยยี่ห้อ/คำหลักหลาย ๆ แบบ '
                    . 'หรือระบุ cost/price เพื่อให้สินค้าที่ราคาทุน/ราคาขายเท่ากันขึ้นก่อน ผลลัพธ์สูงสุด ' . self::SEARCH_LIMIT . ' รายการ',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'คำค้น เช่น ยี่ห้อ หรือชื่อสินค้า เช่น "กาโตะ", "เบอร์ดี้ กาแฟ"'],
                        'cost' => ['type' => 'number', 'description' => 'ราคาทุนต่อชิ้น (ไม่บังคับ)'],
                        'price' => ['type' => 'number', 'description' => 'ราคาขายต่อชิ้น (ไม่บังคับ)'],
                    ],
                ],
            ],
        ];
    }

    /** Fuzzy search for the AI tool: by name coverage, and/or equal cost/price. */
    public function searchFuzzy(string $query, mixed $cost = null, mixed $price = null): array
    {
        $query = mb_substr(trim($query), 0, 100);
        $cost = is_numeric($cost) && (float) $cost > 0 ? (float) $cost : null;
        $price = is_numeric($price) && (float) $price > 0 ? (float) $price : null;
        $scored = [];
        foreach ($this->products() as $p) {
            $s = $query !== '' ? max(self::coverage($query, $p['name']), self::similarity($query, $p['name'])) : 0;
            $costMatch = $cost !== null && abs($cost - $p['cost_price']) < 0.01;
            $priceMatch = $price !== null && abs($price - $p['sale_price']) < 0.01;
            if ($s < 0.34 && !(($costMatch || $priceMatch) && ($query === '' || $s >= 0.15))) {
                continue;
            }
            $scored[] = [$s + ($costMatch ? 0.3 : 0) + ($priceMatch ? 0.15 : 0), $p];
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
        return array_map(fn ($x) => self::forAi($x[1]), array_slice($scored, 0, self::SEARCH_LIMIT));
    }

    private static function forAi(array $p): array
    {
        return [
            'product_id' => $p['id'],
            'name' => $p['name'],
            'owner' => $p['consignor_name'] ?? 'สินค้าของร้าน',
            'cost' => $p['cost_price'],
            'price' => $p['sale_price'],
        ];
    }

    /** Assistant message as it must be sent back with tool results; strips leaked reasoning. */
    private static function normalizeReply(array $m): array
    {
        $content = $m['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(fn ($p) => is_array($p) ? ($p['text'] ?? '') : (string) $p, $content));
        }
        $content = (string) preg_replace('~<think>.*?</think>~su', '', (string) $content);
        if (($pos = mb_strrpos($content, '</think>')) !== false) {
            $content = mb_substr($content, $pos + mb_strlen('</think>'));
        }
        $msg = ['role' => 'assistant', 'content' => trim($content)];
        foreach ((array) ($m['tool_calls'] ?? []) as $c) {
            $name = (string) ($c['function']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $args = $c['function']['arguments'] ?? '{}';
            $msg['tool_calls'][] = [
                'id' => (string) (($c['id'] ?? '') ?: 'call_' . bin2hex(random_bytes(6))),
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => is_string($args) ? $args : json_encode($args, JSON_UNESCAPED_UNICODE)],
            ];
        }
        return $msg;
    }

    private function readMatches(string $text, array $rows, bool $anyProduct): array
    {
        $out = [];
        foreach (self::parseReply($text) as $m) {
            $i = (int) ($m['i'] ?? -1);
            if (!isset($rows[$i]) || isset($out[$i])) {
                continue;
            }
            $pid = isset($m['product_id']) && is_numeric($m['product_id']) ? (int) $m['product_id'] : null;
            $product = $pid !== null ? $this->product($pid) : null;
            // Without the search tool the model only saw the candidates; any other id is made up.
            if ($product && !$anyProduct && !in_array($pid, array_map(fn ($c) => $c['product']['id'], $rows[$i]['candidates']), true)) {
                $product = null;
            }
            if ($pid !== null && !$product) {
                continue;
            }
            $out[$i] = [
                'i' => $i,
                'product' => $product,
                'confidence' => max(0, min(1, (float) ($m['confidence'] ?? 0.5))),
                'reason' => mb_substr(trim((string) ($m['reason'] ?? '')), 0, 120),
            ];
        }
        return array_values($out);
    }

    private function logUsage(AiUsageService $usage, array $user, array $model, array $provider, int $count, int $searches, int $chars, float $started, ?string $error): void
    {
        try {
            $usage->log([
                'user_id' => (int) $user['id'],
                'channel' => 'pos_match',
                'category' => 'markdown',
                'prompt' => "จับคู่รายการขายจาก POS ภายนอก {$count} รายการ (หน้านำเข้ายอดขาย)" . ($searches ? " ค้นสินค้า {$searches} ครั้ง" : ''),
                'model_row_id' => (int) $model['id'],
                'model_name' => $model['model_id'],
                'provider_name' => $provider['name'],
                'response_chars' => $chars,
                'tool_calls' => $searches ? ['search_products'] : [],
                'status' => $error === null ? 'ok' : 'error',
                'error_message' => $error,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            error_log('AI usage log failed: ' . $e->getMessage());
        }
    }

    private function prompt(array $rows, bool $useTools): string
    {
        $data = [];
        foreach ($rows as $i => $r) {
            $data[] = json_encode([
                'i' => $i,
                'pos_name' => $r['pos_name'],
                'category' => $r['pos_category'],
                'qty' => $r['qty'],
                'unit_price' => $r['unit_price'],
                'unit_cost' => $r['unit_cost'],
                'candidates' => array_map(fn ($c) => self::forAi($c['product']), $r['candidates']),
            ], JSON_UNESCAPED_UNICODE);
        }
        $how = $useTools
            ? <<<TXT
            - candidates คือสินค้าที่ระบบคัดมาให้ด้วยความคล้ายของตัวอักษร อาจไม่ครบหรือผิด
            - ถ้าไม่มี candidate ที่ใช่ ให้เรียก search_products ค้นเองจากฐานข้อมูล ลองหลายคำค้น เช่น ยี่ห้ออย่างเดียว ("กาโตะ"),
              คำที่สะกดอีกแบบ ("กาโดะ"→"กาโตะ", "เบอร์ดี"→"เบอร์ดี้", "7up"→"เซเว่นอัพ"), ประเภทสินค้า ("กิ๊บ", "คลิป"), หรือระบุ cost/price
              เรียกพร้อมกันหลายครั้งในรอบเดียวได้ ค้นได้หลายรอบ แต่ไม่เกิน 3 ครั้งต่อรายการ
            - product_id ที่ตอบต้องมาจาก candidates หรือผลค้นหาเท่านั้น
            TXT
            : '- ให้เลือก product_id จาก candidates ของรายการนั้นเท่านั้น';
        return <<<TXT
        งาน: จับคู่ "รายการขายจากระบบ POS ภายนอก" ซึ่งไม่มีบาร์โค้ดหรือรหัสสินค้า กับสินค้าในฐานข้อมูลร้านค้าสหการ
        {$how}
        หลักการพิจารณา:
        - ชื่อใน POS มักสั้นกว่า สะกดต่าง เรียงคำต่าง หรือพิมพ์ผิด (เช่น "กาโดะรสส้ม" = "กาโตะ น้ำส้ม 25% ผสมวุ้นมะพร้าว 250 มล.",
          "กาแฟเบอร์ดีแดง" = "เบอร์ดี้ กาแฟปรุงสำเร็จพร้อมดื่ม โรบัสต้า 170 มล." ซึ่งเป็นกระป๋องสีแดง)
        - รส สี ขนาด ปริมาณ ต้องไม่ขัดกัน ถ้าต่างกันชัดเจน (เช่น "มิรินด้า น้ำเขียว" กับ "มิรินด้า น้ำแดง") ห้ามจับคู่
        - ราคาทุน/ราคาขายต่อชิ้นที่เท่ากันเป็นหลักฐานที่ดี แต่ราคาใน POS อาจไม่ได้อัปเดต ต่างกันเล็กน้อยไม่ถือว่าผิด
        - หมวดหมู่ที่มีคำว่า "ขายฝาก" และชื่อคน (เช่น "003 ขายฝาก ครูออย") ควรตรงกับ owner ของสินค้า
        - ถ้าหาไม่เจอจริง ๆ ให้ product_id = null (ผู้ใช้จะเพิ่มเป็นสินค้าใหม่)
        - confidence 0–1 ตามความมั่นใจ, reason เหตุผลสั้น ๆ ภาษาไทยไม่เกิน 60 ตัวอักษร
        คำตอบสุดท้ายเป็น JSON อย่างเดียว ห้ามมีข้อความอื่น ตอบให้ครบทุกรายการ:
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

    /** Product picker in the review table: name or barcode, then fuzzy name match. */
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
        $rows = array_map([self::class, 'castProduct'], $stmt->fetchAll());
        // Typing it the POS way ("กาโดะ") still finds "กาโตะ …".
        if (count($rows) < 20) {
            $have = array_column($rows, 'id');
            foreach ($this->searchFuzzy($q) as $f) {
                if (!in_array($f['product_id'], $have, true) && ($p = $this->product($f['product_id']))) {
                    $rows[] = $p;
                }
            }
        }
        return array_slice($rows, 0, 20);
    }
}
