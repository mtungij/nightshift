import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-reconciliation-browser";
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
async function login(username, locale = "en") {
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("username", username);
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait("location.pathname==='/dashboard' && document.readyState==='complete' && !!document.querySelector('[name=csrf-token]')");
    await run(
        `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${locale}'})})`,
    );
}
async function command(action, payload) {
    return run(`fetch('/api/nightshift/commands/'+${JSON.stringify(action)},{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({...${JSON.stringify(payload)},idempotency_key:crypto.randomUUID()})}).then(async r=>({status:r.status,data:await r.json()}))`);
}
async function openOwn(username, counter, cashier = false) {
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("username", username);
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait("location.pathname==='/open-shift' && document.readyState==='complete' && !!document.querySelector('[name=stock_location_id]')");
    await set("stock_location_id", counter);
    if (cashier) await set("opening_cash", "500");
    await run(`document.querySelector('form[action$="/open-shift"]').requestSubmit()`);
    await wait("location.pathname==='/dashboard' && document.readyState==='complete' && !!document.querySelector('[name=csrf-token]')");
}
try {
    const safety = await fetch(new URL("/login", base));
    check(
        "Isolated MySQL target",
        ["nightshift_validation", "nightshift_browser_validation"].includes(safety.headers.get("X-Nightshift-Validation-Database")),
    );
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const fixtures = JSON.parse(
        await fs.readFile("/tmp/nightshift-reconciliation-fixtures.json", "utf8"),
    );
    for (const [kind, width, locale] of [
        ["desktop", 1440, "en"],
        ["mobile", 390, "sw"],
    ]) {
        const f = fixtures.cases[kind];
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        await login(f.cashier, locale);
        await navigate("/shift-stock/" + f.shift_id);
        await wait("!!document.querySelector('[data-recon-row]')");
        if (width === 390) await run(`window.NightshiftTheme.apply('dark')`);
        const initial = await report(f.shift_id),
            line = initial.rows.find((r) => r.product_id === f.product_id);
        check(
            kind + " correct frozen opening and unpaid issues",
            line.opening === "48.000000" &&
                line.system_sold === "6.000000" &&
                line.expected === "42.000000" &&
                initial.cash.waiter_outstanding === "18000.00" &&
                initial.cash.expected === "1000.00",
        );
        check(kind + " cashier cost remains hidden", !("cost" in initial.rows[0]));
        check(
            kind + " physical counts initially blank",
            await run(`[...document.querySelectorAll('.physical-count')].every(n=>n.value==='')`),
        );
        check(
            kind + " close disabled before count",
            await run(`document.querySelector('#recon-close-form button').disabled`),
        );
        check(
            kind + " page fits with scrollable stock sheet",
            await run(
                `document.documentElement.scrollWidth<=innerWidth+1 && document.querySelector('.table-scroll').scrollWidth>0`,
            ),
        );
        check(kind + " concurrent waiters and named close blockers", initial.blockers.length === 2 && initial.blockers.every(b => b.open));
        const blocked = await command("shift-close", {id:f.shift_id,actual_cash:"1000"});
        check(kind + " direct close blocked by waiters", blocked.status === 422);
        await login(fixtures.owner, locale);
        check(kind + " initial debt settled", (await command("waiter-payment", {waiter_id:f.waiter_id,stock_location_id:f.counter_id,amount:"18000",method:"Cash"})).status === 200);
        for (const id of [f.waiter_shift,f.together_shift]) check(kind + " waiter close " + id, (await command("shift-close",{id})).status === 200);
        await openOwn(f.rotation,f.counter_id);
        check(kind + " rotating waiter issues immediately", (await command("order-send", {waiter_id:f.rotation_id,pin:"0123",stock_location_id:f.counter_id,discount:"0",items:[{product_unit_id:f.unit_id,quantity:"1"}]})).status === 200);
        await login(fixtures.owner,locale);
        check(kind + " rotating waiter settles", (await command("waiter-payment",{waiter_id:f.rotation_id,stock_location_id:f.counter_id,amount:"3000",method:"Cash"})).status === 200);
        const operation = await report(f.shift_id);
        const rotation = operation.waiters.find(w=>w.name.includes('rotation'));
        check(kind + " rotating waiter belongs to same overnight shift", !!rotation && operation.operating_date === initial.operating_date && operation.total_sales === "21000.00");
        check(kind + " rotating waiter closes", (await command("shift-close",{id:rotation.shift_id})).status === 200);
        await login(f.cashier,locale);
        await navigate("/shift-stock/" + f.shift_id);
        await wait("!!document.querySelector('[data-recon-row]')");
        check(kind + " all waiters clean", (await report(f.shift_id)).blockers.length === 0);
        await set("actual_cash", width === 390 ? "21990" : "22000");
        await run(
            `document.querySelector('#recon-search').value='Serengeti';document.querySelector('#recon-search').dispatchEvent(new Event('input'))`,
        );
        check(
            kind + " product filter works",
            await run(
                `[...document.querySelectorAll('[data-recon-row]:not([hidden])')].length===1`,
            ),
        );
        await run(
            `document.querySelector('#recon-search').value='';document.querySelector('#recon-search').dispatchEvent(new Event('input'));for(const row of document.querySelectorAll('[data-recon-row]')){const n=row.querySelector('input');n.value=Number(row.dataset.expected)-(Number(row.dataset.product)===${f.product_id}&&${width === 390}?2:0);n.dispatchEvent(new Event('input',{bubbles:true}));}`,
        );
        if (width === 390) await set("reason", "Two bottles missing after physical recount");
        await shot(kind + "-physical-count");
        await run(
            `window.__beforeSave=true;document.querySelector('#recon-count-form').requestSubmit()`,
        );
        await wait(`window.__beforeSave===undefined && document.readyState==='complete'`);
        const saved = await report(f.shift_id);
        check(kind + " count saved and complete", saved.complete && saved.revision === 1);
        check(
            kind + " saved physical quantities have no artificial zeroes",
            await run(
                `[...document.querySelectorAll('.physical-count')].every(n=>n.value===String(Number(n.value)))`,
            ),
        );
        if (width === 390) {
            check(
                "mobile shortage and selling value",
                saved.totals.variance_value === "6000.00" &&
                    saved.rows.find((r) => r.product_id === f.product_id).status === "Short",
            );
            check(
                "cashier cannot finalize unapproved variance",
                await run(`document.querySelector('#recon-close-form button').disabled`),
            );
            check(
                "cashier approval forbidden",
                await run(
                    `fetch('/api/nightshift/commands/shift-stock-approve',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({id:${f.shift_id},basis:${JSON.stringify(saved.basis)},revision:1,note:'Unauthorized cashier approval',idempotency_key:crypto.randomUUID()})}).then(r=>r.status===403)`,
                ),
            );
            await login(fixtures.owner, "sw");
            await navigate("/shift-stock/" + f.shift_id);
            await set("note", "Manager verified and approved the missing bottles");
            await run(
                `window.__beforeApprove=true;document.querySelector('#recon-approve-form').requestSubmit()`,
            );
            await wait(`window.__beforeApprove===undefined && document.readyState==='complete'`);
            check("manager approval recorded", (await report(f.shift_id)).approved);
            await login(f.cashier, "sw");
            await navigate("/shift-stock/" + f.shift_id);
        }
        await set("actual_cash", width === 390 ? "21990" : "22000");
        await set("notes", "Closing count and cash checked in person");
        await run(
            `window.__beforeClose=true;document.querySelector('#recon-close-form').requestSubmit()`,
        );
        await wait(`window.__beforeClose===undefined && document.readyState==='complete'`);
        const final = await report(f.shift_id);
        check(
            kind + " finalized immutable view",
            final.status === "CLOSED" && !final.can_edit && !final.can_approve,
        );
        check(
            kind + " existing cash calculation unchanged",
            final.cash.difference === (width === 390 ? "-10.00" : "0.00"),
        );
        await shot(kind + "-final");
        await navigate("/shift-stock/" + f.shift_id + "/print");
        check(
            kind + " translated printable closing sheet",
            await run(
                `document.documentElement.lang==='${locale}' && document.body.innerText.includes('${locale === "sw" ? "Hesabu ya Stock ya Zamu" : "Stock Reconciliation"}')`,
            ),
        );
        check(
            kind + " print is white and black",
            await run(
                `getComputedStyle(document.body).backgroundColor==='rgb(255, 255, 255)' && getComputedStyle(document.body).color==='rgb(0, 0, 0)'`,
            ),
        );
        check(
            kind + " report contains original closing and outstanding values",
            await run(
                `document.body.innerText.includes('0.00') && document.body.innerText.includes('${width === 390 ? "6000.00" : "0.00"}')`,
            ),
        );
        const pdf = await c("Page.printToPDF", {
            preferCSSPageSize: true,
            printBackground: true,
            displayHeaderFooter: false,
        });
        await fs.writeFile(output + "/" + kind + ".pdf", Buffer.from(pdf.data, "base64"));
        check(kind + " PDF generated", pdf.data.length > 1000);
        await openOwn(f.handover,f.counter_id,true);
        const next = await run(`fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())`);
        check(kind + " next cashier starts after finalized handover", next.shift.active && next.shift.id !== f.shift_id);
        await login(fixtures.owner,locale);
        const afterHandover = await report(f.shift_id);
        for (const row of afterHandover.rows) { delete row.cost; delete row.cost_variance_value; }
        delete afterHandover.totals.cost_variance_value;
        check(kind + " report remains frozen after handover", JSON.stringify(afterHandover) === JSON.stringify(final));

    }
    check("No browser runtime exceptions", errors.length === 0, errors);
    check("No HTTP 5xx", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        output + "/result.json",
        JSON.stringify({ result: "PASS", checks, errors, httpErrors }, null, 2),
    );
} catch (error) {
    console.error(error);
    await shot("failure");
    await fs.writeFile(
        output + "/result.json",
        JSON.stringify(
            { result: "FAIL", error: String(error), checks, errors, httpErrors },
            null,
            2,
        ),
    );
    process.exitCode = 1;
} finally {
    process.exit(process.exitCode || 0);
}
