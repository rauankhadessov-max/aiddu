<x-layouts.app
    :title="$source->title . ' — Правовой ИИ'"
    :heading="$source->title"
    description="Нормативный источник и сохранённые редакции текста"
>
    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Вид НПА</div>
            <div class="mt-2 font-semibold text-slate-900">{{ $source->typeLabel() }}</div>

            @if ($source->official_url)
                <div class="mt-5 border-t border-slate-100 pt-5">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Официальный источник</div>
                    <a href="{{ $source->official_url }}" target="_blank" rel="noopener noreferrer" class="mt-2 block break-all text-sm font-semibold text-blue-600 underline">{{ $source->official_url }}</a>
                    @if ($source->versions->isEmpty())
                        <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800">
                            Для использования НПА в юридическом анализе добавьте редакцию нормативного текста.
                        </p>
                    @endif
                </div>
            @endif
        </section>

        <section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Редакции нормативного текста</h2>
                    <p class="mt-1 text-sm text-slate-500">Только сохранённые редакции могут использоваться в юридическом анализе.</p>
                </div>
                @can('update', $source)
                    <a href="{{ route('source-versions.create', $source) }}" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">+ Добавить редакцию</a>
                @endcan
            </div>

            <div class="mt-5 space-y-4">
                @forelse ($source->versions as $version)
                    <article class="rounded-2xl border border-slate-200 bg-white p-6">
                        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                            <div>
                                <h3 class="font-bold text-slate-900">{{ $version->version_name }}</h3>
                                <div class="mt-2 text-sm text-slate-500">Действует с: {{ $version->effective_date?->format('d.m.Y') ?? 'не указано' }}</div>
                                <div class="mt-2 text-sm text-slate-500">Объём текста: {{ number_format(mb_strlen($version->text), 0, ',', ' ') }} символов</div>
                            </div>
                            @can('update', $source)
                                <a href="{{ route('source-versions.edit', [$source, $version]) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">Редактировать</a>
                            @endcan
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8">
                        <h3 class="font-bold text-slate-900">Редакций пока нет</h3>
                        <p class="mt-2 text-sm text-slate-500">Добавьте полный текст редакции НПА.</p>
                    </div>
                @endforelse
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <a href="{{ $workspaceContext ? route('workspaces.sources', $workspaceContext) : route('sources.index') }}" class="inline-flex rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700">← Нормативная база</a>

            @can('delete', $source)
                <form method="POST" action="{{ route('sources.destroy', $source) }}" onsubmit="return confirm('Удалить НПА из доступной нормативной базы? Исторические анализы сохранятся.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-xl border border-red-200 px-5 py-3 text-sm font-semibold text-red-700 hover:bg-red-50">Удалить НПА</button>
                </form>
            @endcan
        </div>
    </div>
</x-layouts.app>
