<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Db;
use PDO;

/**
 * Suppliers (ร้าน/ผู้ขาย). Each purchase keeps the supplier name as typed; the same supplier
 * typed several ways is brought under one main name (suppliers) whose other spellings are its
 * aliases (supplier_aliases, ชื่อเล่น). Anything that compares or groups suppliers goes through
 * the main name. Names never put under a main name stand on their own. Migration 0074.
 */
class SupplierService
{
    /** @var array<string,string>|null lower-cased alias / main name => main name */
    private ?array $map = null;

    public function __construct(private PDO $db, private ?AuditLogger $audit = null)
    {
    }

    /** Migration 0074 has been run. */
    public function ready(): bool
    {
        return Db::hasColumn($this->db, 'supplier_aliases', 'alias');
    }

    /** Trimmed, inner spaces collapsed. */
    public static function norm(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private static function key(string $name): string
    {
        return mb_strtolower(self::norm($name));
    }

    /**
     * SQL pieces that put a purchase's supplier under its main name:
     * [join, main-name expression, tax-id expression] for a column holding the typed name.
     */
    public function sql(string $column, string $taxColumn): array
    {
        if (!$this->ready()) {
            return ['', $column, $taxColumn];
        }
        return [
            "LEFT JOIN supplier_aliases sup_a ON sup_a.alias = {$column} LEFT JOIN suppliers sup ON sup.id = sup_a.supplier_id",
            "COALESCE(sup.name, {$column})",
            "COALESCE(NULLIF(sup.tax_id, ''), NULLIF({$taxColumn}, ''), (SELECT NULLIF(s2.tax_id, '') FROM suppliers s2 WHERE s2.name = {$column}))",
        ];
    }

    /** The main name a typed supplier name belongs under (itself when it has none). */
    public function mainName(string $name): string
    {
        if ($this->map === null) {
            $this->map = [];
            if ($this->ready()) {
                foreach ($this->db->query('SELECT s.name, a.alias FROM suppliers s LEFT JOIN supplier_aliases a ON a.supplier_id = s.id') as $r) {
                    $this->map[self::key((string) $r['name'])] = (string) $r['name'];
                    if ($r['alias'] !== null) {
                        $this->map[self::key((string) $r['alias'])] = (string) $r['name'];
                    }
                }
            }
        }
        return $this->map[self::key($name)] ?? self::norm($name);
    }

    /** Every name the supplier goes by: its main name and its aliases. */
    public function namesOf(string $name): array
    {
        $main = $this->mainName($name);
        $names = [$main];
        if ($this->ready()) {
            $stmt = $this->db->prepare('SELECT a.alias FROM supplier_aliases a JOIN suppliers s ON s.id = a.supplier_id WHERE s.name = ?');
            $stmt->execute([$main]);
            array_push($names, ...$stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        return array_values(array_unique($names));
    }

    /**
     * All suppliers: main names (with their aliases) and names typed on purchases that stand on
     * their own, with how often and how recently they were bought from (cancelled purchases left out).
     * @return array<int,array{id:?int,name:string,tax_id:string,aliases:array,docs:int,last_date:?string,amount:float}>
     */
    public function all(): array
    {
        $entries = [];
        $byKey = []; // lower-cased main name or alias => [entry key, alias index | null]
        if ($this->ready()) {
            foreach ($this->db->query('SELECT id, name, tax_id FROM suppliers ORDER BY name') as $s) {
                $k = self::key((string) $s['name']);
                $entries[$k] = ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'tax_id' => (string) ($s['tax_id'] ?? ''),
                    'aliases' => [], 'docs' => 0, 'last_date' => null, 'amount' => 0.0];
                $byKey[$k] = [$k, null];
            }
            foreach ($this->db->query('SELECT a.id, a.alias, s.name FROM supplier_aliases a JOIN suppliers s ON s.id = a.supplier_id ORDER BY a.alias') as $a) {
                $k = self::key((string) $a['name']);
                $entries[$k]['aliases'][] = ['id' => (int) $a['id'], 'alias' => (string) $a['alias'], 'docs' => 0, 'last_date' => null];
                $byKey[self::key((string) $a['alias'])] = [$k, count($entries[$k]['aliases']) - 1];
            }
        }
        $docs = $this->db->query(
            "SELECT supplier_name, MAX(supplier_tax_id) AS tax_id, COUNT(*) AS docs,
                    MAX(COALESCE(receipt_date, DATE(created_at))) AS last_date, SUM(total) AS amount
             FROM purchase_documents WHERE status <> 'cancelled' GROUP BY supplier_name"
        )->fetchAll();
        foreach ($docs as $d) {
            $name = self::norm((string) $d['supplier_name']);
            if ($name === '') {
                continue;
            }
            $k = self::key($name);
            if (!isset($byKey[$k])) {
                $entries[$k] = ['id' => null, 'name' => $name, 'tax_id' => '', 'aliases' => [], 'docs' => 0, 'last_date' => null, 'amount' => 0.0];
                $byKey[$k] = [$k, null];
            }
            [$ek, $ai] = $byKey[$k];
            $e = &$entries[$ek];
            $e['docs'] += (int) $d['docs'];
            $e['amount'] = round($e['amount'] + (float) $d['amount'], 2);
            $e['last_date'] = max((string) $e['last_date'], (string) $d['last_date']) ?: null;
            if ($e['tax_id'] === '' && trim((string) $d['tax_id']) !== '') {
                $e['tax_id'] = trim((string) $d['tax_id']);
            }
            if ($ai !== null) {
                $e['aliases'][$ai]['docs'] += (int) $d['docs'];
                $e['aliases'][$ai]['last_date'] = max((string) $e['aliases'][$ai]['last_date'], (string) $d['last_date']) ?: null;
            }
            unset($e);
        }
        return array_values($entries);
    }

    /**
     * Puts several supplier names under one main name: the others (and their own aliases) become
     * its aliases. Purchases keep the name they were typed with.
     */
    public function merge(string $main, array $names, int $userId, ?string $ip): array
    {
        $this->requireReady();
        $main = self::norm($main);
        if ($main === '') {
            throw new \RuntimeException('ระบุชื่อหลักของผู้ขาย');
        }
        $others = [];
        foreach ($names as $n) {
            $n = self::norm((string) $n);
            if ($n !== '' && self::key($n) !== self::key($main)) {
                $others[self::key($n)] = $n;
            }
        }
        if (!$others) {
            throw new \RuntimeException('เลือกชื่อผู้ขายอย่างน้อย 2 ชื่อเพื่อรวมกัน');
        }
        $this->db->beginTransaction();
        try {
            $id = $this->ensure($main, $userId);
            $moved = [];
            foreach ($others as $n) {
                $other = $this->supplierByName($n);
                if ($other && (int) $other['id'] !== $id) {
                    // Another main name: its aliases come along, its tax id fills a blank one.
                    $this->db->prepare('UPDATE supplier_aliases SET supplier_id = ? WHERE supplier_id = ?')->execute([$id, $other['id']]);
                    if (trim((string) $other['tax_id']) !== '') {
                        $this->db->prepare("UPDATE suppliers SET tax_id = ? WHERE id = ? AND (tax_id IS NULL OR tax_id = '')")->execute([$other['tax_id'], $id]);
                    }
                    $this->db->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$other['id']]);
                }
                $this->putAlias($id, $n, $userId);
                $moved[] = $n;
            }
            $this->db->prepare('UPDATE suppliers SET updated_at = NOW() WHERE id = ?')->execute([$id]);
            $this->log($id, 'merge', ['name' => $main, 'merged' => $moved], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->map = null;
        return ['name' => $main, 'merged' => $moved];
    }

    /** Adds another name the supplier (by its main name) goes by. */
    public function addAlias(string $main, string $alias, int $userId, ?string $ip): void
    {
        $this->requireReady();
        $alias = self::norm($alias);
        if ($alias === '' || mb_strlen($alias) > 191) {
            throw new \RuntimeException('ชื่อเล่นต้องไม่ว่างและยาวไม่เกิน 191 ตัวอักษร');
        }
        if (self::key($alias) === self::key($main)) {
            throw new \RuntimeException('ชื่อเล่นซ้ำกับชื่อหลัก');
        }
        $other = $this->supplierByName($alias);
        if ($other) {
            throw new \RuntimeException("«{$alias}» เป็นชื่อหลักของผู้ขายอีกรายแล้ว — ใช้รวมรายชื่อแทน");
        }
        $owner = $this->aliasOwner($alias);
        if ($owner !== null && self::key($owner) !== self::key($main)) {
            throw new \RuntimeException("«{$alias}» เป็นชื่อเล่นของ {$owner} อยู่แล้ว");
        }
        if ($this->standsAlone($alias)) {
            throw new \RuntimeException("«{$alias}» มีใบซื้อในชื่อนี้อยู่แล้ว — ใช้รวมรายชื่อแทน");
        }
        if ($owner !== null) {
            return;
        }
        $this->db->beginTransaction();
        try {
            $id = $this->ensure($main, $userId);
            $this->putAlias($id, $alias, $userId);
            $this->log($id, 'alias_add', ['name' => $main, 'alias' => $alias], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->map = null;
    }

    /** Takes an alias off: that name stands on its own again. */
    public function removeAlias(int $aliasId, int $userId, ?string $ip): void
    {
        $this->requireReady();
        $stmt = $this->db->prepare('SELECT a.*, s.name FROM supplier_aliases a JOIN suppliers s ON s.id = a.supplier_id WHERE a.id = ?');
        $stmt->execute([$aliasId]);
        $a = $stmt->fetch();
        if (!$a) {
            throw new \RuntimeException('ไม่พบชื่อเล่นนี้');
        }
        $this->db->prepare('DELETE FROM supplier_aliases WHERE id = ?')->execute([$aliasId]);
        $this->log((int) $a['supplier_id'], 'alias_remove', ['name' => $a['name'], 'alias' => $a['alias']], $userId, $ip);
        $this->map = null;
    }

    /**
     * Changes the main name (the old one stays on as an alias, so purchases typed with it still
     * belong) and / or the tax id. An alias picked as the new main name swaps places with it.
     */
    public function update(string $main, string $newName, string $taxId, int $userId, ?string $ip): string
    {
        $this->requireReady();
        $main = self::norm($main);
        $newName = self::norm($newName);
        $taxId = trim($taxId);
        if ($newName === '' || mb_strlen($newName) > 191) {
            throw new \RuntimeException('ชื่อหลักต้องไม่ว่างและยาวไม่เกิน 191 ตัวอักษร');
        }
        if (mb_strlen($taxId) > 30) {
            throw new \RuntimeException('เลขประจำตัวผู้เสียภาษียาวเกินไป');
        }
        $this->db->beginTransaction();
        try {
            $id = $this->ensure($main, $userId);
            $current = $this->supplierByName($main);
            if (self::key($newName) !== self::key($main)) {
                $other = $this->supplierByName($newName);
                if ($other && (int) $other['id'] !== $id) {
                    throw new \RuntimeException("«{$newName}» เป็นชื่อหลักของผู้ขายอีกรายแล้ว — ใช้รวมรายชื่อแทน");
                }
                $owner = $this->aliasOwner($newName);
                if ($owner !== null && self::key($owner) !== self::key($main)) {
                    throw new \RuntimeException("«{$newName}» เป็นชื่อเล่นของ {$owner} อยู่แล้ว");
                }
                if ($owner === null && $this->standsAlone($newName)) {
                    throw new \RuntimeException("«{$newName}» มีใบซื้อในชื่อนี้อยู่แล้ว — ใช้รวมรายชื่อแทน");
                }
                $this->db->prepare('DELETE FROM supplier_aliases WHERE alias = ?')->execute([$newName]);
                $this->db->prepare('UPDATE suppliers SET name = ? WHERE id = ?')->execute([$newName, $id]);
                $this->putAlias($id, (string) $current['name'], $userId);
            }
            $this->db->prepare('UPDATE suppliers SET tax_id = ?, updated_at = NOW() WHERE id = ?')->execute([$taxId !== '' ? $taxId : null, $id]);
            $this->log($id, 'update', ['old_name' => $current['name'], 'name' => $newName, 'old_tax_id' => $current['tax_id'], 'tax_id' => $taxId], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->map = null;
        return $newName;
    }

    private function requireReady(): void
    {
        if (!$this->ready()) {
            throw new \RuntimeException('ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0074) ก่อน');
        }
    }

    private function supplierByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM suppliers WHERE name = ?');
        $stmt->execute([self::norm($name)]);
        return $stmt->fetch() ?: null;
    }

    /** The main name an alias belongs to, or null. */
    private function aliasOwner(string $alias): ?string
    {
        $stmt = $this->db->prepare('SELECT s.name FROM supplier_aliases a JOIN suppliers s ON s.id = a.supplier_id WHERE a.alias = ?');
        $stmt->execute([self::norm($alias)]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** Purchases were typed with this name and it is nobody's main name or alias yet. */
    private function standsAlone(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM purchase_documents WHERE supplier_name = ? LIMIT 1');
        $stmt->execute([self::norm($name)]);
        return $stmt->fetchColumn() !== false && !$this->supplierByName($name) && $this->aliasOwner($name) === null;
    }

    /**
     * The supplier row behind a main name, made when the name stood on its own so far. A name that
     * is an alias gets its supplier, which then takes this name as its main name.
     */
    private function ensure(string $name, int $userId): int
    {
        $name = self::norm($name);
        $s = $this->supplierByName($name);
        if ($s) {
            return (int) $s['id'];
        }
        $stmt = $this->db->prepare('SELECT supplier_id FROM supplier_aliases WHERE alias = ?');
        $stmt->execute([$name]);
        $owner = $stmt->fetchColumn();
        if ($owner !== false) {
            $stmt = $this->db->prepare('SELECT name FROM suppliers WHERE id = ?');
            $stmt->execute([$owner]);
            $old = (string) $stmt->fetchColumn();
            $this->db->prepare('DELETE FROM supplier_aliases WHERE alias = ?')->execute([$name]);
            $this->db->prepare('UPDATE suppliers SET name = ?, updated_at = NOW() WHERE id = ?')->execute([$name, $owner]);
            $this->putAlias((int) $owner, $old, $userId);
            return (int) $owner;
        }
        $tax = $this->db->prepare("SELECT supplier_tax_id FROM purchase_documents WHERE supplier_name = ? AND supplier_tax_id <> '' ORDER BY id DESC LIMIT 1");
        $tax->execute([$name]);
        $this->db->prepare('INSERT INTO suppliers (name, tax_id, created_by, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$name, ($t = $tax->fetchColumn()) !== false ? $t : null, $userId]);
        return (int) $this->db->lastInsertId();
    }

    private function putAlias(int $supplierId, string $alias, int $userId): void
    {
        $alias = self::norm($alias);
        $this->db->prepare(
            'INSERT INTO supplier_aliases (supplier_id, alias, created_by, created_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE supplier_id = VALUES(supplier_id)'
        )->execute([$supplierId, $alias, $userId]);
    }

    private function log(int $id, string $event, array $data, int $userId, ?string $ip): void
    {
        $this->audit?->log('suppliers', $id, $event, null, $data, $userId, $ip);
    }
}
