<?php

namespace App\Services;

use App\Models\DraftPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SQLite3;

class DraftPackageRebuildBackupService
{
    public function create(DraftPackage $package, array $guard): array
    {
        $package->loadMissing('artifacts');
        $root = trim((string) config(
            'legal_analysis.draft_package.maintenance_backup_root',
            'backups/draft-packages',
        ), '/');
        $relativePath = sprintf(
            '%s/package-%d-%s-%s',
            $root,
            $package->id,
            now('UTC')->format('Ymd-His'),
            Str::lower(Str::random(8)),
        );
        $backupDisk = Storage::disk('local');

        if (! $backupDisk->makeDirectory($relativePath)) {
            throw new RuntimeException('Не удалось создать защищённый каталог backup DraftPackage.');
        }

        $storageManifest = [];
        foreach ($package->artifacts->sortBy('id') as $artifact) {
            $entry = [
                'artifact_id' => $artifact->id,
                'disk' => $artifact->storage_disk ?: config('filesystems.default'),
                'path' => $artifact->storage_path,
                'exists' => false,
                'file_size' => null,
                'sha256' => null,
                'backup_path' => null,
            ];

            if (filled($artifact->storage_path)) {
                $path = $this->validatedArtifactPath((string) $artifact->storage_path);
                $filesystem = Storage::disk((string) $entry['disk']);
                if ($filesystem->exists($path)) {
                    $stream = $filesystem->readStream($path);
                    if ($stream === false) {
                        throw new RuntimeException("Не удалось прочитать Artifact file #{$artifact->id} для backup.");
                    }

                    $copyPath = $relativePath.'/files/'.$artifact->id.'-'.basename($path);
                    try {
                        if (! $backupDisk->put($copyPath, $stream)) {
                            throw new RuntimeException("Не удалось сохранить Artifact file #{$artifact->id} в backup.");
                        }
                    } finally {
                        fclose($stream);
                    }

                    $absolute = $backupDisk->path($copyPath);
                    $entry['exists'] = true;
                    $entry['file_size'] = filesize($absolute) ?: 0;
                    $entry['sha256'] = hash_file('sha256', $absolute) ?: null;
                    $entry['backup_path'] = $copyPath;
                    @chmod($absolute, 0600);
                }
            }

            $storageManifest[] = $entry;
        }

        $snapshot = [
            'schema_version' => 'draft-package-maintenance-backup-v1',
            'created_at' => now('UTC')->toISOString(),
            'guard' => $guard,
            'draft_package' => $package->getAttributes(),
            'artifacts' => $package->artifacts->sortBy('id')->map->getAttributes()->values()->all(),
            'storage_manifest' => $storageManifest,
        ];

        $snapshotPath = $relativePath.'/snapshot.json';
        $manifestPath = $relativePath.'/storage-manifest.json';
        $this->putJson($backupDisk, $snapshotPath, $snapshot);
        $this->putJson($backupDisk, $manifestPath, $storageManifest);

        $databasePath = $relativePath.'/database.sqlite';
        $this->backupSqlite($backupDisk->path($databasePath));

        foreach ([$snapshotPath, $manifestPath, $databasePath] as $path) {
            @chmod($backupDisk->path($path), 0600);
        }
        @chmod($backupDisk->path($relativePath), 0700);

        return [
            'disk' => 'local',
            'path' => $relativePath,
            'snapshot_path' => $snapshotPath,
            'database_path' => $databasePath,
            'storage_manifest_path' => $manifestPath,
            'database_sha256' => hash_file('sha256', $backupDisk->path($databasePath)),
            'database_integrity' => 'ok',
            'storage_manifest' => $storageManifest,
        ];
    }

    public function snapshot(string $relativePath): array
    {
        $relativePath = $this->validatedBackupPath($relativePath);
        $path = $relativePath.'/snapshot.json';
        $json = Storage::disk('local')->get($path);
        $snapshot = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (($snapshot['schema_version'] ?? null) !== 'draft-package-maintenance-backup-v1') {
            throw new RuntimeException('Указан несовместимый backup DraftPackage.');
        }

        return $snapshot;
    }

    public function restoreFiles(array $snapshot): void
    {
        $backupDisk = Storage::disk('local');
        foreach ($snapshot['storage_manifest'] ?? [] as $entry) {
            if (! ($entry['exists'] ?? false)) {
                continue;
            }

            $backupPath = (string) ($entry['backup_path'] ?? '');
            $targetPath = $this->validatedArtifactPath((string) ($entry['path'] ?? ''));
            $targetDisk = Storage::disk((string) ($entry['disk'] ?? 'local'));
            if (! $backupDisk->exists($backupPath)) {
                throw new RuntimeException("В backup отсутствует файл Artifact #{$entry['artifact_id']}.");
            }

            $stream = $backupDisk->readStream($backupPath);
            if ($stream === false) {
                throw new RuntimeException("Не удалось прочитать backup Artifact #{$entry['artifact_id']}.");
            }
            try {
                if (! $targetDisk->put($targetPath, $stream)) {
                    throw new RuntimeException("Не удалось восстановить Artifact file #{$entry['artifact_id']}.");
                }
            } finally {
                fclose($stream);
            }

            $restoredPath = $targetDisk->path($targetPath);
            $hash = hash_file('sha256', $restoredPath);
            if (! is_string($hash) || ! hash_equals((string) $entry['sha256'], $hash)) {
                throw new RuntimeException("Восстановленный Artifact file #{$entry['artifact_id']} не прошёл hash-check.");
            }
        }
    }

    private function backupSqlite(string $destination): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Maintenance backup поддерживает только SQLite.');
        }

        $database = (string) $connection->getConfig('database');
        if ($database === ':memory:') {
            $quoted = str_replace("'", "''", $destination);
            $connection->unprepared("VACUUM INTO '{$quoted}'");
        } else {
            $source = new SQLite3($database, SQLITE3_OPEN_READONLY);
            $target = new SQLite3($destination, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            try {
                if (! $source->backup($target)) {
                    throw new RuntimeException('SQLite online backup завершился ошибкой.');
                }
            } finally {
                $target->close();
                $source->close();
            }
        }

        $backup = new SQLite3($destination, SQLITE3_OPEN_READONLY);
        try {
            if ($backup->querySingle('PRAGMA integrity_check') !== 'ok') {
                throw new RuntimeException('SQLite backup не прошёл integrity_check.');
            }
        } finally {
            $backup->close();
        }
    }

    private function putJson(object $disk, string $path, array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (! $disk->put($path, $json)) {
            throw new RuntimeException("Не удалось сохранить backup file {$path}.");
        }
    }

    private function validatedArtifactPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains('/'.$path.'/', '/../')
            || ! str_starts_with($path, 'draft-packages/')
        ) {
            throw new RuntimeException('Artifact содержит небезопасный storage path.');
        }

        return $path;
    }

    private function validatedBackupPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        $root = trim((string) config(
            'legal_analysis.draft_package.maintenance_backup_root',
            'backups/draft-packages',
        ), '/');
        if (
            $path === ''
            || str_contains('/'.$path.'/', '/../')
            || ! str_starts_with($path.'/', $root.'/')
        ) {
            throw new RuntimeException('Указан небезопасный maintenance backup path.');
        }

        return $path;
    }
}
