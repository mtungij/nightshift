import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-unit-operations-browser";
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
    const f = JSON.parse(
        await fs.readFile("/tmp/nightshift-unit-operations-fixtures.json", "utf8"),
    );
    await login(f.owner);
    for (const locale of ["en", "sw"]) {
        await run(
            `fetch('/settings/language',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:${JSON.stringify(locale)}})})`,
        );
        for (const width of (process.env.SMOKE_WIDTHS || "1440,1920,768,390,412")
            .split(",")
            .map(Number)) {
            await c("Emulation.setDeviceMetricsOverride", {
                width,
                height: 950,
                deviceScaleFactor: 1,
                mobile: width < 600,
            });
            for (const theme of ["light", "dark"]) {
                for (const page of ["pos", "inventory", "business-units"]) {
                    await navigate(
                        page === "business-units" ? "/business-units" : "/dashboard#" + page,
                    );
                    await wait(
                        '!!document.querySelector(".sidebar") && !!document.querySelector(".topbar")',
                    );
                    if ((await run("document.documentElement.dataset.theme")) !== theme) {
                        await click("[data-theme-toggle]");
                        await wait(
                            `document.documentElement.dataset.theme===${JSON.stringify(theme)}`,
                        );
                    }
                    if (page === "pos") {
                        await wait('!!document.querySelector(".pos-cart")');
                        const context = await run(
                            `fetch('/api/nightshift/bootstrap?pos_context=1&branch_id=${f.branch_id}&business_unit_id=${f.simple_unit_id}').then(r=>r.json())`,
                        );
                        check(
                            `${locale}/${width}/${theme}: Simple unit has an internal default`,
                            context.operational_context.simple_mode &&
                                context.location_id ===
                                    context.operational_context.default_stock_location_id,
                        );
                        const selector = '[data-change="business-unit"]';
                        if (await run(`!!document.querySelector(${JSON.stringify(selector)})`)) {
                            await change(selector, f.simple_unit_id);
                            await wait(
                                `document.querySelector('[data-change="business-unit"]')?.value === '${f.simple_unit_id}' && !document.querySelector('[data-change="cart-counter"]') && !document.querySelector('[data-pos-counter-fixed]')`,
                            );
                        }
                    }
                    if (page === "inventory") {
                        await wait(
                            `!!document.querySelector('[data-action="tab"][data-value="Goods receipt"]')`,
                        );
                        await click('[data-action="tab"][data-value="Goods receipt"]');
                        if (
                            await run(`!!document.querySelector('[data-change="business-unit"]')`)
                        ) {
                            await change('[data-change="business-unit"]', f.simple_unit_id);
                            await wait(
                                `document.querySelector('[data-change="business-unit"]')?.value === '${f.simple_unit_id}' && !document.querySelector('[name="location-display"]')`,
                            );
                        }
                        await click("[data-action=grn]");
                        await wait('!!document.querySelector("[data-form=grn]")');
                        check(
                            `${locale}/${width}/${theme}: receiving unit form`,
                            await run(
                                'document.querySelector("[data-form=grn]").dataset.unitStock === "1"',
                            ),
                        );
                        check(
                            `${locale}/${width}/${theme}: receiving actions reachable`,
                            await run(
                                `(()=>{const r=document.querySelector('[data-form=grn] [type=submit]').getBoundingClientRect();return r.top>=0 && r.bottom<=innerHeight;})()`,
                            ),
                        );
                    }
                    if (page === "inventory" && process.env.SMOKE_REQUIRE_PRODUCTS === "1") {
                        await wait(
                            `document.querySelector('[data-form=grn] [name=product]').options.length > 0 && !document.querySelector('[data-form=grn] [type=submit]').disabled`,
                        );
                        check(
                            `${locale}/${width}/${theme}: receiving product and submit usable`,
                            true,
                        );
                    }
                    const layout = await run(
                        `({width:innerWidth,scroll:document.documentElement.scrollWidth,sidebar:!!document.querySelector('.sidebar'),topbar:!!document.querySelector('.topbar'),locale:document.documentElement.lang,theme:document.documentElement.dataset.theme})`,
                    );
                    check(
                        `${locale}/${width}/${theme}/${page}: shell and no overflow`,
                        layout.sidebar &&
                            layout.topbar &&
                            layout.scroll <= width + 1 &&
                            layout.locale === locale &&
                            layout.theme === theme,
                        layout,
                    );
                    if (width < 600 && page !== "inventory") {
                        await click("[data-action=menu]");
                        check(
                            `${locale}/${width}/${theme}/${page}: drawer opens`,
                            await run(
                                'document.querySelector(".sidebar").classList.contains("open")',
                            ),
                        );
                        await click("[data-action=menu]");
                    }
                    if (width === 390 && theme === "dark") await shot(`${locale}-${width}-${page}`);
                }
            }
        }
    }
    check("No JavaScript errors", !errors.length, errors);
    check("No server errors", !httpErrors.length, httpErrors);
} catch (error) {
    checks.push({ name: "Runner", result: "FAIL", detail: error.stack });
    process.exitCode = 1;
} finally {
    await fs.writeFile(
        `${output}/results.json`,
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
    ws.close();
}
