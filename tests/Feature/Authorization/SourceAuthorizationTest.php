<?php

namespace Tests\Feature\Authorization;

use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_global_and_own_sources_but_not_foreign_personal_sources(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $global = $this->source('Глобальный НПА');
        $own = $this->source('Мой НПА', $user);
        $foreign = $this->source('Чужой НПА', $other);

        $this->actingAs($user)->get(route('sources.index'))
            ->assertOk()->assertSee($global->title)->assertSee($own->title)->assertDontSee($foreign->title);
        $this->actingAs($user)->get(route('sources.show', $global))->assertOk();
        $this->actingAs($user)->get(route('sources.show', $own))->assertOk();
        $this->actingAs($user)->get(route('sources.show', $foreign))->assertForbidden();
    }

    public function test_regular_user_can_manage_only_own_personal_source_and_versions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $global = $this->source('Глобальный НПА');
        $own = $this->source('Мой НПА', $user);
        $foreign = $this->source('Чужой НПА', $other);
        $ownVersion = $this->version($own);

        $this->actingAs($user)->get(route('sources.create'))->assertOk();
        $this->actingAs($user)->post(route('sources.store'), $this->sourcePayload())->assertRedirect();
        $created = Source::where('title', 'Новый нормативный акт')->sole();
        $this->assertSame($user->id, $created->user_id);

        $this->actingAs($user)->get(route('source-versions.create', $own))->assertOk();
        $this->actingAs($user)->post(route('source-versions.store', $own), $this->versionPayload())->assertRedirect();
        $this->actingAs($user)->put(route('source-versions.update', [$own, $ownVersion]), $this->versionPayload('Обновлённая редакция'))->assertRedirect();

        foreach ([$global, $foreign] as $forbidden) {
            $this->actingAs($user)->get(route('source-versions.create', $forbidden))->assertForbidden();
            $this->actingAs($user)->post(route('source-versions.store', $forbidden), $this->versionPayload())->assertForbidden();
        }
    }

    public function test_admin_manages_global_but_not_foreign_personal_source(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $global = $this->source('Глобальный НПА');
        $foreign = $this->source('Личный НПА пользователя', $owner);

        $this->actingAs($admin)->post(route('sources.store'), $this->sourcePayload())->assertRedirect();
        $this->assertNull(Source::where('title', 'Новый нормативный акт')->sole()->user_id);
        $this->actingAs($admin)->get(route('source-versions.create', $global))->assertOk();
        $this->actingAs($admin)->get(route('sources.show', $foreign))->assertForbidden();
        $this->actingAs($admin)->get(route('source-versions.create', $foreign))->assertForbidden();
    }

    public function test_foreign_personal_source_cannot_be_attached_to_workspace(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $workspace = $this->workspace($user);
        $foreign = $this->source('Чужой НПА', $other);

        $this->actingAs($user)->post(route('workspaces.sources.attach', [$workspace, $foreign]))->assertForbidden();
        $this->assertDatabaseMissing('workspace_sources', ['workspace_id' => $workspace->id, 'source_id' => $foreign->id]);
    }

    public function test_soft_deleting_personal_source_preserves_versions(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $source = $this->source('Удаляемый личный НПА', $user);
        $version = $this->version($source);
        $workspace->sources()->attach($source);

        $this->actingAs($user)->delete(route('sources.destroy', $source))->assertRedirect(route('sources.index'));
        $this->assertSoftDeleted('sources', ['id' => $source->id]);
        $this->assertDatabaseHas('source_versions', ['id' => $version->id]);
        $this->assertDatabaseMissing('workspace_sources', ['source_id' => $source->id]);
        $this->assertSame($source->id, $version->fresh()->source->id);
    }

    private function source(string $title, ?User $owner = null): Source
    {
        $source = Source::create(['title' => $title, 'type' => 'law', 'status' => 'active']);
        if ($owner) {
            $source->user()->associate($owner);
            $source->save();
        }
        return $source;
    }

    private function version(Source $source): SourceVersion
    {
        $text = 'Действующая редакция '.$source->title;
        return SourceVersion::create(['source_id' => $source->id, 'version_name' => 'Действующая редакция', 'text' => $text, 'hash' => hash('sha256', $text)]);
    }

    private function workspace(User $user): Workspace
    {
        return Workspace::create(['user_id' => $user->id, 'reference_number' => 'WS-'.uniqid(), 'title' => 'Рабочее дело', 'category' => 'other', 'status' => 'draft']);
    }

    private function sourcePayload(): array
    {
        return ['title' => 'Новый нормативный акт', 'type' => 'law', 'input_method' => 'url', 'official_url' => 'https://adilet.zan.kz/rus/docs/Z000000001'];
    }

    private function versionPayload(string $text = 'Дополнительная редакция'): array
    {
        return ['version_name' => 'Новая редакция', 'text' => $text];
    }
}
