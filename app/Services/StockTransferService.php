<?php

namespace App\Services;

use App\Domain\BusinessUnitContext;
use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\Gate;

final class StockTransferService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StockTransfer
    {
        Gate::authorize('transfer-stock');

        return app(BusinessUnitContext::class)->withoutSelection(fn () => $this->tx->run(function () use ($data) {
            $source = app(UnitOperationalService::class)->resolve($data, 'source_business_unit_id', 'source_location_id');
            $destination = app(UnitOperationalService::class)->resolve($data, 'destination_business_unit_id', 'destination_location_id');
            app(BusinessUnitContext::class)->select(null);
            $this->stock->require($source->id !== $destination->id, 'Choose different source and destination locations.');
            $this->stock->require($source->branch_id === $destination->branch_id, 'Both locations must belong to the same branch.');
            $t = StockTransfer::create(['branch_id' => $source->branch_id, 'source_location_id' => $source->id, 'destination_location_id' => $destination->id, 'number' => $this->numbers->next('TRF'), 'notes' => $data['notes'] ?? null, 'created_by' => app(TenantContext::class)->user()->id]);
            foreach ($data['items'] as $line) {
                $u = $this->stock->unit($line['product_unit_id']);
                $qty = D::quantity($line['quantity']);
                $this->stock->require(D::cmp($qty, '0') > 0, 'Transfer quantity must be positive.');
                $t->items()->create(['branch_id' => $t->branch_id, 'product_id' => $u->product_id, 'product_unit_id' => $u->id, 'quantity' => $qty, 'base_quantity' => D::mul($qty, $u->conversion_quantity, 6)]);
            }

            return $t;
        }));
    }

    /** Explicit stock allocation from an idle inactive legacy unit, using normal posted transfers.
     * @param  array<string,mixed>  $data
     */
    public function cutover(array $data): StockTransfer
    {
        Gate::authorize('manage-business-units');
        Gate::authorize('transfer-stock');

        return app(BusinessUnitContext::class)->withoutSelection(fn () => $this->tx->run(fn () => $this->stock->withSupervisedCutover((int) $data['source_location_id'], function () use ($data) {
            $this->stock->require(mb_strlen(trim($data['notes'] ?? '')) >= 8, __('ui.Explain the supervised stock allocation.'));
            $transfer = $this->create($data);
            $this->send($transfer->id);
            $transfer = $this->receive($transfer->id);
            $this->audit->record('UNIT_STOCK_CUTOVER', 'Business Units', 'StockTransfer', $transfer->id, [], ['source_location_id' => $transfer->source_location_id, 'destination_location_id' => $transfer->destination_location_id, 'notes' => $data['notes']], $transfer->branch_id);

            return $transfer;
        })));
    }

    public function send(int $id): StockTransfer
    {
        Gate::authorize('transfer-stock');

        return app(BusinessUnitContext::class)->withoutSelection(fn () => $this->tx->run(function () use ($id) {
            $t = StockTransfer::lockForUpdate()->findOrFail($id);
            $this->stock->location($t->source_location_id);
            $this->stock->location($t->destination_location_id);
            if (in_array($t->status, ['IN_TRANSIT', 'RECEIVED'], true)) {
                return $t;
            }
            $this->stock->require($t->status === 'DRAFT', 'Only draft transfers can be sent.');
            foreach ($t->items()->orderBy('product_id')->get() as $line) {
                $this->stock->change($t->source_location_id, $line->product_id, D::sub('0', $line->base_quantity, 6), 'TRANSFER_SENT', 'StockTransfer', $t->id);
            }
            $t->update(['status' => 'IN_TRANSIT', 'sent_at' => now()]);
            $this->audit->record('TRANSFER_SENT', 'Inventory', 'StockTransfer', $t->id, ['status' => 'DRAFT'], ['status' => 'IN_TRANSIT', 'source_location_id' => $t->source_location_id, 'destination_location_id' => $t->destination_location_id], $t->branch_id);

            return $t;
        }));
    }

    public function receive(int $id): StockTransfer
    {
        Gate::authorize('transfer-stock');

        return app(BusinessUnitContext::class)->withoutSelection(fn () => $this->tx->run(function () use ($id) {
            $t = StockTransfer::lockForUpdate()->findOrFail($id);
            $this->stock->location($t->source_location_id);
            $this->stock->location($t->destination_location_id);
            if ($t->status === 'RECEIVED') {
                return $t;
            }
            $this->stock->require($t->status === 'IN_TRANSIT', 'Only sent transfers can be received.');
            foreach ($t->items()->orderBy('product_id')->get() as $line) {
                $this->stock->change($t->destination_location_id, $line->product_id, $line->base_quantity, 'TRANSFER_RECEIVED', 'StockTransfer', $t->id);
            }
            $t->update(['status' => 'RECEIVED', 'received_at' => now(), 'received_by' => app(TenantContext::class)->user()->id]);
            $this->audit->record('TRANSFER_RECEIVED', 'Inventory', 'StockTransfer', $t->id, ['status' => 'IN_TRANSIT'], ['status' => 'RECEIVED'], $t->branch_id);

            return $t;
        }));
    }
}
