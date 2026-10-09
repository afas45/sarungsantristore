<?php
/**
 * Kasir Ibtidaiyah - Dashboard Admin
 * Ringkasan operasional: penjualan, produk, piutang, dll.
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Dashboard';

// =============================================
// QUERY DATA RINGKASAN
// =============================================

// Total penjualan hari ini
$stmt = $db->query("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE DATE(created_at) = CURDATE() AND status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')");
$todaySales = $stmt->fetchColumn();

// Total penjualan bulan ini
$stmt = $db->query("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')");
$monthSales = $stmt->fetchColumn();

// Total barang terjual bulan ini
$stmt = $db->query("
    SELECT COALESCE(SUM(sd.qty), 0) FROM sale_details sd 
    JOIN sales s ON sd.sale_id = s.id 
    WHERE MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE()) 
    AND s.status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')
");
$monthItemsSold = $stmt->fetchColumn();

// Estimasi profit bulan ini (selling price - base price)
$stmt = $db->query("
    SELECT COALESCE(SUM(sd.qty * (sd.unit_price - COALESCE(pv.base_price, 0))), 0) 
    FROM sale_details sd 
    JOIN sales s ON sd.sale_id = s.id 
    JOIN product_variations pv ON sd.product_variation_id = pv.id
    WHERE MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE()) 
    AND s.status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')
");
$monthProfit = $stmt->fetchColumn();

// Total produk aktif
$stmt = $db->query("SELECT COUNT(*) FROM products WHERE is_active = 1");
$totalProducts = $stmt->fetchColumn();

// Total pelanggan
$stmt = $db->query("SELECT COUNT(*) FROM customers");
$totalCustomers = $stmt->fetchColumn();

// Total transaksi POS hari ini
$stmt = $db->query("SELECT COUNT(*) FROM sales WHERE sale_source = 'POS' AND DATE(created_at) = CURDATE() AND status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')");
$todayPosTransactions = $stmt->fetchColumn();

// Total transaksi Online hari ini
$stmt = $db->query("SELECT COUNT(*) FROM sales WHERE sale_source IN ('E-Commerce', 'Online') AND DATE(created_at) = CURDATE() AND status NOT IN ('Cancelled', 'Pending')");
$todayOnlineTransactions = $stmt->fetchColumn();

// Transaksi hari ini
$stmt = $db->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at) = CURDATE() AND status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')");
$todayTransactions = $stmt->fetchColumn();

// Piutang jatuh tempo / lewat jatuh tempo
$stmt = $db->query("SELECT COUNT(*) FROM receivables WHERE due_date <= CURDATE() AND status != 'Paid'");
$overdueReceivables = $stmt->fetchColumn();

// Stok menipis (Total stok SKU Induk < 3)
$stmt = $db->query("
    SELECT COUNT(*) FROM (
        SELECT p.id, COALESCE(SUM(pv.stock_qty), 0) as total_stock 
        FROM products p 
        LEFT JOIN product_variations pv ON p.id = pv.product_id 
        WHERE p.is_active = 1 
        GROUP BY p.id 
        HAVING total_stock < 3
    ) AS low_stock_products
");
$lowStockCount = $stmt->fetchColumn();

// Transaksi POS bulan ini
$stmt = $db->query("SELECT COUNT(*) FROM sales WHERE sale_source = 'POS' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')");
$monthPosTransactions = $stmt->fetchColumn();

// Transaksi Online bulan ini
$stmt = $db->query("SELECT COUNT(*) FROM sales WHERE sale_source IN ('E-Commerce', 'Online') AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status NOT IN ('Cancelled', 'Pending')");
$monthOnlineTransactions = $stmt->fetchColumn();

// Total pembelian bulan ini
$stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != 'Cancelled'");
$monthPurchases = $stmt->fetchColumn();

// Total seluruh barang terjual
$stmt = $db->query("
    SELECT COALESCE(SUM(sd.qty), 0) FROM sale_details sd 
    JOIN sales s ON sd.sale_id = s.id 
    WHERE s.status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')
");
$allTimeItemsSold = $stmt->fetchColumn();

// =============================================
// DATA GRAFIK PENJUALAN
// =============================================
$chartRange = isset($_GET['range']) && $_GET['range'] == '30' ? 30 : 7;
$chartData = [];
for ($i = $chartRange - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $db->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE DATE(created_at) = ? AND status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')");
    $stmt->execute([$date]);
    $chartData[] = [
        'date' => date('d/m', strtotime($date)),
        'day' => $chartRange == 30 ? date('d/m', strtotime($date)) : ['Min','Sen','Sel','Rab','Kam','Jum','Sab'][date('w', strtotime($date))],
        'total' => (float)$stmt->fetchColumn()
    ];
}

// =============================================
// TRANSAKSI TERBARU
// =============================================
$stmt = $db->query("
    SELECT s.*, COALESCE(c.name, s.customer_name, 'Umum') as display_customer_name 
    FROM sales s 
    LEFT JOIN customers c ON s.customer_id = c.id 
    ORDER BY s.created_at DESC 
    LIMIT 8
");
$recentTransactions = $stmt->fetchAll();

// =============================================
// PRODUK TERLARIS BULAN INI
// =============================================
$stmt = $db->query("
    SELECT sd.product_name, SUM(sd.qty) as total_qty, SUM(sd.qty * sd.unit_price) as revenue
    FROM sale_details sd
    JOIN sales s ON sd.sale_id = s.id
    WHERE MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE())
    AND s.status IN ('Paid','Debt','Confirmed','Processing','Ready','Shipped','Completed')
    GROUP BY sd.product_name
    ORDER BY total_qty DESC
    LIMIT 5
");
$topProducts = $stmt->fetchAll();

// Extra JS for Chart.js
$extraJs = ['dashboard.js'];

include INCLUDES_PATH . '/header.php';
?>


<!-- Stat Cards Row 1 -->
<div class="stats-grid">
    <div class="stat-card primary">
        <div class="stat-icon primary">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Penjualan Hari Ini</div>
            <div class="stat-value"><?= formatRupiah($todaySales) ?></div>
            <div class="stat-change">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                <?= $todayTransactions ?> transaksi
            </div>
        </div>
    </div>
    
    <div class="stat-card gold">
        <div class="stat-icon gold">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Penjualan Bulan Ini</div>
            <div class="stat-value"><?= formatRupiah($monthSales) ?></div>
            <div class="stat-change">
                <?= formatNumber($monthItemsSold) ?> barang terjual
            </div>
        </div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-icon success">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Estimasi Profit Bulan Ini</div>
            <div class="stat-value"><?= formatRupiah($monthProfit) ?></div>
            <div class="stat-change up">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
                Selisih harga jual - modal
            </div>
        </div>
    </div>
    
    <div class="stat-card info">
        <div class="stat-icon info">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Pelanggan</div>
            <div class="stat-value"><?= formatNumber($totalCustomers) ?></div>
            <div class="stat-change"><?= formatNumber($totalProducts) ?> produk</div>
        </div>
    </div>
</div>

<!-- Stat Cards Row 2: Transaksi Hari Ini & Bulanan -->
<div class="stats-grid" style="margin-bottom: 20px;">
    <style>
        @media (max-width: 768px) {
            .center-mobile {
                flex-direction: column;
                text-align: center;
                justify-content: center;
            }
            .center-mobile .stat-icon {
                margin: 0 auto 12px auto;
            }
            .center-mobile .stat-info {
                text-align: center;
                display: flex;
                flex-direction: column;
                align-items: center;
            }
            .center-mobile .stat-value {
                justify-content: center !important;
            }
        }
    </style>
    <div class="stat-card danger center-mobile">
        <div class="stat-icon danger">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Transaksi POS</div>
            <div class="stat-value" style="display:flex; align-items:baseline; gap:6px;">
                <span style="font-size:1.75rem; font-weight:800; line-height:1;"><?= formatNumber($todayPosTransactions) ?></span>
                <span style="font-size:0.9rem; color:var(--gray-500); font-weight:500;">/ <?= formatNumber($monthPosTransactions) ?></span>
            </div>
            <div class="stat-change">Hari ini <span style="color:var(--gray-400)">/</span> Bulan ini</div>
        </div>
    </div>
    
    <div class="stat-card warning center-mobile">
        <div class="stat-icon warning">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Transaksi Online</div>
            <div class="stat-value" style="display:flex; align-items:baseline; gap:6px;">
                <span style="font-size:1.75rem; font-weight:800; line-height:1;"><?= formatNumber($todayOnlineTransactions) ?></span>
                <span style="font-size:0.9rem; color:var(--gray-500); font-weight:500;">/ <?= formatNumber($monthOnlineTransactions) ?></span>
            </div>
            <div class="stat-change">Hari ini <span style="color:var(--gray-400)">/</span> Bulan ini</div>
        </div>
    </div>
    
    <div class="stat-card primary-dark">
        <div class="stat-icon primary-dark">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Pembelian</div>
            <div class="stat-value"><?= formatRupiah($monthPurchases) ?></div>
            <div class="stat-change">Bulan ini</div>
        </div>
    </div>
    
    <div class="stat-card gold-dark">
        <div class="stat-icon gold-dark">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Barang Terjual</div>
            <div class="stat-value"><?= formatNumber($allTimeItemsSold) ?></div>
            <div class="stat-change">Seluruh Waktu</div>
        </div>
    </div>
</div>

<!-- Dashboard Grid: Chart + Sidebar -->
<div class="dashboard-grid">
    <!-- Chart Penjualan -->
    <div class="card">
        <div class="card-header">
            <div class="chart-header">
                <h3 class="card-title">Grafik Penjualan</h3>
                <div class="chart-tabs">
                    <a href="?range=7" class="chart-tab <?= $chartRange == 7 ? 'active' : '' ?>" style="text-decoration:none;">7 Hari</a>
                    <a href="?range=30" class="chart-tab <?= $chartRange == 30 ? 'active' : '' ?>" style="text-decoration:none;">30 Hari</a>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <div class="chart-inner">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Sidebar Widgets -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <!-- Alert Widget -->
        <?php if ($overdueReceivables > 0 || $lowStockCount > 0): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">⚠️ Peringatan</h3>
            </div>
            <div class="card-body">
                <div class="alert-widget">
                    <?php if ($overdueReceivables > 0): ?>
                    <a href="<?= BASE_URL ?>/admin/receivables.php" class="alert-widget-item overdue" style="text-decoration:none; color:inherit;">
                        <span class="alert-widget-count" style="color: var(--danger);"><?= $overdueReceivables ?></span>
                        <span class="alert-widget-text">Piutang jatuh tempo</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($lowStockCount > 0): ?>
                    <a href="<?= BASE_URL ?>/admin/products.php" class="alert-widget-item warning" style="text-decoration:none; color:inherit;">
                        <span class="alert-widget-count" style="color: var(--warning);"><?= $lowStockCount ?></span>
                        <span class="alert-widget-text">Stok hampir habis</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Produk Terlaris -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🏆 Produk Terlaris</h3>
                <span class="text-xs text-muted">Bulan ini</span>
            </div>
            <div class="card-body" style="padding-top: 8px;">
                <?php if (empty($topProducts)): ?>
                    <p class="text-sm text-muted text-center" style="padding: 20px 0;">Belum ada data penjualan</p>
                <?php else: ?>
                    <?php foreach ($topProducts as $idx => $tp): ?>
                        <div class="top-product-item">
                            <div class="top-product-rank <?= $idx < 3 ? 'rank-' . ($idx + 1) : 'rank-default' ?>">
                                <?= $idx + 1 ?>
                            </div>
                            <div class="top-product-info">
                                <div class="top-product-name"><?= htmlspecialchars($tp['product_name']) ?></div>
                                <div class="top-product-qty"><?= formatNumber($tp['total_qty']) ?> terjual</div>
                            </div>
                            <div class="top-product-revenue"><?= formatRupiah($tp['revenue']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- Transaksi Terbaru -->
    <div class="card" style="grid-column: 1 / -1;">
    <div class="card-header">
        <h3 class="card-title">📋 Transaksi Terbaru</h3>
        <a href="<?= BASE_URL ?>/admin/sales_report.php" class="btn btn-sm btn-outline">Lihat Semua</a>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($recentTransactions)): ?>
            <div class="empty-state" style="padding: 40px;">
                <div class="empty-state-icon">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                </div>
                <div class="empty-state-title">Belum Ada Transaksi</div>
                <div class="empty-state-text">Mulai buat transaksi pertama dari POS</div>
                <a href="<?= BASE_URL ?>/pos/index.php" class="btn btn-primary">Buka POS Kasir</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Pelanggan</th>
                            <th>Sumber</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTransactions as $trx): ?>
                            <tr>
                                <td><span class="font-mono text-sm"><?= htmlspecialchars($trx['invoice_number']) ?></span></td>
                                <td><?= htmlspecialchars($trx['display_customer_name']) ?></td>
                                <td>
                                    <span class="badge <?= $trx['sale_source'] === 'POS' ? 'badge-primary' : 'badge-info' ?>">
                                        <?= $trx['sale_source'] ?>
                                    </span>
                                </td>
                                <td class="text-bold"><?= formatRupiah($trx['grand_total']) ?></td>
                                <td>
                                    <?php
                                    $statusBadge = [
                                        'Paid' => 'badge-success',
                                        'Debt' => 'badge-warning',
                                        'Pending' => 'badge-gray',
                                        'Cancelled' => 'badge-danger',
                                    ];
                                    $statusLabel = [
                                        'Paid' => 'Lunas',
                                        'Debt' => 'Kasbon',
                                        'Pending' => 'Pending',
                                        'Cancelled' => 'Batal',
                                    ];
                                    ?>
                                    <span class="badge <?= $statusBadge[$trx['status']] ?? 'badge-gray' ?>">
                                        <?= $statusLabel[$trx['status']] ?? $trx['status'] ?>
                                    </span>
                                </td>
                                <td class="text-sm text-muted"><?= timeAgo($trx['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Chart.js Data -->
<script>
    var chartLabels = <?= json_encode(array_column($chartData, 'day')) ?>;
    var chartValues = <?= json_encode(array_column($chartData, 'total')) ?>;
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
