// Display only: preserve every fractional digit without converting decimal strings to floats.
export function quantity(value) {
    let text = String(value ?? "0");
    if (text.includes(".")) text = text.replace(/0+$/, "").replace(/\.$/, "");
    return text === "-0" || text === "" ? "0" : text;
}
