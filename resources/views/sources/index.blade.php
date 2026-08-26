<x-layouts.app
    title="Нормативная база — Правовой ИИ"
    heading="Нормативная база"
    description="Выберите рабочее дело, нормативную базу которого нужно открыть"
>
    <div class="space-y-5">
        @if (session('success'))
            <x-ui.flash :message="session('success')" />
        @endif
        @if (session('error'))
            <x-ui.flash type="warning" :message="session('error')" />
        @endif

        <p class="text-sm leading-6 text-slate-600">Состав нормативной базы настраивается отдельно для каждого рабочего дела.</p>

        @forelse ($workspaces as $workspace)
            @php
                $isDefault = $workspace->regulatoryProfile?->is_active
                    && $workspace->regulatoryProfile->purpose === App\Models\RegulatoryProfile::NEW_USER_DEFAULT;
                $primarySource = $workspace->sources->first(fn ($source) => (bool) $source->pivot->is_primary);
                $previewSources = $workspace->sources->take(4);
                $remainingSources = max(0, $workspace->sources_count - $previewSources->count());
            @endphp

            <article class="ui-card p-5 sm:p-6" data-regulatory-workspace-card>
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.7fr)_auto] lg:items-center">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-bold text-slate-950">{{ $workspace->title }}</h2>
                            @if ($isDefault)
                                <span class="ui-badge-default">По умолчанию</span>
                            @endif
                        </div>
                        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-500">
                            <span>Подключено НПА: <strong class="text-slate-800">{{ $workspace->sources_count }}</strong></span>
                            <span>Обновлено: <strong class="text-slate-800">{{ $workspace->updated_at?->format('d.m.Y') }}</strong></span>
                        </div>
                        @if ($primarySource)
                            <p class="mt-3 text-sm text-slate-600"><span class="font-semibold text-slate-800">Основной НПА:</span> {{ $primarySource->title }}</p>
                        @endif
                    </div>

                    <div class="border-t border-slate-100 pt-4 lg:border-l lg:border-t-0 lg:py-1 lg:pl-5">
                        @if ($previewSources->isEmpty())
                            <p class="text-sm text-slate-500">НПА пока не подключены.</p>
                        @else
                            <ul class="space-y-1.5 text-sm text-slate-600">
                                @foreach ($previewSources as $source)
                                    <li class="truncate">{{ $source->title }}</li>
                                @endforeach
                            </ul>
                            @if ($remainingSources > 0)
                                <div class="mt-2 text-sm font-semibold text-blue-700">+ ещё {{ $remainingSources }}</div>
                            @endif
                        @endif
                    </div>

                    <a href="{{ route('workspaces.sources', $workspace) }}" class="ui-btn-secondary w-full whitespace-nowrap lg:w-auto">Открыть базу</a>
                </div>
            </article>
        @empty
            <x-ui.empty-state title="Рабочих дел пока нет" description="Создайте рабочее дело, чтобы сформировать его нормативную базу.">
                <a href="{{ route('workspaces.create') }}" class="ui-btn-primary">Создать рабочее дело</a>
            </x-ui.empty-state>
        @endforelse
    </div>
</x-layouts.app>
