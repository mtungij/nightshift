// Translate presentation text only. Interpolated business data and attribute
// values (IDs, statuses, role names, form values) are deliberately left intact.
export const t = (text, replacements = {}) => {
    const messages = window.NightshiftI18n?.messages || {};
    let translated = Object.hasOwn(messages, text) ? messages[text] : text;
    for (const [key, value] of Object.entries(replacements)) {
        translated = translated.replaceAll(`:${key}`, String(value));
    }
    return translated;
};

const fragment = (text) => {
    const trimmed = text.trim();
    if (!trimmed) return text;
    const canonical = trimmed.replace(/\s+/g, " ");
    const suffix = canonical.match(/[:：]$/)?.[0] || "";
    const translated =
        t(canonical) !== canonical
            ? t(canonical)
            : t(suffix ? canonical.slice(0, -1) : canonical) + suffix;
    return text.replace(trimmed, translated);
};

export function html(strings, ...values) {
    // Tokens isolate every substitution, including user-supplied names. No
    // rendered DOM is walked and no user data is fed to the translator.
    const source = strings.reduce(
        (result, part, index) =>
            result + part + (index < values.length ? `\u0001${index}\u0002` : ""),
        "",
    );
    const translateParts = (text) =>
        text
            .split(/(\u0001\d+\u0002)/)
            .map((part) => (/^\u0001/.test(part) ? part : fragment(part)))
            .join("");
    const translated = source
        .replace(/>([^<>]*)</g, (_, text) => ">" + translateParts(text) + "<")
        .replace(
            /\b(aria-label|placeholder|title)="([^"<>]*)"/g,
            (_, attr, text) => `${attr}="${translateParts(text)}"`,
        );
    return translated.replace(/\u0001(\d+)\u0002/g, (_, index) =>
        String(values[Number(index)] ?? ""),
    );
}
