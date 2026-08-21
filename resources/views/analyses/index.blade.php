<x-app-layout>

    <x-slot name="header">
        <div>
            <h2 class="text-xl font-bold text-slate-900">
                История анализов
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Все выполненные и сохранённые юридические анализы.
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

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
                                        <span>
                                            Рабочее дело: {{ $analysis->workspace->title }}
                                        </span>
                                    @endif

                                    @if ($analysis->document)
                                        <span>
                                            Документ: {{ $analysis->document->title }}
                                        </span>
                                    @endif

                                    <span>
                                        {{ $analysis->created_at?->format('d.m.Y H:i') }}
                                    </span>

                                </div>

                            </div>

                            <div class="shrink-0 text-right">

                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                    {{ $analysis->status }}
                                </span>

                                <div class="mt-2 text-xs text-slate-500">
                                    {{ $analysis->analysis_type }}
                                </div>

                            </div>

                        </div>
                    </a>

                @empty

                    <div class="rounded-2xl border border-slate-200 bg-white p-8">
                        <h2 class="text-lg font-bold text-slate-900">
                            Анализов пока нет
                        </h2>

                        <p class="mt-2 text-sm text-slate-500">
                            После запуска анализа он появится здесь.
                        </p>
                    </div>

                @endforelse

            </div>

        </div>
    </div>

</x-app-layout>
