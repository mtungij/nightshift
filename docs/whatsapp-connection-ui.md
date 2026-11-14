# WhatsApp Connection UI refinement

Connection setup is now superseded by [the manual Device ID workflow](whatsapp-manual-device.md). Nightshift no longer provisions or links devices. Historical validation below describes the earlier implementation.

The internal device identifier was already read-only. Its generic label and lack of explanation made it look editable. The Connection and overview now say **WhatsApp Device ID**, with the requested English/Kiswahili help text linked using `aria-describedby`. The input remains selectable, read-only and absent from submitted form data. Its long value truncates safely inside the existing responsive input.

**WhatsApp Phone Number** remains an editable telephone input. QR, pairing, reconnect and status actions keep their existing handlers and backend behavior. Labels use the requested EN/SW wording. The existing status badge is also shown beside the Connection heading, and both badges update together. The page now receives the existing JavaScript translation catalog so refreshed status text remains localized.

Files changed for this refinement:

- `resources/views/whatsapp/settings.blade.php`: labels, help text, accessible read-only identifier, phone label, action labels and existing status badge.
- `resources/views/partials/language.blade.php`: existing client translation catalog on the WhatsApp settings page.
- `lang/en/ui.php` and `lang/sw/ui.php`: requested labels/help and action translations.
- `resources/css/app.css`: bounded connection fields/help and identifier truncation.
- `tests/Feature/WhatsAppIntegrationTest.php`: bilingual markup, read-only device, editable phone, status badges and credential non-disclosure regression coverage.
- `scripts/browser-whatsapp-connection.mjs`: desktop/mobile, language/theme, actual typing resistance and existing action-handler checks.

Validation: 43 focused WhatsApp tests / 236 assertions passed; PHPStan zero errors; Pint and frontend formatting passed; production build/artifact verification passed; Blade compilation and whitespace checks passed. Thirty browser checks cover 390px, 412px and 1440px in English/Kiswahili and light/dark, with no horizontal overflow, browser exceptions or server errors. Browser actions use mocked responses; backend behavior is covered by the existing focused integration tests. No credentials are included in rendered HTML. No real WhatsApp messages were sent and no production deployment was performed.
