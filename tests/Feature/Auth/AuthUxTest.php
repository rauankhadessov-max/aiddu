<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_uses_branded_russian_guest_layout(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('AI DDU Assistant')
            ->assertSee('Вход в систему')
            ->assertSee('Электронная почта')
            ->assertSee('Пароль')
            ->assertSee('Запомнить меня')
            ->assertSee('Забыли пароль?')
            ->assertDontSee('Remember me')
            ->assertDontSee('Forgot your password?')
            ->assertDontSee('>Log in<', false)
            ->assertDontSee('Laravel');
    }

    public function test_password_and_verification_pages_are_localized(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Восстановление пароля')
            ->assertDontSee('Forgot your password?');

        $this->get(route('password.reset', ['token' => 'test-token']))
            ->assertOk()
            ->assertSee('Новый пароль')
            ->assertDontSee('Reset Password');

        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Подтверждение электронной почты')
            ->assertDontSee('Thanks for signing up!');

        $this->actingAs($user)->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('Подтверждение пароля')
            ->assertDontSee('This is a secure area');
    }

    public function test_registration_remains_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }
}
