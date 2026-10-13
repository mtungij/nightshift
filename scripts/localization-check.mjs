import assert from "node:assert/strict";
import { html as translatedHtml, t } from "../resources/js/localization.js";

globalThis.window = {
    NightshiftI18n: {
        locale: "sw",
        messages: {
            Orders: "Oda",
            Waiter: "Mhudumu",
            "Send to cashier": "Tuma kwa Keshia",
            "Search products": "Tafuta Bidhaa",
        },
    },
};
const html = (...args) =>
    translatedHtml(...args)
        .replace(/\s+/g, " ")
        .replace(/>\s+</g, "><")
        .replace(/\s*\/>/g, ">");
const name = "Orders";
assert.equal(
    html`<h1>Orders</h1>
        <p>${name}</p>`,
    "<h1>Oda</h1><p>Orders</p>",
);
assert.equal(
    html`<button data-value="Orders">Orders</button>`,
    '<button data-value="Orders">Oda</button>',
);
assert.equal(
    html`<input value="Orders" name="Orders" placeholder="Search products" />`,
    '<input value="Orders" name="Orders" placeholder="Tafuta Bidhaa">',
);
assert.equal(
    html`<div><span>Waiter</span>${html`<button>Send to cashier</button>`}</div>`,
    "<div><span>Mhudumu</span><button>Tuma kwa Keshia</button></div>",
);
assert.equal(html`<p>Waiter: ${name}</p>`, "<p>Mhudumu: Orders</p>");
assert.equal(t("Custom business name"), "Custom business name");
window.NightshiftI18n.messages = {};
assert.equal(html`<h1>Orders</h1>`, "<h1>Orders</h1>");
console.log(
    "PASS: 7 localization checks; interpolated data and canonical attributes remain unchanged.",
);
