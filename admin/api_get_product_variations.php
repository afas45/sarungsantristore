<?php
/**
 * Kasir Ibtidaiyah - API Get Product Variations
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
header('Content-Type: application/json');

$productId = (int)($_GET['product_id'] ?? 0);

if (!$productId) {
    echo json_encode(['error' => 'Product ID is required']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT id, sku, variation_name, stock_qty 
        FROM product_variations 
        WHERE product_id = ? AND is_active = 1 
        ORDER BY variation_name
    ");
    $stmt->execute([$productId]);
    $variations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status' => 'success', 'data' => $variations]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
