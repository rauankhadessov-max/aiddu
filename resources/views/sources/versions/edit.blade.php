<x-layouts.app
    title="Редактирование редакции — Правовой ИИ"
    heading="Редактирование редакции НПА"
    :description="$source->title"
>

    <div class="py-8">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">

                <form
                    method="POST"
                    action="{{ route('source-versions.update', [$source, $version]) }}"
                    enctype="multipart/form-data"
                    class="space-y-6"
                >

                    @csrf
                    @method('PUT')

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Наименование редакции *
                        </label>

                        <input
                            type="text"
                            name="version_name"
                            value="{{ old('version_name', $version->version_name) }}"
                            required
                            class="mt-2 w-full rounded-xl border-slate-300"
                        >
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Дата вступления редакции в силу
                        </label>

                        <input
                            type="date"
                            name="effective_date"
                            value="{{ old('effective_date', optional($version->effective_date)->format('Y-m-d')) }}"
                            class="mt-2 w-full rounded-xl border-slate-300"
                        >
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Полный текст редакции *
                        </label>

<div>
    <label class="text-sm font-semibold text-slate-700">
        Загрузить Word-файл (.docx)
    </label>

    <p class="mt-1 text-sm text-slate-500">
        Если загрузить DOCX, текущий текст редакции будет заменён текстом,
        извлечённым из Word-файла.
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
                            rows="30"
                            class="mt-3 w-full rounded-xl border-slate-300 font-mono text-sm leading-6"
                        >{{ old('text', $version->text) }}</textarea>
                    </div>

                    <div class="flex gap-3">

                        <button
                            type="submit"
                            class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                        >
                            Сохранить изменения
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
