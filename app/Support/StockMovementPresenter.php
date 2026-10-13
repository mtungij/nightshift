<?php

namespace App\Support;

use App\Domain\TenantContext;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;

final class StockMovementPresenter
{
    /** @return array<string, string|null> */
    public static function present(StockMovement $movement): array
    {
        $location = StockLocation::whereKey($movement->stock_location_id)->first()?->name;
        $from = str_starts_with($movement->quantity, '-') ? $location : null;
        $to = str_starts_with($movement->quantity, '-') ? null : $location;
        $reference = $movement->reference_type.' #'.$movement->reference_id;
        if ($movement->reference_type === 'GoodsReceipt') {
            $receipt = GoodsReceipt::whereKey($movement->reference_id)->first();
            if ($receipt) {
                $from = Supplier::whereKey($receipt->supplier_id)->first()?->name;
                $reference = $receipt->number;
            }
        } elseif ($movement->reference_type === 'StockTransfer') {
            $transfer = StockTransfer::whereKey($movement->reference_id)->first();
            if ($transfer) {
                $from = StockLocation::whereKey($transfer->source_location_id)->first()?->name;
                $to = StockLocation::whereKey($transfer->destination_location_id)->first()?->name;
                $reference = $transfer->number;
            }
        }

        return [
            'ref' => $reference,
            'product' => Product::whereKey($movement->product_id)->first()?->name,
            'from' => $from,
            'to' => $to,
            'qty' => QuantityFormatter::format($movement->quantity),
            'balance' => QuantityFormatter::format($movement->balance_after),
            'reason' => match ($movement->movement_type) {
                'GRN' => 'Goods receipt',
                'TRANSFER_SENT', 'TRANSFER_RECEIVED' => 'Transfer',
                default => str_replace('_', ' ', ucfirst(strtolower($movement->movement_type))),
            },
            'type' => $movement->movement_type,
            // created_by is the immutable posting actor, including issuance/approval,
            // rather than the waiter or requester who initiated the source document.
            'user' => User::where('company_id', $movement->company_id)->whereKey($movement->created_by)->first()?->name,
            'time' => $movement->created_at?->copy()->timezone(app(TenantContext::class)->user()->company->timezone ?: 'Africa/Dar_es_Salaam')->format('d M Y H:i:s'),
        ];
    }
}
