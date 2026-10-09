/**
 * Kasir Ibtidaiyah - POS JavaScript
 * Manajemen keranjang, pencarian, pembayaran, pending order, retur
 */

// =============================================
// STATE
// =============================================
let cart = [];
let selectedCustomer = null;
let currentCategory = 0;
let currentReturnSaleId = null;
const BASE = document.querySelector('meta[name="base-url"]')?.content || '';

// Product popup state
let _popupProduct = null;   // product being configured
let _popupCartIndex = null; // index if editing existing cart item

// =============================================
// INIT
// =============================================
document.addEventListener('DOMContentLoaded', function () {
    loadProducts();
    loadCategories();
    loadPendingCount();

    // Search with debounce
    const searchInput = document.getElementById('posSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function () {
            loadProducts(this.value, currentCategory);
        }, 300));
    }

    // Customer search
    const custInput = document.getElementById('customerSearch');
    if (custInput) {
        custInput.addEventListener('input', debounce(function () {
            searchCustomers(this.value);
        }, 300));
    }

    // Refresh pending count every 60 seconds
    setInterval(loadPendingCount, 60000);
});

// =============================================
// LOAD PRODUCTS
// =============================================
async function loadProducts(query = '', category = 0) {
    const grid = document.getElementById('productGrid');
    if (!grid) return;

    grid.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding:40px; color: var(--gray-400);"><div class="spinner" style="margin:0 auto 12px;"></div>Memuat produk...</div>';

    try {
        let priceType = selectedCustomer ? selectedCustomer.price_type_name : 'Umum';
        let url = `${BASE}/api/products.php?action=search&q=${encodeURIComponent(query)}&limit=50&price_type=${priceType}`;
        if (category) url += `&category=${category}`;

        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (data.success && data.data.length > 0) {
            grid.innerHTML = data.data.map(p => `
                <div class="pos-product-card ${p.is_out_of_stock ? 'out-of-stock' : ''}" 
                     onclick='addToCart(${JSON.stringify(p).replace(/'/g, "&#39;")})'>
                    <div class="pos-product-img">
                        ${(p.variation_image || p.image) ? `<img src="${BASE}/${p.variation_image || p.image}" alt="">` : `<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`}
                    </div>
                    <div class="pos-product-info">
                        <div class="pos-product-name">${escHtml(p.product_name)}${p.variation_name ? ' - ' + escHtml(p.variation_name) : ''}</div>
                        <div class="pos-product-price">${formatRupiah(p.selling_price)}</div>
                        ${p.stock_qty !== null ? `<div class="pos-product-stock ${p.stock_qty <= 5 ? 'low' : ''}">Stok: ${p.stock_qty}</div>` : `<div class="pos-product-stock" style="color: var(--primary-600); font-weight: 600;">Tersedia</div>`}
                    </div>
                </div>
            `).join('');
        } else {
            grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:60px; color: var(--gray-400);">Produk tidak ditemukan</div>';
        }
    } catch (err) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color: var(--danger);">Gagal memuat produk</div>';
        console.error(err);
    }
}

// =============================================
// LOAD CATEGORIES
// =============================================
async function loadCategories() {
    try {
        const res = await fetch(`${BASE}/api/products.php?action=categories`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        const container = document.getElementById('categoryTabs');
        if (!container || !data.success) return;

        let grouped = {};
        data.data.forEach(c => {
            if (!c.parent_id) {
                grouped[c.id] = c;
                grouped[c.id].subs = [];
            }
        });
        data.data.forEach(c => {
            if (c.parent_id && grouped[c.parent_id]) {
                grouped[c.parent_id].subs.push(c);
            }
        });

        let html = '<button class="pos-cat-btn active" onclick="filterCategory(0, this)">Semua</button>';
        Object.values(grouped).forEach(pCat => {
            if (pCat.subs.length > 0) {
                html += `
                <div class="cat-dropdown-wrapper" style="position:relative; display:inline-block;">
                    <button class="pos-cat-btn" onclick="handlePosCatClick(event, ${pCat.id}, this)">
                        ${escHtml(pCat.name)} ▾
                    </button>
                    <div class="cat-dropdown-menu">
                        ${pCat.subs.map(sub => `<div class="cat-dropdown-item" onclick="filterCategory(${sub.id}, this.parentNode.previousElementSibling); event.stopPropagation(); let menu = this.parentNode; menu.style.display='none'; setTimeout(()=>menu.style.display='', 300); document.querySelectorAll('.cat-dropdown-wrapper').forEach(w => w.classList.remove('pos-show-menu'));">${escHtml(sub.name)}</div>`).join('')}
                    </div>
                </div>
                `;
            } else {
                html += `<button class="pos-cat-btn" onclick="filterCategory(${pCat.id}, this)">${escHtml(pCat.name)}</button>`;
            }
        });
        container.innerHTML = html;

        if (!document.getElementById('posCatDropdownStyles')) {
            const style = document.createElement('style');
            style.id = 'posCatDropdownStyles';
            style.innerHTML = `
            .cat-dropdown-wrapper:hover .cat-dropdown-menu, .cat-dropdown-wrapper.pos-show-menu .cat-dropdown-menu { display: block; }
            .cat-dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; min-width: 150px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 8px; padding: 8px 0; z-index: 100; border: 1px solid var(--border-color); margin-top: 4px; text-align: left; }
            .cat-dropdown-item { padding: 8px 16px; font-size: 0.875rem; color: var(--gray-700); cursor: pointer; }
            .cat-dropdown-item:hover { background: var(--gray-100); color: var(--primary-600); }
            `;
            document.head.appendChild(style);
        }
    } catch (err) {
        console.error(err);
    }
}

window.handlePosCatClick = function (e, id, btn) {
    if (window.matchMedia("(hover: none)").matches || 'ontouchstart' in window) {
        let wrapper = btn.parentNode;
        let menu = wrapper.querySelector('.cat-dropdown-menu');
        if (!wrapper.classList.contains('pos-show-menu')) {
            e.preventDefault();
            document.querySelectorAll('.cat-dropdown-wrapper').forEach(w => {
                w.classList.remove('pos-show-menu');
                let m = w.querySelector('.cat-dropdown-menu');
                if (m) { m.style.position = ''; m.style.top = ''; m.style.left = ''; }
            });
            wrapper.classList.add('pos-show-menu');

            // Extract from overflow container using position: fixed on mobile
            if (menu && window.innerWidth <= 1024) {
                let rect = btn.getBoundingClientRect();
                menu.style.position = 'fixed';
                menu.style.top = (rect.bottom + 4) + 'px';

                // Keep menu inside viewport width
                let menuLeft = rect.left;
                if (menuLeft + 150 > window.innerWidth) {
                    menuLeft = window.innerWidth - 160;
                }
                menu.style.left = menuLeft + 'px';
            }
            return;
        }
    }
    filterCategory(id, btn);
};

document.addEventListener('click', function (e) {
    if (!e.target.closest('.cat-dropdown-wrapper')) {
        document.querySelectorAll('.cat-dropdown-wrapper').forEach(w => {
            w.classList.remove('pos-show-menu');
            let m = w.querySelector('.cat-dropdown-menu');
            if (m) { m.style.position = ''; m.style.top = ''; m.style.left = ''; }
        });
    }
});

function filterCategory(catId, btn) {
    currentCategory = catId;
    document.querySelectorAll('.pos-cat-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    loadProducts(document.getElementById('posSearch')?.value || '', catId);
}

// =============================================
// CART MANAGEMENT
// =============================================
// =============================================
// PRODUCT POPUP FLOW
// =============================================

/**
 * Step 1: Open price type selector popup
 */
function addToCart(product) {
    if (product.stock_qty !== null && product.stock_qty <= 0) return;

    _popupProduct = product;
    _popupCartIndex = null;

    const tiers = product.tiers || [];

    // Group tiers by price_type
    const typeMap = {};
    tiers.forEach(t => {
        if (!typeMap[t.price_type]) typeMap[t.price_type] = [];
        typeMap[t.price_type].push(t);
    });

    const typeNames = Object.keys(typeMap);

    // If only 1 price type (or no tiers), skip step-1 and go straight to step-2
    if (typeNames.length <= 1) {
        const defaultTier = tiers.length > 0 ? tiers[0] : null;
        const typeName = typeNames[0] || 'Default';
        openItemDetailPopup(product, typeName, defaultTier);
        return;
    }

    // Build popup HTML
    const rows = typeNames.map(typeName => {
        const typeTiers = typeMap[typeName];
        const tierBadges = typeTiers.map(t =>
            `<span class="price-tier-badge">Min @${t.min_qty} = ${formatRupiah(t.selling_price)}</span>`
        ).join('');
        return `
            <div class="price-type-row" onclick="selectPriceType('${escHtml(typeName)}')">
                <div class="price-type-row-inner">
                    <div class="price-type-name">${escHtml(typeName)}</div>
                    <div class="price-type-tiers">${tierBadges}</div>
                </div>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary-500)" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
        `;
    }).join('');

    document.getElementById('priceTypeModalBody').innerHTML = rows;
    document.getElementById('priceTypeModalProductName').textContent =
        product.product_name + (product.variation_name ? ' - ' + product.variation_name : '');
    openModal('modalPriceType');
}

/**
 * Called when user picks a price type from Step 1
 */
function selectPriceType(typeName) {
    closeModal('modalPriceType');
    const product = _popupProduct;
    if (!product) return;

    const tiers = (product.tiers || []).filter(t => t.price_type === typeName);
    const defaultTier = tiers.length > 0 ? tiers[0] : null;
    openItemDetailPopup(product, typeName, defaultTier);
}

/**
 * Step 2: Open item detail editor popup
 */
function openItemDetailPopup(product, selectedTypeName, defaultTier) {
    const tiers = (product.tiers || []).filter(t => t.price_type === selectedTypeName);
    const basePrice = defaultTier ? parseFloat(defaultTier.selling_price) : parseFloat(product.selling_price || product.base_price || 0);

    // Check if already in cart
    const existingIdx = cart.findIndex(item => item.variation_id == product.variation_id);
    let existingItem = existingIdx >= 0 ? cart[existingIdx] : null;

    const initQty = existingItem ? existingItem.qty : 1;
    const initPrice = existingItem ? existingItem.custom_price ?? basePrice : basePrice;
    const initDiscVal = existingItem ? (existingItem.item_discount_value ?? 0) : 0;
    const initDiscType = existingItem ? (existingItem.item_discount_type ?? 'persen') : 'persen';

    _popupCartIndex = existingIdx >= 0 ? existingIdx : null;
    _popupProduct = product;
    _popupProduct._selectedTypeName = selectedTypeName;
    _popupProduct._tiers = tiers;
    _popupProduct._basePrice = basePrice;

    const modal = document.getElementById('modalItemDetail');

    // Header
    modal.querySelector('#itemDetailProductName').textContent =
        product.product_name + (product.variation_name ? ' - ' + product.variation_name : '');
    modal.querySelector('#itemDetailPriceType').textContent = selectedTypeName;

    // Qty
    modal.querySelector('#itemDetailQty').value = initQty;

    // Tier info (auto price update based on qty)
    _renderTierHint(tiers, initQty);

    // Custom price
    const customPriceCheck = modal.querySelector('#itemCustomPriceCheck');
    const customPriceRow = modal.querySelector('#itemCustomPriceRow');
    const customPriceInput = modal.querySelector('#itemCustomPriceInput');
    const hasCustomPrice = existingItem && existingItem.custom_price != null;
    customPriceCheck.checked = hasCustomPrice;
    customPriceRow.style.display = hasCustomPrice ? 'block' : 'none';
    customPriceInput.value = hasCustomPrice ? initPrice : basePrice;

    // Discount
    const discountSection = modal.querySelector('#itemDiscountSection');
    const discountCheck = modal.querySelector('#itemDiscountCheck');
    const discountRow = modal.querySelector('#itemDiscountRow');

    if (discountSection) discountSection.style.display = 'block';
    discountCheck.checked = initDiscVal > 0;
    discountRow.style.display = initDiscVal > 0 ? 'block' : 'none';
    modal.querySelector('#itemDiscountValue').value = initDiscVal;
    // Set discount type toggle
    modal.querySelectorAll('.disc-type-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === initDiscType);
    });

    // Live price preview
    _updateItemDetailPreview();

    openModal('modalItemDetail');

    // Focus qty input
    setTimeout(() => modal.querySelector('#itemDetailQty').select(), 200);
}

/** Render tier hint text based on qty */
function _renderTierHint(tiers, qty) {
    const hintEl = document.getElementById('itemDetailTierHint');
    if (!hintEl) return;
    if (!tiers || tiers.length === 0) { hintEl.innerHTML = ''; return; }

    let activeTier = tiers[0];
    for (const t of tiers) {
        if (qty >= t.min_qty) activeTier = t;
    }

    const badges = tiers.map(t => {
        const isActive = (t === activeTier);
        return `<span class="tier-hint-badge ${isActive ? 'active' : ''}">${t.min_qty > 1 ? 'Min ' + t.min_qty : 'Eceran'}: ${formatRupiah(t.selling_price)}</span>`;
    }).join('');
    hintEl.innerHTML = badges;
}

/** Recalculate and show live price in item detail popup */
function _updateItemDetailPreview() {
    const modal = document.getElementById('modalItemDetail');
    if (!modal) return;

    const tiers = _popupProduct?._tiers || [];
    const qty = parseInt(modal.querySelector('#itemDetailQty').value) || 1;

    // Determine base price from tier or custom
    let basePrice = _popupProduct?._basePrice || 0;
    let activeTier = tiers.length > 0 ? tiers[0] : null;
    for (const t of tiers) {
        if (qty >= t.min_qty) activeTier = t;
    }
    if (activeTier) basePrice = parseFloat(activeTier.selling_price);

    // Update tier hint
    _renderTierHint(tiers, qty);

    // Custom price override
    const useCustom = modal.querySelector('#itemCustomPriceCheck').checked;
    let unitPrice = useCustom ? (parseFloat(modal.querySelector('#itemCustomPriceInput').value) || basePrice) : basePrice;

    // Discount
    const discVal = parseFloat(modal.querySelector('#itemDiscountValue').value) || 0;
    const discType = modal.querySelector('.disc-type-btn.active')?.dataset.type || 'persen';
    let discAmount = 0;
    if (discType === 'persen') {
        discAmount = unitPrice * qty * Math.min(100, discVal) / 100;
    } else {
        discAmount = Math.min(discVal, unitPrice * qty);
    }

    const subtotal = Math.max(0, unitPrice * qty - discAmount);

    const previewEl = modal.querySelector('#itemDetailPreview');
    if (previewEl) {
        const discLine = discVal > 0
            ? `<span class="item-detail-disc">- Diskon: ${formatRupiah(discAmount)}</span>`
            : '';
        previewEl.innerHTML = `
            <span class="item-detail-unit">${formatRupiah(unitPrice)} × ${qty}</span>
            ${discLine}
            <strong class="item-detail-total">${formatRupiah(subtotal)}</strong>
        `;
    }

    // Also update custom price placeholder when not overriding
    if (!useCustom) {
        modal.querySelector('#itemCustomPriceInput').value = basePrice;
        modal.querySelector('#itemCustomPriceInput').placeholder = formatRupiah(basePrice);
    }
}

/** Toggle custom price row */
function toggleItemCustomPrice(checked) {
    const row = document.getElementById('itemCustomPriceRow');
    row.style.display = checked ? 'block' : 'none';
    _updateItemDetailPreview();
    if (checked) {
        setTimeout(() => document.getElementById('itemCustomPriceInput').select(), 100);
    }
}

/** Toggle discount row */
function toggleItemDiscount(checked) {
    const row = document.getElementById('itemDiscountRow');
    row.style.display = checked ? 'block' : 'none';
    if (!checked) document.getElementById('itemDiscountValue').value = 0;
    _updateItemDetailPreview();
    if (checked) {
        setTimeout(() => document.getElementById('itemDiscountValue').select(), 100);
    }
}

/** Switch discount type (% or Rp) */
function setDiscountType(type) {
    document.querySelectorAll('#modalItemDetail .disc-type-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === type);
    });
    _updateItemDetailPreview();
}

/** Confirm item detail and add/update cart */
function confirmItemDetail() {
    const modal = document.getElementById('modalItemDetail');
    const product = _popupProduct;
    if (!product) return;

    const tiers = product._tiers || [];
    const qty = parseInt(modal.querySelector('#itemDetailQty').value) || 1;
    const useCustom = modal.querySelector('#itemCustomPriceCheck').checked;
    const discVal = parseFloat(modal.querySelector('#itemDiscountValue').value) || 0;
    const discType = modal.querySelector('.disc-type-btn.active')?.dataset.type || 'persen';
    const useDisc = modal.querySelector('#itemDiscountCheck').checked;

    // Validate stock
    if (product.stock_qty !== null && qty > product.stock_qty) {
        showToast(`Stok tidak mencukupi! Maks: ${product.stock_qty}`, 'warning');
        return;
    }
    if (qty <= 0) {
        showToast('Jumlah harus lebih dari 0', 'warning');
        return;
    }

    // Determine base price from tier
    let basePrice = product._basePrice || 0;
    let activeTier = tiers.length > 0 ? tiers[0] : null;
    for (const t of tiers) {
        if (qty >= t.min_qty) activeTier = t;
    }
    if (activeTier) basePrice = parseFloat(activeTier.selling_price);
    const tierLabel = activeTier?.label || '';

    const unitPrice = useCustom
        ? (parseFloat(modal.querySelector('#itemCustomPriceInput').value) || basePrice)
        : basePrice;

    const customPrice = useCustom ? unitPrice : null;
    const itemDiscVal = useDisc ? discVal : 0;
    const itemDiscType = discType;

    if (_popupCartIndex !== null && cart[_popupCartIndex]) {
        // Update existing
        const item = cart[_popupCartIndex];
        item.qty = qty;
        item.unit_price = unitPrice;
        item.custom_price = customPrice;
        item.tier_label = tierLabel;
        item.item_discount_value = itemDiscVal;
        item.item_discount_type = itemDiscType;
    } else {
        // Add new
        const existing = cart.find(i => i.variation_id == product.variation_id);
        if (existing) {
            existing.qty += qty;
            existing.unit_price = unitPrice;
            existing.custom_price = customPrice;
            existing.tier_label = tierLabel;
            existing.item_discount_value = itemDiscVal;
            existing.item_discount_type = itemDiscType;
            // Re-clamp qty
            if (product.stock_qty !== null && existing.qty > product.stock_qty) {
                existing.qty = product.stock_qty;
            }
        } else {
            cart.push({
                variation_id: product.variation_id,
                product_name: product.product_name,
                variation_name: product.variation_name,
                stock_qty: product.stock_qty,
                tiers: product.tiers || [],
                qty,
                unit_price: unitPrice,
                custom_price: customPrice,
                tier_label: tierLabel,
                item_discount_value: itemDiscVal,
                item_discount_type: itemDiscType,
            });
        }
    }

    closeModal('modalItemDetail');
    renderCart();
    showToast(`${product.product_name} ditambahkan ke keranjang`, 'success');
}

/**
 * Open item detail popup from cart item (edit mode)
 */
function openEditCartItem(index) {
    const item = cart[index];
    if (!item) return;

    _popupCartIndex = index;

    // Build a fake product object
    const product = {
        variation_id: item.variation_id,
        product_name: item.product_name,
        variation_name: item.variation_name,
        stock_qty: item.stock_qty,
        tiers: item.tiers || [],
        selling_price: item.unit_price,
        base_price: item.unit_price,
        _selectedTypeName: selectedCustomer?.price_type_name || 'Umum',
        _tiers: item.tiers || [],
        _basePrice: item.unit_price,
    };

    _popupProduct = product;

    // Reuse openItemDetailPopup
    const typeName = selectedCustomer?.price_type_name || 'Umum';
    openItemDetailPopup(product, typeName, null);
}

function _internalAddToCart(product) {
    // Legacy direct add (used internally when needed)
    if (product.stock_qty !== null && product.stock_qty <= 0) return;

    const existing = cart.find(item => item.variation_id == product.variation_id);

    if (existing) {
        if (product.stock_qty !== null && existing.qty >= product.stock_qty) {
            showToast('Stok tidak mencukupi!', 'warning');
            return;
        }
        existing.qty++;
        updateItemPrice(existing, product.tiers);
    } else {
        const item = {
            variation_id: product.variation_id,
            product_name: product.product_name,
            variation_name: product.variation_name,
            stock_qty: product.stock_qty,
            tiers: product.tiers || [],
            qty: 1,
            unit_price: parseFloat(product.selling_price),
            tier_label: '',
            item_discount_value: 0,
            item_discount_type: 'persen',
        };
        updateItemPrice(item, item.tiers);
        cart.push(item);
    }

    renderCart();
}

function updateItemPrice(item, tiers) {
    // Skip items whose price was manually set via the item-detail popup
    if (item.custom_price != null) return;
    if (!tiers || tiers.length === 0) return;

    // Determine target price_type
    const targetType = selectedCustomer ? selectedCustomer.price_type_name : 'Umum';

    // Filter tiers matching the target type, fallback to Umum if not found
    let availableTiers = tiers.filter(t => t.price_type === targetType);
    if (availableTiers.length === 0 && targetType !== 'Umum') {
        availableTiers = tiers.filter(t => t.price_type === 'Umum');
    }

    let applicableTier = availableTiers.length > 0 ? availableTiers[0] : (tiers[0] || {});

    for (const tier of availableTiers) {
        if (item.qty >= tier.min_qty) {
            applicableTier = tier;
        }
    }

    item.unit_price = parseFloat(applicableTier.selling_price);
    item.tier_label = applicableTier.label || '';
}

function updateQty(index, delta) {
    const item = cart[index];
    if (!item) return;

    const newQty = item.qty + delta;

    if (newQty <= 0) {
        cart.splice(index, 1);
    } else if (item.stock_qty !== null && newQty > item.stock_qty) {
        showToast('Stok tidak mencukupi!', 'warning');
        return;
    } else {
        item.qty = newQty;
        updateItemPrice(item, item.tiers);
    }

    renderCart();
}

function setQty(index, qty) {
    const item = cart[index];
    if (!item) return;

    qty = parseInt(qty) || 0;

    if (qty <= 0) {
        cart.splice(index, 1);
    } else if (item.stock_qty !== null && qty > item.stock_qty) {
        item.qty = item.stock_qty;
        showToast('Dibatasi oleh stok tersedia', 'warning');
    } else {
        item.qty = qty;
    }

    updateItemPrice(item, item.tiers);
    renderCart();
}

function removeItem(index) {
    cart.splice(index, 1);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) return;
    confirmAction('Kosongkan keranjang?', () => {
        cart = [];
        renderCart();
    });
}

function getCartTotal() {
    return cart.reduce((sum, item) => {
        const subtotal = item.unit_price * item.qty;
        const discVal = item.item_discount_value || 0;
        const discType = item.item_discount_type || 'persen';
        let discAmount = 0;
        if (discVal > 0) {
            if (discType === 'persen') {
                discAmount = subtotal * Math.min(100, discVal) / 100;
            } else {
                discAmount = Math.min(discVal, subtotal);
            }
        }
        return sum + Math.max(0, subtotal - discAmount);
    }, 0);
}

function getCartItemCount() {
    return cart.reduce((sum, item) => sum + item.qty, 0);
}

// =============================================
// RENDER CART
// =============================================
function renderCart() {
    const container = document.getElementById('cartItems');
    const countEl = document.getElementById('cartCount');
    const totalEl = document.getElementById('cartTotal');
    const payBtnArea = document.getElementById('payBtnArea');
    const floatingCount = document.getElementById('floatingCartCount');

    let currentCount = getCartItemCount();
    if (countEl) countEl.textContent = currentCount;
    if (floatingCount) floatingCount.textContent = currentCount;
    if (totalEl) totalEl.textContent = formatRupiah(getCartTotal());

    if (!container) return;

    if (cart.length === 0) {
        container.innerHTML = `
            <div class="pos-cart-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                <div style="font-weight:600; margin-top:4px;">Keranjang Kosong</div>
                <div style="font-size:0.8125rem;">Klik produk untuk menambahkan</div>
            </div>
        `;
        if (payBtnArea) payBtnArea.style.display = 'none';
        return;
    }

    if (payBtnArea) payBtnArea.style.display = 'flex';

    container.innerHTML = cart.map((item, i) => {
        const rawSubtotal = item.unit_price * item.qty;
        const discVal = item.item_discount_value || 0;
        const discType = item.item_discount_type || 'persen';
        let discAmount = 0;
        if (discVal > 0) {
            discAmount = discType === 'persen'
                ? rawSubtotal * Math.min(100, discVal) / 100
                : Math.min(discVal, rawSubtotal);
        }
        const subtotal = Math.max(0, rawSubtotal - discAmount);
        const discBadge = discVal > 0
            ? `<span class="item-disc-badge">-${discType === 'persen' ? discVal + '%' : formatRupiah(discVal)}</span>`
            : '';
        const customBadge = item.custom_price != null
            ? `<span class="item-custom-badge">Custom</span>`
            : '';
        return `
            <div class="pos-cart-item" onclick="openEditCartItem(${i})" style="cursor:pointer;">
                <div class="pos-cart-item-info">
                    <div class="pos-cart-item-name">${escHtml(item.product_name)}</div>
                    ${item.variation_name ? `<div class="pos-cart-item-variant">${escHtml(item.variation_name)}</div>` : ''}
                    <div class="pos-cart-item-price">
                        ${formatRupiah(item.unit_price)}
                        ${item.tier_label && item.tier_label !== 'Eceran' ? `<span class="tier-label">${escHtml(item.tier_label)}</span>` : ''}
                        ${customBadge}
                    </div>
                    ${discVal > 0 ? `<div class="pos-cart-item-disc">Diskon: ${discType === 'persen' ? discVal + '%' : formatRupiah(discVal)} = -${formatRupiah(discAmount)}</div>` : ''}
                </div>
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;" onclick="event.stopPropagation()">
                    <button class="pos-cart-item-remove" onclick="removeItem(${i})" title="Hapus">&times;</button>
                    <div class="pos-cart-qty">
                        <button onclick="updateQty(${i}, -1)">−</button>
                        <input type="number" value="${item.qty}" min="1" max="${item.stock_qty ?? 9999}" 
                               onchange="setQty(${i}, this.value)" onclick="this.select()">
                        <button onclick="updateQty(${i}, 1)">+</button>
                    </div>
                    <div class="pos-cart-item-subtotal">${formatRupiah(subtotal)}</div>
                </div>
            </div>
        `;
    }).join('');
}

// =============================================
// CUSTOMER SEARCH
// =============================================
async function searchCustomers(query) {
    const list = document.getElementById('customerList');
    if (!list || !query || query.length < 2) {
        if (list) {
            list.style.display = 'none';
            list.classList.remove('active');
        }
        return;
    }

    try {
        const res = await fetch(`${BASE}/api/products.php?action=customers&q=${encodeURIComponent(query)}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (data.success && data.data.length > 0) {
            list.innerHTML = data.data.map(c => `
                <div class="dropdown-item" onclick="selectCustomer(${c.id}, '${escHtml(c.name)}', '${escHtml(c.price_type_name || 'Umum')}')">
                    <span>${escHtml(c.name)}</span>
                    <span class="text-xs text-muted">${c.phone || ''}</span>
                </div>
            `).join('');
            list.style.display = 'block';
            list.classList.add('active');
        } else {
            list.style.display = 'none';
            list.classList.remove('active');
        }
    } catch (err) {
        console.error(err);
    }
}

async function selectCustomer(id, name, priceTypeName = '') {
    if (!priceTypeName) {
        try {
            const res = await fetch(`${BASE}/api/products.php?action=customers&q=${encodeURIComponent(name)}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            if (data.success && data.data.length > 0) {
                const match = data.data.find(c => c.id == id);
                if (match) priceTypeName = match.price_type_name;
            }
        } catch (err) { console.error(err); }
    }
    if (!priceTypeName) priceTypeName = 'Umum';

    selectedCustomer = { id, name, price_type_name: priceTypeName };
    document.getElementById('customerSearch').value = name;
    document.getElementById('customerList').style.display = 'none';
    document.getElementById('customerList').classList.remove('active');
    document.getElementById('selectedCustomerId').value = id;

    // Hanya reload produk untuk menampilkan harga sesuai tipe pelanggan.
    // Harga item di keranjang TIDAK diubah otomatis karena harga sudah
    // dipilih secara manual per item melalui popup tipe harga.
    renderCart();
    loadProducts(document.getElementById('posSearch')?.value || '', currentCategory);
}

function clearCustomer() {
    selectedCustomer = null;
    document.getElementById('customerSearch').value = '';
    document.getElementById('selectedCustomerId').value = '';
    document.getElementById('customerList').style.display = 'none';
    document.getElementById('customerList').classList.remove('active');

    // Reload produk saja, harga keranjang tidak diubah
    renderCart();
    loadProducts(document.getElementById('posSearch')?.value || '', currentCategory);
}

// =============================================
// PAYMENT
// =============================================
function openPayment() {
    if (cart.length === 0) {
        showToast('Keranjang masih kosong!', 'warning');
        return;
    }

    const total = getCartTotal();
    // Reset diskon
    const discountEl = document.getElementById('paymentDiscount');
    if (discountEl) discountEl.value = 0;
    // Reset tambahan
    const feeEl = document.getElementById('paymentAdditionalFee');
    if (feeEl) feeEl.value = 0;
    // Reset tipe diskon ke persen
    document.querySelectorAll('#modalPayment .pay-disc-type-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === 'persen');
    });
    document.getElementById('paymentTotal').textContent = formatRupiah(total);
    document.getElementById('paymentAmount').value = new Intl.NumberFormat('id-ID').format(total);
    document.getElementById('paymentAmount').dataset.raw = total;
    calculateChange();

    openModal('modalPayment');
    setTimeout(() => document.getElementById('paymentAmount').select(), 200);
}

function generateQuickAmounts(total) {
    const container = document.getElementById('quickAmounts');
    if (!container) return;

    const amounts = [];
    // Exact amount
    amounts.push(total);
    // Round up to nearest 5000, 10000, 50000, 100000
    [5000, 10000, 20000, 50000, 100000].forEach(step => {
        const rounded = Math.ceil(total / step) * step;
        if (rounded > total && !amounts.includes(rounded)) {
            amounts.push(rounded);
        }
    });

    container.innerHTML = amounts.slice(0, 4).map(a =>
        `<button class="quick-amount-btn" onclick="setPaymentAmount(${a})">${formatRupiah(a)}</button>`
    ).join('');
}

function setPaymentAmount(amount) {
    document.getElementById('paymentAmount').value = new Intl.NumberFormat('id-ID').format(amount);
    document.getElementById('paymentAmount').dataset.raw = amount;
    calculateChange();
}

function onPaymentInput(input) {
    let val = input.value.replace(/[^\d]/g, '');
    input.dataset.raw = val;
    input.value = val ? new Intl.NumberFormat('id-ID').format(val) : '';
    calculateChange();
}

function calculateChange() {
    const total = getCartTotal();
    const discountEl = document.getElementById('paymentDiscount');
    const discValRaw = parseFloat(discountEl?.value) || 0;
    const discType = document.querySelector('#modalPayment .pay-disc-type-btn.active')?.dataset.type || 'persen';

    const feeEl = document.getElementById('paymentAdditionalFee');
    const additionalFee = parseFloat(feeEl?.value) || 0;

    let discountAmount = 0;
    if (discType === 'persen') {
        const pct = Math.max(0, Math.min(100, discValRaw));
        discountAmount = total * pct / 100;
    } else {
        discountAmount = Math.min(discValRaw, total);
    }
    const grandTotal = Math.max(0, total - discountAmount + additionalFee);

    document.getElementById('paymentTotal').textContent = formatRupiah(grandTotal);
    generateQuickAmounts(grandTotal);

    const paid = parseInt(document.getElementById('paymentAmount').dataset.raw) || 0;
    const change = paid - grandTotal;

    const changeEl = document.getElementById('paymentChange');
    if (changeEl) {
        changeEl.textContent = formatRupiah(Math.max(0, change));
        changeEl.style.color = change >= 0 ? 'var(--success)' : 'var(--danger)';
    }
}

/** Switch payment discount type (% or Rp) */
function setPayDiscountType(type) {
    document.querySelectorAll('#modalPayment .pay-disc-type-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === type);
    });
    calculateChange();
}

function selectPaymentMethod(method, btn) {
    document.querySelectorAll('.payment-method-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('selectedMethod').value = method;

    // Show/hide kasbon options
    const kasbonSection = document.getElementById('kasbonSection');
    if (kasbonSection) {
        kasbonSection.style.display = method === 'Kasbon' ? 'block' : 'none';
    }
}

// =============================================
// SUBMIT TRANSACTION
// =============================================
async function submitPayment() {
    const total = getCartTotal();
    const discountEl = document.getElementById('paymentDiscount');
    const discValRaw = parseFloat(discountEl?.value) || 0;
    const discType = document.querySelector('#modalPayment .pay-disc-type-btn.active')?.dataset.type || 'persen';

    const feeEl = document.getElementById('paymentAdditionalFee');
    const additionalFee = parseFloat(feeEl?.value) || 0;

    let discountAmount = 0;
    if (discType === 'persen') {
        discountAmount = total * Math.max(0, Math.min(100, discValRaw)) / 100;
    } else {
        discountAmount = Math.min(discValRaw, total);
    }
    const grandTotal = Math.max(0, total - discountAmount + additionalFee);
    const discountPercent = total > 0 ? (discountAmount / total) * 100 : 0;

    const method = document.getElementById('selectedMethod').value;
    const paid = parseInt(document.getElementById('paymentAmount').dataset.raw) || 0;
    const isDebt = method === 'Kasbon';

    if (!isDebt && paid < grandTotal) {
        showToast('Jumlah bayar kurang dari total!', 'error');
        return;
    }

    if (isDebt && !selectedCustomer) {
        showToast('Kasbon harus memilih pelanggan!', 'error');
        return;
    }

    const payBtn = document.getElementById('submitPaymentBtn');
    payBtn.disabled = true;
    payBtn.innerHTML = '<div class="spinner" style="width:20px;height:20px;margin:0 auto;"></div>';

    try {
        const payload = {
            action: 'create_sale',
            items: cart.map(item => ({
                variation_id: item.variation_id,
                qty: item.qty,
                custom_price: item.custom_price ?? null,
                item_discount_value: item.item_discount_value || 0,
                item_discount_type: item.item_discount_type || 'persen',
            })),
            customer_id: selectedCustomer?.id || null,
            customer_name: document.getElementById('customerSearch').value.trim() || null,
            sale_source: 'POS',
            payment_method: isDebt ? 'Tunai' : method,
            paid_amount: paid,
            discount_percent: discountEl ? discountPercent : 0,
            additional_fee: additionalFee,
            is_debt: isDebt,
            notes: '',
        };

        const res = await fetch(`${BASE}/api/sales.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });

        const data = await res.json();

        if (data.success) {
            closeModal('modalPayment');
            showReceipt(data.data);
            cart = [];
            clearCustomer();
            renderCart();
            loadProducts();
            showToast('Transaksi berhasil! ' + data.data.invoice_number, 'success');
        } else {
            showToast(data.message || 'Gagal memproses transaksi', 'error');
        }
    } catch (err) {
        showToast('Terjadi kesalahan jaringan', 'error');
        console.error(err);
    } finally {
        payBtn.disabled = false;
        payBtn.innerHTML = 'Proses Pembayaran';
    }
}

// =============================================
// PENDING ORDER
// =============================================
function openSavePendingModal() {
    if (cart.length === 0) {
        showToast('Keranjang masih kosong!', 'warning');
        return;
    }
    document.getElementById('pendingLabel').value = selectedCustomer ? selectedCustomer.name : '';
    openModal('modalSavePending');
    setTimeout(() => document.getElementById('pendingLabel').focus(), 200);
}

async function savePendingOrder() {
    const label = document.getElementById('pendingLabel').value.trim();
    if (!label) {
        showToast('Nama pelanggan / nomor meja harus diisi!', 'warning');
        return;
    }

    const btn = document.getElementById('btnSavePending');
    btn.disabled = true;
    btn.textContent = 'Menyimpan...';

    try {
        const res = await fetch(`${BASE}/api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                action: 'save_pending',
                items: cart.map(item => ({ variation_id: item.variation_id, qty: item.qty })),
                pending_label: label,
                customer_name: label,
                customer_id: selectedCustomer?.id || null,
            }),
        });
        const data = await res.json();

        if (data.success) {
            closeModal('modalSavePending');
            cart = [];
            clearCustomer();
            renderCart();
            loadPendingCount();
            showToast(`Pesanan "${label}" berhasil disimpan!`, 'success');
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast('Terjadi kesalahan', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Simpan Pesanan';
    }
}

async function loadPendingCount() {
    try {
        const res = await fetch(`${BASE}/api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'list_pending' }),
        });
        const data = await res.json();
        const badge = document.getElementById('pendingBadge');
        if (badge && data.success) {
            const count = data.data.length;
            badge.textContent = count;
            badge.style.display = count > 0 ? 'flex' : 'none';
        }
    } catch (err) { /* silent */ }
}

async function openPendingList() {
    openModal('modalPendingList');
    const body = document.getElementById('pendingListBody');
    body.innerHTML = '<div style="text-align:center; padding:40px; color:var(--gray-400);"><div class="spinner" style="margin:0 auto 12px;"></div>Memuat...</div>';

    try {
        const res = await fetch(`${BASE}/api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'list_pending' }),
        });
        const data = await res.json();

        if (data.success && data.data.length > 0) {
            body.innerHTML = data.data.map(order => `
                <div class="pending-order-card">
                    <div class="pending-order-info">
                        <div class="pending-order-label">${escHtml(order.pending_label || order.customer_name || 'Tanpa Nama')}</div>
                        <div class="pending-order-meta">
                            <span>${order.invoice_number}</span>
                            <span>•</span>
                            <span>${order.item_count} item</span>
                            <span>•</span>
                            <span>${formatRupiah(order.grand_total)}</span>
                        </div>
                        <div class="pending-order-time">${new Date(order.created_at).toLocaleString('id-ID')}</div>
                    </div>
                    <div class="pending-order-actions" style="display: flex; gap: 4px;">
                        <button class="btn btn-sm btn-outline btn-icon" title="Edit Pesanan" onclick="resumePendingOrder(${order.id})">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn btn-sm btn-primary btn-icon" title="Lanjutkan" onclick="resumePendingOrder(${order.id})">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                        </button>
                        <button class="btn btn-sm btn-outline btn-icon" title="Hapus" style="color:var(--danger); border-color:var(--danger);" onclick="deletePendingOrder(${order.id})">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        </button>
                    </div>
                </div>
            `).join('');
        } else {
            body.innerHTML = '<div style="text-align:center; padding:40px; color:var(--gray-400);">Tidak ada pesanan tertunda</div>';
        }
    } catch (err) {
        body.innerHTML = '<div style="text-align:center; padding:40px; color:var(--danger);">Gagal memuat data</div>';
    }
}

async function resumePendingOrder(saleId) {
    const processResume = async () => {
        try {
            const res = await fetch(`${BASE}/api/sales.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'resume_pending', sale_id: saleId }),
            });
            const data = await res.json();

            if (data.success) {
                // Load items into cart
                cart = data.data.items.map(item => ({
                    variation_id: item.variation_id,
                    product_name: item.product_name,
                    variation_name: item.variation_name,
                    stock_qty: item.stock_qty,
                    tiers: item.tiers || [],
                    qty: item.qty,
                    unit_price: parseFloat(item.unit_price),
                    tier_label: '',
                }));

                // Update prices based on tiers
                cart.forEach(item => updateItemPrice(item, item.tiers));

                // Set customer if exists
                if (data.data.customer_id) {
                    selectCustomer(data.data.customer_id, data.data.customer_name || '');
                }

                // Delete the pending order from DB
                await fetch(`${BASE}/api/sales.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'delete_pending', sale_id: saleId }),
                });

                renderCart();
                closeModal('modalPendingList');
                loadPendingCount();
                showToast('Pesanan dimuat ke keranjang', 'success');
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Gagal memuat pesanan', 'error');
        }
    };

    if (cart.length > 0) {
        confirmAction('Keranjang saat ini tidak kosong. Mengganti dengan pesanan tertunda?', processResume);
    } else {
        processResume();
    }
}

async function deletePendingOrder(saleId) {
    confirmAction('Yakin hapus pesanan ini?', async () => {
        try {
            const res = await fetch(`${BASE}/api/sales.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'delete_pending', sale_id: saleId }),
            });
            const data = await res.json();

            if (data.success) {
                showToast('Pesanan dihapus', 'success');
                openPendingList(); // Refresh list
                loadPendingCount();
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Gagal menghapus', 'error');
        }
    });
}

// =============================================
// RETURN / RETUR
// =============================================
function openReturnModal() {
    currentReturnSaleId = null;
    document.getElementById('returnInvoiceSearch').value = '';
    document.getElementById('returnFormArea').style.display = 'none';
    document.getElementById('returnFooter').style.display = 'none';
    openModal('modalReturn');
    setTimeout(() => document.getElementById('returnInvoiceSearch').focus(), 200);
}

async function searchInvoiceForReturn() {
    const invoiceNo = document.getElementById('returnInvoiceSearch').value.trim();
    if (!invoiceNo) {
        showToast('Masukkan nomor invoice!', 'warning');
        return;
    }

    try {
        const res = await fetch(`../api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'search_invoice', invoice_number: invoiceNo }),
        });
        const data = await res.json();

        if (data.success) {
            currentReturnSaleId = data.data.sale.id;
            const sale = data.data.sale;
            const items = data.data.items;

            // Show invoice info (Simpler layout)
            document.getElementById('returnInvoiceInfo').innerHTML = `
                <div style="background:#f8fafc; padding:12px 16px; border-radius:8px; display:flex; gap:16px; margin-bottom:20px; align-items:center; flex-wrap:wrap; font-size:0.95rem;">
                    <div><strong>Invoice:</strong> ${escHtml(sale.invoice_number)}</div>
                    <div><strong>Tgl:</strong> ${new Date(sale.created_at).toLocaleDateString('id-ID')}</div>
                    <div><strong>Total Beli:</strong> ${formatRupiah(sale.grand_total)}</div>
                </div>
            `;

            // Show items list (Simpler layout)
            document.getElementById('returnItemsList').innerHTML = `
                <div style="font-weight:600; color:var(--gray-800); margin-bottom:12px;">Pilih item yang diretur:</div>
                ${items.map(item => `
                    <div style="background:#fff; border:1px solid var(--gray-200); border-radius:8px; padding:12px 16px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div>
                            <div style="font-weight:600; color:var(--gray-800); font-size:0.95rem; margin-bottom:4px;">${escHtml(item.product_name)} ${item.variation_name ? `(${escHtml(item.variation_name)})` : ''}</div>
                            <div style="font-size:0.85rem; color:var(--gray-500);">Harga: ${formatRupiah(item.unit_price)} | Maks Retur: ${item.qty_returnable}</div>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="number" class="form-control return-qty-input" min="0" max="${item.qty_returnable}" value="0" 
                                   style="width:70px; text-align:center;"
                                   data-detail-id="${item.sale_detail_id}" data-price="${item.unit_price}" data-max="${item.qty_returnable}"
                                   ${item.qty_returnable <= 0 ? 'disabled' : ''}>
                            <select class="form-control return-condition-select" style="width:110px;" data-detail-id="${item.sale_detail_id}" ${item.qty_returnable <= 0 ? 'disabled' : ''}>
                                <option value="Bagus">Bagus</option>
                                <option value="Rusak">Rusak</option>
                            </select>
                        </div>
                    </div>
                `).join('')}
            `;

            document.getElementById('returnFormArea').style.display = 'block';
            document.getElementById('returnFooter').style.display = 'flex';
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast('Gagal mencari invoice', 'error');
    }
}

function updateReturnTotal() {
    const inputs = document.querySelectorAll('.return-qty-input');
    let total = 0;
    inputs.forEach(input => {
        const qty = parseInt(input.value) || 0;
        const price = parseFloat(input.dataset.price) || 0;
        total += qty * price;
    });
    document.getElementById('returnTotalAmount').textContent = formatRupiah(total);
}

async function submitReturn() {
    const reason = document.getElementById('returnReason').value.trim();
    if (!reason) {
        alert('Alasan retur harus diisi!');
        return;
    }

    const items = [];
    document.querySelectorAll('.return-qty-input').forEach(input => {
        const qty = parseInt(input.value) || 0;
        if (qty > 0) {
            const detailId = input.dataset.detailId;
            const conditionSelect = document.querySelector(`.return-condition-select[data-detail-id="${detailId}"]`);
            items.push({
                sale_detail_id: parseInt(detailId),
                qty: qty,
                condition: conditionSelect ? conditionSelect.value : 'Bagus',
            });
        }
    });

    if (items.length === 0) {
        showToast('Pilih minimal satu item untuk diretur!', 'warning');
        return;
    }

    const btn = document.getElementById('btnSubmitReturn');
    btn.disabled = true;
    btn.textContent = 'Memproses...';

    try {
        const res = await fetch(`../api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                action: 'create_return',
                sale_id: currentReturnSaleId,
                reason: reason,
                return_type: 'Refund',
                payment_method: 'Tunai',
                items: items,
            }),
        });
        const data = await res.json();

        if (data.success) {
            closeModal('modalReturn');
            loadProducts(); // Refresh stock display
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast('Gagal memproses retur', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Proses Retur';
    }
}

// =============================================
// RECEIPT
// =============================================

// Menyimpan data resi terakhir untuk digunakan tombol Cetak Bluetooth
let _lastReceiptData = null;

function showReceipt(data) {
    _lastReceiptData = data; // Simpan data untuk Bluetooth print
    const receiptHtml = generateReceiptHTML(data);
    document.getElementById('receiptContent').innerHTML = receiptHtml;
    openModal('modalReceipt');
}

async function printBluetoothReceipt() {
    if (!_lastReceiptData) {
        showToast('Data resi tidak tersedia.', 'error');
        return;
    }
    if (!navigator.bluetooth) {
        showToast('Browser Anda tidak mendukung Web Bluetooth API. Gunakan Google Chrome di PC/Android.', 'error');
        return;
    }
    const btn = document.querySelector('[onclick="printBluetoothReceipt()"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Menghubungkan...'; }
    try {
        await window.btPrinter.printReceipt(_lastReceiptData);
        showToast('Struk berhasil dikirim ke printer Bluetooth!', 'success');
    } catch (err) {
        console.error(err);
        showToast('Gagal cetak Bluetooth: ' + (err.message || err), 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/><path d="M12 2L6 8l6 6v8l6-6-6-6V2z"/></svg> Cetak BT'; }
    }
}

function generateReceiptHTML(data) {
    return generateReceiptPrintHTML(data);
}

// CSS struk thermal 58mm (Classic)
function getReceiptCSS() {
    return `
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page { margin: 0; size: 58mm auto; }
        html, body {
            width: 58mm;
            max-width: 58mm;
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            padding: 4mm 0 8mm;
        }
        .receipt-preview {
            width: 100%;
            max-width: 280px;
            margin: 0 auto;
            padding: 14px;
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 10px;
        }
        .receipt-header img {
            display: block;
            margin: 0 auto 4px;
            max-height: 48px;
            max-width: 48mm;
            object-fit: contain;
            filter: grayscale(100%);
        }
        .receipt-store-name {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 2px;
            color: #002244;
        }
        .receipt-store-contact {
            font-size: 10px;
            color: #555;
        }
        .receipt-divider {
            border: none;
            border-top: 1px dashed #ccc;
            margin: 6px 0;
        }
        .receipt-meta-row {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            margin-bottom: 2px;
            color: #333;
        }
        .receipt-item {
            margin-bottom: 6px;
        }
        .receipt-item-name {
            font-size: 11px;
            word-break: break-word;
            margin-bottom: 1px;
            color: #222;
        }
        .receipt-item-details {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #555;
        }
        .receipt-totals {
            margin-top: 6px;
        }
        .receipt-totals .row {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            margin-bottom: 3px;
            color: #333;
        }
        .receipt-totals .row.total {
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 4px;
            color: #000;
        }
        .receipt-totals .row.change {
            font-weight: bold;
            color: #000;
        }
        .receipt-totals .row.kasbon {
            color: #cc0000;
            font-weight: bold;
        }
        .receipt-footer {
            text-align: center;
            margin-top: 8px;
            font-size: 10px;
            color: #555;
        }
        @media print {
            html, body {
                width: 58mm;
                max-width: 58mm;
                padding: 0mm 2mm 5mm;
            }
            .receipt-preview {
                max-width: 52mm !important;
                padding: 0 4mm !important;
            }
            .receipt-store-name { color: #000; }
        }
    `;
}

// Generate label nota thermal classic 58mm
function generateReceiptPrintHTML(data) {
    const dateStr = new Date(data.date).toLocaleString('id-ID', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    }).replace(/\./g, ':');

    const logoHtml = data.store_logo
        ? `<img src="${BASE}/${data.store_logo}" alt="logo">`
        : '';

    const storeName = escHtml(data.store_name || 'SarungSantriStore');
    const storePhone = data.store_phone ? escHtml(data.store_phone) : '';
    const cashier = escHtml(data.cashier_name || 'Owner');
    const customer = escHtml(data.customer_name || 'Umum');

    // Items
    const itemsHtml = data.items.map((item, index) => `
        <div class="receipt-item">
            <div class="receipt-item-name">${index + 1}. ${escHtml(item.product_name)}${item.variation_name ? ' - ' + escHtml(item.variation_name) : ''}</div>
            <div class="receipt-item-details">
                <span>${item.qty} x ${formatRupiah(item.unit_price)}</span>
                <span>${formatRupiah(item.subtotal)}</span>
            </div>
        </div>
    `).join('');

    const paymentMethod = escHtml(data.payment_method || 'Tunai');

    const changeOrSisa = data.is_debt ? data.grand_total - data.paid_amount : data.change;

    // Totals
    const paymentRows = !data.is_debt ? `
        <div class="row">
            <span>Bayar (${paymentMethod})</span>
            <span>${formatRupiah(data.paid_amount)}</span>
        </div>
        <div class="row change">
            <span>Kembalian</span>
            <span>${formatRupiah(changeOrSisa)}</span>
        </div>
    ` : `
        <div class="row">
            <span>Bayar (DP)</span>
            <span>${formatRupiah(data.paid_amount)}</span>
        </div>
        <div class="row kasbon" style="color: #cc0000; font-weight: bold;">
            <span>Sisa Kasbon</span>
            <span>${formatRupiah(changeOrSisa)}</span>
        </div>
    `;

    const storeTagline = data.store_tagline ? escHtml(data.store_tagline) : '';

    return `
        <style>
            #receiptPrint { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #000; }
            #receiptPrint .receipt-header { text-align: center; margin-bottom: 10px; }
            #receiptPrint .receipt-header img { display: block; margin: 0 auto 4px; max-height: 48px; max-width: 48mm; object-fit: contain; filter: grayscale(100%); }
            #receiptPrint .receipt-store-name { font-weight: bold; font-size: 13px; margin-bottom: 2px; color: #002244; }
            #receiptPrint .receipt-store-contact { font-size: 10px; color: #555; }
            #receiptPrint .receipt-divider { border: none; border-top: 1px dashed #ccc; margin: 6px 0; }
            #receiptPrint .receipt-meta-row { display: flex; justify-content: space-between; font-size: 10px; margin-bottom: 2px; color: #333; }
            #receiptPrint .receipt-item { margin-bottom: 6px; text-align: left; }
            #receiptPrint .receipt-item-name { font-size: 11px; word-break: break-word; margin-bottom: 2px; color: #222; }
            #receiptPrint .receipt-item-details { display: flex; justify-content: space-between; font-size: 10px; color: #555; }
            #receiptPrint .receipt-totals { margin-top: 6px; }
            #receiptPrint .row { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 3px; color: #333; }
            #receiptPrint .row.total { font-weight: bold; font-size: 12px; margin-bottom: 4px; color: #000; }
            #receiptPrint .row.change { font-weight: bold; color: #000; }
            #receiptPrint .row.kasbon { color: #cc0000; font-weight: bold; }
            #receiptPrint .receipt-footer { text-align: center; margin-top: 8px; font-size: 10px; color: #555; }
        </style>
        <div class="receipt-preview" id="receiptPrint" style="width:100%; padding:14px; background:#fff; margin:0 auto; max-width:280px; text-align:left;">
            <div class="receipt-header">
                ${logoHtml}
                <div class="receipt-store-name">${storeName}</div>
                ${storeTagline ? `<div class="receipt-store-contact">${storeTagline}</div>` : ''}
                ${storePhone ? `<div class="receipt-store-contact">Telp: ${storePhone}</div>` : ''}
            </div>
            
            <hr class="receipt-divider">
            
            <div class="receipt-meta-row">
                <span>${data.invoice_number || '-'}</span>
                <span>Ksr: ${cashier}</span>
            </div>
            <div class="receipt-meta-row">
                <span>${dateStr}</span>
                <span>Plg: ${customer}</span>
            </div>
            
            <hr class="receipt-divider">
            
            <div class="receipt-items">
                ${itemsHtml}
            </div>
            
            <hr class="receipt-divider">
            
            <div class="receipt-totals">
                <div class="row total">
                    <span>TOTAL</span>
                    <span>${formatRupiah(data.grand_total)}</span>
                </div>
                ${paymentRows}
            </div>
            
            <div class="receipt-footer">
                ${data.receipt_footer ? escHtml(data.receipt_footer).replace(/\n/g, '<br>') : ''}
            </div>
        </div>
    `;
}

function printReceipt() {
    if (!_lastReceiptData) return;

    const printWindow = window.open('', '_blank', 'width=250,height=700');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Struk - ${escHtml(_lastReceiptData.invoice_number || '')}</title>
            <style>
                ${getReceiptCSS()}
                /* Force 58mm PDF output */
                @page {
                    size: 58mm auto;
                    margin: 0;
                }
                html, body {
                    width: 58mm !important;
                    max-width: 58mm !important;
                    margin: 0 auto !important;
                }
            </style>
        </head>
        <body>
            ${generateReceiptPrintHTML(_lastReceiptData)}
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.onload = () => {
        printWindow.focus();
        printWindow.print();
        printWindow.onafterprint = () => printWindow.close();
    };
}

async function shareReceipt() {
    if (!_lastReceiptData) {
        showToast('Data resi tidak tersedia.', 'error');
        return;
    }

    // Buat iframe tersembunyi untuk render struk dengan CSS yang sama
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:300px;height:1px;border:none;visibility:hidden;';
    document.body.appendChild(iframe);

    const iDoc = iframe.contentDocument || iframe.contentWindow.document;
    iDoc.open();
    iDoc.write(`
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <style>
                ${getReceiptCSS()}
                /* Override untuk render share - font dan margin sesuai preview modal */
                * { box-sizing: border-box; margin: 0; padding: 0; }
                html, body {
                    width: 300px !important;
                    max-width: 300px !important;
                    padding: 15px !important;
                    background: #fff !important;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif !important;
                    font-size: 11px !important;
                    line-height: 1.5 !important;
                    color: #000 !important;
                    margin: 0 auto !important;
                }
                .receipt-preview {
                    width: 256px !important;
                    max-width: 256px !important;
                    margin: 0 auto !important;
                }
                .receipt-store-name {
                    font-size: 14px !important;
                    font-weight: 700 !important;
                    margin-bottom: 3px !important;
                }
                .receipt-store-contact {
                    font-size: 11px !important;
                    color: #555 !important;
                    margin-bottom: 2px !important;
                }
                .receipt-meta-row {
                    font-size: 10.5px !important;
                    margin-bottom: 3px !important;
                }
                .receipt-item-name {
                    font-size: 11.5px !important;
                }
                .receipt-item-details {
                    font-size: 10.5px !important;
                }
                .receipt-totals .row {
                    font-size: 11.5px !important;
                    margin-bottom: 4px !important;
                }
                .receipt-wrapper {
                    background: #fff !important;
                    margin: 0 auto !important;
                    max-width: 300px !important;
                }
                .receipt-totals .row.total {
                    font-size: 13px !important;
                    margin-bottom: 5px !important;
                }
                .receipt-footer {
                    font-size: 10px !important;
                    margin-top: 10px !important;
                }
            </style>
        </head>
        <body>
            <div class="receipt-wrapper">
                ${generateReceiptPrintHTML(_lastReceiptData)}
            </div>
        </body>
        </html>
    `);
    iDoc.close();

    // Tunggu konten render (minimal delay)
    await new Promise(r => setTimeout(r, 100));

    // Sesuaikan tinggi iframe dengan konten
    const scrollH = iDoc.documentElement.scrollHeight || iDoc.body.scrollHeight;
    iframe.style.height = scrollH + 'px';

    // Load html2canvas jika belum ada
    if (typeof html2canvas === 'undefined') {
        await new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
            s.onload = resolve;
            s.onerror = () => reject(new Error('Gagal memuat html2canvas'));
            document.head.appendChild(s);
        });
    }

    try {
        const canvas = await html2canvas(iDoc.body, {
            backgroundColor: '#ffffff',
            scale: 2,
            useCORS: true,
            allowTaint: false,
            width: 300,
            windowWidth: 300,
            logging: false,
        });

        document.body.removeChild(iframe);

        canvas.toBlob(async blob => {
            if (!blob) {
                showToast('Gagal membuat gambar struk.', 'error');
                return;
            }
            const fileName = `struk-${_lastReceiptData.invoice_number || Date.now()}.png`;
            const file = new File([blob], fileName, { type: 'image/png' });

            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                try {
                    await navigator.share({
                        files: [file],
                        title: 'Struk Pembayaran',
                        text: _lastReceiptData.invoice_number || 'Struk'
                    });
                } catch (e) {
                    if (e.name !== 'AbortError') {
                        // Fallback ke download
                        downloadBlob(blob, fileName);
                    }
                }
            } else {
                // Fallback: download PNG
                downloadBlob(blob, fileName);
            }
        }, 'image/png');

    } catch (err) {
        document.body.removeChild(iframe);
        console.error(err);
        showToast('Gagal membuat gambar: ' + (err.message || err), 'error');
    }
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 500);
}

// =============================================
// HELPERS
// =============================================
function escHtml(str) {
    if (!str) return '';
    // Unescape first to prevent double encoding entities like &amp;
    const unescaped = String(str)
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'");
    const div = document.createElement('div');
    div.textContent = unescaped;
    return div.innerHTML;
}

function debounce(func, wait = 300) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

let isHideEmptyStock = false;
function toggleEmptyStock() {
    isHideEmptyStock = !isHideEmptyStock;
    const btn = document.getElementById('toggleEmptyStockBtn');
    if (isHideEmptyStock) {
        document.body.classList.add('hide-empty-stock');
        btn.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;
        btn.classList.add('active');
        btn.style.color = 'var(--danger)';
    } else {
        document.body.classList.remove('hide-empty-stock');
        btn.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
        btn.classList.remove('active');
        btn.style.color = '';
    }
}

