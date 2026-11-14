import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-admin-ui-browser";
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

try {
 if ((await fetch(base+'/login')).headers.get('X-Nightshift-Validation-Database') !== 'nightshift_treasury_browser_validation') throw Error('Use isolated browser database.');
 await c('Page.enable');await c('Runtime.enable');await c('Network.enable');
 const f=JSON.parse(await fs.readFile('/tmp/nightshift-business-unit-fixtures.json','utf8'));
 await login(f.owner,'/dashboard');
 const sizes=[[390,844],[412,915],[820,1180],[1440,1000],[1920,1080]];
 const pages=[['/business-units','business-units'],['/treasury','treasury'],['/cash-handovers','cash-handovers'],['/audit-trail','audit']];
 for(const locale of ['en','sw']){
  await navigate('/business-units');await wait('!!document.querySelector(".topbar")');
  if(await run('document.documentElement.lang')!==locale){await run('window.__languageBefore=true');await click('[data-language="'+locale+'"]');await wait('window.__languageBefore===undefined && document.documentElement.lang==="'+locale+'" && !!document.querySelector("#main [data-workspace-page]")');}
  for(const [path,screen] of pages){
   await navigate(path);await wait('!!document.querySelector("#main [data-workspace-page]")');
   check(locale+' '+screen+' uses shared active shell',await run('!!document.querySelector(".sidebar .workspace-switch") && !!document.querySelector(".topbar .profile-button") && !!document.querySelector(".nav-item.active[data-nav='+screen+']")'));
   for(const theme of ['light','dark']){
    await run('window.NightshiftTheme.apply('+JSON.stringify(theme)+')');await wait('document.documentElement.dataset.theme==="'+theme+'"');
    for(const [width,height] of sizes){
     await c('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<721});await sleep(350);
     const bounds=await run(`(()=>{const p=document.querySelector('#main [data-workspace-page]');return {overflow:document.documentElement.scrollWidth>innerWidth,page:p.getBoundingClientRect().width,controls:[...document.querySelectorAll('.topbar [data-language],.topbar [data-theme-toggle],.topbar .profile-button')].every(e=>{const r=e.getBoundingClientRect();return r.width>0&&r.left>=0&&r.right<=innerWidth}),forms:[...p.querySelectorAll('input:not([type=hidden]),select,button')].filter(e=>e.getBoundingClientRect().width).every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1})}})()`);
     check(locale+' '+theme+' '+screen+' '+width+'px responsive bounds',!bounds.overflow&&bounds.controls&&bounds.forms&&bounds.page<=1201,bounds);
     if(width<721){await click('.mobile-menu');check(screen+' '+locale+' '+theme+' '+width+' drawer works',await run('document.querySelector(".sidebar").classList.contains("open")'));await c('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await c('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await sleep(300);}
     if(screen==='audit'&&width<721)check('Audit mobile cards '+locale+' '+theme+' '+width,await run('getComputedStyle(document.querySelector(".audit-table thead")).display==="none"'));
     await shot(screen+'-'+locale+'-'+theme+'-'+width);
    }
   }
  }
 }
 await navigate('/audit-trail?search=Readable%20audit');await wait('!!document.querySelector(".topbar")');
 // Search indexes safe action/reference metadata rather than raw payloads.
 await navigate('/audit-trail?action=BUSINESS_UNIT_SAVED');await wait('!!document.querySelector("[data-audit-open]") && !!document.querySelector(".topbar")');
 check('Audit server redacts synthetic secrets',await run('!document.documentElement.innerHTML.includes("SYNTHETIC_UI_PIN_SECRET")&&!document.documentElement.innerHTML.includes("SYNTHETIC_UI_API_SECRET")'));
 await click('[data-audit-open]');await wait('!!document.querySelector("dialog[open]")');
 check('Audit details shows friendly changes and technical code',await run('document.querySelector("dialog[open]").innerText.includes("BUSINESS_UNIT_SAVED")&&!!document.querySelector("dialog[open] .audit-change")'));
 await shot('audit-details');await click('[data-audit-close]');
 const csv=await run(`fetch('/audit-trail?export=1').then(r=>r.text())`);check('Audit CSV redacts secrets',!csv.includes('SYNTHETIC_UI_')&&csv.includes('[REDACTED]'));
 await navigate('/treasury/report?name=Treasury%20Ledger');await wait('!!document.querySelector(".topbar")');check('Treasury reports use shared shell',await run('!!document.querySelector("#main [data-workspace-page=treasury]")'));
 await navigate('/dashboard#staff');await wait('!!document.querySelector("[data-action=add-user]")');
 for(const locale of ['en','sw']){
  if(await run('document.documentElement.lang')!==locale){await click('[data-language="'+locale+'"]');await wait('document.documentElement.lang==="'+locale+'" && !!document.querySelector("[data-action=add-user]")');}
  for(const theme of ['light','dark']){
   await run('window.NightshiftTheme.apply('+JSON.stringify(theme)+')');
   for(const [width,height] of sizes){
    await c('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<721});await click('[data-action=add-user]');await wait('!!document.querySelector(".modal .scope-picker")');
    const form='.modal form';await scopedSet(form,'branch',f.branch_id);
    await run(`(()=>{const i=document.querySelector('.modal [name=business_unit_ids][value="${f.cases.bar.unit_id}"]');i.checked=true;i.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    const scope=await run(`(()=>{const form=document.querySelector('.modal form');return {overflow:document.documentElement.scrollWidth>innerWidth,units:[...form.querySelectorAll('[name=business_unit_ids]:not(:disabled)')].every(i=>Number(i.closest('label').dataset.optionBranch)===${f.branch_id}),counters:[...form.querySelectorAll('[name=cashier_counter_ids]:not(:disabled)')].every(i=>Number(i.closest('label').dataset.optionUnit)===${f.cases.bar.unit_id}),native:!!form.querySelector('select[multiple]'),modal:document.querySelector('.modal').getBoundingClientRect().right<=innerWidth}})()`);
    check('Staff '+locale+' '+theme+' '+width+' dropdown scope and bounds',!scope.overflow&&scope.units&&scope.counters&&!scope.native&&scope.modal,scope);
    await run(`document.querySelector('.modal [data-scope-kind=units]').open=true;document.querySelector('.modal [data-scope-kind=cashier]').open=true;document.querySelector('.modal [data-scope-kind=units]').scrollIntoView({block:'center'});`);await shot('staff-'+locale+'-'+theme+'-'+width);
    await scopedSet(form,'role','POS Terminal');
    await run(`(()=>{const i=document.querySelector('.modal [name=business_unit_ids][value="${f.cases.restaurant.unit_id}"]');i.checked=true;i.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    check('Terminal one unit and matching counters '+locale+' '+theme+' '+width,await run(`document.querySelectorAll('.modal [name=business_unit_ids]:checked').length===1 && [...document.querySelectorAll('.modal [name=terminal_counter]:not(:disabled)')].every(i=>Number(i.closest('label').dataset.optionUnit)===${f.cases.restaurant.unit_id})`));
    await click('.modal [data-action=close-modal]');
   }
  }
 }
 await login(f.bar_manager,'/dashboard');await navigate('/dashboard#staff');await wait('!!document.querySelector("[data-action=add-user]")');await click('[data-action=add-user]');await wait('!!document.querySelector(".modal .scope-picker")');
 check('Bar Manager staff options exclude Restaurant',await run(`!document.querySelector('.modal form').innerText.includes(${JSON.stringify(f.cases.restaurant.unit_name)})`));await click('.modal [data-action=close-modal]');
 await login(f.cases.bar.waiter,'/dashboard');
 for(const path of ['/treasury','/cash-handovers','/audit-trail','/audit-trail?export=1'])check('Waiter direct access denied '+path,await run('fetch('+JSON.stringify(path)+',{headers:{Accept:"application/json"}}).then(r=>r.status)')===403);
 check('No runtime errors',errors.length===0,errors);check('No server errors',httpErrors.length===0,httpErrors);
 await fs.writeFile(output+'/result.json',JSON.stringify({checks,errors,httpErrors},null,2));
} finally {ws.close();}
