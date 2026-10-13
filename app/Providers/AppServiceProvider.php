<?php

namespace App\Providers;

use App\Domain\TenantContext;
use App\Jobs\WhatsApp\SendOperationalAlertJob;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\DeleteUserForm;
use App\Livewire\Settings\DeleteUserModal;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use App\Livewire\Settings\TwoFactorRecoveryCodes;
use App\Livewire\Settings\TwoFactorSetupModal;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        AuditLog::created(function ($audit) {
            if (! in_array($audit->action, config('whatsapp.audit_actions'), true)) {
                return;
            }
            $companyId = $audit->company_id;
            DB::afterCommit(function () use ($companyId) {
                try {
                    $setting = WhatsAppSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
                    if ($setting && $setting->preferences()['enabled']) {
                        SendOperationalAlertJob::dispatch($companyId)->onConnection(config('gowa.queue_connection'))->onQueue(config('gowa.queue'));
                    }
                } catch (\Throwable) {
                    // Committed audit history is scanned again by the scheduler.
                    Log::warning('WhatsApp audit dispatch deferred.', ['company_id' => $companyId]);
                }
            });
        });

        foreach (config('nightshift.permissions') as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
        Event::listen(Login::class, function ($event) {
            $locale = session('locale');
            if ($event->user instanceof User && $event->user->language_preference === null && in_array($locale, ['en', 'sw'], true)) {
                $event->user->language_preference = $locale;
                $event->user->save();
            }
            if ($event->user->company_id) {
                app(TenantContext::class)->run($event->user, fn () => app(AuditService::class)->record('LOGIN', 'Authentication', 'User', $event->user->id, [], ['user' => $event->user->name]));
            }
        });
        View::addNamespace('pages', resource_path('views/pages'));
        Blade::anonymousComponentPath(resource_path('views/pages'), 'pages');
        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');
        Livewire::component('pages::settings.profile', Profile::class);
        Livewire::component('pages::settings.security', Security::class);
        Livewire::component('pages::settings.delete-user-form', DeleteUserForm::class);
        Livewire::component('pages::settings.delete-user-modal', DeleteUserModal::class);
        Livewire::component('pages::settings.appearance', Appearance::class);
        Livewire::component('pages::settings.two-factor-setup-modal', TwoFactorSetupModal::class);
        Livewire::component('pages::settings.two-factor.recovery-codes', TwoFactorRecoveryCodes::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
