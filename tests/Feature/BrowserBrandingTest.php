<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_guest_and_public_pages_use_legal_ai_browser_branding(): void
    {
        foreach (['/', '/login', '/register'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('<title>Правовой ИИ</title>', false)
                ->assertSee('favicon.ico', false)
                ->assertSee('favicon-32x32.png', false)
                ->assertSee('favicon-16x16.png', false)
                ->assertSee('apple-touch-icon.png', false)
                ->assertSee('site.webmanifest', false)
                ->assertDontSee('AI DDU Assistant')
                ->assertDontSee('AI DDU');
        }

        $this->get('/')
            ->assertSee('Возможности «Правового ИИ»')
            ->assertDontSee('Возможности AI DDU');
    }

    public function test_authenticated_layout_uses_page_title_and_shared_favicons(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<title>Главная — Правовой ИИ</title>', false)
            ->assertSee('favicon.ico', false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('site.webmanifest', false)
            ->assertDontSee('AI DDU Assistant');

        $this->actingAs($user)
            ->get(route('analyses.index'))
            ->assertOk()
            ->assertSee('<title>История анализов — Правовой ИИ</title>', false)
            ->assertSee('favicon-32x32.png', false);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('<title>Настройки профиля — Правовой ИИ</title>', false)
            ->assertSee('site.webmanifest', false);
    }

    public function test_favicon_files_and_manifest_are_valid(): void
    {
        $pngFiles = [
            'favicon-16x16.png' => 16,
            'favicon-32x32.png' => 32,
            'apple-touch-icon.png' => 180,
            'icon-192x192.png' => 192,
            'icon-512x512.png' => 512,
        ];

        foreach ($pngFiles as $filename => $size) {
            $path = public_path($filename);
            $image = getimagesize($path);

            $this->assertFileExists($path);
            $this->assertIsArray($image);
            $this->assertSame($size, $image[0]);
            $this->assertSame($size, $image[1]);
            $this->assertSame('image/png', $image['mime']);
        }

        $favicon = public_path('favicon.ico');
        $this->assertFileExists($favicon);
        $this->assertGreaterThan(0, filesize($favicon));
        $this->assertContains(
            mime_content_type($favicon),
            ['image/vnd.microsoft.icon', 'image/x-icon'],
        );

        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Правовой ИИ', $manifest['name']);
        $this->assertSame('/icon-192x192.png', $manifest['icons'][0]['src']);
        $this->assertSame('/icon-512x512.png', $manifest['icons'][1]['src']);
    }
}
