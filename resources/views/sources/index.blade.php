<x-layouts.app
    title="Нормативная база — Правовой ИИ"
    heading="Нормативная база"
    description="Выберите рабочее дело, нормативную базу которого нужно открыть"
>
    <div class="space-y-6">
        @if (session('success'))
            <x-ui.flash :message="session('success')" />
        @endif

        @if (session('error'))
            <x-ui.flash type="warning" :message="session('error')" />
        @endif

        <div>
            <h2 class="text-2xl font-bold text-slate-900">Рабочие дела</h2>
            <p class="mt-1 text-sm text-slate-500">Нормативная база формируется отдельно для каждого рабочего дела.</p>
        </div>

        @forelse ($workspaces as $workspace)
            <article class="ui-card">
                <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-3">
                            <h3 class="text-lg font-bold text-slate-900">{{ $workspace->title }}</h3>
                            @if ($workspace->regulatoryProfile?->is_active && $workspace->regulatoryProfile->purpose === App\Models\RegulatoryProfile::NEW_USER_DEFAULT)
                                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">По умолчанию</span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm text-slate-500">Подключено НПА: <span class="font-semibold text-slate-700">{{ $workspace->sources_count }}</span></p>
                    </div>

                    <a href="{{ route('workspaces.sources', $workspace) }}" class="shrink-0 rounded-xl bg-blue-600 px-5 py-3 text-center text-sm font-semibold text-white hover:bg-blue-500">Открыть нормативную базу</a>
                </div>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8">
                <h3 class="font-bold text-slate-900">Рабочих дел пока нет</h3>
                <p class="mt-2 text-sm text-slate-500">Создайте рабочее дело, чтобы сформировать его нормативную базу.</p>
                <a href="{{ route('workspaces.create') }}" class="mt-4 inline-flex rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white">Создать рабочее дело</a>
            </div>
        @endforelse
    </div>
</x-layouts.app>
