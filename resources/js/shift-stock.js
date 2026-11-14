import { quantity } from "./quantity.js";
const root = document.querySelector("#shift-stock");
if (root) {
    const data = window.ShiftStockData,
        t = (text) => window.ShiftStockMessages[text] || text;
    const rows = [...root.querySelectorAll("[data-recon-row]")];
    const message = root.querySelector("#recon-message");
    const amount = (n) =>
        Number(n).toLocaleString(document.documentElement.lang === "sw" ? "sw-TZ" : "en-TZ", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    function filter() {
        const query = root.querySelector("#recon-search").value.toLowerCase(),
            category = root.querySelector("#recon-category").value,
            only = root.querySelector("#recon-variance-only").checked;
        for (const row of rows)
            row.hidden =
                !row.dataset.name.toLowerCase().includes(query) ||
                (category && row.dataset.category !== category) ||
                (only && row.dataset.variance !== "true");
    }
    function totals() {
        let variance = 0,
            sales = 0;
        for (const row of rows) {
            const value = row.querySelector(".physical-count").value;
            const counted = value !== "" && Number.isFinite(Number(value));
            const difference = Number(value) - Number(row.dataset.expected);
            row.dataset.variance = String(counted && Math.abs(difference) > 0.0000005);
            row.classList.toggle("recon-unbalanced", row.dataset.variance === "true");
            row.querySelector("[data-variance-cell]").textContent = counted
                ? quantity(difference) +
                  " · " +
                  t(
                      Math.abs(difference) < 0.0000005
                          ? "Balanced"
                          : difference < 0
                            ? "Short"
                            : "Over",
                  )
                : t("Not counted");
            const sale = (Number(row.dataset.total) - Number(value)) * Number(row.dataset.price);
            row.querySelector("[data-sales-cell]").textContent = counted ? amount(sale) : "—";
            if (counted) {
                variance += Math.abs(difference) * Number(row.dataset.price);
                sales += sale;
            }
        }
        root.querySelector("#recon-variance-total").textContent = amount(variance);
        root.querySelector("#recon-sales-total").textContent = amount(sales);
        filter();
    }
    for (const row of rows) row.querySelector(".physical-count").addEventListener("input", totals);
    for (const id of ["recon-search", "recon-category", "recon-variance-only"])
        root.querySelector("#" + id).addEventListener("input", filter);
    root.querySelector("[name=actual_cash]")?.addEventListener(
        "input",
        (event) =>
            (root.querySelector("#recon-cash-difference").textContent =
                t("Cash variance") +
                ": " +
                amount(Number(event.target.value) - Number(data.cash.expected))),
    );
    async function post(form, command, payload) {
        const buttons = [...form.querySelectorAll("button")];
        buttons.forEach((b) => (b.disabled = true));
        message.textContent = "";
        try {
            const response = await fetch("/api/nightshift/commands/" + command, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": document.querySelector("[name=csrf-token]").content,
                },
                body: JSON.stringify({
                    ...payload,
                    idempotency_key: (form.dataset.requestKey ||= crypto.randomUUID()),
                }),
            });
            if ([401, 419].includes(response.status)) {
                location.href = "/login";
                return;
            }
            const result = await response.json();
            if (!response.ok)
                throw Error(Object.values(result.errors || {}).flat()[0] || result.message);
            location.reload();
        } catch (error) {
            message.textContent = error.message;
            message.scrollIntoView({ block: "center" });
            buttons.forEach((b) => (b.disabled = false));
        }
    }
    root.querySelectorAll("form").forEach((form) =>
        form.addEventListener("input", () => delete form.dataset.requestKey),
    );
    root.querySelector("#recon-count-form")?.addEventListener("submit", (event) => {
        event.preventDefault();
        const missing = rows.find((row) => row.querySelector(".physical-count").value === "");
        if (missing) {
            message.textContent = t("Count every product in this reconciliation.");
            root.querySelector("#recon-search").value = "";
            root.querySelector("#recon-category").value = "";
            root.querySelector("#recon-variance-only").checked = false;
            filter();
            missing.querySelector("input").focus();
            return;
        }
        post(event.target, "shift-stock-count", {
            id: data.shift_id,
            actual_cash: event.target.elements.actual_cash.value,
            basis: data.basis,
            revision: data.revision,
            reason: event.target.elements.reason.value,
            counts: rows.map((row) => ({
                product_id: Number(row.dataset.product),
                quantity: row.querySelector("input").value,
            })),
        });
    });
    root.querySelector("#recon-approve-form")?.addEventListener("submit", (event) => {
        event.preventDefault();
        post(event.target, "shift-stock-approve", {
            id: data.shift_id,
            basis: data.basis,
            revision: data.revision,
            note: event.target.elements.note.value,
        });
    });
    const closeForm = root.querySelector("#recon-close-form");
    const destination = closeForm?.elements.destination;
    function recipients() {
        if (!destination) return;
        closeForm.querySelector('button').textContent = t(destination.value === 'TREASURY' ? 'Request cash receipt' : 'Confirm and Close Shift');
        const select = closeForm.elements.to_user_id;
        for (const option of select.options) {
            option.disabled = destination.value === 'NEXT_CASHIER' ? option.dataset.cashier !== '1' || Number(option.value) === Number(data.cashier_user_id) : option.dataset.treasury !== '1';
            option.hidden = option.disabled;
        }
        if (select.selectedOptions[0]?.disabled) select.value = [...select.options].find(o => !o.disabled)?.value || '';
    }
    destination?.addEventListener('change', recipients);
    recipients();
    let staged = Boolean(data.custody);
    closeForm?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (destination && !staged) {
            const button = event.target.querySelector('button');
            button.disabled = true;
            try {
                const response = await fetch('/api/cash-custody/stage', {method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},body:JSON.stringify({shift_id:data.shift_id,destination:destination.value,to_user_id:closeForm.elements.to_user_id.value,amount:data.cash.actual,note:closeForm.elements.notes.value,idempotency_key:closeForm.dataset.handoverKey ||= crypto.randomUUID()})});
                const result = await response.json();
                if (!response.ok) throw Error(Object.values(result.errors || {}).flat()[0] || result.message);
                staged = true;
                closeForm.elements.to_user_id.disabled = true;
                destination.disabled = true;
                if (destination.value === 'TREASURY') { location.reload(); return; }
            } catch (error) { message.textContent = error.message; button.disabled = false; return; }
        }
        post(event.target, "shift-close", {
            id: data.shift_id,
            actual_cash: root.querySelector("[name=actual_cash]").value,
            notes: event.target.elements.notes.value,
        });
    });
    totals();
}
