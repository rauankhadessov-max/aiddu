<?php

namespace Tests\Feature\DraftPackage;

use App\Models\Analysis;
use App\Models\AnalysisAmendment;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ArtifactDocxService;
use App\Services\DraftPackageAutoGenerationService;
use App\Services\DraftPackageInputBuilder;
use App\Services\DraftPackageService;
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

    public function test_target_source_package_accepts_validated_supporting_source_citation(): void
    {
        [$user, $analysis, $targetVersion, $supportingVersion] = $this->crossSourceFixture();
        config()->set('services.openai.key', 'fake-key');
        $capturedInput = null;

        Http::fake(function (Request $request) use (&$capturedInput) {
            $capturedInput = $request->data()['input'];

            return Http::response($this->apiResponse([[
                'amendment_id' => 1,
                'text' => 'Поправка согласуется с порядком изменения договора, установленным типовой формой.',
                'warnings' => [],
            ]]), 200);
        });

        $this->actingAs($user)
            ->post(route('draft-packages.store', $analysis))
            ->assertRedirect(route('analyses.show', $analysis));

        $package = $analysis->fresh()->draftPackage()->with('artifacts')->firstOrFail();
        $snapshot = $package->plan['amendment_snapshots'][0];
        $table = $package->artifacts->firstWhere('artifact_type', 'comparative_table')->content;
        $draft = $package->artifacts->firstWhere('artifact_type', 'draft_npa')->content;

        $this->assertSame($targetVersion->source_id, $snapshot['source_id']);
        $this->assertSame($targetVersion->id, $snapshot['source_version_id']);
        $this->assertSame(['target-fragment'], array_column($snapshot['trusted_target_context'], 'fragment_id'));
        $this->assertSame(['supporting-fragment'], array_column($snapshot['trusted_legal_basis_context'], 'fragment_id'));
        $this->assertSame(
            ['target-fragment', 'supporting-fragment'],
            array_column($snapshot['trusted_context'], 'fragment_id'),
        );
        $this->assertSame(
            [$targetVersion->id, $supportingVersion->id],
            collect($package->plan['source_snapshots'])->pluck('source_version_id')->sort()->values()->all(),
        );
        $this->assertSame($targetVersion->source_id, data_get($draft, 'target_npa.source_id'));
        $this->assertStringNotContainsString($supportingVersion->source->title, data_get($draft, 'articles.0.intro'));
        $this->assertCount(1, $table['rows']);
        $this->assertStringContainsString('Типовая форма договора', $capturedInput);
        $this->assertStringContainsString('Изменения оформляются дополнительным соглашением', $capturedInput);
        Http::assertSentCount(1);
    }

    public function test_cross_source_package_rejects_untrusted_or_target_mismatched_citations(): void
    {
        Http::fake();
        $cases = [
            'target_current_text from supporting source' => [
                'expected' => 'не соответствует поправке',
                'mutate' => function (Analysis $analysis): void {
                    $amendment = $analysis->amendments()->sole();
                    $citations = $amendment->citations;
                    $citations[1]['purpose'] = 'target_current_text';
                    $amendment->update(['citations' => $citations]);
                },
            ],
            'target fragment from supporting source' => [
                'expected' => 'не соответствует поправке',
                'mutate' => function (Analysis $analysis): void {
                    $analysis->amendments()->sole()->update(['target_fragment_ids' => ['supporting-fragment']]);
                },
            ],
            'supporting source version not attached' => [
                'expected' => 'не подключён к Analysis',
                'mutate' => function (Analysis $analysis, SourceVersion $supporting): void {
                    $analysis->sourceVersions()->detach($supporting->id);
                },
            ],
            'unknown fragment' => [
                'expected' => 'отсутствует в snapshot анализа',
                'mutate' => function (Analysis $analysis): void {
                    $amendment = $analysis->amendments()->sole();
                    $citations = $amendment->citations;
                    $citations[1]['fragment_id'] = 'unknown-fragment';
                    $amendment->update(['citations' => $citations]);
                },
            ],
            'fabricated quote' => [
                'expected' => 'не подтверждённую snapshot анализа',
                'mutate' => function (Analysis $analysis): void {
                    $amendment = $analysis->amendments()->sole();
                    $citations = $amendment->citations;
                    $citations[1]['quote'] = 'Несуществующая цитата supporting Source.';
                    $amendment->update(['citations' => $citations]);
                },
            ],
            'citation text hash mismatch' => [
                'expected' => 'несовпадающий text_hash',
                'mutate' => function (Analysis $analysis): void {
                    $amendment = $analysis->amendments()->sole();
                    $citations = $amendment->citations;
                    $citations[1]['text_hash'] = str_repeat('0', 64);
                    $amendment->update(['citations' => $citations]);
                },
            ],
            'citation locator mismatch' => [
                'expected' => 'несовпадающий paragraph',
                'mutate' => function (Analysis $analysis): void {
                    $amendment = $analysis->amendments()->sole();
                    $citations = $amendment->citations;
                    $citations[1]['paragraph'] = '99';
                    $amendment->update(['citations' => $citations]);
                },
            ],
        ];

        foreach ($cases as $label => $case) {
            [, $analysis, , $supportingVersion] = $this->crossSourceFixture();
            $case['mutate']($analysis, $supportingVersion);

            try {
                app(DraftPackageInputBuilder::class)->build($analysis->fresh());
                $this->fail("Case [{$label}] was not rejected.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString($case['expected'], $exception->getMessage(), $label);
            }
        }

        Http::assertNothingSent();
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

    public function test_completed_analysis_automatically_gets_one_idempotent_package(): void
    {
        [$user, $analysis] = $this->fixture();
        $this->fakeValidJustifications();

        $automation = app(DraftPackageAutoGenerationService::class);
        $this->assertNull($automation->generate($analysis, $user));
        $this->assertNull($automation->generate($analysis->fresh(), $user));

        Http::assertSentCount(1);
        $this->assertDatabaseCount('draft_packages', 1);
        $this->assertDatabaseCount('artifacts', 2);
        $package = $analysis->fresh()->draftPackage()->with('artifacts')->sole();
        $this->assertCount(2, $package->canonicalArtifacts()->get());
        $this->assertSame(
            ['comparative_table', 'draft_npa'],
            $package->canonicalArtifacts()->pluck('artifact_type')->all(),
        );
    }

    public function test_auto_generation_skips_analysis_without_confirmed_amendments(): void
    {
        [$user, $analysis] = $this->fixture();
        $analysis->amendments()->delete();
        Http::fake();

        $this->assertNull(app(DraftPackageAutoGenerationService::class)->generate($analysis, $user));

        $this->assertDatabaseCount('draft_packages', 0);
        $this->assertDatabaseCount('artifacts', 0);
        Http::assertNothingSent();
    }

    public function test_package_failure_preserves_completed_analysis_and_manual_retry(): void
    {
        [$user, $analysis] = $this->fixture();
        Http::fake();
        $this->mock(DraftPackageService::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andThrow(new \RuntimeException('test package failure'));
        });

        $error = app(DraftPackageAutoGenerationService::class)->generate($analysis, $user);

        $this->assertSame('completed', $analysis->fresh()->status);
        $this->assertNotNull($error);
        $this->assertDatabaseCount('draft_packages', 0);
        $this->assertDatabaseCount('artifacts', 0);
        $this->actingAs($user)
            ->withSession(['draft_package_error' => $error])
            ->get(route('analyses.show', $analysis))
            ->assertOk()
            ->assertSee('Юридический анализ завершён, но пакет документов сформировать не удалось.')
            ->assertSee('Повторить формирование')
            ->assertSee(route('draft-packages.store', $analysis), false);
        Http::assertNothingSent();
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
        config()->set('filesystems.disks.local.root', sys_get_temp_dir().'/aiddu-docx-ui-tests-'.uniqid());
        app('filesystem')->forgetDisk('local');
        $canonicalArtifacts = $package->artifacts->where('format', 'structured_json');
        foreach ($canonicalArtifacts as $artifact) {
            app(ArtifactDocxService::class)->generate($artifact, $user);
        }
        $representations = $package->artifacts()->where('format', 'docx')->get();

        $response = $this->actingAs($user)->get(route('analyses.show', $analysis));
        $response->assertOk()
            ->assertSee('Сравнительная таблица')
            ->assertSee('Проект НПА')
            ->assertSee('Обзор пакета и предупреждений')
            ->assertDontSee('Открыть весь пакет');
        $this->assertSame(2, substr_count($response->getContent(), 'data-logical-document='));
        $this->assertSame(2, substr_count($response->getContent(), 'Скачать DOCX'));
        foreach ($canonicalArtifacts as $artifact) {
            $response->assertSee(route('artifacts.show', $artifact), false);
            $response->assertSee(route('artifacts.docx.download', $artifact), false);
        }
        foreach ($representations as $representation) {
            $response->assertDontSee(route('artifacts.show', $representation), false);
            $response->assertDontSee($representation->artifact_type);
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
            ->assertSee('7-2) отсутствует.')
            ->assertSee('Статья 30-1. Отсутствует.')
            ->assertSee('СРАВНИТЕЛЬНАЯ ТАБЛИЦА')
            ->assertSee('к проекту Закона Республики Казахстан')
            ->assertSee('статья 26, пункт 1, подпункт 7-2)')
            ->assertSee('статья 30-1')
            ->assertDontSee('глава 6, статья', false)
            ->assertSee('width: 3.5%', false)
            ->assertSee('width: 13.4%', false)
            ->assertSee('width: 22.9%', false)
            ->assertSee('width: 29%', false)
            ->assertSee('width: 31.2%', false)
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

    private function crossSourceFixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'DP-CROSS-'.uniqid(),
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Изменение пункта 1 статьи 13',
            'document_type' => 'draft_law',
            'language' => 'ru',
            'analysis_instruction' => 'Проверить поправку по типовой форме договора.',
            'current_text' => '1. Договор изменяется по соглашению сторон.',
            'proposed_text' => '1. Договор изменяется в предусмотренных законом случаях.',
        ]);
        $targetSource = Source::create([
            'title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»',
            'type' => 'law',
            'status' => 'active',
        ]);
        $targetText = '1. Договор изменяется по соглашению сторон.';
        $targetVersion = SourceVersion::create([
            'source_id' => $targetSource->id,
            'version_name' => 'Редакция Закона',
            'text' => $targetText,
            'hash' => hash('sha256', $targetText),
        ]);
        $supportingSource = Source::create([
            'title' => 'Типовая форма договора о долевом участии в жилищном строительстве',
            'type' => 'order',
            'status' => 'active',
        ]);
        $supportingText = '32. Изменения оформляются дополнительным соглашением.';
        $supportingVersion = SourceVersion::create([
            'source_id' => $supportingSource->id,
            'version_name' => 'Редакция типовой формы',
            'text' => $supportingText,
            'hash' => hash('sha256', $supportingText),
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Cross-source drafting fixture',
            'analysis_type' => 'amendment_review',
            'instruction' => $document->analysis_instruction,
            'status' => 'completed',
            'version' => 1,
            'completed_at' => now(),
        ]);
        $analysis->sourceVersions()->attach([$targetVersion->id, $supportingVersion->id], ['role' => 'reference']);
        $targetFragment = $this->fragment($targetSource, $targetVersion, 'target-fragment', '13', '1', null, $targetText);
        $supportingFragment = $this->fragment($supportingSource, $supportingVersion, 'supporting-fragment', null, '32', null, $supportingText);
        $analysis->update(['settings' => [
            'context_hash' => hash('sha256', json_encode([$targetFragment, $supportingFragment])),
            'prompt_version' => 'test',
            'retrieval_version' => 'test',
            'validator_version' => 'test',
            'source_snapshots' => [
                $this->sourceSnapshot($targetSource, $targetVersion),
                $this->sourceSnapshot($supportingSource, $supportingVersion),
            ],
            'retrieval_context' => [$targetFragment, $supportingFragment],
        ]]);
        $analysis->amendments()->create([
            'source_id' => $targetSource->id,
            'source_version_id' => $targetVersion->id,
            'target_mode' => 'existing',
            'structural_element_type' => 'paragraph',
            'article' => '13',
            'paragraph' => '1',
            'amendment_type' => 'new_edition',
            'disposition' => 'revise',
            'current_text' => $targetText,
            'proposed_text' => $document->proposed_text,
            'justification' => 'Поправка уточняет допустимые случаи изменения договора.',
            'legal_basis' => 'Типовая форма подтверждает оформление изменений дополнительным соглашением.',
            'source_reference' => $targetSource->title.'; '.$supportingSource->title,
            'confidence_score' => 90,
            'warnings' => [],
            'target_fragment_ids' => ['target-fragment'],
            'anchor_fragment_ids' => [],
            'citations' => [
                $this->citation($targetFragment, 'target_current_text', 'Договор изменяется по соглашению сторон.'),
                $this->citation($supportingFragment, 'legal_basis', 'Изменения оформляются дополнительным соглашением.'),
            ],
            'target_snapshot' => [
                'source_id' => $targetSource->id,
                'source_version_id' => $targetVersion->id,
                'fragment_ids' => ['target-fragment'],
            ],
            'sort_order' => 1,
        ]);

        return [$user, $analysis, $targetVersion, $supportingVersion];
    }

    private function citation(array $fragment, string $purpose, string $quote): array
    {
        return [
            'fragment_id' => $fragment['fragment_id'],
            'quote' => $quote,
            'purpose' => $purpose,
            'source_id' => $fragment['source_id'],
            'source_version_id' => $fragment['source_version_id'],
            'text_hash' => $fragment['text_hash'],
            'article' => $fragment['article'],
            'paragraph' => $fragment['paragraph'],
            'subparagraph' => $fragment['subparagraph'],
        ];
    }

    private function sourceSnapshot(Source $source, SourceVersion $version): array
    {
        return [
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'source_title' => $source->title,
            'version_name' => $version->version_name,
            'source_version_hash' => $version->hash,
        ];
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
                'source_id' => $source->id,
                'source_version_id' => $version->id,
                'text_hash' => hash('sha256', $citationId === 'f26'
                    ? '7) принимать решения;'
                    : '4. Единый оператор завершает строительство.'),
                'article' => $citationId === 'f26' ? '26' : '30',
                'paragraph' => $citationId === 'f26' ? '1' : '4',
                'subparagraph' => $citationId === 'f26' ? '7' : null,
            ]],
            'target_snapshot' => ['source_id' => $source->id, 'source_version_id' => $version->id, 'fragment_ids' => $anchors],
            'sort_order' => $order,
            'chapter' => '6',
        ]);
    }

    private function fragment(Source $source, SourceVersion $version, string $id, ?string $article, ?string $paragraph, ?string $subparagraph, string $text): array
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
