<x-layouts.app
    title="Рабочие дела — Правовой ИИ"
    heading="Рабочие дела"
    description="Все рабочие дела по анализу и подготовке нормативных правовых актов"
>
    <div class="space-y-6">

        <div class="flex justify-end">
            <a
                href="{{ route('workspaces.create') }}"
                class="ui-btn-primary w-full sm:w-auto"
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
            <x-ui.empty-state title="Рабочих дел пока нет" description="Создайте первое рабочее дело и начните анализ документа.">
                <a href="{{ route('workspaces.create') }}" class="ui-btn-primary">Новое рабочее дело</a>
            </x-ui.empty-state>
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
