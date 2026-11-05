<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ auth()->user()?->preferredTheme() === 'dark' ? 'dark' : '' }}" data-theme="{{ auth()->user()?->preferredTheme() ?? 'light' }}">
    <head>@include('partials.head')</head>
    <body class="nightshift-auth">
        @include('partials.auth-toolbar')
        <main class="auth-shell">
            <div class="auth-container">
                <x-nightshift-brand class="auth-brand" wire:navigate />
                <section class="auth-card">{{ $slot }}</section>
            </div>
        </main>
        @persist('toast')
            <flux:toast.group><flux:toast /></flux:toast.group>
        @endpersist
        @fluxScripts
    </body>
</html>
