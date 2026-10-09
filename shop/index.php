<?php
/**
 * Kasir Ibtidaiyah - E-Commerce: Halaman Utama Toko Online
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();

$priceType = $_SESSION['price_type_name'] ?? 'Umum';

$activeBanners = [];
for ($i = 1; $i <= 7; $i++) {
    $b = getSetting('banner' . $i . '_image', '');
    if (!empty($b)) {
        $activeBanners[] = $b;
    }
}
if (empty($activeBanners)) {
    $activeBanners[] = 'assets/img/hero_sarung.jpg';
}

$stmtCat = $db->prepare("
    SELECT c.* 
    FROM categories c 
    WHERE c.online_visibility = 1 
    AND c.parent_id IS NULL
    AND (
        EXISTS (
            SELECT 1 FROM products p
            WHERE p.category_id = c.id 
            AND p.is_active = 1 
            AND p.online_visibility = 'show'
            AND EXISTS (
                SELECT 1 FROM product_prices pp
                JOIN price_types pt ON pp.price_type_id = pt.id
                WHERE pp.product_id = p.id AND pt.name = ?
            )
        )
        OR EXISTS (
            SELECT 1 FROM categories subc 
            JOIN products p2 ON p2.category_id = subc.id
            WHERE subc.parent_id = c.id
            AND p2.is_active = 1 
            AND p2.online_visibility = 'show'
            AND EXISTS (
                SELECT 1 FROM product_prices pp2
                JOIN price_types pt2 ON pp2.price_type_id = pt2.id
                WHERE pp2.product_id = p2.id AND pt2.name = ?
            )
        )
    )
    ORDER BY c.sort_order, c.name
");
$stmtCat->execute([$priceType, $priceType]);
$categories = $stmtCat->fetchAll();

// Determine price type based on login session
// $priceType already set above

// Fetch featured products
$featuredProducts = $db->query("
    SELECT
        p.id, p.name, p.image AS prod_image, p.image2 AS prod_image2, p.is_featured, p.created_at, p.discount_percent,
        c.name AS category_name,
        (SELECT SUM(stock_qty) FROM product_variations WHERE product_id = p.id AND is_active = 1) AS stock_qty,
        COALESCE(
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = '$priceType' LIMIT 1),
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1),
            p.base_price
        ) AS price,
        (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1) AS umum_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1 AND p.is_featured = 1 AND p.online_visibility = 'show'
    AND (p.category_id IS NULL OR c.online_visibility = 1)
    AND EXISTS (SELECT 1 FROM product_variations WHERE product_id = p.id AND is_active = 1)
    ORDER BY p.created_at DESC
    LIMIT 24
")->fetchAll();

// Fetch latest products
$latestProducts = $db->query("
    SELECT
        p.id, p.name, p.image AS prod_image, p.image2 AS prod_image2, p.created_at, p.discount_percent,
        c.name AS category_name,
        (SELECT SUM(stock_qty) FROM product_variations WHERE product_id = p.id AND is_active = 1) AS stock_qty,
        COALESCE(
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = '$priceType' LIMIT 1),
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1),
            p.base_price
        ) AS price,
        (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1) AS umum_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1 AND p.online_visibility = 'show'
    AND (p.category_id IS NULL OR c.online_visibility = 1)
    AND EXISTS (SELECT 1 FROM product_variations WHERE product_id = p.id AND is_active = 1)
    ORDER BY p.created_at DESC
    LIMIT 18
")->fetchAll();

// Fetch best selling products
$bestSellingProducts = $db->query("
    SELECT
        p.id, p.name, p.image AS prod_image, p.image2 AS prod_image2, p.created_at, p.discount_percent,
        c.name AS category_name,
        pv.id AS var_id, pv.variation_name, pv.stock_qty, pv.image AS var_image, pv.image2 AS var_image2,
        COALESCE(
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = '$priceType' LIMIT 1),
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1),
            p.base_price
        ) AS price,
        (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1) AS umum_price,
        (SELECT COALESCE(SUM(sd.qty), 0) FROM sale_details sd WHERE sd.product_variation_id = pv.id) AS sold_qty
    FROM product_variations pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1 AND pv.is_active = 1 AND p.online_visibility = 'show' AND p.is_best_seller = 1
    AND (p.category_id IS NULL OR c.online_visibility = 1)
    AND pv.stock_qty > 0
    ORDER BY sold_qty DESC, p.created_at DESC
    LIMIT 10
")->fetchAll();


// Cart count from session
$cartCount = 0;
if (isset($_SESSION['shop_cart'])) {
    $cartCount = array_sum(array_column($_SESSION['shop_cart'], 'qty'));
}

$storeName = APP_NAME;

// Function to get better category icons based on name
function getCategoryIcon($name) {
    $lower = strtolower(trim($name));
    $icons = [
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.38 3.46L16 2a8.59 8.59 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="7"/><polyline points="12 9 12 12 13.5 13.5"/><path d="M16.51 17.35l-.35 3.83a2 2 0 0 1-2 1.82H9.83a2 2 0 0 1-2-1.82l-.35-3.83m.01-10.7l.35-3.83A2 2 0 0 1 9.83 1h4.35a2 2 0 0 1 2 1.82l.35 3.83"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13"/><path d="M13 3l3 6-4 13"/></svg>'
    ];
    // Pastikan icon statis berdasarkan nama
    return $icons[abs(crc32($lower)) % count($icons)];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $storeName ?> - Belanja online mudah dan terpercaya">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title><?= $storeName ?> - Toko Online</title>
    
    <?php
    $storeLogo = getSetting('store_logo');
    $favicon = $storeLogo ? BASE_URL . '/' . $storeLogo : ASSETS_URL . '/img/logo_s.png';
    ?>
    <link rel="icon" href="<?= $favicon ?>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="shop-layout">
        <!-- Navbar Mobile-First -->
        <?php include INCLUDES_PATH . '/shop_navbar.php'; ?>
        
        <!-- Main Content -->
        <div class="shop-content">
            <?= renderFlashMessage() ?>
            
            <style>
                /* === Compact Homepage Layout === */
                .hero-slider-wrapper { border-radius: 12px; overflow: hidden; margin-bottom: 0; }
                .feature-bar { display: flex; gap: 0; border: 1px solid #e5e7eb; border-radius: 8px; margin: 16px 0 20px; background: #fff; overflow: hidden; }
                .feature-bar-item { flex: 1; display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-right: 1px solid #e5e7eb; }
                .feature-bar-item:last-child { border-right: none; }
                .feature-bar-item svg { color: var(--primary-700); flex-shrink: 0; }
                .feature-bar-item strong { display: block; font-size: 0.8125rem; color: #111827; font-weight: 700; }
                .feature-bar-item span { font-size: 0.72rem; color: #6b7280; }
                .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
                .section-header h2 { font-size: 1rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; margin: 0; }
                .section-header a { font-size: 0.8125rem; color: var(--primary-700); text-decoration: none; display: flex; align-items: center; gap: 4px; }
                .section-header a:hover { text-decoration: underline; }
                /* Circular Category */
                .cat-circle-grid { display: flex; gap: 16px; overflow-x: auto; padding-bottom: 8px; scrollbar-width: none; margin-bottom: 24px; }
                .cat-circle-grid::-webkit-scrollbar { display: none; }
                .cat-circle-item { flex: 0 0 auto; display: flex; flex-direction: column; align-items: center; gap: 8px; text-decoration: none; color: #374151; min-width: 80px; }
                .cat-circle-img { width: 80px; height: 80px; border-radius: 50%; overflow: hidden; background: #f3f4f6; border: 2px solid #e5e7eb; display: flex; align-items: center; justify-content: center; transition: border-color 0.2s; }
                .cat-circle-img img { width: 100%; height: 100%; object-fit: cover; }
                .cat-circle-item:hover .cat-circle-img { border-color: var(--primary-500); }
                .cat-circle-name { font-size: 0.72rem; font-weight: 600; text-align: center; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
                .promo-banners { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 24px; margin-bottom: 16px; }
                .promo-banner { border-radius: 10px; overflow: hidden; position: relative; min-height: 180px; background-size: cover; background-position: center; }
                .promo-banner a { display: block; width: 100%; height: 100%; min-height: 180px; }
                .promo-banner-overlay { position: absolute; inset: 0; background: linear-gradient(135deg, rgba(34,9,1,0.75) 0%, rgba(34,9,1,0.2) 100%); }

                @media (max-width: 640px) {
                    .feature-bar { flex-wrap: wrap; }
                    .feature-bar-item { flex: 1 1 45%; border-bottom: 1px solid #e5e7eb; gap: 6px; padding: 8px 10px; }
                    .feature-bar-item svg { width: 18px; height: 18px; }
                    .feature-bar-item strong { font-size: 0.7rem; }
                    .feature-bar-item span { font-size: 0.62rem; }
                    .promo-banners { grid-template-columns: 1fr; }
                    .cat-circle-img { width: 56px; height: 56px; }
                    .cat-circle-item { min-width: 56px; }
                    .cat-circle-name { font-size: 0.65rem; }
                    
                    /* Mobile Banner Text Adjustments */
                    .banner-text-content { max-width: 85% !important; padding-left: 4% !important; text-align: left !important; }
                    .banner-text-content > span { font-size: 0.5rem !important; margin-bottom: 2px !important; text-align: left !important; }
                    .banner-text-content > h1 { font-size: 0.85rem !important; margin-bottom: 4px !important; line-height: 1.2 !important; text-align: left !important; }
                    .banner-text-content > p { font-size: 0.55rem !important; margin-bottom: 8px !important; line-height: 1.3 !important; text-align: left !important; }
                    .banner-text-content .hero-buttons { gap: 4px !important; justify-content: flex-start !important; }
                    .banner-text-content .hero-buttons a { padding: 4px 8px !important; font-size: 0.55rem !important; border-radius: 3px !important; }
                }
            </style>

            <!-- Hero Banner Slider -->
            <div class="hero-slider-wrapper" style="position: relative; overflow: hidden; border-radius: 12px; margin-bottom: 0; max-width: 100%; touch-action: pan-y; cursor: grab; aspect-ratio: 2000/800;">
                <div class="hero-slider" id="heroSlider" style="display: flex; transition: transform 0.5s ease-in-out; width: 300%; height: 100%; transform: translateX(0);">
                    
                    <!-- Slide 1 (With Text) -->
                    <div class="hero-banner" style="width: 100%; flex: 0 0 100%; margin-bottom: 0; border-radius: 0; background-color: transparent; background: linear-gradient(90deg, rgba(34, 9, 1, 1) 0%, rgba(34, 9, 1, 1) 45%, rgba(34, 9, 1, 0.7) 65%, transparent 85%) left center / 100% 100% no-repeat, url('<?= BASE_URL ?>/<?= htmlspecialchars($activeBanners[0]) ?>') right center / contain no-repeat; display: flex; align-items: center; padding: 0 5% 0 0%;">
                        <div class="banner-text-content" style="max-width: 48%; position: relative; z-index: 2; padding-left: 4%;">
                            <span style="font-size: clamp(1rem, 1.6vw, 1.3rem); font-weight: 600; color: #fff; display: block; margin-bottom: 6px;">Selamat Datang di</span>
                            <h1 style="line-height: 1.2; margin-bottom: 12px; font-weight: 800; font-size: clamp(1.4rem, 3.8vw, 2.6rem); background: linear-gradient(to right, #ff9800, #ffeb3b); -webkit-background-clip: text; color: transparent; text-shadow: none;"><?= $storeName ?></h1>
                            <p style="font-size: clamp(0.75rem, 1.1vw, 0.92rem); line-height: 1.6; margin-bottom: 20px; color: rgba(255,255,255,0.9); text-shadow: 1px 1px 2px rgba(0,0,0,0.4);">Temukan berbagai koleksi sarung, peci, baju koko, dan fashion muslim lainnya. Dapatkan harga spesial untuk pembelian grosir!</p>
                            <div class="hero-buttons" style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <a href="<?= BASE_URL ?>/shop/category.php" style="padding: 9px 22px; background: linear-gradient(135deg, #ff9800, #e65c00); color: #fff; font-weight: 700; font-size: 0.82rem; border-radius: 6px; text-decoration: none;">Semua Produk</a>
                                <?php if ($priceType === 'Umum'): ?>
                                <a href="<?= BASE_URL ?>/shop/category.php?discount=1" style="padding: 9px 22px; background: linear-gradient(135deg, #ff9800, #e65c00); color: #fff; font-weight: 700; font-size: 0.82rem; border-radius: 6px; text-decoration: none;">Sedang Diskon</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php for ($i = 1; $i < count($activeBanners); $i++): ?>
                    <!-- Additional Slides -->
                    <div class="hero-banner" style="width: 100%; flex: 0 0 100%; margin-bottom: 0; border-radius: 0; background: url('<?= BASE_URL ?>/<?= htmlspecialchars($activeBanners[$i]) ?>') center center / cover no-repeat;">
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Feature Bar -->
            <div class="feature-bar">
                <div class="feature-bar-item">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="m16 8 4 1 3 3v5h-7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    <div><strong>Gratis Ongkos Kirim</strong><span>Pembelian di atas Rp500rb</span></div>
                </div>
                <div class="feature-bar-item">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <div><strong>Pembayaran Aman</strong><span>100% Transaksi Terlindungi</span></div>
                </div>
                <div class="feature-bar-item">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 12l2 2 4-4"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/></svg>
                    <div><strong>Kualitas Original</strong><span>Produk Pilihan &amp; Terjamin</span></div>
                </div>
                <div class="feature-bar-item">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <div><strong>Jaminan Uang Kembali</strong><span>Garansi 3 Hari</span></div>
                </div>
            </div>
            
            <!-- Shop by Category (Circular) -->
            <?php if (!empty($categories)): ?>
                <div class="section-header">
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; color: var(--primary-600); margin-right: 6px;"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                        Kategori
                    </h2>
                    <a href="<?= BASE_URL ?>/shop/category.php">Lihat Semua &rarr;</a>
                </div>
                <div class="cat-circle-grid">
                    <?php foreach ($categories as $cat): ?>
                        <a href="<?= BASE_URL ?>/shop/category.php?category=<?= $cat['id'] ?>" class="cat-circle-item">
                            <div class="cat-circle-img">
                                <?php if (!empty($cat['icon'])): ?>
                                    <img src="<?= BASE_URL ?>/assets/uploads/categories/<?= htmlspecialchars($cat['icon']) ?>" 
                                         alt="<?= htmlspecialchars($cat['name']) ?>" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                                <?php endif; ?>
                            </div>
                            <span class="cat-circle-name"><?= htmlspecialchars($cat['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Best Sellers -->
            <?php if (!empty($bestSellingProducts)): ?>
                <div class="section-header">
                    <h2>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; color: #f59e0b; margin-right: 6px;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        Best Sellers
                    </h2>
                    <a href="<?= BASE_URL ?>/shop/category.php">Lihat Semua &rarr;</a>
                </div>
                <div class="shop-product-row" style="margin-bottom: 8px;">
                    <?php foreach ($bestSellingProducts as $prod): 
                        $prodName = $prod['name'];
                        if (!empty($prod['variation_name']) && !in_array(strtolower($prod['variation_name']), ['random', 'default', ''])) {
                            $prodName .= ' - ' . $prod['variation_name'];
                        }
                        $finalPrice = $prod['price'] ?? 0;
                        $hasDiscount = ($priceType === 'Umum' && !empty($prod['discount_percent']) && $prod['discount_percent'] > 0);
                        if ($hasDiscount) {
                            $finalPrice = $finalPrice - ($finalPrice * $prod['discount_percent'] / 100);
                        }
                    ?>
                        <a href="<?= BASE_URL ?>/shop/product.php?id=<?= $prod['id'] ?>&var_id=<?= $prod['var_id'] ?>" class="shop-product-card">
                            <div class="product-image">
                                <?php if ($hasDiscount): ?>
                                    <div class="discount-badge"><?= $prod['discount_percent'] ?>% OFF</div>
                                <?php endif; ?>
                                <?php 
                                $mainImage = $prod['var_image'] ?: $prod['prod_image'];
                                $hoverImage = $prod['var_image2'] ?: $prod['prod_image2'];
                                if ($mainImage): ?>
                                    <img src="<?= BASE_URL . '/' . $mainImage ?>"
                                         alt="<?= htmlspecialchars($prod['name']) ?>"
                                         loading="lazy"
                                         decoding="async"
                                         width="300" height="300">
                                    <?php if ($hoverImage): ?>
                                        <img src="<?= BASE_URL . '/' . $hoverImage ?>" class="hover-img" loading="lazy" decoding="async" width="300" height="300" alt="">
                                    <?php endif; ?>
                                <?php else: ?>
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                <?php endif; ?>
                                <?php if ($prod['stock_qty'] <= 0): ?>
                                    <div class="out-of-stock-overlay"><span>Sold<br>Out</span></div>
                                <?php endif; ?>
                                <div class="buy-now-btn">Beli Sekarang</div>
                            </div>
                            <div class="product-details">
                                <?php if ($prod['category_name']): ?>
                                    <span class="product-category"><?= htmlspecialchars($prod['category_name']) ?></span>
                                <?php endif; ?>
                                <h3 class="product-name"><?= htmlspecialchars($prodName) ?></h3>
                                <div class="product-price">
                                    <?php if ($hasDiscount): ?>
                                        <div style="font-size: 0.75rem; color: #9ca3af; text-decoration: line-through;">
                                            <?= formatRupiah($prod['price']) ?>
                                        </div>
                                    <?php elseif (isset($prod['umum_price']) && $prod['umum_price'] > $prod['price']): ?>
                                        <div style="font-size: 0.75rem; color: #9ca3af; text-decoration: line-through;"><?= formatRupiah($prod['umum_price']) ?></div>
                                    <?php endif; ?>
                                    <span><?= formatRupiah($finalPrice) ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Promo Banners 2 -->
                <?php 
                $promoBanner6 = $activeBanners[5] ?? null;
                $promoBanner7 = $activeBanners[6] ?? null;
                if ($promoBanner6 || $promoBanner7): 
                ?>
                <div class="promo-banners" style="margin-top: 28px;">
                    <?php if ($promoBanner6): ?>
                    <div class="promo-banner" style="min-height: 200px; background-image: url('<?= BASE_URL ?>/<?= htmlspecialchars($promoBanner6) ?>');">
                        <a href="<?= BASE_URL ?>/shop/category.php?discount=1"></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($promoBanner7): ?>
                    <div class="promo-banner" style="min-height: 200px; background-image: url('<?= BASE_URL ?>/<?= htmlspecialchars($promoBanner7) ?>');">
                        <a href="<?= BASE_URL ?>/shop/category.php"></a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>


            
            <!-- Featured Products -->
            <?php if (!empty($featuredProducts)): ?>
                <h2 class="section-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: text-bottom; margin-right: 8px; color: #f59e0b;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Produk Unggulan
                </h2>
                <div class="shop-product-row">
                    <?php foreach ($featuredProducts as $prod): 
                        $prodName = $prod['name'];
                        $finalPrice = $prod['price'] ?? 0;
                        $hasDiscount = ($priceType === 'Umum' && !empty($prod['discount_percent']) && $prod['discount_percent'] > 0);
                        if ($hasDiscount) {
                            $finalPrice = $finalPrice - ($finalPrice * $prod['discount_percent'] / 100);
                        }
                    ?>
                        <a href="<?= BASE_URL ?>/shop/product.php?id=<?= $prod['id'] ?>" class="shop-product-card">
                            <div class="product-image">
                                <?php if ($hasDiscount): ?>
                                    <div class="discount-badge"><?= $prod['discount_percent'] ?>% OFF</div>
                                <?php endif; ?>
                                <?php 
                                $mainImage = $prod['prod_image'];
                                $hoverImage = $prod['prod_image2'];
                                if ($mainImage): ?>
                                    <img src="<?= BASE_URL . '/' . $mainImage ?>"
                                         alt="<?= htmlspecialchars($prod['name']) ?>"
                                         loading="lazy"
                                         decoding="async"
                                         width="300" height="300">
                                    <?php if ($hoverImage): ?>
                                        <img src="<?= BASE_URL . '/' . $hoverImage ?>" class="hover-img" loading="lazy" decoding="async" width="300" height="300" alt="">
                                    <?php endif; ?>
                                <?php else: ?>
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                <?php endif; ?>
                                <?php if ($prod['stock_qty'] <= 0): ?>
                                    <div class="out-of-stock-overlay"><span>Sold<br>Out</span></div>
                                <?php endif; ?>
                                <div class="buy-now-btn">Buy Now</div>
                            </div>
                            <div class="product-details">
                                <?php if ($prod['category_name']): ?>
                                    <span class="product-category"><?= htmlspecialchars($prod['category_name']) ?></span>
                                <?php endif; ?>
                                <h3 class="product-name"><?= htmlspecialchars($prodName) ?></h3>
                                <div class="product-price">
                                    <?php 
                                    if ($hasDiscount): ?>
                                        <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through; margin-bottom: 2px;">
                                            <?= formatRupiah($prod['price']) ?>
                                        </div>
                                    <?php elseif (isset($prod['umum_price']) && $prod['umum_price'] > $prod['price']): ?>
                                        <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through;"><?= formatRupiah($prod['umum_price']) ?></div>
                                    <?php endif; ?>
                                    <span style="<?= $hasDiscount ? 'color:var(--danger); font-weight:800;' : '' ?>">
                                        <?= formatRupiah($finalPrice) ?>
                                    </span>
                                </div>
                                <div class="product-stock">Stok: <?= $prod['stock_qty'] ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Latest Products -->
            <h2 class="section-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 8px; color: var(--primary-600);"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                Produk Terbaru
            </h2>
            <div class="shop-product-grid">
                <?php if (empty($latestProducts)): ?>
                    <div style="grid-column:1/-1; text-align:center; padding:40px; color: var(--gray-400);">
                        Belum ada produk tersedia
                    </div>
                <?php endif; ?>
                <?php foreach ($latestProducts as $prod): 
                    $prodName = $prod['name'];
                    $finalPrice = $prod['price'] ?? 0;
                    $hasDiscount = ($priceType === 'Umum' && !empty($prod['discount_percent']) && $prod['discount_percent'] > 0);
                    if ($hasDiscount) {
                        $finalPrice = $finalPrice - ($finalPrice * $prod['discount_percent'] / 100);
                    }
                ?>
                    <a href="<?= BASE_URL ?>/shop/product.php?id=<?= $prod['id'] ?>" class="shop-product-card">
                        <div class="product-image">
                            <?php if ($hasDiscount): ?>
                                <div class="discount-badge"><?= $prod['discount_percent'] ?>% OFF</div>
                            <?php endif; ?>
                            <?php 
                            $mainImage = $prod['prod_image'];
                            $hoverImage = $prod['prod_image2'];
                            if ($mainImage): ?>
                                <img src="<?= BASE_URL . '/' . $mainImage ?>"
                                     alt="<?= htmlspecialchars($prod['name']) ?>"
                                     loading="lazy"
                                     decoding="async"
                                     width="300" height="300">
                                <?php if ($hoverImage): ?>
                                    <img src="<?= BASE_URL . '/' . $hoverImage ?>" class="hover-img" loading="lazy" decoding="async" width="300" height="300" alt="">
                                <?php endif; ?>
                            <?php else: ?>
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            <?php endif; ?>
                            <?php if ($prod['stock_qty'] <= 0): ?>
                                <div class="out-of-stock-overlay"><span>Sold<br>Out</span></div>
                            <?php endif; ?>
                            <div class="buy-now-btn">Buy Now</div>
                        </div>
                        <div class="product-details">
                            <?php if ($prod['category_name']): ?>
                                <span class="product-category"><?= htmlspecialchars($prod['category_name']) ?></span>
                            <?php endif; ?>
                            <h3 class="product-name"><?= htmlspecialchars($prodName) ?></h3>
                            <div class="product-price">
                                <?php if ($hasDiscount): ?>
                                    <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through; margin-bottom: 2px;">
                                        <?= formatRupiah($prod['price']) ?>
                                    </div>
                                <?php elseif (isset($prod['umum_price']) && $prod['umum_price'] > $prod['price']): ?>
                                    <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through;"><?= formatRupiah($prod['umum_price']) ?></div>
                                <?php endif; ?>
                                <span style="<?= $hasDiscount ? 'color:var(--danger); font-weight:800;' : '' ?>">
                                    <?= formatRupiah($finalPrice) ?>
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Footer -->
        <footer class="shop-footer">
            <div class="shop-footer-inner">
                <div class="footer-col-brand">
                    <h4 style="display: flex; align-items: center; gap: 8px;" class="footer-brand-title">
                        <img src="<?= BASE_URL ?>/assets/img/logo_s.png" alt="Logo" loading="lazy" decoding="async" style="width: 24px; height: 24px;">
                        <?= $storeName ?>
                    </h4>
                    <p style="margin-top: -10px; margin-bottom: 2px; font-size: 0.85rem; color: #a1a1aa;">Pusat Sarung & Fashion Santri</p>
                    <p class="footer-address">Kanigoro Rembang Pasuruan</p>
                </div>
                <div class="footer-col-navs">
                    <div class="footer-col-nav">
                        <h4>Bantuan</h4>
                        <p><a href="https://chat.whatsapp.com/IbXnIRYyUBt5rcrWaGHopb">Join Reseller</a></p>
                    <p><a href="<?= BASE_URL ?>/shop/category.php">Semua Produk</a></p>
                    <p><a href="<?= BASE_URL ?>/shop/cart.php">Keranjang</a></p>
                    </div>
                    <div class="footer-col-nav">
                        <h4>Akun</h4>
                        <p><a href="<?= BASE_URL ?>/login.php">Login</a></p>
                    <p><a href="<?= BASE_URL ?>/shop/account/orders.php">Pesanan Saya</a></p>
                    <p><a href="<?= BASE_URL ?>/shop/account/profile.php">Profil</a></p>
                    </div>
                </div>
            </div>
            <div class="shop-footer-bottom footer-bottom-flex">
                <div class="footer-copyright">
                    &copy; <?= date('Y') ?> <?= $storeName ?>. Toko Online Resmi Milik SarungSantriStore v<?= APP_VERSION ?>
                </div>
                <div class="footer-socials">
                    <a href="https://wa.me/6281511481192" target="_blank" class="social-icon social-wa">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                    </a>
                    <a href="https://www.instagram.com/sarungsantripasuruan?igsh=MW80OTd0dGdmd2F3MQ==" target="_blank" class="social-icon social-ig">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                    </a>
                    <a href="https://www.tiktok.com/@sarungsantristore?_r=1&_t=ZS-98sm4Pbr4Z2" target="_blank" class="social-icon social-tiktok">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"></path></svg>
                    </a>
                </div>
            </div>
        </footer>
    </div>
    
    <div class="toast-container" id="toastContainer"></div>
    <script src="<?= ASSETS_URL ?>/js/app.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const slider = document.getElementById('heroSlider');
            if (!slider) return;
            
            const originalSlides = document.querySelectorAll('.hero-banner');
            const slideCount = originalSlides.length;
            
            if (slideCount <= 1) {
                // No slider logic needed for single slide
                return;
            }
            
            // Create clones for infinite loop
            const firstClone = originalSlides[0].cloneNode(true);
            const lastClone = originalSlides[slideCount - 1].cloneNode(true);
            
            slider.appendChild(firstClone);
            slider.insertBefore(lastClone, originalSlides[0]);
            
            const allSlides = document.querySelectorAll('.hero-banner');
            const totalSlides = allSlides.length;
            
            slider.style.width = `${totalSlides * 100}%`;
            const slideWidthPercent = 100 / totalSlides;
            
            allSlides.forEach(slide => {
                slide.style.width = `${slideWidthPercent}%`;
                slide.style.flex = `0 0 ${slideWidthPercent}%`;
            });
            
            let currentSlide = 1; // Start at the first real slide (index 1)
            let isTransitioning = false;
            let slideInterval;
            
            // Initial position (skip the lastClone)
            slider.style.transition = 'none';
            slider.style.transform = `translateX(-${currentSlide * slideWidthPercent}%)`;
            
            function goToSlide(index) {
                if (isTransitioning) return;
                currentSlide = index;
                slider.style.transition = 'transform 0.5s ease-in-out';
                slider.style.transform = `translateX(-${currentSlide * slideWidthPercent}%)`;
                
                isTransitioning = true;
            }
            
            slider.addEventListener('transitionend', () => {
                isTransitioning = false;
                if (currentSlide === 0) {
                    // Jump to last real slide
                    slider.style.transition = 'none';
                    currentSlide = slideCount;
                    slider.style.transform = `translateX(-${currentSlide * slideWidthPercent}%)`;
                }
                if (currentSlide === slideCount + 1) {
                    // Jump to first real slide
                    slider.style.transition = 'none';
                    currentSlide = 1;
                    slider.style.transform = `translateX(-${currentSlide * slideWidthPercent}%)`;
                }
            });
            
            function nextSlide() {
                if (isTransitioning) return;
                goToSlide(currentSlide + 1);
            }
            
            function startSlideShow() {
                stopSlideShow();
                slideInterval = setInterval(nextSlide, 7000);
            }
            
            function stopSlideShow() {
                if (slideInterval) clearInterval(slideInterval);
            }
            
            // Touch / Drag events
            let startX = 0;
            let currentX = 0;
            let isDragging = false;
            
            const handleDragStart = (clientX) => {
                if (isTransitioning) return;
                startX = clientX;
                isDragging = true;
                stopSlideShow();
                slider.style.transition = 'none';
            };
            
            const handleDragMove = (clientX) => {
                if (!isDragging) return;
                currentX = clientX;
                const diff = currentX - startX;
                // Calculate percentage relative to the slider's parent wrapper width (which is slider.offsetWidth / totalSlides)
                const wrapperWidth = slider.offsetWidth / totalSlides;
                const percentDiff = (diff / wrapperWidth) * slideWidthPercent;
                const baseTranslate = -(currentSlide * slideWidthPercent);
                slider.style.transform = `translateX(${baseTranslate + percentDiff}%)`;
            };
            
            const handleDragEnd = () => {
                if (!isDragging) return;
                isDragging = false;
                const diff = currentX - startX;
                
                if (Math.abs(diff) > 50 && currentX !== 0) { // minimum threshold for swipe
                    if (diff > 0) goToSlide(currentSlide - 1); // swiped right
                    else goToSlide(currentSlide + 1); // swiped left
                } else {
                    goToSlide(currentSlide); // snap back
                }
                
                currentX = 0;
                startSlideShow();
            };
            
            slider.addEventListener('touchstart', (e) => {
                handleDragStart(e.touches[0].clientX);
            }, {passive: true});
            
            slider.addEventListener('touchmove', (e) => {
                handleDragMove(e.touches[0].clientX);
            }, {passive: true});
            
            slider.addEventListener('touchend', () => {
                handleDragEnd();
            });
            
            // Mouse Drag Events
            const wrapper = document.querySelector('.hero-slider-wrapper');
            wrapper.addEventListener('mousedown', (e) => {
                handleDragStart(e.clientX);
                wrapper.style.cursor = 'grabbing';
            });
            
            window.addEventListener('mousemove', (e) => {
                handleDragMove(e.clientX);
            });
            
            window.addEventListener('mouseup', () => {
                if (isDragging) {
                    handleDragEnd();
                    wrapper.style.cursor = 'grab';
                }
            });
            
            startSlideShow();
        });
    </script>
</body>
</html>
