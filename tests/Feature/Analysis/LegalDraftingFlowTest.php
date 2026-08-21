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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegalDraftingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_amendments_schema_supports_future_document_generation(): void
    {
        $this->assertTrue(Schema::hasColumns('analysis_amendments', [
            'analysis_id',
            'source_id',
            'source_version_id',
            'target_mode',
            'structural_element_type',
            'section',
            'chapter',
            'part',
            'article',
            'paragraph',
            'subparagraph',
            'text_paragraph',
            'appendix',
            'proposed_locator',
            'amendment_type',
            'disposition',
            'current_text',
            'proposed_text',
            'justification',
            'legal_basis',
            'source_reference',
            'confidence_score',
            'warnings',
            'target_fragment_ids',
            'anchor_fragment_ids',
            'citations',
            'target_snapshot',
            'sort_order',
        ]));
    }

    public function test_document_can_be_created_with_instruction_only_and_analysis_uses_drafting_mode(): void
    {
        [$user, $workspace, $source, $version] = $this->baseFixture();
        $workspace->sources()->attach($source->id);

        $this->actingAs($user)->post(route('documents.store', $workspace), [
            'title' => 'Разработка поправок по поручению',
            'document_type' => 'legal_norm',
            'language' => 'ru',
            'analysis_instruction' => 'Разработать требования к сроку рассмотрения заявления.',
        ])->assertRedirect();

        $document = Document::latest('id')->firstOrFail();
        $this->assertNull($document->current_text);
        $this->assertNull($document->proposed_text);

        $this->actingAs($user)->post(route('analyses.store', $document), [
            'source_versions' => [$version->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('analyses', [
            'document_id' => $document->id,
            'analysis_type' => 'amendment_drafting',
        ]);
    }

    public function test_scenario_a_keeps_valid_user_text_and_derives_current_text_from_context(): void
    {
        [$user, , , $version, $analysis] = $this->analysisFixture('amendment_review', 'Предлагаемая редакция пользователя.');
        config()->set('services.openai.key', 'fake-drafting-key');

        Http::fake(function (Request $request) use ($analysis, $version) {
            $fragmentId = data_get($request->data(), 'text.format.schema.properties.amendments.items.properties.target.properties.target_fragment_ids.items.enum.0');

            return Http::response($this->apiResponse($this->draftingResult(
                $analysis,
                $version,
                $fragmentId,
                disposition: 'keep_as_proposed',
                proposedText: 'Текст модели не должен заменить пользовательский.',
            )), 200);
        });

        $this->actingAs($user)->post(route('analyses.run', $analysis))->assertRedirect();

        $amendment = $analysis->fresh()->amendments()->firstOrFail();
        $this->assertSame('Предлагаемая редакция пользователя.', $amendment->proposed_text);
        $this->assertStringContainsString('Срок рассмотрения заявления составляет десять рабочих дней.', $amendment->current_text);
        $this->assertStringNotContainsString('Текст модели', $amendment->current_text);
    }

    public function test_scenario_b_discovers_target_and_saves_trusted_current_text(): void
    {
        [$user, , , $version, $analysis] = $this->analysisFixture('amendment_drafting', null);
        config()->set('services.openai.key', 'fake-drafting-key');
        $call = 0;

        Http::fake(function (Request $request) use (&$call, $analysis, $version) {
            $call++;

            if ($call === 1) {
                $fragmentId = data_get($request->data(), 'text.format.schema.properties.candidate_fragment_ids.items.enum.0');

                return Http::response($this->apiResponse([
                    'source_sufficiency' => 'sufficient',
                    'search_queries' => ['срок рассмотрения заявления'],
                    'candidate_fragment_ids' => [$fragmentId],
                    'warnings' => [],
                ], 'resp_discovery'), 200);
            }

            $fragmentId = data_get($request->data(), 'text.format.schema.properties.amendments.items.properties.target.properties.target_fragment_ids.items.enum.0');

            return Http::response($this->apiResponse($this->draftingResult(
                $analysis,
                $version,
                $fragmentId,
                disposition: 'draft',
                proposedText: 'Срок рассмотрения заявления составляет пять рабочих дней.',
            ), 'resp_drafting'), 200);
        });

        $this->actingAs($user)->post(route('analyses.run', $analysis))->assertRedirect();

        $analysis->refresh();
        $amendment = $analysis->amendments()->firstOrFail();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('Срок рассмотрения заявления составляет пять рабочих дней.', $amendment->proposed_text);
        $this->assertStringContainsString('десять рабочих дней', $amendment->current_text);
        $this->assertSame('resp_discovery', data_get($analysis->settings, 'discovery.response_id'));
        $this->assertSame('sufficient', data_get($analysis->settings, 'context_sufficiency.status'));
        $this->assertTrue(data_get($analysis->settings, 'context_sufficiency.mandatory_context_complete'));
        $this->assertGreaterThan(0, data_get($analysis->settings, 'budget_audit.mandatory_used'));
        $this->assertCount(2, Http::recorded());
    }

    public function test_scenario_b_returns_insufficient_without_fabricating_amendments(): void
    {
        [$user, , , , $analysis] = $this->analysisFixture('amendment_drafting', null);
        config()->set('services.openai.key', 'fake-drafting-key');

        Http::fake(function () {
            return Http::response($this->apiResponse([
                'source_sufficiency' => 'insufficient',
                'search_queries' => [],
                'candidate_fragment_ids' => [],
                'warnings' => ['Не хватает нормативного основания, регулирующего компетенцию уполномоченного органа.'],
            ], 'resp_insufficient'), 200);
        });

        $this->actingAs($user)->post(route('analyses.run', $analysis))->assertRedirect();

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('insufficient', data_get($analysis->settings, 'source_sufficiency'));
        $this->assertSame('insufficient', data_get($analysis->settings, 'context_sufficiency.status'));
        $this->assertContains('discovery_candidate', data_get($analysis->settings, 'context_sufficiency.missing_elements'));
        $this->assertSame([], $analysis->amendments()->get()->all());
        $this->assertStringContainsString('компетенцию', data_get($analysis->settings, 'warnings.0'));
        $this->assertCount(1, Http::recorded());
    }

    public function test_scenario_b_can_add_new_element_only_with_validated_parent_anchor(): void
    {
        [$user, , , $version, $analysis] = $this->analysisFixture('amendment_drafting', null);
        config()->set('services.openai.key', 'fake-drafting-key');
        $call = 0;

        Http::fake(function (Request $request) use (&$call, $analysis, $version) {
            $call++;
            $schema = $request->data()['text']['format']['schema'];

            if ($call === 1) {
                $fragmentId = data_get($schema, 'properties.candidate_fragment_ids.items.enum.0');

                return Http::response($this->apiResponse([
                    'source_sufficiency' => 'sufficient',
                    'search_queries' => ['срок уведомления'],
                    'candidate_fragment_ids' => [$fragmentId],
                    'warnings' => [],
                ], 'resp_discovery_new'), 200);
            }

            $fragmentId = data_get($schema, 'properties.amendments.items.properties.target.properties.anchor_fragment_ids.items.enum.0');

            return Http::response($this->apiResponse($this->newElementResult(
                $analysis,
                $version,
                $fragmentId,
                '3',
            ), 'resp_new_element'), 200);
        });

        $this->actingAs($user)
            ->post(route('analyses.run', $analysis))
            ->assertSessionHas('success');

        $amendment = $analysis->fresh()->amendments()->firstOrFail();
        $this->assertSame('new', $amendment->target_mode);
        $this->assertSame('3', $amendment->proposed_locator);
        $this->assertNull($amendment->current_text);
        $this->assertNotEmpty($amendment->anchor_fragment_ids);
    }

    public function test_model_cannot_present_an_existing_locator_as_a_new_element(): void
    {
        [$user, , , $version, $analysis] = $this->analysisFixture('amendment_drafting', null);
        config()->set('services.openai.key', 'fake-drafting-key');
        $call = 0;

        Http::fake(function (Request $request) use (&$call, $analysis, $version) {
            $call++;
            $schema = $request->data()['text']['format']['schema'];
            $fragmentId = $call === 1
                ? data_get($schema, 'properties.candidate_fragment_ids.items.enum.0')
                : data_get($schema, 'properties.amendments.items.properties.target.properties.anchor_fragment_ids.items.enum.0');

            if ($call === 1) {
                return Http::response($this->apiResponse([
                    'source_sufficiency' => 'sufficient',
                    'search_queries' => ['срок уведомления'],
                    'candidate_fragment_ids' => [$fragmentId],
                    'warnings' => [],
                ], 'resp_discovery_existing'), 200);
            }

            return Http::response($this->apiResponse($this->newElementResult(
                $analysis,
                $version,
                $fragmentId,
                '2',
            ), 'resp_existing_locator'), 200);
        });

        $this->actingAs($user)
            ->post(route('analyses.run', $analysis))
            ->assertSessionHas('error');

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertDatabaseCount('analysis_amendments', 0);
        $this->assertSame(
            'proposed_locator_already_exists',
            data_get($analysis->settings, 'amendment_validation.rejected.0.reasons.0.code'),
        );
    }

    public function test_fully_invalid_drafting_response_preserves_existing_amendments(): void
    {
        [$user, , $source, $version, $analysis] = $this->analysisFixture('amendment_review', 'Предлагаемая редакция пользователя.');
        config()->set('services.openai.key', 'fake-drafting-key');
        $old = $analysis->amendments()->create([
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'target_mode' => 'existing',
            'structural_element_type' => 'paragraph',
            'paragraph' => '1',
            'amendment_type' => 'new_edition',
            'disposition' => 'draft',
            'current_text' => 'Старая действующая редакция.',
            'proposed_text' => 'Старая предлагаемая редакция.',
            'justification' => 'Старое обоснование.',
            'legal_basis' => 'Старое правовое основание.',
            'source_reference' => 'Старая подтверждённая ссылка.',
            'confidence_score' => 80,
            'warnings' => [],
            'target_fragment_ids' => ['old-fragment'],
            'anchor_fragment_ids' => [],
            'citations' => [],
            'target_snapshot' => ['source_title' => $source->title],
            'sort_order' => 1,
        ]);

        Http::fake(fn () => Http::response($this->apiResponse($this->draftingResult(
            $analysis,
            $version,
            'sv999-fabricated',
            disposition: 'revise',
            proposedText: 'Неподтверждённый новый текст.',
        )), 200));

        $this->actingAs($user)
            ->post(route('analyses.run', $analysis))
            ->assertSessionHas('error');

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertDatabaseHas('analysis_amendments', [
            'id' => $old->id,
            'proposed_text' => 'Старая предлагаемая редакция.',
        ]);
        $this->assertDatabaseCount('analysis_amendments', 1);
        $this->assertSame(1, data_get($analysis->settings, 'amendment_validation.rejected_count'));
    }

    private function analysisFixture(string $mode, ?string $proposedText): array
    {
        [$user, $workspace, $source, $version] = $this->baseFixture();
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Изменение срока рассмотрения заявления',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'current_text' => $proposedText === null ? null : 'Пункт 1 действующей редакции.',
            'proposed_text' => $proposedText,
            'analysis_instruction' => 'Уточнить срок рассмотрения заявления.',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридическая разработка поправок',
            'analysis_type' => $mode,
            'instruction' => $document->analysis_instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);

        return [$user, $workspace, $source, $version, $analysis];
    }

    private function baseFixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-'.uniqid(),
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $source = Source::create([
            'title' => 'Условные правила рассмотрения заявлений',
            'type' => 'rules',
            'status' => 'active',
        ]);
        $text = "1. Срок рассмотрения заявления составляет десять рабочих дней.\n2. Решение направляется заявителю в электронной форме.";
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);

        return [$user, $workspace, $source, $version];
    }

    private function draftingResult(
        Analysis $analysis,
        SourceVersion $version,
        string $fragmentId,
        string $disposition,
        string $proposedText,
    ): array {
        $citation = fn (string $purpose) => [
            'purpose' => $purpose,
            'fragment_id' => $fragmentId,
            'quote' => 'Срок рассмотрения заявления составляет десять рабочих дней.',
            'article' => null,
            'paragraph' => '1',
            'subparagraph' => null,
        ];

        return [
            'scenario' => $analysis->analysis_type,
            'summary' => 'Подготовлена подтверждённая поправка.',
            'overall_assessment' => 'Поправка соответствует переданному контексту.',
            'source_sufficiency' => ['status' => 'sufficient', 'warnings' => []],
            'findings' => [],
            'amendments' => [[
                'target' => [
                    'target_mode' => 'existing',
                    'source_version_id' => $version->id,
                    'structural_element_type' => 'paragraph',
                    'target_fragment_ids' => [$fragmentId],
                    'anchor_fragment_ids' => [],
                    'proposed_locator' => null,
                ],
                'amendment_type' => 'new_edition',
                'disposition' => $disposition,
                'proposed_text' => $proposedText,
                'justification' => 'Сокращение срока повышает определённость регулирования.',
                'legal_basis' => 'Действующая норма прямо устанавливает срок.',
                'confidence_score' => 91,
                'warnings' => [],
                'citations' => [$citation('target_current_text'), $citation('legal_basis')],
            ]],
        ];
    }

    private function apiResponse(array $result, string $id = 'resp_drafting'): array
    {
        return [
            'id' => $id,
            'model' => 'test-model',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]],
            ]],
        ];
    }

    private function newElementResult(
        Analysis $analysis,
        SourceVersion $version,
        string $fragmentId,
        string $proposedLocator,
    ): array {
        $citation = fn (string $purpose) => [
            'purpose' => $purpose,
            'fragment_id' => $fragmentId,
            'quote' => 'Срок рассмотрения заявления составляет десять рабочих дней.',
            'article' => null,
            'paragraph' => '1',
            'subparagraph' => null,
        ];

        return [
            'scenario' => $analysis->analysis_type,
            'summary' => 'Подготовлен новый структурный элемент.',
            'overall_assessment' => 'Родительская норма подтверждена.',
            'source_sufficiency' => ['status' => 'sufficient', 'warnings' => []],
            'findings' => [],
            'amendments' => [[
                'target' => [
                    'target_mode' => 'new',
                    'source_version_id' => $version->id,
                    'structural_element_type' => 'paragraph',
                    'target_fragment_ids' => [],
                    'anchor_fragment_ids' => [$fragmentId],
                    'proposed_locator' => $proposedLocator,
                ],
                'amendment_type' => 'add_element',
                'disposition' => 'draft',
                'proposed_text' => 'Уведомление направляется не позднее следующего рабочего дня.',
                'justification' => 'Новый пункт устраняет неопределённость срока уведомления.',
                'legal_basis' => 'Родительская норма регулирует рассмотрение заявления.',
                'confidence_score' => 84,
                'warnings' => [],
                'citations' => [$citation('parent_anchor'), $citation('legal_basis')],
            ]],
        ];
    }
}
