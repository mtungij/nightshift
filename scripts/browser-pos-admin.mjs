import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-pos-admin-browser";
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
    const f = JSON.parse(await fs.readFile("/tmp/nightshift-pos-admin-fixtures.json", "utf8"));
    await login(f.owner);
    const first = await run(
        `fetch('/api/nightshift/bootstrap?pos_context=1&branch_id=${f.branch_id}').then(r=>r.json())`,
    );
    const primary = first.branches.find((b) => b.id === f.branch_id).name;
    check(
        "Company-wide owner with narrow pivot and no staff-operations permission is unlocked",
        first.pos_context.broad_unit_access &&
            !first.pos_context.locked &&
            first.pos_context.can_switch_unit,
    );
    for (const unit of [f.bar.unit_id, f.restaurant.unit_id, f.empty_unit_id]) {
        const data = await run(
            `fetch('/api/nightshift/bootstrap?pos_context=1&branch_id=${f.branch_id}&business_unit_id=${unit}').then(r=>r.json())`,
        );
        check(
            `Unit ${unit}: complete branch options survive context change`,
            JSON.stringify(data.pos_unit_options.map((u) => u.id)) ===
                JSON.stringify(f.expected_options) &&
                data.pos_unit_options.every((u) => u.branch_id === f.branch_id && u.active) &&
                data.pos_context.can_switch_unit,
        );
    }
    check(
        "Cross-branch unit rejected",
        (await run(
            `fetch('/api/nightshift/bootstrap?pos_context=1&branch_id=${f.no_units_branch_id}&business_unit_id=${f.bar.unit_id}').then(r=>r.status)`,
        )) === 422,
    );
    check(
        "Cross-company unit rejected",
        (await run(
            `fetch('/api/nightshift/bootstrap?pos_context=1&business_unit_id=${f.foreign_unit_id}').then(r=>r.status)`,
        )) === 403,
    );
    const expected = JSON.stringify(f.expected_options);
    for (const locale of ["en", "sw"]) {
        await run(
            `fetch('/settings/language',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${locale}'})})`,
        );
        await navigate("/dashboard#pos");
        await wait(`!!document.querySelector('.pos-cart [data-change="business-unit"]')`);
        await change('.pos-cart [data-change="business-unit"]', f.empty_unit_id);
        const noCounters =
            locale === "en"
                ? "No counters are available in this Business Unit."
                : "Hakuna kaunta zinazopatikana kwenye Kitengo hiki cha Biashara.";
        await wait(
            `document.querySelector('[data-pos-context-message]')?.textContent===${JSON.stringify(noCounters)}`,
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
                await sleep(350);
                const state = await run(
                    `(()=>{const select=document.querySelector('.pos-cart [data-change="business-unit"]');return {enabled:!!select&&!select.disabled,options:select?[...select.options].map(o=>Number(o.value)):[],overflow:document.documentElement.scrollWidth>innerWidth,reason:document.querySelector('[data-pos-context-message]')?.textContent,products:document.querySelectorAll('[data-action="add-cart"]').length}})()`,
                );
                check(
                    `${locale}/${theme}/${width}: counterless unit keeps all options usable`,
                    state.enabled &&
                        JSON.stringify(state.options) === expected &&
                        !state.overflow &&
                        state.reason === noCounters &&
                        state.products === 0,
                    state,
                );
                await shot(`${locale}-${theme}-${width}-counterless`);
            }
        }
        await change('.pos-cart [data-change="business-unit"]', f.no_products_unit_id);
        const noProducts =
            locale === "en"
                ? "No products are available in this Business Unit."
                : "Hakuna bidhaa zinazopatikana kwenye Kitengo hiki cha Biashara.";
        await wait(
            `document.querySelector('[data-pos-context-message]')?.textContent===${JSON.stringify(noProducts)}`,
        );
        check(
            `${locale}: valid counter with no products has distinct state`,
            await run(
                `!!document.querySelector('[data-pos-counter-fixed]')&&!document.querySelector('.pos-cart [data-change="business-unit"]').disabled`,
            ),
        );
        await change('[data-change="branch"]', f.no_units_branch_name);
        const noUnits =
            locale === "en"
                ? "No Business Units are configured for this branch."
                : "Hakuna Vitengo vya Biashara vilivyowekwa kwa tawi hili.";
        await wait(
            `document.querySelector('[data-pos-context-message]')?.textContent===${JSON.stringify(noUnits)}`,
        );
        check(
            `${locale}: branch with no active units has distinct state`,
            await run(
                `!document.querySelector('.pos-cart [data-change="business-unit"]')&&document.querySelectorAll('[data-action="add-cart"]').length===0&&document.querySelector('[data-pos-counter-fixed]').value==='—'`,
            ),
        );
        for (const width of [390, 412]) {
            await c("Emulation.setDeviceMetricsOverride", {
                width,
                height: 950,
                deviceScaleFactor: 1,
                mobile: true,
            });
            await sleep(350);
            check(
                `${locale}/${width}: no-unit branch fits mobile`,
                await run("document.documentElement.scrollWidth<=innerWidth"),
            );
        }
        await change('[data-change="branch"]', primary);
        await wait(`!!document.querySelector('.pos-cart [data-change="business-unit"]')`);
        check(
            `${locale}: returning to branch restores all active options`,
            await run(
                `JSON.stringify([...document.querySelector('.pos-cart [data-change="business-unit"]').options].map(o=>Number(o.value)))===${JSON.stringify(expected)}`,
            ),
        );
    }
    for (const username of [f.owner_no_shift, f.super_admin]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width: 1440,
            height: 950,
            deviceScaleFactor: 1,
            mobile: false,
        });
        await login(username);
        await navigate("/dashboard#dashboard");
        await wait(`!!document.querySelector('[data-nav="pos"]')`);
        await click('[data-nav="pos"]');
        await wait(`!!document.querySelector('.pos-cart [data-change="business-unit"]')`);
        check(
            `${username === f.owner_no_shift ? "Owner without shift" : "Super Admin"}: all authorized units interactive`,
            await run(
                `!document.querySelector('.pos-cart [data-change="business-unit"]').disabled&&JSON.stringify([...document.querySelector('.pos-cart [data-change="business-unit"]').options].map(o=>Number(o.value)))===${JSON.stringify(expected)}`,
            ),
        );
    }
    check("No browser/server errors", !errors.length && !httpErrors.length, { errors, httpErrors });
} finally {
    await fs.writeFile(
        `${output}/results.json`,
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
    ws.close();
}
