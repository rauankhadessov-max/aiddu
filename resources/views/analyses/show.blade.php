<x-layouts.app
    :title="$analysis->title . ' — Правовой ИИ'"
    :heading="$analysis->title"
    :description="$analysis->workspace?->title ?: $analysis->document?->title"
>
    <div class="mx-auto max-w-6xl space-y-5">
        @if (session('success'))
            <x-ui.flash :message="session('success')" />
        @endif

        @if (session('error'))
            <x-ui.flash type="error" :message="session('error')" />
        @endif

        <details class="ui-card-compact group">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-slate-800 [&::-webkit-details-marker]:hidden">
                <span>Исходные данные анализа</span>
                <span class="text-sm font-normal text-blue-700 group-open:hidden">Показать</span>
                <span class="hidden text-sm font-normal text-blue-700 group-open:inline">Скрыть</span>
            </summary>

            <div class="mt-4 space-y-4 border-t border-slate-100 pt-4">
                @if ($analysis->document)
                    <div class="grid gap-4 lg:grid-cols-2">
                        <section class="rounded-xl bg-slate-50 p-4">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Действующая редакция</h2>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-800">{{ $analysis->document->current_text ?: 'Не указана' }}</div>
                        </section>
                        <section class="rounded-xl bg-blue-50 p-4">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-blue-700">Предлагаемая редакция</h2>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-800">{{ $analysis->document->proposed_text ?: 'Не указана' }}</div>
                        </section>
                    </div>
                @endif

                <section>
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Поручение ИИ</h2>
                    <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ $analysis->instruction }}</div>
                </section>

                <section>
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Нормативная база анализа</h2>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse ($analysis->sourceVersions as $version)
                            <span class="ui-badge bg-slate-100 text-slate-700">{{ $version->source->title }}</span>
                        @empty
                            <span class="text-sm text-slate-500">Источники не выбраны.</span>
                        @endforelse
                    </div>
                </section>
            </div>
        </details>

        @if ($analysis->status !== 'completed')
            <section class="ui-card border-blue-200 bg-blue-50">
                <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Результат анализа</h2>
                        @if ($analysis->status === 'draft')
                            <p class="mt-1 text-sm text-slate-600">Анализ подготовлен к запуску. Проверьте исходные данные и нормативную базу.</p>
                        @elseif ($analysis->status === 'processing')
                            <p class="mt-1 text-sm text-slate-600">Выполняется юридический анализ...</p>
                        @elseif ($analysis->status === 'failed')
                            <p class="mt-1 text-sm text-red-700">При выполнении анализа произошла ошибка.</p>
                        @endif
                    </div>

                    @if (in_array($analysis->status, ['draft', 'failed']))
                        <form method="POST" action="{{ route('analyses.run', $analysis) }}">
                            @csrf
                            <button type="submit" class="ui-btn-primary">Запустить анализ</button>
                        </form>
                    @endif
                </div>
            </section>
        @else
            <section class="ui-card border-blue-200">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 font-bold text-blue-700">1</span>
                    <h2 class="text-xl font-bold text-slate-900">Итоговая оценка</h2>
                </div>
                <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-800">{{ data_get($analysis->settings, 'overall_assessment') ?: 'Итоговая оценка не сформирована.' }}</div>

                @if (data_get($analysis->settings, 'source_sufficiency') !== 'sufficient')
                    <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <div class="text-sm font-semibold text-amber-900">
                            {{ $resultPresenter->sufficiencyLabel(data_get($analysis->settings, 'source_sufficiency')) ?? 'Требуется дополнительная проверка нормативной базы.' }}
                        </div>
                        @foreach ($resultPresenter->warnings(data_get($analysis->settings, 'warnings', [])) as $warning)
                            <p class="mt-2 text-sm text-amber-800">{{ $warning }}</p>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="ui-card">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 font-bold text-slate-700">2</span>
                    <h2 class="text-xl font-bold text-slate-900">Краткое резюме</h2>
                </div>
                <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ $analysis->summary ?: 'Краткое резюме не сформировано.' }}</div>
            </section>

            <section class="space-y-3">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 font-bold text-blue-700">3</span>
                        <h2 class="text-xl font-bold text-slate-900">Предлагаемые поправки</h2>
                    </div>
                    <span class="ui-badge-default">{{ $analysis->amendments->count() }}</span>
                </div>

                @forelse ($analysis->amendments as $amendment)
                    @php
                        $targetVersion = $analysis->sourceVersions->firstWhere('id', $amendment->source_version_id);
                        $targetSource = data_get($amendment->target_snapshot, 'source_title') ?: $targetVersion?->source?->title;
                        $references = $resultPresenter->groupedReferences($amendment, $analysis->sourceVersions);
                        $warnings = $resultPresenter->warnings($amendment->warnings ?? []);
                    @endphp
                    <article class="ui-card border-blue-200" data-amendment="{{ $amendment->id }}">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Изменяемый НПА</p>
                                <h3 class="mt-1 font-bold text-slate-900">{{ $targetSource ?: 'Нормативный правовой акт' }}</h3>
                                @if ($amendment->proposed_locator)
                                    <p class="mt-1 text-sm font-semibold text-blue-700">{{ $amendment->proposed_locator }}</p>
                                @endif
                            </div>
                            @if ($amendment->confidence_score !== null)
                                <span class="text-xs text-slate-400">Уверенность: {{ $amendment->confidence_score }}%</span>
                            @endif
                        </div>

                        <div class="mt-5 grid gap-4 lg:grid-cols-2">
                            <section class="rounded-xl bg-slate-50 p-4">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Действующая редакция</h4>
                                <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-800">{{ $amendment->current_text ?: 'Отсутствует' }}</div>
                            </section>
                            <section class="rounded-xl bg-blue-50 p-4">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-blue-700">Рекомендуемая редакция</h4>
                                <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-800">{{ $amendment->proposed_text ?: 'Не сформирована' }}</div>
                            </section>
                        </div>

                        <div class="mt-5 grid gap-5 lg:grid-cols-2">
                            <section>
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Обоснование</h4>
                                <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ $amendment->justification }}</div>
                            </section>
                            <section>
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Правовое основание</h4>
                                <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ $amendment->legal_basis }}</div>
                            </section>
                        </div>

                        @if ($warnings !== [])
                            <div class="mt-5 space-y-2">
                                @foreach ($warnings as $warning)
                                    <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                        <span class="font-semibold">Предупреждение:</span> {{ $warning }}
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($references !== [])
                            <details class="mt-5 rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                                <summary class="cursor-pointer list-none text-sm font-semibold text-slate-700 [&::-webkit-details-marker]:hidden">Источники ({{ count($references) }})</summary>
                                <ul class="mt-3 space-y-2 border-t border-slate-200 pt-3 text-sm leading-6 text-slate-600">
                                    @foreach ($references as $reference)
                                        <li>{{ $reference }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </article>
                @empty
                    <div class="ui-flash-warning">Подтверждённые поправки не сформированы. Проверьте предупреждения о достаточности нормативной базы.</div>
                @endforelse
            </section>

            <section class="space-y-3">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 font-bold text-slate-700">4</span>
                        <h2 class="text-xl font-bold text-slate-900">Выявленные замечания</h2>
                    </div>
                    <span class="ui-badge">{{ $analysis->findings->count() }}</span>
                </div>

                @forelse ($analysis->findings as $finding)
                    <article class="ui-card">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="ui-badge-warning">{{ $resultPresenter->severity($finding->severity) }}</span>
                            @if ($finding->confidence_score !== null)
                                <span class="text-xs text-slate-400">Уверенность: {{ $finding->confidence_score }}%</span>
                            @endif
                        </div>
                        <h3 class="mt-3 text-lg font-bold text-slate-900">{{ $finding->title }}</h3>
                        @if ($finding->description)
                            <div class="mt-3 text-sm leading-7 text-slate-700">{{ $finding->description }}</div>
                        @endif

                        <div class="mt-5 grid gap-5 lg:grid-cols-2">
                            @if ($finding->legal_basis)
                                <section>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Правовое основание</h4>
                                    <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ $finding->legal_basis }}</div>
                                </section>
                            @endif
                            @if ($finding->recommendation)
                                <section>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Рекомендация</h4>
                                    <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ $finding->recommendation }}</div>
                                </section>
                            @endif
                        </div>

                        @if ($finding->recommended_text)
                            <section class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Рекомендуемая редакция</h4>
                                <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-800">{{ $finding->recommended_text }}</div>
                            </section>
                        @endif
                        @if ($finding->justification)
                            <section class="mt-5">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Обоснование</h4>
                                <div class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ $finding->justification }}</div>
                            </section>
                        @endif
                    </article>
                @empty
                    <div class="ui-flash-success">Существенных замечаний по представленным материалам не выявлено.</div>
                @endforelse
            </section>

            @if ($analysis->amendments->isNotEmpty())
                <section class="ui-card border-indigo-200">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-50 font-bold text-indigo-700">5</span>
                        <h2 class="text-xl font-bold text-slate-900">Пакет документов</h2>
                    </div>

                    @if ($analysis->draftPackage)
                        <p class="mt-3 text-sm text-slate-600">Документы сформированы на основании подтверждённых поправок этого анализа.</p>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @foreach ($analysis->draftPackage->canonicalArtifacts as $artifact)
                                <article class="rounded-xl border border-indigo-100 bg-indigo-50/40 p-4" data-logical-document="{{ $artifact->artifact_type }}">
                                    <h3 class="font-semibold text-slate-900">{{ $artifact->artifact_type === 'comparative_table' ? 'Сравнительная таблица' : 'Проект НПА' }}</h3>
                                    <div class="analysis-document-actions mt-3">
                                        <a href="{{ route('artifacts.show', $artifact) }}" class="analysis-artifact-open">Открыть</a>
                                        <form method="POST" action="{{ route('artifacts.docx.download', $artifact) }}">
                                            @csrf
                                            <button type="submit" class="analysis-docx-download">Скачать DOCX</button>
                                        </form>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                        <a href="{{ route('draft-packages.show', $analysis->draftPackage) }}" class="mt-4 inline-block text-sm text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-indigo-700">Обзор пакета и предупреждений</a>
                    @else
                        @if (session('draft_package_error'))
                            <div class="ui-flash-warning mt-4">{{ session('draft_package_error') }}</div>
                        @endif
                        <p class="mt-3 text-sm text-slate-600">Сформируйте сравнительную таблицу и структурированный проект НПА без повторного юридического анализа.</p>
                        <form method="POST" action="{{ route('draft-packages.store', $analysis) }}" class="mt-4">
                            @csrf
                            <button type="submit" class="ui-btn-primary">{{ session('draft_package_error') ? 'Повторить формирование' : 'Сформировать пакет документов' }}</button>
                        </form>
                    @endif
                </section>
            @endif
        @endif
    </div>
</x-layouts.app>
