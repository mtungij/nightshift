import { html, t } from "./localization.js";
const escape = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
    );

export function renderCounterCard(location, branch, badge) {
    const counter = location.type === "COUNTER",
        active = location.active_cashier_count,
        max = location.max_active_cashiers;
    return html`<article class="panel counter-card" data-counter-card="${location.id}">
        <h2>${escape(location.name)}</h2>
        <p>${t(location.type)} · ${escape(branch)}</p>
        <p>
            ${location.is_shared ? t("Shared across Business Units") : t("Business Unit") + ": " + escape(location.business_unit_name || "—")}
        </p>
        ${badge(location.active ? "Active" : "Inactive")}${
            counter
                ? html`<form
                      data-cashier-limit
                      class="cashier-limit-form"
                      data-saved-limit="${max}"
                  >
                      <p data-counter-occupancy>${t("Active Cashiers")}: ${active} / ${max}</p>
                      <span class="badge amber" data-counter-full ${active >= max ? "" : "hidden"}
                          >${t("Cashier limit reached")}</span
                      ><input type="hidden" name="id" value="${location.id}" /><label
                          class="form-field"
                          ><span>${t("Maximum Active Cashiers")}</span
                          ><input
                              name="max_active_cashiers"
                              type="number"
                              min="1"
                              max="65535"
                              step="1"
                              required
                              value="${max}"
                          /><small
                              >${t("Maximum number of cashier shifts that may be open on this counter at the same time.")}</small
                          ></label
                      ><button class="button" type="submit">${t("Save")}</button>
                      <p data-cashier-limit-status role="status" aria-live="polite"></p>
                  </form>`
                : ""
        }
    </article>`;
}

export function applyCounterLocation(form, location) {
    const max = location.max_active_cashiers,
        active = location.active_cashier_count;
    form.dataset.savedLimit = String(max);
    form.elements.max_active_cashiers.value = String(max);
    form.querySelector("[data-counter-occupancy]").textContent =
        `${t("Active Cashiers")}: ${active} / ${max}`;
    form.querySelector("[data-counter-full]").hidden = active < max;
    // Keep the separately rendered location identity occupancy synchronized as well.
    const identity = form.previousElementSibling?.querySelector(
        "[data-counter-identity-occupancy]",
    );
    if (identity) identity.textContent = `${t("Active Cashiers")}: ${active} / ${max}`;
}
