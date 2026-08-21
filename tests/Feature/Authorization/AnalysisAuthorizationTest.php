<?php

namespace Tests\Feature\Authorization;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_analysis_creation_and_view_analysis(): void
    {
        [$owner, $workspace, $document, $analysis] = $this->analysisFixture();

        $this->actingAs($owner)
            ->get(route('analyses.create', $document))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('analyses.show', $analysis))
            ->assertOk();
    }

    public function test_user_cannot_create_view_or_run_foreign_analysis(): void
    {
        [$owner, $workspace, $document, $analysis] = $this->analysisFixture();
        $otherUser = User::factory()->create();

        $this->mock(LegalAnalysisService::class)
            ->shouldNotReceive('run');

        $this->actingAs($otherUser)
            ->get(route('analyses.create', $document))
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->post(route('analyses.store', $document), [
                'title' => 'Чужой анализ',
                'analysis_type' => 'comprehensive',
                'instruction' => 'Проверить документ',
            ])
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->get(route('analyses.show', $analysis))
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->post(route('analyses.run', $analysis))
            ->assertForbidden();

        $this->assertDatabaseCount('analyses', 1);
        $this->assertDatabaseHas('analyses', [
            'id' => $analysis->id,
            'status' => 'draft',
        ]);
    }

    private function analysisFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $owner->id,
            'reference_number' => 'WS-'.$owner->id,
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'title' => 'Поправка',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'proposed_text' => 'Предлагаемая редакция',
            'analysis_instruction' => 'Провести юридический анализ',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'title' => 'Юридический анализ',
            'analysis_type' => 'comprehensive',
            'instruction' => 'Проверить документ',
            'status' => 'draft',
            'version' => 1,
        ]);

        return [$owner, $workspace, $document, $analysis];
    }
}
