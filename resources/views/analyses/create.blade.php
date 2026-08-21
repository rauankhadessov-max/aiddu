<x-layouts.app
    title="Новый анализ — AI DDU Assistant"
    heading="Новый анализ"
    :description="$document->title"
>
    <div class="max-w-5xl">

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('analyses.store', $document) }}"
            class="space-y-6"
        >
            @csrf

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Документ</div>
                <div class="mt-2 text-lg font-bold text-slate-900">{{ $document->title }}</div>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Действующая редакция
                    </div>
                    <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-800">
                        {{ $document->current_text ?: 'Не указана' }}
                    </div>
                </section>

                <section class="rounded-2xl border border-blue-200 bg-blue-50 p-6">
                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-600">
                        Предлагаемая редакция
                    </div>
                    <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-800">
                        {{ $document->proposed_text ?: 'Не указана' }}
                    </div>
                </section>
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-bold">Поручение ИИ</h2>
                <div class="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">
                    {{ $document->analysis_instruction }}
                </div>
            </section>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">

                <div>
                    <h2 class="text-lg font-bold text-slate-900">
                        Нормативные источники
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        Выберите редакции НПА, которые ИИ должен использовать при анализе.
                    </p>
                </div>

                <div class="mt-5 space-y-3">

                    @forelse ($sourceVersions as $version)
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4 hover:border-blue-300">

                            <input
                                type="checkbox"
                                name="source_versions[]"
                                value="{{ $version->id }}"
                                @checked(in_array($version->id, old('source_versions', [])))
                                class="mt-1 rounded border-slate-300"
                            >

                            <div>
                                <div class="font-semibold text-slate-900">
                                    {{ $version->source->title }}
                                </div>

                                <div class="mt-1 text-sm text-slate-500">
                                    {{ $version->version_name }}

                                    @if ($version->effective_date)
                                        · с {{ $version->effective_date->format('d.m.Y') }}
                                    @endif
                                </div>
                            </div>

                        </label>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">
                            Для этого рабочего дела пока нет подключённых редакций НПА.
                            <a href="{{ route('workspaces.sources', $document->workspace) }}" class="font-semibold text-blue-600">
                                Подключить источники
                            </a>
                        </div>
                    @endforelse

                </div>

            </div>

            <div class="flex gap-3">
                <button
                    type="submit"
                    @disabled($sourceVersions->isEmpty())
                    class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"
                >
                    Создать анализ
                </button>

                <a
                    href="{{ route('documents.show', $document) }}"
                    class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
                >
                    Отмена
                </a>
            </div>

        </form>

    </div>
</x-layouts.app>
