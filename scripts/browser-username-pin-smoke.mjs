import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-username-pin-browser";
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
    const fixtures = JSON.parse(
        await fs.readFile(
            process.env.PIN_FIXTURE_FILE || "/tmp/username-pin-browser-fixtures.json",
            "utf8",
        ),
    );
    const resume = process.env.PIN_SMOKE_RESUME === "1";
    if (resume) {
        const previous = JSON.parse(await fs.readFile(`${output}/result.json`, "utf8"));
        const roleChecks = previous.checks.filter(
            (check) => /^(1440|390)\//.test(check.name) && check.result === "PASS",
        );
        if (
            roleChecks.length !== Object.keys(fixtures.accounts).length * 18 ||
            previous.errors.length ||
            previous.httpErrors.length
        )
            throw Error("Role matrix must pass before resuming");
        checks.push(...roleChecks);
    }
    const navigate = async (path) => {
        const destination = new URL(path, base);
        destination.searchParams.set("pin_smoke", String(Date.now()));
        await run("window.__previousPinDocument=true");
        await c("Page.navigate", { url: destination.href });
        await wait('window.__previousPinDocument===undefined && document.readyState=== "complete"');
    };
    const login = async (username, pin = "0123", path = "/login") => {
        await c("Network.clearBrowserCookies");
        await navigate(path);
        await set("username", username);
        await set("pin", pin);
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait('!!document.querySelector("#main h1")');
    };
    const landing = {
        owner: "dashboard",
        manager: "dashboard",
        supervisor: "dashboard",
        superadmin: "dashboard",
        storekeeper: "inventory",
        cashier: "waiters",
        waiter: "pos",
    };
    for (const width of resume ? [] : [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        for (const [slug, account] of Object.entries(fixtures.accounts)) {
            await c("Network.clearBrowserCookies");
            await navigate("/login");
            check(
                `${width}/${slug}: only username and PIN`,
                await run(
                    `Array.from(document.querySelectorAll('form[action$="/login"] input:not([type="hidden"])')).map(e=>e.name).join(',')==='username,pin'`,
                ),
            );
            check(
                `${width}/${slug}: PIN is masked and numeric`,
                await run(
                    `document.querySelector('[name="pin"]').type==='password' && document.querySelector('[name="pin"]').inputMode==='numeric'`,
                ),
            );
            await set("pin", "123");
            check(
                `${width}/${slug}: malformed PIN blocked`,
                await run(`!document.querySelector('[name="pin"]').checkValidity()`),
            );
            await set("username", account.username);
            await set("pin", "0123");
            check(
                `${width}/${slug}: leading zero accepted`,
                await run(`document.querySelector('[name="pin"]').checkValidity()`),
            );
            check(
                `${width}/${slug}: login fits viewport`,
                await run("document.documentElement.scrollWidth<=innerWidth+1"),
            );
            if (slug === "owner") await shot(`login-${width}`);
            await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
            await wait('!!document.querySelector("#main h1")');
            const data = await snap();
            check(`${width}/${slug}: correct role`, data.role === account.role);
            check(
                `${width}/${slug}: original role landing page`,
                await run(
                    `document.querySelector('.nav-item.active[data-nav]').dataset.nav===${JSON.stringify(landing[slug])}`,
                ),
            );
            check(
                `${width}/${slug}: secret-free workspace`,
                !JSON.stringify(data).includes("operational_pin") &&
                    !("pin" in data.user) &&
                    !("password" in data.user),
            );
            await navigate("/quick-lock");
            await wait(`!!document.querySelector('form[action$="/quick-lock/unlock"]')`);
            await set("pin", "0123");
            await run(
                `document.querySelector('form[action$="/quick-lock/unlock"]').requestSubmit()`,
            );
            await wait('!!document.querySelector("#main h1")');
            check(
                `${width}/${slug}: same PIN unlocks Quick Lock`,
                (await snap()).user.id === account.id,
            );
        }
    }
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        await c("Network.clearBrowserCookies");
        await navigate("/login");
        await set("username", fixtures.accounts.owner.username);
        await set("pin", "0123");
        check(
            `${width}: submitted PIN is captured then cleared from browser fields`,
            await run(
                `(() => { const form = document.querySelector('form[action$="/login"]'); const captured = new FormData(form); return captured.get('pin') === '0123' && form.querySelector('[name="pin"]').value === ''; })()`,
            ),
        );
        await set("pin", "0123");
        await run(`window.dispatchEvent(new Event('pagehide'))`);
        check(
            `${width}: page cache cannot retain PIN`,
            await run(`document.querySelector('[name="pin"]').value === ''`),
        );
    }
    // Admin reset, login and POS confirmation all use one credential.
    await login(fixtures.accounts.owner.username);
    const reset = await api("staff-pin", { id: fixtures.accounts.waiter.id, pin: "0987" });
    check(
        "Admin resets waiter PIN",
        reset.status === 200 && !JSON.stringify(reset.body).includes("0987"),
    );
    await login(fixtures.accounts.waiter.username, "0987");
    let data = await snap();
    const existingShift = data.shifts.find(
        (s) =>
            s.user_id === data.user.id &&
            s.type === "WAITER" &&
            s.status === "OPEN" &&
            s.stock_location_id === data.location_id,
    );
    const opened = existingShift
        ? { status: 200 }
        : await api("shift-open", {
              user_id: data.user.id,
              stock_location_id: data.location_id,
              type: "WAITER",
              opening_cash: "0",
          });
    check("Waiter has an open shift after PIN login", opened.status === 200);
    await navigate("/dashboard#pos");
    await wait('!!document.querySelector(".pos-cart")');
    data = await snap();
    const p = data.products.find((p) => p.sku === "BR-001");
    await click(`[data-action="add-cart"][data-id="${p.id}"]`);
    await click('[data-action="send-order"]');
    await click(`[data-action="serving-waiter"][data-id="${data.user.id}"]`);
    await set("pin", "0987");
    await submit("serving-order");
    const committed = await snap();
    check(
        "Login PIN confirms POS waiter order",
        committed.orders.some((o) => o.waiter === data.user.id && o.status === "Issued"),
    );
    check("POS PIN cleared after send", await run(`!document.querySelector('[name="pin"]')`));
    await navigate("/quick-lock");
    await set("pin", "0987");
    await run(`document.querySelector('form[action$="/quick-lock/unlock"]').requestSubmit()`);
    await wait('!!document.querySelector("#main h1")');
    check(
        "Reset PIN also unlocks Quick Lock",
        (await snap()).user.id === fixtures.accounts.waiter.id,
    );
    await c("Network.clearBrowserCookies");
    await navigate(`/companies/${fixtures.foreign.company_id}/login`);
    await set("username", fixtures.accounts.owner.username);
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait(
        `!!document.querySelector('[name="username"]') && /credentials do not match|PIN could not be confirmed/i.test(document.body.innerText)`,
    );
    check(
        "Company A cannot log into company B context",
        await run(
            `fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.status===401)`,
        ),
    );
    check(
        "Rejected login never flashes PIN",
        await run(`document.querySelector('[name="pin"]').value===''`),
    );
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("username", fixtures.inactive);
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait(
        `!!document.querySelector('[name="username"]') && /credentials do not match|PIN could not be confirmed/i.test(document.body.innerText)`,
    );
    check(
        "Inactive account cannot log in",
        await run(
            `fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.status===401)`,
        ),
    );
    await login(fixtures.accounts.owner.username);
    await click('[data-nav="staff"]');
    await click(`[data-action="staff-pin"][data-id="${fixtures.accounts.owner.id}"]`);
    check(
        "Owner can reset own login PIN from staff page",
        await run(`!!document.querySelector('[name="pin"]')`),
    );
    await click('.modal-backdrop [data-action="close-modal"]');
    // Failed PINs persist an account lock and subsequent login is throttled.
    await c("Network.clearBrowserCookies");
    const username = fixtures.lockout;
    for (let i = 0; i < 5; i++) {
        await navigate("/login");
        await set("username", username);
        await set("pin", "9999");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait(
            `!!document.querySelector('[name="username"]') && /credentials do not match|PIN could not be confirmed/i.test(document.body.innerText)`,
        );
    }
    await navigate("/login");
    await set("username", username);
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait(
        `document.body.innerText.includes('429') || document.body.innerText.includes('Too Many')`,
    );
    check(
        "Repeated failures lock/rate limit login",
        await run(
            `fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.status===401)`,
        ),
    );
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
            { result: "FAIL", base, checks, errors, httpErrors, failure: String(error) },
            null,
            2,
        ),
    );
    console.error(String(error));
    process.exitCode = 1;
} finally {
    ws.close();
}
