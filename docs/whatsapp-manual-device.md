# Externally managed WhatsApp devices

Nightshift now consumes an existing linked Gowa device. An authorized administrator creates and links the device at `https://notify.buildcore.site`, copies its Device ID, enters it in **WhatsApp Notifications → Connection**, and selects **Save Device ID**. Saving checks the existing status endpoint. **Test Connection** checks the saved company device; after editing, save first. Test Message click deduplication is bound to the saved device: repeated clicks keep the same request, while saving a different ID allows a new test request. Notification enablement requires a saved ID and a successful connection check.

The existing `WhatsAppSetting` remains the company-specific source of truth. IDs are trimmed and restricted to 1–100 characters: an ASCII letter/digit followed by letters, digits, underscore, period or hyphen. URLs and whitespace are rejected. The existing database-wide unique Device ID constraint is preserved, so the same device cannot be assigned to two companies. Provider URL, Basic Auth and webhook credentials remain server-side; they are not accepted through the Connection form.

Nightshift no longer generates IDs or creates devices. QR/pairing/reconnect UI, rendering and provisioning service methods are removed. Former `/connection/qr`, `/connection/code` and `/connection/reconnect` actions return 404 without provider requests. Confirmed-unused `Gowa::createDevice()` and `Gowa::qrImage()` helpers are removed; generic safe provider methods remain. The console `whatsapp:assign-device` command now uses the same validated assignment/status service.

No schema migration or historical rewrite is required. Existing generated IDs remain untouched until explicitly replaced. `provisioned` is retained as a compatibility marker that a Device ID has been assigned. Manual saves set it true; it never means Nightshift created/linked the remote device, and it never gates status checks or sending. Older false values remain usable. No bulk rewrite or schema migration is needed for this interpretation.

Connection results are CONNECTED, DISCONNECTED, NEEDS_RELINKING, NOT_FOUND, PROVIDER_ERROR, or the existing NOT_CONFIGURED/CONNECTING states. Both provider boolean flags must be true to establish a boolean-based connected state; existing documented connected state strings remain supported, with explicit false flags taking precedence. HTTP 404 shows the requested localized not-found message. Other provider failures show a safe status, never a raw provider body. A late response for a replaced ID cannot verify the replacement. Network requests run outside the company transaction lock.

Existing permissions gate device edits (`whatsapp.manage_settings`) and tests (`whatsapp.view_settings`). The same editable field becomes read-only for viewers without management permission. Audit history records saves/changes, requested connection tests and changed connection status. Device changes use the friendly **WhatsApp Device Updated / Kifaa cha WhatsApp Kimebadilishwa** label. Credentials are never audited.

Schedules, recipients, report data, Business Unit scope, PDFs, timezone/language, alerts, deduplication and existing retry policy are preserved. New deliveries use the manually configured ID in `X-Device-Id`. Queued deliveries bound to an old ID are safely skipped rather than silently rerouted; their logs remain. Disconnection does not delete schedules or alter business transactions. Delivery status checks preserve existing provider-error retry semantics. Replacing a device does not reset report history or schedule period deduplication, so an already dispatched period is not automatically resent.

The Connection card reuses existing Nightshift styles, shell, themes and language controls. Help text uses the requested EN/SW wording. The external device-manager link uses HTTPS, a new tab and `noopener noreferrer`. Long IDs stay inside the responsive input; phone identity and last check are displayed from existing status data rather than accepted as a provisioning input.

## Changed files

- `app/Services/WhatsApp/SettingsService.php`: validated manual assignment, enablement gate, safe status persistence, audits and stale-response guard; provisioning retired.
- `app/Services/Gowa.php`: unused provisioning/QR helpers removed; existing authenticated status/send client retained.
- `app/Http/Controllers/WhatsAppController.php`: device save and status endpoints; old provisioning actions retired.
- `app/Jobs/WhatsApp/SendWhatsAppMessageJob.php`: retain existing provider exception semantics during worker status checks.
- `app/Models/WhatsAppSetting.php`: legacy `provisioned` compatibility interpretation documented.
- `app/Services/AuditTrailService.php`: friendly connection audit labels.
- `routes/console.php`: existing assignment command reuses manual assignment service.
- `resources/views/whatsapp/settings.blade.php`: manual device form, status/phone/check details, external manager link; provisioning UI removed.
- `resources/js/whatsapp.js`: manual save/status handling and save-before-test behavior; QR/pairing rendering retired.
- `lang/en/ui.php`, `lang/sw/ui.php`: connection instructions, statuses and audit labels.
- `tests/Feature/WhatsAppIntegrationTest.php`, `tests/Feature/WhatsAppSchedulesTest.php`: manual assignment, isolation, validation, permissions, no secrets/provisioning, status races, schedule preservation and new-device delivery regression checks.
- `scripts/browser-whatsapp-manual-device.mjs`: actual local form saves and status checks across mobile/desktop, languages and themes with mocked provider requests.
- `scripts/browser-whatsapp-connection.mjs`: forwards the superseded read-only browser runner to the new manual-device checks.
- `docs/whatsapp-manual-device.md`: current setup/validation report; earlier connection/scheduled-report documentation now links here for the superseding setup workflow.

## Validation

Completed checks:

| Validation | Result |
| --- | --- |
| Complete Laravel suite (all 42 test files, bounded groups) | 464 tests / 4,340 assertions passed |
| Focused WhatsApp tests | All 46 included in the final passing suite |
| Final focused MySQL 8.4 WhatsApp tests | 46 tests / 265 assertions passed |
| PHPStan | Passed, zero errors |
| Pint and frontend formatting | Passed |
| Production frontend build and artifact verification | Passed |
| Existing frontend tests | 3 passed |
| Blade compilation and whitespace checks | Passed |
| Browser form saves/status checks | 29 passed; no browser exceptions or server errors |
| Viewports | 390px, 412px and 1440px |
| Languages/themes | English/Kiswahili and light/dark |

The broader MySQL 8.4 report/concurrency run passed 95 tests / 766 assertions. It overlaps the focused run; these are not additive unique counts. All automated provider calls are mocked. No production deployment or real WhatsApp message was performed.

## Limits

A status check verifies the configured device at that moment; it cannot guarantee future connectivity or remote delivery/read receipts. Provider credentials and device linking must be managed on the external server. Existing conservative handling of ambiguous sends remains unchanged. Production scheduler/worker/PDF prerequisites remain those in `whatsapp-scheduled-reports.md`.
