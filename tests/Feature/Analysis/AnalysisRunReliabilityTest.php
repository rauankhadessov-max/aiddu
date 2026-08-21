<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalysisRunReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_completely_invalid_citations_fail_without_deleting_existing_findings(): void
    {
        [$owner, $analysis] = $this->fixture();
        $oldFinding = $this->createExistingFinding($analysis);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->response([
                $this->finding('sv999-fabricated', 'Придуманная цитата'),
            ])),
        ]);

        $this->actingAs($owner)
            ->post(route('analyses.run', $analysis))
            ->assertRedirect(route('analyses.show', $analysis))
            ->assertSessionHas('error');

        $analysis->refresh();

        $this->assertSame('failed', $analysis->status);
        $this->assertDatabaseHas('analysis_findings', [
            'id' => $oldFinding->id,
            'title' => 'Старое подтверждённое замечание',
        ]);
        $this->assertDatabaseCount('analysis_findings', 1);
        $this->assertSame(0, $analysis->settings['citation_validation']['accepted_count']);
        $this->assertSame(1, $analysis->settings['citation_validation']['rejected_count']);
        $this->assertArrayHasKey('last_failed_attempt', $analysis->settings);
    }

    public function test_partial_valid_response_atomically_replaces_findings_and_preserves_snapshot_after_source_edit(): void
    {
        [$owner, $analysis, $version] = $this->fixture();
        $this->createExistingFinding($analysis);

        Http::fake(function (Request $request) {
            $fragmentId = $this->fragmentIds($request)[0];

            return Http::response($this->response([
                $this->finding(
                    $fragmentId,
                    'Договор долевого участия заключается в письменной форме.',
                    'Новое подтверждённое замечание',
                ),
                $this->finding('sv999-invalid', 'Придуманная цитата', 'Отклонённое замечание'),
            ]));
        });

        $this->actingAs($owner)
            ->post(route('analyses.run', $analysis))
            ->assertRedirect(route('analyses.show', $analysis))
            ->assertSessionHas('success');

        $analysis->refresh();
        $snapshot = $analysis->settings;
        $snapshotText = $snapshot['retrieval_context'][0]['text'];
        $snapshotHash = $snapshot['retrieval_context'][0]['text_hash'];

        $this->assertSame('completed', $analysis->status);
        $this->assertDatabaseCount('analysis_findings', 1);
        $this->assertDatabaseHas('analysis_findings', [
            'analysis_id' => $analysis->id,
            'title' => 'Новое подтверждённое замечание',
        ]);
        $this->assertDatabaseMissing('analysis_findings', [
            'analysis_id' => $analysis->id,
            'title' => 'Старое подтверждённое замечание',
        ]);
        $this->assertDatabaseMissing('analysis_findings', [
            'analysis_id' => $analysis->id,
            'title' => 'Отклонённое замечание',
        ]);
        $this->assertSame(1, $snapshot['citation_validation']['accepted_count']);
        $this->assertSame(1, $snapshot['citation_validation']['rejected_count']);
        $this->assertStringNotContainsString('fake-run-key', json_encode($snapshot));
        $this->assertStringNotContainsString('Authorization', json_encode($snapshot));

        $changedText = 'Полностью изменённый текст редакции.';
        $version->update([
            'text' => $changedText,
            'hash' => hash('sha256', $changedText),
        ]);

        $analysis->refresh();

        $this->assertSame($snapshotText, $analysis->settings['retrieval_context'][0]['text']);
        $this->assertSame($snapshotHash, $analysis->settings['retrieval_context'][0]['text_hash']);
        $this->assertNotSame($version->fresh()->hash, $analysis->settings['source_snapshots'][0]['source_version_hash']);
    }

    public function test_valid_empty_findings_response_completes_successfully(): void
    {
        [$owner, $analysis] = $this->fixture();
        $this->createExistingFinding($analysis);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->response([])),
        ]);

        $this->actingAs($owner)
            ->post(route('analyses.run', $analysis))
            ->assertRedirect(route('analyses.show', $analysis))
            ->assertSessionHas('success');

        $analysis->refresh();

        $this->assertSame('completed', $analysis->status);
        $this->assertSame('Проверка завершена.', $analysis->summary);
        $this->assertDatabaseCount('analysis_findings', 0);
        $this->assertSame(0, $analysis->settings['citation_validation']['accepted_count']);
        $this->assertSame(0, $analysis->settings['citation_validation']['rejected_count']);
    }

    private function fragmentIds(Request $request): array
    {
        return $request->data()['text']['format']['schema']['properties']['findings']['items']
            ['properties']['citations']['items']['properties']['fragment_id']['enum'];
    }

    private function response(array $findings): array
    {
        return [
            'id' => 'resp_run_reliability',
            'model' => 'test-model',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 80],
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'summary' => 'Проверка завершена.',
                        'overall_assessment' => 'Результат проверен.',
                        'findings' => $findings,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]],
            ]],
        ];
    }

    private function finding(
        string $fragmentId,
        string $quote,
        string $title = 'Замечание',
    ): array {
        return [
            'finding_type' => 'compliance',
            'severity' => 'high',
            'title' => $title,
            'description' => 'Описание замечания',
            'document_fragment' => 'Фрагмент проекта',
            'document_location' => 'Пункт проекта',
            'legal_basis' => 'Правовое основание.',
            'recommendation' => 'Рекомендация.',
            'recommended_text' => 'Предлагаемый текст.',
            'justification' => 'Обоснование.',
            'confidence_score' => 90,
            'citations' => [[
                'fragment_id' => $fragmentId,
                'quote' => $quote,
                'article' => '12',
                'paragraph' => null,
                'subparagraph' => null,
            ]],
        ];
    }

    private function fixture(): array
    {
        config()->set('services.openai.key', 'fake-run-key');
        config()->set('services.openai.model', 'test-model');

        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $owner->id,
            'reference_number' => 'WS-RUN-'.uniqid(),
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'title' => 'Проект договора долевого участия',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'current_text' => 'Действующая редакция.',
            'proposed_text' => 'Изменяется форма договора долевого участия.',
            'analysis_instruction' => 'Проверить договор долевого участия по статье 12.',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $owner->id,
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

        return [$owner, $analysis, $version];
    }

    private function createExistingFinding(Analysis $analysis)
    {
        return $analysis->findings()->create([
            'finding_type' => 'legacy',
            'severity' => 'medium',
            'status' => 'open',
            'title' => 'Старое подтверждённое замечание',
            'description' => 'Старое описание',
            'document_fragment' => 'Старый фрагмент',
            'document_location' => 'Пункт 1',
            'source_reference' => 'Старая ссылка',
            'legal_basis' => 'Старое основание',
            'recommendation' => 'Старая рекомендация',
            'recommended_text' => 'Старый текст',
            'justification' => 'Старое обоснование',
            'confidence_score' => 80,
            'sort_order' => 1,
        ]);
    }
}
