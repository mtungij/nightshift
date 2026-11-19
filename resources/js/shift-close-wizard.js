import { quantity } from "./quantity.js";

const stockPattern = /^\d{1,10}(\.\d{1,6})?$/;
const cashPattern = /^\d{1,10}(\.\d{1,2})?$/;

function scaled(value, places) {
    const [whole, fraction = ""] = String(value).split(".");
    return BigInt(whole + fraction.padEnd(places, "0"));
}

function formatQuantityDelta(delta) {
    const digits = String(delta < 0n ? -delta : delta).padStart(7, "0");
    return quantity((delta < 0n ? "-" : "") + digits.slice(0, -6) + "." + digits.slice(-6));
}

export function physicalCount(value, expected) {
    if (!stockPattern.test(value)) return { status: "Not counted", difference: null };
    const difference = scaled(value, 6) - scaled(expected, 6);
    return { status: difference === 0n ? "Balanced" : difference < 0n ? "Short" : "Over", difference };
}

export function cashDifference(actual, expected) {
    return cashPattern.test(actual) ? Number(scaled(actual, 2) - scaled(expected, 2)) : null;
}

export function closeProgress(report, draft) {
    const ordersClear = report.blockers.length === 0;
    const ticketsClear = report.cash.pending_ticket_count === 0;
    const saved = report.complete && report.cash.actual !== null && draft.difference !== null && !draft.dirty && !draft.conflict;
    const noteRequired = draft.difference !== null && draft.difference !== 0;
    const noteReady = !noteRequired || new TextEncoder().encode(draft.notes.trim()).length >= 8;
    const ready = saved && ordersClear && ticketsClear && report.close_requirements.stock === null && noteReady;
    return {
        ordersClear, ticketsClear, saved, noteRequired, noteReady, ready,
        canClose: ready && report.close_requirements.handover === null,
    };
}

const root = typeof document === "undefined" ? null : document.querySelector("#cashier-close-wizard");
if (root) {
    let report = window.ShiftCloseData;
    const t = (label) => window.ShiftCloseMessages[label] || label;
    const select = (selector) => root.querySelector(selector);
    const steps = [...root.querySelectorAll("[data-wizard-step]")];
    const panels = [...root.querySelectorAll("[data-wizard-panel]")];
    const rows = [...root.querySelectorAll("[data-wizard-count-row]")].map((row) => ({
        row, input: row.querySelector("[data-physical-count]"),
        difference: row.querySelector("[data-count-difference]"), status: row.querySelector("[data-count-status]"),
    }));
    const actual = select("[data-wizard-actual]");
    const notes = select("[data-wizard-notes]");
    const reason = select("[data-stock-reason]");
    const destination = select("[data-wizard-destination]");
    const recipient = select("[data-wizard-recipient]");
    const confirmation = select(".close-wizard-confirm");
    const back = select("[data-wizard-back]");
    const next = select("[data-wizard-next]");
    const save = select("[data-wizard-save]");
    const close = select("[data-wizard-close]");
    const stage = select("[data-handover-submit]");
    const error = select("[data-wizard-error]");
    const format = new Intl.NumberFormat(document.documentElement.lang === "sw" ? "sw-TZ" : "en-TZ", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    const money = (value) => value === null ? "—" : "TSh " + format.format(value);
    const refreshMessage = "Shift reconciliation changed. Refresh and review again.";
    const pendingRequests = new Map();
    let step = 0;
    let dirty = false;
    let conflict = false;
    let busy = false;
    let progress;
    let countsComplete;

    const text = (selector, value) => { select(selector).textContent = value; };
    function feedback(message) {
        error.textContent = message || "";
        error.hidden = !message;
        if (message) error.scrollIntoView({ block: "nearest" });
    }

    function render() {
        let counted = 0;
        let variances = 0;
        for (const item of rows) {
            const count = physicalCount(item.input.value.trim(), item.row.dataset.expected);
            if (count.difference !== null) counted++;
            if (count.status === "Short" || count.status === "Over") variances++;
            item.difference.textContent = count.difference === null ? "—" : formatQuantityDelta(count.difference);
            item.status.textContent = t(count.status);
            item.row.classList.toggle("recon-unbalanced", count.status === "Short" || count.status === "Over");
        }
        countsComplete = counted === rows.length;
        const difference = cashDifference(actual.value.trim(), report.cash.expected);
        progress = closeProgress(report, { dirty, conflict, difference, notes: notes.value });
        const stockProblem = report.close_requirements.stock;
        for (const input of [...rows.map(({ input }) => input), actual, reason]) {
            input.disabled = busy;
            input.readOnly = report.custody !== null;
        }
        notes.disabled = destination.disabled = recipient.disabled = busy;
        text("[data-count-progress]", t(countsComplete ? "Complete" : "Incomplete") + " · " + counted + "/" + rows.length);
        text("[data-count-variance]", variances);
        select("[data-approval-needed]").hidden = stockProblem !== "approval";
        select("[data-approval-granted]").hidden = !report.approved || dirty || conflict;
        text("[data-wizard-cash-difference]", difference === null ? "—" : money(difference / 100) + (difference === 0 ? " · " + t("Balanced") : ""));
        select("[data-wizard-note-required]").hidden = progress.noteReady;
        notes.required = progress.noteRequired;
        select("[data-cash-waiters]").hidden = progress.ordersClear;

        text("[data-review-stock]", t(progress.saved ? "Complete" : "Incomplete"));
        text("[data-review-variance]", report.totals.variance_count);
        text("[data-review-actual]", money(report.cash.actual));
        text("[data-review-difference]", progress.saved && difference !== null ? money(difference / 100) : "—");
        const review = select("[data-review-status]");
        const blocked = !progress.ordersClear || !progress.ticketsClear || stockProblem === "ledger" || conflict;
        review.className = "close-wizard-state " + (progress.canClose ? "ready" : blocked ? "blocked" : "attention");
        review.textContent = t(progress.canClose ? "Ready to close" : blocked ? "Blocked" : progress.ready ? "Cash handover required" : stockProblem === "approval" ? "Awaiting manager approval" : "Needs attention");

        const custody = report.custody;
        const positiveCash = Number(report.cash.actual) > 0;
        select("[data-handover-saved]").hidden = !custody;
        select("[data-no-handover]").hidden = positiveCash || !!custody;
        select("[data-handover-choices]").hidden = !positiveCash || !!custody;
        if (custody) {
            text("[data-handover-recipient]", custody.to_name || t("No cash to hand over"));
            text("[data-handover-status]", t(custody.status));
        }
        text("[data-handover-problem]", report.close_requirements.handover ? t(report.close_requirements.handover) : "");
        const eligibility = destination.value === "NEXT_CASHIER" ? "cashier" : "treasury";
        for (const option of recipient.options) {
            option.disabled = option.hidden = option.dataset[eligibility] !== "1";
        }
        if (!recipient.selectedOptions.length || recipient.selectedOptions[0].disabled)
            recipient.value = [...recipient.options].find((option) => !option.disabled)?.value || "";

        const status = [
            progress.ordersClear ? "Complete" : "Blocked",
            progress.ticketsClear ? "Complete" : "Blocked",
            stockProblem === "ledger" ? "Blocked" : !dirty && stockProblem === null ? "Complete" : "Needs attention",
            progress.saved ? "Complete" : "Needs attention",
            progress.ready ? "Complete" : blocked ? "Blocked" : "Needs attention",
            progress.canClose ? "Ready to close" : "Blocked",
        ];
        steps.forEach((button, index) => {
            button.dataset.state = status[index] === "Complete" ? "done" : status[index] === "Blocked" ? "blocked" : "";
            button.querySelector("[data-step-icon]").textContent = status[index] === "Complete" ? "✓" : status[index] === "Blocked" ? "!" : index + 1;
            button.querySelector("[data-step-status]").textContent = " (" + t(status[index]) + ")";
            button.disabled = busy || (index === 4 && !progress.saved) || (index === 5 && !progress.ready);
        });
        save.disabled = busy || conflict || !progress.ordersClear || !!custody || !countsComplete || difference === null || (variances > 0 && reason.value.trim().length < 8) || progress.saved;
        close.disabled = busy || !progress.canClose;
        select("[data-handover-no-recipient]").hidden = !!recipient.value;
        stage.disabled = busy || !progress.ready || !recipient.value;
        back.disabled = busy || step === 0;
        next.hidden = step === 5;
        next.disabled = busy || (step === 2 && !countsComplete) || (step === 3 && !progress.saved && save.disabled) || (step === 4 && !progress.ready);
        select("[data-wizard-refresh]").disabled = busy;
    }

    function go(index, focus = true) {
        if (!steps[index] || steps[index].disabled) return;
        step = index;
        const id = steps[index].dataset.wizardStep;
        for (const button of steps) {
            if (button === steps[index]) button.setAttribute("aria-current", "step");
            else button.removeAttribute("aria-current");
        }
        for (const panel of panels) panel.hidden = panel.dataset.wizardPanel !== id;
        history.replaceState(history.state, "", "#" + id);
        if (focus) {
            panels[index].querySelector("h2").focus({ preventScroll: true });
            const current = steps[index];
            const strip = current.closest("ol");
            strip.closest("nav").scrollIntoView({ block: "start" });
            if (current.getBoundingClientRect().right > strip.getBoundingClientRect().right || current.getBoundingClientRect().left < strip.getBoundingClientRect().left)
                strip.scrollLeft += current.getBoundingClientRect().left - strip.getBoundingClientRect().left;
        }
        render();
    }

    async function request(path, payload = null) {
        const headers = { Accept: "application/json" };
        let options = { credentials: "same-origin", headers, cache: "no-store" };
        if (payload) {
            const fingerprint = JSON.stringify(payload);
            const previous = pendingRequests.get(path);
            const key = previous?.fingerprint === fingerprint ? previous.key : crypto.randomUUID();
            pendingRequests.set(path, { fingerprint, key });
            headers["Content-Type"] = "application/json";
            headers["X-CSRF-TOKEN"] = document.querySelector("[name=csrf-token]").content;
            options = { ...options, method: "POST", body: JSON.stringify({ ...payload, idempotency_key: key }) };
        }
        const response = await fetch(path, options);
        if ([401, 419].includes(response.status)) {
            location.href = "/login";
            throw new Error(t("Your session has expired."));
        }
        const data = await response.json();
        if (!response.ok) {
            if (response.status === 409 || (response.status === 422 && path.endsWith('/shift-close'))) conflict = true;
            throw new Error(t(Object.values(data.errors || {}).flat()[0] || data.message));
        }
        if (payload) pendingRequests.delete(path);
        return data;
    }

    async function refreshAfterWrite(revision) {
        const latest = await request("/api/nightshift/shift-stock/" + report.shift_id);
        if (latest.status === "CLOSED") {
            location.reload();
            return false;
        }
        if (latest.stale || latest.basis !== report.basis || latest.revision !== revision) {
            conflict = true;
            throw new Error(t(refreshMessage));
        }
        report = latest;
        return true;
    }

    async function run(action) {
        if (busy) return;
        busy = true;
        feedback("");
        render();
        try {
            await action();
        } catch (failure) {
            feedback(failure.message);
        } finally {
            busy = false;
            render();
        }
    }

    async function saveReconciliation() {
        if (save.disabled) return;
        let saved = false;
        await run(async () => {
            const result = await request("/api/nightshift/commands/shift-stock-count", {
                id: report.shift_id, basis: report.basis, revision: report.revision,
                actual_cash: actual.value.trim(), reason: reason.value.trim(),
                counts: rows.map(({ row, input }) => ({ product_id: Number(row.dataset.product), quantity: input.value.trim() })),
            });
            saved = await refreshAfterWrite(result.revision);
            if (saved) dirty = false;
        });
        if (saved) go(4);
    }

    steps.forEach((button, index) => button.addEventListener("click", () => go(index)));
    back.addEventListener("click", () => go(step - 1));
    next.addEventListener("click", () => {
        if (step === 3 && !progress.saved) saveReconciliation();
        else go(step + 1);
    });
    for (const input of [...rows.map(({ input }) => input), actual, reason]) {
        input.addEventListener("input", () => { dirty = true; render(); });
    }
    notes.addEventListener("input", render);
    destination.addEventListener("change", render);
    recipient.addEventListener("change", render);
    select("[data-count-search]").addEventListener("input", ({ target }) => {
        const query = target.value.trim().toLowerCase();
        for (const { row } of rows) row.hidden = !row.dataset.name.toLowerCase().includes(query);
    });
    select("[data-wizard-refresh]").addEventListener("click", () => location.reload());
    save.addEventListener("click", saveReconciliation);
    stage.addEventListener("click", () => run(async () => {
        await request("/api/cash-custody/stage", {
            shift_id: report.shift_id, destination: destination.value, to_user_id: Number(recipient.value),
            amount: report.cash.actual, note: notes.value.trim(),
        });
        await refreshAfterWrite(report.revision);
    }));
    close.addEventListener("click", () => {
        confirmation.returnValue = "";
        confirmation.showModal();
    });
    confirmation.addEventListener("close", () => {
        if (confirmation.returnValue !== "close" || close.disabled) return;
        run(async () => {
            await request("/api/nightshift/commands/shift-close", {
                id: report.shift_id, actual_cash: report.cash.actual, notes: notes.value.trim(),
                reconciliation_basis: report.basis, reconciliation_revision: report.revision,
            });
            location.reload();
        });
    });
    render();
    const initial = Math.max(0, steps.findIndex((button) => "#" + button.dataset.wizardStep === location.hash));
    if (report.stale) go(2, false);
    else go(steps[initial].disabled ? (progress.saved ? 4 : 0) : initial, false);
}
