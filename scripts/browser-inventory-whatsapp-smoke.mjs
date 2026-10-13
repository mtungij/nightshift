import fs from "node:fs/promises";
import { execFileSync } from "node:child_process";

// Start a separate Chrome with --remote-debugging-port=9224 and the MySQL
// application on port 8885. This runner modifies only its validation fixtures.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8893";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9233";
const output =
    process.env.SMOKE_OUTPUT ||
    "/home/james/Downloads/smartcounter/storage/framework/testing/ux-browser-evidence";
await fs.mkdir(output, { recursive: true });
// This debugging port belongs to the isolated validation Chrome profile.
for (const tab of await (await fetch(`${debug}/json/list`)).json()) {
    if (tab.type === "page") await fetch(`${debug}/json/close/${tab.id}`);
}
const target = await (
    await fetch(`${debug}/json/new?${encodeURIComponent("about:blank")}`, { method: "PUT" })
).json();
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
        m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result);
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
const c = (method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++seq;
        pending.set(id, { resolve, reject });
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
    await sleep(350);
    const r = await c("Page.captureScreenshot", { format: "png", captureBeyondViewport: false });
    await fs.writeFile(`${output}/${name}.png`, Buffer.from(r.data, "base64"));
}
try {
    await c("Runtime.enable");
    await c("Page.enable");
    await c("Network.enable");
    await c("Network.clearBrowserCookies");
    await c("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await c("Page.navigate", { url: `${base}/login` });
    await wait('!!document.querySelector("[name=username]")');
    await set("username", "ux-browser-owner");
    await set("pin", "0123");
    await run(`document.querySelector('form[action$="/login"]').requestSubmit()`);
    await wait('!!document.querySelector("#main h1")');
    check("Desktop PIN login", true);
    if (await run('document.documentElement.lang!=="en"')) {
        await click('[data-language="en"]');
        await wait('document.documentElement.lang==="en"');
    }
    await click('[data-nav="inventory"]');
    await click('[data-action="inventory-add"]');
    check(
        "Optional SKU, hidden brand, optional image upload",
        await run(
            `!document.querySelector('[name=sku]').required && !document.querySelector('[name=brand]') && document.querySelector('[name=image]').type==='file' && !document.querySelector('[name=image]').required && document.querySelector('[name=image]').accept.includes('.webp')`,
        ),
    );
    const suffix = Date.now();
    const withoutImage = `UX ${suffix} no image`;
    await set("name", withoutImage);
    await set("cost", "2000");
    await set("price", "3000");
    await submit("product");
    let db = await snap();
    let first = db.products.find((p) => p.name === withoutImage);
    check("Product created without SKU or image", first?.sku.startsWith("NS-") && !first.image);
    await click('[data-action="inventory-add"]');
    const withImage = `UX ${suffix} image`;
    await set("name", withImage);
    await set("cost", "2000");
    await set("price", "3000");
    await run(`(() => {
        const bytes=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='),x=>x.charCodeAt(0));
        const transfer=new DataTransfer();transfer.items.add(new File([bytes],'product.png',{type:'image/png'}));
        const input=document.querySelector('[name=image]');input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));
    })()`);
    await wait('document.querySelector(".product-image-preview img")?.naturalWidth > 0');
    check("Upload preview loads", true);
    await shot("desktop-product-upload");
    await submit("product");
    db = await snap();
    const second = db.products.find((p) => p.name === withImage);
    check("Generated SKUs are unique", second?.sku && second.sku !== first.sku);
    check(
        "Uploaded image uses Laravel route",
        second?.image.includes(`/products/${second.id}/image`),
    );
    check(
        "Stored product image loads",
        await run(
            `fetch(${JSON.stringify(second.image)}).then(r=>r.ok && r.headers.get('content-type').startsWith('image/png'))`,
        ),
    );
    await click('[data-action="tab"][data-value="Goods receipt"]');
    await click('[data-action="grn"]');
    check(
        "Supplier invoice is optional",
        await run('!document.querySelector("[name=reference]").required'),
    );
    await set("product", second.id);
    await set("quantity", "1.5");
    await set("cost", "2000");
    await submit("grn");
    await click('[data-action="grn"]');
    await set("product", second.id);
    await set("quantity", "1");
    await set("cost", "2000");
    await submit("grn");
    db = await snap();
    const receipts = db.movements.filter((m) => m.product === withImage && m.type === "GRN");
    check(
        "Multiple receipts without invoice preserve unique GRNs",
        receipts.length === 2 && receipts[0].ref !== receipts[1].ref,
    );
    check(
        "Stock posting preserves fraction",
        Number(db.products.find((p) => p.id === second.id).stock) === 2.5,
    );
    await click('[data-action="tab"][data-value="Stock Movement"]');
    check(
        "Stock Movement shows users and date/time",
        await run(
            'document.querySelector("#main").innerText.includes("User") && document.querySelector("#main").innerText.includes("Alex Morgan") && document.querySelector("#main").innerText.includes("Date/Time")',
        ),
    );
    check(
        "Quantity display preserves fractions without decimal zeros",
        await run(
            'document.querySelector("#main").innerText.includes("1.5") && !/\\b\\d+\\.0{4,6}\\b/.test(document.querySelector("#main").innerText)',
        ),
    );
    await shot("desktop-stock-movement");
    await click('[data-nav="suppliers"]');
    await click('[data-action="add-supplier"]');
    check(
        "Supplier categories dropdown",
        await run(
            'document.querySelector("[name=category]").tagName === "SELECT" && [...document.querySelector("[name=category]").options].some(o=>o.value==="Beer & Cider")',
        ),
    );
    await set("name", `UX supplier ${suffix}`);
    await set("contact", "0629123456");
    await submit("add-supplier");
    await click('[data-nav="pos"]');
    await wait('!!document.querySelector(".product-grid")');
    await run(
        `document.querySelector('.product-photo[src*="/products/${second.id}/image"]').scrollIntoView({block:"center"})`,
    );
    await wait(
        `document.querySelector('.product-photo[src*="/products/${second.id}/image"]').naturalWidth > 0`,
    );
    check(
        "POS product images load",
        await run(
            'Array.from(document.querySelectorAll(".product-photo")).some(i=>i.naturalWidth > 0)',
        ),
    );
    await shot("desktop-pos-images");
    await c("Page.navigate", { url: `${base}/settings/whatsapp` });
    await wait('document.readyState==="complete" && !!document.querySelector(".whatsapp-page")');
    check(
        "WhatsApp overview is company scoped",
        await run(
            'document.body.innerText.includes("browser-company-device") && !document.body.innerText.includes("browser-fake-password")',
        ),
    );
    await click('[data-whatsapp-tab="connection"]');
    await click('[data-whatsapp-connection="status"]');
    await wait(
        'document.querySelector(".whatsapp-page").getAttribute("aria-busy")!=="true" && document.querySelector("[data-whatsapp-jid]").textContent.includes("255629123456")',
    );
    await click('[data-whatsapp-connection="qr"]');
    await wait('document.querySelector("#whatsapp-link-result img")?.naturalWidth > 0');
    check("Backend QR proxy flow", true);
    await set("phone", "0629123456");
    await run(
        'document.querySelector("[data-whatsapp-form=pairing]").requestSubmit(document.querySelector("[data-whatsapp-form=pairing] button"))',
    );
    await wait(
        'document.querySelector(".whatsapp-page").getAttribute("aria-busy")!=="true" && document.querySelector("#whatsapp-link-result").textContent.includes("ABCD-1234")',
    );
    check("Pairing code flow", true);
    await shot("desktop-whatsapp-connection");
    await click('[data-whatsapp-tab="recipients"]');
    await set("name", `UX owner ${suffix}`);
    await run(
        'document.querySelector("[data-whatsapp-form=recipient] [name=phone]").value="0629123456"',
    );
    await set("label", "Owner");
    await run("window.__recipientSaveOld=true");
    await run(
        'document.querySelector("[name=all_branches]").checked=true; document.querySelector("[data-whatsapp-form=recipient]").requestSubmit(document.querySelector("[data-whatsapp-form=recipient] button"))',
    );
    await wait(
        'window.__recipientSaveOld===undefined && document.readyState==="complete" && document.querySelector(".whatsapp-recipient")?.textContent.includes("255629123456")',
    );
    check("Recipient created and normalized", true);
    await shot("desktop-whatsapp-recipients");
    await click('[data-whatsapp-tab="overview"]');
    await click('[data-whatsapp-connection="status"]');
    await wait(
        'document.querySelector(".whatsapp-page").getAttribute("aria-busy")!=="true" && document.querySelector("[data-whatsapp-status]").textContent === "Connected"',
    );
    await run(
        'document.querySelector("[data-whatsapp-form=send]").requestSubmit(document.querySelector("[data-whatsapp-form=send] button[value=test]"))',
    );
    await wait(
        'document.querySelector(".whatsapp-page").getAttribute("aria-busy")!=="true" && document.querySelector("#whatsapp-notice").textContent.includes("queued")',
    );
    check("Test message queues asynchronously", true);
    await run(
        'document.querySelector("[data-whatsapp-form=send]").requestSubmit(document.querySelector("[data-whatsapp-form=send] button[value=report]"))',
    );
    await wait(
        'document.querySelector(".whatsapp-page").getAttribute("aria-busy")!=="true" && document.querySelector("#whatsapp-notice").textContent.includes("queued")',
    );
    execFileSync("php", ["scripts/inventory-whatsapp-browser-queue.php"], {
        env: {
            ...process.env,
            APP_ENV: "testing",
            DB_CONNECTION: "sqlite",
            DB_DATABASE: `${process.cwd()}/storage/framework/testing/nightshift-ux-browser.sqlite`,
            DB_URL: "",
        },
        stdio: "pipe",
    });
    await c("Page.navigate", { url: `${base}/settings/whatsapp#logs` });
    await wait('document.readyState==="complete" && !!document.querySelector(".whatsapp-page")');
    await click('[data-whatsapp-tab="logs"]');
    check(
        "Queue worker writes successful delivery logs",
        await run(
            'document.querySelector("#whatsapp-logs").innerText.includes("SENT") && document.querySelector("#whatsapp-logs").innerText.includes("BROWSER-FAKE-MESSAGE")',
        ),
    );
    await shot("desktop-whatsapp-logs");
    for (const width of [1440, 390]) {
        await c("Emulation.setDeviceMetricsOverride", {
            width,
            height: width === 390 ? 844 : 1000,
            deviceScaleFactor: 1,
            mobile: width === 390,
        });
        for (const locale of ["en", "sw"]) {
            await run("window.__languageOld=true");
            await run(
                `(() => { const f = document.querySelector('.language-switch [name=locale][value=${locale}]').form; f.requestSubmit(); })()`,
            );
            await wait(
                `window.__languageOld===undefined && document.readyState==="complete" && document.documentElement.lang===${JSON.stringify(locale)} && !!document.querySelector('.whatsapp-page')`,
            );
            for (const theme of ["light", "dark"]) {
                await run(`window.NightshiftTheme.save(${JSON.stringify(theme)})`);
                await wait(`document.documentElement.dataset.theme===${JSON.stringify(theme)}`);
                for (const tab of [
                    "overview",
                    "connection",
                    "recipients",
                    "reports",
                    "alerts",
                    "logs",
                ]) {
                    await click(`[data-whatsapp-tab="${tab}"]`);
                    check(
                        `${width}px ${locale} ${theme} ${tab} fits viewport`,
                        await run("document.documentElement.scrollWidth<=innerWidth+1"),
                    );
                    await shot(`${width}-${locale}-${theme}-${tab}`);
                }
            }
        }
    }

    await c("Page.navigate", { url: `${base}/dashboard#inventory` });
    await wait(
        '!!document.querySelector("#main h1") && !!document.querySelector("[data-action=inventory-add]")',
    );
    check(
        "Mobile inventory fits viewport",
        await run("document.documentElement.scrollWidth<=innerWidth+1"),
    );
    await click('[data-action="inventory-add"]');
    check(
        "Mobile product form has optional SKU and image",
        await run(
            '!document.querySelector("[name=sku]").required && !document.querySelector("[name=image]").required && !document.querySelector("[name=brand]")',
        ),
    );
    await shot("mobile-sw-dark-product-form");
    await run('document.querySelector(".modal-backdrop").remove()');
    await click('[data-action="tab"][data-value="Stock Movement"]');
    check(
        "Mobile Stock Movement has posting user",
        await run('document.querySelector("#main").innerText.includes("Alex Morgan")'),
    );
    await shot("mobile-sw-dark-stock-movement");
    await click('[data-nav="pos"]');
    await wait('!!document.querySelector(".product-grid")');
    check(
        "Mobile POS fits viewport",
        await run("document.documentElement.scrollWidth<=innerWidth+1"),
    );
    await shot("mobile-sw-dark-pos");
    check("No browser JavaScript exceptions", errors.length === 0, errors);
    check("No application HTTP 500 responses", httpErrors.length === 0, httpErrors);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify(
            { result: "PASS", checks, errors, httpErrors, provider: "HTTP fakes only" },
            null,
            2,
        ),
    );
    console.log(`PASS ${checks.length} browser checks`);
} catch (error) {
    await shot("failure").catch(() => {});
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify(
            { result: "FAIL", checks, error: error.message, errors, httpErrors },
            null,
            2,
        ),
    );
    console.error(error);
    process.exitCode = 1;
} finally {
    ws.close();
}
