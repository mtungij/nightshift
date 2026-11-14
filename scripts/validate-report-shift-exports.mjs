import fs from "node:fs/promises";
const fixtures = JSON.parse(
    await fs.readFile("/tmp/nightshift-report-shift-fixtures.json", "utf8"),
);
const base = "http://127.0.0.1:8885";
if (
    (await fetch(base + "/login")).headers.get("X-Nightshift-Validation-Database") !==
    "nightshift_treasury_browser_validation"
)
    throw Error("Disposable database only");
const tabs = await (await fetch("http://127.0.0.1:9225/json/list")).json();
const target = tabs.find((tab) => tab.type === "page" && tab.url.startsWith(base));
if (!target) throw Error("Authenticated validation tab required");
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    ws.addEventListener("open", resolve, { once: true });
    ws.addEventListener("error", reject, { once: true });
});
let sequence = 0;
async function evaluate(expression) {
    const id = ++sequence;
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(Error("Export validation timed out")), 60000);
        const receive = (event) => {
            const data = JSON.parse(event.data);
            if (data.id !== id) return;
            ws.removeEventListener("message", receive);
            clearTimeout(timer);
            if (data.result?.exceptionDetails)
                reject(Error(JSON.stringify(data.result.exceptionDetails)));
            else resolve(data.result.result.value);
        };
        ws.addEventListener("message", receive);
        ws.send(
            JSON.stringify({
                id,
                method: "Runtime.evaluate",
                params: { expression, awaitPromise: true, returnByValue: true },
            }),
        );
    });
}
const results = [];
try {
    for (const [label, id, invoices, total] of [
        ["closed", fixtures.shift_id, fixtures.invoices, 2000],
        ["live", fixtures.live_shift_id, [fixtures.live_invoice], 1000],
    ]) {
        const path = "/api/nightshift/reports/Daily%20Sales",
            query = "?shift_id=" + id + "&from=2001-01-01&to=2001-01-02";
        const screen = await evaluate(
            `fetch('${path + query}',{headers:{Accept:'application/json'}}).then(async r=>({status:r.status,data:await r.json()}))`,
        );
        const csv = await evaluate(
            `fetch('${path + "/export" + query}').then(async r=>({status:r.status,text:await r.text()}))`,
        );
        const pdf = await evaluate(
            `fetch('${path + "/pdf" + query}').then(async r=>({status:r.status,filename:r.headers.get('Content-Disposition'),bytes:Array.from(new Uint8Array(await r.arrayBuffer()))}))`,
        );
        if (screen.status !== 200 || csv.status !== 200 || pdf.status !== 200)
            throw Error(label + " export failed");
        if (
            screen.data.rows.length !== invoices.length ||
            screen.data.rows.reduce((sum, row) => sum + Number(row[3]), 0) !== total
        )
            throw Error(label + " screen scope mismatch");
        const rows = csv.text
            .replace(/^\uFEFF/, "")
            .trim()
            .split(/\r?\n/)
            .slice(1);
        if (
            rows.length !== invoices.length ||
            !invoices.every((number) => csv.text.includes(number))
        )
            throw Error(label + " CSV scope mismatch");
        await fs.writeFile(
            "/tmp/nightshift-report-shift-browser/" + label + "-final.pdf",
            Buffer.from(pdf.bytes),
        );
        results.push({
            scope: label,
            shift_id: id,
            rows: rows.length,
            total,
            filename: pdf.filename,
            result: "PASS",
        });
    }
    console.log(JSON.stringify(results, null, 2));
} finally {
    ws.close();
}
