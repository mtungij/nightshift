import { t } from "./localization.js";

const page = document.querySelector(".whatsapp-page");
if (page) {
    const notice = (message, error = false) => {
        const node = document.getElementById("whatsapp-notice");
        node.hidden = false;
        node.classList.toggle("red", error);
        node.textContent = t(message);
        node.scrollIntoView({ block: "nearest", behavior: "smooth" });
    };
    const selectTab = (key) => {
        page.querySelectorAll("[data-whatsapp-section]").forEach((section) => {
            section.hidden = section.dataset.whatsappSection !== key;
        });
        page.querySelectorAll("[data-whatsapp-tab]").forEach((button) => {
            const selected = button.dataset.whatsappTab === key;
            button.classList.toggle("active", selected);
            button.setAttribute("aria-selected", String(selected));
            if (selected) button.scrollIntoView({ block: "nearest", inline: "center" });
        });
        history.replaceState(null, "", `#${key}`);
    };
    if (page.querySelector(`[data-whatsapp-tab="${CSS.escape(location.hash.slice(1))}"]`))
        selectTab(location.hash.slice(1));
    async function request(path, payload = {}) {
        const response = await fetch(`/settings/whatsapp${path}`, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(payload),
        });
        if (response.status === 423) {
            location.href = "/quick-lock";
            throw Error(t("Your workspace is locked."));
        }
        if (response.status === 401 || response.status === 419) {
            location.href = "/login";
            throw Error(t("Your session expired. Please log in again."));
        }
        const data = await response.json();
        if (!response.ok)
            throw Error(
                t(
                    Object.values(data.errors || {}).flat()[0] ||
                        data.message ||
                        "The request could not be completed.",
                ),
            );
        return data;
    }
    async function connection(action, payload = {}) {
        const data = await request(`/connection/${action}`, payload);
        if (data.device_id) page.querySelector("[data-whatsapp-device]").value = data.device_id;
        if (data.status) {
            const statuses = {
                CONNECTED: "Connected",
                CONNECTING: "Connecting",
                DISCONNECTED: "Disconnected",
                NOT_CONFIGURED: "Not configured",
            };
            page.querySelectorAll("[data-whatsapp-status]").forEach(
                (node) => (node.textContent = t(statuses[data.status] || "Disconnected")),
            );
            if (data.last_checked_at)
                page.querySelector("[data-whatsapp-checked]").textContent = new Date(
                    data.last_checked_at,
                ).toLocaleString();
            page.querySelector("[data-whatsapp-jid]").textContent = data.jid || "—";
            notice(statuses[data.status] || "Disconnected");
        }
        const result = document.getElementById("whatsapp-link-result");
        if (data.qr_image) {
            result.replaceChildren();
            const image = new Image();
            image.src = data.qr_image;
            image.alt = t("Scan to link this bar’s WhatsApp");
            result.append(image);
            setTimeout(() => {
                if (image.isConnected) {
                    image.remove();
                    notice("QR code expired. Get a new QR code.");
                }
            }, data.expires_in * 1000);
        }
        if (data.pair_code) result.textContent = data.pair_code;
    }
    let busy = false;
    async function perform(button, callback) {
        if (busy) return;
        busy = true;
        page.setAttribute("aria-busy", "true");
        const buttons = [
            ...page.querySelectorAll(
                "[data-whatsapp-connection], [data-whatsapp-form] button[type=submit]",
            ),
        ];
        const disabled = new Map(buttons.map((item) => [item, item.disabled]));
        buttons.forEach((item) => (item.disabled = true));
        try {
            await callback();
        } catch (error) {
            notice(error.message, true);
        } finally {
            busy = false;
            page.setAttribute("aria-busy", "false");
            buttons.forEach((item) => (item.disabled = disabled.get(item)));
        }
    }
    page.addEventListener("click", (event) => {
        const tab = event.target.closest("[data-whatsapp-tab]");
        if (tab) return selectTab(tab.dataset.whatsappTab);
        const refresh = event.target.closest("[data-whatsapp-refresh]");
        if (refresh) return location.reload();
        const button = event.target.closest("[data-whatsapp-connection]");
        if (button) perform(button, () => connection(button.dataset.whatsappConnection));
    });
    page.addEventListener("submit", (event) => {
        const form = event.target.closest("[data-whatsapp-form]");
        if (!form) return;
        event.preventDefault();
        const button = event.submitter;
        perform(button, async () => {
            const data = Object.fromEntries(new FormData(form));
            form.querySelectorAll("input[type=checkbox]").forEach((input) => {
                if (input.name !== "branch_ids[]") data[input.name] = input.checked;
            });
            const type = form.dataset.whatsappForm;
            if (type === "settings") {
                const result = await request("", data);
                notice(result.message);
            } else if (type === "recipient") {
                data.branch_ids = [...form.querySelectorAll('[name="branch_ids[]"]:checked')].map(
                    (i) => Number(i.value),
                );
                delete data["branch_ids[]"];
                data.all_branches ??= false;
                await request(`/recipients/${form.dataset.recipientId || ""}`, data);
                location.hash = "recipients";
                location.reload();
            } else if (type === "pairing") await connection("code", { phone: data.phone });
            else {
                const fingerprint = JSON.stringify({ type: button.value, ...data });
                form.requestKeys ||= new Map();
                if (!form.requestKeys.has(fingerprint))
                    form.requestKeys.set(fingerprint, crypto.randomUUID());
                data.idempotency_key = form.requestKeys.get(fingerprint);
                const result = await request(`/send/${button.value}`, data);
                notice(result.message);
            }
        });
    });
}
