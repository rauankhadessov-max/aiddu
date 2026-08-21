<?php

namespace App\Services;

use App\Models\Analysis;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LegalAnalysisService
{
    public function run(Analysis $analysis): array
    {
        $analysis->loadMissing([
            'document',
            'sourceVersions.source',
        ]);

        $document = $analysis->document;

$queryText = trim(
    ($analysis->instruction ?? '') . "\n" .
    ($document->title ?? '') . "\n" .
    ($document->content_text ?? '') . "\n" .
    ($document->current_text ?? '') . "\n" .
    ($document->proposed_text ?? '')
);

$sourcesText = $analysis->sourceVersions
    ->map(function ($version) use ($queryText) {

        $sourceText = $version->text ?? '';

        $keywords = collect(
            preg_split('/[^\p{L}\p{N}\-]+/u', mb_strtolower($queryText))
        )
            ->filter(fn ($word) => mb_strlen($word) >= 5)
            ->unique()
            ->values();

        $paragraphs = preg_split('/\n{2,}/u', $sourceText);

        $scored = collect($paragraphs)
            ->map(function ($paragraph, $index) use ($keywords) {

                $lower = mb_strtolower($paragraph);

                $score = $keywords->sum(function ($keyword) use ($lower) {
                    return mb_substr_count($lower, $keyword);
                });

                return [
                    'index' => $index,
                    'text' => trim($paragraph),
                    'score' => $score,
                ];
            })
            ->filter(fn ($item) => $item['text'] !== '')
            ->sortByDesc('score');

        $selectedIndexes = $scored
            ->take(20)
            ->pluck('index')
            ->sort()
            ->values();

        $selected = collect($paragraphs)
            ->only($selectedIndexes->all())
            ->filter()
            ->implode("\n\n");

        if (mb_strlen($selected) > 30000) {
            $selected = mb_substr($selected, 0, 30000);
        }

        return "НПА: {$version->source->title}\n"
            . "Редакция: {$version->version_name}\n"
            . "Релевантные фрагменты:\n{$selected}";
    })
    ->implode("\n\n-----------------------------\n\n");

        $input = <<<PROMPT
Ты являешься юридическим экспертом по нормативным правовым актам Республики Казахстан.

Твоя задача:
{$analysis->instruction}

ТИП АНАЛИЗА:
{$analysis->analysis_type}

АНАЛИЗИРУЕМЫЙ ДОКУМЕНТ:
Название: {$document->title}

Полный текст:
{$document->content_text}

Действующая редакция:
{$document->current_text}

Предлагаемая редакция:
{$document->proposed_text}

НОРМАТИВНАЯ БАЗА:
{$sourcesText}

Требования:
1. Используй только предоставленные нормативные источники.
2. Не придумывай статьи, пункты и нормы.
3. Если предоставленных источников недостаточно — прямо укажи это.
4. Каждое замечание должно содержать юридическое основание.
5. Если возможно, предложи юридически корректную редакцию.
6. Отделяй прямое нарушение от рекомендации по улучшению.
7. Пиши на русском языке.
PROMPT;

        $response = Http::withToken(config('services.openai.key'))
            ->acceptJson()
            ->timeout(180)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.model'),

                'input' => $input,

                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'legal_analysis',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'summary' => [
                                    'type' => 'string',
                                ],
                                'overall_assessment' => [
                                    'type' => 'string',
                                ],
                                'findings' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'properties' => [
                                            'finding_type' => [
                                                'type' => 'string',
                                            ],
                                            'severity' => [
                                                'type' => 'string',
                                                'enum' => [
                                                    'info',
                                                    'low',
                                                    'medium',
                                                    'high',
                                                    'critical',
                                                ],
                                            ],
                                            'title' => [
                                                'type' => 'string',
                                            ],
                                            'description' => [
                                                'type' => 'string',
                                            ],
                                            'document_fragment' => [
                                                'type' => 'string',
                                            ],
                                            'document_location' => [
                                                'type' => 'string',
                                            ],
                                            'source_reference' => [
                                                'type' => 'string',
                                            ],
                                            'legal_basis' => [
                                                'type' => 'string',
                                            ],
                                            'recommendation' => [
                                                'type' => 'string',
                                            ],
                                            'recommended_text' => [
                                                'type' => 'string',
                                            ],
                                            'justification' => [
                                                'type' => 'string',
                                            ],
                                            'confidence_score' => [
                                                'type' => 'integer',
                                                'minimum' => 0,
                                                'maximum' => 100,
                                            ],
                                        ],
                                        'required' => [
                                            'finding_type',
                                            'severity',
                                            'title',
                                            'description',
                                            'document_fragment',
                                            'document_location',
                                            'source_reference',
                                            'legal_basis',
                                            'recommendation',
                                            'recommended_text',
                                            'justification',
                                            'confidence_score',
                                        ],
                                    ],
                                ],
                            ],
                            'required' => [
                                'summary',
                                'overall_assessment',
                                'findings',
                            ],
                        ],
                    ],
                ],
            ]);

        if (!$response->successful()) {
            $message = $response->json('error.message')
                ?? 'Неизвестная ошибка OpenAI API.';

            throw new RuntimeException(
                'OpenAI API error (' . $response->status() . '): ' . $message
            );
        }

        $message = collect($response->json('output'))
            ->firstWhere('type', 'message');

        $text = $message['content'][0]['text'] ?? null;

        if (!$text) {
            throw new RuntimeException(
                'OpenAI вернул ответ без структурированного результата.'
            );
        }

        $result = json_decode($text, true);

        if (!is_array($result)) {
            throw new RuntimeException(
                'Не удалось преобразовать результат OpenAI в JSON.'
            );
        }

        return [
            'result' => $result,
            'model' => $response->json('model'),
            'response_id' => $response->json('id'),
            'usage' => $response->json('usage') ?? [],
        ];
    }
}
