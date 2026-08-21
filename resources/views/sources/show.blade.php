<x-app-layout>

    <x-slot name="header">
        <div>
            <h2 class="text-xl font-bold text-slate-900">
                {{ $source->title }}
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Карточка нормативного источника
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {{ session('success') }}
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white p-6">

                <div class="grid gap-6 md:grid-cols-3">

                    <div>
                        <div class="text-xs uppercase text-slate-500">Вид</div>
                        <div class="mt-1 font-semibold">{{ $source->type }}</div>
                    </div>

                    <div>
                        <div class="text-xs uppercase text-slate-500">Номер</div>
                        <div class="mt-1 font-semibold">{{ $source->number ?: '—' }}</div>
                    </div>

                    <div>
                        <div class="text-xs uppercase text-slate-500">Статус</div>
                        <div class="mt-1 font-semibold">{{ $source->status }}</div>
                    </div>

                    <div>
                        <div class="text-xs uppercase text-slate-500">Дата принятия</div>
                        <div class="mt-1 font-semibold">
                            {{ $source->adoption_date?->format('d.m.Y') ?? '—' }}
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <div class="text-xs uppercase text-slate-500">Орган</div>
                        <div class="mt-1 font-semibold">
                            {{ $source->issuing_authority ?: '—' }}
                        </div>
                    </div>

                </div>

                @if ($source->description)
                    <div class="mt-6 border-t border-slate-200 pt-6 text-sm leading-7 text-slate-700">
                        {{ $source->description }}
                    </div>
                @endif

            </div>

            <div class="flex items-center justify-between">

                <div>
                    <h2 class="text-xl font-bold text-slate-900">
                        Редакции
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Версии текста нормативного правового акта.
                    </p>
                </div>

                @can('update', $source)
                    <a
                        href="{{ route('source-versions.create', $source) }}"
                        class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                    >
                        + Добавить редакцию
                    </a>
                @endcan

            </div>

            <div class="space-y-4">

                @forelse ($source->versions as $version)

                    <div class="rounded-2xl border border-slate-200 bg-white p-6">

                        <div class="flex items-start justify-between gap-4">

                            <div>
                                <h3 class="font-bold text-slate-900">
                                    {{ $version->version_name }}
                                </h3>

                                <div class="mt-2 text-sm text-slate-500">
                                    Действует с:
                                    {{ $version->effective_date?->format('d.m.Y') ?? 'не указано' }}
                                </div>
                            </div>

                            <div class="flex items-center gap-3">

                            <span class="text-xs text-slate-400">
                             ID {{ $version->id }}
                            </span>

    @can('update', $source)
        <a
            href="{{ route('source-versions.edit', [$source, $version]) }}"
            class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-blue-300"
        >
            Редактировать
        </a>
    @endcan

</div>

<div class="mt-5 grid gap-4 md:grid-cols-3">

    <div class="rounded-xl bg-slate-50 p-4">
        <div class="text-xs uppercase text-slate-500">
            Объём текста
        </div>

        <div class="mt-1 font-semibold text-slate-900">
            {{ number_format(mb_strlen($version->text), 0, ',', ' ') }} символов
        </div>
    </div>

    <div class="rounded-xl bg-slate-50 p-4">
        <div class="text-xs uppercase text-slate-500">
            Текст НПА
        </div>

        <div class="mt-1 font-semibold text-emerald-700">
            Загружен
        </div>
    </div>

    <div class="rounded-xl bg-slate-50 p-4">
        <div class="text-xs uppercase text-slate-500">
            Контрольная сумма
        </div>

        <div class="mt-1 truncate font-mono text-xs text-slate-700">
            {{ $version->hash }}
        </div>
    </div>

</div>
                @empty

                    <div class="rounded-2xl border border-slate-200 bg-white p-8">
                        <h3 class="font-bold text-slate-900">
                            Редакций пока нет
                        </h3>

                        <p class="mt-2 text-sm text-slate-500">
                            Добавьте текст действующей редакции НПА.
                        </p>
                    </div>

                @endforelse

            </div>

            <a
                href="{{ route('sources.index') }}"
                class="inline-flex rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
            >
                ← Нормативная база
            </a>

        </div>
    </div>

</x-app-layout>
