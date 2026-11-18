import { quantity } from "./quantity.js";

const SCALE = 1_000_000n;

function scaled(value) {
    if (typeof value !== "string" || !/^\d{1,12}(\.\d{1,6})?$/.test(value)) return null;
    const [whole, fraction = ""] = value.split(".");
    return BigInt(whole + fraction.padEnd(6, "0"));
}

export function receiptStockPreview(product, unitName, received) {
    if (!product || product.current_stock == null) return null;
    const unit = product.units.find(([name]) => name === unitName);
    return {
        current: quantity(product.current_stock),
        projected: projectedReceiptStock(product.current_stock, received, unit?.[1]),
        baseUnit: product.base_unit,
    };
}

export function projectedReceiptStock(current, received, conversion) {
    if (!/^\d{1,10}(\.\d{1,6})?$/.test(received)) return null;
    const existing = scaled(current);
    const incoming = scaled(received);
    const factor = scaled(conversion);
    if (existing === null || incoming === null || !factor) return null;

    // Round the converted quantity to the server's six base-unit decimal places.
    const balance = existing + (incoming * factor + SCALE / 2n) / SCALE;
    return quantity(`${balance / SCALE}.${String(balance % SCALE).padStart(6, "0")}`);
}
