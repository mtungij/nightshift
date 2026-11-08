import { t } from "./localization.js";

let deferredPrompt = null;
let prompting = false;
let installed = navigator.standalone === true || matchMedia("(display-mode: standalone)").matches;
const authenticatedWorkspace = () => !!document.getElementById("app");
const canInstall = () =>
    authenticatedWorkspace() && !installed && !prompting && deferredPrompt !== null;

function syncControls() {
    document.querySelectorAll("[data-pwa-install-area]").forEach((el) => {
        el.hidden = !canInstall();
    });
    document.querySelectorAll("[data-pwa-install]").forEach((button) => {
        button.hidden = !canInstall();
        button.disabled = prompting;
        button.textContent = t("Install App");
    });
}

async function install() {
    if (!canInstall()) return;
    const prompt = deferredPrompt;
    deferredPrompt = null;
    prompting = true;
    syncControls();
    try {
        await prompt.prompt();
        const choice = await prompt.userChoice;
        if (choice.outcome === "accepted") installed = true;
    } catch {
        window.dispatchEvent(
            new CustomEvent("nightshift:theme-error", {
                detail: t(
                    "Installation could not be started. Use your browser menu to install Nightshift.",
                ),
            }),
        );
    } finally {
        prompting = false;
        syncControls();
    }
}

window.NightshiftPwa = {
    get canInstall() {
        return canInstall();
    },
    install,
    syncControls,
};
window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    if (!installed && !prompting && typeof event.prompt === "function") deferredPrompt = event;
    syncControls();
});
window.addEventListener("appinstalled", () => {
    installed = true;
    deferredPrompt = null;
    syncControls();
});
matchMedia("(display-mode: standalone)").addEventListener?.("change", (event) => {
    if (event.matches) {
        installed = true;
        deferredPrompt = null;
    }
    syncControls();
});
window.addEventListener("nightshift:language", syncControls);
document.addEventListener("click", (event) => {
    if (event.target.closest?.("[data-pwa-install]")) install();
});

// The worker handles only explicitly allowed public assets, never business requests.
if (window.isSecureContext && "serviceWorker" in navigator) {
    const register = async () => {
        try {
            await navigator.serviceWorker.register("/sw.js", {
                scope: "/",
                updateViaCache: "none",
            });
        } catch {
            // Installation is optional; network/policy failure must not disable POS or payments.
        }
    };
    if (document.readyState === "complete") register();
    else window.addEventListener("load", register, { once: true });
}
syncControls();
