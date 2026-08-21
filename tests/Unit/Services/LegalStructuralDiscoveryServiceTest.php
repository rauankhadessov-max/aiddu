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
