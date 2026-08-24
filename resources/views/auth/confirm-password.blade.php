<x-guest-layout>
    <h1 class="text-2xl font-bold text-slate-900">Подтверждение пароля</h1>
    <p class="mt-3 text-sm leading-6 text-slate-600">Это защищённый раздел. Подтвердите пароль, чтобы продолжить.</p>
    <form method="POST" action="{{ route('password.confirm') }}" class="mt-6 space-y-5">
        @csrf
        <div>
            <x-input-label for="password" value="Пароль" />
            <x-text-input id="password" class="mt-2 block w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div class="flex justify-end"><x-primary-button>Подтвердить</x-primary-button></div>
    </form>
</x-guest-layout>
