<x-layouts.app :title="$workspace->title . ' — AI DDU Assistant'" :heading="$workspace->title" :description="$workspace->description">
    <div class="space-y-8">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-col justify-between gap-5 md:flex-row md:items-center">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900">{{ $workspace->title }}</h2>
                    @if ($workspace->description)
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ $workspace->description }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('analyses.workflow.create', ['workspace' => $workspace->id]) }}" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">Новый анализ</a>
                    <a href="{{ route('workspaces.sources', $workspace) }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 hover:border-blue-300">Нормативная база</a>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Последние анализы</h2>
                    <p class="mt-1 text-sm text-slate-500">Материалы, подготовленные в этом рабочем деле.</p>
                </div>
                <a href="{{ route('analyses.index') }}" class="text-sm font-semibold text-blue-600 hover:text-blue-500">Вся история</a>
            </div>
            <div class="mt-5 space-y-3">
                @forelse ($workspace->analyses->take(10) as $analysis)
                    <article class="flex flex-col justify-between gap-4 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-center">
                        <div>
                            <div class="font-semibold text-slate-900">{{ $analysis->title ?: 'Без названия' }}</div>
                            <div class="mt-1 flex flex-wrap gap-3 text-sm text-slate-500">
                                <span>{{ $analysis->created_at?->format('d.m.Y H:i') }}</span>
                                <span>{{ $analysis->statusLabel() }}</span>
                            </div>
                        </div>
                        <a href="{{ $analysis->status === 'draft' ? route('analyses.workflow.edit', $analysis) : route('analyses.show', $analysis) }}" class="shrink-0 rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:border-blue-300">
                            {{ $analysis->status === 'draft' ? 'Продолжить' : 'Открыть' }}
                        </a>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">В этом рабочем деле пока нет анализов.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Подключённая нормативная база</h2>
                    <p class="mt-1 text-sm text-slate-500">НПА, доступные для анализа в этом рабочем деле.</p>
                </div>
                <a href="{{ route('workspaces.sources', $workspace) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">Управление нормативной базой</a>
            </div>
            <div class="mt-5 space-y-3">
                @forelse ($workspace->sources as $source)
                    <div class="rounded-xl border border-slate-200 p-4 font-semibold text-slate-900">{{ $source->title }}</div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">Нормативная база пока не подключена.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
