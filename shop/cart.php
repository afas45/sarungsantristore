<?php
/**
 * Kasir Ibtidaiyah - Keranjang Belanja
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();
$storeName = getSetting('store_name', APP_NAME);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_qty') {
        $index = (int)$_POST['index'];
        $qty = max(0, (int)$_POST['qty']);
        
        if (isset($_SESSION['shop_cart'][$index])) {
            $varId = $_SESSION['shop_cart'][$index]['variation_id'];
            
            // Check stock availability
            $stmt = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ?");
            $stmt->execute([$varId]);
            $stockQty = $stmt->fetchColumn();
            
            if ($qty > 0 && $stockQty !== false && $qty > $stockQty) {
                flashMessage('error', 'Stok tidak mencukupi! Sisa stok: ' . $stockQty);
                redirect(BASE_URL . '/shop/cart.php');
                exit;
            }

            if ($qty <= 0) {
                array_splice($_SESSION['shop_cart'], $index, 1);
            } else {
                $_SESSION['shop_cart'][$index]['qty'] = $qty;
            }
        }
    }
    
    if ($action === 'remove') {
        $index = (int)$_POST['index'];
        if (isset($_SESSION['shop_cart'][$index])) {
            array_splice($_SESSION['shop_cart'], $index, 1);
        }
    }
    
    if ($action === 'clear') {
        $_SESSION['shop_cart'] = [];
    }
    
    redirect(BASE_URL . '/shop/cart.php');
}

// Get cart items with current prices
$cartItems = [];
$cartTotal = 0;

if (!empty($_SESSION['shop_cart'])) {
    foreach ($_SESSION['shop_cart'] as $i => &$item) {
        $stmt = $db->prepare("SELECT pv.*, p.name as product_name, p.image as prod_image FROM product_variations pv JOIN products p ON pv.product_id = p.id WHERE pv.id = ?");
        $stmt->execute([$item['variation_id']]);
        $var = $stmt->fetch();
        
        if (!$var) {
            array_splice($_SESSION['shop_cart'], $i, 1);
            continue;
        }
        
        $unitPrice = getSellingPrice($var['id'], $item['qty']);
        $subtotal = $unitPrice * $item['qty'];
        $cartTotal += $subtotal;
        
        $cartItems[] = [
            'index' => $i,
            'product_name' => $var['product_name'],
            'variation_name' => $var['variation_name'],
            'image' => $var['image'] ?: $var['prod_image'],
            'stock_qty' => $var['stock_qty'],
            'qty' => $item['qty'],
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
        ];
    }
    unset($item);
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
    <title>Keranjang - <?= $storeName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="shop-layout">
        <?php include INCLUDES_PATH . '/shop_navbar.php'; ?>
        
        <div class="shop-content">
            <?= renderFlashMessage() ?>
            
            <h1 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 24px;">
                🛒 Keranjang Belanja
                <span style="font-size: 0.875rem; font-weight: 400; color: var(--gray-500); margin-left: 8px;">(<?= $cartCount ?> item)</span>
            </h1>
            
            <?php if (empty($cartItems)): ?>
                <div class="card" style="text-align: center; padding: 60px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--gray-300)" stroke-width="1.5" style="margin:0 auto 16px;">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    <h3 style="color: var(--gray-500); margin-bottom: 8px;">Keranjang Masih Kosong</h3>
                    <p style="color: var(--gray-400); margin-bottom: 20px;">Yuk mulai belanja dan temukan produk terbaik!</p>
                    <a href="<?= BASE_URL ?>/shop/index.php" class="btn btn-primary">Mulai Belanja</a>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: 1fr 340px; gap: 24px;" class="cart-layout">
                    <!-- Cart Items -->
                    <div class="card">
                        <div class="card-body" style="padding: 0;">
                            <?php foreach ($cartItems as $ci): ?>
                                <div style="display:flex; gap:16px; padding:16px; border-bottom: 1px solid var(--border-color);">
                                    <div style="width:80px; height:80px; border-radius:8px; overflow:hidden; background: var(--gray-100); flex-shrink:0;">
                                        <?php if ($ci['image']): ?>
                                            <img src="<?= BASE_URL . '/' . $ci['image'] ?>" alt="" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;">
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex:1; min-width:0;">
                                        <div class="text-bold"><?= htmlspecialchars($ci['product_name']) ?></div>
                                        <?php if ($ci['variation_name']): ?>
                                            <div class="text-xs text-muted"><?= htmlspecialchars($ci['variation_name']) ?></div>
                                        <?php endif; ?>
                                        <div style="color: var(--primary-700); font-weight:700; margin-top:4px;"><?= formatRupiah($ci['unit_price']) ?></div>
                                        
                                        <div style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                                            <form method="POST" style="display:flex; align-items:center; gap:4px;">
                                                <input type="hidden" name="action" value="update_qty">
                                                <input type="hidden" name="index" value="<?= $ci['index'] ?>">
                                                <button type="submit" name="qty" value="<?= max(0, $ci['qty']-1) ?>" style="width:28px;height:28px;border:1px solid var(--border-color);border-radius:6px;background:var(--gray-50);cursor:pointer;font-size:1rem;">−</button>
                                                <span style="font-weight:600; padding:0 8px;"><?= $ci['qty'] ?></span>
                                                <button type="submit" name="qty" value="<?= $ci['qty']+1 ?>" style="width:28px;height:28px;border:1px solid var(--border-color);border-radius:6px;background:var(--gray-50);cursor:pointer;font-size:1rem;">+</button>
                                            </form>
                                            
                                            <form method="POST" style="margin-left:auto;">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="index" value="<?= $ci['index'] ?>">
                                                <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:0.8125rem;font-weight:600;font-family:var(--font-primary);">Hapus</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div style="font-weight:700; font-size: 1rem; white-space:nowrap;">
                                        <?= formatRupiah($ci['subtotal']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Summary -->
                    <div>
                        <div class="cart-summary">
                            <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 16px;">Ringkasan Belanja</h3>
                            <div class="cart-summary-row">
                                <span>Total Barang</span>
                                <span><?= $cartCount ?> item</span>
                            </div>
                            <div class="cart-summary-row total">
                                <span>Total</span>
                                <span><?= formatRupiah($cartTotal) ?></span>
                            </div>
                            
                            <div style="margin-top: 16px;">
                                <a href="<?= BASE_URL ?>/shop/checkout.php" class="btn btn-primary" style="width:100%; font-size: 1rem; padding: 14px;">
                                    Lanjut ke Checkout
                                </a>
                                
                                <form method="POST" style="margin-top: 8px;">
                                    <input type="hidden" name="action" value="clear">
                                    <button type="submit" class="btn btn-outline" style="width:100%;" onclick="return confirm('Kosongkan keranjang?')">
                                        Kosongkan Keranjang
                                    </button>
                                </form>
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
    
    <script src="<?= ASSETS_URL ?>/js/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
