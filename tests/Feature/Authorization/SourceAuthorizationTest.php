<?php

namespace Tests\Feature\Authorization;

use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_can_read_global_sources_but_cannot_manage_them(): void
    {
        $user = User::factory()->create();
        [$source, $version] = $this->sourceFixture();

        $this->actingAs($user)
            ->get(route('sources.index'))
            ->assertOk()
            ->assertDontSee(route('sources.create'));

        $this->actingAs($user)
            ->get(route('sources.show', $source))
            ->assertOk()
            ->assertDontSee(route('source-versions.create', $source))
            ->assertDontSee(route('source-versions.edit', [$source, $version]));

        $this->actingAs($user)->get(route('sources.create'))->assertForbidden();
        $this->actingAs($user)->post(route('sources.store'), $this->sourcePayload())->assertForbidden();
        $this->actingAs($user)->get(route('source-versions.create', $source))->assertForbidden();
        $this->actingAs($user)->post(route('source-versions.store', $source), $this->versionPayload())->assertForbidden();
        $this->actingAs($user)->get(route('source-versions.edit', [$source, $version]))->assertForbidden();
        $this->actingAs($user)->put(route('source-versions.update', [$source, $version]), $this->versionPayload('Новая редакция'))->assertForbidden();

        $this->assertDatabaseCount('sources', 1);
        $this->assertDatabaseCount('source_versions', 1);
    }

    public function test_admin_can_manage_global_sources_and_versions(): void
    {
        $admin = User::factory()->admin()->create();
        [$source, $version] = $this->sourceFixture();

        $this->actingAs($admin)
            ->get(route('sources.index'))
            ->assertOk()
            ->assertSee(route('sources.create'));

        $this->actingAs($admin)->get(route('sources.create'))->assertOk();

        $this->actingAs($admin)
            ->post(route('sources.store'), $this->sourcePayload())
            ->assertRedirect();

        $this->actingAs($admin)->get(route('source-versions.create', $source))->assertOk();

        $this->actingAs($admin)
            ->post(route('source-versions.store', $source), $this->versionPayload())
            ->assertRedirect(route('sources.show', $source));

        $this->actingAs($admin)
            ->get(route('source-versions.edit', [$source, $version]))
            ->assertOk();

        $this->actingAs($admin)
            ->put(route('source-versions.update', [$source, $version]), $this->versionPayload('Обновлённая редакция'))
            ->assertRedirect(route('sources.show', $source));

        $this->assertDatabaseCount('sources', 2);
        $this->assertDatabaseCount('source_versions', 2);
        $this->assertDatabaseHas('source_versions', [
            'id' => $version->id,
            'text' => 'Обновлённая редакция',
        ]);
    }

    private function sourceFixture(): array
    {
        $source = Source::create([
            'title' => 'Закон Республики Казахстан',
            'type' => 'law',
            'status' => 'active',
        ]);
        $text = 'Действующая редакция закона';
        $version = SourceVersion::create([
            'source_id' => $source->id,
            'version_name' => 'Действующая редакция',
            'text' => $text,
            'hash' => hash('sha256', $text),
        ]);

        return [$source, $version];
    }

    private function sourcePayload(): array
    {
        return [
            'title' => 'Новый нормативный акт',
            'type' => 'law',
            'status' => 'active',
        ];
    }

    private function versionPayload(string $text = 'Дополнительная редакция'): array
    {
        return [
            'version_name' => 'Новая редакция',
            'text' => $text,
        ];
    }
}
