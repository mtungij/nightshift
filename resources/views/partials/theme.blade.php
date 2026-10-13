@include('partials.language')
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="{{ auth()->user()?->preferredTheme() === 'dark' ? '#111317' : '#f6f7f9' }}">
<style>html{color-scheme:light;background:#f6f7f9}html.dark{color-scheme:dark;background:#111317}@media print{html,html.dark{color-scheme:light;background:white}}</style>
<script>
(() => {
    const initial = @js(auth()->user()?->preferredTheme() ?? 'light');
    const sync = () => {
        const theme = document.documentElement.dataset.theme;
        document.querySelectorAll('[data-theme-choice]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.themeChoice === theme));
        });
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            button.setAttribute('aria-label', theme === 'dark' ? (window.NightshiftI18n.messages['Switch to Light Mode'] || 'Switch to Light Mode') : (window.NightshiftI18n.messages['Switch to Dark Mode'] || 'Switch to Dark Mode'));
            button.setAttribute('title', theme === 'dark' ? (window.NightshiftI18n.messages['Switch to Light Mode'] || 'Switch to Light Mode') : (window.NightshiftI18n.messages['Switch to Dark Mode'] || 'Switch to Dark Mode'));
            button.setAttribute('aria-pressed', String(theme === 'dark'));
        });
    };
    const apply = theme => {
        theme = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.classList.toggle('dark', theme === 'dark');
        document.documentElement.dataset.theme = theme;
        document.querySelector('meta[name="theme-color"]').content = theme === 'dark' ? '#111317' : '#f6f7f9';
        // Flux uses this appearance-only key. Account data is the source of truth:
        // never inherit another user's browser preference or the operating system.
        try { localStorage.setItem('flux.appearance', theme); } catch {}
        if (window.Flux && 'appearance' in window.Flux && window.Flux.appearance !== theme) window.Flux.appearance = theme;
        sync();
        window.dispatchEvent(new CustomEvent('nightshift:theme', {detail: theme}));
    };
    let pending = false;
    window.NightshiftTheme = {
        get theme() { return document.documentElement.dataset.theme || 'light'; },
        apply,
        async save(theme) {
            if (pending) return;
            const previous = this.theme;
            pending = true;
            document.querySelectorAll('[data-theme-toggle],[data-theme-choice]').forEach(button => button.disabled = true);
            document.querySelectorAll('[data-theme-status]').forEach(node => node.textContent = (window.NightshiftI18n.messages['Saving theme preference…'] || 'Saving theme preference…'));
            apply(theme);
            try {
                const response = await fetch(@js(route('theme.update')), {
                    method: 'PUT', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                    body: JSON.stringify({theme})
                });
                if (!response.ok) throw new Error((window.NightshiftI18n.messages['Theme could not be saved. Please try again.'] || 'Theme could not be saved. Please try again.'));
                const data = await response.json();
                apply(data.theme);
                document.querySelectorAll('[data-theme-status]').forEach(node => node.textContent = (window.NightshiftI18n.messages['Theme preference saved.'] || 'Theme preference saved.'));
            } catch (error) { apply(previous); throw error; }
            finally {
                pending = false;
                document.querySelectorAll('[data-theme-toggle],[data-theme-choice]').forEach(button => button.disabled = false);
            }
        }
    };
    window.Flux = window.Flux || {};
    window.Flux.applyAppearance = apply;
    apply(initial);
    if (!window.__nightshiftThemeBound) {
        window.__nightshiftThemeBound = true;
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-theme-toggle],[data-theme-choice]');
            if (!button) return;
            const theme = button.dataset.themeChoice || (window.NightshiftTheme.theme === 'dark' ? 'light' : 'dark');
            window.NightshiftTheme.save(theme).catch(error => {
                const status = document.querySelector('[data-theme-status]');
                if (status) status.textContent = error.message;
                window.dispatchEvent(new CustomEvent('nightshift:theme-error', {detail: error.message}));
            });
        });
        document.addEventListener('DOMContentLoaded', sync);
        document.addEventListener('livewire:navigated', sync);
    }
})();
</script>
