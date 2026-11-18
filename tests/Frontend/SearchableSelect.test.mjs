import assert from "node:assert/strict";
import test from "node:test";
import { matchingOptions, mountSearchableSelects } from "../../resources/js/searchable-select.js";

function option(label, { value = label, searchText, hidden, disabled, parentElement } = {}) {
    return { label, value, dataset: { searchText }, hidden, disabled, parentElement };
}

test("search is partial and case-insensitive, and uses existing contact metadata", () => {
    const options = [
        option("Pepsi"),
        option("Johnnie Walker Black 750ml"),
        option("Coastal Supplies", { searchText: "+255 754 812 389" }),
        option("Pepsi unavailable", { disabled: true }),
        option("Pepsi hidden", { hidden: true }),
        option("Pepsi disabled group", { parentElement: { disabled: true } }),
    ];
    assert.deepEqual(matchingOptions(options, " pEp ").map((item) => item.label), ["Pepsi"]);
    assert.deepEqual(matchingOptions(options, "john"), [options[1]]);
    assert.deepEqual(matchingOptions(options, "750"), [options[1]]);
    assert.deepEqual(matchingOptions(options, "812 389"), [options[2]]);
    assert.deepEqual(matchingOptions(options, "not found"), []);
});

class Element {
    constructor(tag) {
        this.tag = tag;
        this.style = {};
        this.dataset = {};
        this.children = [];
        this.attributes = new Map();
        this.events = new Map();
        this.classList = new Set();
        this.classList.remove = this.classList.delete;
        this.isConnected = true;
    }

    setAttribute(name, value) { this.attributes.set(name, value); }
    getAttribute(name) { return this.attributes.get(name); }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    addEventListener(name, handler) {
        const handlers = this.events.get(name) || [];
        this.events.set(name, [...handlers, handler]);
    }
    emit(name, extra = {}) {
        const event = { target: this, preventDefault() {}, stopPropagation() {}, ...extra };
        this.events.get(name)?.forEach((handler) => handler(event));
    }
    dispatchEvent(event) { this.emit(event.type); }
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = items; }
    contains(item) { return item === this || this.children.some((child) => child.contains(item)); }
    insertAdjacentElement(_, element) { this.enhancement = element; }
    getBoundingClientRect() { return { left: 20, width: 230, top: 20, bottom: 64 }; }
    closest() { return null; }
    focus() {}
    select() {}
}

test("keyboard and outside pointer handling preserve the native value and refresh scoped options", () => {
    const select = new Element("select");
    select.name = "product";
    select.labels = [];
    select.selectedIndex = 0;
    select.closest = (selector) => selector.includes("data-form") ? { dataset: { form: "grn" } } : null;
    Object.defineProperties(select, {
        selectedOptions: { get: () => [select.options[select.selectedIndex]].filter(Boolean) },
        value: { get: () => select.selectedOptions[0]?.value ?? "" },
    });
    function updateOptions(...choices) {
        select.options = choices.map(([label, value], index) => ({
            ...option(label, { value }), index, closest: () => select,
        }));
        select.selectedIndex = choices.length ? 0 : -1;
    }
    updateOptions(["Johnnie Walker Black 750ml", "7"], ["Pepsi", "3"]);
    const body = new Element("body");
    body.querySelectorAll = () => [select];
    const window = new Element("window");
    window.NightshiftI18n = { messages: {} };
    Object.assign(globalThis, { window, document: {
        createElement: (tag) => new Element(tag),
        documentElement: { clientWidth: 390, clientHeight: 844 },
    } });
    let mutations;
    globalThis.MutationObserver = class {
        constructor(callback) { mutations = callback; }
        observe() {}
    };
    mountSearchableSelects(body);
    const [search, , panel] = select.enhancement.children;
    const [list, empty] = panel.children;
    let changes = 0;
    select.addEventListener("change", () => changes++);

    search.emit("click");
    search.value = "pEp";
    search.emit("input");
    assert.equal(list.children[0].textContent, "Pepsi");
    search.emit("keydown", { key: "Enter" });
    assert.equal(select.value, "3");
    assert.equal(search.value, "Pepsi");
    assert.equal(changes, 1);
    assert.equal(panel.hidden, true);
    search.emit("click");
    assert.equal(list.children[1].getAttribute("aria-selected"), "true");
    search.emit("keydown", { key: "Escape" });
    assert.equal(panel.hidden, true);

    search.emit("click");
    body.emit("pointerdown", { target: body });
    assert.equal(panel.hidden, true);
    search.emit("click");
    search.emit("keydown", { key: "Tab" });
    assert.equal(panel.hidden, true);

    search.emit("click");
    search.emit("keydown", { key: "ArrowUp" });
    assert.equal(search.getAttribute("aria-activedescendant"), list.children[0].id);
    search.emit("keydown", { key: "Enter" });
    assert.equal(select.value, "7");
    search.emit("click");
    list.emit("click", { target: { closest: () => list.children[1] } });
    assert.equal(select.value, "3");
    assert.equal(panel.hidden, true);

    select.disabled = true;
    updateOptions();
    mutations([{ target: { closest: () => select } }]);
    assert.equal(search.disabled, true);
    assert.equal(select.value, "");

    select.disabled = false;
    updateOptions(["Chicken Wings", "11"]);
    mutations([{ target: { closest: () => select } }]);
    assert.equal(search.value, "Chicken Wings");
    search.emit("click");
    search.value = "Pepsi";
    search.emit("input");
    assert.equal(list.children.length, 0);
    assert.equal(empty.textContent, "No matching options");
    search.emit("keydown", { key: "Enter" });
    assert.equal(select.value, "11");
    assert.equal(changes, 3);
    search.emit("keydown", { key: "Escape" });
});
