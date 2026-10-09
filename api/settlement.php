<?php
/**
 * Kasir Ibtidaiyah - API Setoran Penjualan (Cash Settlement)
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $userId = $_SESSION['user_id'];
    
    // =============================================
    // GET ACTIVE SHIFT SUMMARY
    // =============================================
    if ($action === 'active_shift') {
        $stmt = $db->prepare("SELECT * FROM cash_settlements WHERE cashier_id = ? AND status = 'Open' LIMIT 1");
        $stmt->execute([$userId]);
        $openShift = $stmt->fetch();
        
        if (!$openShift) {
            jsonResponse(['success' => false, 'message' => 'Tidak ada shift aktif.']);
        }
        
        $stmt = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN p.payment_method = 'Tunai' AND p.payment_type = 'Incoming' THEN p.amount ELSE 0 END), 0) as cash_in,
                COALESCE(SUM(CASE WHEN p.payment_method = 'Transfer Bank' AND p.payment_type = 'Incoming' THEN p.amount ELSE 0 END), 0) as transfer_in,
                COALESCE(SUM(CASE WHEN p.payment_method = 'Emaal' AND p.payment_type = 'Incoming' THEN p.amount ELSE 0 END), 0) as emaal_in,
                COALESCE(SUM(CASE WHEN p.payment_method = 'BSI' AND p.payment_type = 'Incoming' THEN p.amount ELSE 0 END), 0) as bsi_in,
                COALESCE(SUM(CASE WHEN p.payment_type = 'Outgoing' THEN p.amount ELSE 0 END), 0) as refund_out,
                COUNT(DISTINCT CASE WHEN p.payment_type = 'Incoming' THEN p.sale_id END) as tx_count
            FROM payments p
            WHERE p.payment_date >= ?
              AND p.created_by = ?
        ");
        $stmt->execute([$openShift['shift_start'], $userId]);
        $liveData = $stmt->fetch();
        
        jsonResponse(['success' => true, 'data' => [
            'shift' => $openShift,
            'summary' => $liveData
        ]]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) jsonResponse(['success' => false, 'message' => 'Data tidak valid.'], 400);
    
    $action = $input['action'] ?? '';
    $userId = $_SESSION['user_id'];
    
    // =============================================
    // OPEN SHIFT
    // =============================================
    if ($action === 'open_shift') {
        try {
            $stmt = $db->prepare("SELECT id FROM cash_settlements WHERE cashier_id = ? AND status = 'Open'");
            $stmt->execute([$userId]);
            if ($stmt->fetch()) {
                throw new Exception('Anda masih memiliki shift yang belum ditutup.');
            }
            
            $stmt = $db->prepare("INSERT INTO cash_settlements (cashier_id, shift_start, status) VALUES (?, NOW(), 'Open')");
            $stmt->execute([$userId]);
            $id = $db->lastInsertId();
            
            logActivity('Buka Shift', 'Settlement', 'Shift dibuka');
            jsonResponse(['success' => true, 'message' => 'Shift berhasil dibuka.', 'data' => ['settlement_id' => $id]]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // CLOSE SHIFT (BLIND CLOSE)
    // =============================================
    if ($action === 'close_shift') {
        $settlementId = (int)($input['settlement_id'] ?? 0);
        $actualCash = (float)($input['actual_cash'] ?? 0);
        $notes = sanitize($input['notes'] ?? '');
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT * FROM cash_settlements WHERE id = ? AND cashier_id = ? AND status = 'Open'");
            $stmt->execute([$settlementId, $userId]);
            $settlement = $stmt->fetch();
            
            if (!$settlement) {
                throw new Exception('Shift tidak ditemukan atau sudah ditutup.');
            }
            
            $shiftStart = $settlement['shift_start'];
            
            // Calculate totals
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN p.payment_method = 'Tunai' THEN p.amount ELSE 0 END), 0) as cash_total,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'Transfer Bank' THEN p.amount ELSE 0 END), 0) as transfer_total,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'Emaal' THEN p.amount ELSE 0 END), 0) as emaal_total,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'BSI' THEN p.amount ELSE 0 END), 0) as bsi_total,
                    COUNT(DISTINCT p.sale_id) as tx_count
                FROM payments p
                WHERE p.payment_type = 'Incoming' 
                  AND p.payment_date >= ?
                  AND p.created_by = ?
            ");
            $stmt->execute([$shiftStart, $userId]);
            $totals = $stmt->fetch();
            
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(p.amount), 0) as refund_total
                FROM payments p
                WHERE p.payment_type = 'Outgoing'
                  AND p.payment_date >= ?
                  AND p.created_by = ?
            ");
            $stmt->execute([$shiftStart, $userId]);
            $refundTotal = (float)$stmt->fetchColumn();
            
            $expectedCash = (float)$totals['cash_total'];
            
            $stmt = $db->prepare("
                UPDATE cash_settlements SET 
                    shift_end = NOW(),
                    expected_cash = ?,
                    actual_cash = ?,
                    total_refund = ?,
                    total_transfer = ?,
                    total_emaal = ?,
                    total_bsi = ?,
                    total_transactions = ?,
                    status = 'Closed',
                    notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $expectedCash,
                $actualCash,
                $refundTotal,
                (float)$totals['transfer_total'],
                (float)$totals['emaal_total'],
                (float)$totals['bsi_total'],
                (int)$totals['tx_count'],
                $notes,
                $settlementId
            ]);
            
            $db->commit();
            
            $diff = $actualCash - ($expectedCash - $refundTotal);
            $diffLabel = $diff >= 0 ? "lebih Rp " . number_format(abs($diff), 0, ',', '.') : "kurang Rp " . number_format(abs($diff), 0, ',', '.');
            
            logActivity('Tutup Shift', 'Settlement', "Settlement #$settlementId, Selisih: $diff");
            
            jsonResponse(['success' => true, 'message' => "Shift ditutup. Selisih kas: $diffLabel", 'data' => [
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'difference' => $diff
            ]]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
