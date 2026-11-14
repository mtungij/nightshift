<?php

namespace App\Services\WhatsApp;

use App\Domain\BusinessUnitContext;
use App\Domain\TenantContext;
use App\Models\User;

final class CompanyContext
{
    public function run(int $companyId, callable $callback): mixed
    {
        $owner = User::where('company_id', $companyId)->where('active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['Company Admin / Owner', 'Super Admin']))->orderBy('id')->first();
        if (! $owner || ! $owner->company->active || ! $owner->hasPermission('all-branches') || ! $owner->hasPermission('view-reports')) {
            throw new GowaException('No active authorized company report administrator.');
        }

        return app(TenantContext::class)->run($owner, function () use ($callback) {
            $context = app(BusinessUnitContext::class);
            $selected = $context->selected();
            $context->select(null);
            try {
                return $callback();
            } finally {
                $context->select($selected);
            }
        });
    }
}
