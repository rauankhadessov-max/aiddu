@php
    $editing = isset($analysis);
    $sourceIds = old('source_versions', $editing ? $analysis->sourceVersions->pluck('id')->all() : []);
    $prefill = $document ?? ($analysis->document ?? null);
@endphp

<x-layouts.app
    :title="($editing ? 'Продолжить анализ' : 'Новый анализ').' — AI DDU Assistant'"
    :heading="$editing ? 'Продолжить анализ' : 'Новый анализ'"
    description="Заполните исходные данные, выберите нормативную базу и запустите юридический анализ"
>
    <div class="mx-auto max-w-5xl space-y-6">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <div class="font-semibold">Проверьте заполнение формы:</div>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($workspaces->isEmpty())
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                <h2 class="text-lg font-bold text-slate-900">Сначала создайте рабочее дело</h2>
                <p class="mt-2 text-sm text-slate-600">Рабочее дело объединяет анализы и подключённую нормативную базу.</p>
                <a href="{{ route('workspaces.create') }}" class="mt-4 inline-flex rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">
                    Создать рабочее дело
                </a>
            </section>
        @else
            <form
                method="POST"
                action="{{ $editing ? route('analyses.workflow.update', $analysis) : route('analyses.workflow.store') }}"
                class="space-y-6"
            >
                @csrf
                @if ($editing)
                    @method('PATCH')
                @endif

                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <label for="workspace_id" class="text-sm font-semibold text-slate-700">Рабочее дело</label>
                            <select id="workspace_id" name="workspace_id" class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" required>
                                <option value="">Выберите рабочее дело</option>
                                @foreach ($workspaces as $workspace)
                                    <option value="{{ $workspace->id }}" @selected((string) $selectedWorkspaceId === (string) $workspace->id)>
                                        {{ $workspace->title }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="title" class="text-sm font-semibold text-slate-700">Название анализа</label>
                            <input id="title" name="title" type="text" value="{{ old('title', $editing ? $analysis->title : $prefill?->title) }}" class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" required>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h2 class="text-lg font-bold text-slate-900">Исходные данные</h2>
                    <p class="mt-1 text-sm text-slate-500">Для разработки поправок только по поручению оба текста можно оставить пустыми.</p>

                    <div class="mt-5 grid gap-6 lg:grid-cols-2">
                        <div>
                            <label for="current_text" class="text-sm font-semibold text-slate-700">Действующая редакция</label>
                            <textarea id="current_text" name="current_text" rows="12" class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500">{{ old('current_text', $prefill?->current_text) }}</textarea>
                        </div>
                        <div>
                            <label for="proposed_text" class="text-sm font-semibold text-slate-700">Предлагаемая редакция</label>
                            <textarea id="proposed_text" name="proposed_text" rows="12" class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500">{{ old('proposed_text', $prefill?->proposed_text) }}</textarea>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label for="analysis_instruction" class="text-sm font-semibold text-slate-700">Поручение ИИ</label>
                        <textarea id="analysis_instruction" name="analysis_instruction" rows="5" class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500">{{ old('analysis_instruction', $editing ? $analysis->instruction : $prefill?->analysis_instruction) }}</textarea>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h2 class="text-lg font-bold text-slate-900">Нормативная база</h2>
                    <p class="mt-1 text-sm text-slate-500">Показываются только источники, подключённые к выбранному рабочему делу.</p>

                    <div class="mt-5">
                        @foreach ($workspaces as $workspace)
                            <div data-workspace-sources="{{ $workspace->id }}" class="space-y-4 {{ (string) $selectedWorkspaceId === (string) $workspace->id ? '' : 'hidden' }}">
                                @forelse ($workspace->sources as $source)
                                    <fieldset class="rounded-xl border border-slate-200 p-4">
                                        <legend class="px-2 font-semibold text-slate-900">{{ $source->title }}</legend>
                                        <div class="mt-2 space-y-2">
                                            @forelse ($source->versions as $version)
                                                <label class="flex items-start gap-3 rounded-lg p-2 hover:bg-slate-50">
                                                    <input type="checkbox" name="source_versions[]" value="{{ $version->id }}" @checked(in_array($version->id, $sourceIds)) class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                                    <span>
                                                        <span class="block text-sm font-medium text-slate-800">{{ $version->version_name }}</span>
                                                        @if ($version->effective_date)
                                                            <span class="text-xs text-slate-500">от {{ $version->effective_date->format('d.m.Y') }}</span>
                                                        @endif
                                                    </span>
                                                </label>
                                            @empty
                                                <p class="text-sm text-slate-500">У источника пока нет редакций.</p>
                                            @endforelse
                                        </div>
                                    </fieldset>
                                @empty
                                    <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">
                                        К этому рабочему делу нормативные источники пока не подключены.
                                        <a href="{{ route('workspaces.sources', $workspace) }}" class="font-semibold text-blue-600 underline">Подключить источник</a>
                                    </div>
                                @endforelse
                            </div>
                        @endforeach

                        <div id="workspace-source-placeholder" class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500 {{ $selectedWorkspaceId ? 'hidden' : '' }}">
                            Сначала выберите рабочее дело.
                        </div>
                    </div>
                </section>

                <div class="flex flex-wrap justify-end gap-3">
                    <button type="submit" name="action" value="save_draft" class="rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:border-blue-300">
                        Сохранить черновик
                    </button>
                    <button type="submit" name="action" value="run" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">
                        Запустить анализ
                    </button>
                </div>
            </form>
        @endif
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const select = document.getElementById('workspace_id');
            if (!select) return;

            const groups = Array.from(document.querySelectorAll('[data-workspace-sources]'));
            const placeholder = document.getElementById('workspace-source-placeholder');

            const render = () => {
                groups.forEach((group) => group.classList.toggle('hidden', group.dataset.workspaceSources !== select.value));
                placeholder?.classList.toggle('hidden', select.value !== '');
            };

            select.addEventListener('change', render);
            render();
        });
    </script>
</x-layouts.app>
