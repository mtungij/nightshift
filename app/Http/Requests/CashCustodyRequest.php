<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CashCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->active && $user->company->active && ! $user->isPosTerminal() && ($user->hasPermission('view-treasury') || $user->hasPermission('receive-treasury') || $user->hasPermission('review-cash-handovers') || ($user->hasPermission('issue-drinks') && $user->hasPermission('close-shifts'))));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['required', function ($attribute, $value, $fail) {
            if (is_float($value) || ! preg_match('/^\d{1,10}(\.\d{1,2})?$/D', (string) $value)) {
                $fail(__('ui.Use a positive decimal string with at most two decimal places.'));
            }
        }];
        $note = ['required', 'string', 'min:8', 'max:2000'];
        $id = ['required', 'integer', 'min:1'];
        $destination = ['required', Rule::in(['NEXT_CASHIER', 'TREASURY'])];
        $rules = match ($this->route('action')) {
            'stage' => ['shift_id' => $id, 'destination' => $destination, 'to_user_id' => $id, 'amount' => $money, 'note' => ['nullable', 'string', 'max:2000']],
            'receive' => ['amount' => $money, 'note' => ['nullable', 'string', 'max:2000']],
            'approve' => ['note' => $note],
            'cancel' => ['note' => $note, 'destination' => ['nullable', Rule::in(['NEXT_CASHIER', 'TREASURY'])], 'to_user_id' => ['nullable', 'integer', 'min:1']],
            'spend' => ['transaction_type' => ['required', Rule::in(['PURCHASE', 'EXPENSE', 'SALARY', 'BANK_DEPOSIT'])], 'branch_id' => $id, 'amount' => $money, 'description' => ['required', 'string', 'min:3', 'max:2000'], 'external_reference' => ['required', 'string', 'max:100'], 'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'], 'category' => ['nullable', 'string', 'max:100'], 'goods_receipt_id' => ['nullable', 'integer', 'min:1'], 'employee_id' => ['nullable', 'integer', 'min:1'], 'pay_period' => ['nullable', 'string', 'max:100'], 'bank' => ['nullable', 'string', 'max:100']],
            'reverse' => ['note' => $note, 'to_user_id' => ['nullable', 'integer', 'min:1']],
            default => abort(404),
        };

        return ['idempotency_key' => ['required', 'uuid'], ...$rules];
    }
}
