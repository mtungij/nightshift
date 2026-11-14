<?php

namespace App\Services\WhatsApp;

use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\WhatsAppSetting;
use App\Services\AuditService;
use App\Services\Gowa;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SettingsService
{
    public function current(): WhatsAppSetting
    {
        return WhatsAppSetting::firstOrCreate([], ['options' => config('whatsapp.defaults'), 'audit_cursor' => (int) AuditLog::max('id')]);
    }

    /** @param array<string, mixed> $options */
    public function save(array $options): WhatsAppSetting
    {
        Gate::authorize('whatsapp.manage_settings');

        return app(TransactionService::class)->run(function () use ($options) {
            $settings = $this->current();
            $old = $settings->preferences();
            if (($options['enabled'] ?? false) && (! $settings->device_id || $settings->connection_status !== 'CONNECTED')) {
                throw ValidationException::withMessages(['enabled' => 'Save a Device ID and test its connection before enabling WhatsApp.']);
            }
            if (! $old['enabled'] && ($options['enabled'] ?? false)) {
                // Starting an integration must not notify recipients about old history.
                $settings->audit_cursor = (int) AuditLog::max('id');
            }
            $settings->options = [...$old, ...array_intersect_key($options, config('whatsapp.defaults'))];
            $settings->save();

            app(AuditService::class)->record('WHATSAPP_SETTINGS_SAVED', 'WhatsApp', 'WhatsAppSetting', $settings->id, $old, $settings->preferences());

            return $settings;
        });
    }

    public function assignDevice(string $device): WhatsAppSetting
    {
        Gate::authorize('whatsapp.manage_settings');
        $device = trim($device);
        Validator::make(['device_id' => $device], ['device_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D', Rule::unique('whatsapp_settings', 'device_id')->ignore($this->current()->id)]])->validate();
        $settings = app(TransactionService::class)->run(function () use ($device) {
            $settings = $this->current();
            $old = $settings->device_id;
            if ($old !== $device) {
                $settings->update(['device_id' => $device, 'provisioned' => true, 'connection_status' => 'NOT_CONFIGURED', 'jid' => null, 'last_checked_at' => null]);
            }
            // Compatibility marker means an ID is assigned, never that Nightshift
            // created or linked it. It is not a prerequisite for checks or sends.
            if (! $settings->provisioned) {
                $settings->update(['provisioned' => true]);
            }
            app(AuditService::class)->record($old === $device ? 'WHATSAPP_DEVICE_SAVED' : 'WHATSAPP_DEVICE_UPDATED', 'WhatsApp', 'WhatsAppSetting', $settings->id, ['device_id' => $old], ['device_id' => $device]);

            return $settings;
        });

        return $this->check($settings);
    }

    public function check(WhatsAppSetting $settings, bool $throwOnProviderError = false): WhatsAppSetting
    {
        abort_unless($settings->company_id === app(TenantContext::class)->companyId(), 403);
        $device = $settings->device_id;
        if (! $device) {
            return $this->persistStatus($device, 'NOT_CONFIGURED', null, $throwOnProviderError);
        }
        try {
            $data = app(Gowa::class)->deviceStatus($settings)['results'];
            $state = strtolower((string) ($data['state'] ?? $data['status'] ?? ''));
            $connected = in_array($state, ['logged_in', 'connected'], true)
                || (($data['is_logged_in'] ?? false) === true && ($data['is_connected'] ?? false) === true);
            if (($data['is_logged_in'] ?? null) === false || ($data['is_connected'] ?? null) === false) {
                $connected = false;
            }
            $status = $connected ? 'CONNECTED' : (in_array($state, ['logged_out', 'needs_relinking'], true) ? 'NEEDS_RELINKING' : ($state === 'connecting' ? 'CONNECTING' : 'DISCONNECTED'));
            $jid = $data['jid'] ?? $data['phone_number'] ?? null;
        } catch (GowaException $error) {
            $settings = $this->persistStatus($device, $error->httpStatus === 404 ? 'NOT_FOUND' : 'PROVIDER_ERROR', null, $throwOnProviderError);
            if ($throwOnProviderError) {
                throw $error;
            }

            return $settings;
        }

        return $this->persistStatus($device, $status, is_string($jid) && preg_match('/^[0-9A-Za-z@.:-]{1,100}$/D', $jid) ? $jid : null, $throwOnProviderError);
    }

    private function persistStatus(?string $device, string $status, ?string $jid, bool $throwOnChange): WhatsAppSetting
    {
        // Provider HTTP runs outside the company lock. A late result must never
        // verify a replacement device or let a worker send using a different ID.
        $settings = app(TransactionService::class)->run(function () use ($device, $status, $jid) {
            $settings = $this->current();
            if ($settings->device_id !== $device) {
                return $settings;
            }
            $old = $settings->connection_status;
            $settings->update(['connection_status' => $status, 'jid' => $jid, 'last_checked_at' => now()]);
            if ($old !== $status) {
                app(AuditService::class)->record('WHATSAPP_CONNECTION_STATUS_CHANGED', 'WhatsApp', 'WhatsAppSetting', $settings->id, ['status' => $old], ['status' => $status]);
            }

            return $settings;
        });
        if ($throwOnChange && $settings->device_id !== $device) {
            throw new GowaException('The company WhatsApp device changed.');
        }

        return $settings;
    }
}
