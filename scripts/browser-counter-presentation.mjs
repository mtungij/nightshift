import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-counter-binding-browser";
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
    const until = Date.now() + 45000;
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
const card = (id) => `[data-counter-card="${id}"]`;
const form = (id) => card(id) + " [data-cashier-limit]";
async function ready(f) {
    await navigate("/dashboard#counters");
    await wait('!!document.querySelector("[data-change=business-unit]")');
    await run(
        `var selector=document.querySelector('[data-change=business-unit]');selector.value='${f.unit_id}';selector.dispatchEvent(new Event('change',{bubbles:true}));`,
    );
    await wait(`!!document.querySelector(${JSON.stringify(form(f.counter_id))})`);
}
async function save(selector, max) {
    await scopedSet(selector, "max_active_cashiers", max);
    await run(
        `window.__saveDocument=7;document.querySelector(${JSON.stringify(selector)}).requestSubmit()`,
    );
    await wait(
        `document.querySelector(${JSON.stringify(selector)}).dataset.savedLimit===${JSON.stringify(String(max))} && !!document.querySelector(${JSON.stringify(selector)}+' [data-cashier-limit-status]').textContent`,
    );
    check(
        "Save keeps current document and reports success " + max,
        await run(
            `window.__saveDocument===7 && !document.querySelector(${JSON.stringify(selector)}+' [data-cashier-limit-status]').classList.contains('is-error')`,
        ),
    );
}
async function closeShift(id) {
    const r = await run(
        `fetch('/api/nightshift/shift-stock/${id}',{headers:{Accept:'application/json'}}).then(r=>r.json())`,
    );
    const counted = await command("shift-stock-count", {
        id,
        actual_cash: "0",
        basis: r.basis,
        revision: r.revision,
        counts: r.rows.map((row) => ({ product_id: row.product_id, quantity: row.expected })),
    });
    check("Fixture count saved", counted.status === 200, counted);
    const closed = await command("shift-close", { id, actual_cash: "0" });
    check("Fixture closes through existing lifecycle", closed.status === 200, closed);
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
    const f = JSON.parse(
        await fs.readFile("/tmp/nightshift-counter-binding-fixtures.json", "utf8"),
    );
    await login(f.owner, "/dashboard", "en");
    for (const locale of ["en", "sw"]) {
        await run(
            `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:${JSON.stringify(locale)}})})`,
        );
        await ready(f);
        check(
            locale + " General Business Unit context",
            await run(
                `document.querySelector(${JSON.stringify(card(f.counter_id))}).innerText.includes(${JSON.stringify(locale === "en" ? "Business Unit: General" : "Kitengo cha Biashara: General")})`,
            ),
        );
        check(
            locale + " private store has no cashier editor",
            await run(
                `!document.querySelector(${JSON.stringify(card(f.store_id))}).querySelector('[data-cashier-limit]')`,
            ),
        );
        check(
            locale + " shared store has shared helper and no misleading General",
            await run(
                `(()=>{const t=document.querySelector(${JSON.stringify(card(f.shared_store_id))}).innerText;return t.includes(${JSON.stringify(locale === "en" ? "Shared across Business Units" : "Inatumika kwa Vitengo Vingi")})&&!t.includes('General')})()`,
            ),
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
                await sleep(80);
                const b = await run(
                    `(()=>{const f=document.querySelector(${JSON.stringify(form(f.counter_id))}),input=f.elements.max_active_cashiers;return {overflow:document.documentElement.scrollWidth>innerWidth,value:input.value,occupancy:f.querySelector('[data-counter-occupancy]').textContent,controls:[...f.querySelectorAll('input:not([type=hidden]),button')].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1})}})()`,
                );
                check(
                    locale + " " + theme + " " + width + "px complete 0 / 1 and prefilled input",
                    !b.overflow && b.controls && b.value === "1" && b.occupancy.endsWith("0 / 1"),
                    b,
                );
                await run(
                    `document.querySelector(${JSON.stringify(card(f.counter_id))}).scrollIntoView({block:'center'})`,
                );
                await shot(locale + "-" + theme + "-" + width);
            }
        }
    }
    await run(
        `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'en'})})`,
    );
    await ready(f);
    for (const value of ["", 0, -1, "1.5", "invalid"]) {
        await run(
            `document.querySelector(${JSON.stringify(form(f.counter_id))}).elements.max_active_cashiers.value=${JSON.stringify(String(value))}`,
        );
        check(
            "Native control rejects " + JSON.stringify(value),
            await run(
                `!document.querySelector(${JSON.stringify(form(f.counter_id))}).elements.max_active_cashiers.checkValidity()`,
            ),
        );
    }
    await scopedSet(form(f.counter_id), "max_active_cashiers", 1);
    const first = await command("shift-open", {
        user_id: f.cashiers[0].id,
        stock_location_id: f.counter_id,
        type: "CASHIER",
    });
    check("First fixture shift opens", first.status === 200, first);
    await ready(f);
    check(
        "Normal refresh shows 1 / 1 and full state",
        await run(
            `(()=>{const f=document.querySelector(${JSON.stringify(form(f.counter_id))});return f.elements.max_active_cashiers.value==='1'&&f.querySelector('[data-counter-occupancy]').textContent.endsWith('1 / 1')&&!f.querySelector('[data-counter-full]').hidden})()`,
        ),
    );
    await c("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await save(form(f.counter_id), 2);
    check(
        "Successful save immediately updates 1 / 2 and removes full state",
        await run(
            `(()=>{const f=document.querySelector(${JSON.stringify(form(f.counter_id))});return f.elements.max_active_cashiers.value==='2'&&f.querySelector('[data-counter-occupancy]').textContent.endsWith('1 / 2')&&f.querySelector('[data-counter-full]').hidden})()`,
        ),
    );
    await ready(f);
    check(
        "Saved limit survives normal refresh without login",
        await run(
            `document.querySelector(${JSON.stringify(form(f.counter_id))}).elements.max_active_cashiers.value==='2'`,
        ),
    );
    const second = await command("shift-open", {
        user_id: f.cashiers[1].id,
        stock_location_id: f.counter_id,
        type: "CASHIER",
    });
    check("Second fixture shift opens", second.status === 200, second);
    await ready(f);
    await scopedSet(form(f.counter_id), "max_active_cashiers", 1);
    await run(`document.querySelector(${JSON.stringify(form(f.counter_id))}).requestSubmit()`);
    await wait(
        `document.querySelector(${JSON.stringify(form(f.counter_id))}+' [data-cashier-limit-status]').classList.contains('is-error')`,
    );
    check(
        "Unsafe decrease restores saved 2, retains 2 / 2 and shows error",
        await run(
            `(()=>{const f=document.querySelector(${JSON.stringify(form(f.counter_id))});return f.elements.max_active_cashiers.value==='2'&&f.dataset.savedLimit==='2'&&f.querySelector('[data-counter-occupancy]').textContent.endsWith('2 / 2')&&f.querySelector('[data-cashier-limit-status]').textContent.includes('Close one active cashier shift')})()`,
        ),
    );
    await shot("unsafe-reduction-390");
    await save(form(f.counter_id), 3);
    check(
        "Success after rejection clears error and updates denominator",
        await run(
            `document.querySelector(${JSON.stringify(form(f.counter_id))}+' [data-counter-occupancy]').textContent.endsWith('2 / 3')`,
        ),
    );
    await navigate("/business-units");
    await wait('!!document.querySelector("[data-cashier-limit]")');
    const serverForm = `[data-cashier-limit]:has([name=id][value="${f.counter_id}"])`;
    await save(serverForm, 2);
    check(
        "Business Unit page keeps identity and editor occupancy aligned",
        await run(
            `(()=>{const f=document.querySelector(${JSON.stringify(serverForm)});return f.querySelector('[data-counter-occupancy]').textContent.endsWith('2 / 2')&&f.previousElementSibling.querySelector('[data-counter-identity-occupancy]').textContent.endsWith('2 / 2')})()`,
        ),
    );
    await closeShift(first.body.id);
    await ready(f);
    check(
        "Counter refresh reflects close as 1 / 2",
        await run(
            `document.querySelector(${JSON.stringify(form(f.counter_id))}+' [data-counter-occupancy]').textContent.endsWith('1 / 2')`,
        ),
    );
    await closeShift(second.body.id);
    await ready(f);
    await save(form(f.counter_id), 1);
    check(
        "Final counter returns to complete 0 / 1 with input 1",
        await run(
            `document.querySelector(${JSON.stringify(form(f.counter_id))}+' [data-counter-occupancy]').textContent.endsWith('0 / 1')&&document.querySelector(${JSON.stringify(form(f.counter_id))}).elements.max_active_cashiers.value==='1'`,
        ),
    );
    check("No runtime errors", errors.length === 0, errors);
    check("No server errors", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        output + "/result.json",
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
} finally {
    ws.close();
}
