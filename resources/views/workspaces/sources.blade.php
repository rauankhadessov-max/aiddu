@php
    $globalSources = $workspace->sources->whereNull('user_id');
    $personalSources = $workspace->sources->where('user_id', auth()->id());
@endphp

<x-layouts.app
    :title="'Нормативная база — '.$workspace->title.' — Правовой ИИ'"
    heading="Нормативная база рабочего дела"
    :description="$workspace->title"
>
    <div class="space-y-7">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-2xl font-bold text-slate-900">{{ $workspace->title }}</h2>
                @if ($workspace->regulatoryProfile?->is_active && $workspace->regulatoryProfile->purpose === App\Models\RegulatoryProfile::NEW_USER_DEFAULT)
                    <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">По умолчанию</span>
                @endif
            </div>

            <a href="{{ route('workspaces.sources.create', $workspace) }}" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">+ Добавить НПА</a>
        </div>

        @foreach ([['title' => 'Глобальные НПА', 'sources' => $globalSources], ['title' => 'Мои НПА', 'sources' => $personalSources]] as $group)
            <section>
                <h3 class="text-lg font-bold text-slate-900">{{ $group['title'] }}</h3>
                <div class="mt-4 space-y-4">
                    @forelse ($group['sources'] as $source)
                        @php($currentVersion = $currentVersions->get($source->id))
                        <article class="rounded-2xl border border-slate-200 bg-white p-6">
                            <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-start">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $source->typeLabel() }}</div>
                                    <h4 class="mt-2 text-lg font-bold text-slate-900">{{ $source->title }}</h4>

                                    @if ($currentVersion)
                                        <div class="mt-3 text-sm text-slate-600">
                                            <span class="font-semibold">Текущая редакция:</span> {{ $currentVersion->version_name }}
                                            @if ($currentVersion->effective_date)
                                                <span class="text-slate-500">· действует с {{ $currentVersion->effective_date->format('d.m.Y') }}</span>
                                            @endif
                                        </div>
                                    @else
                                        <p class="mt-3 text-sm text-amber-700">Нормативный текст ещё не добавлен. Этот НПА пока не участвует в юридическом анализе.</p>
                                    @endif
                                </div>

                                <a href="{{ route('sources.show', ['source' => $source, 'workspace' => $workspace->id]) }}" class="shrink-0 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">Открыть</a>
                            </div>
                        </article>
                    @empty
                        <p class="py-2 text-sm text-slate-500">В этом разделе пока нет подключённых НПА.</p>
                    @endforelse
                </div>
            </section>
        @endforeach

        <a href="{{ route('sources.index') }}" class="inline-flex rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700">← К рабочим делам</a>
    </div>
</x-layouts.app>
