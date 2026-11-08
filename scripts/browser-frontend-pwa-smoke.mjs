import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-frontend-pwa-browser";
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
async function login(account, pin, screen = "pos") {
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("username", account.username);
    await set("pin", pin);
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait("location.pathname==='/dashboard'");
    await navigate("/dashboard#" + screen);
    await wait("!!document.querySelector('#main h1')");
}
async function locale(language) {
    await run(
        `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${language}'})})`,
    );
    await navigate("/dashboard#waiters");
    await wait("!!document.querySelector('[data-action=waiter-payment]')");
}
async function effects() {
    const snap = await run(
        "fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'},cache:'no-store'}).then(r=>r.json())",
    );
    return snap;
}
const traffic = [];
let businessWorkerResponses = 0;
ws.addEventListener("message", (event) => {
    const message = JSON.parse(event.data);
    if (
        message.method === "Network.responseReceived" &&
        new URL(message.params.response.url).pathname.startsWith("/api/nightshift/") &&
        message.params.response.fromServiceWorker
    )
        businessWorkerResponses++;
    if (message.method === "Network.requestWillBeSent") {
        const request = message.params.request;
        if (/^https?:/.test(request.url))
            traffic.push({
                url: request.url,
                method: request.method,
                csrf: Object.keys(request.headers).some(
                    (name) => name.toLowerCase() === "x-csrf-token",
                ),
            });
    }
});
try {
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const fixture = JSON.parse(
        await fs.readFile("/tmp/nightshift-my-orders-fixtures.json", "utf8"),
    );
    check(
        "Guarded validation database",
        (await fetch(new URL("/login", base))).headers.get("X-Nightshift-Validation-Database") ===
            "nightshift_validation",
    );
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        await login(fixture.accounts.terminal, "4567");
        await wait("!!document.querySelector('[data-action=my-orders]')");
        await click("[data-action=my-orders]");
        await wait("!!document.querySelector('[data-form=my-orders-verify]')");
        check(
            width + " shared terminal can select cashier",
            await run(
                `[...document.querySelector('select[name=staff_id]').options].some(o=>Number(o.value)===${fixture.accounts.cashier.id})`,
            ),
        );
        await set("staff_id", fixture.accounts.cashier.id);
        await set("pin", "4567");
        await run(`document.querySelector('[data-form=my-orders-verify]').requestSubmit()`);
        await wait("!!document.querySelector('.my-orders-view')");
        const own = await run(
            "[...document.querySelectorAll('.my-orders-card')].map(e=>Number(e.dataset.orderId))",
        );
        check(
            width + " allocated cashier history visible under terminal PIN scope",
            own.includes(fixture.orders.legacy_paid) &&
                !own.includes(fixture.orders.restricted_paid) &&
                !own.includes(fixture.orders.vip),
        );
        await click("[data-action=close-my-orders]");
        await login(fixture.accounts.cashier, "4567", "waiters");
        await wait("!!document.querySelector('[data-action=waiter-payment]')");
        await locale(width === 390 ? "sw" : "en");
        const before = await effects(),
            waiter = before.waiters.find((w) => w.id === fixture.accounts.asha.id);
        check(
            width + " payment label is localized",
            await run(
                `document.querySelector('[data-action=waiter-payment][data-id="${waiter.id}"]').textContent.includes('${width === 390 ? "Pokea Malipo" : "Receive Payment"}')`,
            ),
        );
        await click(`[data-action=waiter-payment][data-id="${waiter.id}"]`);
        await wait("!!document.querySelector('[data-form=waiter-payment]')");
        check(
            width + " Receive Payment opens existing form",
            await run(
                `Number(document.querySelector('[name=waiter_id]').value)===${waiter.id} && Number(document.querySelector('[name=counter]').value)===${fixture.counter_id} && !!document.querySelector('[name=method]')`,
            ),
        );
        await set("amount", "999999999");
        await run("document.querySelector('[data-form=waiter-payment]').requestSubmit()");
        await wait(
            "!document.querySelector('[data-form=waiter-payment] [type=submit]').disabled && !!document.querySelector('#toast').textContent",
        );
        const invalid = await effects();
        check(
            width + " invalid payment shows feedback without balance change",
            invalid.payments.length === before.payments.length &&
                invalid.waiters.find((w) => w.id === waiter.id).outstanding ===
                    waiter.outstanding &&
                (await run("!!document.querySelector('[data-form=waiter-payment]')")),
        );
        await set("amount", "1000");
        await set("method", "Cash");
        await run("document.querySelector('[data-form=waiter-payment]').requestSubmit()");
        await wait("!document.querySelector('[data-form=waiter-payment]')");
        const paid = await effects();
        check(
            width + " payment uses normal allocation and waiter ledger",
            Number(waiter.outstanding) -
                Number(paid.waiters.find((w) => w.id === waiter.id).outstanding) ===
                1000 && paid.payments.length > before.payments.length,
        );
        for (const mode of ["partial", "full"]) {
            await click(
                `[data-action=waiter-payment][data-id="${waiter.id}"][data-value="${mode}"]`,
            );
            await wait("!!document.querySelector('[data-form=waiter-payment]')");
            check(
                width + " " + mode + " payment action wired",
                await run(
                    `document.querySelector('[name=amount]').value${mode === "partial" ? "===''" : ">'0'"}`,
                ),
            );
            await click("[data-action=close-modal]");
        }
        await shot(width + "-cashier-payment");
        await run("navigator.serviceWorker.ready.then(()=>true)");
        await wait("!!navigator.serviceWorker.controller");
        check(
            width + " secure worker registration",
            await run("window.isSecureContext && !!navigator.serviceWorker.controller"),
        );
        const manifest = await c("Page.getAppManifest");
        check(width + " manifest parses without browser errors", manifest.errors.length === 0);
        const installability = await c("Page.getInstallabilityErrors");
        check(
            width + " Chrome installability",
            installability.installabilityErrors.length === 0,
            installability.installabilityErrors,
        );
        await click("[data-action=profile]");
        await run(
            `(()=>{window.__installCalls=0;const e=new Event('beforeinstallprompt',{cancelable:true});e.prompt=async()=>{window.__installCalls++};e.userChoice=new Promise(resolve=>window.__resolveInstall=resolve);window.dispatchEvent(e)})()`,
        );
        check(
            width + " install action localized",
            await run(
                `!document.querySelector('[data-pwa-install]').hidden && document.querySelector('[data-pwa-install]').textContent==='${width === 390 ? "Sakinisha App" : "Install App"}'`,
            ),
        );
        await click("[data-pwa-install]");
        await run("window.NightshiftPwa.install()");
        check(
            width + " duplicate install prompts prevented",
            await run("window.__installCalls===1"),
        );
        await run(
            "window.__resolveInstall({outcome:'accepted'});window.dispatchEvent(new Event('appinstalled'))",
        );
        check(
            width + " installed state hides action",
            await run("document.querySelector('[data-pwa-install]').hidden"),
        );
        await click("[data-action=close-modal]");
        const cached = await run(
            "(async()=>{const urls=[];for(const name of await caches.keys()){if(name.startsWith('nightshift-static-'))for(const r of await(await caches.open(name)).keys())urls.push(r.url)}return urls})()",
        );
        check(
            width + " no business data in worker caches",
            cached.length >= 4 &&
                !cached.some((url) =>
                    /\/api\/|\/login|\/logout|\/dashboard|\/shift-stock/.test(
                        new URL(url).pathname,
                    ),
                ),
            cached,
        );
        await c("Network.emulateNetworkConditions", {
            offline: true,
            latency: 0,
            downloadThroughput: 0,
            uploadThroughput: 0,
        });
        const offline = await run(
            "fetch('/api/nightshift/bootstrap',{cache:'no-store'}).then(()=>false).catch(()=>true)",
        );
        check(width + " business data does not fall back to offline cache", offline);
        await c("Network.emulateNetworkConditions", {
            offline: false,
            latency: 0,
            downloadThroughput: -1,
            uploadThroughput: -1,
        });
    }
    check(
        "Payment command sent by HTTPS with normal endpoint",
        traffic.some(
            (r) =>
                r.method === "POST" &&
                new URL(r.url).pathname === "/api/nightshift/commands/waiter-payment",
        ) && traffic.every((r) => new URL(r.url).protocol === "https:"),
    );
    check("Business responses bypass service worker", businessWorkerResponses === 0);
    check(
        "Payment requests include CSRF",
        traffic
            .filter(
                (r) =>
                    r.method === "POST" &&
                    new URL(r.url).pathname === "/api/nightshift/commands/waiter-payment",
            )
            .every((r) => r.csrf),
    );
    check("No runtime exceptions", errors.length === 0, errors);
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
