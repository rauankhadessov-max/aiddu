<x-app-layout>

    <x-slot name="header">
        <div>
            <h2 class="text-xl font-bold text-slate-900">
                Добавление НПА
            </h2>

            <p class="mt-1 text-sm text-slate-500">
                Создание нового нормативного источника.
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">

                <form method="POST" action="{{ route('sources.store') }}" class="space-y-6">

                    @csrf

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Наименование НПА *
                        </label>

                        <input
                            type="text"
                            name="title"
                            value="{{ old('title') }}"
                            required
                            class="mt-2 w-full rounded-xl border-slate-300"
                            placeholder='Закон Республики Казахстан "О долевом участии в жилищном строительстве"'
                        >

                        @error('title')
                            <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="grid gap-6 md:grid-cols-2">

                        <div>
                            <label class="text-sm font-semibold text-slate-700">
                                Вид НПА *
                            </label>

                            <select
                                name="type"
                                required
                                class="mt-2 w-full rounded-xl border-slate-300"
                            >
                                <option value="law">Закон</option>
                                <option value="code">Кодекс</option>
                                <option value="government_resolution">Постановление Правительства</option>
                                <option value="order">Приказ</option>
                                <option value="rules">Правила</option>
                                <option value="other">Иное</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-sm font-semibold text-slate-700">
                                Номер
                            </label>

                            <input
                                type="text"
                                name="number"
                                value="{{ old('number') }}"
                                class="mt-2 w-full rounded-xl border-slate-300"
                                placeholder="249"
                            >
                        </div>

                        <div>
                            <label class="text-sm font-semibold text-slate-700">
                                Дата принятия
                            </label>

                            <input
                                type="date"
                                name="adoption_date"
                                value="{{ old('adoption_date') }}"
                                class="mt-2 w-full rounded-xl border-slate-300"
                            >
                        </div>

                        <div>
                            <label class="text-sm font-semibold text-slate-700">
                                Статус *
                            </label>

                            <select
                                name="status"
                                required
                                class="mt-2 w-full rounded-xl border-slate-300"
                            >
                                <option value="active">Действует</option>
                                <option value="inactive">Утратил силу</option>
                                <option value="draft">Проект</option>
                            </select>
                        </div>

                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Орган, принявший НПА
                        </label>

                        <input
                            type="text"
                            name="issuing_authority"
                            value="{{ old('issuing_authority') }}"
                            class="mt-2 w-full rounded-xl border-slate-300"
                        >
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Официальная ссылка
                        </label>

                        <input
                            type="url"
                            name="official_url"
                            value="{{ old('official_url') }}"
                            class="mt-2 w-full rounded-xl border-slate-300"
                            placeholder="https://adilet.zan.kz/..."
                        >
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-700">
                            Описание
                        </label>

                        <textarea
                            name="description"
                            rows="4"
                            class="mt-2 w-full rounded-xl border-slate-300"
                        >{{ old('description') }}</textarea>
                    </div>

                    <div class="flex gap-3">

                        <button
                            type="submit"
                            class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500"
                        >
                            Создать НПА
                        </button>

                        <a
                            href="{{ route('sources.index') }}"
                            class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
                        >
                            Отмена
                        </a>

                    </div>

                </form>

            </div>

        </div>
    </div>

</x-app-layout>
