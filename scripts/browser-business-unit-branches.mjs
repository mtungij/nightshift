import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-business-unit-browser";
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
    if (url.pathname === '/business-units') await wait('!!document.querySelector(".topbar")');
}
const report = (id) =>
    run(
        `fetch('/api/nightshift/shift-stock/${id}',{headers:{Accept:'application/json'}}).then(r=>r.json())`,
    );
async function login(username, path = '/dashboard', locale = 'en') {
    await c('Network.clearBrowserCookies');
    await navigate('/login');
    await set('username', username);
    await set('pin', '0123');
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait(`location.pathname===${JSON.stringify(path)} && document.readyState==='complete' && !!document.querySelector('[name=csrf-token]')`);
    await run(`fetch('/settings/language',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:${JSON.stringify(locale)}})})`);
}
async function submit(selector) {
    await run(`window.__treasuryBefore=true;document.querySelector(${JSON.stringify(selector)}).requestSubmit()`);
    await wait(`window.__treasuryBefore===undefined && document.readyState==='complete'`);
}
async function scopedSet(selector, name, value) {
    await run(`(()=>{const f=document.querySelector(${JSON.stringify(selector)});const el=f.elements[${JSON.stringify(name)}];if(!el)throw Error('Missing '+${JSON.stringify(name)});el.value=${JSON.stringify(String(value))};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`);
}
const treasury = () => run(`fetch('/api/cash-custody/treasury',{headers:{Accept:'application/json'}}).then(r=>r.json())`);

try {
 if ((await fetch(base+'/login')).headers.get('X-Nightshift-Validation-Database') !== 'nightshift_treasury_browser_validation') throw new Error('Use the isolated Business Unit browser validation database.');
 await c('Page.enable');await c('Runtime.enable');await c('Network.enable');
 const f=JSON.parse(await fs.readFile('/tmp/nightshift-business-unit-fixtures.json','utf8'));
 await login(f.owner,'/dashboard');await navigate('/dashboard');
 const branchName='Second branch '+Date.now();
 const created=await run(`fetch('/api/nightshift/commands/catalog-branch',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({idempotency_key:crypto.randomUUID(),name:${JSON.stringify(branchName)},code:'BU_'+Date.now(),active:true})}).then(r=>r.json())`);
 const data=await run(`fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())`);
 const extra=data.business_units.find(u=>u.branch_id===created.id), manager=data.team.find(u=>u.username===f.multi_manager);
 await navigate('/business-units');
 const staff='form[data-business-unit-action=staff]:has(input[name=id][value="'+manager.id+'"])';
 await run(`(()=>{const s=document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="branch_ids[]"]');for(const o of s)o.checked=[${f.branch_id},${created.id}].includes(Number(o.value));s[0]?.dispatchEvent(new Event('input',{bubbles:true}));})()`);await submit(staff);
 check('Owner adds second branch in staff UI',await run(`document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="branch_ids[]"]:checked').length===2`));
 await run(`(()=>{const s=document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="unit_ids[]"]');for(const o of s)o.checked=[${f.cases.bar.unit_id},${f.cases.restaurant.unit_id},${extra.id}].includes(Number(o.value));s[0]?.dispatchEvent(new Event('input',{bubbles:true}));})()`);await submit(staff);
 check('Owner grants unit in the new branch',await run(`Array.from(document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="unit_ids[]"]:checked')).some(o=>Number(o.value)===${extra.id})`));
 await login(f.multi_manager,'/dashboard');await navigate('/dashboard');await wait(`!!document.querySelector('[data-change=business-unit]')`);
 check('Unit selector identifies branch context',await run(`Array.from(document.querySelector('[data-change=business-unit]').options).find(o=>Number(o.value)===${extra.id}).textContent.includes(${JSON.stringify(branchName)})`));
 await run(`(()=>{const s=document.querySelector('[data-change=business-unit]');s.value=${JSON.stringify(String(extra.id))};s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 await wait(`document.querySelector('[data-change=branch]')?.value===${JSON.stringify(branchName)}`);
 check('Unit selection changes to its own branch',await run(`document.querySelector('[data-change=branch]').value===${JSON.stringify(branchName)}`));
 await shot('cross-branch-unit-selector');
 await login(f.owner,'/dashboard');await navigate('/business-units');
 await run(`(()=>{const root=document.querySelector(${JSON.stringify(staff)});for(const o of root.querySelectorAll('[name="branch_ids[]"]'))o.checked=Number(o.value)===${f.branch_id};for(const o of root.querySelectorAll('[name="unit_ids[]"]'))o.checked=[${f.cases.bar.unit_id},${f.cases.restaurant.unit_id}].includes(Number(o.value));root.dispatchEvent(new Event('input',{bubbles:true}));})()`);await submit(staff);
 check('Owner restores original branch and unit assignments',await run(`document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="branch_ids[]"]:checked').length===1&&document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="unit_ids[]"]:checked').length===2`));
 await login(f.multi_manager,'/dashboard');await navigate('/dashboard');
 const denied=await run(`fetch('/api/nightshift/reports/Business%20Unit%20Performance?business_unit_id='+${extra.id},{headers:{Accept:'application/json'}}).then(r=>r.status)`);
 check('Removed branch unit is rejected by backend',denied===403,denied);
 check('No runtime errors',errors.length===0,errors);check('No server errors',httpErrors.length===0,httpErrors);
 await fs.writeFile(output+'/branches-result.json',JSON.stringify({checks,errors,httpErrors},null,2));
} finally {ws.close();}
