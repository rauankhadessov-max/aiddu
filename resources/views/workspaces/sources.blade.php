<x-app-layout>

    <x-slot name="header">
        <div>
            <h2 class="text-xl font-bold text-slate-900">
                Добавить источник
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Рабочее дело: {{ $workspace->title }}
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-900">
                    Нормативная база
                </h1>

                <p class="mt-1 text-sm text-slate-500">
                    Выберите нормативный источник, который нужно подключить к рабочему делу.
                </p>
            </div>

            <div class="space-y-4">

                @forelse ($sources as $source)

                    @php
                        $alreadyAttached = $workspace->sources->contains('id', $source->id);
                    @endphp

                    <div class="rounded-2xl border border-slate-200 bg-white p-6">

                        <div class="flex items-start justify-between gap-6">

                            <div class="min-w-0">

                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {{ $source->type }}
                                </div>

                                <h2 class="mt-2 text-lg font-bold text-slate-900">
                                    {{ $source->title }}
                                </h2>

                                <div class="mt-3 flex flex-wrap gap-4 text-sm text-slate-500">

                                    @if ($source->number)
                                        <span>№ {{ $source->number }}</span>
                                    @endif

                                    @if ($source->adoption_date)
                                        <span>
                                            от {{ $source->adoption_date->format('d.m.Y') }}
                                        </span>
                                    @endif

                                    <span>
                                        Редакций: {{ $source->versions->count() }}
                                    </span>

                                </div>

                            </div>

                            <div class="shrink-0">

                                @if ($alreadyAttached)

                                    <span class="inline-flex rounded-xl bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700">
                                        Уже подключен
                                    </span>

                                @else

                                    <form
                                        method="POST"
                                        action="{{ route('workspaces.sources.attach', [$workspace, $source]) }}"
                                    >
                                        @csrf

                                        <button
                                            type="submit"
                                            class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                                        >
                                            Подключить
                                        </button>
                                    </form>

                                @endif

                            </div>

                        </div>

                    </div>

                @empty

                    <div class="rounded-2xl border border-slate-200 bg-white p-8">
                        <h2 class="font-bold text-slate-900">
                            Нормативная база пуста
                        </h2>

                        <p class="mt-2 text-sm text-slate-500">
                            Сначала добавьте нормативный правовой акт в разделе «Нормативная база».
                        </p>
                    </div>

                @endforelse

            </div>

            <div class="mt-6">
                <a
                    href="{{ route('workspaces.show', $workspace) }}"
                    class="inline-flex rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
                >
                    ← Рабочее дело
                </a>
            </div>

        </div>
    </div>

</x-app-layout>
