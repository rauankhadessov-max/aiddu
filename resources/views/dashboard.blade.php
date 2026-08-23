<x-layouts.app
    title="Главная — AI DDU Assistant"
    heading="Главная"
    description="Рабочая панель правового анализа нормативных правовых актов"
>
    <div class="space-y-8">

        <section class="overflow-hidden rounded-3xl bg-slate-950 px-8 py-10 text-white">
            <div class="grid gap-8 lg:grid-cols-[1.25fr_0.75fr] lg:items-center">
                <div>
                    <span class="inline-flex rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-slate-200">
                        LegalTech · Казахстан
                    </span>

                    <h2 class="mt-5 max-w-3xl text-4xl font-bold leading-tight">
                        Интеллектуальная платформа для анализа и подготовки НПА
                    </h2>

                    <p class="mt-5 max-w-2xl text-base leading-7 text-slate-300">
                        Проверяйте проекты нормативных правовых актов, выявляйте правовые
                        коллизии и формируйте сравнительные таблицы и проекты поправок.
                    </p>

                    <div class="mt-7 flex flex-wrap gap-3">
                        <a
                            href="{{ route('analyses.workflow.create') }}"
                            class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-500"
                        >
                            Создать новый анализ
                        </a>

                        <a
                            href="{{ route('sources.index') }}"
                            class="rounded-xl border border-white/15 bg-white/5 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
                        >
                            Открыть нормативную базу
                        </a>
                    </div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <div class="text-sm text-slate-400">
                        Текущий этап разработки
                    </div>

                    <div class="mt-5 space-y-4">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-slate-300">Авторизация</span>
                            <span class="rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-300">
                                Готово
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-slate-300">Каркас интерфейса</span>
                            <span class="rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-300">
                                Готово
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-slate-300">Юридический анализ</span>
                            <span class="rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-300">
                                MVP готов
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section>
            <div class="mb-5">
                <h2 class="text-2xl font-bold text-slate-900">
                    Основные модули
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    Архитектура AI DDU Assistant v1.0
                </p>
            </div>

            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">

                <a href="{{ route('analyses.workflow.create') }}" class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300 hover:shadow-md">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 font-bold text-blue-700">
                        01
                    </div>

                    <h3 class="mt-5 text-lg font-bold text-slate-900">
                        Новый анализ
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Выбор нормативных источников, загрузка проекта и запуск проверки.
                    </p>
                </a>

                <a href="{{ route('sources.index') }}" class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300 hover:shadow-md">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-50 font-bold text-violet-700">
                        02
                    </div>

                    <h3 class="mt-5 text-lg font-bold text-slate-900">
                        Нормативная база
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Официальные ссылки, сохранённые редакции и история обновлений НПА.
                    </p>
                </a>

                <a href="{{ route('analyses.index') }}" class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300 hover:shadow-md">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50 font-bold text-amber-700">
                        03
                    </div>

                    <h3 class="mt-5 text-lg font-bold text-slate-900">
                        История анализов
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Отчёты, планы поправок, сравнительные таблицы и проекты НПА.
                    </p>
                </a>

                <div aria-disabled="true" class="cursor-not-allowed rounded-2xl border border-slate-200 bg-slate-50 p-6 opacity-70">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 font-bold text-emerald-700">
                        04
                    </div>

                    <h3 class="mt-5 text-lg font-bold text-slate-900">
                        AI-консультант
                        <span class="ml-2 rounded-full bg-slate-200 px-2 py-1 text-xs font-semibold text-slate-500">Скоро</span>
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Диалог по результатам анализа и доработка юридических формулировок.
                    </p>
                </div>

                <div aria-disabled="true" class="cursor-not-allowed rounded-2xl border border-slate-200 bg-slate-50 p-6 opacity-70">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-50 font-bold text-rose-700">
                        05
                    </div>

                    <h3 class="mt-5 text-lg font-bold text-slate-900">
                        RU / KZ
                        <span class="ml-2 rounded-full bg-slate-200 px-2 py-1 text-xs font-semibold text-slate-500">Скоро</span>
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Отдельная проверка одного НПА по русской и казахской ссылкам.
                    </p>
                </div>

                <a href="{{ route('profile.edit') }}" class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-blue-300 hover:shadow-md">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 font-bold text-slate-700">
                        06
                    </div>

                    <h3 class="mt-5 text-lg font-bold text-slate-900">
                        Настройки
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Профиль пользователя и параметры работы системы.
                    </p>
                </a>

            </div>
        </section>

    </div>
</x-layouts.app>
