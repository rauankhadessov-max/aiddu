<x-layouts.app
    :title="$artifact->title . ' — AI DDU Assistant'"
    :heading="$artifact->title"
    :description="$artifact->draftPackage->analysis->title"
>
    @php($content = $artifact->content)

    <div class="space-y-6">
        <div class="flex justify-end">
            <a href="{{ route('draft-packages.show', $artifact->draftPackage) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">
                Вернуться к пакету
            </a>
        </div>

        @if (($content['requires_user_input'] ?? []) !== [])
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                <div class="font-bold">Для завершения проекта требуются данные:</div>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($content['requires_user_input'] as $field)
                        <li>{{ $field }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <article class="rounded-2xl border border-slate-200 bg-white px-8 py-10 text-slate-900 md:px-14">
            <div class="text-right text-sm font-semibold">{{ $content['project_mark'] }}</div>

            @if ($content['act_type'])
                <div class="mt-10 text-center text-lg font-bold uppercase">{{ $content['act_type'] }}</div>
                <h2 class="mx-auto mt-6 max-w-3xl text-center text-xl font-bold leading-8">{{ $content['title'] }}</h2>

                @foreach ($content['articles'] as $article)
                    <section class="mt-10">
                        <div class="font-bold">Статья {{ $article['number'] }}.</div>
                        <div class="mt-4 whitespace-pre-wrap leading-8">{{ $article['intro'] }}</div>

                        @foreach ($article['commands'] as $command)
                            <div class="mt-6 whitespace-pre-wrap leading-8">{{ $command['number'] }}. {{ $command['text'] }}</div>
                        @endforeach
                    </section>
                @endforeach
            @else
                <div class="mt-10 text-center text-lg font-bold">Вид принимающего акта требует уточнения</div>
            @endif
        </article>

        @if (($content['warnings'] ?? []) !== [])
            <section class="rounded-2xl border border-amber-200 bg-white p-6">
                <h2 class="font-bold">Предупреждения</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-700">
                    @foreach ($content['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.app>
