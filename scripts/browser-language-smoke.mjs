import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-language-browser";
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
    await c("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    const navigate = async (url) => {
        await run("window.__previousLanguageDocument=true");
        await c("Page.navigate", { url });
        await wait(
            'window.__previousLanguageDocument===undefined && document.readyState==="complete"',
        );
    };
    const login = async (email) => {
        await c("Network.clearBrowserCookies");
        await navigate(`${base}/login`);
        await set("email", email);
        await set("password", "Nightshift!2026");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait('!!document.querySelector("#main h1")');
    };
    const language = async (locale) => {
        await click(`[data-language="${locale}"]`);
        await wait(
            `document.documentElement.lang==="${locale}" && document.querySelector('[data-language="${locale}"]').getAttribute('aria-pressed')==='true'`,
        );
    };
    await c("Network.clearBrowserCookies");
    await navigate(`${base}/login`);
    check(
        "Guest starts in English and Light Mode",
        await run(
            'document.documentElement.lang==="en" && document.documentElement.dataset.theme==="light"',
        ),
    );
    await run(`document.querySelector('.language-switch input[value="sw"]').form.requestSubmit()`);
    await wait('document.documentElement.lang==="sw"');
    check(
        "Guest Kiswahili login rendered",
        await run('document.body.innerText.includes("Ingia kwenye akaunti yako")'),
    );
    await navigate(`${base}/register-business`);
    check(
        "Guest onboarding retains Kiswahili",
        await run('document.body.innerText.includes("Sajili akaunti ya baa yako")'),
    );
    await login("admin@nightshift.test");
    const identity = await run(
        `fetch('/api/nightshift/bootstrap').then(r=>r.headers.get('X-Nightshift-Validation-Database'))`,
    );
    check(
        "Uses dedicated validation database",
        identity === (process.env.SMOKE_DATABASE || "nightshift_validation"),
        identity,
    );
    await language("en");
    check(
        "Local Poppins loaded at operational weights",
        await run(
            'document.fonts.ready.then(()=>[500,600,700].every(w=>document.fonts.check(`${w} 15px Poppins`)) && getComputedStyle(document.body).fontFamily.includes("Poppins"))',
        ),
    );
    const fixture = await snap();
    const servingWaiter = fixture.servingWaiters[0];
    check("Waiter shift available for translated PIN checks", !!servingWaiter);
    const configured = await api("staff-pin", { id: servingWaiter.id, pin: "0123" });
    check("Validation waiter PIN configured", configured.status === 200);
    await navigate(`${base}/dashboard`);
    await wait('!!document.querySelector("#main h1")');
    const views = [
        "dashboard",
        "pos",
        "orders",
        "waiters",
        "cashiers",
        "tables",
        "inventory",
        "shifts",
        "payments",
        "suppliers",
        "reports",
        "approvals",
        "users",
        "branches",
        "counters",
        "settings",
        "setup",
        "audit",
        "notifications",
    ];
    const copy = [];
    for (const locale of ["en", "sw"]) {
        await language(locale);
        for (const theme of ["light", "dark"]) {
            await run(`window.NightshiftTheme.save('${theme}')`);
            for (const width of [1440, 768, 390, 320]) {
                await c("Emulation.setDeviceMetricsOverride", {
                    width,
                    height: 1000,
                    deviceScaleFactor: 1,
                    mobile: width < 720,
                });
                for (const view of views) {
                    await click(`[data-nav="${view}"]`);
                    await sleep(60);
                    const fit = await run(
                        `(()=>{const h=document.querySelector('#main h1');return {heading:h?.innerText,locale:document.documentElement.lang,overflow:document.documentElement.scrollWidth>${width},badButtons:[...document.querySelectorAll('.button')].filter(b=>b.getClientRects().length && b.scrollWidth>b.clientWidth+2).map(b=>b.innerText)}})()`,
                    );
                    check(
                        `${locale} ${theme} ${width}px ${view}`,
                        !!fit.heading &&
                            fit.locale === locale &&
                            !fit.overflow &&
                            fit.badButtons.length === 0,
                        fit,
                    );
                    if (locale === "sw" && theme === "light" && width === 1440)
                        copy.push({
                            view,
                            text: await run('document.querySelector("#main").innerText'),
                        });
                    if (
                        ["pos", "waiters", "reports", "shifts"].includes(view) &&
                        [1440, 390].includes(width)
                    )
                        await shot(`${locale}-${theme}-${width}-${view}`);
                }
            }
            await c("Emulation.setDeviceMetricsOverride", {
                width: 390,
                height: 1000,
                deviceScaleFactor: 1,
                mobile: true,
            });
            await click('[data-nav="pos"]');
            check(
                `${locale} ${theme} POS send label`,
                await run(
                    `document.querySelector('[data-action="send-order"]').innerText.includes(${JSON.stringify(locale === "sw" ? "Tuma kwa Keshia" : "Send to cashier")})`,
                ),
            );
            await click('[data-action="add-cart"]');
            await click('[data-action="send-order"]');
            check(
                `${locale} ${theme} serving PIN modal language and layout`,
                await run(
                    `(()=>{const m=document.querySelector('.modal'),r=m.getBoundingClientRect();return document.querySelector('#modal-title').innerText===${JSON.stringify(locale === "sw" ? "Nani anahudumia oda hii?" : "Who is serving this order?")} && r.right<=390 && r.left>=0 && m.scrollWidth<=m.clientWidth+2})()`,
                ),
            );
            await shot(`${locale}-${theme}-390-pin-modal`);
            if (locale === "sw") {
                await click(`[data-action="serving-waiter"][data-id="${servingWaiter.id}"]`);
                await set("pin", "9999");
                await run(`document.querySelector('[data-form="serving-order"]').requestSubmit()`);
                await wait(
                    'document.querySelector("[data-pin-error]")?.innerText.includes("PIN si sahihi")',
                );
                check(
                    `Kiswahili ${theme} invalid PIN readable and cleared`,
                    await run(
                        'document.querySelector("[name=pin]").value==="" && document.querySelector("[data-pin-error]").innerText.includes("PIN si sahihi")',
                    ),
                );
            }
            await click('[data-action="close-modal"]');
            // Switching locale in the workspace preserves the unsent cart.
            const before = await run('document.querySelector(".cart-body").innerText');
            await language(locale === "en" ? "sw" : "en");
            check(
                `${locale} switch preserves cart`,
                await run('!!document.querySelector(".cart-item")'),
            );
            await language(locale);
            await run(`document.querySelector('[data-action="clear-cart"]').click()`);
            if (await run('!!document.querySelector(".modal")'))
                await click('.modal [data-action="confirm-clear-cart"]');
        }
    }
    await fs.writeFile(`${output}/kiswahili-screen-copy.json`, JSON.stringify(copy, null, 2));
    await c("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await language("sw");
    await run('window.NightshiftTheme.save("dark")');
    await navigate(`${base}/dashboard`);
    await wait('!!document.querySelector("#main h1")');
    check(
        "SW and Dark survive refresh",
        await run(
            'document.documentElement.lang==="sw" && document.documentElement.dataset.theme==="dark"',
        ),
    );
    await login("admin@nightshift.test");
    check(
        "Saved SW and Dark survive new login",
        await run(
            'document.documentElement.lang==="sw" && document.documentElement.dataset.theme==="dark"',
        ),
    );
    await language("en");
    await run('window.NightshiftTheme.save("light")');
    for (const account of ["cashier", "asha"]) {
        await login(`${account}@nightshift.test`);
        await language("sw");
        const expected = account === "cashier" ? "Hesabu za Wahudumu" : "Mauzo";
        check(
            `${account} Kiswahili role landing`,
            await run(
                `document.querySelector('#main h1').innerText.includes(${JSON.stringify(expected)})`,
            ),
        );
        if (account === "cashier")
            check(
                "Cashier settlement labels localized",
                await run(
                    'document.body.innerText.includes("Malipo Yaliyopokelewa") && document.body.innerText.includes("Deni Lililobaki")',
                ),
            );
        await login(`${account}@nightshift.test`);
        check(
            `${account} language survives login`,
            await run('document.documentElement.lang==="sw"'),
        );
        await language("en");
    }
    await login("admin@nightshift.test");
    await language("sw");
    for (const locale of ["sw", "en"]) {
        await language(locale);
        await click('[data-nav="reports"]');
        await click('[data-action="report"][data-value="Daily Sales"]');
        await wait('!!document.querySelector("#report-content .data-table")');
        check(
            `${locale} report labels translated`,
            await run(
                `document.querySelector('#modal-title').innerText.includes(${JSON.stringify(locale === "sw" ? "Mauzo ya Siku" : "Daily Sales")})`,
            ),
        );
        await c("Emulation.setEmulatedMedia", { media: "print" });
        check(
            `${locale} print remains light`,
            await run('getComputedStyle(document.body).backgroundColor==="rgb(255, 255, 255)"'),
        );
        const pdf = await c("Page.printToPDF", { printBackground: true, preferCSSPageSize: true });
        await fs.writeFile(`${output}/${locale}-report.pdf`, Buffer.from(pdf.data, "base64"));
        await c("Emulation.setEmulatedMedia", { media: "screen" });
        await click('[data-action="close-modal"]');
        const order = (await snap()).orders.find((o) =>
            ["Issued", "Partially paid", "Paid"].includes(o.status),
        );
        check(`${locale} printable fixture exists`, !!order);
        for (const path of ["receipts", "issue-tickets"]) {
            await navigate(`${base}/api/nightshift/${path}/${order.id}`);
            check(
                `${locale} ${path} localized committed data`,
                await run(
                    `document.documentElement.lang==='${locale}' && document.body.innerText.includes('${locale === "sw" ? "Mhudumu" : "Waiter"}') && getComputedStyle(document.body).backgroundColor==='rgb(255, 255, 255)'`,
                ),
            );
            const paper = await c("Page.printToPDF", {
                printBackground: true,
                preferCSSPageSize: true,
                paperWidth: 80 / 25.4,
                paperHeight: 250 / 25.4,
                marginTop: 0,
                marginBottom: 0,
                marginLeft: 0,
                marginRight: 0,
            });
            await fs.writeFile(
                `${output}/${locale}-${path}-80mm.pdf`,
                Buffer.from(paper.data, "base64"),
            );
        }
        await navigate(`${base}/dashboard`);
        await wait('!!document.querySelector("#main h1")');
    }
    await language("en");
    await run('window.NightshiftTheme.save("light")');
    check("No browser exceptions", errors.length === 0, errors);
    check("No HTTP 5xx", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify({ result: "PASS", base, checks, errors, httpErrors }, null, 2),
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
