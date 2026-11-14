export function productSellingUnits(data, existing, unitRecords) {
    const base = unitRecords.find((u) => u.name === data.baseUnit);
    const firstName =
        existing?.units[0]?.[0] || (data.baseUnit === "Bottle" ? "Piece" : data.baseUnit);
    const first = unitRecords.find((u) => u.name === firstName) || base;
    const result = (existing?.units || []).map((u) => ({
        unit_id: u[4] || unitRecords.find((r) => r.name === u[0])?.id,
        name: u[0],
        conversion_quantity: String(u[1]),
        selling_price: String(u[2]),
    }));
    const set = (id, name, qty, price) => {
        const row = result.find((u) => u.unit_id === id);
        if (row) Object.assign(row, { selling_price: price });
        else result.push({ unit_id: id, name, conversion_quantity: qty, selling_price: price });
    };
    set(first?.id, first?.name, "1", data.price);
    for (const [name, key, qty] of [
        ["Bucket", "bucket", "6"],
        ["Crate", "crate", "24"],
    ])
        if (Number(data[key]) > 0) {
            const u = unitRecords.find((u) => u.name === name);
            if (u) set(u.id, u.name, qty, data[key]);
        }
    return result;
}
