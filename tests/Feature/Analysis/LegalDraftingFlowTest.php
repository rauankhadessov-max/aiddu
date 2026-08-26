<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalRetrievalService;
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
                    'source_sufficiency' => 'insufficient',
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

    public function test_scenario_b_separates_target_from_supporting_context_across_seven_sources(): void
    {
        [$user, $analysis, $versions] = $this->productionLikeDiscoveryFixture();
        $retrieval = app(LegalRetrievalService::class);
        $fragment = function (string $sourceKey, string $article, ?string $paragraph = null) use ($retrieval, $versions) {
            return collect($retrieval->split($versions[$sourceKey]))
                ->first(fn ($item) => $item->article === $article
                    && ($paragraph === null || $item->paragraph === $paragraph));
        };
        $target = $fragment('law', '13', '1');
        $lawSupport = $fragment('law', '12', '1');
        $formSupport = $fragment('form', '', '32')
            ?? collect($retrieval->split($versions['form']))->firstWhere('paragraph', '32');
        $civilSupport = $fragment('civil', '401', '1');
        $civilProcedure = $fragment('civil', '402', '1');

        $this->assertNotNull($target);
        $this->assertNotNull($lawSupport);
        $this->assertNotNull($formSupport);
        $this->assertNotNull($civilSupport);
        $this->assertNotNull($civilProcedure);

        config()->set('services.openai.key', 'fake-scenario-b-supporting-key');
        $draftingInput = null;
        $call = 0;

        Http::fake(function (Request $request) use (
            &$call,
            &$draftingInput,
            $target,
            $lawSupport,
            $formSupport,
            $civilSupport,
            $civilProcedure,
        ) {
            $call++;

            if ($call === 1) {
                return Http::response($this->apiResponse([
                    'source_sufficiency' => 'insufficient',
                    'search_queries' => [
                        'типовая форма договора изменение дополнительное соглашение',
                        'гражданский кодекс изменение и расторжение договора',
                    ],
                    'candidate_fragment_ids' => [
                        $target->fragmentId,
                        $lawSupport->fragmentId,
                        $formSupport->fragmentId,
                        $civilSupport->fragmentId,
                        $civilProcedure->fragmentId,
                    ],
                    'target_candidate_fragment_ids' => [$target->fragmentId],
                    'supporting_candidate_fragment_ids' => [
                        $lawSupport->fragmentId,
                        $formSupport->fragmentId,
                        $civilSupport->fragmentId,
                        $civilProcedure->fragmentId,
                    ],
                    'requested_legal_issues' => [
                        'Порядок изменения договора по Гражданскому кодексу Республики Казахстан',
                        'Оформление дополнительного соглашения по Типовой форме договора о долевом участии',
                    ],
                    'warnings' => ['Ограниченный структурный каталог требует локального поиска по полным текстам.'],
                ], 'resp_discovery_separated'), 200);
            }

            $draftingInput = $request->data()['input'];

            return Http::response($this->apiResponse([
                'scenario' => 'amendment_drafting',
                'summary' => 'Нормы для разработки поправки найдены.',
                'overall_assessment' => 'Целевой и supporting context разделены.',
                'source_sufficiency' => ['status' => 'sufficient', 'warnings' => []],
                'findings' => [],
                'amendments' => [],
            ], 'resp_drafting_separated'), 200);
        });

        $this->actingAs($user)->post(route('analyses.run', $analysis))->assertRedirect();

        $settings = $analysis->fresh()->settings;
        $context = collect(data_get($settings, 'retrieval_context'));
        $formAudit = collect(data_get($settings, 'retrieval_audit.fragments'))
            ->where('source_version_id', $versions['form']->id);

        $this->assertSame('completed', $analysis->fresh()->status);
        $this->assertSame('sufficient', data_get($settings, 'context_sufficiency.status'));
        $this->assertSame('partial', data_get($settings, 'discovery.source_sufficiency'));
        $this->assertSame([$target->fragmentId], data_get($settings, 'discovery.target_candidate_fragment_ids'));
        $this->assertContains($formSupport->fragmentId, data_get($settings, 'discovery.supporting_candidate_fragment_ids'));
        $this->assertTrue(collect(data_get($settings, 'retrieval_audit.groups'))->every(
            fn (array $group) => (int) $group['source_version_id'] === $versions['law']->id
                && $group['type'] === 'article'
                && $group['locator'] === '13',
        ));
        $this->assertFalse($formAudit->contains(fn (array $item) => $item['role'] === 'mandatory'));
        $this->assertLessThan($formAudit->count(), $formAudit->where('selected', true)->count());
        $formSupportingAudit = collect(data_get($settings, 'retrieval_audit.supporting_candidates'))
            ->firstWhere('candidate_fragment_id', $formSupport->fragmentId);
        $this->assertSame('retrieved', $formSupportingAudit['coverage_status']);
        $this->assertTrue($context->contains(fn (array $item) => $item['fragment_id'] === $lawSupport->fragmentId));
        $this->assertTrue($context->contains(fn (array $item) => $item['fragment_id'] === $formSupport->fragmentId));
        $this->assertTrue($context->contains(fn (array $item) => in_array($item['fragment_id'], [
            $civilSupport->fragmentId,
            $civilProcedure->fragmentId,
        ], true)));
        $this->assertLessThanOrEqual(30000, data_get($settings, 'budget_audit.total_used'));
        $this->assertLessThanOrEqual(30000, data_get($settings, 'budget_audit.mandatory_required'));
        $this->assertGreaterThan(0, data_get($settings, 'budget_audit.mandatory_used'));
        $this->assertGreaterThan(0, data_get($settings, 'budget_audit.supporting_used'));
        $this->assertStringContainsString('дополнительного соглашения', $draftingInput);
        $this->assertStringContainsString('Изменение и расторжение договора', $draftingInput);
        $this->assertFalse(collect(data_get($settings, 'warnings'))->contains(
            fn (string $warning) => str_contains(mb_strtolower($warning), 'норма отсутствует'),
        ));
        $this->assertCount(2, Http::recorded());
    }

    private function productionLikeDiscoveryFixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-DISCOVERY-'.uniqid(),
            'title' => 'Рабочее дело по долевому строительству',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Дополнительные соглашения при продлении срока строительства',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'analysis_instruction' => 'Разработать механизм заключения дополнительных соглашений при продлении срока строительства.',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Production-like Scenario B',
            'analysis_type' => 'amendment_drafting',
            'instruction' => $document->analysis_instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $formParagraphs = [];

        for ($number = 1; $number <= 40; $number++) {
            $formParagraphs[] = match ($number) {
                1 => '1. Строительство завершается в установленный договором срок.',
                2 => '2. Доля передается после приемки объекта.',
                31 => '31. Стороны исполняют обязательства надлежащим образом.',
                32 => '32. Изменения оформляются путем заключения дополнительного соглашения с обязательной постановкой на учет.',
                33 => '33. Споры разрешаются в установленном порядке.',
                default => $number.'. Технические сведения формы заполняются в установленном порядке.',
            };
        }

        $definitions = [
            'law' => ['Закон о долевом участии в жилищном строительстве', 'law', "Статья 12. Учет договоров\n1. Изменения и дополнения к договору подлежат обязательному учету.\n\nСтатья 13. Изменение и расторжение договора\n1. Изменения в договор вносятся по соглашению сторон в порядке гражданского законодательства."],
            'form' => ['Типовая форма договора о долевом участии', 'order', implode("\n", $formParagraphs)],
            'civil' => ['Гражданский кодекс Республики Казахстан', 'code', "Статья 380. Свобода договора\n1. Стороны свободны в заключении договора.\n\nСтатья 401. Основания изменения и расторжения договора\n1. Изменение договора возможно по соглашению сторон.\n\nСтатья 402. Порядок изменения договора\n1. Соглашение об изменении договора совершается в той же форме, что и договор."],
            'building' => ['Строительный кодекс Республики Казахстан', 'code', "Статья 90. Приемка объекта\n1. Завершенный объект принимается в эксплуатацию в установленном порядке."],
            'housing' => ['Закон о жилищных отношениях', 'law', "Статья 10. Жилищные отношения\n1. Право на жилище охраняется законом."],
            'guarantee' => ['Правила предоставления гарантии', 'rules', "1. Гарантия обеспечивает завершение строительства.\n2. Условия гарантии определяются договором."],
            'unrelated' => ['Правила технического учета', 'rules', "1. Оборудование проходит техническую проверку.\n2. Результат проверки регистрируется."],
        ];
        $versions = [];

        foreach ($definitions as $key => [$title, $type, $text]) {
            $source = Source::create(['title' => $title, 'type' => $type, 'status' => 'active']);
            $version = SourceVersion::create([
                'source_id' => $source->id,
                'version_name' => 'Действующая редакция',
                'text' => $text,
                'hash' => hash('sha256', $text),
            ]);
            $workspace->sources()->attach($source);
            $analysis->sourceVersions()->attach($version, ['role' => 'reference']);
            $versions[$key] = $version;
        }

        return [$user, $analysis, $versions];
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
