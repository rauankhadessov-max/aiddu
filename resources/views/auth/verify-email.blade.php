<x-guest-layout>
    <h1 class="text-2xl font-bold text-slate-900">Подтверждение электронной почты</h1>
    <p class="mt-3 text-sm leading-6 text-slate-600">Мы отправили письмо со ссылкой для подтверждения. Перейдите по ссылке, чтобы продолжить работу.</p>
    @if (session('status') === 'verification-link-sent')
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">Новая ссылка отправлена на вашу электронную почту.</div>
    @endif
    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button>Отправить письмо повторно</x-primary-button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm font-semibold text-slate-600 underline hover:text-blue-600">Выйти</button>
        </form>
    </div>
</x-guest-layout>
