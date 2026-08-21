<?php

namespace Tests\Unit\Services;

use App\Models\Source;
use App\Models\SourceVersion;
use App\Services\LegalStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalStructureServiceTest extends TestCase
{
    use RefreshDatabase;

    public static function structures(): array
    {
        return [
            'code' => [
                "Раздел II. ОБЯЗАТЕЛЬСТВА\nГлава 4. Общие положения\nСтатья 12. Форма сделки\n1. Сделка совершается письменно.\n2. Требования применяются ко всем сторонам.",
                ['section' => 'II', 'chapter' => '4', 'article' => '12', 'paragraph' => '1'],
            ],
            'law' => [
                "Статья 7. Полномочия органа\n1. Орган осуществляет контроль.\n1) проводит проверку документов;\n2) выдаёт обязательное предписание.\nАбзац 2. Дополнительное требование применяется при проверке.\n2. Решение оформляется письменно.",
                ['article' => '7', 'paragraph' => '1', 'subparagraph' => '1', 'textParagraph' => '2'],
            ],
            'order rules' => [
                "Правила предоставления государственной услуги\n1. Настоящие Правила определяют порядок оказания услуги.\n2. Услуга оказывается уполномоченным органом.\n3. Результат направляется заявителю.",
                ['article' => null, 'paragraph' => '2'],
            ],
            'appendix' => [
                "Приложение 2 к постановлению\nФорма отчёта\n1. Отчёт содержит сведения о заявителе.\n2. Отчёт подписывается руководителем.",
                ['appendix' => '2', 'paragraph' => '1'],
            ],
        ];
    }

    public function test_parser_supports_multiple_regulatory_structures(): void
    {
        foreach (self::structures() as $name => [$text, $expected]) {
            $fragments = app(LegalStructureService::class)->fragments($this->version($text));

            $this->assertNotEmpty($fragments, "Expected fragments for {$name}.");

            foreach ($expected as $field => $value) {
                $this->assertNotNull(collect($fragments)->first(
                    fn ($fragment) => $fragment->{$field} === $value,
                ), "Expected {$field}={$value} in {$name} fragments.");
            }

            foreach ($fragments as $fragment) {
                $this->assertSame(
                    $fragment->text,
                    mb_substr($text, $fragment->startOffset, $fragment->endOffset - $fragment->startOffset),
                );
            }
        }
    }

    public function test_unreliable_single_numbered_line_does_not_invent_paragraph_metadata(): void
    {
        $text = "Пояснительная информация\n1. Единственный элемент обычного списка без структуры НПА.";
        $fragments = app(LegalStructureService::class)->fragments($this->version($text));

        $this->assertNotEmpty($fragments);
        $this->assertSame([], array_values(array_filter(array_column(
            array_map(fn ($fragment) => $fragment->toArray(), $fragments),
            'paragraph',
        ))));
    }

    private function version(string $text): SourceVersion
    {
        $source = Source::create([
            'title' => 'Условный нормативный правовой акт',
            'type' => 'law',
            'status' => 'active',
        ]);

        return SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Тестовая редакция',
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);
    }
}
