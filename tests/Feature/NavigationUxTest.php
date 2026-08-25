<?php

namespace Tests\Feature;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('Правовой ИИ')
            ->assertSee('Анализ и подготовка НПА')
            ->assertDontSee('AI DDU Assistant')
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

    public function test_branding_dashboard_and_responsive_navigation_use_the_legal_workspace_system(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Правовой ИИ')
            ->assertSee('Анализ и подготовка НПА')
            ->assertSee('app-sidebar', false)
            ->assertSee('app-mobile-nav', false)
            ->assertSee('Основные действия')
            ->assertDontSee('Текущий этап разработки')
            ->assertDontSee('Архитектура AI DDU Assistant')
            ->assertDontSee('AI DDU Assistant');

        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), route('workspaces.index')));
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), route('analyses.workflow.create')));
        Http::assertNothingSent();
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

    public function test_workspace_cards_hide_machine_status_and_mark_only_active_default_profile(): void
    {
        $owner = User::factory()->create();
        $defaultWorkspace = $this->workspaceFor($owner);
        $defaultProfile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
        $defaultWorkspace->update(['regulatory_profile_id' => $defaultProfile->id]);
        $this->workspaceFor($owner)->update(['title' => 'Обычное рабочее дело']);

        $response = $this->actingAs($owner)->get(route('workspaces.index'));

        $response->assertOk()
            ->assertDontSee('draft')
            ->assertSee('По умолчанию')
            ->assertSee('Обычное рабочее дело');
        $this->assertSame(1, substr_count($response->getContent(), 'По умолчанию'));
    }

    public function test_each_users_active_default_workspace_is_first_regardless_of_creation_date(): void
    {
        $profile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
        $profile->update(['is_active' => true]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $firstDefault = $this->workspaceFor($firstUser);
        $firstDefault->update([
            'title' => 'Default первого пользователя',
            'regulatory_profile_id' => $profile->id,
            'created_at' => now()->subDays(5),
        ]);
        $firstRecent = $this->workspaceFor($firstUser);
        $firstRecent->update(['title' => 'Новое дело первого пользователя']);
        $firstOlder = $this->workspaceFor($firstUser);
        $firstOlder->update([
            'title' => 'Предыдущее дело первого пользователя',
            'created_at' => now()->subDay(),
        ]);

        $secondDefault = $this->workspaceFor($secondUser);
        $secondDefault->update([
            'title' => 'Default второго пользователя',
            'regulatory_profile_id' => $profile->id,
            'created_at' => now()->subDays(10),
        ]);
        $secondRecent = $this->workspaceFor($secondUser);
        $secondRecent->update(['title' => 'Новое дело второго пользователя']);

        $firstResponse = $this->actingAs($firstUser)->get(route('workspaces.index'));
        $firstResponse->assertOk()
            ->assertSeeInOrder([$firstDefault->title, $firstRecent->title, $firstOlder->title])
            ->assertDontSee($secondDefault->title)
            ->assertDontSee($secondRecent->title);

        $secondResponse = $this->actingAs($secondUser)->get(route('workspaces.index'));
        $secondResponse->assertOk()
            ->assertSeeInOrder([$secondDefault->title, $secondRecent->title])
            ->assertDontSee($firstDefault->title)
            ->assertDontSee($firstRecent->title);

        $this->assertSame(1, substr_count($firstResponse->getContent(), 'По умолчанию'));
        $this->assertSame(1, substr_count($secondResponse->getContent(), 'По умолчанию'));
    }

    public function test_workspace_index_uses_compact_badge_and_responsive_layout_without_decoration(): void
    {
        $view = file_get_contents(resource_path('views/workspaces/index.blade.php'));

        $this->assertStringContainsString('flex justify-end', $view);
        $this->assertStringContainsString('ui-btn-primary w-full', $view);
        $this->assertStringContainsString('sm:w-auto', $view);
        $this->assertStringContainsString('inline-flex shrink-0 whitespace-nowrap rounded-full', $view);
        $this->assertStringNotContainsString('absolute', $view);
        $this->assertStringNotContainsString('workspace-card-decoration', $view);
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
            ->assertSee('В выбранном рабочем деле нет редакций НПА, доступных для анализа.');
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
            if (! $file->isFile() || $file->getExtension() !== 'php') {
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
            'reference_number' => 'WS-'.$user->id.'-'.uniqid(),
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
