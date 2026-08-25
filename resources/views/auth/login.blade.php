<x-guest-layout>
    <div class="mb-7">
        <div class="text-sm font-semibold text-blue-600">Правовой ИИ</div>
        <div class="mt-1 text-xs text-slate-500">Анализ и подготовка НПА</div>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">Вход в систему</h1>
        <p class="mt-2 text-sm text-slate-500">Войдите, чтобы продолжить работу с юридическими материалами.</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf
        <div>
            <x-input-label for="email" value="Электронная почта" />
            <x-text-input id="email" class="mt-2 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password" value="Пароль" />
            <x-text-input id="password" class="mt-2 block w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <label for="remember_me" class="flex items-center gap-2 text-sm text-slate-600">
            <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" name="remember">
            <span>Запомнить меня</span>
        </label>
        <div class="flex flex-wrap items-center justify-between gap-3">
            @if (Route::has('password.request'))
                <a class="text-sm font-semibold text-slate-600 underline hover:text-blue-600" href="{{ route('password.request') }}">Забыли пароль?</a>
            @endif
            <x-primary-button>Войти</x-primary-button>
        </div>
    </form>
</x-guest-layout>
