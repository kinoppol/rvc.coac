<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Where stock that no document explains came from (the stock ledger's "gap" and "opening" rows).
 * Every change of a product's stock is in the audit log with the figures before and after, so the
 * log is paired with the ledger's rows; what is left over on either side is the explanation: a
 * product created with stock, a purchase since cancelled or edited, a stock figure set directly.
 * Anything not in the log either (older than the log) is said to be so.
 */
class StockTraceService
{
    /** Documents whose own log entry follows the stock change they made: table => [link kind, create, update, cancel / delete]. */
    private const DOCS = [
        'purchase_documents' => ['purchase', 'บันทึกใบซื้อ', 'แก้ไข / รับของตามใบซื้อ', 'ยกเลิกใบซื้อ'],
        'sales' => ['sale', 'บิลขาย', 'แก้ไขบิลขาย', 'ลบบิลขาย'],
        'receive_documents' => ['receive', 'ใบรับฝาก', 'แก้ไขใบรับฝาก', 'ยกเลิกใบรับฝาก'],
        'return_documents' => ['return', 'ใบคืนผู้ฝาก', 'แก้ไขใบคืนผู้ฝาก', 'ยกเลิกใบคืนผู้ฝาก'],
        'pos_imports' => [null, 'นำเข้ายอดขาย POS', 'แก้ไขการนำเข้า POS', 'ยกเลิกการนำเข้า POS'],
        'product_splits' => [null, 'แยกสินค้า', 'แก้ไขการแยกสินค้า', 'ยกเลิกการแยกสินค้า'],
        'damaged_goods' => [null, 'สินค้าเสียหาย', 'แก้ไขสินค้าเสียหาย', 'ยกเลิกสินค้าเสียหาย'],
        'withdrawals' => [null, 'จ่ายเงินผู้ฝาก', 'แก้ไขการจ่ายเงินผู้ฝาก', 'ยกเลิกการจ่ายเงินผู้ฝาก'],
        'sale_edit_requests' => [null, 'แก้ไขการขาย', 'แก้ไขการขาย', 'ยกเลิกการแก้ไขการขาย'],
    ];

    /** Most lines listed for one unexplained row. */
    private const LINES = 80;

    public function __construct(private PDO $db)
    {
    }

    /**
     * The logged stock changes of a product (and of the duplicate rows merged into it, whose
     * documents are now its own), oldest first. A merge itself only moves stock between those rows.
     */
    public function changes(int $productId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.id, a.record_id, a.action, a.old_data, a.new_data, a.user_id, a.created_at, u.full_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.table_name = 'products' AND a.record_id = ? AND (a.new_data LIKE '%qty_stock%' OR a.new_data LIKE '%merged_from%')
             ORDER BY a.id"
        );
        $todo = [$productId];
        $seen = [];
        $out = [];
        while ($todo) {
            $id = array_pop($todo);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $r) {
                $old = (array) json_decode((string) $r['old_data'], true);
                $new = (array) json_decode((string) $r['new_data'], true);
                $merged = array_map('intval', (array) ($new['merged_from'] ?? []));
                array_push($todo, ...$merged);
                if (!isset($new['qty_stock'])) {
                    continue;
                }
                $created = $r['action'] === 'create';
                if (!$created && !isset($old['qty_stock'])) {
                    continue;
                }
                $before = $created ? 0 : (int) $old['qty_stock'];
                $change = (int) $new['qty_stock'] - $before;
                if ($change === 0 && !$merged) {
                    continue;
                }
                $out[] = [
                    'id' => (int) $r['id'], 'product_id' => (int) $r['record_id'], 'at' => (string) $r['created_at'], 'user_id' => $r['user_id'],
                    'user_name' => $r['full_name'], 'created' => $created, 'merged' => $merged,
                    // A merge adds the duplicates' stock, which their own entries already account for.
                    'change' => $merged ? 0 : $change, 'before' => $before, 'after' => (int) $new['qty_stock'], 'old' => $old, 'new' => $new,
                ];
            }
        }
        usort($out, fn ($a, $b) => $a['id'] <=> $b['id']);
        return $out;
    }

    /**
     * Pairs the log with the ledger's movements. Returns the changes no movement stands for and,
     * for each movement (by its index), the pieces the log does not bear out.
     *
     * @return array{0: array<int,array>, 1: array<int,int>}
     */
    public function pair(array $changes, array $moves): array
    {
        $left = [];
        $residual = [];
        // Counts and their cancels name the count in the log: all entries of one count stand together.
        foreach ($moves as $i => $m) {
            $residual[$i] = (int) $m['qty'];
            if (!in_array($m['kind'], ['count', 'countcancelled', 'countvoid'], true) || !$m['doc_no']) {
                continue;
            }
            foreach ($changes as $k => $c) {
                $void = str_starts_with((string) ($c['new']['note'] ?? ''), 'ยกเลิกการตรวจนับ');
                if (!isset($left[$k]) && ($c['new']['stock_count'] ?? null) === $m['doc_no'] && $void === ($m['kind'] === 'countvoid')) {
                    $left[$k] = true;
                    $residual[$i] -= $c['change'];
                }
            }
        }
        // Everything else (and counts logged without their number): the same pieces within a few seconds.
        foreach ($moves as $i => $m) {
            $named = in_array($m['kind'], ['count', 'countcancelled', 'countvoid'], true) && $residual[$i] !== (int) $m['qty'];
            if ($residual[$i] === 0 || $named) {
                continue;
            }
            $at = strtotime((string) ($m['logged_at'] ?? $m['at']));
            $best = null;
            foreach ($changes as $k => $c) {
                $gap = abs(strtotime($c['at']) - $at);
                if (!isset($left[$k]) && $c['change'] === (int) $m['qty'] && $gap <= 15 && ($best === null || $gap < $best[1])) {
                    $best = [$k, $gap];
                }
            }
            if ($best !== null) {
                $left[$best[0]] = true;
                $residual[$i] = 0;
            }
        }
        return [array_values(array_diff_key($changes, $left)), $residual];
    }

    /**
     * The explanation of one unexplained figure: the log entries and ledger rows left over in its
     * span, and the pieces neither accounts for.
     *
     * @return array{items: array<int,array>, unlogged:int, more:int}
     */
    public function explain(int $qty, array $changes, array $rows): array
    {
        $items = [];
        $explained = 0;
        foreach ($changes as $c) {
            $explained += $c['change'];
            $items[] = $this->describe($c);
        }
        foreach ($rows as [$m, $over]) {
            if ($over === 0) {
                continue;
            }
            $explained -= $over;
            $items[] = [
                'at' => $m['logged_at'] ?? $m['at'], 'doc_no' => $m['doc_no'], 'doc_kind' => null, 'user_name' => $m['user_name'], 'change' => -$over,
                'before' => null, 'after' => null,
                'what' => 'รายการนี้อยู่ในบัญชี ' . sprintf('%+d', $m['qty']) . ' แต่ประวัติการเปลี่ยนสต๊อกตอนนั้นไม่ตรงกัน (หักออกจากยอดที่อธิบายได้)',
            ];
        }
        usort($items, fn ($a, $b) => (string) $a['at'] <=> (string) $b['at']);
        return ['items' => array_slice($items, -self::LINES), 'unlogged' => $qty - $explained, 'more' => max(0, count($items) - self::LINES)];
    }

    /** What a logged stock change was: its own note, else the document logged right after it, else which figure moved. */
    private function describe(array $c): array
    {
        $new = $c['new'];
        $item = ['at' => $c['at'], 'doc_no' => null, 'doc_kind' => null, 'user_name' => $c['user_name'], 'change' => $c['change'],
            'before' => $c['before'], 'after' => $c['after'], 'what' => ''];
        if ($c['created']) {
            $item['what'] = 'สร้างสินค้าในระบบพร้อมยอดคงเหลือ ' . $c['after'] . ' ชิ้น';
        } elseif ($c['merged']) {
            $item['what'] = 'รวมสินค้าซ้ำ (รายการ ' . implode(', ', $c['merged']) . ') ยอดคงเหลือ ' . $c['before'] . ' → ' . $c['after'] . ' เอกสารของรายการที่รวมย้ายมาอยู่ที่สินค้านี้';
        } elseif (!empty($new['note']) && is_string($new['note'])) {
            $item['what'] = $new['note'];
            $item['doc_no'] = $new['stock_count'] ?? null;
            $item['doc_kind'] = isset($new['stock_count']) ? 'count' : null;
        } elseif (!empty($new['withdrawal'])) {
            $item['what'] = "ปรับยอดตอนจ่ายเงินผู้ฝาก {$new['withdrawal']}";
            $item['doc_no'] = $new['stock_count'] ?? null;
            $item['doc_kind'] = 'count';
        } elseif (!empty($new['stock_count'])) {
            $item['what'] = 'ตั้งยอดตามการตรวจนับ';
            $item['doc_no'] = $new['stock_count'];
            $item['doc_kind'] = 'count';
        }
        // A change that says for itself what it was gets no document, except a purchase it names.
        $own = $c['merged'] || !empty($new['withdrawal']) || ($item['what'] !== '' && !$c['created'] && !str_contains($item['what'], 'ใบซื้อ'));
        $doc = $own ? null : $this->documentAfter($c);
        if ($doc !== null) {
            $item['doc_no'] ??= $doc['doc_no'];
            $item['doc_kind'] ??= $doc['kind'];
            $item['what'] = trim($item['what'] . ($item['what'] === '' ? '' : ' · ') . $doc['what']);
        }
        if ($item['what'] === '') {
            $moved = fn (string $k) => isset($c['old'][$k], $new[$k]) && (int) $c['old'][$k] !== (int) $new[$k];
            $item['what'] = match (true) {
                $moved('qty_received') => 'รับเข้า / ย้อนการรับเข้า (ซื้อหรือรับฝาก)',
                $moved('qty_sold') => 'ขาย / ย้อนการขาย',
                $moved('qty_returned') => 'คืนผู้ฝาก / ย้อนการคืน',
                default => 'แก้ยอดคงเหลือของสินค้าโดยตรง (ประวัติไม่ระบุสาเหตุ)',
            };
        }
        return $item;
    }

    private function documentAfter(array $c): ?array
    {
        $tables = array_keys(self::DOCS);
        $in = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $this->db->prepare(
            "SELECT table_name, action, record_id, old_data, new_data FROM audit_logs
             WHERE id > ? AND id <= ? AND user_id <=> ? AND table_name IN ($in) AND created_at <= ? + INTERVAL 15 SECOND
             ORDER BY id LIMIT 1"
        );
        $stmt->execute([$c['id'], $c['id'] + 400, $c['user_id'], ...$tables, $c['at']]);
        $d = $stmt->fetch();
        if (!$d) {
            return null;
        }
        [$kind, $create, $update, $cancel] = self::DOCS[$d['table_name']];
        $old = (array) json_decode((string) $d['old_data'], true);
        $new = (array) json_decode((string) $d['new_data'], true);
        $docNo = $new['doc_no'] ?? $old['doc_no'] ?? null;
        if ($docNo === null && \App\Support\Db::hasColumn($this->db, $d['table_name'], 'doc_no')) {
            $q = $this->db->prepare("SELECT doc_no FROM {$d['table_name']} WHERE id = ?");
            $q->execute([$d['record_id']]);
            $docNo = $q->fetchColumn() ?: null;
        }
        $label = match ($d['action']) {
            'create' => $create,
            'update' => $update,
            default => $cancel,
        };
        $reason = $new['reason'] ?? $new['cancel_reason'] ?? null;
        return ['kind' => $docNo === null ? null : $kind, 'doc_no' => $docNo === null ? null : (string) $docNo,
            'what' => $label . ($reason ? " (เหตุผล: {$reason})" : '')];
    }
}
