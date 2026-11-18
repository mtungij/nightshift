import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import { productImageSelection, showProductImageFallback } from "../../resources/js/product-images.js";

test("a failed upload first switches to the category image", () => {
    const image = {
        src: "/api/nightshift/products/1/image",
        getAttribute() { return this.src; },
        dataset: { categoryImage: "/images/product-categories/beer.webp" },
        hidden: false,
        nextElementSibling: { hidden: true },
    };
    showProductImageFallback(image);
    assert.equal(image.src, "/images/product-categories/beer.webp");
    assert.equal(image.dataset.categoryImage, undefined);
    assert.equal(image.hidden, false);
    assert.equal(image.nextElementSibling.hidden, true);

    showProductImageFallback(image);
    assert.equal(image.hidden, true);
    assert.equal(image.nextElementSibling.hidden, false);
});

test("a missing upload and preset fall through to the category default", () => {
    const image = {
        src: "/api/nightshift/products/1/image",
        getAttribute() { return this.src; },
        dataset: { presetImage: "/images/product-gallery/wine/wine-03.webp", categoryImage: "/images/product-categories/beer.webp" },
        hidden: false,
        nextElementSibling: { hidden: true },
    };
    showProductImageFallback(image);
    assert.equal(image.src, "/images/product-gallery/wine/wine-03.webp");
    showProductImageFallback(image);
    assert.equal(image.src, "/images/product-categories/beer.webp");
    showProductImageFallback(image);
    assert.equal(image.hidden, true);
});

test("category changes refresh suggestions but preserve the active image until an explicit choice", () => {
    const wine = { default: "/wine.webp", presets: { "wine-03": "/wine-03.webp" } };
    const beer = { default: "/beer.webp", presets: { "beer-02": "/beer-02.webp" } };
    const editor = productImageSelection({ image: "/private-image", name: "Example" }, { wine, beer });
    assert.deepEqual(editor.gallery(" Wine "), wine);
    assert.deepEqual(editor.gallery("Beer"), beer);
    assert.equal(editor.gallery("Unknown"), undefined);
    assert.equal(editor.presentation("Wine").image, "/private-image");
    assert.equal(editor.presentation("Beer").image, "/private-image");
    assert.deepEqual(editor.payload(), {});

    editor.choose({ image: beer.presets["beer-02"], image_preset: "beer-02" });
    assert.equal(editor.presentation("Wine").image, "/beer-02.webp");
    assert.deepEqual(editor.payload(), { image_preset: "beer-02" });
    editor.choose({ image: "blob:custom", image_preset: null });
    assert.equal(editor.presentation("Wine").image, "blob:custom");
    assert.deepEqual(editor.payload(), {});
    editor.choose({ image: null, image_preset: null, remove: true });
    assert.equal(editor.presentation("Wine").category_image, "/wine.webp");
    assert.deepEqual(editor.payload(), { remove_image: true });
});

test("gallery presents four desktop columns and three touch-sized columns on phones", () => {
    const css = readFileSync(new URL("../../resources/css/app.css", import.meta.url), "utf8");
    assert.match(css, /\.product-image-options\s*\{[^}]*repeat\(4,/);
    assert.match(css, /\.product-image-options button\s*\{[^}]*min-height: 44px;[^}]*min-width: 44px;/);
    assert.match(css, /@media \(max-width: 520px\)\s*\{[\s\S]*?\.product-image-options\s*\{[^}]*repeat\(3,/);
});

test("a failed category image reveals the original product visual", () => {
    const image = { dataset: {}, hidden: false, nextElementSibling: { hidden: true } };
    showProductImageFallback(image);
    assert.equal(image.hidden, true);
    assert.equal(image.nextElementSibling.hidden, false);
});
