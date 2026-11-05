<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="{{ auth()->user()?->preferredTheme() === 'dark' ? 'dark' : '' }}" data-theme="{{ auth()->user()?->preferredTheme() ?? 'light' }}">
<head>
    @php($title = trim($__env->yieldContent('title')))
    @include('partials.head')
</head>
<body class="nightshift-auth">
    @include('partials.auth-toolbar')
    <main class="auth-shell">
        <div class="auth-container">
            <x-nightshift-brand class="auth-brand" />
            <section class="auth-card text-center">
                <p class="text-amber-700 dark:text-amber-300">@yield('code')</p>
                <h1 class="text-2xl font-bold">@yield('message')</h1>
                <p class="mt-6"><a class="underline" href="{{ route('home') }}">{{ __('Nightshift') }}</a></p>
            </section>
        </div>
    </main>
</body>
</html>
