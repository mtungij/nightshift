<?php

namespace App\Services;

use App\Domain\TenantContext;
use Illuminate\Support\Facades\DB;

final class NumberingService
{
    public function next(string $type): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Numbering must run inside a transaction.');
        }
        $company = app(TenantContext::class)->companyId();
        DB::table('document_sequences')->insertOrIgnore(['company_id' => $company, 'type' => $type, 'value' => 0]);
        $sequence = DB::table('document_sequences')->where('company_id', $company)->where('type', $type)->lockForUpdate()->first();
        $next = $sequence->value + 1;
        DB::table('document_sequences')->where('id', $sequence->id)->update(['value' => $next]);

        return $type.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
