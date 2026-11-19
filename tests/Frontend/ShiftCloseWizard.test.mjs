import assert from "node:assert/strict";
import test from "node:test";
import { cashDifference, closeProgress, physicalCount } from "../../resources/js/shift-close-wizard.js";

test("physical counts are explicit and stock variances keep six decimal places", () => {
    assert.deepEqual(physicalCount("", "0.000000"), { status: "Not counted", difference: null });
    assert.deepEqual(physicalCount("0", "0.000000"), { status: "Balanced", difference: 0n });
    assert.deepEqual(physicalCount("9999999999.999998", "9999999999.999999"), { status: "Short", difference: -1n });
    assert.deepEqual(physicalCount("0.000001", "0.000000"), { status: "Over", difference: 1n });
});

test("cash must be entered and the expected balance uses exact cents", () => {
    assert.equal(cashDifference("", "1000.00"), null);
    assert.equal(cashDifference("999.99", "1000.00"), -1);
    assert.equal(cashDifference("0", "0.00"), 0);
});

test("review uses saved reconciliation and keeps final close gated by server requirements", () => {
    const report = { complete: true, blockers: [], cash: { actual: "0.00", pending_ticket_count: 0 }, close_requirements: { stock: null, handover: null } };
    const draft = { dirty: false, conflict: false, difference: 0, notes: "" };
    assert.equal(closeProgress(report, draft).canClose, true);
    for (const options of [{ dirty: true }, { conflict: true }, { difference: null }])
        assert.equal(closeProgress(report, { ...draft, ...options }).saved, false);
    assert.equal(closeProgress({ ...report, blockers: [{ name: "Asha" }] }, draft).ready, false);
    assert.equal(closeProgress({ ...report, cash: { ...report.cash, pending_ticket_count: 1 } }, draft).ready, false);
    for (const stock of ["ledger", "approval"])
        assert.equal(closeProgress({ ...report, close_requirements: { stock, handover: null } }, draft).ready, false);
    const handover = closeProgress({ ...report, close_requirements: { stock: null, handover: "Awaiting receipt" } }, draft);
    assert.equal(handover.ready, true);
    assert.equal(handover.canClose, false);
    assert.equal(closeProgress(report, { ...draft, difference: -1 }).canClose, false);
    assert.equal(closeProgress(report, { ...draft, difference: -1, notes: "Cash counted again" }).canClose, true);
});
