import { t } from "./localization.js";

const page = document.querySelector(".whatsapp-page");
if (page) {
    page.dataset.configuredDevice = page.querySelector("[data-whatsapp-device]").value;
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
        if (action === "device") page.dataset.deviceSaved = "true";
        page.dataset.configuredDevice = data.device_id || "";
        if (data.device_id) page.querySelector("[data-whatsapp-device]").value = data.device_id;
        if (data.status) {
            const statuses = {
                CONNECTED: "Connected",
                NEEDS_RELINKING: "Needs relinking",
                CONNECTING: "Connecting",
                DISCONNECTED: "Disconnected",
                NOT_CONFIGURED: "Not configured",
                NOT_FOUND: "Not Found",
                PROVIDER_ERROR: "Provider Error",
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
        page.querySelector("[data-whatsapp-connection-jid]").textContent = data.jid || "—";
        page.querySelector("[data-whatsapp-connection-checked]").textContent = data.last_checked_at
            ? new Date(data.last_checked_at).toLocaleString()
            : "—";
        const warning = page.querySelector("[data-whatsapp-connection-warning]");
        warning.hidden = data.status === "CONNECTED";
        warning.textContent = t(
            data.status === "NOT_FOUND"
                ? "Device ID was not found on the WhatsApp server."
                : "Manage device linking on the WhatsApp server, then test the connection here.",
        );
    }
    function updateRecipient(form) {
        const allBranches = form.elements.all_branches?.checked || false;
        const selected = new Set(
            [...form.querySelectorAll('[name="branch_ids[]"]:checked')].map((e) => e.value),
        );
        form.querySelectorAll('[name="branch_ids[]"]').forEach((e) => (e.disabled = allBranches));
        form.querySelectorAll('[name="business_unit_ids[]"]').forEach((e) => {
            const allowed = allBranches || selected.has(e.dataset.branch);
            e.disabled = !allowed || form.elements.all_business_units?.checked || false;
            e.closest("label").hidden = !allowed;
            if (!allowed) e.checked = false;
        });
    }
    page.querySelectorAll('[data-whatsapp-form="recipient"]').forEach(updateRecipient);
    page.addEventListener("change", (event) => {
        const form = event.target.closest('[data-whatsapp-form="recipient"]');
        if (form) updateRecipient(form);
    });
    function updateSchedule(form) {
        const kind = form.elements.scope.value,
            branch = form.elements.branch_id,
            unit = form.elements.business_unit_id,
            counter = form.elements.stock_location_id;
        form.querySelectorAll("[data-schedule-frequency]").forEach(
            (e) => (e.hidden = e.dataset.scheduleFrequency !== form.elements.report_type.value),
        );
        branch.disabled = kind === "COMPANY";
        branch.required = !branch.disabled;
        unit.disabled = kind !== "UNIT" || !branch.value;
        unit.required = kind === "UNIT";
        form.querySelectorAll("[data-schedule-scope-field]").forEach(
            (e) =>
                (e.hidden =
                    kind === "COMPANY" ||
                    (e.dataset.scheduleScopeField === "business_unit_id" && kind !== "UNIT")),
        );
        for (const control of [unit, counter]) {
            for (const option of control.options) {
                const incompatible =
                    option.value &&
                    ((!branch.disabled && branch.value && option.dataset.branch !== branch.value) ||
                        (control === counter &&
                            !unit.disabled &&
                            unit.value &&
                            option.dataset.unit !== unit.value));
                option.disabled = Boolean(incompatible);
                option.hidden = Boolean(incompatible);
            }
            if (control.selectedOptions[0]?.disabled) control.value = "";
        }
        counter.disabled = kind === "COMPANY" || !branch.value || (kind === "UNIT" && !unit.value);
        const advanced = unit.selectedOptions[0]?.dataset.advanced === "1";
        counter.closest("[data-schedule-scope-field]").hidden = !advanced;
        if (!advanced) {
            counter.disabled = true;
            counter.value = "";
        }
    }
    page.querySelectorAll('[data-whatsapp-form="schedule"]').forEach(updateSchedule);
    page.addEventListener("change", (event) => {
        const form = event.target.closest('[data-whatsapp-form="schedule"]');
        if (form) updateSchedule(form);
    });
    page.querySelector("[data-whatsapp-device]").addEventListener("input", () => {
        page.querySelectorAll('[data-whatsapp-connection="status"]').forEach(
            (button) => (button.disabled = true),
        );
    });
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
            if (page.dataset.deviceSaved) {
                page.querySelectorAll('[data-whatsapp-connection="status"]').forEach(
                    (button) => (button.disabled = false),
                );
                delete page.dataset.deviceSaved;
            }
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
                if (!input.name.endsWith("[]")) data[input.name] = input.checked;
            });
            const type = form.dataset.whatsappForm;
            if (type === "settings") {
                const result = await request("", data);
                notice(result.message);
            } else if (type === "recipient") {
                data.branch_ids = [...form.querySelectorAll('[name="branch_ids[]"]:checked')].map(
                    (i) => Number(i.value),
                );
                for (const key of ["business_unit_ids", "enabled_types"]) {
                    data[key] = [...form.querySelectorAll(`[name="${key}[]"]:checked`)].map((i) =>
                        key === "enabled_types" ? i.value : Number(i.value),
                    );
                    delete data[key + "[]"];
                }
                data.all_business_units ??= false;
                delete data["branch_ids[]"];
                data.all_branches ??= false;
                await request(`/recipients/${form.dataset.recipientId || ""}`, data);
                history.replaceState(null, "", "#recipients");
                location.reload();
            } else if (type === "schedule") {
                data.recipient_ids = [
                    ...form.querySelectorAll('[name="recipient_ids[]"]:checked'),
                ].map((i) => Number(i.value));
                delete data["recipient_ids[]"];
                for (const key of ["branch_id", "business_unit_id", "stock_location_id"])
                    data[key] = data[key] ? Number(data[key]) : null;
                await request(`/schedules/${form.dataset.scheduleId || ""}`, data);
                history.replaceState(null, "", "#reports");
                location.reload();
            } else if (type === "device") await connection("device", { device_id: data.device_id });
            else {
                const fingerprint = JSON.stringify({
                    device_id: page.dataset.configuredDevice,
                    type: button.value,
                    ...data,
                });
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
