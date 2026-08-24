<?php

namespace Tests\Feature\Source;

use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DefaultWorkspaceProvisioner;
use App\Services\RegulatoryProfileWorkspaceSynchronizer;
use App\Services\WorkspaceSourceVersionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class WorkspaceRegulatoryBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.local.root', sys_get_temp_dir().'/aiddu-regulatory-base-'.uniqid());
        Storage::forgetDisk('local');
        Http::fake();
    }

    public function test_regulatory_base_starts_with_owned_workspace_selector_and_marks_default_workspace(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $profile = $this->profile();
        $default = $this->workspace($user, 'Профильное рабочее дело', $profile);
        $ordinary = $this->workspace($user, 'Иное рабочее дело');
        $foreign = $this->workspace($other, 'Чужое рабочее дело');

        $this->actingAs($user)->get(route('sources.index'))
            ->assertOk()
            ->assertSee($default->title)
            ->assertSee($ordinary->title)
            ->assertSee('По умолчанию')
            ->assertDontSee($foreign->title)
            ->assertSee(route('workspaces.sources', $default), false)
            ->assertDontSee('WS-')
            ->assertDontSee('draft');
    }

    public function test_workspace_page_groups_only_global_and_owned_personal_sources(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $workspace = $this->workspace($user, 'Рабочее дело');
        $global = $this->source('Глобальный закон');
        $own = $this->source('Мой приказ', $user, 'order');
        $foreign = $this->source('Чужой НПА', $other);
        $globalVersion = $this->version($global, 'Действующая редакция');
        $workspace->sources()->attach([$global->id, $own->id, $foreign->id]);

        $this->actingAs($user)->get(route('workspaces.sources', $workspace))
            ->assertOk()
            ->assertSee('Глобальные НПА')
            ->assertSee('Мои НПА')
            ->assertSee($global->title)
            ->assertSee($own->title)
            ->assertSee($globalVersion->version_name)
            ->assertSee('Закон')
            ->assertSee('Приказ')
            ->assertDontSee($foreign->title)
            ->assertSee('+ Добавить НПА')
            ->assertDontSee('active')
            ->assertDontSee('law');
    }

    public function test_admin_visibility_defaults_follow_active_default_profile_relation_only(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = $this->profile();
        $default = $this->workspace($admin, 'Название не используется для определения', $profile);
        $ordinary = $this->workspace($admin, $profile->workspace_title);

        $this->actingAs($admin)->get(route('workspaces.sources.create', $default))
            ->assertOk()
            ->assertSee('value="global" checked', false);

        $this->actingAs($admin)->get(route('workspaces.sources.create', $ordinary))
            ->assertOk()
            ->assertSee('value="personal" checked', false);
    }

    public function test_regular_user_cannot_forge_global_visibility(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user, 'Рабочее дело');

        $this->actingAs($user)
            ->from(route('workspaces.sources.create', $workspace))
            ->post(route('workspaces.sources.store', $workspace), [
                'title' => 'Подложный глобальный НПА',
                'type' => 'law',
                'input_method' => 'url',
                'official_url' => 'https://example.test/law',
                'visibility' => 'global',
            ])
            ->assertSessionHasErrors('visibility');

        $this->assertDatabaseCount('sources', 0);
    }

    public function test_admin_global_docx_from_default_workspace_propagates_to_existing_and_future_workspaces(): void
    {
        $admin = User::factory()->admin()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $profile = $this->profile();
        $adminWorkspace = $this->workspace($admin, 'Административное рабочее дело', $profile);
        $firstWorkspace = $this->workspace($firstUser, 'Первое профильное дело', $profile);
        $secondWorkspace = $this->workspace($secondUser, 'Второе профильное дело', $profile);
        $unrelated = $this->workspace($firstUser, 'Другое дело');
        $personal = $this->source('Личный НПА пользователя', $firstUser);
        $firstWorkspace->sources()->attach($personal);

        $this->actingAs($admin)->post(route('workspaces.sources.store', $adminWorkspace), [
            'title' => 'Новый глобальный кодекс',
            'type' => 'code',
            'input_method' => 'docx',
            'docx_file' => $this->docxUpload(['Статья 1. Проверенная норма.']),
            'visibility' => 'global',
        ])->assertRedirect(route('workspaces.sources', $adminWorkspace));

        $source = Source::where('title', 'Новый глобальный кодекс')->sole();
        $version = SourceVersion::where('source_id', $source->id)->sole();
        $this->assertNull($source->user_id);
        $this->assertDatabaseHas('regulatory_profile_sources', ['regulatory_profile_id' => $profile->id, 'source_id' => $source->id]);

        foreach ([$adminWorkspace, $firstWorkspace, $secondWorkspace] as $workspace) {
            $this->assertDatabaseHas('workspace_sources', ['workspace_id' => $workspace->id, 'source_id' => $source->id]);
        }
        $this->assertDatabaseMissing('workspace_sources', ['workspace_id' => $unrelated->id, 'source_id' => $source->id]);
        $this->assertDatabaseHas('workspace_sources', ['workspace_id' => $firstWorkspace->id, 'source_id' => $personal->id]);

        $synchronizer = app(RegulatoryProfileWorkspaceSynchronizer::class);
        $synchronizer->sync($profile);
        $synchronizer->sync($profile);
        $this->assertSame(1, \DB::table('workspace_sources')->where('workspace_id', $firstWorkspace->id)->where('source_id', $source->id)->count());

        $futureUser = User::factory()->create();
        $futureWorkspace = app(DefaultWorkspaceProvisioner::class)->provision($futureUser);
        $this->assertDatabaseHas('workspace_sources', ['workspace_id' => $futureWorkspace->id, 'source_id' => $source->id]);
        $this->assertContains($version->id, app(WorkspaceSourceVersionResolver::class)->currentVersionIds($firstUser, $firstWorkspace)->all());
        Http::assertNothingSent();
    }

    public function test_global_source_from_ordinary_admin_workspace_is_not_added_to_default_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        $profile = $this->profile();
        $ordinary = $this->workspace($admin, 'Обычное дело');
        $profileWorkspace = $this->workspace($other, 'Профильное дело', $profile);

        $this->actingAs($admin)->post(route('workspaces.sources.store', $ordinary), [
            'title' => 'Глобальный НПА вне профиля',
            'type' => 'law',
            'input_method' => 'url',
            'official_url' => 'https://example.test/global-law',
            'visibility' => 'global',
        ])->assertRedirect();

        $source = Source::sole();
        $this->assertDatabaseHas('workspace_sources', ['workspace_id' => $ordinary->id, 'source_id' => $source->id]);
        $this->assertDatabaseMissing('regulatory_profile_sources', ['regulatory_profile_id' => $profile->id, 'source_id' => $source->id]);
        $this->assertDatabaseMissing('workspace_sources', ['workspace_id' => $profileWorkspace->id, 'source_id' => $source->id]);
        $this->assertDatabaseCount('source_versions', 0);
    }

    private function profile(): RegulatoryProfile
    {
        return RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
    }

    private function workspace(User $user, string $title, ?RegulatoryProfile $profile = null): Workspace
    {
        return Workspace::create([
            'user_id' => $user->id,
            'regulatory_profile_id' => $profile?->id,
            'reference_number' => 'WS-'.uniqid(),
            'title' => $title,
            'category' => 'other',
            'status' => 'draft',
        ]);
    }

    private function source(string $title, ?User $owner = null, string $type = 'law'): Source
    {
        $source = Source::create(['title' => $title, 'type' => $type, 'status' => 'active']);
        if ($owner) {
            $source->user()->associate($owner);
            $source->save();
        }

        return $source;
    }

    private function version(Source $source, string $name): SourceVersion
    {
        $text = 'Статья 1. Норма '.$source->title;

        return SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => $name,
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);
    }

    private function docxUpload(array $paragraphs): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aiddu-regbase-').'.docx';
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
}
