<?php

namespace App\Models;

use App\Domain\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<TenantModel> */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $builder->where($model->qualifyColumn('company_id'), $context->companyId());
        if ($model->isBranchScoped() && ($branches = $context->branches()) !== null) {
            $builder->whereIn($model->qualifyColumn($model instanceof Branch ? 'id' : 'branch_id'), $branches);
        }
    }
}
abstract class TenantModel extends Model
{
    protected $guarded = ['id', 'company_id'];

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return array_merge(array_fill_keys(['purchase_cost', 'selling_price', 'opening_cash', 'expected_cash', 'actual_cash', 'cash_difference', 'subtotal', 'discount', 'return_total', 'total', 'amount_paid', 'balance', 'credit_amount', 'unit_price', 'discount_share', 'returned_value', 'amount', 'total_cost', 'unit_cost', 'cost', 'debit', 'credit'], 'decimal:2'), array_fill_keys(['quantity', 'minimum_stock', 'conversion_quantity', 'base_quantity', 'returned_quantity', 'balance_after'], 'decimal:6'));
    }

    public function isBranchScoped(): bool
    {
        return $this->branchScoped;
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function ($model) {
            $company = app(TenantContext::class)->companyId();
            if (! $company) {
                throw new \LogicException('A tenant context is required.');
            } if ($model->company_id && (int) $model->company_id !== $company) {
                throw new \LogicException('Tenant mismatch.');
            } $model->company_id = $company;
        });
        static::updating(function ($model) {
            if ($model->isDirty('company_id')) {
                throw new \LogicException('Company cannot be changed.');
            }
        });
    }
}
