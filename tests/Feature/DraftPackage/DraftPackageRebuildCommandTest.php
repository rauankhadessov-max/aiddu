<?php

namespace Tests\Feature\DraftPackage;

use App\Models\Analysis;
use App\Models\Artifact;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ArtifactDocxService;
use App\Services\DraftPackageDocxRenderer;
use App\Services\DraftPackageInputBuilder;
use App\Services\DraftPackageRebuildService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DraftPackageRebuildCommandTest extends TestCase
{
    use DatabaseMigrations;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/aiddu-package-rebuild-'.uniqid();
        config()->set('filesystems.disks.local.root', $this->storageRoot);
        config()->set('legal_analysis.draft_package.maintenance_backup_root', 'backups/draft-packages');
        app('filesystem')->forgetDisk('local');
        Http::fake();
    }

    public function test_command_is_read_only_dry_run_by_default_and_calls_no_ai(): void
    {
        [$user, $analysis, $package, $table, $draft] = $this->fixture();
        $beforePackage = $package->getAttributes();
        $beforeArtifacts = $package->artifacts()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeFiles = $this->storedFiles();

        $this->artisan('draft-package:rebuild', ['package' => $package->id])
            ->expectsOutputToContain('DraftPackage rebuild dry-run')
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('Semantic payload:')
            ->expectsOutputToContain('Runtime storage audit:')
            ->expectsOutputToContain('Пункт 1 статьи 13 изложить в следующей редакции:')
            ->expectsOutputToContain('legal-docx-v3')
            ->assertSuccessful();

        $this->assertSame($beforePackage, $package->fresh()->getAttributes());
        $this->assertSame($beforeArtifacts, $package->artifacts()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($beforeFiles, $this->storedFiles());
        $this->assertFalse(Storage::disk('local')->exists('backups/draft-packages'));
        $this->assertSame($table->content, $table->fresh()->content);
        $this->assertSame($draft->content, $draft->fresh()->content);
        $this->assertSame('completed', $analysis->fresh()->status);
        $this->assertSame($user->id, $analysis->user_id);
        Http::assertNothingSent();
    }

    public function test_apply_preserves_analysis_rebuilds_ids_downloads_and_rolls_back(): void
    {
        [$user, $analysis, $package, $table, $draft, $oldRepresentations] = $this->fixture();
        $service = app(DraftPackageRebuildService::class);
        $preview = $service->preview($package->id);
        $analysisBefore = $analysis->getAttributes();
        $amendmentBefore = $analysis->amendments()->sole()->getAttributes();
        $packageBefore = $package->getAttributes();
        $tableBefore = $table->getAttributes();
        $draftBefore = $draft->getAttributes();
        $oldRepresentationAttributes = collect($oldRepresentations)->map->getAttributes()->all();
        $oldPaths = collect($oldRepresentations)->pluck('storage_path')->all();

        $this->artisan('draft-package:rebuild', [
            'package' => $package->id,
            '--apply' => true,
            '--expected-head' => $preview['head'],
            '--expected-plan-hash' => $preview['plan_hash'],
        ])->expectsOutputToContain('DraftPackage rebuild завершён.')->assertSuccessful();

        $package->refresh()->load('artifacts');
        $this->assertSame($packageBefore['id'], $package->id);
        $this->assertSame($tableBefore['id'], $package->artifacts->firstWhere('artifact_type', 'comparative_table')->id);
        $this->assertSame($draftBefore['id'], $package->artifacts->firstWhere('artifact_type', 'draft_npa')->id);
        $this->assertSame($analysisBefore, $analysis->fresh()->getAttributes());
        $this->assertSame($amendmentBefore, $analysis->amendments()->sole()->getAttributes());
        $this->assertSame(
            data_get(json_decode($packageBefore['plan'], true), 'narrative_generation'),
            data_get($package->plan, 'narrative_generation'),
        );

        $newTable = $package->artifacts->firstWhere('artifact_type', 'comparative_table');
        $newDraft = $package->artifacts->firstWhere('artifact_type', 'draft_npa');
        $heading = 'Статья 13. Изменение и расторжение договора о долевом участии в жилищном строительстве';
        $this->assertSame($heading, data_get($newTable->content, 'rows.0.article_heading'));
        $this->assertStringStartsWith(
            'Пункт 1 статьи 13 изложить в следующей редакции:',
            data_get($newDraft->content, 'commands.0.text'),
        );
        $this->assertDatabaseMissing('artifacts', ['id' => $oldRepresentations[0]->id]);
        $this->assertDatabaseMissing('artifacts', ['id' => $oldRepresentations[1]->id]);

        $representations = $package->artifacts->whereNotNull('source_artifact_id')->values();
        $this->assertCount(2, $representations);
        foreach ($representations as $representation) {
            $this->assertSame('legal-docx-v3', $representation->renderer_version);
            $this->assertTrue(app(ArtifactDocxService::class)->isValid($representation));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $representation->source_content_hash);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $representation->logical_content_hash);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $representation->binary_sha256);
        }
        foreach ($oldPaths as $path) {
            $this->assertFalse(Storage::disk('local')->exists($path));
        }

        $analysisPage = $this->actingAs($user)->get(route('analyses.show', $analysis));
        $analysisPage->assertOk()
            ->assertSee(route('artifacts.docx.download', $newTable), false)
            ->assertSee(route('artifacts.docx.download', $newDraft), false);
        $this->actingAs($user)->post(route('artifacts.docx.download', $newTable))->assertOk();
        $this->actingAs($user)->post(route('artifacts.docx.download', $newDraft))->assertOk();
        $this->assertSame(2, $package->fresh()->artifacts()->whereNotNull('source_artifact_id')->count());

        $backupPaths = Storage::disk('local')->directories('backups/draft-packages');
        $this->assertCount(1, $backupPaths);
        $backupPath = $backupPaths[0];
        $this->assertTrue(Storage::disk('local')->exists($backupPath.'/database.sqlite'));
        $this->assertTrue(Storage::disk('local')->exists($backupPath.'/snapshot.json'));
        $this->assertTrue(Storage::disk('local')->exists($backupPath.'/storage-manifest.json'));

        $newPaths = $package->fresh()->artifacts()->whereNotNull('source_artifact_id')->pluck('storage_path')->all();
        $this->artisan('draft-package:rebuild', [
            'package' => $package->id,
            '--apply' => true,
            '--rollback' => $backupPath,
        ])->expectsOutputToContain('DraftPackage rollback завершён.')->assertSuccessful();

        $this->assertSame($packageBefore, $package->fresh()->getAttributes());
        $this->assertSame($tableBefore, Artifact::findOrFail($table->id)->getAttributes());
        $this->assertSame($draftBefore, Artifact::findOrFail($draft->id)->getAttributes());
        foreach ($oldRepresentationAttributes as $attributes) {
            $this->assertEquals($attributes, Artifact::findOrFail($attributes['id'])->getAttributes());
            $this->assertTrue(Storage::disk('local')->exists($attributes['storage_path']));
        }
        foreach ($newPaths as $path) {
            $this->assertFalse(Storage::disk('local')->exists($path));
        }
        $this->assertSame($analysisBefore, $analysis->fresh()->getAttributes());
        $this->assertSame($amendmentBefore, $analysis->amendments()->sole()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_apply_requires_confirmed_head_and_plan_hash_and_rejects_stale_plan(): void
    {
        [, , $package] = $this->fixture();
        $service = app(DraftPackageRebuildService::class);
        $preview = $service->preview($package->id);

        $this->artisan('draft-package:rebuild', [
            'package' => $package->id,
            '--apply' => true,
        ])->expectsOutputToContain('--apply требует')->assertFailed();

        $package->update(['plan' => [
            ...$package->plan,
            'changed_after_dry_run' => true,
        ]]);
        $this->artisan('draft-package:rebuild', [
            'package' => $package->id,
            '--apply' => true,
            '--expected-head' => $preview['head'],
            '--expected-plan-hash' => $preview['plan_hash'],
        ])->expectsOutputToContain('Semantic plan hash не совпадает')->assertFailed();

        $this->assertFalse(Storage::disk('local')->exists('backups/draft-packages'));
        Http::assertNothingSent();
    }

    public function test_same_semantic_state_three_times_has_byte_identical_payload_and_hash(): void
    {
        [, , $package] = $this->fixture();
        $service = app(DraftPackageRebuildService::class);
        $previews = collect(range(1, 3))->map(fn () => $service->preview($package->id));
        $payloads = $previews->map(fn (array $preview) => json_encode(
            $preview['semantic_plan_payload'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        $this->assertCount(1, $previews->pluck('semantic_plan_hash')->unique());
        $this->assertCount(1, $payloads->unique());
        $this->assertSame(
            $previews->first()['new_input_hash'],
            data_get($previews->first(), 'semantic_plan_payload.input_hash'),
        );
        $this->assertArrayNotHasKey('storage', $previews->first()['semantic_plan_payload']);
        $this->assertStringNotContainsString('binary_sha256', $payloads->first());
        $this->assertSame(
            collect(data_get($previews->first(), 'semantic_plan_payload.artifacts'))->pluck('id')->sort()->values()->all(),
            collect(data_get($previews->first(), 'semantic_plan_payload.artifacts'))->pluck('id')->all(),
        );
        $this->assertSame(
            collect(data_get($previews->first(), 'semantic_plan_payload.renderer.representations'))
                ->pluck('artifact_type')->sort()->values()->all(),
            collect(data_get($previews->first(), 'semantic_plan_payload.renderer.representations'))
                ->pluck('artifact_type')->all(),
        );
        $this->assertSame(
            collect(data_get($previews->first(), '_candidate.input.source_snapshots'))
                ->pluck('source_version_id')->sort()->values()->all(),
            collect(data_get($previews->first(), '_candidate.input.source_snapshots'))
                ->pluck('source_version_id')->all(),
        );

        $hasher = app(DraftPackageInputBuilder::class);
        $this->assertNotSame(
            $hasher->hashPayload(['input_hash' => 'first', 'value' => 1]),
            $hasher->hashPayload(['input_hash' => 'second', 'value' => 1]),
        );
        $this->assertSame(
            $hasher->hashInputPayload(['input_hash' => 'first', 'value' => 1]),
            $hasher->hashInputPayload(['input_hash' => 'second', 'value' => 1]),
        );
        Http::assertNothingSent();
    }

    public function test_changed_canonical_input_and_amendment_change_semantic_hash(): void
    {
        [, $analysis, $package] = $this->fixture();
        $service = app(DraftPackageRebuildService::class);
        $baseline = $service->preview($package->id);

        $analysis->update(['instruction' => 'Изменённое юридически значимое поручение.']);
        $changedInput = $service->preview($package->id);
        $this->assertNotSame($baseline['new_input_hash'], $changedInput['new_input_hash']);
        $this->assertNotSame($baseline['semantic_plan_hash'], $changedInput['semantic_plan_hash']);

        $amendment = $analysis->amendments()->sole();
        $amendment->update(['warnings' => [
            'Проверить согласованность регулирования.',
            'Новое подтверждённое предупреждение.',
        ]]);
        $changedAmendment = $service->preview($package->id);
        $this->assertNotSame(
            $changedInput['new_canonical_hashes']['comparative_table'],
            $changedAmendment['new_canonical_hashes']['comparative_table'],
        );
        $this->assertNotSame($changedInput['semantic_plan_hash'], $changedAmendment['semantic_plan_hash']);
        Http::assertNothingSent();
    }

    public function test_runtime_storage_and_docx_binary_changes_do_not_change_semantic_hash(): void
    {
        [, , $package, , , $oldRepresentations] = $this->fixture();
        $service = app(DraftPackageRebuildService::class);
        $visible = $service->preview($package->id);
        $path = $oldRepresentations[0]->storage_path;
        $visibleAudit = collect($visible['runtime_storage_audit'])->firstWhere(
            'artifact_id',
            $oldRepresentations[0]->id,
        );
        $this->assertTrue($visibleAudit['exists']);

        Storage::disk('local')->delete($path);
        sleep(2);
        $hidden = $service->preview($package->id);
        $hiddenAudit = collect($hidden['runtime_storage_audit'])->firstWhere(
            'artifact_id',
            $oldRepresentations[0]->id,
        );

        $this->assertFalse($hiddenAudit['exists']);
        $this->assertNotSame($visible['guard_hash'], $hidden['guard_hash']);
        $this->assertSame($visible['semantic_plan_payload'], $hidden['semantic_plan_payload']);
        $this->assertSame($visible['semantic_plan_hash'], $hidden['semantic_plan_hash']);
        $this->assertNotSame(
            data_get($visible, 'docx_checks.comparative_table.binary_sha256'),
            data_get($hidden, 'docx_checks.comparative_table.binary_sha256'),
        );
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'REBUILD-'.uniqid(),
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
            'analysis_instruction' => 'Проверить поправку.',
            'current_text' => '1. Договор изменяется по соглашению сторон.',
            'proposed_text' => '1. Договор изменяется только в случаях, предусмотренных законом.',
        ]);
        $source = Source::create([
            'title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»',
            'type' => 'law',
            'status' => 'active',
        ]);
        $heading = 'Статья 13. Изменение и расторжение договора о долевом участии в жилищном строительстве';
        $current = $document->current_text;
        $sourceText = $heading."\n".$current;
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => $sourceText,
            'hash' => hash('sha256', $sourceText),
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
        $headingFragment = $this->fragment($source, $version, 'heading-fragment', 'article', $heading);
        $paragraphFragment = $this->fragment($source, $version, 'paragraph-fragment', 'paragraph', $current);
        $analysis->update(['settings' => [
            'response_id' => 'analysis-response-original',
            'prompt_version' => 'test',
            'retrieval_version' => 'test',
            'validator_version' => 'test',
            'context_hash' => hash('sha256', json_encode([$headingFragment, $paragraphFragment])),
            'source_snapshots' => [[
                'source_id' => $source->id,
                'source_version_id' => $version->id,
                'source_title' => $source->title,
                'version_name' => $version->version_name,
                'source_version_hash' => $version->hash,
            ]],
            'retrieval_context' => [$headingFragment, $paragraphFragment],
        ]]);
        $amendment = $analysis->amendments()->create([
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'target_mode' => 'existing',
            'structural_element_type' => 'paragraph',
            'chapter' => '4',
            'article' => '13',
            'paragraph' => '1',
            'amendment_type' => 'new_edition',
            'disposition' => 'revise',
            'current_text' => $current,
            'proposed_text' => $document->proposed_text,
            'justification' => 'Поправка уточняет порядок изменения договора.',
            'legal_basis' => 'Статья 13 Закона.',
            'source_reference' => $source->title.', статья 13, пункт 1',
            'confidence_score' => 95,
            'warnings' => ['Проверить согласованность регулирования.'],
            'target_fragment_ids' => ['paragraph-fragment'],
            'anchor_fragment_ids' => [],
            'citations' => [[
                'fragment_id' => 'paragraph-fragment',
                'quote' => 'Договор изменяется по соглашению сторон.',
                'purpose' => 'target_current_text',
                'source_id' => $source->id,
                'source_version_id' => $version->id,
                'text_hash' => hash('sha256', $current),
                'article' => '13',
                'paragraph' => '1',
                'subparagraph' => null,
            ]],
            'target_snapshot' => [
                'source_id' => $source->id,
                'source_version_id' => $version->id,
                'fragment_ids' => ['paragraph-fragment'],
            ],
            'sort_order' => 1,
        ]);

        $oldTableContent = [
            'schema_version' => 'comparative-table-v1',
            'title' => 'Сравнительная таблица',
            'columns' => ['№', 'Структурный элемент', 'Действующая редакция', 'Предлагаемая редакция', 'Обоснование'],
            'rows' => [[
                'number' => 1,
                'amendment_id' => $amendment->id,
                'structural_element' => 'глава 4, статья 13, пункт 1',
                'current_text' => $current,
                'proposed_text' => $document->proposed_text,
                'justification' => 'Подтверждённое профессиональное обоснование.',
                'warnings' => ['Проверить согласованность регулирования.'],
            ]],
            'warnings' => [],
        ];
        $oldCommand = "глава 4, статья 13, пункт 1 изложить в следующей редакции:\n\n«{$document->proposed_text}»;";
        $oldDraftContent = [
            'schema_version' => 'draft-npa-v1',
            'project_mark' => 'Проект',
            'act_type' => 'Закон Республики Казахстан',
            'title' => 'О внесении изменений и дополнений в '.$source->title,
            'target_npa' => ['source_id' => $source->id, 'title' => $source->title],
            'adopting_act' => ['status' => 'resolved', 'title' => 'Закон Республики Казахстан'],
            'articles' => [[
                'number' => 1,
                'heading' => null,
                'intro' => 'Внести в '.$source->title.' следующие изменения и дополнения:',
                'commands' => [['number' => 1, 'amendment_id' => $amendment->id, 'text' => $oldCommand]],
            ]],
            'commands' => [['number' => 1, 'amendment_id' => $amendment->id, 'text' => $oldCommand]],
            'requires_user_input' => ['effective_date_rule'],
            'warnings' => ['Заключительная норма требует уточнения.'],
        ];
        $hasher = app(DraftPackageInputBuilder::class);
        $package = $analysis->draftPackage()->create([
            'title' => 'Пакет документов: '.$document->title,
            'package_type' => 'legal_amendment',
            'status' => 'draft',
            'description' => 'Fixture package',
            'plan' => [
                'input_hash' => 'old-input-hash',
                'narrative_generation' => [
                    'model' => 'fake-model',
                    'response_id' => 'response-must-be-preserved',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'request_payload_hash' => hash('sha256', 'old-payload'),
                    'prompt_version' => 'draft-package-justification-v1',
                    'validator_version' => 'draft-package-justification-v1',
                ],
                'artifact_manifest' => [
                    ['artifact_type' => 'comparative_table', 'content_hash' => $hasher->hashPayload($oldTableContent)],
                    ['artifact_type' => 'draft_npa', 'content_hash' => $hasher->hashPayload($oldDraftContent)],
                ],
                'warnings' => ['Проверить согласованность регулирования.'],
            ],
            'generated_at' => now(),
        ]);
        $table = $package->artifacts()->create([
            'created_by' => $user->id,
            'artifact_type' => 'comparative_table',
            'format' => 'structured_json',
            'title' => 'Сравнительная таблица',
            'content' => $oldTableContent,
            'status' => 'draft',
            'generated_at' => now(),
        ]);
        $draft = $package->artifacts()->create([
            'created_by' => $user->id,
            'artifact_type' => 'draft_npa',
            'format' => 'structured_json',
            'title' => 'Проект НПА',
            'content' => $oldDraftContent,
            'status' => 'draft',
            'generated_at' => now(),
        ]);
        $oldRepresentations = [
            $this->oldRepresentation($table, $user),
            $this->oldRepresentation($draft, $user),
        ];

        return [$user, $analysis->fresh(), $package->fresh(), $table->fresh(), $draft->fresh(), $oldRepresentations];
    }

    private function oldRepresentation(Artifact $canonical, User $user): Artifact
    {
        $path = app(DraftPackageDocxRenderer::class)->render($canonical, [
            'draft_npa_content' => $canonical->draftPackage->artifacts()
                ->where('artifact_type', 'draft_npa')->value('content'),
        ]);
        $storagePath = 'draft-packages/'.$canonical->draft_package_id.'/old/'.$canonical->id.'.docx';
        $stream = fopen($path, 'rb');
        $this->assertIsResource($stream);
        try {
            Storage::disk('local')->put($storagePath, $stream);
        } finally {
            fclose($stream);
        }
        @unlink($path);
        $absolute = Storage::disk('local')->path($storagePath);

        return Artifact::create([
            'draft_package_id' => $canonical->draft_package_id,
            'source_artifact_id' => $canonical->id,
            'created_by' => $user->id,
            'artifact_type' => $canonical->artifact_type.'_docx',
            'format' => 'docx',
            'title' => $canonical->title.' (DOCX)',
            'content' => ['render_manifest' => ['renderer_version' => 'legal-docx-v2']],
            'storage_disk' => 'local',
            'storage_path' => $storagePath,
            'filename' => $canonical->artifact_type.'.docx',
            'mime_type' => ArtifactDocxService::MIME_TYPE,
            'file_size' => filesize($absolute),
            'renderer_version' => 'legal-docx-v2',
            'source_content_hash' => hash('sha256', 'source-'.$canonical->id),
            'logical_content_hash' => hash('sha256', 'logical-'.$canonical->id),
            'binary_sha256' => hash_file('sha256', $absolute),
            'status' => 'generated',
            'generated_at' => now(),
        ]);
    }

    private function fragment(Source $source, SourceVersion $version, string $id, string $type, string $text): array
    {
        return [
            'fragment_id' => $id,
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'source_title' => $source->title,
            'version_name' => $version->version_name,
            'section' => null,
            'chapter' => '4',
            'part' => null,
            'article' => '13',
            'paragraph' => $type === 'paragraph' ? '1' : null,
            'subparagraph' => null,
            'text_paragraph' => null,
            'appendix' => null,
            'element_type' => $type,
            'element_label' => $text,
            'start_offset' => 0,
            'end_offset' => mb_strlen($text),
            'text_hash' => hash('sha256', $text),
            'score' => 1,
            'text' => $text,
        ];
    }

    private function storedFiles(): array
    {
        return collect(Storage::disk('local')->allFiles())->sort()->values()->all();
    }
}
