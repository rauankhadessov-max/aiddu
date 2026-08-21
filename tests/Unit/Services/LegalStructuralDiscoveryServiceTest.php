<?php

namespace Tests\Unit\Services;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalRetrievalService;
use App\Services\LegalStructuralDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalStructuralDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_like_multiple_target_diff_resolves_new_subparagraph_and_new_article_independently(): void
    {
        [$analysis, $version] = $this->fixture(
            $this->productionLikeSource(),
            "Статья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n7) принимать решение по заявке;\n7-2) Отсутствует;\n8) запрашивать документы.\n\nСтатья 30-1. Отсутствует",
            "Статья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n7) принимать решение по заявке;\n7-2) реструктуризировать задолженность в установленном порядке;\n8) запрашивать документы.\n\nСтатья 30-1. Реструктуризация задолженности\n1. Единый оператор вправе принять решение о реструктуризации задолженности.",
            'Проверить две предлагаемые поправки.',
        );

        $service = app(LegalStructuralDiscoveryService::class);
        $intents = $service->detectIntents($analysis);
        $plan = $service->plan($analysis);
        $result = app(LegalRetrievalService::class)->retrieve($analysis, structuralPlan: $plan);

        $this->assertCount(2, $intents);
        $this->assertSame([
            ['subparagraph', '7-2', 'new', '26', '1'],
            ['article', '30-1', 'new', '30-1', null],
        ], collect($intents)->map(fn ($intent) => [
            $intent->elementType,
            $intent->locator,
            $intent->targetMode,
            $intent->article,
            $intent->paragraph,
        ])->values()->all());

        $targets = collect($result->contextSufficiency->targets)->keyBy(fn (array $target) => $target['element_type'].'|'.$target['locator']);
        $this->assertSame('resolved', $targets['subparagraph|7-2']['status']);
        $this->assertSame('26', $targets['subparagraph|7-2']['parent_article']);
        $this->assertSame('1', $targets['subparagraph|7-2']['parent_paragraph']);
        $this->assertSame('7', $targets['subparagraph|7-2']['predecessor']);
        $this->assertSame('8', $targets['subparagraph|7-2']['successor']);
        $this->assertSame('30', $targets['article|30-1']['predecessor']);
        $this->assertSame('31', $targets['article|30-1']['successor']);
        $this->assertSame('sufficient', $result->contextSufficiency->status);

        $groups = collect($result->retrievalAudit['groups'])->keyBy('locator');
        $this->assertSame('mandatory_parent', $groups['26']['role']);
        $this->assertSame('anchor_predecessor', $groups['30']['role']);
        $this->assertSame('anchor_successor', $groups['31']['role']);

        foreach (['26', '30', '31'] as $article) {
            $expected = collect(app(LegalRetrievalService::class)->split($version))->where('article', $article)->pluck('fragmentId')->sort()->values()->all();
            $selected = collect($result->fragments)->where('article', $article)->pluck('fragmentId')->sort()->values()->all();
            $this->assertSame($expected, $selected);
        }
    }

    public function test_explicit_absence_of_one_target_does_not_change_existing_mode_of_another(): void
    {
        [$analysis] = $this->fixture(
            $this->productionLikeSource(),
            "Статья 26. Права\n1. Единый оператор вправе:\n7) принимать решение.\n\nСтатья 30-1. Отсутствует",
            "Статья 26. Права\n1. Единый оператор обязан:\n7) принимать решение.\n\nСтатья 30-1. Новая статья\n1. Применяется новый порядок.",
            'Проверить обе поправки.',
        );

        $intents = app(LegalStructuralDiscoveryService::class)->detectIntents($analysis);
        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);

        $this->assertSame(['existing', 'new'], collect($intents)->pluck('targetMode')->values()->all());
        $this->assertSame(['26', '30-1'], collect($intents)->pluck('article')->values()->all());
        $this->assertSame('sufficient', $plan->sufficiency->status);
        $this->assertSame(['existing', 'new'], collect($plan->sufficiency->targets)->pluck('target_mode')->values()->all());
    }

    public function test_unresolved_target_does_not_mask_resolved_target_status(): void
    {
        [$analysis] = $this->fixture(
            $this->productionLikeSource(),
            "Статья 26. Права\nОператор принимает решение.\n\nСтатья 99-1. Отсутствует",
            "Статья 26. Права\nОператор принимает мотивированное решение.\n\nСтатья 99-1. Новая статья\n1. Применяется новый порядок.",
            'Проверить обе поправки.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);
        $targets = collect($plan->sufficiency->targets)->keyBy('locator');

        $this->assertSame('insufficient', $plan->sufficiency->status);
        $this->assertSame('resolved', $targets['26']['status']);
        $this->assertSame('insufficient', $targets['99-1']['status']);
        $this->assertContains('structural_anchor_not_found', $targets['99-1']['reasons']);
        $this->assertSame(['26'], collect($plan->mandatoryGroups)->pluck('article')->values()->all());
    }

    public function test_two_existing_articles_are_resolved_as_two_targets(): void
    {
        [$analysis] = $this->fixture(
            $this->productionLikeSource(),
            "Статья 26. Права\n1. Оператор принимает решение.\n\nСтатья 30. Взнос\n1. Взнос уплачивается.",
            "Статья 26. Права\n1. Оператор принимает мотивированное решение.\n\nСтатья 30. Взнос\n1. Взнос уплачивается единовременно.",
            'Проверить обе поправки.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);

        $this->assertSame('sufficient', $plan->sufficiency->status);
        $this->assertCount(2, $plan->sufficiency->targets);
        $this->assertSame(['existing'], collect($plan->sufficiency->targets)->pluck('target_mode')->unique()->values()->all());
        $this->assertSame(['26', '30'], collect($plan->mandatoryGroups)->pluck('article')->sort()->values()->all());
    }

    public function test_two_new_articles_resolve_independently_and_share_one_mandatory_plan(): void
    {
        [$analysis] = $this->fixture(
            $this->productionLikeSource(),
            "Статья 26-1. Отсутствует\n\nСтатья 30-1. Отсутствует",
            "Статья 26-1. Новая статья\n1. Новый порядок.\n\nСтатья 30-1. Новая статья\n1. Другой новый порядок.",
            'Проверить новые нормы.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);

        $this->assertSame('sufficient', $plan->sufficiency->status);
        $this->assertCount(2, $plan->sufficiency->targets);
        $this->assertSame(['new'], collect($plan->sufficiency->targets)->pluck('target_mode')->unique()->values()->all());
        $this->assertSame(['26', '27', '30', '31'], collect($plan->mandatoryGroups)->pluck('article')->sort()->values()->all());
    }

    public function test_parent_article_is_not_an_amendment_target_for_a_new_subparagraph(): void
    {
        [$analysis] = $this->fixture(
            $this->productionLikeSource(),
            "Статья 26. Права\n1. Оператор вправе:\n7) действовать;\n7-2) Отсутствует;\n8) уведомлять.",
            "Статья 26. Права\n1. Оператор вправе:\n7) действовать;\n7-2) принимать решение;\n8) уведомлять.",
            'Проверить новый подпункт.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);

        $this->assertCount(1, $plan->intents);
        $this->assertSame('subparagraph', $plan->intents[0]->elementType);
        $this->assertSame('mandatory_parent', $plan->mandatoryGroups[0]['role']);
        $this->assertSame('26', $plan->mandatoryGroups[0]['article']);
    }

    public function test_combined_mandatory_articles_over_total_budget_are_not_partially_selected(): void
    {
        config()->set('legal_analysis.retrieval.context_budget_chars', 30000);
        config()->set('legal_analysis.retrieval.structural_reserved_chars', 18000);
        $long = str_repeat('Полная обязательная норма должна передаваться атомарно и без сокращения. ', 180);
        [$analysis] = $this->fixture(
            "Статья 24. Первая\n1. {$long}\n\nСтатья 25. Вторая\n1. {$long}",
            "Статья 24. Первая\n1. Исходный текст.\n\nСтатья 25. Вторая\n1. Исходный текст.",
            "Статья 24. Первая\n1. Новая редакция.\n\nСтатья 25. Вторая\n1. Новая редакция.",
            'Проверить обе статьи.',
        );

        $result = app(LegalRetrievalService::class)->retrieve(
            $analysis,
            structuralPlan: app(LegalStructuralDiscoveryService::class)->plan($analysis),
        );

        $this->assertSame('insufficient', $result->contextSufficiency->status);
        $this->assertContains('mandatory_context_exceeds_total_budget', $result->contextSufficiency->reasons);
        $this->assertSame(0, $result->budgetAudit['mandatory_used']);
        $this->assertSame([], $result->fragments);
    }

    public function test_new_article_30_1_includes_complete_articles_30_and_31_as_anchors(): void
    {
        [$analysis, $version] = $this->fixture(
            $this->articlesThirtyAndThirtyOne(),
            "Отсутствует\n\nСтатья 30-1.\nОтсутствует",
            "Статья 30-1. Новый порядок реструктуризации\n1. Новый механизм применяется при наступлении установленного события.",
            'Проверить новую норму и подготовить юридическое обоснование.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);
        $result = app(LegalRetrievalService::class)->retrieve($analysis, structuralPlan: $plan);
        $all = app(LegalRetrievalService::class)->split($version);

        foreach (['30', '31'] as $article) {
            $expected = collect($all)->where('article', $article)->pluck('fragmentId')->sort()->values()->all();
            $selected = collect($result->fragments)->where('article', $article)->pluck('fragmentId')->sort()->values()->all();
            $this->assertNotEmpty($expected);
            $this->assertSame($expected, $selected, "Article {$article} must be included atomically.");
        }

        $this->assertSame('sufficient', $result->contextSufficiency->status);
        $this->assertTrue($result->contextSufficiency->mandatoryContextComplete);
        $this->assertSame('30', data_get($result->contextSufficiency->targets, '0.predecessor'));
        $this->assertSame('31', data_get($result->contextSufficiency->targets, '0.successor'));
        $this->assertTrue(collect($result->retrievalAudit['groups'])->every(fn (array $group) => $group['complete']));
    }

    public function test_changing_paragraph_of_article_24_makes_the_whole_article_mandatory(): void
    {
        [$analysis, $version] = $this->fixture(
            "Статья 24. Полномочия оператора\n1. Оператор осуществляет гарантирование проектов.\n2. Оператор утверждает внутренние документы.\n\nСтатья 25. Финансирование\n1. Финансирование осуществляется из разрешённых источников.",
            "Статья 24. Полномочия оператора\n1. Оператор осуществляет гарантирование проектов.",
            'Пункт 1 статьи 24 изложить в следующей редакции: оператор осуществляет гарантирование и сопровождение проектов.',
            'Оценить предлагаемое изменение.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);
        $result = app(LegalRetrievalService::class)->retrieve($analysis, structuralPlan: $plan);
        $expected = collect(app(LegalRetrievalService::class)->split($version))
            ->where('article', '24')->pluck('fragmentId')->sort()->values()->all();
        $selected = collect($result->fragments)
            ->where('article', '24')->pluck('fragmentId')->sort()->values()->all();

        $this->assertSame($expected, $selected);
        $this->assertSame('mandatory_parent', data_get($result->retrievalAudit, 'groups.0.role'));
        $this->assertTrue(data_get($result->retrievalAudit, 'groups.0.complete'));
    }

    public function test_optional_fragments_cannot_spend_reserved_structural_budget_or_displace_mandatory_group(): void
    {
        config()->set('legal_analysis.retrieval.context_budget_chars', 4000);
        config()->set('legal_analysis.retrieval.structural_reserved_chars', 3000);
        config()->set('legal_analysis.retrieval.optional_relevance_chars', 500);

        [$analysis, $version] = $this->fixture(
            "Статья 24. Целевая статья\n1. Редкий механизм применяется оператором.\n2. Полная целевая норма сохраняется.\n\nСтатья 25. Дополнительная норма\n1. Оператор применяет механизм к проекту.\n\nСтатья 26. Ещё одна норма\n1. Оператор применяет механизм к другому проекту.",
            "Статья 24. Целевая статья\n1. Редкий механизм применяется оператором.",
            'Пункт 1 статьи 24 изложить в новой редакции: редкий механизм применяется оператором незамедлительно.',
            'Проверить редкий механизм оператора и проект.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);
        $result = app(LegalRetrievalService::class)->retrieve($analysis, structuralPlan: $plan);
        $mandatoryIds = collect(app(LegalRetrievalService::class)->split($version))
            ->where('article', '24')->pluck('fragmentId')->sort()->values()->all();
        $selectedIds = collect($result->fragments)->pluck('fragmentId')->all();

        $this->assertEmpty(array_diff($mandatoryIds, $selectedIds));
        $this->assertLessThanOrEqual(500, $result->budgetAudit['optional_used']);
        $this->assertSame(3000, $result->budgetAudit['structural_reserved']);
        $this->assertTrue($result->contextSufficiency->mandatoryContextComplete);
    }

    public function test_oversized_mandatory_article_is_not_truncated_and_is_programmatically_insufficient(): void
    {
        config()->set('legal_analysis.retrieval.context_budget_chars', 600);
        config()->set('legal_analysis.retrieval.structural_reserved_chars', 400);
        config()->set('legal_analysis.retrieval.optional_relevance_chars', 200);
        $long = str_repeat('Обязательная правовая норма содержит детальное регулирование. ', 30);
        [$analysis] = $this->fixture(
            "Статья 24. Полномочия\n1. {$long}\n2. {$long}\n\nСтатья 25. Следующая статья\n1. Краткая норма.",
            'Статья 24. Полномочия.',
            'Пункт 1 статьи 24 изложить в новой редакции.',
            'Проверить изменение.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);
        $result = app(LegalRetrievalService::class)->retrieve($analysis, structuralPlan: $plan);

        $this->assertSame('insufficient', $result->contextSufficiency->status);
        $this->assertFalse($result->contextSufficiency->mandatoryContextComplete);
        $this->assertContains('mandatory_context_exceeds_total_budget', $result->contextSufficiency->reasons);
        $this->assertSame(0, $result->budgetAudit['mandatory_used']);
        $this->assertFalse(data_get($result->retrievalAudit, 'groups.0.complete'));
    }

    public function test_mentioning_article_only_as_legal_basis_does_not_make_it_a_target(): void
    {
        [$analysis] = $this->fixture(
            "Статья 10. Общие требования\n1. Решение должно быть обоснованным.\n\nСтатья 99. Заключительные положения\n1. Закон вводится в действие со дня опубликования.",
            'Решение принимается органом.',
            'Решение принимается органом после рассмотрения документов.',
            'Проверить редакцию с учётом статьи 99 как правового основания.',
        );

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis);

        $this->assertFalse($plan->isApplicable());
        $this->assertSame([], $plan->mandatoryGroups);
    }

    public function test_new_article_does_not_guess_anchors_across_ambiguous_source_versions(): void
    {
        [$analysis] = $this->fixture(
            $this->articlesThirtyAndThirtyOne(),
            "Отсутствует\nСтатья 30-1.\nОтсутствует",
            "Статья 30-1. Новый порядок\n1. Устанавливается новый механизм.",
            'Разработать новую норму.',
        );
        $secondSource = Source::create([
            'title' => 'Другой условный закон',
            'type' => 'law',
            'status' => 'active',
        ]);
        $secondText = $this->articlesThirtyAndThirtyOne();
        $secondVersion = SourceVersion::create([
            'source_id' => $secondSource->id,
            'version_name' => 'Редакция 2',
            'text' => $secondText,
            'hash' => hash('sha256', 'second-'.$secondText),
        ]);
        $analysis->sourceVersions()->attach($secondVersion->id, ['role' => 'reference']);

        $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis->fresh());

        $this->assertSame('insufficient', $plan->sufficiency->status);
        $this->assertContains('ambiguous_structural_scope', $plan->sufficiency->reasons);
        $this->assertNotEmpty($plan->sufficiency->ambiguities);
        $this->assertSame([], $plan->mandatoryGroups);
    }

    public function test_discovery_candidate_expands_generically_for_code_law_and_rules(): void
    {
        $cases = [
            ['code', "Раздел II. ОБЯЗАТЕЛЬСТВА\nГлава 4. Общие нормы\nСтатья 12. Срок\n1. Срок составляет десять дней.\n2. Срок исчисляется со следующего дня."],
            ['law', "Статья 7. Полномочия\n1. Орган принимает решение.\n2. Орган уведомляет заявителя."],
            ['rules', "Правила оказания услуги\n1. Заявление регистрируется.\n2. Заявление рассматривается.\n3. Результат направляется заявителю."],
        ];

        foreach ($cases as [$type, $text]) {
            [$analysis, $version] = $this->fixture($text, null, null, 'Уточнить срок и порядок.', $type);
            $all = app(LegalRetrievalService::class)->split($version);
            $candidate = $all[0]->fragmentId;
            $plan = app(LegalStructuralDiscoveryService::class)->plan($analysis, [$candidate]);
            $result = app(LegalRetrievalService::class)->retrieve($analysis, structuralPlan: $plan);
            $group = $plan->mandatoryGroups[0];
            $expected = collect($group['fragments'])->pluck('fragmentId')->sort()->values()->all();
            $selected = collect($result->fragments)->pluck('fragmentId')->sort()->values()->all();

            $this->assertEmpty(array_diff($expected, $selected), "Mandatory group must be complete for {$type}.");
            $this->assertSame('sufficient', $result->contextSufficiency->status);
        }
    }

    private function articlesThirtyAndThirtyOne(): string
    {
        return "Статья 29. Общие положения\n1. Общая норма применяется к оператору.\n\nСтатья 30. Гарантийный взнос\n1. Гарантийный взнос уплачивается единовременно.\n2. Уплаченный взнос возврату не подлежит.\n3. Размер взноса пересматривается при изменении стоимости.\n\nСтатья 31. Заявка на заключение договора\n1. Застройщик обращается к оператору с заявкой.\n2. Заявка рассматривается в установленном порядке.\n\nСтатья 32. Решение по заявке\n1. Оператор принимает решение по результатам рассмотрения.";
    }

    private function productionLikeSource(): string
    {
        return "Статья 25. Общие положения\n1. Общая норма.\n\nСтатья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n7) принимать мотивированное решение по поступившей заявке;\n8) запрашивать документы, необходимые для рассмотрения заявки.\n\nСтатья 27. Обязанности застройщика\n1. Застройщик представляет документы.\n\nСтатья 29. Общие положения\n1. Общая норма применяется к оператору.\n\nСтатья 30. Гарантийный взнос\n1. Гарантийный взнос уплачивается единовременно.\n2. Уплаченный взнос возврату не подлежит.\n\nСтатья 31. Заявка на заключение договора\n1. Застройщик обращается к оператору с заявкой.\n2. Заявка рассматривается в установленном порядке.\n\nСтатья 32. Решение по заявке\n1. Оператор принимает решение.";
    }

    private function fixture(
        string $sourceText,
        ?string $currentText,
        ?string $proposedText,
        string $instruction,
        string $sourceType = 'law',
    ): array {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-'.uniqid(),
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Проект поправки',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'current_text' => $currentText,
            'proposed_text' => $proposedText,
            'analysis_instruction' => $instruction,
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридический анализ',
            'analysis_type' => $proposedText === null ? 'amendment_drafting' : 'amendment_review',
            'instruction' => $instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $source = Source::create([
            'title' => 'Условный нормативный правовой акт',
            'type' => $sourceType,
            'status' => 'active',
        ]);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Тестовая редакция',
            'text' => $sourceText,
            'hash' => hash('sha256', $sourceText),
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);

        return [$analysis, $version];
    }
}
