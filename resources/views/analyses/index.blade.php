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

                            @if ($analysis->status === 'processing')
                                <button type="button" disabled class="cursor-not-allowed rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-400" title="Дождитесь завершения анализа">Удалить</button>
                            @else
                                <button
                                    type="button"
                                    x-data
                                    x-on:click.prevent="$dispatch('open-modal', 'delete-analysis-{{ $analysis->id }}')"
                                    class="rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50"
                                >
                                    Удалить
                                </button>
                            @endif
                        </div>
                    </div>
                </article>

                @if ($analysis->status !== 'processing')
                    <x-modal name="delete-analysis-{{ $analysis->id }}" maxWidth="md" focusable>
                        <div class="p-6">
                            <h2 class="text-lg font-bold text-slate-900">Удалить анализ?</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Будут удалены результаты анализа и сформированные документы.<br>
                                Исходные данные рабочего дела и нормативная база сохранятся.
                            </p>
                            <div class="mt-6 flex justify-end gap-3">
                                <button type="button" x-on:click="$dispatch('close')" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Отмена</button>
                                <form method="POST" action="{{ route('analyses.destroy', $analysis) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">Удалить</button>
                                </form>
                            </div>
                        </div>
                    </x-modal>
                @endif
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8">
                    <h2 class="text-lg font-bold text-slate-900">Анализов пока нет</h2>
                    <p class="mt-2 text-sm text-slate-500">Создайте первый анализ в единой форме.</p>
                </div>
            @endforelse
        </div>
    </div>
</x-layouts.app>
