<?php
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);

if (!isset($_GET['id'])) {
    die("ID Setoran tidak ditemukan.");
}

$id = (int)$_GET['id'];
$db = Database::conn();

$stmt = $db->prepare("
    SELECT c.*, u.full_name as cashier_name 
    FROM cash_settlements c 
    JOIN users u ON c.cashier_id = u.id 
    WHERE c.id = ?
");
$stmt->execute([$id]);
$settlement = $stmt->fetch();

if (!$settlement) {
    die("Data setoran tidak ditemukan.");
}

$storeName = getSetting('store_name', APP_NAME);
$storeAddress = getSetting('store_address', '');
$storePhone = getSetting('store_phone', '');
$storeTagline = getSetting('store_tagline', '');
$receiptFooter = getSetting('receipt_footer', 'Terima kasih atas kunjungan Anda');

// Hitung selisih
$variance = $settlement['actual_cash'] - $settlement['expected_cash'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Setoran #<?= $id ?></title>
    <style>
        @page { margin: 0; size: 58mm auto; }
        body { width: 58mm; padding: 10px 5px; font-size: 11px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; box-sizing: border-box; margin: 0 auto; color: #000; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        .mb-2 { margin-bottom: 5px; }
        .mb-4 { margin-bottom: 10px; }
        .dashed-line { border-top: 1px dashed #000; margin: 8px 0; }
        .flex-between { display: flex; justify-content: space-between; align-items: flex-start; }
    </style>
</head>
<body onload="window.print()">
    <div class="text-center text-bold" style="font-size:14px; margin-bottom: 2px;"><?= htmlspecialchars($storeName) ?></div>
    <?php if ($storeTagline): ?>
        <div class="text-center" style="font-size:11px; margin-bottom: 4px; font-style: italic;"><?= htmlspecialchars($storeTagline) ?></div>
    <?php endif; ?>
    <div class="text-center" style="font-size:10px; margin-bottom: 2px;"><?= nl2br(htmlspecialchars($storeAddress)) ?></div>
    <div class="text-center mb-4" style="font-size:10px;">Telp: <?= htmlspecialchars($storePhone) ?></div>
    
    <div class="dashed-line"></div>
    <div class="text-center text-bold" style="font-size:12px; margin: 4px 0;">STRUK SETORAN SHIFT</div>
    <div class="dashed-line"></div>
    
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>ID Setoran:</span>
        <span>#<?= $id ?></span>
    </div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>Kasir:</span>
        <span><?= htmlspecialchars($settlement['cashier_name']) ?></span>
    </div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>Buka:</span>
        <span><?= date('d/m/Y H:i', strtotime($settlement['shift_start'])) ?></span>
    </div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>Tutup:</span>
        <span><?= $settlement['shift_end'] ? date('d/m/Y H:i', strtotime($settlement['shift_end'])) : '-' ?></span>
    </div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>Total Trx:</span>
        <span><?= number_format($settlement['total_transactions'], 0, ',', '.') ?></span>
    </div>
    
    <div class="dashed-line"></div>
    <div class="text-bold mb-2" style="font-size:10px;">PENERIMAAN SISTEM:</div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>Tunai</span>
        <span><?= formatRupiah($settlement['expected_cash']) ?></span>
    </div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>Transfer Bank</span>
        <span><?= formatRupiah($settlement['total_transfer']) ?></span>
    </div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>E-Wallet</span>
        <span><?= formatRupiah($settlement['total_emaal']) ?></span>
    </div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>QRIS</span>
        <span><?= formatRupiah($settlement['total_bsi']) ?></span>
    </div>
    <div class="flex-between text-bold" style="font-size:10px; margin-bottom:2px; margin-top:4px;">
        <span>Total Omzet:</span>
        <?php $totalSistem = $settlement['expected_cash'] + $settlement['total_transfer'] + $settlement['total_emaal'] + $settlement['total_bsi']; ?>
        <span><?= formatRupiah($totalSistem) ?></span>
    </div>
    
    <div class="dashed-line"></div>
    <div class="text-bold mb-2" style="font-size:10px;">PENGELUARAN:</div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>Refund/Retur</span>
        <span><?= formatRupiah($settlement['total_refund']) ?></span>
    </div>
    
    <div class="dashed-line"></div>
    <div class="text-bold mb-2" style="font-size:10px;">SETORAN FISIK (CASH):</div>
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span>Uang Fisik di Laci</span>
        <span><?= formatRupiah($settlement['actual_cash']) ?></span>
    </div>
    <div class="flex-between text-bold" style="font-size:10px; margin-bottom:2px;">
        <span>Selisih (Variance)</span>
        <span style="color: <?= $variance < 0 ? 'red' : ($variance > 0 ? 'green' : 'black') ?>">
            <?= $variance > 0 ? '+' : '' ?><?= formatRupiah($variance) ?>
        </span>
    </div>
    
    <?php if ($settlement['notes']): ?>
    <div class="dashed-line"></div>
    <div style="font-size:10px; margin-bottom:2px;">
        <strong>Catatan:</strong><br>
        <?= nl2br(htmlspecialchars($settlement['notes'])) ?>
    </div>
    <?php endif; ?>
    
    <div class="dashed-line"></div>
    <div class="text-center" style="font-size:10px; margin-top: 10px; margin-bottom: 20px;">
        <?= nl2br(htmlspecialchars($receiptFooter)) ?>
    </div>
</body>
</html>
