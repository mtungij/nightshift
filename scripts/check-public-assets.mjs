import assert from "node:assert/strict";

const origin = new URL(process.argv[2] || "https://barledger.site");
const read = async (pathname) => {
    const url = new URL(pathname, origin);
    const response = await fetch(url, { redirect: "error", signal: AbortSignal.timeout(20000) });
    assert.equal(response.status, 200, `${url}: HTTP ${response.status}`);
    return response;
};
const response = await read("/manifest.webmanifest");
assert.match(response.headers.get("content-type") || "", /(?:manifest\+json|application\/json)/i);
const manifest = await response.json();
assert.equal(manifest.name, "Nightshift");
assert.equal(manifest.scope, "/");
for (const size of [192, 512]) {
    const icon = manifest.icons.find((i) => i.sizes === `${size}x${size}`);
    assert.ok(icon);
    const result = await read(icon.src);
    assert.match(result.headers.get("content-type") || "", /^image\/png/i);
    const bytes = Buffer.from(await result.arrayBuffer());
    assert.equal(bytes.subarray(0, 8).toString("hex"), "89504e470d0a1a0a");
    assert.equal(bytes.readUInt32BE(16), size);
    assert.equal(bytes.readUInt32BE(20), size);
}
const sw = await read("/sw.js");
assert.match(sw.headers.get("content-type") || "", /(?:javascript|ecmascript)/i);
const release = await (await read("/build/release.json")).json();
const html = await (await read("/login")).text();
for (const entry of Object.values(release.entries)) {
    assert.ok(
        html.includes(new URL(entry, origin).href) || html.includes(entry),
        `Rendered HTML does not reference current Vite entry: ${entry}`,
    );
    await read(entry);
}
if (origin.protocol === "https:") {
    for (const [, url] of html.matchAll(/(?:src|href|action)=["'](https?:\/\/[^"']+)["']/g))
        assert.ok(url.startsWith("https:"), `Mixed content: ${url}`);
}
console.log(
    JSON.stringify(
        {
            result: "PASS",
            origin: origin.origin,
            icons: [192, 512],
            manifest: true,
            worker: true,
            current_rendered_entries: release.entries,
            features: release.features,
        },
        null,
        2,
    ),
);
