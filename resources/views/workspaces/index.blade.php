<x-layouts.app
    title="Рабочие дела — Правовой ИИ"
    heading="Рабочие дела"
    description="Рабочие пространства для анализа и подготовки нормативных правовых актов"
>
    <div class="space-y-5">
        <div class="flex justify-end">
            <a href="{{ route('workspaces.create') }}" class="ui-btn-primary w-full sm:w-auto">+ Новое рабочее дело</a>
        </div>

        @if (request('start') === 'analysis')
            <x-ui.flash type="warning">
                <span class="font-semibold">Как создать анализ:</span>
                выберите рабочее дело и нажмите «Новый анализ».
            </x-ui.flash>
        @endif

        @forelse ($workspaces as $workspace)
            @php
                $isDefault = $workspace->regulatoryProfile?->is_active
                    && $workspace->regulatoryProfile->purpose === App\Models\RegulatoryProfile::NEW_USER_DEFAULT;
                $primarySource = $workspace->sources->first(fn ($source) => (bool) $source->pivot->is_primary);
            @endphp

            <article class="ui-card p-5 sm:p-6" data-workspace-card>
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-bold text-slate-950">{{ $workspace->title }}</h2>
                            @if ($isDefault)
                                <span class="ui-badge-default">По умолчанию</span>
                            @endif
                        </div>

                        @if ($workspace->description)
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ $workspace->description }}</p>
                        @endif

                        <dl class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-500">
                            <div class="flex items-center gap-2">
                                <dt>Анализов</dt>
                                <dd class="font-semibold text-slate-800">{{ $workspace->analyses_count }}</dd>
                            </div>
                            <div class="flex items-center gap-2">
                                <dt>Подключено НПА</dt>
                                <dd class="font-semibold text-slate-800">{{ $workspace->sources_count }}</dd>
                            </div>
                            <div class="flex items-center gap-2">
                                <dt>Обновлено</dt>
                                <dd class="font-semibold text-slate-800">{{ $workspace->updated_at?->format('d.m.Y') }}</dd>
                            </div>
                        </dl>

                        @if ($primarySource)
                            <p class="mt-3 text-sm text-slate-500">
                                <span class="font-semibold text-slate-700">Основной НПА:</span>
                                {{ $primarySource->title }}
                            </p>
                        @endif
                    </div>

                    <a href="{{ route('workspaces.show', $workspace) }}" class="ui-btn-secondary w-full shrink-0 sm:w-auto">Открыть рабочее дело</a>
                </div>
            </article>
        @empty
            <x-ui.empty-state title="Рабочих дел пока нет" description="Создайте первое рабочее дело для анализа и подготовки НПА.">
                <a href="{{ route('workspaces.create') }}" class="ui-btn-primary">Новое рабочее дело</a>
            </x-ui.empty-state>
        @endforelse
    </div>
</x-layouts.app>
