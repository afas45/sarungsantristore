<?php
/**
 * Sarung Santri Store - Manajemen Harga Produk
 * Tampilan tabel spreadsheet: SKU | Produk | HPP | Eceran per saluran penjualan
 * Kolom: Harga Umum, Harga Reseller, Harga Shopee, Harga TikTok (semua eceran / min_qty=1)
 * Harga Grosir dan lainnya tersembunyi, dapat diakses via "Atur Harga"
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Harga Produk';
$breadcrumbs = [['label' => 'Harga Produk']];

// Fetch price types from DB to build columns dynamically
$priceTypesRaw = $db->query("SELECT id, name FROM price_types ORDER BY CASE WHEN name = 'Reseller' THEN 0 WHEN name = 'Umum' THEN 1 ELSE 2 END, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$priceColsConfig = [];
$colColors = [
    ['color' => '#1d4ed8', 'bg' => '#eff6ff'], // blue
    ['color' => '#7e22ce', 'bg' => '#fdf4ff'], // purple
    ['color' => '#c2410c', 'bg' => '#fff7ed'], // orange
    ['color' => '#0f172a', 'bg' => '#f8fafc'], // slate
    ['color' => '#15803d', 'bg' => '#f0fdf4'], // green
    ['color' => '#b91c1c', 'bg' => '#fef2f2'], // red
];
foreach ($priceTypesRaw as $i => $pt) {
    $c = $colColors[$i % count($colColors)];
    $label = ($pt['name'] === 'Umum') ? 'Harga Umum' : 'Harga ' . $pt['name'];
    $priceColsConfig[$pt['name']] = ['label' => $label, 'color' => $c['color'], 'bg' => $c['bg']];
}
define('PRICE_COLS', $priceColsConfig);

// AUTO-CREATE product_prices TABLE
try { $db->exec("ALTER TABLE products ADD COLUMN discount_percent INT NOT NULL DEFAULT 0 AFTER base_price"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE products ADD COLUMN last_base_price DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER base_price"); } catch(Exception $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS product_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        label VARCHAR(50) NOT NULL,
        price_type_id INT NOT NULL DEFAULT 1,
        min_qty INT NOT NULL DEFAULT 1,
        selling_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_product_label_type (product_id, label, price_type_id),
        INDEX idx_product_id (product_id)
    )");
} catch (PDOException $e) { /* already exists */ }



// HANDLE POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Quick inline save single price cell
    if ($action === 'save_single') {
        $productId = (int)$_POST['product_id'];
        $ptName    = sanitize($_POST['label'] ?? '');
        $price     = (float)str_replace(['.', ','], ['', '.'], $_POST['selling_price'] ?? '0');
        $minQty    = (int)($_POST['min_qty'] ?? 1);
        if ($minQty < 1) $minQty = 1;

        if ($productId && $ptName && $price > 0) {
            $stmtPT = $db->prepare("SELECT id FROM price_types WHERE name = ?");
            $stmtPT->execute([$ptName]);
            $ptId = $stmtPT->fetchColumn();
            if (!$ptId) $ptId = 1;
            
            $label = ($minQty == 1) ? 'Ecer' : $ptName;
            $db->prepare("INSERT INTO product_prices (product_id, label, price_type_id, min_qty, selling_price)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE selling_price=VALUES(selling_price)")
               ->execute([$productId, $label, $ptId, $minQty, $price]);
            flashMessage('success', "Harga {$ptName} berhasil disimpan.");
        } elseif ($productId && $ptName && $price == 0) {
            $stmtPT = $db->prepare("SELECT id FROM price_types WHERE name = ?");
            $stmtPT->execute([$ptName]);
            $ptId = $stmtPT->fetchColumn();
            if ($ptId) {
                $db->prepare("DELETE FROM product_prices WHERE product_id=? AND price_type_id=? AND min_qty=1")->execute([$productId, $ptId]);
            }
            flashMessage('success', "Harga {$ptName} dihapus (harga 0).");
        }
        redirect(BASE_URL . '/admin/prices.php?' . http_build_query(array_filter(['search' => $_POST['search_val'] ?? null, 'category' => $_POST['cat_val'] ?? null, 'page' => $_POST['page_val'] ?? null])));
    }

    // Full modal save
    if ($action === 'save_price') {
        $productId = (int)$_POST['product_id'];
        $labels    = $_POST['label'] ?? [];
        $prices    = $_POST['selling_price'] ?? [];
        $minQtys   = $_POST['min_qty'] ?? [];
        $ptIds     = $_POST['price_type_id'] ?? [];

        $stmt = $db->prepare("SELECT id FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        if (!$stmt->fetchColumn()) { flashMessage('error', 'Produk tidak ditemukan.'); redirect(BASE_URL . '/admin/prices.php'); }

        // Duplicate Check Validation
        $combos = [];
        foreach ($labels as $i => $label) {
            $minQty = max(1, (int)($minQtys[$i] ?? 1));
            $ptId   = (int)($ptIds[$i] ?? 1);
            $price  = (float)str_replace(['.', ','], ['', '.'], $prices[$i] ?? '0');
            
            if (empty(sanitize($label)) && $minQty != 1 || $price <= 0) continue;
            
            $comboKey = $ptId . '_' . $minQty;
            if (isset($combos[$comboKey])) {
                flashMessage('error', 'Gagal: Terdapat tipe pelanggan dengan Min. Qty yang sama!');
                redirect(BASE_URL . '/admin/prices.php');
                exit;
            }
            $combos[$comboKey] = true;
        }

        $db->prepare("DELETE FROM product_prices WHERE product_id = ?")->execute([$productId]);
        $inserted = 0;
        foreach ($labels as $i => $label) {
            $minQty = max(1, (int)($minQtys[$i] ?? 1));
            $label  = sanitize($label);
            if ($minQty == 1) $label = 'Ecer';
            
            $price  = (float)str_replace(['.', ','], ['', '.'], $prices[$i] ?? '0');
            $ptId   = (int)($ptIds[$i] ?? 1);
            if (empty($label) || $price <= 0) continue;
            $stmtPt = $db->prepare("SELECT id FROM price_types WHERE id = ?");
            $stmtPt->execute([$ptId]);
            if (!$stmtPt->fetchColumn()) $ptId = 1;
            $db->prepare("INSERT INTO product_prices (product_id, label, price_type_id, min_qty, selling_price) VALUES (?, ?, ?, ?, ?)")
               ->execute([$productId, $label, $ptId, $minQty, $price]);
            $inserted++;
        }
        flashMessage('success', "Harga produk berhasil disimpan ($inserted harga).");
        logActivity('Edit', 'Harga Produk', 'Product ID: ' . $productId);
        redirect(BASE_URL . '/admin/prices.php');
    }

    // Action save_discount
    if ($action === 'save_discount') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $discount = max(0, min(100, (int)($_POST['discount_percent'] ?? 0)));
        
        $db->prepare("UPDATE products SET discount_percent = ? WHERE id = ?")
           ->execute([$discount, $productId]);
           
        flashMessage('success', 'Diskon berhasil diperbarui.');
        
        $query = http_build_query(array_filter([
            'search' => $_POST['search_val'] ?? '',
            'category' => $_POST['cat_val'] ?? 0,
            'page' => $_POST['page_val'] ?? 1
        ]));
        redirect(BASE_URL . '/admin/prices.php' . ($query ? '?' . $query : ''));
    }



    redirect(BASE_URL . '/admin/prices.php');
}

// FETCH DATA
$search         = sanitize($_GET['search'] ?? '');
$filterCategory = (int)($_GET['category'] ?? 0);
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = ITEMS_PER_PAGE;

$where  = "WHERE 1=1";
$params = [];
if ($search) { $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filterCategory) { $where .= " AND p.category_id = ?"; $params[] = $filterCategory; }

$totalItems = $db->prepare("SELECT COUNT(DISTINCT p.id) FROM products p LEFT JOIN categories c ON c.id = p.category_id $where");
$totalItems->execute($params);
$totalItems = $totalItems->fetchColumn();
$pagination = getPagination($totalItems, $page, $perPage);
$offset     = $pagination['offset'];

// Main pivot query - fetch all main price labels in one shot
$selectCols = [];
$priceKeys = [];
foreach (PRICE_COLS as $ptName => $c) {
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $ptName);
    $key = 'p_' . strtolower($clean);
    $priceKeys[$ptName] = $key;
    $selectCols[] = "MAX(CASE WHEN pt.name=".$db->quote($ptName)." AND pp.min_qty=1 THEN pp.selling_price END) as $key";
}
$selectSql = implode(", ", $selectCols);
if ($selectSql) $selectSql = ",\n           " . $selectSql;

$stmt = $db->prepare("
    SELECT p.id, p.sku, p.name, p.base_price, p.last_base_price, p.image, p.is_active, p.discount_percent,
           c.name as category_name,
           COUNT(DISTINCT pv.id) as var_count,
           COALESCE(SUM(pv.stock_qty), 0) as total_stock
           $selectSql
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN product_variations pv ON pv.product_id = p.id
    LEFT JOIN product_prices pp ON pp.product_id = p.id
    LEFT JOIN price_types pt ON pp.price_type_id = pt.id
    $where
    GROUP BY p.id
    ORDER BY c.name ASC, p.name ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$products = $stmt->fetchAll();

// Also fetch all prices per product for the modal
$productIds = array_column($products, 'id');
$allPricesMap = [];
if (!empty($productIds)) {
    $inQ = implode(',', array_fill(0, count($productIds), '?'));
    $allPStmt = $db->prepare("
        SELECT pp.*, pt.name as price_type FROM product_prices pp
        JOIN price_types pt ON pp.price_type_id = pt.id
        WHERE pp.product_id IN ($inQ) ORDER BY pp.min_qty ASC
    ");
    $allPStmt->execute($productIds);
    foreach ($allPStmt->fetchAll() as $pr) {
        $allPricesMap[$pr['product_id']][] = $pr;
    }
}

$categories = $db->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$priceTypes = $db->query("SELECT id, name FROM price_types ORDER BY name ASC")->fetchAll();

// Stats
$stats = $db->query("SELECT COUNT(DISTINCT product_id) as pwp, COUNT(*) as total FROM product_prices")->fetch();
$noPrice = $db->query("SELECT COUNT(*) FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_prices pp WHERE pp.product_id = p.id)")->fetchColumn();

include INCLUDES_PATH . '/header.php';
?>

<style>
/* ===================== PRICES PAGE STYLES ===================== */
/* Filter bar */
.price-filter-bar {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: 12px 16px;
    margin-bottom: 14px;
    box-shadow: var(--shadow-xs);
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}

/* Main table */
.price-table-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.price-table-scroll { overflow-x: auto; }
.price-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
    min-width: 900px;
}

@media (max-width: 768px) {
    .toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .toolbar form {
        width: 100%;
        flex-wrap: wrap;
    }

    .search-box {
        max-width: 100% !important;
        width: 100% !important;
        margin-right: 0 !important;
    }

    .filter-group {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .filter-group .btn {
        flex: 1 1 140px;
        justify-content: center;
    }

    .price-table-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .price-table {
        min-width: 760px;
    }

    .price-inline-form form {
        flex-wrap: wrap;
    }

    .price-inline-input {
        width: 80px;
    }
}

@media (max-width: 480px) {
    .page-content {
        padding: 12px 10px;
    }

    .price-table {
        min-width: 640px;
    }

    .prod-name {
        font-size: 0.78rem;
    }

    .price-val {
        font-size: 0.72rem;
    }

    .toolbar .btn,
    .filter-group .btn {
        min-height: 44px;
    }
}
.price-table th {
    background: var(--gray-50);
    padding: 8px 12px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-700);
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
    width: 1%;
    position: sticky;
    top: 0;
    z-index: 2;
}
.price-table th.col-price { text-align: center; }
.price-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
    white-space: nowrap;
    width: 1%;
}
.price-table tbody tr { transition: background var(--transition-fast); }
.price-table tbody tr:hover { background: #fdf9f7; }
.price-table tbody tr:last-child td { border-bottom: none; }

/* Product cell */
.prod-cell { display: flex; align-items: center; gap: 10px; }
.prod-thumb {
    width: 38px; height: 38px; border-radius: 8px;
    object-fit: cover; border: 1px solid var(--border-color); flex-shrink: 0;
}
.prod-thumb-ph {
    width: 38px; height: 38px; border-radius: 8px;
    background: var(--primary-50); display: flex; align-items: center;
    justify-content: center; flex-shrink: 0; color: var(--primary-300);
}
.prod-name { font-weight: 700; color: var(--gray-800); font-size: 0.85rem; }
.prod-meta { font-size: 0.7rem; color: var(--gray-400); margin-top: 1px; }
.sku-code { font-weight: 700; font-size: 0.85rem; color: var(--gray-800); }

/* Price cell */
.price-cell-inner {
    display: flex; flex-direction: column; align-items: center; gap: 2px;
}
.price-val {
    font-weight: 700; font-size: 0.8rem; color: var(--gray-800);
    cursor: pointer; padding: 4px 8px; border-radius: 7px;
    transition: background var(--transition-fast);
    white-space: nowrap;
}
.price-val:hover { background: var(--primary-50); }
.price-val.empty { color: var(--gray-300); font-weight: 400; font-size: 0.72rem; }
.margin-badge {
    font-size: 0.62rem; font-weight: 700; padding: 1px 5px;
    border-radius: 4px; white-space: nowrap;
}
.margin-pos { background: #f0fdf4; color: #15803d; }
.margin-neg { background: #fef2f2; color: #dc2626; }
.margin-zero { background: var(--gray-100); color: var(--gray-500); }

/* Inline edit */
.price-inline-form { display: none; gap: 4px; align-items: center; }
.price-inline-input {
    width: 100px; padding: 4px 8px; border: 1.5px solid var(--primary-400);
    border-radius: 6px; font-size: 0.78rem; font-weight: 600; text-align: right;
    outline: none; font-family: var(--font-mono);
}
.price-inline-input:focus { border-color: var(--primary-600); box-shadow: 0 0 0 2px rgba(188,57,8,.15); }
.price-cell-wrapper { position: relative; text-align: center; }
.price-cell-wrapper.editing .price-view { display: none; }
.price-cell-wrapper.editing .price-inline-form { display: flex; }

/* Action col */
.price-actions { display: flex; gap: 4px; align-items: center; }

/* No data row */
.no-price-dot { width: 6px; height: 6px; border-radius: 50%; background: #f0fdf4; display: inline-block; }
</style>

<!-- ========== FILTER + TOOLBAR ========== -->
<div class="toolbar">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex:1;">
        <div class="search-box" style="flex:1; max-width:400px; margin-right:8px;">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="search" class="form-control" placeholder="Cari produk atau SKU..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
        </div>
        <select name="category" class="form-control" style="width:180px;" onchange="this.form.submit()">
            <option value="">Semua Kategori</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-outline">Cari</button></noscript>
    </form>
    <div class="filter-group">
        <?php if ($search || $filterCategory): ?>
        <a href="<?= BASE_URL ?>/admin/prices.php" class="btn btn-ghost">✕ Reset</a>
        <?php endif; ?>
    </div>
</div>

<div class="text-sm text-muted mb-10" style="display:flex; align-items:center; gap:8px;">
    <span style="font-size:0.72rem; color:var(--gray-400);">💡 Klik harga untuk edit cepat. Klik <strong>Atur Harga</strong> untuk kelola semua tipe harga.</span>
</div>

<!-- ========== MAIN TABLE ========== -->
<?php if (empty($products)): ?>
<div class="price-table-card" style="padding:60px 20px; text-align:center; color:var(--gray-400);">
    <div style="font-size:3rem; margin-bottom:12px;">💰</div>
    <div style="font-weight:600; margin-bottom:6px;">Tidak ada produk ditemukan</div>
    <div style="font-size:0.85rem;">Coba ubah filter pencarian.</div>
</div>
<?php else: ?>
<div class="price-table-card">
    <div class="price-table-scroll">
        <table class="price-table" id="priceMainTable">
            <thead>
                <tr>
                    <th style="width:1%; text-align:center;">No</th>
                    <th style="width:1%; white-space:nowrap;">SKU</th>
                    <th style="width:1%; white-space:nowrap; min-width:180px;">Produk</th>
                    <th style="width:1%; white-space:nowrap; text-align:right;">HPP / Modal</th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Diskon (%)</th>
                    <?php foreach (PRICE_COLS as $colKey => $col): ?>
                    <th class="col-price" style="width:1%; white-space:nowrap;">
                        <div style="display:flex; flex-direction:column; align-items:center; gap:4px; color:<?= $col['color'] ?>;">
                            <span style="color:var(--gray-700);"><?= $col['label'] ?></span>
                        </div>
                    </th>
                    <?php endforeach; ?>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $i => $prod): 
                    $rowNum  = $offset + $i + 1;
                    $allPrices = $allPricesMap[$prod['id']] ?? [];
                ?>
                <tr id="row_<?= $prod['id'] ?>">
                    <td style="text-align:center; color:var(--gray-400); font-size:0.72rem;"><?= $rowNum ?></td>
                    <td>
                        <span class="text-bold"><?= htmlspecialchars($prod['sku'] ?: '—') ?></span>
                    </td>
                    <td>
                        <div class="prod-cell">
                            <?php if ($prod['image']): ?>
                                <img src="<?= BASE_URL . '/' . $prod['image'] ?>" class="prod-thumb" alt="">
                            <?php else: ?>
                                <div class="prod-thumb-ph">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="prod-name"><?= htmlspecialchars($prod['name']) ?></div>
                                <div class="prod-meta">
                                    <?= htmlspecialchars($prod['category_name'] ?: '—') ?>
                                    · <?= $prod['var_count'] ?> varian
                                    <?php if (!$prod['is_active']): ?><span style="color:var(--danger);"> · Nonaktif</span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td style="text-align:right;">
                        <div style="font-weight:700; font-size:0.82rem; color:var(--primary-700); display:flex; justify-content:flex-end; align-items:center;">
                            <?= formatRupiah($prod['base_price']) ?>
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
                    
                    <td style="text-align:center;">
                        <div class="price-cell-wrapper" id="cell_disc_<?= $prod['id'] ?>">
                            <?php
                                $hargaEceran = $prod['p_umum'] ?? $prod['p_eceran'] ?? $prod['base_price'];
                                $estDiskon = 0;
                                if (!empty($prod['discount_percent']) && $prod['discount_percent'] > 0 && $hargaEceran > 0) {
                                    $estDiskon = $hargaEceran - ($hargaEceran * $prod['discount_percent'] / 100);
                                }
                            ?>
                            <div class="price-view price-cell-inner" style="flex-direction: column; gap: 4px;">
                                <span class="price-val" onclick="startEditDisc('cell_disc_<?= $prod['id'] ?>', <?= $prod['id'] ?>, <?= $prod['discount_percent'] ?? 0 ?>)" title="Klik untuk edit diskon">
                                    <?= !empty($prod['discount_percent']) && $prod['discount_percent'] > 0 ? $prod['discount_percent'] . '%' : '<span class="empty">—</span>' ?>
                                </span>
                                <?php if ($estDiskon > 0): ?>
                                    <span class="margin-pct" style="color:var(--gray-600); background:var(--gray-100); font-weight:600;">~ <?= formatRupiah($estDiskon) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="price-inline-form">
                                <form method="POST" style="display:flex; gap:4px; align-items:center;">
                                    <input type="hidden" name="action" value="save_discount">
                                    <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                    <input type="hidden" name="search_val" value="<?= htmlspecialchars($search) ?>">
                                    <input type="hidden" name="cat_val" value="<?= $filterCategory ?>">
                                    <input type="hidden" name="page_val" value="<?= $page ?>">
                                    <input type="number" name="discount_percent" class="price-inline-input"
                                           id="inp_cell_disc_<?= $prod['id'] ?>" placeholder="0"
                                           value="<?= $prod['discount_percent'] ?? 0 ?>" min="0" max="100"
                                           onkeydown="if(event.key==='Escape') cancelEdit('cell_disc_<?= $prod['id'] ?>')"
                                           style="width:60px; text-align:center; padding: 4px;">
                                    <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 8px;" title="Simpan">✓</button>
                                    <button type="button" class="btn btn-sm btn-ghost" style="padding:4px 6px;" onclick="cancelEdit('cell_disc_<?= $prod['id'] ?>')" title="Batal">✕</button>
                                </form>
                            </div>
                        </div>
                    </td>

                    <?php foreach (PRICE_COLS as $colKey => $col):
                        $pkeyField = $priceKeys[$colKey];
                        $cellPrice = $prod[$pkeyField] ?? null;
                        $cellMargin = null;
                        $cellMarginPct = null;
                        if ($cellPrice && $prod['base_price'] > 0) {
                            $cellMargin    = $cellPrice - $prod['base_price'];
                            $cellMarginPct = round($cellMargin / $prod['base_price'] * 100, 1);
                        }
                        $cellId = "cell_{$prod['id']}_{$colKey}";
                    ?>
                    <td>
                        <div class="price-cell-wrapper" id="<?= $cellId ?>">
                            <!-- Static view -->
                            <div class="price-view price-cell-inner">
                                <?php if ($cellPrice): ?>
                                <span class="price-val" onclick="startEdit('<?= $cellId ?>', <?= $prod['id'] ?>, '<?= addslashes($colKey) ?>', <?= $cellPrice ?>, <?= $prod['base_price'] ?>)"
                                      title="Klik untuk edit">
                                    <?= formatRupiah($cellPrice) ?>
                                </span>
                                <?php if ($cellMarginPct !== null): ?>
                                    <span class="margin-badge <?= $cellMarginPct > 0 ? 'margin-pos' : ($cellMarginPct < 0 ? 'margin-neg' : 'margin-zero') ?>">
                                        <?= $cellMarginPct >= 0 ? '+' : '' ?><?= $cellMarginPct ?>%
                                    </span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="price-val empty" onclick="startEdit('<?= $cellId ?>', <?= $prod['id'] ?>, '<?= addslashes($colKey) ?>', 0, <?= $prod['base_price'] ?>)"
                                      title="Klik untuk isi harga">— Belum diatur</span>
                                <?php endif; ?>
                            </div>
                            <!-- Inline edit form -->
                            <div class="price-inline-form">
                                <form method="POST" style="display:flex; gap:4px; align-items:center;">
                                    <input type="hidden" name="action" value="save_single">
                                    <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                    <input type="hidden" name="label" value="<?= htmlspecialchars($colKey) ?>">
                                    <input type="hidden" name="min_qty" value="1">
                                    <input type="hidden" name="search_val" value="<?= htmlspecialchars($search) ?>">
                                    <input type="hidden" name="cat_val" value="<?= $filterCategory ?>">
                                    <input type="hidden" name="page_val" value="<?= $page ?>">
                                    <input type="text" name="selling_price" class="price-inline-input rupiah-input"
                                           id="inp_<?= $cellId ?>" placeholder="0"
                                           value="<?= $cellPrice ? number_format($cellPrice, 0, ',', '.') : '' ?>"
                                           onkeydown="if(event.key==='Escape') cancelEdit('<?= $cellId ?>')">
                                    <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 8px;" title="Simpan">✓</button>
                                    <button type="button" class="btn btn-sm btn-ghost" style="padding:4px 6px;" onclick="cancelEdit('<?= $cellId ?>')" title="Batal">✕</button>
                                </form>
                            </div>
                        </div>
                    </td>
                    <?php endforeach; ?>

                    <td>
                        <div class="price-actions" style="justify-content:center;">
                            <button class="btn btn-sm btn-outline" title="Atur semua harga"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                    'id'         => $prod['id'],
                                    'name'       => $prod['name'],
                                    'base_price' => $prod['base_price'],
                                    'prices'     => $allPrices,
                                ])) ?>)"
                                style="gap:4px; font-size:0.73rem; white-space:nowrap;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                Atur
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div class="text-sm text-muted">
            Menampilkan <strong><?= count($products) ?></strong> dari <strong><?= number_format($totalItems) ?></strong> produk
        </div>
        <?= renderPagination($pagination, BASE_URL . '/admin/prices.php') ?>
    </div>
</div>
<?php endif; ?>


<!-- =============================================
     Modal: Atur Semua Harga (Full Edit)
     ============================================= -->
<div class="modal-overlay" id="modalEditPrice">
    <div class="modal modal-lg">
        <div class="modal-header" style="background:linear-gradient(135deg, var(--primary-600), var(--primary-800)); color:#fff; border-bottom: none;">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <div>
                    <h3 class="modal-title" style="color:#fff; font-size:1rem;">Atur Harga Produk</h3>
                    <div id="editModalProductName" style="font-size:0.78rem; opacity:0.8;"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal('modalEditPrice')" style="color:#fff;">&times;</button>
        </div>
        <form method="POST" id="formEditPrice">
            <input type="hidden" name="action" value="save_price">
            <input type="hidden" name="product_id" id="editModalProductId">
            <div class="modal-body">
                <!-- HPP Info -->
                <div style="background:var(--gray-50); border-radius:var(--border-radius-sm); padding:10px 14px; margin-bottom:16px; border-left:3px solid var(--warning); display:flex; align-items:center; gap:10px; font-size:0.82rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span>HPP (Modal): <strong id="editModalHpp" style="color:var(--primary-700);"></strong> — Harga di bawah HPP akan ditandai merah</span>
                </div>

                <!-- Scrollable Container untuk Layar Kecil -->
                <div style="overflow-x: auto; overflow-y: auto; max-height: 50vh; min-width: 100%; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); padding: 8px;">
                    <div style="min-width: 550px;">
                        <!-- Column Headers -->
                        <div style="display:grid; grid-template-columns: 1.5fr 1fr 0.8fr 1.5fr 80px 30px; gap:8px; padding:0 4px; margin-bottom:6px;">
                            <div style="font-size:0.72rem; font-weight:700; color:var(--gray-500); text-transform:uppercase; letter-spacing:.04em;">Label Harga</div>
                            <div style="font-size:0.72rem; font-weight:700; color:var(--gray-500); text-transform:uppercase; letter-spacing:.04em;">Tipe Pelanggan</div>
                            <div style="font-size:0.72rem; font-weight:700; color:var(--gray-500); text-transform:uppercase; letter-spacing:.04em;">Min. Qty</div>
                            <div style="font-size:0.72rem; font-weight:700; color:var(--gray-500); text-transform:uppercase; letter-spacing:.04em;">Harga Jual</div>
                            <div style="font-size:0.72rem; font-weight:700; color:var(--gray-500); text-transform:uppercase; letter-spacing:.04em;">Margin</div>
                            <div></div>
                        </div>

                        <!-- Price Rows -->
                        <div id="priceRowsContainer"></div>
                    </div>
                </div>

                <button type="button" class="btn btn-outline btn-sm" onclick="addPriceRow()" style="margin-top:10px; gap:6px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tambah Baris Harga
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalEditPrice')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Simpan Semua Harga
                </button>
            </div>
        </form>
    </div>
</div>



<datalist id="labelSuggestions">
    <option value="Ecer">
    <option value="Grosir1">
    <option value="Grosir2">
    <option value="Partai">
</datalist>

<script>
const priceTypes  = <?= json_encode($priceTypes) ?>;
let modalHpp      = 0;
let priceRowIndex = 0;

// ====== INLINE CELL EDIT ======
function startEdit(cellId, productId, label, currentPrice, hpp) {
    const wrapper = document.getElementById(cellId);
    if (!wrapper) return;
    // Close any other open editors first
    document.querySelectorAll('.price-cell-wrapper.editing').forEach(el => {
        if (el.id !== cellId) cancelEdit(el.id);
    });
    wrapper.classList.add('editing');
    const inp = document.getElementById('inp_' + cellId);
    if (inp) {
        inp.value = currentPrice > 0 ? parseInt(currentPrice).toLocaleString('id-ID') : '';
        inp.focus(); inp.select();
        // Real-time margin on inline
        inp.oninput = function() {
            let v = this.value.replace(/\./g, '').replace(/\D/g, '');
            this.value = v ? parseInt(v).toLocaleString('id-ID') : '';
        };
    }
}

function cancelEdit(cellId) {
    document.getElementById(cellId).classList.remove('editing');
}

function startEditDisc(cellId, productId, currentVal) {
    document.querySelectorAll('.price-cell-wrapper.editing').forEach(el => el.classList.remove('editing'));
    let wrapper = document.getElementById(cellId);
    wrapper.classList.add('editing');
    let inp = document.getElementById('inp_' + cellId);
    if(inp) {
        inp.value = currentVal;
        inp.focus();
        inp.select();
    }
}

// ====== MODAL ======
function openEditModal(data) {
    document.getElementById('editModalProductId').value = data.id;
    document.getElementById('editModalProductName').textContent = data.name;
    document.getElementById('editModalHpp').textContent = 'Rp ' + parseInt(data.base_price).toLocaleString('id-ID');
    modalHpp      = parseFloat(data.base_price) || 0;
    priceRowIndex = 0;
    const container = document.getElementById('priceRowsContainer');
    container.innerHTML = '';

    if (data.prices && data.prices.length > 0) {
        data.prices.forEach(p => addPriceRow(p));
    } else {
        // Default rows
        [
            {label: 'Ecer',   price_type_id: 1, min_qty: 1, selling_price: ''},
            {label: 'Grosir1', price_type_id: 1, min_qty: 10, selling_price: ''},
            {label: 'Grosir2', price_type_id: 1, min_qty: 20, selling_price: ''},
            {label: 'Partai',   price_type_id: 1, min_qty: 50, selling_price: ''},
        ].forEach(p => addPriceRow(p));
    }
    openModal('modalEditPrice');
}

function addPriceRow(data = {}) {
    const i = priceRowIndex++;
    const container = document.getElementById('priceRowsContainer');
    const priceTypeOptions = priceTypes.map(pt =>
        `<option value="${pt.id}" ${(data.price_type_id == pt.id) ? 'selected' : ''}>${escHtml(pt.name)}</option>`
    ).join('');
    const formattedPrice = data.selling_price ? parseInt(data.selling_price).toLocaleString('id-ID') : '';

    const row = document.createElement('div');
    row.id = `priceInputRow${i}`;
    row.style.cssText = 'display:grid; grid-template-columns: 1.5fr 1fr 0.8fr 1.5fr 80px 30px; gap:8px; align-items:center; margin-bottom:8px;';
    row.innerHTML = `
        <input list="labelSuggestions" name="label[]" class="form-control" value="${escHtml(data.label || '')}" placeholder="Eceran / Grosir..." style="font-size:0.82rem;">
        <select name="price_type_id[]" class="form-control" style="font-size:0.82rem;">${priceTypeOptions}</select>
        <input type="number" name="min_qty[]" class="form-control" value="${data.min_qty || 1}" min="1" style="font-size:0.82rem;" onchange="if(this.value == 1) { this.parentElement.querySelector('input[name=\'label[]\']').value = 'Ecer'; }">
        <input type="text" name="selling_price[]" class="form-control rupiah-input"
               value="${formattedPrice}" placeholder="0"
               id="modalPriceInp${i}"
               style="font-size:0.82rem; font-family:var(--font-mono);"
               onblur="checkMargin(this, ${i})" oninput="checkMargin(this, ${i})">
        <span id="marginLbl${i}" style="font-size:0.72rem; font-weight:700; text-align:center;"></span>
        <button type="button" class="btn btn-sm btn-ghost" style="color:var(--danger); padding:4px 6px;" onclick="removePriceRow(${i})" title="Hapus">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
    `;
    container.appendChild(row);

    // Init rupiah format + margin on input
    const inp = document.getElementById(`modalPriceInp${i}`);
    if (inp) {
        inp.addEventListener('input', function() {
            let v = this.value.replace(/\./g, '').replace(/\D/g, '');
            this.value = v ? parseInt(v).toLocaleString('id-ID') : '';
            checkMargin(this, i);
        });
        if (formattedPrice) checkMargin(inp, i);
    }
}

function removePriceRow(i) {
    const el = document.getElementById(`priceInputRow${i}`);
    if (el) el.remove();
}

function checkMargin(input, i) {
    const raw   = input.value.replace(/\./g, '').replace(',', '.');
    const price = parseFloat(raw) || 0;
    const el    = document.getElementById(`marginLbl${i}`);
    if (!el) return;
    if (price <= 0) { el.textContent = ''; return; }
    const margin = price - modalHpp;
    const pct    = modalHpp > 0 ? Math.round(margin / modalHpp * 100) : 0;
    el.textContent = (pct >= 0 ? '+' : '') + pct + '%';
    el.style.color = pct >= 0 ? 'var(--success)' : 'var(--danger)';
    el.style.background = pct >= 0 ? '#f0fdf4' : '#fef2f2';
    el.style.borderRadius = '5px';
    el.style.padding = '2px 5px';
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Convert rupiah on modal submit
document.getElementById('formEditPrice').addEventListener('submit', function(e) {
    let combos = {};
    let isDuplicate = false;
    
    this.querySelectorAll('#priceRowsContainer > div').forEach(row => {
        const ptId = row.querySelector('select[name="price_type_id[]"]').value;
        const minQty = row.querySelector('input[name="min_qty[]"]').value;
        const priceVal = row.querySelector('input[name="selling_price[]"]').value.replace(/\./g, '').replace(',', '.');
        
        // Skip empty rows
        if (parseFloat(priceVal || 0) <= 0) return;
        
        const comboKey = ptId + '_' + minQty;
        
        if (combos[comboKey]) {
            isDuplicate = true;
        }
        combos[comboKey] = true;
        
        if (minQty == 1) {
            row.querySelector('input[name="label[]"]').value = 'Ecer';
        }
    });
    
    if (isDuplicate) {
        e.preventDefault();
        alert('Gagal: Terdapat baris dengan Tipe Pelanggan dan Min. Qty yang sama!');
        return;
    }

    this.querySelectorAll('input[name="selling_price[]"]').forEach(inp => {
        inp.value = inp.value.replace(/\./g, '').replace(',', '.') || '0';
    });
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
