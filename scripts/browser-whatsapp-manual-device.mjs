import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-whatsapp-manual-browser";
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
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    const f = JSON.parse(
        await fs.readFile("/tmp/nightshift-whatsapp-schedules-fixtures.json", "utf8"),
    );
    await login(f.owner);
    for (const locale of ["en", "sw"]) {
        await run(
            `fetch('/settings/language',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:'${locale}'})})`,
        );
        await navigate("/settings/whatsapp#connection");
        await wait(`document.querySelector('[data-workspace-page="whatsapp"]')?.hidden===false`);
        await click('[data-whatsapp-tab="connection"]');
        for (const theme of ["light", "dark"]) {
            await run(`window.NightshiftTheme.save('${theme}')`);
            for (const width of [390, 412, 1440]) {
                await c("Emulation.setDeviceMetricsOverride", {
                    width,
                    height: 950,
                    deviceScaleFactor: 1,
                    mobile: width < 600,
                });
                await sleep(150);
                await set("device_id", "manual-business-device-01m7725z72e0jc7p4nb71m2gsc");
                const state = await run(
                    `(()=>{const p=document.querySelector('#whatsapp-connection'),d=p.querySelector('[data-whatsapp-device]');return {editable:!d.readOnly&&!d.disabled,help:!!document.getElementById(d.getAttribute('aria-describedby')),label:p.innerText.includes('${locale === "en" ? "WhatsApp Device ID" : "Kitambulisho cha Kifaa cha WhatsApp"}'),overflow:document.documentElement.scrollWidth>innerWidth,controls:[...p.querySelectorAll('input,button,a')].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth}),retired:!p.querySelector('[name=phone],[data-whatsapp-connection=qr],[data-whatsapp-connection=reconnect]'),secret:document.documentElement.innerHTML.includes('BROWSER-FAKE-PASSWORD'),dirty:!!p.querySelector('[data-whatsapp-connection=status]').disabled}})()`,
                );
                check(
                    `${locale}/${theme}/${width}: editable scoped device and responsive card`,
                    state.editable &&
                        state.help &&
                        state.label &&
                        !state.overflow &&
                        state.controls &&
                        state.retired &&
                        !state.secret &&
                        state.dirty,
                    state,
                );
                await run(`document.querySelector('[data-whatsapp-form=device]').requestSubmit()`);
                await wait(
                    `!document.querySelector('.whatsapp-page').hasAttribute('aria-busy')||document.querySelector('.whatsapp-page').getAttribute('aria-busy')==='false'`,
                );
                await wait(
                    `!document.querySelector('#whatsapp-connection [data-whatsapp-connection=status]').disabled`,
                );
                check(
                    `${locale}/${theme}/${width}: saved device verified`,
                    await run(
                        `[...document.querySelectorAll('[data-whatsapp-status]')].every(e=>e.textContent==='${locale === "en" ? "Connected" : "Imeunganishwa"}')`,
                    ),
                );
                await shot(`${locale}-${theme}-${width}`);
            }
        }
    }
    await click("#whatsapp-connection [data-whatsapp-connection=status]");
    await wait(`document.querySelector('.whatsapp-page').getAttribute('aria-busy')==='false'`);
    check(
        "Test Connection uses saved device",
        await run(
            `document.querySelector('[data-whatsapp-connection-jid]').textContent.includes('255700000000')`,
        ),
    );
    check(
        "Safe external device manager link",
        await run(
            `(()=>{const a=document.querySelector('#whatsapp-connection a');return a.href==='https://notify.buildcore.site/'&&a.target==='_blank'&&a.rel.includes('noopener')})()`,
        ),
    );
    // Capture Test Message requests without creating a real delivery or queue item.
    await run(
        `window.__testKeys=[];const original=window.fetch;window.fetch=async function(url,options){if(String(url).endsWith('/send/test')){window.__testKeys.push(JSON.parse(options.body).idempotency_key);return new Response(JSON.stringify({message:'Queued test'}),{status:202,headers:{'Content-Type':'application/json'}})}return original.apply(this,arguments)}`,
    );
    await click('[data-whatsapp-tab="logs"]');
    for (let i = 0; i < 2; i++) {
        await click('[data-whatsapp-form="send"] button[value="test"]');
        await wait(
            `window.__testKeys.length===${i + 1}&&document.querySelector('.whatsapp-page').getAttribute('aria-busy')==='false'`,
        );
    }
    check(
        "Repeated Test Message remains deduplicated",
        await run("window.__testKeys[0]===window.__testKeys[1]"),
    );
    await click('[data-whatsapp-tab="connection"]');
    await set("device_id", "manual-business-replacement-device");
    await run(`document.querySelector('[data-whatsapp-form="device"]').requestSubmit()`);
    await wait(
        `document.querySelector('.whatsapp-page').dataset.configuredDevice==='manual-business-replacement-device'&&document.querySelector('.whatsapp-page').getAttribute('aria-busy')==='false'`,
    );
    await click('[data-whatsapp-tab="logs"]');
    await click('[data-whatsapp-form="send"] button[value="test"]');
    await wait(
        `window.__testKeys.length===3&&document.querySelector('.whatsapp-page').getAttribute('aria-busy')==='false'`,
    );
    check(
        "Test Message gets a new request after device replacement",
        await run("window.__testKeys[0]!==window.__testKeys[2]"),
    );
    check("No browser or server errors", !errors.length && !httpErrors.length, {
        errors,
        httpErrors,
    });
} finally {
    await fs.writeFile(
        `${output}/results.json`,
        JSON.stringify({ checks, errors, httpErrors }, null, 2),
    );
    ws.close();
}
