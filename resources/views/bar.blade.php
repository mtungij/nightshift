<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="{{ auth()->user()?->preferredTheme() === 'dark' ? 'dark' : '' }}" data-theme="{{ auth()->user()?->preferredTheme() ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.theme')
    <meta name="description" content="Nightshift — a smarter way to run your bar. Sales, waiter accountability, stock and shift control in one workspace.">
    <title>{{ \App\Support\UiText::get('Nightshift · Bar management') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('nightshift.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="nightshift">
    <div id="app">
        <div class="startup-loader" role="status" aria-label="Loading workspace">
            <div class="loading-brand">{{ \App\Support\UiText::get('NIGHTSHIFT') }}</div>
            <div class="loading-bar"></div>
        </div>
    </div>
    <div id="toast" role="status" aria-live="polite"></div>
    <div id="modal-root"></div>
    <noscript>{{ \App\Support\UiText::get('Please enable JavaScript to use the Nightshift dashboard.') }}</noscript>
</body>
</html>
