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

const bootstrap=()=>run(`fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())`);
async function post(command,payload){return run(`fetch('/api/nightshift/commands/'+${JSON.stringify(command)},{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({...${JSON.stringify(payload)},idempotency_key:crypto.randomUUID()})}).then(async r=>({status:r.status,data:await r.json()}))`)}
try {
await c('Page.enable');await c('Runtime.enable');await c('Network.enable');
const f=JSON.parse(await fs.readFile('/tmp/nightshift-business-unit-fixtures.json','utf8'));
const issued={};
check('Isolated MySQL browser database',(await fetch(base+'/login')).headers.get('X-Nightshift-Validation-Database')==='nightshift_treasury_browser_validation');
for(const [kind,width,locale,price] of [['bar',1440,'en',2000],['restaurant',390,'sw',2500]]){
 const a=f.cases[kind], other=f.cases[kind==='bar'?'restaurant':'bar'];
 await c('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});
 await login(a.terminal,'/dashboard',locale);await navigate('/dashboard#pos');
 await wait(`!!document.querySelector('[data-action="add-cart"]')`);
 let b=await bootstrap();
 check(kind+' terminal has one scoped counter',b.locations.length===1&&b.locations[0].id===a.counter_id);
 check(kind+' waiter selector excludes other unit',b.servingWaiters.length===1&&b.servingWaiters[0].id===a.waiter_id);
 check(kind+' shared product uses unit price',Number(b.products.find(p=>p.id===f.product_id).price)===price);
 const tamper=await post('order-send',{stock_location_id:a.counter_id,waiter_id:other.waiter_id,pin:'0123',discount:'0',items:[{product_unit_id:f.selling_id,quantity:'1'}]});
 check(kind+' rejects foreign-unit waiter',tamper.status===403,tamper.status);
 await shot(kind+'-pos');
 const previous=b.orders.map(o=>o.id);
 await click('[data-action="add-cart"][data-id="'+f.product_id+'"]');
 await click('[data-action="send-order"]');
 await wait(`!!document.querySelector('[data-form="serving-order"]')`);
 await set('waiter_id',a.waiter_id);await set('pin','0123');
 await run(`document.querySelector('[data-form="serving-order"]').requestSubmit()`);
 await wait(`!document.querySelector('[data-form="serving-order"]')`);
 b=await bootstrap();const order=b.orders.find(o=>!previous.includes(o.id));
 check(kind+' UI issues one correctly priced order',order&&Number(order.total)===price&&order.status==='Issued',order);
 issued[kind]=order.id;
 await login(a.cashier,'/dashboard',locale);await navigate('/dashboard');
 const paid=await post('payment',{id:order.id,lines:[{method:'Cash',amount:String(price)}]});
 check(kind+' cashier settles own unit',paid.status===200,paid.status);
 const denied=await run(`fetch('/api/nightshift/bootstrap?stock_location_id='+${other.counter_id},{headers:{Accept:'application/json'}}).then(r=>r.status)`);
 check(kind+' cashier cannot open other unit counter',denied===404,denied);
 const pdf=await c('Page.printToPDF',{printBackground:true,landscape:true,paperWidth:11.7,paperHeight:8.3});
 await fs.writeFile(output+'/'+kind+'-screen.pdf',Buffer.from(pdf.data,'base64'));
}
await login(f.bar_manager,'/dashboard');await navigate('/dashboard');
let b=await bootstrap();
check('Bar-only manager sees Bar order only',b.orders.some(o=>o.id===issued.bar)&&!b.orders.some(o=>o.id===issued.restaurant));
check('Bar-only manager sees no Restaurant staff',!b.team.some(u=>u.id===f.cases.restaurant.waiter_id));
check('Bar-only manager report tampering blocked',await run(`fetch('/api/nightshift/reports/Business%20Unit%20Performance?business_unit_id='+${f.cases.restaurant.unit_id},{headers:{Accept:'application/json'}}).then(r=>r.status)`)===403);
await login(f.multi_manager,'/dashboard');await navigate('/dashboard');
b=await bootstrap();check('Multi-unit manager sees both unit orders',b.orders.some(o=>o.id===issued.bar)&&b.orders.some(o=>o.id===issued.restaurant));
check('Multi-unit selector includes All',await run(`Array.from(document.querySelector('[data-change="business-unit"]').options).some(o=>o.value==='')`));
await run(`(()=>{const s=document.querySelector('[data-change="business-unit"]');s.value=${JSON.stringify(String(f.cases.restaurant.unit_id))};s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
await wait(`!document.querySelector('[data-change="stock-location"]') || document.readyState==='complete'`);
await sleep(1500);
check('Selected Restaurant data is isolated',await run(`fetch('/api/nightshift/bootstrap?business_unit_id='+${f.cases.restaurant.unit_id},{headers:{Accept:'application/json'}}).then(r=>r.json()).then(d=>d.orders.some(o=>o.id===${issued.restaurant})&&!d.orders.some(o=>o.id===${issued.bar}))`));
const perf=await run(`fetch('/api/nightshift/reports/Business%20Unit%20Performance',{headers:{Accept:'application/json'}}).then(r=>r.json())`);
check('Consolidated performance reconciles two unit sales',perf.rows.reduce((n,r)=>n+Number(r[1]),0)===4500,perf.rows);
await shot('multi-manager');
await login(f.owner,'/dashboard');await navigate('/business-units');
check('Owner can manage unit hierarchy',await run(`!!document.querySelector('[data-business-unit-action="save"]')`));
await shot('owner-business-units');
check('Legacy cutover financial totals unchanged',f.legacy_cutover_unchanged);
check('No runtime errors',errors.length===0,errors);check('No server errors',httpErrors.length===0,httpErrors);
await fs.writeFile(output+'/result.json',JSON.stringify({checks,issued,errors,httpErrors},null,2));
} finally {ws.close();}
