<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class NightshiftCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()->active && $this->user()->company_id);
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('command') === 'product' && $this->has('payload')) {
            $payload = json_decode((string) $this->input('payload'), true);
            if (is_array($payload)) {
                $this->merge($payload);
            }
        }
        if ($this->has('username')) {
            $this->merge(['username' => mb_strtolower(trim((string) $this->input('username')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $decimal = fn ($a, $v, $fail) => is_float($v) || ! preg_match('/^\d{1,10}(\.\d{1,6})?$/D', (string) $v) ? $fail('Use a positive decimal string with at most six decimal places.') : null;
        $amount = ['required', $decimal];
        $qty = ['required', $decimal];
        $id = ['required', 'integer', 'min:1'];
        $items = ['required', 'array', 'min:1', 'max:100'];
        $text = ['required', 'string', 'max:255'];
        $reason = ['required', 'string', 'min:8', 'max:2000'];
        $rules = match ($this->route('command')) {
            'staff-pin' => ['id' => $id, 'pin' => ['nullable', 'string', 'regex:/^[0-9]{4}$/D']],
            'waiter-payment' => ['waiter_id' => $id, 'stock_location_id' => $id, 'amount' => $amount, 'method' => ['required', Rule::in(['Cash', 'M-Pesa', 'Airtel Money', 'Mixx by Yas', 'Card', 'Bank'])]],
            'order-send' => ['id' => ['nullable', 'integer', 'min:1'], 'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/D'], 'waiter_id' => $id, 'stock_location_id' => ['required_without:id', 'integer', 'min:1'], 'service_table_id' => ['nullable', 'integer', 'min:1'], 'discount' => ['required_without:id', $decimal], 'items' => ['required_without:id', 'array', 'min:1', 'max:100'], 'items.*' => ['array:product_unit_id,quantity'], 'items.*.product_unit_id' => $id, 'items.*.quantity' => $qty],
            'order-create' => ['stock_location_id' => $id, 'waiter_id' => $id, 'service_table_id' => ['nullable', 'integer', 'min:1'], 'status' => ['required', Rule::in(['DRAFT', 'WAITING_FOR_ISSUE'])], 'discount' => ['required', $decimal], 'items' => $items, 'items.*' => ['array:product_unit_id,quantity'], 'items.*.product_unit_id' => $id, 'items.*.quantity' => $qty],
            'order-issue','order-submit','order-cancel','transfer-send','transfer-receive' => ['id' => $id],
            'payment' => ['id' => $id, 'lines' => $items, 'lines.*' => ['array:method,amount,reference'], 'lines.*.method' => ['required', Rule::in(['Cash', 'M-Pesa', 'Airtel Money', 'Mixx by Yas', 'Card', 'Bank', 'Credit'])], 'lines.*.amount' => $amount, 'lines.*.reference' => ['nullable', 'string', 'max:255']],
            'shift-open' => ['user_id' => ['nullable', 'integer', 'min:1'], 'stock_location_id' => $id, 'type' => ['required', Rule::in(['CASHIER', 'WAITER'])], 'opening_cash' => $amount],
            'shift-close' => ['id' => $id, 'actual_cash' => ['nullable', $decimal], 'notes' => ['nullable', 'string', 'max:2000'], 'override_reason' => ['nullable', 'string', 'min:8', 'max:2000']],
            'grn' => ['stock_location_id' => $id, 'supplier_id' => $id, 'supplier_invoice' => ['nullable', 'string', 'max:255'], 'received_on' => ['required', 'date_format:Y-m-d'], 'items' => $items, 'items.*' => ['array:product_unit_id,quantity,unit_cost'], 'items.*.product_unit_id' => $id, 'items.*.quantity' => $qty, 'items.*.unit_cost' => $amount],
            'transfer-create' => ['source_location_id' => $id, 'destination_location_id' => $id, 'notes' => ['nullable', 'string', 'max:2000'], 'items' => $items, 'items.*' => ['array:product_unit_id,quantity'], 'items.*.product_unit_id' => $id, 'items.*.quantity' => $qty],
            'adjustment-request' => ['stock_location_id' => $id, 'reason' => ['required', Rule::in(['BREAKAGE', 'COMPLIMENTARY', 'STAFF_CONSUMPTION', 'PROMOTION', 'EXPIRED', 'DAMAGED', 'OTHER'])], 'notes' => $reason, 'items' => $items, 'items.*' => ['array:product_unit_id,quantity'], 'items.*.product_unit_id' => $id, 'items.*.quantity' => $qty],
            'void-request' => ['id' => $id, 'reason' => $reason],
            'void-decide','adjustment-decide' => ['id' => $id, 'approved' => ['required', 'boolean'], 'reason' => $reason],
            'return' => ['id' => $id, 'reason' => $reason, 'items' => $items, 'items.*' => ['array:order_item_id,quantity'], 'items.*.order_item_id' => $id, 'items.*.quantity' => $qty],
            'product' => ['id' => ['nullable', 'integer', 'min:1'], 'name' => $text, 'sku' => ['nullable', 'string', 'max:255'], 'barcode' => ['nullable', 'string', 'max:255'], 'brand' => ['nullable', 'string', 'max:255'], 'category_id' => $id, 'base_unit_id' => $id, 'purchase_cost' => $amount, 'minimum_stock' => $qty, 'active' => ['required', 'boolean'], 'image_url' => ['nullable', 'url', 'max:1000'], 'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], 'units' => $items, 'units.*' => ['array:unit_id,conversion_quantity,selling_price'], 'units.*.unit_id' => $id, 'units.*.conversion_quantity' => $qty, 'units.*.selling_price' => $amount],
            'catalog-branch' => ['id' => ['nullable', 'integer', 'min:1'], 'name' => $text, 'address' => ['nullable', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:30'], 'active' => ['sometimes', 'boolean']],
            'catalog-location' => ['name' => $text, 'branch_id' => $id, 'type' => ['required', Rule::in(['STORE', 'COUNTER'])]],
            'catalog-category' => ['id' => ['nullable', 'integer', 'min:1'], 'name' => $text, 'active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'], 'icon' => ['sometimes', Rule::in(['box', 'glass', 'cocktail', 'beer', 'coffee', 'food', 'wine'])], 'color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/D']],
            'catalog-category-order' => ['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']],
            'catalog-setup' => ['step' => ['required', Rule::in(config('business.setup_steps'))], 'status' => ['required', Rule::in(['complete', 'skip', 'resume'])]],
            'catalog-unit' => ['name' => $text],
            'catalog-supplier' => ['name' => $text, 'contact' => $text, 'category' => ['nullable', 'string', 'max:255']],
            'catalog-reservation' => ['service_table_id' => $id, 'guest' => $text, 'phone' => $text, 'size' => ['required', 'integer', 'min:1', 'max:100'], 'date' => ['required', 'date_format:Y-m-d'], 'arrival' => ['required', 'date_format:H:i']],
            'catalog-user' => ['name' => $text, 'email' => ['required', 'email', 'max:255'], 'username' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/iD', Rule::unique('users', 'username')], 'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/D'], 'role' => ['required', Rule::in(config('business.staff_roles'))], 'branch_id' => $id, 'phone' => ['nullable', 'string', 'max:40'], 'active' => ['sometimes', 'boolean']],
            'catalog-company' => ['name' => $text, 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:255']],
            'notifications-read' => [],
            default => abort(404),
        };

        return ['idempotency_key' => ['required', 'uuid'], ...$rules];
    }
}
