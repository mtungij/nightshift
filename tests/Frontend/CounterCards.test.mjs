import test from "node:test";
import assert from "node:assert/strict";
import { renderCounterCard, applyCounterLocation } from "../../resources/js/counter-cards.js";
const location = {
    id: 10,
    name: "Main Counter",
    type: "COUNTER",
    active: true,
    business_unit_name: "General",
    is_shared: false,
};
const setLanguage = (messages) => {
    globalThis.window = { NightshiftI18n: { messages } };
};

test("counter cards always render authoritative complete occupancy and prefilled integer limits", () => {
    setLanguage({});
    for (const [active, max] of [
        [0, 1],
        [1, 1],
        [1, 2],
        [2, 2],
    ]) {
        const html = renderCounterCard(
            { ...location, active_cashier_count: active, max_active_cashiers: max },
            "Main Branch",
            (s) => s,
        );
        assert.match(html, new RegExp(`Active Cashiers: ${active} / ${max}`));
        assert.match(html, new RegExp(`name="max_active_cashiers"[^>]*value="${max}"`));
        assert.match(html, /type="number"\s+min="1"\s+max="65535"\s+step="1"\s+required/);
        assert.doesNotMatch(html, /name="max_active_cashiers"[^>]*value=""/);
        assert.doesNotMatch(html, /Active Cashiers:\s*\d+\s*\/\s*</);
        assert.match(html, /Business Unit: General/);
        assert.equal(/data-counter-full hidden/.test(html), active < max);
    }
});
test("non-cashier and shared locations do not expose cashier controls or misleading unit names", () => {
    setLanguage({});
    for (const type of ["STORE", "KITCHEN", "WAREHOUSE"]) {
        const html = renderCounterCard(
            { ...location, type, is_shared: true, business_unit_name: null },
            "Main Branch",
            (s) => s,
        );
        assert.doesNotMatch(html, /Active Cashiers|Maximum Active Cashiers|data-cashier-limit/);
        assert.match(html, /Shared across Business Units/);
        assert.doesNotMatch(html, /Business Unit: General/);
    }
});
test("server save response refreshes both maximum and occupancy, without resetting the count", () => {
    setLanguage({});
    const occupancy = {},
        full = {};
    const form = {
        dataset: { savedLimit: "1" },
        elements: { max_active_cashiers: { value: "1" } },
        querySelector: (s) => (s === "[data-counter-occupancy]" ? occupancy : full),
    };
    applyCounterLocation(form, { max_active_cashiers: 2, active_cashier_count: 1 });
    assert.equal(form.elements.max_active_cashiers.value, "2");
    assert.equal(form.dataset.savedLimit, "2");
    assert.equal(occupancy.textContent, "Active Cashiers: 1 / 2");
    assert.equal(full.hidden, true);
    applyCounterLocation(form, { max_active_cashiers: 2, active_cashier_count: 2 });
    assert.equal(full.hidden, false);
});
test("counter occupancy, full state and saved limit render in Kiswahili", () => {
    setLanguage({
        "Active Cashiers": "Makashia Wanaofanya Kazi",
        "Maximum Active Cashiers": "Idadi ya Juu ya Makashia",
        "Cashier limit reached": "Idadi ya makashia imefikia kiwango cha juu",
    });
    const html = renderCounterCard(
        { ...location, active_cashier_count: 1, max_active_cashiers: 1 },
        "Main Branch",
        (s) => s,
    );
    assert.match(html, /Makashia Wanaofanya Kazi: 1 \/ 1/);
    assert.match(html, /Idadi ya Juu ya Makashia/);
    assert.match(html, /Idadi ya makashia imefikia kiwango cha juu/);
});
