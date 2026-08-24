<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AnalysisExecutionService;
use App\Services\DocxTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Tests\TestCase;

class InlinePersonalSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('filesystems.disks.local.root', sys_get_temp_dir().'/aiddu-inline-source-'.uniqid());
        Storage::forgetDisk('local');
        Http::fake();
    }

    public function test_unified_form_groups_global_and_personal_sources_and_has_inline_form(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $global = $this->source('Глобальный кодекс');
        $personal = $this->source('Мой приказ', $user);
        $workspace->sources()->attach([$global->id, $personal->id]);

        $this->actingAs($user)->get(route('analyses.workflow.create', ['workspace' => $workspace->id]))
            ->assertOk()
            ->assertSee('Глобальные НПА')
            ->assertSee('Мои НПА')
            ->assertSee($global->title)
            ->assertSee($personal->title)
            ->assertSee('+ Добавить НПА')
            ->assertSee('form="inline-source-form"', false)
            ->assertSee('name="docx_file"', false)
            ->assertSee('name="official_url"', false);
    }

    public function test_docx_inline_creation_is_personal_atomic_attached_and_returns_selectable_version(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);

        $response = $this->actingAs($user)->withHeader('Accept', 'application/json')->post(route('workspaces.sources.inline.store', $workspace), [
            'title' => 'Личный закон из DOCX',
            'type' => 'law',
            'input_method' => 'docx',
            'docx_file' => $this->docxUpload(['Статья 1. Общие положения', 'Нормативный текст.']),
        ])->assertCreated()->assertJsonPath('source.title', 'Личный закон из DOCX');

        $source = Source::sole();
        $version = SourceVersion::sole();
        $this->assertSame($user->id, $source->user_id);
        $this->assertSame($version->id, $response->json('version.id'));
        $this->assertSame(hash('sha256', $version->text), $version->hash);
        $this->assertDatabaseHas('workspace_sources', ['workspace_id' => $workspace->id, 'source_id' => $source->id]);
        $this->assertSame([], Storage::disk('local')->allFiles('source_versions'));
        Http::assertNothingSent();
    }

    public function test_url_inline_creation_attaches_personal_source_without_fake_version(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);

        $this->actingAs($user)->withHeader('Accept', 'application/json')->post(route('workspaces.sources.inline.store', $workspace), [
            'title' => 'НПА по ссылке',
            'type' => 'rules',
            'input_method' => 'url',
            'official_url' => 'https://adilet.zan.kz/rus/docs/example',
        ])->assertCreated()->assertJsonPath('version', null)
            ->assertJsonFragment(['message' => 'Ссылка сохранена. Добавьте нормативный текст, чтобы использовать НПА в анализе.']);

        $source = Source::sole();
        $this->assertSame($user->id, $source->user_id);
        $this->assertDatabaseCount('source_versions', 0);
        $this->assertDatabaseHas('workspace_sources', ['workspace_id' => $workspace->id, 'source_id' => $source->id]);
    }

    public function test_inline_creation_for_foreign_workspace_is_forbidden_without_partial_records(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $workspace = $this->workspace($other);

        $this->actingAs($user)->withHeader('Accept', 'application/json')->post(route('workspaces.sources.inline.store', $workspace), [
            'title' => 'Чужой источник', 'type' => 'law', 'input_method' => 'url', 'official_url' => 'https://example.test/law',
        ])->assertForbidden();

        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('workspace_sources', 0);
    }

    public function test_docx_extraction_failure_rolls_back_everything_and_cleans_temporary_file(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $this->mock(DocxTextExtractor::class)->shouldReceive('extract')->once()->andThrow(new RuntimeException('broken'));

        $response = $this->actingAs($user)->withHeader('Accept', 'application/json')->post(route('workspaces.sources.inline.store', $workspace), [
            'title' => 'Повреждённый DOCX', 'type' => 'law', 'input_method' => 'docx', 'docx_file' => $this->docxUpload(['Текст']),
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Не удалось извлечь нормативный текст из DOCX-файла.', $response->json('errors.docx_file.0'));

        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('source_versions', 0);
        $this->assertDatabaseCount('workspace_sources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('source_versions'));
    }

    public function test_admin_inline_source_is_personal_not_global(): void
    {
        $admin = User::factory()->admin()->create();
        $workspace = $this->workspace($admin);

        $this->actingAs($admin)->postJson(route('workspaces.sources.inline.store', $workspace), [
            'title' => 'Личный источник администратора', 'type' => 'law', 'input_method' => 'url', 'official_url' => 'https://example.test/admin-law',
        ])->assertCreated();

        $this->assertSame($admin->id, Source::sole()->user_id);
    }

    public function test_inline_docx_version_can_run_existing_scenario_b_pipeline(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $inline = $this->actingAs($user)->withHeader('Accept', 'application/json')->post(route('workspaces.sources.inline.store', $workspace), [
            'title' => 'Источник для анализа', 'type' => 'law', 'input_method' => 'docx', 'docx_file' => $this->docxUpload(['Статья 1. Норма.']),
        ])->assertCreated();

        $this->mock(AnalysisExecutionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->once()->withArgs(fn (Analysis $analysis) => $analysis->analysis_type === 'amendment_drafting')->andReturnTrue();
        });

        $this->actingAs($user)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $workspace->id,
            'title' => 'Scenario B с личным НПА',
            'analysis_instruction' => 'Разработать поправки',
            'source_versions' => [$inline->json('version.id')],
        ])->assertRedirect();

        $this->assertSame('amendment_drafting', Analysis::sole()->analysis_type);
        Http::assertNothingSent();
    }

    private function workspace(User $user): Workspace
    {
        return Workspace::create(['user_id' => $user->id, 'reference_number' => 'WS-'.uniqid(), 'title' => 'Рабочее дело', 'category' => 'other', 'status' => 'draft']);
    }

    private function source(string $title, ?User $owner = null): Source
    {
        $source = Source::create(['title' => $title, 'type' => 'law', 'status' => 'active']);
        if ($owner) { $source->user()->associate($owner); $source->save(); }
        return $source;
    }

    private function docxUpload(array $paragraphs): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aiddu-inline-docx-').'.docx';
        $word = new PhpWord();
        $section = $word->addSection();
        foreach ($paragraphs as $paragraph) { $section->addText($paragraph); }
        IOFactory::createWriter($word, 'Word2007')->save($path);
        return new UploadedFile($path, 'normative-act.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }
}
