# WhatsApp scheduled reports and operational notifications

Connection setup is now superseded by [the manual Device ID workflow](whatsapp-manual-device.md). Nightshift no longer provisions or links devices. Historical validation below describes the earlier implementation.

Implemented locally. No production deployment. No real WhatsApp messages were sent during validation.

## Integration and files

Integration source: `/home/james/Downloads/LARAVEL.md`, read before implementation. The existing `App\Services\Gowa` remains the single provider client: `https://notify.buildcore.site`, server-side Basic Auth, a saved per-company `X-Device-Id`, `/devices/{id}/status`, `/send/message`, and multipart `/send/file`. Existing QR/pairing/reconnect and the `whatsapp:assign-device` command remain available. Company device IDs cannot be overridden by browser requests. Provider responses/errors never expose authentication, webhook secrets, tokens or PINs.

New migration: `2026_11_11_000007_add_whatsapp_report_schedules.php`. It adds the schedule table, recipient unit/type/language preferences, and delivery scope/snapshot/attachment state. It does not rewrite financial records or historical operational attribution. Existing delivery keys remain recognized; old unscoped queued reports are skipped if their recipient has since been restricted to a unit.

Main implementation:

- Models: `WhatsAppReportSchedule`, extended `WhatsAppRecipient` and `WhatsAppDeliveryLog`.
- Existing WhatsApp services extended: `Scheduler`, `Outbox`, `RecipientService`, `SettingsService`, `OperationalAlerts`, `CompanyContext`, `ReportBuilder`.
- Shared report composition: `app/Services/OperationalPeriodReportService.php`; treasury report service gains an internal exact operating-period interval using its existing queries.
- Controllers: new `WhatsAppScheduleController`; existing WhatsApp and workspace shell controllers extended. Routes remain under authenticated WhatsApp settings middleware.
- Existing send, health and operational-scan jobs extended. `config/whatsapp.php`, `config/gowa.php`, `config/queue.php` configure types, preferences and the isolated queue connection.
- UI: `resources/views/whatsapp/{settings,recipient-form,schedule-form}.blade.php`, `resources/js/whatsapp.js`, dashboard navigation, existing CSS and EN/SW dictionaries. The page now uses the existing authenticated dashboard shell, sidebar, topbar, drawer, theme and language controls.
- Tests: `WhatsAppSchedulesTest`, updated integration/schema checks and two real-process MySQL concurrency cases. Browser/fixture/delivery scripts are prefixed `whatsapp-schedules-` or `browser-whatsapp-schedules`.

## Schedules, periods and recipients

Each company can create independent daily, weekly, monthly and treasury-summary schedules. Every schedule has its own enabled state, send time, IANA timezone, operating cutoff, scope, recipients, format, language, grace window and optional branch/unit breakdown. The new schedule defaults in the UI are 06:00 delivery / 05:00 cutoff; neither is a hard-coded execution time.

A daily send at 06:00 with a 05:00 cutoff covers the completed operating period from the previous day's 05:00 to today's 05:00. A send before the cutoff selects the previous completed period. Weekly schedules cover seven completed operating days ending at the cutoff associated with their scheduled weekday. Monthly schedules cover the most recently completed operating calendar month; delivery day controls when it is sent, not which calendar month is complete. Days such as the 31st clamp to the month's final day; end-of-month delivery is explicitly supported. This monthly behavior is stated in the form.

The scheduler checks today and yesterday in each schedule's timezone so a short grace window can cross midnight. Default grace is 60 minutes, configurable from 1 to 120. Older missed periods are not replayed indefinitely; queued scheduled reports that have passed their delivery grace window are skipped before contacting Gowa. Legacy company daily settings remain a compatibility fallback only until the first explicit daily schedule is saved; inactive legacy time controls are hidden once explicit daily schedules exist. Weekly/monthly/treasury schedules do not disable the legacy daily fallback.

Recipients have company-owned names/numbers/labels, branch and Business Unit scope, active state, optional EN/SW language, and a per-type report/alert matrix. Treasury-only messages and attachments exclude sales and invoice details. Whole-company schedules reject conflicting narrower location IDs; the form enables only the relevant branch/unit/counter controls. Legacy recipient booleans are retained for old integrations; an explicit matrix takes precedence. Each scheduled delivery is intersected with both the recipient's scope and the schedule creator's current authorization. Owner schedules can consolidate authorized branches or produce separate branch messages. Managers retain existing recipient/manual-report permissions and can see only recipients/logs wholly within their permitted branches and units. They do not gain global schedule/device/threshold management grants.

Manual reports select a named operating date. The default operating date respects the company cutoff, including the hours after midnight before cutoff. Test requests send only to configured active recipients and use the same durable queued outbox.

## Reporting source of truth

`OperationalPeriodReportService` selects actual saved operational shifts by their opening period and immutable unit context. It composes existing `ReportService` outputs; WhatsApp does not run a separate sales, payment, pricing, debt or cashier calculation path. Expected/counted cashier totals aggregate existing per-shift figures, including opening floats; they are not a net company cash balance. The treasury balance remains a separate existing report metric. Cashier sales use issuing shift identity, collections use receiving shift identity, waiter amounts use waiter sessions, and source treasury receipts use the linked handover. No activity is assigned by clock overlap alone. Legacy operations without the relevant stored shift links remain in general/date reports and are not synthesized into shift-aware totals.

A shift opened at 18:00 and closed at 03:20 remains one shift. Closed projections retain existing as-of-close behavior; open shifts show OPEN / LIVE and reflect activity at report generation. Earlier open shifts are listed separately and are not repeatedly added to a later period's sales. Shift-close notifications use existing finalized shift, report, stock snapshot and handover presentation sources and are signaled only after commit. Rolled-back closes do not notify.

Stock quantities/variance use existing reporting and final reconciliation data. Concurrent cashier snapshots at a shared counter are not added together; the latest selected closing counter snapshot is presented once. Unsupported metrics, including opening/closing monetary stock valuation and invented shift attribution for unrelated documents, are omitted rather than fabricated. Whole-branch treasury expenses/purchases/wages/deposits/current custody balance are included only when the recipient has the complete branch scope; unit/counter reports omit those unassigned shared cash amounts. Source-shift cashier receipts remain available within their real scope.

Messages use compact phone-friendly sections. Long detail lists are shortened with a visible notice; totals remain complete. PDFs preserve full available detail, within the existing PDF row/size limits. Oversized PDFs fail safely without sending a partial report.

## Alerts and delivery guarantees

Optional company alerts: shift close, large sale, shortage, waiter debt, low stock, approved void, breakage, other approved adjustment, complimentary consumption and cash handover. Existing thresholds and authoritative minimum stock/debt sources are reused. Alert recipients are filtered by the saved source unit; related shift context uses stored relationships. Shared central-store alerts without a single unit attribution are conservatively limited to recipients with all-unit access. Unchanged low stock does not repeatedly alert after cooldown; replenishment/threshold reset or a meaningful changed quantity can re-arm it.

Audit signals are queued after commit. The operational scanner advances a durable audit cursor only with outbox writes. Provider errors and queue outages never roll back sales, payments, stock, shifts, handovers, treasury or settlements. Health and scanner jobs are unique per company with bounded leases; device-health checks are queued at most every 15 minutes. Explicit admin setup/check actions reuse the existing provider integration; business transactions and report/event sends never wait for provider delivery.

Schedule dispatch is serialized per company and recipient-period. All branch messages for a recipient-period are created atomically; repeated ticks, changed send times/scopes in the same period, concurrent schedulers and worker retries do not create a second scheduled dispatch. The durable key additionally identifies the delivery scope. Scope lists are canonicalized. Manual and scheduled reports are generated under the requesting/creating user's existing ERP report permissions, not elevated administrator permissions. Prepared retries are cancelled if that authorization or its assignments change. Treasury previews in delivery logs require treasury oversight permission. Delivery authorization is checked again in the worker, including recipient/device changes, schedule disabling, branch/unit restrictions and creator authorization.

Three attempts use the documented backoff policy `[10, 60, 300]` (the three-attempt limit normally uses the first two retry delays). Confirmed text and PDF message IDs are stored separately. A retry of an explicitly rejected PDF send does not resend confirmed text. The generated message/report data and private PDF are retained for consistent retries. Text+PDF preparation completes before the first text send.

The supplied Gowa documentation does not document an idempotent send key or an authoritative message lookup. Exactly-once delivery across the remote HTTP boundary cannot be guaranteed. Timeouts after sends, success without a message ID and interrupted SENDING attempts are treated as ambiguous failures requiring verification, not automatically resent. Explicit retryable provider errors are retried within the attempt limit. SENT means provider acceptance with a message ID; it does not claim recipient delivery/read receipts.

Logs contain recipient/type, company, frozen reporting period/scope, queue/attempt times, attempt count, safe errors, message previews, text/PDF provider IDs and attachment status. PDFs use private local storage, not public document URLs. Audit records cover device-link requests, settings/threshold changes, recipients, schedules and requested tests/manual reports without credentials.

## Production requirements (not executed)

After normal review/deployment approval, apply the new migration and configure server-side `GOWA_URL`, `GOWA_USERNAME`, `GOWA_PASSWORD`. Assign/link a device per company. Start the normal Laravel scheduler every minute (`php artisan schedule:run`) and a dedicated worker:

```sh
php artisan queue:work whatsapp --queue=whatsapp --timeout=240 --tries=3
```

The new `whatsapp` connection uses the existing database jobs table with `retry_after=300`, longer than the 240-second PDF-capable worker timeout. Replace any older worker consuming this queue through a shorter reservation/timeout configuration. Normal application queue behavior is unchanged. Use the existing shared cache/locking infrastructure for scheduler and unique-job locks. Chromium/Node and the existing PDF renderer must be available; private storage must be writable and monitored/retained under the site's operational policy. Restart workers/config cache through the normal deployment process. None of these production actions were performed here.

## Validation evidence

Completed local validation:

| Check | Result |
| --- | --- |
| Complete Laravel suite, run in bounded groups | 460 tests, 4,279 assertions passed |
| Broader MySQL integration/concurrency run | 128 tests, 2,199 assertions passed |
| Final focused MySQL WhatsApp/schema run | 45 tests, 239 assertions passed |
| Final concurrent scheduler/sender checks | 2 tests, 22 assertions passed |
| PHPStan, Pint and frontend formatting | Passed; zero PHPStan errors |
| Production frontend build and artifact verification | Passed |
| Existing frontend tests and Blade compilation | Passed |
| Browser checks | 83 passed; no browser exceptions or server errors |
| Viewports | 390px, 412px, 768px, 1440px and 1920px |
| Language/theme combinations | English/Kiswahili, light/dark |
| Existing historical table hashes | All 13 unchanged |
| Report reconciliation | Screen, CSV, PDF and both WhatsApp snapshots agree: sales 6,000; two invoices |
| Stock/debt reconciliation | Physical and movement stock both 8; waiter outstanding 6,000 |

The MySQL runs overlap; their counts must not be added as distinct tests. Browser coverage includes authenticated shell/navigation, all six tabs, mobile drawer, responsive forms/lists, saved schedule, test request deduplication, language/theme and absence of horizontal overflow. Two recipient deliveries used real Chromium-generated English/Kiswahili PDFs and mocked provider text/file endpoints. Four separate browser passes left four queued test requests; repeated clicks within each pass were deduplicated.

Automated/browser provider calls were mocked and failed closed for unexpected requests. Local browser fixtures used an isolated company in a disposable database. No real WhatsApp messages were sent, and no production deployment, migration or worker startup was performed. Delivery acceptance/read-receipt and remote idempotency limitations are described above.
