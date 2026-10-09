<?php
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$id = (int)($_GET['id'] ?? 0);
$isDownload = isset($_GET['download']) && $_GET['download'] == 1;

$db = Database::conn();

// Get Opname Data
$stmt = $db->prepare("
    SELECT so.*, u.full_name as user_name 
    FROM stock_opnames so 
    JOIN users u ON so.user_id = u.id 
    WHERE so.id = ?
");
$stmt->execute([$id]);
$opname = $stmt->fetch();

if (!$opname) {
    die("Data opname tidak ditemukan.");
}

// Get Details
$stmt = $db->prepare("
    SELECT sod.*, pv.variation_name, pv.sku, p.name as product_name
    FROM stock_opname_details sod
    JOIN product_variations pv ON sod.product_variation_id = pv.id
    JOIN products p ON pv.product_id = p.id
    WHERE sod.opname_id = ?
    ORDER BY p.name ASC
");
$stmt->execute([$id]);
$details = $stmt->fetchAll();

$storeName = getSetting('store_name');
$storeAddress = getSetting('store_address');
$storePhone = getSetting('store_phone');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Stock Opname - <?= htmlspecialchars($opname['opname_number']) ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 14px;
            color: #333;
            background: #e5e7eb;
            margin: 0;
            padding: 20px;
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            background: #fff;
            padding: 40px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header-left h1 {
            margin: 0 0 10px;
            font-size: 24px;
            color: #111;
        }
        .header-left p { margin: 2px 0; }
        .header-right { text-align: right; }
        .header-right h2 {
            margin: 0 0 10px;
            color: #4b5563;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-box p { margin: 4px 0; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #f3f4f6;
            font-weight: bold;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .diff-pos { color: #10b981; font-weight: bold; }
        .diff-neg { color: #ef4444; font-weight: bold; }
        
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }

        @media print {
            body {
                background: none;
                padding: 0;
            }
            .invoice-box {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-box">
        <div class="header">
            <div class="header-left">
                <h1><?= htmlspecialchars($storeName) ?></h1>
                <p><?= nl2br(htmlspecialchars($storeAddress)) ?></p>
                <p>Telp: <?= htmlspecialchars($storePhone) ?></p>
            </div>
            <div class="header-right">
                <h2>LAPORAN STOCK OPNAME</h2>
                <p><strong>Status:</strong> <?= $opname['status'] === 'Completed' ? 'Selesai' : 'Pending' ?></p>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-box">
                <p><strong>No. Opname:</strong> <?= htmlspecialchars($opname['opname_number']) ?></p>
                <p><strong>Petugas:</strong> <?= htmlspecialchars($opname['user_name']) ?></p>
                <p><strong>Tanggal:</strong> <?= date('d M Y, H:i', strtotime($opname['created_at'])) ?></p>
            </div>
            <div class="info-box">
                <p><strong>Catatan:</strong></p>
                <p><?= nl2br(htmlspecialchars($opname['notes'] ?: '-')) ?></p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="text-center" width="5%">No</th>
                    <th width="15%">SKU</th>
                    <th width="35%">Nama Produk</th>
                    <th class="text-center" width="15%">Stok Sistem</th>
                    <th class="text-center" width="15%">Stok Fisik</th>
                    <th class="text-center" width="15%">Selisih</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($details as $index => $item): ?>
                    <tr>
                        <td class="text-center"><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($item['sku']) ?></td>
                        <td>
                            <?= htmlspecialchars($item['product_name']) ?>
                            <?= $item['variation_name'] ? ' - ' . htmlspecialchars($item['variation_name']) : '' ?>
                        </td>
                        <td class="text-center"><?= $item['system_stock'] ?></td>
                        <td class="text-center"><?= $item['physical_stock'] ?></td>
                        <td class="text-center">
                            <?php 
                                $diff = $item['difference'];
                                if ($diff > 0) echo "<span class='diff-pos'>+$diff</span>";
                                elseif ($diff < 0) echo "<span class='diff-neg'>$diff</span>";
                                else echo "-";
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($details)): ?>
                    <tr><td colspan="6" class="text-center">Tidak ada detail item pada opname ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer">
            <p>Dicetak pada <?= date('d M Y, H:i') ?></p>
        </div>
    </div>

    <script>
        <?php if ($isDownload): ?>
            window.onload = function() {
                window.print();
            }
        <?php endif; ?>
    </script>
</body>
</html>
