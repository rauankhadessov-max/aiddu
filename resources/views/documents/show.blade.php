<x-layouts.app
    :title="$document->title . ' — AI DDU Assistant'"
    :heading="$document->title"
    :description="$document->workspace->title"
>
    <div class="space-y-6">

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-6">

            <div class="grid gap-5 md:grid-cols-4">
                <div>
                    <div class="text-xs text-slate-500">Тип</div>
                    <div class="mt-1 font-semibold">{{ $document->document_type }}</div>
                </div>

                <div>
                    <div class="text-xs text-slate-500">Способ ввода</div>
                    <div class="mt-1 font-semibold">{{ $document->input_type }}</div>
                </div>

                <div>
                    <div class="text-xs text-slate-500">Язык</div>
                    <div class="mt-1 font-semibold">{{ $document->language }}</div>
                </div>

                <div>
                    <div class="text-xs text-slate-500">Статус</div>
                    <div class="mt-1 font-semibold">{{ $document->status }}</div>
                </div>
            </div>

        </div>

        @if ($document->content_text)
            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-bold">Полный текст документа</h2>

                <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                    {{ $document->content_text }}
                </div>
            </section>
        @endif

        @if ($document->current_text)
            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-bold">Действующая редакция</h2>

                <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                    {{ $document->current_text }}
                </div>
            </section>
        @endif

        @if ($document->proposed_text)
            <section class="rounded-2xl border border-blue-200 bg-blue-50 p-6">
                <h2 class="text-lg font-bold text-slate-900">Предлагаемая редакция</h2>

                <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                    {{ $document->proposed_text }}
                </div>
            </section>
        @endif

        <div class="flex gap-3">
            <a
                href="{{ route('workspaces.show', $document->workspace) }}"
                class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
            >
                ← Рабочее дело
            </a>

            <a
                href="{{ route('analyses.create', $document) }}"
                class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white"
            >
                Новый анализ
            </a>
        </div>

    </div>
</x-layouts.app>
