export function showProductImageFallback(image) {
    const categoryImage = image.dataset.categoryImage;
    if (categoryImage) {
        delete image.dataset.categoryImage;
        image.src = categoryImage;
        return;
    }
    image.hidden = true;
    image.nextElementSibling.hidden = false;
}
