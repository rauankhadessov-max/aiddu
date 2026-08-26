<x-layouts.app title="История анализов — Правовой ИИ" heading="История анализов" description="Юридические анализы, поправки и сформированные документы">
    <div class="space-y-5">
        @if (session('success'))
            <x-ui.flash :message="session('success')" />
        @endif
        @if (session('error'))
            <x-ui.flash type="error" :message="session('error')" />
        @endif

        <div class="ui-card-compact">
            <form method="GET" action="{{ route('analyses.index') }}" class="grid gap-3 lg:grid-cols-[minmax(240px,1fr)_minmax(190px,0.65fr)_minmax(170px,0.5fr)_auto] lg:items-end">
                <div>
                    <label for="analysis-search" class="sr-only">Поиск по анализам</label>
                    <input id="analysis-search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" placeholder="Поиск по анализам и рабочим делам" class="ui-input">
                </div>
                <div>
                    <label for="analysis-workspace" class="sr-only">Рабочее дело</label>
                    <select id="analysis-workspace" name="workspace" class="ui-input">
                        <option value="">Все рабочие дела</option>
                        @foreach ($workspaces as $workspace)
                            <option value="{{ $workspace->id }}" @selected((string) ($filters['workspace'] ?? '') === (string) $workspace->id)>{{ $workspace->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="analysis-status" class="sr-only">Статус анализа</label>
                    <select id="analysis-status" name="status" class="ui-input">
                        <option value="">Все статусы</option>
                        <option value="draft" @selected(($filters['status'] ?? '') === 'draft')>Черновик</option>
                        <option value="processing" @selected(($filters['status'] ?? '') === 'processing')>Выполняется</option>
                        <option value="completed" @selected(($filters['status'] ?? '') === 'completed')>Завершён</option>
                        <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>Требует внимания</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="ui-btn-secondary flex-1 lg:flex-none">Найти</button>
                    @if (filled($filters['search'] ?? null) || filled($filters['workspace'] ?? null) || filled($filters['status'] ?? null))
                        <a href="{{ route('analyses.index') }}" class="ui-btn-secondary" aria-label="Сбросить фильтры">Сбросить</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="flex justify-end">
            <a href="{{ route('analyses.workflow.create') }}" class="ui-btn-primary">+ Новый анализ</a>
        </div>

        @if ($analyses->isNotEmpty())
            <div class="ui-table-shell hidden overflow-x-auto lg:block">
                <table class="ui-table min-w-[1080px]">
                    <thead>
                        <tr>
                            <th scope="col">Анализ</th>
                            <th scope="col">Рабочее дело</th>
                            <th scope="col">Дата</th>
                            <th scope="col">Статус</th>
                            <th scope="col">Поправки</th>
                            <th scope="col">НПА</th>
                            <th scope="col">Документы</th>
                            <th scope="col" class="text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($analyses as $analysis)
                            @php
                                $canonicalArtifacts = $analysis->draftPackage?->canonicalArtifacts ?? collect();
                                $comparativeTable = $canonicalArtifacts->firstWhere('artifact_type', 'comparative_table');
                                $draftNpa = $canonicalArtifacts->firstWhere('artifact_type', 'draft_npa');
                                $statusClass = match ($analysis->status) {
                                    'completed' => 'ui-badge-success',
                                    'processing' => 'ui-badge-warning',
                                    'failed' => 'ui-badge-danger',
                                    default => 'ui-badge-default',
                                };
                            @endphp
                            <tr>
                                <td class="max-w-xs"><div class="font-semibold leading-5 text-slate-900">{{ $analysis->title ?: 'Без названия' }}</div></td>
                                <td class="max-w-[220px] text-slate-700">{{ $analysis->workspace?->title ?: 'Рабочее дело не указано' }}</td>
                                <td class="whitespace-nowrap text-slate-600">
                                    <div>{{ $analysis->created_at?->format('d.m.Y') }}</div>
                                    <div class="text-xs text-slate-400">{{ $analysis->created_at?->format('H:i') }}</div>
                                </td>
                                <td><span class="{{ $statusClass }}">{{ $analysis->statusLabel() }}</span></td>
                                <td class="text-slate-700">{{ $analysis->amendments_count }}</td>
                                <td class="text-slate-700">{{ $analysis->sources_count }}</td>
                                <td>
                                    <div class="flex flex-col items-start gap-1.5 text-xs font-semibold">
                                        @if ($comparativeTable)
                                            <a href="{{ route('artifacts.show', $comparativeTable) }}" class="text-blue-700 hover:text-blue-900 hover:underline">Сравнительная таблица</a>
                                        @endif
                                        @if ($draftNpa)
                                            <a href="{{ route('artifacts.show', $draftNpa) }}" class="text-blue-700 hover:text-blue-900 hover:underline">Проект НПА</a>
                                        @endif
                                        @if (! $comparativeTable && ! $draftNpa)
                                            <span class="font-normal text-slate-400">—</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($analysis->status === 'draft')
                                            <a href="{{ route('analyses.workflow.edit', $analysis) }}" class="ui-btn-primary">Продолжить</a>
                                        @else
                                            <a href="{{ route('analyses.show', $analysis) }}" class="ui-btn-secondary">Открыть</a>
                                        @endif
                                        <details class="relative">
                                            <summary class="ui-btn-secondary list-none px-3 text-lg leading-none [&::-webkit-details-marker]:hidden" aria-label="Действия с анализом {{ $analysis->title ?: 'Без названия' }}">⋯</summary>
                                            <div class="ui-action-menu">
                                                @if ($analysis->status === 'processing')
                                                    <span class="block cursor-not-allowed rounded-lg px-3 py-2 text-sm font-medium text-slate-400" title="Дождитесь завершения анализа">Удалить</span>
                                                @else
                                                    <button type="button" x-data x-on:click.prevent="$dispatch('open-modal', 'delete-analysis-{{ $analysis->id }}')" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-red-700 hover:bg-red-50">Удалить</button>
                                                @endif
                                            </div>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="space-y-3 lg:hidden">
                @foreach ($analyses as $analysis)
                    @php
                        $canonicalArtifacts = $analysis->draftPackage?->canonicalArtifacts ?? collect();
                        $comparativeTable = $canonicalArtifacts->firstWhere('artifact_type', 'comparative_table');
                        $draftNpa = $canonicalArtifacts->firstWhere('artifact_type', 'draft_npa');
                        $statusClass = match ($analysis->status) {
                            'completed' => 'ui-badge-success',
                            'processing' => 'ui-badge-warning',
                            'failed' => 'ui-badge-danger',
                            default => 'ui-badge-default',
                        };
                    @endphp
                    <article class="ui-card-compact">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="font-bold leading-5 text-slate-900">{{ $analysis->title ?: 'Без названия' }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ $analysis->workspace?->title ?: 'Рабочее дело не указано' }}</p>
                            </div>
                            <span class="{{ $statusClass }}">{{ $analysis->statusLabel() }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-3 gap-3 border-y border-slate-100 py-3 text-sm">
                            <div><dt class="text-xs text-slate-400">Дата</dt><dd class="mt-1 font-medium text-slate-700">{{ $analysis->created_at?->format('d.m.Y') }}</dd></div>
                            <div><dt class="text-xs text-slate-400">Поправки</dt><dd class="mt-1 font-medium text-slate-700">{{ $analysis->amendments_count }}</dd></div>
                            <div><dt class="text-xs text-slate-400">НПА</dt><dd class="mt-1 font-medium text-slate-700">{{ $analysis->sources_count }}</dd></div>
                        </dl>
                        @if ($comparativeTable || $draftNpa)
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs font-semibold">
                                @if ($comparativeTable)
                                    <a href="{{ route('artifacts.show', $comparativeTable) }}" class="text-blue-700 hover:underline">Сравнительная таблица</a>
                                @endif
                                @if ($draftNpa)
                                    <a href="{{ route('artifacts.show', $draftNpa) }}" class="text-blue-700 hover:underline">Проект НПА</a>
                                @endif
                            </div>
                        @endif
                        <div class="mt-4 flex items-center gap-2">
                            @if ($analysis->status === 'draft')
                                <a href="{{ route('analyses.workflow.edit', $analysis) }}" class="ui-btn-primary flex-1">Продолжить</a>
                            @else
                                <a href="{{ route('analyses.show', $analysis) }}" class="ui-btn-secondary flex-1">Открыть</a>
                            @endif
                            <details class="relative">
                                <summary class="ui-btn-secondary list-none px-3 text-lg leading-none [&::-webkit-details-marker]:hidden" aria-label="Действия с анализом {{ $analysis->title ?: 'Без названия' }}">⋯</summary>
                                <div class="ui-action-menu">
                                    @if ($analysis->status === 'processing')
                                        <span class="block cursor-not-allowed rounded-lg px-3 py-2 text-sm font-medium text-slate-400">Удалить</span>
                                    @else
                                        <button type="button" x-data x-on:click.prevent="$dispatch('open-modal', 'delete-analysis-{{ $analysis->id }}')" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-red-700 hover:bg-red-50">Удалить</button>
                                    @endif
                                </div>
                            </details>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-5">{{ $analyses->onEachSide(1)->links() }}</div>
        @else
            <x-ui.empty-state
                :title="filled($filters['search'] ?? null) || filled($filters['workspace'] ?? null) || filled($filters['status'] ?? null) ? 'Ничего не найдено' : 'Анализов пока нет'"
                :description="filled($filters['search'] ?? null) || filled($filters['workspace'] ?? null) || filled($filters['status'] ?? null) ? 'Измените параметры поиска или сбросьте фильтры.' : 'Создайте первый анализ в единой форме.'"
            >
                @if (filled($filters['search'] ?? null) || filled($filters['workspace'] ?? null) || filled($filters['status'] ?? null))
                    <a href="{{ route('analyses.index') }}" class="ui-btn-secondary">Сбросить фильтры</a>
                @else
                    <a href="{{ route('analyses.workflow.create') }}" class="ui-btn-primary">Новый анализ</a>
                @endif
            </x-ui.empty-state>
        @endif

        @foreach ($analyses as $analysis)
            @if ($analysis->status !== 'processing')
                <x-modal name="delete-analysis-{{ $analysis->id }}" maxWidth="md" focusable>
                    <div class="p-6">
                        <h2 class="text-lg font-bold text-slate-900">Удалить анализ?</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-600">Будут удалены результаты анализа и сформированные документы.<br>Исходные данные рабочего дела и нормативная база сохранятся.</p>
                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button" x-on:click="$dispatch('close')" class="ui-btn-secondary">Отмена</button>
                            <form method="POST" action="{{ route('analyses.destroy', $analysis) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ui-btn-danger">Удалить</button>
                            </form>
                        </div>
                    </div>
                </x-modal>
            @endif
        @endforeach
    </div>
</x-layouts.app>
