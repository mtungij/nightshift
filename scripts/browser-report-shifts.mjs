import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-report-shift-browser";
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

try {
    if (
        (await fetch(base + "/login")).headers.get("X-Nightshift-Validation-Database") !==
        "nightshift_treasury_browser_validation"
    )
        throw Error("Disposable database only");
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const f = JSON.parse(await fs.readFile("/tmp/nightshift-report-shift-fixtures.json", "utf8"));
    await login(f.owner);
    await navigate("/dashboard#reports");
    await wait(`!!document.querySelector('[data-action="report"][data-value="Daily Sales"]')`);
    await click('[data-action="report"][data-value="Daily Sales"]');
    await wait(`document.querySelector('[name="shift_id"]')?.options.length>1`);
    await set("branch", "");
    await set("business_unit", "");
    await set("counter", String(f.counter_id));
    await wait(`!!document.querySelector('[name="shift_id"] option[value="${f.shift_id}"]')`);
    check(
        "Shift search is visible and enabled",
        await run(
            `(()=>{const s=document.querySelector('[data-shift-search]');return !s.disabled&&!s.closest('.form-field').hidden})()`,
        ),
    );
    await run(
        `(()=>{const s=document.querySelector('[data-shift-search]');s.value='${f.shift_number}';s.dispatchEvent(new Event('input',{bubbles:true}));})()`,
    );
    await wait(`document.querySelector('[name="shift_id"]').options.length===2`);
    check(
        "Search narrows to matching operational shift",
        await run(
            `document.querySelector('[name="shift_id"] option[value="${f.shift_id}"]')!==null`,
        ),
    );
    await run(
        `(()=>{const s=document.querySelector('[data-shift-search]');s.value='';s.dispatchEvent(new Event('input',{bubbles:true}));})()`,
    );
    await wait(`document.querySelector('[name="shift_id"]').options.length>2`);
    await set("shift_id", String(f.shift_id));
    await wait(
        `document.getElementById('report-content').innerText.includes(${JSON.stringify(f.invoices[0])})`,
    );
    check(
        "Overnight before/after midnight sales stay together",
        await run(
            `document.getElementById('report-content').innerText.includes(${JSON.stringify(f.invoices[1])})`,
        ),
    );
    check(
        "Concurrent cashier and later shift excluded",
        await run(
            `!document.getElementById('report-content').innerText.includes(${JSON.stringify(f.other_invoice)})&&!document.getElementById('report-content').innerText.includes(${JSON.stringify(f.live_invoice)})`,
        ),
    );
    check(
        "Dates disabled and scope explained",
        await run(
            `document.querySelector('[name="from"]').disabled&&document.querySelector('[data-shift-summary]').innerText.includes('date range is ignored')`,
        ),
    );
    for (const locale of ["en", "sw"]) {
        if ((await run("document.documentElement.lang")) !== locale) {
            await click(`[data-language="${locale}"]`);
            await wait(
                `document.documentElement.lang==='${locale}'&&document.querySelector('[name="shift_id"]')?.value==='${f.shift_id}'`,
            );
        }
        for (const theme of ["light", "dark"]) {
            await run(`window.NightshiftTheme.save('${theme}')`);
            for (const width of [390, 412, 768, 1440, 1920]) {
                await c("Emulation.setDeviceMetricsOverride", {
                    width,
                    height: 950,
                    deviceScaleFactor: 1,
                    mobile: width < 600,
                });
                const d = await run(
                    `(()=>{const f=document.getElementById('report-filters'),m=document.querySelector('.modal');return {doc:document.documentElement.scrollWidth,viewport:innerWidth,modal:m.scrollWidth,client:m.clientWidth,inputs:[...f.querySelectorAll('input,select')].filter(e=>!e.closest('.form-field').hidden).every(e=>{const r=e.getBoundingClientRect(),p=e.closest('.form-field').getBoundingClientRect();return r.width<=p.width+2}),shift:f.elements.shift_id.value}})()`,
                );
                check(
                    `${locale}/${theme}/${width}: filters fit and shift persists`,
                    d.doc <= width &&
                        d.modal <= d.client + 2 &&
                        d.inputs &&
                        d.shift === String(f.shift_id),
                    d,
                );
                await shot(`${locale}-${theme}-${width}`);
            }
        }
    }
    await c("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 950,
        deviceScaleFactor: 1,
        mobile: false,
    });
    const reports = [
        "Daily Sales",
        "Payments",
        "Payment Method Report",
        "Cashier Handover",
        "Cash Received from Cashiers",
        "Opening Stock",
        "Closing Stock",
        "Stock Movement",
        "Cashier Shift",
        "Business Unit Performance",
        "Sales by Product",
        "Sales by Category",
        "Profit Report",
        "Sales by Waiter",
        "Sales by Cashier",
        "Sales by Counter",
        "Paid Orders",
        "Unpaid Orders",
        "Returns",
        "Waiter Outstanding",
        "Waiter Settlement",
    ];
    for (const name of reports) {
        const result = await run(
            `fetch('/api/nightshift/reports/'+encodeURIComponent(${JSON.stringify(name)})+'?shift_id=${f.shift_id}&from=2001-01-01&to=2001-01-02',{headers:{Accept:'application/json'}}).then(async r=>({status:r.status,data:await r.json()}))`,
        );
        check(
            `${name}: selected shift accepted`,
            result.status === 200,
            result.status === 200 ? undefined : result,
        );
        if (name === "Daily Sales")
            check(
                "Closed sales reconcile 2000",
                result.data.rows.reduce((sum, row) => sum + Number(row[3]), 0) === 2000,
                result.data.rows,
            );
        if (name === "Payment Method Report")
            check(
                "Closed cashier collections reconcile 2000",
                Number(result.data.rows[0][1]) === 2000,
                result.data.rows,
            );
        if (name === "Cash Received from Cashiers")
            check(
                "Treasury source receipt reconciles 2000",
                result.data.rows.reduce((sum, row) => sum + Number(row[6]), 0) === 2000,
                result.data.rows,
            );
        if (name === "Opening Stock")
            check(
                "Opening snapshot quantity 10",
                result.data.rows.some((row) => Number(row[2]) === 10),
                result.data.rows,
            );
        if (name === "Closing Stock")
            check(
                "Closing snapshot quantity 7",
                result.data.rows.some((row) => Number(row[2]) === 7),
                result.data.rows,
            );
    }
    const csv = await run(
        `fetch('/api/nightshift/reports/Daily%20Sales/export?shift_id=${f.shift_id}&from=2001-01-01&to=2001-01-02').then(async r=>({status:r.status,body:await r.text(),filename:r.headers.get('Content-Disposition')}))`,
    );
    check(
        "CSV contains same two overnight invoices and shift filename",
        csv.status === 200 &&
            f.invoices.every((i) => csv.body.includes(i)) &&
            !csv.body.includes(f.other_invoice) &&
            csv.filename.includes(f.shift_number),
    );
    const pdf = await run(
        `fetch('/api/nightshift/reports/Daily%20Sales/pdf?shift_id=${f.shift_id}&from=2001-01-01&to=2001-01-02').then(async r=>({status:r.status,filename:r.headers.get('Content-Disposition'),bytes:Array.from(new Uint8Array(await r.arrayBuffer()))}))`,
    );
    check(
        "Real overnight PDF downloads with shift filename",
        pdf.status === 200 && pdf.filename.includes(f.shift_number),
        { status: pdf.status },
    );
    await fs.writeFile(output + "/overnight.pdf", Buffer.from(pdf.bytes));
    await set("shift_id", String(f.live_shift_id));
    await wait(
        `document.getElementById('report-content').innerText.includes(${JSON.stringify(f.live_invoice)})`,
    );
    check(
        "Live shift remains separate",
        await run(
            `!document.getElementById('report-content').innerText.includes(${JSON.stringify(f.invoices[0])})`,
        ),
    );
    await set("counter", "");
    check(
        "Counter change clears shift and restores dates",
        await run(
            `document.querySelector('[name="shift_id"]').value===''&&!document.querySelector('[name="from"]').disabled`,
        ),
    );
    await shot("live-cleared");
    const otherUnit = await run(
        `document.querySelector('[name="business_unit"] option[value="2"]')?.value`,
    );
    if (otherUnit) {
        await set("business_unit", otherUnit);
        check(
            "Business Unit limits counters",
            await run(
                `document.querySelector('[name="counter"] option[value="${f.counter_id}"]').disabled`,
            ),
        );
        await set("business_unit", String(f.unit_id));
    }
    await set("counter", String(f.counter_id));
    await wait(`!!document.querySelector('[name="shift_id"] option[value="${f.shift_id}"]')`);
    await set("shift_id", String(f.shift_id));
    await run("history.back()");
    await wait(`!document.getElementById('report-filters')`);
    await run("history.forward()");
    await wait(`document.querySelector('[name="shift_id"]')?.value==='${f.shift_id}'`);
    check("Browser back and forward restore shift filters", true);
    await navigate(
        "/treasury/report?name=Cash%20Received%20from%20Cashiers&shift_id=" + f.shift_id,
    );
    await wait(
        `document.querySelector('form[data-report-shifts] [name="shift_id"]')?.options.length>1`,
    );
    check(
        "Dedicated treasury source filter retains hierarchy",
        await run(
            `!!document.querySelector('[name="business_unit_id"]')&&!!document.querySelector('[name="stock_location_id"]')&&document.querySelector('[name="shift_id"]').value==='${f.shift_id}'`,
        ),
    );
    await navigate("/audit-trail?shift_id=" + f.shift_id + "&from=2001-01-01&to=2001-01-02");
    await wait(
        `document.querySelector('[data-workspace-page="audit"]')?.hidden===false&&document.querySelector('[data-shift-summary]')?.textContent.includes('${f.shift_number}')&&document.querySelector('[name="shift_id"]')?.value==='${f.shift_id}'`,
    );
    check(
        "Audit shift/reference filter ignores dates",
        await run(
            `document.querySelector('[name="shift_id"]').value==='${f.shift_id}'&&document.body.innerText.includes('${f.shift_number}')`,
        ),
    );
    check(
        "No browser exceptions or server errors",
        errors.length === 0 && httpErrors.length === 0,
        { errors, httpErrors },
    );
} catch (error) {
    console.error(error);
    process.exitCode = 1;
    await shot("failure").catch(() => {});
} finally {
    await fs.writeFile(
        output + "/results.json",
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
    ws.close();
}
