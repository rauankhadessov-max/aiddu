<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.browser-branding', ['browserTitle' => 'Правовой ИИ'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased">
    <div class="flex min-h-screen flex-col">
        <header class="px-5 py-6 sm:px-8">
            <div class="mx-auto flex max-w-6xl items-center justify-between">
                <a href="/" class="rounded-xl focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200">
                    <x-ui.brand />
                </a>
                <a href="/" class="text-sm font-semibold text-slate-600 hover:text-blue-600">На главную</a>
            </div>
        </header>

        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60 sm:p-8">
                {{ $slot }}
            </div>
        </main>

        <footer class="px-5 py-6 text-center text-xs text-slate-500">
            © {{ now()->year }} Правовой ИИ · Анализ и подготовка НПА
        </footer>
    </div>
</body>
</html>
