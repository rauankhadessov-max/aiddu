<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalDraftingService;
use App\Services\LegalRetrievalService;
use App\Services\LegalStructuralDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StructuralRetrievalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_four_multiple_target_regression_sends_complete_parent_and_anchor_articles_in_one_request(): void
    {
        [$analysis, $version] = $this->fixture();
        config()->set('services.openai.key', 'fake-structural-key');
        $capturedInput = null;

        Http::fake(function (Request $request) use (&$capturedInput) {
            $capturedInput = $request->data()['input'];

            return Http::response([
                'id' => 'resp_structural_regression',
                'model' => 'test-model',
                'usage' => ['input_tokens' => 400, 'output_tokens' => 80],
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'scenario' => 'amendment_review',
                            'summary' => 'Структурный контекст проверен.',
                            'overall_assessment' => 'Статьи, окружающие новый элемент, переданы полностью.',
                            'source_sufficiency' => ['status' => 'sufficient', 'warnings' => []],
                            'findings' => [],
                            'amendments' => [],
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ], 200);
        });

        $result = app(LegalDraftingService::class)->run($analysis);
        $settings = $result->settings();
        $allFragments = app(LegalRetrievalService::class)->split($version);

        foreach (['26', '30', '31'] as $article) {
            $expected = collect($allFragments)->where('article', $article)->pluck('fragmentId')->sort()->values()->all();
            $selected = collect($result->retrieval->fragments)->where('article', $article)->pluck('fragmentId')->sort()->values()->all();

            $this->assertNotEmpty($expected);
            $this->assertSame($expected, $selected);

            foreach (collect($allFragments)->where('article', $article) as $fragment) {
                $this->assertStringContainsString($fragment->text, $capturedInput);
            }
        }

        $this->assertSame('sufficient', data_get($settings, 'context_sufficiency.status'));
        $this->assertTrue(data_get($settings, 'context_sufficiency.mandatory_context_complete'));
        $targets = collect(data_get($settings, 'context_sufficiency.targets'))->keyBy(
            fn (array $target) => $target['element_type'].'|'.$target['locator'],
        );
        $this->assertCount(2, $targets);
        $this->assertSame('new', $targets['subparagraph|7-2']['target_mode']);
        $this->assertSame('26', $targets['subparagraph|7-2']['parent_article']);
        $this->assertSame('1', $targets['subparagraph|7-2']['parent_paragraph']);
        $this->assertSame('new', $targets['article|30-1']['target_mode']);
        $this->assertSame('30', $targets['article|30-1']['predecessor']);
        $this->assertSame('31', $targets['article|30-1']['successor']);
        $this->assertArrayNotHasKey('paragraph|1', $targets);
        $this->assertGreaterThan(0, data_get($settings, 'budget_audit.mandatory_used'));
        $this->assertLessThanOrEqual(12000, data_get($settings, 'budget_audit.optional_used'));
        $this->assertSame(30000, data_get($settings, 'budget_audit.total_limit'));
        $this->assertTrue(collect(data_get($settings, 'retrieval_audit.groups'))->every(
            fn (array $group) => $group['complete'] === true,
        ));
        $this->assertCount(1, Http::recorded());
    }

    public function test_production_like_seven_source_case_resolves_target_source_and_keeps_cross_source_context(): void
    {
        config()->set('services.openai.key', 'fake-target-source-key');
        [$user, $workspace, $targetVersion, $otherVersions] = $this->sevenSourceFixture();
        $capturedInput = null;

        Http::fake(function (Request $request) use (&$capturedInput) {
            $capturedInput = $request->data()['input'];

            return Http::response([
                'id' => 'resp_target_source_regression',
                'model' => 'test-model',
                'usage' => ['input_tokens' => 900, 'output_tokens' => 80],
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'scenario' => 'amendment_review',
                            'summary' => 'Целевой НПА определён, межотраслевая проверка выполнена.',
                            'overall_assessment' => 'Предлагаемая редакция проверена.',
                            'source_sufficiency' => ['status' => 'sufficient', 'warnings' => []],
                            'findings' => [],
                            'amendments' => [],
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ], 200);
        });

        $response = $this->actingAs($user)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'title' => 'Изменение пункта 1 статьи 13 Закона о долевом участии',
            'current_text' => "Статья 13. Изменение и расторжение договора о долевом участии в жилищном строительстве\n1. В договор после его заключения по согласию сторон могут быть внесены изменения и дополнения.",
            'proposed_text' => "Статья 13. Изменение и расторжение договора о долевом участии в жилищном строительстве\n1. В договор могут быть внесены изменения только в случаях, установленных настоящим Законом.",
            'analysis_instruction' => 'Проведи анализ пункта 1 статьи 13 Закона Республики Казахстан «О долевом участии в жилищном строительстве». Проверь соответствие предлагаемого ограничения гражданскому законодательству Республики Казахстан, включая принцип свободы договора, основания и порядок изменения и расторжения договора. Проверь соответствие типовой форме договора о долевом участии в жилищном строительстве.',
        ]);

        $analysis = Analysis::with('sourceVersions')->sole();
        $settings = $analysis->settings;
        $target = data_get($settings, 'context_sufficiency.targets.0');

        $response->assertRedirect(route('analyses.show', $analysis));
        $this->assertSame($workspace->id, $analysis->workspace_id);
        $this->assertCount(7, $analysis->sourceVersions);
        $this->assertSame(
            collect([$targetVersion->id, ...collect($otherVersions)->pluck('id')->all()])->sort()->values()->all(),
            $analysis->sourceVersions->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame('sufficient', data_get($settings, 'context_sufficiency.status'));
        $this->assertSame('resolved', $target['source_resolution_status']);
        $this->assertSame($targetVersion->source_id, $target['target_source_id']);
        $this->assertSame($targetVersion->id, $target['target_source_version_id']);
        $this->assertSame('paragraph', $target['element_type']);
        $this->assertSame('13', $target['article']);
        $this->assertSame('1', $target['paragraph']);
        $this->assertContains('exact_current_text', $target['source_resolution_evidence']);
        $this->assertContains('explicit_instruction_title', $target['source_resolution_evidence']);
        $this->assertSame([], $target['source_resolution_ambiguity_reasons']);
        $this->assertTrue(collect(data_get($settings, 'retrieval_audit.groups'))->every(
            fn (array $group) => (int) $group['source_version_id'] === $targetVersion->id
                && $group['locator'] === '13'
                && $group['complete'] === true,
        ));
        $this->assertCount(7, collect(data_get($settings, 'retrieval_audit.fragments'))->pluck('source_version_id')->unique());
        $this->assertTrue(collect(data_get($settings, 'retrieval_audit.fragments'))->contains(
            fn (array $fragment) => (int) $fragment['source_version_id'] !== $targetVersion->id
                && in_array($fragment['role'], ['cross_source', 'optional'], true)
                && $fragment['selected'] === true,
        ));
        $civilVersion = collect($otherVersions)->first(fn (SourceVersion $version) => str_contains($version->source->title, 'Гражданский кодекс'));
        $civilContext = collect(data_get($settings, 'retrieval_context'))
            ->where('source_version_id', $civilVersion->id);
        $coverage = collect(data_get($settings, 'retrieval_audit.cross_source_coverage'))
            ->first(fn (array $entry) => collect($entry['requested_sources'])->contains(
                fn (array $source) => (int) $source['source_version_id'] === $civilVersion->id,
            ));

        $this->assertNotNull($coverage);
        $this->assertSame('retrieved', $coverage['coverage_status']);
        $this->assertNotEmpty($coverage['selected_fragment_ids']);
        $this->assertContains('380', $civilContext->pluck('article')->all());
        $this->assertTrue($civilContext->pluck('article')->intersect(['401', '402'])->isNotEmpty());
        $this->assertTrue($civilContext->every(fn (array $fragment) => str_starts_with($fragment['fragment_id'], 'sv'.$civilVersion->id.'-')));
        $this->assertLessThanOrEqual(30000, data_get($settings, 'budget_audit.total_used'));
        $this->assertGreaterThan(0, data_get($settings, 'budget_audit.cross_source_used'));
        $this->assertStringContainsString('Статья 13. Изменение и расторжение договора', $capturedInput);
        $this->assertStringContainsString('"article":"380"', $capturedInput);
        $this->assertStringContainsString('Граждане и юридические лица свободны в заключении договора', $capturedInput);
        $this->assertStringContainsString('Статья 401. Основания изменения и расторжения договора', $capturedInput);
        $this->assertCount(1, Http::recorded());
    }

    public function test_explicit_other_npa_overrides_default_primary_source(): void
    {
        Http::fake();
        [$analysis, $primaryVersion, $otherVersion] = $this->twoSourceResolutionFixture(
            instruction: 'Проверить пункт 1 статьи 13 Закона Республики Казахстан «О жилищных отношениях».',
            currentText: "Статья 13. Пользовательская действующая редакция\n1. Текст не совпадает с нормативной базой.",
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);
        $target = $plan->sufficiency->targets[0];

        $this->assertSame('resolved', $target['source_resolution_status']);
        $this->assertSame($otherVersion->id, $target['target_source_version_id']);
        $this->assertNotSame($primaryVersion->id, $target['target_source_version_id']);
        $this->assertContains('explicit_instruction_title', $target['source_resolution_evidence']);
        Http::assertNothingSent();
    }

    public function test_conflicting_explicit_title_and_exact_current_text_remains_ambiguous(): void
    {
        Http::fake();
        [$analysis, $primaryVersion, $otherVersion] = $this->twoSourceResolutionFixture(
            instruction: 'Проверить пункт 1 статьи 13 Закона Республики Казахстан «О жилищных отношениях».',
            currentText: "Статья 13. Изменение договора долевого участия\n1. Договор изменяется по соглашению сторон.",
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);
        $target = $plan->sufficiency->targets[0];

        $this->assertSame('insufficient', $plan->sufficiency->status);
        $this->assertSame('ambiguous', $target['source_resolution_status']);
        $this->assertNull($target['target_source_version_id']);
        $this->assertContains('conflicting_exact_current_text_and_explicit_source_title', $target['source_resolution_ambiguity_reasons']);
        $this->assertContains('ambiguous_target_source', $target['reasons']);
        $this->assertContains($primaryVersion->id, collect($target['candidates'])->pluck('source_version_id')->all());
        $this->assertContains($otherVersion->id, collect($target['candidates'])->pluck('source_version_id')->all());
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-STRUCTURAL-REGRESSION',
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Реструктуризация задолженности v2',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'current_text' => "Статья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n...\n7-2) Отсутствует;\n\nСтатья 30-1. Отсутствует",
            'proposed_text' => "Статья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n…\n7-2) реструктуризировать задолженность в установленном порядке;\n\nСтатья 30-1. Реструктуризация задолженности\n1. Единый оператор вправе принять решение о реструктуризации задолженности.",
            'analysis_instruction' => 'Проверить новый подпункт и новую статью, подготовить обоснование.',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридический анализ',
            'analysis_type' => 'amendment_review',
            'instruction' => $document->analysis_instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $source = Source::create([
            'title' => 'Условный закон о гарантиях',
            'type' => 'law',
            'status' => 'active',
        ]);
        $text = "Глава 6. Полномочия и гарантии\nСтатья 25. Общие положения\n1. Оператор действует в пределах компетенции.\n\nСтатья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n7) принимать мотивированное решение по поступившей заявке;\n8) запрашивать документы, необходимые для рассмотрения заявки.\n\nСтатья 27. Обязанности застройщика\n1. Застройщик представляет необходимые документы оператору.\n\nСтатья 29. Общие положения\n1. Оператор действует в пределах установленной компетенции.\n\nСтатья 30. Гарантийный взнос\n1. Гарантийный взнос уплачивается единовременно.\n2. Взнос возврату не подлежит.\n3. Размер взноса определяется по утверждённой методике.\n\nГлава 7. Рассмотрение заявок\nСтатья 31. Заявка на заключение договора\n1. Заявитель обращается к оператору с заявкой.\n2. Заявка рассматривается в установленном порядке.\n\nСтатья 32. Решение\n1. Оператор принимает мотивированное решение.";
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);

        return [$analysis, $version];
    }

    private function sevenSourceFixture(): array
    {
        $user = User::factory()->create();
        $profile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'regulatory_profile_id' => $profile->id,
            'reference_number' => 'WS-SEVEN-SOURCES',
            'title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $definitions = [
            ['Закон Республики Казахстан "О долевом участии в жилищном строительстве"', 'law', "Статья 12. Заключение договора\n1. Договор заключается письменно.\n\nСтатья 13. Изменение и расторжение договора о долевом участии в жилищном строительстве\n1. В договор после его заключения по согласию сторон могут быть внесены изменения и дополнения.\n2. Уступка права требования допускается после оплаты цены договора.\n\nСтатья 14. Учет договора\n1. Договор подлежит учету."],
            ['Типовая форма договора о долевом участии в жилищном строительстве', 'order', "1. Предмет договора о долевом участии.\n2. Изменения оформляются дополнительным соглашением."],
            ['ДДУ в рамках реновации', 'order', "1. Договор реновации заключается письменно.\n2. Дополнительное соглашение подлежит учету."],
            ['Типовая форма договора о предоставлении гарантии', 'order', "1. Гарантия обеспечивает обязательства.\n2. Изменение договора требует проверки гарантии."],
            ['О жилищных отношениях', 'law', "Статья 13. Приобретение права собственности на жилище\n1. Наниматель вправе приватизировать жилище.\n2. Жилище переходит в общую собственность."],
            ['Гражданский кодекс Республики Казахстан', 'code', "Статья 2. Основные начала гражданского законодательства\n1. Гражданское законодательство основывается на признании равенства участников, неприкосновенности собственности и свободы договора.\n\nСтатья 13. Правоспособность граждан\n1. Граждане обладают гражданскими правами.\n2. Правоспособность прекращается смертью.\n\nСтатья 380. Свобода договора\n1. Граждане и юридические лица свободны в заключении договора.\n2. Стороны могут заключить договор, предусмотренный и не предусмотренный законодательством.\n\nСтатья 401. Основания изменения и расторжения договора\n1. Изменение и расторжение договора возможны по соглашению сторон, если иное не предусмотрено кодексом, законами или договором.\n2. По требованию стороны договор может быть изменен или расторгнут судом.\n\nСтатья 402. Порядок изменения и расторжения договора\n1. Соглашение об изменении и расторжении договора совершается в той же форме, что и договор.\n2. Требование может быть заявлено после получения отказа другой стороны."],
            ['СТРОИТЕЛЬНЫЙ КОДЕКС РЕСПУБЛИКИ КАЗАХСТАН', 'code', "Статья 13. Обеспечение экологических требований\n1. Строительная деятельность осуществляется с учетом экологических требований.\n2. Проектная документация содержит природоохранные мероприятия."],
        ];
        $versions = [];

        foreach ($definitions as $index => [$title, $type, $text]) {
            $source = Source::create(['title' => $title, 'type' => $type, 'status' => 'active']);
            $version = SourceVersion::create([
                'source_id' => $source->id,
                'version_name' => 'Действующая редакция '.$index,
                'text' => $text,
                'hash' => hash('sha256', $text),
            ]);
            $profile->sources()->attach($source, ['sort_order' => $index, 'is_primary' => $index === 0]);
            $workspace->sources()->attach($source, ['is_primary' => $index === 0]);
            $versions[] = $version;
        }

        return [$user, $workspace, $versions[0], array_slice($versions, 1)];
    }

    private function twoSourceResolutionFixture(string $instruction, string $currentText): array
    {
        $user = User::factory()->create();
        $profile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'regulatory_profile_id' => $profile->id,
            'reference_number' => 'WS-TARGET-SOURCE',
            'title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Поправка к статье 13',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'current_text' => $currentText,
            'proposed_text' => "Статья 13. Предлагаемая редакция\n1. Новый текст пункта.",
            'analysis_instruction' => $instruction,
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Проверка выбора целевого НПА',
            'analysis_type' => 'amendment_review',
            'instruction' => $instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $primarySource = Source::create(['title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»', 'type' => 'law', 'status' => 'active']);
        $primaryText = "Статья 13. Изменение договора долевого участия\n1. Договор изменяется по соглашению сторон.\n2. Уступка права допускается после оплаты.";
        $primaryVersion = SourceVersion::create(['source_id' => $primarySource->id, 'version_name' => 'Редакция 1', 'text' => $primaryText, 'hash' => hash('sha256', $primaryText)]);
        $otherSource = Source::create(['title' => 'Закон Республики Казахстан «О жилищных отношениях»', 'type' => 'law', 'status' => 'active']);
        $otherText = "Статья 13. Приобретение права собственности на жилище\n1. Наниматель вправе приватизировать жилище.\n2. Жилище переходит в общую собственность.";
        $otherVersion = SourceVersion::create(['source_id' => $otherSource->id, 'version_name' => 'Редакция 2', 'text' => $otherText, 'hash' => hash('sha256', $otherText)]);
        $profile->sources()->attach($primarySource, ['sort_order' => 0, 'is_primary' => true]);
        $workspace->sources()->attach($primarySource, ['is_primary' => true]);
        $workspace->sources()->attach($otherSource, ['is_primary' => false]);
        $analysis->sourceVersions()->attach([$primaryVersion->id, $otherVersion->id], ['role' => 'reference']);

        return [$analysis, $primaryVersion, $otherVersion];
    }
}
