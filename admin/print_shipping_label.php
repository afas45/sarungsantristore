<?php
/**
 * Kasir Ibtidaiyah - Cetak Label Pengiriman
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);

$db = Database::conn();
$saleId = (int)($_GET['id'] ?? 0);

if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8', false);
    }
}

$stmt = $db->prepare("
    SELECT s.*, COALESCE(c.name, s.customer_name, 'Umum') as display_customer_name, c.phone as customer_phone, c.address as customer_address, u.full_name as cashier_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON s.cashier_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();

if (!$sale) {
    die("Transaksi tidak ditemukan.");
}

$stmt = $db->prepare("SELECT * FROM shipping_details WHERE sale_id = ?");
$stmt->execute([$saleId]);
$shipping = $stmt->fetch();

$storeName    = getSetting('store_name', APP_NAME);
$storePhone   = getSetting('store_phone', '08123456789');
$storeLogo    = getSetting('store_logo', '');
$storeTagline = getSetting('store_tagline', '');
$storeAddress = getSetting('store_address', '');

// Recipient Data
$penerima      = $shipping ? $shipping['recipient_name'] : $sale['display_customer_name'];
$telpPenerima  = $shipping ? ($shipping['phone'] ?? $sale['customer_phone']) : $sale['customer_phone'];
$alamatPenerima = $shipping ? $shipping['shipping_address'] : $sale['customer_address'];
$ekspedisi = html_entity_decode((string)($shipping['courier_name'] ?? $sale['shipping_courier'] ?? 'Lainnya'), ENT_QUOTES, 'UTF-8');
$ekspedisi = strtoupper($ekspedisi);
if (empty($ekspedisi)) $ekspedisi = 'LAINNYA';

// Data wilayah terpisah (dari kolom baru)
$provinceName  = $shipping['province_name']  ?? '';
$cityName      = $shipping['city_name']      ?? '';
$districtName  = $shipping['district_name']  ?? '';

$isDropship   = !empty($sale['is_dropship']);
$namaPengirim = $isDropship ? $sale['display_customer_name'] : $storeName;
$telpPengirim = $isDropship ? $sale['customer_phone'] : $storePhone;
$alamatPengirim = $isDropship ? $sale['customer_address'] : $storeAddress;

// Menyusun alamat lengkap
$alamatLengkap = $alamatPenerima;
if ($districtName) $alamatLengkap .= ', Kec. ' . $districtName;
if ($cityName) $alamatLengkap .= ', ' . $cityName;
if ($provinceName) $alamatLengkap .= ', ' . $provinceName;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Label Pengiriman - <?= h($sale['invoice_number']) ?></title>
    <style>
        @page { margin: 0; size: 100mm 150mm; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            background: #e2e8f0;
        }
        .label-container {
            width: 100mm;
            height: 150mm;
            background: white;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            background-image: url('data:image/svg+xml;utf8,<svg width="40" height="40" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg"><path d="M20 20.5V18H0v-2h20v-2H0v-2h20v-2H0V8h20V6H0V4h20V2H0V0h22v20h2V0h2v20h2V0h2v20h2V0h2v20h2V0h2v20h2v2H20v-1.5zM0 20h2v20H0V20zm4 0h2v20H4V20zm4 0h2v20H8V20zm4 0h2v20h-2V20zm4 0h2v20h-2V20zm4 4h20v2H20v-2zm0 4h20v2H20v-2zm0 4h20v2H20v-2zm0 4h20v2H20v-2z" fill="%235d4037" fill-opacity="0.03" fill-rule="evenodd"/></svg>');
            position: relative;
        }
        .header-wrapper {
            background: #3e2723; /* Coklat tua pekat */
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 15px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            border-bottom: 3px solid #4e342e;
        }
        .store-identity {
            flex: 0 0 38%;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            border-right: 1px solid rgba(255,255,255,0.3);
            padding-right: 10px;
        }
        .store-identity img {
            max-width: 60px;
            max-height: 60px;
            margin-bottom: 5px;
            background: transparent;
        }
        .store-identity .s-name {
            font-size: 13px;
            font-weight: bold;
            line-height: 1.2;
            margin-bottom: 2px;
            color: #FFD700; /* Warna kuning */
        }
        .store-identity .s-tagline {
            font-size: 9px;
            opacity: 0.8;
            line-height: 1.1;
        }
        .fragile-box {
            flex: 1;
            text-align: right;
            padding-left: 10px;
        }
        .fragile-box h2 {
            margin: 0;
            font-size: 9px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .fragile-box h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 900;
            letter-spacing: 2px;
        }
        .fragile-box .thank-you {
            font-size: 11px;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .icons {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 5px;
        }
        .icons svg {
            width: 22px;
            height: 22px;
            background: rgba(255,255,255,0.1);
            padding: 3px;
            border-radius: 4px;
        }
        /* Top Section: Sender + Ekspedisi */
        .top-section {
            display: flex;
            padding: 12px 15px;
            border-bottom: 2px solid #5d4037;
            background: rgba(255, 255, 255, 0.9);
            font-size: 12px;
        }
        .sender-box {
            flex: 1;
        }
        .sender-box .label {
            font-weight: bold;
            color: #5d4037;
            font-size: 11px;
            margin-bottom: 2px;
        }
        .sender-box p {
            margin: 0 0 6px 0;
            font-weight: 600;
        }
        .ekspedisi-box {
            text-align: right;
            padding-left: 10px;
        }
        .ekspedisi-box .box-val {
            font-size: 10px;
            font-weight: normal;
            border: 2px solid #5d4037;
            padding: 3px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 4px;
            color: #5d4037;
            text-transform: uppercase;
            max-width: 120px;
            text-align: center;
            line-height: 1.3;
            word-break: break-word;
        }
        /* Middle Section: Receiver */
        .receiver-section {
            flex: 1;
            padding: 15px;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            flex-direction: column;
        }
        .receiver-box {
            border: 2px solid #5d4037;
            border-radius: 8px;
            padding: 15px;
            flex: 1;
            font-size: 13px;
            background: #fff;
        }
        .receiver-box .line {
            display: flex;
            border-bottom: 1px dashed #5d4037;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }
        .receiver-box .line.no-border {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .receiver-box .label {
            width: 70px;
            font-weight: bold;
            color: #5d4037;
            flex-shrink: 0;
        }
        .receiver-box .colon {
            width: 15px;
            font-weight: bold;
            color: #5d4037;
            flex-shrink: 0;
        }
        .receiver-box .val {
            flex: 1;
            font-weight: bold;
        }
        .footer-text {
            text-align: center;
            margin-top: 10px;
            color: #dc2626;
            font-size: 12px;
            font-weight: bold;
            background: #fee2e2;
            padding: 6px;
            border-radius: 4px;
            border: 1px dashed #fca5a5;
        }
        @media screen {
            body { padding: 20px; }
            .label-container { border: 1px solid #ccc; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        }
        @media print {
            body { padding: 0; background: none; }
            .label-container { border: none; box-shadow: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="label-container">
        
        <div class="header-wrapper">
            <div class="store-identity">
                <?php if (!empty($storeLogo)): ?>
                    <img src="<?= BASE_URL . '/' . h($storeLogo) ?>" alt="Logo">
                <?php endif; ?>
                <div class="s-name"><?= h($storeName) ?></div>
                <?php if (!empty($storeTagline)): ?>
                    <div class="s-tagline"><?= h($storeTagline) ?></div>
                <?php endif; ?>
            </div>
            
            <div class="fragile-box">
                <h2>Handle With Care</h2>
                <h1>FRAGILE</h1>
                <div class="thank-you">THANK YOU</div>
                <div class="icons">
                    <!-- Wine Glass (Red) -->
                    <svg viewBox="0 0 24 24" fill="none" stroke="#f43f5e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 22h8"/><path d="M12 15v7"/><path d="M12 15a7.5 7.5 0 0 0 7.5-7.5V4a1 1 0 0 0-1-1H5.5a1 1 0 0 0-1 1v3.5A7.5 7.5 0 0 0 12 15z"/></svg>
                    <!-- Umbrella (Purple) -->
                    <svg viewBox="0 0 24 24" fill="none" stroke="#a855f7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v2"/><path d="M12 12v9a2 2 0 0 0 4 0"/><path d="M22 12A10 10 0 0 0 2 12h20z"/></svg>
                    <!-- Recycle (Green) -->
                    <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="7.5 4.21 12 6.81 16.5 4.21"/><polyline points="7.5 19.79 7.5 14.6 3 12"/><polyline points="21 12 16.5 14.6 16.5 19.79"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    <!-- This Side Up (Blue) -->
                    <svg viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="M5 12l7-7 7 7"/></svg>
                </div>
            </div>
        </div>

        <div class="top-section">
            <div class="sender-box">
                <div class="label">PENGIRIM:</div>
                <p style="margin-bottom: 2px;"><?= h($namaPengirim) ?></p>
                <?php if ($isDropship && !empty($alamatPengirim)): ?>
                <p style="font-weight: normal; font-size: 10px; margin-bottom: 4px; line-height: 1.2;"><?= h($alamatPengirim) ?></p>
                <?php endif; ?>
                <div class="label">TELP:</div>
                <p><?= h($telpPengirim) ?></p>
            </div>
            <div class="ekspedisi-box">
                <div class="label" style="font-weight:bold; color:#5d4037; font-size:11px;">EKSPEDISI:</div>
                <div class="box-val"><?= h($ekspedisi) ?></div>
            </div>
        </div>

        <div class="receiver-section">
            <div class="receiver-box">
                <div class="line">
                    <span class="label">PENERIMA</span> 
                    <span class="colon">:</span>
                    <span class="val"><?= h($penerima) ?></span>
                </div>
                <div class="line">
                    <span class="label">TELP</span> 
                    <span class="colon">:</span>
                    <span class="val"><?= h($telpPenerima) ?></span>
                </div>
                <div class="line no-border">
                    <span class="label">ALAMAT</span>
                    <span class="colon">:</span>
                    <span class="val"><?= h(trim($alamatLengkap)) ?></span>
                </div>
                <?php if (!empty($sale['notes'])): ?>
                <div class="line no-border" style="margin-top: 10px;">
                    <span class="label">CATATAN</span>
                    <span class="colon">:</span>
                    <span class="val"><?= nl2br(h($sale['notes'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="footer-text">
                ⚠️ Komplain Paket? Mohon Sertakan Video Unboxing
            </div>
        </div>
        
    </div>
</body>
</html>
