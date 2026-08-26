<x-layouts.app
    title="Новое рабочее дело — Правовой ИИ"
    heading="Новое рабочее дело"
    description="Создайте рабочее пространство для анализа проекта нормативного правового акта"
>
    <div class="max-w-3xl">

        <form
            method="POST"
            action="{{ route('workspaces.store') }}"
            class="ui-card space-y-6"
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
                    class="ui-input mt-2"
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
                    class="ui-input mt-2"
                    placeholder="Кратко опишите задачу рабочего дела"
                >{{ old('description') }}</textarea>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700">
                    Категория
                </label>

                <select
                    name="category"
                    class="ui-input mt-2"
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
                    class="ui-btn-primary"
                >
                    Создать рабочее дело
                </button>

                <a
                    href="{{ route('workspaces.index') }}"
                    class="ui-btn-secondary"
                >
                    Отмена
                </a>
            </div>
        </form>

    </div>
</x-layouts.app>
