<x-layouts.app
    :title="$draftPackage->title . ' — Правовой ИИ'"
    :heading="$draftPackage->title"
    :description="$draftPackage->analysis->title"
>
    <div class="space-y-6" data-presentation-version="{{ $presentation['version'] }}">
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Статус</div>
                    <div class="mt-2 font-semibold text-slate-900">Черновик для юридического согласования</div>
                </div>
                <a href="{{ route('analyses.show', $draftPackage->analysis) }}" class="print-controls rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">
                    Вернуться к анализу
                </a>
            </div>
        </section>

        @if ($presentation['requirements'] !== [])
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                <h2 class="font-bold text-amber-900">Требуется заполнить пользователем</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-amber-800">
                    @foreach ($presentation['requirements'] as $requirement)
                        <li>{{ $requirement }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($presentation['warnings'] !== [])
            <section class="rounded-2xl border border-amber-200 bg-white p-6">
                <h2 class="font-bold text-slate-900">Юридические предупреждения</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-700">
                    @foreach ($presentation['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="grid gap-5 md:grid-cols-2">
            @foreach ($draftPackage->canonicalArtifacts as $artifact)
                <article class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300 hover:shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-600">
                        {{ $artifact->artifact_type === 'comparative_table' ? 'Сравнительная таблица' : 'Проект НПА' }}
                    </div>
                    <h2 class="mt-3 text-lg font-bold text-slate-900">{{ $artifact->title }}</h2>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="{{ route('artifacts.show', $artifact) }}" class="rounded-lg border border-blue-300 px-3 py-2 text-sm font-semibold text-blue-700 hover:border-blue-500">
                            Открыть
                        </a>
                        <form method="POST" action="{{ route('artifacts.docx.download', $artifact) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                                Скачать DOCX
                            </button>
                        </form>
                    </div>
                </article>
            @endforeach
        </section>
    </div>
</x-layouts.app>
