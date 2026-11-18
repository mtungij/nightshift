import assert from "node:assert/strict";
import test from "node:test";
import { projectedReceiptStock, receiptStockPreview } from "../../resources/js/receipt-stock.js";

const product = {
    current_stock: "24.000000",
    base_unit: "Bottle",
    units: [["Bottle", "1.000000"], ["Crate", "24.000000"]],
};

test("selected product, quantity and receiving unit determine the base balance", () => {
    assert.deepEqual(receiptStockPreview(product, "Bottle", "12"), { current: "24", projected: "36", baseUnit: "Bottle" });
    assert.equal(receiptStockPreview(product, "Crate", "2").projected, "72");
    assert.equal(receiptStockPreview(product, "Crate", "0.5").projected, "36");
    assert.equal(receiptStockPreview(product, "Crate", "").projected, null);
});

test("scope changes clear the preview and use the new stock row or zero balance", () => {
    assert.equal(receiptStockPreview(undefined, "", "2"), null);
    const atAnotherLocation = { ...product, current_stock: "0.000000" };
    assert.deepEqual(receiptStockPreview(atAnotherLocation, "Crate", "2"), { current: "0", projected: "48", baseUnit: "Bottle" });
});

test("decimal projection rounds to six base-unit places without float drift", () => {
    assert.equal(projectedReceiptStock("0.1", "0.2", "1"), "0.3");
    assert.equal(projectedReceiptStock("0.000001", "0.000001", "0.5"), "0.000002");
    assert.equal(projectedReceiptStock("9999999999.999999", "0.000001", "1"), "10000000000");
    assert.equal(projectedReceiptStock("0", "-1", "1"), null);
    assert.equal(projectedReceiptStock("0", "0.0000001", "1"), null);
});
