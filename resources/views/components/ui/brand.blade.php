@props(['compact' => false, 'dark' => false])

<span {{ $attributes->class(['flex min-w-0 items-center gap-3']) }}>
    <span @class([
        'grid shrink-0 place-items-center rounded-xl bg-blue-600 font-extrabold text-white',
        'h-9 w-9 text-xs' => $compact,
        'h-10 w-10 text-sm' => ! $compact,
    ])>ПИИ</span>
    <span class="min-w-0">
        <span @class(['block truncate font-bold', 'text-white' => $dark, 'text-slate-950' => ! $dark])>Правовой ИИ</span>
        <span @class(['mt-0.5 block truncate text-xs', 'text-slate-400' => $dark, 'text-slate-500' => ! $dark])>Анализ и подготовка НПА</span>
    </span>
</span>
