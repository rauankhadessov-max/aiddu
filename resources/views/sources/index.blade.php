<x-app-layout>

    <x-slot name="header">
        <div>
            <h2 class="text-xl font-bold text-slate-900">
                Нормативная база
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                Нормативные правовые акты, используемые AI DDU Assistant при юридическом анализе.
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {{ session('success') }}
                </div>
            @endif

            <div class="mb-6 flex items-center justify-between">

                <div>
                    <h1 class="text-2xl font-bold text-slate-900">
                        Нормативные источники
                    </h1>

                    <p class="mt-1 text-sm text-slate-500">
                        Управление НПА и их редакциями.
                    </p>
                </div>

                <a
                    href="{{ route('sources.create') }}"
                    class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                >
                    + Добавить НПА
                </a>

            </div>

            <div class="space-y-4">

                @forelse ($sources as $source)

                    <a
                        href="{{ route('sources.show', $source) }}"
                        class="block rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300"
                    >

                        <div class="flex items-start justify-between gap-6">

                            <div>

                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {{ $source->type }}
                                </div>

                                <h2 class="mt-2 text-lg font-bold text-slate-900">
                                    {{ $source->title }}
                                </h2>

                                <div class="mt-3 flex flex-wrap gap-4 text-sm text-slate-500">

                                    @if ($source->number)
                                        <span>
                                            № {{ $source->number }}
                                        </span>
                                    @endif

                                    @if ($source->adoption_date)
                                        <span>
                                            от {{ $source->adoption_date->format('d.m.Y') }}
                                        </span>
                                    @endif

                                    @if ($source->issuing_authority)
                                        <span>
                                            {{ $source->issuing_authority }}
                                        </span>
                                    @endif

                                </div>

                            </div>

                            <div class="text-right">

                                <div class="text-2xl font-bold text-slate-900">
                                    {{ $source->versions_count }}
                                </div>

                                <div class="text-xs text-slate-500">
                                    редакций
                                </div>

                            </div>

                        </div>

                    </a>

                @empty

                    <div class="rounded-2xl border border-slate-200 bg-white p-8">
                        <h2 class="text-lg font-bold text-slate-900">
                            Нормативная база пока пуста
                        </h2>

                        <p class="mt-2 text-sm text-slate-500">
                            Добавьте первый нормативный правовой акт.
                        </p>
                    </div>

                @endforelse

            </div>

        </div>
    </div>

</x-app-layout>
