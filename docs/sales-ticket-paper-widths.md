# Nightshift 58mm and 80mm sales tickets

Company Settings → Sales ticket printing → **Paper Width** now offers **58mm** and **80mm**. Kiswahili displays **Upana wa Karatasi**. Existing companies keep the 80mm default; selecting a width changes only that company's presentation preference. No database migration is needed for this extension.

The ticket page reads the current company preference on every render, including reprints and previously issued orders. Width is not added to the immutable sale snapshot or used to change print-batch creation, copy selection, dispatch claims, accounting, payments, stock or ledger posting.

| Setting | Physical page | Safe content | Page margin | Layout |
| --- | --- | --- | --- | --- |
| 58mm | 58mm | 48mm | 0 | 5mm safe inset on each side; stacked metadata and item blocks |
| 80mm | 80mm | 72mm | 4mm | Existing table and spacing preserved |

The 58mm layout uses 11px monospace text, a 16px copy label and a prominent 16px total. Product names and units wrap freely. Invoice/date/waiter/table/counter labels sit above their values. Each item shows its full name, formatted quantity × unit, unit price and line total, without a wide table. The waiter balance uses a stacked **Outstanding Balance / Salio Linalodaiwa** label and a single-line amount. Business name text is used without a logo.

Amounts are grouped using string operations, preserving the stored decimal digits without float conversions. Quantities use the existing presentation formatter: `4.000000` becomes `4`, while `1.500000` and `0.750000` become `1.5` and `0.75`. Database precision and financial calculations are unchanged.

CSS uses a valid explicit page width and a calculated roll length, for example `@page { size: 58mm 180mm; margin: 0; }`. The length is fitted to the taller copy before printing; the initial CSS fallback is 200mm. A single length followed by `auto` is not a valid custom CSS page size, so the existing measured-length approach is retained for both widths. Each copy starts on its own page.

## Printer setup

Use the printer manufacturer's normal browser/OS driver; no printer brand or ESC/POS connection is assumed.

- Select **58mm** paper in Nightshift and the printer driver.
- Use **100% / actual size** scale. Disable shrink-to-fit if the driver offers it.
- Set margins to **none/minimum**, with browser headers and footers **off**.
- Set driver copies to **1**: Nightshift already supplies both ticket pages.
- Check the driver's roll length and cutter/page-feed settings. Actual safe margins vary by printer.

For 80mm printers, select 80mm in both places; Nightshift retains the original 4mm page margins and 72mm content width.

Physical output, driver support for custom roll lengths, cutting, and mobile system print services still require testing on the target 58mm hardware. Browser/PDF checks verify layout and print requests, rather than confirming a physical printer completed the job. Auto-print remains a browser/system print dialog.

## Files for this extension

- `app/Http/Requests/NightshiftCommandRequest.php`: accept the two paper widths.
- `app/Http/Controllers/NightshiftController.php`: pass the current company width to ticket views.
- `app/Support/TicketAmountFormatter.php`: presentation-only digit grouping.
- `resources/views/issue-ticket.blade.php`: paper classes, narrow CSS and measured page width/length.
- `resources/views/partials/sales-tickets-58.blade.php`: dedicated stacked narrow layout.
- `resources/views/partials/sales-tickets-80.blade.php`: original 80mm ticket markup.
- `resources/js/nightshift.js`: selectable company paper width, preserving the selected value.
- `lang/en/ui.php`, `lang/sw/ui.php`: balance and paper-width labels.
- `tests/Feature/SalesTicketPrintingTest.php`: width/default/tenant/render/reprint coverage.
- `scripts/browser-sales-tickets-smoke.mjs`: both-width layout/print validation.
- `scripts/sales-ticket-browser-fixtures.php`: optional long-name/large-price synthetic fixture.
- `scripts/validate-sales-ticket-pdfs.py`: PDF page count, paper width, full product names, copy labels and printable-area bounds.
- `docs/sales-ticket-printing.md`, `docs/sales-ticket-paper-widths.md`: feature and printer guidance.

Validation commands use the existing Laravel and MySQL profiles. The browser runner accepts `SMOKE_PAPER_WIDTH=58mm` or `80mm`, `SMOKE_USERNAME` from the fixture output, and an optional `SMOKE_OUTPUT` directory. Synthetic stress data can be prepared with `php scripts/sales-ticket-browser-fixtures.php --narrow-stress`, only against the guarded disposable validation database. Run database tests before browser tests; both use that disposable database.

PDF validation uses the installed Poppler tools (`pdfinfo`, `pdftotext`):

```sh
python3 scripts/validate-sales-ticket-pdfs.py /tmp/nightshift-80mm-ticket-browser 80
python3 scripts/validate-sales-ticket-pdfs.py /tmp/nightshift-58mm-ticket-browser 58
```

No production deployment is included.

## Final validation

Full regression suites passed on 4 November 2026:

| Check | Result |
| --- | --- |
| Laravel / SQLite | 236 tests, 2,479 assertions passed |
| Isolated MySQL 8.4.11, port 3340 | 249 tests, 3,570 assertions passed |
| PHPStan level 7 | 0 errors |
| PHP and JavaScript formatting | Passed |
| Production frontend build | Passed |

Final browser/PDF checks completed on 5 November 2026 using isolated Chrome desktop/mobile emulation at 1440px and 390px, both themes and English/Kiswahili:

| Paper | Browser checks | PDFs | Print pages |
| --- | --- | --- | --- |
| 58mm | 107 passed | 11 passed | 22; exactly two per PDF |
| 80mm | 97 passed | 9 passed | 18; exactly two per PDF |

All text stayed inside the safe printable area, including complete long/unbroken product names. The 58mm edge cases included `4`, `1.5`, `0.75`, a `9,999,999,999.99` unit price and outstanding balance, and audited reprint labels. The final Kiswahili maximum-amount PDF was also visually inspected. CSS paper dimensions are 58mm and 80mm; Chrome's PDF metadata reports approximately 57.83mm and 80.09mm after rendering quantization (within 0.2mm of the requested widths).

No browser exceptions or HTTP 5xx responses occurred. Final MySQL reconciliation reported zero negative stock rows, zero stock/movement discrepancies and zero waiter-ledger/invoice discrepancies. Browser money checks use exact integer cents, avoiding floating-point comparison errors in large-price test fixtures.

The accounting, stock, waiter-ledger, payment and sales-ticket service files, print-batch model and existing MySQL concurrency tests were checked byte-for-byte against their state before this extension. Submission and dispatch controller logic is unchanged; only the view parameters were extended.

Review artifacts:

- [58mm browser results](/tmp/nightshift-58mm-ticket-browser/result.json)
- [58mm PDF validation](/tmp/nightshift-58mm-ticket-browser/print-validation.json)
- [58mm English ticket](/tmp/nightshift-58mm-ticket-browser/1440-light-en.pdf)
- [58mm Kiswahili ticket](/tmp/nightshift-58mm-ticket-browser/390-dark-sw.pdf)
- [58mm reprint](/tmp/nightshift-58mm-ticket-browser/reprint.pdf)
- [58mm maximum-amount/fractional-quantity preview](/tmp/nightshift-58mm-ticket-browser/stress-sw.pdf)
- [80mm browser results](/tmp/nightshift-80mm-ticket-browser/result.json)
- [80mm PDF validation](/tmp/nightshift-80mm-ticket-browser/print-validation.json)
- [Final MySQL reconciliation](/tmp/nightshift-58mm-ticket-browser/mysql-reconciliation.json)

The temporary web server and isolated Chrome are stopped after validation. The pre-existing isolated MySQL instance on port 3340 was reused; the normal application server and existing MySQL services were not stopped. No production deployment was performed. Actual 58mm hardware output and driver/cutter behavior remain untested.
