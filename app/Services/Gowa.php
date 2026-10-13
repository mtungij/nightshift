<?php

namespace App\Services;

use App\Domain\TenantContext;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\GowaException;
use App\Support\TanzanianPhone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class Gowa
{
    private function client(WhatsAppSetting $settings): PendingRequest
    {
        // All callers, including queue workers, must establish the company context.
        abort_unless($settings->company_id === app(TenantContext::class)->companyId(), 403);
        if (! $settings->device_id || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D', $settings->device_id)) {
            throw new GowaException('Configure a company WhatsApp device first.');
        }
        if (! WhatsAppSetting::whereKey($settings->id)->where('device_id', $settings->device_id)->exists()) {
            throw new GowaException('The device is not assigned to this company.');
        }
        $url = rtrim((string) config('gowa.url'), '/');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS) || ! config('gowa.password')) {
            throw new GowaException('The WhatsApp server is not configured.');
        }

        return Http::baseUrl($url)->withBasicAuth((string) config('gowa.username'), (string) config('gowa.password'))
            ->withHeaders(['X-Device-Id' => $settings->device_id])->acceptJson()
            ->connectTimeout((int) config('gowa.connect_timeout'))->timeout((int) config('gowa.timeout'))
            ->withOptions(['allow_redirects' => false, 'verify' => true]);
    }

    private function safePath(WhatsAppSetting $settings, string $path): void
    {
        // Generic methods cannot be used to access another slot or enumerate the server.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || parse_url($path, PHP_URL_HOST) || parse_url($path, PHP_URL_SCHEME)) {
            throw new GowaException('Unsupported WhatsApp operation.');
        }
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        if (array_key_exists('device_id', $query)) {
            throw new GowaException('The device is selected by the company settings.');
        }
        $device = '/devices/'.rawurlencode((string) $settings->device_id);
        $allowed = [$device.'/status', $device.'/login', $device.'/login/code', $device.'/reconnect', '/user/check', '/send/message', '/send/file'];
        if (! in_array(parse_url($path, PHP_URL_PATH), $allowed, true)) {
            throw new GowaException('Unsupported WhatsApp operation.');
        }
    }

    /** @return array<string, mixed> */
    private function result(Response $response): array
    {
        if (! $response->successful() || $response->json('code') !== 'SUCCESS') {
            // Never surface or log provider bodies/exception strings containing credentials.
            throw new GowaException('WhatsApp provider rejected the request (HTTP '.$response->status().').', $response->status(), in_array($response->status(), [429, 502, 503, 504], true));
        }
        $result = $response->json('results', []);
        if (! is_array($result)) {
            throw new GowaException('Unexpected WhatsApp response.');
        }

        return ['results' => $result, 'http_status' => $response->status(), 'provider_status' => 'SUCCESS'];
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(WhatsAppSetting $settings, string $path, array $query = []): array
    {
        $this->safePath($settings, $path);
        if (array_key_exists('device_id', $query)) {
            throw new GowaException('The device is selected by the company settings.');
        }
        try {
            return $this->result($this->client($settings)->get($path, $query));
        } catch (ConnectionException) {
            throw new GowaException('WhatsApp connection could not be established.', retryable: true);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(WhatsAppSetting $settings, string $path, array $payload = []): array
    {
        $this->safePath($settings, $path);
        if (array_key_exists('device_id', $payload)) {
            throw new GowaException('The device is selected by the company settings.');
        }
        try {
            return $this->result($this->client($settings)->post($path, $payload));
        } catch (ConnectionException) {
            throw new GowaException('WhatsApp delivery could not be confirmed. Check the device before resending.', ambiguous: true);
        }
    }

    /** @return array<string, mixed> */
    public function sendText(WhatsAppSetting $settings, string $phone, string $message): array
    {
        return $this->post($settings, '/send/message', ['phone' => TanzanianPhone::normalize($phone), 'message' => $message]);
    }

    /** @return array<string, mixed> */
    public function sendFile(WhatsAppSetting $settings, string $phone, string $contents, string $filename, string $caption = ''): array
    {
        try {
            return $this->result($this->client($settings)->attach('file', $contents, basename($filename))->post('/send/file', ['phone' => TanzanianPhone::normalize($phone), 'caption' => $caption]));
        } catch (ConnectionException) {
            throw new GowaException('WhatsApp file delivery could not be confirmed.', ambiguous: true);
        }
    }

    /** @return array<string, mixed> */
    public function deviceStatus(WhatsAppSetting $settings): array
    {
        return $this->get($settings, '/devices/'.rawurlencode((string) $settings->device_id).'/status');
    }

    public function isOnWhatsApp(WhatsAppSetting $settings, string $phone): bool
    {
        $response = $this->get($settings, '/user/check', ['phone' => TanzanianPhone::normalize($phone)]);

        return ($response['results']['is_on_whatsapp'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function createDevice(WhatsAppSetting $settings): array
    {
        // Payload contains only the documented custom device_id. No webhook/inbound setup.
        try {
            return $this->result($this->client($settings)->post('/devices', ['device_id' => $settings->device_id]));
        } catch (ConnectionException) {
            throw new GowaException('Device creation could not be confirmed. Check the saved slot before retrying.');
        }
    }

    public function qrImage(WhatsAppSetting $settings, string $url): string
    {
        $base = rtrim((string) config('gowa.url'), '/');
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (! str_starts_with($url, $base.'/statics/images/qrcode/') || parse_url($url, PHP_URL_QUERY) || ! preg_match('~^/statics/images/qrcode/[A-Za-z0-9_.-]+\.png$~D', $path)) {
            throw new GowaException('Unexpected WhatsApp QR image address.');
        }
        try {
            $response = $this->client($settings)->get($path);
            if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/png') || strlen($response->body()) > 1024 * 1024) {
                throw new GowaException('The WhatsApp QR image is unavailable.');
            }

            return 'data:image/png;base64,'.base64_encode($response->body());
        } catch (ConnectionException) {
            throw new GowaException('The WhatsApp QR image is unavailable.');
        }
    }
}
