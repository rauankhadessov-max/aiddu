<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalysisDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_deletes_analysis_dependencies_and_docx_but_preserves_input_domain(): void
    {
        config()->set('filesystems.disks.local.root', sys_get_temp_dir().'/aiddu-analysis-deletion-'.uniqid());
        Storage::forgetDisk('local');
        Http::fake();
        [$owner, $workspace, $document, $source, $version, $analysis] = $this->fixture();

        $analysis->findings()->create([
            'finding_type' => 'compliance',
            'title' => 'Замечание',
        ]);
        $analysis->amendments()->create([
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'target_mode' => 'new',
            'structural_element_type' => 'article',
            'article' => '10-1',
            'amendment_type' => 'addition',
            'disposition' => 'draft',
            'justification' => 'Подтверждённое обоснование',
            'legal_basis' => 'Статья 10',
            'source_reference' => 'Закон, статья 10',
            'citations' => [],
            'target_snapshot' => [],
        ]);

        $package = $analysis->draftPackage()->create([
            'title' => 'Пакет документов',
            'status' => 'generated',
        ]);
        $canonical = $package->artifacts()->create([
            'created_by' => $owner->id,
            'artifact_type' => 'comparative_table',
            'format' => 'structured_json',
            'title' => 'Сравнительная таблица',
            'content' => ['rows' => []],
            'status' => 'generated',
        ]);
        $path = 'draft-packages/'.$package->id.'/docx/'.$canonical->id.'/v2/table.docx';
        Storage::disk('local')->put($path, 'valid-test-docx');
        $representation = $package->artifacts()->create([
            'source_artifact_id' => $canonical->id,
            'created_by' => $owner->id,
            'artifact_type' => 'comparative_table_docx',
            'format' => 'docx',
            'title' => 'Сравнительная таблица DOCX',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'filename' => 'comparative-table.docx',
            'status' => 'generated',
        ]);

        $this->actingAs($owner)
            ->delete(route('analyses.destroy', $analysis))
            ->assertRedirect(route('analyses.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('analyses', ['id' => $analysis->id]);
        $this->assertDatabaseMissing('analysis_findings', ['analysis_id' => $analysis->id]);
        $this->assertDatabaseMissing('analysis_amendments', ['analysis_id' => $analysis->id]);
        $this->assertDatabaseMissing('analysis_source_versions', ['analysis_id' => $analysis->id]);
        $this->assertDatabaseMissing('draft_packages', ['id' => $package->id]);
        $this->assertDatabaseMissing('artifacts', ['id' => $canonical->id]);
        $this->assertDatabaseMissing('artifacts', ['id' => $representation->id]);
        Storage::disk('local')->assertMissing($path);

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseHas('sources', ['id' => $source->id]);
        $this->assertDatabaseHas('source_versions', ['id' => $version->id]);
        Http::assertNothingSent();
    }

    public function test_foreign_user_cannot_delete_analysis(): void
    {
        [, , , , , $analysis] = $this->fixture();

        $this->actingAs(User::factory()->create())
            ->delete(route('analyses.destroy', $analysis))
            ->assertForbidden();

        $this->assertDatabaseHas('analyses', ['id' => $analysis->id]);
    }

    public function test_processing_analysis_cannot_be_deleted(): void
    {
        [$owner, , , , , $analysis] = $this->fixture();
        $analysis->update(['status' => 'processing']);

        $this->actingAs($owner)
            ->from(route('analyses.index'))
            ->delete(route('analyses.destroy', $analysis))
            ->assertRedirect(route('analyses.index'))
            ->assertSessionHas('error', 'Нельзя удалить анализ, пока он выполняется.');

        $this->assertDatabaseHas('analyses', ['id' => $analysis->id]);
    }

    public function test_history_shows_confirmation_and_hides_machine_status(): void
    {
        [$owner, , , , , $analysis] = $this->fixture();

        $this->actingAs($owner)
            ->get(route('analyses.index'))
            ->assertOk()
            ->assertSee('Черновик')
            ->assertDontSee('>draft<', false)
            ->assertSee('Удалить анализ?')
            ->assertSee('Будут удалены результаты анализа и сформированные документы.')
            ->assertSee('Исходные данные рабочего дела и нормативная база сохранятся.')
            ->assertSee(route('analyses.destroy', $analysis), false);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $owner->id,
            'reference_number' => 'WS-'.uniqid(),
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'title' => 'Внутренний документ',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'analysis_instruction' => 'Проверить',
        ]);
        $source = Source::create(['title' => 'Закон', 'type' => 'law', 'status' => 'active']);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => 'Статья 10. Норма.',
            'hash' => hash('sha256', 'deletion-fixture'),
        ]);
        $workspace->sources()->attach($source->id);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'title' => 'Удаляемый анализ',
            'analysis_type' => 'amendment_drafting',
            'instruction' => 'Проверить',
            'status' => 'draft',
            'version' => 1,
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);

        return [$owner, $workspace, $document, $source, $version, $analysis];
    }
}
