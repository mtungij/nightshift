import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const worker = fs.readFileSync(new URL("../../public/sw.js", import.meta.url), "utf8");
function workerHarness() {
    const handlers = {},
        entries = new Map(),
        requests = [];
    const response = (type = "text/javascript", redirected = false) => ({
        ok: true,
        type: "basic",
        redirected,
        headers: new Headers({ "Content-Type": type }),
        clone() {
            return this;
        },
    });
    const cache = {
        match: async (request) => entries.get(request.url),
        put: async (request, r) => entries.set(request.url, r),
    };
    const context = {
        URL,
        Request,
        Headers,
        self: {
            location: { origin: "https://nightshift.test" },
            clients: { claim: async () => {} },
            skipWaiting: async () => {},
            addEventListener: (name, fn) => (handlers[name] = fn),
        },
        caches: {
            open: async () => cache,
            keys: async () => ["unrelated-cache", "nightshift-static-old", "nightshift-static-v1"],
            delete: async (name) => {
                requests.push("delete:" + name);
                return true;
            },
        },
        fetch: async (request) => {
            requests.push(request.url);
            return response(
                request.url.endsWith(".png")
                    ? "image/png"
                    : request.url.endsWith(".webmanifest")
                      ? "application/manifest+json"
                      : request.url.endsWith(".svg")
                        ? "image/svg+xml"
                        : "text/javascript",
            );
        },
    };
    vm.runInNewContext(worker, context);
    return { handlers, entries, requests, response, context };
}
test("worker never intercepts business data, navigation, POSTs or cross-origin requests", () => {
    const h = workerHarness();
    for (const path of [
        "/api/nightshift/bootstrap",
        "/api/nightshift/my-orders",
        "/api/nightshift/my-orders/17",
        "/login",
        "/logout",
        "/dashboard",
        "/api/nightshift/commands/payment",
        "/api/nightshift/commands/order-send",
        "/shift-stock/1",
        "/api/nightshift/reports/Daily%20Sales",
        "/api/nightshift/receipts/1",
        "/build/assets/financial.json",
    ]) {
        let intercepted = false;
        h.handlers.fetch({
            request: { url: "https://nightshift.test" + path, method: "GET", mode: "cors" },
            respondWith: () => (intercepted = true),
        });
        assert.equal(intercepted, false, path);
    }
    for (const request of [
        { url: "https://nightshift.test/build/assets/app-123.js", method: "POST", mode: "cors" },
        { url: "https://nightshift.test/icons/icon-192.png", method: "GET", mode: "navigate" },
        { url: "https://other.test/build/assets/app-123.js", method: "GET", mode: "cors" },
    ]) {
        let intercepted = false;
        h.handlers.fetch({ request, respondWith: () => (intercepted = true) });
        assert.equal(intercepted, false);
    }
});
test("worker caches only allowlisted static types and retains unrelated caches", async () => {
    const h = workerHarness();
    let pending;
    h.handlers.install({ waitUntil: (p) => (pending = p) });
    await pending;
    assert.equal(h.entries.size, 4);
    const request = {
        url: "https://nightshift.test/build/assets/app-123.js",
        method: "GET",
        mode: "cors",
    };
    h.handlers.fetch({ request, respondWith: (p) => (pending = p) });
    await pending;
    assert.ok(h.entries.has(request.url));
    const calls = h.requests.length;
    h.handlers.fetch({ request, respondWith: (p) => (pending = p) });
    await pending;
    assert.equal(h.requests.length, calls);
    h.context.fetch = async () => h.response("text/html");
    const html = { ...request, url: "https://nightshift.test/build/assets/redirect.js" };
    h.handlers.fetch({ request: html, respondWith: (p) => (pending = p) });
    await pending;
    assert.equal(h.entries.has(html.url), false);
    h.context.fetch = async () => h.response("text/javascript", true);
    const redirect = { ...request, url: "https://nightshift.test/build/assets/login.js" };
    h.handlers.fetch({ request: redirect, respondWith: (p) => (pending = p) });
    await pending;
    assert.equal(h.entries.has(redirect.url), false);
    h.handlers.activate({ waitUntil: (p) => (pending = p) });
    await pending;
    assert.ok(h.requests.includes("delete:nightshift-static-old"));
    assert.ok(!h.requests.includes("delete:unrelated-cache"));
});

const pwa = fs
    .readFileSync(new URL("../../resources/js/pwa.js", import.meta.url), "utf8")
    .replace(/^import[^\n]+\n/, "");
function uiHarness({ auth = true, standalone = false, workerSupport = true, secure = true } = {}) {
    const listeners = {},
        docListeners = {},
        controls = [{}],
        areas = [{}],
        registrations = [],
        messages = [];
    const window = {
        isSecureContext: secure,
        addEventListener: (name, fn) => (listeners[name] ??= []).push(fn),
        dispatchEvent: (e) => {
            messages.push(e);
            for (const fn of listeners[e.type] || []) fn(e);
        },
    };
    const media = {
        matches: standalone,
        addEventListener: (name, fn) => (listeners["display:" + name] = [fn]),
    };
    const context = {
        window,
        navigator: {
            ...(workerSupport
                ? {
                      serviceWorker: {
                          register: (...args) => {
                              registrations.push(args);
                              return Promise.resolve();
                          },
                      },
                  }
                : {}),
        },
        matchMedia: () => media,
        document: {
            getElementById: () => (auth ? {} : null),
            readyState: "complete",
            querySelectorAll: (selector) => (selector === "[data-pwa-install]" ? controls : areas),
            addEventListener: (name, fn) => (docListeners[name] = fn),
        },
        CustomEvent: class {
            constructor(type, args) {
                this.type = type;
                this.detail = args.detail;
            }
        },
        t: (key) => (context.locale === "sw" && key === "Install App" ? "Sakinisha App" : key),
    };
    vm.runInNewContext(pwa, context);
    return {
        ...context,
        setLocale: (locale) => (context.locale = locale),
        controls,
        areas,
        registrations,
        messages,
        emit: (type, event = {}) => (listeners[type] || []).forEach((fn) => fn(event)),
    };
}
test("install remains hidden when unsupported, installed, insecure, or unauthenticated", async () => {
    for (const options of [{ standalone: true }, { auth: false }]) {
        const h = uiHarness(options);
        h.emit("beforeinstallprompt", {
            preventDefault() {},
            prompt: async () => {},
            userChoice: Promise.resolve({ outcome: "accepted" }),
        });
        assert.equal(h.controls[0].hidden, true);
    }
    const h = uiHarness({ workerSupport: false });
    assert.equal(h.controls[0].hidden, true);
    assert.equal(h.registrations.length, 0);
    const insecure = uiHarness({ secure: false });
    assert.equal(insecure.registrations.length, 0);
});
test("prompt is captured, localized and consumed once, even on rapid duplicate clicks", async () => {
    const h = uiHarness();
    let count = 0,
        resolve;
    const choice = new Promise((r) => (resolve = r));
    h.emit("beforeinstallprompt", {
        preventDefault() {},
        prompt: async () => {
            count++;
        },
        userChoice: choice,
    });
    assert.equal(h.controls[0].hidden, false);
    h.setLocale("sw"); // Translation update uses the same live translation function.
    h.emit("nightshift:language");
    assert.equal(h.controls[0].textContent, "Sakinisha App");
    const first = h.window.NightshiftPwa.install();
    const second = h.window.NightshiftPwa.install();
    assert.equal(count, 1);
    assert.equal(h.controls[0].hidden, true);
    resolve({ outcome: "accepted" });
    await Promise.all([first, second]);
    h.emit("appinstalled");
    assert.equal(h.controls[0].hidden, true);
    assert.equal(h.registrations[0][0], "/sw.js");
    assert.equal(h.registrations[0][1].scope, "/");
    assert.equal(h.registrations[0][1].updateViaCache, "none");
});
test("dismissal is not prompted twice and prompt failures cannot break business UI", async () => {
    const h = uiHarness();
    let count = 0;
    h.emit("beforeinstallprompt", {
        preventDefault() {},
        prompt: async () => {
            count++;
        },
        userChoice: Promise.resolve({ outcome: "dismissed" }),
    });
    await h.window.NightshiftPwa.install();
    await h.window.NightshiftPwa.install();
    assert.equal(count, 1);
    h.emit("beforeinstallprompt", {
        preventDefault() {},
        prompt: async () => {
            throw Error("not allowed");
        },
    });
    await h.window.NightshiftPwa.install();
    assert.equal(h.messages.at(-1).type, "nightshift:theme-error");
    assert.equal(h.controls[0].hidden, true);
});

test("static network responses still work when cache storage is unavailable", async () => {
    const h = workerHarness();
    h.context.caches.open = async () => {
        throw new Error("cache disabled");
    };
    let pending;
    h.handlers.fetch({
        request: {
            url: "https://nightshift.test/build/assets/app-123.js",
            method: "GET",
            mode: "cors",
        },
        respondWith: (p) => (pending = p),
    });
    assert.equal((await pending).ok, true);
    assert.equal(h.requests.length, 1);
});
