import assert from "node:assert/strict";
import test from "node:test";
import { showProductImageFallback } from "../../resources/js/product-images.js";

test("a failed upload first switches to the category image", () => {
    const image = {
        src: "/api/nightshift/products/1/image",
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

test("a failed category image reveals the original product visual", () => {
    const image = { dataset: {}, hidden: false, nextElementSibling: { hidden: true } };
    showProductImageFallback(image);
    assert.equal(image.hidden, true);
    assert.equal(image.nextElementSibling.hidden, false);
});
