<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisCreationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_analysis_with_automatic_fields_and_selected_source_versions(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'WS-OWNER');
        $document = $this->documentFor($owner, $workspace);
        [$source, $firstVersion] = $this->sourceWithVersion('Закон', 'Редакция 1');
        $secondVersion = $this->versionFor($source, 'Редакция 2');
        $workspace->sources()->attach($source->id);

        $response = $this->actingAs($owner)->post(
            route('analyses.store', $document),
            [
                'title' => 'Подменённое название',
                'analysis_type' => 'custom',
                'instruction' => 'Подменённое поручение',
                'source_versions' => [$firstVersion->id, $secondVersion->id],
            ],
        );

        $analysis = Analysis::sole();

        $response->assertRedirect(route('analyses.show', $analysis));
        $this->assertDatabaseHas('analyses', [
            'id' => $analysis->id,
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'title' => 'Юридический анализ: '.$document->title,
            'analysis_type' => 'amendment_review',
            'instruction' => $document->analysis_instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $this->assertDatabaseHas('analysis_source_versions', [
            'analysis_id' => $analysis->id,
            'source_version_id' => $firstVersion->id,
            'role' => 'reference',
        ]);
        $this->assertDatabaseHas('analysis_source_versions', [
            'analysis_id' => $analysis->id,
            'source_version_id' => $secondVersion->id,
            'role' => 'reference',
        ]);
        $this->assertDatabaseCount('analysis_source_versions', 2);
    }

    public function test_analysis_requires_an_available_source_version_without_partial_writes(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'WS-NONE');
        $document = $this->documentFor($owner, $workspace);

        $this->actingAs($owner)
            ->from(route('analyses.create', $document))
            ->post(route('analyses.store', $document), [])
            ->assertRedirect(route('analyses.create', $document))
            ->assertSessionHasErrors('source_versions');

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('analysis_source_versions', 0);
    }

    public function test_source_version_from_another_workspace_is_rejected_without_partial_writes(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'WS-CURRENT');
        $otherWorkspace = $this->workspaceFor($otherOwner, 'WS-OTHER');
        $document = $this->documentFor($owner, $workspace);
        [$foreignSource, $foreignVersion] = $this->sourceWithVersion('Чужой источник', 'Редакция');
        $otherWorkspace->sources()->attach($foreignSource->id);

        $this->actingAs($owner)
            ->from(route('analyses.create', $document))
            ->post(route('analyses.store', $document), [
                'source_versions' => [$foreignVersion->id],
            ])
            ->assertRedirect(route('analyses.create', $document))
            ->assertSessionHasErrors('source_versions');

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('analysis_source_versions', 0);
    }

    public function test_create_page_lists_only_versions_connected_to_document_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'WS-LIST');
        $document = $this->documentFor($owner, $workspace);
        [$connectedSource, $connectedVersion] = $this->sourceWithVersion('Подключённый источник', 'Доступная редакция');
        [, $unconnectedVersion] = $this->sourceWithVersion('Неподключённый источник', 'Недоступная редакция');
        $workspace->sources()->attach($connectedSource->id);

        $this->actingAs($owner)
            ->get(route('analyses.create', $document))
            ->assertOk()
            ->assertSee('Подключённый источник')
            ->assertSee((string) $connectedVersion->id, false)
            ->assertDontSee('Неподключённый источник')
            ->assertDontSee('value="'.$unconnectedVersion->id.'"', false)
            ->assertSee($document->current_text)
            ->assertSee($document->proposed_text)
            ->assertSee($document->analysis_instruction)
            ->assertDontSee('name="title"', false)
            ->assertDontSee('name="analysis_type"', false)
            ->assertDontSee('name="instruction"', false);
    }

    public function test_user_cannot_create_analysis_for_foreign_document(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'WS-FOREIGN');
        $document = $this->documentFor($owner, $workspace);
        [$source, $version] = $this->sourceWithVersion('Источник', 'Редакция');
        $workspace->sources()->attach($source->id);

        $this->actingAs($intruder)
            ->post(route('analyses.store', $document), [
                'source_versions' => [$version->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('analysis_source_versions', 0);
    }

    public function test_legacy_document_is_blocked_until_instruction_is_saved(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'WS-LEGACY');
        $document = $this->documentFor($owner, $workspace, null);
        [$source, $version] = $this->sourceWithVersion('Источник', 'Редакция');
        $workspace->sources()->attach($source->id);

        $this->actingAs($owner)
            ->get(route('analyses.create', $document))
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('error');

        $this->actingAs($owner)
            ->post(route('analyses.store', $document), [
                'source_versions' => [$version->id],
            ])
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('analysis_source_versions', 0);
    }

    public function test_legacy_instruction_endpoint_updates_only_analysis_instruction(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'WS-PATCH');
        $document = $this->documentFor($owner, $workspace, null);
        $originalTitle = $document->title;
        $originalProposedText = $document->proposed_text;

        $this->actingAs($owner)
            ->patch(route('documents.analysis-instruction.update', $document), [
                'analysis_instruction' => 'Новое поручение ИИ',
                'title' => 'Подменённое название',
                'proposed_text' => 'Подменённая редакция',
                'workspace_id' => 999,
                'user_id' => 999,
            ])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();

        $this->assertSame('Новое поручение ИИ', $document->analysis_instruction);
        $this->assertSame($originalTitle, $document->title);
        $this->assertSame($originalProposedText, $document->proposed_text);
        $this->assertSame($workspace->id, $document->workspace_id);
        $this->assertSame($owner->id, $document->user_id);
    }

    public function test_analysis_show_hides_technical_summary_block(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'WS-SHOW');
        $document = $this->documentFor($owner, $workspace);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'title' => 'Юридический анализ: '.$document->title,
            'analysis_type' => 'comprehensive',
            'instruction' => $document->analysis_instruction,
            'status' => 'draft',
            'version' => 1,
        ]);

        $this->actingAs($owner)
            ->get(route('analyses.show', $analysis))
            ->assertOk()
            ->assertDontSee('Тип анализа')
            ->assertDontSee('>Статус<', false)
            ->assertDontSee('>Версия<', false)
            ->assertDontSee('>Источников<', false)
            ->assertSee('Действующая редакция')
            ->assertSee('Предлагаемая редакция')
            ->assertSee('Поручение ИИ')
            ->assertSee('Нормативная база анализа')
            ->assertSee('Результат анализа');
    }

    private function workspaceFor(User $user, string $referenceNumber): Workspace
    {
        return Workspace::create([
            'user_id' => $user->id,
            'reference_number' => $referenceNumber,
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
    }

    private function documentFor(User $user, Workspace $workspace, ?string $instruction = 'Провести юридический анализ'): Document
    {
        return Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Поправка',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'current_text' => 'Действующая редакция',
            'proposed_text' => 'Предлагаемая редакция',
            'analysis_instruction' => $instruction,
        ]);
    }

    private function sourceWithVersion(string $title, string $versionName): array
    {
        $source = Source::create([
            'title' => $title,
            'type' => 'law',
            'status' => 'active',
        ]);

        return [$source, $this->versionFor($source, $versionName)];
    }

    private function versionFor(Source $source, string $versionName): SourceVersion
    {
        return SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => $versionName,
            'effective_date' => '2026-01-01',
            'text' => 'Текст '.$versionName,
            'hash' => hash('sha256', $source->id.'-'.$versionName),
        ]);
    }
}
