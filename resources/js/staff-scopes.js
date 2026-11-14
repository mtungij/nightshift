import { t } from "./localization.js";
const esc = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
    );
export function scopePicker(name, label, options, kind) {
    return `<details class="scope-picker" data-scope-kind="${kind}"><summary>${esc(t(label))}<span data-scope-summary>${esc(t("Select options"))}</span></summary><fieldset><legend class="sr-only">${esc(t(label))}</legend>${options.map((o) => `<label data-option-branch="${o.branch_id}" data-option-unit="${o.business_unit_id ?? ""}"><input type="${kind === "terminal" ? "radio" : "checkbox"}" name="${name}" value="${o.id}"><span>${esc(o.label || o.name)}</span></label>`).join("")}<p data-scope-empty hidden>${esc(t("No options in the selected scope."))}</p></fieldset></details>`;
}
export function syncStaffScopes(form, changed) {
    if (!form) return;
    const role =
        form.elements.role?.value ||
        form.dataset.scopeRole ||
        (form.dataset.form === "add-waiter" ? "Waiter" : "");
    const branches = form.elements.branch
        ? [Number(form.elements.branch.value)]
        : [...form.querySelectorAll('[name="branch_ids[]"]:checked')].map((i) => Number(i.value));
    const units = [...form.querySelectorAll('[name="business_unit_ids"], [name="unit_ids[]"]')];
    for (const i of units) {
        const label = i.closest("label");
        const visible =
            !label.dataset.optionBranch || branches.includes(Number(label.dataset.optionBranch));
        label.hidden = !visible;
        i.disabled = !visible;
        if (!visible) i.checked = false;
    }
    const permitted = units.filter((i) => !i.disabled);
    if (role === "POS Terminal" && changed?.checked && units.includes(changed))
        for (const i of units) if (i !== changed) i.checked = false;
    if (role === "POS Terminal" && units.filter((i) => i.checked).length > 1)
        for (const i of units.filter((i) => i.checked).slice(1)) i.checked = false;
    if (
        ["add-user", "add-waiter"].includes(form.dataset.form) &&
        permitted.length === 1 &&
        !permitted.some((i) => i.checked)
    )
        permitted[0].checked = true;
    const selected = units.filter((i) => i.checked && !i.disabled).map((i) => Number(i.value));
    for (const kind of ["cashier", "terminal"]) {
        const picker = form.querySelector(`[data-scope-kind="${kind}"]`);
        if (!picker) continue;
        const active = role === (kind === "cashier" ? "Cashier" : "POS Terminal");
        picker.hidden = !active;
        for (const i of picker.querySelectorAll("input")) {
            const label = i.closest("label");
            const visible =
                active &&
                selected.includes(Number(label.dataset.optionUnit)) &&
                branches.includes(Number(label.dataset.optionBranch));
            label.hidden = !visible;
            i.disabled = !visible;
            if (!visible) i.checked = false;
        }
        picker.hidden = !active || !picker.querySelector("input:not(:disabled)");
    }
    for (const picker of form.querySelectorAll(".scope-picker")) {
        const checked = [...picker.querySelectorAll("input:checked:not(:disabled)")];
        const text = picker.querySelector("[data-scope-summary]");
        if (text)
            text.textContent = checked.length
                ? checked.map((i) => i.nextElementSibling?.textContent || i.value).join(", ")
                : t("Select options");
        const empty = picker.querySelector("[data-scope-empty]");
        if (empty) empty.hidden = !!picker.querySelector("input:not(:disabled)");
    }
}
document.addEventListener("change", (event) => {
    const form = event.target.closest("form");
    if (!form?.querySelector(".scope-picker")) return;
    syncStaffScopes(form, event.target);
});
for (const form of document.querySelectorAll("[data-scope-role]")) syncStaffScopes(form);
