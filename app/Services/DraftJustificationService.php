<?php

namespace App\Services;

class DraftJustificationService
{
    public function __construct(
        private readonly OpenAIService $openAIService,
        private readonly DraftPackageValidator $validator,
    ) {}

    public function generate(array $input): array
    {
        $supported = [];
        $justifications = [];
        $warnings = [];

        foreach ($input['amendment_snapshots'] as $amendment) {
            if (! $this->validator->hasSufficientGround($amendment)) {
                $justifications[$amendment['amendment_id']] = [
                    'text' => null,
                    'warnings' => ['Недостаточно подтверждённых оснований для обоснования сравнительной таблицы.'],
                ];
                $warnings[] = "Для поправки {$amendment['amendment_id']} требуется дополнительное подтверждённое основание.";
                continue;
            }
            $supported[] = $amendment;
        }

        if ($supported === []) {
            return [
                'justifications' => $justifications,
                'warnings' => $warnings,
                'response' => null,
            ];
        }

        $response = $this->openAIService->respondStructured(
            $this->prompt($supported),
            $this->schema($supported),
            [
                'schema_name' => 'draft_package_justifications_v1',
                'timeout' => config('legal_analysis.draft_package.timeout_seconds', 180),
            ],
        );
        $validated = $this->validator->validateJustifications($response['result'], $supported);

        return [
            'justifications' => $validated + $justifications,
            'warnings' => $warnings,
            'response' => [
                'model' => $response['model'],
                'response_id' => $response['response_id'],
                'usage' => $response['usage'],
                'request_payload_hash' => $response['request_payload_hash'],
                'prompt_version' => config('legal_analysis.draft_package.justification_prompt_version'),
                'validator_version' => config('legal_analysis.draft_package.justification_validator_version'),
            ],
        ];
    }

    private function prompt(array $amendments): string
    {
        $facts = array_map(fn (array $amendment) => [
            'amendment_id' => $amendment['amendment_id'],
            'target' => $amendment['target'],
            'operation' => $amendment['operation'],
            'proposed_text' => $amendment['proposed_text'],
            'validated_analysis_justification' => $amendment['analysis_justification'],
            'validated_legal_basis' => $amendment['legal_basis'],
            'validated_citations' => $amendment['citations'],
            'warnings' => $amendment['warnings'],
            'trusted_normative_context' => array_map(fn (array $fragment) => [
                'fragment_id' => $fragment['fragment_id'],
                'source_title' => $fragment['source_title'],
                'version_name' => $fragment['version_name'],
                'article' => $fragment['article'],
                'paragraph' => $fragment['paragraph'],
                'subparagraph' => $fragment['subparagraph'],
                'text' => $fragment['text'],
            ], $amendment['trusted_context']),
        ], $amendments);

        return <<<'PROMPT'
Сформулируй для каждой поправки отдельное профессиональное обоснование сравнительной таблицы в стиле разработчика НПА.
Используй исключительно переданные validated основания и trusted normative context.
Не добавляй статистику, поручения Президента или Правительства, протоколы, финансовые показатели, даты, номера и иные факты, которых нет во входе.
Не меняй target, operation и proposed_text. Если основания недостаточны, оставь текст максимально ограниченным и добавь warning.

VALIDATED INPUT:
PROMPT
            .json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function schema(array $amendments): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'justifications' => [
                    'type' => 'array',
                    'minItems' => count($amendments),
                    'maxItems' => count($amendments),
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'amendment_id' => ['type' => 'integer', 'enum' => array_column($amendments, 'amendment_id')],
                            'text' => ['type' => 'string'],
                            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['amendment_id', 'text', 'warnings'],
                    ],
                ],
            ],
            'required' => ['justifications'],
        ];
    }
}
