import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-cashier-browser";
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
const report = (id) =>
    run(
        `fetch('/api/nightshift/shift-stock/${id}',{headers:{Accept:'application/json'}}).then(r=>r.json())`,
    );
async function login(username, path = "/dashboard", locale = "en") {
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("username", username);
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait(
        `location.pathname===${JSON.stringify(path)} && document.readyState==='complete' && !!document.querySelector('[name=csrf-token]')`,
    );
    await run(
        `fetch('/settings/language',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:${JSON.stringify(locale)}})})`,
    );
}
async function submit(selector) {
    await run(
        `window.__treasuryBefore=true;document.querySelector(${JSON.stringify(selector)}).requestSubmit()`,
    );
    await wait(`window.__treasuryBefore===undefined && document.readyState==='complete'`);
}
async function scopedSet(selector, name, value) {
    await run(
        `(()=>{const f=document.querySelector(${JSON.stringify(selector)});const el=f.elements[${JSON.stringify(name)}];if(!el)throw Error('Missing '+${JSON.stringify(name)});el.value=${JSON.stringify(String(value))};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
}
const treasury = () =>
    run(
        `fetch('/api/cash-custody/treasury',{headers:{Accept:'application/json'}}).then(r=>r.json())`,
    );

async function command(name, data) {
    return run(
        `fetch('/api/nightshift/commands/'+${JSON.stringify(name)},{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({...${JSON.stringify(data)},idempotency_key:crypto.randomUUID()})}).then(async r=>({status:r.status,body:await r.json()}))`,
    );
}
async function readyCounters(f) {
    await navigate("/dashboard#counters");
    await wait('!!document.querySelector("[data-change=business-unit]")');
    await run(
        `var e=document.querySelector('[data-change=business-unit]');e.value='${f.unit_id}';e.dispatchEvent(new Event('change',{bubbles:true}));`,
    );
    await wait(
        `!!document.querySelector('[data-cashier-limit] [name=id][value="${f.counter_id}"]')`,
    );
}
const capacityForm = (id) => `[data-cashier-limit]:has([name=id][value="${id}"])`;
async function changeLimit(f, max) {
    const sel = capacityForm(f.counter_id);
    await scopedSet(sel, "max_active_cashiers", max);
    await run(`document.querySelector(${JSON.stringify(sel)}).requestSubmit()`);
    await wait(
        `document.querySelector(${JSON.stringify(sel)}).dataset.savedLimit===${JSON.stringify(String(max))} && !!document.querySelector(${JSON.stringify(sel)}+' [data-cashier-limit-status]').textContent`,
    );
}
async function openOwn(f, index) {
    await login(f.cashiers[index].username, "/open-shift", "en");
    await set("stock_location_id", f.counter_id);
    await run(`document.querySelector('[data-shift-capacity]').requestSubmit()`);
    await wait('location.pathname==="/dashboard" && !!document.querySelector(".topbar")');
}
async function closeOwn() {
    const state = await run(
        "fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())",
    );
    const id = state.shift.id;
    const report = await run(
        `fetch('/api/nightshift/shift-stock/${id}',{headers:{Accept:'application/json'}}).then(r=>r.json())`,
    );
    const counts = await command("shift-stock-count", {
        id,
        actual_cash: "0",
        basis: report.basis,
        revision: report.revision,
        counts: report.rows.map((r) => ({ product_id: r.product_id, quantity: r.expected })),
    });
    check("Individual zero-cash counter count saved", counts.status === 200, counts);
    const closed = await command("shift-close", { id, actual_cash: "0" });
    check("Individual cashier closes without changing shared stock", closed.status === 200, closed);
    return id;
}
try {
    if (
        (await fetch(base + "/login")).headers.get("X-Nightshift-Validation-Database") !==
        "nightshift_treasury_browser_validation"
    )
        throw Error("Use isolated browser database.");
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const f = JSON.parse(await fs.readFile("/tmp/nightshift-cashier-limit-fixtures.json", "utf8"));
    await login(f.owner, "/dashboard", "en");
    await readyCounters(f);
    if (process.env.SMOKE_SKIP_MATRIX) {
        checks.push(
            ...JSON.parse(await fs.readFile(output + "/matrix-result.json", "utf8")).checks,
        );
    } else {
        for (const locale of ["en", "sw"]) {
            if (locale === "sw") {
                await run(
                    `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'sw'})})`,
                );
                await readyCounters(f);
            }
            check(
                locale + " setting label",
                await run(
                    `document.querySelector(${JSON.stringify(capacityForm(f.counter_id))}).innerText.includes(${JSON.stringify(locale === "en" ? "Maximum Active Cashiers" : "Idadi ya Juu ya Makashia")})`,
                ),
            );
            check(
                locale + " store has no cashier controls",
                await run(`!document.querySelector(${JSON.stringify(capacityForm(f.store_id))})`),
            );
            for (const theme of ["light", "dark"]) {
                await run("window.NightshiftTheme.apply(" + JSON.stringify(theme) + ")");
                for (const [width, height] of [
                    [390, 844],
                    [412, 915],
                    [820, 1180],
                    [1440, 1000],
                    [1920, 1080],
                ]) {
                    await c("Emulation.setDeviceMetricsOverride", {
                        width,
                        height,
                        deviceScaleFactor: 1,
                        mobile: width < 721,
                    });
                    await sleep(100);
                    const b = await run(
                        `(()=>{const form=document.querySelector(${JSON.stringify(capacityForm(f.counter_id))});return {overflow:document.documentElement.scrollWidth>innerWidth,controls:[...form.querySelectorAll('input:not([type=hidden]),button')].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1}),topbar:[...document.querySelectorAll('.topbar [data-language],.topbar [data-theme-toggle]')].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1})}})()`,
                    );
                    check(
                        locale + " " + theme + " " + width + "px responsive counter settings",
                        !b.overflow && b.controls && b.topbar,
                        b,
                    );
                    await run(
                        `document.querySelector(${JSON.stringify(capacityForm(f.counter_id))}).scrollIntoView({block:'center'})`,
                    );
                    await shot("limits-" + locale + "-" + theme + "-" + width);
                }
            }
        }
        await fs.writeFile(output + "/matrix-result.json", JSON.stringify({ checks }, null, 2));
    }
    await c("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await openOwn(f, 0);
    const first = await run(
        "fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())",
    );
    check("Default first cashier opens", first.shift.active);
    await login(f.cashiers[1].username, "/open-shift", "sw");
    await navigate("/open-shift");
    await set("stock_location_id", f.counter_id);
    check(
        "Full counter blocks mobile Open Shift and shows SW occupancy",
        await run(
            "document.querySelector('[data-shift-capacity] [type=submit]').disabled && document.querySelector('[data-cashier-occupancy]').textContent.includes('1 / 1') && document.querySelector('[data-cashier-capacity-message]').textContent.includes('Kaunta hii tayari')",
        ),
    );
    await shot("full-counter-390-sw");
    for (const theme of ["light", "dark"]) {
        await run("window.NightshiftTheme.apply(" + JSON.stringify(theme) + ")");
        for (const width of [390, 412, 1440]) {
            await c("Emulation.setDeviceMetricsOverride", {
                width,
                height: 915,
                deviceScaleFactor: 1,
                mobile: width < 721,
            });
            await sleep(80);
            check(
                "Open Shift SW " + theme + " " + width + " fits",
                await run(
                    "document.documentElement.scrollWidth<=innerWidth && document.querySelector('[data-shift-capacity] [type=submit]').disabled",
                ),
            );
        }
    }

    const blocked = await command("shift-open", {
        stock_location_id: f.counter_id,
        type: "CASHIER",
    });
    check("Backend rejects full counter", blocked.status === 422, blocked);
    await login(f.owner, "/dashboard", "en");
    await readyCounters(f);
    await changeLimit(f, 2);
    check(
        "Increase applies immediately",
        await run(
            `document.querySelector(${JSON.stringify(capacityForm(f.counter_id))}).elements.max_active_cashiers.value==='2'`,
        ),
    );
    await c("Emulation.setDeviceMetricsOverride", {
        width: 412,
        height: 915,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await openOwn(f, 1);
    await login(f.cashiers[2].username, "/open-shift", "en");
    await set("stock_location_id", f.counter_id);
    check(
        "Third cashier blocked at two",
        await run(
            "document.querySelector('[data-shift-capacity] [type=submit]').disabled && document.querySelector('[data-cashier-occupancy]').textContent.includes('2 / 2')",
        ),
    );
    await login(f.owner, "/dashboard", "en");
    await readyCounters(f);
    const reduce = await command("catalog-cashier-limit", {
        id: f.counter_id,
        max_active_cashiers: 1,
    });
    check(
        "Unsafe decrease rejected",
        reduce.status === 422 &&
            JSON.stringify(reduce.body).includes("Close one active cashier shift"),
        reduce,
    );
    await navigate("/dashboard#shifts");
    await wait('!!document.querySelector("[data-action=open-shift]")');
    await run(`document.querySelector('[data-action="open-shift"]').click()`);
    await wait('!!document.querySelector("[data-form=open-shift]")');
    await scopedSet("[data-form=open-shift]", "counter", f.counter_id);
    check(
        "Manager modal shows capacity and cannot bypass",
        await run(
            "document.querySelector('[data-form=open-shift] [type=submit]').disabled && document.querySelector('[data-form=open-shift] [data-cashier-occupancy]').textContent.includes('2 / 2')",
        ),
    );
    await click(".modal [data-action=close-modal]");
    await login(f.cashiers[0].username, "/dashboard", "en");
    await closeOwn();
    await login(f.owner, "/dashboard", "en");
    await readyCounters(f);
    await changeLimit(f, 1);
    check(
        "Safe decrease succeeds after close",
        await run(
            `document.querySelector(${JSON.stringify(capacityForm(f.counter_id))}).elements.max_active_cashiers.value==='1'`,
        ),
    );
    await login(f.cashiers[1].username, "/dashboard", "en");
    await closeOwn();
    await openOwn(f, 2);
    check(
        "Default limit allows next cashier after final close",
        await run("document.querySelector('.topbar')!==null"),
    );
    await closeOwn();
    check("No runtime errors", errors.length === 0, errors);
    check("No server errors", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        output + "/result.json",
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
} finally {
    ws.close();
}
