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
    const saudaId = entry.querySelector('[data-sauda-id]');
    const party = entry.querySelector('[data-direct-party]');
    const partyId = entry.querySelector('[data-party-id]');
    const product = entry.querySelector('[data-sauda-product]');
    const productId = entry.querySelector('[data-product-id]');
    const group = entry.querySelector('[data-sauda-group]');
    const remaining = entry.querySelector('[data-sauda-remaining]');
    const rate = entry.querySelector('[data-sauda-rate]');
    const quantity = entry.querySelector('[name="quantity"]');
    const findOption = (listId, value) => [...document.querySelectorAll(`#${listId} option`)].find(option => option.value.toLocaleLowerCase() === value.trim().toLocaleLowerCase());
    const syncParty = () => {
        const option = findOption('party-options', party.value);
        partyId.value = option?.dataset.id || '';
    };
    const syncProduct = () => {
        const option = findOption('product-options', product.value);
        productId.value = option?.dataset.id || '';
        if (!option) return;
        if (group && !saudaId.value) group.value = option.dataset.group || '';
        const description = entry.querySelector('[data-line-description]');
        if (description) description.value = option.value;
        const purchaseRate = entry.querySelector('input[data-purchase-rate]');
        if (purchaseRate) purchaseRate.value = option.dataset.purchaseRate ? `₹${option.dataset.purchaseRate}` : '₹0.00';
    };
    const updateStockAmount = () => {
        const stockRate = entry.querySelector('[data-stock-rate]');
        const stockAmount = entry.querySelector('[data-stock-amount]');
        const rawRate = String(stockRate?.value || '').replace(/[^0-9.]/g, '');
        const amount = (Number(quantity?.value) || 0) * (Number(rawRate) || 0);
        if (stockAmount) stockAmount.value = `₹${amount.toFixed(2)}`;
        entry.querySelectorAll('[data-stock-total]').forEach(total => total.textContent = `₹${amount.toFixed(2)}`);
    };
    const updateSaudaDetails = () => {
        const option = findOption('sauda-options', select.value);
        saudaId.value = option?.dataset.id || '';
        const balance = option?.dataset.remaining || '';
        if (party && option?.dataset.party) { party.value = option.dataset.party; partyId.value = option.dataset.partyId || ''; }
        const groupName = option?.dataset.group || '';
        if (group) group.value = groupName;
        document.querySelectorAll('#product-options option').forEach(productOption => {
            productOption.hidden = Boolean(groupName) && productOption.dataset.group !== groupName;
        });
        if (groupName && product.value && findOption('product-options', product.value)?.dataset.group !== groupName) { product.value = ''; productId.value = ''; }
        const stockRate = entry.querySelector('[data-stock-rate]');
        if (stockRate && option?.dataset.rate) stockRate.value = option.dataset.rate;
        updateStockAmount();
        remaining.value = balance ? `${balance} Ton` : '';
        rate.value = option?.dataset.rate ? `₹${option.dataset.rate}` : '';
        if (balance) quantity.max = balance;
        else quantity.removeAttribute('max');
    };
    select.addEventListener('input', updateSaudaDetails);
    select.addEventListener('change', updateSaudaDetails);
    party.addEventListener('input', syncParty);
    party.addEventListener('change', syncParty);
    product.addEventListener('input', syncProduct);
    product.addEventListener('change', syncProduct);
    quantity?.addEventListener('input', updateStockAmount);
    entry.querySelector('[data-stock-rate]')?.addEventListener('input', updateStockAmount);
    updateSaudaDetails();
    syncParty(); syncProduct();
});

// Vehicle / challan references should look the same while typing, in lists and on printouts.
document.querySelectorAll('[data-uppercase]').forEach(input => input.addEventListener('input', () => {
    const cursor = input.selectionStart;
    input.value = input.value.toLocaleUpperCase();
    input.setSelectionRange(cursor, cursor);
}));

document.querySelectorAll('[data-rate-master]').forEach(form => {
    if (form.action.endsWith('/inventory/size-rates')) {
        ['group_name', 'weight', 'opening_qty'].forEach(name => { const field=form.querySelector(`[name="${name}"]`); field?.removeAttribute('required'); field?.closest('label')?.querySelector('span')?.replaceChildren(field.closest('label').querySelector('span').textContent.replace(' *','')); });
    }
    const picker = form.querySelector('[data-rate-product]');
    const purchase = form.querySelector('[name="purchase_rate"]');
    const differenceRate = form.querySelector('[name="difference_rate"]');
    const sale = form.querySelector('[name="sale_rate"]');
    const totalGst = form.querySelector('[data-total-gst]');
    const cgst = form.querySelector('[data-cgst]');
    const sgst = form.querySelector('[data-sgst]');
    const difference = form.querySelector('[data-rate-difference]');
    if (differenceRate) differenceRate.readOnly = true;
    const newEntry = form.querySelector('.rate-master-actions a');
    if (newEntry) { newEntry.textContent = '+ New Entry'; newEntry.href = '/inventory/size-rates/new'; }
    const updateRates = () => {
        const purchaseValue = Number(purchase.value) || 0;
        const saleValue = Number(sale.value) || 0;
        if (differenceRate) differenceRate.value = purchaseValue.toFixed(2);
        const gstHalf = (Number(totalGst.value) || 0) / 2;
        cgst.value = gstHalf.toFixed(2);
        sgst.value = gstHalf.toFixed(2);
        difference.textContent = `₹${purchaseValue.toFixed(2)}`;
    };
    picker?.addEventListener('change', () => { window.location.href = picker.value; });
    [purchase, sale, totalGst].forEach(input => input?.addEventListener('input', updateRates));
    updateRates();
});

document.querySelectorAll('[data-multi-stock-voucher]').forEach(form => {
    const rows = form.querySelector('[data-stock-rows]'); const template = form.querySelector('[data-stock-row]');
    const sauda = form.querySelector('[data-sauda]'); const party = form.querySelector('[data-party]'); const filter = form.querySelector('[data-group-filter]');
    const addQuickCreate = (select, master, label) => { if (!select || select.parentElement.querySelector('[data-quick-create]')) return; const button=document.createElement('button'); button.type='button'; button.className='quick-create'; button.dataset.quickCreate='1'; button.textContent=`+ New ${label}`; button.addEventListener('click', async()=>{const name=window.prompt(`Enter new ${label} name`);if(!name?.trim())return;const token=form.querySelector('[name="_token"]')?.value;const response=await fetch(`/masters/${master}`,{method:'POST',headers:{'X-CSRF-TOKEN':token,'Content-Type':'application/x-www-form-urlencoded','Accept':'text/html'},body:new URLSearchParams({_token:token,name:name.trim()})});if(response.ok){sessionStorage.setItem(`tradeflow-new-${master}`,name.trim());window.location.reload();}else window.alert(`Could not create ${label}. It may already exist.`);}); select.after(button); };
    addQuickCreate(party,'parties','Party');
    const total = () => {
        let value = 0, totalWeight = 0;
        rows.querySelectorAll('[data-stock-row]').forEach(row => {
            const quantity = Number(row.querySelector('[data-row-qty]').value) || 0;
            const amount = quantity * (Number(row.querySelector('[data-row-rate]').value) || 0);
            row.querySelector('[data-row-amount]').value = `₹${amount.toFixed(2)}`;
            value += amount; totalWeight += quantity;
        });
        const charge = name => Number(form.querySelector(`[name="${name}"]`)?.value) || 0;
        const labourAmount = charge('labour') * totalWeight;
        const taxable = Math.max(0, value + labourAmount + charge('commission') + charge('freight') + charge('other_charges') - charge('discount'));
        const gst = taxable * charge('gst_percent') / 100;
        form.querySelector('[data-total-weight]').value = `${totalWeight.toFixed(3)} Ton`;
        form.querySelector('[data-labour-total]').value = `₹${labourAmount.toFixed(2)}`;
        form.querySelector('[data-stock-value]').textContent = `₹${value.toFixed(2)}`;
        form.querySelector('[data-stock-gst]').textContent = `₹${gst.toFixed(2)}`;
        form.querySelector('[data-stock-grand]').textContent = `₹${(taxable + gst).toFixed(2)}`;
    };
        const bind = row => { const group=row.querySelector('[data-row-group]'), product=row.querySelector('[data-row-product]'), difference=row.querySelector('[data-row-difference]'), base=row.querySelector('[data-row-base]'), rate=row.querySelector('[data-row-rate]'), globalBase=form.querySelector('[data-global-base-rate]'); addQuickCreate(group,'group-heads','Group Head'); difference.readOnly=false; difference.type='number'; difference.min='0'; difference.step='0.01'; difference.name=rate.name.replace('[rate]','[difference]'); base.readOnly=false; base.type='number'; base.min='0'; base.step='0.01'; base.name=rate.name.replace('[rate]','[base_rate]'); const sync=()=>{ [...product.options].forEach(o=>{if(!o.value)return;o.hidden=!!group.value&&o.dataset.group!==group.value;}); const opt=product.options[product.selectedIndex]; if(opt?.value){if(!difference.dataset.initialized){difference.value=opt.dataset.difference||'0.00';difference.dataset.initialized='1';} if(!base.value) base.value=(globalBase?.value || opt.dataset.purchase || '0.00');} rate.value=((Number(base.value)||0)+(Number(difference.value)||0)).toFixed(2); total();}; group.addEventListener('change',()=>{difference.dataset.initialized='';sync();});product.addEventListener('change',()=>{difference.dataset.initialized='';sync();});difference.addEventListener('input',sync);base.addEventListener('input',sync);row.querySelectorAll('input').forEach(i=>i.addEventListener('input',total));row.querySelector('[data-remove-row]').addEventListener('click',()=>{if(rows.children.length>1){row.remove();total();}});sync();};
    const add=()=>{const index=Date.now();const box=document.createElement('div');box.innerHTML=template.innerHTML.replaceAll('__INDEX__',index).trim();const row=box.firstElementChild;rows.appendChild(row);bind(row);return row;};
    form.querySelector('[data-add-row]').addEventListener('click',add); filter.addEventListener('change',()=>rows.querySelectorAll('[data-row-group]').forEach(el=>{el.value=filter.value;el.dispatchEvent(new Event('change'));}));
    sauda.addEventListener('change',()=>{
        const o = sauda.options[sauda.selectedIndex];
        if (!o.value) {
            form.querySelector('[data-sauda-balance]').textContent = 'Direct entry';
            return;
        }
        // A Sauda is one committed deal. Replace unfinished voucher rows so no values
        // from the previously selected deal remain in the new voucher.
        party.value = o.dataset.party || '';
        filter.value = o.dataset.group || '';
        form.querySelector('[data-sauda-balance]').textContent = o.dataset.balance ? `${Number(o.dataset.balance).toFixed(3)} Ton` : '0.000 Ton';
        rows.replaceChildren();
        const firstRow = add();
        const selectedGroup = firstRow.querySelector('[data-row-group]');
        const selectedProduct = firstRow.querySelector('[data-row-product]');
        selectedGroup.value = o.dataset.group || '';
        selectedGroup.dispatchEvent(new Event('change'));
        selectedProduct.value = o.dataset.product || '';
        selectedProduct.dispatchEvent(new Event('change'));
        const rateValue = window.stockSaudaRates?.[o.value];
        const saudaRate = rateValue === undefined ? '' : (Number(rateValue) / 100).toFixed(2);
        const globalBase = form.querySelector('[data-global-base-rate]');
        if (globalBase) globalBase.value = saudaRate;
        firstRow.querySelector('[data-row-base]').value = saudaRate;
        firstRow.querySelector('[data-row-base]').dispatchEvent(new Event('input'));
        firstRow.querySelector('[data-row-qty]').value = o.dataset.balance || '0.000';
        firstRow.querySelector('[data-row-qty]').dispatchEvent(new Event('input'));
    });
    sauda.addEventListener('change',()=>{const storedRate=window.stockSaudaRates?.[sauda.value]; if(storedRate===undefined)return; const saudaRate=(Number(storedRate)/100).toFixed(2); const globalBase=form.querySelector('[data-global-base-rate]'); if(globalBase)globalBase.value=saudaRate; rows.querySelectorAll('[data-row-base]').forEach(base=>{base.value=saudaRate;base.dispatchEvent(new Event('input'));});}); add();
    if (form.dataset.initialSauda) { sauda.value=form.dataset.initialSauda; sauda.dispatchEvent(new Event('change')); const row=rows.querySelector('[data-stock-row]'); if(row){row.querySelector('[data-row-group]').value=filter.value; row.querySelector('[data-row-group]').dispatchEvent(new Event('change')); row.querySelector('[data-row-product]').value=form.dataset.initialProduct; row.querySelector('[data-row-product]').dispatchEvent(new Event('change')); const globalBase=form.querySelector('[data-global-base-rate]'); if(globalBase) globalBase.value=form.dataset.initialRate; row.querySelector('[data-row-base]').value=form.dataset.initialRate; row.querySelector('[data-row-base]').dispatchEvent(new Event('input')); const balance=sauda.options[sauda.selectedIndex]?.dataset.balance; if(balance){row.querySelector('[data-row-qty]').value=balance; row.querySelector('[data-row-qty]').dispatchEvent(new Event('input'));}} }
    // Voucher terminology follows the client's established paper voucher.
    const headings = form.querySelectorAll('.multi-head span');
    if (headings[4]) headings[4].textContent = 'B.Rate';
    form.querySelector('.voucher-lines-title a')?.remove();
    const top = form.querySelector('.voucher-parties');
    if (top && !form.querySelector('.voucher-reference-strip')) {
        const reference = document.createElement('div');
        reference.className = 'voucher-reference-strip';
        reference.innerHTML = '<label>City<input name="city" placeholder="Enter city"></label><label>TIN<input name="tin" placeholder="Enter TIN"></label><label>B.Rate<input type="number" min="0" step="0.01" data-global-base-rate placeholder="Set B.Rate for all rows"></label><label>Terms<input name="terms" placeholder="Payment terms"></label>';
        top.after(reference);
        if (form.dataset.initialRate) reference.querySelector('[data-global-base-rate]').value = form.dataset.initialRate;
        reference.querySelector('[data-global-base-rate]').addEventListener('input', event => { rows.querySelectorAll('[data-stock-row]').forEach(row => { row.querySelector('[data-row-base]').value = event.target.value; row.querySelector('[data-row-base]').dispatchEvent(new Event('input')); }); });
    }
    form.querySelectorAll('[data-charge], [data-gst]').forEach(input => input.addEventListener('input', total));
});
