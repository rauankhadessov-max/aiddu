<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AnalysisExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class UnifiedAnalysisWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_form_only_shows_owned_workspaces_and_connected_sources(): void
    {
        [$owner, $workspace, $version] = $this->fixture();
        $foreign = User::factory()->create();
        $foreignWorkspace = $this->workspace($foreign, 'Чужое дело');
        [, $foreignVersion] = $this->sourceVersion('Чужой источник');
        $foreignWorkspace->sources()->attach($foreignVersion->source_id);

        $this->actingAs($owner)
            ->get(route('analyses.workflow.create'))
            ->assertOk()
            ->assertSee($workspace->title)
            ->assertSee($version->version_name)
            ->assertDontSee($foreignWorkspace->title)
            ->assertDontSee($foreignVersion->version_name)
            ->assertSee('Сохранить черновик')
            ->assertSee('Запустить анализ');
    }

    public function test_scenario_a_runs_through_shared_execution_service(): void
    {
        Http::fake();
        [$owner, $workspace, $version] = $this->fixture();

        $this->mock(AnalysisExecutionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->once()->withArgs(function (Analysis $analysis) {
                return $analysis->analysis_type === 'amendment_review';
            })->andReturnTrue();
        });

        $response = $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $workspace->id,
            'title' => 'Проверка готовой поправки',
            'current_text' => 'Действующая редакция',
            'proposed_text' => 'Предлагаемая редакция',
            'analysis_instruction' => 'Проверить поправку',
            'source_versions' => [$version->id],
        ]);

        $analysis = Analysis::sole();
        $response->assertRedirect(route('analyses.show', $analysis));
        $this->assertSame('amendment_review', $analysis->analysis_type);
        $this->assertSame('Предлагаемая редакция', $analysis->document->proposed_text);
        $this->assertSame([$version->id], $analysis->sourceVersions->pluck('id')->all());
        Http::assertNothingSent();
    }

    public function test_scenario_b_runs_with_only_instruction_and_source(): void
    {
        Http::fake();
        [$owner, $workspace, $version] = $this->fixture();

        $this->mock(AnalysisExecutionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->once()->withArgs(function (Analysis $analysis) {
                return $analysis->analysis_type === 'amendment_drafting';
            })->andReturnTrue();
        });

        $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $workspace->id,
            'title' => 'Разработка поправок',
            'analysis_instruction' => 'Разработать необходимые поправки',
            'source_versions' => [$version->id],
        ])->assertRedirect();

        $analysis = Analysis::sole();
        $this->assertSame('amendment_drafting', $analysis->analysis_type);
        $this->assertNull($analysis->document->current_text);
        $this->assertNull($analysis->document->proposed_text);
        Http::assertNothingSent();
    }

    public function test_draft_is_saved_and_resumed_without_execution(): void
    {
        Http::fake();
        [$owner, $workspace] = $this->fixture();
        $this->mock(AnalysisExecutionService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('execute'));

        $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'save_draft',
            'workspace_id' => $workspace->id,
            'title' => 'Незавершённый анализ',
        ])->assertRedirect();

        $analysis = Analysis::sole();
        $this->assertSame('draft', $analysis->status);
        $this->actingAs($owner)
            ->get(route('analyses.workflow.edit', $analysis))
            ->assertOk()
            ->assertSee('Незавершённый анализ');
        Http::assertNothingSent();
    }

    public function test_source_version_from_another_workspace_is_rejected_without_partial_records(): void
    {
        [$owner, $workspace] = $this->fixture();
        [, $foreignVersion] = $this->sourceVersion('Не подключённый источник');

        $this->actingAs($owner)->from(route('analyses.workflow.create'))->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $workspace->id,
            'title' => 'Недопустимый анализ',
            'analysis_instruction' => 'Проверить',
            'source_versions' => [$foreignVersion->id],
        ])->assertRedirect(route('analyses.workflow.create'))
            ->assertSessionHasErrors('source_versions');

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('analysis_source_versions', 0);
    }

    public function test_foreign_personal_source_version_is_rejected_even_if_pivot_is_injected(): void
    {
        [$owner, $workspace] = $this->fixture();
        $other = User::factory()->create();
        [$foreignSource, $foreignVersion] = $this->sourceVersion('Чужой личный источник');
        $foreignSource->user()->associate($other);
        $foreignSource->save();
        $workspace->sources()->attach($foreignSource);

        $this->actingAs($owner)->from(route('analyses.workflow.create'))->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $workspace->id,
            'title' => 'Недопустимый анализ',
            'analysis_instruction' => 'Проверить',
            'source_versions' => [$foreignVersion->id],
        ])->assertRedirect(route('analyses.workflow.create'))->assertSessionHasErrors('source_versions');

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('analysis_source_versions', 0);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'Рабочее дело пользователя');
        [$source, $version] = $this->sourceVersion('Закон Республики Казахстан');
        $workspace->sources()->attach($source->id);

        return [$owner, $workspace, $version];
    }

    private function workspace(User $user, string $title): Workspace
    {
        return Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-'.uniqid(),
            'title' => $title,
            'category' => 'other',
            'status' => 'draft',
        ]);
    }

    private function sourceVersion(string $title): array
    {
        $source = Source::create(['title' => $title, 'type' => 'law', 'status' => 'active']);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => $title.' — редакция 2026',
            'text' => 'Статья 1. Проверяемая норма.',
            'hash' => hash('sha256', $title),
        ]);

        return [$source, $version];
    }
}
