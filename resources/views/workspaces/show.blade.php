<x-layouts.app
    :title="$workspace->title . ' — AI DDU Assistant'"
    :heading="$workspace->title"
    :description="$workspace->reference_number"
>
    <div class="space-y-8">

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-sm text-slate-500">
                        Статус
                    </div>

                    <div class="mt-1 font-semibold text-slate-900">
                        {{ $workspace->status }}
                    </div>
                </div>

                <div>
                    <div class="text-sm text-slate-500">
                        Категория
                    </div>

                    <div class="mt-1 font-semibold text-slate-900">
                        {{ $workspace->category }}
                    </div>
                </div>

                <div>
                    <div class="text-sm text-slate-500">
                        Создано
                    </div>

                    <div class="mt-1 font-semibold text-slate-900">
                        {{ $workspace->created_at->format('d.m.Y H:i') }}
                    </div>
                </div>
            </div>

            @if ($workspace->description)
                <div class="mt-6 border-t border-slate-100 pt-6">
                    <p class="text-sm leading-6 text-slate-600">
                        {{ $workspace->description }}
                    </p>
                </div>
            @endif
        </section>

        <section>
            <h2 class="text-xl font-bold text-slate-900">
                Быстрые действия
            </h2>

            <div class="mt-4 grid gap-4 md:grid-cols-3">

                <a href="{{ route('documents.create', $workspace) }}" class="rounded-2xl border border-slate-200 bg-white p-5 hover:border-blue-300">
                    <div class="text-sm font-semibold text-blue-600">
                        01
                    </div>
                    <h3 class="mt-2 font-bold text-slate-900">
                        Добавить документ
                    </h3>
                    <p class="mt-2 text-sm text-slate-500">
                        Загрузить файл или ввести предлагаемую норму вручную.
                    </p>
                </a>

<a href="{{ route('workspaces.sources', $workspace) }}" class="rounded-2xl border border-slate-200 bg-white p-5 hover:border-blue-300">                    <div class="text-sm font-semibold text-violet-600">
                        02
                    </div>
                    <h3 class="mt-2 font-bold text-slate-900">
                        Добавить источник
                    </h3>
                    <p class="mt-2 text-sm text-slate-500">
                        Подключить нормативный акт из нормативной базы.
                    </p>
                </a>

                <a href="#" class="rounded-2xl border border-slate-200 bg-white p-5 hover:border-blue-300">
                    <div class="text-sm font-semibold text-emerald-600">
                        03
                    </div>
                    <h3 class="mt-2 font-bold text-slate-900">
                        Новый анализ
                    </h3>
                    <p class="mt-2 text-sm text-slate-500">
                        Запустить юридическую проверку выбранного документа.
                    </p>
                </a>

            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="text-sm text-slate-500">Документы</div>
                <div class="mt-2 text-3xl font-bold text-slate-900">
                    {{ $workspace->documents->count() }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="text-sm text-slate-500">Источники</div>
                <div class="mt-2 text-3xl font-bold text-slate-900">
                    {{ $workspace->sources->count() }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="text-sm text-slate-500">Анализы</div>
                <div class="mt-2 text-3xl font-bold text-slate-900">
                    {{ $workspace->analyses->count() }}
                </div>
            </div>
        </section>

    </div>
</x-layouts.app>
