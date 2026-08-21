<?php

namespace Tests\Unit\Services;

use App\Services\AmendmentCommandCompiler;
use App\Services\ComparativeTableBuilder;
use App\Services\DraftPackageInputBuilder;
use App\Services\NpaDraftBuilder;
use App\Services\NpaTypeResolver;
use Tests\TestCase;

class DraftPackageDomainTest extends TestCase
{
    public function test_id6_like_amendments_build_two_table_rows_and_law_commands(): void
    {
        $input = $this->input();
        $justifications = [
            1 => ['text' => 'В целях наделения Единого оператора полномочием по реструктуризации.', 'warnings' => []],
            2 => ['text' => 'В целях определения условий и порядка реструктуризации задолженности.', 'warnings' => []],
        ];

        $table = app(ComparativeTableBuilder::class)->build($input, $justifications);
        $draft = app(NpaDraftBuilder::class)->build($input);

        $this->assertCount(2, $table['rows']);
        $this->assertSame('Отсутствует', $table['rows'][0]['current_text']);
        $this->assertSame('статья 26, пункт 1, подпункт 7-2', $table['rows'][0]['structural_element']);
        $this->assertSame('Закон Республики Казахстан', $draft['act_type']);
        $this->assertStringContainsString('пункт 1 дополнить подпунктом 7-2)', $draft['commands'][0]['text']);
        $this->assertStringContainsString('Дополнить статьей 30-1', $draft['commands'][1]['text']);
        $this->assertSame(['effective_date_rule'], $draft['requires_user_input']);
    }

    public function test_target_and_adopting_act_are_distinct_and_rules_require_approving_act(): void
    {
        $profile = app(NpaTypeResolver::class)->resolve([
            'source_id' => 9,
            'type' => 'rules',
            'title' => 'Правила проведения проверки',
            'issuing_authority' => null,
        ]);

        $this->assertSame('Правила проведения проверки', $profile['target_npa']['title']);
        $this->assertNull($profile['adopting_act']['type']);
        $this->assertSame('requires_user_input', $profile['adopting_act']['status']);
        $this->assertContains('approving_act', $profile['requires_user_input']);
    }

    public function test_code_is_amended_by_law_and_order_by_order(): void
    {
        $resolver = app(NpaTypeResolver::class);
        $code = $resolver->resolve(['type' => 'code', 'title' => 'Налоговый кодекс']);
        $order = $resolver->resolve(['type' => 'order', 'title' => 'Приказ', 'issuing_authority' => 'Министра финансов Республики Казахстан']);

        $this->assertSame('law', $code['adopting_act']['type']);
        $this->assertSame('order', $order['adopting_act']['type']);
        $this->assertSame('Приказ Министра финансов Республики Казахстан', $order['adopting_act']['title']);
    }

    public function test_operation_compiler_supports_new_edition_delete_and_safe_text_amendment(): void
    {
        $compiler = app(AmendmentCommandCompiler::class);
        $base = $this->input()['amendment_snapshots'][1];

        $edition = $compiler->compile([...$base, 'operation' => 'new_edition']);
        $delete = $compiler->compile([...$base, 'operation' => 'delete_element']);
        $text = $compiler->compile([...$base, 'operation' => 'amend_text']);

        $this->assertStringContainsString('изложить в следующей редакции', $edition['text']);
        $this->assertStringContainsString('исключить', $delete['text']);
        $this->assertNotEmpty($text['warnings']);
    }

    public function test_input_hash_is_stable_for_associative_key_order(): void
    {
        $builder = app(DraftPackageInputBuilder::class);
        $first = ['b' => 2, 'a' => ['d' => 4, 'c' => 3]];
        $second = ['a' => ['c' => 3, 'd' => 4], 'b' => 2];
        $this->assertSame($builder->hashPayload($first), $builder->hashPayload($second));
    }

    private function input(): array
    {
        return [
            'npa_profile' => app(NpaTypeResolver::class)->resolve([
                'source_id' => 1,
                'type' => 'law',
                'title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»',
                'number' => '249-II',
                'issuing_authority' => 'Парламент Республики Казахстан',
            ]),
            'amendment_snapshots' => [
                [
                    'amendment_id' => 1,
                    'target_mode' => 'new',
                    'target' => [
                        'type' => 'subparagraph',
                        'locators' => ['article' => '26', 'paragraph' => '1', 'subparagraph' => '7-2'],
                        'display' => 'статья 26, пункт 1, подпункт 7-2',
                    ],
                    'operation' => 'add_element',
                    'current_text' => null,
                    'proposed_text' => '7-2) осуществлять реструктуризацию задолженности в соответствии со статьей 30-1 настоящего Закона;',
                    'warnings' => [],
                ],
                [
                    'amendment_id' => 2,
                    'target_mode' => 'new',
                    'target' => [
                        'type' => 'article',
                        'locators' => ['article' => '30-1'],
                        'display' => 'статья 30-1',
                    ],
                    'operation' => 'add_element',
                    'current_text' => null,
                    'proposed_text' => "Статья 30-1. Реструктуризация задолженности\n\n1. Условия реструктуризации.\n2. Способы реструктуризации.\n3. Порядок определяется внутренними документами.",
                    'warnings' => [],
                ],
            ],
        ];
    }
}
