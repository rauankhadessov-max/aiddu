<x-layouts.app
    title="Главная — Правовой ИИ"
    heading="Главная"
    description="Анализ и подготовка нормативных правовых актов"
>
    <div class="space-y-6">
        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-950 px-6 py-8 text-white shadow-sm sm:px-8">
            <div class="max-w-3xl">
                <span class="ui-badge bg-white/10 text-slate-200">Правовой workspace</span>
                <h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">Начните юридический анализ</h2>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300 sm:text-base">Проверьте предлагаемую норму или подготовьте поправки по поручению с использованием подключённой нормативной базы.</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('analyses.workflow.create') }}" class="ui-btn-primary">Новый анализ</a>
                    <a href="{{ route('workspaces.index') }}" class="ui-btn border-white/15 bg-white/5 text-white hover:bg-white/10">Рабочие дела</a>
                </div>
            </div>
        </section>

        <section aria-labelledby="dashboard-actions-title">
            <h2 id="dashboard-actions-title" class="text-lg font-bold text-slate-900">Основные действия</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <a href="{{ route('sources.index') }}" class="ui-card-compact group transition hover:border-blue-300 hover:shadow-sm">
                    <div class="text-sm font-bold text-slate-900 group-hover:text-blue-700">Нормативная база</div>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Подключённые НПА и сохранённые редакции.</p>
                </a>
                <a href="{{ route('analyses.index') }}" class="ui-card-compact group transition hover:border-blue-300 hover:shadow-sm">
                    <div class="text-sm font-bold text-slate-900 group-hover:text-blue-700">История анализов</div>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Результаты, сравнительные таблицы и проекты НПА.</p>
                </a>
                <a href="{{ route('profile.edit') }}" class="ui-card-compact group transition hover:border-blue-300 hover:shadow-sm">
                    <div class="text-sm font-bold text-slate-900 group-hover:text-blue-700">Настройки</div>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Данные профиля и безопасность учётной записи.</p>
                </a>
            </div>
        </section>
    </div>
</x-layouts.app>
