<x-layouts.app
    :title="$analysis->title . ' — AI DDU Assistant'"
    :heading="$analysis->title"
    :description="$analysis->document->title"
>
    <div class="space-y-6">

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

@if (session('error'))
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

@if ($analysis->document)

    <div class="grid gap-6 md:grid-cols-2">

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Действующая редакция
            </div>

            <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-800">
                {{ $analysis->document->current_text ?: 'Не указана' }}
            </div>
        </section>

        <section class="rounded-2xl border border-blue-200 bg-blue-50 p-6">
            <div class="text-xs font-semibold uppercase tracking-wide text-blue-600">
                Предлагаемая редакция
            </div>

            <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-800">
                {{ $analysis->document->proposed_text ?: 'Не указана' }}
            </div>
        </section>

    </div>

@endif


        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-bold">Поручение ИИ</h2>

            <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                {{ $analysis->instruction }}
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-bold">Нормативная база анализа</h2>

            <div class="mt-4 space-y-3">
                @forelse ($analysis->sourceVersions as $version)
                    <div class="rounded-xl border border-slate-200 p-4">
                        <div class="font-semibold">
                            {{ $version->source->title }}
                        </div>

                        <div class="mt-1 text-sm text-slate-500">
                            {{ $version->version_name }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">
                        Источники не выбраны.
                    </p>
                @endforelse
            </div>
        </section>

      <section class="rounded-2xl border border-blue-200 bg-blue-50 p-6">

    <div class="flex items-start justify-between gap-4">
        <div>

            <h2 class="text-lg font-bold text-slate-900">
                Результат анализа
            </h2>

            @if ($analysis->status === 'draft')
                <p class="mt-2 text-sm text-slate-600">
                    Анализ подготовлен к запуску. Проверьте документ, нормативную базу и поручение ИИ.
                </p>
            @elseif ($analysis->status === 'processing')
                <p class="mt-2 text-sm text-slate-600">
                    Выполняется юридический анализ...
                </p>
            @elseif ($analysis->status === 'failed')
                <p class="mt-2 text-sm text-red-700">
                    При выполнении анализа произошла ошибка.
                </p>
            @endif
        </div>

        @if (in_array($analysis->status, ['draft', 'failed']))
            <form
                method="POST"
                action="{{ route('analyses.run', $analysis) }}"
            >
                @csrf

                <button
                    type="submit"
                    class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                >
                    Запустить анализ
                </button>
            </form>
        @endif
    </div>

    @if ($analysis->status === 'completed')

        <div class="mt-6 border-t border-blue-200 pt-6">

            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Итоговая оценка
            </div>

            <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-800">
                {{ data_get($analysis->settings, 'overall_assessment') }}
            </div>

            <div class="mt-6 text-xs font-semibold uppercase tracking-wide text-slate-500">
                Резюме
            </div>

            <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-800">
                {{ $analysis->summary }}
            </div>

        </div>

    @endif

</section>

@if ($analysis->status === 'completed')

    <section class="space-y-4">

        <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold text-slate-900">
                Выявленные замечания
            </h2>

            <span class="text-sm text-slate-500">
                {{ $analysis->findings->count() }}
            </span>
        </div>

        @forelse ($analysis->findings as $finding)

            <article class="rounded-2xl border border-slate-200 bg-white p-6">

                <div class="flex flex-wrap items-center gap-3">

                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase text-slate-600">
                        {{ $finding->severity }}
                    </span>

                    <span class="text-xs text-slate-500">
                        Уверенность: {{ $finding->confidence_score }}%
                    </span>

                </div>

                <h3 class="mt-4 text-lg font-bold text-slate-900">
                    {{ $finding->title }}
                </h3>

                <div class="mt-4 text-sm leading-7 text-slate-700">
                    {{ $finding->description }}
                </div>

                @if ($finding->legal_basis)
                    <div class="mt-5">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Правовое основание
                        </div>

                        <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                            {{ $finding->legal_basis }}
                        </div>
                    </div>
                @endif

                @if ($finding->recommendation)
                    <div class="mt-5">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Рекомендация
                        </div>

                        <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                            {{ $finding->recommendation }}
                        </div>
                    </div>
                @endif

                @if ($finding->recommended_text)
                    <div class="mt-5 rounded-xl bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Рекомендуемая редакция
                        </div>

                        <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-800">
                            {{ $finding->recommended_text }}
                        </div>
                    </div>
                @endif

                @if ($finding->justification)
                    <div class="mt-5">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Обоснование
                        </div>

                        <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                            {{ $finding->justification }}
                        </div>
                    </div>
                @endif

            </article>

        @empty

            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-sm text-emerald-800">
                Существенных замечаний по представленным материалам не выявлено.
            </div>

        @endforelse

    </section>

@endif

    </div>
</x-layouts.app>
