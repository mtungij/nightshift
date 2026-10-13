<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div class="flex gap-3" role="group" aria-label="Theme preference">
            <flux:button type="button" icon="sun" data-theme-choice="light" aria-pressed="{{ auth()->user()->preferredTheme() === 'light' ? 'true' : 'false' }}">{{ __('Light') }}</flux:button>
            <flux:button type="button" icon="moon" data-theme-choice="dark" aria-pressed="{{ auth()->user()->preferredTheme() === 'dark' ? 'true' : 'false' }}">{{ __('Dark') }}</flux:button>
        </div>
        <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400" data-theme-status role="status">{{ __('Light is the default. Your choice is saved to your account.') }}</p>
    </x-pages::settings.layout>
</section>
