<?php

namespace Tests\Feature\Analysis;

use App\Models\Analysis;
use App\Models\AnalysisAmendment;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalysisResultPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_id_6_like_result_hides_machine_values_and_keeps_readable_trusted_reference(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-ID6-LIKE',
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Реструктуризация задолженности',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'analysis_instruction' => 'Проверить поправки',
        ]);
        $source = Source::create([
            'title' => 'Закон Республики Казахстан «О тестовом регулировании»',
            'type' => 'law',
            'status' => 'active',
        ]);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Действующая редакция',
            'text' => 'Статья 26. Тестовая норма.',
            'hash' => hash('sha256', 'id-6-like-version'),
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридический анализ: ID 6-like',
            'analysis_type' => 'amendment_review',
            'instruction' => 'Проверить поправки',
            'status' => 'completed',
            'version' => 1,
            'summary' => 'Результат подготовлен.',
            'settings' => [
                'source_sufficiency' => 'partial',
                'overall_assessment' => 'Поправка может быть доработана.',
                'warnings' => ['existing_target_not_found: article 30-1'],
            ],
        ]);
        $fragmentId = 'sv'.$version->id.'-abc123def456';
        AnalysisAmendment::create([
            'analysis_id' => $analysis->id,
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'target_mode' => 'new',
            'structural_element_type' => 'subparagraph',
            'article' => '26',
            'paragraph' => '1',
            'subparagraph' => '7-2',
            'proposed_locator' => 'Статья 26, пункт 1, подпункт 7-2)',
            'amendment_type' => 'add_element',
            'disposition' => 'revise',
            'current_text' => '7-2) отсутствует.',
            'proposed_text' => '7-2) осуществлять предусмотренное законом полномочие;',
            'justification' => 'Поправка уточняет компетенцию.',
            'legal_basis' => 'Статья 26 определяет полномочия оператора.',
            'source_reference' => $source->title.', редакция Действующая редакция, статья 26, пункт 1 ['.$fragmentId.']',
            'confidence_score' => 94,
            'warnings' => ['structural_anchor_not_found: article 30-1'],
            'citations' => [[
                'fragment_id' => $fragmentId,
                'quote' => 'Статья 26. Тестовая норма.',
            ]],
            'target_snapshot' => [
                'source_title' => $source->title,
                'version_name' => $version->version_name,
            ],
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('analyses.show', $analysis));

        $response->assertOk()
            ->assertSee('Рекомендуемая редакция')
            ->assertSee('Правовое основание')
            ->assertSee($source->title)
            ->assertSee('статья 26, пункт 1')
            ->assertSee('Уверенность: 94%')
            ->assertDontSee('add_element')
            ->assertDontSee('revise')
            ->assertDontSee('keep_as_proposed')
            ->assertDontSee('existing_target_not_found')
            ->assertDontSee('structural_anchor_not_found')
            ->assertSee('Изменяемый структурный элемент не найден')
            ->assertSee('Не удалось подтвердить соседние структурные элементы')
            ->assertDontSee('['.$fragmentId.']');
        Http::assertNothingSent();
    }

    public function test_target_source_ambiguity_is_presented_once_in_russian_without_machine_warning(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-AMBIGUITY',
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Поправка к статье 13',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'analysis_instruction' => 'Проверить статью 13',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Неоднозначная статья 13',
            'analysis_type' => 'amendment_review',
            'instruction' => $document->analysis_instruction,
            'status' => 'completed',
            'version' => 1,
            'summary' => 'Требуется уточнение.',
            'settings' => [
                'source_sufficiency' => 'insufficient',
                'overall_assessment' => 'Целевой НПА не определён.',
                'warnings' => [
                    'ambiguous_target_source',
                    'article 13 matches multiple selected SourceVersions or scopes',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('analyses.show', $analysis));
        $message = 'Статья 13 найдена в нескольких подключённых НПА. Не удалось однозначно определить изменяемый нормативный акт. Уточните полное название целевого НПА.';

        $response->assertOk()
            ->assertSee($message)
            ->assertDontSee('article 13 matches multiple selected SourceVersions or scopes')
            ->assertDontSee('ambiguous_target_source');
        $this->assertSame(1, substr_count($response->getContent(), $message));
        Http::assertNothingSent();
    }
}
