<x-layouts.app
    :title="$artifact->title . ' — AI DDU Assistant'"
    :heading="$artifact->title"
    :description="$artifact->draftPackage->analysis->title"
>
    <div class="comparative-preview space-y-6" data-presentation-version="{{ $presentation['version'] }}">
        <div class="print-controls flex justify-end">
            <a href="{{ route('draft-packages.show', $artifact->draftPackage) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300">
                Вернуться к пакету
            </a>
        </div>

        <section class="print-surface overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="comparative-table-wrap overflow-x-auto">
                <table class="comparative-table min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            @foreach ($presentation['columns'] as $column)
                                <th class="px-4 py-3">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 align-top text-slate-800">
                        @foreach ($presentation['rows'] as $row)
                            <tr data-amendment-id="{{ $row['amendment_id'] }}">
                                <td class="px-4 py-4 font-semibold">{{ $row['number'] }}</td>
                                <td class="min-w-52 px-4 py-4 font-semibold">{{ $row['structural_element'] }}</td>
                                <td class="min-w-72 px-4 py-4">
                                    @foreach ($row['current_blocks'] as $block)
                                        <p class="legal-text-block">{{ $block }}</p>
                                    @endforeach
                                </td>
                                <td class="min-w-96 px-4 py-4">
                                    @foreach ($row['proposed_blocks'] as $block)
                                        <p class="legal-text-block">{{ $block }}</p>
                                    @endforeach
                                </td>
                                <td class="min-w-80 px-4 py-4">
                                    @forelse ($row['justification_blocks'] as $block)
                                        <p class="legal-text-block">{{ $block }}</p>
                                    @empty
                                        <p>Требуется дополнительное подтверждённое основание.</p>
                                    @endforelse
                                    @if ($row['warnings'] !== [])
                                        <div class="legal-row-warnings mt-4 border-l-2 border-amber-400 pl-3 text-amber-800">
                                            <div class="font-semibold">Юридические предупреждения</div>
                                            <ul class="mt-1 list-disc space-y-1 pl-4">
                                                @foreach ($row['warnings'] as $warning)
                                                    <li>{{ $warning }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

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
        @page { size: A4 landscape; margin: 10mm; }
    </style>
</x-layouts.app>
