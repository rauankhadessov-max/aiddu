<x-layouts.app
    title="Добавление НПА — Правовой ИИ"
    heading="Добавление НПА"
    :description="$workspace->title"
>
    <div class="mx-auto max-w-4xl">
        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <div class="font-semibold">Проверьте заполнение формы:</div>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <form method="POST" action="{{ route('workspaces.sources.store', $workspace) }}" enctype="multipart/form-data" class="space-y-7" x-data="{ inputMethod: @js(old('input_method', 'docx')) }">
                @csrf

                <div>
                    <label for="title" class="text-sm font-semibold text-slate-700">Название НПА</label>
                    <input id="title" type="text" name="title" value="{{ old('title') }}" required class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" placeholder="Наименование нормативного правового акта">
                </div>

                <div>
                    <label for="type" class="text-sm font-semibold text-slate-700">Вид НПА</label>
                    <select id="type" name="type" required class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500">
                        @foreach (['law' => 'Закон', 'code' => 'Кодекс', 'government_resolution' => 'Постановление Правительства', 'order' => 'Приказ', 'rules' => 'Правила', 'methodology' => 'Методика', 'other' => 'Иное'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                @if (auth()->user()->is_admin)
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-700">Доступность НПА</legend>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach (['global' => 'Всем пользователям', 'personal' => 'Только мне'] as $value => $label)
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-4 hover:border-blue-300">
                                    <input type="radio" name="visibility" value="{{ $value }}" @checked(old('visibility', $defaultVisibility) === $value) class="border-slate-300 text-blue-600 focus:ring-blue-500">
                                    <span class="font-medium text-slate-800">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <fieldset>
                    <legend class="text-sm font-semibold text-slate-700">Источник нормативного текста</legend>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-4 hover:border-blue-300">
                            <input type="radio" name="input_method" value="docx" x-model="inputMethod" class="border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span class="font-medium text-slate-800">Загрузить DOCX</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-4 hover:border-blue-300">
                            <input type="radio" name="input_method" value="url" x-model="inputMethod" class="border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span class="font-medium text-slate-800">Указать ссылку</span>
                        </label>
                    </div>
                </fieldset>

                <div x-show="inputMethod === 'docx'">
                    <label for="docx_file" class="text-sm font-semibold text-slate-700">DOCX-файл нормативного акта</label>
                    <p class="mt-1 text-sm text-slate-500">Текст будет извлечён автоматически и сохранён как редакция НПА.</p>
                    <input id="docx_file" type="file" name="docx_file" accept=".docx" class="mt-3 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                </div>

                <div x-show="inputMethod === 'url'">
                    <label for="official_url" class="text-sm font-semibold text-slate-700">Официальная ссылка</label>
                    <input id="official_url" type="url" name="official_url" value="{{ old('official_url') }}" class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" placeholder="https://adilet.zan.kz/...">
                    <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800">Ссылка будет сохранена как официальный источник. Для использования НПА в юридическом анализе необходимо добавить редакцию нормативного текста.</p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">Добавить НПА</button>
                    <a href="{{ route('workspaces.sources', $workspace) }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700">Отмена</a>
                </div>
            </form>
        </section>
    </div>
</x-layouts.app>
