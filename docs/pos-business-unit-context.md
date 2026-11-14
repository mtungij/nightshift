# POS Business Unit context

Admin/Owner option scoping and empty states are further refined in [the admin unit-scope report](pos-admin-unit-scope.md).

POS now displays Branch → Business Unit → Counter as explicit operational context. Existing reporting, stock, product availability, pricing, permissions, tenant scope and immutable operational-context records remain the source of truth. No migration or historical rewrite is required.

The Current Order panel includes **Business Unit / Kitengo cha Biashara** between Table and Counter. The branch control filters the unit choices to active authorized units in that branch. The unit selection filters counters and the server-provided catalogue. Incompatible counters are cleared. One eligible counter auto-selects; multiple counters require an explicit choice unless an eligible active-shift counter or explicit current selection already applies. A single authorized unit displays as read-only text. Empty units show the requested localized no-products/no-counters messages.

Cashier/waiter context defaults to the active operational shift and remains fixed under their existing restricted operational permissions. POS terminals always use the assigned unit/counter, shown as read-only text. Broader owner/manager permissions continue to allow authorized selections; an eligible active-shift context remains the default rather than removing existing administrative access. Branch and unit membership never grant additional counter access.

POS-specific bootstrap projection uses `pos_context=1`; general inventory/settings/report bootstrap behavior remains broader according to existing permissions. The server validates requested unit/branch hierarchy, current authorization, active units/counters and fixed shift/terminal context. Order commands carry branch/unit/counter IDs, and OrderService verifies them independently of frontend filtering or the bootstrap flag. Existing callers that omit the additional branch/unit fields retain the validated counter-derived workflow.

The existing BusinessUnitService availability and price sources are reused: unit override → default selling price. Hidden products are excluded before POS search/category filtering and rejected by the backend pricing/availability checks. Physical quantities still come from the selected counter. Without a counter choice, stock is zero and adding products is disabled. Stock sufficiency is rechecked by the existing atomic issue/send operation. Drafts retain existing non-reserving behavior; this change does not introduce stock reservation.

With a nonempty cart, branch/unit/counter switching is blocked with localized guidance. Changing a unit resets stale category/search state. Context reloads block old-catalogue clicks and restore the prior context on failure, preventing an in-flight request from carrying old products into a new unit. Counter changes do not silently clear an existing cart.

Orders retain their immutable unit through the existing operational_unit_contexts records inherited from their waiter shift. Creation now checks the saved shift unit matches the selected counter unit. Historical prices are unchanged. My Orders keeps its existing PIN/session and waiter/cashier scope, adds the selected unit to its view authorization, and queries the order's saved unit in addition to its counter. A changed counter/unit association requires reopening My Orders. Broader authorized history remains available in the existing Orders/reports pages.

Both 58mm and 80mm tickets include the order's saved Business Unit. New ticket batches freeze the label with their existing snapshot. Older batches without this field remain readable and are not rewritten. Existing print widths and two-copy layouts remain intact. Long product/unit names wrap within mobile notices using the existing toast style.

## Files changed

- `app/Services/WorkspaceService.php`: POS projection, context defaults/locks, authorized unit/counter filtering and ID normalization.
- `app/Http/Controllers/NightshiftController.php`: accepts the POS projection flag.
- `app/Http/Requests/NightshiftCommandRequest.php`: validates explicit order branch/unit context.
- `app/Services/OrderService.php`: validates submitted context/counter authorization and immutable shift-unit agreement.
- `app/Services/MyOrdersService.php`: unit-bound view authorization and saved-unit query.
- `app/Services/SalesTicketService.php`: saved order unit in the ticket snapshot.
- `resources/js/nightshift.js`: POS selectors/read-only context, hierarchy, cart protection, safe asynchronous context changes and context-aware navigation.
- `resources/css/app.css`: mobile wrapping for long cart/context notices.
- `resources/views/partials/sales-tickets-58.blade.php`, `sales-tickets-80.blade.php`: compact unit metadata with backwards-compatible older snapshots.
- `lang/en/ui.php`, `lang/sw/ui.php`: requested empty states and cart guidance.
- `tests/Feature/BusinessUnitTest.php`: five focused context/selection/tampering/attribution cases plus branch-ID response coverage.
- `scripts/pos-context-browser-fixtures.php`, `pos-context-browser-verify.php`: disposable fixtures and stock/payment/ledger/history reconciliation.
- `scripts/browser-pos-context.mjs`, `browser-pos-context-mobile-cart.mjs`: desktop/mobile, roles, cart safety, language/theme and terminal PIN browser checks.
- `scripts/render-pos-context-tickets.mjs`: real 58mm/80mm EN/SW PDF validation.
- Production frontend assets were rebuilt through the existing Vite build.

## Validation

| Validation | Result |
| --- | --- |
| Complete Laravel suite, all 42 files in bounded groups | 469 tests / 4,375 assertions passed |
| Final Business Unit + My Orders focused run | 51 tests / 442 assertions passed |
| MySQL 8.4 POS/Business Unit/My Orders/shift/report/ticket regression | 118 tests / 1,101 assertions passed |
| MySQL stock, availability/POS-send and terminal-deactivation races | 3 tests / 35 assertions passed |
| Final MySQL context/default/counter-choice/tampering checks | 5 tests / 35 assertions passed |
| PHPStan | Passed, zero errors |
| Pint and frontend formatting | Passed |
| Production frontend build and artifact verification | Passed |
| Existing frontend tests | 3 passed |
| Blade compilation and whitespace checks | Passed |
| Main browser checks | 32 passed |
| Filled-cart browser checks | 13 passed |
| Browser viewport/language/theme coverage | 390px, 412px, 1440px; EN/SW; light/dark |
| Browser exceptions / HTTP server errors | None |
| Real ticket PDFs | 58mm/80mm × EN/SW, all four passed |

MySQL runs overlap; their totals are not additive distinct-test counts. The first browser run sent one real local terminal/PIN order. Repeat browser runs used read-only validation mode for that already-reconciled order rather than issuing another sale. Filled-cart checks performed no financial mutations.

The isolated browser terminal sale used a saved unit override of 5,000 rather than the company default of 3,000. It was paid in full for 5,000; waiter-ledger debit and credit both equal 5,000. Physical stock and movement-ledger stock both equal 9 after one item was issued from an opening quantity of 10. Hashes of all 13 pre-existing financial/stock/shift history tables remained unchanged. Test fixtures and financial mutations were limited to the explicitly guarded disposable MySQL browser database.

Real Chromium PDFs passed physical-width, saved unit, both-copy and price checks for 58mm and 80mm in English and Kiswahili. Browser provider calls were mocked, and no real WhatsApp messages were sent. Local validation does not deploy or migrate production.

## Existing limits retained

Drafts do not reserve stock; a concurrent sale can reduce availability before the final atomic issue check. Missing legacy unit attribution is not reconstructed from a counter's present assignment. Existing permissions can still provide broader context through general Orders/reports views. This work adds no new accounting, availability or pricing engine.
