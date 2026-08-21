<x-layouts.app
    title="Новое рабочее дело — AI DDU Assistant"
    heading="Новое рабочее дело"
    description="Создайте рабочее пространство для анализа проекта нормативного правового акта"
>
    <div class="max-w-3xl">

        <form
            method="POST"
            action="{{ route('workspaces.store') }}"
            class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6"
        >
            @csrf

            <div>
                <label class="block text-sm font-semibold text-slate-700">
                    Название рабочего дела
                </label>

                <input
                    type="text"
                    name="title"
                    value="{{ old('title') }}"
                    class="mt-2 w-full rounded-xl border-slate-300"
                    placeholder="Например: Изменения в Правила №151"
                    required
                >

                @error('title')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700">
                    Описание
                </label>

                <textarea
                    name="description"
                    rows="5"
                    class="mt-2 w-full rounded-xl border-slate-300"
                    placeholder="Кратко опишите задачу рабочего дела"
                >{{ old('description') }}</textarea>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700">
                    Категория
                </label>

                <select
                    name="category"
                    class="mt-2 w-full rounded-xl border-slate-300"
                    required
                >
                    <option value="shared_construction">Долевое строительство</option>
                    <option value="renovation">Реновация</option>
                    <option value="housing">Жилищная политика</option>
                    <option value="legislation">Законодательство</option>
                    <option value="other">Другое</option>
                </select>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-500"
                >
                    Создать рабочее дело
                </button>

                <a
                    href="{{ route('workspaces.index') }}"
                    class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700"
                >
                    Отмена
                </a>
            </div>
        </form>

    </div>
</x-layouts.app>
