<?php
/**
 * Kasir Ibtidaiyah - Halaman Kategori / Semua Produk
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();
$storeName = 'SarungSantriStore';

$search = sanitize($_GET['search'] ?? '');
$catId = (int)($_GET['category'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$sort = sanitize($_GET['sort'] ?? 'newest');

// Determine price type based on login session
$priceType = $_SESSION['price_type_name'] ?? 'Umum';

$where = "WHERE p.is_active = 1 AND p.online_visibility = 'show' AND (p.category_id IS NULL OR c.online_visibility = 1)";
$params = [];

if ($search) {
    $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($catId) {
    $stmtSub = $db->prepare("SELECT id FROM categories WHERE parent_id = ?");
    $stmtSub->execute([$catId]);
    $subIds = $stmtSub->fetchAll(PDO::FETCH_COLUMN);
    $subIds[] = $catId;
    $inPlaceholders = str_repeat('?,', count($subIds) - 1) . '?';
    $where .= " AND p.category_id IN ($inPlaceholders)";
    foreach ($subIds as $sId) {
        $params[] = $sId;
    }
}

$isDiscount = isset($_GET['discount']) && $_GET['discount'] == '1';
if ($isDiscount && $priceType === 'Umum') {
    $where .= " AND p.discount_percent > 0";
}

// Filter out products that don't have a price for the current price type
// (fallback: show all with a price in product_prices)
$where .= " AND EXISTS (
    SELECT 1 FROM product_prices pp2
    WHERE pp2.product_id = p.id
)";

switch ($sort) {
    case 'price_asc':
        $orderBy = 'price ASC';
        break;
    case 'price_desc':
        $orderBy = 'price DESC';
        break;
    case 'name':
        $orderBy = 'p.name ASC';
        break;
    default:
        $orderBy = 'p.created_at DESC';
        break;
}

// Ensure in-stock products appear first is removed, so out of stock still appears on top if newer
// $orderBy = "((SELECT COALESCE(SUM(stock_qty), 0) FROM product_variations WHERE product_id = p.id AND is_active = 1) > 0) DESC, " . $orderBy;

$stmt = $db->prepare("SELECT COUNT(*) FROM product_variations pv JOIN products p ON pv.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id $where AND pv.is_active = 1");
$stmt->execute($params);
$totalItems = $stmt->fetchColumn();
$pagination = getPagination($totalItems, $page, 1000);

$stmt = $db->prepare("
    SELECT
        p.id, p.name, p.image AS prod_image, p.image2 AS prod_image2, p.created_at, p.discount_percent,
        c.name AS category_name,
        pv.id AS var_id, pv.variation_name, pv.stock_qty, pv.image AS var_image, pv.image2 AS var_image2,
        COALESCE(
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = '$priceType' LIMIT 1),
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1),
            p.base_price
        ) AS price,
        (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1) AS umum_price
    FROM product_variations pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    $where AND pv.is_active = 1
    ORDER BY $orderBy
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$products = $stmt->fetchAll();

$stmtCat = $db->prepare("
    SELECT c.id, c.name, c.parent_id, p.name as parent_name 
    FROM categories c 
    LEFT JOIN categories p ON c.parent_id = p.id
    WHERE c.online_visibility = 1 
    AND (
        EXISTS (
            SELECT 1 FROM products pr
            WHERE pr.category_id = c.id 
            AND pr.is_active = 1 
            AND pr.online_visibility = 'show'
        )
        OR EXISTS (
            SELECT 1 FROM categories subc 
            JOIN products pr2 ON pr2.category_id = subc.id
            WHERE subc.parent_id = c.id
            AND pr2.is_active = 1 
            AND pr2.online_visibility = 'show'
        )
    )
    ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.sort_order ASC, c.name ASC
");
$stmtCat->execute([]);
$categories = $stmtCat->fetchAll();

// Get current category name
$currentCatName = '';
if ($catId) {
    $stmt = $db->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->execute([$catId]);
    $currentCatName = $stmt->fetchColumn();
}

$cartCount   = isset($_SESSION['shop_cart']) ? array_sum(array_column($_SESSION['shop_cart'], 'qty')) : 0;

$pageTitle = 'Semua Produk';
if ($isDiscount) {
    $pageTitle = 'Produk Promo Diskon';
} elseif ($currentCatName) {
    $pageTitle = $currentCatName;
} elseif ($search) {
    $pageTitle = "Hasil Pencarian: $search";
}

// Variabel untuk shop_navbar.php
$searchVal   = $search;
$searchCatId = $catId;
$showSearch  = true;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= $storeName ?></title>
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
            <div class="category-header-wrap" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; gap:12px;">
                <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                    <a href="<?= BASE_URL ?>/shop/index.php" class="back-btn-cat" style="padding: 8px; border: 1px solid var(--border-color); border-radius: 8px; color: var(--gray-600); display: flex; align-items: center; justify-content: center; background: #fff; text-decoration: none; transition: 0.2s; flex-shrink: 0;" title="Kembali ke Beranda" onmouseover="this.style.background='var(--gray-50)'; this.style.color='var(--gray-900)'" onmouseout="this.style.background='#fff'; this.style.color='var(--gray-600)'">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    </a>
                    <div>
                        <h1 style="font-size: clamp(1.1rem, 3vw, 1.5rem); font-weight: 800; margin:0; line-height: 1.2;"><?= htmlspecialchars($pageTitle) ?></h1>
                        <p class="text-sm text-muted" style="margin: 4px 0 0 0;"><?= $totalItems ?> produk ditemukan</p>
                    </div>
                </div>
                <div style="flex-shrink: 0;">
                    <select onchange="window.location.href='?search=<?= urlencode($search) ?>&category=<?= $catId ?><?= $isDiscount ? '&discount=1' : '' ?>&sort='+this.value" style="padding:8px 12px;border:1px solid var(--border-color);border-radius:8px;font-size:0.8125rem;font-family:var(--font-primary);">
                        <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Terbaru</option>
                        <option value="name" <?= $sort==='name'?'selected':'' ?>>Nama A-Z</option>
                        <option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?>>Harga Terendah</option>
                        <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Harga Tertinggi</option>
                    </select>
                </div>
            </div>
            
            <!-- Category filter chips -->
            <?php
            $groupedCats = [];
            foreach ($categories as $cat) {
                if (!$cat['parent_id']) {
                    $groupedCats[$cat['id']] = $cat;
                    $groupedCats[$cat['id']]['subs'] = [];
                }
            }
            foreach ($categories as $cat) {
                if ($cat['parent_id'] && isset($groupedCats[$cat['parent_id']])) {
                    $groupedCats[$cat['parent_id']]['subs'][] = $cat;
                }
            }
            ?>
            <style>
            .shop-cat-filters {
                display: flex;
                gap: 8px;
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: visible;
                white-space: nowrap;
                margin-bottom: 20px;
                padding-bottom: 8px;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: thin;
                position: relative;
                z-index: 1;
            }
            .shop-cat-filters::-webkit-scrollbar {
                height: 6px;
            }
            .shop-cat-filters::-webkit-scrollbar-thumb {
                background: rgba(0,0,0,0.16);
                border-radius: 999px;
            }
            .shop-cat-filters .chip {
                padding: 8px 14px;
                border-radius: 999px;
                font-size: 0.8125rem;
                text-decoration: none;
                font-weight: 600;
                border: 1px solid var(--border-color);
                color: var(--gray-600);
                display: inline-block;
                flex: 0 0 auto;
                background: #fff;
            }
            .shop-cat-filters .chip.active {
                background: var(--primary-600);
                color: #fff;
                border-color: var(--primary-600);
                box-shadow: 0 2px 8px rgba(125, 60, 31, 0.18);
            }
            .cat-dropdown-wrapper { position: relative; display: inline-block; flex: 0 0 auto; }
            .cat-dropdown-menu {
                display: none;
                position: absolute;
                top: calc(100% + 6px);
                left: 0;
                background: #fff;
                min-width: 160px;
                box-shadow: 0 8px 18px rgba(0,0,0,0.12);
                border-radius: 12px;
                padding: 8px 0;
                z-index: 200;
                border: 1px solid var(--border-color);
            }
            .cat-dropdown-wrapper:hover .cat-dropdown-menu, .cat-dropdown-wrapper.show-menu .cat-dropdown-menu { display:block; }
            .cat-dropdown-menu a {
                display:block;
                padding: 8px 14px;
                font-size: 0.8125rem;
                color: var(--gray-700);
                text-decoration: none;
            }
            .cat-dropdown-menu a:hover { background: var(--gray-100); }
            .cat-dropdown-menu a.active-sub { font-weight: 700; color: var(--primary-600); }

            @media (max-width: 640px) {
                .shop-cat-filters {
                    flex-wrap: wrap;
                    overflow: visible;
                    padding-bottom: 12px;
                    z-index: 30;
                }
                .shop-cat-filters .cat-dropdown-wrapper {
                    position: relative;
                    z-index: 31;
                }
                .shop-cat-filters .cat-dropdown-wrapper.show-menu {
                    z-index: 32;
                }
                .shop-product-grid {
                    position: relative;
                    z-index: 1;
                }
                .cat-dropdown-menu {
                    min-width: 180px;
                    max-width: min(220px, calc(100vw - 32px));
                    left: 0;
                    right: auto;
                }
            }
            </style>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.cat-dropdown-wrapper > a.chip').forEach(function(el) {
                    el.addEventListener('click', function(e) {
                        if (this.nextElementSibling && this.nextElementSibling.classList.contains('cat-dropdown-menu')) {
                            // Cek jika perangkat touch screen
                            if (window.matchMedia("(hover: none)").matches || 'ontouchstart' in window) {
                                if (!this.parentNode.classList.contains('show-menu')) {
                                    e.preventDefault();
                                    document.querySelectorAll('.cat-dropdown-wrapper').forEach(w => w.classList.remove('show-menu'));
                                    this.parentNode.classList.add('show-menu');
                                }
                            }
                        }
                    });
                });
                
                // Tutup saat klik di luar
                document.addEventListener('click', function(e) {
                    if (!e.target.closest('.cat-dropdown-wrapper')) {
                        document.querySelectorAll('.cat-dropdown-wrapper').forEach(w => w.classList.remove('show-menu'));
                    }
                });
            });
            </script>
            
            <div class="shop-cat-filters">
                <a href="?search=<?= urlencode($search) ?>&sort=<?= $sort ?><?= $isDiscount ? '&discount=1' : '' ?>" class="chip <?= !$catId ? 'active' : '' ?>">Semua</a>
                <?php foreach ($groupedCats as $pCat): ?>
                    <?php 
                    $isActive = ($catId == $pCat['id'] || in_array($catId, array_column($pCat['subs'], 'id'))); 
                    ?>
                    <div class="cat-dropdown-wrapper">
                        <a href="?category=<?= $pCat['id'] ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?><?= $isDiscount ? '&discount=1' : '' ?>" class="chip <?= $isActive ? 'active' : '' ?>">
                            <?= htmlspecialchars($pCat['name']) ?> <?= count($pCat['subs']) > 0 ? '▾' : '' ?>
                        </a>
                        <?php if (count($pCat['subs']) > 0): ?>
                        <div class="cat-dropdown-menu">
                            <?php foreach ($pCat['subs'] as $sub): ?>
                                <a href="?category=<?= $sub['id'] ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?><?= $isDiscount ? '&discount=1' : '' ?>" class="<?= $catId == $sub['id'] ? 'active-sub' : '' ?>">
                                    <?= htmlspecialchars($sub['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Product Grid -->
            <div class="shop-product-grid">
                <?php if (empty($products)): ?>
                    <div style="grid-column:1/-1; text-align:center; padding:60px; color: var(--gray-400);">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <h3>Produk Tidak Ditemukan</h3>
                        <p>Coba kata kunci lain atau kategori yang berbeda</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($products as $prod): 
                        $prodName = $prod['name'];
                        if (!empty($prod['variation_name']) && !in_array(strtolower($prod['variation_name']), ['random', 'default', ''])) {
                            $prodName .= ' - ' . $prod['variation_name'];
                        }
                    ?>
                        <a href="<?= BASE_URL ?>/shop/product.php?id=<?= $prod['id'] ?>&var_id=<?= $prod['var_id'] ?>" class="shop-product-card">
                            <div class="product-image">
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
                                <?php if (isset($prod['stock_qty']) && $prod['stock_qty'] <= 0): ?>
                                    <div class="out-of-stock-overlay"><span>Sold<br>Out</span></div>
                                <?php endif; ?>
                                <div class="buy-now-btn">Buy now</div>
                            </div>
                            <div class="product-details">
                                <?php if ($prod['category_name']): ?>
                                    <span class="product-category"><?= htmlspecialchars($prod['category_name']) ?></span>
                                <?php endif; ?>
                                <h3 class="product-name"><?= htmlspecialchars($prodName) ?></h3>
                                <div class="product-price">
                                    <?php 
                                    $finalPrice = $prod['price'] ?? 0;
                                    $hasDiscount = ($priceType === 'Umum' && !empty($prod['discount_percent']) && $prod['discount_percent'] > 0);
                                    if ($hasDiscount) {
                                        $finalPrice = $finalPrice - ($finalPrice * $prod['discount_percent'] / 100);
                                    }
                                    
                                    if ($hasDiscount): ?>
                                        <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through; margin-bottom: 2px;">
                                            <?= formatRupiah($prod['price']) ?>
                                            <span class="badge" style="background:var(--danger);color:#fff;margin-left:4px;font-size:0.6rem;">-<?= $prod['discount_percent'] ?>%</span>
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
                <?php endif; ?>
            </div>
            
            <!-- Pagination removed to allow infinite scroll visually -->
        </div>
        
        <footer class="shop-footer">
            <div class="shop-footer-bottom">&copy; <?= date('Y') ?> <?= $storeName ?></div>
        </footer>
    </div>
    <script src="<?= ASSETS_URL ?>/js/app.js"></script>
</body>
</html>
