<?php

namespace App\Services;

use App\Models\Artifact;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use RuntimeException;

class DraftPackageDocxRenderer
{
    public function __construct(private readonly LegalDocumentFormatter $formatter) {}

    public function render(Artifact $sourceArtifact): string
    {
        if ($sourceArtifact->format !== 'structured_json' || ! is_array($sourceArtifact->content)) {
            throw new RuntimeException('DOCX можно сформировать только из canonical structured JSON Artifact.');
        }

        $phpWord = $this->document();
        match ($sourceArtifact->artifact_type) {
            'comparative_table' => $this->comparativeTable($phpWord, $sourceArtifact->content),
            'draft_npa' => $this->draftNpa($phpWord, $sourceArtifact->content),
            default => throw new RuntimeException('Этот тип Artifact не поддерживает DOCX export.'),
        };

        $path = tempnam(sys_get_temp_dir(), 'aiddu-docx-');
        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный DOCX-файл.');
        }

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return $path;
    }

    private function document(): PhpWord
    {
        Settings::setOutputEscapingEnabled(true);
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(12);
        $phpWord->getDocInfo()
            ->setCreator('AI DDU Assistant')
            ->setCompany('AI DDU Assistant')
            ->setTitle('Legal Draft Package')
            ->setCreated(0)
            ->setModified(0);

        $phpWord->addParagraphStyle('LegalBody', [
            'alignment' => 'both',
            'spaceAfter' => 120,
            'lineHeight' => 1.15,
        ]);
        $phpWord->addParagraphStyle('LegalCentered', [
            'alignment' => 'center',
            'spaceAfter' => 160,
            'lineHeight' => 1.15,
        ]);
        $phpWord->addParagraphStyle('LegalRight', [
            'alignment' => 'right',
            'spaceAfter' => 120,
        ]);
        $phpWord->addParagraphStyle('LegalCommand', [
            'alignment' => 'both',
            'spaceBefore' => 80,
            'spaceAfter' => 80,
            'lineHeight' => 1.15,
        ]);
        $phpWord->addParagraphStyle('LegalNorm', [
            'alignment' => 'both',
            'leftIndent' => 567,
            'spaceAfter' => 80,
            'lineHeight' => 1.15,
        ]);

        return $phpWord;
    }

    private function comparativeTable(PhpWord $phpWord, array $content): void
    {
        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => 16838,
            'pageSizeH' => 11906,
            'marginTop' => 567,
            'marginRight' => 567,
            'marginBottom' => 567,
            'marginLeft' => 567,
            'headerHeight' => 360,
            'footerHeight' => 360,
        ]);
        $section->addText((string) ($content['title'] ?? 'Сравнительная таблица'), [
            'name' => 'Times New Roman',
            'size' => 14,
            'bold' => true,
        ], 'LegalCentered');

        $phpWord->addTableStyle('ComparativeTable', [
            'borderSize' => 4,
            'borderColor' => '666666',
            'cellMarginTop' => 90,
            'cellMarginRight' => 100,
            'cellMarginBottom' => 90,
            'cellMarginLeft' => 100,
            'layout' => 'fixed',
        ]);
        $table = $section->addTable('ComparativeTable');
        $widths = [600, 2400, 3900, 4400, 4404];
        $columns = array_values($content['columns'] ?? []);
        if (count($columns) !== 5) {
            throw new RuntimeException('Canonical comparative table должна содержать пять колонок.');
        }

        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($columns as $index => $column) {
            $cell = $table->addCell($widths[$index], [
                'bgColor' => 'E7E6E6',
                'valign' => 'center',
            ]);
            $cell->addText((string) $column, ['bold' => true, 'size' => 9], [
                'alignment' => 'center',
                'spaceAfter' => 0,
            ]);
        }

        foreach ($content['rows'] ?? [] as $row) {
            $table->addRow(null, ['cantSplit' => true]);
            $cells = [
                [(string) ($row['number'] ?? ''), 'center'],
                [$this->compactCanonicalLocator((string) ($row['structural_element'] ?? '')), 'left'],
                [(string) ($row['current_text'] ?? ''), 'both'],
                [(string) ($row['proposed_text'] ?? ''), 'both'],
                [(string) ($row['justification'] ?? ''), 'both'],
            ];

            foreach ($cells as $index => [$text, $alignment]) {
                $cell = $table->addCell($widths[$index], ['valign' => 'top']);
                $blocks = $this->formatter->textBlocks($text);
                if ($blocks === []) {
                    $blocks = [''];
                }
                foreach ($blocks as $block) {
                    $cell->addText($block, ['size' => 9], [
                        'alignment' => $alignment,
                        'spaceAfter' => 80,
                        'lineHeight' => 1.05,
                    ]);
                }

                if ($index === 4 && ($row['warnings'] ?? []) !== []) {
                    $cell->addText('Юридические предупреждения:', [
                        'size' => 9,
                        'bold' => true,
                        'color' => '9C6500',
                    ], ['spaceBefore' => 80, 'spaceAfter' => 40]);
                    foreach ($row['warnings'] as $warning) {
                        $cell->addText((string) $warning, ['size' => 9, 'color' => '9C6500'], [
                            'leftIndent' => 180,
                            'spaceAfter' => 40,
                        ]);
                    }
                }
            }
        }

        $this->serviceBlock($section, [], $content['warnings'] ?? []);
    }

    private function draftNpa(PhpWord $phpWord, array $content): void
    {
        $section = $phpWord->addSection([
            'orientation' => 'portrait',
            'pageSizeW' => 11906,
            'pageSizeH' => 16838,
            'marginTop' => 1134,
            'marginRight' => 1021,
            'marginBottom' => 1134,
            'marginLeft' => 1021,
            'headerHeight' => 567,
            'footerHeight' => 567,
        ]);

        $section->addText((string) ($content['project_mark'] ?? 'Проект'), [
            'name' => 'Times New Roman',
            'size' => 12,
            'bold' => true,
        ], 'LegalRight');

        $actType = trim((string) ($content['act_type'] ?? ''));
        $title = $this->formatter->typographicHeading($content['title'] ?? null);
        if ($actType !== '') {
            $section->addText($actType, [
                'name' => 'Times New Roman',
                'size' => 14,
                'bold' => true,
                'allCaps' => true,
            ], 'LegalCentered');
        }
        if ($title !== null && $title !== '') {
            $section->addText($title, [
                'name' => 'Times New Roman',
                'size' => 14,
                'bold' => true,
            ], 'LegalCentered');
        }

        foreach ($content['articles'] ?? [] as $article) {
            $section->addText('Статья '.($article['number'] ?? '').'.', [
                'name' => 'Times New Roman',
                'size' => 12,
                'bold' => true,
            ], ['spaceBefore' => 240, 'spaceAfter' => 120]);
            if (filled($article['intro'] ?? null)) {
                $section->addText(
                    (string) $this->formatter->typographicHeading($article['intro']),
                    ['name' => 'Times New Roman', 'size' => 12],
                    'LegalBody',
                );
            }

            foreach ($article['commands'] ?? [] as $command) {
                $blocks = $this->formatter->commandBlocks((string) ($command['text'] ?? ''));
                foreach ($blocks as $index => $block) {
                    $style = $index === 0 ? 'LegalCommand' : 'LegalNorm';
                    $run = $section->addTextRun($style);
                    if ($index === 0) {
                        $run->addText((string) ($command['number'] ?? '').'. ', ['bold' => true]);
                    }
                    $run->addText((string) $block['text']);
                }
            }
        }

        $requirements = array_map(
            fn ($key) => $this->formatter->requirementLabel((string) $key),
            $content['requires_user_input'] ?? [],
        );
        $this->serviceBlock($section, $requirements, $content['warnings'] ?? []);
    }

    private function serviceBlock(object $section, array $requirements, array $warnings): void
    {
        $items = array_values(array_unique(array_merge($requirements, array_map('strval', $warnings))));
        if ($items === []) {
            return;
        }

        $section->addTextBreak(1);
        $section->addText('Требуется уточнить перед юридическим согласованием', [
            'name' => 'Times New Roman',
            'size' => 12,
            'bold' => true,
            'color' => '9C6500',
        ], ['spaceBefore' => 240, 'spaceAfter' => 120, 'keepNext' => true]);
        foreach ($items as $item) {
            $section->addText((string) $item, [
                'name' => 'Times New Roman',
                'size' => 10,
                'color' => '7F6000',
            ], ['leftIndent' => 360, 'spaceAfter' => 80]);
        }
    }

    private function compactCanonicalLocator(string $locator): string
    {
        $parts = preg_split('/\s*,\s*/u', trim($locator));
        if ($parts === false || count($parts) < 2) {
            return $locator;
        }
        foreach ($parts as $index => $part) {
            if (preg_match('/^(?:статья|бап)\s+/ui', $part) === 1) {
                return implode(', ', array_slice($parts, $index));
            }
        }

        return $locator;
    }
}
