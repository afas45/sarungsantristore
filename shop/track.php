<?php
/**
 * Kasir Ibtidaiyah - Cek Pesanan
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();
$storeName = getSetting('store_name', APP_NAME);

$searchInvoice = sanitize($_GET['invoice'] ?? '');
$searchPhone = sanitize($_GET['phone'] ?? '');
$order = null;
$error = '';

if (!empty($searchInvoice) && !empty($searchPhone)) {
    $stmt = $db->prepare("
        SELECT s.*, c.name as customer_name, c.phone as customer_phone
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.invoice_number = ? AND c.phone = ? AND s.sale_source = 'E-Commerce'
    ");
    $stmt->execute([$searchInvoice, $searchPhone]);
    $order = $stmt->fetch();
    
    if (!$order) {
        $error = 'Pesanan tidak ditemukan. Periksa kembali No. Invoice dan No. Telepon Anda.';
    } else {
        $stmt = $db->prepare("SELECT * FROM sale_details WHERE sale_id = ?");
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll();
        
        $stmt = $db->prepare("SELECT * FROM shipping_details WHERE sale_id = ?");
        $stmt->execute([$order['id']]);
        $shipping = $stmt->fetch();
    }
}
$cartCount  = array_sum(array_column($_SESSION['shop_cart'] ?? [], 'qty'));
$showSearch = false;
$searchVal  = '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Pesanan - <?= $storeName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="shop-layout">
        <?php include INCLUDES_PATH . '/shop_navbar.php'; ?>
        
        <div class="shop-content" style="max-width: 800px; margin: 0 auto; padding: 40px 20px;">
            <h1 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 24px; text-align: center;">🔍 Cek Status Pesanan</h1>
            
            <div class="card mb-24">
                <div class="card-body">
                    <form method="GET" action="" style="display: grid; gap: 16px; align-items: end;" class="track-form">
                        <div class="form-group mb-0">
                            <label class="form-label">No. Invoice</label>
                            <input type="text" name="invoice" class="form-control" placeholder="Contoh: INV-..." value="<?= htmlspecialchars($searchInvoice) ?>" required>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">No. Telepon / WhatsApp</label>
                            <input type="text" name="phone" class="form-control" placeholder="08xxxxxxxxxx" value="<?= htmlspecialchars($searchPhone) ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Cek Pesanan</button>
                    </form>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div style="background: #fff0f0; color: #d32f2f; padding: 16px; border-radius: 8px; border: 1px solid #ffcdd2; margin-bottom: 24px;">
                    <?= $error ?>
                </div>
            <?php endif; ?>
            
            <?php if ($order): ?>
                <div class="card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 class="card-title">Detail Pesanan: <?= htmlspecialchars($order['invoice_number']) ?></h3>
                        <?php
                        $badgeMap = [
                            'Pending' => 'badge-warning',
                            'Confirmed' => 'badge-info',
                            'Processing' => 'badge-primary',
                            'Ready' => 'badge-success',
                            'Shipped' => 'badge-gold',
                            'Completed' => 'badge-success',
                            'Cancelled' => 'badge-danger',
                        ];
                        $badgeClass = $badgeMap[$order['status']] ?? 'badge-primary';
                        ?>
                        <span class="badge <?= $badgeClass ?>" style="font-size: 0.875rem; padding: 6px 12px;"><?= $order['status'] ?></span>
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;" class="order-info-grid">
                            <div>
                                <h4 class="text-muted text-sm mb-2">Informasi Pemesan</h4>
                                <div class="text-bold"><?= htmlspecialchars($order['customer_name']) ?></div>
                                <div><?= htmlspecialchars($order['customer_phone']) ?></div>
                            </div>
                            <div>
                                <h4 class="text-muted text-sm mb-2">Tanggal Pesanan</h4>
                                <div class="text-bold"><?= formatTanggal($order['created_at'], true) ?></div>
                            </div>
                        </div>
                        
                        <?php if ($shipping): ?>
                        <div style="background: var(--gray-50); padding: 16px; border-radius: 8px; margin-bottom: 24px; border: 1px solid var(--border-color);">
                            <h4 class="text-muted text-sm mb-8">Informasi Pengiriman</h4>
                            <div><strong>Kurir:</strong> <?= htmlspecialchars($shipping['courier_name']) ?></div>
                            <div><strong>Alamat:</strong> <?= htmlspecialchars($shipping['shipping_address']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <h4 class="text-muted text-sm mb-8">Daftar Barang</h4>
                        <div style="border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
                            <?php foreach ($items as $item): ?>
                                <div style="display: flex; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--border-color); font-size: 0.875rem; background: #fff;">
                                    <div>
                                        <span class="text-bold"><?= htmlspecialchars($item['product_name']) ?></span>
                                        <?php if ($item['variation_name']): ?>
                                            <span class="text-muted"> (<?= htmlspecialchars($item['variation_name']) ?>)</span>
                                        <?php endif; ?>
                                        <div class="text-muted mt-4"><?= $item['qty'] ?> × <?= formatRupiah($item['unit_price']) ?></div>
                                    </div>
                                    <div class="text-bold">
                                        <?= formatRupiah($item['subtotal']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div style="display: flex; justify-content: space-between; padding: 12px 16px; background: var(--gray-50); font-size: 0.875rem;">
                                <span>Subtotal</span>
                                <span class="text-bold"><?= formatRupiah($order['total_amount']) ?></span>
                            </div>
                            <?php if ($shipping && $shipping['shipping_cost'] > 0): ?>
                            <div style="display: flex; justify-content: space-between; padding: 12px 16px; background: var(--gray-50); border-top: 1px solid var(--border-color); font-size: 0.875rem;">
                                <span>Ongkos Kirim</span>
                                <span class="text-bold"><?= formatRupiah($shipping['shipping_cost']) ?></span>
                            </div>
                            <?php endif; ?>
                            <div style="display: flex; justify-content: space-between; padding: 16px; background: #fff; border-top: 2px solid var(--gray-200); font-size: 1rem;">
                                <span class="text-bold">Total Pembayaran</span>
                                <span class="text-bold" style="color: var(--primary-700);"><?= formatRupiah($order['grand_total']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <footer class="shop-footer">
            <div class="shop-footer-bottom">&copy; <?= date('Y') ?> <?= $storeName ?></div>
        </footer>
    </div>
</body>
</html>
