<?php

namespace App\Services;

use App\Domain\TenantContext;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransactionService
{
    public function run(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            Company::whereKey(app(TenantContext::class)->companyId())->lockForUpdate()->firstOrFail();

            return $callback();
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function idempotent(string $key, string $action, array $payload, callable $callback): array
    {
        return $this->run(function () use ($key, $action, $payload, $callback) {
            $ctx = app(TenantContext::class);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $existing = DB::table('idempotency_keys')->where('company_id', $ctx->companyId())->where('key', $key)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === $ctx->user()->id, 403);
                if ($existing->action !== $action || $existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different request.']);
                }

                return json_decode($existing->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $callback();
            DB::table('idempotency_keys')->insert(['company_id' => $ctx->companyId(), 'user_id' => $ctx->user()->id, 'key' => $key, 'action' => $action, 'payload_hash' => $hash, 'response' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        });
    }
}
