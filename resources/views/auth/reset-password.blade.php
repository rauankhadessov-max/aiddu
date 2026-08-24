<x-guest-layout>
    <h1 class="text-2xl font-bold text-slate-900">Новый пароль</h1>
    <p class="mt-3 text-sm text-slate-600">Задайте новый пароль для вашей учётной записи.</p>
    <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div>
            <x-input-label for="email" value="Электронная почта" />
            <x-text-input id="email" class="mt-2 block w-full" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password" value="Новый пароль" />
            <x-text-input id="password" class="mt-2 block w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password_confirmation" value="Подтверждение нового пароля" />
            <x-text-input id="password_confirmation" class="mt-2 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>
        <div class="flex justify-end"><x-primary-button>Сохранить новый пароль</x-primary-button></div>
    </form>
</x-guest-layout>
