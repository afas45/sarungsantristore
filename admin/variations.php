<?php
/**
 * Sarung Santri Store - Manajemen Varian & Stok
 * Matrix varian: foto per varian (max 3), quick edit stok, bulk stok masuk, riwayat stok
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();

$productId = (int)($_GET['product_id'] ?? 0);
if (!$productId) {
    flashMessage('error', 'Produk tidak ditemukan.');
    redirect(BASE_URL . '/admin/products.php');
}

$stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    flashMessage('error', 'Produk tidak ditemukan.');
    redirect(BASE_URL . '/admin/products.php');
}

// Fetch product prices from product_prices table (harga per produk, bukan per varian)
try {
    $stmtPP = $db->prepare("
        SELECT pp.label, pp.selling_price, pt.name as price_type
        FROM product_prices pp
        JOIN price_types pt ON pp.price_type_id = pt.id
        WHERE pp.product_id = ?
        ORDER BY pp.min_qty ASC
    ");
    $stmtPP->execute([$productId]);
    $productPrices = $stmtPP->fetchAll();
} catch (PDOException $e) {
    $productPrices = [];
}

// Group prices by label
$pricesByLabel = [];
foreach ($productPrices as $p) {
    $pricesByLabel[$p['label']] = $p['selling_price'];
}

$pageTitle = 'Varian: ' . $product['name'];
$breadcrumbs = [
    ['label' => 'Produk', 'url' => BASE_URL . '/admin/products.php'],
    ['label' => $product['name']]
];

// =============================================
// AUTO-CREATE stock_logs TABLE IF NOT EXISTS
// =============================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS stock_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_variation_id INT NOT NULL,
        qty_change INT NOT NULL,
        qty_before INT NOT NULL DEFAULT 0,
        qty_after INT NOT NULL DEFAULT 0,
        change_type ENUM('restok','penjualan','adjustment','opname') NOT NULL DEFAULT 'adjustment',
        note VARCHAR(255),
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_var_id (product_variation_id)
    )");
} catch (PDOException $e) { /* Table might already exist */ }

// =============================================
// HANDLE ACTIONS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_variation') {
        $sku = sanitize($_POST['sku'] ?? '');
        $variation_name = sanitize($_POST['variation_name'] ?? '');
        $stock_qty = (int)$_POST['stock_qty'];
        
        // Handle variant image upload (max 3)
        $imgPaths = ['var_image' => null, 'var_image2' => null, 'var_image3' => null];
        foreach (['var_image', 'var_image2', 'var_image3'] as $imgField) {
            if (!empty($_FILES[$imgField]['name'])) {
                $upload = uploadImage($_FILES[$imgField], 'variants');
                if ($upload['success']) {
                    $imgPaths[$imgField] = $upload['path'];
                } else {
                    flashMessage('error', $upload['message']);
                    redirect(BASE_URL . '/admin/variations.php?product_id=' . $productId);
                }
            }
        }
        
        if (empty($sku)) {
            // Auto-generate SKU from parent SKU and variation name
            $parentSku = $product['sku'] ?: 'SKU';
            $varPart = $variation_name ? str_replace(' ', '-', trim($variation_name)) : rand(100, 999);
            $sku = $parentSku . '-' . $varPart;
        }

        // Cek duplikasi SKU secara case-insensitive
        $stmt = $db->prepare("SELECT COUNT(*) FROM product_variations WHERE LOWER(TRIM(sku)) = LOWER(TRIM(?))");
        $stmt->execute([$sku]);
        if ($stmt->fetchColumn() > 0) {
            flashMessage('error', 'Gagal! SKU Varian "' . htmlspecialchars($sku) . '" sudah digunakan.');
            redirect(BASE_URL . '/admin/variations.php?product_id=' . $productId);
            exit;
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO product_variations (product_id, sku, variation_name, image, image2, image3, stock_qty, base_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $sku, $variation_name ?: null, $imgPaths['var_image'], $imgPaths['var_image2'], $imgPaths['var_image3'], $stock_qty, $product['base_price']]);
            $newVarId = $db->lastInsertId();
            
            // Sinkronisasi stok SKU induk
            syncProductStock($productId);
            
            // Log stok masuk awal
            if ($stock_qty > 0) {
                $userId = getCurrentUser()['id'] ?? null;
                $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$newVarId, $stock_qty, 0, $stock_qty, 'restok', 'Stok awal varian baru', $userId]);
            }
            
            flashMessage('success', 'Varian "' . htmlspecialchars($variation_name ?: $sku) . '" berhasil ditambahkan.');
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                flashMessage('error', 'Gagal! SKU "' . htmlspecialchars($sku) . '" sudah digunakan.');
            } else {
                flashMessage('error', 'Terjadi kesalahan sistem saat menambah varian.');
            }
        }
    }
    
    if ($action === 'edit_variation') {
        $id = (int)$_POST['var_id'];
        $sku = sanitize($_POST['sku'] ?? '');
        $variation_name = sanitize($_POST['variation_name'] ?? '');
        $new_stock = (int)$_POST['stock_qty'];
        
        // Handle variant image upload
        $imgPaths = ['var_image' => null, 'var_image2' => null, 'var_image3' => null];
        foreach (['var_image', 'var_image2', 'var_image3'] as $imgField) {
            if (!empty($_FILES[$imgField]['name'])) {
                $upload = uploadImage($_FILES[$imgField], 'variants');
                if ($upload['success']) $imgPaths[$imgField] = $upload['path'];
            }
        }
        
        if (empty($sku)) {
            // Auto-generate SKU from parent SKU and variation name
            $parentSku = $product['sku'] ?: 'SKU';
            $varPart = $variation_name ? str_replace(' ', '-', trim($variation_name)) : rand(100, 999);
            $sku = $parentSku . '-' . $varPart;
        }
        
        $stmt = $db->prepare("SELECT image, image2, image3, stock_qty FROM product_variations WHERE id = ?");
        $stmt->execute([$id]);
        $oldVar = $stmt->fetch(PDO::FETCH_ASSOC);
        $oldStock = (int)$oldVar['stock_qty'];
        
        $finalImg = $imgPaths['var_image'] ?: $oldVar['image'];
        if ($imgPaths['var_image'] && !empty($oldVar['image'])) deleteUploadedFile($oldVar['image']);
        $finalImg2 = $imgPaths['var_image2'] ?: $oldVar['image2'];
        if ($imgPaths['var_image2'] && !empty($oldVar['image2'])) deleteUploadedFile($oldVar['image2']);
        $finalImg3 = $imgPaths['var_image3'] ?: $oldVar['image3'];
        if ($imgPaths['var_image3'] && !empty($oldVar['image3'])) deleteUploadedFile($oldVar['image3']);
        
        // Cek duplikasi SKU secara case-insensitive
        $stmt = $db->prepare("SELECT COUNT(*) FROM product_variations WHERE LOWER(TRIM(sku)) = LOWER(TRIM(?)) AND id != ?");
        $stmt->execute([$sku, $id]);
        if ($stmt->fetchColumn() > 0) {
            flashMessage('error', 'Gagal! SKU Varian "' . htmlspecialchars($sku) . '" sudah digunakan.');
            redirect(BASE_URL . '/admin/variations.php?product_id=' . $productId);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE product_variations SET sku=?, variation_name=?, image=?, image2=?, image3=?, stock_qty=? WHERE id=? AND product_id=?");
            $stmt->execute([$sku, $variation_name ?: null, $finalImg, $finalImg2, $finalImg3, $new_stock, $id, $productId]);
            
            // Sinkronisasi stok SKU induk
            syncProductStock($productId);
            
            // Log stok change
            if ($new_stock !== $oldStock) {
                $diff = $new_stock - $oldStock;
                $type = $diff > 0 ? 'adjustment' : 'adjustment';
                $note = sanitize($_POST['stock_note'] ?? 'Edit manual stok');
                $userId = getCurrentUser()['id'] ?? null;
                $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$id, $diff, $oldStock, $new_stock, $type, $note, $userId]);
            }
            
            flashMessage('success', 'Varian berhasil diperbarui.');
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                flashMessage('error', 'Gagal! SKU "' . htmlspecialchars($sku) . '" sudah digunakan.');
            } else {
                flashMessage('error', 'Terjadi kesalahan sistem saat memperbarui varian.');
            }
        }
    }
    
    if ($action === 'quick_stock') {
        // Quick add/subtract stock for a variant
        $var_id = (int)$_POST['var_id'];
        $qty_change = (int)$_POST['qty_change'];
        $change_type = in_array($_POST['change_type'] ?? '', ['restok', 'penjualan', 'adjustment']) ? $_POST['change_type'] : 'adjustment';
        $note = sanitize($_POST['note'] ?? '');
        
        $stmt = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ? AND product_id = ?");
        $stmt->execute([$var_id, $productId]);
        $oldStock = (int)$stmt->fetchColumn();
        $newStock = max(0, $oldStock + $qty_change);
        
        $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ? AND product_id = ?")
           ->execute([$newStock, $var_id, $productId]);
        
        // Sinkronisasi stok SKU induk
        syncProductStock($productId);
        
        $userId = getCurrentUser()['id'] ?? null;
        $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$var_id, $qty_change, $oldStock, $newStock, $change_type, $note ?: 'Perubahan stok manual', $userId]);
        
        flashMessage('success', 'Stok berhasil diperbarui: ' . $oldStock . ' → ' . $newStock . ' pcs');
    }
    
    if ($action === 'bulk_stock') {
        // Bulk distribute stock to multiple variants
        $varStocks = $_POST['bulk_stock'] ?? [];
        $bulkNote = sanitize($_POST['bulk_note'] ?? 'Restok massal');
        $userId = getCurrentUser()['id'] ?? null;
        $updated = 0;
        
        foreach ($varStocks as $varId => $qty) {
            $varId = (int)$varId;
            $qty = (int)$qty;
            
            // Verify variant belongs to this product
            $stmt = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ? AND product_id = ?");
            $stmt->execute([$varId, $productId]);
            $oldStock = $stmt->fetchColumn();
            if ($oldStock === false) continue;
            
            $oldStock = (int)$oldStock;
            $newStock = max(0, $oldStock + $qty);
            
            if ($qty != 0) {
                $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")
                   ->execute([$newStock, $varId]);
                $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$varId, $qty, $oldStock, $newStock, 'restok', $bulkNote, $userId]);
                $updated++;
            }
        }
        
        // Sinkronisasi stok SKU induk setelah bulk update
        syncProductStock($productId);
        
        flashMessage('success', "Stok massal berhasil diperbarui untuk $updated varian.");
    }
    
    if ($action === 'delete_variation') {
        $id = (int)$_POST['var_id'];
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM sale_details WHERE product_variation_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                flashMessage('error', 'Varian tidak bisa dihapus karena sudah ada transaksi penjualan.');
            } else {
                $stmt = $db->prepare("SELECT image, image2, image3 FROM product_variations WHERE id = ?");
                $stmt->execute([$id]);
                $imgs = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $db->prepare("DELETE FROM product_variations WHERE id = ? AND product_id = ?")->execute([$id, $productId]);
                
                if (!empty($imgs['image'])) deleteUploadedFile($imgs['image']);
                if (!empty($imgs['image2'])) deleteUploadedFile($imgs['image2']);
                if (!empty($imgs['image3'])) deleteUploadedFile($imgs['image3']);
                flashMessage('success', 'Varian berhasil dihapus.');
            }
        } catch (PDOException $e) {
            flashMessage('error', 'Gagal menghapus varian: ' . $e->getMessage());
        }
    }
    
    if ($action === 'delete_var_image') {
        $id = (int)$_POST['var_id'];
        $imgSlot = (int)($_POST['img_slot'] ?? 1);
        
        $stmt = $db->prepare("SELECT image, image2, image3 FROM product_variations WHERE id = ? AND product_id = ?");
        $stmt->execute([$id, $productId]);
        $imgs = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($imgSlot === 1 && !empty($imgs['image'])) {
            deleteUploadedFile($imgs['image']);
            $db->prepare("UPDATE product_variations SET image = NULL WHERE id = ?")->execute([$id]);
        } elseif ($imgSlot === 2 && !empty($imgs['image2'])) {
            deleteUploadedFile($imgs['image2']);
            $db->prepare("UPDATE product_variations SET image2 = NULL WHERE id = ?")->execute([$id]);
        } elseif ($imgSlot === 3 && !empty($imgs['image3'])) {
            deleteUploadedFile($imgs['image3']);
            $db->prepare("UPDATE product_variations SET image3 = NULL WHERE id = ?")->execute([$id]);
        }
        flashMessage('success', 'Foto varian berhasil dihapus.');
    }
    
    // Harga produk kini dikelola di halaman Harga Produk (/admin/prices.php)
    // Action add_tier dan delete_tier sudah dipindahkan ke prices.php
    
    if ($action === 'distribute_stock') {
        // Bongkar stok dari satu varian Seri ke varian-varian spesifik
        $sourceVarId = (int)$_POST['source_var_id'];
        $distribusi  = $_POST['distribusi'] ?? []; // [target_var_id => qty]
        $note        = sanitize($_POST['dist_note'] ?? 'Distribusi stok bongkar seri');
        $userId      = getCurrentUser()['id'] ?? null;
        
        // Validate source belongs to this product
        $stmt = $db->prepare("SELECT stock_qty, variation_name, sku FROM product_variations WHERE id = ? AND product_id = ?");
        $stmt->execute([$sourceVarId, $productId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$source) {
            flashMessage('error', 'Varian sumber tidak ditemukan.');
        } else {
            $sourceStock    = (int)$source['stock_qty'];
            $sourceName     = $source['variation_name'] ?: $source['sku'];
            $totalDistribusi = 0;
            $validItems     = [];
            
            foreach ($distribusi as $targetId => $qty) {
                $targetId = (int)$targetId;
                $qty      = (int)$qty;
                if ($qty <= 0 || $targetId === $sourceVarId) continue;
                // Verify target belongs to this product
                $chk = $db->prepare("SELECT id, stock_qty, variation_name, sku FROM product_variations WHERE id = ? AND product_id = ?");
                $chk->execute([$targetId, $productId]);
                $target = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$target) continue;
                $validItems[$targetId] = ['qty' => $qty, 'old_stock' => (int)$target['stock_qty'], 'name' => ($target['variation_name'] ?: $target['sku'])];
                $totalDistribusi += $qty;
            }
            
            if ($totalDistribusi <= 0) {
                flashMessage('error', 'Tidak ada stok yang akan didistribusikan.');
            } elseif ($totalDistribusi > $sourceStock) {
                flashMessage('error', "Jumlah distribusi ($totalDistribusi) melebihi stok varian sumber ($sourceStock).");
            } else {
                try {
                    $db->beginTransaction();
                    
                    // Kurangi stok sumber
                    $newSourceStock = $sourceStock - $totalDistribusi;
                    $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")
                       ->execute([$newSourceStock, $sourceVarId]);
                    $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                       ->execute([$sourceVarId, -$totalDistribusi, $sourceStock, $newSourceStock, 'restok', "Bongkar seri: -$totalDistribusi pcs ke varian spesifik. $note", $userId]);
                    
                    // Tambah stok ke masing-masing target
                    foreach ($validItems as $targetId => $info) {
                        $newTargetStock = $info['old_stock'] + $info['qty'];
                        $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")
                           ->execute([$newTargetStock, $targetId]);
                        $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                           ->execute([$targetId, $info['qty'], $info['old_stock'], $newTargetStock, 'restok', "Distribusi dari varian \"$sourceName\": +{$info['qty']} pcs. $note", $userId]);
                    }
                    
                    // Sinkronisasi stok SKU induk
                    syncProductStock($productId);
                    
                    $db->commit();
                    flashMessage('success', "Berhasil mendistribusikan $totalDistribusi pcs dari varian \"$sourceName\" ke " . count($validItems) . " varian.");
                } catch (Exception $e) {
                    $db->rollBack();
                    flashMessage('error', 'Gagal mendistribusikan stok: ' . $e->getMessage());
                }
            }
        }
    }
    
    redirect(BASE_URL . '/admin/variations.php?product_id=' . $productId);
}

// =============================================
// FETCH VARIATIONS
// =============================================
$stmt = $db->prepare("SELECT * FROM product_variations WHERE product_id = ? ORDER BY id");
$stmt->execute([$productId]);
$variations = $stmt->fetchAll();

// Fetch last 5 stock logs per variation
foreach ($variations as &$var) {
    $stmtLog = $db->prepare("SELECT * FROM stock_logs WHERE product_variation_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmtLog->execute([$var['id']]);
    $var['stock_logs'] = $stmtLog->fetchAll();
}
unset($var);

// Total stock
$totalStock = array_sum(array_column($variations, 'stock_qty'));

// Category name
$stmt = $db->prepare("SELECT name FROM categories WHERE id = ?");
$stmt->execute([$product['category_id']]);
$categoryName = $stmt->fetchColumn() ?: '-';

include INCLUDES_PATH . '/header.php';
?>

<style>
/* Variant Grid Container */
.variant-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}
@media (max-width: 768px) {
    .variant-grid {
        grid-template-columns: 1fr;
    }
    .variant-card-header {
        min-width: max-content;
    }
    .toolbar {
        flex-wrap: wrap;
        gap: 12px;
    }
    .toolbar > div {
        margin-left: 0 !important;
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
}

/* Variant Matrix Styles */
.variant-matrix-card {
    background: var(--card-bg);
    border-radius: var(--border-radius);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-xs);
    margin-bottom: 0;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    transition: box-shadow var(--transition-fast);
}
.variant-matrix-card:hover { box-shadow: var(--shadow-md); }

.variant-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: linear-gradient(135deg, #fdf8f6, #faf0ec);
    border-bottom: 1px solid var(--border-color);
}

.variant-photos {
    display: flex;
    gap: 6px;
    align-items: center;
}

.variant-photo-slot {
    position: relative;
    width: 52px;
    height: 52px;
    border-radius: var(--border-radius-sm);
    overflow: hidden;
    background: var(--gray-100);
    border: 2px dashed var(--border-color);
    flex-shrink: 0;
}

.variant-photo-slot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.variant-photo-slot .photo-del {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 18px;
    height: 18px;
    background: rgba(220, 38, 38, 0.85);
    color: #fff;
    border: none;
    border-radius: 50%;
    font-size: 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    padding: 0;
    transition: background var(--transition-fast);
}

.variant-photo-slot .photo-del:hover { background: #dc2626; }

.variant-photo-slot.empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gray-300);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.variant-photo-slot.empty:hover {
    border-color: var(--primary-400);
    background: var(--primary-50);
    color: var(--primary-400);
}

.stock-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--primary-600);
    color: #fff;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.8125rem;
    font-weight: 700;
    white-space: nowrap;
}

.stock-badge .stock-unit {
    font-size: 0.68em;
    opacity: 0.9;
    line-height: 1;
}

.stock-badge.low { background: var(--danger); }
.stock-badge.medium { background: var(--warning); }

@media (max-width: 768px) {
    .stock-badge {
        font-size: 0.75rem;
        padding: 5px 10px;
    }

    .stock-badge .stock-unit {
        font-size: 0.7em;
    }
}

.quick-stock-form {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    background: #fffaf8;
    border-top: 1px solid var(--border-color);
    flex-wrap: wrap;
}

.quick-stock-input {
    width: 90px;
    padding: 6px 10px;
    border: 1.5px solid var(--border-color);
    border-radius: var(--border-radius-sm);
    font-size: 0.875rem;
    font-weight: 600;
    text-align: center;
}

.quick-stock-input:focus {
    border-color: var(--primary-500);
    outline: none;
    box-shadow: 0 0 0 2px rgba(188, 57, 8, 0.1);
}

.log-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.75rem;
}

.log-item:last-child { border-bottom: none; }

.log-change.positive { color: var(--success); font-weight: 700; }
.log-change.negative { color: var(--danger); font-weight: 700; }

.total-stock-banner {
    background: linear-gradient(135deg, var(--primary-700), var(--primary-800));
    color: #fff;
    border-radius: var(--border-radius);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    gap: 16px;
    box-shadow: var(--shadow-md);
}

.variant-name-tag {
    font-size: 0.9375rem;
    font-weight: 700;
    color: var(--gray-800);
}

.variant-sku-tag {
    font-size: 0.75rem;
    color: var(--gray-500);
    font-family: var(--font-mono);
}
</style>

<!-- Product Header Card -->
<div class="card mb-24" style="background: linear-gradient(135deg, var(--primary-600) 0%, var(--primary-800) 100%); color:#fff; border:none;">
    <div class="card-body" style="padding: 20px 24px;">
        <div class="d-flex items-center gap-16 justify-between flex-wrap">
            <div class="d-flex items-center gap-16">
                <?php if ($product['image']): ?>
                    <img src="<?= BASE_URL . '/' . $product['image'] ?>" style="width:72px;height:72px;border-radius:var(--border-radius);object-fit:cover;border:3px solid rgba(255,255,255,0.3);">
                <?php else: ?>
                    <div style="width:72px;height:72px;border-radius:var(--border-radius);background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                    </div>
                <?php endif; ?>
                <div>
                    <div style="font-size:0.75rem;opacity:0.7;margin-bottom:2px;">📦 <?= htmlspecialchars($categoryName) ?></div>
                    <h3 style="color:#fff; font-size:1.25rem; margin-bottom:4px;"><?= htmlspecialchars($product['name']) ?></h3>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; font-size:0.8rem; opacity:0.9;">
                        <span>HPP: <strong><?= formatRupiah($product['base_price']) ?></strong></span>
                        <?php if (!empty($pricesByLabel['Eceran'])): ?>
                        <span>Eceran: <strong><?= formatRupiah($pricesByLabel['Eceran']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (!empty($pricesByLabel['Grosir'])): ?>
                        <span>Grosir: <strong><?= formatRupiah($pricesByLabel['Grosir']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (!empty($pricesByLabel['Reseller'])): ?>
                        <span>Reseller: <strong><?= formatRupiah($pricesByLabel['Reseller']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (empty($productPrices)): ?>
                        <span style="background:rgba(246,170,28,0.2); padding:2px 8px; border-radius:10px; color:#f6aa1c;">⚠ Belum ada harga</span>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/admin/prices.php?search=<?= urlencode($product['name']) ?>" style="color:#fff; text-decoration:underline;">Atur Harga</a>
                    </div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.75rem; opacity:0.7;">Total Stok Semua Varian</div>
                <div style="font-size:2rem; font-weight:800; color:#f6aa1c;"><?= formatNumber($totalStock) ?></div>
                <div style="font-size:0.75rem; opacity:0.7;">pcs · <?= count($variations) ?> varian</div>
            </div>
        </div>
    </div>
</div>

<!-- Action Toolbar -->
<style>
@media (max-width: 768px) {
    .toolbar .btn-text { display: none; }
    .toolbar .btn { padding: 8px !important; }
}
</style>
<div class="toolbar">
    <div class="d-flex items-center gap-8">
        <a href="<?= BASE_URL ?>/admin/products.php" class="btn btn-outline" style="gap:6px;" title="Kembali">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            <span class="btn-text">Kembali</span>
        </a>
        <h3 style="font-size:1rem; margin:0;">Manajemen Varian & Stok</h3>
    </div>
    <div class="d-flex gap-8 items-center" style="margin-left:auto;">
        <a href="<?= BASE_URL ?>/admin/stock_monitor.php?product_id=<?= $productId ?>" class="btn btn-outline" style="gap:6px;" title="Monitor Stok">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <span class="btn-text">Monitor Stok</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/prices.php?search=<?= urlencode($product['name']) ?>" class="btn btn-outline" style="gap:6px;" title="Atur Harga">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            <span class="btn-text">Atur Harga</span>
        </a>
        <button class="btn btn-outline" onclick="openModal('modalBulkStock')" id="btnBulkStock" title="Atur Stok Masuk">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            <span class="btn-text">Atur Stok Masuk</span>
        </button>
        <button class="btn btn-primary" onclick="openModal('modalAddVar')" id="btnTambahVarian" title="Tambah Varian">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span class="btn-text">Tambah Varian</span>
        </button>
    </div>
</div>

<!-- Variant Matrix -->
<?php if (empty($variations)): ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </div>
            <div class="empty-state-title">Belum ada varian</div>
            <div class="empty-state-text">Tambahkan varian produk (warna, motif, ukuran) beserta stok dan foto</div>
            <button class="btn btn-primary" onclick="openModal('modalAddVar')">+ Tambah Varian Pertama</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="variant-grid">
<?php foreach ($variations as $var): ?>
<?php 
$stockLevel = (int)$var['stock_qty'];
$stockClass = $stockLevel <= 5 ? 'low' : ($stockLevel <= 20 ? 'medium' : '');
?>
<div class="variant-matrix-card" id="varCard<?= $var['id'] ?>">
    <!-- Variant Header Row -->
    <div class="variant-card-header">
        <!-- Foto Slot -->
        <div class="variant-photos">
            <?php foreach ([1, 2, 3] as $slot): 
                $imgKey = $slot === 1 ? 'image' : 'image' . $slot;
                $imgSrc = $var[$imgKey] ?? null;
                $isInherited = false;
                if ($slot === 1 && empty($imgSrc) && !empty($product['image'])) {
                    $imgSrc = $product['image'];
                    $isInherited = true;
                }
            ?>
            <div class="variant-photo-slot <?= ($imgSrc && !$isInherited) ? '' : 'empty' ?>" 
                 title="<?= ($imgSrc && !$isInherited) ? 'Klik untuk ganti foto ' . $slot : 'Klik untuk tambah foto ' . $slot ?>"
                 onclick="triggerPhotoUpload(<?= $var['id'] ?>, <?= $slot ?>)" style="cursor:pointer;">
                <?php if ($imgSrc): ?>
                    <img src="<?= BASE_URL . '/' . $imgSrc ?>" alt="Foto <?= $slot ?>">
                    <?php if (!$isInherited): ?>
                    <form method="POST" style="display:contents" onsubmit="return confirm('Hapus foto ' + <?= $slot ?> + '?')">
                        <input type="hidden" name="action" value="delete_var_image">
                        <input type="hidden" name="var_id" value="<?= $var['id'] ?>">
                        <input type="hidden" name="img_slot" value="<?= $slot ?>">
                        <button type="submit" class="photo-del" title="Hapus Foto" onclick="event.stopPropagation();">&times;</button>
                    </form>
                    <?php else: ?>
                    <div style="position:absolute; bottom:2px; right:2px; background:rgba(0,0,0,0.6); color:#fff; font-size:8px; padding:2px 4px; border-radius:4px; pointer-events:none;">Utama</div>
                    <?php endif; ?>
                <?php else: ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Variant Info -->
        <div style="flex:1; min-width:0;">
            <div class="variant-name-tag"><?= htmlspecialchars($var['variation_name'] ?: 'Random') ?></div>
            <div class="variant-sku-tag">SKU: <?= htmlspecialchars($var['sku']) ?></div>
        </div>
        
        <!-- Stock Badge -->
        <div class="stock-badge <?= $stockClass ?>" id="stockDisplay<?= $var['id'] ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            <span id="stockNum<?= $var['id'] ?>"><?= formatNumber($stockLevel) ?></span>
            <span class="stock-unit">pcs</span>
        </div>
        
        <!-- Action Buttons -->
        <div class="d-flex gap-6 items-center">
            <button class="btn btn-sm btn-outline" style="padding: 6px;" title="Riwayat Stok (Log)" onclick="toggleStockLog(<?= $var['id'] ?>)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </button>
            <?php if ($var['stock_qty'] > 0 && count($variations) > 1): ?>
            <button type="button" class="btn btn-sm" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none; padding: 6px;" 
                title="Distribusi Stok (Bongkar Seri)"
                onclick="openDistribusiModal(<?= htmlspecialchars(json_encode([
                    'id' => $var['id'],
                    'sku' => $var['sku'],
                    'variation_name' => $var['variation_name'],
                    'stock_qty' => $var['stock_qty']
                ]), ENT_QUOTES, 'UTF-8') ?>)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            </button>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline" style="padding: 6px;" title="Edit Varian" onclick="editVariation(<?= htmlspecialchars(json_encode([
                'id' => $var['id'],
                'sku' => $var['sku'],
                'variation_name' => $var['variation_name'],
                'stock_qty' => $var['stock_qty']
            ]), ENT_QUOTES, 'UTF-8') ?>)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </button>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete_variation">
                <input type="hidden" name="var_id" value="<?= $var['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline" style="color:var(--danger); padding: 6px;" title="Hapus Varian">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
            </form>
        </div>
    </div>

    
    <!-- Stock Log (hidden by default) -->
    <div id="stockLog<?= $var['id'] ?>" style="display:none; padding: 12px 18px; background: #fffaf8; border-top: 1px solid var(--border-color);">
        <div class="text-sm text-semibold text-muted mb-8">📋 Riwayat Stok (5 Terakhir)</div>
        <?php if (empty($var['stock_logs'])): ?>
            <div class="text-xs text-muted">Belum ada riwayat perubahan stok.</div>
        <?php else: ?>
            <?php foreach ($var['stock_logs'] as $log): ?>
            <div class="log-item">
                <span class="log-change <?= $log['qty_change'] >= 0 ? 'positive' : 'negative' ?>">
                    <?= $log['qty_change'] >= 0 ? '+' : '' ?><?= $log['qty_change'] ?>
                </span>
                <span class="badge badge-gray" style="font-size:0.65rem;"><?= $log['change_type'] ?></span>
                <span class="text-xs" style="flex:1;"><?= htmlspecialchars($log['note'] ?? '-') ?></span>
                <span class="text-xs text-muted"><?= $log['qty_before'] ?> → <?= $log['qty_after'] ?></span>
                <span class="text-xs text-muted"><?= date('d/m H:i', strtotime($log['created_at'])) ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Hidden file inputs for photo upload per slot -->
<form method="POST" enctype="multipart/form-data" id="photoUploadForm" style="display:none;">
    <input type="hidden" name="action" value="edit_variation">
    <input type="hidden" name="var_id" id="photoUploadVarId">
    <input type="hidden" name="sku" id="photoUploadSku">
    <input type="hidden" name="variation_name" id="photoUploadName">
    <input type="number" name="stock_qty" id="photoUploadStock" value="0">
    <input type="file" name="var_image" id="photoSlot1" accept="image/*" onchange="submitPhotoForm(1)">
    <input type="file" name="var_image2" id="photoSlot2" accept="image/*" onchange="submitPhotoForm(2)">
    <input type="file" name="var_image3" id="photoSlot3" accept="image/*" onchange="submitPhotoForm(3)">
</form>

<!-- Modal: Tambah Varian -->
<div class="modal-overlay" id="modalAddVar">
    <div class="modal modal-lg">
        <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-600), var(--primary-700)); color:#fff; border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;">
            <div style="display:flex;align-items:center;gap:10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <h3 class="modal-title" style="color:#fff;">Tambah Varian Baru</h3>
            </div>
            <button class="modal-close" onclick="closeModal('modalAddVar')" style="color:#fff; opacity:0.8;">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_variation">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label class="form-label">Nama / Motif Varian <span class="required">*</span></label>
                        <input type="text" name="variation_name" class="form-control" placeholder="Contoh: Hitam Motif A, Hijau NU, Biru Motif C" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">SKU Varian</label>
                        <input type="text" name="sku" class="form-control" placeholder="Auto-generate jika kosong">
                        <span class="form-hint">Kosongkan untuk auto-generate</span>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Stok Awal</label>
                        <input type="number" name="stock_qty" class="form-control" value="0" min="0">
                    </div>
                </div>
                
                <div style="background: var(--primary-50); border-radius: var(--border-radius-sm); padding: 10px 14px; margin-bottom: 14px; border-left: 3px solid var(--primary-400);">
                    <div class="text-sm text-semibold" style="color:var(--primary-700);">📷 Foto Varian (Maks. 3 Foto)</div>
                    <div class="text-xs text-muted">Format JPEG/PNG, maks. 2MB per foto. Foto pertama akan menjadi foto utama varian.</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">📷 Foto Utama Varian</label>
                        <input type="file" name="var_image" class="form-control" accept="image/jpeg,image/png,image/webp" onchange="previewVarImg(this,'varprev1')">
                        <img id="varprev1" src="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                    <div class="form-group">
                        <label class="form-label">📷 Foto 2 (Opsional)</label>
                        <input type="file" name="var_image2" class="form-control" accept="image/jpeg,image/png,image/webp" onchange="previewVarImg(this,'varprev2')">
                        <img id="varprev2" src="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                    <div class="form-group">
                        <label class="form-label">📷 Foto 3 (Opsional)</label>
                        <input type="file" name="var_image3" class="form-control" accept="image/jpeg,image/png,image/webp" onchange="previewVarImg(this,'varprev3')">
                        <img id="varprev3" src="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalAddVar')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Simpan Varian
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Varian -->
<div class="modal-overlay" id="modalEditVar">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">Edit Stok & Gambar Varian</h3>
            <button class="modal-close" onclick="closeModal('modalEditVar')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_variation">
            <input type="hidden" name="var_id" id="editVarId" value="">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label class="form-label">Nama Varian</label>
                        <input type="text" name="variation_name" id="editVarName" class="form-control" placeholder="Contoh: Merah Marun, XL, dll">
                    </div>
                    <div class="form-group">
                        <label class="form-label">SKU <span class="required">*</span></label>
                        <input type="text" name="sku" id="editVarSku" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Stok Baru</label>
                        <input type="number" name="stock_qty" id="editVarStock" class="form-control" value="0" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan Perubahan Stok</label>
                    <input type="text" name="stock_note" class="form-control" placeholder="Alasan perubahan stok (opsional)">
                </div>
                <div style="border-top: 1px solid var(--border-color); margin: 12px 0 14px; padding-top: 14px;">
                    <div class="text-sm text-semibold text-muted mb-8">📷 Ganti Foto Varian (Kosongkan jika tidak diubah)</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Foto Utama Baru</label>
                        <input type="file" name="var_image" class="form-control" accept="image/*" onchange="previewVarImg(this,'editprev1')">
                        <img id="editprev1" src="" style="display:none;width:60px;height:60px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Foto 2 Baru</label>
                        <input type="file" name="var_image2" class="form-control" accept="image/*" onchange="previewVarImg(this,'editprev2')">
                        <img id="editprev2" src="" style="display:none;width:60px;height:60px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Foto 3 Baru</label>
                        <input type="file" name="var_image3" class="form-control" accept="image/*" onchange="previewVarImg(this,'editprev3')">
                        <img id="editprev3" src="" style="display:none;width:60px;height:60px;object-fit:cover;border-radius:6px;margin-top:6px;" alt="">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalEditVar')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Atur Stok Masuk (Bulk) -->
<div class="modal-overlay" id="modalBulkStock">
    <div class="modal modal-lg">
        <div class="modal-header" style="background: linear-gradient(135deg, #e09210, #c07808); color:#fff; border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;">
            <div style="display:flex;align-items:center;gap:10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                <h3 class="modal-title" style="color:#fff;">Atur Stok Masuk (Bulk)</h3>
            </div>
            <button class="modal-close" onclick="closeModal('modalBulkStock')" style="color:#fff;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="bulk_stock">
            <div class="modal-body">
                <div style="background: var(--gold-100); border-radius: var(--border-radius-sm); padding: 10px 14px; margin-bottom: 16px; border-left: 3px solid var(--gold-500);">
                    <div class="text-sm text-semibold" style="color:var(--gold-700);">💡 Cara Penggunaan</div>
                    <div class="text-xs text-muted">Masukkan jumlah stok yang <strong>ditambahkan</strong> per varian. Gunakan angka negatif untuk mengurangi stok. Kosongkan (atau isi 0) untuk melewati varian tersebut.</div>
                </div>
                
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label">Catatan Stok Masuk</label>
                    <input type="text" name="bulk_note" class="form-control" placeholder="Contoh: Restok dari supplier Pak Ahmad - 100 pcs" value="Restok massal">
                </div>
                
                <!-- Bulk Total Calculator -->
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                    <div class="text-sm text-semibold">Distribusi Stok per Varian:</div>
                    <div class="text-sm" style="background:var(--primary-50); padding:4px 12px; border-radius:20px; color:var(--primary-700);">
                        Total Input: <strong id="bulkTotal">0</strong> pcs
                    </div>
                </div>
                
                <?php foreach ($variations as $bVar): ?>
                <div style="display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom: 1px solid var(--border-color);">
                    <div style="flex:1; min-width:0;">
                        <div class="text-sm text-bold"><?= htmlspecialchars($bVar['variation_name'] ?: $bVar['sku']) ?></div>
                        <div class="text-xs text-muted">Stok saat ini: <strong><?= formatNumber($bVar['stock_qty']) ?></strong> pcs</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="text-xs text-muted">+:</span>
                        <input type="number" name="bulk_stock[<?= $bVar['id'] ?>]" 
                               class="quick-stock-input bulk-qty-input" 
                               value="0" 
                               placeholder="0"
                               style="width:80px;">
                        <span class="text-xs" id="bulkNew<?= $bVar['id'] ?>" style="color:var(--success); font-weight:700; min-width:60px;">
                            = <?= formatNumber($bVar['stock_qty']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalBulkStock')">Batal</button>
                <button type="submit" class="btn btn-gold">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/></svg>
                    Terapkan Stok Masuk
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// =============================================
// DISTRIBUSI STOK (BONGKAR SERI)
// =============================================
const allVariations = <?= json_encode(array_map(fn($v) => [
    'id'             => $v['id'],
    'sku'            => $v['sku'],
    'variation_name' => $v['variation_name'],
    'stock_qty'      => $v['stock_qty'],
], $variations)) ?>;

function openDistribusiModal(sourceVar) {
    try {
        // Set source info
        document.getElementById('distSourceVarId').value = sourceVar.id;
        document.getElementById('distSourceName').textContent = sourceVar.variation_name || sourceVar.sku;
        const sourceStock = parseInt(sourceVar.stock_qty) || 0;
        document.getElementById('distSourceStok').textContent = sourceStock.toLocaleString('id-ID') + ' pcs';
        document.getElementById('distSisaStok').textContent = sourceStock.toLocaleString('id-ID') + ' pcs';
        
        // Build target rows (all variants except source)
        const container = document.getElementById('distTargetRows');
        container.innerHTML = '';
        const targets = allVariations.filter(v => String(v.id) !== String(sourceVar.id));
        
        if (targets.length === 0) {
            container.innerHTML = '<p class="text-muted text-sm">Tidak ada varian lain untuk didistribusikan.</p>';
        } else {
            targets.forEach(v => {
                const currentStock = parseInt(v.stock_qty) || 0;
                const row = document.createElement('div');
                row.className = 'dist-target-card';
                row.style.cssText = 'padding:12px; border:1px solid var(--border-color); border-radius:8px; background:#f9fafb; display:flex; flex-direction:column; justify-content:space-between;';
                row.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                        <div style="padding-right:8px;">
                            <div style="font-weight:600; font-size:0.9rem; line-height:1.2;">${escHtmlDist(v.variation_name || v.sku)}</div>
                            <div style="font-size:0.75rem; color:var(--muted); margin-top:4px;">SKU: ${escHtmlDist(v.sku)}</div>
                        </div>
                        <div style="font-size:0.8rem; text-align:right; white-space:nowrap;">
                            Stok: <strong>${currentStock.toLocaleString('id-ID')}</strong><br>
                            <span class="dist-new-stock" style="color:var(--success); font-weight:600;">= ${currentStock.toLocaleString('id-ID')}</span>
                        </div>
                    </div>
                    <div>
                        <input type="number" class="form-control dist-qty" min="0" value="0"
                            name="distribusi[${v.id}]"
                            data-max="${sourceStock}"
                            data-current="${currentStock}"
                            oninput="onDistQtyChange(this, ${sourceStock})"
                            placeholder="Qty Distribusi"
                            style="text-align:center; font-size:1.1rem; font-weight:700; width:100%;">
                    </div>
                `;
                container.appendChild(row);
            });
        }
        
        document.getElementById('distTotalDist').textContent = '0 pcs';
        openModal('modalDistribusi');
    } catch (e) {
        alert('Terjadi kesalahan pada fitur ini: ' + e.message);
        console.error(e);
    }
}

function escHtmlDist(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function onDistQtyChange(input, maxStock) {
    let total = 0;
    document.querySelectorAll('.dist-qty').forEach(inp => {
        total += parseInt(inp.value) || 0;
    });
    
    const sisa = maxStock - total;
    document.getElementById('distSisaStok').textContent = Math.max(0, sisa).toLocaleString('id-ID') + ' pcs';
    document.getElementById('distTotalDist').textContent = total.toLocaleString('id-ID') + ' pcs';
    
    // Color warning if over
    const sisaEl = document.getElementById('distSisaStok');
    sisaEl.style.color = sisa < 0 ? 'var(--danger)' : 'var(--success)';
    
    // Update new stock preview
    const current = parseInt(input.dataset.current) || 0;
    const qty = parseInt(input.value) || 0;
    const newStock = current + qty;
    const newStockEl = input.closest('.dist-target-card').querySelector('.dist-new-stock');
    if (newStockEl) {
        newStockEl.textContent = '= ' + newStock.toLocaleString('id-ID') + ' pcs';
        newStockEl.style.color = newStock < 0 ? 'var(--danger)' : 'var(--success)';
    }
}

// Photo slot data for quick upload
const variantData = <?= json_encode(array_map(fn($v) => [
    'id' => $v['id'],
    'sku' => $v['sku'],
    'stock_qty' => $v['stock_qty'],
    'variation_name' => $v['variation_name']
], $variations)) ?>;

function triggerPhotoUpload(varId, slot) {
    // Fill hidden form fields
    const vd = variantData.find(v => v.id == varId);
    if (!vd) return;
    document.getElementById('photoUploadVarId').value = varId;
    document.getElementById('photoUploadSku').value = vd.sku;
    document.getElementById('photoUploadName').value = vd.variation_name || '';
    document.getElementById('photoUploadStock').value = vd.stock_qty;
    
    // Trigger file input for correct slot
    document.getElementById('photoSlot' + slot).click();
}

function submitPhotoForm(slot) {
    // Auto-submit when file selected
    const form = document.getElementById('photoUploadForm');
    // Disable non-active slots to avoid sending empty files
    ['photoSlot1','photoSlot2','photoSlot3'].forEach((id, idx) => {
        document.getElementById(id).disabled = (idx + 1) !== slot;
    });
    form.submit();
}

function editVariation(varData) {
    document.getElementById('editVarId').value = varData.id;
    document.getElementById('editVarSku').value = varData.sku;
    document.getElementById('editVarName').value = varData.variation_name || '';
    document.getElementById('editVarStock').value = varData.stock_qty;
    ['editprev1','editprev2','editprev3'].forEach(id => {
        const el = document.getElementById(id);
        el.src = ''; el.style.display = 'none';
    });
    openModal('modalEditVar');
}

function toggleStockLog(varId) {
    const el = document.getElementById('stockLog' + varId);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function previewVarImg(input, previewId) {
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

// Bulk stock total calculator
const bulkInputs = document.querySelectorAll('.bulk-qty-input');
const currentStocks = <?= json_encode(array_combine(
    array_column($variations, 'id'),
    array_column($variations, 'stock_qty')
)) ?>;

bulkInputs.forEach(input => {
    input.addEventListener('input', () => {
        // Update total
        let total = 0;
        bulkInputs.forEach(i => total += parseInt(i.value) || 0);
        document.getElementById('bulkTotal').textContent = total.toLocaleString('id-ID');
        
        // Update new stock preview per variant
        const nameAttr = input.getAttribute('name');
        const varId = nameAttr.match(/\[(\d+)\]/)?.[1];
        if (varId && currentStocks[varId] !== undefined) {
            const newStock = currentStocks[varId] + (parseInt(input.value) || 0);
            const el = document.getElementById('bulkNew' + varId);
            if (el) {
                el.textContent = '= ' + Math.max(0, newStock).toLocaleString('id-ID');
                el.style.color = newStock < 0 ? 'var(--danger)' : 'var(--success)';
            }
        }
    });
});

initRupiahInput('.rupiah-input');
</script>

<!-- Modal: Distribusi Stok / Bongkar Seri -->
<div class="modal-overlay" id="modalDistribusi">
    <div class="modal modal-lg">
        <div class="modal-header" style="padding: 12px 16px; background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-radius:var(--border-radius-lg) var(--border-radius-lg) 0 0;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                <div>
                    <h3 class="modal-title" style="color:#fff; margin:0; font-size: 1rem;">Distribusi Stok / Bongkar Seri</h3>
                    <div style="font-size:0.7rem; opacity:0.85; margin-top:2px;">Pecah stok dari varian Seri ke varian spesifik</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal('modalDistribusi')" style="color:#fff;opacity:0.8;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="distribute_stock">
            <input type="hidden" name="source_var_id" id="distSourceVarId">
            <div class="modal-body">
                <!-- Source Info -->
                <div style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <div style="font-size:0.75rem;color:#92400e;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Varian Sumber (Stok Asal)</div>
                            <div style="font-size:1.15rem;font-weight:700;color:#78350f;margin-top:4px;" id="distSourceName"></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:0.75rem;color:#92400e;">Stok Tersedia</div>
                            <div style="font-size:1.4rem;font-weight:800;color:#d97706;" id="distSourceStok"></div>
                        </div>
                    </div>
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #fcd34d;display:flex;justify-content:space-between;">
                        <div style="font-size:0.85rem;color:#92400e;">Total Didistribusikan: <strong id="distTotalDist" style="color:#d97706;">0 pcs</strong></div>
                        <div style="font-size:0.85rem;color:#92400e;">Sisa Stok Sumber: <strong id="distSisaStok" style="color:var(--success);">0 pcs</strong></div>
                    </div>
                </div>
                
                <!-- Target Variants -->
                <div style="font-weight:600;font-size:0.9rem;margin-bottom:12px;">📦 Distribusikan ke Varian:</div>
                <div style="max-height: 40vh; overflow-y:auto; padding-right:8px;">
                    <div id="distTargetRows" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;"></div>
                </div>
                
                <div class="form-group" style="margin-top:16px;">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="dist_note" class="form-control" placeholder="Contoh: Bongkar barang dari supplier Februari 2026" value="Bongkar seri">
                </div>
            </div>
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 12px;">
                <div style="font-size:0.82rem;color:var(--muted);">⚠️ Pastikan jumlah distribusi tidak melebihi stok sumber</div>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalDistribusi')">Batal</button>
                    <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#f59e0b,#d97706);border-color:#d97706;"
                        onclick="
                            const total = Array.from(document.querySelectorAll('.dist-qty')).reduce((s,i)=>s+(parseInt(i.value)||0),0);
                            const max = parseInt(document.getElementById('distSourceStok').textContent.replace(/[^0-9]/g,''));
                            if(total <= 0){showToast('Masukkan jumlah distribusi minimal 1 pcs.', 'warning');return false;}
                            if(total > max){showToast('Jumlah distribusi melebihi stok yang tersedia!', 'error');return false;}
                        ">Simpan Distribusi</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
