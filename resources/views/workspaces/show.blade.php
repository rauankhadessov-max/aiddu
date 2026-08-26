<x-layouts.app :title="$workspace->title . ' — Правовой ИИ'" :heading="$workspace->title" :description="$workspace->description">
    <div class="space-y-6">
        @if (session('success'))
            <x-ui.flash :message="session('success')" />
        @endif

        <section class="ui-card-compact">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-500">
                    <div><dt class="inline">Анализов:</dt> <dd class="inline font-semibold text-slate-800">{{ $workspace->analyses->count() }}</dd></div>
                    <div><dt class="inline">Подключено НПА:</dt> <dd class="inline font-semibold text-slate-800">{{ $workspace->sources->count() }}</dd></div>
                    <div><dt class="inline">Обновлено:</dt> <dd class="inline font-semibold text-slate-800">{{ $workspace->updated_at?->format('d.m.Y') }}</dd></div>
                </dl>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <a href="{{ route('analyses.workflow.create', ['workspace' => $workspace->id]) }}" class="ui-btn-primary">Новый анализ</a>
                    <a href="{{ route('workspaces.sources', $workspace) }}" class="ui-btn-secondary">Нормативная база</a>
                </div>
            </div>
        </section>

        <section class="ui-card p-0 sm:p-0" aria-labelledby="workspace-analyses-title">
            <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div>
                    <h2 id="workspace-analyses-title" class="text-lg font-bold text-slate-950">Последние анализы</h2>
                    <p class="mt-1 text-sm text-slate-500">Последние материалы этого рабочего дела.</p>
                </div>
                <a href="{{ route('analyses.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-600">Вся история</a>
            </div>

            @if ($workspace->analyses->isEmpty())
                <div class="p-6 text-sm text-slate-500">В этом рабочем деле пока нет анализов.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table min-w-[680px]">
                        <thead>
                            <tr>
                                <th>Название</th>
                                <th>Дата</th>
                                <th>Статус</th>
                                <th class="text-right">Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($workspace->analyses->take(10) as $analysis)
                                <tr>
                                    <td class="font-semibold text-slate-900">{{ $analysis->title ?: 'Без названия' }}</td>
                                    <td class="whitespace-nowrap text-slate-600">{{ $analysis->created_at?->format('d.m.Y H:i') }}</td>
                                    <td><span class="ui-badge bg-slate-100 text-slate-700">{{ $analysis->statusLabel() }}</span></td>
                                    <td class="text-right">
                                        <a href="{{ $analysis->status === 'draft' ? route('analyses.workflow.edit', $analysis) : route('analyses.show', $analysis) }}" class="text-sm font-semibold text-blue-700 hover:text-blue-600">
                                            {{ $analysis->status === 'draft' ? 'Продолжить' : 'Открыть' }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="ui-card p-0 sm:p-0" aria-labelledby="workspace-sources-title">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 id="workspace-sources-title" class="text-lg font-bold text-slate-950">Подключённая нормативная база</h2>
                    <p class="mt-1 text-sm text-slate-500">НПА, доступные для анализа в этом рабочем деле.</p>
                </div>
                <a href="{{ route('workspaces.sources', $workspace) }}" class="ui-btn-secondary">Управление нормативной базой</a>
            </div>

            @if ($workspace->sources->isEmpty())
                <div class="p-6 text-sm text-slate-500">Нормативная база пока не подключена.</div>
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($workspace->sources as $source)
                        <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900">{{ $source->title }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ $source->typeLabel() }}</div>
                            </div>
                            @if ($source->pivot->is_primary)
                                <span class="ui-badge-default">Основной НПА</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
