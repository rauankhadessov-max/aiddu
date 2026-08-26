<?php

namespace Tests\Feature\Analysis;

use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnifiedAnalysisPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_form_uses_compact_legal_workspace_sections_without_changing_safe_flows(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $profile = RegulatoryProfile::where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)->sole();
        $workspace = Workspace::create([
            'user_id' => $user->id,
            'regulatory_profile_id' => $profile->id,
            'reference_number' => 'WS-PRESENTATION',
            'title' => 'Профильное рабочее дело',
            'category' => 'other',
            'status' => 'draft',
        ]);
        [$globalSource, $globalVersion] = $this->sourceVersion('Глобальный закон');
        [$personalSource, $personalVersion] = $this->sourceVersion('Мой нормативный акт', $user);
        $workspace->sources()->attach([$globalSource->id, $personalSource->id]);

        $response = $this->actingAs($user)->get(route('analyses.workflow.create'));

        $response->assertOk()
            ->assertSeeInOrder(['Исходные данные', 'Поручение ИИ', 'Нормативная база'])
            ->assertSee('Если НПА не выбраны вручную, анализ проводится автоматически по нормативной базе рабочего дела.')
            ->assertSee('<option value="'.$workspace->id.'" selected>', false)
            ->assertSee('Глобальные НПА')
            ->assertSee('Мои НПА')
            ->assertSee($globalSource->title)
            ->assertSee($globalVersion->version_name)
            ->assertSee($personalSource->title)
            ->assertSee($personalVersion->version_name)
            ->assertSee('id="inline-source-toggle"', false)
            ->assertSee('id="inline-source-form"', false)
            ->assertSee('Загрузить DOCX')
            ->assertSee('Указать ссылку')
            ->assertSee('name="action" value="save_draft"', false)
            ->assertSee('name="action" value="run"', false)
            ->assertSee('Сохранить черновик')
            ->assertSee('Запустить анализ')
            ->assertSee('grid gap-4 lg:grid-cols-2', false)
            ->assertSee('flex flex-col-reverse gap-3 sm:flex-row', false);

        Http::assertNothingSent();
    }

    private function sourceVersion(string $title, ?User $owner = null): array
    {
        $source = Source::create([
            'user_id' => $owner?->id,
            'title' => $title,
            'type' => 'law',
            'status' => 'active',
        ]);
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Действующая редакция '.$title,
            'text' => 'Статья 1. Норма.',
            'hash' => hash('sha256', $title),
        ]);

        return [$source, $version];
    }
}
