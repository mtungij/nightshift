<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your username and 4-digit PIN')" />
        @isset($loginCompany)<p class="text-center">{{ $loginCompany->name }}</p>@endisset
        <x-auth-session-status class="text-center" :status="session('status')" />
        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf
            <flux:input name="username" :label="__('Username')" :value="old('username')" type="text" required autofocus autocomplete="username" maxlength="100" />
            <flux:input name="pin" :label="__('4-digit PIN')" type="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required autocomplete="off" />
            <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">{{ __('Log in') }}</flux:button>
        </form>
        <p class="text-sm text-center text-zinc-600 dark:text-zinc-400">{{ __('Ask your company admin to reset a forgotten PIN.') }}</p>
        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('business.register')" wire:navigate>{{ __('Set up your business') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
