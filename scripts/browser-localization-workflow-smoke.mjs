import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-localized-workflow-browser";
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
    await c("Page.addScriptToEvaluateOnNewDocument", {
        source: "window.__printCalls=0;window.print=()=>{window.__printCalls++};",
    });
    await c("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: true,
    });
    const navigate = async (url) => {
        await run("window.__previousLocalizedDocument=true");
        const destination = new URL(url);
        destination.searchParams.set("workflow_check", String(Date.now()));
        await c("Page.navigate", { url: destination.href });
        await wait(
            'window.__previousLocalizedDocument===undefined && document.readyState==="complete"',
        );
    };
    const login = async (email) => {
        await c("Network.clearBrowserCookies");
        await navigate(`${base}/login`);
        await set("email", email);
        await set("password", "Nightshift!2026");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait('!!document.querySelector("#main h1")');
    };
    const language = async (locale) => {
        await click(`[data-language="${locale}"]`);
        await wait(`document.documentElement.lang==='${locale}'`);
    };
    await login("admin@nightshift.test");
    await language("sw");
    const identity = await run(
        `fetch('/api/nightshift/bootstrap').then(r=>r.headers.get('X-Nightshift-Validation-Database'))`,
    );
    check("Dedicated MySQL only", identity === "nightshift_validation");
    let data = await snap();
    const w = data.servingWaiters.find((w) => w.counter_ids.includes(data.location_id));
    check("Active waiter at selected counter", !!w);
    check(
        "PIN configured for validation waiter",
        (await api("staff-pin", { id: w.id, pin: "0123" })).status === 200,
    );
    await navigate(`${base}/dashboard#pos`);
    await wait('!!document.querySelector("[data-action=send-order]")');
    const product = (await snap()).products.find(
        (p) => Number(p.stock) > 5 && Number(p.units[0][1]) === 1 && Number(p.units[0][2]) > 1000,
    );
    check("In-stock base-unit product available", !!product);
    for (const theme of ["light", "dark"]) {
        await run(`window.NightshiftTheme.save('${theme}')`);
        await click('[data-nav="pos"]');
        check(
            `${theme} theme accessibility label is Kiswahili`,
            await run(
                'document.querySelector("[data-theme-toggle]").getAttribute("aria-label").includes("Badili")',
            ),
        );
        check(
            `${theme} counter label translates but value stays canonical`,
            await run(
                `(()=>{const s=document.querySelector('[data-change="cart-counter"]');return s.value==='Main Counter' && s.selectedOptions[0].textContent.trim()==='Kaunta Kuu'})()`,
            ),
        );
        const before = await snap();
        await click(`[data-action="add-cart"][data-id="${product.id}"]`);
        await click('[data-action="send-order"]');
        await click(`[data-action="serving-waiter"][data-id="${w.id}"]`);
        await set("pin", "0123");
        await run(
            `document.querySelector('[data-form="serving-order"]').requestSubmit();document.querySelector('[data-form="serving-order"]')?.requestSubmit()`,
        );
        await wait(
            '!document.querySelector(".modal-backdrop") && !!document.querySelector("#issue-ticket-frame")?.contentDocument?.body?.dataset.committedOrder',
        );
        const after = await snap();
        const order = after.orders.find((o) => !before.orders.some((b) => b.id === o.id));
        check(
            `${theme} Kiswahili send creates one ISSUED order`,
            after.orders.length === before.orders.length + 1 &&
                order?.status === "Issued" &&
                order.waiter === w.id,
        );
        check(
            `${theme} stock decreases once`,
            Number(after.products.find((p) => p.id === product.id).stock) ===
                Number(before.products.find((p) => p.id === product.id).stock) - 1,
        );
        check(
            `${theme} waiter ledger increases once`,
            Number(after.waiters.find((x) => x.id === w.id).outstanding) ===
                Number(before.waiters.find((x) => x.id === w.id).outstanding) + Number(order.total),
        );
        await wait('document.querySelector("#issue-ticket-frame").contentWindow.__printCalls===1');
        check(
            `${theme} committed ticket prints in Kiswahili`,
            await run(
                `(()=>{const f=document.querySelector('#issue-ticket-frame');return f.contentDocument.body.dataset.committedOrder==='${order.id}' && f.contentDocument.body.innerText.includes('Mhudumu') && f.contentDocument.body.innerText.includes('HAIJALIPWA')})()`,
            ),
        );
        await shot(`sw-${theme}-issued`);
    }
    await click('[data-nav="notifications"]');
    check(
        "Notification title and generated message localized",
        await run(
            'document.body.innerText.includes("Oda Mpya ya Mhudumu") && document.body.innerText.includes("kutoka kwa")',
        ),
    );
    await language("en");
    await run('window.NightshiftTheme.save("light")');
    await login("cashier@nightshift.test");
    await language("sw");
    await click('[data-nav="waiters"]');
    let before = await snap();
    const balance = Number(before.waiters.find((x) => x.id === w.id).outstanding);
    await click(`[data-action="waiter-payment"][data-value="partial"][data-id="${w.id}"]`);
    await set("amount", 1000);
    await run(`document.querySelector('[data-form="waiter-payment"]').requestSubmit()`);
    await wait('!document.querySelector(".modal-backdrop")');
    let after = await snap();
    check(
        "Kiswahili partial payment reduces ledger by received amount",
        Number(after.waiters.find((x) => x.id === w.id).outstanding) === balance - 1000,
    );
    await click(`[data-action="waiter-payment"][data-value="full"][data-id="${w.id}"]`);
    check(
        "Kiswahili full settlement modal readable",
        await run('document.querySelector("#modal-title").innerText.includes("Malipo Kamili")'),
    );
    await run(`document.querySelector('[data-form="waiter-payment"]').requestSubmit()`);
    await wait('!document.querySelector(".modal-backdrop")');
    after = await snap();
    check(
        "Kiswahili full settlement clears counter balance",
        Number(after.waiters.find((x) => x.id === w.id).settlement_balance) === 0,
    );
    await language("en");
    await run('window.NightshiftTheme.save("light")');
    check("No browser exceptions", errors.length === 0, errors);
    check("No HTTP 5xx", httpErrors.length === 0, httpErrors);
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
