<?php
/**
 * Kasir Ibtidaiyah - Laporan Penjualan
 * Harian, Mingguan, Bulanan, Tahunan
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);

$db = Database::conn();
$pageTitle = 'Laporan';
$breadcrumbs = [['label' => 'Laporan Penjualan']];

// Handle Actions (Retur & Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $saleId = (int)($_POST['sale_id'] ?? 0);
    
    if ($saleId && in_array($action, ['retur', 'delete'])) {
        try {
            $db->beginTransaction();
            // Get sale details to return stock
            $stmt = $db->prepare("SELECT product_variation_id, qty FROM sale_details WHERE sale_id = ?");
            $stmt->execute([$saleId]);
            $items = $stmt->fetchAll();
            
            // Return stock
            foreach ($items as $item) {
                $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ? WHERE id = ?")
                   ->execute([$item['qty'], $item['product_variation_id']]);
            }
            
            // Delete payments and receivables
            $db->prepare("DELETE FROM payments WHERE sale_id = ?")->execute([$saleId]);
            $db->prepare("DELETE FROM receivables WHERE sale_id = ?")->execute([$saleId]);
            
            if ($action === 'delete') {
                $db->prepare("DELETE FROM sale_details WHERE sale_id = ?")->execute([$saleId]);
                $db->prepare("DELETE FROM shipping_details WHERE sale_id = ?")->execute([$saleId]);
                $db->prepare("DELETE FROM sales WHERE id = ?")->execute([$saleId]);
                flashMessage('success', 'Transaksi berhasil dihapus dan stok dikembalikan.');
                logActivity('Hapus', 'Penjualan', "Hapus transaksi ID: $saleId");
            } else {
                $db->prepare("UPDATE sales SET status = 'Cancelled' WHERE id = ?")->execute([$saleId]);
                flashMessage('success', 'Transaksi berhasil diretur (dibatalkan) dan stok dikembalikan.');
                logActivity('Retur', 'Penjualan', "Retur transaksi ID: $saleId");
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            flashMessage('error', 'Gagal memproses: ' . $e->getMessage());
        }
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Filter
$period = $_GET['period'] ?? 'daily';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$source = $_GET['source'] ?? '';
$search = sanitize($_GET['search'] ?? '');

// Enforce Kasir restrictions: only view own today's transactions
if (hasRoleId(ROLE_KASIR)) {
    $period = 'daily';
    $dateFrom = date('Y-m-d');
    $dateTo = date('Y-m-d');
}

// Set date range based on period
switch ($period) {
    case 'weekly':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        break;
    case 'monthly':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo = $_GET['date_to'] ?? date('Y-m-t');
        break;
    case 'yearly':
        $dateFrom = $_GET['date_from'] ?? date('Y-01-01');
        $dateTo = $_GET['date_to'] ?? date('Y-12-31');
        break;
}

$where = "WHERE DATE(s.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if (hasRoleId(ROLE_KASIR)) {
    $where .= " AND s.cashier_id = ?";
    $params[] = $_SESSION['user_id'];
}

if ($source) {
    if ($source === 'E-Commerce') {
        $where .= " AND s.sale_source IN ('E-Commerce', 'Online') AND s.status = 'Completed'";
    } else {
        $where .= " AND s.sale_source = ? AND s.status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')";
        $params[] = $source;
    }
} else {
    $where .= " AND s.status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')";
}

if ($search) {
    $where .= " AND (s.invoice_number LIKE ? OR c.name LIKE ? OR s.customer_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Summary
$stmt = $db->prepare("SELECT COUNT(*) as total_trx, COALESCE(SUM(grand_total),0) as total_revenue, COALESCE(SUM(CASE WHEN status='Debt' THEN grand_total ELSE 0 END),0) as total_debt FROM sales s LEFT JOIN customers c ON s.customer_id = c.id $where");
$stmt->execute($params);
$summary = $stmt->fetch();

// Refund/Retur Summary
$whereRefund = "WHERE DATE(r.created_at) BETWEEN ? AND ?";
$paramsRefund = [$dateFrom, $dateTo];
if (hasRoleId(ROLE_KASIR)) {
    $whereRefund .= " AND r.cashier_id = ?";
    $paramsRefund[] = $_SESSION['user_id'];
}
if ($source) {
    if ($source === 'E-Commerce') {
        $whereRefund .= " AND s.sale_source IN ('E-Commerce', 'Online')";
    } else {
        $whereRefund .= " AND s.sale_source = ?";
        $paramsRefund[] = $source;
    }
}
$stmtRefund = $db->prepare("
    SELECT COUNT(r.id) as total_retur_trx, COALESCE(SUM(r.refund_amount),0) as total_retur_amount 
    FROM returns r
    JOIN sales s ON r.sale_id = s.id 
    $whereRefund
");
$stmtRefund->execute($paramsRefund);
$refundSummary = $stmtRefund->fetch();

// Profit estimate with FIFO (First In, First Out) method
$stmtIn = $db->query("
    SELECT dni.product_id, dni.qty_received as qty, pd.unit_cost as cost 
    FROM delivery_note_items dni
    JOIN delivery_notes dn ON dni.delivery_note_id = dn.id
    JOIN purchase_details pd ON pd.purchase_id = dn.purchase_id AND pd.product_id = dni.product_id
    ORDER BY dn.received_date ASC, dn.id ASC
");
$incomings = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

$fifoQueues = [];
foreach ($incomings as $in) {
    $pid = $in['product_id'];
    if (!isset($fifoQueues[$pid])) $fifoQueues[$pid] = [];
    $fifoQueues[$pid][] = ['qty' => (int)$in['qty'], 'cost' => (float)$in['cost']];
}

$stmtOut = $db->query("
    SELECT sd.id as sd_id, pv.product_id, sd.qty, pv.base_price as fallback_cost
    FROM sale_details sd
    JOIN sales s ON sd.sale_id = s.id
    JOIN product_variations pv ON sd.product_variation_id = pv.id
    WHERE s.status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')
    ORDER BY s.created_at ASC, sd.id ASC
");
$salesOut = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

$sdCogs = [];
foreach ($salesOut as $out) {
    $pid = $out['product_id'];
    $qtyNeeded = (int)$out['qty'];
    $totalCogs = 0;
    
    while ($qtyNeeded > 0) {
        if (isset($fifoQueues[$pid]) && count($fifoQueues[$pid]) > 0) {
            $batch = &$fifoQueues[$pid][0];
            if ($batch['qty'] <= $qtyNeeded) {
                $totalCogs += $batch['qty'] * $batch['cost'];
                $qtyNeeded -= $batch['qty'];
                array_shift($fifoQueues[$pid]);
            } else {
                $totalCogs += $qtyNeeded * $batch['cost'];
                $batch['qty'] -= $qtyNeeded;
                $qtyNeeded = 0;
            }
        } else {
            $totalCogs += $qtyNeeded * (float)$out['fallback_cost'];
            $qtyNeeded = 0;
        }
    }
    $sdCogs[$out['sd_id']] = $totalCogs;
}

$stmt = $db->prepare("
    SELECT sd.id as sd_id, sd.qty, sd.unit_price, pv.base_price
    FROM sale_details sd 
    JOIN sales s ON sd.sale_id = s.id 
    JOIN product_variations pv ON sd.product_variation_id = pv.id
    LEFT JOIN customers c ON s.customer_id = c.id
    $where
");
$stmt->execute($params);
$profitItems = $stmt->fetchAll();

$fifoProfit = 0;
foreach ($profitItems as $item) {
    $revenue = $item['qty'] * $item['unit_price'];
    $cogs = $sdCogs[$item['sd_id']] ?? ($item['qty'] * $item['base_price']);
    $fifoProfit += ($revenue - $cogs);
}
$profitData = ['profit' => $fifoProfit];

// Detail transactions
$stmt = $db->prepare("
    SELECT s.*, COALESCE(c.name, s.customer_name, 'Umum') as display_customer_name, u.full_name as cashier_name,
    (SELECT payment_method FROM payments WHERE sale_id = s.id ORDER BY id ASC LIMIT 1) as payment_method 
    FROM sales s 
    LEFT JOIN customers c ON s.customer_id = c.id 
    LEFT JOIN users u ON s.cashier_id = u.id 
    $where 
    ORDER BY s.created_at DESC 
    LIMIT 200
");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Export Excel
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_penjualan_' . $dateFrom . '_' . $dateTo . '.xls');
    
    echo '<table border="1">';
    echo '<tr><th style="background:#f8f9fa;">Invoice</th><th style="background:#f8f9fa;">Tanggal</th><th style="background:#f8f9fa;">Pelanggan</th><th style="background:#f8f9fa;">Kasir</th><th style="background:#f8f9fa;">Sumber</th><th style="background:#f8f9fa;">Metode Bayar</th><th style="background:#f8f9fa;">Total</th><th style="background:#f8f9fa;">Status</th></tr>';
    
    foreach ($transactions as $t) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($t['invoice_number']) . '</td>';
        echo '<td>' . htmlspecialchars($t['created_at']) . '</td>';
        echo '<td>' . htmlspecialchars($t['display_customer_name']) . '</td>';
        echo '<td>' . htmlspecialchars($t['cashier_name'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($t['sale_source']) . '</td>';
        echo '<td>' . htmlspecialchars($t['payment_method'] ?? '-') . '</td>';
        echo '<td>' . $t['grand_total'] . '</td>';
        echo '<td>' . htmlspecialchars($t['status']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

// Export Excel (SKU / Produk Terjual)
if (isset($_GET['export']) && $_GET['export'] === 'sku_excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_penjualan_sku_' . $dateFrom . '_' . $dateTo . '.xls');
    
    echo '<table border="1">';
    echo '<tr><th style="background:#f8f9fa;">SKU</th><th style="background:#f8f9fa;">Nama Produk</th><th style="background:#f8f9fa;">Varian</th><th style="background:#f8f9fa;">Kategori</th><th style="background:#f8f9fa;">Total Terjual</th><th style="background:#f8f9fa;">Total Pendapatan</th></tr>';
    
    $stmtSKU = $db->prepare("
        SELECT 
            pv.sku,
            p.name as product_name,
            pv.variation_name,
            cat.name as category_name,
            SUM(sd.qty) as total_qty,
            SUM(sd.subtotal) as total_revenue
        FROM sale_details sd
        JOIN sales s ON sd.sale_id = s.id
        JOIN product_variations pv ON sd.product_variation_id = pv.id
        JOIN products p ON pv.product_id = p.id
        LEFT JOIN categories cat ON p.category_id = cat.id
        LEFT JOIN customers c ON s.customer_id = c.id
        $where
        GROUP BY pv.id
        ORDER BY total_qty DESC
    ");
    $stmtSKU->execute($params);
    $skuData = $stmtSKU->fetchAll();
    
    foreach ($skuData as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['sku']) . '</td>';
        echo '<td>' . htmlspecialchars($row['product_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['variation_name'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['category_name'] ?: 'Tanpa Kategori') . '</td>';
        echo '<td>' . $row['total_qty'] . '</td>';
        echo '<td>' . $row['total_revenue'] . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

include INCLUDES_PATH . '/header.php';
?>

<style>

/* Khusus laporan, pastikan card konsisten */
@media (max-width: 768px) {
    .cari-group { width: 100% !important; }
    .cari-group input { width: 100% !important; }
    
    .date-group { width: calc(50% - 6px) !important; }
    .date-group input { width: 100% !important; }
    
    .source-group { width: calc(60% - 6px) !important; }
    .source-group select { width: 100% !important; }
    
    .filter-btn-group { width: calc(40% - 6px) !important; }
    .filter-btn-group button { width: 100% !important; }

    .action-buttons {
        width: 100%;
        margin-top: 8px;
        flex-direction: row !important;
        flex-wrap: nowrap;
    }
    .action-buttons .btn {
        flex: 1;
        padding: 10px;
        font-size: 0.8rem;
    }
    
    #mobileFilterToggle { display: flex !important; }
    #filterBody { display: none; padding-top: 0; }
    #filterBody.show { display: block; }
}

.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.table {
    white-space: nowrap;
}

.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-left: auto;
}

</style>

<!-- Period Tabs -->
<?php if (!hasRoleId(ROLE_KASIR)): ?>
<div class="chart-tabs mb-16" style="display:inline-flex;">
    <?php foreach (['daily'=>'Harian','weekly'=>'Mingguan','monthly'=>'Bulanan','yearly'=>'Tahunan'] as $k => $v): ?>
        <a href="?period=<?= $k ?>" class="chart-tab <?= $period === $k ? 'active' : '' ?>"><?= $v ?></a>
    <?php endforeach; ?>
</div>

<!-- Date Filter -->
<div class="card mb-16">
    <div class="card-header d-flex justify-between items-center cursor-pointer" onclick="toggleFilter()" style="display: none; padding: 12px 16px;" id="mobileFilterToggle">
        <h3 class="card-title mb-0" style="font-size: 1rem; font-weight: 600;">ðŸ” Tampilkan Filter & Export</h3>
        <svg id="filterIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="card-body" id="filterBody">
        <form method="GET" class="d-flex gap-12 items-end flex-wrap">
            <input type="hidden" name="period" value="<?= $period ?>">
            <div class="form-group mb-0 cari-group">
                <label class="form-label">Cari</label>
                <input type="text" name="search" class="form-control" placeholder="No. Invoice / Pelanggan" value="<?= htmlspecialchars($search) ?>" style="width:180px;">
            </div>
            <div class="form-group mb-0 date-group">
                <label class="form-label">Dari Tanggal</label>
                <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div class="form-group mb-0 date-group">
                <label class="form-label">Sampai Tanggal</label>
                <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <div class="form-group mb-0 source-group">
                <label class="form-label">Sumber</label>
                <select name="source" class="form-control" style="width:140px;">
                    <option value="">Semua</option>
                    <option value="POS" <?= $source === 'POS' ? 'selected' : '' ?>>POS</option>
                    <option value="E-Commerce" <?= $source === 'E-Commerce' ? 'selected' : '' ?>>E-Commerce</option>
                </select>
            </div>
            <div class="form-group mb-0 filter-btn-group" style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn btn-primary w-100" style="height: 48px;">Filter</button>
            </div>
            <div class="action-buttons">
                <a href="print_sales_report.php?period=<?= $period ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&source=<?= $source ?>&search=<?= urlencode(htmlspecialchars_decode((string)$search, ENT_QUOTES)) ?>" target="_blank" class="btn btn-outline" title="Cetak/PDF Laporan" style="flex: 1; justify-content: center; text-align: center; min-width: 100px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Cetak
                </a>
                <a href="?period=<?= $period ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&source=<?= $source ?>&export=excel" class="btn btn-outline" title="Download Data Excel" style="flex: 1; justify-content: center; text-align: center; min-width: 100px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Transaksi
                </a>
                <a href="?period=<?= $period ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&source=<?= $source ?>&export=sku_excel" class="btn btn-outline" title="Download Data SKU Excel" style="flex: 1; justify-content: center; text-align: center; min-width: 100px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Produk
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="stats-grid">
    <div class="stat-card primary">
        <div class="stat-icon primary">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Penjualan</div>
            <div class="stat-value"><?= formatRupiah($summary['total_revenue']) ?></div>
            <div class="stat-change"><?= $summary['total_trx'] ?> transaksi</div>
        </div>
    </div>
    <?php if (!hasRoleId(ROLE_KASIR)): ?>
    <div class="stat-card success">
        <div class="stat-icon success">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Estimasi Profit</div>
            <div class="stat-value"><?= formatRupiah($profitData['profit']) ?></div>
        </div>
    </div>
    <?php endif; ?>
    <div class="stat-card warning">
        <div class="stat-icon warning">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Kasbon</div>
            <div class="stat-value"><?= formatRupiah($summary['total_debt']) ?></div>
        </div>
    </div>
    <div class="stat-card danger">
        <div class="stat-icon danger">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Retur / Refund</div>
            <div class="stat-value"><?= formatRupiah($refundSummary['total_retur_amount']) ?></div>
            <div class="stat-change" style="color: rgba(255,255,255,0.7);"><?= $refundSummary['total_retur_trx'] ?> transaksi</div>
        </div>
    </div>
</div>

<!-- Transaction Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Detail Transaksi</h3>
        <span class="text-sm text-muted"><?= formatTanggal($dateFrom) ?> â€” <?= formatTanggal($dateTo) ?></span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Invoice</th><th>Tanggal</th><th>Pelanggan</th><th>Kasir</th><th>Sumber</th><th>Metode Bayar</th><th>Total</th><th>Status</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">Tidak ada transaksi di periode ini</td></tr>
                    <?php endif; ?>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td data-label="Invoice"><code class="text-sm"><?= htmlspecialchars($t['invoice_number']) ?></code></td>
                            <td data-label="Tanggal" class="text-sm"><?= formatTanggal($t['created_at'], true) ?></td>
                            <td data-label="Pelanggan"><?= htmlspecialchars($t['display_customer_name']) ?></td>
                            <td data-label="Kasir" class="text-sm"><?= htmlspecialchars($t['cashier_name'] ?? '-') ?></td>
                            <td data-label="Sumber"><span class="badge <?= $t['sale_source']==='POS' ? 'badge-primary' : 'badge-info' ?>"><?= $t['sale_source'] ?></span></td>
                            <td data-label="Metode Bayar"><?= htmlspecialchars($t['payment_method'] ?? '-') ?></td>
                            <td data-label="Total" class="text-bold"><?= formatRupiah($t['grand_total']) ?></td>
                            <td data-label="Status">
                                <?php $sb = ['Paid'=>'badge-success','Debt'=>'badge-warning','Pending'=>'badge-gray','Cancelled'=>'badge-danger']; ?>
                                <?php $sl = ['Paid'=>'Lunas','Debt'=>'Kasbon','Pending'=>'Pending','Cancelled'=>'Batal']; ?>
                                <span class="badge <?= $sb[$t['status']] ?? 'badge-gray' ?>"><?= $sl[$t['status']] ?? $t['status'] ?></span>
                            </td>
                            <td data-label="Aksi">
                                <div style="display:flex; gap:4px; flex-wrap:nowrap;">
                                    <a href="<?= BASE_URL ?>/admin/print_invoice.php?id=<?= $t['id'] ?>&type=a4" target="_blank" class="btn btn-sm btn-outline" title="Cetak A4" style="padding:4px 8px;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                    </a>
                                    <a href="<?= BASE_URL ?>/admin/print_invoice.php?id=<?= $t['id'] ?>&type=thermal" target="_blank" class="btn btn-sm btn-outline" title="Cetak Thermal 58mm" style="padding:4px 8px;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    </a>
                                    
                                    <button type="button" onclick="openEditTransactionModal('<?= htmlspecialchars($t['invoice_number']) ?>')" class="btn btn-sm btn-outline" style="color:var(--warning); border-color:var(--warning); padding:4px 8px;" title="Edit Transaksi">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Yakin ingin menghapus permanen transaksi ini? Stok akan dikembalikan.')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="sale_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" style="color:var(--danger); border-color:var(--danger); padding:4px 8px;" title="Hapus">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
function toggleFilter() {
    const body = document.getElementById('filterBody');
    const icon = document.getElementById('filterIcon');
    if (body.classList.contains('show')) {
        body.classList.remove('show');
        icon.innerHTML = '<polyline points="6 9 12 15 18 9"/>';
    } else {
        body.classList.add('show');
        icon.innerHTML = '<polyline points="18 15 12 9 6 15"/>';
    }
}
</script>



<!-- Modal: Report Return Product -->
<div class="modal-overlay" id="modalReportReturn">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header" style="background:linear-gradient(135deg, #d97706, #f59e0b); color:#fff; border-radius:var(--border-radius-lg) var(--border-radius-lg) 0 0;">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                <h3 class="modal-title" style="color:#fff;">Retur Produk</h3>
            </div>
            <button class="modal-close" onclick="closeModal('modalReportReturn')" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body" style="max-height:65vh; overflow-y:auto; padding:16px;">
            <div class="form-group" style="display:flex; gap:10px;">
                <input type="text" id="reportReturnInvoiceSearch" class="form-control" placeholder="Masukkan Nomor Invoice (Misal: INV-240815...)" style="flex:1;">
                <button class="btn btn-primary" onclick="searchInvoiceForReportReturn()">Cari</button>
            </div>
            
            <div id="reportReturnLoading" style="text-align:center; padding:30px; color:var(--gray-400); display:none;">
                <div class="spinner" style="margin: 0 auto 12px;"></div>
                Mencari invoice...
            </div>
            
            <div id="reportReturnContent" style="display:none;">
                <div id="reportReturnInvoiceInfo"></div>
                
                <div id="reportReturnItemsList" style="margin-bottom:16px;"></div>
                
                <div class="form-group">
                    <label class="form-label">Alasan Retur <span class="required">*</span></label>
                    <textarea id="reportReturnReason" class="form-control" rows="2" placeholder="Barang cacat / salah ukuran / dll"></textarea>
                </div>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Jenis Pengembalian</label>
                        <select id="reportReturnType" class="form-control">
                            <option value="Refund">Refund (Uang Kembali)</option>
                            <option value="Exchange">Tukar Barang</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metode Refund</label>
                        <select id="reportReturnPaymentMethod" class="form-control">
                            <option value="Tunai">Tunai</option>
                            <option value="Transfer Bank">Transfer Bank</option>
                            <option value="Emaal">Emaal</option>
                            <option value="BSI">BSI</option>
                        </select>
                    </div>
                </div>
                
                <div style="background:var(--gray-50); padding:12px 16px; border-radius:var(--border-radius-sm); display:flex; justify-content:space-between; align-items:center; margin-top:8px; border:1px solid var(--gray-200);">
                    <span style="font-weight:600; color:var(--gray-600);">Total Refund:</span>
                    <strong id="reportReturnTotalAmount" style="font-size:1.1rem; color:var(--danger);">Rp 0</strong>
                </div>
            </div>
        </div>
        <div class="modal-footer" id="reportReturnFooter" style="display:none;">
            <button class="btn btn-outline" onclick="closeModal('modalReportReturn')">Batal</button>
            <button class="btn btn-primary" id="btnReportSubmitReturn" onclick="submitReportReturn()" style="background:var(--warning); border-color:var(--warning); color:#fff;">Proses Retur</button>
        </div>
    </div>
</div>

<script>
let currentReportReturnSaleId = null;

function openReportReturnModal(invoiceNo = '') {
    currentReportReturnSaleId = null;
    document.getElementById('reportReturnInvoiceSearch').value = invoiceNo;
    document.getElementById('reportReturnContent').style.display = 'none';
    document.getElementById('reportReturnFooter').style.display = 'none';
    document.getElementById('reportReturnLoading').style.display = 'none';
    openModal('modalReportReturn');
    
    if (invoiceNo) {
        searchInvoiceForReportReturn();
    } else {
        setTimeout(() => document.getElementById('reportReturnInvoiceSearch').focus(), 200);
    }
}

async function searchInvoiceForReportReturn() {
    const invoiceNo = document.getElementById('reportReturnInvoiceSearch').value.trim();
    if (!invoiceNo) {
        alert('Masukkan nomor invoice!');
        return;
    }
    
    document.getElementById('reportReturnContent').style.display = 'none';
    document.getElementById('reportReturnFooter').style.display = 'none';
    document.getElementById('reportReturnLoading').style.display = 'block';
    
    try {
        const res = await fetch(`../api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'search_invoice', invoice_number: invoiceNo }),
        });
        const data = await res.json();
        
        document.getElementById('reportReturnLoading').style.display = 'none';
        
        if (data.success) {
            currentReportReturnSaleId = data.data.sale.id;
            const sale = data.data.sale;
            const items = data.data.items;
            
            document.getElementById('reportReturnInvoiceInfo').innerHTML = `
                <div style="background:#f8fafc; padding:12px 16px; border-radius:8px; display:flex; gap:16px; margin-bottom:20px; align-items:center; flex-wrap:wrap; font-size:0.95rem;">
                    <div><strong>Invoice:</strong> ${sale.invoice_number}</div>
                    <div><strong>Tgl:</strong> ${new Date(sale.created_at).toLocaleDateString('id-ID')}</div>
                    <div><strong>Total Beli:</strong> Rp ${Math.round(sale.grand_total).toLocaleString('id-ID')}</div>
                </div>
            `;
            
            let html = '<div style="font-weight:600; color:var(--gray-800); margin-bottom:12px;">Pilih item yang diretur:</div>';
            
            items.forEach(item => {
                html += `
                    <div style="background:#fff; border:1px solid var(--gray-200); border-radius:8px; padding:12px 16px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div>
                            <div style="font-weight:600; color:var(--gray-800); font-size:0.95rem; margin-bottom:4px;">${item.product_name} ${item.variation_name ? `(${item.variation_name})` : ''}</div>
                            <div style="font-size:0.85rem; color:var(--gray-500);">Harga: Rp ${Math.round(item.unit_price).toLocaleString('id-ID')} | Maks Retur: ${item.qty_returnable}</div>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="number" class="form-control report-return-qty-input" min="0" max="${item.qty_returnable}" value="0" 
                                   style="width:70px; text-align:center;"
                                   data-detail-id="${item.sale_detail_id}" data-price="${item.unit_price}" 
                                   onchange="updateReportReturnTotal()" ${item.qty_returnable <= 0 ? 'disabled' : ''}>
                            <select class="form-control report-return-condition-select" style="width:110px;" data-detail-id="${item.sale_detail_id}" ${item.qty_returnable <= 0 ? 'disabled' : ''}>
                                <option value="Bagus">Bagus</option>
                                <option value="Rusak">Rusak</option>
                            </select>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('reportReturnItemsList').innerHTML = html;
            document.getElementById('reportReturnReason').value = '';
            document.getElementById('reportReturnContent').style.display = 'block';
            document.getElementById('reportReturnFooter').style.display = 'flex';
            updateReportReturnTotal();
        } else {
            alert(data.message || 'Invoice tidak ditemukan atau tidak valid untuk diretur.');
        }
    } catch (err) {
        document.getElementById('reportReturnLoading').style.display = 'none';
        console.error(err);
        alert('Terjadi kesalahan jaringan.');
    }
}

function updateReportReturnTotal() {
    const inputs = document.querySelectorAll('.report-return-qty-input');
    let total = 0;
    inputs.forEach(input => {
        const qty = parseInt(input.value) || 0;
        const price = parseFloat(input.dataset.price) || 0;
        total += qty * price;
    });
    document.getElementById('reportReturnTotalAmount').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
}

async function submitReportReturn() {
    const reason = document.getElementById('reportReturnReason').value.trim();
    if (!reason) {
        alert('Alasan retur harus diisi!');
        return;
    }
    
    const items = [];
    document.querySelectorAll('.report-return-qty-input').forEach(input => {
        const qty = parseInt(input.value) || 0;
        if (qty > 0) {
            const detailId = input.dataset.detailId;
            const cond = document.querySelector(`.report-return-condition-select[data-detail-id="${detailId}"]`).value;
            items.push({ sale_detail_id: parseInt(detailId), qty: qty, condition: cond });
        }
    });
    
    if (items.length === 0) {
        alert('Pilih minimal satu item dan masukkan jumlah yang diretur!');
        return;
    }
    
    const btn = document.getElementById('btnReportSubmitReturn');
    const oldText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    
    try {
        const res = await fetch(`../api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                action: 'create_return',
                sale_id: currentReportReturnSaleId,
                reason: reason,
                return_type: document.getElementById('reportReturnType').value,
                payment_method: document.getElementById('reportReturnPaymentMethod').value,
                items: items,
            }),
        });
        const data = await res.json();
        
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Gagal memproses retur');
            btn.disabled = false;
            btn.textContent = oldText;
        }
    } catch (err) {
        console.error(err);
        alert('Terjadi kesalahan jaringan.');
        btn.disabled = false;
        btn.textContent = oldText;
    }
}
</script>

<!-- Modal: Admin Return Product -->
<div class="modal-overlay" id="modalAdminReturn">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header" style="background:linear-gradient(135deg, #d97706, #f59e0b); color:#fff; border-radius:var(--border-radius-lg) var(--border-radius-lg) 0 0;">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                <h3 class="modal-title" style="color:#fff;" id="adminReturnTitle">Retur Produk</h3>
            </div>
            <button class="modal-close" onclick="closeModal('modalAdminReturn')" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body" style="max-height:65vh; overflow-y:auto; padding:16px;">
            <div id="adminReturnLoading" style="text-align:center; padding:30px; color:var(--gray-400); display:none;">
                <div class="spinner" style="margin: 0 auto 12px;"></div>
                Memuat data transaksi...
            </div>
            
            <div id="adminReturnContent" style="display:none;">
                <div class="return-items-header" style="font-weight:600; margin-bottom:10px; color:var(--gray-700);">Pilih item yang diretur:</div>
                <div id="adminReturnItemsList" style="display:flex; flex-direction:column; gap:10px; margin-bottom:16px;"></div>
                
                <div class="form-group">
                    <label class="form-label">Alasan Retur <span class="required">*</span></label>
                    <textarea id="adminReturnReason" class="form-control" rows="2" placeholder="Barang cacat / salah ukuran / dll"></textarea>
                </div>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Jenis Pengembalian</label>
                        <select id="adminReturnType" class="form-control">
                            <option value="Refund">Refund (Uang Kembali)</option>
                            <option value="Exchange">Tukar Barang</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metode Refund</label>
                        <select id="adminReturnPaymentMethod" class="form-control">
                            <option value="Tunai">Tunai</option>
                            <option value="Transfer Bank">Transfer Bank</option>
                            <option value="Emaal">Emaal</option>
                            <option value="BSI">BSI</option>
                        </select>
                    </div>
                </div>
                
                <div style="background:var(--gray-50); padding:12px 16px; border-radius:var(--border-radius-sm); display:flex; justify-content:space-between; align-items:center; margin-top:8px; border:1px solid var(--gray-200);">
                    <span style="font-weight:600; color:var(--gray-600);">Total Refund:</span>
                    <strong id="adminReturnTotalAmount" style="font-size:1.1rem; color:var(--danger);">Rp 0</strong>
                </div>
            </div>
        </div>
        <div class="modal-footer" id="adminReturnFooter" style="display:none;">
            <button class="btn btn-outline" onclick="closeModal('modalAdminReturn')">Batal</button>
            <button class="btn btn-primary" id="btnAdminSubmitReturn" onclick="submitAdminReturn()" style="background:var(--warning); border-color:var(--warning); color:#fff;">Proses Retur</button>
        </div>
    </div>
</div>

<style>
.return-item-row-admin { background:#fff; border:1px solid var(--gray-200); border-radius:8px; padding:12px; display:flex; flex-direction:column; gap:10px; }
@media(min-width:640px) { .return-item-row-admin { flex-direction:row; align-items:center; justify-content:space-between; } }
.return-item-info-admin { flex:1; }
.return-item-name-admin { font-weight:600; color:var(--gray-800); font-size:0.9rem; margin-bottom:4px; }
.return-item-meta-admin { font-size:0.8rem; color:var(--gray-500); }
.return-item-inputs-admin { display:flex; gap:8px; align-items:center; }
.return-qty-input-admin { width:80px !important; text-align:center; }
.return-condition-select-admin { width:140px !important; }
</style>

<script>
let currentAdminReturnSaleId = null;

async function openAdminReturnModal(saleId, invoiceNo) {
    currentAdminReturnSaleId = saleId;
    document.getElementById('adminReturnTitle').textContent = 'Retur Produk - ' + invoiceNo;
    document.getElementById('adminReturnContent').style.display = 'none';
    document.getElementById('adminReturnFooter').style.display = 'none';
    document.getElementById('adminReturnLoading').style.display = 'block';
    document.getElementById('adminReturnReason').value = '';
    
    openModal('modalAdminReturn');
    
    try {
        const res = await fetch(`${BASE_URL}/api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'search_invoice', invoice_number: invoiceNo }),
        });
        const data = await res.json();
        
        if (data.success) {
            const items = data.data.items;
            let html = '';
            
            items.forEach(item => {
                html += `
                    <div class="return-item-row-admin">
                        <div class="return-item-info-admin">
                            <div class="return-item-name-admin">${item.product_name} ${item.variation_name ? `(${item.variation_name})` : ''}</div>
                            <div class="return-item-meta-admin">Dibeli: ${item.qty} Ã— ${formatRupiah(item.unit_price)} | Sudah Retur: ${item.qty_returned}</div>
                        </div>
                        <div class="return-item-inputs-admin">
                            <div>
                                <input type="number" class="form-control return-qty-input-admin" min="0" max="${item.qty_returnable}" value="0" 
                                       data-detail-id="${item.sale_detail_id}" data-price="${item.unit_price}" 
                                       onchange="updateAdminReturnTotal()" ${item.qty_returnable <= 0 ? 'disabled' : ''}>
                                <div style="font-size:0.7rem; color:var(--gray-400); text-align:center; margin-top:2px;">Maks: ${item.qty_returnable}</div>
                            </div>
                            <select class="form-control return-condition-select-admin" data-detail-id="${item.sale_detail_id}" ${item.qty_returnable <= 0 ? 'disabled' : ''}>
                                <option value="Bagus">Bagus (Ke stok)</option>
                                <option value="Rusak">Rusak (Buang)</option>
                            </select>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('adminReturnItemsList').innerHTML = html;
            document.getElementById('adminReturnLoading').style.display = 'none';
            document.getElementById('adminReturnContent').style.display = 'block';
            document.getElementById('adminReturnFooter').style.display = 'flex';
            updateAdminReturnTotal();
        } else {
            alert(data.message || 'Gagal memuat data transaksi');
            closeModal('modalAdminReturn');
        }
    } catch (err) {
        console.error(err);
        alert('Terjadi kesalahan jaringan.');
        closeModal('modalAdminReturn');
    }
}

function updateAdminReturnTotal() {
    const inputs = document.querySelectorAll('.return-qty-input-admin');
    let total = 0;
    inputs.forEach(input => {
        const qty = parseInt(input.value) || 0;
        const price = parseFloat(input.dataset.price) || 0;
        total += qty * price;
    });
    document.getElementById('adminReturnTotalAmount').textContent = formatRupiah(total);
}

async function submitAdminReturn() {
    const reason = document.getElementById('adminReturnReason').value.trim();
    if (!reason) {
        alert('Alasan retur harus diisi!');
        return;
    }
    
    const items = [];
    document.querySelectorAll('.return-qty-input-admin').forEach(input => {
        const qty = parseInt(input.value) || 0;
        if (qty > 0) {
            const detailId = input.dataset.detailId;
            const cond = document.querySelector(`.return-condition-select-admin[data-detail-id="${detailId}"]`).value;
            items.push({ sale_detail_id: parseInt(detailId), qty: qty, condition: cond });
        }
    });
    
    if (items.length === 0) {
        alert('Pilih minimal satu item dan masukkan jumlah yang diretur!');
        return;
    }
    
    const btn = document.getElementById('btnAdminSubmitReturn');
    const oldText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    
    try {
        const res = await fetch(`${BASE_URL}/api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                action: 'create_return',
                sale_id: currentAdminReturnSaleId,
                reason: reason,
                return_type: document.getElementById('adminReturnType').value,
                payment_method: document.getElementById('adminReturnPaymentMethod').value,
                items: items,
            }),
        });
        const data = await res.json();
        
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Gagal memproses retur');
            btn.disabled = false;
            btn.textContent = oldText;
        }
    } catch (err) {
        console.error(err);
        alert('Terjadi kesalahan jaringan.');
        btn.disabled = false;
        btn.textContent = oldText;
    }
}
</script>


<!-- MODAL EDIT TRANSAKSI -->
<div id="modalEditTransaction" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto; padding:20px;">
    <div class="modal-content" style="background:#fff; max-width:700px; margin:20px auto; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <div class="modal-header" style="padding:16px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1.25rem;">Edit Transaksi (Retur & Tambah)</h3>
            <button type="button" onclick="document.getElementById('modalEditTransaction').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding:16px;">
            <div id="editTxLoading" style="display:none; text-align:center; padding:20px;">Memuat transaksi...</div>
            <div id="editTxContent" style="display:none;">
                <div id="editTxInfo" style="background:var(--gray-50); padding:12px; border-radius:8px; margin-bottom:16px; font-size:0.9rem;"></div>
                
                <h4 style="margin-bottom:8px; color:var(--danger); font-size:1rem;">Retur Produk (Kurangi)</h4>
                <div id="editTxReturnItems" style="margin-bottom:16px;"></div>
                
                <h4 style="margin:18px 0 8px; color:var(--success); font-size:1rem;">Tambah Produk</h4>
                <div style="display:flex; gap:8px; margin-bottom:8px;">
                    <input id="editTxProductSearch" class="form-control" placeholder="Cari nama produk atau SKU" oninput="editTxSearchProducts()">
                </div>
                <div id="editTxProductResults" style="display:flex; flex-direction:column; gap:6px; margin-bottom:16px;"></div>
                <div id="editTxAddedItems"></div>
                
                <label class="form-label" style="margin-top:16px;">Catatan edit</label>
                <textarea id="editTxReason" class="form-control" rows="2" placeholder="Alasan retur / tambahan produk"></textarea>
                
                <div id="editTxSummary" style="margin-top:16px; border-top:1px dashed var(--gray-300); padding-top:12px; font-size:1.1rem;"></div>
            </div>
        </div>
        <div class="modal-footer" id="editTxFooter" style="padding:16px; border-top:1px solid #ddd; display:none; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('modalEditTransaction').style.display='none'">Batal</button>
            <button type="button" class="btn btn-primary" id="btnEditTxSubmit" onclick="editTxSubmit()">Simpan Edit Transaksi</button>
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';
let editTxSale = null;
let editTxSaleItems = [];
let editTxAddedItems = [];

async function openEditTransactionModal(invoice) {
    document.getElementById('modalEditTransaction').style.display = 'block';
    document.getElementById('editTxLoading').style.display = 'block';
    document.getElementById('editTxContent').style.display = 'none';
    document.getElementById('editTxFooter').style.display = 'none';
    document.getElementById('editTxProductSearch').value = '';
    document.getElementById('editTxProductResults').innerHTML = '';
    document.getElementById('editTxReason').value = '';
    
    try {
        const res = await fetch(BASE_URL + '/api/sales.php', {
            method:'POST', 
            headers:{'Content-Type':'application/json'}, 
            body:JSON.stringify({action:'search_invoice', invoice_number:invoice})
        });
        const result = await res.json();
        
        if (!result.success) {
            alert(result.message || 'Invoice tidak ditemukan.');
            document.getElementById('modalEditTransaction').style.display = 'none';
            return;
        }
        
        editTxSale = result.data.sale;
        editTxSaleItems = result.data.items;
        editTxAddedItems = [];
        
        document.getElementById('editTxInfo').innerHTML = '<strong>' + editTxSale.invoice_number + '</strong> &middot; ' + editTxSale.display_customer_name + ' &middot; Total awal: <strong>' + formatRupiah(editTxSale.grand_total) + '</strong>';
        
        editTxRenderReturns(); 
        editTxRenderAdded(); 
        editTxUpdateSummary();
        
        document.getElementById('editTxLoading').style.display = 'none';
        document.getElementById('editTxContent').style.display = 'block';
        document.getElementById('editTxFooter').style.display = 'flex';
        
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
        document.getElementById('modalEditTransaction').style.display = 'none';
    }
}

function editTxRenderReturns() {
    document.getElementById('editTxReturnItems').innerHTML = editTxSaleItems.map(item => `
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 0; border-bottom:1px solid var(--gray-200);">
            <span style="flex:1;">
                ${item.product_name} ${item.variation_name ? '- ' + item.variation_name : ''}
                <small style="display:block; color:var(--gray-500);">${item.qty} x ${formatRupiah(item.unit_price)} (tersisa ${item.qty_returnable})</small>
            </span>
            <input type="number" class="editTxReturnQty form-control" style="width:80px;" min="0" max="${item.qty_returnable}" value="0" data-id="${item.sale_detail_id}" data-price="${item.unit_price}" onchange="editTxUpdateSummary()">
        </div>
    `).join('');
}

async function editTxSearchProducts() {
    const query = document.getElementById('editTxProductSearch').value.trim();
    if (query.length < 2) { document.getElementById('editTxProductResults').innerHTML = ''; return; }
    
    const res = await fetch(BASE_URL + '/api/products.php?action=search&q=' + encodeURIComponent(query) + '&limit=10&price_type=Umum');
    const result = await res.json();
    
    document.getElementById('editTxProductResults').innerHTML = (result.data || []).map(item => `
        <button type="button" class="btn btn-outline" style="text-align:left;" onclick="editTxAddProduct(${item.variation_id}, '${item.product_name.replace(/'/g,"\\'").replace(/"/g,'&quot;')}', '${(item.variation_name || '').replace(/'/g,"\\'").replace(/"/g,'&quot;')}', ${item.selling_price})">
            ${item.product_name} ${item.variation_name ? '- ' + item.variation_name : ''} 
            <span style="float:right; font-weight:bold;">${formatRupiah(item.selling_price)}</span>
        </button>
    `).join('');
}

function editTxAddProduct(id, name, variation, price) {
    const existing = editTxAddedItems.find(item => item.variation_id === id);
    if (existing) existing.qty++;
    else editTxAddedItems.push({variation_id:id, product_name:name, variation_name:variation, unit_price:Number(price), qty:1});
    
    document.getElementById('editTxProductSearch').value = ''; 
    document.getElementById('editTxProductResults').innerHTML = ''; 
    editTxRenderAdded(); 
    editTxUpdateSummary();
}

function editTxRenderAdded() {
    document.getElementById('editTxAddedItems').innerHTML = editTxAddedItems.map((item,index) => `
        <div style="display:flex; align-items:center; gap:8px; padding:8px; background:var(--gray-50); margin-bottom:6px; border-radius:6px; border:1px solid var(--gray-200);">
            <span style="flex:1;">
                ${item.product_name} ${item.variation_name ? '- ' + item.variation_name : ''}
                <small style="display:block; color:var(--gray-500);">${formatRupiah(item.unit_price)}</small>
            </span>
            <input type="number" min="1" class="form-control" style="width:80px;" value="${item.qty}" onchange="editTxAddedItems[${index}].qty=Math.max(1,parseInt(this.value)||1); editTxUpdateSummary()">
            <button type="button" class="btn btn-sm btn-outline" style="color:var(--danger); border-color:var(--danger);" onclick="editTxAddedItems.splice(${index},1); editTxRenderAdded(); editTxUpdateSummary();">Hapus</button>
        </div>
    `).join('');
}

function editTxUpdateSummary() {
    let returned = 0, added = 0;
    document.querySelectorAll('.editTxReturnQty').forEach(input => returned += (parseInt(input.value)||0) * Number(input.dataset.price));
    editTxAddedItems.forEach(item => added += item.qty * item.unit_price);
    
    document.getElementById('editTxSummary').innerHTML = `
        <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Total Tambahan</span><strong>${formatRupiah(added)}</strong></div>
        <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Total Retur</span><strong style="color:var(--danger);">- ${formatRupiah(returned)}</strong></div>
        <div style="display:flex; justify-content:space-between; margin-top:8px; font-weight:800; font-size:1.2rem; border-top:1px solid #ccc; padding-top:8px;">
            <span>Total Selisih</span><strong>${formatRupiah(added-returned)}</strong>
        </div>
    `;
}

async function editTxSubmit() {
    if (!editTxSale) return;
    
    const returnItems = Array.from(document.querySelectorAll('.editTxReturnQty')).filter(input => Number(input.value) > 0).map(input => ({sale_detail_id:Number(input.dataset.id), qty:Number(input.value)}));
    if (!returnItems.length && !editTxAddedItems.length) return alert('Tambahkan produk atau pilih produk untuk diretur.');
    
    const btn = document.getElementById('btnEditTxSubmit');
    const oldText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Menyimpan...';
    
    try {
        const res = await fetch(BASE_URL + '/api/sales.php', {
            method:'POST', 
            headers:{'Content-Type':'application/json'}, 
            body:JSON.stringify({
                action:'edit_sale', 
                sale_id:editTxSale.id, 
                reason:document.getElementById('editTxReason').value || 'Edit transaksi', 
                return_items:returnItems, 
                add_items:editTxAddedItems
            })
        });
        const result = await res.json();
        
        if (!result.success) {
            alert(result.message || 'Gagal menyimpan edit transaksi.');
            btn.disabled = false;
            btn.textContent = oldText;
            return;
        }
        
        alert('Transaksi berhasil diedit. Total selisih: ' + formatRupiah(result.data.total_difference));
        
        // Open print popup and reload page
        window.open(BASE_URL + '/admin/print_invoice.php?id=' + editTxSale.id + '&type=thermal', '_blank');
        window.location.reload();
        
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
        btn.disabled = false;
        btn.textContent = oldText;
    }
}
</script>
<?php include INCLUDES_PATH . '/footer.php'; ?>

