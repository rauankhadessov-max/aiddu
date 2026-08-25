<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\User;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class ArtifactDocxService
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(
        private readonly DraftPackageDocxRenderer $renderer,
        private readonly DraftPackageInputBuilder $hasher,
        private readonly DraftPackagePresentationContext $presentationContext,
    ) {}

    public function generate(Artifact $sourceArtifact, User $user): Artifact
    {
        $this->assertCanonicalSource($sourceArtifact);

        $rendererVersion = (string) config('legal_analysis.draft_package.docx_renderer_version');
        $disk = (string) config('legal_analysis.draft_package.docx_storage_disk', 'local');
        $renderContext = $this->presentationContext->for($sourceArtifact);
        $sourceHash = $this->hasher->hashPayload([
            'canonical_artifact_hash' => $renderContext['canonical_hash'],
            'canonical_draft_npa_hash' => $sourceArtifact->artifact_type === 'comparative_table'
                ? $renderContext['draft_npa_hash']
                : null,
        ]);
        $representationType = $sourceArtifact->artifact_type.'_docx';
        $logicalHash = $this->hasher->hashPayload([
            'artifact_type' => $representationType,
            'renderer_version' => $rendererVersion,
            'source_content_hash' => $sourceHash,
        ]);
        $renderManifest = [
            'renderer_version' => $rendererVersion,
            'canonical_artifact_id' => $sourceArtifact->id,
            'canonical_comparative_table_hash' => $renderContext['comparative_table_hash'],
            'canonical_draft_npa_artifact_id' => $renderContext['draft_npa_artifact_id'],
            'canonical_draft_npa_hash' => $renderContext['draft_npa_hash'],
            'source_content_hash' => $sourceHash,
            'composite_logical_hash' => $logicalHash,
        ];

        $existing = $this->findRepresentation($sourceArtifact, $representationType, $rendererVersion, $sourceHash);
        if ($existing !== null && $this->isValid($existing)) {
            return $existing;
        }

        $temporaryPath = $this->renderer->render($sourceArtifact, $renderContext);
        try {
            $this->assertValidDocx($temporaryPath);
            $binaryHash = hash_file('sha256', $temporaryPath);
            $fileSize = filesize($temporaryPath);
            if ($binaryHash === false || $fileSize === false) {
                throw new RuntimeException('Не удалось вычислить контрольные суммы DOCX.');
            }

            return DB::transaction(function () use (
                $sourceArtifact,
                $user,
                $rendererVersion,
                $disk,
                $sourceHash,
                $representationType,
                $logicalHash,
                $renderManifest,
                $temporaryPath,
                $binaryHash,
                $fileSize,
            ): Artifact {
                $source = Artifact::query()->lockForUpdate()->findOrFail($sourceArtifact->id);
                $this->assertCanonicalSource($source);
                $representation = $this->findRepresentation($source, $representationType, $rendererVersion, $sourceHash);
                if ($representation !== null && $this->isValid($representation)) {
                    return $representation;
                }

                $storagePath = $this->storagePath($source, $representationType, $rendererVersion, $sourceHash);
                $stream = fopen($temporaryPath, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Не удалось открыть временный DOCX для сохранения.');
                }
                try {
                    if (! Storage::disk($disk)->put($storagePath, $stream)) {
                        throw new RuntimeException('Не удалось сохранить DOCX в защищённое хранилище.');
                    }
                    $this->ensureRuntimeReadable($disk, $storagePath);
                } finally {
                    fclose($stream);
                }

                $attributes = [
                    'draft_package_id' => $source->draft_package_id,
                    'source_artifact_id' => $source->id,
                    'created_by' => $user->id,
                    'artifact_type' => $representationType,
                    'format' => 'docx',
                    'title' => $source->title.' (DOCX)',
                    'content' => ['render_manifest' => $renderManifest],
                    'storage_disk' => $disk,
                    'storage_path' => $storagePath,
                    'filename' => $this->filename($source),
                    'mime_type' => self::MIME_TYPE,
                    'file_size' => $fileSize,
                    'renderer_version' => $rendererVersion,
                    'source_content_hash' => $sourceHash,
                    'logical_content_hash' => $logicalHash,
                    'binary_sha256' => $binaryHash,
                    'status' => 'generated',
                    'generated_at' => now(),
                ];

                if ($representation === null) {
                    $representation = Artifact::create($attributes);
                } else {
                    $representation->fill($attributes)->save();
                }

                if (! $this->isValid($representation->fresh())) {
                    throw new RuntimeException('Сохранённый DOCX не прошёл проверку целостности.');
                }

                return $representation->fresh();
            });
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function isValid(Artifact $representation): bool
    {
        if (
            $representation->format !== 'docx'
            || $representation->mime_type !== self::MIME_TYPE
            || blank($representation->storage_disk)
            || blank($representation->storage_path)
            || blank($representation->binary_sha256)
        ) {
            return false;
        }

        $disk = Storage::disk($representation->storage_disk);
        if (! $disk->exists($representation->storage_path)) {
            return false;
        }

        $path = $disk->path($representation->storage_path);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if (
            $size === false
            || $hash === false
            || (int) $size !== (int) $representation->file_size
            || ! hash_equals((string) $representation->binary_sha256, $hash)
        ) {
            return false;
        }

        try {
            $this->assertValidDocx($path);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    private function assertCanonicalSource(Artifact $artifact): void
    {
        if (
            $artifact->source_artifact_id !== null
            || $artifact->format !== 'structured_json'
            || ! in_array($artifact->artifact_type, ['comparative_table', 'draft_npa'], true)
            || ! is_array($artifact->content)
        ) {
            throw new RuntimeException('Недопустимый canonical Artifact для DOCX export.');
        }
    }

    private function ensureRuntimeReadable(string $diskName, string $storagePath): void
    {
        if (
            PHP_OS_FAMILY === 'Windows'
            || config("filesystems.disks.{$diskName}.driver") !== 'local'
            || ! function_exists('posix_geteuid')
        ) {
            return;
        }

        $disk = Storage::disk($diskName);
        $path = $disk->path($storagePath);
        $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        $currentUid = posix_geteuid();
        $directories = [];
        $directory = dirname($path);

        while ($directory !== $root && str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) {
            $directories[] = $directory;
            $directory = dirname($directory);
        }

        foreach (array_reverse($directories) as $runtimeDirectory) {
            $this->addOwnerManagedPermission($runtimeDirectory, $currentUid, 0o001);
        }
        $this->addOwnerManagedPermission($path, $currentUid, 0o004);

        clearstatcache(true, $path);
        clearstatcache(true, dirname($path));
        if ((fileperms($path) & 0o004) !== 0o004 || (fileperms(dirname($path)) & 0o001) !== 0o001) {
            throw new RuntimeException('DOCX сохранён без прав чтения для PHP-FPM runtime.');
        }
    }

    private function addOwnerManagedPermission(string $path, int $currentUid, int $mask): void
    {
        $owner = fileowner($path);
        $permissions = fileperms($path);
        if ($owner === false || $permissions === false || $owner !== $currentUid) {
            return;
        }

        $mode = $permissions & 0o777;
        if (($mode & $mask) === $mask) {
            return;
        }

        if (! chmod($path, $mode | $mask)) {
            throw new RuntimeException('Не удалось подготовить DOCX для чтения PHP-FPM runtime.');
        }
    }

    private function findRepresentation(
        Artifact $source,
        string $type,
        string $rendererVersion,
        string $sourceHash,
    ): ?Artifact {
        return Artifact::query()
            ->where('source_artifact_id', $source->id)
            ->where('artifact_type', $type)
            ->where('renderer_version', $rendererVersion)
            ->where('source_content_hash', $sourceHash)
            ->first();
    }

    private function assertValidDocx(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Сформированный файл не является корректным DOCX ZIP-контейнером.');
        }
        try {
            foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml'] as $entry) {
                if ($zip->locateName($entry) === false) {
                    throw new RuntimeException("В DOCX отсутствует обязательная часть {$entry}.");
                }
            }
            $documentXml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($documentXml) || $documentXml === '') {
            throw new RuntimeException('Основной OOXML-документ пуст.');
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $valid = $dom->loadXML($documentXml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $valid) {
            throw new RuntimeException('Основной OOXML-документ повреждён.');
        }
    }

    private function storagePath(Artifact $source, string $type, string $rendererVersion, string $sourceHash): string
    {
        $version = preg_replace('/[^a-z0-9_-]+/i', '-', $rendererVersion) ?: 'renderer';

        return sprintf(
            'draft-packages/%d/docx/%d/%s/%s-%s.docx',
            $source->draft_package_id,
            $source->id,
            trim($version, '-'),
            $type,
            substr($sourceHash, 0, 16),
        );
    }

    private function filename(Artifact $source): string
    {
        $prefix = $source->artifact_type === 'comparative_table' ? 'comparative-table' : 'draft-npa';

        return sprintf('%s-package-%d.docx', $prefix, $source->draft_package_id);
    }
}
