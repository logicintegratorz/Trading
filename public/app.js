const dialog = document.querySelector('#create-dialog');
// Treat spaces and common separators as optional in searchable dropdowns.
// For example, "1x1x3", "1 x 1 x 3" and "1-X-1-X-3" find the same item.
const normalizeSelectSearch = value => String(value).toLowerCase().replace(/[^a-z0-9]+/g, '');
const showToast = (message, type = 'error') => {
    document.querySelectorAll('.app-toast').forEach(toast => toast.remove());
    const toast = document.createElement('div');
    toast.className = `app-toast app-toast-${type}`;
    toast.setAttribute('role', 'alert');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed;z-index:9999;top:18px;left:50%;transform:translateX(-50%);max-width:min(540px,calc(100vw - 32px));padding:13px 18px;border-radius:10px;background:#a84b3c;color:#fff;box-shadow:0 14px 32px rgba(25,48,45,.28);font:600 14px/1.4 system-ui,-apple-system,sans-serif;cursor:pointer;';
    toast.addEventListener('click', () => toast.remove());
    document.body.append(toast);
    window.setTimeout(() => toast.remove(), 4000);
};

// A material line can contain a product only once. This is intentionally
// delegated so it also covers rows that are added dynamically.
const isMultiLineProductSelect = select => select instanceof HTMLSelectElement
    && select.matches('[data-product-select], [data-row-product]');
document.addEventListener('change', event => {
    const select = event.target;
    if (!isMultiLineProductSelect(select) || !select.value) return;

    const form = select.closest('form');
    if (!form) return;
    const productSelects = [...form.querySelectorAll('[data-product-select], [data-row-product]')];
    const isDuplicate = productSelects.some(other => other !== select && other.value === select.value);
    if (!isDuplicate) {
        select.dataset.lastProductId = select.value;
        return;
    }

    const productName = select.options[select.selectedIndex]?.textContent?.trim() || 'This product';
    select.value = select.dataset.lastProductId || '';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    showToast(`${productName} is already selected in this entry. Please choose another product.`);
});
document.querySelectorAll('[data-pending-dispatch-form]').forEach(form => form.addEventListener('submit', event => {
    if (form.querySelector('input[type="checkbox"]:checked')) return;
    event.preventDefault();
    showToast('Select at least one pending product to dispatch.');
}));
document.querySelectorAll('[data-open-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
document.querySelectorAll('[data-close-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
if (dialog?.hasAttribute('data-auto-open')) dialog.showModal();
document.addEventListener('click', event => document.querySelectorAll('.nav-dropdown[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; }));
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('.nav-dropdown').forEach(menu => menu.open = false);
    document.querySelectorAll('.search-select-menu:not([hidden])').forEach(menu => {
        menu.hidden = true;
        menu.classList.remove('opens-up');
        menu.closest('.search-select')?.querySelector('[data-search-select-trigger]')?.setAttribute('aria-expanded', 'false');
    });
});

// Session activity is refreshed only while the user is actively working.
// This prevents a 25-minute timeout during long keyboard/mouse work, while an
// untouched page still expires normally. The server remains the source of truth
// for both the inactivity and midnight (Asia/Kolkata) logout rules.
(() => {
    let lastInteractionAt = Date.now();
    let lastKeepAliveAt = 0;
    const markInteraction = () => { lastInteractionAt = Date.now(); };
    ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'].forEach(eventName => {
        document.addEventListener(eventName, markInteraction, { passive: true });
    });
    const keepAlive = async () => {
        const now = Date.now();
        if (document.hidden || now - lastInteractionAt > 90_000 || now - lastKeepAliveAt < 60_000) return;
        lastKeepAliveAt = now;
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch('/session/keep-alive', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-CSRF-TOKEN': token } : {}) },
            });
            if (response.redirected || response.url.includes('/login')) window.location.assign(response.url);
        } catch (_) {
            // A transient network error must not make an active form unusable.
        }
    };
    window.setInterval(keepAlive, 30_000);
})();

// Keyboard-first data entry: Enter, Right Arrow and Down Arrow advance
// through editable fields. Left Arrow at an input's start and Up Arrow go
// back. Computed cells and row action buttons are deliberately skipped.
const usableFormFields = form => [...form.querySelectorAll('input, select, textarea, button[type="submit"], button[data-search-select-trigger], button[data-add-row], button[data-add-order-item]')]
    .filter(field => !field.disabled && field.type !== 'hidden' && !field.readOnly && field.getClientRects().length)
    .filter(field => !field.hasAttribute('data-keyboard-skip'));
const moveFormFocus = (field, direction) => {
    const form = field.closest('form');
    if (!form) return;
    const fields = usableFormFields(form);
    const current = fields.indexOf(field);
    const next = fields[current + direction];
    if (next) {
        next.focus();
        if (typeof next.select === 'function' && next.matches('input:not([type="date"]):not([type="file"])')) next.select();
    }
};
document.addEventListener('keydown', event => {
    const field = event.target;
    if (!(field instanceof HTMLElement) || event.defaultPrevented || field.matches('[data-select-search], textarea, [contenteditable="true"], button, a, input[type="file"], input[type="checkbox"], input[type="radio"]')) return;

    if (event.key === 'Enter') {
        event.preventDefault();
        moveFormFocus(field, 1);
        return;
    }

    const cursorAtStart = field.matches('input, textarea') && typeof field.selectionStart === 'number' && field.selectionStart === 0 && field.selectionEnd === 0;
    const cursorAtEnd = field.matches('input, textarea') && typeof field.selectionEnd === 'number' && field.selectionEnd === field.value.length;
    const isNumber = field.matches('input[type="number"]');
    if ((event.key === 'ArrowLeft' && (cursorAtStart || field.matches('select'))) || (event.key === 'ArrowUp' && !isNumber)) {
        event.preventDefault();
        moveFormFocus(field, -1);
    }
    if ((event.key === 'ArrowRight' && (cursorAtEnd || field.matches('select'))) || (event.key === 'ArrowDown' && !field.matches('select') && !isNumber)) {
        event.preventDefault();
        moveFormFocus(field, 1);
    }
});

// Every standard dropdown gets the same compact, internally searchable menu.
// A rare browser-native select can opt out with data-native-select.
const searchableSelect = select => !select.multiple && !select.hasAttribute('data-native-select');
const enhanceSearchableSelects = (root = document) => {
    const selects = root instanceof HTMLSelectElement
        ? [root]
        : [...root.querySelectorAll?.('select') || []];
    selects.filter(select => searchableSelect(select) && !select.dataset.searchReady).forEach(select => {
        select.dataset.searchReady = 'true';
        const wrapper = document.createElement('div');
        const trigger = document.createElement('button');
        const menu = document.createElement('div');
        const search = document.createElement('input');
        const options = document.createElement('div');
        const labelText = (() => {
            if (select.matches('[data-row-product], [data-product-select], [data-sauda-product], select[name="product_id"]')) return 'product';
            if (select.matches('[data-row-group], [data-group-filter], select[name="group_name"]') || select.name === 'group' || select.name.includes('group_head')) return 'group head';
            if (select.name === 'stock_status') return 'stock status';
            if (select.name === 'type') return 'type';
            if (select.name === 'status') return 'status';
            if (select.name === 'sauda_id') return 'Sauda';
            return 'party';
        })();
        wrapper.className = 'search-select';
        trigger.type = 'button';
        trigger.className = 'search-select-trigger';
        trigger.dataset.searchSelectTrigger = 'true';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        menu.className = 'search-select-menu';
        menu.hidden = true;
        search.type = 'search';
        search.className = 'search-select-input';
        search.dataset.selectSearch = 'true';
        search.placeholder = `Search ${labelText}`;
        search.autocomplete = 'off';
        search.setAttribute('aria-label', `Search ${labelText} options`);
        options.className = 'search-select-options';
        select.before(wrapper);
        wrapper.append(trigger, select, menu);
        menu.append(search, options);
        select.classList.add('search-select-native');

        const visibleOptions = () => {
            const query = normalizeSelectSearch(search.value);
            return [...select.options].filter(option => option.value && !option.disabled && !option.hidden && (!query || normalizeSelectSearch(option.textContent).includes(query)));
        };
        const refreshTrigger = () => {
            const option = select.options[select.selectedIndex];
            trigger.textContent = option?.value ? option.textContent : `Select ${labelText}`;
        };
        const close = () => {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            menu.style.removeProperty('left');
            menu.style.removeProperty('top');
            menu.style.removeProperty('width');
            menu.style.removeProperty('right');
            menu.style.removeProperty('position');
        };
        const positionMenu = () => {
            // Fixed positioning keeps a dropdown visible when its select is inside
            // a horizontally scrolling table or an overflowed voucher section.
            const rect = trigger.getBoundingClientRect();
            const gutter = 12;
            const width = Math.min(Math.max(rect.width, 220), window.innerWidth - (gutter * 2));
            const left = Math.max(gutter, Math.min(rect.left, window.innerWidth - width - gutter));
            menu.style.position = 'fixed';
            menu.style.left = `${left}px`;
            menu.style.top = `${rect.bottom + 6}px`;
            menu.style.width = `${width}px`;
            menu.style.right = 'auto';
            // Keep the search field and at least a few options usable on a
            // short phone viewport. The menu still always opens downwards.
            menu.style.maxHeight = `${Math.max(132, window.innerHeight - rect.bottom - 18)}px`;
        };
        const choose = (option, advance = false) => {
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            refreshTrigger();
            close();
            if (advance) moveFormFocus(trigger, 1); else trigger.focus();
        };
        const render = () => {
            const matches = visibleOptions();
            options.replaceChildren();
            if (!matches.length) {
                const empty = document.createElement('p');
                empty.className = 'search-select-empty';
                empty.textContent = `No matching ${labelText}`;
                options.append(empty);
                return;
            }
            matches.forEach(option => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'search-select-option';
                item.textContent = option.textContent;
                item.dataset.value = option.value;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', String(option.value === select.value));
                item.addEventListener('click', () => choose(option));
                item.addEventListener('keydown', event => {
                    const items = [...options.querySelectorAll('.search-select-option')];
                    const index = items.indexOf(item);
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        (items[index + 1] || items[0])?.focus();
                    }
                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (index === 0) search.focus(); else items[index - 1]?.focus();
                    }
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        choose(option, true);
                    }
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        close();
                        trigger.focus();
                    }
                    if (event.key === 'ArrowLeft') {
                        event.preventDefault();
                        close();
                        moveFormFocus(trigger, -1);
                    }
                    if (event.key === 'ArrowRight') {
                        event.preventDefault();
                        close();
                        moveFormFocus(trigger, 1);
                    }
                });
                options.append(item);
            });
        };
        const open = (focusFirstOption = false) => {
            document.querySelectorAll('.search-select-menu:not([hidden])').forEach(other => {
                if (other !== menu) other.hidden = true;
            });
            menu.hidden = false;
            // Trading operators expect each select to open downwards everywhere.
            menu.classList.remove('opens-up');
            trigger.setAttribute('aria-expanded', 'true');
            search.value = '';
            render();
            positionMenu();
            if (focusFirstOption) options.querySelector('.search-select-option')?.focus(); else search.focus();
        };
        refreshTrigger();
        trigger.addEventListener('click', () => menu.hidden ? open() : close());
        trigger.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (select.value) moveFormFocus(trigger, 1); else open();
            }
            if (event.key === ' ') {
                event.preventDefault();
                open();
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open(true);
            }
            if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                event.preventDefault();
                moveFormFocus(trigger, -1);
            }
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                moveFormFocus(trigger, 1);
            }
        });
        search.addEventListener('input', render);
        search.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                options.querySelector('.search-select-option')?.focus();
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                const matches = visibleOptions();
                if (matches.length === 1) choose(matches[0], true);
            }
            if (event.key === 'ArrowLeft' && search.selectionStart === 0 && search.selectionEnd === 0) {
                event.preventDefault();
                close();
                trigger.focus();
            }
            if (event.key === 'ArrowUp' && search.selectionStart === 0 && search.selectionEnd === 0) {
                event.preventDefault();
                close();
                trigger.focus();
            }
        });
        select.addEventListener('change', () => {
            refreshTrigger();
            render();
        });
    });
};
enhanceSearchableSelects();
document.addEventListener('click', event => document.querySelectorAll('.search-select').forEach(wrapper => {
    if (!wrapper.contains(event.target)) {
        const menu = wrapper.querySelector('.search-select-menu');
        const trigger = wrapper.querySelector('[data-search-select-trigger]');
        if (menu) menu.hidden = true;
        trigger?.setAttribute('aria-expanded', 'false');
    }
}));
// A fixed-position menu must not remain on screen when the operator scrolls
// anywhere outside that menu. Option-list scrolling remains available.
document.addEventListener('scroll', event => {
    const target = event.target;
    if (target instanceof Element && target.closest('.search-select-menu')) return;
    document.querySelectorAll('.search-select-menu:not([hidden])').forEach(menu => {
        menu.hidden = true;
        menu.closest('.search-select')?.querySelector('[data-search-select-trigger]')?.setAttribute('aria-expanded', 'false');
    });
    document.querySelectorAll('.nav-dropdown[open]').forEach(menu => menu.open = false);
}, { capture: true, passive: true });
window.addEventListener('resize', () => {
    document.querySelectorAll('.search-select-menu:not([hidden])').forEach(menu => {
        menu.hidden = true;
        menu.closest('.search-select')?.querySelector('[data-search-select-trigger]')?.setAttribute('aria-expanded', 'false');
    });
    // Orientation/viewport changes can otherwise leave a fixed mobile menu
    // detached from its navigation trigger.
    document.querySelectorAll('.nav-dropdown[open]').forEach(menu => menu.open = false);
});
document.querySelectorAll('.nav-dropdown').forEach(details => details.addEventListener('toggle', () => {
    if (!details.open || !window.matchMedia('(max-width: 800px)').matches) return;
    document.querySelectorAll('.nav-dropdown[open]').forEach(other => {
        if (other !== details) other.open = false;
    });
    const summary = details.querySelector('summary');
    const dropdown = details.querySelector('.dropdown');
    if (!summary || !dropdown) return;
    const rect = summary.getBoundingClientRect();
    dropdown.style.top = `${rect.bottom + 6}px`;
    dropdown.style.maxHeight = `${Math.max(140, window.innerHeight - rect.bottom - 18)}px`;
}));
new MutationObserver(mutations => mutations.forEach(mutation => mutation.addedNodes.forEach(node => {
    if (node.nodeType === Node.ELEMENT_NODE) enhanceSearchableSelects(node);
}))).observe(document.body, { childList: true, subtree: true });

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
    let newProductTargetRow = null;
    orderForm.querySelector('[data-order-new-party]')?.addEventListener('click', async () => {
        const name = window.prompt('Enter new Party name');
        if (!name?.trim()) return;
        const token = orderForm.querySelector('[name="_token"]')?.value;
        try {
            const response = await fetch('/masters/parties', { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ name: name.trim() }) });
            if (!response.ok) throw new Error();
            const party = await response.json();
            const select = orderForm.querySelector('[name="party_id"]');
            select.add(new Option(party.name, party.id, true, true));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            showToast(`${party.name} added and selected.`, 'success');
        } catch (_) { showToast('Could not add this Party. It may already exist.'); }
    });
    const updateTotal = () => {
        rows.querySelectorAll('[data-order-row]').forEach(row => {
            const quantity = Number.parseFloat(row.querySelector('[data-item-quantity]')?.value) || 0;
            const selectedProduct = row.querySelector('[data-product-select] option:checked');
            const pieceWeight = Number.parseFloat(selectedProduct?.dataset.weight)
                || Number.parseFloat(row.querySelector('[data-item-weight]')?.value) || 0;
            if (pieceWeight > 0) {
                row.querySelector('[data-item-pieces]').value = ((quantity * 1000) / pieceWeight).toFixed(3);
            }
            row.querySelector('[data-item-bundles]').value = ((quantity * 1000) / 52).toFixed(3);
        });
    };
    const renumber = () => rows.querySelectorAll('[data-order-row]').forEach((row, index) => { row.querySelector('.line-number').textContent = index + 1; });
    const bindRow = row => {
        row.addEventListener('input', updateTotal);
        row.querySelector('[data-order-new-product]')?.addEventListener('click', () => {
            // The Order remains open in this tab, so none of the already-filled
            // voucher details are lost while a new inventory product is created.
            newProductTargetRow = row;
            const popup = window.open('/inventory/products/create?popup=1', 'order-product-create', 'popup=yes,width=1080,height=820');
            if (!popup) showToast('Please allow pop-ups to add a new product without leaving this Order.');
        });
        row.querySelector('[data-product-select]')?.addEventListener('change', event => {
            const option = event.target.options[event.target.selectedIndex];
            if (!option?.value) return;
            row.querySelector('[data-item-description]').value = option.dataset.name || '';
            const values = (option.dataset.name || '').match(/\d+(?:\.\d+)?/g);
            row.querySelector('[data-item-weight]').value = values?.at(-1) || '';
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
    window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.data?.type !== 'tradeflow-product-created') return;
        const product = event.data.product;
        if (!product?.id || !product?.name) return;
        orderForm.querySelectorAll('[data-product-select]').forEach(select => {
            if (![...select.options].some(option => option.value === String(product.id))) {
                const option = new Option(product.name, product.id);
                option.dataset.name = product.name;
                option.dataset.weight = product.weight || '';
                option.dataset.rate = product.rate || '0.00';
                select.add(option);
            }
        });
        const select = newProductTargetRow?.querySelector('[data-product-select]');
        if (select) {
            select.value = String(product.id);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        newProductTargetRow = null;
        showToast(`${product.name} added and selected.`, 'success');
    });
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
        if (differenceRate) differenceRate.value = saleValue.toFixed(2);
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
    const isPurchaseVoucher = form.dataset.module === 'purchase';
    const initialBundleIds = (form.dataset.initialSaudaIds || '').split(',').filter(Boolean);
    const initialBundleOptions = initialBundleIds.map(id => [...sauda.options].find(option => option.value === id)).filter(Boolean);
    const addQuickCreate = (select, master, label) => { if (!select || select.parentElement.querySelector('[data-quick-create]')) return; const button=document.createElement('button'); button.type='button'; button.className='quick-create'; button.dataset.quickCreate='1'; button.textContent=`+ New ${label}`; button.addEventListener('click', async()=>{const name=window.prompt(`Enter new ${label} name`);if(!name?.trim())return;const token=form.querySelector('[name="_token"]')?.value;const response=await fetch(`/masters/${master}`,{method:'POST',headers:{'X-CSRF-TOKEN':token,'Content-Type':'application/x-www-form-urlencoded','Accept':'text/html'},body:new URLSearchParams({_token:token,name:name.trim()})});if(response.ok){sessionStorage.setItem(`tradeflow-new-${master}`,name.trim());window.location.reload();}else window.alert(`Could not create ${label}. It may already exist.`);}); select.after(button); };
    addQuickCreate(party,'parties','Party');
    const total = () => {
        let value = 0, totalWeight = 0;
        rows.querySelectorAll('[data-stock-row]').forEach(row => {
            if (row.dataset.bundleRow === 'true' && !row.querySelector('[data-bundle-include]')?.checked) return;
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
        form.querySelector('[data-stock-labour]').textContent = `₹${labourAmount.toFixed(2)}`;
        form.querySelector('[data-stock-discount]').textContent = `₹${charge('discount').toFixed(2)}`;
        form.querySelector('[data-stock-taxable]').textContent = `₹${taxable.toFixed(2)}`;
        form.querySelector('[data-stock-gst-rate]').textContent = charge('gst_percent').toFixed(2);
        form.querySelector('[data-stock-gst]').textContent = `₹${gst.toFixed(2)}`;
        form.querySelector('[data-stock-grand]').textContent = `₹${(taxable + gst).toFixed(2)}`;
    };
    const updateSaudaProgress = () => {
        const balanceLabel = form.querySelector('[data-sauda-balance]');
        const selected = sauda.options[sauda.selectedIndex];
        if (!balanceLabel) return;
        if (initialBundleOptions.length) {
            let remaining = 0;
            rows.querySelectorAll('[data-stock-row]').forEach(row => {
                if (!row.querySelector('[data-bundle-include]')?.checked) return;
                const quantity = row.querySelector('[data-row-qty]');
                const available = Number(row.dataset.bundleBalance) || 0;
                const entered = Number(quantity.value) || 0;
                remaining += available - entered;
                quantity.setCustomValidity(entered > available + 0.0001 ? `Quantity cannot exceed this product's Sale balance of ${available.toFixed(3)} Ton.` : '');
            });
            balanceLabel.textContent = remaining >= -0.0001 ? `${Math.max(0, remaining).toFixed(3)} Ton` : 'Check product quantities';
            return;
        }
        if (!selected?.value) {
            balanceLabel.textContent = 'Direct entry';
            return;
        }
        const available = Number(selected.dataset.balance) || 0;
        const entered = [...rows.querySelectorAll('[data-row-qty]')].reduce((sum, quantity) => sum + (Number(quantity.value) || 0), 0);
        const remaining = available - entered;
        balanceLabel.textContent = remaining >= -0.0001
            ? Math.max(0, remaining).toFixed(3) + ' Ton '
            : 'Over by ' + Math.abs(remaining).toFixed(3) + ' Ton';
        rows.querySelectorAll('[data-row-qty]').forEach(quantity => quantity.setCustomValidity(
            remaining < -0.0001 ? 'Total quantity cannot exceed the available Sauda balance of ' + available.toFixed(3) + ' Ton.' : ''
        ));
    };
        const getGlobalBaseRate = () => form.querySelector('[data-global-base-rate]')?.value?.trim() || '';
        const setRowBaseRate = (row, value = getGlobalBaseRate()) => {
            const base = row.querySelector('[data-row-base]');
            if (!base || value === '') return;
            base.value = value;
            base.dispatchEvent(new Event('input'));
        };
        const bind = row => { const group=row.querySelector('[data-row-group]'), product=row.querySelector('[data-row-product]'), difference=row.querySelector('[data-row-difference]'), base=row.querySelector('[data-row-base]'), rate=row.querySelector('[data-row-rate]'); addQuickCreate(group,'group-heads','Group Head'); difference.readOnly=false; difference.type='number'; difference.min='0'; difference.step='0.01'; difference.name=rate.name.replace('[rate]','[difference]'); base.readOnly=false; base.type='number'; base.min='0'; base.step='0.01'; base.name=rate.name.replace('[rate]','[base_rate]'); const sync=()=>{ [...product.options].forEach(o=>{if(!o.value)return;o.hidden=!!group.value&&o.dataset.group!==group.value;}); const opt=product.options[product.selectedIndex]; if(opt?.value&&!difference.dataset.initialized){difference.value=(isPurchaseVoucher ? opt.dataset.purchase : opt.dataset.difference)||'0.00';difference.dataset.initialized='1';} if(!base.value) base.value=(getGlobalBaseRate() || opt?.dataset.purchase || '0.00'); rate.value=((Number(base.value)||0)+(Number(difference.value)||0)).toFixed(2); total(); updateSaudaProgress();}; group.addEventListener('change',()=>{difference.dataset.initialized='';sync();});product.addEventListener('change',()=>{difference.dataset.initialized='';sync();});difference.addEventListener('input',sync);base.addEventListener('input',sync);row.querySelectorAll('input').forEach(i=>i.addEventListener('input',()=>{total();updateSaudaProgress();}));row.querySelector('[data-remove-row]').addEventListener('click',()=>{if(rows.children.length>1){row.remove();total();updateSaudaProgress();}});sync();};
    let rowSequence = 0;
    const focusNewRow = row => requestAnimationFrame(() => {
        const group = row.querySelector('[data-row-group]');
        const trigger = group?.closest('.search-select')?.querySelector('[data-search-select-trigger]');
        (trigger || group)?.focus();
    });
    const add=(focusFirstField=false)=>{const index=`${Date.now()}${rowSequence++}`;const box=document.createElement('div');box.innerHTML=template.innerHTML.replaceAll('__INDEX__',index).trim();const row=box.firstElementChild;rows.appendChild(row);bind(row);setRowBaseRate(row);if(focusFirstField)focusNewRow(row);return row;};
    form.querySelector('[data-add-row]').addEventListener('click',()=>add(true)); filter.addEventListener('change',()=>rows.querySelectorAll('[data-row-group]').forEach(el=>{el.value=filter.value;el.dispatchEvent(new Event('change'));}));
    // Party and Sauda stay in sync. A changed customer only sees that
    // customer's open Sauda entries, and the first matching Sauda loads its
    // complete voucher details automatically.
    let syncingPartyAndSauda = false;
    const filterSaudasForParty = partyId => {
        [...sauda.options].forEach(option => {
            if (!option.value) return;
            option.hidden = Boolean(partyId && option.dataset.party !== partyId);
        });
        const selected = sauda.options[sauda.selectedIndex];
        if (selected?.value && !selected.hidden) return selected;
        return [...sauda.options].find(option => option.value && !option.hidden) || sauda.options[0];
    };
    party.addEventListener('change', () => {
        if (syncingPartyAndSauda) return;
        const matchingSauda = filterSaudasForParty(party.value);
        sauda.value = matchingSauda?.value || '';
        sauda.dispatchEvent(new Event('change'));
    });
    sauda.addEventListener('change',()=>{
        const o = sauda.options[sauda.selectedIndex];
        if (!o.value) {
            form.querySelector('[data-sauda-balance]').textContent = 'Direct entry';
            return;
        }
        // A Sauda is one committed deal. Replace unfinished voucher rows so no values
        // from the previously selected deal remain in the new voucher.
        syncingPartyAndSauda = true;
        party.value = o.dataset.party || '';
        party.dispatchEvent(new Event('change'));
        syncingPartyAndSauda = false;
        filterSaudasForParty(party.value);
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
    if (initialBundleOptions.length) {
        syncingPartyAndSauda = true;
        party.value = initialBundleOptions[0].dataset.party || '';
        party.dispatchEvent(new Event('change'));
        syncingPartyAndSauda = false;
        rows.replaceChildren();
        filter.value = '';
        initialBundleOptions.forEach(option => {
            const row = add();
            row.dataset.bundleBalance = option.dataset.balance || '0';
            const group = row.querySelector('[data-row-group]');
            const product = row.querySelector('[data-row-product]');
            group.value = option.dataset.group || '';
            group.dispatchEvent(new Event('change'));
            product.value = option.dataset.product || '';
            product.dispatchEvent(new Event('change'));
            const rate = window.stockSaudaRates?.[option.value];
            const base = rate === undefined ? '0.00' : (Number(rate) / 100).toFixed(2);
            row.querySelector('[data-row-base]').value = base;
            row.querySelector('[data-row-base]').dispatchEvent(new Event('input'));
            row.querySelector('[data-row-qty]').value = option.dataset.balance || '0.000';
            row.querySelector('[data-row-qty]').dispatchEvent(new Event('input'));
            row.dataset.bundleRow = 'true';
            const productCell = row.querySelector('[data-row-group]')?.closest('label');
            if (productCell) {
                const toggle = document.createElement('span');
                toggle.className = 'bundle-dispatch-toggle';
                toggle.innerHTML = '<input type="checkbox" data-bundle-include checked> Dispatch this product';
                productCell.prepend(toggle);
                const checkbox = toggle.querySelector('[data-bundle-include]');
                const setIncluded = () => {
                    const included = checkbox.checked;
                    row.classList.toggle('bundle-row-excluded', !included);
                    row.querySelectorAll('select, input').forEach(field => {
                        if (field !== checkbox) field.disabled = !included;
                    });
                    total();
                    updateSaudaProgress();
                };
                checkbox.addEventListener('change', setIncluded);
                setIncluded();
            }
        });
        updateSaudaProgress();
    } else if (form.dataset.initialSauda) { sauda.value=form.dataset.initialSauda; sauda.dispatchEvent(new Event('change')); const row=rows.querySelector('[data-stock-row]'); if(row){row.querySelector('[data-row-group]').value=filter.value; row.querySelector('[data-row-group]').dispatchEvent(new Event('change')); row.querySelector('[data-row-product]').value=form.dataset.initialProduct; row.querySelector('[data-row-product]').dispatchEvent(new Event('change')); const globalBase=form.querySelector('[data-global-base-rate]'); if(globalBase) globalBase.value=form.dataset.initialRate; row.querySelector('[data-row-base]').value=form.dataset.initialRate; row.querySelector('[data-row-base]').dispatchEvent(new Event('input')); const balance=sauda.options[sauda.selectedIndex]?.dataset.balance; if(balance){row.querySelector('[data-row-qty]').value=balance; row.querySelector('[data-row-qty]').dispatchEvent(new Event('input'));}} }
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
        const globalBaseRate = reference.querySelector('[data-global-base-rate]');
        if (form.dataset.initialRate) globalBaseRate.value = form.dataset.initialRate;
        rows.querySelectorAll('[data-stock-row]').forEach(row => setRowBaseRate(row));
        globalBaseRate.addEventListener('input', event => rows.querySelectorAll('[data-stock-row]').forEach(row => setRowBaseRate(row, event.target.value)));
    }
    form.querySelectorAll('[data-charge], [data-gst]').forEach(input => input.addEventListener('input', total));
});

// Gate photos are converted in the browser before submit. This keeps uploads
// small and ensures the saved photo is always WebP. The original file is only
// sent as a safe fallback when browser conversion is unavailable.
document.querySelectorAll('[data-webp-upload]').forEach(input => {
    const form = input.closest('form');
    const output = input.parentElement.querySelector('[data-webp-output]');
    const status = input.parentElement.querySelector('[data-webp-status]');
    if (!form || !output) return;
    // Android respects camera capture more reliably with the broad image/* MIME
    // filter; the server still validates the final image type.
    input.accept = 'image/*';
    input.setAttribute('capture', 'environment');

    let conversion = null;
    const setStatus = message => { if (status) status.textContent = message; };
    const compress = () => {
        const file = input.files?.[0];
        output.value = '';
        input.disabled = false;
        if (!file) return Promise.resolve();
        setStatus('Preparing photo…');
        conversion = new Promise((resolve, reject) => {
            const reader = new FileReader();
            const image = new Image();
            reader.onerror = () => reject(new Error('Photo could not be read.'));
            image.onerror = () => reject(new Error('Photo format is not supported by this browser.'));
            image.onload = () => {
                const maximum = 1600;
                const scale = Math.min(1, maximum / Math.max(image.width, image.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(image.width * scale));
                canvas.height = Math.max(1, Math.round(image.height * scale));
                const context = canvas.getContext('2d');
                if (!context) { reject(new Error('Photo canvas is unavailable.')); return; }
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                const encodeBlob = blob => {
                    if (!blob || !blob.size) { reject(new Error('Compressed photo is empty.')); return; }
                    const compressedReader = new FileReader();
                    compressedReader.onerror = () => reject(new Error('Compressed photo could not be read.'));
                    compressedReader.onload = () => {
                        const dataUrl = String(compressedReader.result || '');
                        if (!/^data:image\/(webp|jpeg);base64,/.test(dataUrl)) { reject(new Error('Invalid compressed photo.')); return; }
                        output.value = dataUrl;
                        setStatus('Photo compressed successfully.');
                        resolve();
                    };
                    compressedReader.readAsDataURL(blob);
                };
                canvas.toBlob(blob => {
                    if (blob?.type === 'image/webp') {
                        encodeBlob(blob);
                        return;
                    }
                    // iOS Safari and in-app browsers can capture a photo but may not
                    // encode WebP. JPEG is universally supported and the server
                    // converts it to WebP after upload.
                    canvas.toBlob(encodeBlob, 'image/jpeg', 0.74);
                }, 'image/webp', 0.78);
            };
            reader.onload = event => { image.src = event.target.result; };
            reader.readAsDataURL(file);
        });
        return conversion;
    };

    input.addEventListener('change', () => { compress().catch(() => { output.value = ''; setStatus('Using original photo; server will compress it.'); }); });
    form.addEventListener('submit', async event => {
        if (!input.files?.length) return;
        event.preventDefault();
        try {
            await (conversion || compress());
            // The compressed WebP data is now submitted; do not also validate
            // the original phone-camera file on the server.
            if (/^data:image\/(webp|jpeg);base64,/.test(output.value)) input.disabled = true;
            form.submit();
        } catch (_) {
            // If conversion fails, submit the original valid image as fallback.
            input.disabled = false;
            output.value = '';
            setStatus('Using original photo; server will compress it.');
            form.submit();
        }
    }, { once: true });
});
