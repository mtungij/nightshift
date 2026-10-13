<?php

namespace App\Models;

abstract class ImmutableModel extends TenantModel
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('Immutable transaction history. Create a reversal.'));
        static::deleting(fn () => throw new \LogicException('Immutable transaction history cannot be deleted.'));
    }
}
