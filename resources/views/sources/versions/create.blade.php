<x-layouts.app
    title="Новая редакция НПА — Правовой ИИ"
    heading="Новая редакция НПА"
    :description="$source->title"
>

    <div class="py-8">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">

                <form
                    method="POST"
                    action="{{ route('source-versions.store', $source) }}"
                    enctype="multipart/form-data"
                    class="space-y-6"
                >

                    @csrf

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Наименование редакции *
                        </label>

                        <input
                            type="text"
                            name="version_name"
                            value="{{ old('version_name') }}"
                            required
                            class="mt-2 w-full rounded-xl border-slate-300"
                            placeholder="Редакция от 01.07.2026"
                        >
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Дата вступления редакции в силу
                        </label>

                        <input
                            type="date"
                            name="effective_date"
                            value="{{ old('effective_date') }}"
                            class="mt-2 w-full rounded-xl border-slate-300"
                        >
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Полный текст редакции *
                        </label>

                        <p class="mt-1 text-sm text-slate-500">
                            Вставьте полный официальный текст НПА. Именно этот текст будет использовать ИИ при юридическом анализе.
                        </p>

<div>
    <label class="text-sm font-semibold text-slate-700">
        Загрузить Word-файл (.docx)
    </label>

    <p class="mt-1 text-sm text-slate-500">
        Если загрузить DOCX, текст будет извлечён автоматически.
        Поле ручного ввода ниже можно оставить пустым.
    </p>

    <input
        type="file"
        name="docx_file"
        accept=".docx"
        class="mt-3 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm"
    >

    @error('docx_file')
        <div class="mt-1 text-sm text-red-600">
            {{ $message }}
        </div>
    @enderror
</div>


                        <textarea
                            name="text"
                            rows="25"
                            class="mt-3 w-full rounded-xl border-slate-300 font-mono text-sm leading-6"
                            placeholder="Вставьте текст нормативного правового акта..."
                        >{{ old('text') }}</textarea>

                        @error('text')
                            <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="flex gap-3">

                        <button
                            type="submit"
                            class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                        >
                            Сохранить редакцию
                        </button>

                        <a
                            href="{{ route('sources.show', $source) }}"
                            class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
                        >
                            Отмена
                        </a>

                    </div>

                </form>

            </div>

        </div>
    </div>

</x-layouts.app>
