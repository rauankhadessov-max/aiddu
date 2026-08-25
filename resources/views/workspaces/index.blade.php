<x-layouts.app
    title="Рабочие дела — AI DDU Assistant"
    heading="Рабочие дела"
    description="Все рабочие дела по анализу и подготовке нормативных правовых актов"
>
    <div class="space-y-6">

        <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Рабочие дела</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Создавайте отдельное рабочее дело для каждого вопроса или проекта НПА.
                </p>
            </div>

            <a
                href="{{ route('workspaces.create') }}"
                class="w-full rounded-xl bg-blue-600 px-5 py-3 text-center text-sm font-semibold text-white transition hover:bg-blue-500 sm:w-auto"
            >
                + Новое рабочее дело
            </a>
        </div>

        @if (request('start') === 'analysis')
            <div class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm text-blue-800">
                <div class="font-semibold">Как создать новый анализ</div>
                <div class="mt-1">
                    Выберите рабочее дело → откройте нужный документ → нажмите «Новый анализ»
                </div>
            </div>
        @endif

        @if ($workspaces->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                <h3 class="text-lg font-semibold text-slate-900">
                    Рабочих дел пока нет
                </h3>

                <p class="mt-2 text-sm text-slate-500">
                    Создайте первое рабочее дело и начните анализ документа.
                </p>
            </div>
        @else
            <div class="grid gap-4">
                @foreach ($workspaces as $workspace)
                    <a
                        href="{{ route('workspaces.show', $workspace) }}"
                        class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300 hover:shadow-sm"
                    >
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                {{ $workspace->reference_number }}
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <h3 class="min-w-0 text-lg font-bold text-slate-900">
                                    {{ $workspace->title }}
                                </h3>

                                @if ($workspace->regulatoryProfile?->is_active && $workspace->regulatoryProfile->purpose === App\Models\RegulatoryProfile::NEW_USER_DEFAULT)
                                    <span class="inline-flex shrink-0 whitespace-nowrap rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                                        По умолчанию
                                    </span>
                                @endif
                            </div>

                            @if ($workspace->description)
                                <p class="mt-2 text-sm leading-6 text-slate-500">
                                    {{ $workspace->description }}
                                </p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

    </div>
</x-layouts.app>
