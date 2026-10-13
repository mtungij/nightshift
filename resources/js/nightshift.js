import { quantity } from "./quantity.js";
import { t as tr, html } from "./localization.js";

const root = document.getElementById("app");
if (root) {
    window.addEventListener("nightshift:language", () => shell());
    document.addEventListener(
        "error",
        (event) => {
            if (event.target.matches?.(".product-photo")) {
                event.target.hidden = true;
                event.target.nextElementSibling.hidden = false;
            }
        },
        true,
    );
    document.addEventListener("change", (event) => {
        if (!event.target.matches?.("[data-input=product-image]")) return;
        const file = event.target.files[0];
        const preview = event.target.closest(".form-field").querySelector(".product-image-preview");
        if (preview.dataset.url) URL.revokeObjectURL(preview.dataset.url);
        event.target.setCustomValidity("");
        if (!file) {
            preview.innerHTML = icon("box");
            return;
        }
        if (
            !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
            file.size > 2 * 1024 * 1024
        ) {
            event.target.setCustomValidity(tr("Choose a JPG, JPEG, PNG or WEBP image up to 2 MB."));
            event.target.reportValidity();
            preview.innerHTML = icon("box");
            return;
        }
        preview.dataset.url = URL.createObjectURL(file);
        preview.innerHTML = `<img src="${esc(preview.dataset.url)}" alt="${esc(tr("Product image preview"))}" />`;
    });
    const paths = {
        dashboard: html`<rect x="3" y="3" width="7" height="7" rx="1.5" /><rect
                x="14"
                y="3"
                width="7"
                height="7"
                rx="1.5"
            /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect
                x="14"
                y="14"
                width="7"
                height="7"
                rx="1.5"
            />`,
        cocktail: html`<path d="M4 4h16L12 13 4 4Z" /><path
                d="M12 13v7m-5 0h10M6 7h12m-3-4 2-2"
            />`,
        pos: html`<rect x="3" y="3" width="18" height="13" rx="2" /><path
                d="M8 21h8m-4-5v5M6 7h5m-5 4h3m5-4h4m-4 4h4"
            />`,
        orders: html`<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" /><path d="M9 7h6m-6 4h6m-6 4h3" />`,
        tables: html`<rect x="5" y="5" width="14" height="14" rx="3" /><path
                d="M9 2v3m6-3v3M9 19v3m6-3v3M2 9h3m-3 6h3m14-6h3m-3 6h3"
            />`,
        users: html`<path
                d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"
            /><circle cx="9" cy="7" r="4" />`,
        user: html`<circle cx="12" cy="7" r="4" /><path d="M4 21v-2a8 8 0 0 1 16 0v2" />`,
        box: html`<path d="m3 7 9-5 9 5v10l-9 5-9-5V7Zm0 0 9 5 9-5M12 12v10M7 4.5l10 5.5" />`,
        clock: html`<circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />`,
        wallet: html`<path
                d="M21 8H5a2 2 0 0 1 0-4h14v4M3 6v13a2 2 0 0 0 2 2h16V8m0 5h-5v4h5"
            /><path d="M17 15h.01" />`,
        truck: html`<path d="M1 3h14v13H1V3Zm14 5h4l3 4v4h-7" /><circle
                cx="5"
                cy="18"
                r="3"
            /><circle cx="18" cy="18" r="3" />`,
        chart: html`<path d="M4 3v17h17M8 15v-4m5 4V7m5 8V4" />`,
        shield: html`<path d="m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6l9-4Z" /><path
                d="m8 12 3 3 5-6"
            />`,
        branch: html`<path
            d="M3 21h18M5 21V3h14v18M9 7h1m4 0h1M9 11h1m4 0h1M9 15h1m4 0h1M10 21v-3h4v3"
        />`,
        settings: html`<path
                d="m9 3-1 3-3 1-2 3 2 2-1 3 3 2 1 3h4l1-3 3-1 2-3-2-2 1-3-3-2-1-3H9Z"
            /><circle cx="10" cy="11" r="3" />`,
        audit: html`<path
            d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm0 0v6h6M8 13h8m-8 4h5"
        />`,
        search: html`<circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 5 5" />`,
        bell: html`<path
            d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-11 12a2 2 0 0 0 4 0"
        />`,
        chevron: html`<path d="m9 5 7 7-7 7" />`,
        down: html`<path d="m6 9 6 6 6-6" />`,
        arrow: html`<path d="M4 12h16m-5-5 5 5-5 5" />`,
        up: html`<path d="m4 14 6-6 4 4 6-6m-5 0h5v5" />`,
        calendar: html`<rect x="3" y="5" width="18" height="16" rx="2" /><path
                d="M16 3v4M8 3v4M3 11h18m-11 4h.01m4 0h.01"
            />`,
        download: html`<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5" />`,
        plus: html`<path d="M12 5v14M5 12h14" />`,
        check: html`<path d="m5 12 4 4L19 6" />`,
        close: html`<path d="m6 6 12 12M6 18 18 6" />`,
        menu: html`<path d="M3 6h18M3 12h18M3 18h18" />`,
        help: html`<circle cx="12" cy="12" r="9" /><path
                d="M9.1 9a3 3 0 1 1 5 2.2c-1.2.8-2.1 1-2.1 2.8m0 3h.01"
            />`,
        cash: html`<rect x="2" y="5" width="20" height="14" rx="2" /><circle
                cx="12"
                cy="12"
                r="3"
            /><path d="M6 12h.01m12 0h.01" />`,
        alert: html`<path d="m12 3 10 18H2L12 3Zm0 6v5m0 3h.01" />`,
        refresh: html`<path
            d="M3 9a9 9 0 0 1 15-5l3 3m0-5v5h-5m5 8a9 9 0 0 1-15 5l-3-3m0 5v-5h5"
        />`,
        lock: html`<rect x="5" y="10" width="14" height="12" rx="2" /><path
                d="M8 10V6a4 4 0 0 1 8 0v4m-4 5v3"
            />`,
        heart: html`<path
            d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0l-1 1-1-1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"
        />`,
        beer: html`<path
            d="M5 5h11v15H5V5Zm11 3h3v8h-3M8 9v7m5-7v7M5 5c-2-5 5-6 6-3 4-3 8 0 5 3"
        />`,
        wine: html`<path d="M7 3h10v7a5 5 0 0 1-10 0V3Zm5 12v7m-4 0h8M7 8h10" />`,
        coffee: html`<path
            d="M3 8h14v7a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm14 1h4v6h-4M6 2v3m5-3v3M2 22h18"
        />`,
        glass: html`<path d="M7 3h10v7a5 5 0 0 1-10 0V3Zm5 12v7m-4 0h8M7 8h10" />`,
        ban: html`<circle cx="12" cy="12" r="9" /><path d="m6 6 12 12" />`,
        more: html`<circle cx="5" cy="12" r="1" /><circle cx="12" cy="12" r="1" /><circle
                cx="19"
                cy="12"
                r="1"
            />`,
        sun: html`<circle cx="12" cy="12" r="4" /><path
                d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"
            />`,
        moon: html`<path d="M20.5 13A9 9 0 0 1 11 3.5 9 9 0 1 0 20.5 13Z" />`,
        print: html`<path d="M6 9V3h12v6M6 17H3V9h18v8h-3M6 14h12v7H6v-7Zm12-2h.01" />`,
        filter: html`<path d="M3 4h18l-7 8v7l-4 2v-9L3 4Z" />`,
        cart: html`<path d="M2 3h3l3 12h11l3-9H6" /><circle cx="9" cy="20" r="1" /><circle
                cx="18"
                cy="20"
                r="1"
            />`,
        food: html`<path d="M4 2v7a3 3 0 0 0 6 0V2M7 2v20m13 0V2c-5 3-5 10 0 10" />`,
        transfer: html`<path d="M3 7h17l-4-4m4 4-4 4M21 17H4l4-4m-4 4 4 4" />`,
        phone: html`<rect x="6" y="2" width="12" height="20" rx="2" /><path
                d="M10 5h4m-2 14h.01"
            />`,
    };
    const icon = (name, cls = "") =>
        html`<svg
            class="icon ${cls}"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            ${paths[name] || paths.box}
        </svg>`;
    const esc = (value) =>
        String(value ?? "").replace(
            /[&<>"']/g,
            (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
        );
    const fmt = (n) => Math.round(Number(n) || 0).toLocaleString("en-US");
    const locationLabel = (name) =>
        ["Main Store", "Main Counter", "Main Branch"].includes(name) ? tr(name) : name;
    const currencyLabel = () => (db?.currency === "TZS" || !db?.currency ? "TSh" : db.currency);
    const money = (n) => `${esc(currencyLabel())} ${fmt(n)}`;
    const colors = ["rose", "blue", "purple", "green"];
    const avatar = (name, index = 0, cls = "") =>
        html`<span class="avatar ${colors[index % 4]} ${cls}"
            >${esc(
                name
                    .split(" ")
                    .map((x) => x[0])
                    .slice(0, 2)
                    .join(""),
            )}</span
        >`;
    const btn = (label, action, type = "", ico = "", attrs = "") =>
        html`<button type="button" class="button ${type}" data-action="${action}" ${attrs}>
            ${ico ? icon(ico) : ""}${tr(label)}
        </button>`;
    const link = (label, screen) =>
        html`<button class="text-link" data-nav="${screen}">${tr(label)}${icon("arrow")}</button>`;
    const badge = (text, color = "") => html`<span class="badge ${color}">${esc(tr(text))}</span>`;
    const categories = [];
    let db;
    let screen = location.hash.slice(1);
    if (["cashiers", "users"].includes(screen)) screen = "staff";
    let staffRole = location.hash === "#cashiers" ? "Cashier" : "";
    let staffStatus = "";
    let staffLabel = staffRole ? "Cashiers" : "All staff";
    let tab = "";
    let search = "";
    let category = "All products";
    let cart = [];
    const servingRequestKeys = new Map();
    let cartTable = "VIP 04";
    let cartCounter = "Main Counter";
    let cartDiscount = 0;
    let period = "Today";
    let orderPage = 1;
    let modalTrigger = null;
    const roles = ["Manager", "Cashier", "Waiter", "Store Keeper", "Supervisor"];
    const navigationPermissions = {
        dashboard: "view-dashboard",
        pos: "create-orders",
        orders: "view-orders",
        tables: "view-orders",
        waiters: "view-waiter-balances",
        cashiers: "manage-users",
        staff: "manage-users",
        inventory: "manage-inventory",
        shifts: "open-shifts",
        payments: "receive-payments",
        suppliers: "manage-inventory",
        reports: "view-reports",
        approvals: "approve-adjustments",
        users: "manage-users",
        branches: "manage-branches",
        counters: "manage-counters",
        settings: "manage-company",
        setup: "manage-company",
        audit: "view-audit",
        notifications: null,
    };
    const permitted = (name) => db.permissions.includes(name);
    const allowed = (name) => name === "notifications" || permitted(navigationPermissions[name]);
    const managerial = () => permitted("approve-void") || permitted("override-shifts");
    const canIssue = () => permitted("issue-drinks");
    const waiter = (id) => db.waiters.find((w) => w.id === Number(id));
    const product = (id) => db.products.find((p) => p.id === Number(id));
    const outstanding = (w) => Number(w.outstanding || 0);
    const branchWaiters = () =>
        db.waiters.filter((w) => db.branch === "All branches" || w.branch === db.branch);
    const branchOrders = () =>
        db.orders.filter((o) => db.branch === "All branches" || o.branch === db.branch);
    const invoice = (id) =>
        db.orders.find((o) => o.id === Number(id))?.invoice || `INV-${String(id).padStart(6, "0")}`;
    const statusColor = (status) =>
        ({
            Paid: "",
            Issued: "blue",
            "Waiting for issue": "amber",
            "Partially paid": "amber",
            Voided: "red",
            "Void requested": "red",
            Cancelled: "gray",
            Draft: "gray",
            Returned: "gray",
        })[status] || "";
    const timeNow = () =>
        new Intl.DateTimeFormat("en-GB", {
            hour: "2-digit",
            minute: "2-digit",
            timeZone: db?.timezone || "Africa/Dar_es_Salaam",
        }).format(new Date());
    function toast(message) {
        document.getElementById("toast").innerHTML = html`<div>
            ${icon("check")}<span>${esc(tr(message))}</span>
        </div>`;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => (document.getElementById("toast").innerHTML = ""), 4200);
    }
    const navItems = [
        ["dashboard", "dashboard", "Overview"],
        ["pos", "pos", "Point of sale"],
        ["orders", "orders", "Orders"],
        ["tables", "tables", "Tables"],
        ["waiters", "users", "Waiter accounts"],
        ["staff", "users", "Staff"],
        ["inventory", "box", "Inventory"],
        ["shifts", "clock", "Shifts"],
        ["payments", "wallet", "Payments"],
        ["suppliers", "truck", "Suppliers"],
        ["reports", "chart", "Reports"],
        ["approvals", "shield", "Approvals"],
        ["branches", "branch", "Branches"],
        ["counters", "pos", "Counters"],
        ["settings", "settings", "Settings"],
        ["setup", "check", "Setup guide"],
        ["audit", "audit", "Audit trail"],
    ];
    window.addEventListener("nightshift:theme-error", (event) => toast(event.detail));
    function navigation() {
        return navItems
            .map(
                ([id, ico, label], i) =>
                    `${i === 0 ? html`<div class="nav-group">WORKSPACE</div>` : i === 6 ? html`<div class="nav-group">MANAGEMENT</div>` : i === 12 ? html`<div class="nav-group">ADMINISTRATION</div>` : ""}${allowed(id) ? html`<button class="nav-item ${screen === id ? "active" : ""}" data-nav="${id}">${icon(ico)}<span>${tr(label)}</span>${id === "orders" ? html`<span class="nav-count">${branchOrders().filter((o) => !["Paid", "Voided", "Cancelled", "Returned"].includes(o.status)).length}</span>` : id === "approvals" ? html`<span class="nav-count nav-alert">${db.approvals.filter((a) => a.status === "Pending").length}</span>` : ["inventory", "shifts"].includes(id) ? icon("chevron", "chevron") : ""}</button>` : ""}`,
            )
            .join("");
    }
    function shell() {
        if (!allowed(screen) && screen !== "profile")
            screen =
                db.role === "Cashier" && allowed("waiters")
                    ? "waiters"
                    : navItems.find(([id]) => allowed(id))?.[0] || "dashboard";
        root.innerHTML = html`<aside class="sidebar" id="sidebar">
                <a href="#dashboard" class="brand" aria-label="Nightshift home"
                    ><span class="brand-symbol">${icon("cocktail")}</span
                    ><span>NIGHTSHIFT<small>BAR MANAGEMENT</small></span></a
                >
                <div class="workspace-switch">
                    <span class="workspace-icon">${icon("branch")}</span>
                    <div><strong>${esc(db.company)}</strong><small>Business workspace</small></div>
                    ${icon("down")}
                </div>
                <nav class="nav-scroll" aria-label="Main navigation">${navigation()}</nav>
                <div class="sidebar-foot">
                    <div class="shift-live">
                        <span class="dot" style="${db.shift.active ? "" : "background:#888"}"></span
                        >${tr(db.shift.active ? "Current shift is live" : "Shift is closed")}<small
                            >${esc(db.branch)} ·
                            ${db.shift.active ? db.shifts.find((s) => s.id === db.shift.id)?.opened_at?.slice(11, 16) || "Open" : "Ready for a new shift"}</small
                        >
                    </div>
                    <button class="nav-item" data-action="help">
                        ${icon("help")}Help & getting started${icon("chevron", "chevron")}
                    </button>
                </div>
            </aside>
            <div class="shell">
                <header class="topbar">
                    <button
                        class="icon-button mobile-menu"
                        data-action="menu"
                        aria-label="Toggle navigation"
                    >
                        ${icon("menu")}
                    </button>
                    <div class="breadcrumb">
                        ${icon("branch")}<span>Workspace</span>${icon("chevron")}<strong
                            >${esc(tr(navItems.find((n) => n[0] === screen)?.[2] || "Notifications"))}</strong
                        >
                    </div>
                    <div class="topbar-right">
                        <div class="language-switch" role="group" aria-label="${tr("Language")}">
                            ${["en", "sw"].map((locale) => html`<button type="button" data-language="${locale}" aria-label="${locale === "en" ? "English" : "Kiswahili"}" aria-pressed="${window.NightshiftI18n.locale === locale}">${locale.toUpperCase()}</button>`).join("")}
                        </div>
                        ${db.quickLock?.enabled ? html`<button type="button" class="icon-button" data-action="quick-lock" aria-label="Lock workspace" title="Lock workspace">${icon("lock")}</button>` : ""}<button
                            class="icon-button theme-toggle"
                            type="button"
                            data-theme-toggle
                            aria-pressed="${window.NightshiftTheme?.theme === "dark"}"
                            aria-label="${tr(window.NightshiftTheme?.theme === "dark" ? "Switch to Light Mode" : "Switch to Dark Mode")}"
                            title="${tr(window.NightshiftTheme?.theme === "dark" ? "Switch to Light Mode" : "Switch to Dark Mode")}"
                        >
                            <span class="theme-sun">${icon("sun")}</span
                            ><span class="theme-moon">${icon("moon")}</span>
                        </button>
                        <form class="global-search" id="global-search">
                            ${icon("search")}<input
                                name="query"
                                aria-label="Search the workspace"
                                placeholder="Search anything…"
                                autocomplete="off"
                            /><kbd>⌘ K</kbd>
                        </form>
                        <button
                            class="icon-button"
                            data-nav="notifications"
                            aria-label="Notifications"
                        >
                            ${icon("bell")}<span class="notification-dot"></span>
                        </button>
                        <div class="top-separator"></div>
                        <button class="profile-button" data-action="profile">
                            ${avatar(db.user.name)}
                            <div>
                                <strong>${esc(db.user.name)}</strong
                                ><small>${esc(tr(db.role))}</small>
                            </div>
                            ${icon("down")}
                        </button>
                    </div>
                </header>
                <main class="content page-enter" id="main">${renderScreen()}</main>
                <nav class="mobile-bottom" aria-label="Mobile navigation">
                    ${[
                        ["dashboard", "dashboard", "Home"],
                        ["pos", "pos", "POS"],
                        ["orders", "orders", "Orders"],
                        ["waiters", "users", "Waiters"],
                    ]
                        .filter((n) => allowed(n[0]))
                        .map(
                            ([id, ico, label]) =>
                                html`<button
                                    data-nav="${id}"
                                    class="${screen === id ? "active" : ""}"
                                >
                                    ${icon(ico)}${tr(label)}
                                </button>`,
                        )
                        .join("")}<button data-action="menu">${icon("menu")}More</button>
                </nav>
            </div>`;
    }
    function navigate(next, nextTab = "") {
        if (next === "pos" || next === "inventory") category = "All products";
        if (["cashiers", "users"].includes(next)) {
            staffRole = next === "cashiers" ? "Cashier" : "";
            staffLabel = staffRole ? "Cashiers" : "All staff";
            next = "staff";
        }
        screen = next;
        tab = nextTab;
        search = "";
        orderPage = 1;
        history.replaceState(null, "", `#${next}`);
        shell();
        window.scrollTo({ top: 0, behavior: "instant" });
    }
    const heading = (title, description, actions = "", eyebrow = "YOUR BUSINESS, IN CONTROL") =>
        html`<div class="page-heading">
            <div>
                <div class="eyebrow">${tr(eyebrow)}</div>
                <h1>${tr(title)}</h1>
                <p>${tr(description)}</p>
            </div>
            <div class="heading-actions">${actions}</div>
        </div>`;
    const branchSelect = () =>
        html`<select class="select-control" data-change="branch" aria-label="Branch">
            ${[...db.branches.map((b) => b.name), "All branches"].map((b) => html`<option value="${esc(b)}" ${b === db.branch ? "selected" : ""}>${b === "All branches" ? tr(b) : esc(b)}</option>`).join("")}
        </select>`;
    const dateSelect = () =>
        html`<select class="select-control" data-change="period" aria-label="Reporting period">
            ${["Today", "Yesterday", "This week"].map((p) => html`<option value="${p}" ${p === period ? "selected" : ""}>${p === "Today" ? tr("Today") + ", " + db.date : tr(p)}</option>`).join("")}
        </select>`;
    const footer = () =>
        html`<footer class="app-footer">
            <span>${icon("lock")}Your workspace. Your business.</span
            ><span
                >Nightshift © 2026 <span style="margin:0 8px">·</span> Made for the after
                hours.</span
            >
        </footer>`;
    function kpi(label, value, ico, color, change, note, spark = false) {
        return html`<div class="kpi">
            <div class="kpi-header">
                ${tr(label)}<span class="kpi-icon ${color}">${icon(ico)}</span>
            </div>
            <div class="kpi-value">${value}</div>
            <div class="kpi-footer">
                ${change ? html`<span class="kpi-trend ${color === "red" ? "red" : ""}">${icon("up")}${change}</span>` : ""}<span
                    >${tr(note)}</span
                >
            </div>
            ${
                spark
                    ? html`<svg class="mini-spark" viewBox="0 0 65 30" fill="none">
                          <path
                              d="M1 25 8 20 14 23 21 16 28 19 35 12 43 15 51 8 57 11 64 2"
                              stroke="${color === "green" ? "var(--green)" : color === "red" ? "var(--red)" : color === "blue" ? "var(--blue)" : "var(--amber)"}"
                              stroke-width="1.5"
                          />
                          <path
                              d="M1 25 8 20 14 23 21 16 28 19 35 12 43 15 51 8 57 11 64 2V30H1Z"
                              fill="${color === "red" ? "#ee838409" : "#eab66a09"}"
                          />
                      </svg>`
                    : ""
            }
        </div>`;
    }
    const cashValue = (n) => html`<span class="currency">${esc(currencyLabel())}</span>${fmt(n)}`;
    function chart() {
        const values = Object.entries(
            tab === "Weekly" ? db.metrics?.daily || {} : db.metrics?.hourly || {},
        );
        const points = values.length ? values : [["No sales", 0]];
        const max = Math.max(1, ...points.map((v) => Number(v[1])));
        const curve = points
            .map(
                ([label, value], i) =>
                    `${i ? "L" : "M"}${48 + (i * 538) / Math.max(1, points.length - 1)} ${154 - (Number(value) / max) * 125}`,
            )
            .join(" ");
        return html`<div class="chart-wrap">
            <svg
                class="sales-chart"
                viewBox="0 0 620 200"
                role="img"
                aria-label="Sales from posted invoices"
            >
                <defs>
                    <linearGradient id="sales-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--amber)" stop-opacity=".18" />
                        <stop offset="100%" stop-color="var(--amber)" stop-opacity="0" />
                    </linearGradient>
                </defs>
                ${[25, 68, 111, 154].map((y, i) => html`<path class="chart-grid" d="M48 ${y}H602" /><text class="chart-text" x="3" y="${y + 3}">${fmt((max * (3 - i)) / 3)}</text>`).join("")}
                <path class="chart-area" d="${curve}L586 162H48Z" />
                <path class="chart-line" d="${curve}" />
                ${points
                    .filter((v, i) => i % Math.max(1, Math.ceil(points.length / 7)) === 0)
                    .map(
                        ([label, value], i) =>
                            html`<text class="chart-text" x="${48 + i * 76}" y="187"
                                >${esc(label)}</text
                            >`,
                    )
                    .join("")}
            </svg>
        </div>`;
    }

    function dashboard() {
        const sales = Number(db.metrics?.sales || 0),
            collected = Number(db.metrics?.collected || 0),
            balance = Number(db.metrics?.outstanding || 0);
        return (
            heading(
                "Operations overview",
                "A clear view of your business. Every sale, every shift, every night.",
                `${branchSelect()}${dateSelect()}${btn("Export report", "export-dashboard", "", "download")}`,
                "YOUR NIGHT, AT A GLANCE",
            ) +
            html`<div class="overview-meta">
                    <div class="live-label">
                        <span class="dot"></span>Live overview
                        <span style="color:var(--muted);margin-left:5px">Updated just now</span>
                    </div>
                    <span
                        >${icon("clock")}${esc(db.shift.active ? db.shifts.find((s) => s.id === db.shift.id)?.number || tr("Open shift") : tr("No open cashier shift"))}</span
                    >
                </div>
                <section class="kpi-grid" aria-label="Business key performance indicators">
                    ${kpi("Today's sales", cashValue(sales), "chart", "", "", "Posted invoices")}${kpi("Cash collected", cashValue(collected), "wallet", "green", "", "Received payments")}${kpi("Waiter outstanding", cashValue(balance), "users", "red", "", "Ledger balance")}${kpi("Open orders", String(db.metrics?.open_orders || 0), "orders", "blue", "", "Awaiting settlement")}
                </section>
                <div class="dashboard-grid">
                    <section class="panel sales-panel">
                        <div class="panel-header">
                            <div>
                                <h2>Sales overview</h2>
                                <p>
                                    Your revenue throughout the
                                    ${tr(tab === "Weekly" ? "week" : "night")}
                                </p>
                            </div>
                            <div class="segmented">
                                <button
                                    data-action="chart"
                                    data-value="Hourly"
                                    class="${tab !== "Weekly" ? "active" : ""}"
                                >
                                    Hourly</button
                                ><button
                                    data-action="chart"
                                    data-value="Weekly"
                                    class="${tab === "Weekly" ? "active" : ""}"
                                >
                                    Weekly
                                </button>
                            </div>
                        </div>
                        <div class="sales-summary">
                            <strong>${cashValue(sales)}</strong>${badge("Posted sales", "gray")}
                        </div>
                        ${chart()}
                        <div class="chart-footer">
                            <span class="legend"><span class="legend-dot"></span>Total sales</span
                            ><span
                                >Peak hour
                                <strong style="color:var(--text);font-weight:500;margin-left:5px"
                                    >${esc(Object.entries(db.metrics?.hourly || {}).sort((a, b) => Number(b[1]) - Number(a[1]))[0]?.[0] || "—")}</strong
                                ></span
                            >
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-header">
                            <div>
                                <h2>Waiter accountability</h2>
                                <p>Stay on top of every open balance</p>
                            </div>
                            ${link("View all", "waiters")}
                        </div>
                        <div class="waiter-summary">
                            <strong>${money(balance)}</strong
                            ><span>outstanding</span
                            >${badge(tr(":count active", { count: branchWaiters().filter((w) => w.active).length }), "gray")}
                        </div>
                        <div class="waiter-list">
                            ${branchWaiters()
                                .slice(0, 4)
                                .map(
                                    (w, i) =>
                                        html`<div
                                            class="waiter-row"
                                            data-action="waiter-profile"
                                            data-id="${w.id}"
                                            tabindex="0"
                                            role="button"
                                            aria-label="View ${esc(w.short)} account"
                                        >
                                            ${avatar(w.name, i)}
                                            <div>
                                                <div class="waiter-name">${esc(w.short)}</div>
                                                <small>${w.orders} orders this shift</small>
                                            </div>
                                            <div class="waiter-amount">
                                                <strong
                                                    class="${outstanding(w) > 200000 ? "text-red" : outstanding(w) > 0 ? "text-amber" : "text-green"}"
                                                    >${money(outstanding(w))}</strong
                                                >${badge(outstanding(w) > 200000 ? "High balance" : outstanding(w) > 0 ? "Outstanding" : "Settled", outstanding(w) > 200000 ? "red" : outstanding(w) > 0 ? "amber" : "")}
                                            </div>
                                        </div>`,
                                )
                                .join("")}
                        </div>
                        <div class="waiter-panel-footer">
                            ${icon("shield")}Waiters must settle balances before closing their
                            shift.
                        </div>
                    </section>
                </div>
                <div class="dashboard-grid">
                    <section class="panel orders-panel">
                        <div class="panel-header">
                            <div class="panel-header-title">
                                <h2>Recent orders</h2>
                                ${badge("Live", "gray")}
                            </div>
                            ${link("All orders", "orders")}
                        </div>
                        ${ordersTable(branchOrders().slice(0, 5), true)}
                        <div class="table-footer">
                            <span
                                >Showing ${Math.min(5, branchOrders().length)} of ${db.order_count}
                                orders</span
                            ><span class="live-label"><span class="dot"></span>Auto-updating</span>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-header">
                            <div class="panel-header-title">
                                ${icon("alert")}
                                <h2>Low stock alerts</h2>
                            </div>
                            ${badge(db.products.filter((p) => p.stock < p.min).length + " products", "red")}
                        </div>
                        ${db.products
                            .filter((p) => p.stock < p.min)
                            .slice(0, 4)
                            .map(
                                (p) =>
                                    html`<div class="stock-row">
                                        <div class="product-thumb">
                                            <span class="bottle ${p.style}"></span>
                                        </div>
                                        <div>
                                            <div class="stock-name">${esc(p.name)}</div>
                                            <small
                                                >${esc(db.locations.find((l) => l.id === db.location_id)?.name || "Stock location")}
                                                · Min. ${quantity(p.min)}
                                                ${tr(p.category === "Beer" ? "bottles" : "units")}</small
                                            >
                                        </div>
                                        <div class="stock-count">
                                            <strong>${quantity(p.stock)} left</strong>
                                            <div class="stock-bar">
                                                <span
                                                    style="width:${Math.min(100, (p.stock / p.min) * 100)}%"
                                                ></span>
                                            </div>
                                        </div>
                                    </div>`,
                            )
                            .join(
                                "",
                            )}${btn("Manage inventory", "go-inventory", "stock-action", "box")}
                    </section>
                </div>
                <section class="metrics-strip">
                    ${[
                        ["Stock value", "box", money(db.metrics?.stock_value || 0), ""],
                        ["Complimentary", "heart", money(db.metrics?.complimentary || 0), ""],
                        ["Breakages", "glass", money(db.metrics?.breakages || 0), ""],
                        ["Voided sales", "ban", money(db.metrics?.voids || 0), ""],
                        [
                            "Cash shortage",
                            "cash",
                            money(Math.abs(Number(db.metrics?.cash_difference || 0))),
                            "text-green",
                        ],
                        [
                            "Active waiters",
                            "users",
                            `${branchWaiters().filter((w) => w.active).length} on shift`,
                            "",
                        ],
                    ]
                        .map(
                            ([label, ico, value, cls]) =>
                                html`<div class="mini-metric">
                                    <span>${icon(ico)}${tr(label)}</span
                                    ><strong class="${cls}"
                                        >${value.replace(esc(currencyLabel()), html`<small>${esc(currencyLabel())}</small>`)}</strong
                                    >
                                </div>`,
                        )
                        .join("")}
                </section>
                <div class="bottom-grid">
                    <section class="panel">
                        <div class="panel-header">
                            <h2>Sales by payment</h2>
                            ${icon("wallet")}
                        </div>
                        <div class="donut-content">
                            <div class="donut" style="background:${paymentGradient()}">
                                <div class="donut-center">
                                    <strong>${paymentPercent("Cash")}%</strong
                                    ><small>Cash payments</small>
                                </div>
                            </div>
                            <div class="donut-legend">
                                ${Object.entries(db.metrics?.payment_methods || {})
                                    .map(([name, value], i) => [
                                        name,
                                        paymentPercent(name) + "%",
                                        [
                                            "var(--amber)",
                                            "#718cb8",
                                            "var(--chart-green)",
                                            "#756a91",
                                        ][i % 4],
                                    ])
                                    .map(
                                        ([name, val, color]) =>
                                            html`<div>
                                                <span
                                                    class="legend-dot"
                                                    style="background:${color}"
                                                ></span
                                                >${name}<strong>${val}</strong>
                                            </div>`,
                                    )
                                    .join("")}
                            </div>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-header">
                            <h2>Top selling products</h2>
                            ${link("Report", "reports")}
                        </div>
                        ${(db.metrics?.top_products || [])
                            .map((p) => [p.product_id, p.quantity, p.total])
                            .map(([id, qty, total], i) => {
                                const p = product(id) || {
                                    name: db.metrics.top_products.find((p) => p.product_id === id)
                                        ?.name,
                                    style: "",
                                };
                                return html`<div class="rank-row">
                                    <span class="rank">0${i + 1}</span>
                                    <div class="product-thumb">
                                        <span class="bottle ${p.style}"></span>
                                    </div>
                                    <div>
                                        <strong>${p.name}</strong
                                        ><small>${quantity(qty)} bottles sold</small>
                                    </div>
                                    <span>${money(total)}</span>
                                </div>`;
                            })
                            .join("")}
                    </section>
                    <section class="panel">
                        <div class="panel-header">
                            <h2>Sales by branch</h2>
                            ${icon("branch")}
                        </div>
                        ${(db.metrics?.branches || [])
                            .map((b) => [
                                b.name,
                                b.total,
                                (Number(b.total) /
                                    Math.max(
                                        1,
                                        ...db.metrics.branches.map((b) => Number(b.total)),
                                    )) *
                                    100,
                            ])
                            .map(
                                ([name, total, width]) =>
                                    html`<div class="branch-bar-row">
                                        <div>
                                            <span>${name}</span><strong>${money(total)}</strong>
                                        </div>
                                        <div class="branch-bar">
                                            <span
                                                style="width:${width}%;opacity:${width === 100 ? 1 : 0.55}"
                                            ></span>
                                        </div>
                                    </div>`,
                            )
                            .join("")}
                    </section>
                </div>
                ${dashboardActivity()}${footer()}`
        );
    }
    function ordersTable(orders, compact = false) {
        return html`<div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Waiter</th>
                        <th>Table</th>
                        <th>Amount</th>
                        <th>Status</th>
                        ${
                            compact
                                ? ""
                                : html`<th>Time</th>
                                      <th></th>`
                        }
                    </tr>
                </thead>
                <tbody>
                    ${
                        orders
                            .map(
                                (o) =>
                                    html`<tr>
                                        <td>
                                            <button
                                                class="text-link"
                                                style="color:var(--text);font-size:12px"
                                                data-action="order-detail"
                                                data-id="${o.id}"
                                            >
                                                ${invoice(o.id)}
                                            </button>
                                        </td>
                                        <td>
                                            <div class="table-avatar">
                                                ${avatar(waiter(o.waiter)?.name || "Guest", o.waiter - 1)}${esc(waiter(o.waiter)?.short || "Guest")}
                                            </div>
                                        </td>
                                        <td>${esc(o.table)}</td>
                                        <td class="amount">${money(o.total)}</td>
                                        <td>${badge(o.status, statusColor(o.status))}</td>
                                        ${
                                            compact
                                                ? ""
                                                : html`<td>${esc(o.time)}</td>
                                                      <td>
                                                          ${btn("View", "order-detail", "small", "", `data-id="${o.id}"`)}
                                                      </td>`
                                        }
                                    </tr>`,
                            )
                            .join("") ||
                        html`<tr>
                            <td colspan="7">
                                <div class="empty-state">No orders match your filters.</div>
                            </td>
                        </tr>`
                    }
                </tbody>
            </table>
        </div>`;
    }
    let activityTab = "Stock Movement";
    function dashboardActivity() {
        let content;
        if (activityTab === "Stock Movement")
            content = simpleTable(
                [
                    "Reference",
                    "Product",
                    "Movement Type",
                    "From",
                    "To",
                    "Quantity",
                    "Date/Time",
                    "User",
                ],
                db.movements
                    .slice(0, 4)
                    .map((m) => [
                        esc(m.ref),
                        esc(m.product),
                        badge(m.type, m.reason === "Transfer" ? "blue" : ""),
                        esc(m.from),
                        esc(m.to),
                        quantity(m.qty),
                        esc(m.time),
                        esc(m.user || "—"),
                    ]),
            );
        else if (activityTab === "Cashier settlements")
            content = simpleTable(
                ["Payment", "Invoice", "Waiter", "Method", "Amount", "Time"],
                db.payments
                    .slice(0, 4)
                    .map((p) => [
                        p.id,
                        invoice(p.invoice),
                        esc(waiter(p.waiter)?.short),
                        badge(p.method, "blue"),
                        money(p.amount),
                        p.time,
                    ]),
            );
        else
            content = approvalTable(
                db.approvals.filter((a) => a.type === "Void request").slice(0, 4),
            );
        return html`<section class="panel" style="margin-top:20px">
            <div class="panel-header">
                <div>
                    <h2>Activity & exceptions</h2>
                    <p>The latest changes across your operation</p>
                </div>
                <div class="segmented">
                    ${["Stock Movement", "Cashier settlements", "Void requests"].map((t) => html`<button class="${t === activityTab ? "active" : ""}" data-action="activity-tab" data-value="${t}">${tr(t)}</button>`).join("")}
                </div>
            </div>
            ${content}
        </section>`;
    }
    const tabs = (items, active) =>
        html`<div class="subtabs">
            ${items.map((t) => html`<button class="${t === active ? "active" : ""}" data-action="tab" data-value="${esc(t)}">${esc(tr(t))}</button>`).join("")}
        </div>`;
    function ordersScreen() {
        if (db.role === "Cashier" && !tab) return waitersScreen();
        const active = tab || "Live board";
        let filtered = branchOrders().filter(
            (o) =>
                !search ||
                `${invoice(o.id)} ${waiter(o.waiter)?.name} ${o.table}`
                    .toLowerCase()
                    .includes(search.toLowerCase()),
        );
        if (!["Live board", "All orders"].includes(active))
            filtered = filtered.filter((o) => o.status === active);
        return (
            heading(
                "Order management",
                "From the first round to the final payment. Keep every order moving.",
                `${branchSelect()}${allowed("pos") ? btn("New order", "go-pos", "primary", "plus") : ""}`,
            ) +
            tabs(
                ["Live board", "All orders", "Waiting for issue", "Issued", "Paid", "Voided"],
                active,
            ) +
            html`<div class="toolbar">
                <input
                    class="search-field"
                    data-input="search"
                    placeholder="Search invoice, waiter or table…"
                    aria-label="Search orders"
                    value="${esc(search)}"
                /><span class="live-label"><span class="dot"></span>${filtered.length} orders</span>
            </div>` +
            (active === "Live board"
                ? html`<div class="kanban">
                      ${["Waiting for issue", "Issued", "Partially paid", "Paid"]
                          .map(
                              (status, i) =>
                                  html`<section class="kanban-column">
                                      <div class="kanban-title">
                                          <span
                                              class="legend-dot"
                                              style="background:${["var(--amber)", "var(--blue)", "var(--amber)", "var(--green)"][i]}"
                                          ></span
                                          >${tr(status).toUpperCase()}<span
                                              >${filtered.filter((o) => o.status === status).length}</span
                                          >
                                      </div>
                                      ${
                                          filtered
                                              .filter((o) => o.status === status)
                                              .map(
                                                  (o) =>
                                                      html`<article class="panel order-card">
                                                          <div class="order-card-top">
                                                              <strong>${invoice(o.id)}</strong
                                                              ><span
                                                                  style="color:var(--muted);font-size:12px"
                                                                  >${o.time}</span
                                                              >
                                                          </div>
                                                          <p>
                                                              Waiter
                                                              <span
                                                                  style="color:var(--text);float:right"
                                                                  >${esc(waiter(o.waiter)?.short)}</span
                                                              >
                                                          </p>
                                                          <p>
                                                              Table
                                                              <span
                                                                  style="color:var(--text);float:right"
                                                                  >${esc(o.table)}</span
                                                              >
                                                          </p>
                                                          <div class="order-card-items">
                                                              ${o.items.map((it) => `${quantity(it.qty)} × ${esc(it.name || product(it.product)?.name)} ${it.unit > 0 ? `(${esc(product(it.product)?.units[it.unit]?.[0])})` : ""}`).join(html`<br />`)}
                                                          </div>
                                                          <div class="order-card-total">
                                                              ${money(o.total)}
                                                          </div>
                                                          ${o.paid > 0 && o.paid < o.total ? html`<p class="text-amber">${money(o.total - o.paid)} remaining</p>` : ""}${status === "Waiting for issue" && canIssue() ? btn("Issue drinks", "issue", "primary", "box", `data-id="${o.id}"`) : ["Issued", "Partially paid"].includes(status) && canIssue() ? btn("Receive payment", "payment", "success", "wallet", `data-id="${o.id}"`) : ""}${btn("View order", "order-detail", "", "", `data-id="${o.id}"`)}
                                                      </article>`,
                                              )
                                              .join("") ||
                                          html`<div class="empty-state" style="padding:30px 0">
                                              All caught up.
                                          </div>`
                                      }
                                  </section>`,
                          )
                          .join("")}
                  </div>`
                : html`<section class="panel">
                      ${ordersTable(filtered.slice((orderPage - 1) * 8, orderPage * 8))}
                      <div class="table-footer">
                          <span
                              >${filtered.length} orders · page ${orderPage} of
                              ${Math.max(1, Math.ceil(filtered.length / 8))}</span
                          >
                          <div class="pagination">
                              ${btn("Previous", "page", "small", "", `data-value="${Math.max(1, orderPage - 1)}" ${orderPage <= 1 ? "disabled" : ""}`)}${btn("Next", "page", "small", "", `data-value="${orderPage + 1}" ${orderPage >= Math.ceil(filtered.length / 8) ? "disabled" : ""}`)}
                          </div>
                      </div>
                  </section>`) +
            footer()
        );
    }
    function waitersScreen() {
        const active = tab || "All waiters";
        const list = branchWaiters().filter(
            (w) =>
                (active !== "Outstanding" || outstanding(w) > 0) &&
                (active !== "Settled" || outstanding(w) === 0) &&
                (!search || w.name.toLowerCase().includes(search.toLowerCase())),
        );
        return (
            heading(
                "Waiter accounts",
                "Every drink accounted for. Every balance visible.",
                `${branchSelect()}${
                    permitted("receive-payments")
                        ? html`<select
                              class="select-control"
                              data-change="stock-location"
                              aria-label="Settlement counter"
                          >
                              ${db.locations
                                  .filter((l) => l.type === "COUNTER" && l.active)
                                  .map(
                                      (l) =>
                                          html`<option
                                              value="${l.id}"
                                              ${l.id === db.location_id ? "selected" : ""}
                                          >
                                              ${esc(locationLabel(l.name))}
                                          </option>`,
                                  )
                                  .join("")}
                          </select>`
                        : ""
                }${managerial() ? btn("Add waiter", "add-waiter", "primary", "plus") : ""}`,
            ) +
            html`<div class="kpi-grid">
                ${kpi("Total outstanding", cashValue(branchWaiters().reduce((s, w) => s + outstanding(w), 0)), "users", "red", "", "Live shift balance", false)}${kpi("Payments Received", cashValue(db.visibility?.settlement_only ? db.payments.filter((p) => !p.is_credit).reduce((s, p) => s + Number(p.amount), 0) : branchWaiters().reduce((s, w) => s + w.paid, 0)), "wallet", "green", "", "During the current shift", false)}${kpi("Active waiters", String(branchWaiters().filter((w) => w.active).length), "user", "blue", "", "On duty right now", false)}${kpi("Settled accounts", String(branchWaiters().filter((w) => outstanding(w) === 0).length), "check", "green", "", "Ready to close shift", false)}
            </div>` +
            tabs(["All waiters", "Outstanding", "Settled"], active) +
            html`<div class="toolbar">
                    <input
                        class="search-field"
                        data-input="search"
                        aria-label="Search waiters"
                        placeholder="Search waiter name…"
                        value="${esc(search)}"
                    />
                </div>
                <div class="waiter-cards">
                    ${
                        list
                            .map(
                                (w, i) =>
                                    html`<article
                                        class="panel waiter-card"
                                        data-action="waiter-profile"
                                        data-id="${w.id}"
                                        role="button"
                                        tabindex="0"
                                    >
                                        <div class="waiter-card-top">
                                            ${avatar(w.name, i)}
                                            <div>
                                                <h3>${esc(w.name)}</h3>
                                                <p>
                                                    ${esc(w.branch)} ·
                                                    ${esc(db.visibility?.settlement_only ? cartCounter : w.shift_number || "No open shift")}
                                                </p>
                                            </div>
                                            ${badge(w.active ? "Active" : "Closed", w.active ? "" : "gray")}
                                        </div>
                                        <div class="detail-row">
                                            <span>Open invoices</span
                                            ><strong>${w.open_orders}</strong>
                                        </div>
                                        ${
                                            db.visibility?.settlement_only
                                                ? ""
                                                : html`
                                                      <div class="detail-row">
                                                          <span>Orders Count</span
                                                          ><strong>${w.orders}</strong>
                                                      </div>
                                                      <div class="detail-row">
                                                          <span>Issued Value</span
                                                          ><strong>${money(w.issued)}</strong>
                                                      </div>
                                                      <div class="detail-row">
                                                          <span>Payments Received</span
                                                          ><strong class="text-green"
                                                              >${money(w.paid)}</strong
                                                          >
                                                      </div>
                                                  `
                                        }
                                        <div class="balance-line">
                                            <span>Outstanding Balance</span
                                            ><strong
                                                class="${outstanding(w) > 200000 ? "text-red" : outstanding(w) > 0 ? "text-amber" : "text-green"}"
                                                >${money(outstanding(w))}</strong
                                            >
                                        </div>
                                        <div style="margin-top:18px">
                                            ${btn("View Orders", "waiter-profile", "small", "orders", `data-id="${w.id}"`)}${permitted("receive-payments") && outstanding(w) > 0 ? btn("Receive Payment", "waiter-payment", "primary small", "wallet", `data-id="${w.id}"`) + btn("Partial Payment", "waiter-payment", "small", "", `data-id="${w.id}" data-value="partial"`) + btn("Full Settlement", "waiter-payment", "small", "check", `data-id="${w.id}" data-value="full"`) : ""}
                                        </div>
                                    </article>`,
                            )
                            .join("") ||
                        html`<div class="empty-state">No waiter accounts match your filters.</div>`
                    }
                </div>
                ${footer()}`
        );
    }
    function productVisual(p) {
        if (p.image)
            return html`<img
                    class="product-photo"
                    src="${esc(p.image)}"
                    alt="${esc(p.name)}"
                    loading="lazy"
                /><span class="product-photo-fallback" hidden>${icon("box")}</span>`;
        return ["cocktail", "food"].includes(p.style)
            ? icon(p.style)
            : html`<span class="bottle ${p.style}"></span>`;
    }
    function posScreen() {
        const list = db.products.filter(
            (p) =>
                p.active !== false &&
                (category === "All products" || p.category === category) &&
                (!search || p.name.toLowerCase().includes(search.toLowerCase())),
        );
        const total = cart.reduce((n, it) => n + it.qty * it.price, 0);
        return (
            heading(
                "Point of sale",
                "A great night starts with a seamless order.",
                `${branchSelect()}${badge("Current shift")}`,
                "POUR. SERVE. REPEAT.",
            ) +
            html`<div class="toolbar">
                    <input
                        class="search-field"
                        data-input="search"
                        aria-label="Search products"
                        value="${esc(search)}"
                        placeholder="Search products or scan a barcode…"
                    /><span style="color:var(--muted);font-size:12px">${list.length} products</span>
                </div>
                <div class="pos-layout">
                    <aside class="category-list">
                        ${categories.map((c) => html`<button data-action="category" data-value="${esc(c)}" class="${c === category ? "active" : ""}">${esc(c === "All products" ? tr(c) : c)}<small>${c === "All products" ? db.products.length : db.products.filter((p) => p.category === c).length}</small></button>`).join("")}
                    </aside>
                    <section class="product-grid">
                        ${
                            list
                                .map(
                                    (p) =>
                                        html`<article class="product-card">
                                            <div class="product-visual">${productVisual(p)}</div>
                                            <h3>${esc(p.name)}</h3>
                                            <small
                                                >${quantity(p.stock)}
                                                ${tr(p.category === "Beer" ? "bottles" : "units")}
                                                available</small
                                            ><strong id="price-${p.id}"
                                                >${money(p.units[0][2])}</strong
                                            ><select
                                                class="select-control"
                                                data-change="unit"
                                                data-id="${p.id}"
                                                aria-label="Selling unit for ${esc(p.name)}"
                                            >
                                                ${p.units.map((u, i) => html`<option value="${i}">${esc(u[0])} ${u[1] > 1 ? "· " + quantity(u[1]) + " bottles" : ""}</option>`).join("")}</select
                                            >${btn("Add to order", "add-cart", "small", "plus", `data-id="${p.id}" ${p.stock <= 0 ? "disabled" : ""}`)}
                                        </article>`,
                                )
                                .join("") ||
                            html`<div class="empty-state">No products in this category.</div>`
                        }
                    </section>
                    <section class="panel pos-cart">
                        <div class="cart-header">
                            <h2>Current order</h2>
                            ${badge(tr(":count items", { count: quantity(cart.reduce((n, i) => n + i.qty, 0)) }), "amber")}
                        </div>
                        <div class="cart-body">
                            <div class="cart-selects">
                                ${field(
                                    "Table",
                                    "table",
                                    html`<select data-change="cart-table">
                                        ${[...db.tables.map((t) => t.name), "Takeaway"].map((t) => html`<option value="${esc(t)}" ${t === cartTable ? "selected" : ""}>${t === "Takeaway" ? tr(t) : esc(t)}</option>`).join("")}
                                    </select>`,
                                )}${field(
                                    "Counter",
                                    "counter",
                                    html`<select data-change="cart-counter">
                                        ${db.locations
                                            .filter((l) => l.type === "COUNTER")
                                            .map((l) => l.name)
                                            .map(
                                                (c) =>
                                                    html`<option
                                                        value="${esc(c)}"
                                                        ${c === cartCounter ? "selected" : ""}
                                                    >
                                                        ${esc(locationLabel(c))}
                                                    </option>`,
                                            )
                                            .join("")}
                                    </select>`,
                                )}${field("Branch", "branch", `<input value="${esc(db.locations.find((l) => l.name === cartCounter)?.branch_id ? db.branches.find((b) => b.id === db.locations.find((l) => l.name === cartCounter).branch_id)?.name : db.branch)}" readonly>`)}
                            </div>
                            ${
                                cart.length
                                    ? cart
                                          .map(
                                              (it, i) =>
                                                  html`<div class="cart-item">
                                                      <div>
                                                          <strong
                                                              >${esc(product(it.product).name)}</strong
                                                          ><button
                                                              class="icon-button"
                                                              data-action="remove-cart"
                                                              data-id="${i}"
                                                              aria-label="Remove product"
                                                              style="width:20px;height:20px"
                                                          >
                                                              ${icon("close")}
                                                          </button>
                                                      </div>
                                                      <div>
                                                          <span
                                                              >${product(it.product).units[it.unit][0]}
                                                              · ${money(it.price)}</span
                                                          ><span class="quantity-control"
                                                              ><button
                                                                  data-action="cart-qty"
                                                                  data-id="${i}"
                                                                  data-value="-1"
                                                                  aria-label="Decrease quantity"
                                                              >
                                                                  −</button
                                                              >${quantity(it.qty)}<button
                                                                  data-action="cart-qty"
                                                                  data-id="${i}"
                                                                  data-value="1"
                                                                  aria-label="Increase quantity"
                                                              >
                                                                  +
                                                              </button></span
                                                          >
                                                      </div>
                                                  </div>`,
                                          )
                                          .join("")
                                    : html`<div class="cart-empty">
                                          ${icon("cart")}
                                          <div>Your next round starts here.</div>
                                          <small>Select a product to add it to the order.</small>
                                      </div>`
                            }
                            <div class="detail-row">
                                <span>Subtotal</span><strong>${money(total)}</strong>
                            </div>
                            <div class="detail-row">
                                <span>Discount (${esc(currencyLabel())})</span
                                ><input
                                    type="number"
                                    min="0"
                                    max="${total}"
                                    data-change="cart-discount"
                                    aria-label="Order discount"
                                    value="${cartDiscount}"
                                    class="search-field"
                                    style="width:90px;min-width:0;padding:5px 8px"
                                />
                            </div>
                            <div class="cart-total">
                                <span>Total</span
                                ><span>${money(Math.max(0, total - cartDiscount))}</span>
                            </div>
                            <div class="cart-actions">
                                ${btn("Send to cashier", "send-order", "primary", "arrow", cart.length ? "" : "disabled")}${db.role === "Waiter" ? btn("Save draft", "save-draft", "", "orders", cart.length ? "" : "disabled") : ""}${btn("Clear cart", "clear-cart", "small", "", cart.length ? "" : "disabled")}
                            </div>
                            <p
                                style="font-size:12px;color:var(--muted);margin:15px 0 0;text-align:center"
                            >
                                Sending with the serving waiter’s PIN issues stock and updates their
                                account immediately.
                            </p>
                        </div>
                    </section>
                </div>
                ${cart.length ? html`<button class="cart-jump" data-action="cart-jump"><span>${icon("cart")} View order · ${quantity(cart.reduce((n, it) => n + it.qty, 0))} items</span><strong>${money(Math.max(0, total - cartDiscount))} →</strong></button>` : ""}${footer()}`
        );
    }
    function tablesScreen() {
        const area = tab || "VIP";
        const defs = db.tables
            .filter((t) => t.area === area)
            .map((t) => {
                const orders = branchOrders().filter(
                    (o) =>
                        o.table === t.name &&
                        [
                            "Issued",
                            "Waiting for issue",
                            "Partially paid",
                            "Void requested",
                        ].includes(o.status),
                );
                const total = orders.reduce((n, o) => n + o.balance, 0);
                return {
                    name: t.name,
                    orders,
                    total,
                    reservation: t.reservation,
                    status: orders.length ? "occupied" : t.reservation ? "reserved" : "available",
                };
            });
        return (
            heading(
                "Tables & floor",
                "A live view of your floor. Keep the service flowing.",
                `${branchSelect()}${btn("New reservation", "reservation", "primary", "plus")}`,
            ) +
            tabs(["Main Hall", "VIP", "Garden", "Pool Area", "Rooftop"], area) +
            html`<div class="toolbar">
                    <span class="badge">● Available</span
                    ><span class="badge amber">● Active order</span
                    ><span class="badge red">● Payment overdue</span
                    ><span class="badge gray">● Reserved</span
                    ><span style="margin-left:auto;color:var(--muted);font-size:12px"
                        >${defs.length} tables</span
                    >
                </div>
                <div class="table-grid">
                    ${defs
                        .map(
                            (t) =>
                                html`<article
                                    class="panel floor-card ${t.status}"
                                    data-action="table-detail"
                                    data-value="${esc(t.name)}"
                                    tabindex="0"
                                    role="button"
                                >
                                    <h3>${t.name}</h3>
                                    ${icon("tables")}${badge({ available: "Available", occupied: "Active order", overdue: "Payment overdue", reserved: "Reserved" }[t.status], { available: "", occupied: "amber", overdue: "red", reserved: "gray" }[t.status])}<strong
                                        >${t.total ? money(t.total) : t.status === "reserved" ? t.reservation?.arrival || "21:00 – 23:00" : tr("Ready for guests")}</strong
                                    ><small
                                        >${t.orders.length ? tr("Waiter: :waiter · :count open bills", { waiter: esc(waiter(t.orders[0].waiter)?.short), count: t.orders.length }) : t.status === "reserved" ? tr("Party of :count · :guest", { count: esc(t.reservation?.size || 6), guest: esc(t.reservation?.guest || "Reserved seating") }) : tr("Seats 4 guests")}</small
                                    >
                                </article>`,
                        )
                        .join("")}
                </div>
                ${footer()}`
        );
    }
    const field = (label, name, content, cls = "") =>
        html`<label class="form-field ${cls}"><span>${tr(label)}</span>${content}</label>`;
    const input = (label, name, type = "text", value = "", required = true) =>
        field(
            label,
            name,
            `<input name="${name}" type="${type}" value="${esc(value)}" ${required ? "required" : ""} ${type === "number" ? 'min="0" step="any"' : ""}>`,
        );
    const select = (label, name, options, selected = "") =>
        field(
            label,
            name,
            html`<select name="${name}">
                ${options
                    .map((o) => {
                        const val = Array.isArray(o) ? o[0] : o;
                        const label = Array.isArray(o) ? o[1] : o;
                        const display =
                            ["role", "type", "method", "unit", "status"].includes(name) ||
                            val === "" ||
                            /^All /.test(String(label))
                                ? tr(label)
                                : label;
                        return html`<option
                            value="${esc(val)}"
                            ${String(val) === String(selected) ? "selected" : ""}
                        >
                            ${esc(display)}
                        </option>`;
                    })
                    .join("")}
            </select>`,
        );
    const productOptions = () => db.products.map((p) => [p.id, p.name]);
    function inventoryScreen() {
        const active = tab || "Products";
        const list = db.products.filter(
            (p) =>
                (active !== "Low stock" || p.stock < p.min) &&
                (!search ||
                    `${p.name} ${p.sku} ${p.category}`
                        .toLowerCase()
                        .includes(search.toLowerCase())),
        );
        const action = ["Products", "Categories", "Units"].includes(active)
            ? btn(
                  active === "Products"
                      ? "Add product"
                      : active === "Categories"
                        ? "Add category"
                        : "Add unit",
                  "inventory-add",
                  "primary",
                  "plus",
                  `data-value="${active}"`,
              )
            : active === "Transfers"
              ? btn("New transfer", "transfer", "primary", "transfer")
              : active === "Adjustments"
                ? btn("Request adjustment", "adjustment", "primary", "plus")
                : active === "Goods receipt"
                  ? btn("Receive goods", "grn", "primary", "plus")
                  : btn("Receive stock", "grn", "primary", "plus");
        let body = "";
        if (["Products", "Current stock", "Low stock"].includes(active)) {
            body = html`<div class="toolbar">
                    <input
                        class="search-field"
                        data-input="search"
                        aria-label="Search inventory"
                        placeholder="Search product, SKU or category…"
                        value="${esc(search)}"
                    />${selectControl("category-filter", ["All categories", ...categories.slice(1)], category === "All products" ? "All categories" : category)}${btn("Export", "export-inventory", "", "download")}
                </div>
                <section class="panel">
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU / Category</th>
                                    <th>Cost</th>
                                    <th>Selling price</th>
                                    <th>Available stock</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                ${list
                                    .filter(
                                        (p) =>
                                            category === "All products" || p.category === category,
                                    )
                                    .map(
                                        (p) =>
                                            html`<tr>
                                                <td>
                                                    <div class="table-avatar">
                                                        <div
                                                            class="product-thumb"
                                                            style="width:29px;height:32px"
                                                        >
                                                            ${productVisual(p)}
                                                        </div>
                                                        <span>${esc(p.name)}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    ${p.sku}<br /><small style="color:var(--muted)"
                                                        >${p.category}</small
                                                    >
                                                </td>
                                                <td>${money(p.cost)}</td>
                                                <td class="amount">${money(p.price)}</td>
                                                <td>
                                                    ${quantity(p.stock)}<small
                                                        style="display:block;color:var(--muted)"
                                                        >Min. ${quantity(p.min)}</small
                                                    >
                                                </td>
                                                <td>
                                                    ${badge(p.stock < p.min ? "Low stock" : "In stock", p.stock < p.min ? "red" : "")}
                                                </td>
                                                <td>
                                                    ${btn("Edit", "edit-product", "small", "", `data-id="${p.id}"`)}
                                                </td>
                                            </tr>`,
                                    )
                                    .join("")}
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span
                            >${list.length} products ·
                            ${esc(db.locations.find((l) => l.id === db.location_id)?.name || "Stock location")}</span
                        ><span>Base quantities shown in bottles / units</span>
                    </div>
                </section>`;
        } else if (active === "Opening stock" || active === "Closing stock") {
            body = historicalStockView(
                active === "Opening stock" ? "Opening Stock" : "Closing Stock",
            );
        } else if (active === "Categories") {
            body = html`<section class="panel">
                ${simpleTable(
                    ["Category", "Status", "Sort order", "Products", "Actions"],
                    db.categoryRecords.map((c, i) => [
                        html`<span style="color:${esc(c.color)}">${icon(c.icon)}</span>
                            <button
                                class="text-link"
                                data-action="category-open"
                                data-value="${esc(c.name)}"
                            >
                                ${esc(c.name)}
                            </button>`,
                        badge(c.active ? "Active" : "Inactive", c.active ? "" : "gray"),
                        c.sort_order,
                        c.products_count,
                        btn("Edit", "edit-category", "small", "", `data-id="${c.id}"`) +
                            btn(
                                c.active ? "Deactivate" : "Activate",
                                "toggle-category",
                                "small",
                                "",
                                `data-id="${c.id}"`,
                            ) +
                            btn(
                                "↑",
                                "reorder-category",
                                "small",
                                "",
                                `data-id="${c.id}" data-value="-1" aria-label="Move ${esc(c.name)} up" ${i === 0 ? "disabled" : ""}`,
                            ) +
                            btn(
                                "↓",
                                "reorder-category",
                                "small",
                                "",
                                `data-id="${c.id}" data-value="1" aria-label="Move ${esc(c.name)} down" ${i === db.categoryRecords.length - 1 ? "disabled" : ""}`,
                            ),
                    ]),
                )}
            </section>`;
        } else if (active === "Units") {
            body = html`<section class="panel">
                    <div class="panel-header"><h2>Available units</h2></div>
                    <div class="panel-body">
                        ${db.unitRecords.map((u) => badge(u.name)).join(" ")}
                    </div>
                </section>
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Selling units & conversions</h2>
                            <p>Each unit has its own selling price.</p>
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Unit</th>
                                    <th>Base quantity</th>
                                    <th>Selling price</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${db.products
                                    .flatMap((p) =>
                                        p.units.map(
                                            (u) =>
                                                html`<tr>
                                                    <td>${p.name}</td>
                                                    <td>${u[0]}</td>
                                                    <td>
                                                        ${quantity(u[1])}
                                                        ${tr(p.category === "Beer" ? "bottles" : "units")}
                                                    </td>
                                                    <td>${money(u[2])}</td>
                                                </tr>`,
                                        ),
                                    )
                                    .join("")}
                            </tbody>
                        </table>
                    </div>
                </section>`;
        } else if (active === "Adjustments") {
            body = html`<section class="panel">
                <div class="panel-header">
                    <h2>Adjustment requests</h2>
                    ${badge("Manager approval required", "amber")}
                </div>
                ${approvalTable(db.approvals.filter((a) => a.type !== "Void request"))}
            </section>`;
        } else {
            const list = db.movements.filter((m) =>
                active === "Transfers"
                    ? m.reason === "Transfer"
                    : active === "Goods receipt"
                      ? m.reason === "Goods receipt"
                      : true,
            );
            body = html`<section class="panel">
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Product</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Quantity</th>
                                <th>Movement Type</th>
                                <th>Date/Time</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${
                                list
                                    .map(
                                        (m) =>
                                            html`<tr>
                                                <td>${esc(m.ref)}</td>
                                                <td>${esc(m.product)}</td>
                                                <td>${esc(m.from)}</td>
                                                <td>${esc(m.to)}</td>
                                                <td>${quantity(m.qty)}</td>
                                                <td>
                                                    ${badge(m.type, m.reason === "Transfer" ? "blue" : "")}
                                                </td>
                                                <td>${esc(m.time)}</td>
                                                <td>${esc(m.user || "—")}</td>
                                            </tr>`,
                                    )
                                    .join("") ||
                                html`<tr>
                                    <td colspan="8">
                                        <div class="empty-state">No stock movements yet.</div>
                                    </td>
                                </tr>`
                            }
                        </tbody>
                    </table>
                </div>
            </section>`;
        }
        return (
            heading(
                "Inventory control",
                "Know what’s on your shelves, behind the bar, and on the move.",
                `${branchSelect()}${select(
                    "Stock location",
                    "location-display",
                    db.locations.map((l) => [l.id, l.name]),
                    db.location_id,
                ).replace(
                    html`<select name="location-display"></select>`,
                    html`<select class="select-control" data-change="stock-location"></select>`,
                )}${action}`,
            ) +
            tabs(
                [
                    "Products",
                    "Categories",
                    "Units",
                    "Current stock",
                    "Goods receipt",
                    "Transfers",
                    "Adjustments",
                    "Stock Movement",
                    "Low stock",
                    "Opening stock",
                    "Closing stock",
                ],
                active,
            ) +
            (active === "Transfers"
                ? html`<section class="panel" style="margin-bottom:20px">
                      ${simpleTable(
                          ["Transfer", "Source", "Destination", "Status", "Action"],
                          db.transfers.map((t) => [
                              t.number,
                              esc(db.locations.find((l) => l.id === t.source_location_id)?.name),
                              esc(
                                  db.locations.find((l) => l.id === t.destination_location_id)
                                      ?.name,
                              ),
                              badge(t.status, t.status === "IN_TRANSIT" ? "amber" : ""),
                              t.status === "IN_TRANSIT"
                                  ? btn(
                                        "Confirm receipt",
                                        "receive-transfer",
                                        "small primary",
                                        "",
                                        `data-id="${t.id}"`,
                                    )
                                  : "—",
                          ]),
                      )}
                  </section>`
                : "") +
            body +
            footer()
        );
    }
    function selectControl(action, options, selected) {
        return html`<select
            class="select-control"
            data-change="${action}"
            aria-label="${action.replaceAll("-", " ")}"
        >
            ${options.map((o) => html`<option value="${esc(o)}" ${o === selected ? "selected" : ""}>${/^All /.test(o) ? tr(o) : esc(o)}</option>`).join("")}
        </select>`;
    }
    function shiftsScreen() {
        if (!canIssue() && !db.visibility?.staff_operations) {
            return (
                heading(
                    "Your shifts",
                    "Your current shift and shift history.",
                    branchSelect() +
                        btn("Open shift", "open-shift", "", "plus") +
                        (db.shift.active && waiter(db.user.id)
                            ? btn(
                                  "Close waiter shift",
                                  "close-waiter",
                                  "primary",
                                  "lock",
                                  `data-id="${db.user.id}"`,
                              )
                            : ""),
                ) +
                html`<section class="panel">
                    ${simpleTable(
                        ["Shift", "Counter", "Opened", "Closed", "Status"],
                        db.shifts.map((s) => [
                            esc(s.number),
                            esc(db.locations.find((l) => l.id === s.stock_location_id)?.name || ""),
                            esc(s.opened_at),
                            esc(s.closed_at || "—"),
                            badge(s.status === "OPEN" ? "Active" : "Closed"),
                        ]),
                    )}
                </section>` +
                footer()
            );
        }
        const expected = db.shift.opening + db.shift.cashSales;
        return (
            heading(
                "Shift management",
                "Start prepared. Close reconciled. Account for every shilling.",
                branchSelect() +
                    btn("Open shift", "open-shift", "", "plus") +
                    (db.shift.active
                        ? btn("Close cashier shift", "close-shift", "primary", "lock")
                        : ""),
            ) +
            tabs(["Current shifts", "Shift history"], tab || "Current shifts") +
            (tab === "Shift history"
                ? html`<section class="panel">
                      ${simpleTable(
                          [
                              "Shift",
                              "Type",
                              "Opened",
                              "Closed",
                              "Expected cash",
                              "Actual cash",
                              "Difference",
                          ],
                          db.shifts
                              .filter((s) => s.status === "CLOSED")
                              .map((s) => [
                                  s.number,
                                  tr(s.type),
                                  esc(s.opened_at),
                                  esc(s.closed_at),
                                  money(s.expected_cash),
                                  money(s.actual_cash),
                                  money(s.cash_difference),
                              ]),
                      )}
                  </section>`
                : html`<div class="two-column">
                      <section class="panel">
                          <div class="panel-header">
                              <div>
                                  <h2>${esc(db.shift.cashier)} · Cashier shift</h2>
                                  <p>${esc(cartCounter || "No counter selected")}</p>
                              </div>
                              ${badge(db.shift.active ? "Shift active" : "Closed", db.shift.active ? "" : "gray")}
                          </div>
                          <div class="panel-body">
                              ${[
                                  ["Opening cash", db.shift.opening],
                                  ["Cash sales", db.shift.cashSales],
                                  ["Mobile money", db.shift.mobile],
                                  ["Card payments", db.shift.card],
                              ]
                                  .map(
                                      ([label, value]) =>
                                          html`<div class="detail-row">
                                              <span>${tr(label)}</span
                                              ><strong>${money(value)}</strong>
                                          </div>`,
                                  )
                                  .join("")}
                              <div class="balance-line">
                                  <span>Expected cash in drawer</span
                                  ><strong class="text-amber">${money(expected)}</strong>
                              </div>
                              <div class="info-box">
                                  Expected cash = opening cash + cash sales − cash refunds.
                              </div>
                          </div>
                      </section>
                      <section class="panel">
                          <div class="panel-header"><h2>Waiter shift readiness</h2></div>
                          <div class="panel-body">
                              ${branchWaiters()
                                  .map(
                                      (w, i) =>
                                          html`<div class="waiter-row">
                                              ${avatar(w.name, i)}
                                              <div>
                                                  <div class="waiter-name">${esc(w.short)}</div>
                                                  <small
                                                      >${money(outstanding(w))} outstanding</small
                                                  >
                                              </div>
                                              <div style="margin-left:auto">
                                                  ${btn(w.active ? "Close shift" : "Closed", "close-waiter", "small", "", `data-id="${w.id}" ${w.active && (permitted("manage-users") || w.id === db.user.id) ? "" : "disabled"}`)}
                                              </div>
                                          </div>`,
                                  )
                                  .join("")}
                          </div>
                      </section>
                  </div>`) +
            footer()
        );
    }
    function paymentsScreen() {
        const list = db.payments.filter(
            (p) => db.branch === "All branches" || p.branch === db.branch,
        );
        return (
            heading(
                "Payments & settlements",
                "Every payment in one place, from cash to mobile money.",
                `${branchSelect()}${btn("Export payments", "export-payments", "", "download")}`,
            ) +
            html`<div class="kpi-grid">
                    ${kpi("Cash collected", cashValue(db.shift.cashSales), "cash", "green", "", "Current cashier shift", false)}${kpi("Mobile money", cashValue(db.shift.mobile), "phone", "blue", "", "M-Pesa, Airtel & Mixx", false)}${kpi("Card payments", cashValue(db.shift.card), "wallet", "", "", "Current cashier shift", false)}${kpi("Total transactions", String(list.length), "orders", "", "", "Today’s settlements", false)}
                </div>
                <section class="panel">
                    <div class="panel-header">
                        <h2>Latest payments</h2>
                        ${badge("Reconciled")}
                    </div>
                    ${simpleTable(
                        ["Reference", "Invoice", "Waiter", "Method", "Amount", "Time"],
                        list.map((p) => [
                            p.id,
                            invoice(p.invoice),
                            waiter(p.waiter)?.short || "Guest",
                            badge(p.method, p.method === "Cash" ? "" : "blue"),
                            money(p.amount),
                            p.time,
                        ]),
                    )}
                </section>
                ${footer()}`
        );
    }
    const reportNames = [
        "Daily Sales",
        "Sales by Waiter",
        "Sales by Cashier",
        "Sales by Product",
        "Sales by Category",
        "Sales by Table",
        "Sales by Counter",
        "Sales by Branch",
        "Waiter Outstanding",
        "Waiter Settlement",
        "Cashier Shift",
        "Waiter Shift",
        "Opening Stock",
        "Closing Stock",
        "Stock Movement",
        "Stock Transfer",
        "Goods Received",
        "Breakages",
        "Complimentary Drinks",
        "Staff Consumption",
        "Returns",
        "Voided Sales",
        "Cash Shortage / Excess",
        "Profit Report",
        "Best Selling Products",
        "Slow Moving Products",
        "Payment Method Report",
    ];
    function reportsScreen() {
        return (
            heading(
                "Reports & insights",
                "Turn every night’s numbers into your next smart decision.",
                `${branchSelect()}${dateSelect()}`,
            ) +
            html`<div class="toolbar">
                    <input
                        class="search-field"
                        data-input="search"
                        aria-label="Search reports"
                        placeholder="Find a report…"
                        value="${esc(search)}"
                    />
                </div>
                <div class="report-grid">
                    ${reportNames
                        .filter((r) => tr(r).toLowerCase().includes(search.toLowerCase()))
                        .map(
                            (r, i) =>
                                html`<button
                                    class="panel report-card"
                                    data-action="report"
                                    data-value="${r}"
                                    style="text-align:left;color:var(--text)"
                                >
                                    ${icon(i < 8 ? "chart" : i < 12 ? "users" : i < 17 ? "box" : "audit")}
                                    <div>
                                        <strong>${tr(r)}</strong
                                        ><small>View, filter and export</small>
                                    </div>
                                    ${icon("chevron", "chevron")}
                                </button>`,
                        )
                        .join("")}
                </div>
                ${footer()}`
        );
    }
    function simpleTable(headers, rows) {
        return html`<div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        ${headers.map((h) => html`<th>${tr(h)}</th>`).join("")}
                    </tr>
                </thead>
                <tbody>
                    ${
                        rows
                            .map(
                                (row) =>
                                    html`<tr>
                                        ${row.map((cell) => html`<td>${cell ?? "—"}</td>`).join("")}
                                    </tr>`,
                            )
                            .join("") ||
                        html`<tr>
                            <td colspan="${headers.length}">
                                <div class="empty-state">No records to display.</div>
                            </td>
                        </tr>`
                    }
                </tbody>
            </table>
        </div>`;
    }
    function approvalTable(list) {
        return simpleTable(
            [
                "Request",
                "Requested by",
                "Reference / Product",
                "Amount",
                "Reason",
                "Status",
                "Actions",
            ],
            list.map((a) => [
                a.type,
                esc(a.user),
                a.invoice ? invoice(a.invoice) : esc(product(a.product)?.name),
                money(a.amount),
                html`<span style="display:block;max-width:210px;white-space:normal"
                    >${esc(a.reason)}</span
                >`,
                badge(
                    a.status,
                    a.status === "Pending" ? "amber" : a.status === "Rejected" ? "red" : "",
                ),
                a.status === "Pending" && managerial()
                    ? `${btn("Approve", "approve", "small success", "", `data-id="${a.id}"`)} ${btn("Reject", "reject-approval", "small danger", "", `data-id="${a.id}"`)}`
                    : "—",
            ]),
        );
    }
    function approvalsScreen() {
        return (
            heading(
                "Approval center",
                "Review exceptions. Protect your stock and your revenue.",
                branchSelect(),
            ) +
            tabs(
                ["All requests", "Void requests", "Stock adjustments", "Complimentary"],
                tab || "All requests",
            ) +
            html`<section class="panel">
                    ${approvalTable(db.approvals.filter((a) => !tab || tab === "All requests" || a.type.toLowerCase() === tab.toLowerCase().replace(/s$/, "")))}
                </section>
                ${footer()}`
        );
    }
    function auditScreen() {
        return (
            heading(
                "Audit trail",
                "A clear record of who did what, when, and why.",
                btn("Export audit log", "export-audit", "", "download"),
            ) +
            html`<div class="toolbar">
                    <input
                        class="search-field"
                        data-input="search"
                        aria-label="Search audit trail"
                        placeholder="Search action, user or reference…"
                        value="${esc(search)}"
                    />
                </div>
                <section class="panel">
                    ${simpleTable(
                        [
                            "User",
                            "Action / Module",
                            "Reference",
                            "Previous value",
                            "New value",
                            "Date & time",
                            "Device / terminal",
                        ],
                        db.audit
                            .filter((a) =>
                                JSON.stringify(a).toLowerCase().includes(search.toLowerCase()),
                            )
                            .map((a) => [
                                esc(a.user),
                                html`${esc(a.action)}<small style="display:block;color:var(--muted)"
                                        >${esc(a.module)}</small
                                    >`,
                                esc(a.ref),
                                esc(a.previous),
                                esc(a.next),
                                `${esc(a.time)}`,
                                esc(a.device),
                            ]),
                    )}
                </section>
                ${footer()}`
        );
    }
    function notificationsScreen() {
        return (
            heading(
                "Notifications",
                "Your attention, where it matters.",
                btn("Mark all as read", "read-notifications", "", "check"),
            ) +
            html`<section class="panel">
                ${
                    db.notifications
                        .map(
                            (n) =>
                                html`<button
                                    class="notification-card"
                                    style="width:100%;background:none;text-align:left;color:inherit"
                                    data-nav="${esc(n.screen)}"
                                >
                                    ${icon("bell")}
                                    <div>
                                        <h3>${esc(tr(n.title))}</h3>
                                        <p>
                                            ${esc(n.type === "NEW_ORDER" && n.message.includes(" from ") ? tr(":invoice from :waiter", { invoice: n.message.split(" from ")[0], waiter: n.message.slice(n.message.indexOf(" from ") + 6) }) : n.message)}
                                        </p>
                                    </div>
                                    <small>${esc(n.created_at.slice(0, 16))}</small>
                                </button>`,
                        )
                        .join("") ||
                    html`<div class="empty-state">All caught up. No notifications yet.</div>`
                }
            </section>` +
            footer()
        );
    }

    function setupScreen() {
        const steps = [
            ["business", "Business information", "/settings/business"],
            ["branch", "Main branch", "branches"],
            ["locations", "Counters / stock locations", "counters"],
            ["categories", "Categories", "inventory"],
            ["products", "Add first products", "inventory"],
            ["staff", "Add staff", "users"],
            ["finish", "Finish setup", "dashboard"],
        ];
        const done = steps.filter(([key]) =>
            ["complete", "skip"].includes(db.onboarding[key]),
        ).length;
        return (
            heading(
                "Business setup",
                tr(":count of 7 steps complete. You can return to this guide at any time.", {
                    count: done,
                }),
            ) +
            html`<div class="report-grid">
                ${steps
                    .map(
                        ([key, title, dest], i) =>
                            html`<section class="panel setup-step">
                                <h2>${i + 1}. ${tr(title)}</h2>
                                ${badge(db.onboarding[key] || "To do", db.onboarding[key] === "skip" ? "gray" : "")}
                                <div class="onboarding-actions">
                                    ${dest.startsWith("/") ? html`<a class="button" href="${dest}">Review information</a>` : btn("Open", "setup-open", "", "", `data-value="${dest}" data-step="${key}"`)}${btn(key === "finish" ? "Finish setup" : "Mark complete", "setup-status", "primary", "check", `data-value="complete" data-step="${key}" ${key === "finish" && steps.slice(0, 6).some(([k]) => !["complete", "skip"].includes(db.onboarding[k])) ? "disabled" : ""}`)}${["locations", "categories", "products", "staff"].includes(key) ? btn("Skip for now", "setup-status", "", "", `data-value="skip" data-step="${key}"`) : ""}${db.onboarding[key] ? btn("Continue later", "setup-status", "small", "", `data-value="resume" data-step="${key}"`) : ""}
                                </div>
                            </section>`,
                    )
                    .join("")}
            </div>` +
            footer()
        );
    }
    function categoryForm(id) {
        const c = db.categoryRecords.find((item) => item.id === Number(id));
        modal(
            c ? "Edit category" : "Add category",
            html`<input type="hidden" name="id" value="${c?.id || ""}" />
                <div class="form-grid">
                    ${input("Category name", "name", "text", c?.name || "")}${select("Status", "active", ["Active", "Inactive"], c?.active === false ? "Inactive" : "Active")}${input("Sort order", "sort_order", "number", c?.sort_order ?? db.categoryRecords.length)}${select("Icon", "icon", ["box", "glass", "cocktail", "beer", "coffee", "food", "wine"], c?.icon || "box")}${input("Color", "color", "color", c?.color || "#956015")}
                </div>`,
            submitButton("Save category"),
            false,
            "category",
        );
    }
    function branchForm(id) {
        const b = db.branches.find((item) => item.id === Number(id));
        modal(
            "Edit branch",
            html`<input type="hidden" name="id" value="${b.id}" />
                <div class="form-grid">
                    ${input("Branch name", "name", "text", b.name)}${input("Branch code", "code", "text", b.code || "", false)}${input("Address", "address", "text", b.address || "", false)}${select("Status", "active", ["Active", "Inactive"], b.active ? "Active" : "Inactive")}
                </div>`,
            submitButton("Save branch"),
            false,
            "add-branch",
        );
    }
    function adminScreen() {
        if (screen === "settings")
            return (
                heading(
                    "Workspace settings",
                    "Make Nightshift work the way your business does.",
                    btn("Save changes", "save-settings", "primary", "check"),
                ) +
                html`<div class="two-column">
                    <section class="panel">
                        <div class="panel-header">
                            <h2>Business details</h2>
                            <a class="button small" href="/settings/business">Business Profile</a
                            >${permitted("whatsapp.view_settings") ? html`<a class="button small" href="/settings/whatsapp">WhatsApp</a>` : ""}
                        </div>
                        <div class="panel-body">
                            <div class="form-grid">
                                ${input("Company name", "company", "text", db.company)}${input("Business email", "email", "email", db.companySettings.email || "", false)}${input("Phone", "phone", "tel", db.companySettings.phone || "", false)}
                            </div>
                            <div class="settings-row">
                                <div>
                                    Stock issue control<small
                                        >Deduct stock when drinks are issued</small
                                    >
                                </div>
                                ${badge("Enabled")}
                            </div>
                            <div class="settings-row">
                                <div>
                                    Waiter balance protection<small
                                        >Require settlement before closing shifts</small
                                    >
                                </div>
                                ${badge("Enabled")}
                            </div>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-header"><h2>Your account</h2></div>
                        <div class="panel-body">
                            <div class="profile-summary">
                                ${avatar(db.user.name)}
                                <div>
                                    <h3>${esc(db.user.name)}</h3>
                                    <p>${esc(tr(db.role))}</p>
                                </div>
                            </div>
                            <a class="button" href="/settings/profile">Profile & security</a>
                        </div>
                    </section>
                </div>` +
                footer()
            );
        if (screen === "branches")
            return (
                heading(
                    "Branches",
                    "One business. Every location connected.",
                    btn("Add branch", "add-branch", "primary", "plus"),
                ) +
                html`<div class="report-grid">
                    ${db.branches
                        .map(
                            (b) =>
                                html`<article class="panel" style="padding:23px">
                                    <div class="workspace-icon">${icon("branch")}</div>
                                    <h2 style="font-size:17px">${esc(b.name)}</h2>
                                    <p style="color:var(--muted)">${esc(b.address || "")}</p>
                                    ${badge(b.active ? "Active" : "Inactive")}${btn("Edit", "edit-branch", "small", "", `data-id="${b.id}"`)}${btn("Open branch", "select-branch", "small", "arrow", `data-value="${esc(b.name)}"`)}
                                </article>`,
                        )
                        .join("")}
                </div>` +
                footer()
            );
        if (screen === "counters")
            return (
                heading(
                    "Counters & stores",
                    "The right stock, in the right place.",
                    branchSelect() + btn("Add location", "add-counter", "primary", "plus"),
                ) +
                html`<section class="panel">
                    ${simpleTable(
                        ["Location", "Type", "Branch", "Status"],
                        db.locations.map((l) => [
                            esc(l.name),
                            tr(l.type),
                            esc(db.branches.find((b) => b.id === l.branch_id)?.name),
                            badge(l.active ? "Active" : "Inactive"),
                        ]),
                    )}
                </section>` +
                footer()
            );
        if (screen === "suppliers")
            return (
                heading(
                    "Suppliers",
                    "Your supply partners and purchase history.",
                    btn("Add supplier", "add-supplier", "primary", "plus"),
                ) +
                html`<section class="panel">
                    ${simpleTable(
                        ["Supplier", "Category", "Contact"],
                        db.suppliers.map((s) => [esc(s.name), esc(s.category), esc(s.contact)]),
                    )}
                </section>` +
                footer()
            );
        const staffFilters = [
            ["", "All staff"],
            ["Waiter", "Waiters"],
            ["Cashier", "Cashiers"],
            ["Supervisor", "Supervisors"],
            ["Manager", "Manager"],
            ["Store Keeper", "Store Keeper"],
            ["Supervisor", "Supervisor role"],
        ];
        const users = db.team.filter(
            (u) =>
                (!staffRole || u.role === staffRole) &&
                (!staffStatus || u.active === (staffStatus === "Active")),
        );
        return (
            heading(
                "Staff",
                "The right access for every member of your team.",
                branchSelect() + btn("Add team member", "add-user", "primary", "plus"),
            ) +
            html`<div class="subtabs" role="tablist" aria-label="Staff roles">
                    ${staffFilters.map(([role, label]) => html`<button role="tab" aria-selected="${staffLabel === label}" class="${staffLabel === label ? "active" : ""}" data-action="staff-filter" data-value="${role}" data-label="${label}">${esc(tr(label))}</button>`).join("")}
                </div>
                <section class="panel">
                    <div class="toolbar">
                        <label
                            >${tr("Status")}
                            <select
                                class="select-control"
                                data-change="staff-status"
                                aria-label="Staff status"
                            >
                                ${[
                                    ["", "All statuses"],
                                    ["Active", "Active"],
                                    ["Inactive", "Inactive"],
                                ]
                                    .map(
                                        ([value, label]) =>
                                            html`<option
                                                value="${value}"
                                                ${staffStatus === value ? "selected" : ""}
                                            >
                                                ${tr(label)}
                                            </option>`,
                                    )
                                    .join("")}
                            </select>
                        </label>
                    </div>
                    ${simpleTable(
                        [
                            "Team member",
                            "Username",
                            "Role",
                            "Branch",
                            "Email",
                            "Phone",
                            "Status",
                            "Operational PIN",
                        ],
                        users.map((u, i) => [
                            html`<div class="table-avatar">
                                ${avatar(u.name, i)}${esc(u.name)}
                            </div>`,
                            esc(u.username),
                            esc(tr(u.role)),
                            esc(u.branch),
                            esc(u.email),
                            esc(u.phone),
                            badge(u.active ? "Active" : "Inactive", u.active ? "" : "gray"),
                            ["Company Admin / Owner", "Super Admin"].includes(db.role)
                                ? btn(
                                      u.pin_configured ? "Reset PIN" : "Set PIN",
                                      "staff-pin",
                                      "small",
                                      "lock",
                                      `data-id="${u.id}"`,
                                  )
                                : "—",
                        ]),
                    )}
                </section>` +
            footer()
        );
    }

    function renderScreen() {
        return (
            {
                dashboard,
                setup: setupScreen,
                orders: ordersScreen,
                waiters: waitersScreen,
                pos: posScreen,
                tables: tablesScreen,
                inventory: inventoryScreen,
                shifts: shiftsScreen,
                payments: paymentsScreen,
                reports: reportsScreen,
                approvals: approvalsScreen,
                audit: auditScreen,
                notifications: notificationsScreen,
            }[screen] || adminScreen
        )();
    }
    function modal(title, body, actions = "", wide = false, formAction = "") {
        modalTrigger = document.activeElement;
        document.getElementById("modal-root").innerHTML = html`<div class="modal-backdrop">
            <section
                class="modal ${wide ? "wide" : ""}"
                role="dialog"
                aria-modal="true"
                aria-labelledby="modal-title"
                tabindex="-1"
            >
                <div class="modal-heading">
                    <h2 id="modal-title">${tr(title)}</h2>
                    <button class="icon-button" data-action="close-modal" aria-label="Close dialog">
                        ${icon("close")}
                    </button>
                </div>
                ${formAction ? `<form data-form="${formAction}">` : ""}
                <div class="modal-body">${body}</div>
                ${actions ? html`<div class="modal-actions">${btn("Cancel", "close-modal")}${actions}</div>` : ""}${formAction ? html`</form>` : ""}
            </section>
        </div>`;
        document.body.style.overflow = "hidden";
        document
            .querySelector('.modal input:not([type="hidden"]),.modal select,.modal button')
            ?.focus();
    }
    function closeModal() {
        document.getElementById("modal-root").innerHTML = "";
        document.body.style.overflow = "";
        modalTrigger?.focus();
    }
    function confirm(title, description, action, id, label = "Confirm") {
        modal(
            title,
            html`<p class="modal-description">${tr(description)}</p>`,
            btn(label, action, "primary", "check", `data-id="${id}"`),
        );
    }
    const pinField = (disabled = false, required = true) =>
        html`<label class="form-field"
            ><span>4-digit PIN</span
            ><input
                type="password"
                name="pin"
                inputmode="numeric"
                pattern="[0-9]{4}"
                minlength="4"
                maxlength="4"
                autocomplete="off"
                ${required ? "required" : ""}
                ${disabled ? "disabled" : ""}
        /></label>`;
    function cartPayload() {
        return {
            stock_location_id: db.locations.find((l) => l.name === cartCounter)?.id,
            service_table_id: db.tables.find((t) => t.name === cartTable)?.id || null,
            discount: String(cartDiscount),
            items: cart.map((it) => ({
                product_unit_id: product(it.product).units[it.unit][3],
                quantity: String(it.qty),
            })),
        };
    }
    function servingModal(draftId = null) {
        if (!draftId && !cart.length) return;
        const draft = db.orders.find((o) => o.id === draftId);
        const counter = draft?.counter_id || db.locations.find((l) => l.name === cartCounter)?.id;
        const waiters = (db.servingWaiters || []).filter(
            (w) => w.counter_ids.includes(counter) && (!draft || w.id === draft.waiter),
        );
        modal(
            "Who is serving this order?",
            html`<input type="hidden" name="id" value="${draftId || ""}" /><input
                    type="hidden"
                    name="waiter_id"
                />
                <p class="modal-description">
                    Choose the serving waiter and confirm their PIN. Drinks are issued immediately
                    after confirmation.
                </p>
                <div class="serving-waiters">
                    ${waiters.map((w, i) => html`<button type="button" class="button waiter-card" data-action="serving-waiter" data-id="${w.id}" aria-pressed="false" ${w.pin_configured ? "" : "disabled"}>${avatar(w.name, i)}<span>${esc(w.name)}${w.pin_configured ? "" : html`<small>Ask Company Admin to set PIN</small>`}</span></button>`).join("") || html`<p>No active waiters with an open shift at this counter.</p>`}
                </div>
                ${pinField(true)}
                <p data-pin-error role="alert" class="text-red"></p>`,
            html`<button type="submit" class="button primary" disabled>
                Confirm and Send to Cashier
            </button>`,
            false,
            "serving-order",
        );
    }
    function staffPinModal(id) {
        const staff = db.team.find((u) => u.id === id);
        modal(
            `${tr("Set / reset PIN")} · ${esc(staff.name)}`,
            html`<input type="hidden" name="id" value="${id}" />
                <p class="modal-description">
                    Enter a new four-digit PIN. The saved PIN cannot be viewed. Leave blank to
                    remove it.
                </p>
                ${pinField(false, false)}`,
            submitButton("Save PIN"),
            false,
            "staff-pin",
        );
    }
    function waiterPaymentModal(id, mode = "") {
        const w = waiter(id);
        const balance = Number(w.settlement_balance || 0);
        if (!balance)
            return toast(
                "No unpaid issued invoices at this counter. Select the correct counter first.",
            );
        modal(
            mode === "full" ? "Full waiter settlement" : "Receive waiter payment",
            html`<input type="hidden" name="waiter_id" value="${id}" /><input
                    type="hidden"
                    name="counter"
                    value="${db.location_id}"
                />
                <p>
                    ${esc(w.name)} · ${esc(db.locations.find((l) => l.id === db.location_id)?.name)}
                </p>
                <div class="payment-total">
                    <span>Outstanding at this counter</span><strong>${money(balance)}</strong>
                </div>
                ${input("Amount", "amount", "number", mode === "partial" ? "" : balance)}${select(
                    "Payment method",
                    "method",
                    paymentMethods.filter((m) => m !== "Credit"),
                )}
                <p class="modal-description">
                    Allocated to the oldest unpaid invoices first. Other counters and invoices
                    awaiting a void decision remain separate.
                </p>`,
            submitButton("Receive payment"),
            false,
            "waiter-payment",
        );
    }
    function waiterProfile(id) {
        const w = waiter(id);
        if (!w) return;
        if (db.visibility?.settlement_only) {
            const unpaid = db.orders.filter(
                (o) =>
                    o.waiter === w.id &&
                    ["Issued", "Partially paid", "Void requested"].includes(o.status),
            );
            return modal(
                `${tr("Account")} · ${esc(w.short)}`,
                html`<p>${esc(w.name)} · ${esc(cartCounter)}</p>
                    <div class="balance-line">
                        <span>Outstanding Balance</span
                        ><strong>${money(w.settlement_balance)}</strong>
                    </div>
                    <h3>Unpaid invoices</h3>
                    ${ordersTable(unpaid, true)}`,
                outstanding(w) > 0
                    ? btn(
                          "Receive Payment",
                          "waiter-payment",
                          "primary",
                          "wallet",
                          `data-id="${w.id}"`,
                      )
                    : "",
                true,
            );
        }
        const unpaid = db.orders.filter(
            (o) =>
                o.waiter === w.id &&
                ["Issued", "Partially paid", "Void requested"].includes(o.status),
        );
        const history = db.payments.filter((p) => p.waiter === w.id);
        modal(
            `${tr("Account")} · ${esc(w.short)}`,
            html`<div class="profile-summary">
                    ${avatar(w.name, w.id - 1)}
                    <div>
                        <h3>${esc(w.name)}</h3>
                        <p>
                            ${esc(w.branch)} ·
                            ${esc(db.visibility?.settlement_only ? cartCounter : w.shift_number || "No open shift")}
                            · ${tr(w.active ? "On duty" : "Shift closed")}
                        </p>
                    </div>
                    <div style="margin-left:auto">
                        ${badge(outstanding(w) ? "Balance due" : "Settled", outstanding(w) ? "amber" : "")}
                    </div>
                </div>
                <div class="kpi-grid">
                    ${kpi("Drinks issued", cashValue(w.issued), "glass", "", "", "Account totals", false)}${kpi("Payments Received", cashValue(w.paid), "wallet", "green", "", "Account totals", false)}${kpi("Outstanding", cashValue(outstanding(w)), "users", "red", "", "Returns: " + money(w.returns) + " · Adjustments: " + money(w.adjustments), false)}
                </div>
                <h3 style="font-size:12px;margin-bottom:10px">Unpaid invoices</h3>
                ${ordersTable(unpaid, true)}
                <h3 style="font-size:12px;margin:22px 0 10px">Payment history</h3>
                ${simpleTable(
                    ["Reference", "Invoice", "Method", "Amount", "Time"],
                    history.map((p) => [
                        p.id,
                        invoice(p.invoice),
                        p.method,
                        money(p.amount),
                        p.time,
                    ]),
                )}
                <h3 style="font-size:12px;margin:22px 0 10px">Transaction ledger</h3>
                ${simpleTable(
                    ["Type", "Debit", "Credit", "Source"],
                    (w.ledger || []).map((l) => [
                        l.entry_type,
                        money(l.debit),
                        money(l.credit),
                        l.reference_type + " #" + l.reference_id,
                    ]),
                )}`,
            btn(
                "Close waiter shift",
                "close-waiter",
                "primary",
                "lock",
                `data-id="${w.id}" ${w.active && (permitted("manage-users") || w.id === db.user.id) ? "" : "disabled"}`,
            ),
            true,
        );
    }
    function orderDetail(id) {
        const o = db.orders.find((o) => o.id === Number(id));
        if (!o) return;
        let actions = btn("Print receipt", "receipt", "", "print", `data-id="${o.id}"`);
        if (o.status === "Draft")
            actions += btn(
                "Send to cashier",
                "send-draft",
                "primary",
                "arrow",
                `data-id="${o.id}"`,
            );
        if (o.status === "Waiting for issue" && canIssue())
            actions +=
                btn("Reject", "reject-order", "danger", "", `data-id="${o.id}"`) +
                btn("Issue drinks", "issue", "primary", "box", `data-id="${o.id}"`);
        if (["Issued", "Partially paid"].includes(o.status)) {
            if (canIssue())
                actions +=
                    btn("Return items", "return-items", "", "refresh", `data-id="${o.id}"`) +
                    btn("Receive payment", "payment", "primary", "wallet", `data-id="${o.id}"`);
            actions += btn("Request void", "void-request", "danger", "ban", `data-id="${o.id}"`);
        }
        modal(
            invoice(o.id),
            html`<div class="toolbar">
                    ${badge(o.status, statusColor(o.status))}<span style="color:var(--muted)"
                        >${o.time} · ${o.branch} · ${o.counter}</span
                    >
                </div>
                <div class="detail-row">
                    <span>Waiter</span><strong>${esc(waiter(o.waiter)?.name)}</strong>
                </div>
                <div class="detail-row"><span>Table</span><strong>${esc(o.table)}</strong></div>
                ${simpleTable(
                    ["Product", "Quantity", "Unit", "Price", "Total"],
                    o.items.map((it) => {
                        const p = product(it.product);
                        return [
                            esc(p?.name),
                            quantity(it.qty),
                            esc(p?.units[it.unit]?.[0]),
                            money(it.price),
                            money(it.qty * it.price),
                        ];
                    }),
                )}
                <div class="detail-row">
                    <span>Discount</span><strong>${money(o.discount || 0)}</strong>
                </div>
                <div class="balance-line">
                    <span>Invoice total</span><strong>${money(o.total)}</strong>
                </div>
                <div class="detail-row">
                    <span>Paid</span><strong class="text-green">${money(o.paid)}</strong>
                </div>
                <div class="detail-row">
                    <span>Remaining</span
                    ><strong class="text-amber">${money(o.total - o.paid)}</strong>
                </div>`,
            actions,
            true,
        );
    }
    const paymentMethods = [
        "Cash",
        "M-Pesa",
        "Airtel Money",
        "Mixx by Yas",
        "Bank",
        "Card",
        "Credit",
    ];
    function paymentModal(id) {
        const o = db.orders.find((o) => o.id === Number(id));
        if (!o || !["Issued", "Partially paid"].includes(o.status)) return;
        modal(
            "Receive payment",
            html`<p class="modal-description">
                    ${invoice(o.id)} · ${esc(waiter(o.waiter)?.short)} · ${esc(o.table)}
                </p>
                <div class="payment-total">
                    <span>Outstanding invoice balance</span
                    ><strong>${money(o.total - o.paid)}</strong>
                </div>
                <input type="hidden" name="id" value="${o.id}" />
                <div id="payment-lines">${paymentLine(o.total - o.paid)}</div>
                ${btn("Add payment method", "add-payment-line", "small", "plus")}
                <div class="detail-row" style="margin-top:20px">
                    <span>Remaining after payment</span
                    ><strong id="payment-remaining" class="text-green">${money(0)}</strong>
                </div>
                <div class="info-box">
                    Use multiple methods for a split payment. Enter a smaller amount for a partial
                    payment. Credit is recorded and remains outstanding.
                </div>`,
            html`<button type="submit" class="button primary">
                ${icon("check")}Confirm payment
            </button>`,
            false,
            "payment",
        );
    }
    function paymentLine(amount = 0) {
        return html`<div class="payment-line">
            <select name="method[]" aria-label="Payment method">
                ${paymentMethods.map((m) => html`<option value="${esc(m)}">${tr(m)}</option>`).join("")}</select
            ><input
                type="number"
                name="amount[]"
                value="${amount}"
                min="0"
                step="1"
                aria-label="Payment amount"
                data-input="payment-amount"
            /><button
                type="button"
                class="icon-button"
                data-action="remove-payment-line"
                aria-label="Remove payment method"
            >
                ${icon("close")}
            </button>
        </div>`;
    }
    const submitButton = (label) =>
        html`<button class="button primary" type="submit">${icon("check")}${tr(label)}</button>`;
    function stockForm(type) {
        const locations = db.locations.map((l) => [l.id, l.name]);
        const selectedProduct = db.products[0];
        const common =
            select("Product", "product", productOptions()) +
            input("Quantity", "quantity", "number", 1) +
            select("Unit", "unit", selectedProduct?.units.map((u) => u[0]) || []);
        if (type === "grn")
            modal(
                "Receive goods",
                html`<div class="form-grid">
                    ${select(
                        "Supplier",
                        "supplier",
                        db.suppliers.map((s) => [s.id, s.name]),
                    )}${input("Supplier invoice number (optional)", "reference", "text", "", false)}${select("Receiving location", "location", locations, db.location_id)}${input("Receipt date", "date", "date", db.date)}${common}${input(`Cost per selected unit (${currencyLabel()})`, "cost", "number", 0)}
                </div>`,
                submitButton("Confirm receipt"),
                false,
                "grn",
            );
        else if (type === "transfer")
            modal(
                "Transfer stock",
                html`<div class="form-grid">
                        ${select("From location", "from", locations)}${select("To location", "to", locations, db.location_id)}${common}
                    </div>
                    ${field("Transfer notes", "notes", html`<textarea name="notes" required minlength="8"></textarea>`)}
                    <div class="info-box">
                        Sending removes stock from the source. Confirm receipt separately to add it
                        to the destination.
                    </div>`,
                submitButton("Send transfer"),
                false,
                "transfer",
            );
        else
            modal(
                "Stock adjustment request",
                html`<div class="form-grid">
                        ${select("Stock location", "location", locations, db.location_id)}${common}${select("Reason", "reason", ["BREAKAGE", "COMPLIMENTARY", "STAFF_CONSUMPTION", "PROMOTION", "EXPIRED", "DAMAGED", "OTHER"])}
                    </div>
                    ${field("Notes / justification", "notes", html`<textarea name="notes" required minlength="8"></textarea>`)}
                    <div class="info-box">
                        Stock changes only when a manager approves the request.
                    </div>`,
                submitButton("Submit for approval"),
                false,
                "adjustment",
            );
    }
    function productForm(id) {
        const p = product(id);
        const productCategories = categories.slice(1);
        if (p?.category && !productCategories.includes(p.category))
            productCategories.push(p.category);
        modal(
            p ? "Edit product" : "Add product",
            html`<input name="id" type="hidden" value="${p?.id || ""}" />
                <div class="form-grid">
                    ${input("Product name", "name", "text", p?.name || "")}${input("SKU (optional)", "sku", "text", p?.sku || "", false)}${input("Barcode", "barcode", "text", p?.barcode || "", false)}${select("Category", "category", productCategories, p?.category)}${select(
                        "Base unit",
                        "baseUnit",
                        db.unitRecords.map((u) => u.name),
                        p?.baseUnit || "Bottle",
                    )}${input(`Purchase cost (${currencyLabel()})`, "cost", "number", p?.cost || 0)}${input(`Single unit selling price (${currencyLabel()})`, "price", "number", p?.price || 0)}${html`<div class="info-box">Stock is managed through goods receipts, transfers and approved adjustments.</div>`}${input("Minimum stock", "min", "number", p?.min || 0)}${select("Status", "active", ["Active", "Inactive"], p?.active === false ? "Inactive" : "Active")}${field(
                        "Upload Product Image",
                        "image",
                        html`<input
                                type="file"
                                name="image"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                data-input="product-image"
                            /><small>${tr("Optional · JPG, JPEG, PNG, WEBP · up to 2 MB")}</small>
                            <div class="product-image-preview">
                                ${p?.image ? html`<img src="${esc(p.image)}" alt="${esc(p.name)}" />` : icon("box")}
                            </div>`,
                    )}
                </div>
                <h3 style="font-size:12px;margin:5px 0 15px">Optional selling units</h3>
                <div class="form-grid">
                    ${input("Bucket price · 6 base units", "bucket", "number", p?.units.find((u) => u[0] === "Bucket")?.[2] || 0, false)}${input("Crate price · 24 base units", "crate", "number", p?.units.find((u) => u[0] === "Crate")?.[2] || 0, false)}
                </div>`,
            submitButton("Save product"),
            false,
            "product",
        );
    }
    let reportName = "Daily Sales";
    function reportRows() {
        return activeReport;
    }

    function reportFilters() {
        const form = document.getElementById("report-filters");
        return form ? Object.fromEntries(new FormData(form)) : {};
    }
    function reportContents() {
        const data = activeReport;
        return (
            (data.values
                ? data.values
                      .slice(0, 8)
                      .map(
                          ([name, value]) =>
                              html`<div class="branch-bar-row">
                                  <div>
                                      <span>${esc(name)}</span><strong>${money(value)}</strong>
                                  </div>
                                  <div class="branch-bar">
                                      <span
                                          style="width:${(Number(value) / Math.max(1, ...data.values.map((v) => Number(v[1])))) * 100}%"
                                      ></span>
                                  </div>
                              </div>`,
                      )
                      .join("")
                : "") +
            simpleTable(
                data.headers.map(tr),
                data.rows.map((row) => row.map(esc)),
            ) +
            html`<div class="table-footer">
                <span>Page ${data.page || 1} · ${data.rows.length} rows</span>
                <div>
                    ${data.page > 1 ? btn("Previous", "report-page", "small", "", `data-value="${data.page - 1}"`) : ""}${data.has_more ? btn("Next", "report-page", "small", "", `data-value="${data.page + 1}"`) : ""}
                </div>
            </div>`
        );
    }

    function openReport(name) {
        reportName = name;
        modal(
            esc(name),
            html`<form id="report-filters">
                    <div class="form-grid">
                        ${input("Date from", "from", "date", db.date)}${input("Date to", "to", "date", db.date)}${select("Branch", "branch", ["All branches", ...db.branches.map((b) => b.name)], db.branch)}${select("Counter", "counter", [["", "All counters"], ...db.locations.filter((l) => l.type === "COUNTER").map((l) => l.name)])}${select("Waiter", "waiter", [["", "All waiters"], ...db.waiters.map((w) => [w.id, w.short])])}${select(
                            "Cashier",
                            "cashier",
                            [
                                ["", "All cashiers"],
                                ...db.team
                                    .filter((u) => u.role === "Cashier")
                                    .map((u) => [u.name, u.name]),
                            ],
                        )}${select("Category", "category", [["", "All categories"], ...categories.slice(1)])}${select("Product", "product", [["", "All products"], ...productOptions()])}
                    </div>
                </form>
                <div id="report-content"></div>`,
            `${btn("Print / PDF", "print", "", "print")}${btn("Excel export", "export-report", "primary", "download")}`,
            true,
        );
        loadReport().catch((error) => toast(error.message));
    }
    function exportCsv(name, headers, rows) {
        const quote = (v) =>
            `"${String(v ?? "")
                .replace(/<[^>]*>/g, "")
                .replace(/"/g, '""')}"`;
        const text =
            "\uFEFF" + [headers.map(tr), ...rows].map((r) => r.map(quote).join(",")).join("\r\n");
        const url = URL.createObjectURL(new Blob([text], { type: "text/csv;charset=utf-8;" }));
        const a = document.createElement("a");
        a.href = url;
        a.download = `Nightshift-${name.replace(/[^a-z0-9]/gi, "-")}-${db.date}.csv`;
        a.click();
        URL.revokeObjectURL(url);
        toast("Report exported. Open the CSV file in Excel.");
    }
    function issueOrder(id) {
        return perform("order-issue", { id: Number(id) });
    }

    function closeWaiter(id) {
        const w = waiter(id);
        if (!w || !w.active) return;
        if (outstanding(w) > 0) {
            const unpaid = db.orders.filter(
                (o) =>
                    o.waiter === w.id &&
                    ["Issued", "Partially paid", "Void requested"].includes(o.status),
            ).length;
            modal(
                "Unable to close shift",
                html`<div class="info-box red">
                        <strong>${esc(w.short)} has ${money(outstanding(w))} outstanding.</strong
                        ><br />${unpaid} invoices remain unpaid.
                    </div>
                    <p class="modal-description">
                        Settle the outstanding balance before closing this shift.
                    </p>
                    ${managerial() ? `<input type="hidden" name="id" value="${w.id}">${field("Manager override reason (required)", "reason", html`<textarea name="reason" required minlength="8" placeholder="Explain why you are closing this shift with an outstanding balance…"></textarea>`)}` : ""}`,
                managerial() ? submitButton("Record override & close") : "",
                false,
                managerial() ? "override-shift" : "",
            );
        } else
            confirm(
                "Close waiter shift",
                tr(":waiter’s account is fully settled. Close their current shift?", {
                    waiter: esc(w.short),
                }),
                "confirm-close-waiter",
                w.id,
                "Close shift",
            );
    }
    function staffCredentials() {
        return (
            pinField() +
            html`<p class="form-help">Use this PIN for login, Quick Lock and POS confirmation.</p>`
        );
    }
    function newBasicForm(type) {
        const branches = db.branches.map((b) => [b.id, b.name]);
        const configs = {
            "add-waiter": [
                "Add waiter",
                input("Full name", "name") +
                    input("Username", "username") +
                    input("Email", "email", "email") +
                    input("Phone", "phone", "tel", "", false) +
                    select("Status", "active", ["Active", "Inactive"]) +
                    html`<div data-staff-credentials>${staffCredentials("Waiter")}</div>` +
                    select("Branch", "branch", branches),
                "Add waiter",
            ],
            "add-user": [
                "Add team member",
                input("Full name", "name") +
                    input("Username", "username") +
                    input("Email", "email", "email") +
                    input("Phone", "phone", "tel", "", false) +
                    select("Status", "active", ["Active", "Inactive"]) +
                    select("Role", "role", roles, "Cashier") +
                    html`<div data-staff-credentials>${staffCredentials("Cashier")}</div>` +
                    select("Branch", "branch", branches),
                "Add member",
            ],
            "add-branch": [
                "Add branch",
                input("Branch name", "name") +
                    input("Branch code", "code", "text", "", false) +
                    input("Address", "address", "text", "", false) +
                    select("Status", "active", ["Active", "Inactive"]),
                "Create branch",
            ],
            "add-counter": [
                "Add stock location",
                input("Location name", "name") +
                    select("Location type", "type", ["Bar counter", "Storage"]) +
                    select("Branch", "branch", branches),
                "Create location",
            ],
            "add-supplier": [
                "Add supplier",
                input("Supplier name", "name") +
                    select(
                        "Category",
                        "category",
                        db.supplierCategories.map((c) => [c, tr(c)]),
                    ) +
                    input("Phone / email", "contact"),
                "Save supplier",
            ],
            reservation: [
                "Reserve a table",
                input("Guest name", "guest") +
                    input("Phone", "phone", "tel") +
                    select(
                        "Table",
                        "table",
                        db.tables.map((t) => [t.id, t.name]),
                    ) +
                    input("Party size", "size", "number", 4) +
                    input("Reservation date", "date", "date", db.date) +
                    input("Arrival time", "arrival", "time", "21:00"),
                "Reserve table",
            ],
        };
        const c = configs[type];
        if (c)
            modal(
                c[0],
                html`<div class="form-grid">${c[1]}</div>`,
                submitButton(c[2]),
                false,
                type,
            );
    }
    root.addEventListener("submit", (e) => {
        if (e.target.id === "global-search") {
            e.preventDefault();
            globalSearch(new FormData(e.target).get("query"));
        }
    });
    function globalSearch(query) {
        const q = String(query || "")
            .trim()
            .toLowerCase();
        if (!q) return;
        const menus = navItems.filter(
            ([id, , label]) => allowed(id) && label.toLowerCase().includes(q),
        );
        const products = db.products.filter((p) => p.name.toLowerCase().includes(q));
        const waiters = db.waiters.filter((w) => w.name.toLowerCase().includes(q));
        const orders = db.orders.filter(
            (o) => invoice(o.id).toLowerCase().includes(q) || o.table.toLowerCase().includes(q),
        );
        modal(
            "Search results",
            html`<p class="modal-description">Results for “${esc(query)}”</p>
                ${menus.map(([id, ico, label]) => html`<button class="nav-item" data-nav="${id}">${icon(ico)}${tr(label)}${icon("arrow")}</button>`).join("")}${products.map((p) => html`<button class="nav-item" data-action="search-product" data-id="${p.id}">${icon("box")}${p.name}<span style="margin-left:auto">${money(p.price)}</span></button>`).join("")}${waiters.map((w) => html`<button class="nav-item" data-action="waiter-profile" data-id="${w.id}">${icon("users")}${esc(w.name)}<span style="margin-left:auto">${money(outstanding(w))}</span></button>`).join("")}${orders.map((o) => html`<button class="nav-item" data-action="order-detail" data-id="${o.id}">${icon("orders")}${invoice(o.id)} · ${esc(o.table)}<span style="margin-left:auto">${money(o.total)}</span></button>`).join("")}${menus.length + products.length + waiters.length + orders.length === 0 ? html`<div class="empty-state">No results found. Try a product, waiter, invoice or page name.</div>` : ""}`,
        );
    }
    document.addEventListener("click", async (e) => {
        const nav = e.target.closest("[data-nav]");
        if (nav) {
            const parent = nav.closest('[data-action="waiter-profile"]');
            if (parent) return waiterProfile(parent.dataset.id);
            if (!allowed(nav.dataset.nav)) return toast("You do not have access to this page.");
            closeModal();
            return navigate(nav.dataset.nav);
        }
        if (e.target.classList.contains("modal-backdrop")) return closeModal();
        const target = e.target.closest("[data-action]");
        if (!target) return;
        const { action, id, value } = target.dataset;
        try {
            if (action === "close-modal") return closeModal();
            if (action === "menu")
                return document.getElementById("sidebar").classList.toggle("open");
            if (action === "staff-filter") {
                staffRole = value;
                staffLabel = target.dataset.label;
                shell();
                return;
            }
            if (action === "tab") {
                tab = value;
                search = "";
                orderPage = 1;
                shell();
                return;
            }
            if (action === "page") {
                orderPage = Number(value);
                shell();
                return;
            }
            if (action === "chart") {
                tab = value;
                period = value === "Weekly" ? "This week" : "Today";
                return reload();
            }
            if (action === "activity-tab") {
                activityTab = value;
                return shell();
            }
            if (action === "go-inventory") return navigate("inventory", "Low stock");
            if (action === "go-pos") return navigate("pos");
            if (action === "waiter-profile") return waiterProfile(id);
            if (action === "order-detail") return orderDetail(id);
            if (action === "issue")
                return confirm(
                    "Issue drinks",
                    `Issue ${invoice(id)}? Stock and the waiter ledger will update together.`,
                    "confirm-issue",
                    id,
                    "Issue drinks",
                );
            if (action === "confirm-issue")
                return perform("order-issue", { id: Number(id) }, target);
            if (action === "payment") return paymentModal(id);
            if (action === "category") {
                category = value;
                return shell();
            }
            if (action === "add-cart") {
                const p = product(id),
                    unit = Number(
                        document.querySelector(`[data-change="unit"][data-id="${id}"]`).value,
                    ),
                    match = cart.find((it) => it.product === p.id && it.unit === unit);
                if (match) match.qty++;
                else cart.push({ product: p.id, unit, qty: 1, price: p.units[unit][2] });
                shell();
                return toast(`${p.name} added to your order.`);
            }
            if (action === "remove-cart") {
                cart.splice(Number(id), 1);
                return shell();
            }
            if (action === "cart-qty") {
                cart[id].qty += Number(value);
                if (cart[id].qty <= 0) cart.splice(Number(id), 1);
                return shell();
            }
            if (action === "cart-jump")
                return document.querySelector(".pos-cart").scrollIntoView({ behavior: "smooth" });
            if (action === "clear-cart")
                return confirm(
                    "Clear current order",
                    "Remove the unsaved items in your cart?",
                    "confirm-clear-cart",
                    0,
                    "Clear cart",
                );
            if (action === "confirm-clear-cart") {
                cart = [];
                cartDiscount = 0;
                closeModal();
                return shell();
            }
            if (action === "save-draft") {
                if (!cart.length || db.role !== "Waiter") return;
                const result = await perform(
                    "order-create",
                    { ...cartPayload(), waiter_id: db.user.id, status: "DRAFT" },
                    target,
                );
                if (result) {
                    cart = [];
                    cartDiscount = 0;
                    shell();
                }
                return;
            }
            if (action === "send-order") return servingModal();
            if (action === "send-draft") return servingModal(Number(id));
            if (action === "serving-waiter") {
                const form = target.closest("form");
                form.querySelector("[name=waiter_id]").value = id;
                form.querySelectorAll("[data-action=serving-waiter]").forEach((b) =>
                    b.setAttribute("aria-pressed", String(b === target)),
                );
                form.querySelector("[name=pin]").disabled = false;
                form.querySelector("[name=pin]").value = "";
                form.querySelector("[name=pin]").focus();
                form.querySelector("[data-pin-error]").textContent = "";
                form.querySelector("[type=submit]").disabled = false;
                return;
            }
            if (action === "waiter-payment") return waiterPaymentModal(Number(id), value);
            if (action === "staff-pin") return staffPinModal(Number(id));
            if (action === "quick-lock") {
                location.href = "/quick-lock";
                return;
            }
            if (action === "reject-order")
                return confirm(
                    "Cancel order",
                    "Cancel this order before issue?",
                    "confirm-reject-order",
                    id,
                    "Cancel order",
                );
            if (action === "confirm-reject-order")
                return perform("order-cancel", { id: Number(id) }, target);
            if (action === "add-payment-line")
                return document
                    .getElementById("payment-lines")
                    .insertAdjacentHTML("beforeend", paymentLine());
            if (action === "remove-payment-line") {
                if (document.querySelectorAll(".payment-line").length > 1)
                    target.closest(".payment-line").remove();
                return updatePaymentRemaining();
            }
            if (action === "table-detail") {
                const orders = branchOrders().filter(
                    (o) =>
                        o.table === value &&
                        !["Paid", "Voided", "Cancelled", "Returned"].includes(o.status),
                );
                return modal(
                    esc(value),
                    orders.length
                        ? ordersTable(orders, true)
                        : html`<div class="empty-state">Ready for guests.</div>`,
                    allowed("pos")
                        ? btn(
                              "New order here",
                              "table-pos",
                              "primary",
                              "plus",
                              `data-value="${esc(value)}"`,
                          )
                        : "",
                    true,
                );
            }
            if (action === "table-pos") {
                cartTable = value;
                closeModal();
                return navigate("pos");
            }
            if (action === "close-waiter") return closeWaiter(id);
            if (action === "confirm-close-waiter")
                return perform("shift-close", { id: waiter(id)?.shift_id }, target);
            if (action === "open-shift") return shiftOpenModal();
            if (action === "close-shift") return shiftCloseModal();
            if (["grn", "transfer", "adjustment"].includes(action)) return stockForm(action);
            if (action === "edit-product") return productForm(id);
            if (action === "edit-category") return categoryForm(id);
            if (action === "edit-branch") return branchForm(id);
            if (action === "setup-open")
                return navigate(value, target.dataset.step === "categories" ? "Categories" : "");
            if (action === "setup-status")
                return perform(
                    "catalog-setup",
                    { step: target.dataset.step, status: value },
                    target,
                );
            if (action === "toggle-category") {
                const c = db.categoryRecords.find((item) => item.id === Number(id));
                return perform(
                    "catalog-category",
                    { id: c.id, name: c.name, active: !c.active },
                    target,
                );
            }
            if (action === "reorder-category") {
                const ids = db.categoryRecords.map((c) => c.id),
                    from = ids.indexOf(Number(id)),
                    to = from + Number(value);
                if (to < 0 || to >= ids.length) return;
                [ids[from], ids[to]] = [ids[to], ids[from]];
                return perform("catalog-category-order", { ids }, target);
            }
            if (action === "inventory-add") {
                if (value === "Products") return productForm();
                if (value === "Units")
                    return modal(
                        "Add unit",
                        input("Unit name", "name"),
                        submitButton("Add unit"),
                        false,
                        "catalog-unit",
                    );
                return categoryForm();
            }
            if (action === "category-open") {
                category = value;
                tab = "Products";
                return shell();
            }
            if (action === "void-request")
                return modal(
                    "Request invoice void",
                    `<input type="hidden" name="id" value="${id}">${field("Reason", "reason", html`<textarea name="reason" required minlength="8"></textarea>`)}`,
                    submitButton("Submit request"),
                    false,
                    "void-request",
                );
            if (action === "return-items") {
                const o = db.orders.find((o) => o.id === Number(id));
                return modal(
                    "Return items",
                    `<input type="hidden" name="id" value="${id}">${o.items.map((it, i) => input(`${it.name} · ${quantity(Number(it.qty) - Number(it.returned_qty))} ${it.unitName} returnable`, `qty-${i}`, "number", 0, false)).join("")}${field("Return reason", "reason", html`<textarea name="reason" required minlength="8"></textarea>`)}`,
                    submitButton("Confirm return"),
                    false,
                    "return-items",
                );
            }
            if (action === "approve" || action === "confirm-approve") return approveRequest(id);
            if (action === "reject-approval")
                return modal(
                    "Reject request",
                    `<input type="hidden" name="id" value="${esc(id)}">${field("Reason", "reason", html`<textarea name="reason" required minlength="8"></textarea>`)}`,
                    submitButton("Reject request"),
                    false,
                    "reject-approval",
                );
            if (action === "report") return openReport(value);
            if (action === "report-page") return loadReport(Number(value));
            if (action === "export-report") return downloadReport(reportName, activeReportFilters);
            if (action === "export-dashboard") {
                return downloadReport("Daily Sales", queryParams());
            }
            if (action === "export-inventory") {
                return downloadReport("Closing Stock", queryParams());
            }
            if (action === "export-payments") {
                return downloadReport("Payment Method Report", queryParams());
            }
            if (action === "export-audit")
                return exportCsv(
                    "Audit",
                    ["User", "Action", "Reference", "Previous", "New", "Time"],
                    db.audit.map((a) => [a.user, a.action, a.ref, a.previous, a.next, a.time]),
                );
            if (action === "print") return window.print();
            if (action === "receipt")
                return window.open(`/api/nightshift/receipts/${id}`, "_blank", "noopener");
            if (action === "read-notifications") return perform("notifications-read", {}, target);
            if (action === "profile")
                return modal(
                    "Your account",
                    html`<div class="profile-summary">
                            ${avatar(db.user.name)}
                            <div>
                                <h3>${esc(db.user.name)}</h3>
                                <p>${esc(tr(db.role))} · ${esc(db.company)}</p>
                            </div>
                        </div>
                        <a class="button" href="/settings/profile">Profile & security</a>
                        <form method="POST" action="/logout" style="margin-top:15px">
                            <input
                                type="hidden"
                                name="_token"
                                value="${document.querySelector('[name="csrf-token"]').content}"
                            /><button class="button danger" type="submit">Log out</button>
                        </form>`,
                );
            if (action === "save-settings")
                return perform(
                    "catalog-company",
                    {
                        name: document.querySelector('[name="company"]').value,
                        email: document.querySelector('[name="email"]').value || null,
                        phone: document.querySelector('[name="phone"]').value || null,
                    },
                    target,
                );
            if (action === "select-branch") {
                await selectBranch(value);
                return navigate("dashboard");
            }
            if (action === "search-product") {
                closeModal();
                category = product(id).category;
                search = product(id).name;
                screen = allowed("pos") ? "pos" : "inventory";
                return shell();
            }
            if (action === "receive-transfer")
                return perform("transfer-receive", { id: Number(id) }, target);
            if (action === "help")
                return modal(
                    "Welcome to Nightshift",
                    html`<p class="modal-description">
                        Create an order, issue drinks, collect payment, then reconcile your shift.
                        Every stock and money movement is recorded in your company’s transaction
                        history.
                    </p>`,
                );
            newBasicForm(action);
        } catch (error) {
            toast(error.message);
        }
    });
    function updatePaymentRemaining() {
        const form = document.querySelector('[data-form="payment"]');
        if (!form) return;
        const o = db.orders.find((o) => o.id === Number(form.querySelector('[name="id"]').value));
        const sum = [...form.querySelectorAll(".payment-line")].reduce(
            (n, line) =>
                n +
                (line.querySelector("select").value === "Credit"
                    ? 0
                    : Number(line.querySelector("input").value || 0)),
            0,
        );
        const remaining = o.total - o.paid - sum;
        const el = document.getElementById("payment-remaining");
        el.textContent = money(remaining);
        el.className = remaining === 0 ? "text-green" : remaining < 0 ? "text-red" : "text-amber";
    }
    document.addEventListener("input", (e) => {
        if (e.target.matches('[data-input="search"]')) {
            search = e.target.value;
            orderPage = 1;
            const selection = e.target.selectionStart;
            shell();
            const input = document.querySelector('[data-input="search"]');
            input?.focus();
            input?.setSelectionRange(selection, selection);
        }
        if (e.target.matches('[data-input="payment-amount"]')) updatePaymentRemaining();
        if (e.target.name === "actual") {
            const variance = Number(e.target.value) - db.shift.opening - db.shift.cashSales;
            const el = document.getElementById("cash-variance");
            if (el)
                el.innerHTML = html`<span
                        >${variance < 0 ? "Cash shortage" : variance > 0 ? "Cash excess" : "Cash variance"}</span
                    ><strong class="${variance < 0 ? "text-red" : "text-green"}"
                        >${money(Math.abs(variance))}${variance === 0 ? " · Balanced" : ""}</strong
                    >`;
        }
    });
    document.addEventListener("change", (e) => {
        if (e.target.dataset.change === "staff-status") {
            staffStatus = e.target.value;
            shell();
            return;
        }
        if (e.target.name === "role" && e.target.closest('[data-form="add-user"]')) {
            e.target.closest("form").querySelector("[data-staff-credentials]").innerHTML =
                staffCredentials(e.target.value);
        }
        if (e.target.name === "product" && e.target.closest("[data-form]")) {
            const unit = e.target.closest("form").querySelector('[name="unit"]');
            if (unit) {
                unit.innerHTML = product(e.target.value)
                    .units.map((u) => html`<option>${esc(u[0])}</option>`)
                    .join("");
            }
        }
        if (e.target.dataset.change === "stock-location") {
            db.location_id = Number(e.target.value);
            cartCounter = db.locations.find((l) => l.id === db.location_id)?.name || "";
            reload().catch((error) => toast(error.message));
            return;
        }

        if (e.target.matches('[name="method[]"]')) updatePaymentRemaining();
        if (e.target.closest("#report-filters")) {
            loadReport().catch((error) => toast(error.message));
            return;
        }
        const action = e.target.dataset.change;
        const value = e.target.value;
        if (action === "branch") {
            selectBranch(value).catch((error) => toast(error.message));
        }
        if (action === "stock-date") {
            stockDate = value;
            shell();
        }
        if (action === "period") {
            period = value;
            reload().catch((error) => toast(error.message));
        }
        if (action === "unit")
            document.getElementById("price-" + e.target.dataset.id).textContent = money(
                product(e.target.dataset.id).units[Number(value)][2],
            );
        if (action === "cart-table") cartTable = value;
        if (action === "cart-counter") {
            cartCounter = value;
            db.location_id = db.locations.find((l) => l.name === value)?.id;
            reload().catch((error) => toast(error.message));
        }
        if (action === "cart-discount") {
            cartDiscount = Math.max(0, Number(value) || 0);
            shell();
        }
        if (action === "category-filter") {
            category = value === "All categories" ? "All products" : value;
            shell();
        }
    });
    function approveRequest(id) {
        const a = db.approvals.find((a) => a.id === id);
        if (!a) return;
        modal(
            "Approve request",
            `<input type="hidden" name="id" value="${esc(id)}">${field("Approval reason / goods recovery confirmation", "reason", html`<textarea name="reason" required minlength="8" placeholder="Record your decision and confirm recovered goods for a void…"></textarea>`)}`,
            submitButton("Approve request"),
            false,
            "approve-request",
        );
    }

    document.addEventListener("submit", async (e) => {
        const form = e.target.closest("[data-form]");
        if (!form) return;
        e.preventDefault();
        if (pendingRequests) return toast("Please wait for the current save to finish.");
        try {
            const data = Object.fromEntries(new FormData(form)),
                type = form.dataset.form;
            let command, payload;
            if (type === "serving-order") {
                if (pendingRequests) return;
                payload = data.id ? { id: Number(data.id) } : cartPayload();
                payload.waiter_id = Number(data.waiter_id);
                payload.pin = data.pin;
                delete data.pin;
                form.querySelector("[name=pin]").value = "";
                try {
                    const result = await perform("order-send", payload, form);
                    if (result) toast("Sent to cashier. Drinks issued; waiter account updated.");
                } catch (error) {
                    form.querySelector("[data-pin-error]").textContent = error.message;
                    form.querySelector("[name=pin]").focus();
                    throw error;
                } finally {
                    delete payload.pin;
                }
                return;
            } else if (type === "staff-pin") {
                payload = { id: Number(data.id), pin: data.pin || null };
                delete data.pin;
                form.querySelector("[name=pin]").value = "";
                try {
                    await perform("staff-pin", payload, form);
                } finally {
                    delete payload.pin;
                }
                return;
            } else if (type === "waiter-payment") {
                command = "waiter-payment";
                payload = {
                    waiter_id: Number(data.waiter_id),
                    stock_location_id: Number(data.counter),
                    amount: data.amount,
                    method: data.method,
                };
            } else if (type === "payment") {
                command = "payment";
                payload = {
                    id: Number(data.id),
                    lines: [...form.querySelectorAll(".payment-line")]
                        .map((line) => ({
                            method: line.querySelector("select").value,
                            amount: line.querySelector("input").value,
                        }))
                        .filter((l) => Number(l.amount) > 0),
                };
            } else if (type === "product") {
                command = "product";
                payload = productPayload(data);
                const image = form.querySelector("[name=image]").files[0];
                if (image) payload.image = image;
            } else if (["grn", "transfer", "adjustment"].includes(type)) {
                const p = product(data.product),
                    unit = p.units.find((u) => u[0] === data.unit) || p.units[0],
                    line = { product_unit_id: unit[3], quantity: data.quantity };
                if (type === "grn") {
                    command = "grn";
                    payload = {
                        stock_location_id: Number(data.location),
                        supplier_id: Number(data.supplier),
                        supplier_invoice: data.reference,
                        received_on: data.date,
                        items: [{ ...line, unit_cost: data.cost }],
                    };
                } else if (type === "transfer") {
                    const created = await send(
                        "transfer-create",
                        {
                            source_location_id: Number(data.from),
                            destination_location_id: Number(data.to),
                            notes: data.notes,
                            items: [line],
                        },
                        form,
                    );
                    command = "transfer-send";
                    payload = { id: created.id };
                } else {
                    command = "adjustment-request";
                    payload = {
                        stock_location_id: Number(data.location),
                        reason: data.reason,
                        notes: data.notes,
                        items: [line],
                    };
                }
            } else if (type === "void-request") {
                command = "void-request";
                payload = { id: Number(data.id), reason: data.reason };
            } else if (type === "return-items") {
                command = "return";
                const o = db.orders.find((o) => o.id === Number(data.id));
                payload = {
                    id: o.id,
                    reason: data.reason,
                    items: o.items
                        .map((it, i) => ({
                            order_item_id: it.id,
                            quantity: data["qty-" + i] || "0",
                        }))
                        .filter((it) => Number(it.quantity) > 0),
                };
            } else if (type === "approve-request" || type === "reject-approval") {
                const a = db.approvals.find((a) => a.id === data.id);
                command = a.type === "Void request" ? "void-decide" : "adjustment-decide";
                payload = {
                    id: a.record_id,
                    approved: type === "approve-request",
                    reason: data.reason,
                };
            } else if (type === "close-shift") {
                command = "shift-close";
                payload = { id: db.shift.id, actual_cash: data.actual, notes: data.notes };
            } else if (type === "open-shift") {
                command = "shift-open";
                payload = {
                    user_id: Number(data.user),
                    stock_location_id: Number(data.counter),
                    type: data.type,
                    opening_cash: data.opening,
                };
            } else if (type === "override-shift") {
                command = "shift-close";
                payload = { id: waiter(data.id).shift_id, override_reason: data.reason };
            } else if (type === "category") {
                command = "catalog-category";
                payload = {
                    ...(data.id ? { id: Number(data.id) } : {}),
                    name: data.name,
                    active: data.active === "Active",
                    sort_order: Number(data.sort_order),
                    icon: data.icon,
                    color: data.color,
                };
            } else if (type === "catalog-unit") {
                command = "catalog-unit";
                payload = { name: data.name };
            } else if (type === "add-branch") {
                command = "catalog-branch";
                payload = {
                    ...(data.id ? { id: Number(data.id) } : {}),
                    name: data.name,
                    address: data.address,
                    code: data.code || null,
                    active: data.active === "Active",
                };
            } else if (type === "add-counter") {
                command = "catalog-location";
                payload = {
                    name: data.name,
                    type: data.type === "Storage" ? "STORE" : "COUNTER",
                    branch_id: Number(data.branch),
                };
            } else if (type === "add-user" || type === "add-waiter") {
                command = "catalog-user";
                payload = {
                    name: data.name,
                    username: data.username,
                    email: data.email,
                    pin: data.pin,
                    role: type === "add-waiter" ? "Waiter" : data.role,
                    phone: data.phone,
                    active: data.active === "Active",
                    branch_id: Number(data.branch),
                };
            } else if (type === "add-supplier") {
                command = "catalog-supplier";
                payload = { name: data.name, category: data.category, contact: data.contact };
            } else if (type === "reservation") {
                command = "catalog-reservation";
                payload = {
                    service_table_id: Number(data.table),
                    guest: data.guest,
                    phone: data.phone,
                    size: Number(data.size),
                    date: data.date,
                    arrival: data.arrival,
                };
            }
            if (command) {
                if (command === "catalog-user") {
                    delete data.pin;
                    form.querySelector('[name="pin"]').value = "";
                    try {
                        await perform(command, payload, form);
                    } finally {
                        delete payload.pin;
                    }
                } else await perform(command, payload, form);
            }
        } catch (error) {
            toast(error.message);
        }
    });
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            closeModal();
            document.getElementById("sidebar")?.classList.remove("open");
        }
        if ((e.metaKey || e.ctrlKey) && e.key === "k") {
            e.preventDefault();
            document.querySelector(".global-search input")?.focus();
        }
        if (e.key === "Enter" && e.target.matches('[role="button"][data-action]')) e.target.click();
        if (e.key === "Tab" && document.querySelector(".modal")) {
            const elements = [
                ...document.querySelectorAll(
                    '.modal button:not(:disabled),.modal input:not([type="hidden"]):not(:disabled),.modal select:not(:disabled),.modal textarea:not(:disabled)',
                ),
            ];
            const first = elements[0];
            const last = elements[elements.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last?.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first?.focus();
            }
        }
    });
    window.addEventListener("hashchange", () => {
        const next = location.hash.slice(1);
        if (navItems.some((n) => n[0] === next) || ["cashiers", "users"].includes(next)) {
            if (["cashiers", "users"].includes(next)) {
                staffRole = next === "cashiers" ? "Cashier" : "";
                staffLabel = staffRole ? "Cashiers" : "All staff";
            }
            screen = ["cashiers", "users"].includes(next) ? "staff" : next;
            tab = "";
            search = "";
            shell();
        }
    });
    let activeReportFilters = new URLSearchParams(),
        historicalStock = new Map(),
        stockDate = "",
        activeReport = { headers: [], rows: [] },
        pendingRequests = 0;
    function queryParams(extra = {}) {
        const values = { period, ...extra };
        if (db?.branch_id) values.branch_id = db.branch_id;
        else if (db) values.all_branches = 1;
        if (db?.location_id) values.stock_location_id = db.location_id;
        return new URLSearchParams(
            Object.entries(values).filter(([k, v]) => v !== null && v !== undefined && v !== ""),
        );
    }
    async function api(path, options = {}) {
        const response = await fetch("/api/nightshift/" + path, {
            credentials: "same-origin",
            ...options,
            headers: {
                Accept: "application/json",
                ...(options.body instanceof FormData ? {} : { "Content-Type": "application/json" }),
                "X-CSRF-TOKEN": document.querySelector('[name="csrf-token"]').content,
                ...options.headers,
            },
        });
        if (response.status === 423) {
            location.href = "/quick-lock";
            throw new Error("Your workspace is locked.");
        }
        if (response.status === 401 || response.status === 419) {
            location.href = "/login";
            throw new Error("Your session expired. Please log in again.");
        }
        const data = await response.json();
        if (response.status === 409 && data.shift_required) {
            location.href = "/open-shift";
            throw new Error(data.message);
        }
        if (!response.ok)
            throw new Error(
                tr(
                    Object.values(data.errors || {}).flat()[0] ||
                        data.message ||
                        "The request could not be completed.",
                ),
            );
        return data;
    }
    function normalize(data) {
        for (const p of data.products) {
            for (const key of ["price", "cost", "stock", "min"]) p[key] = Number(p[key]);
            p.units = p.units.map((u) => [u[0], Number(u[1]), Number(u[2]), u[3]]);
        }
        for (const w of data.waiters)
            for (const key of ["issued", "paid", "returns"]) if (key in w) w[key] = Number(w[key]);
        for (const o of data.orders) {
            for (const key of ["total", "subtotal", "discount", "paid", "balance"])
                o[key] = Number(o[key]);
            for (const it of o.items)
                for (const key of ["qty", "returned_qty", "price"]) it[key] = Number(it[key]);
        }
        for (const key of ["opening", "cashSales", "mobile", "card"])
            if (key in data.shift) data.shift[key] = Number(data.shift[key]);
        if (data.shift.actual !== undefined && data.shift.actual !== null)
            data.shift.actual = Number(data.shift.actual);
        for (const a of data.approvals) a.amount = Number(a.amount || 0);
        for (const p of data.payments) p.amount = Number(p.amount);
        categories.splice(0, categories.length, "All products", ...data.categories);
        return data;
    }
    let lastInteraction = Date.now();
    const activityChannel = new BroadcastChannel("nightshift-staff-activity");
    activityChannel.onmessage = (e) => {
        if (e.data?.user === db?.user.id)
            lastInteraction = Math.max(lastInteraction, Number(e.data.at) || 0);
    };
    for (const event of ["pointerdown", "keydown", "touchstart"])
        document.addEventListener(
            event,
            () => {
                lastInteraction = Date.now();
                if (db?.quickLock?.enabled)
                    activityChannel.postMessage({ user: db.user.id, at: lastInteraction });
            },
            { passive: true },
        );
    setInterval(async () => {
        if (!db?.quickLock?.enabled) return;
        if (Date.now() - lastInteraction >= db.quickLock.minutes * 60000) {
            location.href = "/quick-lock";
            return;
        }
        if (Date.now() - lastInteraction < 60000) {
            try {
                const response = await fetch("/api/nightshift/activity", {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": document.querySelector("[name=csrf-token]").content,
                    },
                });
                if (response.status === 423) location.href = "/quick-lock";
                else if ([401, 419].includes(response.status)) location.href = "/login";
            } catch {
                /* Normal requests will surface connection failures. */
            }
        }
    }, 30000);
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) boot();
    });
    async function boot() {
        try {
            db = normalize(await api("bootstrap"));
            cartCounter = db.locations.find((l) => l.id === db.location_id)?.name || "";
            cartTable = db.tables[0]?.name || "Takeaway";
            shell();
        } catch (error) {
            root.innerHTML = html`<div class="startup-loader">
                <div class="loading-brand">NIGHTSHIFT</div>
                <p>${esc(error.message)}</p>
                <a class="button" href="/login">Log in</a>
            </div>`;
        }
    }
    async function reload() {
        historicalStock.clear();
        db = normalize(await api("bootstrap?" + queryParams()));
        shell();
    }
    async function selectBranch(name) {
        const branch = db.branches.find((b) => b.name === name);
        const params = new URLSearchParams({
            period,
            ...(branch ? { branch_id: branch.id } : { all_branches: 1 }),
        });
        db = normalize(await api("bootstrap?" + params));
        cartCounter = db.locations.find((l) => l.id === db.location_id)?.name || "";
        shell();
    }
    async function send(command, payload, element = { dataset: {} }) {
        const { pin, ...safePayload } = payload;
        const fingerprint = JSON.stringify({
            command,
            payload: {
                ...safePayload,
                ...(payload.image
                    ? {
                          image: [
                              payload.image.name,
                              payload.image.size,
                              payload.image.lastModified,
                          ],
                      }
                    : {}),
            },
        });
        const requestKeys =
            command === "order-send" ? servingRequestKeys : (element.requestKeys ||= new Map());
        let key = requestKeys.get(fingerprint);
        if (!key) {
            key = crypto.randomUUID();
            requestKeys.set(fingerprint, key);
        }
        let body = JSON.stringify({ ...payload, idempotency_key: key });
        if (payload.image instanceof File) {
            const { image, ...fields } = payload;
            body = new FormData();
            body.append("payload", JSON.stringify({ ...fields, idempotency_key: key }));
            body.append("image", image);
        }
        return api("commands/" + command, { method: "POST", body });
    }
    async function perform(command, payload, element = { dataset: {} }) {
        if (pendingRequests) return;
        pendingRequests++;
        const buttons = element.matches?.("button")
            ? [element]
            : [...(element.querySelectorAll?.("button") || [])];
        const originallyDisabled = new Map(buttons.map((b) => [b, b.disabled]));
        buttons.forEach((b) => {
            b.disabled = true;
            b.dataset.originalText = b.innerHTML;
            b.innerHTML = icon("refresh") + " " + tr("Saving…");
        });
        try {
            const result = await send(command, payload, element);
            if (command === "order-send") {
                cart = [];
                cartDiscount = 0;
                servingRequestKeys.clear();
                shell();
                const frame = document.createElement("iframe");
                frame.title = "Committed waiter issue ticket";
                frame.style.cssText = "position:fixed;width:1px;height:1px;left:-9999px;border:0";
                frame.src = result.ticket_url + "?print=1";
                document.querySelector("#issue-ticket-frame")?.remove();
                frame.id = "issue-ticket-frame";
                document.body.append(frame);
            }
            if (command === "catalog-user") {
                staffRole = payload.role;
                staffLabel =
                    { Waiter: "Waiters", Cashier: "Cashiers", Supervisor: "Supervisors" }[
                        payload.role
                    ] || payload.role;
                staffStatus = "";
                screen = "staff";
                tab = "";
                search = "";
                history.replaceState(null, "", "#staff");
                // Follow the assigned branch so the newly saved employee is visible.
                if (db.branch_id && db.branch_id !== payload.branch_id) {
                    db.branch_id = payload.branch_id;
                    db.location_id = null;
                }
            }
            if (
                command === "shift-close" &&
                ["Waiter", "Cashier"].includes(db.role) &&
                db.shifts.some((s) => s.id === payload.id && s.user_id === db.user.id)
            ) {
                location.href = "/open-shift";
                return result;
            }
            if (command === "shift-open" && payload.user_id === db.user.id) {
                const assigned = [...(db.shiftLocations || []), ...db.locations].find(
                    (l) => l.id === payload.stock_location_id,
                );
                if (assigned) {
                    db.branch_id = assigned.branch_id;
                    db.location_id = assigned.id;
                }
                if (["Waiter", "Cashier"].includes(db.role)) {
                    screen = db.role === "Waiter" ? "pos" : "waiters";
                    tab = "";
                    history.replaceState(null, "", "#" + screen);
                }
            }
            try {
                await reload();
            } catch {
                closeModal();
                toast("Saved successfully. Refresh this page to update the workspace.");
                return result;
            }
            closeModal();
            toast(
                command === "catalog-user"
                    ? tr(":role added successfully.", { role: tr(payload.role) })
                    : "Saved. Stock, cash and ledger records are up to date.",
            );
            return result;
        } finally {
            pendingRequests--;
            buttons.forEach((b) => {
                b.disabled = originallyDisabled.get(b);
                b.innerHTML = b.dataset.originalText;
            });
        }
    }
    function paymentPercent(method) {
        const methods = db.metrics?.payment_methods || {},
            total = Object.values(methods).reduce((s, n) => s + Number(n), 0);
        return total > 0 ? Math.round((Number(methods[method] || 0) / total) * 100) : 0;
    }
    function paymentGradient() {
        const entries = Object.entries(db.metrics?.payment_methods || {});
        let sum = 0;
        return entries.length
            ? "conic-gradient(" +
                  entries
                      .map(([m, v], i) => {
                          const from = sum;
                          sum += paymentPercent(m);
                          return (
                              ["var(--amber)", "#718cb8", "var(--chart-green)", "#756a91"][i % 4] +
                              ` ${from}% ${sum}%`
                          );
                      })
                      .join(",") +
                  ")"
            : "var(--panel2)";
    }
    function downloadReport(name, params) {
        const link = document.createElement("a");
        link.href = "/api/nightshift/reports/" + encodeURIComponent(name) + "/export?" + params;
        link.click();
    }
    function historicalStockView(name) {
        const date = stockDate || db.date;
        const filters = queryParams({ from: date, to: date });
        const key = name + "?" + filters;
        if (!historicalStock.has(key)) {
            historicalStock.set(key, null);
            getReport(name, { from: date, to: date })
                .then((result) => {
                    historicalStock.set(key, result);
                    if (screen === "inventory") shell();
                })
                .catch((error) => {
                    historicalStock.set(key, { headers: [], rows: [], error: error.message });
                    if (screen === "inventory") shell();
                });
        }
        const result = historicalStock.get(key);
        return html`<div class="toolbar">
                <label
                    >Business date
                    <input
                        class="search-field"
                        type="date"
                        data-change="stock-date"
                        value="${esc(date)}" /></label
                >${btn("Full report", "report", "", "chart", `data-value="${name}"`)}
            </div>
            <section class="panel">
                ${
                    !result
                        ? html`<div class="empty-state">Loading stock history…</div>`
                        : result.error
                          ? html`<div class="empty-state">${esc(result.error)}</div>`
                          : simpleTable(
                                result.headers,
                                result.rows.map((row) => row.map(esc)),
                            )
                }
            </section>`;
    }
    async function getReport(name, filters = {}) {
        return api("reports/" + encodeURIComponent(name) + "?" + queryParams(filters));
    }
    async function loadReport(page = 1) {
        const fields = reportFilters();
        const branch = db.branches.find((b) => b.name === fields.branch),
            counter = db.locations.find((l) => l.name === fields.counter),
            categoryRecord = db.categoryRecords.find((c) => c.name === fields.category);
        const filters = {
            page,
            from: fields.from,
            to: fields.to,
            branch_id: branch?.id || "",
            waiter_id: fields.waiter,
            stock_location_id: counter?.id || "",
            category_id: categoryRecord?.id || "",
            product_id: fields.product,
            issued_by: db.team.find((u) => u.name === fields.cashier)?.id || "",
        };
        const params = new URLSearchParams(Object.entries(filters).filter(([k, v]) => v));
        activeReportFilters = params;
        activeReport = await api("reports/" + encodeURIComponent(reportName) + "?" + params);
        const content = document.getElementById("report-content");
        if (content) content.innerHTML = reportContents();
    }
    function shiftOpenModal() {
        const users = permitted("manage-users") ? db.team : [db.user];
        modal(
            "Open shift",
            html`<div class="form-grid">
                ${select(
                    "Employee",
                    "user",
                    users.map((u) => [u.id, u.name]),
                    db.user.id,
                )}${select(
                    "Counter",
                    "counter",
                    (db.shiftLocations?.length ? db.shiftLocations : db.locations)
                        .filter((l) => l.type === "COUNTER")
                        .map((l) => [l.id, l.name]),
                    db.location_id,
                )}${select("Shift type", "type", ["CASHIER", "WAITER"], permitted("issue-drinks") ? "CASHIER" : "WAITER")}${input(`Opening cash (${currencyLabel()})`, "opening", "number", 0)}
            </div>`,
            submitButton("Open shift"),
            false,
            "open-shift",
        );
    }
    function shiftCloseModal() {
        if (!db.shift.active) return toast("You do not have an open cashier shift.");
        const expected = db.shift.opening + db.shift.cashSales;
        modal(
            "Reconcile cashier shift",
            html`<h3>Staff with unpaid balances</h3>
                ${simpleTable(
                    ["Waiter", "Outstanding"],
                    branchWaiters()
                        .filter((w) => outstanding(w) > 0)
                        .map((w) => [esc(w.name), money(outstanding(w))]),
                )}
                <div class="payment-total">
                    <span>Expected cash in drawer</span><strong>${money(expected)}</strong>
                </div>
                ${input(`Actual cash counted (${currencyLabel()})`, "actual", "number", expected)}
                <div id="cash-variance"></div>
                ${field("Closing notes", "notes", html`<textarea name="notes" placeholder="Explain any shortage or excess…"></textarea>`)}`,
            submitButton("Close shift"),
            false,
            "close-shift",
        );
    }
    function productPayload(data) {
        const categoryRecord = db.categoryRecords.find((c) => c.name === data.category),
            base = db.unitRecords.find((u) => u.name === data.baseUnit),
            existing = product(data.id);
        const unitName =
            existing?.units[0]?.[0] || (data.baseUnit === "Bottle" ? "Piece" : data.baseUnit);
        const unit = db.unitRecords.find((u) => u.name === unitName) || base;
        const units = [{ unit_id: unit?.id, conversion_quantity: "1", selling_price: data.price }];
        for (const [name, key, qty] of [
            ["Bucket", "bucket", "6"],
            ["Crate", "crate", "24"],
        ])
            if (Number(data[key]) > 0) {
                const u = db.unitRecords.find((u) => u.name === name);
                units.push({ unit_id: u?.id, conversion_quantity: qty, selling_price: data[key] });
            }
        return {
            id: data.id ? Number(data.id) : null,
            name: data.name,
            sku: data.sku,
            barcode: data.barcode,
            category_id: categoryRecord?.id,
            base_unit_id: base?.id,
            purchase_cost: data.cost,
            minimum_stock: data.min,
            active: data.active === "Active",
            units,
        };
    }
    setInterval(() => {
        if (
            db &&
            !pendingRequests &&
            !document.querySelector(".modal") &&
            !document.querySelector("input:focus,select:focus,textarea:focus")
        )
            reload().catch(() => {});
    }, 30000);

    boot();
}
