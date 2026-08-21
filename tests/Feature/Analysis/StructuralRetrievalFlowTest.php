<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalDraftingService;
use App\Services\LegalRetrievalService;
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
        $this->assertGreaterThan(0, data_get($settings, 'budget_audit.mandatory_used'));
        $this->assertLessThanOrEqual(12000, data_get($settings, 'budget_audit.optional_used'));
        $this->assertSame(30000, data_get($settings, 'budget_audit.total_limit'));
        $this->assertTrue(collect(data_get($settings, 'retrieval_audit.groups'))->every(
            fn (array $group) => $group['complete'] === true,
        ));
        $this->assertCount(1, Http::recorded());
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
            'current_text' => "Статья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n7) принимать решение по заявке;\n7-2) Отсутствует;\n8) запрашивать документы.\n\nСтатья 30-1. Отсутствует",
            'proposed_text' => "Статья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n7) принимать решение по заявке;\n7-2) реструктуризировать задолженность в установленном порядке;\n8) запрашивать документы.\n\nСтатья 30-1. Реструктуризация задолженности\n1. Единый оператор вправе принять решение о реструктуризации задолженности.",
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
        $text = "Статья 25. Общие положения\n1. Оператор действует в пределах компетенции.\n\nСтатья 26. Права и обязанности Единого оператора\n1. Единый оператор вправе:\n7) принимать решение по заявке;\n8) запрашивать документы.\n\nСтатья 27. Обязанности застройщика\n1. Застройщик представляет документы.\n\nСтатья 29. Общие положения\n1. Оператор действует в пределах компетенции.\n\nСтатья 30. Гарантийный взнос\n1. Гарантийный взнос уплачивается единовременно.\n2. Взнос возврату не подлежит.\n3. Размер взноса определяется по утверждённой методике.\n\nСтатья 31. Заявка на заключение договора\n1. Заявитель обращается к оператору с заявкой.\n2. Заявка рассматривается в установленном порядке.\n\nСтатья 32. Решение\n1. Оператор принимает мотивированное решение.";
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);

        return [$analysis, $version];
    }
}
