<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AnalysisExecutionService;
use App\Services\DraftPackageAutoGenerationService;
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

    public function test_create_form_selects_active_default_workspace_and_shows_its_sources(): void
    {
        [$owner, $workspace, $version] = $this->fixture();
        $this->makeDefault($workspace);

        $this->actingAs($owner)
            ->get(route('analyses.workflow.create'))
            ->assertOk()
            ->assertSee('<option value="'.$workspace->id.'" selected>', false)
            ->assertSee($version->version_name)
            ->assertSee('id="inline-source-toggle" type="button"', false)
            ->assertDontSee('id="inline-source-toggle" type="button" disabled', false);
    }

    public function test_scenario_a_without_workspace_id_uses_default_workspace(): void
    {
        Http::fake();
        [$owner, $workspace, $version] = $this->fixture();
        $this->makeDefault($workspace);
        $this->mock(AnalysisExecutionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('execute')->once()->andReturnTrue());

        $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'title' => 'Scenario A без выбора рабочего дела',
            'current_text' => 'Действующая редакция',
            'proposed_text' => 'Предлагаемая редакция',
            'analysis_instruction' => 'Проверить поправку',
        ])->assertRedirect();

        $analysis = Analysis::sole();
        $this->assertSame($workspace->id, $analysis->workspace_id);
        $this->assertSame('amendment_review', $analysis->analysis_type);
        $this->assertSame([$version->id], $analysis->sourceVersions->pluck('id')->all());
        Http::assertNothingSent();
    }

    public function test_scenario_b_without_workspace_id_uses_default_workspace(): void
    {
        Http::fake();
        [$owner, $workspace, $version] = $this->fixture();
        $this->makeDefault($workspace);
        $this->mock(AnalysisExecutionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('execute')->once()->andReturnTrue());

        $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'title' => 'Scenario B без выбора рабочего дела',
            'analysis_instruction' => 'Разработать необходимые поправки',
        ])->assertRedirect();

        $analysis = Analysis::sole();
        $this->assertSame($workspace->id, $analysis->workspace_id);
        $this->assertSame('amendment_drafting', $analysis->analysis_type);
        $this->assertSame([$version->id], $analysis->sourceVersions->pluck('id')->all());
        Http::assertNothingSent();
    }

    public function test_automatic_selection_uses_current_version_per_eligible_source(): void
    {
        Http::fake();
        [$owner, $workspace, $undatedVersion] = $this->fixture();
        $newerUndated = SourceVersion::create([
            'source_id' => $undatedVersion->source_id,
            'version_name' => 'Новая редакция без даты',
            'text' => 'Новый текст без даты',
            'hash' => hash('sha256', 'newer-undated'),
        ]);

        [$datedSource, $oldVersion] = $this->sourceVersion('Источник с датами');
        $oldVersion->update(['effective_date' => now()->subYear()->toDateString()]);
        $currentVersion = SourceVersion::create([
            'source_id' => $datedSource->id,
            'version_name' => 'Текущая редакция',
            'effective_date' => now()->subDay()->toDateString(),
            'text' => 'Текущая норма',
            'hash' => hash('sha256', 'current-version'),
        ]);
        $futureVersion = SourceVersion::create([
            'source_id' => $datedSource->id,
            'version_name' => 'Будущая редакция',
            'effective_date' => now()->addYear()->toDateString(),
            'text' => 'Будущая норма',
            'hash' => hash('sha256', 'future-version'),
        ]);
        $workspace->sources()->attach($datedSource);

        $this->mock(AnalysisExecutionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('execute')->once()->andReturnTrue());

        $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $workspace->id,
            'title' => 'Автоматическая нормативная база',
            'analysis_instruction' => 'Проверить нормы',
        ])->assertRedirect();

        $selected = Analysis::sole()->sourceVersions->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$newerUndated->id, $currentVersion->id])->sort()->values()->all(), $selected);
        $this->assertNotContains($undatedVersion->id, $selected);
        $this->assertNotContains($oldVersion->id, $selected);
        $this->assertNotContains($futureVersion->id, $selected);
        Http::assertNothingSent();
    }

    public function test_explicit_selection_limits_versions_even_when_a_newer_version_exists(): void
    {
        Http::fake();
        [$owner, $workspace, $selectedVersion] = $this->fixture();
        $newerVersion = SourceVersion::create([
            'source_id' => $selectedVersion->source_id,
            'version_name' => 'Новая доступная редакция',
            'effective_date' => now()->subDay()->toDateString(),
            'text' => 'Новая доступная норма',
            'hash' => hash('sha256', 'newer-explicit-version'),
        ]);
        $this->mock(AnalysisExecutionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('execute')->once()->andReturnTrue());

        $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $workspace->id,
            'title' => 'Явное ограничение базы',
            'analysis_instruction' => 'Проверить выбранную редакцию',
            'source_versions' => [$selectedVersion->id],
        ])->assertRedirect();

        $this->assertSame([$selectedVersion->id], Analysis::sole()->sourceVersions->pluck('id')->all());
        $this->assertDatabaseMissing('analysis_source_versions', [
            'analysis_id' => Analysis::sole()->id,
            'source_version_id' => $newerVersion->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_run_without_selection_fails_when_workspace_has_no_usable_versions(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'Пустая нормативная база');
        $this->mock(AnalysisExecutionService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('execute'));

        $this->actingAs($owner)
            ->from(route('analyses.workflow.create'))
            ->post(route('analyses.workflow.store'), [
                'action' => 'run',
                'workspace_id' => $workspace->id,
                'title' => 'Анализ без нормативного текста',
                'analysis_instruction' => 'Проверить',
            ])
            ->assertRedirect(route('analyses.workflow.create'))
            ->assertSessionHasErrors([
                'source_versions' => 'В выбранном рабочем деле нет редакций НПА, доступных для анализа. Добавьте нормативный текст или подключите источник.',
            ]);

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('documents', 0);
        Http::assertNothingSent();
    }

    public function test_explicit_workspace_has_priority_over_default_workspace(): void
    {
        Http::fake();
        [$owner, $defaultWorkspace] = $this->fixture();
        $this->makeDefault($defaultWorkspace);
        $explicitWorkspace = $this->workspace($owner, 'Явно выбранное дело');
        [$source, $version] = $this->sourceVersion('Источник явного дела');
        $explicitWorkspace->sources()->attach($source);
        $this->mock(AnalysisExecutionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('execute')->once()->andReturnTrue());

        $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $explicitWorkspace->id,
            'title' => 'Явно выбранное дело',
            'analysis_instruction' => 'Разработать поправки',
            'source_versions' => [$version->id],
        ])->assertRedirect();

        $this->assertSame($explicitWorkspace->id, Analysis::sole()->workspace_id);
        Http::assertNothingSent();
    }

    public function test_missing_workspace_still_fails_when_default_workspace_does_not_exist(): void
    {
        [$owner, , $version] = $this->fixture();

        $this->actingAs($owner)->from(route('analyses.workflow.create'))->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'title' => 'Нет рабочего дела по умолчанию',
            'analysis_instruction' => 'Проверить',
            'source_versions' => [$version->id],
        ])->assertRedirect(route('analyses.workflow.create'))->assertSessionHasErrors('workspace_id');

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_foreign_explicit_workspace_is_rejected_instead_of_falling_back_to_default(): void
    {
        [$owner, $defaultWorkspace] = $this->fixture();
        $this->makeDefault($defaultWorkspace);
        $other = User::factory()->create();
        $foreignWorkspace = $this->workspace($other, 'Чужое рабочее дело');
        [$foreignSource, $foreignVersion] = $this->sourceVersion('Источник чужого дела');
        $foreignWorkspace->sources()->attach($foreignSource);

        $this->actingAs($owner)->from(route('analyses.workflow.create'))->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $foreignWorkspace->id,
            'title' => 'Подмена рабочего дела',
            'analysis_instruction' => 'Проверить',
            'source_versions' => [$foreignVersion->id],
        ])->assertRedirect(route('analyses.workflow.create'))->assertSessionHasErrors('workspace_id');

        $this->assertDatabaseCount('analyses', 0);
        $this->assertDatabaseCount('documents', 0);
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

    public function test_successful_unified_run_invokes_package_automation(): void
    {
        Http::fake();
        [$owner, $workspace, $version] = $this->fixture();
        $this->mock(AnalysisExecutionService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('execute')->once()->andReturnTrue());
        $this->mock(DraftPackageAutoGenerationService::class, function (MockInterface $mock) use ($owner) {
            $mock->shouldReceive('generate')->once()->withArgs(
                fn (Analysis $analysis, User $user) => $analysis->user_id === $owner->id && $user->is($owner),
            )->andReturnNull();
        });

        $this->actingAs($owner)->post(route('analyses.workflow.store'), [
            'action' => 'run',
            'workspace_id' => $workspace->id,
            'title' => 'Анализ с автоматическим пакетом',
            'analysis_instruction' => 'Разработать поправку',
            'source_versions' => [$version->id],
        ])->assertRedirect();

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

    private function makeDefault(Workspace $workspace): void
    {
        $profile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
        $workspace->update(['regulatory_profile_id' => $profile->id]);
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
