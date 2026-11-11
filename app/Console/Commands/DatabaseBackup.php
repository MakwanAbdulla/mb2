<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DatabaseBackup extends Command
{
    protected $signature = 'db:backup {--keep=30 : Antal dagar att behålla backuper} {--commit= : Git commit-hash att inkludera i filnamnet}';

    protected $description = 'Tar en backup av databasen till storage/backups/';

    public function handle(): int
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $backupDir = storage_path('backups');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $commit = $this->option('commit') ? '_'.substr($this->option('commit'), 0, 7) : '';
        $filename = 'database_'.Carbon::now()->format('Y-m-d_H-i').$commit.'.sql';
        $path = $backupDir.'/'.$filename;

        match ($driver) {
            'mysql'  => $this->backupMysql($path),
            'sqlite' => $this->backupSqlite($path),
            default  => throw new \RuntimeException("Backuper stöds inte för driver: {$driver}"),
        };

        $this->info("Backup sparad: {$filename}");

        $this->rensaGamlaBackuper($backupDir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    private function backupMysql(string $path): void
    {
        $connection = config('database.default');
        $host       = config("database.connections.{$connection}.host");
        $port       = config("database.connections.{$connection}.port", 3306);
        $database   = config("database.connections.{$connection}.database");
        $username   = config("database.connections.{$connection}.username");
        $password   = config("database.connections.{$connection}.password");

        $pdo = new \PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $handle = fopen($path, 'w');
        if (! $handle) {
            throw new \RuntimeException("Kunde inte öppna fil för skrivning: {$path}");
        }

        try {
            fwrite($handle, "-- Membrum2 databas-backup\n");
            fwrite($handle, '-- Skapad: '.now()->toDateTimeString()."\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // Struktur
                $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                fwrite($handle, "\nDROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $create['Create Table'].";\n\n");

                // Data
                $rows    = $pdo->query("SELECT * FROM `{$table}`");
                $columns = array_column(
                    $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC),
                    'Field'
                );
                $columnList = '`'.implode('`, `', $columns).'`';

                while ($row = $rows->fetch(\PDO::FETCH_NUM)) {
                    $values = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                    fwrite($handle, "INSERT INTO `{$table}` ({$columnList}) VALUES (".implode(', ', $values).");\n");
                }
            }

            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    private function backupSqlite(string $path): void
    {
        $connection = config('database.default');
        $database   = config("database.connections.{$connection}.database");

        if (! copy($database, $path)) {
            throw new \RuntimeException('Kunde inte kopiera SQLite-databasen');
        }
    }

    private function rensaGamlaBackuper(string $dir, int $keepDays): void
    {
        $cutoff  = Carbon::now()->subDays($keepDays)->timestamp;
        $raderade = 0;

        foreach (glob($dir.'/database_*.sql') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $raderade++;
            }
        }

        if ($raderade > 0) {
            $this->info("Raderade {$raderade} gamla backup(er) äldre än {$keepDays} dagar.");
        }
    }
}
