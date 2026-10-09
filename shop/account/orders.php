<?php
/**
 * Kasir Ibtidaiyah - Pesanan Saya
 */
require_once __DIR__ . '/../../config/app.php';
requireLogin();

$db = Database::conn();
$storeName = getSetting('store_name', APP_NAME);

$orders = [];
if (isset($_SESSION['customer_id'])) {
    $stmt = $db->prepare("
        SELECT * FROM sales 
        WHERE customer_id = ? AND sale_source = 'E-Commerce' 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $stmt->execute([$_SESSION['customer_id']]);
    $orders = $stmt->fetchAll();
}

$cartCount = (isset($_SESSION['shop_cart']) && is_array($_SESSION['shop_cart'])) ? array_sum(array_column($_SESSION['shop_cart'], 'qty')) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan Saya - <?= $storeName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="shop-layout">
        <?php
        $showSearch = false;
        $searchVal  = '';
        include INCLUDES_PATH . '/shop_navbar.php';
        ?>
        
        <div class="shop-content">
            <h1 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 24px; display: flex; align-items: center; gap: 8px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary-600);"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                Pesanan Saya
            </h1>
            
            <?php if (empty($orders)): ?>
                <div class="card" style="text-align: center; padding: 60px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--gray-300)" stroke-width="1.5" style="margin:0 auto 16px;">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line>
                    </svg>
                    <h3 style="color: var(--gray-500); margin-bottom: 8px;">Belum Ada Pesanan</h3>
                    <p style="color: var(--gray-400); margin-bottom: 20px;">Anda belum melakukan pemesanan apapun.</p>
                    <a href="<?= BASE_URL ?>/shop/index.php" class="btn btn-primary">Mulai Belanja</a>
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:16px;">
                    <?php foreach ($orders as $order): ?>
                        <div class="card">
                            <div class="card-body">
                                <div style="display:flex; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                                    <div>
                                        <div class="text-xs text-muted">No. Invoice</div>
                                        <div class="text-bold" style="font-family: monospace;"><?= htmlspecialchars($order['invoice_number']) ?></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="text-xs text-muted">Tanggal</div>
                                        <div><?= formatTanggal($order['created_at'], true) ?></div>
                                    </div>
                                </div>
                                <div style="border-top:1px dashed var(--border-color); margin:12px 0;"></div>
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div class="text-xs text-muted">Total Bayar</div>
                                        <div class="text-bold" style="font-size: 1.125rem; color: var(--primary-700);"><?= formatRupiah($order['grand_total']) ?></div>
                                    </div>
                                    <div>
                                        <?php 
                                            // Memetakan status default POS menjadi status Ecommerce (Pending, Kirim, Selesai)
                                            $sb = ['Pending'=>'badge-warning','Paid'=>'badge-success','Debt'=>'badge-info','Cancelled'=>'badge-danger']; 
                                            $sl = ['Pending'=>'Pending','Paid'=>'Selesai / Terkirim','Debt'=>'Diproses / Dikirim','Cancelled'=>'Batal']; 
                                        ?>
                                        <span class="badge <?= $sb[$order['status']] ?? 'badge-gray' ?>"><?= $sl[$order['status']] ?? $order['status'] ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <footer class="shop-footer">
            <div class="shop-footer-bottom">&copy; <?= date('Y') ?> <?= $storeName ?></div>
        </footer>
    </div>
</body>
</html>
