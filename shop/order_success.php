<?php
/**
 * Kasir Ibtidaiyah - Pesanan Berhasil
 */
require_once __DIR__ . '/../config/app.php';

$storeName = getSetting('store_name', APP_NAME);
$order = $_SESSION['last_order'] ?? null;

if (!$order) {
    redirect(BASE_URL . '/shop/index.php');
}

unset($_SESSION['last_order']);

$storePhone = getSetting('store_phone', '08123456789');
$waNum = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $storePhone));
$waNum = preg_replace('/^\+/', '', $waNum);
$waMessage = urlencode("Halo, saya *{$order['customer_name']}* ingin konfirmasi pesanan dengan nomor invoice *{$order['invoice_number']}* senilai *" . formatRupiah($order['total']) . "*. Terima kasih! 🙏");
$waLink = "https://wa.me/{$waNum}?text=$waMessage";

// Load configured payment methods for display
$paymentBanks = [];
foreach (['bca'=>'BCA', 'mandiri'=>'Mandiri', 'bni'=>'BNI', 'bri'=>'BRI', 'bsi'=>'BSI', 'emaal'=>'Emaal'] as $code => $name) {
    $logoFile = '';
    if ($code == 'bca') $logoFile = 'BCA.png';
    elseif ($code == 'mandiri') $logoFile = 'mandiri.png';
    elseif ($code == 'bni') $logoFile = 'BNI.svg';
    elseif ($code == 'bri') $logoFile = 'BRI.png';
    elseif ($code == 'bsi') $logoFile = 'BSI.jpeg';
    elseif ($code == 'emaal') $logoFile = 'emaal.png';

    $paymentBanks["Bank $name"] = [
        'name' => $name,
        'account' => getSetting("payment_bank_{$code}_account"),
        'owner' => getSetting("payment_bank_{$code}_name"),
        'logo' => $logoFile
    ];
}
$paymentEwallets = [];
$ewalletName = getSetting('payment_ewallet_name');
foreach (['dana'=>'Dana', 'ovo'=>'OVO', 'shopeepay'=>'ShopeePay'] as $code => $name) {
    $logoFile = '';
    if ($code == 'dana') $logoFile = 'dana.png';
    elseif ($code == 'ovo') $logoFile = 'ovo.png';
    elseif ($code == 'shopeepay') $logoFile = 'Shopeepay.png';

    $paymentEwallets["E-Wallet $name"] = [
        'name' => $name,
        'account' => getSetting("payment_ewallet_{$code}"),
        'owner' => $ewalletName,
        'logo' => $logoFile
    ];
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
    <title>Pesanan Berhasil - <?= $storeName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
    <style>
        .success-container {
            max-width: 500px;
            margin: 60px auto;
            text-align: center;
            padding: 0 20px;
        }
        .success-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, var(--primary-400), var(--primary-600));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: scaleIn 0.5s ease-out;
        }
        @keyframes scaleIn {
            0% { transform: scale(0); }
            60% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        .success-icon svg { animation: check 0.5s ease-out 0.3s both; }
        @keyframes check { 0% { opacity:0; transform:scale(0); } 100% { opacity:1; transform:scale(1); } }
    </style>
</head>
<body>
    <div class="shop-layout">
        <?php
        $showSearch = false;
        $searchVal  = '';
        $cartCount  = 0;
        include INCLUDES_PATH . '/shop_navbar.php';
        ?>
        
        <div class="success-container">
            <div class="success-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            
            <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--gray-900); margin-bottom: 8px;">Pesanan Berhasil! 🎉</h1>
            <p style="color: var(--gray-500); margin-bottom: 24px;">Terima kasih, pesanan Anda sedang diproses</p>
            
            <div class="card" style="text-align: left; margin-bottom: 24px;">
                <div class="card-body">
                    <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
                        <span class="text-muted">No. Invoice</span>
                        <span class="text-bold" style="font-family: monospace; font-size: 1rem;"><?= htmlspecialchars($order['invoice_number']) ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
                        <span class="text-muted">Subtotal</span>
                        <span class="text-bold"><?= formatRupiah($order['total'] - ($order['shipping_cost'] ?? 0)) ?></span>
                    </div>
                    <?php if (isset($order['shipping_cost']) && $order['shipping_cost'] > 0): ?>
                    <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
                        <span class="text-muted">Ongkos Kirim (<?= htmlspecialchars($order['courier_name'] ?? '') ?> <?= htmlspecialchars($order['courier_service'] ?? '') ?>)</span>
                        <span class="text-bold"><?= formatRupiah($order['shipping_cost']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; justify-content:space-between; margin-bottom:12px; padding-top: 12px; border-top: 1px dashed var(--gray-300);">
                        <span class="text-muted">Total Bayar</span>
                        <span class="text-bold" style="font-size: 1.25rem; color: var(--primary-700);"><?= formatRupiah($order['total']) ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span class="text-muted">Status</span>
                        <span class="badge badge-warning">Menunggu Konfirmasi</span>
                    </div>
                </div>
            </div>
            
            <?php 
            $pm = $order['payment_method'] ?? '';
            $isCod = ($pm === '') && in_array($order['courier_name'] ?? '', ['AMBIL DI TOKO', 'ONGKIR BAYAR DI TEMPAT (KHUSUS J&T EXPRESS)', 'ONGKIR BAYAR DI TEMPAT (J&T CARGO)']);
            
            if ($isCod): 
            ?>
            <div class="card" style="text-align: left; margin-bottom: 24px;">
                <div class="card-body" style="text-align: center;">
                    <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 12px;">Pembayaran di Tempat (COD)</h3>
                    <p style="color:var(--gray-600); font-size:0.9rem;">Silakan siapkan uang tunai sesuai total tagihan saat kurir atau barang tiba.</p>
                </div>
            </div>
            
            <?php elseif (isset($paymentBanks[$pm]) && $paymentBanks[$pm]['account']): $bank = $paymentBanks[$pm]; ?>
            <div class="card" style="text-align: left; margin-bottom: 24px;">
                <div class="card-body">
                    <h3 style="display:flex; align-items:center; justify-content:center; gap:8px; font-size: 1rem; font-weight: 700; margin-bottom: 16px; text-align: center;">
                        <?php if (!empty($bank['logo'])): ?>
                            <img src="<?= ASSETS_URL ?>/img/<?= $bank['logo'] ?>" alt="<?= $bank['name'] ?>" loading="lazy" decoding="async" style="height:24px; max-width:60px; object-fit:contain;">
                        <?php endif; ?>
                        Bank <?= $bank['name'] ?>
                    </h3>
                    <div style="background:var(--gray-50); border:1px solid var(--border-color); border-radius:8px; padding:16px; text-align:center;">
                        <div style="font-size:0.875rem; color:var(--gray-500); margin-bottom:4px;">Nomor Rekening</div>
                        <div style="font-size:1.25rem; font-weight:700; color:var(--gray-900); font-family:monospace; margin-bottom:4px;"><?= htmlspecialchars($bank['account']) ?></div>
                        <div style="font-size:0.875rem; color:var(--gray-600);">a/n <strong><?= htmlspecialchars($bank['owner']) ?></strong></div>
                    </div>
                </div>
            </div>
            
            <?php elseif (isset($paymentEwallets[$pm]) && $paymentEwallets[$pm]['account']): $ew = $paymentEwallets[$pm]; ?>
            <div class="card" style="text-align: left; margin-bottom: 24px;">
                <div class="card-body">
                    <h3 style="display:flex; align-items:center; justify-content:center; gap:8px; font-size: 1rem; font-weight: 700; margin-bottom: 16px; text-align: center;">
                        <?php if (!empty($ew['logo'])): ?>
                            <img src="<?= ASSETS_URL ?>/img/<?= $ew['logo'] ?>" alt="<?= $ew['name'] ?>" loading="lazy" decoding="async" style="height:24px; max-width:60px; object-fit:contain;">
                        <?php endif; ?>
                        Pembayaran <?= $ew['name'] ?>
                    </h3>
                    <div style="background:var(--gray-50); border:1px solid var(--border-color); border-radius:8px; padding:16px; text-align:center;">
                        <div style="font-size:0.875rem; color:var(--gray-500); margin-bottom:4px;">Nomor Tujuan</div>
                        <div style="font-size:1.25rem; font-weight:700; color:var(--gray-900); font-family:monospace; margin-bottom:4px;"><?= htmlspecialchars($ew['account']) ?></div>
                        <div style="font-size:0.875rem; color:var(--gray-600);">a/n <strong><?= htmlspecialchars($ew['owner']) ?></strong></div>
                    </div>
                </div>
            </div>
            
            <?php elseif ($pm === 'QRIS' && !empty($paymentQris['image'])): ?>
            <div class="card" style="text-align: left; margin-bottom: 24px;">
                <div class="card-body">
                    <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 12px; text-align: center;">Scan QRIS</h3>
                    <div style="text-align:center; font-weight:600; font-size:0.9rem; margin-bottom:8px;"><?= htmlspecialchars($paymentQris['name'] ?: 'Bayar dengan QRIS') ?></div>
                    <img src="<?= BASE_URL . '/' . htmlspecialchars($paymentQris['image']) ?>" alt="QRIS" loading="lazy" decoding="async" style="width: 100%; border-radius: 8px; border: 1px solid var(--border-color);">
                </div>
            </div>
            
            <?php endif; ?>

            <?php if (!$isCod): ?>
            <div style="background: var(--gold-50); border: 1px solid var(--gold-200); border-radius: var(--border-radius); padding: 16px; margin-bottom: 24px; font-size: 0.875rem; color: var(--gold-700);">
                📱 Setelah melakukan pembayaran, silakan hubungi kami via WhatsApp untuk konfirmasi pesanan dan pembayaran.
            </div>
            <?php else: ?>
            <div style="background: var(--gold-50); border: 1px solid var(--gold-200); border-radius: var(--border-radius); padding: 16px; margin-bottom: 24px; font-size: 0.875rem; color: var(--gold-700);">
                📱 Silakan hubungi kami via WhatsApp untuk konfirmasi pesanan.
            </div>
            <?php endif; ?>
            
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <button onclick="confirmTransferAndOpen()" class="btn btn-primary btn-lg" style="background: #25D366; border-color: #25D366; width:100%; font-size:1rem; padding:14px; cursor:pointer;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle; margin-right:6px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.5.5 0 00.611.611l4.458-1.495A11.945 11.945 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-2.33 0-4.512-.67-6.36-1.827l-.356-.212-3.692 1.237 1.237-3.692-.212-.356A9.953 9.953 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                    Konfirmasi via WhatsApp
                </button>
                <a href="<?= BASE_URL ?>/shop/index.php" class="btn btn-outline">Kembali ke Beranda</a>
            </div>

            <!-- Modal Konfirmasi Transfer -->
            <div id="transferModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center;">
                <div style="background:#fff; border-radius:16px; padding:28px 24px; max-width:380px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.2); text-align:center; animation:scaleIn 0.25s ease-out;">
                    <div style="font-size:2.5rem; margin-bottom:12px;">💸</div>
                    <h3 style="font-size:1.125rem; font-weight:700; margin-bottom:8px; color:var(--gray-900);">Konfirmasi Pembayaran</h3>
                    <p style="color:var(--gray-600); font-size:0.9rem; margin-bottom:20px; line-height:1.5;">
                        Apakah Anda <strong>sudah melakukan transfer</strong> ke rekening kami?<br>
                        <span style="font-size:0.8rem; color:var(--gray-400);">Pastikan transfer sudah selesai sebelum konfirmasi.</span>
                    </p>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <a href="<?= $waLink ?>" target="_blank" id="btnSudahTransfer"
                           onclick="closeTransferModal()"
                           class="btn btn-primary" style="background:#25D366; border-color:#25D366; font-size:0.9375rem; padding:12px;">
                            ✅ Ya, Sudah Transfer — Lanjut ke WA
                        </a>
                        <button onclick="closeTransferModal()" 
                                class="btn btn-outline" style="color:var(--danger); border-color:var(--danger); font-size:0.9rem; padding:10px;">
                            ⏳ Belum, Saya Akan Transfer Dulu
                        </button>
                    </div>
                </div>
            </div>

            <script>
            function confirmTransferAndOpen() {
                var modal = document.getElementById('transferModal');
                modal.style.display = 'flex';
            }
            function closeTransferModal() {
                var modal = document.getElementById('transferModal');
                modal.style.display = 'none';
            }
            // Close modal on backdrop click
            document.getElementById('transferModal').addEventListener('click', function(e) {
                if (e.target === this) closeTransferModal();
            });
            </script>
        </div>
        
        <footer class="shop-footer">
            <div class="shop-footer-bottom">&copy; <?= date('Y') ?> <?= $storeName ?></div>
        </footer>
    </div>
</body>
</html>
