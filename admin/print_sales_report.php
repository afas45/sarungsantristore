<?php
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);

$db = Database::conn();

// Filter Logic sama dengan sales_report.php
$period = $_GET['period'] ?? 'daily';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$source = $_GET['source'] ?? '';
$search = sanitize($_GET['search'] ?? '');

if (hasRoleId(ROLE_KASIR)) {
    $period = 'daily';
    $dateFrom = date('Y-m-d');
    $dateTo = date('Y-m-d');
    $source = 'POS';
} else {
    if ($period === 'weekly') {
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        $dateTo = date('Y-m-d');
    } elseif ($period === 'monthly') {
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
    } elseif ($period === 'yearly') {
        $dateFrom = date('Y-01-01');
        $dateTo = date('Y-12-31');
    }
}

$where = "WHERE 1=1";
$params = [];

if ($dateFrom && $dateTo) {
    $where .= " AND DATE(s.created_at) BETWEEN ? AND ?";
    $params[] = $dateFrom;
    $params[] = $dateTo;
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
    $where .= " AND (s.invoice_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Summary Query
$stmt = $db->prepare("SELECT COUNT(*) as total_trx, COALESCE(SUM(grand_total),0) as total_revenue, COALESCE(SUM(CASE WHEN status='Debt' THEN grand_total ELSE 0 END),0) as total_debt FROM sales s LEFT JOIN customers c ON s.customer_id = c.id $where");
$stmt->execute($params);
$summary = $stmt->fetch();

// Detail Transactions
$stmt = $db->prepare("
    SELECT s.*, c.name as customer_name, u.full_name as cashier_name,
    (SELECT payment_method FROM payments WHERE sale_id = s.id ORDER BY id ASC LIMIT 1) as payment_method 
    FROM sales s 
    LEFT JOIN customers c ON s.customer_id = c.id 
    LEFT JOIN users u ON s.cashier_id = u.id 
    $where 
    ORDER BY s.created_at ASC 
");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$storeName = getSetting('store_name');
$storeAddress = getSetting('store_address');
$storePhone = getSetting('store_phone');

// Formatter tanggal cetak
$printPeriod = "";
if ($dateFrom === $dateTo) {
    $printPeriod = date('d M Y', strtotime($dateFrom));
} else {
    $printPeriod = date('d M Y', strtotime($dateFrom)) . " - " . date('d M Y', strtotime($dateTo));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan - <?= htmlspecialchars($printPeriod) ?></title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 13px;
            color: #333;
            background: #f3f4f6;
            margin: 0;
            padding: 20px;
        }
        .report-box {
            max-width: 900px;
            margin: auto;
            background: #fff;
            padding: 40px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid #1A512E;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header-left {
            text-align: left;
        }
        .header-right {
            text-align: right;
        }
        .header h1 {
            margin: 0 0 5px;
            font-size: 24px;
            color: #1A512E;
        }
        .header p { margin: 2px 0; color: #555; }
        .header h2 {
            margin: 0 0 5px;
            font-size: 22px;
            color: #333;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .summary-box {
            border: 1px solid #C6DC93;
            background: #f0f7f2;
            padding: 8px;
            text-align: center;
            border-radius: 6px;
        }
        .summary-label {
            font-size: 12px;
            color: #589D62;
            text-transform: uppercase;
            font-weight: bold;
        }
        .summary-value {
            font-size: 20px;
            color: #1A512E;
            font-weight: bold;
            margin-top: 8px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td {
            border: 1px solid #ddd;
            padding: 4px 6px;
            font-size: 11px;
        }
        th {
            background-color: #f0f7f2;
            color: #1A512E;
            font-weight: bold;
            text-align: left;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        
        .footer {
            margin-top: 40px;
            text-align: right;
            font-size: 12px;
            color: #555;
        }

        @media print {
            body {
                background: none;
                padding: 0;
            }
            .report-box {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="report-box">
        <div class="header">
            <div class="header-left">
                <h1><?= htmlspecialchars($storeName) ?></h1>
                <p><?= nl2br(htmlspecialchars($storeAddress)) ?></p>
                <p>Telp: <?= htmlspecialchars($storePhone) ?></p>
            </div>
            <div class="header-right">
                <h2>LAPORAN PENJUALAN</h2>
                <p>Periode: <?= htmlspecialchars($printPeriod) ?></p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="5%" class="text-center">No</th>
                    <th width="15%">Tanggal</th>
                    <th width="15%">No. Invoice</th>
                    <th width="20%">Pelanggan</th>
                    <th width="10%" class="text-center">Sumber</th>
                    <th width="10%" class="text-center">Metode</th>
                    <th width="10%" class="text-center">Status</th>
                    <th width="15%" class="text-right">Total (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $index => $trx): ?>
                    <tr>
                        <td class="text-center"><?= $index + 1 ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($trx['created_at'])) ?></td>
                        <td><?= htmlspecialchars($trx['invoice_number']) ?></td>
                        <td><?= htmlspecialchars($trx['customer_name'] ?? 'Pelanggan Umum') ?></td>
                        <td class="text-center"><?= htmlspecialchars($trx['sale_source']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($trx['payment_method'] ?? '-') ?></td>
                        <td class="text-center"><?= $trx['status'] === 'Paid' ? 'Lunas' : 'Kredit' ?></td>
                        <td class="text-right"><?= number_format($trx['grand_total'], 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($transactions)): ?>
                    <tr><td colspan="8" class="text-center">Tidak ada transaksi pada periode ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="summary-grid">
            <div class="summary-box">
                <div class="summary-label">Total Transaksi</div>
                <div class="summary-value"><?= number_format($summary['total_trx'], 0, ',', '.') ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Total Pendapatan</div>
                <div class="summary-value">Rp <?= number_format($summary['total_revenue'], 0, ',', '.') ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Total Piutang (Kredit)</div>
                <div class="summary-value">Rp <?= number_format($summary['total_debt'], 0, ',', '.') ?></div>
            </div>
        </div>

        <div class="footer">
            <p>Dicetak oleh: <?= htmlspecialchars($_SESSION['full_name']) ?></p>
            <p>Tanggal Cetak: <?= date('d M Y, H:i') ?></p>
        </div>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
