<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use PDO;

/**
 * Books the sales an Android POS device sends (pos_device_sales, migration 0059).
 *
 * Each sale carries an id the device made up (client_uuid), so sending it again never books
 * it twice. A sale made while the device was online is checked like a sale on the web POS
 * (stock, payment methods, the cash drawer) and turned back for the cashier to fix. A sale
 * made offline already happened: the customer has paid and gone, so it is booked as it was
 * made — at its own time and price, even when stock runs below zero or a payment method has
 * since been turned off — and noted with warnings. One the server still cannot book (say its
 * product was merged away) is kept as failed for an admin to retry or settle by hand.
 */
class DeviceSaleSyncService
{
    public const MAX_BATCH = 50;
    // A device clock this far ahead is wrong: the sale is booked at the time it arrived.
    private const FUTURE_SLACK = 600;

    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    /**
     * @param array $sale {client_uuid, sold_at, offline, allow_short, items:[{product_id, qty, pack_size?, unit_price?}], payments:[{method, amount}], total_value?}
     * @param array $user the seller who made the sale (with its active role)
     * @param string[] $notes warnings to keep with the sale
     * @return array{client_uuid:string, status:string, sale?:array, error?:string, warnings?:string[]}
     *         status: synced | duplicate | failed (kept for an admin) | rejected (online: fix and send again) | drawer_required
     *
     * A clash with another till (same document number, deadlock) is tried again. Any other error
     * of one sale stays with that sale — it never fails the whole batch, or the device would send
     * the same batch for ever and nothing behind it would get through.
     */
    public function process(array $device, array $user, array $sale, ?string $ip, array $notes = []): array
    {
        for ($try = 1; ; $try++) {
            try {
                return $this->book($device, $user, $sale, $ip, $notes);
            } catch (\Throwable $e) {
                $uuid = strtolower(trim((string) ($sale['client_uuid'] ?? '')));
                $clash = $e instanceof \PDOException && in_array($e->errorInfo[1] ?? null, [1062, 1205, 1213], true);
                if ($clash && $try < 3) {
                    usleep(100000 * $try);
                    continue;
                }
                if ($clash) {
                    return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => 'กำลังบันทึกรายการนี้อยู่ ส่งใหม่อีกครั้ง'];
                }
                error_log(sprintf('[pos-device %d] sale %s: %s at %s:%d', $device['id'], $uuid, $e->getMessage(), $e->getFile(), $e->getLine()));
                $error = 'เซิร์ฟเวอร์บันทึกรายการนี้ไม่ได้: ' . $e->getMessage();
                if (empty($sale['offline'])) {
                    return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => $error];
                }
                $soldAt = $this->soldAt($sale['sold_at'] ?? null) ?? new \DateTimeImmutable();
                $this->keepFailed((int) $device['id'], $uuid, (int) $user['id'], $soldAt, (string) json_encode($sale, JSON_UNESCAPED_UNICODE), $error);
                return ['client_uuid' => $uuid, 'status' => 'failed', 'error' => $error];
            }
        }
    }

    private function book(array $device, array $user, array $sale, ?string $ip, array $notes): array
    {
        $uuid = strtolower(trim((string) ($sale['client_uuid'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => 'client_uuid ต้องเป็น UUID'];
        }
        $offline = !empty($sale['offline']);
        $deviceId = (int) $device['id'];

        $done = $this->booked($deviceId, $uuid);
        if ($done) {
            return $done;
        }

        $settings = new SettingsService($this->db);
        $warnings = $notes;
        $now = new \DateTimeImmutable();
        $soldAt = $this->soldAt($sale['sold_at'] ?? null) ?? $now;
        if ($soldAt->getTimestamp() > $now->getTimestamp() + self::FUTURE_SLACK) {
            $warnings[] = 'เวลาขายบนอุปกรณ์ล้ำหน้าเวลาเซิร์ฟเวอร์ (' . $soldAt->format('Y-m-d H:i:s') . ') บันทึกเป็นเวลาที่ได้รับแทน';
            $soldAt = $now;
        }
        $day = $settings->businessDay($soldAt);
        $role = $user['role'] ?? null;

        $items = [];
        foreach (is_array($sale['items'] ?? null) ? $sale['items'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $item = ['product_id' => (int) ($line['product_id'] ?? 0), 'qty' => (int) ($line['qty'] ?? 0)];
            if ((int) ($line['pack_size'] ?? 1) > 1) {
                $item['pack_size'] = (int) $line['pack_size'];
            }
            // Offline the customer paid the price the device knew; online the server's price stands.
            if ($offline && isset($line['unit_price']) && is_numeric($line['unit_price']) && empty($item['pack_size'])) {
                $item['unit_price'] = (float) $line['unit_price'];
                $current = $this->db->prepare('SELECT name, sale_price FROM products WHERE id = ?');
                $current->execute([$item['product_id']]);
                $p = $current->fetch();
                if ($p && abs((float) $p['sale_price'] - $item['unit_price']) > 0.004) {
                    $warnings[] = "{$p['name']} ขายราคา ฿" . number_format($item['unit_price'], 2) . ' (ราคาปัจจุบัน ฿' . number_format((float) $p['sale_price'], 2) . ')';
                }
            }
            $items[] = $item;
        }
        $lines = is_array($sale['payments'] ?? null) ? $sale['payments'] : [];
        $methods = $settings->paymentMethods();
        if ($offline) {
            foreach ($lines as $p) {
                $m = (string) ($p['method'] ?? '');
                if (isset(SettingsService::PAYMENT_METHODS[$m]) && !in_array($m, $methods, true)) {
                    $warnings[] = 'ชำระด้วย' . SettingsService::PAYMENT_METHODS[$m] . ' ซึ่งปัจจุบันร้านปิดรับแล้ว';
                }
            }
        }

        // Online the drawer must be counted first, as on the web POS. Offline the sale already happened.
        $audit = $this->audit;
        if (!$offline) {
            $required = !($day['after_hours'] && $day['date'] !== date('Y-m-d'));
            try {
                $maySell = !$required || (new CashDrawerService($this->db, $audit))->canSell($day['date'], (int) $user['id']);
            } catch (\PDOException $e) {
                $maySell = true; // migration 0033 not run yet
            }
            if (!$maySell) {
                return ['client_uuid' => $uuid, 'status' => 'drawer_required', 'error' => 'กรุณาระบุจำนวนเงินในลิ้นชักก่อนทำการขาย'];
            }
        }

        $service = new SaleService(
            $this->db,
            new ProductRepo($this->db, $audit),
            new LedgerService($this->db),
            new DocumentNumberService($this->db),
            new ListingFeeService($this->db, $settings),
            $audit
        );
        $payload = json_encode($sale, JSON_UNESCAPED_UNICODE);
        $this->db->beginTransaction();
        try {
            // Another request for the same sale got here first: wait for it, then report it.
            $lock = $this->db->prepare('SELECT id FROM pos_device_sales WHERE device_id = ? AND client_uuid = ? FOR UPDATE');
            $lock->execute([$deviceId, $uuid]);
            $rowId = $lock->fetchColumn();
            if ($rowId !== false) {
                $again = $this->booked($deviceId, $uuid);
                if ($again) {
                    $this->db->commit();
                    return $again;
                }
            }
            if (!$offline || $settings->get('pos_short_stock_always') === '1') {
                $allowShort = $settings->sellShortStock($role, !empty($sale['allow_short']));
            } else {
                $allowShort = true;
            }
            if ($offline) {
                foreach ($items as $it) {
                    $stock = $this->db->prepare('SELECT name, qty_stock FROM products WHERE id = ?');
                    $stock->execute([$it['product_id']]);
                    $p = $stock->fetch();
                    if ($p && (int) $p['qty_stock'] < $it['qty']) {
                        $warnings[] = "{$p['name']} คงเหลือในระบบ {$p['qty_stock']} ชิ้น ขายไป {$it['qty']} ชิ้น (สต๊อกติดลบ ควรตรวจนับ)";
                    }
                }
            }
            $result = $service->record($items, (int) $user['id'], $ip, [
                'sale_datetime' => $soldAt->format('Y-m-d H:i:s'),
                'business_date' => $day['date'],
                'after_hours' => $day['after_hours'],
                'payments' => [
                    'methods' => $offline ? array_keys(SettingsService::PAYMENT_METHODS) : $methods,
                    'mixed' => $offline || $settings->posRoleAllows('pos_mixed_roles', $role),
                    'lines' => $lines,
                ],
                'allow_short_stock' => $allowShort,
                'note' => $offline ? 'ขายออฟไลน์จากอุปกรณ์ ' . $device['name'] : null,
            ]);
            if (!empty($result['payment_warning'])) {
                $warnings[] = $result['payment_warning'];
            }
            if (isset($sale['total_value']) && is_numeric($sale['total_value']) && abs((float) $sale['total_value'] - $result['total_value']) > 0.004) {
                $warnings[] = 'ยอดขายบนอุปกรณ์ ฿' . number_format((float) $sale['total_value'], 2) . ' ไม่ตรงกับที่บันทึก ฿' . number_format($result['total_value'], 2);
            }
            if ($offline) {
                $warnings = array_merge($warnings, $this->lateDayWarnings($day['date']));
                (new CashDrawerService($this->db, $audit))->refreshClose($day['date']);
            }
            $fields = [
                'synced', $result['id'], (int) $user['id'], $soldAt->format('Y-m-d H:i:s'), (int) $offline, $result['total_value'],
                $payload, $warnings ? implode("\n", $warnings) : null,
            ];
            if ($rowId !== false) {
                $this->db->prepare(
                    'UPDATE pos_device_sales SET status = ?, sale_id = ?, user_id = ?, sold_at = ?, offline = ?, total_value = ?, payload = ?,
                        warnings = ?, error = NULL, attempts = attempts + 1, synced_at = NOW() WHERE id = ?'
                )->execute([...$fields, $rowId]);
            } else {
                $this->db->prepare(
                    'INSERT INTO pos_device_sales (status, sale_id, user_id, sold_at, offline, total_value, payload, warnings, device_id, client_uuid, received_at, synced_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                )->execute([...$fields, $deviceId, $uuid]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof \PDOException && ($e->errorInfo[1] ?? null) === 1062) {
                // Booked by a request running alongside this one; otherwise a document number
                // taken at the same moment by another till: process() tries again.
                $done = $this->booked($deviceId, $uuid);
                if ($done) {
                    return $done;
                }
                throw $e;
            }
            if (!$e instanceof \RuntimeException || $e instanceof \PDOException) {
                throw $e;
            }
            if (!$offline) {
                return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => $e->getMessage()];
            }
            $this->keepFailed($deviceId, $uuid, (int) $user['id'], $soldAt, $payload, $e->getMessage());
            return ['client_uuid' => $uuid, 'status' => 'failed', 'error' => $e->getMessage()];
        }
        $this->db->prepare('UPDATE pos_devices SET last_sync_at = NOW() WHERE id = ?')->execute([$deviceId]);
        return [
            'client_uuid' => $uuid, 'status' => 'synced', 'warnings' => $warnings,
            'sale' => array_intersect_key($result, array_flip(['id', 'doc_no', 'total_qty', 'total_value', 'change', 'business_date', 'after_hours'])),
        ];
    }

    /**
     * A sale whose seller cannot sell (taken off the device, account closed): online it goes
     * back to the device; one made offline already happened, so it is kept for an admin.
     */
    public function fail(array $device, int $userId, array $sale, string $error): array
    {
        $uuid = strtolower(trim((string) ($sale['client_uuid'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => 'client_uuid ต้องเป็น UUID'];
        }
        $done = $this->booked((int) $device['id'], $uuid);
        if ($done) {
            return $done;
        }
        if (empty($sale['offline'])) {
            return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => $error];
        }
        $soldAt = $this->soldAt($sale['sold_at'] ?? null) ?? new \DateTimeImmutable();
        $this->keepFailed((int) $device['id'], $uuid, $userId, $soldAt, (string) json_encode($sale, JSON_UNESCAPED_UNICODE), $error);
        return ['client_uuid' => $uuid, 'status' => 'failed', 'error' => $error];
    }

    /** Admin: books a failed sale again, as the seller and device that made it. */
    public function retry(int $id, ?string $ip): array
    {
        $row = $this->row($id);
        if (!$row || $row['status'] !== 'failed') {
            throw new \RuntimeException('ไม่พบรายการที่บันทึกไม่สำเร็จ');
        }
        $stmt = $this->db->prepare('SELECT * FROM pos_devices WHERE id = ?');
        $stmt->execute([$row['device_id']]);
        $device = $stmt->fetch();
        $seller = (new PosDeviceService($this->db, $this->audit))->seller($device, (int) $row['user_id'], true);
        if (isset($seller['error'])) {
            throw new \RuntimeException($seller['error']);
        }
        $sale = (array) json_decode((string) $row['payload'], true);
        $sale['offline'] = true; // it was made long ago: book it as it was made
        return $this->process($device, $seller['user'], $sale, $ip);
    }

    /** Admin: a failed sale settled another way (recorded by hand, or not a real sale). */
    public function resolve(int $id, string $note, int $adminId, ?string $ip): void
    {
        $row = $this->row($id);
        if (!$row || $row['status'] !== 'failed') {
            throw new \RuntimeException('ไม่พบรายการที่บันทึกไม่สำเร็จ');
        }
        $note = trim($note);
        if ($note === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผล');
        }
        $this->db->prepare("UPDATE pos_device_sales SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), resolve_note = ? WHERE id = ?")
            ->execute([$adminId, mb_substr($note, 0, 500), $id]);
        $this->audit->log('pos_device_sales', $id, 'update', ['status' => 'failed'], ['status' => 'resolved', 'note' => $note], $adminId, $ip);
    }

    /** Sales sent by devices: failed ones (all), or the latest of a status. */
    public function list(string $status, ?int $deviceId, int $limit = 200): array
    {
        $where = ['s.status = ?'];
        $params = [$status];
        if ($deviceId) {
            $where[] = 's.device_id = ?';
            $params[] = $deviceId;
        }
        $stmt = $this->db->prepare(
            'SELECT s.id, s.device_id, s.client_uuid, s.status, s.sale_id, s.sold_at, s.offline, s.total_value, s.payload, s.error, s.warnings,
                    s.attempts, s.received_at, s.synced_at, s.resolved_at, s.resolve_note,
                    d.name AS device_name, u.full_name AS user_name, sa.doc_no, r.full_name AS resolved_by_name
             FROM pos_device_sales s
             JOIN pos_devices d ON d.id = s.device_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN sales sa ON sa.id = s.sale_id
             LEFT JOIN users r ON r.id = s.resolved_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute($params);
        return array_map(function ($r) {
            $r['payload'] = json_decode((string) $r['payload'], true);
            $r['warnings'] = $r['warnings'] ? explode("\n", $r['warnings']) : [];
            return $r;
        }, $stmt->fetchAll());
    }

    /** A sale of this device already booked (or settled by an admin), as the device should hear it. */
    private function booked(int $deviceId, string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.status, s.error, s.warnings, s.sale_id, sa.doc_no, sa.total_qty, sa.total_value, sa.change_amount, sa.business_date, sa.after_hours
             FROM pos_device_sales s LEFT JOIN sales sa ON sa.id = s.sale_id WHERE s.device_id = ? AND s.client_uuid = ?'
        );
        $stmt->execute([$deviceId, $uuid]);
        $r = $stmt->fetch();
        if (!$r || $r['status'] === 'failed') {
            return null;
        }
        return [
            'client_uuid' => $uuid, 'status' => 'duplicate', 'warnings' => $r['warnings'] ? explode("\n", $r['warnings']) : [],
            'sale' => $r['sale_id'] ? [
                'id' => (int) $r['sale_id'], 'doc_no' => $r['doc_no'], 'total_qty' => (int) $r['total_qty'], 'total_value' => (float) $r['total_value'],
                'change' => (float) $r['change_amount'], 'business_date' => $r['business_date'], 'after_hours' => (bool) $r['after_hours'],
            ] : null,
        ] + ($r['status'] === 'resolved' ? ['error' => 'ผู้ดูแลระบบจัดการรายการนี้แล้ว'] : []);
    }

    private function keepFailed(int $deviceId, string $uuid, int $userId, \DateTimeImmutable $soldAt, string $payload, string $error): void
    {
        $this->db->prepare(
            "INSERT INTO pos_device_sales (device_id, client_uuid, status, user_id, sold_at, offline, payload, error, received_at)
             VALUES (?, ?, 'failed', ?, ?, 1, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE error = VALUES(error), payload = VALUES(payload), attempts = attempts + 1"
        )->execute([$deviceId, $uuid, $userId, $soldAt->format('Y-m-d H:i:s'), $payload, mb_substr($error, 0, 500)]);
    }

    /** A day already counted at closing or summed up for the college: the late sale changes its figures. */
    private function lateDayWarnings(string $date): array
    {
        $out = [];
        try {
            $closed = $this->db->prepare('SELECT closed_at FROM cash_drawers WHERE business_date = ?');
            $closed->execute([$date]);
            if ($closed->fetchColumn()) {
                $out[] = "ลิ้นชักของวันที่ {$date} ปิดยอดไปแล้ว ยอดเงินที่ควรมีถูกปรับตามรายการนี้";
            }
            $closing = $this->db->prepare('SELECT 1 FROM daily_closings WHERE business_date = ? LIMIT 1');
            $closing->execute([$date]);
            if ($closing->fetchColumn()) {
                $out[] = "วันที่ {$date} สรุปบัญชีรายวันไปแล้ว ควรตรวจสอบสรุปบัญชีของวันนั้นอีกครั้ง";
            }
        } catch (\PDOException $e) {
            // Drawer / closing tables not there yet (migrations 0033, 0051).
        }
        return $out;
    }

    private function row(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pos_device_sales WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function soldAt(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            // Into the app's time zone: the device may send UTC or its own offset.
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception $e) {
            return null;
        }
    }
}
