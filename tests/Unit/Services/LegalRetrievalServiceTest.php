<?php

namespace Tests\Unit\Services;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalRetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_structural_splitting_produces_stable_fragments_and_unicode_offsets(): void
    {
        [$analysis, $version] = $this->fixture(<<<'LAW'
Статья 12. Требования к договору
1. Договор заключается в письменной форме.
1) В договоре указывается цена объекта.
2) В договоре указывается срок передачи.
2. Запрещается одностороннее изменение условий договора.

Статья 13. Ответственность сторон
За нарушение обязательств стороны несут ответственность.
LAW);

        $service = app(LegalRetrievalService::class);
        $first = $service->split($version);
        $second = $service->split($version);

        $this->assertNotEmpty($first);
        $this->assertSame(
            array_map(fn ($fragment) => $fragment->fragmentId, $first),
            array_map(fn ($fragment) => $fragment->fragmentId, $second),
        );
        $this->assertContains('12', array_column(array_map(fn ($fragment) => $fragment->toArray(), $first), 'article'));
        $this->assertContains('1', array_column(array_map(fn ($fragment) => $fragment->toArray(), $first), 'paragraph'));
        $this->assertContains('2', array_column(array_map(fn ($fragment) => $fragment->toArray(), $first), 'paragraph'));

        foreach ($first as $fragment) {
            $this->assertMatchesRegularExpression('/^sv'.$version->id.'-[a-f0-9]{12}$/', $fragment->fragmentId);
            $this->assertSame(
                $fragment->text,
                mb_substr($version->text, $fragment->startOffset, $fragment->endOffset - $fragment->startOffset),
            );
            $this->assertSame(hash('sha256', $fragment->text), $fragment->textHash);
        }
    }

    public function test_word_boundaries_prevent_substring_matches_and_zero_relevance_returns_no_context(): void
    {
        [$analysis] = $this->fixture(
            'Статья 1. Неправовой технический термин применяется к оборудованию.',
            instruction: 'Найти слово «правовой» в нормативном источнике.',
            documentText: 'Автомобильная спецификация.',
        );

        $result = app(LegalRetrievalService::class)->retrieve($analysis);

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->fragments);
        $this->assertSame('zero_relevance', data_get($result->retrievalAudit, 'fragments.0.reason'));
        $this->assertFalse(data_get($result->retrievalAudit, 'fragments.0.selected'));
    }

    public function test_exact_phrase_and_article_reference_boost_matching_fragment(): void
    {
        [$analysis] = $this->fixture(<<<'LAW'
Статья 12. Договор заключается в письменной форме и содержит цену объекта.

Статья 13. Договор заключается сторонами и содержит общие условия.
LAW,
            instruction: 'Проверь статью 12 и фразу «цена объекта».',
            documentText: 'Договор и цена объекта.',
        );

        $result = app(LegalRetrievalService::class)->retrieve($analysis);
        $articleTwelve = collect($result->fragments)->firstWhere('article', '12');
        $articleThirteen = collect($result->fragments)->firstWhere('article', '13');

        $this->assertNotNull($articleTwelve);
        $this->assertNotNull($articleThirteen);
        $this->assertGreaterThan($articleThirteen->score, $articleTwelve->score);
    }

    public function test_bm25_reduces_weight_of_common_terms_and_prioritizes_rare_legal_term(): void
    {
        [$analysis] = $this->fixture(<<<'LAW'
Статья 1. Общие положения
1. Оператор рассматривает проект и принимает решение.

Статья 2. Полномочия оператора
1. Оператор рассматривает проект и уведомляет заявителя.

Статья 3. Кадастровая идентификация
1. Уникальный кадастровый идентификатор подтверждает границы земельного участка.
LAW,
            instruction: 'Проверить уникальный кадастровый идентификатор земельного участка.',
            documentText: 'Требуется кадастровый идентификатор.',
        );

        $result = app(LegalRetrievalService::class)->retrieve($analysis);
        $articleThree = collect($result->fragments)->firstWhere('article', '3');
        $articleOne = collect($result->fragments)->firstWhere('article', '1');

        $this->assertNotNull($articleThree);
        $this->assertGreaterThan($articleOne?->score ?? 0, $articleThree->score);
    }

    public function test_global_budget_keeps_whole_fragments_across_multiple_source_versions(): void
    {
        config()->set('legal_analysis.retrieval.context_budget_chars', 1100);
        config()->set('legal_analysis.retrieval.top_k', 10);

        [$analysis] = $this->fixture(
            "Статья 1. Долевое строительство регулируется настоящим законом.\n\nСтатья 2. Договор долевого участия заключается письменно.",
            instruction: 'Проверить долевое строительство и договор участия.',
            documentText: 'Договор долевого строительства.',
        );
        $secondSource = Source::create([
            'title' => 'Второй закон',
            'type' => 'law',
            'status' => 'active',
        ]);
        $secondVersion = SourceVersion::create([
            'source_id' => $secondSource->id,
            'version_name' => 'Редакция 2',
            'text' => "Статья 5. Договор участия должен содержать срок передачи.\n\nСтатья 6. Долевое строительство требует разрешения.",
            'hash' => hash('sha256', 'second-version'),
        ]);
        $analysis->sourceVersions()->attach($secondVersion->id, ['role' => 'reference']);

        $result = app(LegalRetrievalService::class)->retrieve($analysis->fresh());

        $this->assertCount(2, array_unique(array_map(fn ($fragment) => $fragment->sourceVersionId, $result->fragments)));
        $this->assertLessThanOrEqual(1100, array_sum(array_map(
            fn ($fragment) => mb_strlen($fragment->toPromptBlock()) + 40,
            $result->fragments,
        )));

        foreach ($result->fragments as $fragment) {
            $version = SourceVersion::findOrFail($fragment->sourceVersionId);
            $this->assertSame(
                $fragment->text,
                mb_substr($version->text, $fragment->startOffset, $fragment->endOffset - $fragment->startOffset),
            );
        }
    }

    public function test_snapshot_lists_only_source_versions_present_in_actual_context(): void
    {
        [$analysis, $selectedVersion] = $this->fixture(
            'Статья 1. Договор долевого участия заключается в письменной форме.',
            instruction: 'Проверить договор долевого участия.',
            documentText: 'Договор долевого участия.',
        );
        $irrelevantSource = Source::create([
            'title' => 'Технический регламент',
            'type' => 'law',
            'status' => 'active',
        ]);
        $irrelevantVersion = SourceVersion::create([
            'source_id' => $irrelevantSource->id,
            'version_name' => 'Редакция 1',
            'text' => 'Оборудование проходит электрические испытания.',
            'hash' => hash('sha256', 'irrelevant-version'),
        ]);
        $analysis->sourceVersions()->attach($irrelevantVersion->id, ['role' => 'reference']);

        $result = app(LegalRetrievalService::class)->retrieve($analysis->fresh());

        $this->assertSame(
            [$selectedVersion->id],
            array_column($result->sourceSnapshots, 'source_version_id'),
        );
    }

    public function test_retrieval_is_generic_across_code_law_rules_and_appendix_structures(): void
    {
        $cases = [
            [
                "Раздел III. НАЛОГОВОЕ АДМИНИСТРИРОВАНИЕ\nГлава 8. Отчётность\nСтатья 101. Электронная декларация\n1. Электронная декларация представляется через информационную систему.\n2. Срок определяется настоящей статьёй.",
                'Электронная декларация представляется через информационную систему.',
                fn ($fragment) => $fragment->section === 'III' && $fragment->article === '101',
            ],
            [
                "Статья 5. Лицензирование деятельности\n1. Лицензия выдаётся уполномоченным органом.\n2. Заявление рассматривается в установленный срок.",
                'Лицензия выдаётся уполномоченным органом.',
                fn ($fragment) => $fragment->article === '5',
            ],
            [
                "Правила оказания услуги\n1. Заявитель подаёт электронное заявление.\n2. Уполномоченный орган проверяет комплектность документов.\n3. Результат направляется заявителю.",
                'Уполномоченный орган проверяет комплектность документов.',
                fn ($fragment) => $fragment->paragraph === '2',
            ],
            [
                "Приложение 4 к постановлению\nФорма уведомления\n1. Уведомление содержит идентификатор заявления.\n2. Уведомление подписывается электронной подписью.",
                'Уведомление содержит идентификатор заявления.',
                fn ($fragment) => $fragment->appendix === '4',
            ],
        ];

        foreach ($cases as [$text, $instruction, $metadataMatches]) {
            [$analysis] = $this->fixture($text, instruction: $instruction, documentText: $instruction);
            $result = app(LegalRetrievalService::class)->retrieve($analysis);

            $this->assertNotEmpty($result->fragments);
            $this->assertNotNull(collect($result->fragments)->first($metadataMatches));
        }
    }

    private function fixture(
        string $sourceText,
        string $instruction = 'Проверить договор, цену и ответственность сторон.',
        string $documentText = 'Договор содержит цену объекта.',
    ): array {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-'.$user->id,
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Проект документа',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'proposed_text' => $documentText,
            'analysis_instruction' => $instruction,
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридический анализ',
            'analysis_type' => 'comprehensive',
            'instruction' => $instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $source = Source::create([
            'title' => 'Закон',
            'type' => 'law',
            'status' => 'active',
        ]);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 1',
            'text' => $sourceText,
            'hash' => hash('sha256', $sourceText),
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);

        return [$analysis, $version];
    }
}
