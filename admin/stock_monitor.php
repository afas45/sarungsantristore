<?php
/**
 * Sarung Santri Store - Monitor Stok Varian
 * Tampilan ringkasan: Stok Masuk, Stok Laku, Sisa Stok per Varian
 * Fitur: Filter, Edit Opname, Delete, Import CSV, Export CSV
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Monitor Stok';
$breadcrumbs = [['label' => 'Monitor Stok']];

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
        INDEX idx_var_id (product_variation_id),
        INDEX idx_created_at (created_at)
    )");
} catch (PDOException $e) { /* already exists */ }

// =============================================
// EXPORT CSV
// =============================================
if (isset($_GET['export'])) {
    $fProd   = (int)($_GET['product_id'] ?? 0);
    $fSearch = sanitize($_GET['search'] ?? '');
    $fStockStatus = sanitize($_GET['stock_status'] ?? '');

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=monitor_stok_' . date('Ymd_His') . '.xls');
    
    echo '<table border="1">';
    echo '<tr><th style="background:#f8f9fa;">Produk</th><th style="background:#f8f9fa;">Varian</th><th style="background:#f8f9fa;">SKU Varian</th><th style="background:#f8f9fa;">Stok Masuk</th><th style="background:#f8f9fa;">Stok Laku</th><th style="background:#f8f9fa;">Sisa Stok</th></tr>';

    $wE = "WHERE 1=1";
    $pE = [];
    if ($fProd)   { $wE .= " AND pv.product_id = ?"; $pE[] = $fProd; }
    if ($fSearch) {
        $wE .= " AND (p.name LIKE ? OR pv.sku LIKE ? OR pv.variation_name LIKE ?)";
        $pE[] = "%$fSearch%"; $pE[] = "%$fSearch%"; $pE[] = "%$fSearch%";
    }
    if ($fStockStatus === 'in_stock') {
        $wE .= " AND pv.stock_qty > 0";
    } elseif ($fStockStatus === 'out_of_stock') {
        $wE .= " AND pv.stock_qty <= 0";
    }

    $rows = $db->prepare("
        SELECT p.name as product_name,
               pv.variation_name, pv.sku, pv.stock_qty,
               COALESCE(SUM(CASE WHEN sl.change_type IN ('restok','opname') AND sl.qty_change > 0 THEN sl.qty_change ELSE 0 END),0) as stok_masuk,
               COALESCE(SUM(CASE WHEN sl.change_type = 'penjualan' THEN ABS(sl.qty_change) ELSE 0 END),0) as stok_laku
        FROM product_variations pv
        JOIN products p ON pv.product_id = p.id
        LEFT JOIN stock_logs sl ON sl.product_variation_id = pv.id
        $wE
        GROUP BY pv.id
        ORDER BY p.name, pv.variation_name
    ");
    $rows->execute($pE);
    while ($r = $rows->fetch()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['product_name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['variation_name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['sku']) . '</td>';
        echo '<td>' . $r['stok_masuk'] . '</td>';
        echo '<td>' . $r['stok_laku'] . '</td>';
        echo '<td>' . $r['stock_qty'] . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

// =============================================
// DOWNLOAD FOTO ZIP
// =============================================
if (isset($_GET['download_photos'])) {
    $fProd   = (int)($_GET['product_id'] ?? 0);
    $fSearch = sanitize($_GET['search'] ?? '');
    $fStockStatus = sanitize($_GET['stock_status'] ?? '');

    $wE = "WHERE 1=1 AND (p.image IS NOT NULL AND p.image != '' OR p.image2 IS NOT NULL AND p.image2 != '' OR pv.image IS NOT NULL AND pv.image != '' OR pv.image2 IS NOT NULL AND pv.image2 != '')";
    $pE = [];
    if ($fProd)   { $wE .= " AND pv.product_id = ?"; $pE[] = $fProd; }
    if ($fSearch) {
        $wE .= " AND (p.name LIKE ? OR pv.sku LIKE ? OR pv.variation_name LIKE ?)";
        $pE[] = "%$fSearch%"; $pE[] = "%$fSearch%"; $pE[] = "%$fSearch%";
    }
    if ($fStockStatus === 'in_stock') {
        $wE .= " AND pv.stock_qty > 0";
    } elseif ($fStockStatus === 'out_of_stock') {
        $wE .= " AND pv.stock_qty <= 0";
    }

    $rows = $db->prepare("
        SELECT pv.id as var_id, p.id as product_id, p.name, pv.variation_name, pv.sku, p.sku as parent_sku, p.image as product_image, p.image2 as product_image2, pv.image as var_image, pv.image2 as var_image2
        FROM product_variations pv
        JOIN products p ON pv.product_id = p.id
        $wE
    ");
    $rows->execute($pE);
    $allRows = $rows->fetchAll();
    
    if (empty($allRows)) {
        flashMessage('error', 'Tidak ada foto yang bisa didownload dari filter ini.');
        redirect(BASE_URL . '/admin/stock_monitor.php');
        exit;
    }
    
    if (!class_exists('ZipArchive')) {
        flashMessage('error', 'Ekstensi ZipArchive tidak aktif. Silakan aktifkan extension=zip pada file php.ini untuk menggunakan fitur ini.');
        redirect(BASE_URL . '/admin/stock_monitor.php');
        exit;
    }
    
    // Tentukan nama ZIP berdasarkan SKU Induk jika hanya 1 produk, jika tidak gunakan nama generic
    $parentSkus = [];
    foreach ($allRows as $r) {
        if (!empty($r['parent_sku']) && !in_array($r['parent_sku'], $parentSkus)) {
            $parentSkus[] = $r['parent_sku'];
        }
    }
    
    if (count($parentSkus) === 1) {
        $zipName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $parentSkus[0]) . '.zip';
    } else {
        $zipName = 'foto_produk_' . date('Ymd_His') . '.zip';
    }
    
    $zip = new ZipArchive();
    $zipPath = sys_get_temp_dir() . '/' . $zipName;
    
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die("Cannot create zip file");
    }
    
    $hasFiles = false;
    $addedZipNames = [];
    
    foreach ($allRows as $r) {
        $img1 = !empty($r['var_image']) ? $r['var_image'] : $r['product_image'];
        $img2 = !empty($r['var_image2']) ? $r['var_image2'] : $r['product_image2'];
        
        $imagesToProcess = [];
        if (!empty($img1)) $imagesToProcess[] = ['path' => $img1, 'suffix' => '_1'];
        if (!empty($img2)) $imagesToProcess[] = ['path' => $img2, 'suffix' => '_2'];
        
        foreach ($imagesToProcess as $imgData) {
            $imgPath = __DIR__ . '/../' . $imgData['path'];
            if (file_exists($imgPath) && is_file($imgPath)) {
                $ext = pathinfo($imgPath, PATHINFO_EXTENSION);
                
                // Nama foto dalam ZIP = SKU Varian_1 / _2
                $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $r['sku']) . $imgData['suffix'] . '.' . $ext;
                
                // Hindari nama file duplikat di dalam ZIP
                if (!in_array($filename, $addedZipNames)) {
                    $zip->addFile($imgPath, $filename);
                    $addedZipNames[] = $filename;
                    $hasFiles = true;
                }
            }
        }
    }
    $zip->close();
    
    if ($hasFiles) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    } else {
        flashMessage('error', 'Tidak ada foto yang bisa didownload dari filter ini.');
        redirect(BASE_URL . '/admin/stock_monitor.php');
    }
    exit;
}

// =============================================
// HANDLE POST ACTIONS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = getCurrentUser()['id'] ?? null;

    // --- Opname: set sisa stok aktual ---
    if ($action === 'opname') {
        $varId    = (int)$_POST['var_id'];
        $newStock = max(0, (int)$_POST['new_stock']);
        $note     = sanitize($_POST['note'] ?? 'Opname stok');

        $stmtCur = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ?");
        $stmtCur->execute([$varId]);
        $curStock = (int)$stmtCur->fetchColumn();

        $diff = $newStock - $curStock;

        // Update stok varian
        $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")
           ->execute([$newStock, $varId]);

        // Catat log opname
        $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by)
                      VALUES (?, ?, ?, ?, 'opname', ?, ?)")
           ->execute([$varId, $diff, $curStock, $newStock, $note, $userId]);

        flashMessage('success', "Opname berhasil. Stok disesuaikan: {$curStock} → {$newStock} pcs");
        redirect(BASE_URL . '/admin/stock_monitor.php?' . http_build_query(array_filter(['product_id' => $_POST['product_id_filter'] ?? null, 'search' => $_POST['search_filter'] ?? null])));
    }

    // --- Edit log stok ---
    if ($action === 'edit_log') {
        $logId      = (int)$_POST['log_id'];
        $qtyChange  = (int)$_POST['qty_change'];
        $changeType = in_array($_POST['change_type'] ?? '', ['restok','penjualan','adjustment','opname'])
                      ? $_POST['change_type'] : 'adjustment';
        $note       = sanitize($_POST['note'] ?? '');
        $createdAt  = sanitize($_POST['created_at'] ?? '');

        $stmt = $db->prepare("SELECT * FROM stock_logs WHERE id = ?");
        $stmt->execute([$logId]);
        $log = $stmt->fetch();

        if ($log) {
            $newAfter = $log['qty_before'] + $qtyChange;
            $db->prepare("UPDATE stock_logs SET qty_change=?, qty_after=?, change_type=?, note=?, created_at=? WHERE id=?")
               ->execute([$qtyChange, $newAfter, $changeType, $note, $createdAt ?: $log['created_at'], $logId]);
            $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")
               ->execute([$newAfter, $log['product_variation_id']]);
            flashMessage('success', 'Log stok berhasil diperbarui.');
        } else {
            flashMessage('error', 'Log tidak ditemukan.');
        }
        redirect(BASE_URL . '/admin/stock_monitor.php?' . http_build_query($_GET));
    }

    // --- Delete log stok ---
    if ($action === 'delete_log') {
        $logId = (int)$_POST['log_id'];
        $db->prepare("DELETE FROM stock_logs WHERE id = ?")->execute([$logId]);
        flashMessage('success', 'Log stok berhasil dihapus.');
        redirect(BASE_URL . '/admin/stock_monitor.php?' . http_build_query($_GET));
    }

    // --- Import CSV ---
    if ($action === 'import_csv') {
        if (empty($_FILES['csv_file']['name'])) {
            flashMessage('error', 'Pilih file CSV terlebih dahulu.');
            redirect(BASE_URL . '/admin/stock_monitor.php');
        }
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        fgetcsv($handle);
        $imported = 0; $errors = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) continue;
            $sku = trim($row[0]);
            $type = in_array(trim($row[1]), ['restok','penjualan','adjustment','opname']) ? trim($row[1]) : 'adjustment';
            $qty = (int)trim($row[2]);
            $note = sanitize(trim($row[3] ?? 'Import CSV'));
            $stmtV = $db->prepare("SELECT id, stock_qty FROM product_variations WHERE sku = ?");
            $stmtV->execute([$sku]);
            $var = $stmtV->fetch();
            if (!$var) { $errors++; continue; }
            $oldStock = (int)$var['stock_qty'];
            $newStock = max(0, $oldStock + $qty);
            $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")->execute([$newStock, $var['id']]);
            $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$var['id'], $qty, $oldStock, $newStock, $type, $note, $userId]);
            $imported++;
        }
        fclose($handle);
        flashMessage('success', "Import: {$imported} berhasil" . ($errors > 0 ? ", {$errors} gagal." : "."));
        redirect(BASE_URL . '/admin/stock_monitor.php');
    }

    redirect(BASE_URL . '/admin/stock_monitor.php');
}

// =============================================
// FETCH FILTER PARAMS
// =============================================
$fProductId = (int)($_GET['product_id'] ?? 0);
$fSearch    = sanitize($_GET['search'] ?? '');
$fStockStatus = sanitize($_GET['stock_status'] ?? '');
$fSort      = sanitize($_GET['sort'] ?? '');
$fDir       = strtoupper(sanitize($_GET['dir'] ?? 'ASC'));
if (!in_array($fDir, ['ASC', 'DESC'])) $fDir = 'ASC';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = ITEMS_PER_PAGE;

// =============================================
// BUILD MAIN QUERY (Summary per varian)
// =============================================
$where  = "WHERE 1=1";
$params = [];

if ($fProductId) { $where .= " AND pv.product_id = ?"; $params[] = $fProductId; }
if ($fSearch) {
    $where .= " AND (p.name LIKE ? OR pv.sku LIKE ? OR pv.variation_name LIKE ?)";
    $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%";
}
if ($fStockStatus === 'in_stock') {
    $where .= " AND pv.stock_qty > 0";
} elseif ($fStockStatus === 'out_of_stock') {
    $where .= " AND pv.stock_qty <= 0";
}

$countStmt = $db->prepare("
    SELECT COUNT(DISTINCT pv.id)
    FROM product_variations pv
    JOIN products p ON pv.product_id = p.id
    $where
");
$countStmt->execute($params);
$totalItems = $countStmt->fetchColumn();
$pagination = getPagination($totalItems, $page, $perPage);
$offset     = $pagination['offset'];

$orderBy = "ORDER BY p.name ASC, pv.variation_name ASC";
if ($fSort === 'varian') {
    $orderBy = "ORDER BY pv.variation_name $fDir, p.name ASC";
} elseif ($fSort === 'stok_masuk') {
    $orderBy = "ORDER BY stok_masuk $fDir, p.name ASC, pv.variation_name ASC";
} elseif ($fSort === 'stok_laku') {
    $orderBy = "ORDER BY stok_laku $fDir, p.name ASC, pv.variation_name ASC";
} elseif ($fSort === 'sisa_stok') {
    $orderBy = "ORDER BY pv.stock_qty $fDir, p.name ASC, pv.variation_name ASC";
}

$stmt = $db->prepare("
    SELECT pv.id as var_id, pv.sku as var_sku, pv.variation_name, pv.stock_qty, pv.image as var_image,
           p.id as product_id, p.name as product_name, p.image as product_image,
           COALESCE(SUM(CASE WHEN sl.change_type = 'restok' THEN sl.qty_change WHEN sl.change_type = 'opname' AND sl.qty_change > 0 THEN sl.qty_change ELSE 0 END), 0) as stok_masuk,
           COALESCE(SUM(CASE WHEN sl.change_type = 'penjualan' THEN ABS(sl.qty_change) ELSE 0 END), 0) as stok_laku,
           MAX(sl.created_at) as last_activity
    FROM product_variations pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN stock_logs sl ON sl.product_variation_id = pv.id
    $where
    GROUP BY pv.id
    $orderBy
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$variantRows = $stmt->fetchAll();

// Stats global
$statsRow = $db->query("
    SELECT
        COALESCE(SUM(stock_qty),0) as total_sisa,
        COUNT(*) as total_varian
    FROM product_variations
")->fetch();

$stokMasukTotal = $db->query("SELECT COALESCE(SUM(CASE WHEN change_type = 'restok' THEN qty_change WHEN change_type = 'opname' AND qty_change > 0 THEN qty_change ELSE 0 END),0) FROM stock_logs")->fetchColumn();
$stokLakuTotal  = $db->query("SELECT COALESCE(SUM(ABS(qty_change)),0) FROM stock_logs WHERE change_type = 'penjualan'")->fetchColumn();

// Products for filter
$productList = $db->query("SELECT id, name FROM products ORDER BY name")->fetchAll();

// Fetch last logs per variant (for detail modal)
$varIds = array_column($variantRows, 'var_id');
$recentLogs = [];
if (!empty($varIds)) {
    $in = implode(',', array_fill(0, count($varIds), '?'));
    $logStmt = $db->prepare("
        SELECT sl.*, u.full_name as operator_name
        FROM stock_logs sl
        LEFT JOIN users u ON sl.created_by = u.id
        WHERE sl.product_variation_id IN ($in)
        ORDER BY sl.created_at DESC
    ");
    $logStmt->execute($varIds);
    foreach ($logStmt->fetchAll() as $lg) {
        $recentLogs[$lg['product_variation_id']][] = $lg;
    }
}

include INCLUDES_PATH . '/header.php';
?>

<style>
/* Stock badges */
.stock-in  { background: #f0fdf4; color: #15803d; font-weight:700; padding:2px 8px; border-radius:6px; font-size:0.82rem; }
.stock-out { background: #fef2f2; color: #dc2626; font-weight:700; padding:2px 8px; border-radius:6px; font-size:0.82rem; }
.stock-rem { font-weight:700; font-size:0.9rem; }
.stock-low  { color: var(--danger); }
.stock-mid  { color: var(--warning); }
.stock-ok   { color: var(--success); }

/* Log detail popup */
.log-pill {
    display: inline-flex; align-items:center; gap:4px;
    padding:2px 8px; border-radius:20px;
    font-size:0.7rem; font-weight:700;
}
.log-restok     { background:#f0fdf4; color:#15803d; }
.log-penjualan  { background:#eff6ff; color:#1d4ed8; }
.log-adjustment { background:#fffbeb; color:#92400e; }
.log-opname     { background:#fdf4ff; color:#7e22ce; }

/* Compact Table */
.table-compact td, .table-compact th {
    padding: 6px 10px !important;
    vertical-align: middle;
}
</style>

<!-- Stats Overview -->
<div class="stats-grid" style="margin-bottom: 20px;">
    <div class="stat-card success">
        <div class="stat-icon success">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="21 15 12 24 3 15"/><line x1="12" y1="2" x2="12" y2="24"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Stok Masuk</div>
            <div class="stat-value"><?= number_format($stokMasukTotal) ?></div>
            <div class="stat-change">pcs (semua waktu)</div>
        </div>
    </div>
    
    <div class="stat-card info">
        <div class="stat-icon info">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Stok Laku</div>
            <div class="stat-value"><?= number_format($stokLakuTotal) ?></div>
            <div class="stat-change">pcs terjual</div>
        </div>
    </div>
    
    <div class="stat-card primary">
        <div class="stat-icon primary">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Sisa Stok Semua Varian</div>
            <div class="stat-value"><?= number_format($statsRow['total_sisa']) ?></div>
            <div class="stat-change">pcs real-time</div>
        </div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-icon warning">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Varian</div>
            <div class="stat-value"><?= number_format($statsRow['total_varian']) ?></div>
            <div class="stat-change">varian aktif</div>
        </div>
    </div>
</div>

<!-- Toolbar -->
<div class="toolbar">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex:1;">
        <div class="search-box" style="flex:1; max-width:260px; margin-right:8px;">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="search" class="form-control" placeholder="Cari produk, SKU Induk, varian..." value="<?= htmlspecialchars($fSearch) ?>" onchange="this.form.submit()">
        </div>
        <select name="product_id" class="form-control" style="width:180px;" onchange="this.form.submit()">
            <option value="">Semua Produk</option>
            <?php foreach ($productList as $pr): ?>
                <option value="<?= $pr['id'] ?>" <?= $fProductId == $pr['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($pr['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="stock_status" id="stockStatusInput" value="<?= htmlspecialchars($fStockStatus) ?>">
        <button type="button" class="btn btn-outline btn-icon" onclick="toggleStockVisibility()" title="<?= $fStockStatus === 'in_stock' ? 'Tampilkan Stok Kosong' : 'Sembunyikan Stok Kosong' ?>">
            <?php if ($fStockStatus === 'in_stock'): ?>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            <?php else: ?>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <?php endif; ?>
        </button>
        <script>
        function toggleStockVisibility() {
            var input = document.getElementById('stockStatusInput');
            input.value = input.value === 'in_stock' ? '' : 'in_stock';
            input.form.submit();
        }
        </script>
        <noscript><button type="submit" class="btn btn-outline">Cari</button></noscript>
    </form>
    <div class="filter-group">
        <?php if ($fSearch || $fProductId || $fStockStatus): ?>
        <a href="<?= BASE_URL ?>/admin/stock_monitor.php" class="btn btn-ghost">✕ Reset</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/stock_monitor.php?download_photos=1<?= $fProductId ? '&product_id='.$fProductId : '' ?><?= $fSearch ? '&search='.urlencode($fSearch) : '' ?><?= $fStockStatus ? '&stock_status='.$fStockStatus : '' ?>"
           class="btn btn-outline btn-icon" title="Download Foto" style="color: #0284c7; border-color: #0284c7;" download>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="btn-text-mobile-only" style="display: none;">Download Foto</span>
        </a>
        <button class="btn btn-outline btn-icon" onclick="openModal('modalImportStock')" title="Import CSV" style="color: darkgreen; border-color: darkgreen;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="btn-text-mobile-only" style="display: none;">Import</span>
        </button>
        <a href="<?= BASE_URL ?>/admin/stock_monitor.php?export=1<?= $fProductId ? '&product_id='.$fProductId : '' ?><?= $fSearch ? '&search='.urlencode($fSearch) : '' ?><?= $fStockStatus ? '&stock_status='.$fStockStatus : '' ?>"
           class="btn btn-outline btn-icon" title="Export CSV" style="color: darkred; border-color: darkred;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <span class="btn-text-mobile-only" style="display: none;">Export</span>
        </a>
    </div>
</div>



<!-- Main Table -->
<div class="card">
    <div class="table-responsive">
        <style>
            .table-compact thead th { position: sticky; top: 0; z-index: 10; background: #fdf8f6; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        </style>
        <?php if (empty($variantRows)): ?>
        <div style="padding:60px 20px; text-align:center; color:var(--gray-400);">
            <div style="font-size:3rem; margin-bottom:12px;">📦</div>
            <div style="font-weight:600; margin-bottom:6px;">Tidak ada varian ditemukan</div>
            <div style="font-size:0.85rem;">Coba ubah filter pencarian.</div>
        </div>
        <?php else: ?>
        <table class="table table-compact" style="font-size:0.82rem;">
            <?php
            $buildSortUrl = function($col) use ($fProductId, $fSearch, $fStockStatus, $fSort, $fDir) {
                $newDir = ($fSort === $col && $fDir === 'ASC') ? 'DESC' : 'ASC';
                $params = ['sort' => $col, 'dir' => $newDir];
                if ($fProductId) $params['product_id'] = $fProductId;
                if ($fSearch) $params['search'] = $fSearch;
                if ($fStockStatus) $params['stock_status'] = $fStockStatus;
                return '?' . http_build_query($params);
            };
            $sortIcon = function($col) use ($fSort, $fDir) {
                if ($fSort !== $col) return '<i data-lucide="chevrons-up-down" style="width:14px;height:14px;margin-left:4px;opacity:0.3;vertical-align:middle;"></i>';
                return $fDir === 'ASC' ? '<i data-lucide="arrow-up-0-9" style="width:14px;height:14px;margin-left:4px;color:var(--primary-600);vertical-align:middle;"></i>' : '<i data-lucide="arrow-down-9-0" style="width:14px;height:14px;margin-left:4px;color:var(--primary-600);vertical-align:middle;"></i>';
            };
            ?>
            <thead>
                <tr>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Foto</th>
                    <th style="width:1%; white-space:nowrap; min-width:180px;">Produk</th>
                    <th style="width:1%; white-space:nowrap;">SKU</th>
                    <th style="width:1%; white-space:nowrap;">
                        <a href="<?= htmlspecialchars($buildSortUrl('varian')) ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center;">
                            Varian <?= $sortIcon('varian') ?>
                        </a>
                    </th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">
                        <a href="<?= htmlspecialchars($buildSortUrl('stok_masuk')) ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; justify-content:center;">
                            Stok Masuk <?= $sortIcon('stok_masuk') ?>
                        </a>
                    </th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">
                        <a href="<?= htmlspecialchars($buildSortUrl('stok_laku')) ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; justify-content:center;">
                            Stok Laku <?= $sortIcon('stok_laku') ?>
                        </a>
                    </th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">
                        <a href="<?= htmlspecialchars($buildSortUrl('sisa_stok')) ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; justify-content:center;">
                            Sisa Stok <?= $sortIcon('sisa_stok') ?>
                        </a>
                    </th>
                    <th style="width:1%; white-space:nowrap; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($variantRows as $vr): 
                    $sisaStock = (int)$vr['stock_qty'];
                    $logCount  = count($recentLogs[$vr['var_id']] ?? []);
                ?>
                <tr id="varRow<?= $vr['var_id'] ?>">
                    <td style="text-align:center;">
                        <?php 
                            $dispImg = !empty($vr['var_image']) ? $vr['var_image'] : $vr['product_image'];
                        ?>
                        <?php if (!empty($dispImg)): ?>
                            <img src="<?= BASE_URL . '/' . htmlspecialchars($dispImg) ?>" alt="Foto Produk" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid var(--gray-200); display: block; margin: 0 auto;">
                        <?php else: ?>
                            <div style="width: 40px; height: 40px; background: var(--gray-100); border-radius: 4px; display:flex; align-items:center; justify-content:center; color: var(--gray-400); margin: 0 auto;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <div style="font-weight:600; color:var(--gray-800);"><?= htmlspecialchars($vr['product_name']) ?></div>
                        <?php if ($vr['last_activity']): ?>
                        <div style="font-size:0.7rem; color:var(--gray-400);">Aktivitas: <?= date('d/m/Y', strtotime($vr['last_activity'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <span class="text-bold"><?= htmlspecialchars($vr['var_sku']) ?></span>
                    </td>
                    <td style="white-space:nowrap;">
                        <div style="font-weight:600; color:var(--gray-700);"><?= htmlspecialchars($vr['variation_name'] ?: 'Random') ?></div>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <span class="stock-in"><?= number_format($vr['stok_masuk']) ?> pcs</span>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <span class="stock-out"><?= number_format($vr['stok_laku']) ?> pcs</span>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <?php 
                        if ($sisaStock > 3) {
                            $sisaClass = 'stock-mid';
                        } else {
                            $sisaClass = 'stock-low';
                        }
                        ?>
                        <span class="stock-rem <?= $sisaClass ?>"><?= number_format($sisaStock) ?> pcs</span>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <div class="d-flex gap-4 items-center" style="justify-content:center;">
                            <!-- Opname Button -->
                            <button class="btn btn-sm btn-primary btn-icon" title="Opname Stok"
                                onclick='openOpname(<?= json_encode([
                                    "var_id"    => $vr["var_id"],
                                    "var_name"  => ($vr["variation_name"] ?: "Random"),
                                    "prod_name" => $vr["product_name"],
                                    "cur_stock" => $sisaStock,
                                ]) ?>)'>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            </button>
                            <!-- Log Detail -->
                            <?php if ($logCount > 0): ?>
                            <button class="btn btn-sm btn-outline btn-icon" title="Lihat Log (<?= $logCount ?>)"
                                onclick='openLogDetail(<?= $vr["var_id"] ?>, <?= htmlspecialchars(json_encode($vr["product_name"] . " - " . ($vr["variation_name"] ?: "Random"))) ?>)'>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div class="text-sm text-muted">
            Menampilkan <strong><?= count($variantRows) ?></strong> dari <strong><?= number_format($totalItems) ?></strong> varian
            <?php if ($fSearch || $fProductId): ?>(terfilter)<?php endif; ?>
        </div>
        <?= renderPagination($pagination, BASE_URL . '/admin/stock_monitor.php') ?>
    </div>
</div>


<!-- =============================================
     Modal: Opname Stok
     ============================================= -->
<div class="modal-overlay" id="modalOpname">
    <div class="modal">
        <div class="modal-header" style="background:linear-gradient(135deg, var(--primary-600), var(--primary-800)); color:#fff; border-bottom: none;">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <h3 class="modal-title" style="color:#fff;">Opname Stok</h3>
            </div>
            <button class="modal-close" onclick="closeModal('modalOpname')" style="color:#fff;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="opname">
            <input type="hidden" name="var_id" id="opVarId">
            <input type="hidden" name="product_id_filter" value="<?= $fProductId ?>">
            <input type="hidden" name="search_filter" value="<?= htmlspecialchars($fSearch) ?>">
            <div class="modal-body">
                <div style="background:var(--primary-50); border-radius:var(--border-radius-sm); padding:10px 14px; margin-bottom:16px; border-left:3px solid var(--primary-500); font-size:0.83rem;">
                    <div style="font-weight:700; color:var(--primary-800);" id="opProductLabel"></div>
                    <div style="color:var(--gray-600); margin-top:4px;">
                        Stok di sistem saat ini: <strong id="opCurrentStock" style="color:var(--primary-700);"></strong> pcs
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Stok Aktual (setelah dihitung fisik) <span class="required">*</span></label>
                    <input type="number" name="new_stock" id="opNewStock" class="form-control" min="0" required placeholder="Masukkan jumlah stok aktual...">
                    <span class="form-hint">Sistem akan menyesuaikan stok dan mencatat log opname secara otomatis.</span>
                </div>
                <div id="opnameDiffPreview" style="display:none; padding:10px 14px; border-radius:var(--border-radius-sm); font-size:0.83rem; font-weight:600; margin-top:4px;"></div>
                <div class="form-group mt-12">
                    <label class="form-label">Keterangan</label>
                    <input type="text" name="note" class="form-control" value="Opname stok" placeholder="Keterangan opname...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalOpname')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Opname</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Log Detail -->
<div class="modal-overlay" id="modalLogDetail">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title" id="logDetailTitle">Riwayat Log Stok</h3>
            <button class="modal-close" onclick="closeModal('modalLogDetail')">&times;</button>
        </div>
        <div class="modal-body" id="logDetailBody" style="padding:0; max-height:440px; overflow-y:auto;">
            <!-- Injected by JS -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('modalLogDetail')">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal: Import CSV -->
<div class="modal-overlay" id="modalImportStock">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Import Stok dari CSV</h3>
            <button class="modal-close" onclick="closeModal('modalImportStock')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_csv">
            <div class="modal-body">
                <div style="background:var(--info-bg); border-radius:var(--border-radius-sm); padding:12px 16px; margin-bottom:16px; border-left:3px solid var(--info); font-size:0.82rem;">
                    <strong style="color:var(--info);">Format CSV:</strong><br>
                    <code>SKU Varian | Tipe | Qty Change | Keterangan</code><br>
                    Tipe: <code>restok / penjualan / adjustment / opname</code>
                </div>
                <div class="form-group">
                    <label class="form-label">Pilih File CSV <span class="required">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalImportStock')">Batal</button>
                <button type="submit" class="btn btn-primary">Proses Import</button>
            </div>
        </form>
    </div>
</div>

<script>
// All logs data per variant
const allLogs = <?= json_encode($recentLogs) ?>;
const typeIcons = {restok:'📥', penjualan:'🛒', adjustment:'🔧', opname:'📋'};

function openOpname(data) {
    document.getElementById('opVarId').value    = data.var_id;
    document.getElementById('opProductLabel').textContent = data.prod_name + ' — ' + data.var_name;
    document.getElementById('opCurrentStock').textContent = data.cur_stock;
    document.getElementById('opNewStock').value = data.cur_stock;
    document.getElementById('opnameDiffPreview').style.display = 'none';
    openModal('modalOpname');

    // Diff preview
    document.getElementById('opNewStock').oninput = function() {
        const newVal = parseInt(this.value) || 0;
        const diff   = newVal - data.cur_stock;
        const el     = document.getElementById('opnameDiffPreview');
        if (this.value === '') { el.style.display='none'; return; }
        el.style.display = 'block';
        if (diff > 0) {
            el.style.background = '#f0fdf4'; el.style.color = '#15803d';
            el.textContent = `▲ Stok akan bertambah ${diff} pcs (${data.cur_stock} → ${newVal})`;
        } else if (diff < 0) {
            el.style.background = '#fef2f2'; el.style.color = '#dc2626';
            el.textContent = `▼ Stok akan berkurang ${Math.abs(diff)} pcs (${data.cur_stock} → ${newVal})`;
        } else {
            el.style.background = 'var(--gray-50)'; el.style.color = 'var(--gray-600)';
            el.textContent = `Stok tidak berubah (${newVal} pcs)`;
        }
    };
}

function openLogDetail(varId, label) {
    document.getElementById('logDetailTitle').textContent = 'Log Stok: ' + label;
    const logs = allLogs[varId] || [];
    let html = '';
    if (!logs.length) {
        html = '<div style="padding:40px; text-align:center; color:var(--gray-400);">Belum ada log stok</div>';
    } else {
        html = '<table class="table" style="font-size:0.8rem; margin:0;"><thead><tr><th>Tanggal</th><th>Tipe</th><th>Qty</th><th>Sebelum → Sesudah</th><th>Keterangan</th><th>Operator</th></tr></thead><tbody>';
        logs.forEach(l => {
            const isPos = l.qty_change >= 0;
            const qtyColor = isPos ? '#15803d' : '#dc2626';
            const typeClass = 'log-' + l.change_type;
            const dt = new Date(l.created_at);
            const dateStr = dt.toLocaleDateString('id-ID',{day:'2-digit',month:'2-digit',year:'numeric'}) + ' ' + dt.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'});
            html += `<tr>
                <td style="white-space:nowrap;">${dateStr}</td>
                <td><span class="log-pill ${typeClass}">${typeIcons[l.change_type]||''} ${l.change_type}</span></td>
                <td style="color:${qtyColor}; font-weight:700;">${isPos?'+':''}${l.qty_change}</td>
                <td style="font-size:0.78rem;">${l.qty_before} → <strong>${l.qty_after}</strong></td>
                <td style="color:var(--gray-600); max-width:160px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${l.note||''}">${l.note||'-'}</td>
                <td style="color:var(--gray-500); font-size:0.75rem;">${l.operator_name||'Sistem'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
    }
    document.getElementById('logDetailBody').innerHTML = html;
    openModal('modalLogDetail');
}

async function downloadPhotosAjax(url, btn) {
    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:16px; height:16px; margin-right:5px; border: 2px solid #0284c7; border-top-color: transparent;"></div><span class="btn-text-mobile-only" style="display: none;">Loading...</span>';
    
    try {
        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Download failed');
        
        const blob = await response.blob();
        if (blob.type.includes('text/html')) {
            alert('Tidak ada foto yang bisa didownload atau sesi Anda habis.');
            btn.innerHTML = oldHtml;
            btn.disabled = false;
            return;
        }

        // Ambil nama file dari Header Content-Disposition
        const disposition = response.headers.get('Content-Disposition');
        let filename = 'foto_produk.zip';
        if (disposition && disposition.indexOf('filename') !== -1) {
            const matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
            if (matches != null && matches[1]) {
                filename = matches[1].replace(/['"]/g, '');
            }
        }
        
        const blobUrl = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = blobUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        
        window.URL.revokeObjectURL(blobUrl);
        a.remove();
    } catch (err) {
        console.error(err);
        alert('Gagal mendownload foto.');
    }
    
    btn.innerHTML = oldHtml;
    btn.disabled = false;
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
