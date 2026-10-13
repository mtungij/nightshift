<?php

namespace App\Services\WhatsApp;

use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\WhatsAppSetting;
use App\Services\Gowa;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
            if (! $old['enabled'] && ($options['enabled'] ?? false)) {
                // Starting an integration must not notify recipients about old history.
                $settings->audit_cursor = (int) AuditLog::max('id');
            }
            $settings->options = [...$old, ...array_intersect_key($options, config('whatsapp.defaults'))];
            $settings->save();

            return $settings;
        });
    }

    public function connect(): WhatsAppSetting
    {
        Gate::authorize('whatsapp.manage_settings');
        $settings = app(TransactionService::class)->run(function () {
            $settings = $this->current();
            if (! $settings->device_id) {
                $settings->update(['device_id' => 'nightshift-'.app(TenantContext::class)->companyId().'-'.strtolower((string) Str::ulid())]);
            }

            return $settings;
        });
        if (! $settings->provisioned) {
            // Only our server-generated, reserved company slot may be created.
            app(Gowa::class)->createDevice($settings);
            $settings->update(['provisioned' => true, 'connection_status' => 'DISCONNECTED']);
        }

        return $settings;
    }

    public function check(WhatsAppSetting $settings): WhatsAppSetting
    {
        if (! $settings->device_id) {
            $settings->update(['connection_status' => 'NOT_CONFIGURED', 'last_checked_at' => now(), 'jid' => null]);

            return $settings;
        }
        try {
            $data = app(Gowa::class)->deviceStatus($settings)['results'];
            $state = strtolower((string) ($data['state'] ?? $data['status'] ?? ''));
            $connected = in_array($state, ['logged_in', 'connected'], true)
                || (($data['is_logged_in'] ?? false) === true && ($data['is_connected'] ?? true) === true);
            if (($data['is_logged_in'] ?? null) === false) {
                $connected = false;
            }
            $status = $connected ? 'CONNECTED' : ($state === 'connecting' ? 'CONNECTING' : 'DISCONNECTED');
            $jid = $data['jid'] ?? $data['phone_number'] ?? null;
            $settings->update(['connection_status' => $status, 'last_checked_at' => now(), 'jid' => is_string($jid) && preg_match('/^[0-9A-Za-z@.:-]{1,100}$/D', $jid) ? $jid : null]);
        } catch (GowaException $error) {
            $settings->update(['connection_status' => 'DISCONNECTED', 'last_checked_at' => now(), 'jid' => null]);
            throw $error;
        }

        return $settings;
    }

    /** @return array<string, string|int> */
    public function link(string $mode, ?string $phone = null): array
    {
        Gate::authorize('whatsapp.manage_settings');
        $settings = $this->connect();
        $path = '/devices/'.rawurlencode((string) $settings->device_id);
        if ($mode === 'reconnect') {
            app(Gowa::class)->post($settings, $path.'/reconnect');
            $settings->update(['connection_status' => 'CONNECTING']);

            return ['status' => 'CONNECTING', 'device_id' => (string) $settings->device_id];
        }
        if ($mode === 'code') {
            $data = app(Gowa::class)->post($settings, $path.'/login/code?phone='.rawurlencode((string) $phone))['results'];
            $code = $data['pair_code'] ?? null;
            if (! is_string($code) || ! preg_match('/^[A-Za-z0-9-]{4,30}$/D', $code)) {
                throw new GowaException('Unexpected WhatsApp pairing response.');
            }
            $settings->update(['connection_status' => 'CONNECTING']);

            return ['pair_code' => $code, 'device_id' => (string) $settings->device_id];
        }
        $data = app(Gowa::class)->get($settings, $path.'/login')['results'];
        if (! is_string($data['qr_link'] ?? null)) {
            throw new GowaException('Unexpected WhatsApp QR response.');
        }
        $settings->update(['connection_status' => 'CONNECTING']);

        return ['device_id' => (string) $settings->device_id, 'qr_image' => app(Gowa::class)->qrImage($settings, $data['qr_link']), 'expires_in' => min(120, max(1, (int) ($data['qr_duration'] ?? 30)))];
    }
}
