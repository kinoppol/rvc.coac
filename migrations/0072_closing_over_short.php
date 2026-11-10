<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // What the bank says reached each receiving account, recorded before the day is summed up.
        // Every change is a row: the latest one per method counts, amount NULL = taken back.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS closing_account_reports (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                business_date DATE NOT NULL,
                method VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_account_report_date (business_date, method)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // Cash / transfers over (+) or short (−) of what they should be, kept per day for statistics,
        // with the closer's explanation.
        $cols = fn (string $c) => (bool) $db->query("SHOW COLUMNS FROM daily_closings LIKE '{$c}'")->fetch();
        if (!$cols('cash_over_short')) {
            $db->exec('ALTER TABLE daily_closings ADD COLUMN cash_over_short DECIMAL(12,2) NULL AFTER cash_sent');
        }
        if (!$cols('transfer_over_short')) {
            $db->exec('ALTER TABLE daily_closings ADD COLUMN transfer_over_short DECIMAL(12,2) NULL AFTER cash_over_short');
        }
        if (!$cols('over_short_note')) {
            $db->exec('ALTER TABLE daily_closings ADD COLUMN over_short_note VARCHAR(500) NULL AFTER transfer_over_short');
        }

        // Days summed up before: the figures from the snapshot taken at the time.
        $rows = $db->query('SELECT id, snapshot, actual_accounts FROM daily_closings WHERE cash_over_short IS NULL AND transfer_over_short IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        $upd = $db->prepare('UPDATE daily_closings SET cash_over_short = ?, transfer_over_short = ? WHERE id = ?');
        foreach ($rows as $r) {
            $snap = json_decode((string) $r['snapshot'], true) ?: [];
            $dr = $snap['drawer'] ?? null;
            $cash = $dr && isset($dr['counted_cash'], $dr['should_have']) ? round((float) $dr['counted_cash'] - (float) $dr['should_have'], 2) : null;
            $transfer = null;
            $actual = json_decode((string) $r['actual_accounts'], true);
            if (is_array($actual) && $actual) {
                $expected = array_column($snap['methods'] ?? [], 'amount', 'method');
                $transfer = 0.0;
                foreach ($actual as $method => $got) {
                    $transfer += (float) $got - (float) ($expected[$method] ?? 0);
                }
                $transfer = round($transfer, 2);
            }
            $upd->execute([$cash, $transfer, $r['id']]);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS closing_account_reports');
        foreach (['over_short_note', 'transfer_over_short', 'cash_over_short'] as $c) {
            if ($db->query("SHOW COLUMNS FROM daily_closings LIKE '{$c}'")->fetch()) {
                $db->exec("ALTER TABLE daily_closings DROP COLUMN {$c}");
            }
        }
    }
};
