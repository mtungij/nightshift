import fs from "node:fs/promises";
import { execFileSync } from "node:child_process";

const directory = "/tmp/nightshift-pos-context-tickets";
const fixture = JSON.parse(await fs.readFile("/tmp/nightshift-pos-context-fixtures.json", "utf8"));
const target = await (
    await fetch("http://127.0.0.1:9225/json/new?about:blank", { method: "PUT" })
).json();
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    ws.addEventListener("open", resolve, { once: true });
    ws.addEventListener("error", reject, { once: true });
});
let sequence = 0;
const pending = new Map();
ws.addEventListener("message", (event) => {
    const result = JSON.parse(event.data);
    if (!result.id) return;
    const request = pending.get(result.id);
    pending.delete(result.id);
    if (request)
        result.error
            ? request.reject(Error(JSON.stringify(result.error)))
            : request.resolve(result.result);
});
const c = (method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++sequence;
        pending.set(id, { resolve, reject });
        ws.send(JSON.stringify({ id, method, params }));
    });
const results = [];
try {
    await c("Page.enable");
    await c("Network.enable");
    await c("Network.setBlockedURLs", { urls: ["http://*", "https://*", "file://*"] });
    await c("Emulation.setScriptExecutionDisabled", { value: true });
    for (const locale of ["en", "sw"])
        for (const width of ["58mm", "80mm"]) {
            const name = `${locale}-${width}`;
            const { frameTree } = await c("Page.getFrameTree");
            await c("Page.setDocumentContent", {
                frameId: frameTree.frame.id,
                html: await fs.readFile(`${directory}/${name}.html`, "utf8"),
            });
            const { data } = await c("Page.printToPDF", {
                preferCSSPageSize: true,
                printBackground: true,
                displayHeaderFooter: false,
                marginTop: 0,
                marginBottom: 0,
                marginLeft: 0,
                marginRight: 0,
            });
            const path = `${directory}/${name}.pdf`;
            await fs.writeFile(path, Buffer.from(data, "base64"));
            const metadata = execFileSync("pdfinfo", [path], { encoding: "utf8" });
            const size = metadata.match(/Page size:\s+([\d.]+) x ([\d.]+) pts/);
            const text = execFileSync("pdftotext", ["-layout", path, "-"], {
                encoding: "utf8",
            }).replace(/\s/g, "");
            const unit = fixture.bar.unit_name.replace(/\s/g, "");
            const label = locale === "en" ? "BusinessUnit" : "KitengochaBiashara";
            const copies = text.split(unit).length - 1;
            if (
                !size ||
                Math.abs((Number(size[1]) * 25.4) / 72 - Number.parseInt(width)) > 0.5 ||
                copies < 2 ||
                !text.includes(label) ||
                !/5,?000/.test(text)
            )
                throw Error(`Ticket validation failed: ${name}`);
            results.push({
                name,
                result: "PASS",
                physical_width_mm: (Number(size[1]) * 25.4) / 72,
                unit_copies: copies,
                path,
            });
            console.log(`PASS ${name}: physical width, saved unit, both copies and order price`);
        }
} finally {
    ws.close();
    await fetch(`http://127.0.0.1:9225/json/close/${target.id}`);
}
await fs.writeFile("/tmp/pos-context-ticket-results.json", JSON.stringify(results, null, 2));
