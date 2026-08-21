<?php

namespace Tests\Feature\Authorization;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_view_document(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        $this->actingAs($owner)
            ->get(route('documents.create', $workspace))
            ->assertOk();

        $response = $this->actingAs($owner)
            ->post(route('documents.store', $workspace), $this->documentPayload());

        $document = Document::sole();

        $response->assertRedirect(route('documents.show', $document));

        $this->actingAs($owner)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_user_cannot_create_or_view_document_in_foreign_workspace(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $document = $this->documentFor($owner, $workspace);

        $this->actingAs($otherUser)
            ->get(route('documents.create', $workspace))
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->post(route('documents.store', $workspace), $this->documentPayload())
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->get(route('documents.show', $document))
            ->assertForbidden();

        $this->assertDatabaseCount('documents', 1);
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
            'title' => 'Поправка',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'proposed_text' => 'Предлагаемая редакция',
            'analysis_instruction' => 'Провести юридический анализ',
        ]);
    }

    private function documentPayload(): array
    {
        return [
            'title' => 'Поправка',
            'document_type' => 'legal_norm',
            'language' => 'ru',
            'current_text' => 'Действующая редакция',
            'proposed_text' => 'Предлагаемая редакция',
            'analysis_instruction' => 'Провести юридический анализ',
        ];
    }
}
