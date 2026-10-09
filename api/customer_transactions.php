<?php
/**
 * Kasir Ibtidaiyah - API Transaksi Pelanggan
 * Mengambil detail transaksi untuk ditampilkan di modal pelanggan
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$customerId = (int)($_GET['customer_id'] ?? 0);

if (!$customerId) {
    jsonResponse(['success' => false, 'message' => 'Customer ID tidak valid.']);
}

$stmt = $db->prepare("
    SELECT id, invoice_number, total_amount, grand_total, status, sale_source, created_at 
    FROM sales 
    WHERE customer_id = ? 
    ORDER BY created_at DESC 
    LIMIT 50
");
$stmt->execute([$customerId]);
$sales = $stmt->fetchAll();

foreach ($sales as &$sale) {
    $sale['date'] = date('d/m/Y H:i', strtotime($sale['created_at']));
    $sale['total'] = formatRupiah($sale['grand_total']);
}

jsonResponse(['success' => true, 'data' => $sales]);
