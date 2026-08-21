<?php

namespace App\Services;

use App\Data\AmendmentValidationResult;
use App\Data\CitationValidationResult;
use App\Data\LegalAnalysisResult;
use App\Data\LegalDiscoveryResult;
use App\Data\LegalRetrievalResult;
use App\Models\Analysis;
use RuntimeException;

class LegalDraftingService
{
    public function __construct(
        private readonly LegalRetrievalService $retrievalService,
        private readonly LegalDiscoveryService $discoveryService,
        private readonly OpenAIService $openAIService,
        private readonly LegalCitationValidator $citationValidator,
        private readonly LegalAmendmentValidator $amendmentValidator,
    ) {}

    public function run(Analysis $analysis): LegalAnalysisResult
    {
        $analysis->loadMissing(['document', 'sourceVersions.source']);
        $scenario = $analysis->analysis_type;
        $discovery = null;

        if ($scenario === 'amendment_drafting') {
            $discovery = $this->discoveryService->discover($analysis);
            $retrieval = $discovery->retrieval;

            if ($discovery->sourceSufficiency === 'insufficient' || $retrieval->isEmpty()) {
                return $this->insufficientResult($retrieval, $discovery);
            }
        } else {
            $retrieval = $this->retrievalService->retrieve($analysis);

            if ($retrieval->isEmpty()) {
                return $this->insufficientResult(
                    $retrieval,
                    null,
                    ['Выбранная нормативная база не содержит релевантного контекста для проверки предлагаемой редакции.'],
                );
            }
        }

        $prompt = $this->prompt($analysis, $scenario, $retrieval);
        $response = $this->openAIService->respondStructured(
            $prompt,
            $this->schema($analysis, $retrieval),
            [
                'schema_name' => 'legal_drafting_v1',
                'timeout' => config('legal_analysis.timeout_seconds', 180),
            ],
        );
        $result = $response['result'];
        $summary = $result['summary'] ?? null;
        $assessment = $result['overall_assessment'] ?? null;
        $findings = $result['findings'] ?? null;
        $amendments = $result['amendments'] ?? null;
        $sufficiency = data_get($result, 'source_sufficiency.status');
        $warnings = data_get($result, 'source_sufficiency.warnings', []);

        if (! is_string($summary) || trim($summary) === '' || ! is_string($assessment) || trim($assessment) === '') {
            throw new RuntimeException('OpenAI вернул неполный результат разработки поправок.');
        }

        if (! is_array($findings) || ! is_array($amendments)) {
            throw new RuntimeException('OpenAI вернул некорректную структуру замечаний или поправок.');
        }

        if (! in_array($sufficiency, ['sufficient', 'partial', 'insufficient'], true)) {
            throw new RuntimeException('OpenAI вернул некорректную оценку достаточности нормативной базы.');
        }

        $warnings = array_values(array_filter(
            is_array($warnings) ? $warnings : [],
            fn ($warning) => is_string($warning) && trim($warning) !== '',
        ));

        if ($sufficiency === 'insufficient' && $warnings === []) {
            $warnings[] = 'Выбранной нормативной базы недостаточно для подтверждённого вывода.';
        }

        $citationValidation = $this->citationValidator->validate($findings, $retrieval, $analysis);
        $amendmentValidation = $this->amendmentValidator->validate(
            $amendments,
            $retrieval,
            $analysis,
            $scenario,
        );

        if ($sufficiency === 'insufficient' && $amendmentValidation->acceptedAmendments !== []) {
            $rejected = $amendmentValidation->rejectedAmendments;

            foreach ($amendmentValidation->acceptedAmendments as $amendment) {
                $rejected[] = [
                    'index' => $amendment['_model_index'],
                    'reasons' => [['code' => 'amendment_for_insufficient_sources']],
                    'amendment' => $amendment,
                ];
            }

            $amendmentValidation = new AmendmentValidationResult(
                [],
                $rejected,
                $amendmentValidation->validatorVersion,
            );
        }

        return new LegalAnalysisResult(
            summary: trim($summary),
            overallAssessment: trim($assessment),
            findings: $citationValidation->acceptedFindings,
            returnedFindingsCount: count($findings),
            retrieval: $retrieval,
            citationValidation: $citationValidation,
            promptHash: hash('sha256', $prompt),
            requestPayloadHash: $response['request_payload_hash'],
            model: $response['model'] ?? null,
            responseId: $response['response_id'] ?? null,
            usage: $response['usage'] ?? [],
            amendments: $amendmentValidation->acceptedAmendments,
            returnedAmendmentsCount: count($amendments),
            sourceSufficiency: $sufficiency,
            warnings: $warnings,
            amendmentValidation: $amendmentValidation,
            discovery: $discovery?->snapshot(),
        );
    }

    private function insufficientResult(
        LegalRetrievalResult $retrieval,
        ?LegalDiscoveryResult $discovery = null,
        array $warnings = [],
    ): LegalAnalysisResult {
        $warnings = array_values(array_unique(array_merge($warnings, $discovery?->warnings ?? [])));

        return new LegalAnalysisResult(
            summary: 'Недостаточно нормативного контекста для подтверждённой разработки поправок.',
            overallAssessment: 'Требуется дополнить выбранную нормативную базу.',
            findings: [],
            returnedFindingsCount: 0,
            retrieval: $retrieval,
            citationValidation: new CitationValidationResult([], [], config('legal_analysis.citation_validator_version')),
            promptHash: hash('sha256', 'insufficient|'.implode('|', $warnings)),
            requestPayloadHash: $discovery?->requestPayloadHash ?? hash('sha256', 'no-api-request'),
            model: $discovery?->model,
            responseId: $discovery?->responseId,
            usage: $discovery?->usage ?? [],
            amendments: [],
            returnedAmendmentsCount: 0,
            sourceSufficiency: 'insufficient',
            warnings: $warnings,
            amendmentValidation: new AmendmentValidationResult([], [], config('legal_analysis.drafting_validator_version')),
            discovery: $discovery?->snapshot(),
        );
    }

    private function prompt(Analysis $analysis, string $scenario, LegalRetrievalResult $retrieval): string
    {
        $document = $analysis->document;
        $scenarioRules = $scenario === 'amendment_review'
            ? <<<'RULES'
Проверь готовую предлагаемую редакцию. Даже при отсутствии противоречий оцени юридическую определённость, однозначность, полноту регулирования, внутреннюю согласованность и нормотворческую технику. Если улучшение не требуется, используй disposition=keep_as_proposed. Если требуется — disposition=revise и дай итоговый улучшенный текст.
RULES
            : <<<'RULES'
Самостоятельно определи подтверждённые точки внесения изменений. Для existing target выбирай только реально переданные fragment_id. Для нового элемента используй target_mode=new и подтверждённый parent/anchor. Не придумывай действующую редакцию: приложение восстановит её из target_fragment_ids.
RULES;

        return <<<PROMPT
Ты разрабатываешь юридически корректные поправки к нормативным правовым актам Республики Казахстан.

СЦЕНАРИЙ: {$scenario}
ПОРУЧЕНИЕ: {$analysis->instruction}
ДОКУМЕНТ: {$document->title}
ДЕЙСТВУЮЩАЯ РЕДАКЦИЯ ПОЛЬЗОВАТЕЛЯ:
{$document->current_text}
ПРЕДЛАГАЕМАЯ РЕДАКЦИЯ ПОЛЬЗОВАТЕЛЯ:
{$document->proposed_text}

{$scenarioRules}

TRUSTED НОРМАТИВНЫЙ КОНТЕКСТ:
{$retrieval->promptContext()}

Правила:
1. Используй только переданный контекст и выбранные SourceVersion.
2. Не компенсируй отсутствующие НПА внешними знаниями.
3. Каждая существующая цель требует citation purpose=target_current_text.
4. Каждый новый элемент требует citation purpose=parent_anchor.
5. Каждая поправка требует citation purpose=legal_basis.
6. quote должен дословно содержаться в fragment после нормализации пробелов.
7. При недостаточности верни source_sufficiency=insufficient, amendments=[] и конкретные warnings без выдуманного названия НПА.
8. Поле current_text не формируй: оно будет получено приложением из trusted fragments.
PROMPT;
    }

    private function schema(Analysis $analysis, LegalRetrievalResult $retrieval): array
    {
        $fragmentIds = array_values(array_map(fn ($fragment) => $fragment->fragmentId, $retrieval->fragments));
        $sourceVersionIds = array_values($analysis->sourceVersions->pluck('id')->map(fn ($id) => (int) $id)->all());
        $nullableString = ['type' => ['string', 'null']];
        $citation = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'purpose' => ['type' => 'string', 'enum' => ['target_current_text', 'legal_basis', 'cross_reference', 'parent_anchor']],
                'fragment_id' => ['type' => 'string', 'enum' => $fragmentIds],
                'quote' => ['type' => 'string', 'minLength' => 1],
                'article' => $nullableString,
                'paragraph' => $nullableString,
                'subparagraph' => $nullableString,
            ],
            'required' => ['purpose', 'fragment_id', 'quote', 'article', 'paragraph', 'subparagraph'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'scenario' => ['type' => 'string', 'enum' => ['amendment_review', 'amendment_drafting']],
                'summary' => ['type' => 'string', 'minLength' => 1],
                'overall_assessment' => ['type' => 'string', 'minLength' => 1],
                'source_sufficiency' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['sufficient', 'partial', 'insufficient']],
                        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['status', 'warnings'],
                ],
                'findings' => [
                    'type' => 'array',
                    'items' => $this->findingSchema($citation),
                ],
                'amendments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'target' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'target_mode' => ['type' => 'string', 'enum' => ['existing', 'new']],
                                    'source_version_id' => ['type' => 'integer', 'enum' => $sourceVersionIds],
                                    'structural_element_type' => ['type' => 'string', 'enum' => ['section', 'chapter', 'part', 'article', 'paragraph', 'subparagraph', 'text_paragraph', 'appendix', 'text_block']],
                                    'target_fragment_ids' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $fragmentIds]],
                                    'anchor_fragment_ids' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $fragmentIds]],
                                    'proposed_locator' => $nullableString,
                                ],
                                'required' => ['target_mode', 'source_version_id', 'structural_element_type', 'target_fragment_ids', 'anchor_fragment_ids', 'proposed_locator'],
                            ],
                            'amendment_type' => ['type' => 'string', 'enum' => ['new_edition', 'supplement_text', 'exclude_text', 'add_element', 'repeal_element']],
                            'disposition' => ['type' => 'string', 'enum' => ['keep_as_proposed', 'revise', 'reject', 'draft']],
                            'proposed_text' => $nullableString,
                            'justification' => ['type' => 'string', 'minLength' => 1],
                            'legal_basis' => ['type' => 'string', 'minLength' => 1],
                            'confidence_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'citations' => ['type' => 'array', 'minItems' => 1, 'items' => $citation],
                        ],
                        'required' => ['target', 'amendment_type', 'disposition', 'proposed_text', 'justification', 'legal_basis', 'confidence_score', 'warnings', 'citations'],
                    ],
                ],
            ],
            'required' => ['scenario', 'summary', 'overall_assessment', 'source_sufficiency', 'findings', 'amendments'],
        ];
    }

    private function findingSchema(array $citation): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'finding_type' => ['type' => 'string'],
                'severity' => ['type' => 'string', 'enum' => ['info', 'low', 'medium', 'high', 'critical']],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'document_fragment' => ['type' => 'string'],
                'document_location' => ['type' => 'string'],
                'legal_basis' => ['type' => 'string'],
                'recommendation' => ['type' => 'string'],
                'recommended_text' => ['type' => 'string'],
                'justification' => ['type' => 'string'],
                'confidence_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'citations' => ['type' => 'array', 'minItems' => 1, 'items' => $citation],
            ],
            'required' => ['finding_type', 'severity', 'title', 'description', 'document_fragment', 'document_location', 'legal_basis', 'recommendation', 'recommended_text', 'justification', 'confidence_score', 'citations'],
        ];
    }
}
