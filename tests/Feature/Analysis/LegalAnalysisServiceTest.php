<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegalAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_accepts_valid_findings_rejects_invalid_ones_and_builds_snapshot(): void
    {
        $analysis = $this->fixture();

        Http::fake(function (Request $request) {
            $fragmentId = $this->fragmentIds($request)[0];

            return Http::response($this->response([
                $this->finding($fragmentId, 'Договор долевого участия заключается в письменной форме.'),
                $this->finding('sv999-fabricated', 'Несуществующая цитата', title: 'Неподтверждённое замечание'),
            ]));
        });

        $result = app(LegalAnalysisService::class)->run($analysis);
        $settings = $result->settings();

        $this->assertSame(2, $result->returnedFindingsCount);
        $this->assertCount(1, $result->findings);
        $this->assertSame('Подтверждённое замечание', $result->findings[0]['title']);
        $this->assertStringContainsString('Закон о долевом участии', $result->findings[0]['source_reference']);
        $this->assertSame(1, $settings['citation_validation']['accepted_count']);
        $this->assertSame(1, $settings['citation_validation']['rejected_count']);
        $this->assertSame('unknown_fragment', $settings['citation_validation']['rejected'][0]['reasons'][0]['code']);
        $this->assertNotEmpty($settings['retrieval_context']);
        $this->assertNotEmpty($settings['source_snapshots']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $settings['request_payload_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $settings['context_hash']);
        $this->assertArrayNotHasKey('api_key', $settings);
        $this->assertArrayNotHasKey('authorization', $settings);

        Http::assertSent(function (Request $request) {
            $format = $request->data()['text']['format'];
            $findingProperties = $format['schema']['properties']['findings']['items']['properties'];

            return $format['strict'] === true
                && isset($findingProperties['citations'])
                && !isset($findingProperties['source_reference'])
                && str_contains($request->data()['input'], 'fragment_id')
                && str_contains($request->data()['input'], 'НОРМАТИВНЫЙ КОНТЕКСТ');
        });
    }

    public function test_empty_findings_is_valid_with_non_empty_summary_and_assessment(): void
    {
        $analysis = $this->fixture();

        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->response([])),
        ]);

        $result = app(LegalAnalysisService::class)->run($analysis);

        $this->assertSame(0, $result->returnedFindingsCount);
        $this->assertSame([], $result->findings);
        $this->assertFalse($result->hasCompletelyInvalidCitations());
        $this->assertSame('Резюме анализа', $result->summary);
        $this->assertSame('Итоговая оценка', $result->overallAssessment);
    }

    private function fragmentIds(Request $request): array
    {
        return $request->data()['text']['format']['schema']['properties']['findings']['items']
            ['properties']['citations']['items']['properties']['fragment_id']['enum'];
    }

    private function response(array $findings): array
    {
        return [
            'id' => 'resp_legal_test',
            'model' => 'test-model',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'summary' => 'Резюме анализа',
                        'overall_assessment' => 'Итоговая оценка',
                        'findings' => $findings,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]],
            ]],
        ];
    }

    private function finding(
        string $fragmentId,
        string $quote,
        string $title = 'Подтверждённое замечание',
    ): array {
        return [
            'finding_type' => 'compliance',
            'severity' => 'high',
            'title' => $title,
            'description' => 'Описание замечания',
            'document_fragment' => 'Предлагаемая норма',
            'document_location' => 'Пункт проекта',
            'legal_basis' => 'Договор должен иметь письменную форму.',
            'recommendation' => 'Уточнить форму договора.',
            'recommended_text' => 'Договор заключается письменно.',
            'justification' => 'Требование нормативного акта.',
            'confidence_score' => 95,
            'citations' => [[
                'fragment_id' => $fragmentId,
                'quote' => $quote,
                'article' => '12',
                'paragraph' => null,
                'subparagraph' => null,
            ]],
        ];
    }

    private function fixture(): Analysis
    {
        config()->set('services.openai.key', 'fake-feature-key');
        config()->set('services.openai.model', 'test-model');

        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-LEGAL-SERVICE',
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Проект договора долевого участия',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'current_text' => 'Действующая редакция договора.',
            'proposed_text' => 'Предлагается изменить форму договора долевого участия.',
            'analysis_instruction' => 'Проверить форму договора долевого участия по статье 12.',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридический анализ',
            'analysis_type' => 'comprehensive',
            'instruction' => $document->analysis_instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $source = Source::create([
            'title' => 'Закон о долевом участии',
            'type' => 'law',
            'status' => 'active',
        ]);
        $text = "Статья 12. Форма договора\nДоговор долевого участия заключается в письменной форме.";
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);

        return $analysis;
    }
}
