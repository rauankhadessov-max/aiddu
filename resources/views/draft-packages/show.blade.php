<x-layouts.app
    :title="$draftPackage->title . ' — AI DDU Assistant'"
    :heading="$draftPackage->title"
    :description="$draftPackage->analysis->title"
>
    <div class="space-y-6">
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Статус</div>
                    <div class="mt-2 font-semibold text-slate-900">Черновик для юридического согласования</div>
                </div>
                <a href="{{ route('analyses.show', $draftPackage->analysis) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">
                    Вернуться к анализу
                </a>
            </div>
        </section>

        @if (data_get($draftPackage->plan, 'requires_user_input', []) !== [])
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                <h2 class="font-bold text-amber-900">Требуются дополнительные данные</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-amber-800">
                    @foreach (data_get($draftPackage->plan, 'requires_user_input', []) as $field)
                        <li>{{ $field }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (data_get($draftPackage->plan, 'warnings', []) !== [])
            <section class="rounded-2xl border border-amber-200 bg-white p-6">
                <h2 class="font-bold text-slate-900">Предупреждения</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-700">
                    @foreach (data_get($draftPackage->plan, 'warnings', []) as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="grid gap-5 md:grid-cols-2">
            @foreach ($draftPackage->artifacts as $artifact)
                <a href="{{ route('artifacts.show', $artifact) }}" class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300 hover:shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-600">
                        {{ $artifact->artifact_type === 'comparative_table' ? 'Сравнительная таблица' : 'Проект НПА' }}
                    </div>
                    <h2 class="mt-3 text-lg font-bold text-slate-900">{{ $artifact->title }}</h2>
                    <div class="mt-5 text-sm font-semibold text-blue-700">Открыть →</div>
                </a>
            @endforeach
        </section>
    </div>
</x-layouts.app>
