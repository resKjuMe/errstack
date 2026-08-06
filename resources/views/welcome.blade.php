<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-white font-sans text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
    <main class="text-center">
        <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
        <p class="mt-2 text-sm text-zinc-500">Noch keine Oberfläche — das Grundgerüst läuft.</p>
    </main>
</body>
</html>
