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

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            @foreach ($content['columns'] as $column)
                                <th class="px-4 py-3">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 align-top text-slate-800">
                        @foreach ($content['rows'] as $row)
                            <tr>
                                <td class="px-4 py-4 font-semibold">{{ $row['number'] }}</td>
                                <td class="min-w-52 px-4 py-4 font-semibold">{{ $row['structural_element'] }}</td>
                                <td class="min-w-72 whitespace-pre-wrap px-4 py-4">{{ $row['current_text'] }}</td>
                                <td class="min-w-96 whitespace-pre-wrap px-4 py-4">{{ $row['proposed_text'] }}</td>
                                <td class="min-w-80 whitespace-pre-wrap px-4 py-4">{{ $row['justification'] ?: 'Требуется дополнительное подтверждённое основание.' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
