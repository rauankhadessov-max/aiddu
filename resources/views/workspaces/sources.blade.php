@php
    $globalSources = $workspace->sources->whereNull('user_id');
    $personalSources = $workspace->sources->where('user_id', auth()->id());
    $isDefault = $workspace->regulatoryProfile?->is_active
        && $workspace->regulatoryProfile->purpose === App\Models\RegulatoryProfile::NEW_USER_DEFAULT;
@endphp

<x-layouts.app
    :title="'Нормативная база — '.$workspace->title.' — Правовой ИИ'"
    heading="Нормативная база рабочего дела"
    :description="$workspace->title"
>
    <div class="space-y-6">
        @if (session('success'))
            <x-ui.flash :message="session('success')" />
        @endif

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-slate-600">Подключено НПА: <strong class="text-slate-900">{{ $workspace->sources->count() }}</strong></span>
                @if ($isDefault)
                    <span class="ui-badge-default">По умолчанию</span>
                @endif
            </div>
            <a href="{{ route('workspaces.sources.create', $workspace) }}" class="ui-btn-primary w-full sm:w-auto">+ Добавить НПА</a>
        </div>

        @foreach ([['title' => 'Глобальные НПА', 'sources' => $globalSources], ['title' => 'Мои НПА', 'sources' => $personalSources]] as $group)
            <section class="ui-card p-0 sm:p-0" aria-labelledby="source-group-{{ $loop->index }}">
                <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                    <h2 id="source-group-{{ $loop->index }}" class="text-lg font-bold text-slate-950">{{ $group['title'] }}</h2>
                </div>

                @if ($group['sources']->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500 sm:px-6">В этом разделе пока нет подключённых НПА.</p>
                @else
                    <div class="overflow-x-auto" data-regulatory-table>
                        <table class="ui-table min-w-[820px]">
                            <thead>
                                <tr>
                                    <th>Документ</th>
                                    <th>Вид НПА</th>
                                    <th>Редакция</th>
                                    <th>Роль</th>
                                    <th class="text-right">Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($group['sources'] as $source)
                                    @php($currentVersion = $currentVersions->get($source->id))
                                    <tr>
                                        <td class="max-w-md font-semibold text-slate-900">{{ $source->title }}</td>
                                        <td class="text-slate-600">{{ $source->typeLabel() }}</td>
                                        <td>
                                            @if ($currentVersion)
                                                <div class="font-medium text-slate-800">{{ $currentVersion->version_name }}</div>
                                                @if ($currentVersion->effective_date)
                                                    <div class="mt-1 text-xs text-slate-500">с {{ $currentVersion->effective_date->format('d.m.Y') }}</div>
                                                @endif
                                            @else
                                                <span class="text-sm font-medium text-amber-700">Нормативный текст ещё не добавлен</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="ui-badge {{ $source->pivot->is_primary ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600' }}">
                                                {{ $source->pivot->is_primary ? 'Основной' : 'Дополнительный' }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <a href="{{ route('sources.show', ['source' => $source, 'workspace' => $workspace->id]) }}" class="text-sm font-semibold text-blue-700 hover:text-blue-600">Открыть</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach

        <a href="{{ route('sources.index') }}" class="ui-btn-secondary">← К рабочим делам</a>
    </div>
</x-layouts.app>
