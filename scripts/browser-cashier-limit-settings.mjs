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

try {
    if (
        (await fetch(base + "/login")).headers.get("X-Nightshift-Validation-Database") !==
        "nightshift_treasury_browser_validation"
    )
        throw Error("Use disposable browser database.");
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const f = JSON.parse(await fs.readFile("/tmp/nightshift-cashier-limit-fixtures.json", "utf8"));
    await login(f.owner, "/dashboard", "en");
    for (const locale of ["en", "sw"]) {
        if (locale === "sw")
            await run(
                `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'sw'})})`,
            );
        await navigate("/dashboard#counters");
        await wait('!!document.querySelector("[data-action=add-counter]")');
        await c("Emulation.setDeviceMetricsOverride", {
            width: locale === "en" ? 390 : 412,
            height: 915,
            deviceScaleFactor: 1,
            mobile: true,
        });
        await run(
            "window.NightshiftTheme.apply(" +
                JSON.stringify(locale === "en" ? "light" : "dark") +
                ")",
        );
        await click("[data-action=add-counter]");
        await wait('!!document.querySelector("[data-form=add-counter]")');
        check(
            locale + " new counter limit defaults to positive integer 1",
            await run(
                "(()=>{const e=document.querySelector('[data-form=add-counter] [name=max_active_cashiers]');return e.value==='1'&&e.min==='1'&&e.step==='1'})()",
            ),
        );
        await scopedSet("[data-form=add-counter]", "type", "Storage");
        check(
            locale + " stores hide and disable cashier control",
            await run(
                "document.querySelector('[data-new-counter-limit]').hidden && document.querySelector('[data-new-counter-limit] input').disabled",
            ),
        );
        await scopedSet("[data-form=add-counter]", "type", "Bar counter");
        check(
            locale + " counter restores cashier control",
            await run(
                "!document.querySelector('[data-new-counter-limit]').hidden && !document.querySelector('[data-new-counter-limit] input').disabled",
            ),
        );
        check(
            locale + " add-location modal fits mobile",
            await run(
                "document.documentElement.scrollWidth<=innerWidth && document.querySelector('.modal').scrollWidth<=document.querySelector('.modal').clientWidth+1",
            ),
        );
        await shot("new-location-" + locale);
        await click(".modal [data-action=close-modal]");
    }
    await navigate("/business-units");
    await wait('!!document.querySelector("[data-cashier-limit]")');
    const selector = `[data-cashier-limit]:has([name=id][value="${f.counter_id}"])`;
    check(
        "Business Unit page exposes separate authorized cashier setting",
        await run(
            `!!document.querySelector(${JSON.stringify(selector)}) && !document.querySelector(${JSON.stringify(selector)}).parentElement.closest('form')`,
        ),
    );
    check(
        "Business Unit setting fits 412px",
        await run(
            `(()=>{const f=document.querySelector(${JSON.stringify(selector)});return document.documentElement.scrollWidth<=innerWidth&&[...f.querySelectorAll('input:not([type=hidden]),button')].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1})})()`,
        ),
    );
    for (const max of [2, 1]) {
        await scopedSet(selector, "max_active_cashiers", max);
        await run(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`);
        await wait(
            `document.querySelector(${JSON.stringify(selector)}).dataset.savedLimit===${JSON.stringify(String(max))} && !!document.querySelector(${JSON.stringify(selector)}+' [data-cashier-limit-status]').textContent`,
        );
        check(
            "Business Unit form saves limit " + max,
            await run(
                `document.querySelector(${JSON.stringify(selector)}).elements.max_active_cashiers.value===${JSON.stringify(String(max))}`,
            ),
        );
    }
    await run(
        `document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center'})`,
    );
    await shot("business-unit-cashier-limit-412");
    check("No runtime errors", errors.length === 0, errors);
    check("No server errors", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        output + "/settings-result.json",
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
} finally {
    ws.close();
}
