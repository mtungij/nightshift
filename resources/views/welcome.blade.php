<x-layouts::auth :title="__('Nightshift')">
    <x-auth-header :title="__('Nightshift')" :description="__('A workspace for your bar, your team, and every night ahead.')" />
    <div class="flex flex-col gap-4">
        <flux:button :href="route('login')" variant="primary">{{ __('Log in') }}</flux:button>
        <flux:link :href="route('business.register')">{{ __('Set up your business') }}</flux:link>
    </div>
</x-layouts::auth>
