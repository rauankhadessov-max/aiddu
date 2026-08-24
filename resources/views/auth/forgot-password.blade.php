<x-guest-layout>
    <h1 class="text-2xl font-bold text-slate-900">Восстановление пароля</h1>
    <p class="mt-3 text-sm leading-6 text-slate-600">Укажите электронную почту. Мы отправим ссылку для создания нового пароля.</p>
    <x-auth-session-status class="mt-4" :status="session('status')" />
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
        @csrf
        <div>
            <x-input-label for="email" value="Электронная почта" />
            <x-text-input id="email" class="mt-2 block w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div class="flex justify-end"><x-primary-button>Отправить ссылку</x-primary-button></div>
    </form>
</x-guest-layout>
