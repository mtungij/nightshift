import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-product-browser";
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
        throw Error("Use isolated browser database.");
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const f = JSON.parse(await fs.readFile("/tmp/nightshift-business-unit-fixtures.json", "utf8"));
    await login(f.owner, "/dashboard");
    await navigate("/dashboard#inventory");
    await wait('!!document.querySelector("[data-change=business-unit]")');
    await run(
        `(()=>{const e=document.querySelector('[data-change=business-unit]');e.value='${f.cases.restaurant.unit_id}';e.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
    await wait(
        `!!document.querySelector('[data-change=business-unit] option[value="${f.cases.restaurant.unit_id}"]:checked') && !!document.querySelector('[data-action=inventory-add]')`,
    );
    await sleep(500);
    const name = "Restaurant UI meal " + Date.now();
    await click("[data-action=inventory-add][data-value=Products]");
    await wait('!!document.querySelector("[data-product-unit]")');
    check(
        "Add preselects Restaurant only",
        await run(
            `JSON.stringify([...document.querySelectorAll('[data-product-unit]')].filter(r=>r.querySelector('[data-product-enabled]').checked).map(r=>Number(r.dataset.productUnit)))==='[${f.cases.restaurant.unit_id}]'`,
        ),
    );
    await scopedSet("[data-form=product]", "name", name);
    await scopedSet("[data-form=product]", "price", "3000");
    await run(
        `(()=>{const i=document.querySelector('[data-product-unit="${f.cases.restaurant.unit_id}"] [data-product-price]');i.value='2500';i.dispatchEvent(new Event('input',{bubbles:true}));})()`,
    );
    for (const locale of ["en", "sw"]) {
        if (locale === "sw") {
            await click(".modal [data-action=close-modal]");
            await run(
                `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'sw'})})`,
            );
            await navigate("/dashboard#inventory");
            await wait('!!document.querySelector("[data-action=inventory-add]")');
            await click("[data-action=inventory-add][data-value=Products]");
            await wait('!!document.querySelector("[data-product-unit]")');
        }
        check(
            locale + " availability heading",
            await run(
                `document.querySelector('[data-product-availability]').innerText.includes(${JSON.stringify(locale === "en" ? "Available in Business Units" : "Inapatikana kwenye Vitengo vya Biashara")})`,
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
                await sleep(150);
                const bounds = await run(
                    `(()=>{const m=document.querySelector('.modal');return {overflow:document.documentElement.scrollWidth>innerWidth||m.scrollWidth>m.clientWidth+1,controls:[...m.querySelectorAll('input:not([type=hidden]),select,button')].filter(e=>e.getBoundingClientRect().width).every(e=>{const r=e.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1}),cards:document.querySelectorAll('[data-product-unit]').length}})()`,
                );
                check(
                    locale + " " + theme + " " + width + "px form fits",
                    !bounds.overflow && bounds.controls && bounds.cards > 0,
                    bounds,
                );
                await run(
                    "document.querySelector('[data-product-availability]').scrollIntoView({block:'start'})",
                );
                await shot("availability-" + locale + "-" + theme + "-" + width);
            }
        }
    }
    // Save the real mobile Add Product form after changing locale.
    await c("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await scopedSet("[data-form=product]", "name", name);
    await scopedSet("[data-form=product]", "price", "3000");
    await run(
        `document.querySelectorAll('[data-product-unit]').forEach(r=>{const i=r.querySelector('[data-product-enabled]');i.checked=Number(r.dataset.productUnit)===${f.cases.restaurant.unit_id};i.dispatchEvent(new Event('change',{bubbles:true}));});const i=document.querySelector('[data-product-unit="${f.cases.restaurant.unit_id}"] [data-product-price]');i.value='2500';`,
    );
    await run(`document.querySelector('[data-form=product]').requestSubmit()`);
    await wait('!document.querySelector(".modal")');
    const fetchJson = (path) =>
        run(
            "fetch(" +
                JSON.stringify(path) +
                ',{headers:{Accept:"application/json"}}).then(r=>r.json())',
        );
    const restaurant = await fetchJson(
        "/api/nightshift/bootstrap?business_unit_id=" + f.cases.restaurant.unit_id,
    );
    const p = restaurant.products.find((p) => p.name === name);
    check(
        "Mobile save Restaurant override",
        p?.price === "2500.00" && p.default_price === "3000.00",
        p,
    );
    for (const id of [1, f.cases.bar.unit_id]) {
        const data = await fetchJson(
            "/api/nightshift/bootstrap?business_unit_id=" +
                id +
                "&search=" +
                encodeURIComponent(name),
        );
        check("Hidden outside Restaurant " + id, !data.products.some((x) => x.name === name));
    }
    await click(`[data-action=edit-product][data-id="${p.id}"]`);
    await wait('!!document.querySelector("[data-product-unit]")');
    check(
        "Edit reads company price",
        (await run("document.querySelector('[data-form=product] [name=price]').value")) ===
            "3000.00",
    );
    check(
        "Edit restores explicit checked assignment",
        await run(
            `JSON.stringify([...document.querySelectorAll('[data-product-unit]')].filter(r=>r.querySelector('[data-product-enabled]').checked).map(r=>Number(r.dataset.productUnit)))==='[${f.cases.restaurant.unit_id}]'`,
        ),
    );
    await click(".modal [data-action=close-modal]");
    await click('[data-action=tab][data-value="Goods receipt"]');
    await click("[data-action=grn]");
    await wait(
        '!!document.querySelector("[data-receiving-context]") && !document.querySelector("[data-form=grn] [type=submit]").disabled',
    );
    check(
        "Receive Goods has unit helper and no second unit selector",
        await run(
            "!!document.querySelector('[data-receiving-context]').textContent && !document.querySelector('[data-form=grn] [name=business_unit_id]')",
        ),
    );
    for (const width of [390, 412, 1440]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 915,
            deviceScaleFactor: 1,
            mobile: width < 721,
        });
        await sleep(100);
        check(
            "Receiving fits " + width,
            await run(
                "document.documentElement.scrollWidth<=innerWidth && document.querySelector('.modal').scrollWidth<=document.querySelector('.modal').clientWidth+1",
            ),
        );
    }
    await shot("receiving-mobile");
    await click(".modal [data-action=close-modal]");
    for (const unit of ["bar", "restaurant"]) {
        await login(f.cases[unit].terminal, "/dashboard");
        await navigate("/dashboard#pos");
        await wait('!!document.querySelector(".product-grid")');
        const data = await fetchJson("/api/nightshift/bootstrap");
        check(
            unit + " terminal availability",
            data.products.some((x) => x.name === name) === (unit === "restaurant"),
        );
    }
    check("No runtime errors", errors.length === 0, errors);
    check("No server errors", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        output + "/result.json",
        JSON.stringify({ checks, errors, httpErrors, product_id: p.id }, null, 2),
    );
} finally {
    ws.close();
}
