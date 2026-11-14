const custodyRoot = document.querySelector('[data-cash-custody]');
if (custodyRoot) {
    const message = custodyRoot.querySelector('[data-custody-message]');
    for (const form of custodyRoot.querySelectorAll('form[data-custody-action]')) {
        form.addEventListener('input', () => delete form.dataset.requestKey);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const buttons = [...form.querySelectorAll('button')];
            buttons.forEach(b => b.disabled = true);
            message.textContent = '';
            try {
                const payload = Object.fromEntries(new FormData(form));
                for (const key of Object.keys(payload)) if (payload[key] === '') delete payload[key];
                const response = await fetch('/api/cash-custody/' + form.dataset.custodyAction + (form.dataset.id ? '/' + form.dataset.id : ''), {
                    method: 'POST', credentials: 'same-origin', headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},
                    body: JSON.stringify({...payload, idempotency_key: form.dataset.requestKey ||= crypto.randomUUID()}),
                });
                const data = await response.json();
                if (!response.ok) throw Error(Object.values(data.errors || {}).flat()[0] || data.message);
                location.reload();
            } catch (error) {
                message.textContent = error.message;
                message.scrollIntoView({block:'center'});
                buttons.forEach(b => b.disabled = false);
            }
        });
        const type = form.elements.transaction_type;
        if (type) {
            const update = () => form.querySelectorAll('[data-cash-type]').forEach(field => {
                const active = field.dataset.cashType === type.value;
                field.hidden = !active;
                field.querySelectorAll('input,select').forEach(input => input.disabled = !active);
            });
            type.addEventListener('change', update);
            update();
        }
    }
}

if (!document.querySelector('[data-workspace-page]') && document.querySelector('[data-cash-custody], #shift-stock, [data-open-shift]')) {
    window.addEventListener('nightshift:language', () => location.reload());
}
