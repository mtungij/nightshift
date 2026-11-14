import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-treasury-browser";
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
async function count(id) {
    await navigate('/shift-stock/' + id);
    await wait(`!!document.querySelector('#recon-count-form')`);
    await set('actual_cash', '650000');
    await run(`for(const row of document.querySelectorAll('[data-recon-row]')){const el=row.querySelector('input');el.value=row.dataset.expected;el.dispatchEvent(new Event('input',{bubbles:true}));}`);
    await submit('#recon-count-form');
    check('complete stock and cash reconciliation ' + id, (await report(id)).complete);
}
async function disposition(id, destination, recipient) {
    await scopedSet('#recon-close-form','destination',destination);
    await scopedSet('#recon-close-form','to_user_id',recipient);
    await scopedSet('#recon-close-form','notes','Cash and stock checked before custody transfer');
    await submit('#recon-close-form');
    return report(id);
}
async function spend(kind, f, type, amount, extra = {}) {
    const selector='form[data-custody-action=spend]';
    await scopedSet(selector,'transaction_type',type);
    await scopedSet(selector,'branch_id',f.branch_id);
    await scopedSet(selector,'amount',amount);
    await scopedSet(selector,'external_reference',kind+'-'+type+'-'+f.shift_id);
    await scopedSet(selector,'description','Authorized '+type+' physical cash use');
    for (const [name,value] of Object.entries(extra)) await scopedSet(selector,name,value);
    await submit(selector);
}
try {
    check('Isolated treasury MySQL browser database', (await fetch(base+'/login')).headers.get('X-Nightshift-Validation-Database')==='nightshift_treasury_browser_validation');
    await c('Page.enable'); await c('Runtime.enable'); await c('Network.enable');
    const fixtures=JSON.parse(await fs.readFile('/tmp/nightshift-treasury-fixtures.json','utf8'));
    for (const [kind,width,locale] of [['desktop',1440,'en'],['mobile',390,'sw']]) {
        const f={...fixtures.cases[kind],branch_id:fixtures.branch_id};
        await c('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});
        await login(f.cashier,'/dashboard',locale);
        const bootstrap=await run(`fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())`);
        check(kind+' normal cashier opening is zero',Number(bootstrap.shift.opening)===0);
        let closing=f.shift_id;
        if (kind==='desktop') {
            await count(closing);
            const old=await disposition(closing,'NEXT_CASHIER',f.next_id);
            check('cashier closes with pending next-cashier handover',old.status==='CLOSED'&&old.custody.status==='PENDING');
            const frozen=JSON.stringify({rows:old.rows,cash:old.cash,number:old.number,cash_disposition:old.cash_disposition});
            await login(f.next,'/open-shift',locale);
            await navigate('/open-shift');
            check('next cashier sees pending amount without manual opening cash input',await run(`document.body.innerText.includes('650000')&&!document.querySelector('[name=opening_cash]')`));
            await set('stock_location_id',f.counter_id);
            await run(`document.querySelector('[name=handover_id][value="${old.custody.id}"]').checked=true`);
            await submit('form[action$="/open-shift"]');
            await wait(`location.pathname==='/dashboard' && !!document.querySelector('#app')`);
            const next=await run(`fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(r=>r.json())`);
            closing=next.shift.id;
            check('confirmed handover automatically becomes next opening cash',Number(next.shift.opening)===650000&&next.location_id===f.counter_id);
            await login(fixtures.owner,'/dashboard',locale);
            const after=await report(f.shift_id);
            check('previous financial snapshot remains frozen after receipt',JSON.stringify({rows:after.rows.map(({cost,cost_variance_value,...r})=>r),cash:after.cash,number:after.number,cash_disposition:after.cash_disposition})===frozen);
            await login(f.next,'/dashboard',locale);
        }
        await count(closing);
        let staged=await disposition(closing,'TREASURY',f.accountant_id);
        const h=staged.custody;
        check(kind+' treasury handover waits for recipient confirmation',staged.status==='OPEN'&&h.status==='PENDING');
        check(kind+' final close is disabled before receipt',await run(`document.querySelector('#recon-close-form button').disabled`));
        await login(f.accountant,'/treasury',locale);
        await navigate('/treasury');
        check(kind+' accountant restricted treasury workspace',await run(`document.documentElement.lang===${JSON.stringify(locale)}&&document.body.innerText.includes(${JSON.stringify(locale==='sw'?'Hazina ya Cash':'Treasury')})`));
        check(kind+' receipt does not pre-credit an unclosed drawer',(await treasury()).balances.length===0);
        const receive=`form[data-custody-action=receive][data-id="${h.id}"]`;
        const amount=kind==='mobile'?'645000':'650000';
        await scopedSet(receive,'amount',amount);
        if (kind==='mobile') await scopedSet(receive,'note','Cash recounted and shortage reported to owner');
        await submit(receive);
        if (kind==='mobile') {
            check('disputed amount remains pending approval',(await treasury()).handovers.find(r=>r.id===h.id)?.status==='DISPUTED');
            await login(fixtures.owner,'/dashboard',locale);
            await navigate('/cash-handovers');
            const approve=`form[data-custody-action=approve][data-id="${h.id}"]`;
            await scopedSet(approve,'note','Owner verified and approved the physical cash difference');
            await submit(approve);
            check('manager approval preserves original handover amount',await run(`document.body.innerText.includes('650000.00')&&document.body.innerText.includes('-5000.00')`));
        }
        await login(kind==='desktop'?f.next:f.cashier,'/dashboard',locale);
        await navigate('/shift-stock/'+closing);
        await scopedSet('#recon-close-form','notes','Cash receipt confirmed and reconciliations reviewed');
        await submit('#recon-close-form');
        const finalized=await report(closing);
        check(kind+' final cashier close succeeds after confirmed custody',finalized.status==='CLOSED'&&finalized.custody.received_amount===amount+'.00');
        await shot(kind+'-closed-shift');
        const unauthorized=await run(`fetch('/api/cash-custody/treasury',{headers:{Accept:'application/json'}}).then(r=>r.status)`);
        check(kind+' cashier has no treasury access',unauthorized===403);
        await login(f.accountant,'/treasury',locale);
        await navigate('/treasury');
        let data=await treasury();
        check(kind+' treasury credits only the confirmed received amount',data.balances.length===1&&Number(data.balances[0].amount)===Number(amount));
        await spend(kind,f,'PURCHASE','200000',{goods_receipt_id:f.grn_id});
        await spend(kind,f,'EXPENSE','50000',{category:'Transport'});
        await spend(kind,f,'SALARY','150000',{employee_id:f.cashier_id,pay_period:'November 2026'});
        await spend(kind,f,'BANK_DEPOSIT','100000',{bank:'CRDB'});
        data=await treasury();
        const expected=Number(amount)-500000;
        check(kind+' purchase expense salary and bank deposit reduce custody',Number(data.balances[0].amount)===expected&&data.entries.length===5);
        check(kind+' each outflow retains business details',data.entries.some(e=>e.transaction_type==='PURCHASE'&&e.goods_receipt_id===f.grn_id)&&data.entries.some(e=>e.transaction_type==='SALARY'&&e.employee_id===f.cashier_id)&&data.entries.some(e=>e.transaction_type==='BANK_DEPOSIT'&&e.bank==='CRDB'));
        const form='form[data-custody-action=spend]';
        await scopedSet(form,'transaction_type','EXPENSE'); await scopedSet(form,'amount',String(expected+1)); await scopedSet(form,'external_reference','OVERDRAW-'+kind); await scopedSet(form,'description','Overdraw must be rejected');
        await run(`document.querySelector(${JSON.stringify(form)}).requestSubmit()`);
        await wait(`document.querySelector('[data-custody-message]').textContent.includes(${JSON.stringify(locale==='sw'?'haitoshi':'Insufficient')})`);
        check(kind+' overspending leaves ledger unchanged',(await treasury()).entries.length===5);
        check(kind+' mobile and desktop layout fits viewport',await run(`document.documentElement.scrollWidth<=innerWidth+1`));
        await shot(kind+'-treasury');
        for (const name of ['Cashier Handover','Treasury Ledger','Cash Received from Cashiers','Cash Used for Purchases','Expenses','Salaries and Wages','Bank Deposits','Cash Custody Balance']) {
            check(kind+' report '+name,await run(`fetch('/api/nightshift/reports/'+encodeURIComponent(${JSON.stringify(name)}),{headers:{Accept:'application/json'}}).then(r=>r.status===200)`));
        }
        await navigate('/treasury?print=1');
        const pdf=await c('Page.printToPDF',{preferCSSPageSize:true,printBackground:true,displayHeaderFooter:false});
        await fs.writeFile(output+'/'+kind+'-treasury.pdf',Buffer.from(pdf.data,'base64'));
        check(kind+' treasury PDF generated',pdf.data.length>1000);
        await login(fixtures.owner,'/dashboard',locale);
        await navigate('/shift-stock/'+closing+'/print');
        const shiftPdf=await c('Page.printToPDF',{preferCSSPageSize:true,printBackground:true,displayHeaderFooter:false});
        await fs.writeFile(output+'/'+kind+'-shift.pdf',Buffer.from(shiftPdf.data,'base64'));
        check(kind+' handover shift PDF generated',shiftPdf.data.length>1000);
        f.closed_shift_id=closing;
        fixtures.cases[kind]=f;
    }
    check('No browser runtime exceptions',errors.length===0,errors);
    check('No server HTTP 5xx',httpErrors.length===0,httpErrors);
    await fs.writeFile(output+'/result.json',JSON.stringify({result:'PASS',checks,errors,httpErrors,fixtures},null,2));
} catch(error) {
    console.error(error);
    await shot('failure');
    await fs.writeFile(output+'/result.json',JSON.stringify({result:'FAIL',error:String(error),checks,errors,httpErrors},null,2));
    process.exitCode=1;
} finally { ws.close(); }
