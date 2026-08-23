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

        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
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

        @if (blank($document->analysis_instruction))
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                <h2 class="text-lg font-bold text-slate-900">Поручение ИИ необходимо указать</h2>

                <p class="mt-2 text-sm text-slate-600">
                    Этот документ был создан ранее. Сохраните поручение ИИ, прежде чем создавать новый анализ.
                </p>

                <form
                    method="POST"
                    action="{{ route('documents.analysis-instruction.update', $document) }}"
                    class="mt-5"
                >
                    @csrf
                    @method('PATCH')

                    <label for="analysis_instruction" class="block text-sm font-semibold text-slate-700">
                        Поручение ИИ
                    </label>

                    <textarea
                        id="analysis_instruction"
                        name="analysis_instruction"
                        rows="6"
                        class="mt-2 w-full rounded-xl border-slate-300 bg-white"
                        required
                    >{{ old('analysis_instruction') }}</textarea>

                    <button
                        type="submit"
                        class="mt-4 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                    >
                        Сохранить поручение ИИ
                    </button>
                </form>
            </section>
        @else
            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-bold">Поручение ИИ</h2>

                <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                    {{ $document->analysis_instruction }}
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Анализы документа</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Все созданные анализы и их текущий статус.
                    </p>
                </div>

                @unless (blank($document->analysis_instruction))
                    <a
                        href="{{ route('analyses.workflow.create', ['document' => $document->id]) }}"
                        class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500"
                    >
                        + Новый анализ
                    </a>
                @endunless
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($document->analyses as $analysis)
                    <a
                        href="{{ route('analyses.show', $analysis) }}"
                        class="flex items-start justify-between gap-4 rounded-xl border border-slate-200 p-4 transition hover:border-blue-300"
                    >
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-900">
                                {{ $analysis->title ?: 'Юридический анализ' }}
                            </div>
                            <div class="mt-1 text-sm text-slate-500">
                                {{ $analysis->created_at?->format('d.m.Y H:i') }}
                            </div>
                        </div>

                        <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            {{ $analysis->status }}
                        </span>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">
                        Для этого документа анализы ещё не создавались.
                    </div>
                @endforelse
            </div>
        </section>

        <div class="flex gap-3">
            <a
                href="{{ route('workspaces.show', $document->workspace) }}"
                class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
            >
                ← Рабочее дело
            </a>

        </div>

    </div>
</x-layouts.app>
