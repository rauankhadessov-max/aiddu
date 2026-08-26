@php
    $editing = isset($analysis);
    $sourceIds = old('source_versions', $editing ? $analysis->sourceVersions->pluck('id')->all() : []);
    $prefill = $document ?? ($analysis->document ?? null);
@endphp

<x-layouts.app
    :title="($editing ? 'Продолжить анализ' : 'Новый анализ').' — Правовой ИИ'"
    :heading="$editing ? 'Продолжить анализ' : 'Новый анализ'"
    description="Заполните исходные данные, выберите нормативную базу и запустите юридический анализ"
>
    <div class="mx-auto max-w-6xl space-y-4">
        @if (session('success'))
            <x-ui.flash :message="session('success')" />
        @endif

        @if ($errors->any())
            <div class="ui-flash-error">
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
                class="space-y-4"
            >
                @csrf
                @if ($editing)
                    @method('PATCH')
                @endif

                <section class="ui-card p-5 sm:p-5">
                    <h2 class="text-lg font-bold text-slate-900">Исходные данные</h2>
                    <p class="mt-1 text-sm text-slate-500">Для разработки поправок только по поручению действующую и предлагаемую редакции можно оставить пустыми.</p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="workspace_id" class="text-sm font-semibold text-slate-700">Рабочее дело</label>
                            <select id="workspace_id" name="workspace_id" class="ui-input mt-1.5" required>
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
                            <input id="title" name="title" type="text" value="{{ old('title', $editing ? $analysis->title : $prefill?->title) }}" class="ui-input mt-1.5" required>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div>
                            <label for="current_text" class="text-sm font-semibold text-slate-700">Действующая редакция</label>
                            <textarea id="current_text" name="current_text" rows="9" class="ui-input mt-1.5 resize-y leading-6">{{ old('current_text', $prefill?->current_text) }}</textarea>
                        </div>
                        <div>
                            <label for="proposed_text" class="text-sm font-semibold text-slate-700">Предлагаемая редакция</label>
                            <textarea id="proposed_text" name="proposed_text" rows="9" class="ui-input mt-1.5 resize-y leading-6">{{ old('proposed_text', $prefill?->proposed_text) }}</textarea>
                        </div>
                    </div>
                </section>

                <section class="ui-card p-5 sm:p-5">
                    <h2 class="text-lg font-bold text-slate-900">Поручение ИИ</h2>
                    <p class="mt-1 text-sm text-slate-500">Опишите правовой результат, который необходимо проверить или разработать.</p>
                    <label for="analysis_instruction" class="sr-only">Поручение ИИ</label>
                    <textarea id="analysis_instruction" name="analysis_instruction" rows="5" class="ui-input mt-4 resize-y leading-6">{{ old('analysis_instruction', $editing ? $analysis->instruction : $prefill?->analysis_instruction) }}</textarea>
                </section>

                <section class="ui-card p-5 sm:p-5">
                    <h2 class="text-lg font-bold text-slate-900">Нормативная база</h2>
                    <p class="mt-1 text-sm text-slate-500">Показываются только НПА, подключённые к выбранному рабочему делу.</p>
                    <p class="mt-2 rounded-lg bg-blue-50 px-3 py-2 text-sm font-medium text-blue-800">Если НПА не выбраны вручную, анализ проводится автоматически по нормативной базе рабочего дела.</p>

                    <div class="mt-4">
                        @foreach ($workspaces as $workspace)
                            @php
                                $globalSources = $workspace->sources->whereNull('user_id');
                                $personalSources = $workspace->sources->where('user_id', auth()->id());
                            @endphp
                            <div data-workspace-sources="{{ $workspace->id }}" class="grid gap-5 lg:grid-cols-2 {{ (string) $selectedWorkspaceId === (string) $workspace->id ? '' : 'hidden' }}">
                                <section>
                                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-600">Глобальные НПА</h3>
                                    <div class="mt-2 space-y-2" data-source-list="global">
                                        @foreach ($globalSources as $source)
                                            @include('analyses.partials.source-card', ['source' => $source, 'sourceIds' => $sourceIds])
                                        @endforeach
                                        <p data-source-empty="global" class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500 {{ $globalSources->isNotEmpty() ? 'hidden' : '' }}">Глобальные НПА к этому рабочему делу пока не подключены.</p>
                                    </div>
                                </section>

                                <section>
                                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-600">Мои НПА</h3>
                                    <div class="mt-2 space-y-2" data-source-list="personal">
                                        @foreach ($personalSources as $source)
                                            @include('analyses.partials.source-card', ['source' => $source, 'sourceIds' => $sourceIds])
                                        @endforeach
                                        <p data-source-empty="personal" class="py-2 text-sm text-slate-500 {{ $personalSources->isNotEmpty() ? 'hidden' : '' }}">Личные НПА к этому рабочему делу пока не добавлены.</p>
                                    </div>
                                </section>
                            </div>
                        @endforeach

                        <div id="workspace-source-placeholder" class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500 {{ $selectedWorkspaceId ? 'hidden' : '' }}">
                            Сначала выберите рабочее дело.
                        </div>
                    </div>

                    @if (App\Models\Source::supportsOwnership())
                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <button id="inline-source-toggle" type="button" @disabled(!$selectedWorkspaceId) class="ui-btn-secondary disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400">+ Добавить НПА</button>

                        <div id="inline-source-panel" class="mt-3 hidden rounded-xl border border-blue-100 bg-blue-50/50 p-4">
                            <h3 class="font-bold text-slate-900">Добавить личный НПА</h3>
                            <p class="mt-1 text-sm text-slate-600">НПА будет доступен только вам и автоматически подключён к выбранному рабочему делу.</p>

                            <div id="inline-source-feedback" class="mt-4 hidden rounded-lg px-3 py-2 text-sm"></div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label for="inline_source_title" class="text-sm font-semibold text-slate-700">Название НПА</label>
                                    <input form="inline-source-form" id="inline_source_title" name="title" type="text" required class="ui-input mt-1.5">
                                </div>
                                <div>
                                    <label for="inline_source_type" class="text-sm font-semibold text-slate-700">Вид НПА</label>
                                    <select form="inline-source-form" id="inline_source_type" name="type" class="ui-input mt-1.5">
                                        <option value="law">Закон</option><option value="code">Кодекс</option><option value="government_resolution">Постановление Правительства</option><option value="order">Приказ</option><option value="rules">Правила</option><option value="methodology">Методика</option><option value="other">Иное</option>
                                    </select>
                                </div>
                            </div>

                            <fieldset class="mt-4">
                                <legend class="text-sm font-semibold text-slate-700">Источник нормативного текста</legend>
                                <div class="mt-2 flex flex-wrap gap-4">
                                    <label class="flex items-center gap-2"><input form="inline-source-form" type="radio" name="input_method" value="docx" checked class="text-blue-600"><span class="text-sm">Загрузить DOCX</span></label>
                                    <label class="flex items-center gap-2"><input form="inline-source-form" type="radio" name="input_method" value="url" class="text-blue-600"><span class="text-sm">Указать ссылку</span></label>
                                </div>
                            </fieldset>

                            <div id="inline-docx-field" class="mt-4">
                                <label for="inline_docx_file" class="text-sm font-semibold text-slate-700">DOCX-файл</label>
                                <input form="inline-source-form" id="inline_docx_file" name="docx_file" type="file" accept=".docx" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                            </div>
                            <div id="inline-url-field" class="mt-4 hidden">
                                <label for="inline_official_url" class="text-sm font-semibold text-slate-700">Официальная ссылка</label>
                                <input form="inline-source-form" id="inline_official_url" name="official_url" type="url" class="ui-input mt-1.5" placeholder="https://adilet.zan.kz/...">
                                <p class="mt-2 text-sm text-amber-800">Для использования НПА в анализе после сохранения ссылки необходимо добавить редакцию нормативного текста.</p>
                            </div>

                            <div class="mt-4 flex gap-3">
                                <button form="inline-source-form" type="submit" class="ui-btn-primary">Добавить</button>
                                <button id="inline-source-cancel" type="button" class="ui-btn-secondary">Отмена</button>
                            </div>
                        </div>
                    </div>
                    @endif
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="submit" name="action" value="save_draft" class="ui-btn-secondary">
                        Сохранить черновик
                    </button>
                    <button type="submit" name="action" value="run" class="ui-btn-primary">
                        Запустить анализ
                    </button>
                </div>
            </form>
            <form id="inline-source-form" method="POST" enctype="multipart/form-data" class="hidden">
                @csrf
            </form>
        @endif
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const select = document.getElementById('workspace_id');
            if (!select) return;

            const groups = Array.from(document.querySelectorAll('[data-workspace-sources]'));
            const placeholder = document.getElementById('workspace-source-placeholder');
            const inlineForm = document.getElementById('inline-source-form');
            const inlinePanel = document.getElementById('inline-source-panel');
            const feedback = document.getElementById('inline-source-feedback');
            const methodInputs = Array.from(document.querySelectorAll('input[name="input_method"][form="inline-source-form"]'));
            const docxField = document.getElementById('inline-docx-field');
            const urlField = document.getElementById('inline-url-field');
            const endpointTemplate = @js(route('workspaces.sources.inline.store', ['workspace' => '__WORKSPACE__']));
            const inlineToggle = document.getElementById('inline-source-toggle');

            const render = () => {
                groups.forEach((group) => group.classList.toggle('hidden', group.dataset.workspaceSources !== select.value));
                placeholder?.classList.toggle('hidden', select.value !== '');
                if (inlineToggle) inlineToggle.disabled = select.value === '';
            };

            select.addEventListener('change', render);
            render();

            inlineToggle?.addEventListener('click', () => {
                if (!select.value) {
                    placeholder?.classList.remove('hidden');
                    return;
                }
                inlinePanel?.classList.toggle('hidden');
            });
            document.getElementById('inline-source-cancel')?.addEventListener('click', () => inlinePanel?.classList.add('hidden'));

            const renderInputMethod = () => {
                const method = methodInputs.find((input) => input.checked)?.value;
                docxField?.classList.toggle('hidden', method !== 'docx');
                urlField?.classList.toggle('hidden', method !== 'url');
            };
            methodInputs.forEach((input) => input.addEventListener('change', renderInputMethod));
            renderInputMethod();

            const appendPersonalSource = (payload) => {
                const workspaceGroup = groups.find((group) => group.dataset.workspaceSources === select.value);
                const list = workspaceGroup?.querySelector('[data-source-list="personal"]');
                if (!list) return;
                list.querySelector('[data-source-empty="personal"]')?.classList.add('hidden');

                const fieldset = document.createElement('fieldset');
                fieldset.className = 'rounded-xl border border-slate-200 bg-white p-3';
                fieldset.dataset.sourceId = String(payload.source.id);
                const legend = document.createElement('legend');
                legend.className = 'px-2 font-semibold text-slate-900';
                legend.textContent = payload.source.title;
                fieldset.appendChild(legend);

                const content = document.createElement('div');
                content.className = 'mt-2 space-y-2';
                if (payload.version) {
                    const label = document.createElement('label');
                    label.className = 'flex items-start gap-3 rounded-lg p-2 hover:bg-slate-50';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'source_versions[]';
                    checkbox.value = String(payload.version.id);
                    checkbox.checked = true;
                    checkbox.className = 'mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500';
                    const text = document.createElement('span');
                    text.className = 'block text-sm font-medium text-slate-800';
                    text.textContent = payload.version.version_name;
                    label.append(checkbox, text);
                    content.appendChild(label);
                } else {
                    const warning = document.createElement('p');
                    warning.className = 'rounded-lg bg-amber-50 p-3 text-sm text-amber-800';
                    warning.textContent = 'Нормативный текст ещё не добавлен. Этот источник пока нельзя выбрать для анализа.';
                    content.appendChild(warning);
                }
                fieldset.appendChild(content);
                list.appendChild(fieldset);
            };

            inlineForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!select.value) return;
                feedback?.classList.add('hidden');
                const submit = document.querySelector('button[form="inline-source-form"][type="submit"]');
                if (submit) submit.disabled = true;

                try {
                    const response = await fetch(endpointTemplate.replace('__WORKSPACE__', encodeURIComponent(select.value)), {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: new FormData(inlineForm),
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        const messages = payload.errors ? Object.values(payload.errors).flat() : [payload.message || 'Не удалось добавить НПА.'];
                        throw new Error(messages.join(' '));
                    }

                    appendPersonalSource(payload);
                    inlineForm.reset();
                    renderInputMethod();
                    feedback.textContent = payload.message;
                    feedback.className = 'mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700';
                } catch (error) {
                    feedback.textContent = error.message;
                    feedback.className = 'mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700';
                } finally {
                    if (submit) submit.disabled = false;
                }
            });
        });
    </script>
</x-layouts.app>
