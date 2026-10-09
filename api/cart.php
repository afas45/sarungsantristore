<?php
require_once '../config/database.php';
require_once '../config/app.php';
session_start();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

try {
    $db = Database::getInstance()->getConnection();

    if ($method === 'GET' && $action === 'get_cart') {
        echo json_encode($_SESSION['cart']);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        $action = $input['action'] ?? '';
        
        if ($action === 'add') {
            $product_id = (int)$input['product_id'];
            $variation_id = isset($input['variation_id']) ? (int)$input['variation_id'] : null;
            $qty = isset($input['qty']) ? (int)$input['qty'] : 1;
            
            // Get product info
            $stmt = $db->prepare("SELECT name, image_url FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            
            if (!$product) {
                throw new Exception("Product not found");
            }
            
            $price = 0;
            $variation_name = '';
            
            if ($variation_id) {
                $stmt = $db->prepare("SELECT variation_name, price FROM product_variations WHERE id = ?");
                $stmt->execute([$variation_id]);
                $var = $stmt->fetch();
                if ($var) {
                    $variation_name = $var['variation_name'];
                    $price = $var['price'];
                }
            } else {
                // If no variation, get default variation price
                $stmt = $db->prepare("SELECT id, price FROM product_variations WHERE product_id = ? ORDER BY id ASC LIMIT 1");
                $stmt->execute([$product_id]);
                $var = $stmt->fetch();
                if ($var) {
                    $variation_id = $var['id'];
                    $price = $var['price'];
                }
            }
            
            $cart_item_id = $variation_id; // Use variation id as unique identifier
            
            if (isset($_SESSION['cart'][$cart_item_id])) {
                $_SESSION['cart'][$cart_item_id]['qty'] += $qty;
            } else {
                $_SESSION['cart'][$cart_item_id] = [
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'name' => $product['name'] . ($variation_name ? " - $variation_name" : ""),
                    'price' => $price,
                    'qty' => $qty,
                    'image' => $product['image_url']
                ];
            }
            
            echo json_encode(['success' => true, 'cart_count' => array_sum(array_column($_SESSION['cart'], 'qty'))]);
            exit;
        }
        
        if ($action === 'update') {
            $cart_item_id = (int)$input['id'];
            $qty = (int)$input['qty'];
            
            if ($qty > 0) {
                $_SESSION['cart'][$cart_item_id]['qty'] = $qty;
            } else {
                unset($_SESSION['cart'][$cart_item_id]);
            }
            
            echo json_encode(['success' => true, 'cart' => $_SESSION['cart']]);
            exit;
        }
        
        if ($action === 'remove') {
            $cart_item_id = (int)$input['id'];
            unset($_SESSION['cart'][$cart_item_id]);
            echo json_encode(['success' => true, 'cart' => $_SESSION['cart']]);
            exit;
        }
    }

    throw new Exception("Invalid request");

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
