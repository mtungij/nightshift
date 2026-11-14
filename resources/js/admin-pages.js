document.addEventListener('click', event => {
    const open = event.target.closest('[data-audit-open]');
    if (open) document.getElementById(open.dataset.auditOpen)?.showModal();
    if (event.target.closest('[data-audit-close]')) event.target.closest('dialog')?.close();
});
