import fs from "node:fs/promises";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8884. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8884";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9224";
const paperWidth = process.env.SMOKE_PAPER_WIDTH || "80mm";
if (!["58mm", "80mm"].includes(paperWidth)) throw new Error("Unsupported test paper width");
const output = process.env.SMOKE_OUTPUT || `/tmp/nightshift-${paperWidth}-ticket-browser`;
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
// Compare money exactly: large decimal stress prices expose float rounding in tests.
function cents(value) {
    const text = String(value);
    const [whole, fraction = ""] = text.replace(/^-/, "").split(".");
    const amount = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, "0"));
    return text.startsWith("-") ? -amount : amount;
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
async function login() {
    await c("Network.clearBrowserCookies");
    await navigate("/login");
    await set("username", process.env.SMOKE_USERNAME || "alex-morgan-1");
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait('!!document.querySelector("#main h1")');
}
async function language(locale) {
    await run(
        `fetch('/settings/language',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({locale:${JSON.stringify(locale)}})}).then(r=>{if(!r.ok)throw Error('Language '+r.status)})`,
    );
}
try {
    const safety = await fetch(new URL("/login", base));
    check(
        "Dedicated MySQL target",
        safety.headers.get("X-Nightshift-Validation-Database") === "nightshift_validation",
    );
    await c("Runtime.enable");
    await c("Page.enable");
    await c("Network.enable");
    await c("Page.addScriptToEvaluateOnNewDocument", {
        source: "window.__printCalls=0;window.print=()=>{window.__printCalls++;};",
    });
    await login();
    await navigate("/dashboard#settings");
    await wait('!!document.querySelector("[name=auto_print]")');
    check(
        "Settings keep auto print and both copies enabled, with both paper options",
        await run(
            `['auto_print','waiter_copy','cashier_copy'].every(n=>document.querySelector('[name='+n+']').checked)&&document.querySelector('[name=number_of_copies]').value==='2'&&[...document.querySelector('[name=paper_width]').options].map(o=>o.value).join(',')==='58mm,80mm'`,
        ),
    );
    await set("paper_width", paperWidth);
    await click("[data-action=save-print-settings]");
    await wait(`!document.querySelector('[data-action=save-print-settings]').disabled`);
    check("Company paper width persisted", (await snap()).printSettings.paper_width === paperWidth);
    const initial = await snap(),
        asha = initial.servingWaiters.find((w) => w.name.startsWith("Asha"));
    let latest;
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        for (const theme of ["light", "dark"]) {
            for (const locale of ["en", "sw"]) {
                const tag = `${width}-${theme}-${locale}`;
                await navigate("/dashboard#settings");
                await wait('!!document.querySelector("[name=auto_print]")');
                await language(locale);
                await run(`window.NightshiftTheme.save(${JSON.stringify(theme)})`);
                await navigate("/dashboard#settings");
                await wait('!!document.querySelector("[name=auto_print]")');
                check(
                    `${tag} settings fit`,
                    await run("document.documentElement.scrollWidth<=innerWidth+1"),
                );
                await shot(`${tag}-settings`);
                await navigate("/dashboard#pos");
                await wait('!!document.querySelector(".pos-cart")');
                const before = await snap(),
                    product = before.products.find((p) => p.sku === "BR-001");
                await click(`[data-action=add-cart][data-id="${product.id}"]`);
                await click("[data-action=send-order]");
                await click(`[data-action=serving-waiter][data-id="${asha.id}"]`);
                if (width === 1440 && theme === "light" && locale === "en") {
                    await set("pin", "9999");
                    await run(
                        'document.querySelector("[data-form=serving-order]").requestSubmit()',
                    );
                    await wait(
                        '!document.querySelector("[data-form=serving-order] [type=submit]").disabled && document.querySelector("[name=pin]").value===""',
                    );
                    check(
                        "Failed PIN produces no ticket",
                        await run('!document.querySelector("#issue-ticket-frame")'),
                    );
                    check(
                        "Failed PIN produces no order",
                        (await snap()).orders.length === before.orders.length,
                    );
                }
                await set("pin", "0123");
                await submit("serving-order");
                await wait(
                    'document.querySelector("#issue-ticket-frame")?.contentWindow?.__printCalls===1',
                );
                const after = await snap(),
                    order = after.orders.find((o) => !before.orders.some((b) => b.id === o.id));
                latest = order;
                check(
                    `${tag} one stock issue and ledger debit`,
                    order.status === "Issued" &&
                        Number(after.products.find((p) => p.id === product.id).stock) ===
                            Number(product.stock) - 1 &&
                        cents(after.waiters.find((w) => w.id === asha.id).outstanding) ===
                            cents(before.waiters.find((w) => w.id === asha.id).outstanding) +
                                cents(order.total),
                );
                const info = await run(
                    `(()=>{const f=document.querySelector('#issue-ticket-frame'),d=f.contentDocument;return {copies:[...d.querySelectorAll('.copy-label')].map(n=>n.textContent),count:d.querySelectorAll('.ticket').length,background:f.contentWindow.getComputedStyle(d.body).backgroundColor,color:f.contentWindow.getComputedStyle(d.body).color,text:d.body.innerText,url:new URL(f.src).pathname,invoice:d.querySelector('.row strong').textContent,order:d.body.dataset.committedOrder,style:d.querySelector('#ticket-paper-size').textContent}})()`,
                );
                check(
                    `${tag} two correct labels`,
                    info.count === 2 &&
                        info.copies[0] ===
                            (locale === "sw" ? "NAKALA YA MHUDUMU" : "WAITER COPY") &&
                        info.copies[1] === (locale === "sw" ? "NAKALA YA KESHIA" : "CASHIER COPY"),
                );
                check(
                    `${tag} white background and black text`,
                    info.background === "rgb(255, 255, 255)" && info.color === "rgb(0, 0, 0)",
                );
                check(
                    `${tag} committed invoice and ${paperWidth}`,
                    info.order === String(order.id) && info.style.includes(paperWidth),
                );
                const claimed = await run(
                    `fetch(${JSON.stringify(info.url + "/dispatch")},{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content}}).then(r=>r.json())`,
                );
                check(`${tag} duplicate print claim suppressed`, claimed.print === false);
                await navigate(info.url);
                check(
                    `${tag} ticket fits viewport`,
                    await run("document.documentElement.scrollWidth<=innerWidth+1"),
                );
                check(
                    `${tag} amounts stay on one line`,
                    await run(
                        `[...document.querySelectorAll('.row>.amount')].every(el => {const text=document.createRange(); text.selectNodeContents(el); return text.getBoundingClientRect().height <= parseFloat(getComputedStyle(el).lineHeight) + 1;})`,
                    ),
                );
                check(
                    `${tag} long product name is complete`,
                    await run(
                        `document.querySelector('.ticket').textContent.includes(${JSON.stringify(product.name)})`,
                    ),
                );
                check(
                    `${tag} text remains inside printable content`,
                    await run(
                        `(() => {for (const article of document.querySelectorAll('.ticket')) {const box=article.getBoundingClientRect();const walker=document.createTreeWalker(article,NodeFilter.SHOW_TEXT);let node;while(node=walker.nextNode()){if(!node.textContent.trim())continue;const range=document.createRange();range.selectNodeContents(node);for(const r of range.getClientRects()){if(r.left<box.left-1||r.right>box.right+1)return false;}}}return true;})()`,
                    ),
                );
                if (paperWidth === "58mm")
                    check(
                        `${tag} stacked 48mm layout`,
                        await run(
                            `!document.querySelector('.ticket table') && Math.abs(document.querySelector('.ticket').getBoundingClientRect().width*25.4/96-48)<0.1 && getComputedStyle(document.querySelector('.outstanding')).display==='block'`,
                        ),
                    );
                await shot(`${tag}-ticket`);
                const pdf = await c("Page.printToPDF", {
                    printBackground: true,
                    preferCSSPageSize: true,
                    displayHeaderFooter: false,
                });
                await fs.writeFile(`${output}/${tag}.pdf`, Buffer.from(pdf.data, "base64"));
                check(`${tag} print PDF generated`, pdf.data.length > 1000);
            }
        }
    }
    await language("en");
    await navigate("/dashboard#orders");
    await wait('!!document.querySelector(".subtabs")');
    await click('[data-action=tab][data-value="All orders"]');
    await click(`[data-action=order-detail][data-id="${latest.id}"]`);
    await click(`[data-action=reprint-tickets][data-id="${latest.id}"]`);
    await wait('document.querySelector("#issue-ticket-frame")?.contentWindow?.__printCalls===1');
    check(
        "Authorized UI reprint has two REPRINT labels",
        await run(
            `document.querySelector('#issue-ticket-frame').contentDocument.querySelectorAll('.reprint').length===2`,
        ),
    );
    const reprintUrl = await run(
        'new URL(document.querySelector("#issue-ticket-frame").src).pathname',
    );
    await navigate(reprintUrl);
    check(
        "Reprint uses selected current paper width",
        await run(`document.body.dataset.paperWidth===${JSON.stringify(paperWidth)}`),
    );
    await shot("reprint-390");
    const pdf = await c("Page.printToPDF", {
        printBackground: true,
        preferCSSPageSize: true,
        displayHeaderFooter: false,
    });
    await fs.writeFile(`${output}/reprint.pdf`, Buffer.from(pdf.data, "base64"));
    if (paperWidth === "58mm") {
        for (const locale of ["en", "sw"]) {
            const html = await fs.readFile(
                `/tmp/nightshift-58mm-layout-fixtures/${locale}.html`,
                "utf8",
            );
            await navigate("about:blank");
            const tree = await c("Page.getFrameTree");
            await c("Page.setDocumentContent", { frameId: tree.frameTree.frame.id, html });
            await wait("document.querySelectorAll('.ticket').length===2");
            check(
                `${locale} largest amounts and fractions fit`,
                await run(
                    `(() => {for (const article of document.querySelectorAll('.ticket')) {const box=article.getBoundingClientRect();const walker=document.createTreeWalker(article,NodeFilter.SHOW_TEXT);let node;while(node=walker.nextNode()){if(!node.textContent.trim())continue;const range=document.createRange();range.selectNodeContents(node);for(const r of range.getClientRects()){if(r.left<box.left-1||r.right>box.right+1)return false;}}}return document.body.innerText.includes('9,999,999,999.99')&&document.body.innerText.includes('4 × Piece')&&document.body.innerText.includes('1.5 × Piece')&&document.body.innerText.includes('0.75 × Piece');})()`,
                ),
            );
            await shot(`stress-${locale}`);
            const stressPdf = await c("Page.printToPDF", {
                printBackground: true,
                preferCSSPageSize: true,
                displayHeaderFooter: false,
            });
            await fs.writeFile(
                `${output}/stress-${locale}.pdf`,
                Buffer.from(stressPdf.data, "base64"),
            );
        }
    }
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
            { result: "FAIL", checks, errors, httpErrors, failure: String(error) },
            null,
            2,
        ),
    );
    console.error(String(error));
    process.exitCode = 1;
} finally {
    ws.close();
    // Chrome may retain the debugging connection after the close handshake.
    setTimeout(() => process.exit(process.exitCode || 0), 100);
}
