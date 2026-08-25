@props(['title', 'description' => null])

<div {{ $attributes->class(['ui-empty-state']) }}>
    <h2 class="text-base font-bold text-slate-900">{{ $title }}</h2>
    @if ($description)
        <p class="mx-auto mt-2 max-w-xl leading-6">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5 flex flex-wrap justify-center gap-3">{{ $slot }}</div>
    @endif
</div>
