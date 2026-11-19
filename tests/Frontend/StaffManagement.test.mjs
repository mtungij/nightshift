import assert from "node:assert/strict";
import test from "node:test";
import { staffAccountStatus, staffActions } from "../../resources/js/staff-management.js";

const cashier = { id: 5, role: "Cashier", active: true, archived: false };
const button = (label, action) => `<button data-action="${action}">${label}</button>`;

test("staff actions start with Edit and keep PIN resets separate", () => {
    const actions = staffActions(cashier, true, true, button);
    assert.deepEqual([...actions.matchAll(/data-action="([^"]+)"/g)].map((match) => match[1]),
        ["staff-edit", "staff-pin", "staff-status", "staff-delete"]);
    assert.doesNotMatch(actions, /Promote to admin|Edit role/);
    assert.doesNotMatch(staffActions(cashier, false, false, button), /staff-pin/);
    assert.equal(staffActions({ ...cashier, role: "Company Admin / Owner" }, false, false, button), "—");
});

test("inactive staff can be activated and archived staff is read-only", () => {
    const inactive = { ...cashier, active: false };
    const archived = { ...inactive, archived: true };
    assert.equal(staffAccountStatus(cashier), "Active");
    assert.equal(staffAccountStatus(inactive), "Inactive");
    assert.equal(staffAccountStatus(archived), "Archived");
    assert.match(staffActions(inactive, true, true, button), />Activate<\/button>/);
    assert.equal(staffActions(archived, true, true, button), "—");
});
