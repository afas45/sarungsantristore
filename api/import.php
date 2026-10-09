<?php
/**
 * Kasir Ibtidaiyah - Import & Export CSV API
 */
require_once __DIR__ . '/../config/app.php';

// Check roles for import actions
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ==========================================
// 1. DOWNLOAD TEMPLATES
// ==========================================
if ($action === 'download_template') {
    $type = $_GET['type'] ?? '';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=template_' . $type . '_' . date('Ymd') . '.csv');
    
    $output = fopen('php://output', 'w');
    // Tambahkan BOM untuk kompabilitas Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    switch ($type) {
        case 'categories':
            fputcsv($output, ['ID', 'Nama Kategori', 'Urutan', 'Tampil Online (1/0)']);
            fputcsv($output, ['1', 'Alat Tulis', '1', '1']);
            break;
            
        case 'users':
            fputcsv($output, ['Username', 'Nama Lengkap', 'Email', 'No Telepon', 'Role', 'Password']);
            fputcsv($output, ['kasirbaru', 'Nama Kasir', 'kasir@toko.com', '0812345678', 'Kasir', '123456']);
            break;
            
        case 'customers':
            fputcsv($output, ['Nama', 'Telepon', 'Email', 'Alamat', 'Tipe Harga']);
            fputcsv($output, ['Budi Santoso', '08123456789', 'budi@example.com', 'Jl. Merdeka No 1', 'Pelanggan Setia']);
            break;
            
        case 'suppliers':
            fputcsv($output, ['Nama', 'Kontak Person', 'Telepon', 'Email', 'Alamat']);
            fputcsv($output, ['PT. Distribusi Maju', 'Pak Andi', '0811223344', 'admin@maju.com', 'Kawasan Industri']);
            break;
            
        case 'products':
            fputcsv($output, ['SKU Induk', 'Nama Produk', 'Kategori', 'Stok', 'Modal', 'Harga', 'Berat (g)', 'Online', 'Deskripsi']);
            fputcsv($output, ['PRD-001', 'Buku Tulis Sinar Dunia 38', 'Alat Tulis', '100', '3000', '4000', '500', '1', 'Buku tulis isi 38 lembar']);
            break;
            
        case 'purchases':
            fputcsv($output, ['No Faktur (PO)', 'Tanggal (YYYY-MM-DD)', 'Nama Supplier', 'SKU Produk', 'Qty', 'Harga Beli Satuan']);
            fputcsv($output, ['PO-INV-001', date('Y-m-d'), 'PT. Distribusi Maju', 'PRD-001', '50', '3000']);
            break;
            
        case 'opname':
            // Khusus opname, kita download data produk yang ada untuk diisi fisiknya
            $categoryId = (int)($_GET['category'] ?? 0);
            fputcsv($output, ['SKU', 'Nama Produk', 'Stok Sistem', 'Stok Fisik (Isi Disini)']);
            
            $where = "WHERE p.is_active = 1 AND pv.is_active = 1";
            $params = [];
            if ($categoryId > 0) {
                $where .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }
            $stmt = $db->prepare("
                SELECT pv.sku, p.name as product_name, pv.variation_name, pv.stock_qty
                FROM product_variations pv
                JOIN products p ON pv.product_id = p.id
                $where
                ORDER BY p.name
            ");
            $stmt->execute($params);
            
            while ($row = $stmt->fetch()) {
                $name = $row['product_name'] . ($row['variation_name'] ? ' - ' . $row['variation_name'] : '');
                fputcsv($output, [$row['sku'], $name, $row['stock_qty'], '']);
            }
            break;
            
        case 'variations':
            fputcsv($output, ['SKU Varian', 'Nama Varian', 'Stok', 'Tipe Harga', 'Qty Minimal', 'Harga Jual']);
            fputcsv($output, ['VAR-001', 'Ukuran M', '50', 'Umum', '1', '60000']);
            fputcsv($output, ['VAR-001', 'Ukuran M', '50', 'Ranting', '1', '55000']);
            break;
            
        default:
            fputcsv($output, ['Invalid template type']);
    }
    
    fclose($output);
    exit;
}

// ==========================================
// 2. UPLOAD CSV
// ==========================================
if ($action === 'upload_csv') {
    $type = $_POST['type'] ?? '';
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        flashMessage('error', 'Gagal mengunggah file.');
        redirect($_SERVER['HTTP_REFERER']);
    }
    
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle) {
        flashMessage('error', 'Tidak dapat membaca file.');
        redirect($_SERVER['HTTP_REFERER']);
    }
    
    // Baca BOM jika ada
    $bom = fread($handle, 3);
    if ($bom !== b"\xEF\xBB\xBF") {
        rewind($handle);
    }
    
    // Header
    $header = fgetcsv($handle, 1000, ",");
    // Deteksi delimiter (koma atau titik koma)
    if (count($header) === 1 && strpos($header[0], ';') !== false) {
        rewind($handle);
        if ($bom === b"\xEF\xBB\xBF") fread($handle, 3); // skip bom again
        $header = fgetcsv($handle, 1000, ";");
        $delimiter = ';';
    } else {
        $delimiter = ',';
    }
    
    $successCount = 0;
    $skipCount = 0;
    $errorCount = 0;
    
    try {
        $db->beginTransaction();
        
        switch ($type) {
            case 'categories':
                $checkDupStmt = $db->prepare("SELECT id FROM categories WHERE id = ? OR sort_order = ?");
                $insertStmt = $db->prepare("INSERT INTO categories (id, name, sort_order, online_visibility) VALUES (?, ?, ?, ?)");
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (count($data) < 2 || empty(trim($data[0]))) continue;
                    if (strtolower(trim($data[0])) === 'id') continue;
                    
                    $id = (int)trim($data[0]);
                    $name = trim($data[1]);
                    $sort_order = isset($data[2]) && trim($data[2]) !== '' ? (int)trim($data[2]) : 0;
                    $online_vis = isset($data[3]) && trim($data[3]) !== '' ? (int)trim($data[3]) : 1;
                    
                    $checkDupStmt->execute([$id, $sort_order]);
                    if ($checkDupStmt->fetch()) {
                        // Tolak jika ID atau urutan sama
                        $errorCount++;
                    } else {
                        if ($insertStmt->execute([$id, $name, $sort_order, $online_vis])) {
                            $successCount++;
                        } else {
                            $errorCount++;
                        }
                    }
                }
                break;
                
            case 'users':
                $roleStmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
                $checkUserStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $insertUserStmt = $db->prepare("INSERT INTO users (role_id, username, password_hash, full_name, email, phone, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (count($data) < 1 || empty(trim($data[0]))) continue;
                    if (strtolower(trim($data[0])) === 'username') continue; // Header
                    
                    $username = trim($data[0]);
                    $fullName = trim($data[1] ?? '');
                    $email = trim($data[2] ?? '');
                    $phone = trim($data[3] ?? '');
                    $roleName = trim($data[4] ?? 'Kasir');
                    $password = trim($data[5] ?? '123456');
                    
                    if (empty($username) || empty($fullName)) {
                        $skipCount++;
                        continue;
                    }
                    
                    // Tolak jika username sama
                    $checkUserStmt->execute([$username]);
                    if ($checkUserStmt->fetchColumn() > 0) {
                        $errorCount++;
                        continue;
                    }
                    
                    $roleStmt->execute([$roleName]);
                    $roleId = $roleStmt->fetchColumn();
                    if (!$roleId) {
                        // Fallback ke Kasir jika role tidak valid
                        $roleStmt->execute(['Kasir']);
                        $roleId = $roleStmt->fetchColumn();
                    }
                    
                    if ($insertUserStmt->execute([$roleId, $username, hashPassword($password), $fullName, $email, $phone])) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                }
                break;
                
            case 'customers':
                $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address, price_type_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), address=VALUES(address), price_type_id=VALUES(price_type_id)");
                
                // Get or insert price type
                $stmtPT = $db->prepare("SELECT id FROM price_types WHERE name = ?");
                $stmtPTIns = $db->prepare("INSERT INTO price_types (name) VALUES (?)");
                
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (count($data) < 1 || empty(trim($data[0]))) continue;
                    if (strtolower(trim($data[0])) === 'nama') continue; // Skip header fallback
                    $name = trim($data[0]);
                    $phone = trim($data[1] ?? '');
                    $email = trim($data[2] ?? '');
                    $address = trim($data[3] ?? '');
                    $priceType = trim($data[4] ?? 'Umum');
                    
                    if (empty($priceType)) $priceType = 'Umum';
                    
                    $stmtPT->execute([$priceType]);
                    $priceTypeId = $stmtPT->fetchColumn();
                    if (!$priceTypeId) {
                        $stmtPTIns->execute([$priceType]);
                        $priceTypeId = $db->lastInsertId();
                    }
                    
                    if ($stmt->execute([$name, $phone, $email, $address, $priceTypeId])) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                }
                break;
                
            case 'suppliers':
                $stmt = $db->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE contact_person=VALUES(contact_person), email=VALUES(email), address=VALUES(address)");
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (count($data) < 1 || empty(trim($data[0]))) continue;
                    if (strtolower(trim($data[0])) === 'nama') continue;
                    $name = trim($data[0]);
                    $contact = trim($data[1] ?? '');
                    $phone = trim($data[2] ?? '');
                    $email = trim($data[3] ?? '');
                    $address = trim($data[4] ?? '');
                    
                    if ($stmt->execute([$name, $contact, $phone, $email, $address])) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                }
                break;
                
            case 'products':
                // Prepare statements
                $catStmt = $db->prepare("SELECT id FROM categories WHERE name = ?");
                $catInsert = $db->prepare("INSERT INTO categories (name) VALUES (?)");
                $prodCheck = $db->prepare("SELECT id FROM products WHERE sku = ?");
                $prodInsert = $db->prepare("INSERT INTO products (category_id, sku, name, weight, description, is_active, base_price, online_visibility) VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
                $prodUpdate = $db->prepare("UPDATE products SET category_id=?, name=?, weight=?, description=?, base_price=?, online_visibility=? WHERE id=?");
                
                $varCheck = $db->prepare("SELECT id FROM product_variations WHERE product_id = ? AND sku = ?");
                $varInsert = $db->prepare("INSERT INTO product_variations (product_id, sku, variation_name, stock_qty, base_price, is_active) VALUES (?, ?, NULL, ?, ?, 1)");
                $varUpdate = $db->prepare("UPDATE product_variations SET stock_qty=?, base_price=? WHERE id=?");

                // Get or insert price type 'Umum'
                $stmtPT = $db->prepare("SELECT id FROM price_types WHERE name = 'Umum'");
                $stmtPT->execute();
                $umumPriceTypeId = $stmtPT->fetchColumn();
                if (!$umumPriceTypeId) {
                    $db->exec("INSERT INTO price_types (name) VALUES ('Umum')");
                    $umumPriceTypeId = $db->lastInsertId();
                }

                $prodPriceCheck = $db->prepare("SELECT id FROM product_prices WHERE product_id = ? AND label = 'Eceran' AND min_qty = 1 AND price_type_id = ?");
                $prodPriceInsert = $db->prepare("INSERT INTO product_prices (product_id, label, price_type_id, min_qty, selling_price) VALUES (?, 'Eceran', ?, 1, ?)");
                $prodPriceUpdate = $db->prepare("UPDATE product_prices SET selling_price=? WHERE id=?");
                
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (count($data) < 5 || empty(trim($data[0]))) continue;
                    if (strtolower(trim($data[0])) === 'sku induk' || strtolower(trim($data[0])) === 'sku') continue;
                    
                    $sku = trim($data[0]);
                    $name = trim($data[1]);
                    $catName = trim($data[2] ?? '');
                    $stock = (int)str_replace(',', '', $data[3] ?? '0');
                    $basePrice = (float)str_replace(',', '', $data[4] ?? '0');
                    $sellingPrice = (float)str_replace(',', '', $data[5] ?? '0');
                    $weight = (int)str_replace(',', '', $data[6] ?? '0');
                    $online = trim($data[7] ?? '1') === '0' ? 0 : 1;
                    $description = trim($data[8] ?? '');
                    
                    // Handle category
                    $categoryId = null;
                    if (!empty($catName)) {
                        $catStmt->execute([$catName]);
                        $categoryId = $catStmt->fetchColumn();
                        if (!$categoryId) {
                            $catInsert->execute([$catName]);
                            $categoryId = $db->lastInsertId();
                        }
                    }
                    
                    // Check if Product SKU exists
                    $prodCheck->execute([$sku]);
                    $productId = $prodCheck->fetchColumn();
                    if ($productId) {
                        // UPDATE Product
                        $prodUpdate->execute([$categoryId, $name, $weight, $description, $basePrice, $online, $productId]);
                    } else {
                        // INSERT Product
                        $prodInsert->execute([$categoryId, $sku, $name, $weight, $description, $basePrice, $online]);
                        $productId = $db->lastInsertId();
                    }
                    
                    // Check Variation
                    $varCheck->execute([$productId, $sku]);
                    $varId = $varCheck->fetchColumn();
                    if ($varId) {
                        $varUpdate->execute([$stock, $basePrice, $varId]);
                    } else {
                        $varInsert->execute([$productId, $sku, $stock, $basePrice]);
                        $varId = $db->lastInsertId();
                    }
                    
                    // Check Product Price
                    $prodPriceCheck->execute([$productId, $umumPriceTypeId]);
                    $priceId = $prodPriceCheck->fetchColumn();
                    if ($priceId) {
                        $prodPriceUpdate->execute([$sellingPrice, $priceId]);
                    } else {
                        $prodPriceInsert->execute([$productId, $umumPriceTypeId, $sellingPrice]);
                    }
                    
                    $successCount++;
                }
                break;
                
            case 'purchases':
                // We need to group by PO Number
                $purchases = [];
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (count($data) < 6 || empty(trim($data[0]))) continue;
                    if (strtolower(trim($data[0])) === 'no faktur (po)') continue;
                    
                    $po = trim($data[0]);
                    $date = trim($data[1]);
                    $supplierName = trim($data[2]);
                    $sku = trim($data[3]);
                    $qty = (int)str_replace(',', '', $data[4] ?? '0');
                    $cost = (float)str_replace(',', '', $data[5] ?? '0');
                    
                    if (!isset($purchases[$po])) {
                        $purchases[$po] = [
                            'date' => $date,
                            'supplier' => $supplierName,
                            'items' => []
                        ];
                    }
                    $purchases[$po]['items'][] = ['sku' => $sku, 'qty' => $qty, 'cost' => $cost];
                }
                
                // Process Purchases
                $supStmt = $db->prepare("SELECT id FROM suppliers WHERE name = ?");
                $supInsert = $db->prepare("INSERT INTO suppliers (name) VALUES (?)");
                $skuStmt = $db->prepare("SELECT id FROM product_variations WHERE sku = ?");
                
                foreach ($purchases as $po => $poData) {
                    // Check if PO exists
                    $chk = $db->prepare("SELECT id FROM purchases WHERE po_number = ?");
                    $chk->execute([$po]);
                    if ($chk->fetch()) {
                        $skipCount++;
                        continue;
                    }
                    
                    // Get Supplier
                    $supStmt->execute([$poData['supplier']]);
                    $supplierId = $supStmt->fetchColumn();
                    if (!$supplierId) {
                        $supInsert->execute([$poData['supplier']]);
                        $supplierId = $db->lastInsertId();
                    }
                    
                    $total = 0;
                    $validItems = [];
                    foreach ($poData['items'] as $item) {
                        $skuStmt->execute([$item['sku']]);
                        $varId = $skuStmt->fetchColumn();
                        if ($varId) {
                            $sub = $item['qty'] * $item['cost'];
                            $total += $sub;
                            $validItems[] = [
                                'var_id' => $varId,
                                'qty' => $item['qty'],
                                'cost' => $item['cost']
                            ];
                        }
                    }
                    
                    if (empty($validItems)) {
                        $errorCount++;
                        continue;
                    }
                    
                    $adminId = $_SESSION['user_id'];
                    $date = !empty($poData['date']) ? $poData['date'] : date('Y-m-d');
                    
                    // Insert Purchase
                    $db->prepare("INSERT INTO purchases (supplier_id, admin_id, po_number, date, status, total_amount) VALUES (?, ?, ?, ?, 'Completed', ?)")
                       ->execute([$supplierId, $adminId, $po, $date, $total]);
                    $purchaseId = $db->lastInsertId();
                    
                    foreach ($validItems as $item) {
                        $db->prepare("INSERT INTO purchase_details (purchase_id, product_variation_id, qty, unit_cost) VALUES (?, ?, ?, ?)")
                           ->execute([$purchaseId, $item['var_id'], $item['qty'], $item['cost']]);
                        
                        // Update stock & base price
                        $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ?, base_price = ? WHERE id = ?")
                           ->execute([$item['qty'], $item['cost'], $item['var_id']]);
                    }
                    $successCount++;
                }
                break;
                
            case 'opname':
                $opnameNumber = generateOpnameNumber();
                $userId = $_SESSION['user_id'];
                
                // Create Opname Header
                $db->prepare("INSERT INTO stock_opnames (opname_number, user_id, notes, status) VALUES (?, ?, 'Diimpor dari CSV', 'Completed')")
                   ->execute([$opnameNumber, $userId]);
                $opnameId = $db->lastInsertId();
                
                $skuStmt = $db->prepare("SELECT id, stock_qty FROM product_variations WHERE sku = ?");
                
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (count($data) < 4 || empty(trim($data[0]))) continue; // Skip header/empty
                    if (strtolower(trim($data[0])) === 'sku') continue; // Skip header
                    
                    $sku = trim($data[0]);
                    $phys = trim($data[3]);
                    if ($phys === '') continue; // No physical stock input
                    
                    $phys = (int)$phys;
                    
                    $skuStmt->execute([$sku]);
                    $var = $skuStmt->fetch();
                    if ($var) {
                        $sys = (int)$var['stock_qty'];
                        if ($sys !== $phys) {
                            $db->prepare("INSERT INTO stock_opname_details (opname_id, product_variation_id, system_stock, physical_stock) VALUES (?, ?, ?, ?)")
                               ->execute([$opnameId, $var['id'], $sys, $phys]);
                               
                            $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")
                               ->execute([$phys, $var['id']]);
                               
                            $successCount++;
                        } else {
                            $skipCount++; // No change
                        }
                    } else {
                        $errorCount++; // SKU not found
                    }
                }
                
                if ($successCount === 0) {
                    // Delete empty opname
                    $db->prepare("DELETE FROM stock_opnames WHERE id = ?")->execute([$opnameId]);
                }
                break;

            case 'variations':
                $productId = (int)($_POST['product_id'] ?? 0);
                if (!$productId) {
                    throw new Exception('ID Produk tidak ditentukan.');
                }
                
                $varCheck = $db->prepare("SELECT id FROM product_variations WHERE sku = ? AND product_id = ?");
                $varInsert = $db->prepare("INSERT INTO product_variations (product_id, sku, variation_name, stock_qty) VALUES (?, ?, ?, ?)");
                $varUpdate = $db->prepare("UPDATE product_variations SET variation_name = ?, stock_qty = ? WHERE id = ?");
                
                $tierCheck = $db->prepare("SELECT id FROM tiered_prices WHERE product_variation_id = ? AND min_qty = ? AND price_type_id = ?");
                $tierInsert = $db->prepare("INSERT INTO tiered_prices (product_variation_id, label, price_type_id, min_qty, selling_price) VALUES (?, ?, ?, ?, ?)");
                $tierUpdate = $db->prepare("UPDATE tiered_prices SET label = ?, selling_price = ? WHERE id = ?");
                
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    if (count($data) < 6 || empty(trim($data[0]))) continue;
                    if (strtolower(trim($data[0])) === 'sku varian') continue;
                    
                    $sku = trim($data[0]);
                    $varName = trim($data[1]);
                    $stock = (int)str_replace(',', '', $data[2] ?? '0');
                    $priceType = trim($data[3]);
                    if (empty($priceType)) $priceType = 'Umum';
                    $minQty = (int)str_replace(',', '', $data[4] ?? '1');
                    if ($minQty < 1) $minQty = 1;
                    $sellingPrice = (float)str_replace(',', '', $data[5] ?? '0');
                    
                    // Check if variation exists
                    $varCheck->execute([$sku, $productId]);
                    if ($var = $varCheck->fetch()) {
                        $varUpdate->execute([$varName, $stock, $var['id']]);
                        $varId = $var['id'];
                    } else {
                        // Insert variation
                        $varInsert->execute([$productId, $sku, $varName ?: null, $stock]);
                        $varId = $db->lastInsertId();
                    }
                    
                    // Check if tiered price exists
                    // Get or insert price type ID
                    $stmtPT = $db->prepare("SELECT id FROM price_types WHERE name = ?");
                    $stmtPT->execute([$priceType]);
                    $priceTypeId = $stmtPT->fetchColumn();
                    if (!$priceTypeId) {
                        $stmtPTIns = $db->prepare("INSERT INTO price_types (name) VALUES (?)");
                        $stmtPTIns->execute([$priceType]);
                        $priceTypeId = $db->lastInsertId();
                    }
                    
                    $tierCheck->execute([$varId, $minQty, $priceTypeId]);
                    $tier = $tierCheck->fetch();
                    
                    if ($tier) {
                        // Update tiered price
                        $label = ($minQty == 1) ? 'Eceran' : 'Grosir';
                        $tierUpdate->execute([$label, $sellingPrice, $tier['id']]);
                    } else {
                        // Insert tiered price
                        $label = ($minQty == 1) ? 'Eceran' : 'Grosir';
                        $tierInsert->execute([$varId, $label, $priceTypeId, $minQty, $sellingPrice]);
                    }
                    
                    $successCount++;
                }
                break;
        }
        
        $db->commit();
        logActivity('Import CSV', ucfirst($type), "Imported: $successCount, Skipped: $skipCount, Error: $errorCount");
        flashMessage('success', "Import selesai. Berhasil: $successCount, Dilewati: $skipCount, Gagal: $errorCount");
        
    } catch (Exception $e) {
        $db->rollBack();
        flashMessage('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
    }
    
    fclose($handle);
    redirect($_SERVER['HTTP_REFERER']);
}

// ==========================================
// 3. EXPORT CSV
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $type = $_GET['type'] ?? '';
    
    // Fungsi untuk export CSV dengan decode HTML Entities (agar &amp; menjadi &)
    if (!function_exists('fputcsv_decoded')) {
        function fputcsv_decoded($handle, $fields, $separator = ',', $enclosure = '"', $escape = "\\") {
            $decodedFields = array_map(function($v) {
                return is_string($v) ? htmlspecialchars_decode($v, ENT_QUOTES) : $v;
            }, $fields);
            return fputcsv($handle, $decodedFields, $separator, $enclosure, $escape);
        }
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=export_' . $type . '_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add filters if passed via GET
    $search = $_GET['search'] ?? '';
    $category = (int)($_GET['category'] ?? 0);
    $status = $_GET['status'] ?? '';
    $period = $_GET['period'] ?? '';
    
    switch ($type) {
        case 'categories':
            fputcsv_decoded($output, ['ID', 'Nama Kategori', 'Urutan', 'Tampil Online (1/0)']);
            $stmt = $db->query("SELECT * FROM categories ORDER BY sort_order, name");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['id'], $row['name'], $row['sort_order'], $row['online_visibility'] ?? 1]);
            }
            break;
            
        case 'users':
            fputcsv_decoded($output, ['ID', 'Username', 'Nama Lengkap', 'Email', 'No Telepon', 'Role', 'Status']);
            $stmt = $db->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.role_id, u.username");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['id'], $row['username'], $row['full_name'], $row['email'], $row['phone'], $row['role_name'], $row['is_active'] ? 'Aktif' : 'Nonaktif']);
            }
            break;
            
        case 'customers':
            fputcsv_decoded($output, ['ID', 'Nama', 'Telepon', 'Email', 'Kota', 'Alamat Lengkap', 'Tipe Harga']);
            $stmt = $db->query("SELECT c.id, c.name, c.phone, c.email, c.city, c.address, pt.name as price_type FROM customers c LEFT JOIN price_types pt ON c.price_type_id = pt.id ORDER BY c.name");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['id'], $row['name'], $row['phone'], $row['email'], $row['city'], $row['address'], $row['price_type'] ?? 'Umum']);
            }
            break;
            
        case 'suppliers':
            fputcsv_decoded($output, ['ID', 'Nama', 'Kontak Person', 'Telepon', 'Email', 'Alamat']);
            $stmt = $db->query("SELECT * FROM suppliers ORDER BY name");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['id'], $row['name'], $row['contact_person'], $row['phone'], $row['email'], $row['address']]);
            }
            break;
            
        case 'products':
            fputcsv_decoded($output, ['SKU Induk', 'Nama Produk', 'Kategori', 'Stok', 'Modal', 'Harga', 'Berat (g)', 'Online', 'Deskripsi']);
            $stmt = $db->query("
                SELECT p.sku as prod_sku, p.name as prod_name, c.name as cat_name, 
                       SUM(pv.stock_qty) as total_stock, p.base_price, 
                       (SELECT selling_price FROM tiered_prices tp JOIN product_variations pv2 ON tp.product_variation_id = pv2.id WHERE pv2.product_id = p.id AND tp.min_qty = 1 LIMIT 1) as harga_jual,
                       p.weight, p.online_visibility, p.description
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                LEFT JOIN product_variations pv ON pv.product_id = p.id
                GROUP BY p.id
                ORDER BY p.name
            ");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [
                    $row['prod_sku'], 
                    $row['prod_name'], 
                    $row['cat_name'], 
                    $row['total_stock'], 
                    $row['base_price'], 
                    $row['harga_jual'], 
                    $row['weight'], 
                    $row['online_visibility'], 
                    $row['description']
                ]);
            }
            break;
            
        case 'purchases':
            fputcsv_decoded($output, ['ID', 'PO Number', 'Tanggal', 'Supplier', 'Total', 'Status']);
            $stmt = $db->query("SELECT p.id, p.po_number, p.date, s.name as supplier, p.total_amount, p.status FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id ORDER BY p.date DESC");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['id'], $row['po_number'], $row['date'], $row['supplier'], $row['total_amount'], $row['status']]);
            }
            break;
            
        case 'opname':
            fputcsv_decoded($output, ['No Opname', 'Tanggal', 'Status', 'Catatan']);
            $stmt = $db->query("SELECT * FROM stock_opnames ORDER BY created_at DESC");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['opname_number'], $row['created_at'], $row['status'], $row['notes']]);
            }
            break;
            
        case 'online_orders':
            fputcsv_decoded($output, ['Invoice', 'Tanggal', 'Pelanggan', 'Total', 'Status']);
            $stmt = $db->query("SELECT invoice_number, created_at, customer_name, grand_total, status FROM sales WHERE sale_source IN ('E-Commerce', 'Online') ORDER BY created_at DESC");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['invoice_number'], $row['created_at'], $row['customer_name'], $row['grand_total'], $row['status']]);
            }
            break;
            
        case 'delivery_notes':
            fputcsv_decoded($output, ['No Surat Jalan', 'No PO', 'Tanggal Terima', 'Penerima', 'Catatan']);
            $stmt = $db->query("SELECT d.surat_jalan_number, p.po_number, d.received_date, u.full_name, d.notes FROM delivery_notes d JOIN purchases p ON d.purchase_id = p.id JOIN users u ON d.receiver_id = u.id ORDER BY d.created_at DESC");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['surat_jalan_number'], $row['po_number'], $row['received_date'], $row['full_name'], $row['notes']]);
            }
            break;
            
        case 'cash_settlement':
            fputcsv_decoded($output, ['Shift Start', 'Kasir', 'Expected Cash', 'Actual Cash', 'Selisih', 'Status']);
            $stmt = $db->query("SELECT c.*, u.full_name FROM cash_settlements c JOIN users u ON c.cashier_id = u.id ORDER BY c.shift_start DESC");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['shift_start'], $row['full_name'], $row['expected_cash'], $row['actual_cash'], $row['cash_difference'], $row['status']]);
            }
            break;
            
        case 'receivables':
            fputcsv_decoded($output, ['Invoice', 'Pelanggan', 'Tanggal Jatuh Tempo', 'Total Hutang', 'Sudah Dibayar', 'Sisa', 'Status']);
            $stmt = $db->query("SELECT r.*, s.invoice_number, c.name as customer_name FROM receivables r JOIN sales s ON r.sale_id = s.id JOIN customers c ON r.customer_id = c.id ORDER BY r.due_date ASC");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['invoice_number'], $row['customer_name'], $row['due_date'], $row['total_debt'], $row['paid_amount'], $row['total_debt'] - $row['paid_amount'], $row['status']]);
            }
            break;
            
        case 'payables':
            fputcsv_decoded($output, ['PO Number', 'Supplier', 'Tanggal Jatuh Tempo', 'Total Hutang', 'Sudah Dibayar', 'Sisa', 'Status']);
            $stmt = $db->query("SELECT p.*, pu.po_number, s.name as supplier_name FROM payables p JOIN purchases pu ON p.purchase_id = pu.id JOIN suppliers s ON pu.supplier_id = s.id ORDER BY p.due_date ASC");
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [$row['po_number'], $row['supplier_name'], $row['due_date'], $row['total_debt'], $row['paid_amount'], $row['total_debt'] - $row['paid_amount'], $row['status']]);
            }
            break;
            
        case 'variations':
            $productId = (int)($_GET['product_id'] ?? 0);
            if (!$productId) {
                fputcsv_decoded($output, ['Error: ID Produk tidak ditentukan']);
                break;
            }
            fputcsv_decoded($output, ['SKU Varian', 'Nama Varian', 'Stok', 'Harga Modal', 'Tipe Harga', 'Qty Minimal', 'Harga Jual']);
            $stmt = $db->prepare("
                SELECT pv.sku, pv.variation_name, pv.stock_qty, pv.base_price, 
                       pt.name as price_type, tp.min_qty, tp.selling_price
                FROM product_variations pv
                LEFT JOIN tiered_prices tp ON tp.product_variation_id = pv.id
                LEFT JOIN price_types pt ON tp.price_type_id = pt.id
                WHERE pv.product_id = ?
                ORDER BY pv.id, pt.name, tp.min_qty
            ");
            $stmt->execute([$productId]);
            while ($row = $stmt->fetch()) {
                fputcsv_decoded($output, [
                    $row['sku'],
                    $row['variation_name'],
                    $row['stock_qty'],
                    $row['base_price'],
                    $row['price_type'] ?? 'Umum',
                    $row['min_qty'] ?? 1,
                    $row['selling_price'] ?? 0
                ]);
            }
            break;

        default:
            fputcsv_decoded($output, ['Invalid export type']);
    }
    
    fclose($output);
    exit;
}
