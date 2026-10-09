<?php
/**
 * Sarung Santri Store - Manajemen Produk
 * CRUD produk dengan variasi & harga bertingkat (HPP + Eceran/Grosir/Reseller)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
try { $db->exec("ALTER TABLE products ADD COLUMN discount_percent INT NOT NULL DEFAULT 0 AFTER base_price"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE products ADD COLUMN last_base_price DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER base_price"); } catch(Exception $e) {}
$pageTitle = 'Produk';
$breadcrumbs = [['label' => 'Produk']];

// =============================================
// HANDLE ACTIONS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $sku = sanitize($_POST['sku'] ?? '');
        $name = sanitize($_POST['name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $weight = (int)($_POST['weight'] ?? 0);
        $description = sanitize($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        $is_best_seller = isset($_POST['is_best_seller']) ? 1 : 0;
        $online_visibility = isset($_POST['online_visibility']) ? 'show' : 'hide';
        $slug = createSlug($name);
        $basePrice = (float)str_replace(['.', ','], ['', '.'], $_POST['base_price'] ?? '0');
        
        if (empty($name)) {
            flashMessage('error', 'Nama produk harus diisi.');
            redirect(BASE_URL . '/admin/products.php');
        }
        
        // Handle image upload
        $imgPaths = ['image' => null, 'image2' => null, 'image3' => null];
        foreach (['image', 'image2', 'image3'] as $imgField) {
            if (!empty($_FILES[$imgField]['name'])) {
                $upload = uploadImage($_FILES[$imgField], 'products');
                if ($upload['success']) {
                    $imgPaths[$imgField] = $upload['path'];
                } else {
                    flashMessage('error', $upload['message']);
                    redirect(BASE_URL . '/admin/products.php');
                }
            }
        }
        
        if ($action === 'add') {
            // Validasi SKU Induk
            if (!empty($sku)) {
                $sku = trim($sku);
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(sku)) = LOWER(TRIM(?))");
                $stmt->execute([$sku]);
                if ($stmt->fetchColumn() > 0) {
                    flashMessage('error', 'Gagal! SKU sudah digunakan oleh produk lain.');
                    redirect(BASE_URL . '/admin/products.php');
                    exit;
                }
            }

            try {
                $stmt = $db->prepare("INSERT INTO products (category_id, weight, sku, name, slug, description, image, image2, image3, is_active, is_featured, is_best_seller, online_visibility, base_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$category_id, $weight, $sku, $name, $slug, $description, $imgPaths['image'], $imgPaths['image2'], $imgPaths['image3'], $is_active, $is_featured, $is_best_seller, $online_visibility, $basePrice]);
                $productId = $db->lastInsertId();
                
                // Add default variation (1)
                $varSku = ($sku ?: 'PRD-' . $productId) . '-1';
                $stock = (int)($_POST['stock_qty'] ?? 0);
                
                $stmt = $db->prepare("INSERT INTO product_variations (product_id, sku, variation_name, stock_qty, base_price) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$productId, $varSku, '1', $stock, $basePrice]);
                $varId = $db->lastInsertId();
                
                flashMessage('success', 'Produk berhasil ditambahkan. Silakan atur harga di menu Harga Produk.');
                logActivity('Tambah', 'Produk', 'Produk: ' . $name);
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') {
                    flashMessage('error', 'Gagal! SKU sudah digunakan oleh produk lain.');
                } else {
                    flashMessage('error', 'Terjadi kesalahan sistem saat menambah produk.');
                }
            }
        } else {
            $id = (int)$_POST['id'];
            
            $stmt = $db->prepare("SELECT image, image2, image3 FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $oldImgs = $stmt->fetch(PDO::FETCH_ASSOC);

            $finalImg = $imgPaths['image'] ?: $oldImgs['image'];
            if ($imgPaths['image'] && !empty($oldImgs['image'])) deleteUploadedFile($oldImgs['image']);

            $finalImg2 = $imgPaths['image2'] ?: $oldImgs['image2'];
            if ($imgPaths['image2'] && !empty($oldImgs['image2'])) deleteUploadedFile($oldImgs['image2']);

            $finalImg3 = $imgPaths['image3'] ?: $oldImgs['image3'];
            if ($imgPaths['image3'] && !empty($oldImgs['image3'])) deleteUploadedFile($oldImgs['image3']);
            
            $stmtVar = $db->prepare("SELECT COUNT(*) FROM product_variations WHERE product_id = ?");
            $stmtVar->execute([$id]);
            $varCount = (int)$stmtVar->fetchColumn();

            if ($varCount > 1 && empty($sku)) {
                flashMessage('error', 'Gagal! SKU Induk wajib diisi karena produk ini memiliki lebih dari 1 varian.');
                redirect(BASE_URL . '/admin/products.php');
                exit;
            }

            // Cek duplikasi SKU secara case-insensitive untuk edit
            if (!empty($sku)) {
                $sku = trim($sku);

                $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(sku)) = LOWER(TRIM(?)) AND id != ?");
                $stmt->execute([$sku, $id]);
                if ($stmt->fetchColumn() > 0) {
                    flashMessage('error', 'Gagal! SKU sudah digunakan oleh produk lain.');
                    redirect(BASE_URL . '/admin/products.php');
                    exit;
                }
            }

            try {
                $stmt = $db->prepare("UPDATE products SET category_id=?, weight=?, sku=?, name=?, slug=?, description=?, image=?, image2=?, image3=?, is_active=?, is_featured=?, is_best_seller=?, online_visibility=?, base_price=? WHERE id=?");
                $stmt->execute([$category_id, $weight, $sku, $name, $slug, $description, $finalImg, $finalImg2, $finalImg3, $is_active, $is_featured, $is_best_seller, $online_visibility, $basePrice, $id]);
                
                flashMessage('success', 'Produk berhasil diperbarui.');
                logActivity('Edit', 'Produk', 'Produk ID: ' . $id);
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') {
                    flashMessage('error', 'Gagal! SKU sudah digunakan oleh produk lain.');
                } else {
                    flashMessage('error', 'Terjadi kesalahan sistem saat memperbarui produk.');
                }
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            $stmt = $db->prepare("SELECT image, image2, image3 FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $imgs = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
            
            if (!empty($imgs['image'])) deleteUploadedFile($imgs['image']);
            if (!empty($imgs['image2'])) deleteUploadedFile($imgs['image2']);
            if (!empty($imgs['image3'])) deleteUploadedFile($imgs['image3']);
            
            flashMessage('success', 'Produk berhasil dihapus.');
            logActivity('Hapus', 'Produk', 'Produk ID: ' . $id);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                flashMessage('error', 'Produk tidak bisa dihapus karena sedang digunakan dalam transaksi (Penjualan, Pembelian, Surat Jalan, dll).');
            } else {
                flashMessage('error', 'Gagal menghapus produk: ' . $e->getMessage());
            }
        }
    }
    
    redirect(BASE_URL . '/admin/products.php');
}

// =============================================
// FETCH DATA
// =============================================
$page = max(1, (int)($_GET['page'] ?? 1));
$search = sanitize($_GET['search'] ?? '');
$filterCategory = (int)($_GET['category'] ?? 0);

$where = "WHERE 1=1";
$params = [];

if ($search) {
    $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterCategory) {
    $where .= " AND p.category_id = ?";
    $params[] = $filterCategory;
}

// Count total
$stmt = $db->prepare("SELECT COUNT(*) FROM products p $where");
$stmt->execute($params);
$totalItems = $stmt->fetchColumn();
$pagination = getPagination($totalItems, $page);

// Sorting parameters
$sortBy = sanitize($_GET['sort_by'] ?? '');
$order = strtoupper(sanitize($_GET['order'] ?? 'ASC'));
if (!in_array($order, ['ASC', 'DESC'])) $order = 'ASC';

$allowedSorts = [
    'id' => 'p.id',
    'name' => 'p.name',
    'sku' => 'p.sku',
    'category' => 'category_name',
    'stock' => 'total_stock'
];

$orderBy = 'c.name ASC, p.name ASC';
if (array_key_exists($sortBy, $allowedSorts)) {
    $dbField = $allowedSorts[$sortBy];
    $orderBy = "$dbField $order";
}

// Fetch products with total stock accumulated from all variations
$stmt = $db->prepare("
    SELECT p.*, c.name as category_name,
    COALESCE((SELECT SUM(pv.stock_qty) FROM product_variations pv WHERE pv.product_id = p.id), 0) as total_stock,
    (SELECT pp.selling_price FROM product_prices pp JOIN price_types pt ON pp.price_type_id = pt.id WHERE pp.product_id = p.id AND pp.label = 'Ecer' AND pt.name = 'Umum' LIMIT 1) as selling_price_eceran,
    (SELECT COUNT(*) FROM product_variations WHERE product_id = p.id) as variation_count
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    $where 
    ORDER BY $orderBy 
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$products = $stmt->fetchAll();

// Categories for filter/form
$stmt = $db->query("
    SELECT c.id, c.name, c.parent_id, p.name as parent_name 
    FROM categories c 
    LEFT JOIN categories p ON c.parent_id = p.id 
    ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.sort_order ASC, c.name ASC
");
$allCategories = $stmt->fetchAll();

// Build structured category tree for optgroup rendering
$parentCats  = []; // root_id => [sub categories]
$rootCats    = []; // root categories
$categoryMap = []; // id => category row (for fast lookup)
foreach ($allCategories as $cat) {
    $categoryMap[$cat['id']] = $cat;
    if ($cat['parent_id'] === null) {
        $rootCats[$cat['id']] = $cat;
        if (!isset($parentCats[$cat['id']])) $parentCats[$cat['id']] = [];
    } else {
        $parentCats[$cat['parent_id']][] = $cat;
    }
}

include INCLUDES_PATH . '/header.php';
?>

<!-- Toolbar -->
<div class="toolbar">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex:1;">
        <div class="search-box" style="flex:1; max-width:260px; margin-right:8px;">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="search" class="form-control" placeholder="Cari produk atau SKU Induk..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
        </div>
        <select name="category" class="form-control" style="width:200px;" onchange="this.form.submit()">
            <option value="0">Semua Kategori</option>
            <?php foreach ($allCategories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>>
                    <?= $cat['parent_id'] ? '&nbsp;&nbsp;— ' : '' ?><?= htmlspecialchars($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-outline">Cari</button></noscript>
    </form>
    <div class="filter-group">
        <button class="btn btn-primary" id="btnTambahProduk" onclick="openModal('modalProduct'); resetProductForm()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Tambah Produk
        </button>
        
        <?php 
        $importType = 'products';
        $hasImport = true;
        $hasExport = true;
        $hasTemplate = true;
        include INCLUDES_PATH . '/import_export_toolbar.php'; 
        ?>
    </div>
</div>



<!-- Products Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <?php
            $renderSortIcon = function($col, $type = 'alpha') use ($sortBy, $order) {
                if ($sortBy !== $col) {
                    return '<i data-lucide="chevrons-up-down" style="width:14px;height:14px;margin-left:4px;opacity:0.3;vertical-align:middle;"></i>';
                }
                
                if ($type === 'alpha') {
                    $icon = $order === 'ASC' ? 'arrow-up-a-z' : 'arrow-down-z-a';
                } else {
                    $icon = $order === 'ASC' ? 'arrow-up-0-9' : 'arrow-down-9-0';
                }
                return '<i data-lucide="' . $icon . '" style="width:14px;height:14px;margin-left:4px;color:var(--primary-600);vertical-align:middle;"></i>';
            };
            ?>
            <table class="table" id="productsTable">
                <thead>
                    <tr>
                        <th style="width:70px;">
                            <a href="?sort_by=id&order=<?= ($sortBy === 'id' && $order === 'ASC') ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterCategory ? '&category='.(int)$filterCategory : '' ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">
                                ID <?= $renderSortIcon('id', 'numeric') ?>
                            </a>
                        </th>
                        <th style="min-width:200px;">
                            <a href="?sort_by=name&order=<?= ($sortBy === 'name' && $order === 'ASC') ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterCategory ? '&category='.(int)$filterCategory : '' ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">
                                Produk <?= $renderSortIcon('name', 'alpha') ?>
                            </a>
                        </th>
                        <th style="width:130px;">
                            <a href="?sort_by=sku&order=<?= ($sortBy === 'sku' && $order === 'ASC') ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterCategory ? '&category='.(int)$filterCategory : '' ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">
                                SKU Induk <?= $renderSortIcon('sku', 'alpha') ?>
                            </a>
                        </th>
                        <th style="width:130px;">
                            <a href="?sort_by=category&order=<?= ($sortBy === 'category' && $order === 'ASC') ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterCategory ? '&category='.(int)$filterCategory : '' ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">
                                Kategori <?= $renderSortIcon('category', 'alpha') ?>
                            </a>
                        </th>
                        <th style="width:120px;">HPP (Modal)</th>
                        <th style="width:120px;">Harga Jual</th>
                        <th style="width:100px;">
                            <a href="?sort_by=stock&order=<?= ($sortBy === 'stock' && $order === 'ASC') ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterCategory ? '&category='.(int)$filterCategory : '' ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">
                                Total Stok <?= $renderSortIcon('stock', 'numeric') ?>
                            </a>
                        </th>
                        <th style="width:100px;">Status</th>
                        <th style="width:150px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr><td colspan="9" class="text-center text-muted" style="padding:40px;">Tidak ada produk ditemukan</td></tr>
                    <?php else: ?>
                        <?php foreach ($products as $prod): ?>
                            <tr>
                                <td class="text-sm text-muted"><?= (int)$prod['id'] ?></td>
                                <td>
                                    <div class="product-cell">
                                        <?php if ($prod['image']): ?>
                                            <img src="<?= BASE_URL . '/' . $prod['image'] ?>" class="product-img" alt="">
                                        <?php else: ?>
                                            <div class="product-img" style="display:flex;align-items:center;justify-content:center;background:var(--primary-50);color:var(--primary-400);">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="text-bold" style="display:flex;align-items:center;">
                                                <?= htmlspecialchars($prod['name']) ?>
                                                <?php if (!empty($prod['discount_percent'])): ?>
                                                    <span class="badge" style="background:var(--danger);color:#fff;margin-left:6px;font-size:0.7rem;">-<?= $prod['discount_percent'] ?>%</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-muted"><?= $prod['variation_count'] ?> varian</div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="text-bold"><?= htmlspecialchars($prod['sku'] ?? '-') ?></span></td>
                                <td>
                                    <?php
                                    $catInfo = isset($categoryMap[$prod['category_id']]) ? $categoryMap[$prod['category_id']] : null;
                                    $catHasParent = $catInfo && $catInfo['parent_id'];
                                    ?>
                                    <?php if ($prod['category_name']): ?>
                                        <?php if ($catHasParent): ?>
                                            <!-- Sub kategori: tampilkan breadcrumb Induk › Sub -->
                                            <div style="display:flex;flex-direction:column;gap:3px;line-height:1.3;">
                                                <span style="font-size:0.7rem;color:var(--gray-500);"><?= htmlspecialchars($catInfo['parent_name']) ?></span>
                                                <span class="badge badge-primary" style="font-size:0.72rem;width:fit-content;">
                                                    <?= htmlspecialchars($prod['category_name']) ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <!-- Kategori utama: tampil sebagai badge biasa -->
                                            <span class="badge badge-primary"><?= htmlspecialchars($prod['category_name']) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm">
                                    <div style="font-weight:600; color:var(--primary-600); display:flex; align-items:center;">
                                        <?= formatRupiah($prod['base_price'] ?? 0) ?>
                                        <?php 
                                        $currBase = (float)($prod['base_price'] ?? 0);
                                        $lastBase = (float)($prod['last_base_price'] ?? 0);
                                        if ($lastBase > 0 && $currBase != $lastBase) {
                                            if ($currBase > $lastBase) {
                                                echo '<span title="Naik dari '.formatRupiah($lastBase).'" style="color:var(--danger); font-size:0.7rem; margin-left:6px;">▲</span>';
                                            } else {
                                                echo '<span title="Turun dari '.formatRupiah($lastBase).'" style="color:var(--success); font-size:0.7rem; margin-left:6px;">▼</span>';
                                            }
                                        } elseif ($lastBase > 0 && $currBase == $lastBase) {
                                            echo '<span title="Tetap" style="color:var(--gray-500); font-size:0.7rem; margin-left:6px;">■</span>';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td class="text-sm" style="font-weight:600; color:var(--success-600);">
                                    <?= formatRupiah($prod['selling_price_eceran'] ?? 0) ?>
                                </td>
                                <td>
                                    <?php 
                                    $totalStock = (int)$prod['total_stock'];
                                    $stockClass = $totalStock < 3 ? 'text-danger text-bold' : 'text-bold';
                                    ?>
                                    <span class="<?= $stockClass ?>"><?= formatNumber($totalStock) ?> pcs</span>
                                </td>
                                <td>
                                    <?php if ($prod['is_active']): ?>
                                        <span class="badge badge-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge badge-gray">Nonaktif</span>
                                    <?php endif; ?>
                                    <?php if ($prod['is_featured']): ?>
                                        <span class="badge badge-gold">⭐</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions" style="gap:4px;">
                                        <a href="<?= BASE_URL ?>/admin/variations.php?product_id=<?= $prod['id'] ?>" 
                                           class="btn btn-sm btn-primary" 
                                           title="Kelola Varian & Stok"
                                           style="gap:5px; font-size:0.78rem; white-space:nowrap;">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                                        </a>
                                        <button class="btn btn-sm btn-outline btn-icon" title="Edit"
                                            onclick="editProduct(<?= htmlspecialchars(json_encode($prod, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG), ENT_QUOTES) ?>)">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Yakin hapus produk ini? Semua varian akan ikut terhapus.')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $prod['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline btn-icon" title="Hapus" style="color:var(--danger);">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div class="text-sm text-muted">
            Menampilkan <strong><?= count($products) ?></strong> dari <strong><?= number_format($totalItems) ?></strong> produk
        </div>
        <?= renderPagination($pagination, BASE_URL . '/admin/products.php') ?>
    </div>
</div>

<!-- Modal Tambah/Edit Produk -->
<div class="modal-overlay" id="modalProduct">
    <div class="modal modal-lg">
        <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-600), var(--primary-700)); color:#fff; border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                <h3 class="modal-title" id="modalProductTitle" style="color:#fff;">Tambah Produk</h3>
            </div>
            <button class="modal-close" onclick="closeModal('modalProduct')" style="color:#fff; opacity:0.8;">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="productForm">
            <input type="hidden" name="action" id="productAction" value="add">
            <input type="hidden" name="id" id="productId">
            <div class="modal-body">
                
                <div class="form-row">
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Nama Produk <span class="required">*</span></label>
                        <input type="text" name="name" id="productName" class="form-control" placeholder="Contoh: Sarung Atlas Motif BHS" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Kategori</label>
                        <select name="category_id" id="productCategory" class="form-control">
                            <option value="">— Tanpa Kategori —</option>
                            <?php foreach ($allCategories as $cat): ?>
                                <option value="<?= $cat['id'] ?>">
                                    <?= $cat['parent_id'] ? '&nbsp;&nbsp;— ' : '' ?><?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="newProductFields" style="flex: 1;">
                        <label class="form-label">Stok Awal</label>
                        <input type="number" name="stock_qty" class="form-control" value="0" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">SKU Induk</label>
                        <input type="text" name="sku" id="productSku" class="form-control" placeholder="Auto-generate">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Berat (Gram)</label>
                        <input type="number" name="weight" id="productWeight" class="form-control" placeholder="Contoh: 500" value="75" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="color: var(--primary-700); font-weight:700;">HPP (Modal Pokok) *</label>
                        <input type="text" name="base_price" id="productBasePrice" class="form-control rupiah-input" placeholder="0" style="border-color: var(--primary-400);" required>
                    </div>
                </div>

                <!-- Seksi Foto -->
                <div style="border-top: 1px solid var(--border-color); margin: 8px 0 14px; padding-top: 14px;">
                    <div class="text-semibold text-sm text-muted mb-8">📷 Foto Produk Utama (Opsional)</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Foto Utama</label>
                        <input type="file" name="image" id="productImg1" class="form-control" accept="image/*" onchange="previewImg(this,'prev1')">
                        <img id="prev1" src="" style="display:none;width:60px;height:60px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Foto 2 (Opsional)</label>
                        <input type="file" name="image2" id="productImg2" class="form-control" accept="image/*" onchange="previewImg(this,'prev2')">
                        <img id="prev2" src="" style="display:none;width:60px;height:60px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Foto 3 (Opsional)</label>
                        <input type="file" name="image3" id="productImg3" class="form-control" accept="image/*" onchange="previewImg(this,'prev3')">
                        <img id="prev3" src="" style="display:none;width:60px;height:60px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Deskripsi</label>
                    <textarea name="description" id="productDesc" class="form-control" rows="2" placeholder="Deskripsi produk"></textarea>
                </div>
                

                
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: nowrap; gap: 10px; margin-top: 12px; margin-bottom: 8px; padding: 12px 16px; background: var(--gray-50); border-radius: 8px; border: 1px solid var(--border-color); overflow-x: auto;">
                    <label class="form-check" style="margin: 0; white-space: nowrap;">
                        <input type="checkbox" name="is_active" id="productActive" checked>
                        <span style="font-size: 0.9rem;">Produk Aktif</span>
                    </label>
                    <label class="form-check" style="margin: 0; white-space: nowrap;">
                        <input type="checkbox" name="is_featured" id="productFeatured">
                        <span style="font-size: 0.9rem;">⭐ Produk Unggulan</span>
                    </label>
                    <label class="form-check" style="margin: 0; white-space: nowrap;">
                        <input type="checkbox" name="is_best_seller" id="productBestSeller">
                        <span style="font-size: 0.9rem;">🔥 Best Seller</span>
                    </label>
                    <label class="form-check" style="margin: 0; white-space: nowrap;">
                        <input type="checkbox" name="online_visibility" id="productOnline" checked>
                        <span style="font-size: 0.9rem;">🌐 Tampil di Online</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalProduct')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Simpan Produk
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function resetProductForm() {
    document.getElementById('modalProductTitle').textContent = 'Tambah Produk';
    document.getElementById('productAction').value = 'add';
    document.getElementById('productId').value = '';
    document.getElementById('productForm').reset();
    document.getElementById('productBasePrice').value = '0';
    document.getElementById('productActive').checked = true;
    document.getElementById('productOnline').checked = true;
    
    // Reset Stok Awal field
    const stockField = document.getElementById('newProductFields');
    stockField.style.display = 'block';
    const stockInput = stockField.querySelector('input[name="stock_qty"]');
    stockInput.removeAttribute('readonly');
    stockField.querySelector('label').textContent = 'Stok Awal';
    
    ['prev1','prev2','prev3'].forEach(id => {
        const el = document.getElementById(id);
        el.src = ''; el.style.display = 'none';
    });
}

function editProduct(prod) {
    document.getElementById('modalProductTitle').textContent = 'Edit Produk';
    document.getElementById('productAction').value = 'edit';
    document.getElementById('productId').value = prod.id;
    document.getElementById('productSku').value = prod.sku || '';
    document.getElementById('productName').value = prod.name;
    document.getElementById('productCategory').value = prod.category_id || '';
    document.getElementById('productWeight').value = prod.weight || '75';
    var basePriceNum = parseFloat(prod.base_price) || 0;
    document.getElementById('productBasePrice').value = basePriceNum > 0
        ? new Intl.NumberFormat('id-ID').format(Math.round(basePriceNum))
        : '0';
    document.getElementById('productDesc').value = prod.description || '';
    document.getElementById('productActive').checked = prod.is_active == 1;
    document.getElementById('productFeatured').checked = prod.is_featured == 1;
    document.getElementById('productBestSeller').checked = prod.is_best_seller == 1;
    document.getElementById('productOnline').checked = (prod.online_visibility || 'show') === 'show';
    
    // Tampilkan total stok di mode edit (readonly)
    const stockField = document.getElementById('newProductFields');
    stockField.style.display = 'block';
    const stockInput = stockField.querySelector('input[name="stock_qty"]');
    stockInput.value = prod.total_stock || 0;
    stockInput.setAttribute('readonly', 'readonly');
    stockField.querySelector('label').textContent = 'Stok SKU Induk (Total)';
    
    openModal('modalProduct');
}

function previewImg(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Init Rupiah input formatting
initRupiahInput('.rupiah-input');
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
