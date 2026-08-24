<?php

namespace Tests\Feature\Source;

use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DocxTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Tests\TestCase;

class SourceUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.local.root', sys_get_temp_dir().'/aiddu-source-ux-'.uniqid());
        Storage::forgetDisk('local');
        Http::fake();
    }

    public function test_add_source_uses_sidebar_and_only_simplified_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $workspace = $this->workspace($admin);

        $this->actingAs($admin)
            ->get(route('workspaces.sources.create', $workspace))
            ->assertOk()
            ->assertSee('AI DDU Assistant')
            ->assertSee('Рабочие дела')
            ->assertSee('Добавление НПА')
            ->assertSee('Название НПА')
            ->assertSee('Загрузить DOCX')
            ->assertSee('Указать ссылку')
            ->assertSee('name="docx_file"', false)
            ->assertSee('name="official_url"', false)
            ->assertDontSee('name="issuing_authority"', false)
            ->assertDontSee('name="number"', false)
            ->assertDontSee('name="adoption_date"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="description"', false)
            ->assertDontSee('>law<', false)
            ->assertDontSee('>government_resolution<', false);
    }

    public function test_url_only_source_is_active_and_creates_no_fake_version(): void
    {
        $admin = User::factory()->admin()->create();
        $workspace = $this->workspace($admin);

        $this->actingAs($admin)->post(route('workspaces.sources.store', $workspace), [
            'title' => 'Закон по официальной ссылке',
            'type' => 'law',
            'input_method' => 'url',
            'official_url' => 'https://adilet.zan.kz/rus/docs/Z000000001',
            'visibility' => 'personal',
        ])->assertRedirect();

        $source = Source::sole();
        $this->assertSame('active', $source->status);
        $this->assertSame('https://adilet.zan.kz/rus/docs/Z000000001', $source->official_url);
        $this->assertDatabaseCount('source_versions', 0);

        $this->actingAs($admin)
            ->get(route('sources.show', $source))
            ->assertOk()
            ->assertSee('Для использования НПА в юридическом анализе добавьте редакцию нормативного текста.')
            ->assertSee(route('source-versions.create', $source), false);

        Http::assertNothingSent();
    }

    public function test_docx_source_atomically_creates_version_available_to_analysis_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $workspace = $this->workspace($admin);
        $docx = $this->docxUpload([
            'Статья 1. Общие положения',
            'Настоящий Закон регулирует общественные отношения.',
        ]);

        $this->actingAs($admin)->post(route('workspaces.sources.store', $workspace), [
            'title' => 'Закон из DOCX',
            'type' => 'law',
            'input_method' => 'docx',
            'docx_file' => $docx,
            'visibility' => 'personal',
        ])->assertRedirect();

        $source = Source::sole();
        $version = SourceVersion::sole();
        $expected = "Статья 1. Общие положения\n\nНастоящий Закон регулирует общественные отношения.";

        $this->assertSame($source->id, $version->source_id);
        $this->assertSame($expected, $version->text);
        $this->assertSame(hash('sha256', $expected), $version->hash);
        $this->assertSame([], Storage::disk('local')->allFiles('source_versions'));

        $this->actingAs($admin)
            ->get(route('analyses.workflow.create', ['workspace' => $workspace->id]))
            ->assertOk()
            ->assertSee($version->version_name);

        Http::assertNothingSent();
    }

    public function test_docx_extraction_failure_rolls_back_source(): void
    {
        $admin = User::factory()->admin()->create();
        $workspace = $this->workspace($admin);
        $extractor = $this->mock(DocxTextExtractor::class);
        $extractor->shouldReceive('extract')->once()->andThrow(new RuntimeException('broken docx'));

        $this->actingAs($admin)
            ->from(route('workspaces.sources.create', $workspace))
            ->post(route('workspaces.sources.store', $workspace), [
                'title' => 'Повреждённый НПА',
                'type' => 'law',
                'input_method' => 'docx',
                'docx_file' => $this->docxUpload(['Текст']),
                'visibility' => 'personal',
            ])
            ->assertRedirect(route('workspaces.sources.create', $workspace))
            ->assertSessionHasErrors('docx_file');

        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('source_versions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('source_versions'));
    }

    public function test_source_and_version_pages_keep_application_sidebar(): void
    {
        $admin = User::factory()->admin()->create();
        $source = Source::create(['title' => 'Кодекс', 'type' => 'code', 'status' => 'active']);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Действующая редакция',
            'text' => 'Статья 1. Норма.',
            'hash' => hash('sha256', 'Статья 1. Норма.'),
        ]);

        foreach ([
            route('sources.show', $source),
            route('source-versions.create', $source),
            route('source-versions.edit', [$source, $version]),
        ] as $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertSee('Рабочие дела')
                ->assertSee('Нормативная база');
        }
    }

    private function docxUpload(array $paragraphs): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aiddu-docx-').'.docx';
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        foreach ($paragraphs as $paragraph) {
            $section->addText($paragraph);
        }

        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return new UploadedFile(
            $path,
            'normative-act.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }

    private function workspace(User $user): Workspace
    {
        return Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-SOURCE-'.uniqid(),
            'title' => 'Рабочее дело НПА',
            'category' => 'other',
            'status' => 'draft',
        ]);
    }
}
