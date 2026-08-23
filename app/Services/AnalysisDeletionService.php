<?php

namespace App\Services;

use App\Models\Analysis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AnalysisDeletionService
{
    public function delete(Analysis $analysis): void
    {
        $movedFiles = [];

        try {
            DB::transaction(function () use ($analysis, &$movedFiles) {
                $lockedAnalysis = Analysis::query()
                    ->lockForUpdate()
                    ->findOrFail($analysis->id);

                if ($lockedAnalysis->status === 'processing') {
                    throw ValidationException::withMessages([
                        'analysis' => 'Нельзя удалить анализ, пока он выполняется.',
                    ]);
                }

                $lockedAnalysis->load('draftPackage.artifacts');
                $deletionId = (string) Str::uuid();

                foreach ($lockedAnalysis->draftPackage?->artifacts ?? [] as $index => $artifact) {
                    if (blank($artifact->storage_path)) {
                        continue;
                    }

                    $disk = $artifact->storage_disk ?: config('filesystems.default');
                    $path = $this->validatedArtifactPath($disk, $artifact->storage_path);
                    $filesystem = Storage::disk($disk);

                    if (!$filesystem->exists($path)) {
                        continue;
                    }

                    $quarantinePath = '.analysis-deletions/'.$deletionId.'/'.$index.'-'.basename($path);

                    if (!$filesystem->move($path, $quarantinePath)) {
                        throw new RuntimeException('Не удалось подготовить сформированный файл к удалению.');
                    }

                    $movedFiles[] = compact('disk', 'path', 'quarantinePath');
                }

                // Findings, amendments, source-version pivots, DraftPackage and
                // all Artifact rows are removed by their existing FK cascades.
                $lockedAnalysis->delete();
            });
        } catch (Throwable $exception) {
            $this->restoreMovedFiles($movedFiles);
            throw $exception;
        }

        foreach ($movedFiles as $file) {
            $filesystem = Storage::disk($file['disk']);

            if (!$filesystem->delete($file['quarantinePath']) && $filesystem->exists($file['quarantinePath'])) {
                report(new RuntimeException('Не удалось окончательно удалить quarantined DOCX-файл.'));
            }
        }
    }

    private function validatedArtifactPath(string $disk, string $path): string
    {
        if (!config()->has('filesystems.disks.'.$disk)) {
            throw new RuntimeException('Для сформированного файла указан недоступный storage disk.');
        }

        $normalized = str_replace('\\', '/', trim($path));

        if (
            $normalized === ''
            || str_starts_with($normalized, '/')
            || str_contains('/'.$normalized.'/', '/../')
            || !str_starts_with($normalized, 'draft-packages/')
        ) {
            throw new RuntimeException('Для сформированного файла указан небезопасный storage path.');
        }

        return $normalized;
    }

    private function restoreMovedFiles(array $movedFiles): void
    {
        foreach (array_reverse($movedFiles) as $file) {
            $filesystem = Storage::disk($file['disk']);

            if ($filesystem->exists($file['quarantinePath']) && !$filesystem->exists($file['path'])) {
                if (!$filesystem->move($file['quarantinePath'], $file['path'])) {
                    report(new RuntimeException('Не удалось восстановить DOCX-файл после отмены удаления анализа.'));
                }
            }
        }
    }
}
