import { t } from "./localization.js";

const controls = new WeakMap();
let nextId = 0;
let openControl;

const labels = {
    product: "Search product…",
    cutover_product_unit_id: "Search product…",
    supplier: "Search supplier…",
    business_unit: "Search Business Unit…",
    business_unit_id: "Search Business Unit…",
    destination_business_unit_id: "Search Business Unit…",
    location: "Search stock location…",
    "location-display": "Search stock location…",
    stock_location_id: "Search stock location…",
    source_location_id: "Search stock location…",
    from: "Search stock location…",
    to: "Search stock location…",
    counter: "Search stock location…",
    branch: "Search branch…",
    branch_id: "Search branch…",
    waiter: "Search staff…",
    waiter_id: "Search staff…",
    cashier: "Search staff…",
    user: "Search staff…",
    user_id: "Search staff…",
    staff_id: "Search staff…",
    employee_id: "Search staff…",
    to_user_id: "Search staff…",
    category: "Search category…",
};

const changeLabels = {
    "business-unit": labels.business_unit_id,
    "stock-location": labels.stock_location_id,
    "cart-counter": labels.counter,
    "cart-table": "Search table…",
    "category-filter": labels.category,
    branch: labels.branch,
};

export function matchingOptions(options, query) {
    const search = query.trim().toLowerCase();
    return Array.from(options).filter((option) => !option.hidden && !option.disabled
        && !option.parentElement?.hidden && !option.parentElement?.disabled
        && (option.label + " " + (option.dataset.searchText || "")).toLowerCase().includes(search));
}

function accessibleLabel(select, fallback) {
    if (select.getAttribute("aria-label")) return select.getAttribute("aria-label");
    const label = select.labels[0]?.cloneNode(true);
    if (!label) return fallback;
    label.querySelectorAll("select, .searchable-select").forEach((element) => element.remove());
    return label.textContent.trim() || fallback;
}

export function makeSearchableSelect(select, placeholder) {
    if (controls.has(select)) return;
    const id = "searchable-select-" + ++nextId;
    const create = (tag, className) => {
        const element = document.createElement(tag);
        element.className = className;
        return element;
    };
    const wrapper = create("span", "searchable-select");
    const input = create("input", "searchable-select-input");
    const list = create("span", "searchable-select-list");
    const panel = create("span", "searchable-select-panel");
    const message = create("span", "searchable-select-empty");
    const caret = create("span", "searchable-select-caret");
    caret.setAttribute("aria-hidden", "true");
    input.type = "text";
    input.id = id;
    input.autocomplete = "off";
    input.spellcheck = false;
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-controls", id + "-options");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("aria-label", accessibleLabel(select, t(placeholder)));
    for (const attribute of ["aria-labelledby", "aria-describedby"]) {
        if (select.hasAttribute(attribute)) input.setAttribute(attribute, select.getAttribute(attribute));
    }
    if (select.required) input.setAttribute("aria-required", "true");
    list.id = id + "-options";
    list.setAttribute("role", "listbox");
    list.setAttribute("aria-label", input.getAttribute("aria-label"));
    message.setAttribute("role", "status");
    panel.hidden = true;
    panel.append(list, message);
    wrapper.append(input, caret, panel);
    const fieldLabels = Array.from(select.labels);
    select.insertAdjacentElement("afterend", wrapper);
    select.classList.add("searchable-select-native");
    select.tabIndex = -1;
    select.setAttribute("aria-hidden", "true");
    fieldLabels.forEach((label) => { label.htmlFor = id; });

    let options = [];
    let active = -1;
    let opened = false;

    function position() {
        if (!opened) return;
        const rect = input.getBoundingClientRect();
        const viewport = window.visualViewport;
        const top = viewport?.offsetTop ?? 0;
        const left = viewport?.offsetLeft ?? 0;
        const bottom = top + (viewport?.height ?? document.documentElement.clientHeight);
        const right = left + (viewport?.width ?? document.documentElement.clientWidth);
        const below = bottom - rect.bottom - 8;
        const above = rect.top - top - 8;
        const flip = below < 180 && above > below;
        panel.style.left = Math.max(left + 4, rect.left) + "px";
        panel.style.width = Math.min(rect.width, right - Math.max(left + 4, rect.left) - 4) + "px";
        panel.style.maxHeight = Math.max(44, Math.min(272, flip ? above : below)) + "px";
        panel.style.top = flip ? "auto" : rect.bottom + 4 + "px";
        panel.style.bottom = flip ? document.documentElement.clientHeight - rect.top + 4 + "px" : "auto";
    }

    function highlight(index, reveal = false) {
        list.children[active]?.classList.remove("is-active");
        active = index;
        const row = list.children[index];
        if (!row) {
            input.removeAttribute("aria-activedescendant");
            return;
        }
        row.classList.add("is-active");
        input.setAttribute("aria-activedescendant", row.id);
        if (reveal) {
            const box = panel.getBoundingClientRect();
            const item = row.getBoundingClientRect();
            if (item.top < box.top) panel.scrollTop += item.top - box.top;
            else if (item.bottom > box.bottom) panel.scrollTop += item.bottom - box.bottom;
        }
    }

    function filter(query) {
        options = matchingOptions(select.options, query);
        const selected = select.selectedOptions[0];
        list.replaceChildren(...options.map((option, index) => {
            const row = create("span", "searchable-select-option");
            row.id = id + "-" + index;
            row.dataset.index = index;
            row.setAttribute("role", "option");
            row.setAttribute("aria-selected", String(option === selected));
            row.textContent = option.label;
            return row;
        }));
        message.hidden = options.length !== 0;
        message.textContent = options.length ? "" : t("No matching options");
        panel.scrollTop = 0;
        active = -1;
        position();
        const current = options.indexOf(selected);
        highlight(query ? 0 : current, true);
    }

    function refresh() {
        input.disabled = select.disabled;
        input.placeholder = t(placeholder);
        if (opened && !select.disabled) {
            filter(input.value);
            return;
        }
        if (opened) close();
        const value = select.selectedOptions[0];
        input.value = value?.label || "";
    }

    function close() {
        if (!opened) return;
        opened = false;
        if (openControl === control) openControl = undefined;
        input.setAttribute("aria-expanded", "false");
        input.removeAttribute("aria-activedescendant");
        panel.hidden = true;
        refresh();
    }

    function open(query = "") {
        if (select.disabled) return;
        openControl?.close();
        opened = true;
        openControl = control;
        input.value = query;
        input.setAttribute("aria-expanded", "true");
        panel.hidden = false;
        input.focus({ preventScroll: true });
        filter(query);
    }

    function choose(index) {
        const option = options[index];
        if (!option || option.disabled || option.hidden || option.closest("select") !== select) return;
        const changed = select.selectedIndex !== option.index;
        select.selectedIndex = option.index;
        input.removeAttribute("aria-invalid");
        close();
        if (changed) {
            select.dispatchEvent(new Event("input", { bubbles: true }));
            select.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }

    const control = { refresh, close, position, contains: (target) => wrapper.contains(target), wrapper };
    controls.set(select, control);
    input.addEventListener("focus", () => { if (!opened) input.select(); });
    input.addEventListener("click", () => { if (!opened) open(); });
    input.addEventListener("blur", close);
    input.addEventListener("input", (event) => {
        event.stopPropagation();
        if (opened) filter(input.value);
        else open(input.value);
    });
    input.addEventListener("keydown", (event) => {
        if (event.isComposing) return;
        if (event.key === "Tab") {
            close();
        } else if (event.key === "Escape" && opened) {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (["ArrowDown", "ArrowUp"].includes(event.key)) {
            event.preventDefault();
            if (!opened) {
                open();
                if (active < 0) highlight(event.key === "ArrowDown" ? 0 : options.length - 1, true);
            } else if (options.length) {
                highlight(event.key === "ArrowDown" ? Math.min(active + 1, options.length - 1)
                    : active < 0 ? options.length - 1 : Math.max(0, active - 1), true);
            }
        } else if (event.key === "Enter") {
            event.preventDefault();
            if (!opened) open();
            else choose(active);
        }
    });
    list.addEventListener("mousedown", (event) => event.preventDefault());
    list.addEventListener("click", (event) => {
        const row = event.target.closest("[data-index]");
        if (row) {
            event.preventDefault();
            choose(Number(row.dataset.index));
        }
    });
    select.addEventListener("change", refresh);
    select.form?.addEventListener("reset", () => queueMicrotask(refresh));
    select.addEventListener("invalid", (event) => {
        event.preventDefault();
        input.setAttribute("aria-invalid", "true");
        input.focus();
        if (!opened) open();
    });
    refresh();
    return control;
}

export function refreshSearchableSelect(select) {
    controls.get(select)?.refresh();
}

export function mountSearchableSelects(root) {
    function update(select) {
        if (controls.has(select)) {
            refreshSearchableSelect(select);
            return;
        }
        if (select.multiple || select.size > 1) return;
        const stockForm = select.closest('[data-form="grn"], [data-form="transfer"], [data-form="adjustment"]');
        const name = select.name;
        const stock = stockForm && ["supplier", "product", "location", "from", "to"].includes(name);
        const placeholder = stock && stockForm.dataset.unitStock === "1" && ["location", "from", "to"].includes(name)
            ? labels.business_unit_id
            : Object.hasOwn(labels, name) ? labels[name] : changeLabels[select.dataset.change];
        if (placeholder && (stock || select.options.length >= 6)) makeSearchableSelect(select, placeholder);
    }

    const scan = (element) => {
        if (element.matches?.("select")) update(element);
        else element.querySelectorAll?.("select").forEach(update);
    };
    scan(root);
    new MutationObserver((records) => {
        if (openControl && !openControl.wrapper.isConnected) openControl.close();
        const selects = new Set();
        for (const record of records) {
            const select = record.target.closest?.("select");
            if (select) selects.add(select);
            else if (!record.target.closest?.(".searchable-select")) {
                for (const added of record.addedNodes) if (added.nodeType === 1) scan(added);
            }
        }
        selects.forEach(update);
    }).observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ["disabled", "hidden", "label", "selected", "data-search-text"] });
    root.addEventListener("pointerdown", (event) => {
        if (openControl && !openControl.contains(event.target)) openControl.close();
    }, true);
    root.addEventListener("scroll", (event) => {
        if (!openControl?.contains(event.target)) openControl?.position();
    }, true);
    window.addEventListener("resize", () => openControl?.position());
    window.visualViewport?.addEventListener("resize", () => openControl?.position());
    window.visualViewport?.addEventListener("scroll", () => openControl?.position());
}
