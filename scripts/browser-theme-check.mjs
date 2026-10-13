import fs from 'node:fs/promises';

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || 'http://127.0.0.1:8884';
const debug = process.env.SMOKE_DEBUG_URL || 'http://127.0.0.1:9224';
const output = process.env.SMOKE_OUTPUT || '/tmp/nightshift-theme-browser';
await fs.mkdir(output, { recursive: true });
// This debugging port belongs to the isolated validation Chrome profile.
for(const tab of await (await fetch(`${debug}/json/list`)).json()){
    if(tab.type==='page') await fetch(`${debug}/json/close/${tab.id}`);
}
const target = await (await fetch(`${debug}/json/new?${encodeURIComponent('about:blank')}`, { method: 'PUT' })).json();
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
let seq = 0;
const pending = new Map(), errors = [], httpErrors = [], checks = [];
ws.addEventListener('message', e => {
    const m = JSON.parse(e.data);
    if (m.id) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result); }
    if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
    if (m.method === 'Network.responseReceived' && m.params.response.url.startsWith(base) && m.params.response.status >= 500) httpErrors.push({ url: m.params.response.url, status: m.params.response.status });
});
const c = (method, params = {}) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params })); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function run(expression) {
    const r = await c('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
    return r.result.value;
}
function check(name, ok, detail) {
    checks.push({ name, result: ok ? 'PASS' : 'FAIL', ...(detail === undefined ? {} : { detail }) });
    console.log(`${ok ? 'PASS' : 'FAIL'} ${name}`);
    if (!ok) throw new Error(`${name}: ${JSON.stringify(detail)}`);
}
async function wait(expression) {
    const until = Date.now() + 20000;
    while (Date.now() < until) { try { if (await run(expression)) return; } catch {} await sleep(200); }
    throw new Error(`Timed out: ${expression}; modal/toast=${await run('JSON.stringify({modal:document.querySelector(".modal")?.innerText,toast:document.querySelector("#toast")?.innerText})')}; page=${await run('document.body.innerText.slice(0,800)')}`);
}
const click = selector => run(`(() => { const e=document.querySelector(${JSON.stringify(selector)}); if(!e)throw Error('Missing '+${JSON.stringify(selector)}); e.click(); })()`);
const set = (name, value) => run(`(() => { const e=document.querySelector(${JSON.stringify(`[name="${name}"]`)}); e.value=${JSON.stringify(String(value))}; e.dispatchEvent(new Event('input',{bubbles:true})); e.dispatchEvent(new Event('change',{bubbles:true})); })()`);
const snap = () => run(`fetch('/api/nightshift/bootstrap',{headers:{Accept:'application/json'}}).then(async r=>{if(!r.ok)throw Error('Bootstrap '+r.status);return r.json()})`);
const refresh = async () => {
    await run('window.__smokePreviousDocument=true');
    await c('Page.navigate', {url:`${base}/dashboard?smoke_reload=${Date.now()}#dashboard`});
    await wait('window.__smokePreviousDocument===undefined && !!document.querySelector(".kpi-grid")');
};
async function submit(type) { await run(`document.querySelector('[data-form="${type}"]').requestSubmit()`); await wait('!document.querySelector(".modal-backdrop")'); }
async function api(command, payload) {
    return run(`(async()=>{const r=await fetch('/api/nightshift/commands/'+${JSON.stringify(command)},{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({...${JSON.stringify(payload)},idempotency_key:crypto.randomUUID()})});return {status:r.status,body:await r.json()};})()`);
}
async function shot(name) { await sleep(350); const r = await c('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); await fs.writeFile(`${output}/${name}.png`, Buffer.from(r.data, 'base64')); }
try {
    await c('Runtime.enable'); await c('Page.enable'); await c('Network.enable');
    await c('Emulation.setTimezoneOverride',{timezoneId:'Africa/Dar_es_Salaam'});
    await c('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'dark'}]});
    await c('Page.addScriptToEvaluateOnNewDocument',{source:`
      localStorage.setItem('flux.appearance','dark');
      window.__themeHistory=[];
      new MutationObserver(()=>{const theme=document.documentElement?.dataset.theme;if(theme)window.__themeHistory.push(theme)}).observe(document,{subtree:true,attributes:true,childList:true});
    `});
    async function navigate(url) {
        await run('window.__previousThemeDocument=true');
        await c('Page.navigate',{url:url+(url.includes('?')?'&':'?')+'theme_check='+Date.now()});
        await wait('window.__previousThemeDocument===undefined && document.readyState==="complete"');
    }
    async function login(email) {
        await c('Network.clearBrowserCookies'); await navigate(`${base}/login`);
        await wait('!!document.querySelector("[name=email]")');
        check('Guest defaults to light despite OS/browser dark preference',await run('document.documentElement.dataset.theme==="light" && !window.__themeHistory.includes("dark")'));
        await set('email',email);await set('password','Nightshift!2026');await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);await wait('!!document.querySelector("#main h1") && !!document.querySelector("[data-theme-toggle]")');
    }
    await c('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await login('admin@nightshift.test');
    check('Account loads light without a dark flash',await run('document.documentElement.dataset.theme==="light" && !window.__themeHistory.includes("dark")'));
    const identity=await run(`fetch('/api/nightshift/bootstrap').then(r=>r.headers.get('X-Nightshift-Validation-Database'))`);
    check('Visual checks use verified MySQL environment',identity==='nightshift_validation');
    const views=['dashboard','pos','orders','waiters','cashiers','tables','inventory','shifts','payments','suppliers','reports','approvals','users','branches','counters','settings','audit','notifications'];
    const colors=[];
    async function choose(theme){
        if(await run(`document.documentElement.dataset.theme!==${JSON.stringify(theme)}`)){
            await click('[data-theme-toggle]');
            await wait(`fetch('/dashboard').then(r=>r.text()).then(t=>t.includes('data-theme="${theme}"'))`);
        }
        check(`${theme} header toggle applies`,await run(`document.documentElement.dataset.theme==='${theme}'`));
    }
    for(const theme of ['light','dark']){
        await choose(theme);
        for(const view of views){
            await click(`[data-nav="${view}"]`);
            check(`${theme} desktop ${view}`,await run('!!document.querySelector("h1")?.innerText.length && document.documentElement.scrollWidth<=1440'));
            if(['dashboard','pos','orders','waiters','cashiers','tables','inventory','shifts','reports','approvals'].includes(view))await shot(`${theme}-desktop-${view}`);
        }
        colors.push(await run(`(()=>{const b=getComputedStyle(document.body),p=getComputedStyle(document.querySelector('.panel')||document.body);return {theme:'${theme}',background:b.backgroundColor,text:b.color,panel:p.backgroundColor}})()`));
        await click('[data-nav="pos"]'); await click('[data-action="add-cart"]');await shot(`${theme}-desktop-pos-cart`);
        await click('[data-nav="inventory"]');await click('[data-action="tab"][data-value="Goods receipt"]');await click('[data-action="grn"]');
        check(`${theme} dropdown/modal/input contrast`,await run(`(()=>{const m=getComputedStyle(document.querySelector('.modal')),i=getComputedStyle(document.querySelector('.form-field input'));return m.backgroundColor!==m.color && i.backgroundColor!==i.color})()`));
        await shot(`${theme}-grn-modal`);await click('[data-action="close-modal"]');
        await click('[data-nav="reports"]');await click('[data-action="report"][data-value="Sales by Category"]');await wait('document.querySelector("#report-content")?.innerText.includes("Beer")');
        await shot(`${theme}-report-modal`);
        await c('Emulation.setEmulatedMedia',{media:'print'});
        check(`${theme} reports print white with dark text`,await run(`(()=>{const b=getComputedStyle(document.body),m=getComputedStyle(document.querySelector('.modal'));return b.backgroundColor==='rgb(255, 255, 255)'&&m.backgroundColor==='rgb(255, 255, 255)'&&m.color==='rgb(17, 17, 17)'})()`));
        const pdf=await c('Page.printToPDF',{printBackground:true,preferCSSPageSize:true});await fs.writeFile(`${output}/${theme}-report.pdf`,Buffer.from(pdf.data,'base64'));
        await c('Emulation.setEmulatedMedia',{media:'',features:[{name:'prefers-color-scheme',value:'dark'}]});await click('[data-action="close-modal"]');
        await c('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        for(const view of views){
            await click('[data-action="menu"]');await click(`[data-nav="${view}"]`);
            check(`${theme} mobile ${view}`,await run('!!document.querySelector("h1")?.innerText.length && document.documentElement.scrollWidth<=390'));
            if(['dashboard','pos','orders','waiters','cashiers','tables','inventory','shifts','reports','approvals'].includes(view))await shot(`${theme}-mobile-${view}`);
        }
        await click('[data-theme-toggle]');
        const opposite=theme==='dark'?'light':'dark';await wait(`fetch('/dashboard').then(r=>r.text()).then(t=>t.includes('data-theme="${opposite}"'))`);
        check(`${theme} mobile theme toggle is usable`,await run('document.querySelector("[data-theme-toggle]").getBoundingClientRect().right<=390'));
        await click('[data-theme-toggle]');await wait(`fetch('/dashboard').then(r=>r.text()).then(t=>t.includes('data-theme="${theme}"'))`);
        await c('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await navigate(`${base}/dashboard`);await wait('!!document.querySelector(".kpi-grid")');
        check(`${theme} saved preference survives reload without wrong-theme flash`,await run(`document.documentElement.dataset.theme==='${theme}' && window.__themeHistory.every(t=>t==='${theme}')`));
    }
    await navigate(`${base}/settings/appearance`);await wait('!!document.querySelector("[data-theme-choice=light]")');
    await click('[data-theme-choice="light"]');await wait('document.querySelector("[data-theme-status]")?.innerText.includes("saved")');
    await wait(`fetch('/dashboard').then(r=>r.text()).then(t=>t.includes('data-theme="light"'))`);
    check('Appearance settings saves account light preference',await run('document.documentElement.dataset.theme==="light"'));
    await click('[data-theme-choice="dark"]');await wait(`fetch('/dashboard').then(r=>r.text()).then(t=>t.includes('data-theme="dark"'))`);
    await navigate(`${base}/dashboard`);await wait('!!document.querySelector(".kpi-grid")');check('Settings dark preference reaches workspace',await run('document.documentElement.dataset.theme==="dark"'));
    const order=(await snap()).orders.find(o=>o.status!=='Waiting for issue');
    await navigate(`${base}/api/nightshift/receipts/${order.id}`);
    check('80mm receipt stays light regardless of account dark theme',await run(`(()=>{const s=getComputedStyle(document.body);return s.backgroundColor==='rgb(255, 255, 255)'&&s.color==='rgb(0, 0, 0)'&&s.width.startsWith('272')&&document.documentElement.innerHTML.includes('80mm')})()`));
    await shot('receipt-screen');
    const receiptPdf=await c('Page.printToPDF',{printBackground:true,paperWidth:80/25.4,paperHeight:200/25.4,marginTop:0,marginBottom:0,marginLeft:0,marginRight:0});await fs.writeFile(`${output}/receipt-80mm.pdf`,Buffer.from(receiptPdf.data,'base64'));
    await login('manager@nightshift.test');check('Different account does not inherit owner dark preference',await run('document.documentElement.dataset.theme==="light"'));
    await login('admin@nightshift.test');check('Owner preference persists across logout/login',await run('document.documentElement.dataset.theme==="dark"'));
    // Leave the demo account on the requested default appearance.
    await run('window.NightshiftTheme.save("light")');
    // A failed save must restore the rendered theme and release the controls.
    const rollback = await run(`(async () => {
        const originalFetch = window.fetch;
        window.fetch = (...args) => String(args[0]).endsWith('/settings/theme')
            ? Promise.reject(new Error('Simulated theme save failure')) : originalFetch(...args);
        try { await window.NightshiftTheme.save('dark'); return false; }
        catch { return document.documentElement.dataset.theme === 'light'
            && localStorage.getItem('flux.appearance') === 'light'
            && !document.querySelector('[data-theme-toggle]').disabled; }
        finally { window.fetch = originalFetch; }
    })()`);
    check('Failed preference save restores theme and enables retry', rollback);
    for (const [account, role] of [['cashier', 'Cashier'], ['asha', 'Waiter']]) {
        await login(`${account}@nightshift.test`);
        check(`${role} account starts light`, await run('document.documentElement.dataset.theme === "light"'));
        for (const theme of ['light', 'dark']) {
            await run(`window.NightshiftTheme.save(${JSON.stringify(theme)})`);
            for (const width of [1440, 390, 320]) {
                await c('Emulation.setDeviceMetricsOverride', {width, height: width === 1440 ? 1000 : 844, deviceScaleFactor: 1, mobile: width !== 1440});
                await click('[data-nav="pos"]');
                check(`${role} ${theme} POS at ${width}px`, await run(`document.documentElement.scrollWidth <= ${width}`));
                check(`${role} ${theme} toggle can be tapped at ${width}px`, await run(`(() => {
                    const button = document.querySelector('[data-theme-toggle]');
                    const rect = button.getBoundingClientRect();
                    return rect.left >= 0 && rect.right <= ${width}
                        && button.contains(document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2));
                })()`));
                await shot(`${account}-${theme}-${width}-pos`);
            }
            await navigate(`${base}/dashboard`); await wait('!!document.querySelector("#main h1")');
            check(`${role} ${theme} refresh has no wrong-theme flash`, await run(`document.documentElement.dataset.theme === '${theme}' && window.__themeHistory.every(t => t === '${theme}')`));
        }
        await login(`${account}@nightshift.test`);
        check(`${role} dark preference survives new login`, await run('document.documentElement.dataset.theme === "dark"'));
        await run('window.NightshiftTheme.save("light")');
    }
    check('No application browser exceptions',errors.length===0,errors);check('No HTTP 5xx responses',httpErrors.length===0,httpErrors);
    await fs.writeFile(`${output}/result.json`,JSON.stringify({result:'PASS',base,checks,colors,errors,httpErrors},null,2));
} catch(error){
    await fs.writeFile(`${output}/result.json`,JSON.stringify({result:'FAIL',base,checks,errors,httpErrors,failure:String(error)},null,2));console.error(String(error));process.exitCode=1;
} finally{ws.close();}
