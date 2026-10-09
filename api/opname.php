<?php
/**
 * Kasir Ibtidaiyah - API Opname Produk
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();

// GET: List opname history / detail
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        $stmt = $db->query("
            SELECT so.*, u.full_name as user_name,
                   (SELECT COUNT(*) FROM stock_opname_details WHERE opname_id = so.id) as item_count,
                   (SELECT COUNT(*) FROM stock_opname_details WHERE opname_id = so.id AND difference != 0) as diff_count
            FROM stock_opnames so
            JOIN users u ON so.user_id = u.id
            ORDER BY so.created_at DESC
            LIMIT 50
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }
    
    if ($action === 'detail') {
        $opnameId = (int)($_GET['id'] ?? 0);
        
        $stmt = $db->prepare("
            SELECT sod.*, pv.sku, pv.variation_name, p.name as product_name
            FROM stock_opname_details sod
            JOIN product_variations pv ON sod.product_variation_id = pv.id
            JOIN products p ON pv.product_id = p.id
            WHERE sod.opname_id = ?
            ORDER BY p.name
        ");
        $stmt->execute([$opnameId]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }
}

// POST: Create opname via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) jsonResponse(['success' => false, 'message' => 'Data tidak valid.'], 400);
    
    $action = $input['action'] ?? '';
    
    if ($action === 'create') {
        try {
            $db->beginTransaction();
            
            $items = $input['items'] ?? [];
            $notes = $input['notes'] ?? '';
            $userId = $_SESSION['user_id'];
            
            if (empty($items)) {
                throw new Exception('Tidak ada data produk.');
            }
            
            $opnameNumber = generateOpnameNumber();
            
            $stmt = $db->prepare("INSERT INTO stock_opnames (opname_number, user_id, notes, status) VALUES (?, ?, ?, 'Completed')");
            $stmt->execute([$opnameNumber, $userId, $notes]);
            $opnameId = $db->lastInsertId();
            
            $adjustmentCount = 0;
            
            foreach ($items as $item) {
                $varId = (int)$item['variation_id'];
                $physicalStock = (int)$item['physical_stock'];
                
                $stmt = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ? FOR UPDATE");
                $stmt->execute([$varId]);
                $systemStock = (int)$stmt->fetchColumn();
                
                $stmt = $db->prepare("INSERT INTO stock_opname_details (opname_id, product_variation_id, system_stock, physical_stock) VALUES (?, ?, ?, ?)");
                $stmt->execute([$opnameId, $varId, $systemStock, $physicalStock]);
                
                if ($physicalStock !== $systemStock) {
                    $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")->execute([$physicalStock, $varId]);
                    $adjustmentCount++;
                }
            }
            
            $db->commit();
            logActivity('Opname', 'Stock', "Opname: $opnameNumber, Adjusted: $adjustmentCount");
            
            jsonResponse(['success' => true, 'message' => "Opname berhasil. $adjustmentCount item disesuaikan.", 'data' => [
                'opname_number' => $opnameNumber,
                'adjusted' => $adjustmentCount,
            ]]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
