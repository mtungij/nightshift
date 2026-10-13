import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-shift-routing-browser";
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
    await sleep(350);
    const r = await c("Page.captureScreenshot", { format: "png", captureBeyondViewport: false });
    await fs.writeFile(`${output}/${name}.png`, Buffer.from(r.data, "base64"));
}
try {
    await c("Runtime.enable");
    await c("Page.enable");
    await c("Network.enable");
    const f = JSON.parse(
        await fs.readFile(
            process.env.SHIFT_FIXTURE_FILE || "/tmp/shift-routing-browser-fixtures.json",
            "utf8",
        ),
    );
    async function navigate(path) {
        const url = new URL(path, base);
        url.searchParams.set("shift_smoke", String(Date.now()));
        await run("window.__oldShiftDocument=true");
        await c("Page.navigate", { url: url.href });
        await wait('window.__oldShiftDocument===undefined && document.readyState==="complete"');
    }
    async function login(slug) {
        await c("Network.clearBrowserCookies");
        await navigate("/login");
        await set("username", f.accounts[slug].username);
        await set("pin", "0123");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait('!!document.querySelector("[data-open-shift],#main h1")');
    }
    const openScreen = () => wait('!!document.querySelector("[data-open-shift]")');
    const workScreen = () => wait('!!document.querySelector("#main h1")');
    const status = (path) =>
        run(
            `fetch(${JSON.stringify(path)},{headers:{Accept:'application/json'}}).then(r=>r.status)`,
        );
    async function open(counter, cash = null) {
        await set("stock_location_id", counter);
        if (cash !== null) await set("opening_cash", cash);
        await run(`document.querySelector('form[action$="/open-shift"]').requestSubmit()`);
        await workScreen();
    }
    async function closeCashier() {
        await run('location.hash="shifts"');
        await sleep(250);
        const d = await snap();
        await click('[data-action="close-shift"]');
        await set("actual", Number(d.shift.opening) + Number(d.shift.cashSales));
        await run(`document.querySelector('[data-form="close-shift"]').requestSubmit()`);
        await openScreen();
    }
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        const p = width + "/";
        await login("waiter");
        await openScreen();
        check(
            p + "Waiter without shift lands on Open Shift",
            await run('location.pathname==="/open-shift"'),
        );
        check(
            p + "Clear shift instruction and no opening cash for waiter",
            await run(
                `document.body.innerText.includes('Open your shift to start working.') && !document.querySelector('[name="opening_cash"]')`,
            ),
        );
        check(
            p + "Only permitted counters offered",
            await run(
                `!document.querySelector('option[value="${f.outside}"]') && !!document.querySelector('option[value="${f.main}"]')`,
            ),
        );
        check(
            p + "No-shift bootstrap blocked",
            (await status("/api/nightshift/bootstrap")) === 409,
        );
        await navigate("/dashboard#pos");
        await openScreen();
        check(
            p + "Manual POS URL cannot bypass shift gate",
            await run('location.pathname==="/open-shift"'),
        );
        await shot(`${width}-waiter-open-shift`);
        await open(f.main);
        check(
            p + "Opening waiter shift lands in POS",
            await run(
                `location.hash==='#pos' && document.querySelector('.nav-item.active[data-nav]').dataset.nav==='pos'`,
            ),
        );
        let d = await snap();
        check(
            p + "Only one own waiter shift opened",
            d.shifts.filter((s) => s.user_id === f.accounts.waiter.id && s.status === "OPEN")
                .length === 1 && d.shift.type === "WAITER",
        );
        await login("waiter");
        await workScreen();
        check(
            p + "Waiter with open shift lands directly in POS",
            await run("location.hash==='#pos'"),
        );
        const duplicate = await api("shift-open", {
            user_id: f.accounts.waiter.id,
            type: "WAITER",
            stock_location_id: f.main,
            opening_cash: "0",
        });
        check(p + "Duplicate waiter shift rejected", duplicate.status === 422);
        await run('location.hash="shifts"');
        await sleep(250);
        await click('[data-action="close-waiter"]');
        await click('[data-action="confirm-close-waiter"]');
        await openScreen();
        check(
            p + "Waiter close sends user to Open Shift",
            await run('location.pathname==="/open-shift"'),
        );
        await run("history.back()");
        await sleep(750);
        await openScreen();
        check(
            p + "Browser back cannot restore closed waiter workspace",
            (await run('location.pathname==="/open-shift"')) &&
                (await status("/api/nightshift/bootstrap")) === 409,
        );
        await navigate("/dashboard#pos");
        await openScreen();
        check(
            p + "Closed waiter manual URL redirected",
            await run('location.pathname==="/open-shift"'),
        );
        await login("cashier");
        await openScreen();
        check(
            p + "Cashier without shift lands on Open Shift",
            await run('location.pathname==="/open-shift"'),
        );
        check(
            p + "Cashier must enter opening cash",
            await run(
                `document.querySelector('[name="opening_cash"]').required && document.querySelector('[name="opening_cash"]').value==='' && !document.querySelector('form[action$="/open-shift"]').checkValidity()`,
            ),
        );
        const forbidden = await api("shift-open", {
            type: "CASHIER",
            stock_location_id: f.outside,
            opening_cash: "0",
        });
        check(p + "Unassigned counter rejected by backend", forbidden.status === 404);
        await navigate("/dashboard#waiters");
        await openScreen();
        check(
            p + "Manual cashier URL cannot bypass shift gate",
            await run('location.pathname==="/open-shift"'),
        );
        await shot(`${width}-cashier-open-shift`);
        await open(f.vip, "40000");
        check(
            p + "Opening cashier shift lands in cashier workspace",
            await run(
                `location.hash==='#waiters' && document.querySelector('.nav-item.active[data-nav]').dataset.nav==='waiters'`,
            ),
        );
        d = await snap();
        check(
            p + "Cashier uses selected shift counter and opening cash",
            d.location_id === f.vip &&
                d.locations.length === 1 &&
                d.locations[0].id === f.vip &&
                d.shift.opening === "40000.00",
        );
        check(
            p + "Other counter data remains forbidden",
            (await status(`/api/nightshift/bootstrap?stock_location_id=${f.main}`)) === 403,
        );
        await login("cashier");
        await workScreen();
        check(
            p + "Cashier with open shift skips Open Shift",
            (await run("location.hash==='#waiters'")) && (await snap()).location_id === f.vip,
        );
        const dupe = await api("shift-open", {
            user_id: f.accounts.cashier.id,
            type: "CASHIER",
            stock_location_id: f.main,
            opening_cash: "999",
        });
        check(p + "Duplicate cashier shift rejected", dupe.status === 422);
        await navigate("/open-shift");
        await workScreen();
        check(
            p + "Open Shift URL reuses existing cashier shift",
            (await snap()).shifts.filter((s) => s.status === "OPEN").length === 1,
        );
        await closeCashier();
        check(
            p + "Cashier close sends user to Open Shift",
            (await run('location.pathname==="/open-shift"')) &&
                (await status("/api/nightshift/bootstrap")) === 409,
        );
        await navigate("/dashboard#waiters");
        await openScreen();
        check(
            p + "Closed cashier manual URL redirected",
            await run('location.pathname==="/open-shift"'),
        );
        await open(f.main, "500");
        check(
            p + "Next shift can select another permitted counter",
            (await snap()).location_id === f.main &&
                (await status(`/api/nightshift/bootstrap?stock_location_id=${f.vip}`)) === 403,
        );
        await closeCashier();
        check(
            p + "Open Shift fits mobile and desktop viewport",
            await run("document.documentElement.scrollWidth<=innerWidth+1"),
        );
        for (const slug of ["manager", "owner"]) {
            await login(slug);
            await workScreen();
            check(
                p + slug + " routing remains dashboard",
                await run(
                    "location.pathname==='/dashboard' && document.querySelector('.nav-item.active[data-nav]').dataset.nav==='dashboard'",
                ),
            );
        }
    }
    check("No browser exceptions", errors.length === 0, errors);
    check("No HTTP 5xx", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify({ result: "PASS", checks, errors, httpErrors }, null, 2),
    );
} catch (error) {
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
}
