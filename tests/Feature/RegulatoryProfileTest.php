<?php

namespace Tests\Feature;

use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DefaultWorkspaceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegulatoryProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_profile_exists_empty_and_admin_can_manage_only_global_sources(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $global = Source::create(['title' => 'Глобальный закон', 'type' => 'law', 'status' => 'active']);
        $personal = Source::create(['title' => 'Личный закон', 'type' => 'law', 'status' => 'active']);
        $personal->user()->associate($owner);
        $personal->save();
        $profile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();

        $this->assertCount(0, $profile->sources);
        $this->actingAs($admin)->get(route('regulatory-profiles.default.edit'))
            ->assertOk()->assertSee($global->title)->assertDontSee($personal->title);

        $this->actingAs($admin)->patch(route('regulatory-profiles.default.update'), [
            'source_ids' => [$global->id],
        ])->assertRedirect();
        $this->assertSame([$global->id], $profile->fresh()->sources->pluck('id')->all());

        $this->actingAs($admin)->from(route('regulatory-profiles.default.edit'))
            ->patch(route('regulatory-profiles.default.update'), ['source_ids' => [$personal->id]])
            ->assertSessionHasErrors('source_ids');
        $this->assertSame([$global->id], $profile->fresh()->sources->pluck('id')->all());
    }

    public function test_regular_user_cannot_manage_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('regulatory-profiles.default.edit'))->assertForbidden();
        $this->actingAs($user)->patch(route('regulatory-profiles.default.update'))->assertForbidden();
    }

    public function test_provisioner_creates_one_workspace_and_attaches_global_sources_idempotently(): void
    {
        $user = User::factory()->create();
        $profile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
        $first = Source::create(['title' => 'Первый глобальный НПА', 'type' => 'law', 'status' => 'active']);
        $second = Source::create(['title' => 'Второй глобальный НПА', 'type' => 'code', 'status' => 'active']);
        $profile->sources()->attach([
            $first->id => ['sort_order' => 0, 'is_primary' => true],
            $second->id => ['sort_order' => 1, 'is_primary' => false],
        ]);

        $provisioner = app(DefaultWorkspaceProvisioner::class);
        $workspace = $provisioner->provision($user);
        $again = $provisioner->provision($user);

        $this->assertSame($workspace->id, $again->id);
        $this->assertSame($profile->id, $workspace->regulatory_profile_id);
        $this->assertSame('Закон Республики Казахстан «О долевом участии в жилищном строительстве»', $workspace->title);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('workspace_sources', 2);
        $this->assertDatabaseCount('sources', 2);
    }

    public function test_existing_matching_workspace_can_be_adopted_without_duplicate(): void
    {
        $user = User::factory()->create();
        $profile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-EXISTING',
            'title' => $profile->workspace_title,
            'category' => 'other',
            'status' => 'draft',
        ]);

        $result = app(DefaultWorkspaceProvisioner::class)->provision($user, $workspace);

        $this->assertSame($workspace->id, $result->id);
        $this->assertSame($profile->id, $result->regulatory_profile_id);
        $this->assertDatabaseCount('workspaces', 1);
    }

    public function test_existing_users_command_is_dry_run_by_default(): void
    {
        $user = User::factory()->create();

        $this->artisan('regulatory-profile:provision-existing', ['--user' => [$user->id]])
            ->expectsOutputToContain('Dry-run only')
            ->assertSuccessful();

        $this->assertDatabaseCount('workspaces', 0);
    }
}
