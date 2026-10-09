<?php
/**
 * Kasir Ibtidaiyah - POS (Point of Sale)
 * Antarmuka kasir full-screen
 * Fitur: Keranjang, Pembayaran, Pending Order, Retur
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);

$currentUser = getCurrentUser();

$db = Database::conn();
$stmt = $db->prepare("SELECT id FROM cash_settlements WHERE cashier_id = ? AND status = 'Open' LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$hasOpenShift = $stmt->fetch() ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title>POS Kasir - <?= APP_NAME ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/pos.css?v=<?= time() ?>">
</head>
<body style="background: var(--gray-100);">
    
    <div class="pos-layout">
        <!-- ========================= -->
        <!-- LEFT: Products Panel -->
        <!-- ========================= -->
        <div class="pos-products">
            <!-- POS Header -->
            <div class="pos-header">
                <div class="pos-brand">
                    <img src="<?= BASE_URL ?>/assets/img/logo_SSS.png" alt="Logo" class="pos-brand-logo" style="background:none; object-fit:contain; padding:0; border-radius:4px;">
                    <span><?= APP_NAME ?></span>
                </div>
                
                <div class="pos-search">
                    <svg class="pos-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="posSearch" placeholder="Cari produk, SKU, barcode..." autofocus>
                </div>
                
                <div class="pos-header-actions">
                    <!-- Hide Empty Stock Button -->
                    <button class="pos-header-btn" id="toggleEmptyStockBtn" onclick="toggleEmptyStock()" title="Sembunyikan Stok Kosong">
                        <svg id="eyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                    <!-- Pending Orders Button -->
                    <button class="pos-header-btn pos-pending-btn" onclick="openPendingList()" title="Pesanan Tertunda">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 8v4l3 3"/>
                        </svg>
                        <span class="pos-pending-badge" id="pendingBadge" style="display:none;">0</span>
                    </button>
                    <!-- Retur Button -->
                    <button class="pos-header-btn" onclick="openEditTransactionModal()" title="Edit Transaksi">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                        </svg>
                    </button>
                    <a href="<?= BASE_URL ?>/admin/index.php" class="pos-header-btn" title="Dashboard">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        </svg>
                    </a>
                    <a href="<?= BASE_URL ?>/logout.php" class="pos-header-btn" title="Logout">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </a>
                </div>
            </div>
            
            <!-- Category Tabs -->
            <div class="pos-categories" id="categoryTabs">
                <button class="pos-cat-btn active">Semua</button>
            </div>
            
            <!-- Product Grid -->
            <div class="pos-product-grid" id="productGrid">
                <div style="grid-column: 1/-1; text-align:center; padding:60px; color: var(--gray-400);">
                    <div class="spinner" style="margin: 0 auto 12px;"></div>
                    Memuat produk...
                </div>
            </div>
        </div>
        
        <!-- ========================= -->
        <!-- RIGHT: Cart Panel -->
        <!-- ========================= -->
        <div class="pos-cart">
            <div class="pos-cart-header">
                <div class="pos-cart-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    Keranjang
                    <span class="pos-cart-count" id="cartCount">0</span>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button class="pos-cart-clear" onclick="clearCart()">Kosongkan</button>
                    <button id="closeCartBtn" class="btn btn-outline" onclick="toggleCart()" style="display:none; padding:4px 8px; border:none; background:var(--gray-100); color:var(--gray-600);" title="Tutup">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Customer Selector dipindah ke modal pembayaran -->
            

            <!-- Cart Items -->
            <div class="pos-cart-items" id="cartItems">
                <div class="pos-cart-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    <div style="font-weight:600; margin-top:4px;">Keranjang Kosong</div>
                    <div style="font-size:0.8125rem;">Klik produk untuk menambahkan</div>
                </div>
            </div>
            
            <!-- Cart Footer: Total & Pay -->
            <div class="pos-cart-footer">
                <div class="pos-totals">
                    <div class="pos-total-row grand-total">
                        <span>Total</span>
                        <span id="cartTotal">Rp 0</span>
                    </div>
                </div>
                <div class="pos-pay-btn" id="payBtnArea" style="display:none; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="openPayment()" style="flex:2;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>
                        </svg>
                        Bayar
                    </button>
                    <button class="btn btn-gold" onclick="openPaymentDebt()" title="Kasbon">
                        Kasbon
                    </button>
                    <button class="btn btn-outline" onclick="openSavePendingModal()" title="Simpan Pesanan" style="padding:14px 12px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 8v4l3 3"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Shift Warning Overlay (Optional but good UX) -->
        <?php if ($hasOpenShift === 'false'): ?>
        <div style="position: absolute; top: 70px; left: 0; right: 0; z-index: 100; padding: 12px; background: var(--danger); color: white; text-align: center; font-weight: 600; font-size: 0.875rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            ⚠️ PERHATIAN: Anda belum membuka shift. Transaksi tidak dapat dilakukan. <a href="<?= BASE_URL ?>/admin/cash_settlement.php" style="color: white; text-decoration: underline;">Buka Shift Sekarang</a>
        </div>
        <?php endif; ?>
        
        <!-- Cart Overlay for Mobile -->
        <div class="pos-cart-overlay" id="posCartOverlay" onclick="toggleCart()"></div>
        
        <!-- Floating Cart Button for Mobile -->
        <button class="floating-cart-btn" id="floatingCartBtn" onclick="toggleCart()">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
            <span class="floating-cart-badge" id="floatingCartCount">0</span>
        </button>
    </div>
    
    <!-- ========================= -->
    <!-- EDIT TRANSACTION MODAL -->
    <!-- ========================= -->
    <div class="modal-overlay" id="modalEditTransaction">
        <div class="modal" style="max-width:700px; width:90%; height:80vh; display:flex; flex-direction:column; background:var(--content-bg);">
            <div class="modal-header">
                <h3 class="modal-title">Edit Transaksi</h3>
                <button class="modal-close" onclick="closeModal('modalEditTransaction')">&times;</button>
            </div>
            <div class="modal-body" style="padding:0; flex:1;">
                <iframe id="editTransactionFrame" src="" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>
    
    <script>
    function openEditTransactionModal(invoice = '') {
        const frame = document.getElementById('editTransactionFrame');
        const url = '../admin/edit_transaction.php?popup=1' + (invoice ? '&invoice=' + encodeURIComponent(invoice) : '');
        frame.src = url;
        openModal('modalEditTransaction');
    }
    </script>

    <!-- ========================= -->
    <!-- PAYMENT MODAL -->
    <!-- ========================= -->
    <div class="modal-overlay payment-modal" id="modalPayment">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Pembayaran</h3>
                <button class="modal-close" onclick="closeModal('modalPayment')">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Total Display -->
                <div style="text-align:center; margin-bottom:20px;">
                    <div class="text-sm text-muted">Total Belanja</div>
                    <div style="font-size:2rem; font-weight:800; color:var(--primary-800);" id="paymentTotal">Rp 0</div>
                </div>
                
                <!-- Payment Method -->
                <?php
                // Load configured payment methods for POS
                $paymentBanks = [];
                foreach (['bca'=>'BCA', 'mandiri'=>'Mandiri', 'bni'=>'BNI', 'bri'=>'BRI', 'bsi'=>'BSI', 'emaal'=>'Emaal'] as $code => $name) {
                    $acc = getSetting("payment_bank_{$code}_account");
                    if ($acc) {
                        $paymentBanks[] = ['code' => $code, 'name' => $name];
                    }
                }
                $paymentEwallets = [];
                foreach (['dana'=>'Dana', 'ovo'=>'OVO', 'shopeepay'=>'ShopeePay'] as $code => $name) {
                    $acc = getSetting("payment_ewallet_{$code}");
                    if ($acc) {
                        $paymentEwallets[] = ['code' => $code, 'name' => $name];
                    }
                }
                $hasQris = getSetting('payment_qris_image') ? true : false;
                ?>
                <!-- Payment Method Dropdown -->
                <label style="font-size:0.8125rem; font-weight:600; color:var(--gray-700); display:block; margin-bottom:6px;">Metode Pembayaran</label>
                <select id="selectedMethod" class="form-control" style="margin-bottom: 15px;" onchange="handlePaymentMethodChange(this.value)">
                    <option value="Tunai">Tunai</option>
                    <?php foreach ($paymentBanks as $bank): ?>
                    <option value="Bank <?= $bank['name'] ?>">Bank <?= $bank['name'] ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($paymentEwallets as $ewallet): ?>
                    <option value="E-Wallet <?= $ewallet['name'] ?>">E-Wallet <?= $ewallet['name'] ?></option>
                    <?php endforeach; ?>
                    <?php if ($hasQris): ?>
                    <option value="QRIS">QRIS</option>
                    <?php endif; ?>
                    <optgroup label="Marketplace">
                        <option value="Shopee">Shopee</option>
                        <option value="TikTok">TikTok</option>
                    </optgroup>
                    <!-- Hidden Kasbon option so JS can select it programmatically -->
                    <option value="Kasbon" style="display:none;">Kasbon</option>
                </select>
                
                <!-- Customer Selector di Pembayaran -->
                <div class="pay-customer-row">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <div style="flex:1; position:relative;">
                        <input type="text" id="customerSearch" placeholder="Cari pelanggan (opsional)..." autocomplete="off">
                        <input type="hidden" id="selectedCustomerId">
                        <div id="customerList" class="dropdown-menu" style="display:none; left:0; right:0; max-height:200px; overflow-y:auto; border-radius:8px; margin-top:8px; box-shadow:var(--shadow-md);"></div>
                    </div>
                    <button onclick="clearCustomer()" style="background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:1.25rem;" title="Hapus pelanggan">&times;</button>
                </div>

                <!-- Discount & Additional Fee -->
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex:1.5;">
                        <label style="font-size:0.8125rem; font-weight:600; color:var(--gray-700); display:block; margin-bottom:6px;">Diskon</label>
                        <div class="pay-discount-row">
                            <input type="number" id="paymentDiscount" min="0" value="0"
                                   oninput="calculateChange()" onclick="this.select()"
                                   class="pay-discount-input">
                            <div class="pay-disc-type-toggle">
                                <button class="pay-disc-type-btn active" data-type="persen" onclick="setPayDiscountType('persen')">%</button>
                                <button class="pay-disc-type-btn" data-type="rupiah" onclick="setPayDiscountType('rupiah')">Rp</button>
                            </div>
                        </div>
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:0.8125rem; font-weight:600; color:var(--gray-700); display:block; margin-bottom:6px;">Biaya Tambahan</label>
                        <input type="number" id="paymentAdditionalFee" min="0" value="0"
                               oninput="calculateChange()" onclick="this.select()"
                               class="pay-discount-input" style="border-radius: 8px; width: 100%; border: 1px solid var(--border-color);">
                    </div>
                </div>
                
                <!-- Amount Input -->
                <label style="font-size:0.8125rem; font-weight:600; color:var(--gray-700); display:block; margin-bottom:6px;">Jumlah Bayar</label>
                <input type="text" class="payment-amount-input" id="paymentAmount" oninput="onPaymentInput(this)" onclick="this.select()">
                
                <!-- Quick Amounts -->
                <div class="quick-amount-btns" id="quickAmounts"></div>
                
                <!-- Change Display -->
                <div class="payment-change">
                    <div class="payment-change-label">Kembalian</div>
                    <div class="payment-change-value" id="paymentChange">Rp 0</div>
                </div>
                
                <!-- Kasbon Section (hidden by default) -->
                <div id="kasbonSection" style="display:none;">
                    <div style="background: var(--warning-bg); border:1px solid #fde68a; border-radius:8px; padding:12px; margin-bottom:12px; font-size:0.8125rem;">
                        ⚠️ Kasbon hanya untuk pelanggan terdaftar. Pastikan pelanggan sudah dipilih.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('modalPayment')">Batal</button>
                <button class="btn btn-primary btn-lg" id="submitPaymentBtn" onclick="submitPayment()" style="flex:1;">
                    Proses Pembayaran
                </button>
            </div>
        </div>
    </div>
    
    <!-- ========================= -->
    <!-- RECEIPT MODAL -->
    <!-- ========================= -->
    <div class="modal-overlay" id="modalReceipt">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">✅ Transaksi Berhasil!</h3>
                <button class="modal-close" onclick="closeModal('modalReceipt')">&times;</button>
            </div>
            <div class="modal-body" id="receiptContent" style="text-align:center;">
                <!-- Receipt will be injected here -->
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button class="btn btn-primary" onclick="printReceipt()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Cetak Struk
                </button>
                <button class="btn btn-gold" onclick="shareReceipt()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                    Share PNG
                </button>
            </div>
        </div>
    </div>
    
    <!-- ========================= -->
    <!-- SAVE PENDING ORDER MODAL -->
    <!-- ========================= -->
    <div class="modal-overlay" id="modalSavePending">
        <div class="modal" style="max-width:420px;">
            <div class="modal-header">
                <h3 class="modal-title">💾 Simpan Pesanan</h3>
                <button class="modal-close" onclick="closeModal('modalSavePending')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.8125rem; color:var(--gray-500); margin-bottom:16px;">
                    Simpan keranjang ini untuk dilanjutkan nanti. Stok belum dikurangi.
                </p>
                <div class="form-group">
                    <label class="form-label">Nama Pelanggan / No. Meja <span class="required">*</span></label>
                    <input type="text" id="pendingLabel" class="form-control" placeholder="Contoh: Pak Ahmad / Meja 5" required>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('modalSavePending')">Batal</button>
                <button class="btn btn-primary" id="btnSavePending" onclick="savePendingOrder()">Simpan Pesanan</button>
            </div>
        </div>
    </div>
    
    <!-- ========================= -->
    <!-- PENDING ORDERS LIST MODAL -->
    <!-- ========================= -->
    <div class="modal-overlay" id="modalPendingList">
        <div class="modal" style="max-width:560px;">
            <div class="modal-header">
                <h3 class="modal-title">🕐 Pesanan Tertunda</h3>
                <button class="modal-close" onclick="closeModal('modalPendingList')">&times;</button>
            </div>
            <div class="modal-body" id="pendingListBody" style="max-height:400px; overflow-y:auto;">
                <div style="text-align:center; padding:40px; color:var(--gray-400);">
                    <div class="spinner" style="margin:0 auto 12px;"></div>
                    Memuat...
                </div>
            </div>
        </div>
    </div>
    
    <!-- ========================= -->
    <!-- RETURN MODAL -->
    <!-- ========================= -->
    <div class="modal-overlay" id="modalReturn">
        <div class="modal" style="max-width:640px;">
            <div class="modal-header">
                <h3 class="modal-title">↩️ Retur Produk</h3>
                <button class="modal-close" onclick="closeModal('modalReturn')">&times;</button>
            </div>
            <div class="modal-body" style="max-height:500px; overflow-y:auto;">
                <!-- Search Invoice -->
                <div class="form-group">
                    <label class="form-label">Cari Nomor Invoice</label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="returnInvoiceSearch" class="form-control" placeholder="INV-20260728-0001">
                        <button class="btn btn-primary" onclick="searchInvoiceForReturn()">Cari</button>
                    </div>
                </div>
                
                <!-- Return Form (hidden until invoice found) -->
                <div id="returnFormArea" style="display:none;">
                    <div class="return-invoice-info" id="returnInvoiceInfo"></div>
                    
                    <div id="returnItemsList"></div>
                    
                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label" style="font-weight:600; color:var(--gray-800);">Alasan Retur <span style="color:var(--danger);">*</span></label>
                        <textarea id="returnReason" class="form-control" rows="3" placeholder="Barang rusak / salah kirim / dll"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="returnFooter" style="display:none;">
                <button class="btn btn-outline" onclick="closeModal('modalReturn')">Batal</button>
                <button class="btn btn-primary" id="btnSubmitReturn" onclick="submitReturn()">Proses Retur</button>
            </div>
        </div>
    </div>
    
    <!-- ========================= -->
    <!-- MODAL: PILIH TIPE HARGA  -->
    <!-- ========================= -->
    <div class="modal-overlay" id="modalPriceType">
        <div class="modal price-type-modal">
            <div class="modal-header price-type-modal-header">
                <div>
                    <div class="price-type-modal-title">PILIH TIPE HARGA</div>
                    <div class="price-type-modal-product" id="priceTypeModalProductName"></div>
                </div>
                <button class="modal-close" onclick="closeModal('modalPriceType')">&times;</button>
            </div>
            <div class="modal-body price-type-modal-body" id="priceTypeModalBody">
                <!-- Rows injected by JS -->
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button class="btn btn-outline" onclick="closeModal('modalPriceType')" style="width:100%; max-width:200px;">BATAL</button>
            </div>
        </div>
    </div>

    <!-- ========================= -->
    <!-- MODAL: DETAIL ITEM        -->
    <!-- ========================= -->
    <div class="modal-overlay" id="modalItemDetail">
        <div class="modal item-detail-modal">
            <div class="modal-header item-detail-modal-header">
                <div style="flex:1; min-width:0;">
                    <div class="item-detail-product-name" id="itemDetailProductName"></div>
                    <div class="item-detail-price-type-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        <span id="itemDetailPriceType">Umum</span>
                    </div>
                </div>
                <button class="modal-close" onclick="closeModal('modalItemDetail')">&times;</button>
            </div>

            <div class="modal-body item-detail-body">

                <!-- QTY -->
                <div class="item-detail-section">
                    <div class="item-detail-section-label">Jumlah Barang</div>
                    <div class="item-detail-qty-row">
                        <button class="item-detail-qty-btn" onclick="
                            var inp=document.getElementById('itemDetailQty');
                            inp.value=Math.max(1,parseInt(inp.value||1)-1);
                            _updateItemDetailPreview();">−</button>
                        <input type="number" id="itemDetailQty" value="1" min="1"
                               oninput="_updateItemDetailPreview()" onclick="this.select()"
                               class="item-detail-qty-input">
                        <button class="item-detail-qty-btn" onclick="
                            var inp=document.getElementById('itemDetailQty');
                            inp.value=parseInt(inp.value||1)+1;
                            _updateItemDetailPreview();">+</button>
                    </div>
                    <!-- Tier badges -->
                    <div class="item-detail-tier-hint" id="itemDetailTierHint"></div>
                </div>

                <!-- CUSTOM PRICE -->
                <div class="item-detail-section">
                    <label class="item-detail-checkbox-label">
                        <input type="checkbox" id="itemCustomPriceCheck" onchange="toggleItemCustomPrice(this.checked)">
                        <span>Ubah harga sementara</span>
                    </label>
                    <div id="itemCustomPriceRow" style="display:none; margin-top:10px;">
                        <div class="item-detail-input-prefix-wrap">
                            <span class="item-detail-input-prefix">Rp</span>
                            <input type="number" id="itemCustomPriceInput"
                                   oninput="_updateItemDetailPreview()" onclick="this.select()"
                                   class="item-detail-price-input" placeholder="0">
                        </div>
                    </div>
                </div>

                <!-- DISCOUNT -->
                <div class="item-detail-section" id="itemDiscountSection">
                    <label class="item-detail-checkbox-label">
                        <input type="checkbox" id="itemDiscountCheck" onchange="toggleItemDiscount(this.checked)">
                        <span>Ubah diskon per jumlah</span>
                    </label>
                    <div id="itemDiscountRow" style="display:none; margin-top:10px;">
                        <div class="item-detail-disc-row">
                            <input type="number" id="itemDiscountValue" value="0" min="0"
                                   oninput="_updateItemDetailPreview()" onclick="this.select()"
                                   class="item-detail-disc-input" placeholder="0">
                            <div class="disc-type-toggle">
                                <button class="disc-type-btn active" data-type="persen" onclick="setDiscountType('persen')">%</button>
                                <button class="disc-type-btn" data-type="rupiah" onclick="setDiscountType('rupiah')">Rp</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PREVIEW -->
                <div class="item-detail-preview" id="itemDetailPreview">
                    <!-- Filled by JS -->
                </div>
            </div>

            <div class="modal-footer item-detail-footer">
                <button class="btn btn-outline item-detail-cancel-btn" onclick="closeModal('modalItemDetail')">Batal</button>
                <button class="btn btn-primary item-detail-ok-btn" onclick="confirmItemDetail()">OK</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <script src="<?= ASSETS_URL ?>/js/app.js?v=<?= time() ?>"></script>
    <script src="<?= ASSETS_URL ?>/js/bluetooth-printer.js?v=<?= time() ?>"></script>
    <script src="<?= ASSETS_URL ?>/js/pos.js?v=<?= time() ?>"></script>
    <!-- Preload html2canvas di background agar Share PNG instan -->
    <script>
    (function() {
        var s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        s.async = true;
        document.head.appendChild(s);
    })();
    </script>
    
    <script>
    const hasOpenShift = <?= $hasOpenShift ?>;

    const originalOpenPayment = typeof openPayment === 'function' ? openPayment : null;
    window.openPayment = function() {
        if (!hasOpenShift) {
            showToast('Anda harus membuka shift terlebih dahulu untuk melakukan transaksi!', 'error');
            return;
        }
        closeMobileCart();
        if (originalOpenPayment) originalOpenPayment();
    };
    
    const originalOpenSavePendingModal = typeof openSavePendingModal === 'function' ? openSavePendingModal : null;
    window.openSavePendingModal = function() {
        if (!hasOpenShift) {
            showToast('Anda harus membuka shift terlebih dahulu untuk menyimpan pesanan!', 'error');
            return;
        }
        closeMobileCart();
        if (originalOpenSavePendingModal) originalOpenSavePendingModal();
    };

    function openPaymentDebt() {
        if (!hasOpenShift) {
            showToast('Anda harus membuka shift terlebih dahulu untuk melakukan transaksi!', 'error');
            return;
        }
        if (cart.length === 0) {
            showToast('Keranjang masih kosong!', 'warning');
            return;
        }

        
        document.getElementById('selectedMethod').value = 'Kasbon';
        document.querySelectorAll('.payment-method-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('kasbonSection').style.display = 'block';
        
        const total = getCartTotal();
        document.getElementById('paymentTotal').textContent = formatRupiah(total);
        document.getElementById('paymentAmount').value = '0';
        document.getElementById('paymentAmount').dataset.raw = '0';
        document.getElementById('paymentChange').textContent = 'Rp 0';
        
        closeMobileCart();
        openModal('modalPayment');
    }
    
    // Mobile Cart Helpers
    function closeMobileCart() {
        const cartEl = document.querySelector('.pos-cart');
        const overlayEl = document.getElementById('posCartOverlay');
        if (cartEl && cartEl.classList.contains('open')) {
            cartEl.classList.remove('open');
            if (overlayEl) overlayEl.classList.remove('active');
        }
    }

    // Mobile Cart Toggle function
    function toggleCart() {
        const cartEl = document.querySelector('.pos-cart');
        const overlayEl = document.getElementById('posCartOverlay');
        const closeBtn = document.getElementById('closeCartBtn');
        if(cartEl.classList.contains('open')) {
            cartEl.classList.remove('open');
            overlayEl.classList.remove('active');
        } else {
            cartEl.classList.add('open');
            overlayEl.classList.add('active');
            if (window.innerWidth <= 1024 && closeBtn) {
                closeBtn.style.display = 'block';
            }
        }
    }
    


    function handlePaymentMethodChange(method) {
        const kasbonSection = document.getElementById('kasbonSection');
        if (kasbonSection) {
            kasbonSection.style.display = method === 'Kasbon' ? 'block' : 'none';
        }
    }
    </script>
</body>
</html>
