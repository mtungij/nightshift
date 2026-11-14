import test from "node:test";
import assert from "node:assert/strict";
import { productSellingUnits } from "../../resources/js/product-selling-units.js";

const records = [
    { id: 1, name: "Piece" },
    { id: 2, name: "Bucket" },
    { id: 3, name: "Crate" },
    { id: 4, name: "Glass" },
];
test("editing availability/prices preserves every existing conversion and custom selling unit", () => {
    const existing = {
        units: [
            ["Glass", "250.000000", "3000.00", 11, 4],
            ["Bucket", "12.000000", "24000.00", 12, 2],
            ["Crate", "48.000000", "96000.00", 13, 3],
        ],
    };
    const result = productSellingUnits(
        { baseUnit: "Glass", price: "3500", bucket: "25000", crate: "97000" },
        existing,
        records,
    );
    assert.deepEqual(
        result.map((u) => [u.unit_id, u.conversion_quantity]),
        [
            [4, "250.000000"],
            [2, "12.000000"],
            [3, "48.000000"],
        ],
    );
    assert.deepEqual(
        result.map((u) => u.selling_price),
        ["3500", "25000", "97000"],
    );
});
test("new products use standard conversions; omitted optional prices preserve existing units", () => {
    const result = productSellingUnits(
        { baseUnit: "Bottle", price: "3000", bucket: "18000", crate: "72000" },
        null,
        records,
    );
    assert.deepEqual(
        result.map((u) => [u.unit_id, u.conversion_quantity]),
        [
            [1, "1"],
            [2, "6"],
            [3, "24"],
        ],
    );
    const edited = productSellingUnits(
        { baseUnit: "Piece", price: "4000", bucket: "0", crate: "0" },
        {
            units: result.map((u, i) => [
                u.name,
                u.conversion_quantity,
                u.selling_price,
                i + 10,
                u.unit_id,
            ]),
        },
        records,
    );
    assert.equal(edited.length, 3);
    assert.equal(edited[1].selling_price, "18000");
});
