import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-counter-binding-browser";
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
        throw Error("Use isolated database.");
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const f = JSON.parse(
        await fs.readFile("/tmp/nightshift-counter-binding-fixtures.json", "utf8"),
    );
    await login(f.owner, "/dashboard", "sw");
    await navigate("/dashboard#counters");
    await wait('!!document.querySelector("[data-counter-card]")');
    const id = await run(
        `fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json()).then(d=>d.locations.find(l=>l.name==='Main Counter').id)`,
    );
    check(
        "Sw full state uses database occupancy and complete default",
        await run(
            `(()=>{const e=document.querySelector('[data-counter-card="${id}"] [data-cashier-limit]');return e.elements.max_active_cashiers.value==='1' && e.querySelector('[data-counter-occupancy]').textContent==='Makashia Wanaofanya Kazi: 1 / 1' && !e.querySelector('[data-counter-full]').hidden && e.querySelector('[data-counter-full]').textContent==='Idadi ya makashia imefikia kiwango cha juu'})()`,
        ),
    );
    for (const theme of ["light", "dark"]) {
        await run("window.NightshiftTheme.apply(" + JSON.stringify(theme) + ")");
        for (const width of [390, 412]) {
            await c("Emulation.setDeviceMetricsOverride", {
                width,
                height: 915,
                deviceScaleFactor: 1,
                mobile: true,
            });
            await sleep(100);
            const b = await run(
                `(()=>{const e=document.querySelector('[data-counter-card="${id}"] [data-counter-full]'),r=e.getBoundingClientRect();return {overflow:document.documentElement.scrollWidth>innerWidth,left:r.left,right:r.right}})()`,
            );
            check(
                "SW full badge " + theme + " " + width + " fits",
                !b.overflow && b.left >= 0 && b.right <= width + 1,
                b,
            );
            await run(
                `document.querySelector('[data-counter-card="${id}"]').scrollIntoView({block:'center'})`,
            );
            await shot("full-sw-" + theme + "-" + width);
        }
    }

    for (const [width, max, theme] of [
        [390, 2, "light"],
        [412, 1, "dark"],
    ]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 915,
            deviceScaleFactor: 1,
            mobile: true,
        });
        await run("window.NightshiftTheme.apply(" + JSON.stringify(theme) + ")");
        const selector = `[data-counter-card="${f.counter_id}"] [data-cashier-limit]`;
        await scopedSet(selector, "max_active_cashiers", max);
        await run(
            `document.querySelector(${JSON.stringify(selector)}+' [type=submit]').scrollIntoView({block:'center'})`,
        );
        await sleep(150);
        const target = await run(
            `(()=>{const b=document.querySelector(${JSON.stringify(selector)}+' [type=submit]'),r=b.getBoundingClientRect(),x=r.left+r.width/2,y=r.top+r.height/2;return {x,y,reachable:r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight&&document.elementFromPoint(x,y)?.closest('button')===b}})()`,
        );
        check(width + "px Save is physically reachable", target.reachable, target);
        await c("Input.dispatchMouseEvent", {
            type: "mousePressed",
            x: target.x,
            y: target.y,
            button: "left",
            clickCount: 1,
        });
        await c("Input.dispatchMouseEvent", {
            type: "mouseReleased",
            x: target.x,
            y: target.y,
            button: "left",
            clickCount: 1,
        });
        await wait(
            `document.querySelector(${JSON.stringify(selector)}).dataset.savedLimit===${JSON.stringify(String(max))} && !!document.querySelector(${JSON.stringify(selector)}+' [data-cashier-limit-status]').textContent`,
        );
        check(
            width + "px actual pointer Save updates maximum and feedback",
            await run(
                `(()=>{const f=document.querySelector(${JSON.stringify(selector)});return f.elements.max_active_cashiers.value===${JSON.stringify(String(max))}&&f.querySelector('[data-counter-occupancy]').textContent.endsWith(${JSON.stringify("0 / " + max)})&&!f.querySelector('[data-cashier-limit-status]').classList.contains('is-error')})()`,
            ),
        );
    }
    check("No runtime errors", errors.length === 0, errors);
    check("No server errors", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        output + "/full-state-result.json",
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
} finally {
    ws.close();
}
