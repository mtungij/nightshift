import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import fs from "node:fs/promises";
import path from "node:path";

// Poppler's pdfinfo/pdftotext must be installed; no application/database writes.
const directory = process.argv[2] || "/tmp/nightshift-reconciliation-browser";
let pages = 0,
    checks = 0;
const attributes = (text) =>
    Object.fromEntries(
        [...text.matchAll(/(\w+)="([^"]*)"/g)].map((match) => [match[1], Number(match[2])]),
    );
for (const kind of ["desktop", "mobile"]) {
    const file = path.join(directory, kind + ".pdf");
    const metadata = execFileSync("pdfinfo", [file], { encoding: "utf8" });
    const bbox = execFileSync("pdftotext", ["-bbox-layout", file, "-"], { encoding: "utf8" });
    const documentPages = [...bbox.matchAll(/<page\b([^>]*)>([\s\S]*?)<\/page>/g)];
    assert.ok(documentPages.length > 0, kind + ": readable pages");
    checks++;
    assert.match(metadata, new RegExp("Pages:\\s+" + documentPages.length + "\\b"));
    checks++;
    for (const page of documentPages) {
        const { width, height } = attributes(page[1]);
        assert.ok(
            Math.abs(width - 841.89) < 1 && Math.abs(height - 595.28) < 1,
            kind + ": A4 landscape",
        );
        pages++;
        checks++;
        for (const word of page[2].matchAll(/<word\b([^>]*)>/g)) {
            const box = attributes(word[1]);
            assert.ok(
                box.xMin >= 0 && box.xMax <= width + 1 && box.yMin >= 0 && box.yMax <= height + 1,
                kind + ": text inside page bounds",
            );
            checks++;
        }
    }
    const text = execFileSync("pdftotext", ["-layout", file, "-"], { encoding: "utf8" });
    for (const expected of [
        "Serengeti",
        "18000.00",
        "1000.00",
        kind === "mobile" ? "6000.00" : "0.00",
        kind === "mobile" ? "21990.00" : "22000.00",
        kind === "mobile" ? "27000.00" : "21000.00",
    ]) {
        assert.ok(text.includes(expected), kind + ": expected closing/variance/outstanding values");
        checks++;
    }
}
const result = {
    result: "PASS",
    files: 2,
    pages,
    checks,
    orientation: "A4 landscape",
    text_within_page_bounds: true,
    totals_and_outstanding_present: true,
};
await fs.writeFile(
    path.join(directory, "pdf-validation.json"),
    JSON.stringify(result, null, 2) + "\n",
);
console.log(JSON.stringify(result));
