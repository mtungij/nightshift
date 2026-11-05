import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-auth-branding-browser";
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
async function language(locale) {
    await run(
        `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:${JSON.stringify(locale)}})}).then(r=>{if(!r.ok)throw Error('Language '+r.status)})`,
    );
}
async function chooseLanguage(locale) {
    if (await run(`document.documentElement.lang===${JSON.stringify(locale)}`)) return;
    await run(
        `window.__brandPreviousDocument=true;document.querySelector('.language-switch button[aria-label="${locale === "sw" ? "Kiswahili" : "English"}"]').click()`,
    );
    await wait(
        `window.__brandPreviousDocument===undefined && document.readyState==="complete" && document.documentElement.lang===${JSON.stringify(locale)}`,
    );
}
async function appearance(theme) {
    if (await run(`document.documentElement.dataset.theme!==${JSON.stringify(theme)}`))
        await click("[data-auth-theme-toggle]");
}
async function brandCheck(tag) {
    await wait(
        `document.querySelector('img[src$="/nightshift.svg"]')?.complete && document.querySelector('img[src$="/nightshift.svg"]').naturalWidth>0`,
    );
    check(
        `${tag}: canonical mark and no starter branding`,
        await run(
            `document.querySelector('img[src$="/nightshift.svg"]')?.naturalWidth>0 && !/laravel|starter.?kit/i.test(document.body.innerText) && !document.querySelector('a[href*="laravel.com"],a[href*="livewire-starter-kit"]') && !document.documentElement.innerHTML.includes('M17.2 5.633')`,
        ),
    );
    check(
        `${tag}: no horizontal overflow`,
        await run("document.documentElement.scrollWidth<=innerWidth+1"),
    );
}
try {
    const safety = await fetch(new URL("/login", base));
    check(
        "Isolated MySQL validation target",
        safety.headers.get("X-Nightshift-Validation-Database") === "nightshift_validation",
    );
    await c("Page.enable");
    await c("Runtime.enable");
    await c("Network.enable");
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        for (const locale of ["en", "sw"]) {
            for (const theme of ["light", "dark"]) {
                const tag = `${width}-${locale}-${theme}`;
                await c("Network.clearBrowserCookies");
                await navigate("/login");
                await chooseLanguage(locale);
                check(
                    `${tag}: guest defaults to light`,
                    await run("document.documentElement.dataset.theme==='light'"),
                );
                await appearance(theme);
                await brandCheck(`${tag} login`);
                check(
                    `${tag}: requested title and PIN form`,
                    await run(
                        `document.querySelector('h1').textContent.trim()===${JSON.stringify(locale === "sw" ? "Ingia Nightshift" : "Log in to Nightshift")} && Array.from(document.querySelectorAll('form[action$="/login"] input:not([type="hidden"])')).map(n=>n.name).join(',')==='username,pin' && document.querySelector('[name=pin]').type==='password' && document.querySelector('[name=pin]').inputMode==='numeric'`,
                    ),
                );
                check(
                    `${tag}: typography and theme`,
                    await run(
                        `getComputedStyle(document.querySelector('[name=username]')).fontSize==='16px' && getComputedStyle(document.body).fontFamily.includes('Poppins') && document.documentElement.dataset.theme===${JSON.stringify(theme)} && getComputedStyle(document.querySelector('.auth-card')).backgroundColor===${JSON.stringify(theme === "light" ? "rgb(255, 255, 255)" : "rgb(26, 29, 34)")}`,
                    ),
                );
                check(
                    `${tag}: translated helper and onboarding`,
                    await run(
                        `document.body.innerText.includes(${JSON.stringify(locale === "sw" ? "Umesahau PIN? Wasiliana na msimamizi wa biashara yako." : "Ask your company admin to reset a forgotten PIN.")}) && document.querySelector('a[href$="/register-business"]').textContent.trim()===${JSON.stringify(locale === "sw" ? "Sajili biashara yako" : "Set up your business")}`,
                    ),
                );
                await shot(`${tag}-login`);
                for (const path of [
                    "/register-business",
                    "/forgot-password",
                    "/reset-password/brand-check?email=owner%40example.test",
                ]) {
                    await navigate(path);
                    await appearance(theme);
                    await brandCheck(`${tag} ${path.split("?")[0]}`);
                    if (path === "/register-business") await shot(`${tag}-business-register`);
                }
            }
        }
    }
    const fixtures = JSON.parse(await fs.readFile("/tmp/nightshift-auth-fixtures.json", "utf8"));
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        for (const role of ["owner", "manager", "cashier", "waiter", "storekeeper"]) {
            const tag = `${width}-${role}`;
            await c("Network.clearBrowserCookies");
            await navigate("/login");
            await set("username", fixtures.accounts[role].username);
            await set("pin", "0123");
            await run("document.querySelector('form[action$=\"/login\"]').requestSubmit()");
            await wait("location.pathname==='/dashboard'||location.pathname==='/open-shift'");
            check(
                `${tag}: authenticated through original PIN flow`,
                await run("location.pathname==='/dashboard'||location.pathname==='/open-shift'"),
            );
            await language(width === 390 ? "sw" : "en");
            await run(
                `fetch('/quick-lock',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content}}).then(r=>{if(!r.ok)throw Error('Lock '+r.status)})`,
            );
            await navigate("/quick-lock");
            await appearance(width === 390 ? "dark" : "light");
            await brandCheck(`${tag} Quick Lock`);
            check(
                `${tag}: Quick Lock preserves actor`,
                await run(
                    `document.body.innerText.includes(${JSON.stringify("PIN " + role)}) && document.querySelector('[name=pin]').type==='password'`,
                ),
            );
            await shot(`${tag}-quick-lock`);
            await set("pin", "0123");
            await run(
                "document.querySelector('form[action$=\"/quick-lock/unlock\"]').requestSubmit()",
            );
            await wait("location.pathname==='/dashboard'||location.pathname==='/open-shift'");
            check(
                `${tag}: unlock succeeds`,
                await run("location.pathname==='/dashboard'||location.pathname==='/open-shift'"),
            );
            await navigate("/settings/profile");
            await brandCheck(`${tag} settings`);
            if (role === "owner") await shot(`${tag}-settings`);
        }
    }
    await c("Network.clearBrowserCookies");
    await c("Emulation.setDeviceMetricsOverride", {
        width: 320,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await navigate("/login");
    await chooseLanguage("sw");
    await appearance("dark");
    await brandCheck("320px Kiswahili dark login");
    await shot("320-sw-dark-login");
    await navigate("/missing-branding-check");
    await brandCheck("404 error");
    await shot("404-error");
    const manifest = await (await fetch(base + "/manifest.webmanifest")).json();
    check(
        "Manifest uses Nightshift",
        manifest.name === "Nightshift" && manifest.short_name === "Nightshift",
    );
    for (const icon of [
        ...manifest.icons,
        { src: "/apple-touch-icon.png" },
        { src: "/favicon.ico" },
        { src: "/favicon.svg" },
    ]) {
        const response = await fetch(base + icon.src);
        check(
            `Browser icon ${icon.src} exists`,
            response.ok && Number(response.headers.get("content-length")) > 0,
        );
    }
    check("No browser exceptions", errors.length === 0, errors);
    check("No HTTP 5xx", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify({ result: "PASS", checks, errors, httpErrors }, null, 2),
    );
} catch (error) {
    process.exitCode = 1;
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
    // All evidence writes are awaited; process exit closes the debugging socket.
    process.exit(process.exitCode || 0);
}
