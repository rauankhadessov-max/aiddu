<?php

namespace Tests\Unit\Services;

use App\Services\LegalDocumentFormatter;
use Tests\TestCase;

class LegalDocumentFormatterTest extends TestCase
{
    private LegalDocumentFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = app(LegalDocumentFormatter::class);
    }

    public function test_requirement_keys_are_mapped_without_leaking_unknown_keys(): void
    {
        $this->assertSame('Необходимо определить порядок и срок введения НПА в действие', $this->formatter->requirementLabel('effective_date_rule'));
        $this->assertSame('Необходимо указать НПА, которым утверждён изменяемый документ', $this->formatter->requirementLabel('approving_act'));
        $this->assertSame('Необходимо указать государственный орган, принимающий НПА', $this->formatter->requirementLabel('adopting_authority'));
        $this->assertSame('Необходимо определить вид принимаемого НПА', $this->formatter->requirementLabel('adopting_act_type'));
        $this->assertSame('Необходимо уточнить дополнительные данные для завершения проекта НПА', $this->formatter->requirementLabel('private_machine_key'));
        $this->assertStringNotContainsString('private_machine_key', $this->formatter->requirementLabel('private_machine_key'));
    }

    public function test_compact_locators_are_universal_and_omit_unneeded_hierarchy(): void
    {
        $this->assertSame('статья 30-1', $this->formatter->compactLocator([
            'type' => 'article',
            'locators' => ['section' => 'II', 'chapter' => '6', 'article' => '30-1'],
        ], 'fallback'));
        $this->assertSame('статья 26, пункт 1, подпункт 7-2)', $this->formatter->compactLocator([
            'type' => 'subparagraph',
            'locators' => ['chapter' => '6', 'article' => '26', 'paragraph' => '1', 'subparagraph' => '7-2'],
        ], 'fallback'));
        $this->assertSame('статья 100, пункт 3', $this->formatter->compactLocator([
            'type' => 'paragraph',
            'locators' => ['chapter' => '10', 'article' => '100', 'paragraph' => '3'],
        ], 'fallback'));
        $this->assertSame('глава 4, пункт 12', $this->formatter->compactLocator([
            'type' => 'paragraph',
            'locators' => ['chapter' => '4', 'paragraph' => '12'],
        ], 'fallback'));
        $this->assertSame('приложение 2', $this->formatter->compactLocator([
            'type' => 'appendix',
            'locators' => ['appendix' => '2'],
        ], 'fallback'));
    }

    public function test_existing_line_breaks_become_visual_blocks_without_rewriting_text(): void
    {
        $text = "Статья 30-1. Заголовок\n\n1. Первый пункт.\n2. Второй пункт.\n3. Третий пункт.";
        $blocks = $this->formatter->textBlocks($text);

        $this->assertSame([
            'Статья 30-1. Заголовок',
            '1. Первый пункт.',
            '2. Второй пункт.',
            '3. Третий пункт.',
        ], $blocks);
        $this->assertSame(str_replace("\n\n", "\n", $text), implode("\n", $blocks));
    }

    public function test_command_is_split_only_at_existing_line_breaks_and_ambiguous_text_falls_back(): void
    {
        $text = "В статье 26:\n\nпункт 1 дополнить подпунктом 7-2) следующего содержания:\n\n«7-2) исходная формулировка;»;";
        $blocks = $this->formatter->commandBlocks($text);

        $this->assertCount(3, $blocks);
        $this->assertSame('instruction', $blocks[0]['kind']);
        $this->assertSame('normative', $blocks[1]['kind']);
        $this->assertSame('quotation_start', $blocks[2]['kind']);
        $this->assertSame('Неразмеченный исходный текст', $this->formatter->commandBlocks('Неразмеченный исходный текст')[0]['text']);
    }

    public function test_only_heading_quotes_are_typographically_presented(): void
    {
        $heading = 'О внесении изменений в Закон "О жилищных отношениях"';
        $this->assertSame('О внесении изменений в Закон «О жилищных отношениях»', $this->formatter->typographicHeading($heading));
    }

    public function test_print_styles_cover_landscape_portrait_and_navigation_hiding(): void
    {
        $css = file_get_contents(resource_path('views/components/layouts/app.blade.php'));
        $table = file_get_contents(resource_path('views/artifacts/comparative-table.blade.php'));
        $draft = file_get_contents(resource_path('views/artifacts/draft-npa.blade.php'));

        $this->assertStringContainsString('@media print', $css);
        $this->assertStringContainsString('.app-sidebar', $css);
        $this->assertStringContainsString('table-header-group', $css);
        $this->assertStringContainsString('A4 landscape', $table);
        $this->assertStringContainsString('A4 portrait', $draft);
    }
}
