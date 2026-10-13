import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-staff-filters-browser";
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
    const navigate = async (url) => {
        await run("window.__previousLocalizedDocument=true");
        const destination = new URL(url);
        destination.searchParams.set("workflow_check", String(Date.now()));
        await c("Page.navigate", { url: destination.href });
        await wait(
            'window.__previousLocalizedDocument===undefined && document.readyState==="complete"',
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
        await wait(`document.documentElement.lang==='${locale}'`);
    };

    await login("admin@nightshift.test");
    check(
        "Dedicated MySQL only",
        await run(
            `fetch('/api/nightshift/bootstrap').then(r=>r.headers.get('X-Nightshift-Validation-Database')==='nightshift_validation')`,
        ),
    );
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        for (const theme of ["light", "dark"]) {
            await run(`window.NightshiftTheme.save('${theme}')`);
            for (const locale of ["en", "sw"]) {
                await language(locale);
                await navigate(`${base}/dashboard#users`);
                await wait('!!document.querySelector("[data-action=add-user]")');
                await click("[data-action=add-user]");
                check(
                    `${width}/${theme}/${locale}: Cashier PIN only`,
                    await run(
                        `!!document.querySelector('[name=pin]') && !document.querySelector('[name=password]')`,
                    ),
                );
                await set("role", "Manager");
                check(
                    `${width}/${theme}/${locale}: Manager password only`,
                    await run(
                        `!!document.querySelector('[name=password][required]') && !document.querySelector('[name=pin]')`,
                    ),
                );
                await set("role", "Waiter");
                check(
                    `${width}/${theme}/${locale}: Waiter PIN only`,
                    await run(
                        `!!document.querySelector('[name=pin][required]') && !document.querySelector('[name=password]')`,
                    ),
                );
                await set("pin", "123");
                check(
                    `${width}/${theme}/${locale}: three digits blocked`,
                    await run(`!document.querySelector('[name=pin]').checkValidity()`),
                );
                await set("pin", "ab12");
                check(
                    `${width}/${theme}/${locale}: letters blocked`,
                    await run(`!document.querySelector('[name=pin]').checkValidity()`),
                );
                await set("pin", "0123");
                check(
                    `${width}/${theme}/${locale}: leading zero valid`,
                    await run(`document.querySelector('[name=pin]').checkValidity()`),
                );
                check(
                    `${width}/${theme}/${locale}: mobile layout fits`,
                    await run(
                        `document.documentElement.scrollWidth<=innerWidth+1 && document.querySelector('[name=pin]').getBoundingClientRect().right<=innerWidth`,
                    ),
                );
                await shot(`staff-${width}-${theme}-${locale}`);
                await run(
                    `document.querySelector('.modal-backdrop [data-action="close-modal"]').click()`,
                );
            }
        }
    }
    await language("sw");
    await navigate(`${base}/dashboard#cashiers`);
    await wait('!!document.querySelector("[data-action=add-user]")');
    check(
        "Legacy cashier link opens unified staff page",
        await run('document.querySelector("#main h1").textContent.includes("Wafanyakazi")'),
    );
    await run('window.__staffDocumentMarker="preserved"');
    const suffix = Date.now();
    for (const role of ["Waiter", "Cashier", "Manager", "Store Keeper", "Supervisor"]) {
        await click("[data-action=add-user]");
        await set("role", role);
        await set("name", `PIN Smoke ${role} ${suffix}`);
        await set("email", `${role.toLowerCase().replaceAll(" ", "-")}-${suffix}@pin-staff.test`);
        await set(
            !["Waiter", "Cashier"].includes(role) ? "password" : "pin",
            !["Waiter", "Cashier"].includes(role) ? "Secure!Password2026" : "0123",
        );
        await submit("add-user");
        const staff = (await snap()).team.find((u) => u.name === `PIN Smoke ${role} ${suffix}`);
        check(`${role}: staff created with correct role`, staff?.role === role);
        check(
            `${role}: immediate table appearance`,
            await run(
                `document.querySelector('.data-table').innerText.includes(${JSON.stringify(`PIN Smoke ${role} ${suffix}`)})`,
            ),
        );
        check(
            `${role}: selected role filter`,
            await run(
                `document.querySelector('[data-action="staff-filter"][aria-selected="true"]').dataset.value === ${JSON.stringify(role)}`,
            ),
        );
        check(
            `${role}: matching table rows only`,
            await run(
                `Array.from(document.querySelectorAll('.data-table tbody tr')).every(row => row.children[1].textContent.trim() === ${JSON.stringify({ Waiter: "Mhudumu", Cashier: "Keshia", Manager: "Meneja", "Store Keeper": "Mtunza Stoo", Supervisor: "Msimamizi" }[role])})`,
            ),
        );
        check(
            `${role}: role-specific Swahili toast`,
            await run(
                `document.querySelector('#toast').innerText.includes(${JSON.stringify({ Waiter: "Mhudumu", Cashier: "Keshia", Manager: "Meneja", "Store Keeper": "Mtunza Stoo", Supervisor: "Msimamizi" }[role] + " ameongezwa kikamilifu.")})`,
            ),
        );
        check(
            `${role}: no full refresh`,
            await run('window.__staffDocumentMarker === "preserved"'),
        );
        if (role === "Waiter") {
            await click('[data-nav="waiters"]');
            check(
                "New waiter visible in accounts before shift",
                await run(
                    `document.querySelector('#main').innerText.includes(${JSON.stringify(staff.name)})`,
                ),
            );
            await click('[data-nav="shifts"]');
            await click('[data-action="open-shift"]');
            check(
                "New waiter available for shift assignment",
                await run(
                    `Array.from(document.querySelector('[name="user"]').options).some(o => Number(o.value) === ${staff.id})`,
                ),
            );
            await set("user", staff.id);
            await set("type", "WAITER");
            await submit("open-shift");
            await click('[data-nav="pos"]');
            await click('[data-action="add-cart"]');
            await click('[data-action="send-order"]');
            check(
                "New waiter visible in POS selection after opening shift",
                await run(
                    `!!document.querySelector('[data-action="serving-waiter"][data-id="${staff.id}"]:not(:disabled)')`,
                ),
            );
            await run(
                `document.querySelector('.modal-backdrop [data-action="close-modal"]').click()`,
            );
            await click('[data-nav="staff"]');
        }
        check(
            `${role}: no secrets in browser data`,
            staff && !("pin" in staff) && !("operational_pin" in staff) && !("password" in staff),
        );
        if (["Waiter", "Cashier"].includes(role)) {
            const reset = await api("staff-pin", { id: staff.id, pin: "4567" });
            check(`${role}: admin PIN reset works`, reset.status === 200);
        }
    }
    await navigate(`${base}/dashboard#waiters`);
    await wait('!!document.querySelector("[data-action=add-waiter]")');
    await click("[data-action=add-waiter]");
    check(
        "Add waiter shortcut requires PIN only",
        await run(
            `!!document.querySelector('[name=pin][required]')&&!document.querySelector('[name=password]')`,
        ),
    );
    await set("name", `Quick Waiter ${suffix}`);
    await set("email", `quick-${suffix}@pin-staff.test`);
    await set("pin", "0987");
    await submit("add-waiter");
    check(
        "Add waiter shortcut creates staff",
        (await snap()).team.some((u) => u.name === `Quick Waiter ${suffix}` && u.role === "Waiter"),
    );
    await click('[data-action="staff-filter"][data-value=""]');
    await click("[data-action=add-user]");
    await set("role", "Waiter");
    await set("name", `Inactive Waiter ${suffix}`);
    await set("email", `inactive-${suffix}@pin-staff.test`);
    await set("pin", "0123");
    await set("active", "Inactive");
    await submit("add-user");
    check(
        "Inactive creation remains visible and marked",
        await run(
            `Array.from(document.querySelectorAll('.data-table tbody tr')).some(r => r.innerText.includes('Inactive Waiter ${suffix}') && r.innerText.includes('Haifanyi Kazi'))`,
        ),
    );
    await run(
        `(() => { const status=document.querySelector('[data-change="staff-status"]');status.value='Active';status.dispatchEvent(new Event('change',{bubbles:true})); })()`,
    );
    check(
        "Active filter excludes inactive staff",
        await run(
            `!document.querySelector('.data-table').innerText.includes('Inactive Waiter ${suffix}')`,
        ),
    );
    await run(
        `(() => { const status=document.querySelector('[data-change="staff-status"]');status.value='Inactive';status.dispatchEvent(new Event('change',{bubbles:true})); })()`,
    );
    check(
        "Inactive filter shows inactive staff",
        await run(
            `document.querySelector('.data-table').innerText.includes('Inactive Waiter ${suffix}')`,
        ),
    );
    await click('[data-action="staff-filter"][data-label="Supervisor role"]');
    check(
        "Supervisor alias uses the existing role",
        await run(
            `document.querySelector('[data-action="staff-filter"][aria-selected="true"]').dataset.value === 'Supervisor'`,
        ),
    );
    await click('[data-action="staff-filter"][data-value=""]');
    await run(
        `(() => { const status=document.querySelector('[data-change="staff-status"]');status.value='';status.dispatchEvent(new Event('change',{bubbles:true})); })()`,
    );
    for (const width of [390, 1440]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        check(
            `Staff table ${width}: layout fits viewport`,
            await run("document.documentElement.scrollWidth<=innerWidth+1"),
        );
        await shot(`staff-filters-${width}-sw`);
    }
    const foreign = JSON.parse(
        await fs.readFile(
            process.env.STAFF_FIXTURE_FILE || "/tmp/staff-browser-fixtures.json",
            "utf8",
        ),
    );
    check(
        "Foreign company staff excluded",
        !(await snap()).team.some((u) => u.id === foreign.foreign_user_id),
    );
    check(
        "Foreign branch bootstrap rejected",
        await run(
            `fetch('/api/nightshift/bootstrap?branch_id=${foreign.foreign_branch_id}', {headers:{Accept:'application/json'}}).then(r=>r.status===404)`,
        ),
    );
    const denied = await api("catalog-user", {
        name: "Cross tenant attempt",
        email: `cross-${suffix}@staff.test`,
        role: "Waiter",
        pin: "0123",
        branch_id: foreign.foreign_branch_id,
    });
    check("Cross company staff creation rejected", denied.status === 404);
    check(
        "Cross company attempt never appears in staff list",
        !(await snap()).team.some((u) => u.email === `cross-${suffix}@staff.test`),
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
