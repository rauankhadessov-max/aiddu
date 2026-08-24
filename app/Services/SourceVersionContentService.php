<?php

namespace App\Services;

use App\Models\Source;
use App\Models\SourceVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SourceVersionContentService
{
    public function __construct(
        private readonly DocxTextExtractor $docxTextExtractor,
    ) {
    }

    public function create(
        Source $source,
        string $versionName,
        ?string $effectiveDate,
        ?string $text,
        ?UploadedFile $docxFile,
    ): SourceVersion {
        $content = $this->content($text, $docxFile);

        return $source->versions()->create([
            'version_name' => $versionName,
            'effective_date' => $effectiveDate,
            'text' => $content,
            'hash' => hash('sha256', $content),
        ]);
    }

    public function update(
        SourceVersion $version,
        string $versionName,
        ?string $effectiveDate,
        ?string $text,
        ?UploadedFile $docxFile,
    ): SourceVersion {
        $content = $this->content($text, $docxFile);

        $version->update([
            'version_name' => $versionName,
            'effective_date' => $effectiveDate,
            'text' => $content,
            'hash' => hash('sha256', $content),
        ]);

        return $version->fresh();
    }

    private function content(?string $text, ?UploadedFile $docxFile): string
    {
        if ($docxFile) {
            $storedPath = $docxFile->store('source_versions', 'local');

            try {
                $text = $this->docxTextExtractor->extract(
                    Storage::disk('local')->path($storedPath),
                );
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'docx_file' => 'Не удалось извлечь нормативный текст из DOCX-файла.',
                ]);
            }
        }

        $normalized = trim((string) $text);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'text' => 'Введите текст редакции или загрузите DOCX-файл.',
            ]);
        }

        return $normalized;
    }
}
