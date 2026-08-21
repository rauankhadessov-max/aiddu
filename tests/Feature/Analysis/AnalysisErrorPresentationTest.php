<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AnalysisErrorPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_failure_does_not_expose_internal_exception_to_user(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $owner->id,
            'reference_number' => 'WS-ERROR',
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'title' => 'Проект поправки',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'proposed_text' => 'Предлагаемая редакция',
            'analysis_instruction' => 'Провести юридический анализ',
        ]);
        $source = Source::create([
            'title' => 'Закон',
            'type' => 'law',
            'status' => 'active',
        ]);
        $sourceVersion = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция',
            'text' => 'Текст источника',
            'hash' => hash('sha256', 'analysis-error-source-version'),
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'title' => 'Юридический анализ: '.$document->title,
            'analysis_type' => 'comprehensive',
            'instruction' => $document->analysis_instruction,
            'status' => 'draft',
            'version' => 1,
        ]);
        $analysis->sourceVersions()->attach($sourceVersion->id, ['role' => 'reference']);

        $technicalMessage = 'SECRET upstream stack detail';

        $this->mock(LegalAnalysisService::class)
            ->shouldReceive('run')
            ->once()
            ->andThrow(new RuntimeException($technicalMessage));

        $response = $this->actingAs($owner)
            ->post(route('analyses.run', $analysis));

        $response
            ->assertRedirect(route('analyses.show', $analysis))
            ->assertSessionHas(
                'error',
                'Не удалось выполнить анализ. Попробуйте повторить позже или обратитесь к администратору.',
            );

        $this->assertDatabaseHas('analyses', [
            'id' => $analysis->id,
            'status' => 'failed',
            'error_message' => $technicalMessage,
        ]);
    }
}
