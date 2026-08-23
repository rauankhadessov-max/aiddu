<?php

namespace Tests\Feature;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class NavigationUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_has_working_mvp_navigation_and_disabled_future_modules(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('workspaces.index'), false)
            ->assertSee(route('analyses.workflow.create'), false)
            ->assertSee(route('sources.index'), false)
            ->assertSee(route('analyses.index'), false)
            ->assertSee('AI-консультант')
            ->assertSee('RU / KZ')
            ->assertSee('aria-disabled="true"', false)
            ->assertSee('Скоро')
            ->assertDontSee('href="#"', false);
    }

    public function test_new_analysis_entry_point_opens_unified_form(): void
    {
        $user = User::factory()->create();
        $this->workspaceFor($user);

        $this->actingAs($user)
            ->get(route('analyses.workflow.create'))
            ->assertOk()
            ->assertSee('Название анализа')
            ->assertSee('Действующая редакция')
            ->assertSee('Предлагаемая редакция')
            ->assertSee('Поручение ИИ')
            ->assertSee('Нормативная база');
    }

    public function test_sources_and_analysis_history_keep_the_main_sidebar(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('sources.index'))
            ->assertOk()
            ->assertSee('Рабочие дела')
            ->assertSee('Новый анализ')
            ->assertSee('История анализов');

        $this->actingAs($user)
            ->get(route('analyses.index'))
            ->assertOk()
            ->assertSee('Рабочие дела')
            ->assertSee('Нормативная база')
            ->assertSee('История анализов');
    }

    public function test_workspace_page_lists_recent_analyses_and_connected_sources_without_documents(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $document = $this->documentFor($owner, $workspace);
        [$source] = $this->sourceWithVersion();
        $workspace->sources()->attach($source->id);

        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'title' => 'Последний юридический анализ',
            'analysis_type' => 'amendment_review',
            'instruction' => 'Проверить',
            'status' => 'completed',
            'version' => 1,
        ]);

        $this->actingAs($owner)
            ->get(route('workspaces.show', $workspace))
            ->assertOk()
            ->assertSee($analysis->title)
            ->assertSee(route('analyses.show', $analysis), false)
            ->assertDontSee(route('documents.show', $document), false)
            ->assertDontSee($document->document_type)
            ->assertSee($source->title)
            ->assertDontSee('1 редакций')
            ->assertSee(route('workspaces.sources', $workspace), false);
    }

    public function test_document_page_lists_instruction_and_existing_analyses(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $document = $this->documentFor($owner, $workspace);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'title' => 'Юридический анализ: '.$document->title,
            'analysis_type' => 'comprehensive',
            'instruction' => $document->analysis_instruction,
            'status' => 'completed',
            'version' => 1,
        ]);

        $this->actingAs($owner)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee($document->analysis_instruction)
            ->assertSee($analysis->title)
            ->assertSee('completed')
            ->assertSee(route('analyses.show', $analysis), false)
            ->assertSee(route('analyses.workflow.create', ['document' => $document->id]), false);
    }

    public function test_document_and_analysis_validation_errors_are_visible(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $document = $this->documentFor($owner, $workspace);

        $this->actingAs($owner)
            ->from(route('documents.create', $workspace))
            ->followingRedirects()
            ->post(route('documents.store', $workspace), [])
            ->assertOk()
            ->assertSee('Проверьте заполнение формы:');

        $this->actingAs($owner)
            ->from(route('analyses.create', $document))
            ->followingRedirects()
            ->post(route('analyses.store', $document), [])
            ->assertOk()
            ->assertSee('Выберите хотя бы одну редакцию нормативного источника.');
    }

    public function test_welcome_actions_are_working_and_capabilities_anchor_remains(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('login'), false)
            ->assertSee('href="#capabilities"', false)
            ->assertDontSee('href="#"', false);
    }

    public function test_blade_views_contain_no_empty_hash_links(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views')),
        );

        $checkedFiles = 0;

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            $this->assertStringNotContainsString('href="#"', $contents, $file->getPathname());
            $this->assertStringNotContainsString("href='#'", $contents, $file->getPathname());
            $checkedFiles++;
        }

        $this->assertGreaterThan(0, $checkedFiles);
    }

    private function workspaceFor(User $user): Workspace
    {
        return Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-'.$user->id,
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
    }

    private function documentFor(User $user, Workspace $workspace): Document
    {
        return Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Проект поправки',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'current_text' => 'Действующая редакция',
            'proposed_text' => 'Предлагаемая редакция',
            'analysis_instruction' => 'Провести юридический анализ',
        ]);
    }

    private function sourceWithVersion(): array
    {
        $source = Source::create([
            'title' => 'Подключённый закон',
            'type' => 'law',
            'status' => 'active',
        ]);

        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026 года',
            'effective_date' => '2026-01-01',
            'text' => 'Текст нормативного источника',
            'hash' => hash('sha256', 'navigation-ux-source-version'),
        ]);

        return [$source, $version];
    }
}
