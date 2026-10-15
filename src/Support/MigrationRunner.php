<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Minimal, dependency-free migration runner.
 *
 * Each file in /migrations is named "NNNN_description.php" and returns an
 * object with up(PDO $db) and down(PDO $db) methods. Applied migrations are
 * tracked in the `migrations` table so re-running is always safe: only
 * migrations not yet recorded are applied.
 */
class MigrationRunner
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/migrations';
    }

    public function ensureMigrationsTable(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(191) NOT NULL UNIQUE,
                batch INT UNSIGNED NOT NULL,
                run_at DATETIME NOT NULL,
                KEY idx_batch (batch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    /** @return string[] sorted migration file basenames (without .php) */
    public function allMigrationNames(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $files = glob($this->dir . '/*.php') ?: [];
        $names = array_map(fn ($f) => basename($f, '.php'), $files);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return string[] */
    public function appliedNames(PDO $db): array
    {
        $this->ensureMigrationsTable($db);
        $stmt = $db->query('SELECT migration FROM migrations ORDER BY id ASC');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return string[] */
    public function pendingNames(PDO $db): array
    {
        $applied = array_flip($this->appliedNames($db));
        return array_values(array_filter(
            $this->allMigrationNames(),
            fn ($n) => !isset($applied[$n])
        ));
    }

    /**
     * Full status list: [ ['name' => ..., 'applied' => bool, 'batch' => ?int, 'run_at' => ?string], ... ]
     */
    public function status(PDO $db): array
    {
        $this->ensureMigrationsTable($db);
        $rows = $db->query('SELECT migration, batch, run_at FROM migrations')->fetchAll();
        $applied = [];
        foreach ($rows as $r) {
            $applied[$r['migration']] = $r;
        }
        $out = [];
        foreach ($this->allMigrationNames() as $name) {
            if (isset($applied[$name])) {
                $out[] = [
                    'name' => $name,
                    'applied' => true,
                    'batch' => (int) $applied[$name]['batch'],
                    'run_at' => $applied[$name]['run_at'],
                ];
            } else {
                $out[] = ['name' => $name, 'applied' => false, 'batch' => null, 'run_at' => null];
            }
        }
        return $out;
    }

    private function load(string $name): object
    {
        $path = $this->dir . '/' . $name . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("ไม่พบไฟล์ migration: $name");
        }
        $migration = require $path;
        if (!is_object($migration) || !method_exists($migration, 'up') || !method_exists($migration, 'down')) {
            throw new \RuntimeException("ไฟล์ migration ไม่ถูกต้อง: $name");
        }
        return $migration;
    }

    private function nextBatch(PDO $db): int
    {
        $this->ensureMigrationsTable($db);
        $max = (int) $db->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();
        return $max + 1;
    }

    /**
     * Runs every pending migration in one new batch.
     * @return string[] names of migrations that were applied
     */
    public function runPending(PDO $db): array
    {
        $this->ensureMigrationsTable($db);
        $pending = $this->pendingNames($db);
        if (empty($pending)) {
            return [];
        }
        $batch = $this->nextBatch($db);
        $ran = [];
        foreach ($pending as $name) {
            $migration = $this->load($name);
            try {
                // Migrations are typically DDL (CREATE TABLE...), which causes
                // an implicit commit in MariaDB/MySQL, so they are NOT wrapped
                // in an explicit PDO transaction here (that would just throw
                // "no active transaction" on the later commit()).
                $migration->up($db);
                $stmt = $db->prepare('INSERT INTO migrations (migration, batch, run_at) VALUES (?, ?, NOW())');
                $stmt->execute([$name, $batch]);
                $ran[] = $name;
            } catch (\Throwable $e) {
                throw new \RuntimeException("Migration ล้มเหลว: $name — " . $e->getMessage(), 0, $e);
            }
        }
        return $ran;
    }

    /**
     * Rolls back every migration in the most recent batch.
     * @return string[] names of migrations that were rolled back
     */
    public function rollbackLastBatch(PDO $db): array
    {
        $this->ensureMigrationsTable($db);
        $lastBatch = (int) $db->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();
        if ($lastBatch === 0) {
            return [];
        }
        $stmt = $db->prepare('SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC');
        $stmt->execute([$lastBatch]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $rolled = [];
        foreach ($names as $name) {
            $migration = $this->load($name);
            try {
                $migration->down($db);
                $del = $db->prepare('DELETE FROM migrations WHERE migration = ?');
                $del->execute([$name]);
                $rolled[] = $name;
            } catch (\Throwable $e) {
                throw new \RuntimeException("Rollback ล้มเหลว: $name — " . $e->getMessage(), 0, $e);
            }
        }
        return $rolled;
    }
}
