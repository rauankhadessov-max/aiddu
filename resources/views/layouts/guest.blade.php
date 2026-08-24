<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI DDU Assistant</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased" style="background-image: radial-gradient(circle at top right, #dbeafe 0, transparent 34%), linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);">
    <div class="flex min-h-screen flex-col">
        <header class="px-5 py-6 sm:px-8">
            <div class="mx-auto flex max-w-6xl items-center justify-between">
                <a href="/" class="flex items-center gap-3 font-bold text-slate-900">
                    <span class="grid h-11 w-11 place-items-center rounded-2xl bg-blue-600 text-sm font-extrabold text-white shadow-lg shadow-blue-200">AI</span>
                    <span class="text-xl">AI DDU Assistant</span>
                </a>
                <a href="/" class="text-sm font-semibold text-slate-600 hover:text-blue-600">На главную</a>
            </div>
        </header>

        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-md rounded-3xl border border-white/80 bg-white/90 p-6 shadow-2xl shadow-slate-300/50 backdrop-blur sm:p-8">
                {{ $slot }}
            </div>
        </main>

        <footer class="px-5 py-6 text-center text-xs text-slate-500">
            © {{ now()->year }} AI DDU Assistant · Интеллектуальная система правового анализа
        </footer>
    </div>
</body>
</html>
