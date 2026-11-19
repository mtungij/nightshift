export function staffAccountStatus(staff) {
    return staff.archived ? "Archived" : staff.active ? "Active" : "Inactive";
}

export function staffActions(staff, canManageAdmins, canResetPin, button) {
    const protectedRole = ["Company Admin / Owner", "POS Terminal"].includes(staff.role);
    if (staff.archived || (!canManageAdmins && protectedRole) || staff.role === "Super Admin") {
        return "—";
    }
    const id = `data-id="${staff.id}"`;
    return `<div class="staff-actions">
        ${button("Edit", "staff-edit", "small", "", id)}
        ${canResetPin ? button("Reset PIN", "staff-pin", "small", "lock", id) : ""}
        ${button(staff.active ? "Deactivate" : "Activate", "staff-status", "small", "", id)}
        ${button("Delete", "staff-delete", "small danger", "", id)}
    </div>`;
}
