import "./nightshift.js";
import "./whatsapp.js";

// FormData captures the submitted credential before clearing the field, so
// neither a completed form nor the back/forward cache retains the entered PIN.
document.addEventListener("formdata", (event) => {
    event.target.querySelectorAll('[name="pin"]').forEach((input) => (input.value = ""));
});
window.addEventListener("pagehide", () => {
    document.querySelectorAll('[name="pin"]').forEach((input) => (input.value = ""));
});

document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-language]");
    if (!button || button.disabled) return;
    const buttons = document.querySelectorAll("[data-language]");
    buttons.forEach((item) => (item.disabled = true));
    try {
        const response = await fetch(window.NightshiftI18n.endpoint, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ locale: button.dataset.language }),
        });
        if (response.status === 423) {
            location.href = "/quick-lock";
            return;
        }
        if (!response.ok)
            throw new Error(
                window.NightshiftI18n.messages["Language could not be saved. Please try again."] ||
                    "Language could not be saved. Please try again.",
            );
        const data = await response.json();
        Object.assign(window.NightshiftI18n, data);
        document.documentElement.lang = data.locale;
        window.dispatchEvent(new CustomEvent("nightshift:language"));
    } catch (error) {
        window.dispatchEvent(new CustomEvent("nightshift:theme-error", { detail: error.message }));
    } finally {
        buttons.forEach((item) => (item.disabled = false));
    }
});
