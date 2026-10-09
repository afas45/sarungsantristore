<?php
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID tidak ditemukan']);
    exit;
}

$id = (int)$_GET['id'];
$db = Database::conn();

$stmt = $db->prepare("SELECT * FROM purchases WHERE id = ?");
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    http_response_code(404);
    echo json_encode(['error' => 'PO tidak ditemukan']);
    exit;
}

$stmt = $db->prepare("SELECT pd.*, p.name as product_name, p.sku FROM purchase_details pd JOIN products p ON pd.product_id = p.id WHERE pd.purchase_id = ?");
$stmt->execute([$id]);
$details = $stmt->fetchAll();

echo json_encode([
    'purchase' => $purchase,
    'details' => $details
]);
