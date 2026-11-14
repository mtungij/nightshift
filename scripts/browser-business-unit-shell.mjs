import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-business-unit-shell";
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
 if ((await fetch(base+'/login')).headers.get('X-Nightshift-Validation-Database') !== 'nightshift_treasury_browser_validation') throw new Error('Use the isolated browser database.');
 await c('Page.enable'); await c('Runtime.enable'); await c('Network.enable');
 const f=JSON.parse(await fs.readFile('/tmp/nightshift-business-unit-fixtures.json','utf8'));
 await login(f.owner,'/dashboard'); await wait('!!document.querySelector(".sidebar")');
 await click('[data-nav="business-units"]'); await wait('location.pathname==="/business-units" && !!document.querySelector(".topbar")');
 check('Dashboard navigation retains workspace shell',await run('!!document.querySelector(".sidebar .workspace-switch") && !!document.querySelector(".profile-button") && !!document.querySelector(".nav-item.active[data-nav=business-units]")'));
 const sizes=[[390,844],[412,915],[820,1180],[1440,1000],[1920,1080]];
 for(const locale of ['en','sw']) {
  if(await run('document.documentElement.lang')!==locale){await run('window.__shellBeforeLanguage=true');await click('[data-language="'+locale+'"]');await wait('window.__shellBeforeLanguage===undefined && document.readyState==="complete" && document.documentElement.lang==="'+locale+'" && !!document.querySelector("#main .business-units-page")');}
  for(const theme of ['light','dark']) {
   await run('window.NightshiftTheme.apply('+JSON.stringify(theme)+')');
   await wait('document.documentElement.dataset.theme==="'+theme+'"');
   for(const [width,height] of sizes){
    await c('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<721});await sleep(350);
    const layout=await run(`(()=>{const p=document.querySelector('.business-units-page'),forms=[...p.querySelectorAll('form')],main=document.querySelector('#main').getBoundingClientRect();return {overflow:document.documentElement.scrollWidth>innerWidth,heading:p.querySelector('h1').innerText,width:p.getBoundingClientRect().width,formsFit:forms.every(f=>{const r=f.getBoundingClientRect();return r.left>=main.left&&r.right<=main.right+1&&[...f.querySelectorAll('input:not([type=hidden]),select,button,fieldset')].every(e=>{const a=e.getBoundingClientRect();return a.left>=r.left-1&&a.right<=r.right+1})}),controls:[...document.querySelectorAll('.topbar [data-language],.topbar [data-theme-toggle],.profile-button')].every(e=>{const r=e.getBoundingClientRect();return r.width>0&&r.left>=0&&r.right<=innerWidth})}})()`);
    check(locale+' '+theme+' '+width+'px content and controls fit',!layout.overflow&&layout.formsFit&&layout.controls&&layout.width<=1121,layout);
    if(width<721){
     await click('.mobile-menu');check(locale+' '+theme+' '+width+'px drawer opens',await run('document.querySelector(".sidebar").classList.contains("open")'));
     await c('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await c('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
     check(locale+' '+theme+' '+width+'px drawer closes',await run('!document.querySelector(".sidebar").classList.contains("open")'));
     check(locale+' '+theme+' '+width+'px create fields stack',await run(`(()=>{const a=[...document.querySelector('.unit-create').querySelectorAll('.form-field,button')].map(e=>e.getBoundingClientRect());return a.every((r,i)=>!i||r.top>=a[i-1].bottom)})()`));
    }else check(locale+' '+theme+' '+width+'px sidebar visible',await run('document.querySelector(".sidebar").getBoundingClientRect().left===0'));
    await shot(locale+'-'+theme+'-'+width);
   }
  }
 }
 await c('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
 await sleep(350);
 await run(`document.querySelector('form[data-business-unit-action="save"]:not(.unit-create)').scrollIntoView({block:'center'})`);
 check('Mobile existing unit controls remain reachable',await run(`(()=>{const f=document.querySelector('form[data-business-unit-action="save"]:not(.unit-create)');return [...f.querySelectorAll('input:not([type=hidden]),select,button')].every(e=>e.getBoundingClientRect().width>40)})()`));
 await shot('mobile-existing-units');
 await run(`document.querySelector('form[data-business-unit-action="location"]').scrollIntoView({block:'center'})`);
 check('Mobile location assignments use labelled checkboxes',await run(`(()=>{const f=document.querySelector('form[data-business-unit-action="location"]');return !!f.querySelector('fieldset legend')&&!!f.querySelector('label input[type=checkbox]')&&!f.querySelector('select[multiple]')&&f.querySelector('button').getBoundingClientRect().width>200})()`));
 await shot('mobile-location-assignment');
 await run('scrollTo(0,0)');
 await scopedSet('.unit-create','name' ,'Shell mobile '+Date.now());await scopedSet('.unit-create','code','SHELL_'+Date.now());
 const name=await run('document.querySelector(".unit-create [name=name]").value');
 await submit('.unit-create'); await wait('!!document.querySelector(".topbar")');
 check('Mobile create saves and appears in editable unit list',await run('[...document.querySelectorAll("form[data-business-unit-action=save]:not(.unit-create) [name=name]")].some(e=>e.value==='+JSON.stringify(name)+')'));
 await click('.business-units-page .page-heading a');await wait('location.pathname==="/dashboard" && !!document.querySelector(".topbar")');
 check('Dashboard button returns to normal dashboard',await run('!!document.querySelector("[data-nav=dashboard].active")'));
 await run('history.back()');await wait('location.pathname==="/business-units" && !!document.querySelector(".topbar")');
 check('Browser back restores authenticated unit page',await run('!!document.querySelector("[data-nav=business-units].active")'));
 await click('.mobile-menu');await click('[data-nav="orders"]');await wait('location.pathname==="/dashboard" && location.hash==="#orders" && !!document.querySelector(".topbar")');
 check('Shell sidebar navigates to Orders',await run('!!document.querySelector("[data-nav=orders].active")'));
 await login(f.bar_manager,'/dashboard');
 check('Unauthorized manager direct request stays denied',await run("fetch('/business-units',{headers:{Accept:'application/json'}}).then(r=>r.status)")===403);
 check('Unauthorized manager has no unit management navigation',await run('!document.querySelector("[data-nav=business-units]")'));
 check('No runtime errors',errors.length===0,errors);check('No server errors',httpErrors.length===0,httpErrors);
 await fs.writeFile(output+'/result.json',JSON.stringify({checks,errors,httpErrors},null,2));
} finally {ws.close();}
