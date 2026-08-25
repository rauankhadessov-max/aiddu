<?php

namespace Tests\Feature\DraftPackage;

use App\Models\Analysis;
use App\Models\Artifact;
use App\Models\Document;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ArtifactDocxService;
use App\Services\DraftPackageInputBuilder;
use App\Services\DraftPackagePresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ArtifactDocxExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('filesystems.disks.local.root', sys_get_temp_dir().'/aiddu-docx-tests-'.uniqid());
        app('filesystem')->forgetDisk('local');
        Http::fake();
    }

    public function test_owner_downloads_reusable_comparative_table_docx_from_canonical_json(): void
    {
        [$user, $analysis, $table] = $this->fixture();
        $canonical = $table->content;
        $analysisTimestamp = $analysis->getRawOriginal('updated_at');
        $amendmentTimestamps = $analysis->amendments()->get()->mapWithKeys(
            fn ($amendment) => [$amendment->id => $amendment->getRawOriginal('updated_at')],
        )->all();

        $first = $this->actingAs($user)->post(route('artifacts.docx.download', $table));
        $first->assertOk()
            ->assertHeader('content-type', ArtifactDocxService::MIME_TYPE)
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'comparative-table-package-',
            (string) $first->headers->get('content-disposition'),
        );
        $this->assertStringNotContainsString('unsafe', (string) $first->headers->get('content-disposition'));
        $this->assertStringNotContainsString('..', (string) $first->headers->get('content-disposition'));

        $representation = Artifact::where('source_artifact_id', $table->id)->sole();
        $this->assertSame('comparative_table_docx', $representation->artifact_type);
        $this->assertSame('docx', $representation->format);
        $this->assertSame('legal-docx-v4', $representation->renderer_version);
        $this->assertSame('comparative-table-package-'.$table->draft_package_id.'.docx', $representation->filename);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $representation->source_content_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $representation->logical_content_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $representation->binary_sha256);
        $this->assertFalse(str_contains($representation->storage_path, '..'));
        $draft = $table->draftPackage->artifacts()->where('artifact_type', 'draft_npa')->sole();
        $manifest = $representation->content['render_manifest'];
        $this->assertSame(
            app(DraftPackageInputBuilder::class)->hashPayload($canonical),
            $manifest['canonical_comparative_table_hash'],
        );
        $this->assertSame(
            app(DraftPackageInputBuilder::class)->hashPayload($draft->content),
            $manifest['canonical_draft_npa_hash'],
        );
        $this->assertSame($representation->logical_content_hash, $manifest['composite_logical_hash']);

        $xml = $this->documentXml($representation);
        $this->assertSame(5, $this->xpath($xml, '/w:document/w:body/w:tbl[1]/w:tr[1]/w:tc')->length);
        $this->assertGreaterThan(0, $this->xpath($xml, '//w:tr[1]/w:trPr/w:tblHeader')->length);
        $text = $this->plainText($xml);
        $this->assertStringContainsString('СРАВНИТЕЛЬНАЯ ТАБЛИЦА', $text);
        $this->assertStringContainsString('к проекту Закона Республики Казахстан', $text);
        $this->assertStringContainsString('О внесении изменений и дополнений', $text);
        $this->assertStringContainsString('статья 26, пункт 1, подпункт 7-2)', $text);
        $this->assertStringContainsString('статья 30-1', $text);
        $this->assertStringNotContainsString('глава 6, статья', $text);
        $this->assertStringContainsString('7-2) отсутствует.', $text);
        $this->assertStringContainsString('Статья 30-1. Отсутствует.', $text);
        $this->assertSame(
            2,
            substr_count($text, 'Статья 13. Изменение и расторжение договора о долевом участии в жилищном строительстве'),
        );
        $this->assertSame(1, substr_count($text, 'Статья 30-1. Реструктуризация задолженности'));
        $this->assertStringContainsString('Юридические предупреждения', $text);
        $this->assertSame(1, substr_count($text, 'Следует провести отдельную сверку итоговой редакции с положениями типовой формы'));
        $this->assertSame(1, substr_count($text, 'Пятимесячный предельный срок сохранён в соответствии с целью поправки'));
        foreach (['retrieval', 'fragment', 'SourceVersion', 'BM25', 'неизвлеченными'] as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $text);
        }
        $this->assertStringContainsString('<script>alert(1)</script>', $text);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $xml);
        $grid = $this->xpath($xml, '/w:document/w:body/w:tbl[1]/w:tblGrid/w:gridCol');
        $this->assertSame([550, 2100, 3600, 4550, 4904], collect(iterator_to_array($grid))->map(
            fn (\DOMElement $node) => (int) $node->getAttributeNS(
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                'w',
            ),
        )->all());
        $this->assertSame(0, $this->xpath($xml, '//w:pageBreakBefore')->length);
        $this->assertSame(0, $this->xpath($xml, '//w:br[@w:type="page"]')->length);
        $this->assertSame(0, $this->xpath($xml, '/w:document/w:body/w:p[3]/w:pPr/w:keepNext')->length);
        $this->assertSame(1, $this->xpath($xml, '/w:document/w:body/w:tbl[1]/w:tr[1]/w:trPr/w:cantSplit')->length);
        $this->assertSame(0, $this->xpath($xml, '/w:document/w:body/w:tbl[1]/w:tr[2]/w:trPr/w:cantSplit')->length);

        $second = $this->actingAs($user)->post(route('artifacts.docx.download', $table));
        $second->assertOk();
        $this->assertSame(1, Artifact::where('source_artifact_id', $table->id)->count());
        $this->assertSame($representation->id, Artifact::where('source_artifact_id', $table->id)->sole()->id);
        $this->assertSame($canonical, $table->fresh()->content);
        $this->assertSame($analysisTimestamp, $analysis->fresh()->getRawOriginal('updated_at'));
        $this->assertSame($amendmentTimestamps, $analysis->amendments()->get()->mapWithKeys(
            fn ($amendment) => [$amendment->id => $amendment->getRawOriginal('updated_at')],
        )->all());
        Http::assertNothingSent();
    }

    public function test_html_and_docx_share_warning_presentation_without_mutating_canonical_json(): void
    {
        [$user, , $table] = $this->fixture();
        $canonical = $table->content;
        $presentation = app(DraftPackagePresentationService::class)->comparativeTable($table);
        $warnings = data_get($presentation, 'rows.2.warnings');

        $this->assertSame([
            'Следует провести отдельную сверку итоговой редакции с положениями типовой формы договора о долевом участии в жилищном строительстве, которые не были охвачены проведённой проверкой, в частности с терминологией о площади доли, цене и сроке приемки в эксплуатацию.',
            'Пятимесячный предельный срок сохранён в соответствии с целью поправки, однако его материально-правовое и градостроительное обоснование не подтверждено материалами, использованными для анализа. Перед внесением проекта необходимо проверить соответствующие нормы.',
        ], $warnings);

        $this->actingAs($user)->post(route('artifacts.docx.download', $table))->assertOk();
        $text = $this->plainText($this->documentXml(
            Artifact::where('source_artifact_id', $table->id)->sole(),
        ));
        foreach ($warnings as $warning) {
            $this->assertSame(1, substr_count($text, $warning));
        }
        $this->assertSame($canonical, $table->fresh()->content);
        Http::assertNothingSent();
    }

    public function test_cli_generated_docx_uses_runtime_shared_posix_permissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions are not available on Windows.');
        }

        [$user, , $table] = $this->fixture();
        $representation = app(ArtifactDocxService::class)->generate($table, $user);
        $path = Storage::disk($representation->storage_disk)->path($representation->storage_path);

        $this->assertSame(0o004, fileperms($path) & 0o004);
        $this->assertSame(0o001, fileperms(dirname($path)) & 0o001);
        Http::assertNothingSent();
    }

    public function test_owner_downloads_structured_draft_npa_docx_with_service_block(): void
    {
        [$user, , , $draft] = $this->fixture();
        $canonical = $draft->content;

        $this->actingAs($user)->post(route('artifacts.docx.download', $draft))->assertOk();

        $representation = Artifact::where('source_artifact_id', $draft->id)->sole();
        $this->assertSame('draft_npa_docx', $representation->artifact_type);
        $xml = $this->documentXml($representation);
        $text = $this->plainText($xml);
        $this->assertStringContainsString('Проект', $text);
        $this->assertStringContainsString('Закон Республики Казахстан', $text);
        $this->assertStringContainsString('Статья 1.', $text);
        $this->assertStringContainsString('Дополнить статьей 30-1 следующего содержания:', $text);
        $this->assertStringContainsString('Требуется уточнить перед юридическим согласованием', $text);
        $this->assertStringContainsString('Необходимо определить порядок и срок введения НПА в действие', $text);
        $this->assertStringNotContainsString('effective_date_rule', $text);

        $paragraphs = [];
        foreach ($this->xpath($xml, '//w:body/w:p') as $paragraph) {
            $paragraphs[] = trim($paragraph->textContent);
        }
        $this->assertContains('1. Условия реструктуризации.', $paragraphs);
        $this->assertContains('2. Способы реструктуризации.', $paragraphs);
        $this->assertTrue(collect($paragraphs)->contains(
            fn (string $paragraph) => str_starts_with($paragraph, '3. Порядок реструктуризации.'),
        ));
        $this->assertSame($canonical, $draft->fresh()->content);
        Http::assertNothingSent();
    }

    public function test_foreign_user_cannot_generate_or_download_docx(): void
    {
        [, , $table] = $this->fixture();
        $foreign = User::factory()->create();

        $this->actingAs($foreign)
            ->post(route('artifacts.docx.download', $table))
            ->assertForbidden();

        $this->assertDatabaseMissing('artifacts', ['source_artifact_id' => $table->id]);
        Http::assertNothingSent();
    }

    public function test_missing_or_corrupt_representation_is_regenerated_without_duplicate(): void
    {
        [$user, , $table] = $this->fixture();
        $service = app(ArtifactDocxService::class);
        $representation = $service->generate($table, $user);
        $originalId = $representation->id;

        Storage::disk($representation->storage_disk)->put($representation->storage_path, 'not-a-docx');
        $this->assertFalse($service->isValid($representation->fresh()));

        $this->actingAs($user)->post(route('artifacts.docx.download', $table))->assertOk();
        $recovered = Artifact::where('source_artifact_id', $table->id)->sole();
        $this->assertSame($originalId, $recovered->id);
        $this->assertTrue($service->isValid($recovered));
        $this->assertSame(1, Artifact::where('source_artifact_id', $table->id)->count());
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'reference_number' => 'DOCX-'.uniqid(),
            'title' => 'Рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        $document = Document::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => '../../unsafe title <script>',
            'document_type' => 'draft_law',
            'language' => 'ru',
            'analysis_instruction' => 'Разработать нормы.',
        ]);
        $analysis = Analysis::create([
            'workspace_id' => $workspace->id,
            'document_id' => $document->id,
            'user_id' => $user->id,
            'title' => 'Юридический анализ fixture',
            'analysis_type' => 'amendment_review',
            'instruction' => $document->analysis_instruction,
            'status' => 'completed',
            'version' => 1,
            'completed_at' => now(),
        ]);
        $package = $analysis->draftPackage()->create([
            'title' => 'Пакет документов',
            'package_type' => 'legal_amendment',
            'status' => 'draft',
            'plan' => [],
            'generated_at' => now(),
        ]);

        $source = Source::create([
            'title' => 'Закон Республики Казахстан «О долевом участии в жилищном строительстве»',
            'type' => 'law',
            'status' => 'active',
        ]);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Редакция 2026',
            'text' => 'Статья 26. Текст.',
            'hash' => hash('sha256', 'Статья 26. Текст.'),
        ]);
        $analysis->amendments()->create([
            'source_id' => $source->id,
            'source_version_id' => $version->id,
            'target_mode' => 'new',
            'structural_element_type' => 'article',
            'article' => '30-1',
            'proposed_locator' => 'статья 30-1',
            'amendment_type' => 'add_element',
            'disposition' => 'revise',
            'proposed_text' => 'Статья 30-1. Текст.',
            'justification' => 'Обоснование.',
            'legal_basis' => 'Правовое основание.',
            'source_reference' => $source->title,
            'warnings' => [],
            'target_fragment_ids' => [],
            'anchor_fragment_ids' => [],
            'citations' => [],
            'target_snapshot' => [],
            'sort_order' => 1,
        ]);

        $tableContent = [
            'schema_version' => 'comparative-table-v1',
            'title' => 'Сравнительная таблица',
            'columns' => ['№', 'Структурный элемент', 'Действующая редакция', 'Предлагаемая редакция', 'Обоснование'],
            'rows' => [
                [
                    'number' => 1,
                    'amendment_id' => 1,
                    'structural_element' => 'глава 6, статья 26, пункт 1, подпункт 7-2)',
                    'current_text' => 'Отсутствует',
                    'proposed_text' => '7-2) осуществлять реструктуризацию задолженности;',
                    'justification' => 'Поправка устраняет правовую неопределённость.',
                    'warnings' => [],
                ],
                [
                    'number' => 2,
                    'amendment_id' => 2,
                    'structural_element' => 'глава 6, статья 30-1',
                    'current_text' => 'Отсутствует',
                    'proposed_text' => "Статья 30-1. Реструктуризация задолженности\n\n1. Условия реструктуризации.\n2. Способы реструктуризации.\n3. Порядок реструктуризации. <script>alert(1)</script>",
                    'justification' => "Необходимо определить механизм.\nПоправка обеспечивает правовую определённость.",
                    'warnings' => ['Проверить согласованность новой статьи с иными нормами.'],
                ],
                [
                    'number' => 3,
                    'amendment_id' => 3,
                    'structural_element' => 'глава 4, статья 13, пункт 1',
                    'article_heading' => 'Статья 13. Изменение и расторжение договора о долевом участии в жилищном строительстве',
                    'current_text' => '1. Договор изменяется по соглашению сторон.',
                    'proposed_text' => '1. Договор изменяется только в случаях, предусмотренных законом.',
                    'justification' => 'Поправка уточняет порядок изменения договора.',
                    'warnings' => [
                        'Следует провести отдельную сверку итоговой редакции с неизвлеченными положениями типовой формы договора о долевом участии в жилищном строительстве, в частности с терминологией о площади доли, цене и сроке приемки в эксплуатацию.',
                        'Пятимесячный предельный срок сохранен в соответствии с целью пользовательской поправки, однако его материально-правовое и градостроительное обоснование не подтверждается предоставленным контекстом. Перед внесением проекта необходимо проверить соответствующие нормы, не включенные в retrieval-контекст.',
                        'Пятимесячный предельный срок сохранен в соответствии с целью поправки, однако его материально-правовое и градостроительное обоснование не подтверждается предоставленным контекстом. Перед внесением проекта необходимо проверить соответствующие нормы, не включенные в retrieval-контекст.',
                    ],
                ],
            ],
            'warnings' => [],
        ];
        $draftContent = [
            'schema_version' => 'draft-npa-v1',
            'project_mark' => 'Проект',
            'act_type' => 'Закон Республики Казахстан',
            'title' => 'О внесении изменений и дополнений в Закон Республики Казахстан "О долевом участии в жилищном строительстве"',
            'articles' => [[
                'number' => 1,
                'intro' => 'Внести в Закон Республики Казахстан «О долевом участии в жилищном строительстве» следующие изменения и дополнения:',
                'commands' => [
                    [
                        'number' => 1,
                        'text' => "В статье 26:\n\nпункт 1 дополнить подпунктом 7-2) следующего содержания:\n\n«7-2) осуществлять реструктуризацию задолженности;»;",
                    ],
                    [
                        'number' => 2,
                        'text' => "Дополнить статьей 30-1 следующего содержания:\n\n«Статья 30-1. Реструктуризация задолженности\n\n1. Условия реструктуризации.\n2. Способы реструктуризации.\n3. Порядок реструктуризации.»;",
                    ],
                ],
            ]],
            'requires_user_input' => ['effective_date_rule'],
            'warnings' => ['Заключительная норма требует подтверждения.'],
        ];

        $table = $package->artifacts()->create([
            'created_by' => $user->id,
            'artifact_type' => 'comparative_table',
            'format' => 'structured_json',
            'title' => '../../unsafe comparative table',
            'content' => $tableContent,
            'status' => 'draft',
        ]);
        $draft = $package->artifacts()->create([
            'created_by' => $user->id,
            'artifact_type' => 'draft_npa',
            'format' => 'structured_json',
            'title' => 'Проект НПА',
            'content' => $draftContent,
            'status' => 'draft',
        ]);

        return [$user, $analysis->fresh(), $table, $draft];
    }

    private function documentXml(Artifact $artifact): string
    {
        $path = Storage::disk($artifact->storage_disk)->path($artifact->storage_path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }

    private function xpath(string $xml, string $query): \DOMNodeList
    {
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $nodes = $xpath->query($query);
        $this->assertInstanceOf(\DOMNodeList::class, $nodes);

        return $nodes;
    }

    private function plainText(string $xml): string
    {
        $texts = [];
        foreach ($this->xpath($xml, '//w:t') as $node) {
            $texts[] = $node->textContent;
        }

        return implode("\n", $texts);
    }
}
