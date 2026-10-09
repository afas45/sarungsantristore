<?php
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

if (!isset($_GET['id'])) {
    die("ID Purchase Order tidak ditemukan.");
}

$id = (int)$_GET['id'];
$db = Database::conn();

$stmt = $db->prepare("
    SELECT p.*, s.name as supplier_name, s.phone as supplier_phone, u.full_name as admin_name 
    FROM purchases p 
    JOIN suppliers s ON p.supplier_id = s.id 
    JOIN users u ON p.admin_id = u.id 
    WHERE p.id = ?
");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    die("Data Purchase Order tidak ditemukan.");
}

if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8', false);
    }
}


$stmtItems = $db->prepare("
    SELECT pd.*, pr.name as product_name, pr.sku 
    FROM purchase_details pd 
    JOIN products pr ON pd.product_id = pr.id 
    WHERE pd.purchase_id = ?
");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

$storeName = getSetting('store_name', APP_NAME);
$storeAddress = getSetting('store_address', '');
$storePhone = getSetting('store_phone', '');
$receiptFooter = getSetting('receipt_footer', 'Terima kasih.');

$remainingDebt = $po['total_amount'] - ($po['paid_amount'] ?? 0);
if ($remainingDebt < 0) $remainingDebt = 0;

$paymentStatus = 'Belum Bayar';
if (($po['paid_amount'] ?? 0) >= $po['total_amount']) {
    $paymentStatus = 'LUNAS';
} elseif (($po['paid_amount'] ?? 0) > 0) {
    $paymentStatus = 'DP / SEBAGIAN';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Pembayaran PO #<?= h($po['po_number']) ?></title>
    <!-- html2canvas for sharing -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        @page { margin: 0; size: 58mm auto; }
        body { 
            background: #f1f5f9; 
            margin: 0; 
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
        }
        
        .receipt-container {
            width: 58mm;
            background: #fff;
            padding: 10px 5px;
            font-size: 11px;
            box-sizing: border-box;
            color: #000;
            margin-top: 10px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        .mb-2 { margin-bottom: 5px; }
        .mb-4 { margin-bottom: 10px; }
        .dashed-line { border-top: 1px dashed #000; margin: 8px 0; }
        .flex-between { display: flex; justify-content: space-between; align-items: flex-start; }
        
        /* Action buttons hide when printing */
        .actions-bar {
            width: 100%;
            max-width: 300px;
            display: flex;
            gap: 10px;
            padding: 15px;
            box-sizing: border-box;
        }
        .btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            font-size: 14px;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
        }
        .btn-print { background: #0ea5e9; }
        .btn-share { background: #10b981; }

        @media print {
            body { background: #fff; display: block; }
            .receipt-container { margin-top: 0; box-shadow: none; padding: 0; }
            .actions-bar { display: none; }
        }
    </style>
</head>
<body>

<?php
ob_start();
?>
    <div class="receipt-container" id="receiptContent">
        <div class="text-center text-bold" style="font-size:14px; margin-bottom: 2px;"><?= h($storeName) ?></div>
        <div class="text-center" style="font-size:10px; margin-bottom: 2px;"><?= nl2br(h($storeAddress)) ?></div>
        <div class="text-center mb-4" style="font-size:10px;">Telp: <?= h($storePhone) ?></div>
        
        <div class="dashed-line"></div>
        <div class="text-center text-bold" style="font-size:12px; margin: 4px 0;">PEMBAYARAN PO</div>
        <div class="text-center text-bold" style="font-size:11px; margin-bottom: 4px;"><?= $paymentStatus ?></div>
        <div class="dashed-line"></div>
        
        <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
            <span>No PO:</span>
            <span><?= h($po['po_number']) ?></span>
        </div>
        <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
            <span>Tanggal:</span>
            <span><?= date('d/m/Y H:i', strtotime($po['date'])) ?></span>
        </div>
        <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
            <span>Supplier:</span>
            <span><?= h($po['supplier_name']) ?></span>
        </div>
        <div class="flex-between" style="font-size:10px; margin-bottom:2px;">
            <span>Admin:</span>
            <span><?= h($po['admin_name']) ?></span>
        </div>
        
        <div class="dashed-line"></div>
        <div style="font-size:10px; margin-bottom: 4px;">
            <table style="width: 100%; font-size: 10px; border-collapse: collapse;">
                <?php foreach ($items as $item): ?>
                <tr>
                    <td colspan="3" style="padding-bottom: 2px;"><?= h($item['product_name']) ?></td>
                </tr>
                <tr>
                    <td style="padding-bottom: 4px;"><?= $item['qty'] ?>x</td>
                    <td style="padding-bottom: 4px;"><?= formatRupiah($item['unit_cost']) ?></td>
                    <td class="text-right" style="padding-bottom: 4px;"><?= formatRupiah($item['qty'] * $item['unit_cost']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        
        <div class="dashed-line"></div>
        <div class="flex-between text-bold" style="font-size:11px; margin-bottom:4px;">
            <span>TOTAL SELURUH</span>
            <span><?= formatRupiah($po['total_amount']) ?></span>
        </div>
        
        <div class="flex-between" style="font-size:11px; margin-bottom:2px;">
            <span>DIBAYAR (<?= h($po['payment_method'] ?? 'Tunai') ?>)</span>
            <span><?= formatRupiah($po['paid_amount'] ?? 0) ?></span>
        </div>
        
        <div class="flex-between text-bold" style="font-size:11px; margin-bottom:2px; margin-top: 4px;">
            <span>SISA HUTANG</span>
            <span><?= formatRupiah($remainingDebt) ?></span>
        </div>
        
        <div class="dashed-line"></div>
        <div class="text-center" style="font-size:10px; margin-top: 10px; margin-bottom: 10px;">
            <?= nl2br(h($receiptFooter)) ?>
        </div>
    </div>
<?php
$receiptHtml = ob_get_clean();

if (isset($_GET['partial'])) {
    // Add basic inline styles so it looks good when injected
    $inlineHtml = str_replace(
        ['class="receipt-container"', 'class="dashed-line"', 'class="flex-between"', 'class="text-center"', 'class="text-right"', 'class="text-bold"', 'class="mb-2"', 'class="mb-4"'],
        ['class="receipt-container" style="width: 100%; max-width: 320px; margin: 0 auto; background: #fff; padding: 20px; font-size: 12px; color: #000; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; box-sizing: border-box; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #eaeaea; border-radius: 8px;"',
         'class="dashed-line" style="border-top: 1px dashed #ccc; margin: 10px 0;"',
         'class="flex-between" style="display: flex; justify-content: space-between; align-items: flex-start;"',
         'class="text-center" style="text-align: center;"',
         'class="text-right" style="text-align: right;"',
         'class="text-bold" style="font-weight: bold;"',
         'class="mb-2" style="margin-bottom: 5px;"',
         'class="mb-4" style="margin-bottom: 10px;"'
        ],
        $receiptHtml
    );
    echo $inlineHtml;
    exit;
}
?>

    <div class="actions-bar">
        <button class="btn btn-print" onclick="window.print()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Cetak
        </button>
        <button class="btn btn-share" onclick="shareReceipt()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
            Bagikan JPG
        </button>
    </div>

    <?= $receiptHtml ?>

    <script>
        async function shareReceipt() {
            const btn = document.querySelector('.btn-share');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span style="font-size:12px;">Memproses...</span>';
            btn.disabled = true;

            try {
                const element = document.getElementById('receiptContent');
                // Ensure text rendering and white background
                const canvas = await html2canvas(element, {
                    scale: 2,
                    backgroundColor: '#ffffff',
                    useCORS: true
                });

                canvas.toBlob(async (blob) => {
                    const file = new File([blob], 'Struk_PO_<?= addslashes(h($po['po_number'])) ?>.jpg', { type: 'image/jpeg' });
                    
                    if (navigator.canShare && navigator.canShare({ files: [file] })) {
                        try {
                            await navigator.share({
                                title: 'Struk Pembayaran PO',
                                text: 'Struk Pembayaran PO #<?= addslashes(h($po['po_number'])) ?>',
                                files: [file]
                            });
                        } catch (e) {
                            console.error('Share failed', e);
                            if (e.name !== 'AbortError') {
                                downloadBlob(blob, file.name);
                            }
                        }
                    } else {
                        // Fallback to download if Web Share API is not supported
                        downloadBlob(blob, file.name);
                    }
                    
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }, 'image/jpeg', 0.9);

            } catch (error) {
                console.error("Error generating receipt image:", error);
                alert("Gagal membuat gambar struk.");
                btn.innerHTML = originalText;
                btn.disabled = false;
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
            URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
