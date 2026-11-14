<script>
window.NightshiftI18n = {
    locale: @js(app()->getLocale()),
    messages: @js((request()->routeIs('dashboard') || request()->is('business-units', 'treasury', 'treasury/report', 'cash-handovers', 'audit-trail', 'settings/whatsapp')) ? \App\Support\UiText::catalog() : array_intersect_key(\App\Support\UiText::catalog(), array_flip(['Active Cashiers', 'This counter already has the maximum number of active cashiers.', 'Counter cashier limit reached', 'Switch to Light Mode', 'Switch to Dark Mode', 'Saving theme preference…', 'Theme preference saved.', 'Theme could not be saved. Please try again.', 'Language could not be saved. Please try again.', 'PDF generation is unavailable. Please try again.']))),
    endpoint: @js(route('language.update'))
};
</script>
