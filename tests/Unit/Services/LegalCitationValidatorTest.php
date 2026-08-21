<?php

namespace Tests\Unit\Services;

use App\Data\LegalContextFragment;
use App\Data\LegalRetrievalResult;
use App\Models\Analysis;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegalCitationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalCitationValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_citation_accepts_unicode_whitespace_and_builds_trusted_reference(): void
    {
        [$analysis, $context, $fragment] = $this->fixture(article: '12', paragraph: '3');
        $finding = $this->finding([
            'fragment_id' => $fragment->fragmentId,
            'quote' => "Договор участия   заключается\nв письменной форме.",
            'article' => 'статья 12',
            'paragraph' => 'пункт 3',
            'subparagraph' => null,
        ]);

        $result = app(LegalCitationValidator::class)->validate([$finding], $context, $analysis);

        $this->assertCount(1, $result->acceptedFindings);
        $this->assertSame([], $result->rejectedFindings);
        $this->assertStringContainsString('Закон о долевом участии', $result->acceptedFindings[0]['source_reference']);
        $this->assertStringContainsString('статья 12', $result->acceptedFindings[0]['source_reference']);
        $this->assertStringNotContainsString('Ссылка, придуманная моделью', $result->acceptedFindings[0]['source_reference']);
    }

    public function test_unknown_fragment_and_fabricated_quote_are_rejected(): void
    {
        [$analysis, $context, $fragment] = $this->fixture();

        $unknown = $this->finding([
            'fragment_id' => 'sv999-unknown',
            'quote' => 'Несуществующая цитата',
            'article' => null,
            'paragraph' => null,
            'subparagraph' => null,
        ]);
        $fabricated = $this->finding([
            'fragment_id' => $fragment->fragmentId,
            'quote' => 'Этой формулировки в законе нет.',
            'article' => null,
            'paragraph' => null,
            'subparagraph' => null,
        ]);

        $result = app(LegalCitationValidator::class)->validate(
            [$unknown, $fabricated],
            $context,
            $analysis,
        );

        $this->assertSame([], $result->acceptedFindings);
        $this->assertSame('unknown_fragment', $result->rejectedFindings[0]['reasons'][0]['code']);
        $this->assertSame('quote_not_found', $result->rejectedFindings[1]['reasons'][0]['code']);
    }

    public function test_locator_mismatch_is_checked_only_when_trusted_metadata_exists(): void
    {
        [$analysis, $context, $fragment] = $this->fixture(article: '12');
        $mismatch = $this->finding($this->citation($fragment, article: '13'));

        $rejected = app(LegalCitationValidator::class)->validate([$mismatch], $context, $analysis);

        $this->assertSame('article_mismatch', $rejected->rejectedFindings[0]['reasons'][0]['code']);

        [$analysisWithoutLocator, $contextWithoutLocator, $fragmentWithoutLocator] = $this->fixture(
            article: null,
            sourceTitle: 'Другой закон',
        );
        $unstructured = $this->finding($this->citation($fragmentWithoutLocator, article: '99'));

        $accepted = app(LegalCitationValidator::class)->validate(
            [$unstructured],
            $contextWithoutLocator,
            $analysisWithoutLocator,
        );

        $this->assertCount(1, $accepted->acceptedFindings);
    }

    public function test_fragment_from_source_version_not_attached_to_analysis_is_rejected(): void
    {
        [$analysis, $context, $fragment, $version] = $this->fixture();
        $analysis->sourceVersions()->detach($version->id);

        $result = app(LegalCitationValidator::class)->validate(
            [$this->finding($this->citation($fragment))],
            $context,
            $analysis->fresh(),
        );

        $this->assertSame(
            'source_version_not_attached',
            $result->rejectedFindings[0]['reasons'][0]['code'],
        );
    }

    private function fixture(
        ?string $article = '12',
        ?string $paragraph = null,
        string $sourceTitle = 'Закон о долевом участии',
    ): array {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'WS-'.uniqid(),
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Документ',
            'document_type' => 'legal_norm',
            'input_type' => 'text',
            'language' => 'ru',
            'status' => 'ready',
            'proposed_text' => 'Текст',
            'analysis_instruction' => 'Проверить документ',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Анализ',
            'analysis_type' => 'comprehensive',
            'instruction' => 'Проверить документ',
            'status' => 'draft',
            'version' => 1,
        ]);
        $source = Source::create([
            'title' => $sourceTitle,
            'type' => 'law',
            'status' => 'active',
        ]);
        $text = "Договор участия\u{00A0}заключается в письменной форме.";
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);
        $analysis->sourceVersions()->attach($version->id, ['role' => 'reference']);
        $fragment = new LegalContextFragment(
            fragmentId: 'sv'.$version->id.'-abc123def456',
            sourceId: $source->id,
            sourceVersionId: $version->id,
            sourceTitle: $source->title,
            versionName: $version->version_name,
            article: $article,
            paragraph: $paragraph,
            subparagraph: null,
            startOffset: 0,
            endOffset: mb_strlen($text),
            textHash: hash('sha256', $text),
            text: $text,
            score: 10,
        );
        $context = new LegalRetrievalResult(
            fragments: [$fragment],
            sourceSnapshots: [],
            queryHash: hash('sha256', 'query'),
            contextHash: hash('sha256', $text),
            retrievalVersion: 'test-v2',
            totalChars: mb_strlen($text),
        );

        return [$analysis, $context, $fragment, $version];
    }

    private function citation(
        LegalContextFragment $fragment,
        ?string $article = null,
    ): array {
        return [
            'fragment_id' => $fragment->fragmentId,
            'quote' => 'Договор участия заключается в письменной форме.',
            'article' => $article,
            'paragraph' => null,
            'subparagraph' => null,
        ];
    }

    private function finding(array $citation): array
    {
        return [
            'finding_type' => 'compliance',
            'severity' => 'medium',
            'title' => 'Требование к форме договора',
            'description' => 'Описание',
            'document_fragment' => 'Фрагмент',
            'document_location' => 'Пункт 1',
            'source_reference' => 'Ссылка, придуманная моделью',
            'legal_basis' => 'Правовое основание',
            'recommendation' => 'Рекомендация',
            'recommended_text' => 'Текст',
            'justification' => 'Обоснование',
            'confidence_score' => 90,
            'citations' => [$citation],
        ];
    }
}
