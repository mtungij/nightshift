import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-pin-browser";
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
async function login(email) {
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("email", email);
    await set("password", "Nightshift!2026");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait('!!document.querySelector("#main h1")');
}
async function draftCheck() {
    await login("asha@nightshift.test");
    let data = await snap();
    if (!data.servingWaiters.some((w) => w.id === data.user.id)) {
        const opened = await api("shift-open", {
            user_id: data.user.id,
            stock_location_id: data.location_id,
            type: "WAITER",
            opening_cash: "0",
        });
        check("Waiter opens own shift for draft smoke", opened.status === 200);
        await navigate("/dashboard#pos");
        await wait('!!document.querySelector(".pos-cart")');
    }
    data = await snap();
    const p = data.products.find((p) => p.sku === "BR-001"),
        balance = Number(data.waiters.find((w) => w.id === data.user.id).outstanding);
    await click(`[data-action=add-cart][data-id="${p.id}"]`);
    await click("[data-action=save-draft]");
    await wait(
        `fetch('/api/nightshift/bootstrap').then(r=>r.json()).then(s=>s.orders.length===${data.orders.length + 1})`,
    );
    const saved = await snap(),
        draft = saved.orders.find((o) => !data.orders.some((old) => old.id === o.id));
    check(
        "Waiter saves draft only to own account without stock or ledger posting",
        draft.status === "Draft" &&
            draft.waiter === data.user.id &&
            Number(saved.products.find((item) => item.id === p.id).stock) === Number(p.stock) &&
            Number(saved.waiters.find((w) => w.id === data.user.id).outstanding) === balance,
    );
    await navigate("/dashboard#orders");
    await wait('!!document.querySelector(".subtabs")');
    await click('[data-action=tab][data-value="All orders"]');
    await click(`[data-action=order-detail][data-id="${draft.id}"]`);
    await click(`[data-action=send-draft][data-id="${draft.id}"]`);
    await c("Emulation.setDeviceMetricsOverride", {
        width: 320,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    check(
        "Draft Send to Cashier requires serving PIN and fits 320px",
        await run(
            'document.documentElement.scrollWidth<=320 && document.querySelector("[name=pin]").disabled',
        ),
    );
    await click(`[data-action=serving-waiter][data-id="${data.user.id}"]`);
    await set("pin", "0123");
    await submit("serving-order");
    const issued = await snap();
    check(
        "Sending existing draft issues stock and ledger exactly once",
        issued.orders.find((o) => o.id === draft.id).status === "Issued" &&
            Number(issued.products.find((item) => item.id === p.id).stock) ===
                Number(p.stock) - 1 &&
            Number(issued.waiters.find((w) => w.id === data.user.id).outstanding) ===
                balance + Number(draft.total),
    );
    await shot("waiter-draft-issued-320");
}
async function cashierLayoutCheck() {
    await login("cashier@nightshift.test");
    for (const width of [1440, 390, 320]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width !== 1440,
        });
        for (const theme of ["light", "dark"]) {
            await run(`window.NightshiftTheme.save(${JSON.stringify(theme)})`);
            check(
                `${theme} ${width}: cashier settlement counter and full waiter names fit`,
                await run(
                    `document.documentElement.scrollWidth<=${width} && !!document.querySelector('[aria-label="Settlement counter"]') && document.querySelector('#main').textContent.includes('Asha Mwakalinga') && document.querySelector('#main').textContent.includes('Issued Value')`,
                ),
            );
            await shot(`${theme}-${width}-settlement-counter`);
        }
    }
    const data = await snap(),
        other = data.locations.find((l) => l.type === "COUNTER" && l.id !== data.location_id);
    await run(
        `(()=>{const select=document.querySelector('[aria-label="Settlement counter"]');select.value='${other.id}';select.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
    await wait(
        `document.querySelector('[aria-label="Settlement counter"]').value==='${other.id}' && fetch('/api/nightshift/bootstrap?stock_location_id=${other.id}').then(r=>r.json()).then(s=>s.waiters.every(w=>w.settlement_balance==='0.00'))`,
    );
    check("Other counter does not inherit Main Counter settleable balances", true);
    await run('window.NightshiftTheme.save("light")');
}
try {
    const safety = await fetch(new URL("/login", base));
    check(
        "Dedicated MySQL validation target confirmed before login",
        safety.headers.get("X-Nightshift-Validation-Database") === "nightshift_validation" &&
            safety.headers.get("X-Nightshift-Validation-Driver") === "mysql",
    );
    await c("Runtime.enable");
    await c("Page.enable");
    await c("Network.enable");
    // Headless validation records print calls instead of opening a printer dialog.
    // The order transaction and ticket HTTP request execute normally.
    await c("Page.addScriptToEvaluateOnNewDocument", {
        source: "window.__printCalls=0;window.print=()=>{window.__printCalls++;};",
    });
    await c("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    if (process.env.SMOKE_DRAFT_ONLY === "1") {
        await draftCheck();
    } else if (process.env.SMOKE_CASHIER_ONLY === "1") {
        await cashierLayoutCheck();
    } else {
        await login("admin@nightshift.test");
        const initial = await snap(),
            asha = initial.servingWaiters.find((w) => w.name.startsWith("Asha"));
        const cashierId = initial.team.find((u) => u.role === "Cashier").id;
        await click('[data-nav="users"]');
        await click(`[data-action="staff-pin"][data-id="${asha.id}"]`);
        check(
            "PIN admin form is masked and never shows an old PIN",
            await run(
                'document.querySelector("[name=pin]").type==="password" && document.querySelector("[name=pin]").value===""',
            ),
        );
        await set("pin", "0123");
        await submit("staff-pin");
        check("Staff PIN save clears the form", await run('!document.querySelector("[name=pin]")'));
        await click(`[data-action="staff-pin"][data-id="${cashierId}"]`);
        await set("pin", "4567");
        await submit("staff-pin");
        for (const width of [1440, 390]) {
            await c("Emulation.setDeviceMetricsOverride", {
                width,
                height: width === 1440 ? 1000 : 844,
                deviceScaleFactor: 1,
                mobile: width !== 1440,
            });
            for (const theme of ["light", "dark"]) {
                await run(`window.NightshiftTheme.save(${JSON.stringify(theme)})`);
                await navigate("/dashboard#pos");
                await wait('!!document.querySelector(".pos-cart")');
                check(
                    `${theme} ${width}: no waiter dropdown`,
                    await run('!document.querySelector("[data-change=cart-waiter]")'),
                );
                const before = await snap(),
                    p = before.products.find((p) => p.sku === "BR-001");
                const waiterBefore = before.waiters.find((w) => w.id === asha.id);
                await click(`[data-action="add-cart"][data-id="${p.id}"]`);
                await click('[data-action="send-order"]');
                check(
                    `${theme} ${width}: identity modal fits viewport`,
                    await run(
                        `document.querySelector('#modal-title').textContent==='Who is serving this order?' && document.documentElement.scrollWidth<=${width}`,
                    ),
                );
                check(
                    `${theme} ${width}: PIN required before confirmation`,
                    await run(
                        'document.querySelector("[data-form=serving-order] [type=submit]").disabled && document.querySelector("[name=pin]").disabled',
                    ),
                );
                await click(`[data-action="serving-waiter"][data-id="${asha.id}"]`);
                await set("pin", "9999");
                await run('document.querySelector("[data-form=serving-order]").requestSubmit()');
                await wait(
                    '!document.querySelector("[data-form=serving-order] [type=submit]").disabled && document.querySelector("#toast").textContent.includes("PIN")',
                );
                check(
                    `${theme} ${width}: wrong PIN leaves cart and stock intact`,
                    (await snap()).orders.length === before.orders.length,
                );
                check(
                    `${theme} ${width}: rejected PIN cleared`,
                    await run('document.querySelector("[name=pin]").value===""'),
                );
                await shot(`${theme}-${width}-identity`);
                await set("pin", "0123");
                await submit("serving-order");
                await wait(
                    '!!document.querySelector("#issue-ticket-frame")?.contentDocument?.querySelector("[data-committed-order]")',
                );
                const after = await snap(),
                    o = after.orders.find((o) => !before.orders.some((b) => b.id === o.id));
                check(
                    `${theme} ${width}: immediate ISSUED status`,
                    o.status === "Issued" && o.waiter === asha.id,
                );
                check(
                    `${theme} ${width}: stock deducted exactly once`,
                    Number(after.products.find((item) => item.id === p.id).stock) ===
                        Number(p.stock) - 1,
                );
                check(
                    `${theme} ${width}: ledger debited once`,
                    Number(after.waiters.find((w) => w.id === asha.id).outstanding) ===
                        Number(waiterBefore.outstanding) + Number(o.total),
                );
                check(
                    `${theme} ${width}: print follows committed ticket load`,
                    await run(
                        `document.querySelector('#issue-ticket-frame').contentDocument.body.dataset.committedOrder==='${o.id}' && document.querySelector('#issue-ticket-frame').contentWindow.__printCalls===1`,
                    ),
                );
                check(
                    `${theme} ${width}: ticket remains light/80mm/unpaid`,
                    await run(
                        `(()=>{const doc=document.querySelector('#issue-ticket-frame').contentDocument;return doc.body.innerText.includes('UNPAID')&&doc.documentElement.innerHTML.includes('80mm')&&getComputedStyle(doc.body).backgroundColor==='rgb(255, 255, 255)';})()`,
                    ),
                );
                // The browser session, rather than Node's fetch, owns authentication.
                check(
                    `${theme} ${width}: PIN absent from rendered workspace and storage`,
                    await run(
                        `!document.querySelector('[name=pin]') && !Object.values(localStorage).some(v=>v==='0123'||v==='9999')`,
                    ),
                );
                await shot(`${theme}-${width}-sent`);
            }
        }
        await run('window.NightshiftTheme.save("light")');
        await login("cashier@nightshift.test");
        check(
            "Cashier primary workflow is waiter settlement",
            await run(
                'document.querySelector("#main h1").textContent==="Waiter accounts" && !document.querySelector("[data-action=issue]")',
            ),
        );
        for (const width of [1440, 390]) {
            await c("Emulation.setDeviceMetricsOverride", {
                width,
                height: 1000,
                deviceScaleFactor: 1,
                mobile: width !== 1440,
            });
            for (const theme of ["light", "dark"]) {
                await run(`window.NightshiftTheme.save(${JSON.stringify(theme)})`);
                check(
                    `${theme} ${width}: cashier account actions fit`,
                    await run(
                        `document.documentElement.scrollWidth<=${width} && !!document.querySelector('[data-action=waiter-payment][data-value=full]')`,
                    ),
                );
                await shot(`${theme}-${width}-cashier-accounts`);
            }
        }
        const prePayment = await snap(),
            due = Number(prePayment.waiters.find((w) => w.id === asha.id).outstanding);
        await click(`[data-action="waiter-payment"][data-id="${asha.id}"][data-value="partial"]`);
        await set("amount", "5000");
        await submit("waiter-payment");
        check(
            "Partial waiter payment reduces account by received amount",
            Number((await snap()).waiters.find((w) => w.id === asha.id).outstanding) === due - 5000,
        );
        await click(`[data-action="waiter-payment"][data-id="${asha.id}"][data-value="full"]`);
        await submit("waiter-payment");
        check(
            "Full waiter settlement reconciles all issued invoices",
            (await snap()).waiters.find((w) => w.id === asha.id).outstanding === "0.00",
        );
        await navigate("/dashboard#shifts");
        await wait('!!document.querySelector("[data-action=close-shift]")');
        await click('[data-action="close-shift"]');
        check(
            "Cashier closing displays unpaid staff balances",
            await run(
                'document.querySelector(".modal").innerText.includes("Staff with unpaid balances")&&document.querySelector(".modal").innerText.includes("James")',
            ),
        );
        await click('[data-action="close-modal"]');
        await navigate("/quick-lock");
        check(
            "Quick Lock masks PIN and preserves account identity",
            await run(
                'document.querySelector("[name=pin]").type==="password"&&document.body.innerText.includes("Peter Macha")',
            ),
        );
        check(
            "Locked API cannot read business data",
            await run(
                'fetch("/api/nightshift/bootstrap",{headers:{Accept:"application/json"}}).then(r=>r.status===423)',
            ),
        );
        await set("pin", "4567");
        await run('document.querySelector("form[action$=unlock]").requestSubmit()');
        await wait('!!document.querySelector("#main h1")');
        check(
            "Own PIN unlocks cashier settlement workspace",
            await run('document.querySelector("#main h1").textContent==="Waiter accounts"'),
        );
        await run('window.NightshiftTheme.save("light")');
        await login("asha@nightshift.test");
        check(
            "Waiter password login still reaches POS",
            await run('document.querySelector("#main h1").textContent==="Point of sale"'),
        );
        await navigate("/quick-lock");
        await set("pin", "0123");
        await run('document.querySelector("form[action$=unlock]").requestSubmit()');
        await wait('!!document.querySelector("#main h1")');
        check(
            "Waiter own PIN unlocks POS",
            await run('document.querySelector("#main h1").textContent==="Point of sale"'),
        );
    }
    check("No browser runtime exceptions", errors.length === 0, errors);
    check("No HTTP 5xx responses", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify({ result: "PASS", checks, errors, httpErrors }, null, 2),
    );
} catch (error) {
    await shot("failure");
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify(
            { result: "FAIL", checks, errors, httpErrors, failure: String(error) },
            null,
            2,
        ),
    );
    console.error(String(error));
    process.exitCode = 1;
} finally {
    ws.close();
    await fetch(`${debug}/json/close/${target.id}`);
}
