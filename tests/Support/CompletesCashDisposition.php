<?php

namespace Tests\Support;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashHandoverService;

trait CompletesCashDisposition
{
    protected function prepareCashDisposition(Shift $shift): void
    {
        $shift = $shift->fresh();
        if ($shift->type !== 'CASHIER' || D::cmp($shift->actual_cash, '0') === 0 || app(CashHandoverService::class)->active($shift)) {
            return;
        }
        $owner = User::where('company_id', $shift->company_id)->whereHas('role', fn ($q) => $q->where('name', 'Company Admin / Owner'))->firstOrFail();
        $h = app(CashHandoverService::class)->stage($shift->id, ['destination' => 'TREASURY', 'to_user_id' => $owner->id, 'amount' => $shift->actual_cash]);
        app(TenantContext::class)->run($owner, fn () => app(CashHandoverService::class)->receive($h->id, $h->amount));
    }
}
