import { applyCounterLocation } from "./counter-cards.js";
import { t } from "./localization.js";

export function updateCashierOccupancy(form) {
    const unit = form.querySelector("[data-shift-unit]");
    const locationRow = form.querySelector("[data-advanced-shift-location]");
    const advanced = unit?.selectedOptions[0]?.dataset.advanced === "1";
    if (locationRow) {
        locationRow.hidden = !advanced;
        const location = locationRow.querySelector("select");
        location.disabled = !advanced;
        location.required = advanced;
        for (const item of location.options)
            item.hidden = !!item.value && item.dataset.unit !== unit.value;
        if (location.selectedOptions[0]?.hidden) location.value = "";
    }
    const counter = unit || form.querySelector("[name=stock_location_id],[name=counter]");
    const option = counter?.selectedOptions[0];
    const cashier =
        (form.querySelector("[name=type]")?.value || form.dataset.shiftCapacity) === "CASHIER";
    const valid = !!option?.value && (!advanced || !!locationRow?.querySelector("select").value);
    const active = Number(option?.dataset.activeCashiers || 0),
        max = Number(option?.dataset.maxActiveCashiers || 1);
    const selectedLocation = locationRow?.querySelector("select")?.selectedOptions[0];
    const locationFull =
        advanced &&
        selectedLocation?.value &&
        Number(selectedLocation.dataset.activeCashiers || 0) >=
            Number(selectedLocation.dataset.maxActiveCashiers || 1);
    const full = cashier && valid && (active >= max || locationFull);
    const occupancy = form.querySelector("[data-cashier-occupancy]"),
        message = form.querySelector("[data-cashier-capacity-message]");
    if (occupancy)
        occupancy.textContent =
            cashier && valid ? `${t("Active Cashiers")}: ${active} / ${max}` : "";
    if (message)
        message.textContent = full
            ? t(
                  unit && active >= max
                      ? "This Business Unit already has the maximum number of active cashiers."
                      : "This counter already has the maximum number of active cashiers.",
              )
            : "";
    const button = form.querySelector("[type=submit]");
    if (button) button.disabled = !valid || full;
}
for (const form of document.querySelectorAll("[data-shift-capacity]")) updateCashierOccupancy(form);
document.addEventListener("change", (event) => {
    const capacity = event.target.closest("[data-shift-capacity]");
    if (capacity) updateCashierOccupancy(capacity);
    const create = event.target.closest("[data-form=add-counter]");
    if (create && event.target.name === "type") {
        const row = create.querySelector("[data-new-counter-limit]"),
            store = event.target.value === "Storage";
        if (row) {
            row.hidden = store;
            row.querySelector("input").disabled = store;
        }
    }
});
document.addEventListener("submit", async (event) => {
    const form = event.target.closest("[data-cashier-limit]");
    if (!form) return;
    event.preventDefault();
    const button = form.querySelector("[type=submit]"),
        status = form.querySelector("[data-cashier-limit-status]");
    button.disabled = true;
    form.dataset.saving = "true";
    window.dispatchEvent(new CustomEvent("nightshift:counter-saving"));
    status.textContent = "";
    status.classList.remove("is-error");
    try {
        const fields = new FormData(form),
            body = {
                id: Number(fields.get("id")),
                max_active_cashiers: Number(fields.get("max_active_cashiers")),
                idempotency_key: crypto.randomUUID(),
            };
        const response = await fetch("/api/nightshift/commands/catalog-cashier-limit", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector("[name=csrf-token]").content,
            },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok) throw Error(Object.values(data.errors || {}).flat()[0] || data.message);
        applyCounterLocation(form, data.location);
        window.dispatchEvent(
            new CustomEvent("nightshift:counter-updated", { detail: data.location }),
        );
        status.textContent = t("Cashier limit saved.");
    } catch (error) {
        form.elements.max_active_cashiers.value = form.dataset.savedLimit;
        status.classList.add("is-error");
        status.textContent = error.message;
    } finally {
        delete form.dataset.saving;
        button.disabled = false;
    }
});
