import fs from "node:fs/promises";

// Use an isolated validation server/database with NULL staff verification timestamps.
// This runner logs in and reads data only; it does not submit business commands.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8885";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9225";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-auth-browser";
await fs.mkdir(output, { recursive: true });
const target = await (await fetch(`${debug}/json/new?about:blank`, { method: "PUT" })).json();
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    ws.addEventListener("open", resolve, { once: true });
    ws.addEventListener("error", reject, { once: true });
});
let sequence = 0;
const pending = new Map();
const checks = [],
    errors = [],
    verificationRequests = [],
    serverErrors = [];
ws.addEventListener("message", (event) => {
    const message = JSON.parse(event.data);
    if (message.id) {
        const call = pending.get(message.id);
        pending.delete(message.id);
        if (message.error) call.reject(new Error(JSON.stringify(message.error)));
        else call.resolve(message.result);
    }
    if (message.method === "Runtime.exceptionThrown") {
        errors.push(
            message.params.exceptionDetails.exception?.description ||
                message.params.exceptionDetails.text,
        );
    }
    if (
        message.method === "Network.requestWillBeSent" &&
        message.params.request.url.includes("/verify-email")
    ) {
        verificationRequests.push(message.params.request.url);
    }
    if (
        message.method === "Network.responseReceived" &&
        message.params.response.url.startsWith(base) &&
        message.params.response.status >= 500
    ) {
        serverErrors.push(message.params.response);
    }
});
const command = (method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++sequence;
        pending.set(id, { resolve, reject });
        ws.send(JSON.stringify({ id, method, params }));
    });
async function run(expression) {
    const result = await command("Runtime.evaluate", {
        expression,
        awaitPromise: true,
        returnByValue: true,
    });
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
}
async function wait(expression) {
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
        try {
            if (await run(expression)) return;
        } catch {}
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    throw new Error(
        `Timed out: ${expression}; page=${await run("document.body.innerText.slice(0, 600)")}`,
    );
}
function check(name, passed) {
    checks.push({ name, result: passed ? "PASS" : "FAIL" });
    console.log(`${passed ? "PASS" : "FAIL"} ${name}`);
    if (!passed) throw new Error(name);
}
async function navigate(path) {
    await run("window.__previousLoginDocument = true");
    const url = new URL(path, base);
    url.searchParams.set("login_smoke", String(Date.now()));
    await command("Page.navigate", { url: url.href });
    await wait(
        'window.__previousLoginDocument === undefined && document.readyState === "complete"',
    );
}
async function screenshot(name) {
    const result = await command("Page.captureScreenshot", { format: "png" });
    await fs.writeFile(`${output}/${name}.png`, Buffer.from(result.data, "base64"));
}
try {
    await command("Page.enable");
    await command("Runtime.enable");
    await command("Network.enable");
    await command("Emulation.setDeviceMetricsOverride", {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    for (const [account, title, restricted] of [
        ["admin", "Operations overview", false],
        ["manager", "Operations overview", false],
        ["cashier", "Point of sale", true],
        ["asha", "Point of sale", true],
        ["storekeeper", "Inventory control", true],
    ]) {
        await command("Network.clearBrowserCookies");
        await navigate("/dashboard");
        check(
            `${account}: guest workspace redirects to login`,
            await run('location.pathname === "/login"'),
        );
        await run(`(() => {
            for (const [name, value] of [['email', '${account}@nightshift.test'], ['password', 'Nightshift!2026']]) {
                const input = document.querySelector('[name="' + name + '"]');
                input.value = value;
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
            }
            document.querySelector('form[action$="/login"]').requestSubmit();
        })()`);
        await wait('!!document.querySelector("#main h1")');
        check(
            `${account}: direct authorized landing page`,
            await run(
                `location.pathname === '/dashboard' && document.querySelector('#main h1').textContent.trim() === ${JSON.stringify(title)}`,
            ),
        );
        const snapshot = await run(
            `fetch('/api/nightshift/bootstrap', {headers: {Accept: 'application/json'}}).then(async response => ({status: response.status, isolated: response.headers.get('X-Nightshift-Auth-Validation'), data: await response.json()}))`,
        );
        check(
            `${account}: authenticated isolated workspace API`,
            snapshot.status === 200 &&
                snapshot.isolated === "sqlite-copy" &&
                snapshot.data.user.email === `${account}@nightshift.test`,
        );
        if (restricted) {
            check(
                `${account}: management navigation remains restricted`,
                await run(
                    '!document.querySelector(".sidebar [data-nav=users]") && !document.querySelector(".sidebar [data-nav=settings]")',
                ),
            );
        }
        if (["cashier", "asha"].includes(account)) {
            check(
                `${account}: reports remain forbidden`,
                await run(
                    `fetch('/api/nightshift/reports/Sales', {headers: {Accept: 'application/json'}}).then(response => response.status === 403)`,
                ),
            );
            await navigate("/dashboard#inventory");
            await wait('!!document.querySelector("#main h1")');
            check(
                `${account}: forbidden deep link falls back to POS`,
                await run(
                    'document.querySelector("#main h1").textContent.trim() === "Point of sale"',
                ),
            );
        }
        await screenshot(`${account}-unverified-login`);
        await navigate("/settings/profile");
        check(
            `${account}: profile has no verification prompt`,
            await run(
                '!document.body.innerText.includes("Your email address is unverified") && !document.body.innerText.includes("re-send the verification email")',
            ),
        );
        await navigate("/dashboard");
        await wait('!!document.querySelector("#main h1")');
        check(
            `${account}: refreshed workspace stays accessible`,
            await run(
                `document.querySelector('#main h1').textContent.trim() === ${JSON.stringify(title)}`,
            ),
        );
        await run(`(async () => {
            const form = document.createElement('form'); form.method = 'POST'; form.action = '/logout';
            const csrf = document.createElement('input'); csrf.name = '_token'; csrf.value = document.querySelector('meta[name="csrf-token"]').content;
            form.append(csrf); document.body.append(form); form.requestSubmit();
        })()`);
        await wait('!!document.querySelector("[name=email]")');
        await navigate("/dashboard");
        check(
            `${account}: logout blocks the workspace again`,
            await run('location.pathname === "/login"'),
        );
    }
    check("No requests or redirects to /verify-email", verificationRequests.length === 0);
    check("No browser exceptions", errors.length === 0);
    check("No application HTTP 5xx responses", serverErrors.length === 0);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify(
            { result: "PASS", checks, errors, verificationRequests, serverErrors },
            null,
            2,
        ) + "\n",
    );
} catch (error) {
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify({ result: "FAIL", checks, errors, failure: String(error) }, null, 2) + "\n",
    );
    console.error(String(error));
    process.exitCode = 1;
} finally {
    ws.close();
    await fetch(`${debug}/json/close/${target.id}`);
}
