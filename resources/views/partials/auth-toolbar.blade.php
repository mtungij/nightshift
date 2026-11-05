<div class="auth-toolbar">
    @include('partials.language-switch')
    <button type="button" class="auth-theme-toggle" data-auth-theme-toggle aria-label="{{ __('Toggle color theme') }}" onclick="window.NightshiftTheme.apply(window.NightshiftTheme.theme === 'dark' ? 'light' : 'dark')">{{ __('Light / Dark') }}</button>
</div>
