import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve(import.meta.dirname, "..");
const publicDir = path.join(root, "public");
assert.ok(
    !fs.existsSync(path.join(publicDir, "hot")),
    "public/hot exists: stop the dev server and remove its stale hot file before publishing a production build.",
);
const manifest = JSON.parse(fs.readFileSync(path.join(publicDir, "build/manifest.json"), "utf8"));
for (const entry of ["resources/js/app.js", "resources/css/app.css"])
    assert.ok(manifest[entry]?.file, `Missing Vite entry: ${entry}`);
for (const entry of Object.values(manifest)) {
    for (const file of [entry.file, ...(entry.assets || [])].filter(Boolean)) {
        assert.ok(file.startsWith("assets/") && !file.includes(".."), "Unexpected build path");
        assert.ok(
            fs.statSync(path.join(publicDir, "build", file)).size > 0,
            `Missing/empty build asset: ${file}`,
        );
    }
}
const app = fs.readFileSync(
    path.join(publicDir, "build", manifest["resources/js/app.js"].file),
    "utf8",
);
for (const marker of [
    "My Orders",
    "my-orders",
    "waiter-payment",
    "beforeinstallprompt",
    "appinstalled",
    "/sw.js",
])
    assert.ok(app.includes(marker), `Current frontend feature missing from built JS: ${marker}`);
const pwa = JSON.parse(fs.readFileSync(path.join(publicDir, "manifest.webmanifest"), "utf8"));
assert.equal(pwa.name, "Nightshift");
assert.equal(pwa.short_name, "Nightshift");
assert.equal(pwa.scope, "/");
assert.equal(pwa.display, "standalone");
assert.ok(pwa.start_url.startsWith("/") && !pwa.start_url.startsWith("//"));
for (const size of [192, 512]) {
    const icon = pwa.icons.find(
        (icon) => icon.sizes === `${size}x${size}` && icon.type === "image/png",
    );
    assert.ok(icon && icon.src === `/icons/icon-${size}.png`, "Invalid manifest icon");
    const bytes = fs.readFileSync(path.join(publicDir, icon.src.slice(1)));
    assert.equal(bytes.subarray(0, 8).toString("hex"), "89504e470d0a1a0a");
    assert.equal(bytes.readUInt32BE(16), size);
    assert.equal(bytes.readUInt32BE(20), size);
}
assert.ok(fs.statSync(path.join(publicDir, "sw.js")).size > 0);
const release = {
    schema: 1,
    built_at: new Date().toISOString(),
    entries: {
        js: `/build/${manifest["resources/js/app.js"].file}`,
        css: `/build/${manifest["resources/css/app.css"].file}`,
    },
    features: ["my-orders", "waiter-payment", "pwa-install"],
    public_assets: [
        "/manifest.webmanifest",
        "/sw.js",
        "/icons/icon-192.png",
        "/icons/icon-512.png",
        "/nightshift.svg",
    ],
};
fs.writeFileSync(
    path.join(publicDir, "build/release.json"),
    JSON.stringify(release, null, 2) + "\n",
);
console.log(
    "PASS: production Vite entries, all referenced files, current features, manifest and PNG dimensions; public/build/release.json written.",
);
