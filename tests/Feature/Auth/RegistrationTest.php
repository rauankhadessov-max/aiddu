<?php

namespace Tests\Feature\Auth;

use App\Models\RegulatoryProfile;
use App\Models\Source;
use App\Models\SourceVersion;
use App\Models\User;
use App\Services\DefaultWorkspaceProvisioner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationTest extends TestCase
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

    public function test_registration_screen_is_available_to_guests_in_branded_russian_layout(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Правовой ИИ')
            ->assertSee('Анализ и подготовка НПА')
            ->assertSee('Регистрация')
            ->assertSee('Имя')
            ->assertSee('Электронная почта')
            ->assertSee('Пароль')
            ->assertSee('Подтверждение пароля')
            ->assertSee('Зарегистрироваться')
            ->assertSee('sm:flex-row', false)
            ->assertDontSee('Register')
            ->assertDontSee('Confirm Password');
    }

    public function test_authenticated_user_cannot_register_again(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/register')->assertRedirect(route('dashboard'));
        $this->actingAs($user)->post('/register', $this->validPayload('other@example.test'))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_creates_regular_user_and_complete_default_onboarding(): void
    {
        $profile = $this->defaultProfile();
        $global = Source::create(['title' => 'Глобальный закон', 'type' => 'law', 'status' => 'active']);
        SourceVersion::create([
            'source_id' => $global->id,
            'version_name' => 'Действующая редакция',
            'text' => 'Статья 1. Проверочная норма.',
            'hash' => hash('sha256', 'Статья 1. Проверочная норма.'),
        ]);
        $otherOwner = User::factory()->create();
        $personal = Source::create(['title' => 'Чужой личный НПА', 'type' => 'order', 'status' => 'active']);
        $personal->user()->associate($otherOwner);
        $personal->save();
        $profile->sources()->attach($global, ['sort_order' => 0, 'is_primary' => true]);
        $sourceCount = Source::withTrashed()->count();
        $versionCount = SourceVersion::count();
        Event::fake();

        $response = $this->post('/register', $this->validPayload());

        $user = User::where('email', 'new.user@example.test')->sole();
        $workspace = $user->workspaces()->sole();

        $response->assertRedirect(route('analyses.workflow.create'));
        Event::assertDispatched(Registered::class, fn (Registered $event) => $event->user->is($user));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check('SecurePass123!', $user->password));
        $this->assertSame($profile->id, $workspace->regulatory_profile_id);
        $this->assertSame('Закон Республики Казахстан «О долевом участии в жилищном строительстве»', $workspace->title);
        $this->assertSame([$global->id], $workspace->sources()->pluck('sources.id')->all());
        $this->assertSame($sourceCount, Source::withTrashed()->count());
        $this->assertSame($versionCount, SourceVersion::count());

        $this->get(route('analyses.workflow.create'))
            ->assertOk()
            ->assertSee($workspace->title)
            ->assertSee($global->title)
            ->assertDontSee($personal->title)
            ->assertSee('value="'.$workspace->id.'" selected', false);

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'SecurePass123!',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_duplicate_email_and_password_confirmation_are_rejected(): void
    {
        User::factory()->create(['email' => 'duplicate@example.test']);

        $this->post('/register', $this->validPayload('duplicate@example.test'))
            ->assertSessionHasErrors('email');

        $payload = $this->validPayload('confirmation@example.test');
        $payload['password_confirmation'] = 'DifferentPass123!';

        $this->post('/register', $payload)->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'confirmation@example.test']);
    }

    public function test_admin_and_ownership_fields_cannot_be_forged(): void
    {
        $payload = $this->validPayload('forged@example.test') + [
            'is_admin' => 1,
            'role' => 'admin',
            'user_id' => 1,
            'owner_id' => 1,
        ];

        $this->post('/register', $payload)
            ->assertSessionHasErrors(['is_admin', 'role', 'user_id', 'owner_id']);

        $this->assertDatabaseMissing('users', ['email' => 'forged@example.test']);
        $this->assertGuest();
    }

    public function test_provisioning_failure_rolls_back_user_and_partial_workspace(): void
    {
        $this->defaultProfile()->update(['is_active' => false]);
        Event::fake();

        $this->post('/register', $this->validPayload('rollback@example.test'))
            ->assertSessionHasErrors('registration');

        Event::assertNotDispatched(Registered::class);
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertGuest();
    }

    public function test_default_workspace_provisioner_remains_idempotent_after_registration(): void
    {
        $this->post('/register', $this->validPayload('idempotent@example.test'));
        $user = User::where('email', 'idempotent@example.test')->sole();

        app(DefaultWorkspaceProvisioner::class)->provision($user);
        app(DefaultWorkspaceProvisioner::class)->provision($user);

        $this->assertSame(1, $user->workspaces()->count());
    }

    public function test_registration_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $payload = $this->validPayload('limited@example.test');
            $payload['password_confirmation'] = 'Mismatch'.$attempt;
            $this->post('/register', $payload)->assertSessionHasErrors('password');
        }

        $this->post('/register', $this->validPayload('limited@example.test'))
            ->assertTooManyRequests();
        $this->assertDatabaseMissing('users', ['email' => 'limited@example.test']);
    }

    private function defaultProfile(): RegulatoryProfile
    {
        return RegulatoryProfile::query()
            ->where('purpose', RegulatoryProfile::NEW_USER_DEFAULT)
            ->sole();
    }

    /** @return array<string, string> */
    private function validPayload(string $email = 'new.user@example.test'): array
    {
        return [
            'name' => 'Новый пользователь',
            'email' => $email,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];
    }
}
