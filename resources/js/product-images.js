import { t } from "./localization.js";

export function showProductImageFallback(image) {
    for (const key of ["presetImage", "categoryImage"]) {
        if (!image.dataset[key]) continue;
        const source = image.dataset[key];
        delete image.dataset[key];
        if (source === image.getAttribute("src")) continue;
        image.src = source;
        return;
    }
    image.hidden = true;
    image.nextElementSibling.hidden = false;
}

export function productImageSelection(product, galleries) {
    let choice = null;
    const gallery = (category) => galleries[category.trim().toLowerCase()];

    return {
        gallery,
        choose(choiceToApply) {
            choice = choiceToApply;
        },
        presentation(category, name) {
            return {
                ...product,
                ...choice,
                name: name || product?.name || t("Product image preview"),
                category_image: gallery(category)?.default,
            };
        },
        payload() {
            if (choice?.image_preset) return { image_preset: choice.image_preset };
            if (choice?.remove) return { remove_image: true };
            return {};
        },
    };
}

export function mountProductImages(form, { product, galleries, renderImage, releasePreview }) {
    const selection = productImageSelection(product, galleries);
    const { category, image: picker, remove_image: remove, name } = form.elements;
    const preview = form.querySelector(".product-image-preview");
    const suggestions = form.querySelector(".product-image-suggestions");
    const title = suggestions.querySelector("h3");
    const grid = suggestions.querySelector(".product-image-options");
    const selectedLabel = form.querySelector("[data-product-image-status]");
    const removeLabel = remove.closest("label");
    const presentation = () => selection.presentation(category.value, name.value);

    function renderPreview() {
        const current = presentation();
        preview.innerHTML = renderImage(current, "eager");
        selectedLabel.textContent = current.image ? t("Selected image") : current.category_image ? t("Category default") : "";
        removeLabel.hidden = !product?.image && !current.image && !remove.checked;
        for (const button of grid.querySelectorAll("button")) {
            button.setAttribute("aria-pressed", String(button.dataset.imagePreset === current.image_preset));
        }
    }

    function renderGallery() {
        const presets = Object.entries(selection.gallery(category.value)?.presets ?? {});
        suggestions.hidden = presets.length === 0;
        title.textContent = t("Suggested for :category", { category: t(category.value) });
        grid.replaceChildren(...presets.map(([id, url], index) => {
            const label = t(":category image option :number", { category: t(category.value), number: index + 1 });
            const button = document.createElement("button");
            button.type = "button";
            button.dataset.imagePreset = id;
            button.setAttribute("aria-label", label);
            const image = document.createElement("img");
            image.src = url;
            image.alt = label;
            image.loading = "lazy";
            image.decoding = "async";
            const check = document.createElement("span");
            check.className = "product-image-check";
            check.textContent = "✓";
            check.setAttribute("aria-hidden", "true");
            button.append(image, check);
            return button;
        }));
    }

    grid.addEventListener("click", (event) => {
        const button = event.target.closest("button[data-image-preset]");
        if (!button) return;
        const id = button.dataset.imagePreset;
        const url = selection.gallery(category.value).presets[id];
        releasePreview();
        picker.value = "";
        picker.setCustomValidity("");
        remove.checked = false;
        selection.choose({ image: url, preset_image: null, image_preset: id });
        renderPreview();
    });

    form.addEventListener("change", (event) => {
        if (event.target === category) {
            renderGallery();
        } else if (event.target === picker) {
            const file = picker.files[0];
            picker.setCustomValidity("");
            if (file && ((file.type && !["image/jpeg", "image/png", "image/webp"].includes(file.type))
                || file.size > 2 * 1024 * 1024)) {
                picker.setCustomValidity(t("Choose a JPG, JPEG, PNG or WEBP image up to 2 MB."));
                picker.reportValidity();
                return;
            }
            releasePreview();
            remove.checked = false;
            if (file) preview.dataset.url = URL.createObjectURL(file);
            selection.choose(file ? { image: preview.dataset.url, preset_image: null, image_preset: null } : null);
        } else if (event.target === remove) {
            releasePreview();
            picker.value = "";
            picker.setCustomValidity("");
            selection.choose(remove.checked ? { image: null, preset_image: null, image_preset: null, remove: true } : null);
        } else {
            return;
        }
        renderPreview();
    });

    renderGallery();
    renderPreview();
    return selection;
}
