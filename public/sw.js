/* Nightshift installation shell. Business operations always use the network. */
const CACHE = "nightshift-static-v1";
const PUBLIC_ASSETS = new Set([
    "/manifest.webmanifest",
    "/nightshift.svg",
    "/favicon.svg",
    "/favicon.ico",
    "/apple-touch-icon.png",
    "/icons/icon-192.png",
    "/icons/icon-512.png",
]);
const PRECACHE = [
    "/manifest.webmanifest",
    "/nightshift.svg",
    "/icons/icon-192.png",
    "/icons/icon-512.png",
];
const STATIC_TYPE =
    /^(?:text\/(?:css|javascript)|application\/(?:javascript|json|manifest\+json|font-woff)|image\/(?:png|svg\+xml|x-icon|vnd.microsoft.icon)|font\/)/i;

function isStatic(request) {
    const url = new URL(request.url);
    return (
        request.method === "GET" &&
        request.mode !== "navigate" &&
        url.origin === self.location.origin &&
        (PUBLIC_ASSETS.has(url.pathname) ||
            /^\/build\/assets\/[^/]+\.(?:js|css|woff2?|ttf)$/.test(url.pathname))
    );
}
function cacheable(response) {
    return (
        response.ok &&
        !response.redirected &&
        response.type === "basic" &&
        STATIC_TYPE.test(response.headers.get("Content-Type") || "")
    );
}
self.addEventListener("install", (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(CACHE);
            for (const path of PRECACHE) {
                const request = new Request(new URL(path, self.location.origin), {
                    cache: "reload",
                    credentials: "omit",
                });
                const response = await fetch(request);
                if (!cacheable(response)) throw new Error("Nightshift public asset unavailable.");
                await cache.put(request, response);
            }
            await self.skipWaiting();
        })(),
    );
});
self.addEventListener("activate", (event) => {
    event.waitUntil(
        (async () => {
            for (const key of await caches.keys()) {
                if (key.startsWith("nightshift-static-") && key !== CACHE) await caches.delete(key);
            }
            await self.clients.claim();
        })(),
    );
});
self.addEventListener("fetch", (event) => {
    if (!isStatic(event.request)) return;
    event.respondWith(
        (async () => {
            let cache;
            try {
                cache = await caches.open(CACHE);
            } catch {
                return fetch(event.request);
            }
            const url = new URL(event.request.url);
            // Fingerprinted build files are immutable. Public branding/manifest are refreshed online.
            if (url.pathname.startsWith("/build/assets/")) {
                try {
                    const existing = await cache.match(event.request);
                    if (existing) return existing;
                } catch {
                    // Cache storage is optional.
                }
            }
            try {
                const response = await fetch(event.request);
                if (cacheable(response)) {
                    try {
                        await cache.put(event.request, response.clone());
                    } catch {
                        /* Quota/storage errors must not hide a successful network response. */
                    }
                }
                return response;
            } catch (error) {
                try {
                    const existing = await cache.match(event.request);
                    if (existing) return existing;
                } catch {
                    // Preserve the original network error when there is no usable cache.
                }
                throw error;
            }
        })(),
    );
});
