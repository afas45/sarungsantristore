<?php
/**
 * Kasir Ibtidaiyah - Cetak Invoice
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);

$db = Database::conn();
$saleId = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'a4'; // a4 or thermal

if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8', false);
    }
}

$stmt = $db->prepare("
    SELECT s.*, COALESCE(s.customer_name, c.name, 'Umum') as display_customer_name, c.phone as customer_phone, c.address as customer_address, u.full_name as cashier_name
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

$stmt = $db->prepare("
    SELECT sd.*, 
    COALESCE((SELECT SUM(rd.qty_returned) FROM return_details rd JOIN returns r ON rd.return_id = r.id WHERE rd.sale_detail_id = sd.id), 0) as qty_returned
    FROM sale_details sd WHERE sd.sale_id = ?
");
$stmt->execute([$saleId]);
$rawItems = $stmt->fetchAll();

$items = [];
$returnedItems = [];
$totalRefund = 0;
foreach ($rawItems as $item) {
    // Keep original qty for the main list
    $item['subtotal'] = $item['qty'] * $item['unit_price'];
    $items[] = $item;
    
    if ($item['qty_returned'] > 0) {
        $item['refund_subtotal'] = $item['qty_returned'] * $item['unit_price'];
        $totalRefund += $item['refund_subtotal'];
        $returnedItems[] = $item;
    }
}

$sale['grand_total'] -= $totalRefund;

$stmt = $db->prepare("SELECT * FROM shipping_details WHERE sale_id = ?");
$stmt->execute([$saleId]);
$shipping = $stmt->fetch();

// Riwayat edit transaksi untuk struk dua bagian.
$editHistory = [];
$editDetails = [];
try {
    $stmt = $db->prepare("SELECT * FROM sale_edits WHERE sale_id = ? ORDER BY id ASC");
    $stmt->execute([$saleId]);
    $editHistory = $stmt->fetchAll();
    if ($editHistory) {
        $editIds = array_column($editHistory, 'id');
        $placeholders = implode(',', array_fill(0, count($editIds), '?'));
        $stmt = $db->prepare("SELECT * FROM sale_edit_details WHERE edit_id IN ($placeholders) ORDER BY id ASC");
        $stmt->execute($editIds);
        $editDetails = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $editHistory = [];
}

$originalTotal = $editHistory ? (float)$editHistory[0]['total_before'] : (float)$sale['grand_total'];
$totalAdded = $editHistory ? array_sum(array_column($editHistory, 'total_added')) : 0;
$totalReturned = $editHistory ? array_sum(array_column($editHistory, 'total_returned')) : 0;
$totalDifference = $totalAdded - $totalReturned;
$addedQuantities = [];
foreach ($editDetails as $editDetail) {
    if ($editDetail['adjustment_type'] !== 'added') continue;
    $key = $editDetail['product_variation_id'] . '|' . $editDetail['unit_price'];
    $addedQuantities[$key] = ($addedQuantities[$key] ?? 0) + (int)$editDetail['qty'];
}
foreach ($items as $itemIndex => &$item) {
    $key = $item['product_variation_id'] . '|' . $item['unit_price'];
    if (!empty($addedQuantities[$key])) {
        $removedQty = min((int)$item['qty'], $addedQuantities[$key]);
        $item['qty'] -= $removedQty;
        $item['subtotal'] = $item['qty'] * $item['unit_price'];
        $addedQuantities[$key] -= $removedQty;
    }
}
unset($item);
$items = array_values(array_filter($items, static fn($item) => (int)$item['qty'] > 0));
$editDisplayItems = array_values(array_filter($editDetails, static fn($item) => $item['adjustment_type'] === 'added' || $item['adjustment_type'] === 'returned'));

// Format phone numbers (change +62 to 0 for local displays to make it easier for couriers)
$displayPhone = $shipping ? ($shipping['phone'] ?? '') : ($sale['customer_phone'] ?? '');
if ($displayPhone) {
    if (strpos($displayPhone, '+62') === 0) {
        $displayPhone = '0' . substr($displayPhone, 3);
    } elseif (strpos($displayPhone, '62') === 0) {
        $displayPhone = '0' . substr($displayPhone, 2);
    }
}
$displayPhone = $displayPhone ?: '-';

$cashierName = $sale['cashier_name'] ?? ($_SESSION['user']['full_name'] ?? 'Admin');
$isDropship  = !empty($sale['is_dropship']);
$shippingAddress = !empty($shipping['shipping_address']) ? $shipping['shipping_address'] : ($sale['customer_address'] ?? '');


$storeName = getSetting('store_name', APP_NAME);
$storeAddress = getSetting('store_address', 'Jl. Pendidikan No. 1');
$storePhone = getSetting('store_phone', '08123456789');
$storeLogo = getSetting('store_logo', '');
$storeTagline = getSetting('store_tagline', '');
$receiptFooter = getSetting('receipt_footer', 'Terima kasih atas kunjungan Anda');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= h($sale['invoice_number']) ?></title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; margin: 0; padding: 0; color: #333; }
        
        <?php if ($type === 'thermal'): ?>
        /* THERMAL 58MM STYLES */
        @page { margin: 0; size: 58mm auto; }
        body { font-size: 11px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; box-sizing: border-box; text-align: center; margin: 0; padding: 4mm 0 8mm; background: #fff; }
        .thermal-wrapper { width: 58mm; max-width: 58mm; margin: 0 auto; padding: 0 4mm; text-align: left; display: inline-block; box-sizing: border-box; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        .mb-2 { margin-bottom: 5px; }
        .mb-4 { margin-bottom: 10px; }
        .dashed-line { border-top: 1px dashed #000; margin: 8px 0; }
        .flex-between { display: flex; justify-content: space-between; align-items: flex-start; }
        <?php else: ?>
        /* A4 STYLES */
        @page { margin: 15mm; size: A4; }
        body { background: #f0f2f5; padding: 20px; }
        .invoice-box { max-width: 800px; margin: auto; padding: 20px; border: 1px solid #eee; box-shadow: 0 0 10px rgba(0, 0, 0, 0.15); font-size: 12px; line-height: 1.4; font-family: 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif; color: #555; background: #fff; }
        .invoice-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .invoice-table th, .invoice-table td { padding: 4px 6px; vertical-align: middle; border-bottom: 1px solid #eee; }
        .invoice-table thead th { background: #f9fafb; font-weight: bold; border-bottom: 2px solid #ddd; }
        .invoice-table tfoot td { border-top: 2px solid #ddd; font-weight: bold; }
        .text-bold { font-weight: bold; color: #333; }
        @media print { body { background: none; padding: 0; } .invoice-box { box-shadow: none; border: none; padding: 0; } }
        <?php endif; ?>
    </style>
</head>
<body <?= isset($_GET['preview']) && $_GET['preview'] == '1' ? '' : 'onload="window.print()"' ?>>

<?php if ($type === 'thermal'): ?>
    <!-- THERMAL LAYOUT -->
    <div class="thermal-wrapper">
    <?php if ($storeLogo): ?>
        <div class="text-center" style="margin-bottom: 4px;">
            <img src="<?= BASE_URL . '/' . htmlspecialchars($storeLogo) ?>" style="max-height: 40px; object-fit: contain; filter: grayscale(100%);">
        </div>
    <?php endif; ?>
    <div class="text-center text-bold" style="font-size:14px; margin-bottom: 2px;"><?= h($storeName) ?></div>
    <?php if ($storeTagline): ?>
        <div class="text-center" style="font-size:11px; margin-bottom: 4px; font-style: italic;"><?= h($storeTagline) ?></div>
    <?php endif; ?>
    <div class="text-center" style="font-size:10px; margin-bottom: 2px;"><?= nl2br(h($storeAddress)) ?></div>
    <div class="text-center mb-4" style="font-size:10px;">Telp: <?= h($storePhone) ?></div>
    
    <div class="dashed-line"></div>
    
    <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
        <span><?= h($sale['invoice_number']) ?></span>
        <span>Ksr: <?= h($cashierName) ?></span>
    </div>
    <div class="flex-between <?= $shipping ? 'mb-2' : 'mb-4' ?>" style="font-size:10px;">
        <span><?= date('d/m/Y H:i', strtotime($sale['created_at'])) ?></span>
        <span>Plg: <?= h($shipping ? $shipping['recipient_name'] : $sale['display_customer_name']) ?></span>
    </div>
    <?php if ($isDropship): ?>
    <div class="flex-between mb-2" style="font-size:10px;">
        <span>Dropship:</span>
        <span class="text-right">Ya</span>
    </div>
    <?php endif; ?>
    
    <div class="dashed-line"></div>
    
    <?php if ($editHistory): ?>
    <div class="text-center text-bold" style="font-size:11px; margin-bottom:6px;">TRANSAKSI AWAL</div>
    <?php endif; ?>
    <?php 
    $totalQty = 0;
    foreach ($items as $index => $item): 
        $totalQty += $item['qty'];
    ?>
        <div class="mb-2" style="font-size:11px;">
            <div style="margin-bottom: 2px; text-align: left;"><?= ($index + 1) ?>. <?= h($item['product_name']) ?><?= $item['variation_name'] ? ' - '.h($item['variation_name']) : '' ?></div>
            <div class="flex-between">
                <span><?= $item['qty'] ?> x <?= formatRupiah($item['unit_price']) ?></span>
                <span><?= formatRupiah($item['subtotal']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($editHistory): ?>
    <div class="dashed-line"></div>
    <div class="text-center text-bold" style="font-size:11px; margin-bottom:6px;">RETUR / TAMBAHAN</div>
    <?php foreach ($editDisplayItems as $editIndex => $editItem): ?>
        <div style="font-size:11px; margin-bottom:6px; color:<?= $editItem['adjustment_type'] === 'returned' ? 'red' : '#222' ?>;">
            <div style="margin-bottom:2px; text-align:left;"><?= ($editIndex + 1) ?>. <?= $editItem['adjustment_type'] === 'returned' ? 'Retur ' : '' ?><?= h($editItem['product_name']) ?><?= $editItem['variation_name'] ? ' - '.h($editItem['variation_name']) : '' ?></div>
            <div class="flex-between">
                <span><?= $editItem['adjustment_type'] === 'returned' ? '-' : '' ?><?= $editItem['qty'] ?> x <?= formatRupiah($editItem['unit_price']) ?></span>
                <span><?= $editItem['adjustment_type'] === 'returned' ? '-' : '' ?><?= formatRupiah($editItem['subtotal'] ?? ($editItem['qty'] * $editItem['unit_price'])) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="dashed-line"></div>
    <div class="flex-between" style="font-size:11px; margin-bottom:3px;"><span>Total Awal</span><span><?= formatRupiah($originalTotal) ?></span></div>
    <div class="flex-between" style="font-size:11px; margin-bottom:3px;"><span>Total Selisih</span><span style="color:<?= $totalDifference < 0 ? 'red' : '#222' ?>;"> <?= $totalDifference < 0 ? '-' : '' ?><?= formatRupiah(abs($totalDifference)) ?></span></div>
    <div class="flex-between" style="font-size:11px; margin-bottom:3px;"><span>Subtotal</span><span><?= formatRupiah($sale['grand_total']) ?></span></div>
    <div class="flex-between" style="font-size:11px; margin-bottom:3px;"><span>Total Retur</span><span style="color:red;">-<?= formatRupiah($totalReturned) ?></span></div>
    <?php endif; ?>
    
    <div class="dashed-line"></div>
    
    <?php if ($totalRefund > 0): ?>
    <div class="mb-2 text-bold text-center" style="font-size:11px;">RETUR PRODUK</div>
    <?php foreach ($returnedItems as $ri): ?>
        <div class="mb-2" style="font-size:11px;">
            <div style="margin-bottom: 2px; text-align: left;"><?= h($ri['product_name']) ?><?= $ri['variation_name'] ? ' ('.h($ri['variation_name']).')' : '' ?></div>
            <div class="flex-between">
                <span style="color:red;">-<?= $ri['qty_returned'] ?> x <?= formatRupiah($ri['unit_price']) ?></span>
                <span style="color:red;">-<?= formatRupiah($ri['refund_subtotal']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="dashed-line"></div>
    <?php endif; ?>
    
    <div style="font-size:11px;">
        <?php if ($sale['discount_amount'] > 0 || $totalRefund > 0): ?>
        <div class="flex-between mb-2">
            <span>Subtotal</span>
            <span><?= formatRupiah($sale['total_amount']) ?></span>
        </div>
        <?php if ($sale['discount_amount'] > 0): ?>
        <div class="flex-between mb-2">
            <span>Diskon</span>
            <span>-<?= formatRupiah($sale['discount_amount']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($totalRefund > 0): ?>
        <div class="flex-between mb-2">
            <span>Total Retur</span>
            <span style="color:red;">-<?= formatRupiah($totalRefund) ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <div class="flex-between mb-4">
            <span class="text-bold" style="font-size:13px;">TOTAL</span>
            <span class="text-bold" style="font-size:13px;"><?= formatRupiah($sale['grand_total']) ?></span>
        </div>
        
        <?php
        $stmtPay = $db->prepare("SELECT SUM(amount) as paid, payment_method FROM payments WHERE sale_id = ? AND payment_type='Incoming' GROUP BY payment_method LIMIT 1");
        $stmtPay->execute([$sale['id']]);
        $paymentInfo = $stmtPay->fetch();
        $paid = $paymentInfo['paid'] ?? 0;
        $method = $paymentInfo['payment_method'] ?? 'Cash';
        $kembali = $paid - $sale['grand_total'];
        if($kembali < 0) $kembali = 0;
        ?>
        <div class="flex-between mb-2">
            <span>Bayar (<?= h($method) ?>)</span>
            <span><?= formatRupiah($paid) ?></span>
        </div>
        <div class="flex-between text-bold mb-4">
            <span>Kembalian</span>
            <span><?= formatRupiah($kembali) ?></span>
        </div>
    </div>
    
    <div class="dashed-line"></div>
    
    <?php if (!empty($sale['notes'])): ?>
    <div style="font-size:10px; margin-bottom:10px; text-align: left;">
        <strong>Catatan:</strong><br>
        <?= nl2br(h($sale['notes'])) ?>
    </div>
    <div class="dashed-line"></div>
    <?php endif; ?>
    
    <div class="text-center" style="font-size:10px; margin-top:10px;">
        <?= nl2br(htmlspecialchars($receiptFooter)) ?><br>
        Cetak : <?= date('d/m/Y, H.i.s') ?>
    </div>
    </div> <!-- end thermal-wrapper -->

<?php else: ?>
    <!-- A4 LAYOUT -->
    <div class="invoice-box">
        <!-- HEADER INFO -->
        <div style="display:flex; justify-content:space-between; margin-bottom: 15px; border-bottom: 2px solid #ddd; padding-bottom: 15px;">
            <div style="flex: 2; margin-right: 20px;">
                <?php if ($storeLogo): ?>
                    <img src="<?= BASE_URL . '/' . htmlspecialchars($storeLogo) ?>" style="max-height: 50px; object-fit: contain; margin-bottom: 5px;"><br>
                <?php endif; ?>
                <div style="font-size: 22px; font-weight: bold; color: #333; margin-bottom: 5px;">INVOICE</div>
                <div style="font-weight: bold; font-size: 14px;"><?= h($storeName) ?></div>
                <div><?= nl2br(h($storeAddress)) ?></div>
                <div>Telp: <?= h($storePhone) ?></div>
            </div>
            <div style="text-align: right; flex:1;">
                <div style="margin-bottom: 10px;">
                    Invoice #: <strong style="font-size:14px;"><?= h($sale['invoice_number']) ?></strong><br>
                    Dibuat: <?= date('d/m/Y H:i', strtotime($sale['created_at'])) ?><br>
                    Status: <strong style="text-transform:uppercase;"><?= h($sale['status']) ?></strong>
                </div>
                <?php if ($isDropship): ?>
                <div><strong>PEMESAN:</strong></div>
                <div style="font-weight: bold;"><?= h($sale['display_customer_name']) ?></div>
                <?php endif; ?>
                <?php if ($shipping): ?>
                <div style="margin-top: 10px;"><strong>ALAMAT PENGIRIMAN:</strong></div>
                <div style="font-weight: bold;"><?= h($shipping['recipient_name'] ?? $sale['display_customer_name']) ?></div>
                <div><?= h($shipping['phone'] ?? $displayPhone) ?></div>
                <?php
                $formattedAddress = h($shippingAddress ?: '-');
                $formattedAddress = preg_replace('/,\s*(Kabupaten|Kota)\s/i', ',<br>$1 ', $formattedAddress);
                ?>
                <div><?= $formattedAddress ?></div>
                <div>Kurir: <?= h(trim(($shipping['courier_name'] ?? '') . ' ' . ($shipping['courier_service'] ?? ''))) ?></div>
                
                <?php else: ?>
                    <div><?= h($sale['customer_address'] ?? '-') ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ITEMS TABLE -->
        <table class="invoice-table">
            <thead>
                <tr>
                    <th style="text-align:center; width:30px;">No</th>
                    <th style="text-align:left;">Barang</th>
                    <th style="text-align:center; width:100px;">Harga</th>
                    <th style="text-align:center; width:50px;">Qty</th>
                    <th style="text-align:right; width:120px;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                foreach ($items as $item): 
                ?>
                <tr>
                    <td style="text-align:center;"><?= $no++ ?></td>
                    <td style="text-align:left;">
                        <?= h($item['product_name']) ?>
                        <?= $item['variation_name'] ? ' <small style="color:#666;">('.h($item['variation_name']).')</small>' : '' ?>
                    </td>
                    <td style="text-align:center;"><?= formatRupiah($item['unit_price']) ?></td>
                    <td style="text-align:center;"><?= $item['qty'] ?></td>
                    <td style="text-align:right;"><?= formatRupiah($item['subtotal']) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if ($totalRefund > 0): ?>
                <tr>
                    <td colspan="5" style="text-align:left; background: #fef2f2; color: #b91c1c; font-weight: bold; padding: 10px 6px;">RETUR PRODUK</td>
                </tr>
                <?php foreach ($returnedItems as $ri): ?>
                <tr style="background: #fef2f2; color: #b91c1c;">
                    <td style="text-align:center;">-</td>
                    <td style="text-align:left;">
                        <?= h($ri['product_name']) ?>
                        <?= $ri['variation_name'] ? ' <small>('.h($ri['variation_name']).')</small>' : '' ?>
                    </td>
                    <td style="text-align:center;"><?= formatRupiah($ri['unit_price']) ?></td>
                    <td style="text-align:center;">-<?= $ri['qty_returned'] ?></td>
                    <td style="text-align:right;">-<?= formatRupiah($ri['refund_subtotal']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if ($shipping && $shipping['shipping_cost'] > 0): ?>
                <tr>
                    <td style="text-align:center;"><?= $no++ ?></td>
                    <td style="text-align:left;">Ongkos Kirim (<?= h($shipping['courier_name'] . ' ' . $shipping['courier_service']) ?>)</td>
                    <td style="text-align:center;"></td>
                    <td style="text-align:center;"></td>
                    <td style="text-align:right;"><?= formatRupiah($shipping['shipping_cost']) ?></td>
                </tr>
                <?php endif; ?>
                
                <?php if ($sale['discount_amount'] > 0 || $totalRefund > 0): ?>
                <tr>
                    <td colspan="5" style="text-align:right; border-top: 2px solid #ddd; padding-top: 10px; font-size: 13px;">
                        Subtotal: <?= formatRupiah($sale['total_amount']) ?><br>
                        <?php if ($sale['discount_amount'] > 0): ?>Diskon: -<?= formatRupiah($sale['discount_amount']) ?><br><?php endif; ?>
                        <?php if ($totalRefund > 0): ?><span style="color:red;">Total Retur: -<?= formatRupiah($totalRefund) ?></span><br><?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td colspan="5" style="text-align:right; <?= empty($sale['discount_amount']) ? 'border-top: 2px solid #ddd; padding-top: 10px;' : '' ?> font-size: 14px;">
                        <strong>Total Tagihan: <?= formatRupiah($sale['grand_total']) ?></strong>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <?php if ($sale['notes']): ?>
        <div style="margin-top: 15px; background: #f9fafb; padding: 10px; border-radius: 4px; border: 1px solid #eee;">
            <strong>Catatan:</strong><br>
            <?= nl2br(h($sale['notes'])) ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

</body>
</html>
