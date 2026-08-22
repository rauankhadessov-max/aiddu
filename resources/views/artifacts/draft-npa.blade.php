<x-layouts.app
    :title="$artifact->title . ' — AI DDU Assistant'"
    :heading="$artifact->title"
    :description="$artifact->draftPackage->analysis->title"
>
    <div class="draft-npa-preview space-y-6" data-presentation-version="{{ $presentation['version'] }}">
        <div class="print-controls flex flex-wrap justify-end gap-3">
            <form method="POST" action="{{ route('artifacts.docx.download', $artifact) }}">
                @csrf
                <button type="submit" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    Скачать DOCX
                </button>
            </form>
            <a href="{{ route('draft-packages.show', $artifact->draftPackage) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">
                Вернуться к пакету
            </a>
        </div>

        @if ($presentation['requirements'] !== [])
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                <div class="font-bold">Требуется заполнить пользователем</div>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($presentation['requirements'] as $requirement)
                        <li>{{ $requirement }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <article class="legal-document print-surface rounded-2xl border border-slate-200 bg-white px-8 py-10 text-slate-900 md:px-14">
            <div class="legal-project-mark text-right text-sm font-semibold">{{ $presentation['project_mark'] }}</div>

            @if ($presentation['act_type'])
                <div class="legal-act-type mt-10 text-center text-lg font-bold uppercase">{{ $presentation['act_type'] }}</div>
                <h2 class="legal-act-title mx-auto mt-6 max-w-3xl text-center text-xl font-bold leading-8">{{ $presentation['title'] }}</h2>

                @foreach ($presentation['articles'] as $article)
                    <section class="legal-draft-article mt-10">
                        <div class="font-bold">Статья {{ $article['number'] }}.</div>
                        <p class="legal-intro mt-4 leading-8">{{ $article['intro'] }}</p>

                        @foreach ($article['commands'] as $command)
                            <section class="legal-amendment-command mt-6 leading-8" data-command-number="{{ $command['number'] }}">
                                @foreach ($command['blocks'] as $index => $block)
                                    <p class="legal-command-block legal-command-block--{{ $block['kind'] }}">
                                        @if ($index === 0)<span class="legal-command-number">{{ $command['number'] }}. </span>@endif
                                        {{ $block['text'] }}
                                    </p>
                                @endforeach
                            </section>
                        @endforeach
                    </section>
                @endforeach
            @else
                <div class="mt-10 text-center text-lg font-bold">Вид принимающего акта требует уточнения</div>
            @endif
        </article>

        @if ($presentation['warnings'] !== [])
            <section class="rounded-2xl border border-amber-200 bg-white p-6">
                <h2 class="font-bold">Юридические предупреждения</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-700">
                    @foreach ($presentation['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    <style media="print">
        @page { size: A4 portrait; margin: 20mm 18mm; }
    </style>
</x-layouts.app>
