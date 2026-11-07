import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-terminal-browser";
const paperWidth = process.env.SMOKE_PAPER_WIDTH || "80mm";
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
const snap = () =>
    run(
        "fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())",
    );
const requests = [];
ws.addEventListener("message", (e) => {
    const m = JSON.parse(e.data);
    if (m.method === "Network.requestWillBeSent") requests.push(m.params.request.url);
});
try {
    const safety = await fetch(new URL("/login", base));
    check(
        "Isolated MySQL target",
        safety.headers.get("X-Nightshift-Validation-Database") === "nightshift_validation",
    );
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    await c("Page.addScriptToEvaluateOnNewDocument", {
        source: "window.__printCalls=0;window.print=()=>window.__printCalls++;",
    });
    const accounts = JSON.parse(
        await fs.readFile("/tmp/nightshift-shared-pos-fixtures.json", "utf8"),
    );
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("username", accounts.admin.username);
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait("location.pathname==='/dashboard'");
    await navigate("/dashboard#staff");
    await wait("!!document.querySelector('[data-action=add-user]')");
    await click("[data-action=add-user]");
    await set("role", "POS Terminal");
    check(
        "Owner terminal creation exposes counter assignment",
        await run(`!!document.querySelector('[name=terminal_counter]')`),
    );
    const terminalName = "Browser Terminal " + Date.now(),
        terminalUsername = "pos-browser-" + Date.now();
    await set("name", terminalName);
    await set("username", terminalUsername);
    await set("email", terminalUsername + "@example.test");
    await set("pin", "2468");
    await run(`document.querySelector('[data-form=add-user]').requestSubmit()`);
    await wait("!document.querySelector('[data-form=add-user]')");
    check(
        "Owner can create terminal in Staff",
        (await snap()).team.some(
            (u) => u.username === terminalUsername && u.role === "POS Terminal",
        ),
    );
    await run(
        `fetch('/logout',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content}})`,
    );
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        await c("Network.clearBrowserCookies");
        await navigate("/login");
        await set("username", accounts.terminal.username);
        await set("pin", "4567");
        await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
        await wait(
            "location.pathname==='/dashboard' && location.hash==='#pos' && !!document.querySelector('.pos-cart')",
        );
        check(
            width + " landing and restricted navigation",
            await run(
                `Array.from(document.querySelectorAll('.nav-scroll [data-nav]')).map(e=>e.dataset.nav).join(',')==='pos,orders'`,
            ),
        );
        const initial = await snap();
        check(
            width + " one assigned counter",
            initial.locations.length === 1 &&
                initial.branches.length === 1 &&
                initial.role === "POS Terminal",
        );
        for (const endpoint of [
            "/settings/business",
            "/settings/whatsapp",
            "/settings/profile",
            "/open-shift",
            "/api/nightshift/reports/Daily%20Sales",
        ]) {
            check(
                width + " denies " + endpoint,
                (await run(
                    `fetch(${JSON.stringify(endpoint)},{headers:{Accept:'application/json'}}).then(r=>r.status)`,
                )) === 403,
            );
        }
        for (const [locale, person, pin] of [
            ["en", "asha", "0123"],
            ["sw", "james", "6789"],
        ]) {
            await run(
                `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${locale}'})})`,
            );
            await navigate("/dashboard#pos");
            await wait("!!document.querySelector('.pos-cart')");
            if (locale === "sw")
                check(
                    width + " terminal role localized",
                    await run("document.body.textContent.includes('Kituo cha POS')"),
                );
            const before = await snap(),
                product = before.products.find((p) => p.sku === "BR-001");
            await click(`[data-action=add-cart][data-id="${product.id}"]`);
            const note = locale === "sw" ? "Mteja karibu na TV" : "Customer near the TV";
            await set("order_note", note);
            await click("[data-action=send-order]");
            await set("waiter_id", accounts[person].id);
            await set("pin", person === "asha" ? "6789" : "0123");
            await run(`document.querySelector('[data-form=serving-order]').requestSubmit()`);
            await wait(
                `!document.querySelector('[data-form=serving-order] [type=submit]').disabled && document.querySelector('[name=pin]').value===''`,
            );
            const rejected = await snap();
            check(
                width + " " + person + " wrong PIN has no stock/order effects",
                rejected.orders.length === before.orders.length &&
                    rejected.products.find((p) => p.id === product.id).stock === product.stock,
            );
            await set("pin", pin);
            await run(`document.querySelector('[data-form=serving-order]').requestSubmit()`);
            await wait(
                "document.querySelector('#issue-ticket-frame')?.contentWindow?.__printCalls===1",
            );
            const after = await snap(),
                order = after.orders.find((o) => !before.orders.some((b) => b.id === o.id));
            check(
                width + " " + person + " correct attribution and session retained",
                order?.waiter === accounts[person].id &&
                    after.user.id === accounts.terminal.id &&
                    order.order_note === note,
            );
            check(
                width + " " + person + " two copies and note",
                await run(
                    `(()=>{const d=document.querySelector('#issue-ticket-frame').contentDocument;return d.querySelectorAll('.ticket').length===2 && [...d.querySelectorAll('.order-note')].every(e=>e.textContent.includes(${JSON.stringify(note)})) && d.body.textContent.includes('Main Counter POS')})()`,
                ),
            );
            check(
                width + " " + person + " stock issued once",
                Number(after.products.find((p) => p.id === product.id).stock) ===
                    Number(product.stock) - 1,
            );
            check(
                width + " " + person + " no mobile overflow",
                await run("document.documentElement.scrollWidth<=innerWidth+1"),
            );
            await shot(width + "-" + locale + "-terminal");
            const printUrl = await run(
                "document.querySelector('#issue-ticket-frame').getAttribute('src')",
            );
            await navigate(printUrl.replace("?print=1", ""));
            await wait("document.querySelectorAll('.ticket').length===2");
            if (locale === "sw")
                check(
                    width + " terminal print attribution localized",
                    await run("document.body.textContent.includes('Imeingizwa kupitia')"),
                );
            check(
                width + " " + locale + " native paper width",
                await run(`document.body.dataset.paperWidth===${JSON.stringify(paperWidth)}`),
            );
            const pdf = await c("Page.printToPDF", {
                printBackground: true,
                preferCSSPageSize: true,
            });
            await fs.writeFile(
                `${output}/${width}-${locale}-${paperWidth}.pdf`,
                Buffer.from(pdf.data, "base64"),
            );
        }
        await navigate("/dashboard#pos");
        await wait("!!document.querySelector('.pos-cart')");
        await click("[data-action=profile]");
        check(
            width + " terminal account exposes logout only",
            await run(
                `!document.querySelector('.modal a[href="/settings/profile"]') && !!document.querySelector('.modal form[action="/logout"]')`,
            ),
        );
        await run(`document.querySelector('.modal form[action="/logout"]').requestSubmit()`);
        await wait("location.pathname==='/' || location.pathname==='/login'");
        await navigate("/dashboard");
        check(width + " manual logout", await run("location.pathname==='/login'"));
    }
    check("No browser runtime errors", errors.length === 0, errors);
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
