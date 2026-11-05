import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import fs from "node:fs/promises";
import path from "node:path";

// Run after browser-sales-tickets-smoke.mjs; requires Poppler's pdfinfo/pdftotext.
const [directory, paperWidth] = process.argv.slice(2);
assert.ok(
    directory && ["58mm", "80mm"].includes(paperWidth),
    "Usage: node scripts/validate-sales-ticket-pdfs.mjs DIRECTORY 58mm|80mm",
);
const files = (await fs.readdir(directory)).filter((file) => file.endsWith(".pdf")).sort();
assert.equal(
    files.filter((file) => /^(1440|390)-(light|dark)-(en|sw)\.pdf$/.test(file)).length,
    8,
    "Expected desktop/mobile × light/dark × EN/SW PDFs",
);
assert.ok(files.includes("reprint.pdf"), "Expected reprint PDF");
const expectedNote = (
    "Customer near TV <script>window.__noteExecuted=true</script> " +
    "LongLocationReference".repeat(15)
).replace(/\s/g, "");
let assertions = 2;
for (const file of files) {
    const target = path.join(directory, file);
    const metadata = execFileSync("pdfinfo", [target], { encoding: "utf8" });
    assert.match(metadata, /Pages:\s+2\b/, `${file}: exactly two copies/pages`);
    const size = metadata.match(/Page size:\s+([\d.]+) x ([\d.]+) pts/);
    assert.ok(size, `${file}: physical page size`);
    assert.ok(
        Math.abs((Number(size[1]) * 25.4) / 72 - parseInt(paperWidth)) < 0.5,
        `${file}: configured paper width`,
    );
    const pages = execFileSync("pdftotext", ["-layout", target, "-"], { encoding: "utf8" })
        .split("\f")
        .filter((page) => page.trim());
    assert.equal(pages.length, 2, `${file}: both pages have readable text`);
    const labels = file.endsWith("-sw.pdf")
        ? ["NAKALAYAMHUDUMU", "NAKALAYAKESHIA"]
        : ["WAITERCOPY", "CASHIERCOPY"];
    for (let i = 0; i < 2; i++) {
        const text = pages[i].replace(/\s/g, "");
        if (!file.startsWith("stress-"))
            assert.ok(text.includes(expectedNote), `${file}: complete long note on copy ${i + 1}`);
        assert.ok(text.includes(labels[i]), `${file}: correct copy label on page ${i + 1}`);
    }
    if (file === "reprint.pdf") {
        for (const page of pages) assert.match(page, /REPRINT/, "Reprint marker on each page");
        assertions += 2;
    }
    assertions += file.startsWith("stress-") ? 6 : 8;
}
console.log(
    JSON.stringify({
        result: "PASS",
        paperWidth,
        files: files.length,
        pages: files.length * 2,
        assertions,
    }),
);
