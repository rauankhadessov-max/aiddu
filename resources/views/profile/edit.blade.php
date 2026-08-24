<x-layouts.app
    title="Настройки профиля — AI DDU Assistant"
    heading="Настройки профиля"
    description="Данные учётной записи и параметры безопасности"
>
    <div class="mx-auto max-w-4xl space-y-6">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            @include('profile.partials.update-profile-information-form')
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            @include('profile.partials.update-password-form')
        </section>

        <section class="rounded-2xl border border-red-200 bg-white p-6 sm:p-8">
            @include('profile.partials.delete-user-form')
        </section>
    </div>
</x-layouts.app>
