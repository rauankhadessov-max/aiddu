<?php

namespace App\Services;

use App\Data\LegalAnalysisResult;
use App\Models\Analysis;
use RuntimeException;

class LegalAnalysisService
{
    public function __construct(
        private readonly LegalRetrievalService $retrievalService,
        private readonly OpenAIService $openAIService,
        private readonly LegalCitationValidator $citationValidator,
        private readonly LegalDraftingService $draftingService,
    ) {}

    public function run(Analysis $analysis): LegalAnalysisResult
    {
        if (in_array($analysis->analysis_type, ['amendment_review', 'amendment_drafting'], true)) {
            return $this->draftingService->run($analysis);
        }

        $analysis->loadMissing(['document', 'sourceVersions.source']);

        $retrieval = $this->retrievalService->retrieve($analysis);

        if ($retrieval->isEmpty()) {
            throw new RuntimeException('Не найден релевантный нормативный контекст.');
        }

        $prompt = $this->buildPrompt($analysis, $retrieval->promptContext());
        $schema = $this->buildSchema(array_map(
            fn ($fragment) => $fragment->fragmentId,
            $retrieval->fragments,
        ));
        $response = $this->openAIService->respondStructured(
            input: $prompt,
            schema: $schema,
            options: [
                'schema_name' => 'legal_analysis_v2',
                'timeout' => config('legal_analysis.timeout_seconds', 180),
            ],
        );
        $result = $response['result'];
        $summary = $result['summary'] ?? null;
        $overallAssessment = $result['overall_assessment'] ?? null;
        $findings = $result['findings'] ?? null;

        if (! is_string($summary) || trim($summary) === '') {
            throw new RuntimeException('OpenAI вернул пустое резюме анализа.');
        }

        if (! is_string($overallAssessment) || trim($overallAssessment) === '') {
            throw new RuntimeException('OpenAI вернул пустую итоговую оценку.');
        }

        if (! is_array($findings)) {
            throw new RuntimeException('OpenAI вернул некорректный список замечаний.');
        }

        $citationValidation = $this->citationValidator->validate(
            $findings,
            $retrieval,
            $analysis,
        );

        return new LegalAnalysisResult(
            summary: trim($summary),
            overallAssessment: trim($overallAssessment),
            findings: $citationValidation->acceptedFindings,
            returnedFindingsCount: count($findings),
            retrieval: $retrieval,
            citationValidation: $citationValidation,
            promptHash: hash('sha256', $prompt),
            requestPayloadHash: $response['request_payload_hash'],
            model: $response['model'] ?? null,
            responseId: $response['response_id'] ?? null,
            usage: $response['usage'] ?? [],
        );
    }

    private function buildPrompt(Analysis $analysis, string $context): string
    {
        $document = $analysis->document;

        return <<<PROMPT
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

НОРМАТИВНЫЙ КОНТЕКСТ:
{$context}

ОБЯЗАТЕЛЬНЫЕ ПРАВИЛА:
1. Используй только нормативные фрагменты, переданные выше.
2. Каждый Finding должен содержать минимум одну citation.
3. fragment_id выбирай только из переданных metadata.
4. quote должен быть точной цитатой из TEXT соответствующего fragment с допустимыми различиями только в пробелах.
5. Не придумывай статьи, пункты, подпункты и нормативные формулировки.
6. Если locator не следует из фрагмента надёжно, верни null в article, paragraph или subparagraph.
7. Если контекста недостаточно, не создавай неподтверждённый Finding и укажи ограничение в summary.
8. Отделяй прямое нарушение от рекомендации по улучшению.
9. Пиши на русском языке.
PROMPT;
    }

    private function buildSchema(array $fragmentIds): array
    {
        $nullableLocator = [
            'type' => ['string', 'null'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'overall_assessment' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'finding_type' => ['type' => 'string'],
                            'severity' => [
                                'type' => 'string',
                                'enum' => ['info', 'low', 'medium', 'high', 'critical'],
                            ],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'document_fragment' => ['type' => 'string'],
                            'document_location' => ['type' => 'string'],
                            'legal_basis' => ['type' => 'string'],
                            'recommendation' => ['type' => 'string'],
                            'recommended_text' => ['type' => 'string'],
                            'justification' => ['type' => 'string'],
                            'confidence_score' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 100,
                            ],
                            'citations' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'fragment_id' => [
                                            'type' => 'string',
                                            'enum' => array_values($fragmentIds),
                                        ],
                                        'quote' => [
                                            'type' => 'string',
                                            'minLength' => 1,
                                        ],
                                        'article' => $nullableLocator,
                                        'paragraph' => $nullableLocator,
                                        'subparagraph' => $nullableLocator,
                                    ],
                                    'required' => [
                                        'fragment_id',
                                        'quote',
                                        'article',
                                        'paragraph',
                                        'subparagraph',
                                    ],
                                ],
                            ],
                        ],
                        'required' => [
                            'finding_type',
                            'severity',
                            'title',
                            'description',
                            'document_fragment',
                            'document_location',
                            'legal_basis',
                            'recommendation',
                            'recommended_text',
                            'justification',
                            'confidence_score',
                            'citations',
                        ],
                    ],
                ],
            ],
            'required' => ['summary', 'overall_assessment', 'findings'],
        ];
    }
}
