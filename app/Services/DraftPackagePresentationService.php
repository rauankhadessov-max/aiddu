<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\DraftPackage;

class DraftPackagePresentationService
{
    public function __construct(private readonly LegalDocumentFormatter $formatter) {}

    public function package(DraftPackage $package): array
    {
        return [
            'version' => LegalDocumentFormatter::PRESENTATION_VERSION,
            'requirements' => $this->requirements(data_get($package->plan, 'requires_user_input', [])),
            'warnings' => array_values(data_get($package->plan, 'warnings', [])),
        ];
    }

    public function comparativeTable(Artifact $artifact): array
    {
        $content = $artifact->content;
        $snapshots = collect(data_get($artifact->draftPackage->plan, 'amendment_snapshots', []))
            ->keyBy('amendment_id');

        $rows = collect($content['rows'] ?? [])->map(function (array $row) use ($snapshots): array {
            $snapshot = $snapshots->get($row['amendment_id'] ?? null, []);

            return [
                ...$row,
                'structural_element' => $this->formatter->compactLocator(
                    is_array($snapshot['target'] ?? null) ? $snapshot['target'] : [],
                    (string) ($row['structural_element'] ?? ''),
                ),
                'current_blocks' => $this->formatter->textBlocks($row['current_text'] ?? null),
                'proposed_blocks' => $this->formatter->textBlocks($row['proposed_text'] ?? null),
                'justification_blocks' => $this->formatter->textBlocks($row['justification'] ?? null),
                'warnings' => array_values($row['warnings'] ?? []),
            ];
        })->all();

        return [
            'version' => LegalDocumentFormatter::PRESENTATION_VERSION,
            'columns' => $content['columns'] ?? [],
            'rows' => $rows,
            'warnings' => array_values($content['warnings'] ?? []),
        ];
    }

    public function draftNpa(Artifact $artifact): array
    {
        $content = $artifact->content;
        $articles = collect($content['articles'] ?? [])->map(function (array $article): array {
            $article['intro'] = $this->formatter->typographicHeading($article['intro'] ?? null);
            $article['commands'] = collect($article['commands'] ?? [])->map(function (array $command): array {
                return [...$command, 'blocks' => $this->formatter->commandBlocks((string) ($command['text'] ?? ''))];
            })->all();

            return $article;
        })->all();

        return [
            ...$content,
            'version' => LegalDocumentFormatter::PRESENTATION_VERSION,
            'title' => $this->formatter->typographicHeading($content['title'] ?? null),
            'requirements' => $this->requirements($content['requires_user_input'] ?? []),
            'articles' => $articles,
            'warnings' => array_values($content['warnings'] ?? []),
        ];
    }

    private function requirements(array $keys): array
    {
        return array_values(array_unique(array_map(
            fn ($key) => $this->formatter->requirementLabel((string) $key),
            $keys,
        )));
    }
}
