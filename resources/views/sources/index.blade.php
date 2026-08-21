<x-layouts.app
    title="Нормативная база — AI DDU Assistant"
    heading="Нормативная база"
    description="Нормативные правовые акты, используемые при юридическом анализе"
>
    <div class="space-y-6">

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Нормативные источники</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Глобальная нормативная база и сохранённые редакции НПА.
                </p>
            </div>

            @can('create', App\Models\Source::class)
                <a
                    href="{{ route('sources.create') }}"
                    class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                >
                    + Добавить НПА
                </a>
            @endcan
        </div>

        <div class="space-y-4">
            @forelse ($sources as $source)
                <a
                    href="{{ route('sources.show', $source) }}"
                    class="block rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300"
                >
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ $source->type }}
                            </div>

                            <h2 class="mt-2 text-lg font-bold text-slate-900">{{ $source->title }}</h2>

                            <div class="mt-3 flex flex-wrap gap-4 text-sm text-slate-500">
                                @if ($source->number)
                                    <span>№ {{ $source->number }}</span>
                                @endif

                                @if ($source->adoption_date)
                                    <span>от {{ $source->adoption_date->format('d.m.Y') }}</span>
                                @endif

                                @if ($source->issuing_authority)
                                    <span>{{ $source->issuing_authority }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="shrink-0 text-right">
                            <div class="text-2xl font-bold text-slate-900">{{ $source->versions_count }}</div>
                            <div class="text-xs text-slate-500">редакций</div>
                        </div>
                    </div>
                </a>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8">
                    <h2 class="text-lg font-bold text-slate-900">Нормативная база пока пуста</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Доступные нормативные источники появятся здесь.
                    </p>
                </div>
            @endforelse
        </div>

    </div>
</x-layouts.app>
