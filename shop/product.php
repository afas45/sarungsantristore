<?php
/**
 * Kasir Ibtidaiyah - Detail Produk
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();
$productId = (int)($_GET['id'] ?? 0);

if (!$productId) {
    redirect(BASE_URL . '/shop/index.php');
}

// Determine price type based on login session
$priceType = $_SESSION['price_type_name'] ?? 'Umum';

$stmt = $db->prepare("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.id = ? AND p.is_active = 1
    AND (p.category_id IS NULL OR c.online_visibility = 1)
");
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product || ($product['online_visibility'] ?? 'show') === 'hide') {
    flashMessage('error', 'Produk tidak ditemukan.');
    redirect(BASE_URL . '/shop/index.php');
}

// Fetch variations
$stmt = $db->prepare("SELECT * FROM product_variations WHERE product_id = ? AND is_active = 1 ORDER BY id");
$stmt->execute([$productId]);
$variations = $stmt->fetchAll();

// Fetch price from product_prices table
$stmtPrice = $db->prepare("
    SELECT pp.*, pt.name as price_type 
    FROM product_prices pp
    JOIN price_types pt ON pp.price_type_id = pt.id
    WHERE pp.product_id = ?
    ORDER BY pp.min_qty
");
$stmtPrice->execute([$productId]);
$productPricesAll = $stmtPrice->fetchAll();

// Build tiers structure (compatible with existing display code)
// Group by price type, then by min_qty
$priceByType = [];
foreach ($productPricesAll as $pp) {
    $priceByType[$pp['price_type']][] = [
        'label' => $pp['label'] ?? $pp['price_type'],
        'price_type' => $pp['price_type'],
        'min_qty' => $pp['min_qty'],
        'selling_price' => $pp['selling_price'],
    ];
}

// Use the price type matching the customer's type, fallback to Eceran
$tiersToUse = $priceByType[$priceType] ?? $priceByType['Eceran'] ?? $priceByType['Umum'] ?? [];

// Attach tiers to each variation (product-level prices, not variation-level)
foreach ($variations as &$v) {
    $v['tiers'] = $tiersToUse;
}
unset($v);

// Add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'add_to_cart') {
    $varId = (int)$_POST['variation_id'];
    $qty = max(1, (int)$_POST['qty']);
    
    if (!isset($_SESSION['shop_cart'])) $_SESSION['shop_cart'] = [];
    
    $found = false;
    $existingQty = 0;
    foreach ($_SESSION['shop_cart'] as &$item) {
        if ($item['variation_id'] == $varId) {
            $existingQty = $item['qty'];
            $found = true;
            break;
        }
    }
    
    // Check stock availability
    $stmt = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ?");
    $stmt->execute([$varId]);
    $stockQty = $stmt->fetchColumn();
    
    if ($stockQty !== false && ($existingQty + $qty) > $stockQty) {
        flashMessage('error', 'Stok tidak mencukupi! Sisa stok: ' . $stockQty);
        redirect(BASE_URL . '/shop/product.php?id=' . $productId . '&var_id=' . $varId);
        exit;
    }
    
    if ($found) {
        $item['qty'] += $qty;
    }
    unset($item);
    
    if (!$found) {
        $_SESSION['shop_cart'][] = [
            'variation_id' => $varId,
            'product_id' => $productId,
            'product_name' => $product['name'],
            'variation_name' => null,
            'qty' => $qty,
            'image' => $product['image'],
        ];
        
        // Find variation name and use variant image if available
        foreach ($variations as $v) {
            if ($v['id'] == $varId) {
                $lastIdx = count($_SESSION['shop_cart'])-1;
                $_SESSION['shop_cart'][$lastIdx]['variation_name'] = $v['variation_name'];
                if (!empty($v['image'])) {
                    $_SESSION['shop_cart'][$lastIdx]['image'] = $v['image'];
                }
                break;
            }
        }
    }
    
    flashMessage('success', 'Produk ditambahkan ke keranjang!');
    redirect(BASE_URL . '/shop/product.php?id=' . $productId . '&var_id=' . $varId);
}

$cartCount = 0;
if (isset($_SESSION['shop_cart'])) {
    $cartCount = array_sum(array_column($_SESSION['shop_cart'], 'qty'));
}

$storeName  = 'SarungSantriStore';
$searchVal  = '';    // Tidak ada search di halaman produk
$showSearch = false; // Sembunyikan search bar di navbar product page
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($product['name']) ?> - <?= $storeName ?>">
    <title><?= htmlspecialchars($product['name']) ?> - <?= $storeName ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
    <style>
        .product-description-text { line-height: 1.7; }
    </style>
</head>
<body>
    <div class="shop-layout">
        <?php include INCLUDES_PATH . '/shop_navbar.php'; ?>
        
        <div class="shop-content">
            <?= renderFlashMessage() ?>
            
            <!-- Breadcrumb -->
            <div style="font-size: 0.8125rem; color: var(--gray-500); margin-bottom: 20px; font-weight: 600;">
                <a href="<?= BASE_URL ?>/shop/index.php" style="color: var(--primary-600); text-decoration: none;">Beranda</a>
                <?php if ($product['category_name']): ?>
                    → <a href="<?= BASE_URL ?>/shop/category.php?category=<?= $product['category_id'] ?>" style="color: var(--primary-600); text-decoration: none;"><?= htmlspecialchars($product['category_name']) ?></a>
                <?php endif; ?>
                → <?= htmlspecialchars($product['name']) ?>
            </div>
            
            <!-- Product Detail -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 40px;" class="product-detail-grid">
                <!-- Image -->
                <div>
                    <div id="productMainImage" style="position: relative; background: #efefef; border-radius: var(--border-radius-lg); overflow: hidden; aspect-ratio: 1; display:flex; align-items:center; justify-content:center;">
                        <?php if ($product['image']): ?>
                            <!-- fetchpriority=high karena ini LCP element (above the fold) -->
                            <img src="<?= BASE_URL . '/' . $product['image'] ?>"
                                 alt="<?= htmlspecialchars($product['name']) ?>"
                                 style="width:100%; height:100%; object-fit: contain;"
                                 id="productImg"
                                 loading="lazy"
                                 fetchpriority="high"
                                 decoding="async"
                                 width="600" height="600">
                        <?php else: ?>
                            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="var(--gray-300)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <?php endif; ?>
                    </div>
                    <?php 
                    // Collect images for main product and each variation
                    $galleries = [];
                    $prodImgs = array_values(array_filter([$product['image'], $product['image2'], $product['image3']]));
                    $galleries[0] = $prodImgs;
                    
                    foreach ($variations as $v) {
                        $varImgs = array_values(array_filter([$v['image'], $v['image2'], $v['image3']]));
                        $galleries[$v['id']] = !empty($varImgs) ? $varImgs : $prodImgs;
                    }
                    ?>
                    <script>
                        const productGalleries = <?= json_encode($galleries) ?>;
                        const siteBaseUrl = <?= json_encode(BASE_URL) ?>;
                    </script>
                    <div id="productGalleryContainer" style="display:none; gap:8px; margin-top:12px; overflow-x:auto; min-height: 68px;">
                        <!-- Diisi oleh JS -->
                    </div>
                </div>
                
                <!-- Info -->
                <div>
                    <?php if ($product['category_name']): ?>
                        <span class="badge badge-primary" style="margin-bottom: 8px;"><?= htmlspecialchars($product['category_name']) ?></span>
                    <?php endif; ?>
                    
                    <?php 
                    $targetVarId = isset($_GET['var_id']) ? (int)$_GET['var_id'] : 0;
                    $selectedIndex = 0;
                    if ($targetVarId > 0 && !empty($variations)) {
                        foreach ($variations as $i => $v) {
                            if ((int)$v['id'] === $targetVarId) {
                                $selectedIndex = $i;
                                break;
                            }
                        }
                    }
                    $selectedVarId = !empty($variations) ? $variations[$selectedIndex]['id'] : 0;
                    $selectedVarName = !empty($variations) ? $variations[$selectedIndex]['variation_name'] : '';
                    
                    $displayProductName = $product['name'];
                    if (!empty($selectedVarName) && !in_array(strtolower($selectedVarName), ['random', 'default', ''])) {
                        $displayProductName .= ' - ' . $selectedVarName;
                    }
                    ?>
                    <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--gray-900); margin-bottom: 8px;"><?= htmlspecialchars($displayProductName) ?></h1>
                    <p style="display: none; color: var(--gray-500); font-size: 0.875rem; margin-bottom: 16px;">SKU: <?= htmlspecialchars($product['sku'] ?? '-') ?></p>
                    
                    <!-- Variations & Pricing -->
                    <form method="POST">
                        <input type="hidden" name="action" value="add_to_cart">
                        
                        <input type="hidden" name="variation_id" value="<?= $selectedVarId ?>">
                        
                        <!-- Tiered Pricing Display -->
                        <?php foreach ($variations as $v): ?>
                            <div class="tier-display" id="tiers-<?= $v['id'] ?>" style="<?= $v['id'] !== $selectedVarId ? 'display:none;' : '' ?> margin-bottom: 20px;">
                                <?php if (!empty($v['tiers'])): ?>
                                    <div style="font-size: 1.75rem; font-weight: 800; color: var(--primary-700); margin-bottom: 12px;">
                                        <?= formatRupiah($v['tiers'][0]['selling_price']) ?>
                                    </div>
                                    
                                    <?php if (count($v['tiers']) > 1): ?>
                                        <div style="display: none; background: var(--gold-50); border: 1px solid var(--gold-200); border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                                            <div style="font-weight: 600; font-size: 0.8125rem; color: var(--gold-700); margin-bottom: 8px;">🏷️ Harga Grosir:</div>
                                            <?php foreach ($v['tiers'] as $tier): ?>
                                                <div style="display:flex; justify-content:space-between; padding: 4px 0; font-size: 0.875rem;">
                                                    <span><?= htmlspecialchars($tier['label']) ?> (≥<?= $tier['min_qty'] ?> pcs)</span>
                                                    <span class="text-bold"><?= formatRupiah($tier['selling_price']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <div style="font-size: 0.875rem; color: <?= $v['stock_qty'] > 0 ? 'var(--success)' : 'var(--danger)' ?>; margin-bottom: 16px;">
                                    Stok: <?= $v['stock_qty'] ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Quantity -->
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                            <label style="font-weight: 600; font-size: 0.875rem;">Jumlah:</label>
                            <div style="display:flex; align-items:center; gap:4px;">
                                <button type="button" onclick="changeQty(-1)" style="width:36px;height:36px;border:1px solid var(--border-color);border-radius:8px;background:var(--gray-50);font-size:1.25rem;cursor:pointer;">−</button>
                                <input type="number" name="qty" id="productQty" value="1" min="1" style="width:60px;text-align:center;border:1px solid var(--border-color);border-radius:8px;padding:8px;font-weight:600;font-size:1rem;">
                                <button type="button" onclick="changeQty(1)" style="width:36px;height:36px;border:1px solid var(--border-color);border-radius:8px;background:var(--gray-50);font-size:1.25rem;cursor:pointer;">+</button>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn btn-primary btn-lg" <?= ($variations[0]['stock_qty'] ?? 0) <= 0 ? 'disabled' : '' ?> style="flex:1;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                                Tambah ke Keranjang
                            </button>
                        </div>
                    </form>
                    
                    <?php if ($product['description']): ?>
                        <div style="margin-top: 32px; border-top: 1px solid var(--gray-200); padding-top: 24px;">
                            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--gray-900); margin-bottom: 12px;">Deskripsi Produk</h3>
                            <div class="product-description-text" style="color: var(--gray-700);">
                                <?= nl2br(htmlspecialchars($product['description'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <footer class="shop-footer">
            <div class="shop-footer-bottom">&copy; <?= date('Y') ?> <?= $storeName ?>. v<?= APP_VERSION ?></div>
        </footer>
    </div>
    
    <div class="toast-container" id="toastContainer"></div>
    <script src="<?= ASSETS_URL ?>/js/app.js"></script>
    <script>
    function changeQty(delta) {
        const input = document.getElementById('productQty');
        input.value = Math.max(1, parseInt(input.value || 1) + delta);
    }
    
    function showTiers(varId) {
        document.querySelectorAll('.tier-display').forEach(el => el.style.display = 'none');
        const tierEl = document.getElementById('tiers-' + varId);
        if (tierEl) tierEl.style.display = '';
        
        // Highlight selected variant button
        document.querySelectorAll('.var-btn').forEach(btn => btn.style.borderColor = 'var(--border-color)');
        if (event && event.target && event.target.closest('label')) {
            event.target.closest('label').querySelector('.var-btn').style.borderColor = 'var(--primary-500)';
        }
        
        // Update gallery
        renderGallery(varId);
    }
    
    let currentImgs = [];
    
    function renderGallery(varId) {
        const container = document.getElementById('productGalleryContainer');
        const mainImgDiv = document.getElementById('productMainImage');
        if (!container || typeof productGalleries === 'undefined') return;
        
        currentImgs = productGalleries[varId] || productGalleries[0] || [];
        
        if (currentImgs.length > 0) {
            let sliderHTML = `<img src="${siteBaseUrl}/${currentImgs[0]}" loading="lazy" decoding="async" style="width:100%; height:100%; object-fit: contain;" id="productImg" data-index="0">`;
            
            if (currentImgs.length > 1) {
                sliderHTML += `
                    <button type="button" onclick="slideImage(-1)" style="position:absolute; left:16px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.8); border:none; width:40px; height:40px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,0.1); z-index:10; transition:background 0.2s;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gray-700)" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <button type="button" onclick="slideImage(1)" style="position:absolute; right:16px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.8); border:none; width:40px; height:40px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,0.1); z-index:10; transition:background 0.2s;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gray-700)" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                `;
            }
            mainImgDiv.innerHTML = sliderHTML;
            
            if (currentImgs.length > 1) {
                let html = '';
                currentImgs.forEach((img, index) => {
                    let border = index === 0 ? 'var(--primary-600)' : 'var(--border-color)';
                    html += `<img src="${siteBaseUrl}/${img}" class="thumb-img" loading="lazy" decoding="async" data-index="${index}" style="width:64px; height:64px; object-fit:cover; border-radius:8px; cursor:pointer; border:2px solid ${border}; transition:border-color 0.2s;" 
                        onclick="setMainImage(${index})" onmouseover="setMainImage(${index})">`;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '';
            }
        }
    }

    function setMainImage(index) {
        if (index < 0) index = currentImgs.length - 1;
        if (index >= currentImgs.length) index = 0;
        
        const mainImg = document.getElementById('productImg');
        if (mainImg && currentImgs[index]) {
            mainImg.src = siteBaseUrl + '/' + currentImgs[index];
            mainImg.setAttribute('data-index', index);
            
            document.querySelectorAll('.thumb-img').forEach(el => {
                if (parseInt(el.getAttribute('data-index')) === index) {
                    el.style.borderColor = 'var(--primary-600)';
                } else {
                    el.style.borderColor = 'var(--border-color)';
                }
            });
        }
    }
    
    function slideImage(direction) {
        const mainImg = document.getElementById('productImg');
        if (mainImg) {
            let currentIndex = parseInt(mainImg.getAttribute('data-index') || 0);
            setMainImage(currentIndex + direction);
        }
    }
    
    let autoSlideInterval;
    
    function startAutoSlide() {
        if (!autoSlideInterval && currentImgs.length > 1) {
            autoSlideInterval = setInterval(() => {
                slideImage(1);
            }, 3000);
        }
    }
    
    function stopAutoSlide() {
        if (autoSlideInterval) {
            clearInterval(autoSlideInterval);
            autoSlideInterval = null;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        let varInput = document.querySelector('input[name="variation_id"]');
        let activeVarId = varInput ? varInput.value : 0;
        
        renderGallery(activeVarId);
        
        // Show correct tier
        document.querySelectorAll('.tier-display').forEach(el => el.style.display = 'none');
        const tierEl = document.getElementById('tiers-' + activeVarId);
        if (tierEl) tierEl.style.display = '';
        
        // Auto-slide setup
        const galleryParent = document.querySelector('.product-detail-grid > div:first-child');
        if (galleryParent) {
            galleryParent.addEventListener('mouseenter', stopAutoSlide);
            galleryParent.addEventListener('mouseleave', startAutoSlide);
        }
        startAutoSlide();
    });
    
    </script>
</body>
</html>
