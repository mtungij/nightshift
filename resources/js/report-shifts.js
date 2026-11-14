import { t } from "./localization.js";

export function mountReportShifts(
    form,
    report,
    { units = [], counters = [], change = () => {} } = {},
) {
    const field = (name) => form.elements.namedItem(name);
    const branch = field("branch") || field("branch_id");
    const unit = field("business_unit") || field("business_unit_id");
    const counter = field("counter") || field("stock_location_id");
    const shift = field("shift_id");
    const supportsCounter = counter && !counter.disabled;
    function locationMode() {
        if (!counter || !units.some((u) => "advanced_locations_enabled" in u)) return;
        const selected = units.find((u) => String(u.id) === unit?.value);
        const advanced = supportsCounter && !!selected?.advanced_locations_enabled;
        (counter.closest(".form-field") || counter.parentElement).hidden = !advanced;
        counter.disabled = !advanced;
        if (!advanced) counter.value = "";
    }
    locationMode();
    if (!shift || shift.disabled) {
        unit?.addEventListener("change", locationMode);
        return;
    }
    const box = form.querySelector("[data-shift-summary]");
    const search = form.querySelector("[data-shift-search]");
    const more = form.querySelector("[data-shift-more]");
    let cursor,
        sequence = 0,
        items = new Map();
    function limit(control, records, accepts) {
        if (!control || !records.length) return;
        for (const option of control.options) {
            const record = records.find((r) => String(r.id) === option.value);
            option.hidden = Boolean(record && !accepts(record));
            option.disabled = option.hidden;
        }
        if (control.selectedOptions[0]?.disabled) control.value = "";
    }
    function hierarchy() {
        locationMode();
        limit(unit, units, (u) => !branch?.value || String(u.branch_id) === branch.value);
        limit(
            counter,
            counters,
            (c) =>
                (!branch?.value || String(c.branch_id) === branch.value) &&
                (!unit?.value || String(c.business_unit_id) === unit.value),
        );
    }
    function summary() {
        const selected = items.get(shift.value);
        box.replaceChildren();
        if (selected) {
            for (const [label, value] of [
                ["Shift", selected.number],
                ["Employee", selected.employee],
                ["Branch", selected.branch],
                ["Business Unit", selected.business_unit],
                ["Counter", selected.counter],
                ["Opened", selected.opened_at],
                ["Closed", selected.closed_at || "—"],
                ["Status", t(selected.status)],
            ]) {
                if (label === "Counter" && selected.show_location === false) continue;
                const row = document.createElement("span");
                row.textContent = `${t(label)}: ${value || "—"}`;
                box.append(row);
            }
            const note = document.createElement("p");
            note.textContent = t("Selected shift; date range is ignored.");
            box.append(note);
        }
        for (const name of ["from", "to"]) {
            const date = field(name);
            if (date && !date.closest(".form-field")?.hidden) date.disabled = Boolean(shift.value);
        }
    }
    async function load(append = false) {
        const request = ++sequence;
        const params = new URLSearchParams({ report });
        for (const [key, control] of [
            ["branch_id", branch],
            ["business_unit_id", unit],
            ["stock_location_id", counter],
            ["shift_id", shift],
        ])
            if (control?.value) params.set(key, control.value);
        if (search?.value) params.set("search", search.value);
        if (append && cursor) params.set("before", cursor);
        try {
            const response = await fetch("/api/nightshift/report-shifts?" + params, {
                headers: { Accept: "application/json" },
                credentials: "same-origin",
            });
            const data = await response.json();
            if (request !== sequence || !form.isConnected) return;
            if (!response.ok) throw new Error(data.message || t("Unable to load shifts."));
            const value = shift.value;
            if (!append) items = new Map();
            for (const item of [...data.shifts, ...(data.selected ? [data.selected] : [])])
                items.set(String(item.id), item);
            shift.replaceChildren(new Option(t("All Shifts"), ""));
            for (const [id, item] of items)
                shift.add(
                    new Option(
                        `${item.number} · ${item.employee} · ${item.counter} · ${item.opened_at} → ${item.closed_at || t("Now")} · ${t(item.status)}`,
                        id,
                    ),
                );
            shift.value = items.has(value) ? value : "";
            cursor = data.next_before;
            if (more) more.hidden = !cursor;
            summary();
        } catch (error) {
            box.textContent = error.message;
        }
    }
    form.addEventListener("change", (event) => {
        if ([branch, unit, counter].includes(event.target)) {
            shift.value = "";
            hierarchy();
            summary();
            void load();
        } else if (event.target === shift) summary();
        else return;
        change();
    });
    let timer;
    search?.addEventListener("input", () => {
        clearTimeout(timer);
        timer = setTimeout(() => load(), 250);
    });
    more?.addEventListener("click", () => load(true));
    hierarchy();
    void load();
}

for (const form of document.querySelectorAll("form[data-report-shifts]")) {
    const options = JSON.parse(
        form.querySelector("[data-report-shift-options]")?.textContent || "{}",
    );
    mountReportShifts(form, form.dataset.reportShifts, options);
}
