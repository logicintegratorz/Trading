const dialog = document.querySelector('#create-dialog');
document.querySelectorAll('[data-open-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
document.querySelectorAll('[data-close-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
if (dialog?.hasAttribute('data-auto-open')) dialog.showModal();
document.addEventListener('click', event => document.querySelectorAll('.nav-dropdown[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; }));
document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.nav-dropdown').forEach(menu => menu.open = false); });

document.querySelectorAll('[data-auto-filter]').forEach(form => {
    let debounceTimer;
    const submitButton = form.querySelector('[data-filter-submit]');

    if (submitButton) submitButton.hidden = true;

    form.querySelectorAll('select').forEach(select => {
        select.addEventListener('change', () => form.requestSubmit());
    });

    form.querySelectorAll('input[type="date"]').forEach(input => {
        input.addEventListener('change', () => form.requestSubmit());
    });

    form.querySelectorAll('input[type="search"], input[name="q"]').forEach(input => {
        input.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => form.requestSubmit(), 450);
        });
    });
});

document.querySelectorAll('[data-confirm-delete]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm('Delete this value? This action cannot be undone.')) event.preventDefault();
    });
});

document.querySelectorAll('[data-href]').forEach(row => {
    row.addEventListener('click', event => {
        if (!event.target.closest('a, button, input, select')) window.location.href = row.dataset.href;
    });
});

const moveDialog = document.querySelector('#move-dialog');
document.querySelectorAll('[data-open-move]').forEach(button => button.addEventListener('click', () => moveDialog?.showModal()));
document.querySelectorAll('[data-close-move]').forEach(button => button.addEventListener('click', () => moveDialog?.close()));

const orderForm = document.querySelector('#order-form');
if (orderForm) {
    const rows = orderForm.querySelector('[data-order-items]');
    const template = document.querySelector('#order-item-template');
    const total = document.querySelector('[data-order-total]');
    const summary = document.querySelector('[data-order-summary]');
    const currency = value => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(value);
    const updateTotal = () => {
        let subtotal = 0;
        rows.querySelectorAll('[data-order-row]').forEach(row => {
            const quantity = Number.parseFloat(row.querySelector('[data-item-quantity]')?.value) || 0;
            const weight = Number.parseFloat(row.querySelector('[data-item-weight]')?.value) || 0;
            const amount = quantity * (Number.parseFloat(row.querySelector('[data-item-rate]')?.value) || 0);
            if (weight > 0 && !row.dataset.manualPacking) {
                const pieces = Math.round(quantity * 1000 / weight);
                row.querySelector('[data-item-pieces]').value = pieces;
                row.querySelector('[data-item-bundles]').value = Math.ceil(pieces / 50);
            }
            subtotal += amount;
            row.querySelector('[data-item-amount]').textContent = currency(amount);
        });
        const charge = name => Number.parseFloat(orderForm.querySelector(`[name="${name}"]`)?.value) || 0;
        const taxable = Math.max(0, subtotal + charge('labour') + charge('commission') + charge('freight') + charge('other_charges') - charge('discount'));
        const gst = taxable * (Number.parseFloat(orderForm.querySelector('[name="gst_percent"]')?.value) || 0) / 100;
        total.textContent = currency(taxable + gst);
        summary.textContent = `Subtotal ${currency(subtotal)} · Taxable ${currency(taxable)} · GST ${currency(gst)}`;
    };
    const renumber = () => rows.querySelectorAll('[data-order-row]').forEach((row, index) => { row.querySelector('.line-number').textContent = index + 1; });
    const bindRow = row => {
        row.addEventListener('input', updateTotal);
        row.querySelector('[data-item-pieces]')?.addEventListener('input', () => { row.dataset.manualPacking = '1'; });
        row.querySelector('[data-item-bundles]')?.addEventListener('input', () => { row.dataset.manualPacking = '1'; });
        row.querySelector('[data-product-select]')?.addEventListener('change', event => {
            const option = event.target.options[event.target.selectedIndex];
            if (!option?.value) return;
            row.querySelector('[data-item-description]').value = option.dataset.name || '';
            row.querySelector('[data-item-weight]').value = option.dataset.weight || '';
            row.querySelector('[data-item-base-rate]').value = option.dataset.baseRate || '';
            row.querySelector('[data-item-rate]').value = option.dataset.rate || '';
            updateTotal();
        });
        row.querySelector('[data-remove-order-item]')?.addEventListener('click', () => {
            if (rows.querySelectorAll('[data-order-row]').length === 1) return;
            row.remove(); renumber(); updateTotal();
        });
    };
    rows.querySelectorAll('[data-order-row]').forEach(bindRow);
    document.querySelector('[data-add-order-item]')?.addEventListener('click', () => {
        const index = Date.now();
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', index).trim();
        const row = wrapper.firstElementChild;
        rows.appendChild(row); bindRow(row); renumber(); row.querySelector('[data-item-description]').focus();
    });
    orderForm.querySelectorAll('[data-charge], [data-gst]').forEach(input => input.addEventListener('input', updateTotal));
    updateTotal();
}

document.querySelectorAll('[data-sauda-entry]').forEach(entry => {
    const select = entry.querySelector('[data-sauda-select]');
    const party = entry.querySelector('[data-sauda-party]');
    const product = entry.querySelector('[data-sauda-product]');
    const remaining = entry.querySelector('[data-sauda-remaining]');
    const rate = entry.querySelector('[data-sauda-rate]');
    const quantity = entry.querySelector('[name="quantity"]');
    const updateSaudaDetails = () => {
        const option = select.options[select.selectedIndex];
        const balance = option?.dataset.remaining || '';
        party.value = option?.dataset.party || '';
        product.value = option?.dataset.product || '';
        remaining.value = balance ? `${balance} Ton` : '';
        rate.value = option?.dataset.rate ? `₹${option.dataset.rate}` : '';
        if (balance) quantity.max = balance;
        else quantity.removeAttribute('max');
    };
    select.addEventListener('change', updateSaudaDetails);
    updateSaudaDetails();
});
