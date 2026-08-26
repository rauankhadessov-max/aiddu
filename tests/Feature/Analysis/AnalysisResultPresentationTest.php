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
            'source_reference' => $source->title.', редакция Редакция из загруженного DOCX, статья 26, пункт 1 ['.$fragmentId.']',
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
        $analysis->findings()->create([
            'finding_type' => 'legal_risk',
            'severity' => 'high',
            'title' => 'Требуется уточнение нормы',
            'description' => 'Описание юридического риска.',
            'confidence_score' => 88,
        ]);

        $response = $this->actingAs($user)->get(route('analyses.show', $analysis));

        $response->assertOk()
            ->assertSee('Рекомендуемая редакция')
            ->assertSee('Правовое основание')
            ->assertSee($source->title)
            ->assertSee('статья 26, пункт 1')
            ->assertSee('Уверенность: 94%')
            ->assertSee('Высокий риск')
            ->assertDontSee('>high<', false)
            ->assertDontSee('Редакция Редакция')
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

    public function test_old_analysis_groups_trusted_citations_by_source_without_mutating_saved_data(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-GROUPED-REFERENCES',
            'title' => 'Долевое участие',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Изменение статьи 13',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'analysis_instruction' => 'Проверить свободу договора',
        ]);
        $targetSource = Source::create(['title' => 'Закон о долевом участии', 'type' => 'law', 'status' => 'active']);
        $targetVersion = SourceVersion::create([
            'source_id' => $targetSource->id,
            'version_name' => 'Редакция из официального DOCX',
            'text' => 'Статьи 12 и 13.',
            'hash' => hash('sha256', 'grouped-target'),
        ]);
        $supportingSource = Source::create(['title' => 'Гражданский кодекс Республики Казахстан', 'type' => 'code', 'status' => 'active']);
        $supportingVersion = SourceVersion::create([
            'source_id' => $supportingSource->id,
            'version_name' => 'Редакция из загруженного DOCX',
            'text' => 'Статьи 380 и 403.',
            'hash' => hash('sha256', 'grouped-supporting'),
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридический анализ статьи 13',
            'analysis_type' => 'amendment_review',
            'instruction' => $document->analysis_instruction,
            'status' => 'completed',
            'version' => 1,
            'summary' => 'Поправка требует уточнения.',
            'settings' => ['source_sufficiency' => 'sufficient', 'overall_assessment' => 'Допустима после доработки.'],
        ]);
        $analysis->sourceVersions()->attach([$targetVersion->id, $supportingVersion->id]);

        $citations = [
            $this->citation('sv'.$targetVersion->id.'-target', $targetSource, $targetVersion, '13', '1'),
            $this->citation('sv'.$targetVersion->id.'-law12', $targetSource, $targetVersion, '12', '1'),
            $this->citation('sv'.$targetVersion->id.'-law132', $targetSource, $targetVersion, '13', '2'),
            $this->citation('sv'.$targetVersion->id.'-law134', $targetSource, $targetVersion, '13', '4'),
            $this->citation('sv'.$supportingVersion->id.'-civil380', $supportingSource, $supportingVersion, '380', '1'),
            $this->citation('sv'.$supportingVersion->id.'-civil380a', $supportingSource, $supportingVersion, '380', '1-1'),
            $this->citation('sv'.$supportingVersion->id.'-civil403', $supportingSource, $supportingVersion, '403', '3'),
        ];
        $sourceReference = collect($citations)->map(function (array $citation) use ($targetSource, $targetVersion, $supportingSource, $supportingVersion) {
            $source = $citation['source_id'] === $targetSource->id ? $targetSource : $supportingSource;
            $version = $citation['source_version_id'] === $targetVersion->id ? $targetVersion : $supportingVersion;

            return $source->title.', '.$version->version_name.', статья '.$citation['article'].', пункт '.$citation['paragraph'].' ['.$citation['fragment_id'].']';
        })->implode('; ');

        $amendment = AnalysisAmendment::create([
            'analysis_id' => $analysis->id,
            'source_id' => $targetSource->id,
            'source_version_id' => $targetVersion->id,
            'target_mode' => 'existing',
            'structural_element_type' => 'paragraph',
            'article' => '13',
            'paragraph' => '1',
            'proposed_locator' => 'Статья 13, пункт 1',
            'amendment_type' => 'new_edition',
            'disposition' => 'revise',
            'current_text' => '1. Действующая норма.',
            'proposed_text' => '1. Рекомендуемая норма.',
            'justification' => 'Поправка обеспечивает правовую определённость.',
            'legal_basis' => 'Принцип свободы договора и порядок изменения договора.',
            'source_reference' => $sourceReference,
            'confidence_score' => 91,
            'warnings' => ['Требуется дополнительная сверка терминологии.'],
            'citations' => $citations,
            'target_snapshot' => ['source_id' => $targetSource->id, 'source_title' => $targetSource->title],
        ]);

        $package = $analysis->draftPackage()->create(['title' => 'Пакет документов', 'status' => 'generated']);
        $table = $package->artifacts()->create([
            'created_by' => $user->id,
            'artifact_type' => 'comparative_table',
            'format' => 'structured_json',
            'title' => 'Сравнительная таблица',
            'content' => ['rows' => []],
            'status' => 'generated',
        ]);
        $draft = $package->artifacts()->create([
            'created_by' => $user->id,
            'artifact_type' => 'draft_npa',
            'format' => 'structured_json',
            'title' => 'Проект НПА',
            'content' => ['commands' => []],
            'status' => 'generated',
        ]);
        $package->artifacts()->create([
            'source_artifact_id' => $table->id,
            'created_by' => $user->id,
            'artifact_type' => 'comparative_table_docx',
            'format' => 'docx',
            'title' => 'Техническое представление DOCX',
            'status' => 'generated',
        ]);

        $analysisSettingsBefore = $analysis->settings;
        $amendmentBefore = $amendment->only(['source_reference', 'citations', 'warnings', 'target_snapshot']);

        $response = $this->actingAs($user)->get(route('analyses.show', $analysis));

        $response->assertOk()
            ->assertSeeInOrder(['Итоговая оценка', 'Краткое резюме', 'Предлагаемые поправки', 'Выявленные замечания', 'Пакет документов'])
            ->assertSee('Источники (2)')
            ->assertSee('Закон о долевом участии — ст. 12 п. 1; ст. 13 п. 1, 2, 4')
            ->assertSee('Гражданский кодекс Республики Казахстан — ст. 380 п. 1, 1-1; ст. 403 п. 3')
            ->assertDontSee('Редакция из официального DOCX')
            ->assertDontSee('Редакция из загруженного DOCX')
            ->assertDontSee('sv'.$targetVersion->id.'-target')
            ->assertSee(route('artifacts.show', $table), false)
            ->assertSee(route('artifacts.show', $draft), false)
            ->assertSee(route('artifacts.docx.download', $table), false)
            ->assertSee(route('artifacts.docx.download', $draft), false)
            ->assertDontSee('Техническое представление DOCX');

        $this->assertSame(2, substr_count($response->getContent(), 'data-logical-document='));
        $this->assertSame($analysisSettingsBefore, $analysis->fresh()->settings);
        $this->assertSame($amendmentBefore, $amendment->fresh()->only(['source_reference', 'citations', 'warnings', 'target_snapshot']));
        Http::assertNothingSent();
    }

    private function citation(string $fragmentId, Source $source, SourceVersion $version, string $article, string $paragraph): array
    {
        return [
            'fragment_id' => $fragmentId,
            'quote' => 'Подтверждённая нормативная цитата.',
            'article' => $article,
            'paragraph' => $paragraph,
            'subparagraph' => null,
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'text_hash' => hash('sha256', $fragmentId),
            'purpose' => 'legal_basis',
        ];
    }
}
