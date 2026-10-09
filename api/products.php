<?php
/**
 * Kasir Ibtidaiyah - API Produk
 * Endpoint AJAX untuk POS & E-commerce
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();
$method = $_SERVER['REQUEST_METHOD'];

// GET: Search / List products
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'search';
    
    if ($action === 'search') {
        $q = sanitize($_GET['q'] ?? '');
        $category = (int)($_GET['category'] ?? 0);
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        
        $where = "WHERE p.is_active = 1 AND pv.is_active = 1";
        $params = [];
        
        if ($q) {
            $where .= " AND (p.name LIKE ? OR p.sku LIKE ? OR pv.sku LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($category) {
            $stmtSub = $db->prepare("SELECT id FROM categories WHERE parent_id = ?");
            $stmtSub->execute([$category]);
            $subIds = $stmtSub->fetchAll(PDO::FETCH_COLUMN);
            $subIds[] = $category;
            $inPlaceholders = str_repeat('?,', count($subIds) - 1) . '?';
            $where .= " AND p.category_id IN ($inPlaceholders)";
            foreach ($subIds as $sId) {
                $params[] = $sId;
            }
        }
        
        $stmt = $db->prepare("
            SELECT 
                p.id as product_id,
                p.name as product_name,
                p.sku as product_sku,
                p.image,
                c.name as category_name,
                pv.id as variation_id,
                pv.sku as variation_sku,
                pv.variation_name,
                pv.image as variation_image,
                pv.stock_qty,
                pv.base_price
            FROM products p
            JOIN product_variations pv ON pv.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            $where
            ORDER BY p.name, pv.variation_name
            LIMIT $limit
        ");
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        // Check if cashier is allowed to view stock count
        $showStockVal = !isLoggedIn() || !isCashier() || hasPermission('action_view_stock');

        // Attach tiered prices
        $priceType = sanitize($_GET['price_type'] ?? 'Umum');
        foreach ($products as &$prod) {
            $prod['is_out_of_stock'] = $prod['stock_qty'] <= 0;
            if (!$showStockVal) {
                $prod['stock_qty'] = null;
            }
            $stmt2 = $db->prepare("SELECT pp.label, pt.name as price_type, pp.min_qty, pp.selling_price FROM product_prices pp JOIN price_types pt ON pp.price_type_id = pt.id WHERE pp.product_id = ? ORDER BY pt.name, pp.min_qty");
            $stmt2->execute([$prod['product_id']]);
            $prod['tiers'] = $stmt2->fetchAll();
            
            // Default selling price based on requested price_type
            $prod['selling_price'] = $prod['base_price'];
            foreach ($prod['tiers'] as $t) {
                if ($t['min_qty'] == 1 && $t['price_type'] === $priceType) {
                    $prod['selling_price'] = $t['selling_price'];
                    break;
                }
            }
            // Fallback to 'Eceran' or 'Umum' if no price_type match
            if ($prod['selling_price'] == $prod['base_price']) {
                foreach ($prod['tiers'] as $t) {
                    if ($t['min_qty'] == 1 && ($t['price_type'] === 'Eceran' || $t['price_type'] === 'Umum')) {
                        $prod['selling_price'] = $t['selling_price'];
                        break;
                    }
                }
            }
        }
        unset($prod);
        
        jsonResponse(['success' => true, 'data' => $products]);
    }
    
    if ($action === 'categories') {
        $stmt = $db->query("
            SELECT c.id, c.name, c.parent_id, p.name as parent_name 
            FROM categories c 
            LEFT JOIN categories p ON c.parent_id = p.id
            ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.sort_order ASC, c.name ASC
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }
    
    if ($action === 'customers') {
        $q = sanitize($_GET['q'] ?? '');
        $stmt = $db->prepare("
            SELECT c.id, c.name, c.phone, c.credit_limit, pt.name as price_type_name 
            FROM customers c 
            LEFT JOIN price_types pt ON c.price_type_id = pt.id 
            WHERE c.name LIKE ? OR c.phone LIKE ? 
            ORDER BY c.name LIMIT 20
        ");
        $stmt->execute(["%$q%", "%$q%"]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
