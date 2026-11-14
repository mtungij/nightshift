import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-admin-ui-staff";
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
 await login(f.owner,'/dashboard');await wait('!!document.querySelector(".sidebar")');
 for(const [screen,path] of [['business-units','/business-units'],['treasury','/treasury'],['cash-handovers','/cash-handovers'],['audit','/audit-trail']]){
  await click('[data-nav="'+screen+'"]');await wait('location.pathname==="'+path+'" && !!document.querySelector("#main [data-workspace-page]")');
  check('Sidebar route '+screen,await run('!!document.querySelector(".nav-item.active[data-nav='+screen+']")'));
 }
 await navigate('/dashboard#staff');await wait('!!document.querySelector("[data-action=add-user]")');
 await c('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
 await click('[data-action=add-user]');await wait('!!document.querySelector(".modal .scope-picker")');
 const form='.modal form';
 const options=await run(`fetch('/api/nightshift/staff-assignment-options',{headers:{Accept:'application/json'}}).then(r=>r.json())`);
 const empty=options.units.find(u=>!options.counters.some(c=>c.business_unit_id===u.id));
 if(!empty)throw Error('Expected validation unit without counters.');
 await scopedSet(form,'branch',empty.branch_id);
 await run(`(()=>{const i=document.querySelector('.modal [name=business_unit_ids][value="${empty.id}"]');i.checked=true;i.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 check('Branch change removes other branch units',await run(`Array.from(document.querySelectorAll('.modal [name=business_unit_ids]:not(:disabled)')).every(i=>Number(i.closest('label').dataset.optionBranch)===${empty.branch_id})`));
 check('Unit without counters has clear empty state',await run(`!document.querySelector('.modal [name=cashier_counter_ids]:not(:disabled)')&&!document.querySelector('.modal [data-scope-kind=cashier] [data-scope-empty]').hidden`));
 await scopedSet(form,'branch',f.branch_id);
 for(const id of [f.cases.bar.unit_id,f.cases.restaurant.unit_id])await run(`(()=>{const i=document.querySelector('.modal [name=business_unit_ids][value="${id}"]');i.checked=true;i.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 const actual=await run(`Array.from(document.querySelectorAll('.modal [name=cashier_counter_ids]:not(:disabled)')).map(i=>Number(i.value))`);
 check('Multiple units combine authorized counters',actual.includes(f.cases.bar.counter_id)&&actual.includes(f.cases.restaurant.counter_id));
 for(const id of [f.cases.bar.counter_id,f.cases.restaurant.counter_id])await run(`(()=>{const i=document.querySelector('.modal [name=cashier_counter_ids][value="${id}"]');i.checked=true;i.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 const username='ui-cashier-'+Date.now();
 for(const [name,value] of Object.entries({name:'UI scoped cashier',username,email:username+'@validation.test',phone:'',pin:'0123'}))await scopedSet(form,name,value);
 await run('document.querySelector(".modal form").requestSubmit()');await wait('!document.querySelector(".modal")');
 const team=await run(`fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())`);const user=team.team.find(u=>u.username===username);
 check('Mobile staff create succeeds',!!user,user);
 await navigate('/business-units');await wait('!!document.querySelector(".topbar")');
 const assigned='form[data-business-unit-action=staff]:has(input[name=id][value="'+user.id+'"])';
 check('Saved staff has both units and explicit counters',await run(`document.querySelector(${JSON.stringify(assigned)}).querySelectorAll('[name="unit_ids[]"]:checked').length===2&&document.querySelector(${JSON.stringify(assigned)}).querySelectorAll('[name="counter_ids[]"]:checked').length===2`));

 const extra=options.units.find(u=>u.branch_id!==f.branch_id);
 if(!extra)throw Error('Expected second branch fixture.');
 await run(`(()=>{const form=document.querySelector(${JSON.stringify(assigned)});const b=form.querySelector('[name="branch_ids[]"][value="${extra.branch_id}"]');b.checked=true;b.dispatchEvent(new Event('change',{bubbles:true}));const u=form.querySelector('[name="unit_ids[]"][value="${extra.id}"]');if(!u||u.disabled)throw Error('New branch unit unavailable');u.checked=true;u.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 check('Edit branch selection reveals its units immediately',await run(`!document.querySelector(${JSON.stringify(assigned)}).querySelector('[name="unit_ids[]"][value="${extra.id}"]').disabled`));
 await submit(assigned);await wait('!!document.querySelector(".topbar")');
 check('Edit saves branch and unit together',await run(`document.querySelector(${JSON.stringify(assigned)}).querySelector('[name="unit_ids[]"][value="${extra.id}"]').checked`));
 await run(`(()=>{const form=document.querySelector(${JSON.stringify(assigned)});const b=form.querySelector('[name="branch_ids[]"][value="${extra.branch_id}"]');b.checked=false;b.dispatchEvent(new Event('change',{bubbles:true}));})()`);await submit(assigned);await wait('!!document.querySelector(".topbar")');
 check('Edit restores original branch and counters',await run(`document.querySelector(${JSON.stringify(assigned)}).querySelectorAll('[name="branch_ids[]"]:checked').length===1&&document.querySelector(${JSON.stringify(assigned)}).querySelectorAll('[name="counter_ids[]"]:checked').length===2`));
 // A deliberate empty assignment remains possible; do not submit this preview.
 await run(`(()=>{const form=document.querySelector(${JSON.stringify(assigned)});for(const i of form.querySelectorAll('[name="unit_ids[]"]'))i.checked=false;form.querySelector('[name="unit_ids[]"]').dispatchEvent(new Event('change',{bubbles:true}));})()`);
 check('Edit supports clearing assignments without silently selecting a unit',await run(`document.querySelector(${JSON.stringify(assigned)}).querySelectorAll('[name="unit_ids[]"]:checked').length===0`));
 await shot('mobile-staff-saved');
 check('No runtime errors',errors.length===0,errors);check('No server errors',httpErrors.length===0,httpErrors);
 await fs.writeFile(output+'/result.json',JSON.stringify({checks,errors,httpErrors,created_user:user.id},null,2));
} finally {ws.close();}
