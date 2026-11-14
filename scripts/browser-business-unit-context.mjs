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

const bootstrap=(id)=>run(`fetch('/api/nightshift/bootstrap'+${JSON.stringify('')}+${id ? JSON.stringify('?stock_location_id='+id) : JSON.stringify('')},{headers:{Accept:'application/json'}}).then(r=>r.json())`);
try {
 if ((await fetch(base+'/login')).headers.get('X-Nightshift-Validation-Database') !== 'nightshift_treasury_browser_validation') throw new Error('Use the isolated Business Unit browser validation database.');
 await c('Page.enable');await c('Runtime.enable');await c('Network.enable');
 const f=JSON.parse(await fs.readFile('/tmp/nightshift-business-unit-fixtures.json','utf8'));
 for(const [kind,width] of [['bar',1440],['restaurant',390]]){
  const a=f.cases[kind];await c('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});
  await login(a.terminal,'/dashboard',kind==='bar'?'en':'sw');await navigate('/dashboard#pos');
  await wait(`!!document.querySelector('[data-change=cart-counter]')`);
  check(kind+' POS uses stable counter ID',await run(`Number(document.querySelector('[data-change=cart-counter]').value)===${a.counter_id}`));
  await click('[data-action=add-cart][data-id="'+f.product_id+'"]');await click('[data-action=send-order]');
  await wait(`!!document.querySelector('[data-form=serving-order]')`);
  check(kind+' final waiter modal stays in counter unit',await run(`Array.from(document.querySelector('[data-form=serving-order] [name=waiter_id]').options).filter(o=>o.value).every(o=>Number(o.value)===${a.waiter_id})`));
 }
 await c('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 await login(f.bar_manager,'/dashboard');await navigate('/dashboard#inventory');
 await wait(`!!document.querySelector('[data-action=tab][data-value=Transfers]')`);
 await click('[data-action=tab][data-value=Transfers]');
 const before=(await bootstrap()).transfers.map(t=>t.id);
 const central=Number((await bootstrap(f.central_id)).products.find(p=>p.id===f.product_id).stock);
 const bar=Number((await bootstrap(f.cases.bar.counter_id)).products.find(p=>p.id===f.product_id).stock);
 await click('[data-action=transfer]');await wait(`!!document.querySelector('[data-form=transfer]')`);
 await set('from',f.central_id);await set('to',f.cases.bar.counter_id);await set('product',f.product_id);await set('quantity','1');await set('notes','Reviewed shared central to Bar transfer');
 await run(`document.querySelector('[data-form=transfer]').requestSubmit()`);
 await wait(`!document.querySelector('[data-form=transfer]')`);
 let data=await bootstrap(), transfer=data.transfers.find(t=>!before.includes(t.id));
 check('Bar Manager sends shared-store transfer in UI',transfer&&transfer.status==='IN_TRANSIT',transfer);
 await wait(`!!document.querySelector('[data-action=receive-transfer][data-id="${transfer.id}"]')`);
 await click('[data-action=receive-transfer][data-id="'+transfer.id+'"]');
 await wait(`fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json()).then(d=>d.transfers.find(t=>t.id===${transfer.id})?.status==='RECEIVED')`);
 check('Bar Manager confirms transfer receipt in UI',(await bootstrap()).transfers.find(t=>t.id===transfer.id).status==='RECEIVED');
 check('Transfer conserves location stock',Number((await bootstrap(f.central_id)).products.find(p=>p.id===f.product_id).stock)===central-1&&Number((await bootstrap(f.cases.bar.counter_id)).products.find(p=>p.id===f.product_id).stock)===bar+1);
 const blocked=await run(`fetch('/api/nightshift/commands/transfer-create',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({idempotency_key:crypto.randomUUID(),source_location_id:${f.central_id},destination_location_id:${f.cases.restaurant.counter_id},items:[{product_unit_id:${f.selling_id},quantity:'1'}]})}).then(r=>r.status)`);
 check('Unauthorized Restaurant transfer rejected',blocked===404,blocked);
 await shot('bar-transfer-final');
 await login(f.owner,'/dashboard');const team=await bootstrap();const manager=team.team.find(u=>u.username===f.bar_manager);
 await navigate('/business-units');
 const staff='form[data-business-unit-action=staff]:has(input[name=id][value="'+manager.id+'"])';
 await run(`(()=>{const s=document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="unit_ids[]"]');for(const o of s)o.checked=[${f.cases.bar.unit_id},${f.cases.restaurant.unit_id}].includes(Number(o.value));s[0]?.dispatchEvent(new Event('input',{bubbles:true}));})()`);
 await submit(staff);
 check('Owner assigns multiple units in staff UI',await run(`document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="unit_ids[]"]:checked').length===2`));
 await run(`(()=>{const s=document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="unit_ids[]"]');for(const o of s)o.checked=Number(o.value)===${f.cases.bar.unit_id};s[0]?.dispatchEvent(new Event('input',{bubbles:true}));})()`);await submit(staff);
 check('Owner restores one-unit staff assignment',await run(`document.querySelector(${JSON.stringify(staff)}).querySelectorAll('[name="unit_ids[]"]:checked').length===1`));
 check('No runtime errors',errors.length===0,errors);check('No server errors',httpErrors.length===0,httpErrors);
 await fs.writeFile(output+'/context-result.json',JSON.stringify({checks,errors,httpErrors,transfer_id:transfer.id},null,2));
} finally {ws.close();}
