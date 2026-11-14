import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-report-pdf-filters";
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

const artifacts=[];
async function savePdf(url,key){
 const result=await run(`(async()=>{const r=await fetch(${JSON.stringify(url)},{headers:{Accept:'application/json'}});if(!r.ok)return {status:r.status,error:await r.text()};const blob=await r.blob();const data=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result.split(',')[1]);reader.onerror=reject;reader.readAsDataURL(blob)});return {status:r.status,type:r.headers.get('Content-Type'),filename:r.headers.get('Content-Disposition'),data};})()`);
 check('PDF '+key,result.status===200&&result.type?.includes('application/pdf'),{status:result.status,error:result.error});
 const bytes=Buffer.from(result.data,'base64');if(bytes.subarray(0,5).toString()!=='%PDF-')throw Error('Missing PDF signature');await fs.writeFile(output+'/'+key+'.pdf',bytes);
 artifacts.push({key,url,filename:result.filename,bytes:bytes.length});
}

try{
 if((await fetch(base+'/login')).headers.get('X-Nightshift-Validation-Database')!=='nightshift_treasury_browser_validation')throw Error('Use isolated browser database.');
 await c('Page.enable');await c('Runtime.enable');await c('Network.enable');await c('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:output});
 const f=JSON.parse(await fs.readFile('/tmp/nightshift-business-unit-fixtures.json','utf8'));await login(f.owner,'/dashboard');await navigate('/dashboard#reports');await wait('!!document.querySelector("[data-action=report]")');
 const d=await run(`fetch('/api/nightshift/bootstrap').then(r=>r.json())`);const product=d.products.find(p=>p.id===f.product_id),category=d.categoryRecords.find(c=>c.name===product.category);
 await c('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
 await click('[data-action=report][data-value="Daily Sales"]');await wait('!!document.querySelector("#report-filters")');
 for(const [key,value] of Object.entries({from:'2026-11-01',to:'2026-11-30',branch:f.branch_id,business_unit:f.cases.restaurant.unit_id,counter:f.cases.restaurant.counter_id,waiter:f.cases.restaurant.waiter_id,product:f.product_id,category:category.name}))await scopedSet('#report-filters',key,value);
 await wait('!document.querySelector("[data-action=pdf-report]").disabled && document.querySelector("#report-content").innerText.includes("2500.00")');
 const requests=[];ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.method==='Network.requestWillBeSent'&&m.params.request.url.includes('/pdf?'))requests.push(m.params.request.url);});
 await run('document.querySelector(".modal").scrollTop=document.querySelector(".modal").scrollHeight');
 check('390px report PDF action reachable',await run('(()=>{const r=document.querySelector("[data-action=pdf-report]").getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth&&r.left>=0&&r.right<=innerWidth&&r.bottom<=innerHeight})()'));
 await click('[data-action=pdf-report]');await wait('!document.querySelector("[data-action=pdf-report]").hasAttribute("aria-busy")');
 const actual=requests.at(-1);if(!actual)throw Error('No PDF request');const q=new URL(actual).searchParams;
 for(const [key,value] of Object.entries({from:'2026-11-01',to:'2026-11-30',branch_id:f.branch_id,business_unit_id:f.cases.restaurant.unit_id,stock_location_id:f.cases.restaurant.counter_id,waiter_id:f.cases.restaurant.waiter_id,product_id:f.product_id,category_id:category.id}))check('Actual PDF retains '+key,q.get(key)===String(value),q.get(key));
 await savePdf(new URL(actual).pathname+new URL(actual).search,'branch-counter-staff-product');await shot('390px-report-download');
 await scopedSet('#report-filters','cashier',f.cases.restaurant.cashier_id);await wait('!document.querySelector("[data-action=pdf-report]").disabled');await click('[data-action=pdf-report]');await wait('!document.querySelector("[data-action=pdf-report]").hasAttribute("aria-busy")');
 check('Actual PDF retains cashier ID',new URL(requests.at(-1)).searchParams.get('issued_by')===String(f.cases.restaurant.cashier_id));await savePdf(new URL(requests.at(-1)).pathname+new URL(requests.at(-1)).search,'cashier-filter-empty');
 await sleep(500);const files=await fs.readdir(output);check('Browser saves a downloadable PDF',files.some(n=>n.startsWith('nightshift-daily-sales')&&n.endsWith('.pdf')));
 check('No runtime errors',errors.length===0,errors);check('No server errors',httpErrors.length===0,httpErrors);
 await fs.writeFile(output+'/result.json',JSON.stringify({checks,errors,httpErrors,artifacts},null,2));
}finally{ws.close();}
