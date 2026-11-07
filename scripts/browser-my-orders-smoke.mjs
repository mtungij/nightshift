import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-my-orders-browser";
const paperWidth = process.env.SMOKE_PAPER_WIDTH || "80mm";
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
async function login(account, pin) {
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("username", account.username);
    await set("pin", pin);
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait("location.pathname==='/dashboard'");
    await navigate("/dashboard#pos");
    await wait("!!document.querySelector('.pos-cart')");
}
async function verify(staffId, pin) {
    await click("[data-action=my-orders]");
    await wait("!!document.querySelector('[data-form=my-orders-verify]')");
    await set("staff_id", staffId);
    await set("pin", pin);
    await run(`document.querySelector('[data-form=my-orders-verify]').requestSubmit()`);
    await wait("!!document.querySelector('.my-orders-view')");
}
const ids = () =>
    run("[...document.querySelectorAll('.my-orders-card')].map(e=>Number(e.dataset.orderId))");
async function closeView(tag) {
    const token = await run("window.__myOrdersToken");
    await click("[data-action=close-my-orders]");
    check(
        tag + " close clears private DOM immediately",
        await run(
            "!document.querySelector('.my-orders-view') && !!document.querySelector('.pos-cart')",
        ),
    );
    await wait(
        `fetch('/api/nightshift/my-orders',{headers:{Accept:'application/json','X-My-Orders-Token':${JSON.stringify(token)}}}).then(r=>r.status===403)`,
    );
    check(tag + " closed proof revoked", true);
    check(
        tag + " POS cart preserved",
        await run("!document.querySelector('[data-action=send-order]').disabled"),
    );
}
try {
    const safety = await fetch(new URL("/login", base));
    check(
        "Disposable MySQL target",
        safety.headers.get("X-Nightshift-Validation-Database") === "nightshift_validation",
    );
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    await c("Page.addScriptToEvaluateOnNewDocument", {
        source: `const originalFetch=window.fetch;window.fetch=(url,options={})=>{if(options.headers?.['X-My-Orders-Token'])window.__myOrdersToken=options.headers['X-My-Orders-Token'];return originalFetch(url,options)};const originalTimeout=window.setTimeout;window.setTimeout=(fn,delay,...args)=>{if(delay>=290000&&delay<=300000)window.__expireMyOrders=()=>fn(...args);return originalTimeout(fn,delay,...args);};`,
    });
    const fixture = JSON.parse(
        await fs.readFile("/tmp/nightshift-my-orders-fixtures.json", "utf8"),
    );
    const a = fixture.accounts,
        o = fixture.orders;
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        await login(a.terminal, "4567");
        for (const locale of ["en", "sw"]) {
            const tag = width + " " + locale;
            await run(
                `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${locale}'})})`,
            );
            await navigate("/dashboard#pos");
            await wait("!!document.querySelector('[data-action=my-orders]')");
            const current = await snap(),
                product = current.products.find((p) => p.sku === "BR-001");
            await click(`[data-action=add-cart][data-id="${product.id}"]`);
            check(
                tag + " localized POS action",
                await run(
                    `document.querySelector('[data-action=my-orders]').textContent.includes('${locale === "sw" ? "Oda Zangu" : "My Orders"}')`,
                ),
            );
            await click("[data-action=my-orders]");
            await wait("!!document.querySelector('[data-form=my-orders-verify]')");
            const staffIds = await run(
                "[...document.querySelector('[name=staff_id]').options].filter(o=>o.value).map(o=>Number(o.value))",
            );
            check(
                tag + " waiter/cashier eligibility",
                staffIds.includes(a.asha.id) &&
                    staffIds.includes(a.cashier.id) &&
                    !fixture.excluded_staff.some((id) => staffIds.includes(id)),
            );
            await set("staff_id", a.asha.id);
            await set("pin", "6789");
            await run(`document.querySelector('[data-form=my-orders-verify]').requestSubmit()`);
            await wait(
                "!!document.querySelector('[data-my-orders-error]')?.textContent && !document.querySelector('[data-form=my-orders-verify] [type=submit]').disabled",
            );
            check(
                tag + " another waiter PIN rejected and cleared",
                await run(
                    "!document.querySelector('.my-orders-view') && document.querySelector('[name=pin]').value===''",
                ),
            );
            await set("pin", "0123");
            await run(`document.querySelector('[data-form=my-orders-verify]').requestSubmit()`);
            await wait("!!document.querySelector('.my-orders-view')");
            let visible = await ids();
            check(
                tag + " waiter owns all results",
                visible.includes(o.unpaid) &&
                    visible.includes(o.partial) &&
                    visible.includes(o.paid) &&
                    !visible.includes(o.james) &&
                    !visible.includes(o.restricted_paid),
            );
            check(
                tag + " note is text and partial payment localized",
                await run(
                    `document.querySelector('[data-order-id="${o.partial}"]').textContent.includes('Mteja karibu na TV <b>private note</b>') && !document.querySelector('.my-orders-card .order-note b') && document.querySelector('[data-order-id="${o.partial}"]').textContent.includes('${locale === "sw" ? "Imelipwa Sehemu" : "Partially Paid"}')`,
                ),
            );
            check(
                tag + " mobile/desktop fit",
                await run(
                    "document.documentElement.scrollWidth<=innerWidth+1 && document.querySelector('.modal').getBoundingClientRect().width<=innerWidth && document.querySelector('.modal').scrollWidth<=document.querySelector('.modal').clientWidth+1",
                ),
            );
            await shot(width + "-" + locale + "-waiter");
            await click("[data-action=my-orders-filter][data-value=unpaid]");
            await wait(
                `!document.querySelector('[data-order-id="${o.paid}"]') && !!document.querySelector('[data-order-id="${o.partial}"]')`,
            );
            check(tag + " Unpaid includes partial payment", true);
            await click("[data-action=my-orders-filter][data-value=paid]");
            await wait(
                `!!document.querySelector('[data-order-id="${o.paid}"]') && !document.querySelector('[data-order-id="${o.partial}"]')`,
            );
            check(tag + " Paid excludes unpaid", true);
            const ownToken = await run("window.__myOrdersToken");
            check(
                tag + " direct other waiter ID denied",
                (await run(
                    `fetch('/api/nightshift/my-orders/${o.james}',{headers:{Accept:'application/json','X-My-Orders-Token':${JSON.stringify(ownToken)}}}).then(r=>r.status)`,
                )) === 404,
            );
            await closeView(tag + " waiter");
            await verify(a.james.id, "6789");
            visible = await ids();
            check(
                tag + " next waiter has no inherited identity",
                visible.includes(o.james) && !visible.includes(o.unpaid),
            );
            await closeView(tag + " James");
            await click("[data-action=my-orders]");
            await wait("!!document.querySelector('[data-form=my-orders-verify]')");
            await set("staff_id", a.cashier.id);
            await set("pin", "1122");
            await run(`document.querySelector('[data-form=my-orders-verify]').requestSubmit()`);
            await wait(
                "!!document.querySelector('[data-my-orders-error]')?.textContent && !document.querySelector('[data-form=my-orders-verify] [type=submit]').disabled",
            );
            check(
                tag + " another cashier PIN rejected",
                await run("!document.querySelector('.my-orders-view')"),
            );
            await set("pin", "4567");
            await run(`document.querySelector('[data-form=my-orders-verify]').requestSubmit()`);
            await wait("!!document.querySelector('.my-orders-view')");
            visible = await ids();
            check(
                tag + " cashier existing settlement and paid ownership",
                visible.includes(o.unpaid) &&
                    visible.includes(o.james) &&
                    visible.includes(o.paid) &&
                    !visible.includes(o.restricted_paid) &&
                    !visible.includes(o.vip),
            );
            const token = await run("window.__myOrdersToken");
            check(
                tag + " restricted counter ID denied",
                (await run(
                    `fetch('/api/nightshift/my-orders/${o.vip}',{headers:{Accept:'application/json','X-My-Orders-Token':${JSON.stringify(token)}}}).then(r=>r.status)`,
                )) === 404,
            );
            check(
                tag + " terminal remains authenticated",
                (await snap()).user.id === a.terminal.id,
            );
            await shot(width + "-" + locale + "-cashier");
            await navigate("/dashboard#pos");
            await wait("!!document.querySelector('.pos-cart')");
            check(
                tag + " refresh clears view and proof",
                await run(
                    `fetch('/api/nightshift/my-orders',{headers:{Accept:'application/json','X-My-Orders-Token':${JSON.stringify(token)}}}).then(r=>!document.querySelector('.my-orders-view') && r.status===403)`,
                ),
            );
        }
    }
    for (const [role, pin, locale] of [
        ["asha", "0123", "en"],
        ["cashier", "4567", "sw"],
    ]) {
        await login(a[role], pin);
        await run(
            `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${locale}'})})`,
        );
        await navigate("/dashboard#pos");
        await wait("!!document.querySelector('[data-action=my-orders]')");
        await click("[data-action=my-orders]");
        await wait("!!document.querySelector('[data-form=my-orders-verify]')");
        check(
            role + " individual auto-selection is fixed",
            await run(
                `!document.querySelector('select[name=staff_id]') && Number(document.querySelector('[name=staff_id]').value)===${a[role].id}`,
            ),
        );
        await set("staff_id", role === "asha" ? a.james.id : fixture.other_cashier_id);
        await set("pin", role === "asha" ? "6789" : "1122");
        await run(`document.querySelector('[data-form=my-orders-verify]').requestSubmit()`);
        await wait(
            "!!document.querySelector('[data-my-orders-error]')?.textContent && !document.querySelector('[data-form=my-orders-verify] [type=submit]').disabled",
        );
        check(
            role + " individual hidden-ID tampering denied",
            await run("!document.querySelector('.my-orders-view')"),
        );
        await set("staff_id", a[role].id);
        await set("pin", pin);
        await run(`document.querySelector('[data-form=my-orders-verify]').requestSubmit()`);
        await wait("!!document.querySelector('.my-orders-view')");
        check(role + " individual main identity unchanged", (await snap()).user.id === a[role].id);
        check(
            role + " five-minute view timer installed",
            await run("typeof window.__expireMyOrders==='function'"),
        );
        await run("window.__expireMyOrders()");
        check(
            role + " expiry clears UI and preserves login",
            await run(
                "!document.querySelector('.my-orders-view') && !!document.querySelector('.pos-cart')",
            ),
        );
        check(
            role + " still authenticated after view expiry",
            (await snap()).user.id === a[role].id,
        );
    }
    check("No browser exceptions", errors.length === 0, errors);
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
