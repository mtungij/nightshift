import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8885. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-staff-data-browser";
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
    await sleep(350);
    const r = await c("Page.captureScreenshot", { format: "png", captureBeyondViewport: false });
    await fs.writeFile(`${output}/${name}.png`, Buffer.from(r.data, "base64"));
}
try {
    await c("Runtime.enable");
    await c("Page.enable");
    await c("Network.enable");
    const f = JSON.parse(
        await fs.readFile(
            process.env.STAFF_FIXTURE_FILE || "/tmp/staff-data-browser-fixtures.json",
            "utf8",
        ),
    );
    const navigate = async (path) => {
        const url = new URL(path, base);
        url.searchParams.set("scope_smoke", String(Date.now()));
        await run("window.__oldScopeDocument=true");
        await c("Page.navigate", { url: url.href });
        await wait('window.__oldScopeDocument===undefined && document.readyState==="complete"');
    };
    const login = async (slug) => {
        await c("Network.clearBrowserCookies");
        await navigate("/login");
        await set("username", f.accounts[slug].username);
        await set("pin", "0123");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait('!!document.querySelector("#main h1")');
    };
    const status = (path) =>
        run(
            `fetch(${JSON.stringify(path)},{headers:{Accept:'application/json'}}).then(r=>r.status)`,
        );
    const screen = async (name) => {
        await run(`location.hash=${JSON.stringify(name)}`);
        await sleep(250);
    };
    const resumeMobile = process.env.STAFF_SMOKE_RESUME === "mobile";
    if (resumeMobile) {
        const previous = JSON.parse(await fs.readFile(`${output}/result.json`, "utf8"));
        const desktop = previous.checks.filter((c) => c.name.startsWith("1440/"));
        if (
            !desktop.some((c) => c.name === "1440/Responsive page has no horizontal overflow") ||
            desktop.some((c) => c.result !== "PASS") ||
            previous.errors.length ||
            previous.httpErrors.length
        )
            throw Error("Complete desktop checks must pass before mobile resume");
        checks.push(...desktop);
    }
    for (const width of resumeMobile ? [390] : [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        const label = `${width}/`;
        await login("waiter-a");
        check(
            label + "Waiter role lands in POS",
            await run("document.querySelector('.nav-item.active[data-nav]').dataset.nav==='pos'"),
        );
        let d = await snap();
        check(
            label + "Waiter receives only own orders and accounts",
            d.orders.length > 0 &&
                d.orders.every((o) => o.waiter === f.accounts["waiter-a"].id) &&
                d.waiters.length === 1 &&
                d.waiters[0].id === f.accounts["waiter-a"].id,
        );
        check(
            label + "Waiter receives only own payments and shifts",
            d.payments.every((p) => p.waiter === f.accounts["waiter-a"].id) &&
                d.shifts.every(
                    (s) => s.user_id === f.accounts["waiter-a"].id && !("actual_cash" in s),
                ),
        );
        check(
            label + "POS identity list is own waiter",
            d.servingWaiters.length === 1 && d.servingWaiters[0].id === f.accounts["waiter-a"].id,
        );
        check(
            label + "Other waiter receipt and ticket denied",
            (await status(`/api/nightshift/receipts/${f.orders["waiter-b"]}`)) === 403 &&
                (await status(`/api/nightshift/issue-tickets/${f.orders["waiter-b"]}`)) === 403,
        );
        check(
            label + "Waiter filter and cashier report denied",
            (await status(`/api/nightshift/bootstrap?waiter_id=${f.accounts["waiter-b"].id}`)) ===
                403 && (await status("/api/nightshift/reports/Cashier%20Shift")) === 403,
        );
        const product = d.products.find((p) => p.sku === "BR-001");
        await click(`[data-action="add-cart"][data-id="${product.id}"]`);
        await click('[data-action="send-order"]');
        await wait('!!document.querySelector(".serving-waiters")');
        check(
            label + "POS waiter modal exposes only self",
            await run(
                `document.querySelectorAll('[data-action="serving-waiter"]').length===1 && !!document.querySelector('[data-action="serving-waiter"][data-id="${f.accounts["waiter-a"].id}"]')`,
            ),
        );
        await click(`[data-action="serving-waiter"][data-id="${f.accounts["waiter-a"].id}"]`);
        await set("pin", "0123");
        await submit("serving-order");
        const afterSend = await snap();
        check(
            label + "Own PIN-confirmed POS order still works",
            afterSend.orders.length === d.orders.length + 1 &&
                afterSend.orders.every((o) => o.waiter === f.accounts["waiter-a"].id),
        );
        await navigate("/quick-lock");
        await set("pin", "0123");
        await run(`document.querySelector('form[action$="/quick-lock/unlock"]').requestSubmit()`);
        await wait('!!document.querySelector("#main h1")');
        check(
            label + "Same PIN unlocks staff workspace",
            (await snap()).user.id === f.accounts["waiter-a"].id,
        );
        await screen("waiters");
        check(
            label + "Waiter accounts excludes other waiter names",
            await run(
                `document.querySelector('#main').innerText.includes(${JSON.stringify(f.accounts["waiter-a"].name)}) && !document.querySelector('#main').innerText.includes(${JSON.stringify(f.accounts["waiter-b"].name)})`,
            ),
        );
        await screen("shifts");
        check(
            label + "Own shift page excludes reconciliation fields",
            await run(
                `!document.querySelector('#main').innerText.match(/Actual cash|Expected cash|Cash difference|Fedha halisi|Fedha zinazotarajiwa/) && !!document.querySelector('[data-action="close-waiter"]')`,
            ),
        );
        await shot(label.replace("/", "-") + "waiter-shifts");
        await login("cashier-a");
        check(
            label + "Cashier role lands in waiter accounts",
            await run(
                "document.querySelector('.nav-item.active[data-nav]').dataset.nav==='waiters'",
            ),
        );
        d = await snap();
        check(
            label + "Cashier counter and own reconciliation",
            d.locations.length === 1 &&
                d.locations[0].id === f.main &&
                d.shifts.length > 0 &&
                d.shifts.every((s) => s.user_id === f.accounts["cashier-a"].id),
        );
        check(
            label + "Cashier sees own payment activity",
            d.payments.length === (width === 1440 ? 1 : 2) &&
                d.payments.every((p) => p.waiter === f.accounts["waiter-a"].id) &&
                d.metrics.collected === String(width === 1440 ? "100.00" : "101.00"),
        );
        check(
            label + "Minimal eligible waiter balances",
            d.waiters.some((w) => w.id === f.accounts["waiter-a"].id) &&
                d.waiters.every((w) => !("ledger" in w) && !("paid" in w) && !("shift_id" in w)) &&
                !d.waiters.some((w) => w.id === f.accounts["waiter-vip"].id),
        );
        check(
            label + "Other counter filter and receipt denied",
            (await status(`/api/nightshift/bootstrap?stock_location_id=${f.vip}`)) === 403 &&
                (await status(`/api/nightshift/receipts/${f.orders["waiter-vip"]}`)) === 403,
        );
        let r = await api("shift-close", { id: f.shifts["cashier-b"], actual_cash: "10000" });
        check(label + "Other cashier shift ID denied", r.status === 403);
        r = await api("waiter-payment", {
            waiter_id: f.accounts["waiter-vip"].id,
            stock_location_id: f.vip,
            amount: "1",
            method: "Cash",
        });
        check(label + "Other counter settlement denied", r.status === 403);
        await click(`[data-action="waiter-profile"][data-id="${f.accounts["waiter-a"].id}"]`);
        await wait('!!document.querySelector(".modal")');
        check(
            label + "Settlement modal omits ledger and private history",
            await run(
                `!document.querySelector('.modal').innerText.match(/Transaction ledger|Payment history|Leja ya|Historia ya malipo/) && !!document.querySelector('.modal [data-action="waiter-payment"]')`,
            ),
        );
        await shot(label.replace("/", "-") + "cashier-settlement");
        await click('[data-action="close-modal"]');
        r = await api("waiter-payment", {
            waiter_id: f.accounts["waiter-a"].id,
            stock_location_id: f.main,
            amount: "1",
            method: "Cash",
        });
        check(label + "Allowed counter settlement succeeds", r.status === 200);
        await screen("pos");
        const cashierProduct = (await snap()).products.find((p) => p.sku === "BR-001");
        await click(`[data-action="add-cart"][data-id="${cashierProduct.id}"]`);
        await click('[data-action="send-order"]');
        await wait('!!document.querySelector(".serving-waiters")');
        check(
            label + "Cashier POS lists eligible counter waiters",
            await run(
                `!!document.querySelector('[data-action="serving-waiter"][data-id="${f.accounts["waiter-a"].id}"]') && !!document.querySelector('[data-action="serving-waiter"][data-id="${f.accounts["waiter-b"].id}"]') && !document.querySelector('[data-action="serving-waiter"][data-id="${f.accounts["waiter-vip"].id}"]')`,
            ),
        );
        await click('[data-action="close-modal"]');
        await click('[data-action="clear-cart"]');
        await screen("shifts");
        let current = (await snap()).shift;
        await click('[data-action="close-shift"]');
        await set("actual", Number(current.opening) + Number(current.cashSales));
        await submit("close-shift");
        check(
            label + "Closed shift retains assigned counter",
            (await snap()).location_id === f.main,
        );
        await click('[data-action="open-shift"]');
        await set("counter", f.vip);
        await set("opening", "0");
        await submit("open-shift");
        d = await snap();
        check(
            label + "New counter assignment updates immediately",
            d.location_id === f.vip &&
                d.locations.length === 1 &&
                d.locations[0].id === f.vip &&
                d.waiters.some((w) => w.id === f.accounts["waiter-vip"].id) &&
                !d.orders.some((o) => o.waiter === f.accounts["waiter-a"].id),
        );
        check(
            label + "Previous counter data denied after reassignment",
            (await status(`/api/nightshift/bootstrap?stock_location_id=${f.main}`)) === 403,
        );
        check(
            label + "Shift change needs no refresh warning",
            await run(
                `!document.querySelector('#toast')?.innerText.match(/Refresh this page|Onyesha upya/)`,
            ),
        );
        await click('[data-action="close-shift"]');
        await set("actual", "0");
        await submit("close-shift");
        await click('[data-action="open-shift"]');
        await set("counter", f.main);
        await set("opening", "10000");
        await submit("open-shift");
        for (const slug of ["manager", "supervisor"]) {
            await login(slug);
            d = await run(
                `fetch('/api/nightshift/bootstrap?all_branches=1',{headers:{Accept:'application/json'}}).then(r=>r.json())`,
            );
            check(
                label + slug + " permitted branch only",
                !d.shifts.some((s) => s.user_id === f.accounts["cashier-private"].id) &&
                    !d.branches.some((b) => b.id === f.private_branch),
            );
            check(
                label + slug + " branch ID tampering denied",
                (await status(`/api/nightshift/bootstrap?branch_id=${f.private_branch}`)) === 404,
            );
        }
        await login("owner");
        d = await run(
            `fetch('/api/nightshift/bootstrap?all_branches=1',{headers:{Accept:'application/json'}}).then(r=>r.json())`,
        );
        check(
            label + "Owner sees company-wide staff shifts",
            d.shifts.some((s) => s.user_id === f.accounts["cashier-private"].id),
        );
        r = await api("shift-close", { id: f.foreign_shift, actual_cash: "765432" });
        check(
            label + "Owner cannot access foreign company staff",
            !d.shifts.some((s) => s.id === f.foreign_shift) && r.status === 404,
        );
        await shot(label.replace("/", "-") + "owner-company-shifts");
        check(
            label + "Responsive page has no horizontal overflow",
            await run("document.documentElement.scrollWidth<=innerWidth+1"),
        );
    }
    check("No browser exceptions", errors.length === 0, errors);
    check("No HTTP 5xx", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify({ result: "PASS", checks, errors, httpErrors }, null, 2),
    );
} catch (error) {
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify(
            { result: "FAIL", checks, errors, httpErrors, failure: String(error) },
            null,
            2,
        ),
    );
    console.error(String(error));
    process.exitCode = 1;
} finally {
    ws.close();
}
