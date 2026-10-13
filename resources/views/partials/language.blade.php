<script>
window.NightshiftI18n = {
    locale: @js(app()->getLocale()),
    messages: @js(request()->routeIs('dashboard') ? \App\Support\UiText::catalog() : array_intersect_key(\App\Support\UiText::catalog(), array_flip(['Switch to Light Mode', 'Switch to Dark Mode', 'Saving theme preference…', 'Theme preference saved.', 'Theme could not be saved. Please try again.', 'Language could not be saved. Please try again.']))),
    endpoint: @js(route('language.update'))
};
</script>
