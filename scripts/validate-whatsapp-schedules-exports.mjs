import fs from "node:fs/promises";
const base = "http://127.0.0.1:8885";
if ((await fetch(base + "/login")).headers.get("X-Nightshift-WhatsApp-Mocked") !== "yes")
    throw Error("Mocked local validation app required");
const f = JSON.parse(await fs.readFile("/tmp/nightshift-whatsapp-schedules-fixtures.json", "utf8"));
const tabs = await (await fetch("http://127.0.0.1:9225/json/list")).json();
const tab = tabs.find((t) => t.type === "page" && t.url.startsWith(base));
if (!tab) throw Error("Authenticated validation tab required");
const ws = new WebSocket(tab.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    ws.addEventListener("open", resolve, { once: true });
    ws.addEventListener("error", reject, { once: true });
});
let seq = 0;
async function run(expression) {
    const id = ++seq;
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(Error("Validation timed out")), 60000);
        const receive = (event) => {
            const data = JSON.parse(event.data);
            if (data.id !== id) return;
            clearTimeout(timer);
            ws.removeEventListener("message", receive);
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
try {
    const path = "/api/nightshift/reports/Daily%20Sales";
    const query = "?shift_id=" + f.shift_id;
    const screen = await run(
        `fetch('${path + query}',{headers:{Accept:'application/json'}}).then(async r=>({status:r.status,data:await r.json()}))`,
    );
    const csv = await run(
        `fetch('${path + "/export" + query}').then(async r=>({status:r.status,text:await r.text()}))`,
    );
    const pdf = await run(
        `fetch('${path + "/pdf" + query}').then(async r=>({status:r.status,bytes:Array.from(new Uint8Array(await r.arrayBuffer()))}))`,
    );
    if (screen.status !== 200 || csv.status !== 200 || pdf.status !== 200)
        throw Error("Report export failed");
    const total = screen.data.rows.reduce((sum, row) => sum + Number(row[3]), 0);
    const lines = csv.text
        .replace(/^\uFEFF/, "")
        .trim()
        .split(/\r?\n/);
    if (
        total !== 6000 ||
        lines.length !== 3 ||
        !f.invoices.every((number) => csv.text.includes(number))
    )
        throw Error("Screen/CSV reconciliation mismatch");
    await fs.writeFile("/tmp/nightshift-whatsapp-screen-report.pdf", Buffer.from(pdf.bytes));
    const delivery = JSON.parse(await fs.readFile("/tmp/whatsapp-schedules-delivery.json", "utf8"));
    if (
        !delivery.deliveries.every(
            (d) =>
                Number(d.sales) === total &&
                f.invoices.every((i) => d.message.includes(i) === false),
        )
    )
        throw Error("WhatsApp summary totals mismatch");
    console.log(
        JSON.stringify({
            result: "PASS",
            screen_sales: total,
            screen_rows: screen.data.rows.length,
            csv_rows: lines.length - 1,
            whatsapp_sales: delivery.deliveries.map((d) => d.sales),
            pdf: "/tmp/nightshift-whatsapp-screen-report.pdf",
        }),
    );
} finally {
    ws.close();
}
