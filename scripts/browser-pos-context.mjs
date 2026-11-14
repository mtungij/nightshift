import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-pos-context-browser";
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
const change = (selector, value) =>
    run(
        `(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)throw Error('Missing context selector');e.value=${JSON.stringify(String(value))};e.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
try {
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const f = JSON.parse(await fs.readFile("/tmp/nightshift-pos-context-fixtures.json", "utf8"));
    await login(f.owner);
    const barButton = `[data-action="add-cart"][data-id="${f.products.bar.id}"]`;
    const restaurantButton = `[data-action="add-cart"][data-id="${f.products.restaurant.id}"]`;
    for (const locale of ["en", "sw"]) {
        await run(
            `fetch('/settings/language',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${locale}'})})`,
        );
        await navigate("/dashboard#pos");
        await wait(`!!document.querySelector('.pos-cart [data-change="business-unit"]')`);
        await change('.pos-cart [data-change="business-unit"]', f.bar.unit_id);
        await wait(
            `[...document.querySelector('.pos-cart [data-change="cart-counter"]').options].some(o=>o.value==='${f.bar.counter_id}')`,
        );
        check(
            `${locale}: multiple counters require an explicit choice`,
            await run(
                `document.querySelector('.pos-cart [data-change="cart-counter"]').value===''`,
            ),
        );
        await change('.pos-cart [data-change="cart-counter"]', f.bar.counter_id);
        await wait(
            `!!document.querySelector('${barButton}')&&document.querySelector('.pos-cart [data-change="business-unit"]').value==='${f.bar.unit_id}'`,
        );
        for (const theme of ["light", "dark"]) {
            await run(`window.NightshiftTheme.save('${theme}')`);
            for (const width of [390, 412, 1440]) {
                await c("Emulation.setDeviceMetricsOverride", {
                    width,
                    height: 950,
                    deviceScaleFactor: 1,
                    mobile: width < 600,
                });
                await sleep(150);
                const state = await run(
                    `({overflow:document.documentElement.scrollWidth>innerWidth,unit:document.querySelector('.pos-cart').innerText.includes('${locale === "en" ? "Business Unit" : "Kitengo cha Biashara"}'),controls:[...document.querySelectorAll('.cart-selects input,.cart-selects select,.cart-actions button')].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth}),hidden:!document.querySelector('${restaurantButton}')})`,
                );
                check(
                    `${locale}/${theme}/${width}: explicit unit, controls and filtered products`,
                    !state.overflow && state.unit && state.controls && state.hidden,
                    state,
                );
                await shot(`${locale}-${theme}-${width}`);
            }
        }
    }
    const barData = await run(
        `fetch('/api/nightshift/bootstrap?pos_context=1&business_unit_id=${f.bar.unit_id}&branch_id=${f.branch_id}&stock_location_id=${f.bar.counter_id}').then(r=>r.json())`,
    );
    check(
        "Bar price override and counter stock",
        barData.products.find((p) => p.id === f.products.bar.id).units[0][2] === "5000.00" &&
            Number(barData.products.find((p) => p.id === f.products.bar.id).stock) ===
                Number(process.env.POS_CONTEXT_STOCK || 10),
        barData.location_id,
    );
    await click(barButton);
    await change('.pos-cart [data-change="business-unit"]', f.restaurant.unit_id);
    check(
        "Cart blocks unit switch",
        await run(
            `document.querySelector('.pos-cart [data-change="business-unit"]').value==='${f.bar.unit_id}'&&document.querySelectorAll('.cart-item').length===1`,
        ),
    );
    await change('.pos-cart [data-change="cart-counter"]', f.second_counter_id);
    check(
        "Cart blocks counter switch",
        await run(
            `document.querySelector('.pos-cart [data-change="cart-counter"]').value==='${f.bar.counter_id}'&&document.querySelectorAll('.cart-item').length===1`,
        ),
    );
    const branchBefore = await run(`document.querySelector('[data-change="branch"]').value`);
    await change(
        '[data-change="branch"]',
        await run(
            `document.querySelector('[data-change="branch"]').options[document.querySelector('[data-change="branch"]').options.length-1].value`,
        ),
    );
    check(
        "Cart blocks branch switch",
        (await run(`document.querySelector('[data-change="branch"]').value`)) === branchBefore,
    );
    await click('[data-action="clear-cart"]');
    await click('[data-action="confirm-clear-cart"]');
    await run(
        `(()=>{const select=document.querySelector('.pos-cart [data-change="business-unit"]');select.value='${f.restaurant.unit_id}';select.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('${barButton}')?.click();})()`,
    );

    await wait(
        `!!document.querySelector('${restaurantButton}')&&!document.querySelector('${barButton}')`,
    );
    check(
        "Context loading blocks clicks on the old catalogue",
        await run(`document.querySelectorAll('.cart-item').length===0`),
    );
    check(
        "Restaurant one-counter auto-selection",
        await run(`!!document.querySelector('[data-pos-counter-fixed]')`),
    );
    const restData = await run(
        `fetch('/api/nightshift/bootstrap?pos_context=1&business_unit_id=${f.restaurant.unit_id}&branch_id=${f.branch_id}').then(r=>r.json())`,
    );
    check(
        "Company/default price fallback",
        restData.products.find((p) => p.id === f.products.restaurant.id).units[0][2] === "4000.00",
    );
    await run(
        `document.querySelector('.pos-search [data-input="search"],.search-input[data-input="search"],input[placeholder][data-input="search"]').value=${JSON.stringify(f.products.bar.name)};document.querySelector('input[placeholder][data-input="search"]').dispatchEvent(new Event('input',{bubbles:true}))`,
    );
    check(
        "Search does not reveal other-unit product",
        await run(`!document.querySelector('${barButton}')`),
    );
    await change('.pos-cart [data-change="business-unit"]', f.empty_unit_id);
    await wait(
        `document.querySelector('.pos-cart [data-change="business-unit"]').value==='${f.empty_unit_id}' && document.querySelector('.pos-cart').innerText.includes('Hakuna kaunta')`,
    );
    check(
        "Empty unit has no counters or products",
        await run(
            `document.querySelectorAll('[data-action="add-cart"]').length===0&&document.querySelector('.pos-cart').innerText.includes('Hakuna kaunta')`,
        ),
    );
    for (const [role, username] of [
        ["manager", f.manager],
        ["cashier", f.bar.cashier],
        ["waiter", f.bar.waiter],
        ["terminal", f.bar.terminal],
    ]) {
        await login(username);
        await navigate("/dashboard#pos");
        await wait(`!!document.querySelector('.pos-cart [data-pos-unit-fixed]')`);
        check(
            `${role}: assigned unit fixed`,
            await run(
                `document.querySelector('[data-pos-unit-fixed]').value===${JSON.stringify(f.bar.unit_name)}`,
            ),
        );
        if (role !== "manager")
            check(
                `${role}: assigned counter fixed`,
                await run(
                    `document.querySelector('[data-pos-counter-fixed]').value===${JSON.stringify(f.bar.counter_name)}&&!document.querySelector('.pos-cart [data-change="cart-counter"]')`,
                ),
            );
    }
    if (!process.env.POS_CONTEXT_SKIP_SEND) {
        await click(barButton);
        await click('[data-action="send-order"]');
        await wait(`!!document.querySelector('[data-form="serving-order"]')`);
        await set("waiter_id", f.bar.waiter_id);
        await set("pin", "0123");
        await run(`document.querySelector('[data-form="serving-order"]').requestSubmit()`);
        await wait(
            `!document.querySelector('[data-form="serving-order"]')&&document.querySelectorAll('.cart-item').length===0`,
        );
        check("Terminal PIN order send succeeds in fixed unit", true);
    } else {
        check(
            "Prior terminal PIN sale retained without a duplicate send",
            JSON.parse(await fs.readFile("/tmp/pos-context-reconciliation.json", "utf8")).result ===
                "PASS",
        );
    }
    check("No browser or server errors", !errors.length && !httpErrors.length, {
        errors,
        httpErrors,
    });
} finally {
    await fs.writeFile(
        `${output}/results.json`,
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
    ws.close();
}
