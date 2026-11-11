<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Database dump + uploaded files, zipped into storage/app/backups.
 * Schedule runs it nightly. Restore steps are in docs/OPERATIONS.md.
 */
class BackupCommand extends Command
{
    protected $signature = 'finox:backup {--keep=14 : Number of backups to keep} {--mysqldump= : Path to mysqldump}';

    protected $description = 'Back up the database and uploaded documents';

    public function handle(): int
    {
        $dir = storage_path('app/backups');
        @mkdir($dir, 0775, true);
        $stamp = now()->format('Ymd-His');
        $sql = "{$dir}/db-{$stamp}.sql";
        $zipPath = "{$dir}/finox-backup-{$stamp}.zip";

        $db = config('database.connections.'.config('database.default'));
        if (($db['driver'] ?? '') !== 'mysql') {
            $this->error('Backups are implemented for MySQL only.');

            return self::FAILURE;
        }

        $bin = $this->option('mysqldump') ?: config('finox.mysqldump_path');
        $cmd = [$bin, '--single-transaction', '--routines', '--default-character-set=utf8mb4', "--host={$db['host']}", "--port={$db['port']}",
            "--user={$db['username']}", '--result-file='.$sql, $db['database']];
        $env = $db['password'] ? ['MYSQL_PWD' => $db['password']] : [];
        $p = new Process($cmd, null, $env, null, 600);
        $p->run();
        if (! $p->isSuccessful() || ! is_file($sql)) {
            $this->error('mysqldump failed: '.trim($p->getErrorOutput()).' (set MYSQLDUMP_PATH in .env if it is not on PATH)');

            return self::FAILURE;
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Could not create '.$zipPath);
            @unlink($sql);

            return self::FAILURE;
        }
        $zip->addFile($sql, 'database.sql');
        $base = storage_path('app/private');
        if (is_dir($base)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && ! str_contains($f->getPathname(), DIRECTORY_SEPARATOR.'seed'.DIRECTORY_SEPARATOR)) {
                    $zip->addFile($f->getPathname(), 'files/'.ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/'));
                }
            }
        }
        if (! $zip->close() || ! is_file($zipPath)) {
            $this->error('Writing the backup zip failed; the SQL dump was kept at '.$sql);

            return self::FAILURE;
        }
        @unlink($sql);

        $old = glob("{$dir}/finox-backup-*.zip");
        rsort($old);
        foreach (array_slice($old, max(1, (int) $this->option('keep'))) as $f) {
            @unlink($f);
        }

        $this->info('Backup written: '.$zipPath.' ('.round(filesize($zipPath) / 1048576, 2).' MB)');

        return self::SUCCESS;
    }
}
