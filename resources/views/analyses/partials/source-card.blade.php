<fieldset class="rounded-xl border border-slate-200 bg-white p-3" data-source-id="{{ $source->id }}">
    <legend class="max-w-full truncate px-2 text-sm font-semibold text-slate-900" title="{{ $source->title }}">{{ $source->title }}</legend>
    <div class="mt-1 space-y-1">
        @forelse ($source->versions as $version)
            <label class="flex items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
                <input type="checkbox" name="source_versions[]" value="{{ $version->id }}" @checked(in_array($version->id, $sourceIds)) class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span>
                    <span class="block text-sm font-medium leading-5 text-slate-800">{{ $version->version_name }}</span>
                    @if ($version->effective_date)
                        <span class="text-xs text-slate-500">от {{ $version->effective_date->format('d.m.Y') }}</span>
                    @endif
                </span>
            </label>
        @empty
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Нормативный текст ещё не добавлен. Этот источник пока нельзя выбрать для анализа.</p>
            @can('update', $source)
                <a href="{{ route('source-versions.create', $source) }}" class="inline-flex text-sm font-semibold text-blue-600 underline">Добавить редакцию нормативного текста</a>
            @endcan
        @endforelse
    </div>
</fieldset>
