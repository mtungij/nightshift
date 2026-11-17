// Server-rendered workspace content uses the same language preference as the shell.
if (document.querySelector("[data-workspace-page]")) {
    window.addEventListener("nightshift:language", () => location.reload());
}

for (const form of document.querySelectorAll("[data-business-unit-action]")) {
    form.addEventListener("input", (event) => {
        delete form.dataset.requestKey;
        if (event.target.name !== "order_collection_mode") return;
        for (const description of form.querySelectorAll("[data-mode-description]")) {
            description.hidden = description.dataset.modeDescription !== event.target.value;
        }
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const status = document.querySelector("[data-business-unit-status]");
        const button = form.querySelector("button");
        button.disabled = true;
        try {
            const fields = new FormData(form),
                data = Object.fromEntries(fields);
            if (["staff", "location"].includes(form.dataset.businessUnitAction)) {
                data.unit_ids = fields.getAll("unit_ids[]").map(Number);
                delete data["unit_ids[]"];
            }
            if (form.querySelector('[name="branch_ids[]"]')) {
                data.branch_ids = fields.getAll("branch_ids[]").map(Number);
                delete data["branch_ids[]"];
            }
            if (form.querySelector('[name="counter_ids[]"]')) {
                data.counter_ids = fields.getAll("counter_ids[]").map(Number);
                delete data["counter_ids[]"];
            }
            if (form.dataset.businessUnitAction === "cutover") {
                data.items = [
                    {
                        product_unit_id: Number(data.cutover_product_unit_id),
                        quantity: data.cutover_quantity,
                    },
                ];
                delete data.cutover_product_unit_id;
                delete data.cutover_quantity;
            }
            if (form.dataset.businessUnitAction === "product") {
                data.prices = [];
                for (const [key, value] of fields) {
                    const match = key.match(/^prices\[(\d+)\]\[(\w+)\]$/);
                    if (match) {
                        (data.prices[Number(match[1])] ||= {})[match[2]] = value || null;
                        delete data[key];
                    }
                }
            }
            const response = await fetch("/api/business-units/" + form.dataset.businessUnitAction, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": document.querySelector("[name=csrf-token]").content,
                },
                body: JSON.stringify({
                    ...data,
                    idempotency_key: (form.dataset.requestKey ||= crypto.randomUUID()),
                }),
            });
            const result = await response.json();
            if (!response.ok)
                throw Error(Object.values(result.errors || {}).flat()[0] || result.message);
            location.reload();
        } catch (error) {
            status.textContent = error.message;
            status.scrollIntoView({ block: "center" });
        } finally {
            button.disabled = false;
        }
    });
}

const productSection = document.querySelector("[data-unit-products]");
const productSearch = productSection?.querySelector("[data-unit-product-search]");
if (productSearch) {
    const products = [...productSection.querySelectorAll("[data-unit-product]")];
    const empty = productSection.querySelector("[data-unit-product-empty]");
    const filter = () => {
        const query = productSearch.value.trim().toLocaleLowerCase();
        for (const product of products) {
            product.hidden = !product.dataset.unitProduct.toLocaleLowerCase().includes(query);
        }
        empty.hidden = products.some((product) => !product.hidden);
    };
    productSearch.addEventListener("input", filter);
    filter();
}
