<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Before the fix, a drawer counted after closing time (or on a day off) was booked to the
        // next opening day: that day showed the previous evening's opening and closing counts and
        // its staff could no longer count it. Move such a drawer (and its open / close log) back to
        // the day it was counted on, when that day has no drawer of its own.
        $rows = $db->query(
            'SELECT d.* FROM cash_drawers d
             WHERE DATE(d.opened_at) < d.business_date
               AND NOT EXISTS (SELECT 1 FROM cash_drawers o WHERE o.business_date = DATE(d.opened_at))'
        )->fetchAll(PDO::FETCH_ASSOC);

        $cash = $db->prepare(
            "SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
             WHERE s.business_date = ? AND sp.method = 'cash'"
        );
        foreach ($rows as $d) {
            $from = $d['business_date'];
            $to = substr((string) $d['opened_at'], 0, 10);
            $cash->execute([$to]);
            $expected = round((float) $d['opening_cash'] + (float) $cash->fetchColumn(), 2);
            $closed = $d['closed_at'] !== null;
            // The opening cash stays as entered; it was not taken off sales of the day it moves to.
            $db->prepare(
                'UPDATE cash_drawers SET business_date = ?, late_open = 0, cash_sales_before_open = 0,
                        expected_cash = ?, cash_diff = ? WHERE id = ?'
            )->execute([$to, $closed ? $expected : $d['expected_cash'],
                $closed ? round((float) $d['counted_cash'] - $expected, 2) : $d['cash_diff'], $d['id']]);
            $db->prepare(
                "UPDATE cash_drawer_events SET business_date = ?
                 WHERE business_date = ? AND event IN ('open', 'open_late', 'close', 'recount', 'adjust_open') AND DATE(created_at) = ?"
            )->execute([$to, $from, $to]);
            $db->prepare(
                'INSERT INTO cash_drawer_events (business_date, user_id, subject_user_id, event, amount, detail, created_at)
                 VALUES (?, ?, NULL, ?, ?, ?, NOW())'
            )->execute([$to, $d['opened_by'], 'adjust_open', $d['opening_cash'],
                "ย้ายเงินในลิ้นชักที่นับวันที่ {$to} (หลังเวลาปิดร้าน) ซึ่งเคยบันทึกเป็นของวันที่ {$from} กลับมาเป็นของวันที่ {$to}"]);
        }
    }

    public function down(PDO $db): void
    {
    }
};
