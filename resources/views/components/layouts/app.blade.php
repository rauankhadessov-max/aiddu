<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'AI DDU Assistant' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        .legal-text-block + .legal-text-block,
        .legal-command-block + .legal-command-block { margin-top: 0.75rem; }
        .legal-command-block--normative,
        .legal-command-block--quotation_start,
        .legal-command-block--norm_heading,
        .legal-command-block--norm_item { padding-left: 1.5rem; }

        @media print {
            html, body { background: #fff !important; color: #000 !important; }
            .app-sidebar, .app-header, .app-mobile-nav, .print-controls { display: none !important; }
            .app-shell, .app-main {
                display: block !important;
                min-height: 0 !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .print-surface { border: 0 !important; border-radius: 0 !important; box-shadow: none !important; }
            .comparative-table-wrap { overflow: visible !important; }
            .comparative-table {
                width: 100% !important;
                min-width: 0 !important;
                table-layout: fixed;
                border-collapse: collapse;
                font-size: 8.5pt;
                line-height: 1.3;
            }
            .comparative-table thead { display: table-header-group; }
            .comparative-table th, .comparative-table td {
                min-width: 0 !important;
                padding: 2.5mm !important;
                border: 0.2mm solid #777 !important;
                overflow-wrap: anywhere;
                vertical-align: top;
            }
            .comparative-table th:nth-child(1) { width: 4%; }
            .comparative-table th:nth-child(2) { width: 14%; }
            .comparative-table th:nth-child(3) { width: 24%; }
            .comparative-table th:nth-child(4) { width: 29%; }
            .comparative-table th:nth-child(5) { width: 29%; }
            .comparative-table tr { break-inside: avoid-page; }
            .legal-document {
                width: 100% !important;
                max-width: none !important;
                padding: 0 !important;
                font-family: "Times New Roman", Times, serif;
                font-size: 12pt;
                line-height: 1.5;
            }
            .legal-project-mark, .legal-act-type, .legal-act-title, .legal-command-block {
                orphans: 3;
                widows: 3;
            }
            .legal-intro, .legal-command-block { text-align: justify; }
        }
    </style>
</head>

<body class="bg-slate-100 font-sans text-slate-900 antialiased">

<div class="app-shell min-h-screen lg:flex">

    {{-- Боковая панель --}}
    <aside class="app-sidebar hidden w-72 shrink-0 flex-col bg-slate-950 text-white lg:flex">

        <div class="border-b border-white/10 px-6 py-6">
            <a href="{{ route('dashboard') }}" class="block">
                <div class="text-xl font-bold">
                    AI DDU Assistant
                </div>

                <div class="mt-1 text-xs text-slate-400">
                    Правовой анализ НПА
                </div>
            </a>
        </div>

        <nav class="flex-1 space-y-1 px-4 py-6">

            <a
                href="{{ route('workspaces.index') }}"
                class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition
                {{ request()->routeIs('workspaces.*') && request('start') !== 'analysis'
                    ? 'bg-blue-600 text-white'
                    : 'text-slate-300 hover:bg-white/10 hover:text-white' }}"
            >
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
                    01
                </span>

                <span>Рабочие дела</span>
            </a>

            <a
                href="{{ route('workspaces.index', ['start' => 'analysis']) }}"
                class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition
                {{ request()->routeIs('workspaces.index') && request('start') === 'analysis'
                    ? 'bg-blue-600 text-white'
                    : 'text-slate-300 hover:bg-white/10 hover:text-white' }}"
            >
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
                    02
                </span>

                <span>Новый анализ</span>
            </a>

            <a
                href="{{ route('sources.index') }}"
                class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition
                {{ request()->routeIs('sources.*', 'source-versions.*')
                    ? 'bg-blue-600 text-white'
                    : 'text-slate-300 hover:bg-white/10 hover:text-white' }}"
            >
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
                    03
                </span>

                <span>Нормативная база</span>
            </a>

            <a
                href="{{ route('analyses.index') }}"
                class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition
                {{ request()->routeIs('analyses.*')
                    ? 'bg-blue-600 text-white'
                    : 'text-slate-300 hover:bg-white/10 hover:text-white' }}"
            >
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
                    04
                </span>

                <span>История анализов</span>
            </a>

            <div
                aria-disabled="true"
                class="flex cursor-not-allowed items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-slate-500"
            >
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
                    05
                </span>

                <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                    <span>AI-консультант</span>
                    <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Скоро</span>
                </span>
            </div>

            <div class="my-5 border-t border-white/10"></div>

            <div
                aria-disabled="true"
                class="flex cursor-not-allowed items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-slate-500"
            >
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
                    06
                </span>

                <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                    <span>RU / KZ</span>
                    <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Скоро</span>
                </span>
            </div>

            <a
                href="{{ route('profile.edit') }}"
                class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
            >
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
                    07
                </span>

                <span>Настройки</span>
            </a>

        </nav>

        <div class="border-t border-white/10 p-4">

            <div class="rounded-2xl bg-white/5 p-4">

                <div class="text-sm font-semibold text-white">
                    {{ Auth::user()->name }}
                </div>

                <div class="mt-1 truncate text-xs text-slate-400">
                    {{ Auth::user()->email }}
                </div>

                <form method="POST" action="{{ route('logout') }}" class="mt-4">
                    @csrf

                    <button
                        type="submit"
                        class="w-full rounded-xl border border-white/10 px-4 py-2 text-left text-sm text-slate-300 transition hover:bg-white/10 hover:text-white"
                    >
                        Выйти из системы
                    </button>
                </form>

            </div>

        </div>

    </aside>

    {{-- Основная часть --}}
    <div class="min-w-0 flex-1">

        {{-- Верхняя панель --}}
        <header class="app-header border-b border-slate-200 bg-white">

            <div class="flex min-h-20 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">

                <div>
                    <h1 class="text-xl font-bold text-slate-900">
                        {{ $heading ?? 'AI DDU Assistant' }}
                    </h1>

                    @isset($description)
                        <p class="mt-1 text-sm text-slate-500">
                            {{ $description }}
                        </p>
                    @endisset
                </div>

                <div class="flex items-center gap-3">

                    <div class="hidden text-right sm:block">
                        <div class="text-sm font-medium text-slate-900">
                            {{ Auth::user()->name }}
                        </div>

                        <div class="text-xs text-slate-500">
                            {{ now()->format('d.m.Y') }}
                        </div>
                    </div>

                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-900 text-sm font-bold text-white">
                        {{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}
                    </div>

                </div>

            </div>

        </header>

        {{-- Содержимое страницы --}}
        <main class="app-main p-4 sm:p-6 lg:p-8">
            {{ $slot }}
        </main>

    </div>

</div>

<nav class="app-mobile-nav border-t border-white/10 bg-slate-950 px-4 py-3 text-white lg:hidden">
    <div class="flex gap-2 overflow-x-auto">
        <a href="{{ route('workspaces.index') }}" class="shrink-0 rounded-lg px-3 py-2 text-sm text-slate-200">Рабочие дела</a>
        <a href="{{ route('workspaces.index', ['start' => 'analysis']) }}" class="shrink-0 rounded-lg px-3 py-2 text-sm text-slate-200">Новый анализ</a>
        <a href="{{ route('sources.index') }}" class="shrink-0 rounded-lg px-3 py-2 text-sm text-slate-200">Нормативная база</a>
        <a href="{{ route('analyses.index') }}" class="shrink-0 rounded-lg px-3 py-2 text-sm text-slate-200">История</a>
        <a href="{{ route('profile.edit') }}" class="shrink-0 rounded-lg px-3 py-2 text-sm text-slate-200">Настройки</a>
    </div>
</nav>

</body>
</html>
