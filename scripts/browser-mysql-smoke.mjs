import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-mysql-browser";
await fs.mkdir(output, { recursive: true });
// This debugging port belongs to the isolated validation Chrome profile.
for (const tab of await (await fetch(`${debug}/json/list`)).json()) {
    if (tab.type === "page") await fetch(`${debug}/json/close/${tab.id}`);
}
const target = await (
    await fetch(`${debug}/json/new?${encodeURIComponent("about:blank")}`, { method: "PUT" })
).json();
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    ws.addEventListener("open", resolve, { once: true });
    ws.addEventListener("error", reject, { once: true });
});
let seq = 0;
const pending = new Map(),
    errors = [],
    httpErrors = [],
    checks = [];
ws.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (m.id) {
        const p = pending.get(m.id);
        pending.delete(m.id);
        m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result);
    }
    if (m.method === "Runtime.exceptionThrown")
        errors.push(
            m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text,
        );
    if (
        m.method === "Network.responseReceived" &&
        m.params.response.url.startsWith(base) &&
        m.params.response.status >= 500
    )
        httpErrors.push({ url: m.params.response.url, status: m.params.response.status });
});
const c = (method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++seq;
        pending.set(id, { resolve, reject });
        ws.send(JSON.stringify({ id, method, params }));
    });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function run(expression) {
    const r = await c("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
    return r.result.value;
}
function check(name, ok, detail) {
    checks.push({
        name,
        result: ok ? "PASS" : "FAIL",
        ...(detail === undefined ? {} : { detail }),
    });
    console.log(`${ok ? "PASS" : "FAIL"} ${name}`);
    if (!ok) throw new Error(`${name}: ${JSON.stringify(detail)}`);
}
async function wait(expression) {
    const until = Date.now() + 20000;
    while (Date.now() < until) {
        try {
            if (await run(expression)) return;
        } catch {}
        await sleep(200);
    }
    throw new Error(
        `Timed out: ${expression}; modal/toast=${await run('JSON.stringify({modal:document.querySelector(".modal")?.innerText,toast:document.querySelector("#toast")?.innerText})')}; page=${await run("document.body.innerText.slice(0,800)")}`,
    );
}
const click = (selector) =>
    run(
        `(() => { const e=document.querySelector(${JSON.stringify(selector)}); if(!e)throw Error('Missing '+${JSON.stringify(selector)}); e.click(); })()`,
    );
const set = (name, value) =>
    run(
        `(() => { const e=document.querySelector(${JSON.stringify(`[name="${name}"]`)}); e.value=${JSON.stringify(String(value))}; e.dispatchEvent(new Event('input',{bubbles:true})); e.dispatchEvent(new Event('change',{bubbles:true})); })()`,
    );
const snap = () =>
    run(
        `fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(async r=>{if(!r.ok)throw Error('Bootstrap '+r.status);return r.json()})`,
    );
const refresh = async () => {
    await run("window.__smokePreviousDocument=true");
    await c("Page.navigate", { url: `${base}/dashboard?smoke_reload=${Date.now()}#dashboard` });
    await wait(
        'window.__smokePreviousDocument===undefined && !!document.querySelector(".kpi-grid")',
    );
};
async function submit(type) {
    await run(`document.querySelector('[data-form="${type}"]').requestSubmit()`);
    await wait('!document.querySelector(".modal-backdrop")');
}
async function api(command, payload) {
    return run(
        `(async()=>{const r=await fetch('/api/nightshift/commands/'+${JSON.stringify(command)},{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({...${JSON.stringify(payload)},idempotency_key:crypto.randomUUID()})});return {status:r.status,body:await r.json()};})()`,
    );
}
async function shot(name) {
    const r = await c("Page.captureScreenshot", { format: "png", captureBeyondViewport: false });
    await fs.writeFile(`${output}/${name}.png`, Buffer.from(r.data, "base64"));
}
try {
    await c("Runtime.enable");
    await c("Page.enable");
    await c("Network.enable");
    await c("Network.clearBrowserCookies");
    await c("Emulation.setTimezoneOverride", { timezoneId: "Africa/Dar_es_Salaam" });
    await c("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await c("Page.navigate", { url: `${base}/login` });
    await wait('!!document.querySelector("[name=email]")');
    await set("email", "admin@nightshift.test");
    await set("password", "Nightshift!2026");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait('!!document.querySelector(".kpi-grid")');
    const identity = await run(
        `fetch('/api/nightshift/bootstrap').then(r=>({database:r.headers.get('X-Nightshift-Validation-Database'),driver:r.headers.get('X-Nightshift-Validation-Driver')}))`,
    );
    check(
        "Browser server explicitly identifies authorized MySQL database",
        identity.database === "nightshift_validation" && identity.driver === "mysql",
        identity,
    );
    check(
        "Desktop login and database dashboard",
        (await snap()).company === "Nightshift Demo Lounge",
    );
    const views = [
        "dashboard",
        "pos",
        "orders",
        "waiters",
        "cashiers",
        "tables",
        "inventory",
        "shifts",
        "payments",
        "suppliers",
        "reports",
        "approvals",
        "users",
        "branches",
        "counters",
        "settings",
        "audit",
        "notifications",
    ];
    for (const view of views) {
        await click(`[data-nav="${view}"]`);
        check(`Desktop ${view}`, !!(await run('document.querySelector("h1")?.innerText.length')));
    }
    await click('[data-nav="pos"]');
    const before = await snap();
    const servingWaiter = before.servingWaiters.find((w) => w.name.startsWith("Asha"));
    const pinReset = await api("staff-pin", { id: servingWaiter.id, pin: "0123" });
    check("Company Admin configures serving waiter PIN", pinReset.status === 200);
    await refresh();
    await click('[data-nav="pos"]');
    const productId = before.products.find((p) => p.sku === "BR-001").id;
    await click(`[data-action="add-cart"][data-id="${productId}"]`);
    await click('[data-action="send-order"]');
    await wait('!!document.querySelector("[data-form=serving-order]")');
    await click(`[data-action="serving-waiter"][data-id="${servingWaiter.id}"]`);
    await set("pin", "0123");
    await submit("serving-order");
    await wait(
        `fetch('/api/nightshift/bootstrap').then(r=>r.json()).then(s=>s.orders.length===${before.orders.length + 1})`,
    );
    let s = await snap(),
        order = s.orders.find((o) => !before.orders.some((b) => b.id === o.id));
    check("POS PIN send immediately creates issued order", !!order && order.status === "Issued");
    check(
        "Direct send updates stock once and waiter liability without cashier confirmation",
        order.status === "Issued" &&
            Number(s.products.find((p) => p.id === productId).stock) ===
                Number(before.products.find((p) => p.id === productId).stock) - 1,
    );
    check(
        "Committed waiter issue ticket is opened after send",
        await run(
            '!!document.querySelector("#issue-ticket-frame")?.src.includes("/issue-tickets/")',
        ),
    );
    await refresh();
    await click('[data-nav="orders"]');
    const issuedBalance = Number(s.waiters.find((w) => w.id === order.waiter).outstanding);
    await click(`[data-action="payment"][data-id="${order.id}"]`);
    await set("amount[]", "1000");
    await submit("payment");
    s = await snap();
    order = s.orders.find((o) => o.id === order.id);
    check(
        "Partial payment reconciles invoice and waiter balance",
        order.status === "Partially paid" &&
            Number(order.paid) === 1000 &&
            Number(s.waiters.find((w) => w.id === order.waiter).outstanding) ===
                issuedBalance - 1000,
    );
    await click(`[data-action="payment"][data-id="${order.id}"]`);
    const remaining = Number(order.balance),
        first = Math.floor(remaining / 2);
    await set("amount[]", first);
    await click('[data-action="add-payment-line"]');
    await run(
        `const line=document.querySelectorAll('.payment-line')[1];line.querySelector('select').value='M-Pesa';line.querySelector('input').value=${remaining - first}`,
    );
    await submit("payment");
    s = await snap();
    order = s.orders.find((o) => o.id === order.id);
    check(
        "Split payment settles invoice and preserves waiter reconciliation",
        order.status === "Paid" &&
            Number(order.balance) === 0 &&
            Number(s.waiters.find((w) => w.id === order.waiter).outstanding) ===
                issuedBalance - Number(order.total),
    );
    const receipt = await run(
        `fetch('/api/nightshift/receipts/${order.id}').then(async r=>({status:r.status,text:await r.text()}))`,
    );
    check(
        "Receipt contains actual invoice and print styles",
        receipt.status === 200 &&
            receipt.text.includes(order.invoice) &&
            receipt.text.includes("@media print"),
    );
    await refresh();
    await click('[data-nav="reports"]');
    await click('[data-action="report"][data-value="Sales by Category"]');
    await wait('document.querySelector("#report-content")?.innerText.includes("Beer")');
    check("Grouped sales report renders", true);
    const csv = await run(
        `fetch('/api/nightshift/reports/Daily%20Sales/export').then(async r=>({status:r.status,type:r.headers.get('content-type'),text:await r.text()}))`,
    );
    check(
        "CSV export contains persisted invoice",
        csv.status === 200 && csv.type.includes("text/csv") && csv.text.includes(order.invoice),
    );
    await click('[data-action="close-modal"]');
    await click('[data-nav="inventory"]');
    await click('[data-action="tab"][data-value="Goods receipt"]');
    await click('[data-action="grn"]');
    await set("reference", `SMOKE-${Date.now()}`);
    await set("quantity", "2");
    await set("cost", "1000");
    await submit("grn");
    check("Goods receipt posts through browser form", true);
    await click('[data-action="tab"][data-value="Transfers"]');
    await click('[data-action="transfer"]');
    const locations = (await snap()).locations,
        source = locations.find((l) => l.type === "STORE"),
        destination = locations.find((l) => l.name === "Main Counter");
    await set("from", source.id);
    await set("to", destination.id);
    await set("quantity", "1");
    await set("notes", "Browser validation transfer");
    await submit("transfer");
    s = await snap();
    const transfer = s.transfers[0];
    check("Transfer enters transit through browser form", transfer.status === "IN_TRANSIT");
    const received = await api("transfer-receive", { id: transfer.id });
    check(
        "Transfer receipt posts through session API",
        received.status === 200 &&
            (await snap()).transfers.find((t) => t.id === transfer.id).status === "RECEIVED",
    );
    await click('[data-action="tab"][data-value="Closing stock"]');
    await wait(
        'document.body.innerText.includes("Base quantity") && !document.body.innerText.includes("Loading stock history")',
    );
    check("Historical closing stock renders from movement report", true);
    // Browser-origin API calls supplement the forms with returns and approvals.
    s = await snap();
    const unit = s.products.find((p) => p.id === productId).units[0][3];
    const created = await api("order-create", {
        waiter_id: order.waiter,
        stock_location_id: destination.id,
        status: "WAITING_FOR_ISSUE",
        discount: "0",
        items: [{ product_unit_id: unit, quantity: "2" }],
    });
    check("Return/void fixture creation", created.status === 200);
    const secondId = created.body.id;
    check("Return/void fixture issue", (await api("order-issue", { id: secondId })).status === 200);
    s = await snap();
    const second = s.orders.find((o) => o.id === secondId);
    check(
        "Unpaid return posts stock and ledger credit",
        (
            await api("return", {
                id: secondId,
                reason: "Unopened drink recovered in browser validation",
                items: [{ order_item_id: second.items[0].id, quantity: "1" }],
            })
        ).status === 200,
    );
    check(
        "Void request posts",
        (
            await api("void-request", {
                id: secondId,
                reason: "Remaining goods recovered for browser validation",
            })
        ).status === 200,
    );
    s = await snap();
    const approval =
        s.approvals.find((a) => a.type === "Void request" && a.invoice === secondId) ||
        s.approvals.find((a) => a.type === "Void request");
    check(
        "Void approval reverses remaining liability",
        (
            await api("void-decide", {
                id: approval.record_id,
                approved: true,
                reason: "Remaining unopened goods recovered and checked",
            })
        ).status === 200 &&
            (await snap()).orders.find((o) => o.id === secondId).status === "Voided",
    );
    s = await snap();
    const james = s.waiters.find((w) => w.short === "James"),
        debt = Number(james.outstanding);
    await refresh();
    await click('[data-nav="waiters"]');
    await click(`[data-action="waiter-profile"][data-id="${james.id}"]`);
    await click(`[data-action="close-waiter"][data-id="${james.id}"]`);
    check(
        "Manager override form requires a reason",
        await run('!document.querySelector("[data-form=override-shift]").checkValidity()'),
    );
    await set("reason", "Manager approved outstanding debt for validation");
    await submit("override-shift");
    s = await snap();
    check(
        "Manager override closes shift and retains debt",
        !s.waiters.find((w) => w.id === james.id).active &&
            Number(s.waiters.find((w) => w.id === james.id).outstanding) === debt,
    );
    const asha = s.waiters.find((w) => w.short === "Asha");
    for (const o of s.orders.filter((o) => o.waiter === asha.id)) {
        if (["Waiting for issue", "Draft"].includes(o.status))
            check(
                "Cancel unissued order before shift closure",
                (await api("order-cancel", { id: o.id })).status === 200,
            );
        else if (Number(o.balance) > 0)
            check(
                "Settle remaining waiter invoice",
                (
                    await api("payment", {
                        id: o.id,
                        lines: [{ method: "Cash", amount: String(o.balance) }],
                    })
                ).status === 200,
            );
    }
    await refresh();
    await click('[data-nav="waiters"]');
    await click(`[data-action="waiter-profile"][data-id="${asha.id}"]`);
    await click(`[data-action="close-waiter"][data-id="${asha.id}"]`);
    await click('[data-action="confirm-close-waiter"]');
    await wait('!document.querySelector(".modal-backdrop")');
    s = await snap();
    check(
        "Settled waiter shift closes normally",
        !s.waiters.find((w) => w.id === asha.id).active &&
            Number(s.waiters.find((w) => w.id === asha.id).outstanding) === 0,
    );
    await refresh();
    await click('[data-nav="dashboard"]');
    await shot("desktop");
    await c("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    for (const view of views) {
        await click('[data-action="menu"]');
        check(
            `Mobile menu opens for ${view}`,
            await run('document.querySelector("#sidebar").classList.contains("open")'),
        );
        await click(`[data-nav="${view}"]`);
        await sleep(100);
        check(
            `Mobile ${view} renders without page overflow`,
            await run(
                '!!document.querySelector("h1")?.innerText.length && document.documentElement.scrollWidth<=390',
            ),
        );
    }
    await click('[data-nav="pos"]');
    await shot("mobile-pos");
    await click('[data-nav="dashboard"]');
    await shot("mobile-dashboard");
    check("No browser runtime exceptions", errors.length === 0, errors);
    check("No HTTP 5xx responses", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify({ result: "PASS", base, checks, errors, httpErrors }, null, 2),
    );
} catch (error) {
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify(
            { result: "FAIL", base, checks, errors, httpErrors, failure: String(error) },
            null,
            2,
        ),
    );
    console.error(String(error));
    process.exitCode = 1;
} finally {
    ws.close();
}
