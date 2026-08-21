<x-layouts.app
    title="История анализов — AI DDU Assistant"
    heading="История анализов"
    description="Все выполненные и сохранённые юридические анализы"
>
    <div class="space-y-6">

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Все анализы</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Откройте анализ, чтобы просмотреть нормативную базу и результат.
                </p>
            </div>

            <a
                href="{{ route('workspaces.index', ['start' => 'analysis']) }}"
                class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
            >
                + Новый анализ
            </a>
        </div>

        <div class="space-y-4">
            @forelse ($analyses as $analysis)
                <a
                    href="{{ route('analyses.show', $analysis) }}"
                    class="block rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300"
                >
                    <div class="flex items-start justify-between gap-6">
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Анализ #{{ $analysis->id }}
                            </div>

                            <h2 class="mt-2 text-lg font-bold text-slate-900">
                                {{ $analysis->title ?: 'Без названия' }}
                            </h2>

                            <div class="mt-3 flex flex-wrap gap-4 text-sm text-slate-500">
                                @if ($analysis->workspace)
                                    <span>Рабочее дело: {{ $analysis->workspace->title }}</span>
                                @endif

                                @if ($analysis->document)
                                    <span>Документ: {{ $analysis->document->title }}</span>
                                @endif

                                <span>{{ $analysis->created_at?->format('d.m.Y H:i') }}</span>
                            </div>
                        </div>

                        <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                            {{ $analysis->status }}
                        </span>
                    </div>
                </a>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8">
                    <h2 class="text-lg font-bold text-slate-900">Анализов пока нет</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Выберите рабочее дело и документ, чтобы создать первый анализ.
                    </p>
                </div>
            @endforelse
        </div>

    </div>
</x-layouts.app>
