<?php

namespace App\Services\WhatsApp;

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\WhatsAppRecipient;
use App\Support\TanzanianPhone;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RecipientService
{
    /** @return list<int> */
    public function branches(WhatsAppRecipient $recipient): array
    {
        return array_values(Branch::whereIn('id', $recipient->all_branches ? Branch::pluck('id')->all() : $recipient->branch_ids)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function visible(WhatsAppRecipient $recipient): bool
    {
        $actor = app(TenantContext::class)->user();
        if ($recipient->company_id !== $actor->company_id) {
            return false;
        }
        if ($actor->hasPermission('all-branches')) {
            return true;
        }
        $allowed = Branch::pluck('id')->all();

        return ! $recipient->all_branches && $recipient->branch_ids !== [] && array_diff($recipient->branch_ids, $allowed) === [];
    }

    public function find(int $id): WhatsAppRecipient
    {
        $recipient = WhatsAppRecipient::findOrFail($id);
        abort_unless($this->visible($recipient), 403);

        return $recipient;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?int $id = null): WhatsAppRecipient
    {
        Gate::authorize('whatsapp.manage_recipients');
        $recipient = $id ? $this->find($id) : new WhatsAppRecipient;
        $ids = array_values(array_unique(array_map(intval(...), $data['branch_ids'] ?? [])));
        if ($data['all_branches']) {
            abort_unless(app(TenantContext::class)->user()->hasPermission('all-branches'), 403);
            $ids = [];
        } else {
            if ($ids === [] || Branch::whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['branch_ids' => 'Choose permitted company branches.']);
            }
        }
        $phone = TanzanianPhone::normalize($data['phone']);
        if (WhatsAppRecipient::where('phone', $phone)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['phone' => 'This WhatsApp recipient already exists.']);
        }
        $recipient->fill([...$data, 'phone' => $phone, 'branch_ids' => $ids])->save();

        return $recipient;
    }
}
