<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalysisHistoryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_compact_uses_real_counts_and_only_shows_existing_documents(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'Основное рабочее дело');
        $analysis = $this->analysis($owner, $workspace, 'Проверка поправки', 'completed');

        [$sourceA, $versionA1] = $this->sourceVersion('Закон А', 'Первая редакция');
        [, $versionA2] = $this->sourceVersion('Закон А', 'Вторая редакция', $sourceA);
        [, $versionB] = $this->sourceVersion('Кодекс Б', 'Действующая редакция');
        $analysis->sourceVersions()->attach([$versionA1->id, $versionA2->id, $versionB->id]);

        foreach ([1, 2] as $sortOrder) {
            $analysis->amendments()->create([
                'source_id' => $sourceA->id,
                'source_version_id' => $versionA1->id,
                'target_mode' => 'existing',
                'structural_element_type' => 'article',
                'article' => (string) $sortOrder,
                'amendment_type' => 'revision',
                'disposition' => 'revise',
                'justification' => 'Обоснование',
                'legal_basis' => 'Правовое основание',
                'source_reference' => 'Закон А',
                'citations' => [],
                'target_snapshot' => [],
                'sort_order' => $sortOrder,
            ]);
        }

        $package = $analysis->draftPackage()->create(['title' => 'Пакет', 'status' => 'generated']);
        $table = $package->artifacts()->create([
            'created_by' => $owner->id,
            'artifact_type' => 'comparative_table',
            'format' => 'structured_json',
            'title' => 'Сравнительная таблица',
            'content' => ['rows' => []],
            'status' => 'generated',
        ]);
        $package->artifacts()->create([
            'source_artifact_id' => $table->id,
            'created_by' => $owner->id,
            'artifact_type' => 'comparative_table_docx',
            'format' => 'docx',
            'title' => 'DOCX representation',
            'status' => 'generated',
        ]);

        Model::preventLazyLoading();

        try {
            $response = $this->actingAs($owner)->get(route('analyses.index'));
        } finally {
            Model::preventLazyLoading(false);
        }

        $response->assertOk()
            ->assertSee('Проверка поправки')
            ->assertSee('Основное рабочее дело')
            ->assertSee('Поправки')
            ->assertSee('НПА')
            ->assertSee('Сравнительная таблица')
            ->assertSee(route('artifacts.show', $table), false)
            ->assertDontSee('Проект НПА')
            ->assertDontSee('PDF')
            ->assertDontSee('XLS')
            ->assertSee('hidden overflow-x-auto lg:block', false)
            ->assertSee('space-y-3 lg:hidden', false)
            ->assertSee('<details', false);

        $response->assertViewHas('analyses', function ($analyses) use ($analysis) {
            $item = $analyses->firstWhere('id', $analysis->id);

            return $item !== null
                && (int) $item->amendments_count === 2
                && (int) $item->sources_count === 2;
        });

        Http::assertNothingSent();
    }

    public function test_search_workspace_and_status_filters_are_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $workspaceA = $this->workspace($owner, 'Долевое строительство');
        $workspaceB = $this->workspace($owner, 'Жилищные отношения');
        $matching = $this->analysis($owner, $workspaceA, 'Изменение статьи 13', 'completed');
        $this->analysis($owner, $workspaceA, 'Другой черновик', 'draft');
        $this->analysis($owner, $workspaceB, 'Изменение статьи 13 в другом деле', 'completed');

        $foreignUser = User::factory()->create();
        $foreignWorkspace = $this->workspace($foreignUser, 'Чужое рабочее дело');
        $foreignAnalysis = $this->analysis($foreignUser, $foreignWorkspace, 'Изменение статьи 13 — чужое', 'completed');

        $this->actingAs($owner)
            ->get(route('analyses.index', [
                'search' => 'статьи 13',
                'workspace' => $workspaceA->id,
                'status' => 'completed',
            ]))
            ->assertOk()
            ->assertSee($matching->title)
            ->assertDontSee('Другой черновик')
            ->assertDontSee('в другом деле')
            ->assertDontSee($foreignAnalysis->title)
            ->assertDontSee($foreignWorkspace->title);

        $this->actingAs($owner)
            ->get(route('analyses.index', ['workspace' => $foreignWorkspace->id]))
            ->assertOk()
            ->assertSee('Ничего не найдено')
            ->assertDontSee($matching->title)
            ->assertDontSee($foreignAnalysis->title);
    }

    public function test_pagination_preserves_search_and_filters(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'Профильное дело');

        foreach (range(1, 16) as $number) {
            $this->analysis($owner, $workspace, 'History item '.$number, 'completed');
        }

        $response = $this->actingAs($owner)->get(route('analyses.index', [
            'search' => 'History',
            'workspace' => $workspace->id,
            'status' => 'completed',
        ]));

        $response->assertOk()->assertViewHas('analyses', function ($analyses) use ($workspace) {
            $nextPageUrl = $analyses->nextPageUrl();

            return $analyses->count() === 15
                && str_contains($nextPageUrl, 'search=History')
                && str_contains($nextPageUrl, 'workspace='.$workspace->id)
                && str_contains($nextPageUrl, 'status=completed');
        });
    }

    public function test_primary_action_and_delete_menu_follow_analysis_status(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'Рабочее дело');
        $draft = $this->analysis($owner, $workspace, 'Черновой анализ', 'draft');
        $completed = $this->analysis($owner, $workspace, 'Готовый анализ', 'completed');

        $response = $this->actingAs($owner)->get(route('analyses.index'));

        $response->assertOk()
            ->assertSee(route('analyses.workflow.edit', $draft), false)
            ->assertSee('Продолжить')
            ->assertSee(route('analyses.show', $completed), false)
            ->assertSee('Открыть')
            ->assertSee(route('analyses.destroy', $draft), false)
            ->assertSee('Действия с анализом Черновой анализ')
            ->assertSee('Удалить анализ?');
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

    private function analysis(User $user, Workspace $workspace, string $title, string $status): Analysis
    {
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => $title,
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'analysis_instruction' => 'Проверить',
        ]);

        return Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => $title,
            'analysis_type' => 'amendment_review',
            'instruction' => 'Проверить',
            'status' => $status,
            'version' => 1,
        ]);
    }

    private function sourceVersion(string $sourceTitle, string $versionName, ?Source $source = null): array
    {
        $source ??= Source::create([
            'title' => $sourceTitle,
            'type' => 'law',
            'status' => 'active',
        ]);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => $versionName,
            'text' => 'Статья 1. Норма '.$versionName,
            'hash' => hash('sha256', $source->id.'-'.$versionName),
        ]);

        return [$source, $version];
    }
}
