<?php

namespace App\Console\Commands;

use App\Domain\TenantContext;
use App\Models\User;
use App\Services\StaffPinService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class SetLoginPin extends Command
{
    protected $signature = 'nightshift:set-login-pin {username : Exact login username}';

    protected $description = 'Provision or reset a PIN securely without putting it in command history';

    public function handle(StaffPinService $pins): int
    {
        $user = User::where('username', mb_strtolower(trim($this->argument('username'))))->first();
        if (! $user) {
            $this->error('Username not found.');

            return self::FAILURE;
        }
        $admin = User::where('company_id', $user->company_id)->where('active', true)->whereHas('role', fn ($q) => $q->whereIn('name', ['Company Admin / Owner', 'Super Admin']))->first();
        if (! $admin) {
            $this->error('No active company admin is available.');

            return self::FAILURE;
        }
        $pin = $this->secret('New 4-digit PIN');
        $confirmation = $this->secret('Confirm PIN');
        if ($pin !== $confirmation) {
            $this->error('PIN confirmation does not match.');

            return self::FAILURE;
        }
        try {
            app(TenantContext::class)->run($admin, fn () => $pins->set($user->id, $pin));
        } catch (ValidationException) {
            $this->error('Enter exactly four numeric digits.');

            return self::FAILURE;
        } finally {
            unset($pin, $confirmation);
        }
        $this->info('PIN configured.');

        return self::SUCCESS;
    }
}
