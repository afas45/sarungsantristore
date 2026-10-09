<?php
/**
 * Kasir Ibtidaiyah - Checkout
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();
$storeName = getSetting('store_name', APP_NAME);

// Must have items in cart
if (empty($_SESSION['shop_cart'])) {
    flashMessage('error', 'Keranjang kosong.');
    redirect(BASE_URL . '/shop/cart.php');
}

// Process checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flashMessage('error', 'Sesi telah kedaluwarsa atau permintaan tidak valid. Silakan muat ulang halaman.');
        redirect(BASE_URL . '/shop/checkout.php');
    }
    
    $customerName  = sanitize($_POST['name']  ?? '');
    $rawPhone      = sanitize($_POST['phone'] ?? '');
    $customerPhone = formatPhoneNumber($rawPhone);
    $notes         = sanitize($_POST['notes'] ?? '');

    // Gabungkan alamat: wilayah dari dropdown + detail jalan
    $enableAddressApi  = getSetting('enable_address_api')  === '1';
    $enableShippingApi = getSetting('enable_shipping_api') === '1';

    // Dropship fields
    $isDropship            = isset($_POST['is_dropship']) ? 1 : 0;
    $dropshipReceiverName  = $isDropship ? sanitize($_POST['dropship_receiver_name'] ?? '') : null;
    $dropshipReceiverPhone = $isDropship ? formatPhoneNumber(sanitize($_POST['dropship_receiver_phone'] ?? '')) : null;

    $provinceName = sanitize($_POST['province_name'] ?? '');
    $cityName     = sanitize($_POST['city_name']     ?? '');
    $districtName = sanitize($_POST['district_name'] ?? '');
    $streetDetail = sanitize($_POST['street_detail'] ?? '');

    $parts = array_filter([$streetDetail, $districtName, $cityName, $provinceName]);
    $customerAddress = implode(', ', $parts);
    
    if (empty($customerAddress) && isset($_POST['address'])) {
        $customerAddress = sanitize($_POST['address']);
    }

    // Shipping
    $courierName  = sanitize($_POST['courier_name']  ?? '');
    $shippingCost = 0;
    if ($enableShippingApi) {
        $shippingCost = (float)($_POST['shipping_cost'] ?? 0);
    }

    $destinationCityId = (int)($_POST['destination_city_id'] ?? 0);

    // Payment Method
    $paymentMethod = sanitize($_POST['payment_method'] ?? '');

    if (empty($customerName) || empty($customerPhone)) {
        flashMessage('error', 'Nama dan nomor telepon harus diisi.');
        redirect(BASE_URL . '/shop/checkout.php');
    }
    if (empty($paymentMethod) && !in_array($courierName, ['Ambil di Toko', 'Ongkir Bayar di Tempat (khusus J&T Express)', 'Ongkir Bayar di Tempat (J&T Cargo)'])) {
        flashMessage('error', 'Silakan pilih metode pembayaran.');
        redirect(BASE_URL . '/shop/checkout.php');
    }

    try {
        $db->beginTransaction();

        // Find or create customer
        if (isLoggedIn() && isCustomer() && isset($_SESSION['customer_id'])) {
            $customerId = $_SESSION['customer_id'];
            $stmt = $db->prepare("UPDATE customers SET name = ?, phone = ?, address = ? WHERE id = ?");
            $stmt->execute([$customerName, $customerPhone, $customerAddress, $customerId]);
        } else {
            $stmt = $db->prepare("SELECT id FROM customers WHERE phone = ?");
            $stmt->execute([$customerPhone]);
            $customerId = $stmt->fetchColumn();

            if (!$customerId) {
                $stmt = $db->prepare("INSERT INTO customers (name, phone, address) VALUES (?,?,?)");
                $stmt->execute([$customerName, $customerPhone, $customerAddress]);
                $customerId = $db->lastInsertId();
            }
        }

        // Calculate totals
        $totalAmount    = 0;
        $processedItems = [];

        foreach ($_SESSION['shop_cart'] as $item) {
            $stmt = $db->prepare("SELECT pv.*, p.name as product_name FROM product_variations pv JOIN products p ON pv.product_id = p.id WHERE pv.id = ? FOR UPDATE");
            $stmt->execute([$item['variation_id']]);
            $var = $stmt->fetch();

            if (!$var || $var['stock_qty'] < $item['qty']) {
                throw new Exception("Stok {$item['product_name']} tidak cukup.");
            }

            $priceTypeUser = $_SESSION['price_type_name'] ?? 'Umum';
            $unitPrice = getSellingPrice($var['id'], $item['qty'], $priceTypeUser);
            $subtotal  = $unitPrice * $item['qty'];
            $totalAmount += $subtotal;

            $processedItems[] = [
                'variation_id'  => $var['id'],
                'product_name'  => $var['product_name'],
                'variation_name'=> $var['variation_name'],
                'qty'           => $item['qty'],
                'unit_price'    => $unitPrice,
                'subtotal'      => $subtotal,
            ];
        }

        $invoiceNumber = generateInvoiceNumber();
        $grandTotal    = $totalAmount + $shippingCost;

        $recipientName  = $isDropship && !empty($dropshipReceiverName) ? $dropshipReceiverName : $customerName;
        $recipientPhone = $isDropship && !empty($dropshipReceiverPhone) ? $dropshipReceiverPhone : $customerPhone;

        $stmt = $db->prepare("INSERT INTO sales (invoice_number, customer_id, customer_name, sale_source, total_amount, grand_total, status, notes, is_dropship, dropship_receiver_name, dropship_receiver_phone, payment_method) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$invoiceNumber, $customerId, $customerName, 'E-Commerce', $totalAmount, $grandTotal, 'Pending', $notes, $isDropship, $dropshipReceiverName, $dropshipReceiverPhone, $paymentMethod]);
        $saleId = $db->lastInsertId();

        // Simpan Detail Pengiriman
        $shippingMethod = empty($courierName) || $courierName === 'Ambil di Toko' ? 'Ambil di Toko' : 'Ekspedisi';
        $stmt = $db->prepare("INSERT INTO shipping_details (sale_id, recipient_name, phone, shipping_address, courier_name, shipping_method) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$saleId, $recipientName, $recipientPhone, $customerAddress, $courierName, $shippingMethod]);

        foreach ($processedItems as $pi) {
            $stmt = $db->prepare("INSERT INTO sale_details (sale_id, product_variation_id, product_name, variation_name, qty, unit_price) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$saleId, $pi['variation_id'], $pi['product_name'], $pi['variation_name'], $pi['qty'], $pi['unit_price']]);

            $db->prepare("UPDATE product_variations SET stock_qty = stock_qty - ? WHERE id = ?")->execute([$pi['qty'], $pi['variation_id']]);
        }

        $db->commit();

        // Clear cart
        $_SESSION['shop_cart'] = [];

        logActivity('Pesanan', 'E-Commerce', "Invoice: $invoiceNumber");

        // Redirect to success page
        $_SESSION['last_order'] = [
            'invoice_number' => $invoiceNumber,
            'total'          => $grandTotal,
            'shipping_cost'  => $shippingCost,
            'courier_name'   => strtoupper(html_entity_decode((string)$courierName, ENT_QUOTES, 'UTF-8')),
            'customer_name'  => $customerName,
            'payment_method' => $paymentMethod
        ];

        redirect(BASE_URL . '/shop/order_success.php');

    } catch (Exception $e) {
        $db->rollBack();
        flashMessage('error', $e->getMessage());
        redirect(BASE_URL . '/shop/checkout.php');
    }
}

// Get cart totals
$cartTotal  = 0;
$cartCount  = 0;
$cartWeight = 0;
$priceTypeUser = $_SESSION['price_type_name'] ?? 'Umum';
foreach ($_SESSION['shop_cart'] as $item) {
    $price = getSellingPrice($item['variation_id'], $item['qty'], $priceTypeUser);
    $cartTotal  += $price * $item['qty'];
    $cartCount  += $item['qty'];

    $stmt = $db->prepare("SELECT pv.weight_gram, p.weight FROM products p JOIN product_variations pv ON pv.product_id = p.id WHERE pv.id = ?");
    $stmt->execute([$item['variation_id']]);
    $weightRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Prioritas: weight_gram dari variasi, lalu weight dari produk, lalu default 500g
    $w_grams = 0;
    if ($weightRow) {
        $vw = (int)$weightRow['weight_gram'];
        $pw = (int)$weightRow['weight'];
        $w_grams = ($vw > 0) ? $vw : $pw;
    }
    
    // Default 500 gram (berat rata-rata 1 sarung) jika tidak diisi
    if ($w_grams <= 0) $w_grams = 500;
    
    $cartWeight += $w_grams * $item['qty'];
}
if ($cartWeight < 1000) $cartWeight = 1000;

$currentUser       = getCurrentUser();
$enableAddressApi  = getSetting('enable_address_api')  === '1';
$enableShippingApi = getSetting('enable_shipping_api') === '1';
$defaultShippingCost = (float)getSetting('default_shipping_cost', '0');
$originCityId      = (int)getSetting('rajaongkir_origin_city_id', 0);
$originCityName    = getSetting('rajaongkir_origin_city_name', '');

// Load configured payment methods
$paymentBanks = [];
foreach (['bca'=>'BCA', 'mandiri'=>'Mandiri', 'bni'=>'BNI', 'bri'=>'BRI', 'bsi'=>'BSI', 'emaal'=>'Emaal'] as $code => $name) {
    $acc = getSetting("payment_bank_{$code}_account");
    if ($acc) {
        $logoFile = '';
        if ($code == 'bca') $logoFile = 'BCA.png';
        elseif ($code == 'mandiri') $logoFile = 'mandiri.png';
        elseif ($code == 'bni') $logoFile = 'BNI.svg';
        elseif ($code == 'bri') $logoFile = 'BRI.png';
        elseif ($code == 'bsi') $logoFile = 'BSI.jpeg';
        elseif ($code == 'emaal') $logoFile = 'emaal.png';

        $paymentBanks[] = [
            'code' => $code,
            'name' => $name,
            'account' => $acc,
            'owner' => getSetting("payment_bank_{$code}_name"),
            'logo' => $logoFile
        ];
    }
}
$paymentEwallets = [];
$ewalletName = getSetting('payment_ewallet_name');
foreach (['dana'=>'Dana', 'ovo'=>'OVO', 'shopeepay'=>'ShopeePay'] as $code => $name) {
    $acc = getSetting("payment_ewallet_{$code}");
    if ($acc) {
        $logoFile = '';
        if ($code == 'dana') $logoFile = 'dana.png';
        elseif ($code == 'ovo') $logoFile = 'ovo.png';
        elseif ($code == 'shopeepay') $logoFile = 'Shopeepay.png';

        $paymentEwallets[] = [
            'code' => $code,
            'name' => $name,
            'account' => $acc,
            'owner' => $ewalletName,
            'logo' => $logoFile
        ];
    }
}
$paymentQris = [
    'image' => getSetting('payment_qris_image'),
    'name' => getSetting('payment_qris_name')
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?= $storeName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
    <style>
        /* Address API Dropdowns */
        .address-dropdown-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .address-full-width {
            grid-column: 1 / -1;
        }
        @media (max-width: 640px) {
            .address-dropdown-grid { 
                display: flex; 
                flex-direction: column; 
                gap: 12px; 
            }
            .address-dropdown-grid > .form-group { 
                width: 100% !important; 
                margin: 0; 
            }
            .address-dropdown-grid select,
            .address-dropdown-grid .form-control { 
                width: 100% !important; 
                box-sizing: border-box;
                min-width: 0; 
            }
            .address-full-width {
                width: 100% !important;
            }
        }
        .form-select-loading {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Ccircle cx='12' cy='12' r='10'/%3E%3Cpath d='M12 6v6l4 2'/%3E%3C/svg%3E") !important;
        }

        /* Shipping cost result */
        .shipping-options-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 12px;
        }
        .shipping-option-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.15s;
        }
        .shipping-option-item:hover { border-color: var(--primary-400); background: var(--gray-50); }
        .shipping-option-item.selected { border-color: var(--primary-500); background: #f0fdf4; }
        .shipping-option-item input[type="radio"] { accent-color: var(--primary-600); }

        /* Animate loading */
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-spinner {
            display: inline-block;
            width: 16px; height: 16px;
            border: 2px solid var(--gray-200);
            border-top-color: var(--primary-600);
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }

        .ongkir-total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 0.9375rem;
        }

        /* Payment Methods Accordion */
        .payment-methods-container {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .pm-group {
            border-bottom: 1px solid var(--border-color);
        }
        .pm-group:last-child {
            border-bottom: none;
        }
        .pm-header {
            padding: 14px 16px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }
        .pm-header:hover { background: var(--gray-50); }
        .pm-header-title { font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .pm-header-logos { display: flex; gap: 4px; }
        .pm-header-logos img { height: 16px; object-fit: contain; }
        .pm-body {
            padding: 0 16px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out, padding 0.3s ease;
            background: var(--gray-50);
        }
        .pm-group.active .pm-body {
            padding: 12px 16px 16px;
            max-height: 500px;
        }
        .pm-option {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 8px;
            background: #fff;
            cursor: pointer;
        }
        .pm-option:last-child { margin-bottom: 0; }
        .pm-option:hover { border-color: var(--primary-400); }
        .pm-option input[type="radio"] { margin-right: 12px; accent-color: var(--primary-600); }
        .pm-option-logo { width: 40px; text-align: center; margin-right: 12px; }
        .pm-option-logo img { max-width: 100%; max-height: 20px; object-fit: contain; }
        .pm-option-name { flex: 1; font-weight: 500; font-size: 0.875rem; }
    </style>
</head>
<body>
    <div class="shop-layout">
        <?php
        $showSearch = false;
        $searchVal  = '';
        $cartCount  = array_sum(array_column($_SESSION['shop_cart'] ?? [], 'qty'));
        include INCLUDES_PATH . '/shop_navbar.php';
        ?>
        
        <div class="shop-content">
            <?= renderFlashMessage() ?>
            
            <h1 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 24px;">📦 Checkout</h1>
            
            <form method="POST" id="checkoutForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <!-- Hidden fields untuk API Ongkir -->
                <?php if ($enableShippingApi): ?>
                <input type="hidden" name="destination_city_id" id="destinationCityId" value="">
                <input type="hidden" name="shipping_cost" id="hiddenShippingCost" value="0">
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr 400px; gap: 24px;" class="cart-layout">
                    <div>
                        <!-- ================================
                             KARTU: DATA PEMESAN
                        ================================ -->
                        <div class="card mb-16">
                            <div class="card-header"><h3 class="card-title">Data Pemesan</h3></div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label class="form-label">Nama Lengkap <span class="required">*</span></label>
                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($currentUser['full_name'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">No. WhatsApp/Telepon <span class="required">*</span></label>
                                    <div style="display:flex; align-items:stretch;">
                                        <span style="display:flex; align-items:center; padding:0 12px; background:var(--gray-100); border:1.5px solid var(--border-color); border-right:none; border-radius:var(--border-radius) 0 0 var(--border-radius); font-size:0.9rem; font-weight:600; color:var(--gray-600); white-space:nowrap;">+62</span>
                                        <input type="text" name="phone" id="phoneInput" class="form-control" 
                                               style="border-radius:0 var(--border-radius) var(--border-radius) 0;"
                                               value="<?= htmlspecialchars(ltrim(preg_replace('/^\+?62/', '', $currentUser['phone'] ?? ''), '0')) ?>" 
                                               placeholder="8xxxxxxxxxx" required
                                               oninput="formatPhoneInput(this)">
                                    </div>
                                    <small class="text-muted" style="font-size:0.75rem; margin-top:4px; display:block;">Contoh: 81234567890 (tanpa angka 0 di depan)</small>
                                    <!-- Hidden field to store the full number with +62 for form submission -->
                                    <input type="hidden" name="phone_raw" id="phoneRaw">
                                </div>
                                
                                <div class="form-group" style="margin-top: 20px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                                        <input type="checkbox" name="is_dropship" id="isDropship" value="1" onchange="toggleDropship(this)">
                                        Kirim sebagai Dropship / Hadiah (Atas Nama Lain)
                                    </label>
                                </div>

                                <div id="dropshipFields" style="display: none; padding: 16px; background: #fdf2ef; border: 1px solid #f9d8cc; border-radius: 8px; margin-top: 12px;">
                                    <div class="form-group" style="width: 100%; min-width: 100%;">
                                        <label class="form-label">Nama Penerima <span class="required">*</span></label>
                                        <input type="text" name="dropship_receiver_name" class="form-control" id="dropshipReceiver" placeholder="Nama Orang yang Menerima" style="min-width: 100%; width: 100%; display: block; box-sizing: border-box;">
                                    </div>
                                    <div class="form-group mb-0">
                                        <label class="form-label">No. HP Penerima <span class="required">*</span></label>
                                        <input type="text" name="dropship_receiver_phone" class="form-control" id="dropshipReceiverPhone" placeholder="Contoh: 081234567890">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ================================
                             KARTU: ALAMAT PENGIRIMAN
                        ================================ -->
                        <div class="card mb-16" style="background-color: #fdfbf7; border: 1px solid #f0e6d2;">
                            <div class="card-header" style="border-bottom: 1px solid #f0e6d2;">
                                <h3 class="card-title" style="color: #1f2937;">
                                    Alamat Pengiriman
                                </h3>
                            </div>
                            <div class="card-body">
                                <!-- === MODE DROPDOWN WILAYAH (DATABASE LOKAL) === -->
                                <div class="address-dropdown-grid" style="margin-bottom: 12px;">
                                    <div class="form-group" style="margin-bottom:0; width: 100%; min-width: 100%;">
                                        <label class="form-label" style="color: #4b5563;">Provinsi <span class="required" style="color: #ef4444;">*</span></label>
                                        <select name="province_id" id="selectProvince" class="form-control" required onchange="loadCities(this.value)" style="min-width: 100%; width: 100%; display: block; box-sizing: border-box;">
                                            <option value="">-- Pilih Provinsi --</option>
                                        </select>
                                        <input type="hidden" name="province_name" id="provinceName">
                                    </div>
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label class="form-label" style="color: #4b5563;">Kota / Kabupaten <span class="required" style="color: #ef4444;">*</span></label>
                                        <select name="city_id" id="selectCity" class="form-control" required onchange="loadDistricts(this.value)" disabled>
                                            <option value="">-- Pilih Kota/Kab --</option>
                                        </select>
                                        <input type="hidden" name="city_name" id="cityName">
                                    </div>
                                    <div class="form-group address-full-width" style="margin-bottom:0;">
                                        <label class="form-label" style="color: #4b5563;">Kecamatan <span class="required" style="color: #ef4444;">*</span></label>
                                        <select name="district_id" id="selectDistrict" class="form-control" required disabled onchange="setDistrictName()">
                                            <option value="">-- Pilih Kecamatan --</option>
                                        </select>
                                        <input type="hidden" name="district_name" id="districtName">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" style="color: #4b5563;">Detail Alamat (Desa / Gang / RT/RW) <span class="required" style="color: #ef4444;">*</span></label>
                                    <textarea name="street_detail" class="form-control" rows="3" placeholder="Contoh: Jl. Mawar No. 5, RT 02/03" required></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- ================================
                             KARTU: PENGIRIMAN & ONGKIR
                        ================================ -->
                        <div class="card mb-16">
                            <div class="card-header">
                                <h3 class="card-title">
                                    Opsi Pengiriman
                                </h3>
                            </div>
                            <div class="card-body">
                                <?php if ($enableShippingApi): ?>
                                <!-- === MODE CEK ONGKIR OTOMATIS === -->
                                <div style="background:var(--gray-50); border:1.5px solid var(--border-color); border-radius:8px; padding:14px; margin-bottom:14px;">
                                    <p style="font-size:0.8125rem; color:var(--gray-600); margin:0 0 12px;">Pilih kurir dan klik <strong>Cek Ongkir</strong> untuk mengetahui biaya pengiriman ke alamat Anda.</p>
                                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                                        <div style="flex:1; min-width:140px;">
                                            <label class="form-label" style="font-size:0.8125rem;">Kurir</label>
                                            <select id="selectCourier" class="form-control" style="font-size:0.875rem;">
                                                <option value="jnt">J&T Express</option>
                                                <option value="pos">Pos Indonesia</option>
                                                <option value="anteraja">Anteraja</option>
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-primary" onclick="cekOngkir()" id="btnCekOngkir" style="white-space:nowrap; padding:10px 18px;">
                                            🔍 Cek Ongkir
                                        </button>
                                    </div>
                                    <div id="ongkirStatus" style="margin-top:10px; font-size:0.8125rem; color:var(--gray-500);"></div>
                                    <div id="ongkirResult" class="shipping-options-list"></div>
                                </div>

                                <div class="form-group" style="margin-bottom:0;">
                                    <label class="form-label">Atau pilih:</label>
                                    <div style="display:grid; grid-template-columns:1fr; gap:12px;">
                                        <label class="shipping-option-item" id="pickupOption" onclick="selectPickup()" style="margin:0;">
                                            <input type="radio" name="courier_name" value="Ambil di Toko" id="radioPickup" required>
                                            <div>
                                                <div style="font-weight:600;">🏪 Ambil di Toko</div>
                                                <div style="font-size:0.75rem; color:var(--gray-500);">Gratis — Ambil langsung di toko kami</div>
                                            </div>
                                        </label>
                                        <label class="shipping-option-item" id="codOption" onclick="selectCOD()" style="margin:0;">
                                            <input type="radio" name="courier_name" value="Ongkir Bayar di Tempat (khusus J&T Express)" id="radioCOD">
                                            <div>
                                                <div style="font-weight:600;">💵 Ongkir Bayar di Tempat (J&T Express)</div>
                                                <div style="font-size:0.75rem; color:var(--gray-500);">Biaya ongkir dibayar saat paket tiba</div>
                                            </div>
                                        </label>
                                        <label class="shipping-option-item" id="codCargoOption" onclick="selectCODCargo()" style="margin:0;">
                                            <input type="radio" name="courier_name" value="Ongkir Bayar di Tempat (J&T Cargo)" id="radioCODCargo">
                                            <div>
                                                <div style="font-weight:600;">📦 Ongkir Bayar di Tempat (J&T Cargo)</div>
                                                <div style="font-size:0.75rem; color:var(--gray-500);">Biaya ongkir dibayar saat paket tiba</div>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <?php else: ?>
                                <!-- === MODE MANUAL (tanpa API) === -->
                                <div class="form-group" style="margin-bottom:0;">
                                    <label class="form-label">Pilih Ekspedisi / Metode Pengiriman <span class="required">*</span></label>
                                    <select name="courier_name" class="form-control" required onchange="updateTotalDisplay()">
                                        <option value="">Pilih Opsi</option>
                                        <option value="Ambil di Toko">Ambil di Toko</option>
                                        <option value="Ongkir Bayar di Tempat (khusus J&T Express)">Ongkir Bayar di Tempat (J&T Express)</option>
                                        <option value="Ongkir Bayar di Tempat (J&T Cargo)">Ongkir Bayar di Tempat (J&T Cargo)</option>
                                        <option value="Ekspedisi J&T Express">J&T Express</option>
                                        <option value="Ekspedisi Pos Indonesia">Pos Indonesia</option>
                                        <option value="Ekspedisi Anteraja">Anteraja</option>
                                    </select>
                                </div>
                                <?php endif; ?><!--  -->
                            </div>
                        </div>

                        <!-- CATATAN -->
                        <div class="card mb-16">
                            <div class="card-body">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label class="form-label">Catatan Tambahan</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Catatan pesanan (opsional)"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ================================
                         KOLOM KANAN: RINGKASAN
                    ================================ -->
                    <div>
                        <div class="card mb-16">
                            <div class="card-header"><h3 class="card-title">Detail Pesanan</h3></div>
                            <div class="card-body" style="padding:0;">
                                <?php foreach ($_SESSION['shop_cart'] as $item): ?>
                                    <?php $price = getSellingPrice($item['variation_id'], $item['qty']); ?>
                                    <div style="display:flex; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); font-size: 0.875rem;">
                                        <div>
                                            <span class="text-bold"><?= htmlspecialchars($item['product_name']) ?></span>
                                            <?php if ($item['variation_name']): ?>
                                                <span class="text-muted"> (<?= htmlspecialchars($item['variation_name']) ?>)</span>
                                            <?php endif; ?>
                                            <span class="text-muted"> × <?= $item['qty'] ?></span>
                                        </div>
                                        <span class="text-bold"><?= formatRupiah($price * $item['qty']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <div style="padding:12px 16px; font-size: 0.875rem; text-align: right; background: var(--gray-50);">
                                    <span class="text-muted">Estimasi Berat:</span> <span class="text-bold"><?= number_format($cartWeight / 1000, 2) ?> kg</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="cart-summary" style="position: sticky; top: 20px;">
                            <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 16px;">Ringkasan Pembayaran</h3>
                            <div class="cart-summary-row">
                                <span>Subtotal Barang</span>
                                <span><?= formatRupiah($cartTotal) ?></span>
                            </div>
                            <div class="cart-summary-row" id="shippingRow" style="display:none;">
                                <span id="shippingLabel">Ongkos Kirim</span>
                                <span id="displayShippingCost">Rp 0</span>
                            </div>
                            <div class="cart-summary-row total">
                                <span>Total Bayar</span>
                                <span id="text_total"><?= formatRupiah($cartTotal) ?></span>
                            </div>
                            <div style="background: var(--gold-50); border: 1px solid var(--gold-200); border-radius: 8px; padding: 12px; margin: 16px 0; font-size: 0.8125rem; color: var(--gold-700);">
                                📱 Setelah checkout dan kirim bukti TF, hubungi admin (WA) untuk konfirmasi pesanan.
                            </div>
                            
                            <!-- Payment Methods UI -->
                            <div id="paymentMethodsArea" style="margin: 16px 0;">
                                <h4 style="font-size: 0.875rem; font-weight: 700; margin-bottom: 12px;">Metode Pembayaran</h4>
                                
                                <div class="payment-methods-container">
                                    <?php if (!empty($paymentBanks)): ?>
                                    <div class="pm-group">
                                        <div class="pm-header" onclick="togglePaymentGroup(this)">
                                            <div class="pm-header-title">🏦 Bank Transfer</div>
                                            <div class="pm-header-logos">
                                                <?php foreach ($paymentBanks as $b): ?>
                                                    <?php if (!empty($b['logo'])): ?>
                                                        <img src="<?= ASSETS_URL ?>/img/<?= $b['logo'] ?>" alt="<?= $b['name'] ?>" loading="lazy" decoding="async" style="height:14px; border-radius:2px; vertical-align:middle; margin:0 2px; border:1px solid #eee; padding:1px; background:#fff;">
                                                    <?php else: ?>
                                                        <span style="font-size:0.7rem; background:#fff; border:1px solid #ddd; padding:2px 4px; border-radius:4px;"><?= $b['name'] ?></span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="pm-body">
                                            <?php foreach ($paymentBanks as $b): ?>
                                            <label class="pm-option">
                                                <input type="radio" name="payment_method" value="Bank <?= $b['name'] ?>">
                                                <div class="pm-option-name" style="display:flex; align-items:center; gap:8px;">
                                                    <?php if (!empty($b['logo'])): ?>
                                                        <img src="<?= ASSETS_URL ?>/img/<?= $b['logo'] ?>" alt="<?= $b['name'] ?>" loading="lazy" decoding="async" style="height:18px; max-width:40px; object-fit:contain;">
                                                    <?php endif; ?>
                                                    <span>Bank <?= $b['name'] ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($paymentEwallets)): ?>
                                    <div class="pm-group">
                                        <div class="pm-header" onclick="togglePaymentGroup(this)">
                                            <div class="pm-header-title">💳 E-Wallet</div>
                                            <div class="pm-header-logos">
                                                <?php foreach ($paymentEwallets as $e): ?>
                                                    <?php if (!empty($e['logo'])): ?>
                                                        <img src="<?= ASSETS_URL ?>/img/<?= $e['logo'] ?>" alt="<?= $e['name'] ?>" loading="lazy" decoding="async" style="height:14px; border-radius:2px; vertical-align:middle; margin:0 2px; border:1px solid #eee; padding:1px; background:#fff;">
                                                    <?php else: ?>
                                                        <span style="font-size:0.7rem; background:#fff; border:1px solid #ddd; padding:2px 4px; border-radius:4px;"><?= $e['name'] ?></span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="pm-body">
                                            <?php foreach ($paymentEwallets as $e): ?>
                                            <label class="pm-option">
                                                <input type="radio" name="payment_method" value="E-Wallet <?= $e['name'] ?>">
                                                <div class="pm-option-name" style="display:flex; align-items:center; gap:8px;">
                                                    <?php if (!empty($e['logo'])): ?>
                                                        <img src="<?= ASSETS_URL ?>/img/<?= $e['logo'] ?>" alt="<?= $e['name'] ?>" loading="lazy" decoding="async" style="height:18px; max-width:40px; object-fit:contain;">
                                                    <?php endif; ?>
                                                    <span><?= $e['name'] ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($paymentQris['image'])): ?>
                                    <div class="pm-group">
                                        <div class="pm-header" onclick="togglePaymentGroup(this)">
                                            <div class="pm-header-title">📱 QRIS</div>
                                            <div class="pm-header-logos">
                                                <span style="font-size:0.7rem; background:#fff; border:1px solid #ddd; padding:2px 4px; border-radius:4px;">QRIS</span>
                                            </div>
                                        </div>
                                        <div class="pm-body">
                                            <label class="pm-option">
                                                <input type="radio" name="payment_method" value="QRIS">
                                                <div class="pm-option-name"><?= htmlspecialchars($paymentQris['name'] ?: 'Bayar dengan QRIS') ?></div>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div id="paymentErrorMsg" style="color:var(--danger); font-size:0.8rem; display:none; margin-top:-8px; margin-bottom:8px;">Silakan pilih metode pembayaran.</div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary" style="width:100%; font-size: 1rem; padding: 14px;" id="btnCheckout">
                                Buat Pesanan
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <footer class="shop-footer">
            <div class="shop-footer-bottom">&copy; <?= date('Y') ?> <?= $storeName ?></div>
        </footer>
    </div>
    <script src="<?= ASSETS_URL ?>/js/app.js"></script>
    <script>
    // =============================================
    // CONSTANTS
    // =============================================
    var BASE_URL       = '<?= BASE_URL ?>';
    var CART_TOTAL     = <?= $cartTotal ?>;
    var CART_WEIGHT    = <?= $cartWeight ?>;
    var ENABLE_ADDRESS = <?= $enableAddressApi  ? 'true' : 'false' ?>;
    var ENABLE_ONGKIR  = <?= $enableShippingApi ? 'true' : 'false' ?>;
    var ORIGIN_CITY_ID = <?= $originCityId ?>;
    var DEFAULT_SHIPPING = <?= $defaultShippingCost ?>;

    // =============================================
    // FORMAT NOMOR TELEPON (+62)
    // =============================================
    function formatPhoneInput(input) {
        // Hapus semua karakter kecuali angka
        var val = input.value.replace(/[^0-9]/g, '');
        // Hapus awalan 0 atau 62 jika user mengetikkan sendiri
        if (val.startsWith('620')) val = val.slice(2);
        else if (val.startsWith('62')) val = val.slice(2);
        else if (val.startsWith('0')) val = val.slice(1);
        input.value = val;
    }

    // Pastikan format +62 saat submit form
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('checkoutForm');
        if (form) {
            form.addEventListener('submit', function() {
                var phoneInput = document.getElementById('phoneInput');
                if (phoneInput) {
                    formatPhoneInput(phoneInput);
                }
            }, true);
        }
        // Format nilai awal jika ada
        var phoneInput = document.getElementById('phoneInput');
        if (phoneInput && phoneInput.value) {
            formatPhoneInput(phoneInput);
        }
    });

    // =============================================
    // API WILAYAH — Dropdown Bertingkat (Database Lokal)
    // =============================================
    var WILAYAH_API = BASE_URL + '/api/wilayah.php';

    function setSelectDisabled(selectId, disabled, defaultText) {
        var el = document.getElementById(selectId);
        if (!el) return;
        el.disabled = disabled;
        el.innerHTML = '<option value="">' + defaultText + '</option>';
    }

    function loadProvinces() {
        fetch(BASE_URL + '/api/ongkir.php?action=provinces')
            .then(r => r.json())
            .then(resp => {
                if (!resp.success) return;
                var sel = document.getElementById('selectProvince');
                if (!sel) return;
                resp.provinces.forEach(p => {
                    var opt = document.createElement('option');
                    opt.value = p.province_id;
                    opt.textContent = p.province;
                    opt.dataset.name = p.province;
                    sel.appendChild(opt);
                });
            })
            .catch(function() {
                console.error('Gagal memuat data provinsi dari server.');
            });
    }

    function loadCities(provinceId) {
        if (!provinceId) {
            setSelectDisabled('selectCity', true, '-- Pilih Kota/Kab --');
            setSelectDisabled('selectDistrict', true, '-- Pilih Kecamatan --');
            return;
        }
        // Simpan nama provinsi
        var pSel = document.getElementById('selectProvince');
        document.getElementById('provinceName').value = pSel.options[pSel.selectedIndex].dataset.name || pSel.options[pSel.selectedIndex].text;

        setSelectDisabled('selectCity', true, 'Memuat...');
        setSelectDisabled('selectDistrict', true, '-- Pilih Kecamatan --');

        fetch(BASE_URL + '/api/ongkir.php?action=cities&province=' + encodeURIComponent(provinceId))
            .then(r => r.json())
            .then(resp => {
                var sel = document.getElementById('selectCity');
                sel.innerHTML = '<option value="">-- Pilih Kota/Kab --</option>';
                if (!resp.success) { sel.disabled = false; return; }
                resp.cities.forEach(c => {
                    var opt = document.createElement('option');
                    opt.value = c.city_id;
                    var typeLabel = c.type ? c.type + ' ' : '';
                    opt.textContent = typeLabel + c.city_name;
                    opt.dataset.name = typeLabel + c.city_name;
                    sel.appendChild(opt);
                });
                sel.disabled = false;
            })
            .catch(function() {
                setSelectDisabled('selectCity', false, '-- Pilih Kota/Kab --');
            });
    }

    function loadDistricts(cityId) {
        if (!cityId) {
            setSelectDisabled('selectDistrict', true, '-- Pilih Kecamatan --');
            return;
        }
        // Simpan nama kota
        var cSel = document.getElementById('selectCity');
        var selectedOpt = cSel.options[cSel.selectedIndex];
        document.getElementById('cityName').value = selectedOpt.dataset.name || selectedOpt.text;
        
        var destCityInput = document.getElementById('destinationCityId');
        if(destCityInput) destCityInput.value = cityId;

        setSelectDisabled('selectDistrict', true, 'Memuat...');

        fetch(BASE_URL + '/api/ongkir.php?action=subdistricts&city=' + encodeURIComponent(cityId))
            .then(r => r.json())
            .then(resp => {
                var sel = document.getElementById('selectDistrict');
                sel.innerHTML = '<option value="">-- Pilih Kecamatan --</option>';
                if (!resp.success || !resp.subdistricts || resp.subdistricts.length === 0) { 
                    setSelectDisabled('selectDistrict', true, 'Tidak tersedia (Gunakan Kota)');
                    return; 
                }
                resp.subdistricts.forEach(d => {
                    var opt = document.createElement('option');
                    opt.value = d.subdistrict_id;
                    opt.textContent = d.subdistrict_name;
                    opt.dataset.name = d.subdistrict_name;
                    sel.appendChild(opt);
                });
                sel.disabled = false;
            })
            .catch(function() {
                setSelectDisabled('selectDistrict', false, '-- Pilih Kecamatan --');
            });
    }

    function setDistrictName() {
        var dSel = document.getElementById('selectDistrict');
        if (dSel && dSel.selectedIndex > 0) {
            var selectedOpt = dSel.options[dSel.selectedIndex];
            document.getElementById('districtName').value = selectedOpt.dataset.name || selectedOpt.text;
        } else {
            document.getElementById('districtName').value = '';
        }
    }

    // =============================================
    // CEK ONGKIR (RajaOngkir via PHP Proxy)
    // =============================================
    var selectedShippingCost = 0;

    function formatRupiah(amount) {
        return 'Rp ' + Math.round(amount).toLocaleString('id-ID');
    }

    function updateTotalDisplay() {
        var total = CART_TOTAL + selectedShippingCost;
        document.getElementById('text_total').textContent = formatRupiah(total);

        var shippingRow = document.getElementById('shippingRow');
        var displayCost = document.getElementById('displayShippingCost');
        if (shippingRow) {
            shippingRow.style.display = 'flex';
            if (selectedShippingCost > 0) {
                displayCost.textContent = formatRupiah(selectedShippingCost);
            } else {
                var cod = document.getElementById('radioCOD');
                var pickup = document.getElementById('radioPickup');
                var manualSel = document.querySelector('select[name="courier_name"]');
                
                var labelEl = document.getElementById('shippingLabel');
                
                if (cod && cod.checked) {
                    displayCost.textContent = 'Bayar di Tujuan';
                } else if (pickup && pickup.checked) {
                    displayCost.textContent = 'Gratis';
                } else if (manualSel) {
                    if (manualSel.value === 'Ongkir Bayar di Tempat (khusus J&T Express)' || manualSel.value === 'Ongkir Bayar di Tempat (J&T Cargo)' || manualSel.value === 'Ongkir Bayar di Tempat') {
                        displayCost.textContent = 'Bayar di Tujuan';
                        if (labelEl) labelEl.textContent = 'Ongkir (Bayar di Tempat)';
                    } else if (manualSel.value === 'Ambil di Toko') {
                        displayCost.textContent = 'Gratis';
                        if (labelEl) labelEl.textContent = 'Ongkir (Ambil di Toko)';
                    } else if (manualSel.value.startsWith('Ekspedisi')) {
                        displayCost.textContent = 'Dihitung Admin';
                        if (labelEl) labelEl.textContent = 'Ongkir (' + manualSel.value.replace('Ekspedisi ', '') + ')';
                    } else {
                        displayCost.textContent = 'Rp 0';
                        if (labelEl) labelEl.textContent = 'Ongkos Kirim';
                        shippingRow.style.display = 'none';
                    }
                } else {
                    displayCost.textContent = 'Rp 0';
                    if (labelEl) labelEl.textContent = 'Ongkos Kirim';
                    if (!manualSel && (!cod || !cod.checked) && (!pickup || !pickup.checked)) {
                         shippingRow.style.display = 'none';
                    }
                }
            }
        }
    }

    function selectShippingOption(cost, label, radioInput) {
        // Unselect all
        document.querySelectorAll('.shipping-option-item').forEach(el => el.classList.remove('selected'));
        // Select clicked
        var parentItem = radioInput.closest('.shipping-option-item');
        if (parentItem) parentItem.classList.add('selected');

        // Uncheck pickup
        var pickup = document.getElementById('radioPickup');
        if (pickup) pickup.checked = false;
        var pickupOpt = document.getElementById('pickupOption');
        if (pickupOpt) pickupOpt.classList.remove('selected');

        // Update values
        selectedShippingCost = cost;
        document.getElementById('hiddenShippingCost').value = cost;

        // Set courier_name
        var courierInput = document.querySelector('input[name="courier_name"]');
        if (courierInput) courierInput.value = label;

        var sLabel = document.getElementById('shippingLabel');
        if (sLabel) sLabel.textContent = 'Ongkir (' + label + ')';

        updateTotalDisplay();
    }

    function selectPickup() {
        // Unselect ongkir options
        document.querySelectorAll('#ongkirResult .shipping-option-item').forEach(el => el.classList.remove('selected'));
        document.querySelectorAll('#ongkirResult input[type="radio"]').forEach(r => r.checked = false);

        var pickupOpt = document.getElementById('pickupOption');
        if (pickupOpt) pickupOpt.classList.add('selected');

        var codOpt = document.getElementById('codOption');
        if (codOpt) codOpt.classList.remove('selected');

        var flatOpt = document.getElementById('flatShippingOption');
        if (flatOpt) flatOpt.classList.remove('selected');

        var pickup = document.getElementById('radioPickup');
        if (pickup) pickup.checked = true;

        var cod = document.getElementById('radioCOD');
        if (cod) cod.checked = false;

        var codCargoOpt = document.getElementById('codCargoOption');
        if (codCargoOpt) codCargoOpt.classList.remove('selected');
        var codCargo = document.getElementById('radioCODCargo');
        if (codCargo) codCargo.checked = false;

        var flat = document.getElementById('radioFlatShipping');
        if (flat) flat.checked = false;

        selectedShippingCost = 0;
        var hiddenCost = document.getElementById('hiddenShippingCost');
        if (hiddenCost) hiddenCost.value = 0;

        var sLabel = document.getElementById('shippingLabel');
        if (sLabel) sLabel.textContent = 'Ongkir (Ambil di Toko)';

        updateTotalDisplay();
    }

    function selectCOD() {
        // Unselect ongkir API options
        document.querySelectorAll('#ongkirResult .shipping-option-item').forEach(el => el.classList.remove('selected'));
        document.querySelectorAll('#ongkirResult input[type="radio"]').forEach(r => r.checked = false);

        var pickupOpt = document.getElementById('pickupOption');
        if (pickupOpt) pickupOpt.classList.remove('selected');
        var pickup = document.getElementById('radioPickup');
        if (pickup) pickup.checked = false;

        var codOpt = document.getElementById('codOption');
        if (codOpt) codOpt.classList.add('selected');
        var cod = document.getElementById('radioCOD');
        if (cod) cod.checked = true;

        var codCargoOpt = document.getElementById('codCargoOption');
        if (codCargoOpt) codCargoOpt.classList.remove('selected');
        var codCargo = document.getElementById('radioCODCargo');
        if (codCargo) codCargo.checked = false;

        var flatOpt = document.getElementById('flatShippingOption');
        if (flatOpt) flatOpt.classList.remove('selected');
        var flat = document.getElementById('radioFlatShipping');
        if (flat) flat.checked = false;

        selectedShippingCost = 0;
        var hiddenCost = document.getElementById('hiddenShippingCost');
        if (hiddenCost) hiddenCost.value = 0;

        var sLabel = document.getElementById('shippingLabel');
        if (sLabel) sLabel.textContent = 'Ongkir (Bayar di Tempat)';

        updateTotalDisplay();
    }

    function selectCODCargo() {
        // Unselect ongkir API options
        document.querySelectorAll('#ongkirResult .shipping-option-item').forEach(el => el.classList.remove('selected'));
        document.querySelectorAll('#ongkirResult input[type="radio"]').forEach(r => r.checked = false);

        var pickupOpt = document.getElementById('pickupOption');
        if (pickupOpt) pickupOpt.classList.remove('selected');
        var pickup = document.getElementById('radioPickup');
        if (pickup) pickup.checked = false;

        var codOpt = document.getElementById('codOption');
        if (codOpt) codOpt.classList.remove('selected');
        var cod = document.getElementById('radioCOD');
        if (cod) cod.checked = false;

        var codCargoOpt = document.getElementById('codCargoOption');
        if (codCargoOpt) codCargoOpt.classList.add('selected');
        var codCargo = document.getElementById('radioCODCargo');
        if (codCargo) codCargo.checked = true;

        var flatOpt = document.getElementById('flatShippingOption');
        if (flatOpt) flatOpt.classList.remove('selected');
        var flat = document.getElementById('radioFlatShipping');
        if (flat) flat.checked = false;

        selectedShippingCost = 0;
        var hiddenCost = document.getElementById('hiddenShippingCost');
        if (hiddenCost) hiddenCost.value = 0;

        var sLabel = document.getElementById('shippingLabel');
        if (sLabel) sLabel.textContent = 'Ongkir (Bayar di Tempat)';

        updateTotalDisplay();
    }

    function selectFlatShipping() {
        // Unselect ongkir API options
        document.querySelectorAll('#ongkirResult .shipping-option-item').forEach(el => el.classList.remove('selected'));
        document.querySelectorAll('#ongkirResult input[type="radio"]').forEach(r => r.checked = false);

        var pickupOpt = document.getElementById('pickupOption');
        if (pickupOpt) pickupOpt.classList.remove('selected');
        var pickup = document.getElementById('radioPickup');
        if (pickup) pickup.checked = false;

        var codOpt = document.getElementById('codOption');
        if (codOpt) codOpt.classList.remove('selected');
        var cod = document.getElementById('radioCOD');
        if (cod) cod.checked = false;

        var codCargoOpt = document.getElementById('codCargoOption');
        if (codCargoOpt) codCargoOpt.classList.remove('selected');
        var codCargo = document.getElementById('radioCODCargo');
        if (codCargo) codCargo.checked = false;

        var flatOpt = document.getElementById('flatShippingOption');
        if (flatOpt) flatOpt.classList.add('selected');
        var flat = document.getElementById('radioFlatShipping');
        if (flat) flat.checked = true;

        selectedShippingCost = DEFAULT_SHIPPING;
        var hiddenCost = document.getElementById('hiddenShippingCost');
        if (hiddenCost) hiddenCost.value = selectedShippingCost;

        updateTotalDisplay();
    }

    function cekOngkir() {
        var cityId = document.getElementById('selectCity') ? document.getElementById('selectCity').value : '';
        var distId = document.getElementById('selectDistrict') ? document.getElementById('selectDistrict').value : '';

        if (!cityId) {
            alert('Silakan pilih kota/kabupaten tujuan terlebih dahulu.');
            return;
        }

        var courier  = document.getElementById('selectCourier').value;
        var status   = document.getElementById('ongkirStatus');
        var resultEl = document.getElementById('ongkirResult');

        status.innerHTML  = '<span class="loading-spinner"></span> Menghitung ongkos kirim...';
        resultEl.innerHTML = '';
        document.getElementById('btnCekOngkir').disabled = true;

        var fd = new FormData();
        fd.append('action', 'cost');
        fd.append('origin', ORIGIN_CITY_ID);
        fd.append('destination', cityId);
        if (distId) fd.append('subdistrict', distId);
        fd.append('weight', CART_WEIGHT);
        fd.append('courier', courier);

        fetch(BASE_URL + '/api/ongkir.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                document.getElementById('btnCekOngkir').disabled = false;
                if (!data.success) {
                    status.innerHTML = '⚠️ ' + (data.message || 'Gagal menghitung ongkir.');
                    return;
                }
                status.innerHTML = '✅ Pilih layanan pengiriman:';

                var services = data.services || [];
                if (!services.length) {
                    resultEl.innerHTML = '<p style="color:var(--gray-500); font-size:0.875rem;">Tidak ada layanan tersedia untuk rute ini.</p>';
                    return;
                }

                var courierName = courier.toUpperCase();
                services.forEach((svc, i) => {
                    // Support both Komerce V2 flat format and Legacy nested format
                    var cost, etd, serviceName, description;
                    if (typeof svc.cost === 'number') {
                        // Komerce V2 format: {cost: 22000, etd: "3 day", service: "EZ", description: "Reguler"}
                        cost = svc.cost;
                        etd  = svc.etd || '-';
                        serviceName = svc.service || '';
                        description = svc.description || '';
                    } else if (Array.isArray(svc.cost) && svc.cost.length > 0) {
                        // Legacy RajaOngkir format: {cost: [{value: 22000, etd: "3-4"}], service: "REG", description: "..."}
                        cost = svc.cost[0].value || 0;
                        etd  = svc.cost[0].etd || '-';
                        serviceName = svc.service || '';
                        description = svc.description || '';
                    } else {
                        cost = 0;
                        etd  = '-';
                        serviceName = svc.service || '';
                        description = svc.description || '';
                    }

                    var label   = courierName + ' ' + serviceName + ' — ' + description;
                    var radioId = 'shippingOpt_' + i;

                    var item = document.createElement('label');
                    item.className = 'shipping-option-item';
                    item.innerHTML = `
                        <input type="radio" name="courier_name" id="${radioId}" value="${label}" onchange="selectShippingOption(${cost}, '${label}', this)">
                        <div style="flex:1;">
                            <div style="font-weight:600; font-size:0.875rem;">${serviceName} — ${description}</div>
                            <div style="font-size:0.75rem; color:var(--gray-500);">Estimasi: ${etd}</div>
                        </div>
                        <div style="font-weight:700; color:var(--primary-700); font-size:0.9375rem; white-space:nowrap;">
                            ${formatRupiah(cost)}
                        </div>
                    `;
                    resultEl.appendChild(item);
                });
            })
            .catch(err => {
                document.getElementById('btnCekOngkir').disabled = false;
                status.innerHTML = '⚠️ Gagal terhubung ke server. Silakan coba lagi.';
            });
    }

    function toggleDropship(checkbox) {
        var fields = document.getElementById('dropshipFields');
        var reqInputs = fields.querySelectorAll('input');
        if (checkbox.checked) {
            fields.style.display = 'block';
            reqInputs.forEach(inp => inp.required = true);
        } else {
            fields.style.display = 'none';
            reqInputs.forEach(inp => inp.required = false);
        }
    }

    function togglePaymentGroup(headerEl) {
        var group = headerEl.closest('.pm-group');
        var wasActive = group.classList.contains('active');
        
        // Close all
        document.querySelectorAll('.pm-group').forEach(g => g.classList.remove('active'));
        
        // Open clicked if it wasn't active
        if (!wasActive) {
            group.classList.add('active');
        }
    }

    // Toggle Payment Method required based on Shipping (COD/Pickup doesn't strictly need it, but let's just hide/unrequire if COD)
    function checkPaymentMethodRequirement() {
        var pmArea = document.getElementById('paymentMethodsArea');
        var radios = document.querySelectorAll('input[name="payment_method"]');
        var courierInput = document.querySelector('input[name="courier_name"]') || document.querySelector('select[name="courier_name"]');
        var isCodOrPickup = false;
        
        if (courierInput) {
            var cv = courierInput.value;
            if (cv === 'Ambil di Toko' || cv.includes('Bayar di Tempat')) {
                isCodOrPickup = true;
            }
        }

        if (isCodOrPickup) {
            pmArea.style.opacity = '0.5';
        } else {
            pmArea.style.opacity = '1';
        }
    }

    // Call it on change of shipping
    document.addEventListener('change', function(e) {
        if (e.target.name === 'courier_name' || e.target.id === 'radioPickup' || e.target.id === 'radioCOD' || e.target.id === 'radioCODCargo' || e.target.id === 'radioFlatShipping') {
            setTimeout(checkPaymentMethodRequirement, 100);
        }
    });

    // =============================================
    // VALIDASI FORM
    // =============================================
    document.getElementById('checkoutForm').addEventListener('submit', function(e) {
        if (ENABLE_ONGKIR) {
            var courierVal = '';
            var radios = document.querySelectorAll('input[name="courier_name"]');
            radios.forEach(r => { if (r.checked) courierVal = r.value; });
            if (!courierVal) {
                e.preventDefault();
                alert('Silakan pilih metode pengiriman terlebih dahulu. Klik "Cek Ongkir" atau pilih "Ambil di Toko".');
                return false;
            }
        }

        // Validate payment method
        var pmRadios = document.querySelectorAll('input[name="payment_method"]');
        var isCodOrPickup = false;
        var courierInput = document.querySelector('input[name="courier_name"]') || document.querySelector('select[name="courier_name"]');
        if (courierInput) {
            var cv = courierInput.value;
            if (cv === 'Ambil di Toko' || cv.includes('Bayar di Tempat')) {
                isCodOrPickup = true;
            }
        }

        if (!isCodOrPickup && pmRadios.length > 0) {
            var pmChecked = false;
            pmRadios.forEach(r => { if (r.checked) pmChecked = true; });
            if (!pmChecked) {
                e.preventDefault();
                document.getElementById('paymentErrorMsg').style.display = 'block';
                // Open first group
                var firstGroup = document.querySelector('.pm-group');
                if (firstGroup) firstGroup.classList.add('active');
                return false;
            } else {
                document.getElementById('paymentErrorMsg').style.display = 'none';
            }
        }
    });

    // =============================================
    // INISIALISASI
    // =============================================
    loadProvinces();
    </script>
</body>
</html>
