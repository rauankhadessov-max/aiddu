<x-guest-layout>
    <div class="mb-7">
        <div class="text-sm font-semibold text-blue-600">Правовой ИИ</div>
        <div class="mt-1 text-xs text-slate-500">Анализ и подготовка НПА</div>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">Регистрация</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">Создайте аккаунт — рабочее дело и стартовая нормативная база будут подготовлены автоматически.</p>
    </div>

    <x-input-error :messages="$errors->get('registration')" class="mb-5" />

    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="name" value="Имя" />
            <x-text-input id="name" class="mt-2 block w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="email" value="Электронная почта" />
            <x-text-input id="email" class="mt-2 block w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" value="Пароль" />
            <x-text-input id="password" class="mt-2 block w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Подтверждение пароля" />
            <x-text-input id="password_confirmation" class="mt-2 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex flex-col-reverse gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
            <a class="text-center text-sm font-semibold text-slate-600 underline hover:text-blue-600" href="{{ route('login') }}">Уже есть аккаунт?</a>
            <x-primary-button class="justify-center">Зарегистрироваться</x-primary-button>
        </div>
    </form>
</x-guest-layout>
