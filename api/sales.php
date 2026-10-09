<?php
/**
 * Kasir Ibtidaiyah - API Penjualan
 * Endpoint untuk transaksi POS, Pending Order, dan Retur
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(['success' => false, 'message' => 'Data tidak valid.'], 400);
    }
    
    $action = $input['action'] ?? 'create_sale';
    
    // =============================================
    // ACTION: CREATE SALE (Bayar langsung)
    // =============================================
    if ($action === 'create_sale') {
        try {
            $db->beginTransaction();
            
            // Auto-alter: tambah kolom item_discount jika belum ada
            try {
                $db->query("SELECT item_discount FROM sale_details LIMIT 0");
            } catch (Exception $e) {
                $db->exec("ALTER TABLE sale_details ADD COLUMN item_discount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER unit_price");
            }
            
            $items = $input['items'] ?? [];
            $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
            $customerName = !empty($input['customer_name']) ? sanitize($input['customer_name']) : null;
            $cashierId = $_SESSION['user_id'] ?? null;
            $saleSource = $input['sale_source'] ?? 'POS';
            $paymentMethod = $input['payment_method'] ?? 'Tunai';
            $paidAmount = (float)($input['paid_amount'] ?? 0);
            $isDebt = $input['is_debt'] ?? false;
            $notes = $input['notes'] ?? '';
            $discountPercent = isset($input['discount_percent']) ? max(0, min(100, (float)$input['discount_percent'])) : 0;
            $additionalFee = (float)($input['additional_fee'] ?? 0);
            $dueDate = $input['due_date'] ?? date('Y-m-d', strtotime('+' . getSetting('default_due_days', 30) . ' days'));
            
            if ($additionalFee > 0) {
                $notes = trim($notes . " (Biaya Tambahan: " . formatRupiah($additionalFee) . ")");
            }
            
            if (empty($items)) {
                throw new Exception('Keranjang kosong.');
            }
            
            // Calculate totals
            $totalAmount = 0;
            $processedItems = [];
            
            // Tentukan price type dari customer (untuk fallback jika tidak ada custom_price)
            $priceType = 'Umum';
            if ($customerId) {
                $stmtPt = $db->prepare("SELECT pt.name FROM price_types pt JOIN customers c ON c.price_type_id = pt.id WHERE c.id = ?");
                $stmtPt->execute([$customerId]);
                $pt = $stmtPt->fetchColumn();
                if ($pt) $priceType = $pt;
            }
            
            foreach ($items as $item) {
                $varId = (int)$item['variation_id'];
                $qty = (int)$item['qty'];
                $customPrice = isset($item['custom_price']) && $item['custom_price'] !== null ? (float)$item['custom_price'] : null;
                $itemDiscVal = (float)($item['item_discount_value'] ?? 0);
                $itemDiscType = $item['item_discount_type'] ?? 'persen';
                
                if ($qty <= 0) continue;
                
                // Get variation info
                $stmt = $db->prepare("SELECT pv.*, p.name as product_name FROM product_variations pv JOIN products p ON pv.product_id = p.id WHERE pv.id = ? FOR UPDATE");
                $stmt->execute([$varId]);
                $variation = $stmt->fetch();
                
                if (!$variation) {
                    throw new Exception("Produk tidak ditemukan (ID: $varId).");
                }
                
                if ($variation['stock_qty'] < $qty) {
                    throw new Exception("Stok {$variation['product_name']} tidak cukup. Tersisa: {$variation['stock_qty']}");
                }
                
                // Gunakan custom_price dari frontend jika ada (kasir sudah pilih tipe harga)
                // Jika tidak ada, fallback ke getSellingPrice berdasarkan customer price type
                if ($customPrice !== null) {
                    $unitPrice = $customPrice;
                } else {
                    $unitPrice = getSellingPrice($varId, $qty, $priceType);
                }
                
                // Hitung diskon per item
                $rawSubtotal = $unitPrice * $qty;
                $itemDiscAmount = 0;
                if ($itemDiscVal > 0) {
                    if ($itemDiscType === 'persen') {
                        $itemDiscAmount = $rawSubtotal * min(100, $itemDiscVal) / 100;
                    } else {
                        $itemDiscAmount = min($itemDiscVal, $rawSubtotal);
                    }
                }
                $subtotal = max(0, $rawSubtotal - $itemDiscAmount);
                $totalAmount += $subtotal;
                
                $processedItems[] = [
                    'variation_id' => $varId,
                    'product_name' => $variation['product_name'],
                    'variation_name' => $variation['variation_name'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'item_discount' => $itemDiscAmount,
                    'subtotal' => $subtotal,
                ];
            }
            
            if (empty($processedItems)) {
                throw new Exception('Tidak ada item valid.');
            }
            
            // Generate invoice number
            $invoiceNumber = generateInvoiceNumber();
            $discountAmount = ($totalAmount * $discountPercent) / 100;
            $grandTotal = max(0, $totalAmount - $discountAmount + $additionalFee);
            $status = $isDebt ? 'Debt' : 'Paid';
            
            // Validate debt
            if ($isDebt) {
                if (!$customerId) {
                    throw new Exception('Kasbon harus memilih pelanggan terdaftar.');
                }
                
                // Check credit limit
                $stmt = $db->prepare("SELECT credit_limit FROM customers WHERE id = ?");
                $stmt->execute([$customerId]);
                $creditLimit = (float)$stmt->fetchColumn();
                
                $stmt = $db->prepare("SELECT COALESCE(SUM(total_debt - paid_amount), 0) FROM receivables WHERE customer_id = ? AND status != 'Paid'");
                $stmt->execute([$customerId]);
                $currentDebt = (float)$stmt->fetchColumn();
                
                if ($creditLimit > 0 && ($currentDebt + $grandTotal) > $creditLimit) {
                    throw new Exception("Limit piutang pelanggan terlampaui. Sisa limit: " . formatRupiah($creditLimit - $currentDebt));
                }
            }
            
            // Insert sale
            $stmt = $db->prepare("INSERT INTO sales (invoice_number, customer_id, customer_name, cashier_id, sale_source, total_amount, discount_amount, grand_total, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$invoiceNumber, $customerId, $customerName, $cashierId, $saleSource, $totalAmount, $discountAmount, $grandTotal, $status, $notes]);
            $saleId = $db->lastInsertId();
            
            // Insert sale details & reduce stock
            foreach ($processedItems as $item) {
                $stmt = $db->prepare("INSERT INTO sale_details (sale_id, product_variation_id, product_name, variation_name, qty, unit_price, item_discount) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$saleId, $item['variation_id'], $item['product_name'], $item['variation_name'], $item['qty'], $item['unit_price'], $item['item_discount']]);
                
                // Reduce stock
                $stmt = $db->prepare("UPDATE product_variations SET stock_qty = stock_qty - ? WHERE id = ?");
                $stmt->execute([$item['qty'], $item['variation_id']]);
                
                // Sinkronisasi stok SKU induk
                $pidStmt = $db->prepare("SELECT product_id FROM product_variations WHERE id = ?");
                $pidStmt->execute([$item['variation_id']]);
                $pId = $pidStmt->fetchColumn();
                if ($pId) syncProductStock($pId);
            }
            
            // Insert payment
            if (!$isDebt && $paidAmount > 0) {
                $stmt = $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, created_by) VALUES (?, 'Incoming', ?, ?, ?)");
                $stmt->execute([$saleId, $paymentMethod, $paidAmount, $cashierId]);
            } elseif ($isDebt && $paidAmount > 0) {
                $stmt = $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, created_by) VALUES (?, 'Incoming', ?, ?, ?)");
                $stmt->execute([$saleId, $paymentMethod, $paidAmount, $cashierId]);
            }
            
            // Create receivable if debt
            if ($isDebt) {
                $debtAmount = $grandTotal - $paidAmount;
                $stmt = $db->prepare("INSERT INTO receivables (customer_id, sale_id, total_debt, paid_amount, due_date, status) VALUES (?,?,?,?,?,?)");
                $paidOnDebt = max(0, $paidAmount);
                $recStatus = $paidOnDebt > 0 ? 'Partial' : 'Unpaid';
                $stmt->execute([$customerId, $saleId, $grandTotal, $paidOnDebt, $dueDate, $recStatus]);
            }
            
            $db->commit();
            
            logActivity('Penjualan', 'Sales', "Invoice: $invoiceNumber, Total: " . formatRupiah($grandTotal));
            
            // Return receipt data
            $receiptData = [
                'sale_id' => $saleId,
                'invoice_number' => $invoiceNumber,
                'items' => $processedItems,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'change' => max(0, $paidAmount - $grandTotal),
                'payment_method' => $paymentMethod,
                'status' => $status,
                'is_debt' => $isDebt,
                'customer_id' => $customerId,
                'date' => date('Y-m-d H:i:s'),
                'store_name' => getSetting('store_name', APP_NAME),
                'store_tagline' => getSetting('store_tagline', ''),
                'store_address' => getSetting('store_address', ''),
                'store_phone' => getSetting('store_phone', ''),
                'store_logo' => getSetting('store_logo', ''),
                'receipt_footer' => getSetting('receipt_footer', 'Terima kasih!'),
                'cashier_name' => $_SESSION['full_name'] ?? '',
            ];
            
            // Get customer name if exists
            if ($customerId) {
                $stmt = $db->prepare("SELECT name FROM customers WHERE id = ?");
                $stmt->execute([$customerId]);
                $receiptData['customer_name'] = $stmt->fetchColumn();
            } else if ($customerName) {
                $receiptData['customer_name'] = $customerName;
            } else {
                $receiptData['customer_name'] = 'Umum';
            }
            
            jsonResponse(['success' => true, 'message' => 'Transaksi berhasil!', 'data' => $receiptData]);
            
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: SAVE PENDING ORDER
    // =============================================
    if ($action === 'save_pending') {
        try {
            $db->beginTransaction();
            
            $items = $input['items'] ?? [];
            $pendingLabel = sanitize($input['pending_label'] ?? '');
            $customerName = sanitize($input['customer_name'] ?? '');
            $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
            $cashierId = $_SESSION['user_id'] ?? null;
            
            if (empty($items)) {
                throw new Exception('Keranjang kosong.');
            }
            if (empty($pendingLabel) && empty($customerName)) {
                throw new Exception('Nama pelanggan atau nomor meja harus diisi.');
            }
            
            $totalAmount = 0;
            $processedItems = [];
            
            foreach ($items as $item) {
                $varId = (int)$item['variation_id'];
                $qty = (int)$item['qty'];
                if ($qty <= 0) continue;
                
                $stmt = $db->prepare("SELECT pv.*, p.name as product_name FROM product_variations pv JOIN products p ON pv.product_id = p.id WHERE pv.id = ?");
                $stmt->execute([$varId]);
                $variation = $stmt->fetch();
                if (!$variation) continue;
                
                $priceType = 'Umum';
                if ($customerId) {
                    $stmtPt = $db->prepare("SELECT pt.name FROM price_types pt JOIN customers c ON c.price_type_id = pt.id WHERE c.id = ?");
                    $stmtPt->execute([$customerId]);
                    $pt = $stmtPt->fetchColumn();
                    if ($pt) $priceType = $pt;
                }
                $unitPrice = getSellingPrice($varId, $qty, $priceType);
                $subtotal = $unitPrice * $qty;
                $totalAmount += $subtotal;
                
                $processedItems[] = [
                    'variation_id' => $varId,
                    'product_name' => $variation['product_name'],
                    'variation_name' => $variation['variation_name'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                ];
            }
            
            if (empty($processedItems)) {
                throw new Exception('Tidak ada item valid.');
            }
            
            $invoiceNumber = generateInvoiceNumber();
            $label = $pendingLabel ?: $customerName;
            
            // Cek nama pending order (pending_label) tidak boleh sama
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM sales WHERE status = 'Pending' AND LOWER(TRIM(pending_label)) = LOWER(TRIM(?))");
            $stmtCheck->execute([$label]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception('Nama Pending (Pending Label) "' . $label . '" sudah digunakan. Harap gunakan nama yang berbeda.');
            }
            
            // Insert sale with status Pending (stok TIDAK dikurangi)
            $stmt = $db->prepare("INSERT INTO sales (invoice_number, customer_id, customer_name, pending_label, cashier_id, sale_source, total_amount, grand_total, status, notes) VALUES (?,?,?,?,?,'POS',?,?,'Pending','')");
            $stmt->execute([$invoiceNumber, $customerId, $customerName, $label, $cashierId, $totalAmount, $totalAmount]);
            $saleId = $db->lastInsertId();
            
            foreach ($processedItems as $item) {
                $stmt = $db->prepare("INSERT INTO sale_details (sale_id, product_variation_id, product_name, variation_name, qty, unit_price) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$saleId, $item['variation_id'], $item['product_name'], $item['variation_name'], $item['qty'], $item['unit_price']]);
            }
            
            $db->commit();
            logActivity('Simpan Pesanan', 'Sales', "Pending: $label, Invoice: $invoiceNumber");
            
            jsonResponse(['success' => true, 'message' => 'Pesanan disimpan.', 'data' => ['sale_id' => $saleId, 'label' => $label]]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: LIST PENDING ORDERS
    // =============================================
    if ($action === 'list_pending') {
        $cashierId = $_SESSION['user_id'] ?? null;
        $stmt = $db->prepare("
            SELECT s.id, s.invoice_number, s.pending_label, s.customer_name, s.grand_total, s.created_at,
                   (SELECT COUNT(*) FROM sale_details WHERE sale_id = s.id) as item_count
            FROM sales s
            WHERE s.status = 'Pending' AND s.sale_source = 'POS'
            ORDER BY s.created_at DESC
        ");
        $stmt->execute();
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }
    
    // =============================================
    // ACTION: RESUME PENDING ORDER (load items to cart)
    // =============================================
    if ($action === 'resume_pending') {
        $saleId = (int)($input['sale_id'] ?? 0);
        
        $stmt = $db->prepare("SELECT * FROM sales WHERE id = ? AND status = 'Pending'");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        
        if (!$sale) {
            jsonResponse(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
        }
        
        // Get items with current stock & tiered prices
        $stmt = $db->prepare("
            SELECT sd.product_variation_id as variation_id, sd.product_name, sd.variation_name, sd.qty, sd.unit_price,
                   pv.stock_qty
            FROM sale_details sd
            JOIN product_variations pv ON sd.product_variation_id = pv.id
            WHERE sd.sale_id = ?
        ");
        $stmt->execute([$saleId]);
        $items = $stmt->fetchAll();
        
        // Attach tiers for each item
        foreach ($items as &$item) {
            $stmt2 = $db->prepare("SELECT label, min_qty, selling_price FROM tiered_prices WHERE product_variation_id = ? ORDER BY min_qty");
            $stmt2->execute([$item['variation_id']]);
            $item['tiers'] = $stmt2->fetchAll();
        }
        unset($item);
        
        jsonResponse(['success' => true, 'data' => [
            'sale_id' => $sale['id'],
            'customer_id' => $sale['customer_id'],
            'customer_name' => $sale['customer_name'],
            'items' => $items,
        ]]);
    }
    
    // =============================================
    // ACTION: DELETE PENDING ORDER
    // =============================================
    if ($action === 'delete_pending') {
        $saleId = (int)($input['sale_id'] ?? 0);
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT id, status FROM sales WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            
            if (!$sale) {
                throw new Exception('Pesanan tidak ditemukan atau sudah diproses.');
            }
            
            // Update status to Cancelled
            $db->prepare("UPDATE sales SET status = 'Cancelled' WHERE id = ?")->execute([$saleId]);
            
            $db->commit();
            logActivity('Hapus Pending', 'Sales', "Sale ID: $saleId");
            jsonResponse(['success' => true, 'message' => 'Pesanan dibatalkan.']);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: SEARCH INVOICE (untuk Retur)
    // =============================================
    if ($action === 'search_invoice') {
        $invoiceNo = sanitize($input['invoice_number'] ?? '');
        
        if (empty($invoiceNo)) {
            jsonResponse(['success' => false, 'message' => 'Nomor invoice harus diisi.'], 400);
        }
        
        $stmt = $db->prepare("
            SELECT s.*, u.full_name as cashier_name
            FROM sales s 
            LEFT JOIN users u ON s.cashier_id = u.id
            WHERE s.invoice_number = ? AND s.status IN ('Paid', 'Debt', 'Completed', 'Confirmed', 'Processing', 'Ready', 'Shipped')
        ");
        $stmt->execute([$invoiceNo]);
        $sale = $stmt->fetch();
        
        if (!$sale) {
            jsonResponse(['success' => false, 'message' => 'Invoice tidak ditemukan atau belum lunas.'], 404);
        }
        
        // Get items with already-returned qty
        $stmt = $db->prepare("
            SELECT sd.id as sale_detail_id, sd.product_name, sd.variation_name, sd.qty, sd.unit_price,
                   COALESCE(
                       (SELECT SUM(rd.qty_returned) FROM return_details rd 
                        JOIN returns r ON rd.return_id = r.id 
                        WHERE rd.sale_detail_id = sd.id), 0
                   ) as qty_returned
            FROM sale_details sd
            WHERE sd.sale_id = ?
        ");
        $stmt->execute([$sale['id']]);
        $items = $stmt->fetchAll();
        
        // Calculate returnable qty
        foreach ($items as &$item) {
            $item['qty_returnable'] = $item['qty'] - $item['qty_returned'];
        }
        unset($item);
        
        jsonResponse(['success' => true, 'data' => [
            'sale' => $sale,
            'items' => $items,
        ]]);
    }
    
    // =============================================
    // ACTION: CREATE RETURN (Retur Produk)
    // =============================================
    if ($action === 'create_return') {
        try {
            $db->beginTransaction();
            
            $saleId = (int)($input['sale_id'] ?? 0);
            $reason = sanitize($input['reason'] ?? '');
            $returnType = $input['return_type'] ?? 'Refund';
            $returnItems = $input['items'] ?? [];
            $paymentMethod = $input['payment_method'] ?? 'Tunai';
            $cashierId = $_SESSION['user_id'] ?? null;
            
            if (empty($reason)) {
                throw new Exception('Alasan retur harus diisi.');
            }
            if (empty($returnItems)) {
                throw new Exception('Pilih minimal satu item untuk diretur.');
            }
            
            // Validate sale
            $stmt = $db->prepare("SELECT id, status FROM sales WHERE id = ? AND status IN ('Paid', 'Debt', 'Completed', 'Confirmed', 'Processing', 'Ready', 'Shipped')");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                throw new Exception('Transaksi tidak valid untuk retur.');
            }
            
            $totalRefund = 0;
            $validItems = [];
            
            foreach ($returnItems as $ri) {
                $saleDetailId = (int)$ri['sale_detail_id'];
                $qtyReturn = (int)$ri['qty'];
                $condition = $ri['condition'] ?? 'Bagus';
                
                if ($qtyReturn <= 0) continue;
                
                // Get sale detail
                $stmt = $db->prepare("SELECT * FROM sale_details WHERE id = ? AND sale_id = ?");
                $stmt->execute([$saleDetailId, $saleId]);
                $detail = $stmt->fetch();
                if (!$detail) continue;
                
                // Check already returned qty
                $stmt = $db->prepare("SELECT COALESCE(SUM(qty_returned), 0) FROM return_details rd JOIN returns r ON rd.return_id = r.id WHERE rd.sale_detail_id = ?");
                $stmt->execute([$saleDetailId]);
                $alreadyReturned = (int)$stmt->fetchColumn();
                
                $maxReturnable = $detail['qty'] - $alreadyReturned;
                if ($qtyReturn > $maxReturnable) {
                    throw new Exception("Qty retur untuk {$detail['product_name']} melebihi batas. Maks: $maxReturnable");
                }
                
                $itemRefund = $detail['unit_price'] * $qtyReturn;
                $totalRefund += $itemRefund;
                
                $validItems[] = [
                    'sale_detail_id' => $saleDetailId,
                    'qty' => $qtyReturn,
                    'condition' => $condition,
                    'variation_id' => $detail['product_variation_id'],
                ];
            }
            
            if (empty($validItems)) {
                throw new Exception('Tidak ada item valid untuk diretur.');
            }
            
            // Generate return number
            $returnNumber = generateReturnNumber();
            
            // Insert return header
            $stmt = $db->prepare("INSERT INTO returns (return_number, sale_id, cashier_id, reason, return_type, refund_amount, payment_method) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$returnNumber, $saleId, $cashierId, $reason, $returnType, $totalRefund, $paymentMethod]);
            $returnId = $db->lastInsertId();
            
            // Insert return details & adjust stock
            foreach ($validItems as $vi) {
                $stmt = $db->prepare("INSERT INTO return_details (return_id, sale_detail_id, qty_returned, item_condition) VALUES (?,?,?,?)");
                $stmt->execute([$returnId, $vi['sale_detail_id'], $vi['qty'], $vi['condition']]);
                
                // If item condition is "Bagus", add back to stock
                if ($vi['condition'] === 'Bagus') {
                    $stmt = $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ? WHERE id = ?");
                    $stmt->execute([$vi['qty'], $vi['variation_id']]);
                }
            }
            
            // Record refund payment (outgoing)
            if ($returnType === 'Refund' && $totalRefund > 0) {
                $stmt = $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, notes, created_by) VALUES (?, 'Outgoing', ?, ?, ?, ?)");
                $stmt->execute([$saleId, $paymentMethod, $totalRefund, "Refund: $returnNumber", $cashierId]);
            }
            
            $db->commit();
            logActivity('Retur', 'Returns', "Return: $returnNumber, Refund: " . formatRupiah($totalRefund));
            
            jsonResponse(['success' => true, 'message' => 'Retur berhasil diproses.', 'data' => [
                'return_id' => $returnId,
                'return_number' => $returnNumber,
                'refund_amount' => $totalRefund,
                'return_type' => $returnType,
            ]]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // =============================================
    // ACTION: EDIT SALE (Tambah Produk & Retur)
    // =============================================
    if ($action === 'edit_sale') {
        try {
            $db->beginTransaction();
            $saleId = (int)($input['sale_id'] ?? 0);
            $reason = sanitize($input['reason'] ?? 'Edit transaksi');
            $addItems = $input['add_items'] ?? [];
            $returnItems = $input['return_items'] ?? [];
            $cashierId = $_SESSION['user_id'] ?? null;

            $stmt = $db->prepare("SELECT * FROM sales WHERE id = ? AND status IN ('Paid', 'Debt', 'Completed', 'Confirmed', 'Processing', 'Ready', 'Shipped') FOR UPDATE");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            if (!$sale) throw new Exception('Transaksi tidak valid untuk diedit.');
            if (empty($addItems) && empty($returnItems)) throw new Exception('Tambahkan produk atau pilih produk untuk diretur.');

            $db->exec("CREATE TABLE IF NOT EXISTS sale_edits (
                id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL, reason VARCHAR(255) NOT NULL,
                total_before DECIMAL(15,2) NOT NULL DEFAULT 0, total_added DECIMAL(15,2) NOT NULL DEFAULT 0,
                total_returned DECIMAL(15,2) NOT NULL DEFAULT 0, total_after DECIMAL(15,2) NOT NULL DEFAULT 0,
                created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_sale_id (sale_id)
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS sale_edit_details (
                id INT AUTO_INCREMENT PRIMARY KEY, edit_id INT NOT NULL, product_variation_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL, variation_name VARCHAR(255) NULL, qty INT NOT NULL,
                unit_price DECIMAL(15,2) NOT NULL, adjustment_type ENUM('added','returned') NOT NULL,
                INDEX idx_edit_id (edit_id)
            )");

            $totalAdded = 0;
            $totalReturned = 0;
            $editItems = [];
            foreach ($addItems as $item) {
                $varId = (int)($item['variation_id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                if ($varId <= 0 || $qty <= 0) continue;
                $stmt = $db->prepare("SELECT pv.*, p.name AS product_name FROM product_variations pv JOIN products p ON p.id = pv.product_id WHERE pv.id = ? FOR UPDATE");
                $stmt->execute([$varId]);
                $variation = $stmt->fetch();
                if (!$variation) throw new Exception('Produk tambahan tidak ditemukan.');
                if ((int)$variation['stock_qty'] < $qty) throw new Exception("Stok {$variation['product_name']} tidak cukup.");
                $price = getSellingPrice($varId, $qty, 'Umum');
                $subtotal = $price * $qty;
                $totalAdded += $subtotal;
                $editItems[] = ['variation_id'=>$varId, 'product_name'=>$variation['product_name'], 'variation_name'=>$variation['variation_name'], 'qty'=>$qty, 'unit_price'=>$price, 'subtotal'=>$subtotal, 'type'=>'added'];
                $db->prepare("INSERT INTO sale_details (sale_id, product_variation_id, product_name, variation_name, qty, unit_price) VALUES (?,?,?,?,?,?)")
                   ->execute([$saleId, $varId, $variation['product_name'], $variation['variation_name'], $qty, $price]);
                $db->prepare("UPDATE product_variations SET stock_qty = stock_qty - ? WHERE id = ?")->execute([$qty, $varId]);
            }
            foreach ($returnItems as $item) {
                $detailId = (int)($item['sale_detail_id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                if ($detailId <= 0 || $qty <= 0) continue;
                $stmt = $db->prepare("SELECT sd.*, pv.stock_qty FROM sale_details sd JOIN product_variations pv ON pv.id = sd.product_variation_id WHERE sd.id = ? AND sd.sale_id = ? FOR UPDATE");
                $stmt->execute([$detailId, $saleId]);
                $detail = $stmt->fetch();
                if (!$detail) throw new Exception('Item retur tidak ditemukan.');
                $stmt = $db->prepare("SELECT COALESCE(SUM(qty_returned),0) FROM return_details rd JOIN returns r ON r.id = rd.return_id WHERE rd.sale_detail_id = ?");
                $stmt->execute([$detailId]);
                $max = (int)$detail['qty'] - (int)$stmt->fetchColumn();
                if ($qty > $max) throw new Exception("Qty retur {$detail['product_name']} melebihi batas.");
                $subtotal = $detail['unit_price'] * $qty;
                $totalReturned += $subtotal;
                $editItems[] = ['variation_id'=>$detail['product_variation_id'], 'product_name'=>$detail['product_name'], 'variation_name'=>$detail['variation_name'], 'qty'=>$qty, 'unit_price'=>$detail['unit_price'], 'subtotal'=>$subtotal, 'type'=>'returned'];
                $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ? WHERE id = ?")->execute([$qty, $detail['product_variation_id']]);
            }
            if (!$editItems) throw new Exception('Tidak ada perubahan yang valid.');
            $before = (float)$sale['grand_total'];
            $difference = $totalAdded - $totalReturned;
            $after = max(0, $before + $difference);
            $db->prepare("INSERT INTO sale_edits (sale_id, reason, total_before, total_added, total_returned, total_after, created_by) VALUES (?,?,?,?,?,?,?)")
               ->execute([$saleId, $reason, $before, $totalAdded, $totalReturned, $after, $cashierId]);
            $editId = $db->lastInsertId();
            foreach ($editItems as $ei) {
                $db->prepare("INSERT INTO sale_edit_details (edit_id, product_variation_id, product_name, variation_name, qty, unit_price, adjustment_type) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$editId, $ei['variation_id'], $ei['product_name'], $ei['variation_name'], $ei['qty'], $ei['unit_price'], $ei['type']]);
            }
            $db->prepare("UPDATE sales SET grand_total = ? WHERE id = ?")->execute([$after, $saleId]);
            $db->commit();
            logActivity('Edit Transaksi', 'Sales', "Sale ID: $saleId, Selisih: " . formatRupiah($difference));
            jsonResponse(['success'=>true, 'message'=>'Transaksi berhasil diedit.', 'data'=>[
                'sale_id'=>$saleId, 'total_before'=>$before, 'total_added'=>$totalAdded, 'total_returned'=>$totalReturned,
                'total_difference'=>$difference, 'grand_total'=>$after, 'items'=>$editItems
            ]]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(['success'=>false, 'message'=>$e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: UPDATE ONLINE ORDER STATUS
    // =============================================
    if ($action === 'update_order_status') {
        try {
            $saleId = (int)($input['sale_id'] ?? 0);
            $newStatus = $input['status'] ?? '';
            $rejectReason = $input['reject_reason'] ?? '';
            $cashierId = $_SESSION['user_id'] ?? null;
            
            $validStatuses = ['Confirmed', 'Processing', 'Ready', 'Shipped', 'Completed', 'Cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception('Status tidak valid.');
            }
            
            $stmt = $db->prepare("SELECT * FROM sales WHERE id = ?");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                throw new Exception('Pesanan tidak ditemukan.');
            }
            
            $db->beginTransaction();
            
            // If confirming: reduce stock
            if ($newStatus === 'Confirmed' && in_array($sale['status'], ['Pending'])) {
                $stmt = $db->prepare("SELECT sd.product_variation_id, sd.qty FROM sale_details sd WHERE sd.sale_id = ?");
                $stmt->execute([$saleId]);
                $items = $stmt->fetchAll();
                
                foreach ($items as $item) {
                    $stmtStock = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ? FOR UPDATE");
                    $stmtStock->execute([$item['product_variation_id']]);
                    $currentStock = (int)$stmtStock->fetchColumn();
                    
                    if ($currentStock < $item['qty']) {
                        throw new Exception("Stok tidak cukup untuk memproses pesanan ini.");
                    }
                    
                    $db->prepare("UPDATE product_variations SET stock_qty = stock_qty - ? WHERE id = ?")
                       ->execute([$item['qty'], $item['product_variation_id']]);
                }
            }
            
            // If cancelling confirmed order: restore stock
            if ($newStatus === 'Cancelled' && in_array($sale['status'], ['Confirmed', 'Processing', 'Ready'])) {
                $stmt = $db->prepare("SELECT sd.product_variation_id, sd.qty FROM sale_details sd WHERE sd.sale_id = ?");
                $stmt->execute([$saleId]);
                $items = $stmt->fetchAll();
                
                foreach ($items as $item) {
                    $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ? WHERE id = ?")
                       ->execute([$item['qty'], $item['product_variation_id']]);
                }
            }
            
            $notes = $sale['notes'];
            if ($newStatus === 'Cancelled' && $rejectReason) {
                $notes = ($notes ? $notes . "\n" : '') . "Ditolak: $rejectReason";
            }
            
            $stmt = $db->prepare("UPDATE sales SET status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$newStatus, $notes, $saleId]);
            
            $db->commit();
            logActivity('Update Status', 'Sales', "Sale #$saleId: {$sale['status']} → $newStatus");
            
            jsonResponse(['success' => true, 'message' => "Status diperbarui menjadi $newStatus."]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: LIST ONLINE ORDERS
    // =============================================
    if ($action === 'list_online_orders') {
        $status = $input['status'] ?? '';
        
        $where = "WHERE s.sale_source IN ('E-Commerce', 'Online') AND s.status != 'Pending'";
        $params = [];
        
        if ($status) {
            $where .= " AND s.status = ?";
            $params[] = $status;
        }
        
        $stmt = $db->prepare("
            SELECT s.*, u.full_name as cashier_name,
                   c.name as cust_name, c.phone as cust_phone,
                   (SELECT COUNT(*) FROM sale_details WHERE sale_id = s.id) as item_count
            FROM sales s
            LEFT JOIN users u ON s.cashier_id = u.id
            LEFT JOIN customers c ON s.customer_id = c.id
            $where
            ORDER BY s.created_at DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }
}

// =============================================
// GET REQUESTS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    // Count new online orders (for badge)
    if ($action === 'count_new_online') {
        $stmt = $db->query("SELECT COUNT(*) FROM sales WHERE sale_source IN ('E-Commerce', 'Online') AND status = 'Confirmed'");
        $count = (int)$stmt->fetchColumn();
        jsonResponse(['success' => true, 'count' => $count]);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
