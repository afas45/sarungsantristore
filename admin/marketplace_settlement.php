<?php
/**
 * Kasir Ibtidaiyah - Setoran Marketplace (Shopee & TikTok)
 * Laporan rekap bulanan untuk transaksi dari marketplace.
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);
if (!hasPermission('menu_setoran')) {
    die("Akses ditolak.");
}

$db = Database::conn();
$pageTitle = 'Setoran Marketplace';
$breadcrumbs = [['label' => 'Setoran Marketplace']];

$selectedMonth = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$selectedYear = isset($_GET['year']) ? $_GET['year'] : date('Y');

$startDate = "$selectedYear-$selectedMonth-01 00:00:00";
$endDate = date('Y-m-t 23:59:59', strtotime($startDate));

// Ambil total per metode (Shopee / TikTok)
$stmt = $db->prepare("
    SELECT payment_method, SUM(amount) as total_amount
    FROM payments
    WHERE payment_type = 'Incoming'
      AND payment_method IN ('Shopee', 'TikTok')
      AND payment_date BETWEEN ? AND ?
    GROUP BY payment_method
");
$stmt->execute([$startDate, $endDate]);
$platformTotals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$totalShopee = $platformTotals['Shopee'] ?? 0;
$totalTikTok = $platformTotals['TikTok'] ?? 0;
$grandTotal = $totalShopee + $totalTikTok;

// Ambil detail transaksi
$stmt = $db->prepare("
    SELECT p.*, s.invoice_number, u.full_name as cashier_name
    FROM payments p
    LEFT JOIN sales s ON p.sale_id = s.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.payment_type = 'Incoming'
      AND p.payment_method IN ('Shopee', 'TikTok')
      AND p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date DESC
");
$stmt->execute([$startDate, $endDate]);
$transactions = $stmt->fetchAll();

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h1 class="page-title">Setoran Marketplace</h1>
        <p class="text-muted">Rekap transaksi bulanan khusus metode pembayaran Shopee dan TikTok.</p>
    </div>
    <form method="GET" style="display:flex; gap:8px;">
        <select name="month" class="form-control" style="width: auto;">
            <?php for ($i = 1; $i <= 12; $i++): ?>
                <?php $m = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                <option value="<?= $m ?>" <?= $selectedMonth == $m ? 'selected' : '' ?>>
                    <?= date('F', mktime(0, 0, 0, $i, 10)) ?>
                </option>
            <?php endfor; ?>
        </select>
        <select name="year" class="form-control" style="width: auto;">
            <?php for ($i = date('Y'); $i >= date('Y') - 3; $i--): ?>
                <option value="<?= $i ?>" <?= $selectedYear == $i ? 'selected' : '' ?>><?= $i ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>
</div>

<div class="stats-grid" style="margin-top:20px;">
    <div class="stat-card" style="border-top: 4px solid var(--primary-600);">
        <div class="stat-title">Total Shopee</div>
        <div class="stat-value" style="color: #5c3a21;"><?= formatRupiah($totalShopee) ?></div>
    </div>
    <div class="stat-card" style="border-top: 4px solid var(--gray-800);">
        <div class="stat-title">Total TikTok</div>
        <div class="stat-value" style="color: #5c3a21;"><?= formatRupiah($totalTikTok) ?></div>
    </div>
    <div class="stat-card" style="border-top: 4px solid var(--success);">
        <div class="stat-title">Grand Total Bulan Ini</div>
        <div class="stat-value" style="color:var(--success);"><?= formatRupiah($grandTotal) ?></div>
    </div>
</div>

<div class="card" style="margin-top:24px;">
    <div class="card-header">
        <h3 class="card-title">Rincian Transaksi Marketplace</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>No Invoice</th>
                        <th>Metode</th>
                        <th>Nominal</th>
                        <th>Kasir</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="5" class="text-center text-muted" style="padding:40px;">Belum ada transaksi bulan ini</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td><?= formatTanggal($t['payment_date'], true) ?></td>
                                <td>
                                    <?php if ($t['invoice_number']): ?>
                                        <a href="print_invoice.php?id=<?= $t['sale_id'] ?>" target="_blank"><?= htmlspecialchars($t['invoice_number']) ?></a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($t['payment_method'] === 'Shopee'): ?>
                                        <span class="badge" style="background:#ee4d2d; color:#fff;">Shopee</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#000; color:#fff;">TikTok</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-bold"><?= formatRupiah($t['amount']) ?></td>
                                <td><?= htmlspecialchars($t['cashier_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
