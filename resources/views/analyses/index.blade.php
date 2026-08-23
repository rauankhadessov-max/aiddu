<x-layouts.app title="История анализов — AI DDU Assistant" heading="История анализов" description="Сохранённые юридические анализы и черновики">
    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Все анализы</h2>
                <p class="mt-1 text-sm text-slate-500">Откройте результат или продолжите сохранённый черновик.</p>
            </div>
            <a href="{{ route('analyses.workflow.create') }}" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">+ Новый анализ</a>
        </div>

        <div class="space-y-4">
            @forelse ($analyses as $analysis)
                <article class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="flex flex-col justify-between gap-5 md:flex-row md:items-center">
                        <div class="min-w-0">
                            <h2 class="text-lg font-bold text-slate-900">{{ $analysis->title ?: 'Без названия' }}</h2>
                            <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-slate-500">
                                <span>{{ $analysis->created_at?->format('d.m.Y H:i') }}</span>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $analysis->statusLabel() }}</span>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($analysis->status === 'draft')
                                <a href="{{ route('analyses.workflow.edit', $analysis) }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">Продолжить</a>
                            @else
                                <a href="{{ route('analyses.show', $analysis) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">Открыть</a>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8">
                    <h2 class="text-lg font-bold text-slate-900">Анализов пока нет</h2>
                    <p class="mt-2 text-sm text-slate-500">Создайте первый анализ в единой форме.</p>
                </div>
            @endforelse
        </div>
    </div>
</x-layouts.app>
