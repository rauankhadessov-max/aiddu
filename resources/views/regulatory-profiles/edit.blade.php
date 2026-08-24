<x-layouts.app
    title="Стартовый нормативный профиль — AI DDU Assistant"
    heading="Стартовый нормативный профиль"
    description="Глобальные НПА, автоматически подключаемые к рабочему делу нового пользователя"
>
    <div class="mx-auto max-w-4xl space-y-6">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h2 class="font-bold text-slate-900">{{ $profile->name }}</h2>
            <p class="mt-2 text-sm text-slate-500">Рабочее дело: {{ $profile->workspace_title }}</p>

            <form method="POST" action="{{ route('regulatory-profiles.default.update') }}" class="mt-6 space-y-3">
                @csrf
                @method('PATCH')

                @forelse ($sources as $source)
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
                        <input type="checkbox" name="source_ids[]" value="{{ $source->id }}" @checked($profile->sources->contains($source)) class="mt-1 rounded border-slate-300 text-blue-600">
                        <span><span class="block font-semibold text-slate-900">{{ $source->title }}</span><span class="text-sm text-slate-500">{{ $source->typeLabel() }}</span></span>
                    </label>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">Глобальные НПА пока не добавлены.</p>
                @endforelse

                <button type="submit" class="mt-4 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white">Сохранить состав профиля</button>
            </form>
        </section>
    </div>
</x-layouts.app>
