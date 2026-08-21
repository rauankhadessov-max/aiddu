<x-layouts.app
    title="Новый документ — AI DDU Assistant"
    heading="Новый документ"
    :description="$workspace->title"
>
    <div class="max-w-6xl">

        <form
            method="POST"
            action="{{ route('documents.store', $workspace) }}"
            class="space-y-6"
        >
            @csrf

            <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-6">

                <div>
                    <label class="block text-sm font-semibold text-slate-700">
                        Название документа
                    </label>

                    <input
                        type="text"
                        name="title"
                        value="{{ old('title') }}"
                        class="mt-2 w-full rounded-xl border-slate-300"
                        placeholder="Например: Поправка в пункт 1 статьи 11"
                        required
                    >
                </div>

                <div class="grid gap-6 md:grid-cols-2">

                    <div>
                        <label class="block text-sm font-semibold text-slate-700">
                            Тип документа
                        </label>

                        <select
                            name="document_type"
                            class="mt-2 w-full rounded-xl border-slate-300"
                            required
                        >
                            <option value="legal_norm">Отдельная норма</option>
                            <option value="draft_law">Проект закона</option>
                            <option value="draft_order">Проект приказа</option>
                            <option value="draft_resolution">Проект постановления</option>
                            <option value="comparative_table">Сравнительная таблица</option>
                            <option value="legal_opinion">Юридическое заключение</option>
                            <option value="other">Другое</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700">
                            Язык
                        </label>

                        <select
                            name="language"
                            class="mt-2 w-full rounded-xl border-slate-300"
                            required
                        >
                            <option value="ru">Русский</option>
                            <option value="kz">Казахский</option>
                            <option value="bilingual">RU / KZ</option>
                        </select>
                    </div>

                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-2">

                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <label class="block text-sm font-semibold text-slate-700">
                        Действующая редакция
                    </label>

                    <p class="mt-1 text-sm text-slate-500">
                        Вставьте действующую редакцию нормы.
                    </p>

                    <textarea
                        name="current_text"
                        rows="14"
                        class="mt-3 w-full rounded-xl border-slate-300"
                        placeholder="Действующая редакция..."
                    >{{ old('current_text') }}</textarea>
                </div>

                <div class="rounded-2xl border border-blue-200 bg-blue-50 p-6">
                    <label class="block text-sm font-semibold text-blue-700">
                        Предлагаемая редакция
                    </label>

                    <p class="mt-1 text-sm text-slate-500">
                        Вставьте предлагаемую новую редакцию нормы.
                    </p>

                    <textarea
                        name="proposed_text"
                        rows="14"
                        class="mt-3 w-full rounded-xl border-slate-300 bg-white"
                        placeholder="Предлагаемая редакция..."
                        required
                    >{{ old('proposed_text') }}</textarea>
                </div>

            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">

                <label class="block text-sm font-semibold text-slate-700">
                    Поручение ИИ
                </label>

                <p class="mt-1 text-sm text-slate-500">
                    Укажите, что необходимо проверить, сопоставить или подготовить.
                </p>

                <textarea
                    name="analysis_instruction"
                    rows="6"
                    class="mt-3 w-full rounded-xl border-slate-300"
                    placeholder="Например: провести юридический анализ предлагаемой редакции, выявить противоречия и правовые риски, указать правовые основания и предложить юридически корректную редакцию."
                    required
                >{{ old('analysis_instruction') }}</textarea>

            </div>

            <div class="flex gap-3">

                <button
                    type="submit"
                    class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                >
                    Сохранить документ
                </button>

                <a
                    href="{{ route('workspaces.show', $workspace) }}"
                    class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
                >
                    Отмена
                </a>

            </div>

        </form>

    </div>
</x-layouts.app>
