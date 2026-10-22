<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Generates document numbers as {TYPE}-{YYMMDD in Buddhist Era}-{seq},
 * sequence resetting daily per type, e.g. RC-690906-001.
 */
class DocumentNumberService
{
    public function __construct(private PDO $db)
    {
    }

    public function next(string $type, string $table, string $column = 'doc_no', ?\DateTimeInterface $date = null): string
    {
        $now = $date ?? new \DateTimeImmutable('now');
        $beYear = ((int) $now->format('Y')) + 543;
        $datePart = substr((string) $beYear, -2) . $now->format('md');
        $prefix = $type . '-' . $datePart . '-';

        $stmt = $this->db->prepare(
            "SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();

        $seq = 1;
        if ($last) {
            $seq = (int) substr((string) $last, strrpos((string) $last, '-') + 1) + 1;
        }

        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
