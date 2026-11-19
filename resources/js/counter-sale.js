// Compare tender with the checkout total in cents so split payments don't drift.
export function counterSaleRemaining(total, lines) {
    const cents = (amount) => Math.round(Number(amount) * 100);
    return cents(total) - lines.reduce((sum, line) => sum + cents(line.amount), 0);
}
