<x-layouts.app
    title="Новый анализ — AI DDU Assistant"
    heading="Новый анализ"
    :description="$document->title"
>
    <div class="max-w-5xl">

        <form
            method="POST"
            action="{{ route('analyses.store', $document) }}"
            class="space-y-6"
        >
            @csrf

            <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-6">

                <div>
                    <label class="block text-sm font-semibold text-slate-700">
                        Название анализа
                    </label>

                    <input
                        type="text"
                        name="title"
                        value="{{ old('title', 'Юридический анализ: ' . $document->title) }}"
                        class="mt-2 w-full rounded-xl border-slate-300"
                        required
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700">
                        Тип анализа
                    </label>

                    <select
                        name="analysis_type"
                        class="mt-2 w-full rounded-xl border-slate-300"
                        required
                    >
                        <option value="comprehensive">Комплексный анализ</option>
                        <option value="legal_compliance">Соответствие законодательству</option>
                        <option value="legal_collisions">Правовые коллизии</option>
                        <option value="legal_risks">Правовые риски</option>
                        <option value="revision_drafting">Подготовка новой редакции</option>
                        <option value="comparative_table">Сравнительная таблица</option>
                        <option value="bilingual_comparison">RU / KZ</option>
                        <option value="custom">Свободное поручение</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700">
                        Поручение ИИ
                    </label>

                    <textarea
                        name="instruction"
                        rows="8"
                        class="mt-2 w-full rounded-xl border-slate-300"
                        placeholder="Например: Проверь предлагаемую редакцию на соответствие законодательству, выяви риски, предложи улучшенную редакцию и подготовь обоснование."
                        required
                    >{{ old('instruction') }}</textarea>
                </div>

            </div>

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
                            В нормативной базе пока нет редакций НПА.
                        </div>
                    @endforelse

                </div>

            </div>

            <div class="flex gap-3">
                <button
                    type="submit"
                    class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
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
