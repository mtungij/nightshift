import assert from "node:assert/strict";
import test from "node:test";
import { counterSaleRemaining } from "../../resources/js/counter-sale.js";

test("counter checkout requires the full total across tender lines", () => {
    assert.equal(counterSaleRemaining("20000", [{ amount: "10000" }, { amount: "10000" }]), 0);
    assert.equal(counterSaleRemaining("20000", [{ amount: "10000" }]), 1_000_000);
    assert.equal(counterSaleRemaining("20000", [{ amount: "21000" }]), -100_000);
    assert.equal(counterSaleRemaining("0.30", [{ amount: "0.10" }, { amount: "0.20" }]), 0);
});
