<?php

namespace Tests\Feature\Authorization;

use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_workspace_and_manage_its_sources(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $source = Source::create([
            'title' => 'Закон Республики Казахстан',
            'type' => 'law',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->get(route('workspaces.show', $workspace))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('workspaces.sources', $workspace))
            ->assertOk();

        $this->actingAs($owner)
            ->post(route('workspaces.sources.attach', [$workspace, $source]))
            ->assertRedirect(route('workspaces.show', $workspace));

        $this->assertDatabaseHas('workspace_sources', [
            'workspace_id' => $workspace->id,
            'source_id' => $source->id,
        ]);
    }

    public function test_user_cannot_view_or_attach_sources_to_foreign_workspace(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $source = Source::create([
            'title' => 'Закон Республики Казахстан',
            'type' => 'law',
            'status' => 'active',
        ]);

        $this->actingAs($otherUser)
            ->get(route('workspaces.show', $workspace))
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->get(route('workspaces.sources', $workspace))
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->post(route('workspaces.sources.attach', [$workspace, $source]))
            ->assertForbidden();

        $this->assertDatabaseMissing('workspace_sources', [
            'workspace_id' => $workspace->id,
            'source_id' => $source->id,
        ]);
    }

    private function workspaceFor(User $user): Workspace
    {
        return Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-'.$user->id,
            'title' => 'Рабочее дело пользователя '.$user->id,
            'category' => 'other',
            'status' => 'draft',
        ]);
    }
}
