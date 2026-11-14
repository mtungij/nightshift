import { t } from "./localization.js";
const escape = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
    );
const states = new WeakMap();
export function mountProductAvailability(form, configuration, getSellingUnits, selectedUnit) {
    const values = new Map(
        configuration.units.map((u) => [
            u.id,
            {
                available: configuration.product ? u.available : false,
                prices: new Map(u.prices.map((p) => [p.unit_id, p.selling_price ?? ""])),
            },
        ]),
    );
    states.set(form, { configuration, getSellingUnits, values });
    render(form);
}
function render(form) {
    const state = states.get(form);
    if (!state) return;
    for (const row of form.querySelectorAll("[data-product-unit]")) {
        const value = state.values.get(Number(row.dataset.productUnit));
        value.available = row.querySelector("[data-product-enabled]").checked;
        for (const input of row.querySelectorAll("[data-product-price]"))
            value.prices.set(Number(input.dataset.productPrice), input.value);
    }
    const units = state.getSellingUnits();
    form.querySelector("[data-product-availability]").innerHTML =
        `<h3>${escape(t("Available in Business Units"))}</h3><p class="form-help">${escape(t("Only checked Business Units can use this product. Blank prices use the company selling price."))}</p><div class="product-unit-settings">${state.configuration.units
            .map((u) => {
                const value = state.values.get(u.id);
                return `<article class="product-unit-card" data-product-unit="${u.id}"><label class="product-unit-check"><input type="checkbox" data-product-enabled ${value.available ? "checked" : ""}><span><strong>${escape(u.name)}</strong><small>${escape(u.branch)}${u.active ? "" : " · " + escape(t("Inactive"))}</small></span></label><div class="product-unit-prices">${units.map((unit) => `<label class="form-field"><span>${escape(t("Selling price"))} · ${escape(unit.name)}</span><input data-product-price="${unit.unit_id}" type="number" min="0" step="0.01" inputmode="decimal" value="${escape(value.prices.get(unit.unit_id) || "")}" placeholder="${escape(unit.selling_price)}" ${value.available ? "" : "disabled"}></label>`).join("")}</div></article>`;
            })
            .join(
                "",
            )}</div>${!state.configuration.units.length ? `<p>${escape(t("No authorized Business Units are available."))}</p>` : ""}`;
}
export function productAvailabilityPayload(form) {
    return [...form.querySelectorAll("[data-product-unit]")].map((row) => ({
        business_unit_id: Number(row.dataset.productUnit),
        available: row.querySelector("[data-product-enabled]").checked,
        prices: [...row.querySelectorAll("[data-product-price]")].map((input) => ({
            unit_id: Number(input.dataset.productPrice),
            selling_price: input.value || null,
        })),
    }));
}
document.addEventListener("change", (event) => {
    const form = event.target.closest("[data-form=product]");
    if (!states.has(form)) return;
    if (event.target.matches("[data-product-enabled]"))
        for (const input of event.target
            .closest("[data-product-unit]")
            .querySelectorAll("[data-product-price]"))
            input.disabled = !event.target.checked;
    if (event.target.name === "baseUnit") render(form);
});
document.addEventListener("input", (event) => {
    const form = event.target.closest("[data-form=product]");
    if (states.has(form) && ["price", "bucket", "crate"].includes(event.target.name)) render(form);
});
