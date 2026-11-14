<?php

namespace App\Services\WhatsApp;

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\WhatsAppRecipient;
use App\Services\AuditService;
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
        $allowed = Branch::pluck('id')->all();
        if (! $actor->hasPermission('all-branches') && ($recipient->all_branches || $recipient->branch_ids === [] || array_diff($recipient->branch_ids, $allowed) !== [])) {
            return false;
        }
        if (! $actor->hasPermission('all-business-units') && ($recipient->all_business_units || array_diff($recipient->business_unit_ids ?? [], BusinessUnit::pluck('id')->all()) !== [])) {
            return false;
        }

        return true;
    }

    /** @return list<int> */
    public function units(WhatsAppRecipient $recipient): array
    {
        return array_values(BusinessUnit::whereIn('branch_id', $this->branches($recipient))->when(! $recipient->all_business_units, fn ($q) => $q->whereIn('id', $recipient->business_unit_ids ?? []))->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function enabled(WhatsAppRecipient $recipient, string $type): bool
    {
        if ($type === 'TEST') {
            return true;
        }
        if ($type === 'MANUAL') {
            $type = 'DAILY';
        }
        if ($recipient->enabled_types !== null) {
            return in_array($type, $recipient->enabled_types, true);
        }

        return match ($type) {
            'DAILY','MANUAL','WEEKLY','MONTHLY','TREASURY_SUMMARY' => $recipient->daily_report, 'SHIFT' => $recipient->shift_reports, 'TEST' => true, default => $recipient->critical_alerts
        };
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
        if (! array_key_exists('all_business_units', $data) && ! $recipient->exists && ! app(TenantContext::class)->user()->hasPermission('all-business-units')) {
            $data['all_business_units'] = false;
            $data['business_unit_ids'] = BusinessUnit::whereIn('branch_id', $ids)->pluck('id')->all();
        }
        $allUnits = $data['all_business_units'] ?? $recipient->all_business_units ?? true;
        $unitIds = array_values(array_unique(array_map(intval(...), $data['business_unit_ids'] ?? $recipient->business_unit_ids ?? [])));
        if ($allUnits) {
            abort_unless(app(TenantContext::class)->user()->hasPermission('all-business-units'), 403);
        } elseif ($unitIds === [] || BusinessUnit::whereIn('id', $unitIds)->when(! $data['all_branches'], fn ($q) => $q->whereIn('branch_id', $ids))->count() !== count($unitIds)) {
            throw ValidationException::withMessages(['business_unit_ids' => 'Choose permitted Business Units within the recipient branches.']);
        }
        $data['all_business_units'] = $allUnits;
        $data['business_unit_ids'] = $allUnits ? [] : $unitIds;
        $phone = TanzanianPhone::normalize($data['phone']);
        if (WhatsAppRecipient::where('phone', $phone)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['phone' => 'This WhatsApp recipient already exists.']);
        }
        $before = $recipient->exists ? ['active' => $recipient->active, 'branch_ids' => $recipient->branch_ids, 'business_unit_ids' => $recipient->business_unit_ids] : [];
        $recipient->fill([...$data, 'phone' => $phone, 'branch_ids' => $ids])->save();

        app(AuditService::class)->record('WHATSAPP_RECIPIENT_SAVED', 'WhatsApp', 'WhatsAppRecipient', $recipient->id, $before, ['active' => $recipient->active, 'branch_ids' => $recipient->branch_ids, 'business_unit_ids' => $recipient->business_unit_ids]);

        return $recipient;
    }
}
