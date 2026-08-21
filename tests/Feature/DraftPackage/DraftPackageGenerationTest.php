<?php

namespace Tests\Feature\DraftPackage;

use App\Models\Analysis;
use App\Models\AnalysisAmendment;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DraftPackageInputBuilder;
use App\Services\LegalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DraftPackageGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_generates_reproducible_package_with_one_fake_api_call(): void
    {
        [$user, $analysis] = $this->fixture();
        config()->set('services.openai.key', 'fake-key');
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;
            $ids = data_get($request->data(), 'text.format.schema.properties.justifications.items.properties.amendment_id.enum');

            return Http::response($this->apiResponse(array_map(fn ($id) => [
                'amendment_id' => $id,
                'text' => $id === $ids[0]
                    ? 'В целях наделения Единого оператора полномочием по реструктуризации задолженности.'
                    : 'В целях определения условий и порядка реструктуризации задолженности.',
                'warnings' => [],
            ], $ids)), 200);
        });

        $this->actingAs($user)
            ->post(route('draft-packages.store', $analysis))
            ->assertRedirect(route('analyses.show', $analysis));

        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('draft_packages', 1);
        $this->assertDatabaseCount('artifacts', 2);
        $package = $analysis->fresh()->draftPackage()->with('artifacts')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $package->plan['input_hash']);
        $this->assertCount(2, $package->plan['artifact_manifest']);
        $table = $package->artifacts->firstWhere('artifact_type', 'comparative_table')->content;
        $draft = $package->artifacts->firstWhere('artifact_type', 'draft_npa')->content;
        $this->assertCount(2, $table['rows']);
        $this->assertSame('Отсутствует', $table['rows'][0]['current_text']);
        $this->assertStringContainsString('подпунктом 7-2)', $draft['commands'][0]['text']);
        $this->assertStringContainsString('статьей 30-1', $draft['commands'][1]['text']);
    }

    public function test_invalid_ai_facts_create_no_partial_package(): void
    {
        [$user, $analysis] = $this->fixture();
        config()->set('services.openai.key', 'fake-key');
        Http::fake(fn (Request $request) => Http::response($this->apiResponse(array_map(fn ($id) => [
            'amendment_id' => $id,
            'text' => 'Во исполнение поручения Президента № 123 сформировать новую норму.',
            'warnings' => [],
        ], data_get($request->data(), 'text.format.schema.properties.justifications.items.properties.amendment_id.enum'))), 200));

        $this->actingAs($user)->post(route('draft-packages.store', $analysis));
        $this->assertDatabaseCount('draft_packages', 0);
        $this->assertDatabaseCount('artifacts', 0);
    }

    public function test_foreign_user_cannot_generate_package(): void
    {
        [, $analysis] = $this->fixture();
        $this->actingAs(User::factory()->create())
            ->post(route('draft-packages.store', $analysis))
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_repeated_request_returns_existing_package_without_second_api_call(): void
    {
        [$user, $analysis] = $this->fixture();
        config()->set('services.openai.key', 'fake-key');
        Http::fake(function (Request $request) {
            $ids = data_get($request->data(), 'text.format.schema.properties.justifications.items.properties.amendment_id.enum');

            return Http::response($this->apiResponse(array_map(fn ($id) => [
                'amendment_id' => $id,
                'text' => 'В целях устранения правовой неопределённости и определения порядка регулирования.',
                'warnings' => [],
            ], $ids)), 200);
        });

        $this->actingAs($user)->post(route('draft-packages.store', $analysis));
        $this->actingAs($user)->post(route('draft-packages.store', $analysis));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('draft_packages', 1);
        $this->assertDatabaseCount('artifacts', 2);
    }

    public function test_analysis_page_shows_generation_button_then_artifact_links(): void
    {
        [$user, $analysis] = $this->fixture();
        $this->actingAs($user)->get(route('analyses.show', $analysis))
            ->assertOk()
            ->assertSee('Сформировать пакет документов')
            ->assertSee(route('draft-packages.store', $analysis), false);

        $this->fakeValidJustifications();
        $this->actingAs($user)->post(route('draft-packages.store', $analysis));
        $package = $analysis->fresh()->draftPackage()->with('artifacts')->firstOrFail();

        $response = $this->actingAs($user)->get(route('analyses.show', $analysis));
        $response->assertOk()->assertSee('Сравнительная таблица — Открыть');
        foreach ($package->artifacts as $artifact) {
            $response->assertSee(route('artifacts.show', $artifact), false);
        }
    }

    public function test_owner_can_open_package_and_safe_html_previews(): void
    {
        [$user, $analysis] = $this->fixture();
        $analysis->amendments()->first()->update([
            'proposed_text' => '7-2) безопасный текст <script>alert(1)</script>;',
        ]);
        $this->fakeValidJustifications();
        $this->actingAs($user)->post(route('draft-packages.store', $analysis));
        $package = $analysis->fresh()->draftPackage()->with('artifacts')->firstOrFail();
        $table = $package->artifacts->firstWhere('artifact_type', 'comparative_table');
        $draft = $package->artifacts->firstWhere('artifact_type', 'draft_npa');
        $canonicalBefore = $package->artifacts->mapWithKeys(fn ($artifact) => [$artifact->id => $artifact->content])->all();
        $hashesBefore = $package->artifacts->mapWithKeys(fn ($artifact) => [
            $artifact->id => app(DraftPackageInputBuilder::class)->hashPayload($artifact->content),
        ])->all();
        $timestampsBefore = [
            'analysis' => $analysis->fresh()->getRawOriginal('updated_at'),
            'package' => $package->getRawOriginal('updated_at'),
            'artifacts' => $package->artifacts->mapWithKeys(fn ($artifact) => [$artifact->id => $artifact->getRawOriginal('updated_at')])->all(),
            'amendments' => $analysis->amendments()->orderBy('id')->get()->mapWithKeys(fn ($amendment) => [
                $amendment->id => $amendment->getRawOriginal('updated_at'),
            ])->all(),
        ];

        Http::fake();
        $legalAnalysis = \Mockery::mock(LegalAnalysisService::class);
        $legalAnalysis->shouldNotReceive('run');
        $this->app->instance(LegalAnalysisService::class, $legalAnalysis);

        $this->actingAs($user)->get(route('draft-packages.show', $package))
            ->assertOk()
            ->assertSee('Сравнительная таблица')
            ->assertSee('Проект НПА')
            ->assertSee('Требуется заполнить пользователем')
            ->assertSee('Необходимо определить порядок и срок введения НПА в действие')
            ->assertDontSee('effective_date_rule');

        $this->actingAs($user)->get(route('artifacts.show', $table))
            ->assertOk()
            ->assertSee('Отсутствует')
            ->assertSee('статья 26, пункт 1, подпункт 7-2)')
            ->assertSee('статья 30-1')
            ->assertDontSee('глава 6, статья', false)
            ->assertSee('Юридические предупреждения')
            ->assertSee('Проверить согласованность новой статьи с иными нормами.')
            ->assertSee('legal-text-block', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($user)->get(route('artifacts.show', $draft))
            ->assertOk()
            ->assertSee('Проект')
            ->assertSee('Закон Республики Казахстан')
            ->assertSee('Статья 1.')
            ->assertSee('Необходимо определить порядок и срок введения НПА в действие')
            ->assertDontSee('effective_date_rule')
            ->assertSee('О внесении изменений и дополнений в Закон Республики Казахстан «О долевом участии в жилищном строительстве»')
            ->assertSee('legal-command-block--instruction', false)
            ->assertSee('legal-command-block--norm_item', false)
            ->assertSee('data-command-number="1"', false)
            ->assertSee('data-command-number="2"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        Http::assertNothingSent();
        $package->refresh()->load('artifacts');
        $this->assertSame($canonicalBefore, $package->artifacts->mapWithKeys(fn ($artifact) => [$artifact->id => $artifact->content])->all());
        $this->assertSame($hashesBefore, $package->artifacts->mapWithKeys(fn ($artifact) => [
            $artifact->id => app(DraftPackageInputBuilder::class)->hashPayload($artifact->content),
        ])->all());
        $this->assertSame($timestampsBefore, [
            'analysis' => $analysis->fresh()->getRawOriginal('updated_at'),
            'package' => $package->getRawOriginal('updated_at'),
            'artifacts' => $package->artifacts->mapWithKeys(fn ($artifact) => [$artifact->id => $artifact->getRawOriginal('updated_at')])->all(),
            'amendments' => $analysis->amendments()->orderBy('id')->get()->mapWithKeys(fn ($amendment) => [
                $amendment->id => $amendment->getRawOriginal('updated_at'),
            ])->all(),
        ]);
    }

    public function test_foreign_user_cannot_view_package_or_artifacts(): void
    {
        [$owner, $analysis] = $this->fixture();
        $this->fakeValidJustifications();
        $this->actingAs($owner)->post(route('draft-packages.store', $analysis));
        $package = $analysis->fresh()->draftPackage()->with('artifacts')->firstOrFail();
        $foreign = User::factory()->create();

        $this->actingAs($foreign)->get(route('draft-packages.show', $package))->assertForbidden();
        $this->actingAs($foreign)->get(route('artifacts.show', $package->artifacts->first()))->assertForbidden();
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'DP-'.uniqid(),
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Реструктуризация задолженности v4 fixture',
            'document_type' => 'draft_law',
            'language' => 'ru',
            'analysis_instruction' => 'Разработать нормы о реструктуризации.',
            'proposed_text' => 'Статья 30-1. Новая редакция.',
        ]);
        $source = Source::create([
            'title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»',
            'type' => 'law',
            'number' => '249-II',
            'issuing_authority' => 'Парламент Республики Казахстан',
            'status' => 'active',
        ]);
        $text = "Статья 26. Компетенция\n1. Единый оператор вправе:\n7) принимать решения;\n8) осуществлять иные полномочия.\nСтатья 30. Гарантийный случай\n4. Единый оператор завершает строительство.\nСтатья 31. Урегулирование\n1. Меры принимаются в установленном порядке.";
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридический анализ fixture',
            'analysis_type' => 'amendment_review',
            'instruction' => $document->analysis_instruction,
            'status' => 'completed',
            'version' => 1,
            'completed_at' => now(),
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);

        $fragments = [
            $this->fragment($source, $version, 'f26', '26', '1', '7', '7) принимать решения;'),
            $this->fragment($source, $version, 'f30', '30', '4', null, '4. Единый оператор завершает строительство.'),
            $this->fragment($source, $version, 'f31', '31', '1', null, '1. Меры принимаются в установленном порядке.'),
        ];
        $analysis->update(['settings' => [
            'context_hash' => hash('sha256', json_encode($fragments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'prompt_version' => 'test',
            'retrieval_version' => 'test',
            'validator_version' => 'test',
            'source_snapshots' => [[
                'source_id' => $source->id,
                'source_version_id' => $version->id,
                'source_title' => $source->title,
                'version_name' => $version->version_name,
                'source_version_hash' => hash('sha256', $text),
            ]],
            'retrieval_context' => $fragments,
        ]]);

        $this->amendment($analysis, $source, $version, 1, 'subparagraph', 'Статья 26, пункт 1, подпункт 7-2', ['f26'], '7-2) осуществлять реструктуризацию задолженности;', 'f26');
        $this->amendment($analysis, $source, $version, 2, 'article', 'Статья 30-1', ['f30', 'f31'], "Статья 30-1. Реструктуризация задолженности\n\n1. Условия.\n2. Способы.\n3. Порядок.", 'f30');

        return [$user, $analysis];
    }

    private function amendment(Analysis $analysis, Source $source, SourceVersion $version, int $order, string $type, string $locator, array $anchors, string $proposed, string $citationId): AnalysisAmendment
    {
        return $analysis->amendments()->create([
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'target_mode' => 'new',
            'structural_element_type' => $type,
            'article' => $type === 'article' ? '30' : '26',
            'paragraph' => $type === 'subparagraph' ? '1' : '4',
            'subparagraph' => $type === 'subparagraph' ? '7' : null,
            'proposed_locator' => $locator,
            'amendment_type' => 'add_element',
            'disposition' => 'revise',
            'current_text' => null,
            'proposed_text' => $proposed,
            'justification' => 'Поправка устраняет правовую неопределённость.',
            'legal_basis' => 'Действующие нормы подтверждают компетенцию и порядок.',
            'source_reference' => $source->title,
            'confidence_score' => 90,
            'warnings' => $order === 2 ? ['Проверить согласованность новой статьи с иными нормами.'] : [],
            'target_fragment_ids' => [],
            'anchor_fragment_ids' => $anchors,
            'citations' => [[
                'fragment_id' => $citationId,
                'quote' => $citationId === 'f26' ? 'принимать решения' : 'Единый оператор завершает строительство',
                'purpose' => 'legal_basis',
            ]],
            'target_snapshot' => ['source_id' => $source->id, 'source_version_id' => $version->id, 'fragment_ids' => $anchors],
            'sort_order' => $order,
            'chapter' => '6',
        ]);
    }

    private function fragment(Source $source, SourceVersion $version, string $id, string $article, ?string $paragraph, ?string $subparagraph, string $text): array
    {
        return [
            'fragment_id' => $id,
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'source_title' => $source->title,
            'version_name' => $version->version_name,
            'section' => null,
            'chapter' => null,
            'part' => null,
            'article' => $article,
            'paragraph' => $paragraph,
            'subparagraph' => $subparagraph,
            'text_paragraph' => null,
            'appendix' => null,
            'start_offset' => 0,
            'end_offset' => mb_strlen($text),
            'text_hash' => hash('sha256', $text),
            'score' => 1,
            'text' => $text,
        ];
    }

    private function apiResponse(array $justifications): array
    {
        return [
            'id' => 'resp_draft_package',
            'model' => 'test-model',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['justifications' => $justifications], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]],
            ]],
        ];
    }

    private function fakeValidJustifications(): void
    {
        config()->set('services.openai.key', 'fake-key');
        Http::fake(function (Request $request) {
            $ids = data_get($request->data(), 'text.format.schema.properties.justifications.items.properties.amendment_id.enum');

            return Http::response($this->apiResponse(array_map(fn ($id) => [
                'amendment_id' => $id,
                'text' => 'В целях устранения правовой неопределённости и определения порядка регулирования.',
                'warnings' => [],
            ], $ids)), 200);
        });
    }
}
