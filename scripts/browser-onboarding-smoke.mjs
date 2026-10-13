import fs from "node:fs/promises";

// Use only the guarded disposable onboarding validation database.
// Registration and setup commands create validation fixtures; never target production.
const base = process.env.SMOKE_BASE_URL || "http://127.0.0.1:8886";
const debug = process.env.SMOKE_DEBUG_URL || "http://127.0.0.1:9226";
const output = process.env.SMOKE_OUTPUT || "/tmp/nightshift-business-browser";
const driver = process.env.SMOKE_DB_DRIVER || "sqlite";
if (!["sqlite", "mysql"].includes(driver)) throw new Error("Unsupported validation driver");
const validationGuard =
    driver === "mysql"
        ? "r.headers.get('X-Nightshift-Validation-Driver') === 'mysql' && r.headers.get('X-Nightshift-Validation-Database') === 'nightshift_validation'"
        : "r.headers.get('X-Nightshift-Business-Validation') === 'disposable-sqlite'";
const safeResponse = (r) =>
    driver === "mysql"
        ? r.headers.get("X-Nightshift-Validation-Driver") === "mysql" &&
          r.headers.get("X-Nightshift-Validation-Database") === "nightshift_validation"
        : r.headers.get("X-Nightshift-Business-Validation") === "disposable-sqlite";

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
const suffix = String(Date.now());
const ownerEmail = `owner-${suffix}@onboarding.test`;
async function fill(values, selector = "form") {
    await run(
        `(() => {const form = document.querySelector(${JSON.stringify(selector)}); for (const [name, value] of Object.entries(${JSON.stringify(values)})) {const input = form.querySelector('[name="'+name+'"]'); if (!input) throw new Error('Missing '+name); input.value = value; input.dispatchEvent(new Event('input', {bubbles:true})); input.dispatchEvent(new Event('change', {bubbles:true}));}})()`,
    );
}
async function snapshot() {
    return run(
        `fetch('/api/nightshift/bootstrap', {headers:{Accept:'application/json'}}).then(async r => {if(!(${validationGuard})) throw new Error('Unsafe validation target'); return r.json();})`,
    );
}
async function click(selector) {
    await run(`document.querySelector(${JSON.stringify(selector)}).click()`);
}
async function dimensions(width) {
    await command("Emulation.setDeviceMetricsOverride", {
        width,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: width < 600,
    });
    await new Promise((resolve) => setTimeout(resolve, 400));
}
async function logout() {
    await run("window.__previousLoginDocument = true");
    await run(
        `(() => {const form=document.createElement('form');form.method='POST';form.action='/logout';const token=document.createElement('input');token.name='_token';token.value=document.querySelector('meta[name="csrf-token"]').content;form.append(token);document.body.append(form);form.requestSubmit();})()`,
    );
    await wait(
        'window.__previousLoginDocument === undefined && document.readyState === "complete" && !!document.querySelector("form[action$=\\"/login\\"] [name=email]")',
    );
}
try {
    const safety = await fetch(new URL("/register-business", base));
    check("Disposable validation server confirmed before registration", safeResponse(safety));
    await command("Page.enable");
    await command("Runtime.enable");
    await command("Network.enable");
    await command("Network.clearBrowserCookies");
    await navigate("/register-business");
    for (const width of [1440, 390]) {
        await dimensions(width);
        check(
            `Registration ${width}px fits viewport`,
            await run("document.documentElement.scrollWidth <= innerWidth + 1"),
        );
        check(
            `Registration ${width}px defaults to Light`,
            await run('document.documentElement.dataset.theme === "light"'),
        );
        check(
            `Registration ${width}px selects twenty categories`,
            await run(
                'document.querySelectorAll("input[name=\\"categories[]\\"]:checked").length === 20',
            ),
        );
        await screenshot(`registration-${width}`);
    }
    await dimensions(1440);
    await fill(
        {
            business_name: `Smoke Bar ${suffix}`,
            trading_name: "Smoke Lounge",
            business_type: "Lounge",
            business_phone: "+255700000001",
            business_email: `bar-${suffix}@onboarding.test`,
            address: "Masaki",
            city: "Dar es Salaam",
            tin: "123456",
            vrn: "VRN-1",
            receipt_footer: "Come again soon",
            first_name: "Smoke",
            last_name: "Owner",
            owner_email: ownerEmail,
            owner_phone: "+255700000002",
            password: "Nightshift!2026",
            password_confirmation: "Nightshift!2026",
        },
        'form[action$="/register-business"]',
    );
    await run('document.querySelector("form[action$=\\"/register-business\\"]").requestSubmit()');
    await wait('document.querySelector("#main h1")?.textContent === "Business setup"');
    const first = await snapshot();
    check(
        "Owner auto login and setup landing",
        first.user.email === ownerEmail && first.role === "Company Admin / Owner",
    );
    check(
        "Default branch MAIN is active",
        first.branches.length === 1 &&
            first.branches[0].code === "MAIN" &&
            first.branches[0].active,
    );
    check(
        "Main Store and Counter seeded",
        first.locations.length === 2 &&
            first.locations.some((l) => l.name === "Main Store" && l.type === "STORE") &&
            first.locations.some((l) => l.name === "Main Counter" && l.type === "COUNTER"),
    );
    check(
        "Twenty categories and eleven units seeded",
        first.categoryRecords.length === 20 && first.unitRecords.length === 11,
    );
    check(
        "Default currency timezone correct",
        first.currency === "TZS" && first.timezone === "Africa/Dar_es_Salaam",
    );
    await click("[data-action=setup-status][data-step=products][data-value=skip]");
    await wait(
        'document.querySelector("[data-action=setup-status][data-step=products][data-value=resume]")',
    );
    await click("[data-action=setup-status][data-step=staff][data-value=skip]");
    await wait(
        '!document.querySelector("[data-action=setup-status][data-step=finish][data-value=complete]").disabled',
    );
    await click("[data-action=setup-status][data-step=finish][data-value=complete]");
    await wait(
        'document.querySelector("[data-action=setup-status][data-step=finish][data-value=resume]")',
    );
    await navigate("/dashboard#setup");
    await wait('document.querySelector("#main h1")?.textContent === "Business setup"');
    check(
        "Setup skip and finish survive refresh",
        (await snapshot()).onboarding.finish === "complete",
    );
    await navigate("/dashboard#inventory");
    await wait('document.querySelector("#main h1")');
    await click("[data-action=tab][data-value=Categories]");
    check(
        "Category management shows twenty rows",
        await run('document.querySelectorAll(".data-table tbody tr").length === 20'),
    );
    await click(`[data-action=edit-category][data-id="${first.categoryRecords[0].id}"]`);
    await fill({ name: "Craft Beer", color: "#123456", icon: "glass" }, "form[data-form=category]");
    await run('document.querySelector("form[data-form=category]").requestSubmit()');
    await wait(
        '!document.querySelector(".modal") && document.querySelector("#main").textContent.includes("Craft Beer")',
    );
    await click(`[data-action=toggle-category][data-id="${first.categoryRecords[0].id}"]`);
    await wait('document.querySelector("#main").textContent.includes("Activate")');
    check(
        "Category edit and deactivate persist",
        (await snapshot()).categoryRecords.some(
            (c) => c.name === "Craft Beer" && c.color === "#123456" && !c.active,
        ),
    );
    await click(
        `[data-action=reorder-category][data-id="${first.categoryRecords[1].id}"][data-value="-1"]`,
    );
    await wait(`document.querySelector('.data-table tbody tr').textContent.includes('Wine')`);
    check("Category reorder persists", (await snapshot()).categoryRecords[0].name === "Wine");
    await navigate("/dashboard#inventory");
    await wait('document.querySelector("#main h1")');
    await click("[data-action=inventory-add][data-value=Products]");
    check(
        "Product form offers all eleven units with Bottle default",
        await run(
            'document.querySelector("[name=baseUnit]").options.length === 11 && document.querySelector("[name=baseUnit]").value === "Bottle"',
        ),
    );
    await fill(
        {
            name: "House Wine",
            sku: `WINE-${suffix}`,
            category: "Wine",
            brand: "House",
            cost: "1000",
            price: "3000",
            min: "4",
        },
        "form[data-form=product]",
    );
    await run('document.querySelector("form[data-form=product]").requestSubmit()');
    await wait(
        '!document.querySelector(".modal") && document.querySelector("#main").textContent.includes("House Wine")',
    );
    const stocked = await snapshot();
    const wineProduct = stocked.products.find((p) => p.name === "House Wine");
    check(
        "First product uses seeded category and unit conversion",
        wineProduct?.category === "Wine" &&
            wineProduct?.baseUnit === "Bottle" &&
            Number(wineProduct?.units[0][1]) === 1,
    );
    await click("[data-action=tab][data-value=Categories]");
    check(
        "Category count includes first product",
        (await run(
            "document.querySelector('.data-table tbody tr').textContent.includes('Wine')",
        )) &&
            (await snapshot()).categoryRecords.find((c) => c.name === "Wine").products_count === 1,
    );
    await click(`[data-action=toggle-category][data-id="${first.categoryRecords[1].id}"]`);
    await wait(
        `document.querySelector('[data-action=toggle-category][data-id="${first.categoryRecords[1].id}"]').textContent.trim() === "Activate"`,
    );
    await click("[data-action=tab][data-value=Products]");
    await click(`[data-action=edit-product][data-id="${wineProduct.id}"]`);
    check(
        "Editing product preserves its deactivated category",
        await run('document.querySelector("select[name=category]").value === "Wine"'),
    );
    await click("[data-action=close-modal]");
    await navigate("/dashboard#branches");
    await wait('document.querySelector("#main h1")?.textContent === "Branches"');
    await click("[data-action=edit-branch]");
    await fill({ name: "Garden Branch", code: "GARDEN" }, "form[data-form=add-branch]");
    await run('document.querySelector("form[data-form=add-branch]").requestSubmit()');
    await wait(
        '!document.querySelector(".modal") && document.querySelector("#main").textContent.includes("Garden Branch")',
    );
    check("Default branch can be renamed", (await snapshot()).branches[0].name === "Garden Branch");
    await navigate("/settings/business");
    await fill(
        {
            receipt_header: "Welcome to Smoke Lounge",
            receipt_footer: "Thank you from Smoke Lounge",
        },
        'form[action$="/settings/business"]',
    );
    await run('document.querySelector("form[action$=\\"/settings/business\\"]").requestSubmit()');
    await wait('!!document.querySelector("[role=status]")');
    check(
        "Business profile saves header and footer",
        await run(
            'document.querySelector("[name=receipt_footer]").value === "Thank you from Smoke Lounge"',
        ),
    );
    for (const theme of ["dark", "light"]) {
        if ((await run("document.documentElement.dataset.theme")) !== theme)
            await click("[data-theme-toggle]");
        await wait(`document.documentElement.dataset.theme === '${theme}'`);
        for (const width of [1440, 390]) {
            await dimensions(width);
            check(
                `Business profile ${theme} ${width}px fits viewport`,
                await run("document.documentElement.scrollWidth <= innerWidth + 1"),
            );
            await screenshot(`profile-${theme}-${width}`);
        }
        await navigate("/dashboard#setup");
        await wait('document.querySelector("#main h1")?.textContent === "Business setup"');
        check(
            `Setup preserves ${theme} preference`,
            await run(`document.documentElement.dataset.theme === '${theme}'`),
        );
        for (const width of [1440, 390]) {
            await dimensions(width);
            check(
                `Setup ${theme} ${width}px fits viewport`,
                await run("document.documentElement.scrollWidth <= innerWidth + 1"),
            );
            await screenshot(`setup-${theme}-${width}`);
        }
        await navigate("/settings/business");
    }
    await dimensions(1440);
    for (const role of ["Cashier", "Waiter"]) {
        await navigate("/dashboard#users");
        await wait('document.querySelector("#main h1")?.textContent === "Users & roles"');
        await click("[data-action=add-user]");
        check(
            `${role}: owner and super admin not offered`,
            await run(
                '!Array.from(document.querySelector("select[name=role]").options).some(o => o.textContent.includes("Admin"))',
            ),
        );
        await fill(
            {
                role,
                name: `Smoke ${role}`,
                email: `${role.toLowerCase()}-${suffix}@onboarding.test`,
                phone: "+255700000003",
                pin: "0123",
                active: "Active",
            },
            "form[data-form=add-user]",
        );
        await run('document.querySelector("form[data-form=add-user]").requestSubmit()');
        await wait(
            `!document.querySelector('.modal') && document.querySelector('#main').textContent.includes('Smoke ${role}')`,
        );
        check(
            `${role}: created with phone and active state`,
            (await snapshot()).team.some(
                (u) => u.role === role && u.phone === "+255700000003" && u.active,
            ),
        );
    }
    await logout();
    // Operational accounts receive private random passwords; a PIN is not a login credential.
    // Password login/role landing coverage uses established accounts in the auth/PIN smoke suites.
    for (const role of ["cashier", "waiter"]) {
        await navigate("/login");
        const rejected = await run(
            `fetch('/login', {method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({email:'${role}-${suffix}@onboarding.test',password:'0123'})}).then(r=>r.status===422)`,
        );
        check(`${role}: operational PIN cannot replace account password`, rejected);
        check(
            `${role}: normal password reset remains available`,
            await run(`fetch('/forgot-password').then(r=>r.ok)`),
        );
    }
    await navigate("/register-business");
    await fill(
        {
            business_name: `Second Bar ${suffix}`,
            trading_name: "Second Lounge",
            business_phone: "+255700000001",
            business_email: `second-${suffix}@onboarding.test`,
            address: "City",
            city: "Dar es Salaam",
            first_name: "Second",
            last_name: "Owner",
            owner_email: `second-owner-${suffix}@onboarding.test`,
            owner_phone: "+255700000002",
            password: "Nightshift!2026",
            password_confirmation: "Nightshift!2026",
        },
        'form[action$="/register-business"]',
    );
    await run('document.querySelector("form[action$=\\"/register-business\\"]").requestSubmit()');
    await wait('document.querySelector("#main h1")?.textContent === "Business setup"');
    const second = await snapshot();
    check(
        "Second tenant has independent defaults",
        second.categoryRecords.length === 20 &&
            !second.categoryRecords.some((c) => first.categoryRecords.some((a) => a.id === c.id)) &&
            second.team.length === 1 &&
            second.branches[0].name === "Main Branch",
    );
    check(
        "Second owner starts Light",
        await run('document.documentElement.dataset.theme === "light"'),
    );
    check("No verification redirects", verificationRequests.length === 0);
    check("No browser exceptions", errors.length === 0);
    check("No HTTP 5xx responses", serverErrors.length === 0);
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify({ result: "PASS", checks, errors, serverErrors }, null, 2) + "\n",
    );
} catch (error) {
    await screenshot("failure");
    await fs.writeFile(
        `${output}/result.json`,
        JSON.stringify(
            { result: "FAIL", checks, errors, serverErrors, failure: String(error) },
            null,
            2,
        ) + "\n",
    );
    console.error(error);
    process.exitCode = 1;
} finally {
    ws.close();
    await fetch(`${debug}/json/close/${target.id}`);
}
