import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-shared-pos-browser";
await fs.mkdir(output, { recursive: true });
// Keep a page open while replacing earlier validation tabs.
const target = await (
    await fetch(`${debug}/json/new?${encodeURIComponent("about:blank")}`, { method: "PUT" })
).json();
for (const tab of await (await fetch(`${debug}/json/list`)).json()) {
    if (tab.type === "page" && tab.id !== target.id) await fetch(`${debug}/json/close/${tab.id}`);
}
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
        if (p) m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result);
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
function disconnected() {
    for (const request of pending.values())
        request.reject(new Error("Chrome debugging connection closed"));
    pending.clear();
}
ws.addEventListener("close", disconnected);
ws.addEventListener("error", disconnected);
const c = (method, params = {}) =>
    new Promise((resolve, reject) => {
        if (ws.readyState !== WebSocket.OPEN)
            return reject(new Error("Chrome debugging connection is unavailable"));
        const id = ++seq;
        const timeout = setTimeout(() => {
            pending.delete(id);
            reject(new Error(`Chrome command timed out: ${method}`));
        }, 30000);
        pending.set(id, {
            resolve: (result) => {
                clearTimeout(timeout);
                resolve(result);
            },
            reject: (error) => {
                clearTimeout(timeout);
                reject(error);
            },
        });
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
async function shot(name) {
    const r = await c("Page.captureScreenshot", { format: "png", captureBeyondViewport: false });
    await fs.writeFile(`${output}/${name}.png`, Buffer.from(r.data, "base64"));
}
async function navigate(path) {
    await run("window.__pinPreviousDocument=true");
    const url = new URL(path, base);
    url.searchParams.set("pin_smoke", String(Date.now()));
    await c("Page.navigate", { url: url.href });
    await wait('window.__pinPreviousDocument===undefined && document.readyState==="complete"');
}
const snap = () =>
    run(
        "fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())",
    );
const requests = [];
ws.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (m.method === "Network.requestWillBeSent") requests.push(m.params.request.url);
});
try {
    const safety = await fetch(new URL("/login", base));
    check(
        "Isolated MySQL target",
        safety.headers.get("X-Nightshift-Validation-Database") === "nightshift_validation",
    );
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    await c("Page.addScriptToEvaluateOnNewDocument", {
        source: `window.__intervals=[];const originalInterval=window.setInterval;window.setInterval=(fn,ms,...args)=>{window.__intervals.push(()=>fn(...args));return originalInterval(fn,ms,...args)};window.__printCalls=0;window.print=()=>window.__printCalls++;`,
    });
    const accounts = JSON.parse(
        await fs.readFile("/tmp/nightshift-shared-pos-fixtures.json", "utf8"),
    );
    for (const width of [1440, 390]) {
        const tag = String(width);
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        await c("Network.clearBrowserCookies");
        await navigate("/login");
        await set("username", accounts.cashier.username);
        await set("pin", "4567");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait("location.pathname==='/dashboard'");
        await navigate("/dashboard#pos");
        await wait("!!document.querySelector('.pos-cart')");
        check(
            tag + " no lock navigation or UI",
            await run(
                `!document.querySelector('[data-action="quick-lock"],a[href*="quick-lock"]')&&!/Quick Lock|Funga Skrini/.test(document.body.innerText)`,
            ),
        );
        await run(
            "window.__realNow=Date.now;Date.now=()=>window.__realNow()+21*60000;Promise.all(window.__intervals.map(fn=>fn()))",
        );
        await sleep(700);
        check(
            tag + " no redirect after simulated 21 minute inactivity",
            await run("location.pathname==='/dashboard'"),
        );
        check(
            tag + " authenticated API still usable",
            (await snap()).user.id === accounts.cashier.id,
        );
        await run("Date.now=window.__realNow");
        const before = await snap(),
            product = before.products.find((p) => p.sku === "BR-001");
        await click(`[data-action=add-cart][data-id="${product.id}"]`);
        await set("order_note", "Red shirt customer <img src=x onerror=alert(1)>");
        check(
            tag + " manual discount field removed",
            await run(`!document.querySelector('[data-change="cart-discount"]')`),
        );
        await click("[data-action=send-order]");
        check(
            tag + " eligible waiters only in compact select",
            await run(
                `(()=>{const ids=[...document.querySelector('select[name="waiter_id"]').options].filter(o=>o.value).map(o=>Number(o.value));return ids.length===${before.servingWaiters.filter((w) => w.counter_ids.includes(before.location_id)).length} && !document.querySelector('[name="pin"]').disabled})()`,
            ),
        );
        await set("waiter_id", accounts.james.id);
        await set("pin", "0123");
        await run(`document.querySelector('[data-form=serving-order]').requestSubmit()`);
        await wait(
            `!document.querySelector('[data-form=serving-order] [type=submit]').disabled && document.querySelector('[name=pin]').value===''`,
        );
        const rejected = await snap();
        check(
            tag + " waiter A PIN cannot issue as waiter B",
            rejected.orders.length === before.orders.length &&
                rejected.products.find((p) => p.id === product.id).stock === product.stock,
        );
        check(
            tag + " rejected PIN does not print",
            await run("!document.querySelector('#issue-ticket-frame')"),
        );
        await set("pin", "6789");
        await run(`document.querySelector('[data-form=serving-order]').requestSubmit()`);
        await wait(
            "document.querySelector('#issue-ticket-frame')?.contentWindow?.__printCalls===1",
        );
        const after = await snap(),
            order = after.orders.find((o) => !before.orders.some((b) => b.id === o.id));
        check(
            tag + " correct selected waiter PIN issues once",
            after.orders.length === before.orders.length + 1 &&
                order.waiter === accounts.james.id &&
                order.status === "Issued" &&
                Number(after.products.find((p) => p.id === product.id).stock) ===
                    Number(product.stock) - 1,
        );
        check(
            tag + " saved order has escaped note",
            order.order_note === "Red shirt customer <img src=x onerror=alert(1)>" &&
                Number(order.discount) === 0,
        );
        check(tag + " cashier session preserved", after.user.id === accounts.cashier.id);
        check(
            tag + " two copies print in one batch",
            await run(
                `document.querySelector('#issue-ticket-frame').contentDocument.querySelectorAll('.ticket').length===2`,
            ),
        );
        check(
            tag + " ledger charged to selected waiter",
            Number(after.waiters.find((w) => w.id === accounts.james.id).outstanding) -
                Number(before.waiters.find((w) => w.id === accounts.james.id).outstanding) ===
                Number(order.total),
        );
        check(
            tag + " other waiter ledger unchanged",
            after.waiters.find((w) => w.id === accounts.asha.id).outstanding ===
                before.waiters.find((w) => w.id === accounts.asha.id).outstanding,
        );
        // Laravel also trusts verified same-origin Fetch Metadata. Exercise its
        // token fallback with the browser session but no trusted origin header.
        const cookies = await c("Network.getCookies", { urls: [base] });
        const csrf = await fetch(new URL("/api/nightshift/commands/order-send", base), {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                Cookie: cookies.cookies
                    .map((cookie) => cookie.name + "=" + cookie.value)
                    .join("; "),
            },
            body: "{}",
        });
        check(tag + " CSRF token fallback rejects untrusted mutation", csrf.status === 419);
        await shot(tag + "-shared-pos");
        await run(
            `fetch('/logout',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content}})`,
        );
        await navigate("/dashboard");
        check(tag + " logout returns to login", await run("location.pathname==='/login'"));
    }
    for (const width of [1440, 390]) {
        const tag = width + " waiter";
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        await c("Network.clearBrowserCookies");
        await navigate("/login");
        await set("username", accounts.asha.username);
        await set("pin", "0123");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait("location.pathname==='/dashboard'");
        await run(
            `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${width === 390 ? "sw" : "en"}'})})`,
        );
        await navigate("/dashboard#pos");
        await wait(`!!document.querySelector('.pos-cart')`);
        const before = await snap(),
            product = before.products.find((p) => p.sku === "BR-001");
        await click(`[data-action=add-cart][data-id="${product.id}"]`);
        const note = width === 390 ? "Mteja karibu na TV" : "Customer near TV";
        await set("order_note", note);
        await click("[data-action=send-order]");
        check(
            tag + " automatically selected without a card or dropdown",
            await run(
                `!document.querySelector('select[name="waiter_id"]') && document.querySelector('[name="waiter_id"]').value==='${accounts.asha.id}' && document.querySelector('.modal input[readonly]').value.includes('Asha') && document.activeElement.name==='pin'`,
            ),
        );
        check(
            tag + " localized note and confirmation",
            await run(
                `document.body.innerText.includes('${width === 390 ? "Maelezo ya Oda" : "Order Note"}') && document.querySelector('[data-form="serving-order"] [type=submit]').textContent.includes('${width === 390 ? "Thibitisha na Tuma kwa Keshia" : "Confirm and Send to Cashier"}')`,
            ),
        );
        check(
            tag + " modal fits mobile and desktop",
            await run(
                `document.documentElement.scrollWidth<=innerWidth+1 && document.querySelector('.modal').getBoundingClientRect().width<=innerWidth`,
            ),
        );
        await shot(width + "-waiter-modal");
        await set("waiter_id", accounts.james.id);
        await set("pin", "0123");
        await run(`document.querySelector('[data-form="serving-order"]').requestSubmit()`);
        await wait(`!document.querySelector('[data-form="serving-order"] [type=submit]').disabled`);
        check(
            tag + " tampered waiter rejected",
            (await snap()).orders.length === before.orders.length,
        );
        await set("waiter_id", accounts.asha.id);
        await set("pin", "0123");
        await run(`document.querySelector('[data-form="serving-order"]').requestSubmit()`);
        await wait(
            `document.querySelector('#issue-ticket-frame')?.contentWindow?.__printCalls===1`,
        );
        const after = await snap(),
            order = after.orders.find((o) => !before.orders.some((b) => b.id === o.id));
        check(
            tag + " only own PIN required and note persisted",
            order?.waiter === accounts.asha.id && order.order_note === note,
        );
        await navigate("/dashboard#orders");
        await wait(`!!document.querySelector('[data-action="tab"][data-value="All orders"]')`);
        await click('[data-action="tab"][data-value="All orders"]');
        check(
            tag + " note survives reload in history",
            await run(
                `document.querySelector('#main').textContent.includes(${JSON.stringify(note)})`,
            ),
        );
        await click(`[data-action=order-detail][data-id="${order.id}"]`);
        check(
            tag + " note shown in order details",
            await run(
                `document.querySelector('.modal .order-note').textContent.includes(${JSON.stringify(note)})`,
            ),
        );
    }
    for (const role of ["admin", "manager"]) {
        await c("Network.clearBrowserCookies");
        await navigate("/login");
        await set("username", accounts[role].username);
        await set("pin", "0123");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait("location.pathname==='/dashboard'");
        await navigate("/dashboard#pos");
        await wait(`!!document.querySelector('.pos-cart')`);
        const before = await snap(),
            product = before.products.find((p) => p.sku === "BR-001");
        await click(`[data-action=add-cart][data-id="${product.id}"]`);
        await click("[data-action=send-order]");
        check(
            role + " eligible dropdown follows scoped snapshot",
            await run(
                `(()=>{const ids=[...document.querySelector('select[name="waiter_id"]').options].filter(o=>o.value).map(o=>Number(o.value));return JSON.stringify(ids)===JSON.stringify(${JSON.stringify(before.servingWaiters.filter((w) => w.counter_ids.includes(before.location_id)).map((w) => w.id))}) && !document.querySelector('[name="pin"]').disabled})()`,
            ),
        );
        await set("waiter_id", accounts.asha.id);
        await set("pin", "0123");
        await run(`document.querySelector('[data-form="serving-order"]').requestSubmit()`);
        await wait(
            `document.querySelector('#issue-ticket-frame')?.contentWindow?.__printCalls===1`,
        );
        const after = await snap(),
            order = after.orders.find((o) => !before.orders.some((b) => b.id === o.id));
        check(
            role + " order belongs to selected waiter while operator stays signed in",
            order?.waiter === accounts.asha.id && after.user.id === accounts[role].id,
        );
    }
    check(
        "No removed lock or heartbeat requests",
        !requests.some((u) => u.includes("/quick-lock") || u.includes("/api/nightshift/activity")),
    );
    check("No browser runtime errors", errors.length === 0, errors);
    check("No HTTP 5xx", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        output + "/result.json",
        JSON.stringify({ result: "PASS", checks, errors, httpErrors }, null, 2),
    );
} catch (error) {
    console.error(error);
    await shot("failure");
    await fs.writeFile(
        output + "/result.json",
        JSON.stringify(
            { result: "FAIL", error: String(error), checks, errors, httpErrors },
            null,
            2,
        ),
    );
    process.exitCode = 1;
} finally {
    process.exit(process.exitCode || 0);
}
