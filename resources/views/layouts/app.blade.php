<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} &mdash; @yield('title', 'Upload your CV, meet your matches')</title>
    <meta name="description" content="Upload a CV, get an AI-parsed profile, and see it scored against real job postings.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-4 flex items-center justify-between">
            <a href="{{ url('/') }}" class="font-semibold text-slate-900">Find Job <span class="text-indigo-600">with AI</span></a>
            <a href="https://github.com/muhammedkado/find_job_with_ai" target="_blank" rel="noopener" class="text-sm text-slate-500 hover:text-slate-800">View source on GitHub</a>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-10">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-5xl px-4 py-10 text-sm text-slate-400">
        Public demo &mdash; AI calls are rate-limited and fall back to sample data once the daily budget is used up.
    </footer>
</body>
</html>
