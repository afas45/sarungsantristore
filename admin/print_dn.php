<?php
/**
 * Kasir Ibtidaiyah - Cetak Surat Jalan
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$dnId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT dn.*, p.po_number, s.name as supplier_name, s.address as supplier_address, s.phone as supplier_phone, u.full_name as receiver_name
    FROM delivery_notes dn
    JOIN purchases p ON dn.purchase_id = p.id
    JOIN suppliers s ON p.supplier_id = s.id
    JOIN users u ON dn.receiver_id = u.id
    WHERE dn.id = ?
");
$stmt->execute([$dnId]);
$dn = $stmt->fetch();

if (!$dn) {
    die("Surat Jalan tidak ditemukan.");
}

if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8', false);
    }
}


$stmt = $db->prepare("
    SELECT dni.*, pr.name as product_name, pr.sku
    FROM delivery_note_items dni
    JOIN products pr ON dni.product_id = pr.id
    WHERE dni.delivery_note_id = ?
");
$stmt->execute([$dnId]);
$items = $stmt->fetchAll();

$storeName = getSetting('store_name', APP_NAME);
$storeAddress = getSetting('store_address', 'Jl. Pendidikan No. 1');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Jalan <?= h($dn['surat_jalan_number']) ?></title>
    <style>
        body { background: #f0f2f5; padding: 20px; font-family: 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif; color: #333; }
        .page { max-width: 800px; margin: auto; background: #fff; padding: 30px; border: 1px solid #ccc; font-size: 14px; line-height: 24px; }
        .header { display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .title { font-size: 24px; font-weight: bold; text-transform: uppercase; }
        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th, .table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .table th { background: #eee; font-weight: bold; text-align: center; }
        .table td:nth-child(1), .table td:nth-child(4) { text-align: center; }
        .signature { margin-top: 50px; display: flex; justify-content: space-between; text-align: center; }
        .sig-box { width: 200px; }
        .sig-name { margin-top: 70px; border-bottom: 1px solid #333; padding-bottom: 5px; font-weight: bold; }
        @media print { body { background: none; padding: 0; } .page { border: none; padding: 0; } }
    </style>
</head>
<body onload="window.print()">
    <div class="page">
        <div class="header">
            <div>
                <strong>DARI / PENGIRIM:</strong><br>
                <?= h($dn['supplier_name']) ?><br>
                <?= h($dn['supplier_address']) ?><br>
                Telp: <?= h($dn['supplier_phone']) ?>
            </div>
            <div style="text-align:right;">
                <div class="title">SURAT JALAN</div>
                No. SJ: <strong><?= h($dn['surat_jalan_number']) ?></strong><br>
                No. PO: <?= h($dn['po_number']) ?><br>
                Tanggal: <?= date('d/m/Y', strtotime($dn['received_date'])) ?>
            </div>
        </div>
        
        <div>
            <strong>KEPADA / PENERIMA:</strong><br>
            <?= h($storeName) ?><br>
            <?= nl2br(h($storeAddress)) ?><br>
            Penerima: <?= h($dn['receiver_name']) ?>
        </div>
        
        <table class="table">
            <thead>
                <tr>
                    <th width="5%">No</th>
                    <th width="25%">SKU</th>
                    <th width="55%">Nama Barang</th>
                    <th width="15%">Qty Diterima</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= h($item['sku']) ?></td>
                    <td>
                        <?= h($item['product_name']) ?>
                    </td>
                    <td><?= $item['qty_received'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($dn['notes']): ?>
        <div style="margin-top: 20px;">
            <strong>Catatan:</strong><br>
            <?= nl2br(h($dn['notes'])) ?>
        </div>
        <?php endif; ?>
        
        <div class="signature">
            <div class="sig-box">
                <div>Penerima</div>
                <div class="sig-name"><?= h($dn['receiver_name']) ?></div>
            </div>
            <div class="sig-box">
                <div>Pengirim / Supplier</div>
                <div class="sig-name">( ___________________ )</div>
            </div>
        </div>
    </div>
</body>
</html>
