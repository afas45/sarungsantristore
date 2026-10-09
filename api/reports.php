<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

// Membutuhkan login dengan role Pemilik atau Admin
requireRole(['Pemilik', 'Admin']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    $db = Database::getInstance()->getConnection();

    if ($action === 'sales_chart') {
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
        
        $stmt = $db->prepare("
            SELECT DATE(sale_date) as date, SUM(final_total) as total
            FROM sales
            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(sale_date)
            ORDER BY DATE(sale_date) ASC
        ");
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        
        $labels = [];
        $data = [];
        
        foreach ($results as $row) {
            $labels[] = $row['date'];
            $data[] = (float)$row['total'];
        }
        
        echo json_encode(['labels' => $labels, 'data' => $data]);
        exit;
    }
    
    if ($action === 'financial_summary') {
        $summary = [];
        
        // Total Penjualan (Bulan Ini)
        $stmt = $db->query("SELECT SUM(final_total) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURRENT_DATE()) AND YEAR(sale_date) = YEAR(CURRENT_DATE())");
        $summary['total_sales'] = $stmt->fetch()['total'] ?? 0;
        
        // Total Piutang (Belum lunas)
        $stmt = $db->query("SELECT SUM(remaining_amount) as total FROM receivables WHERE status IN ('Unpaid', 'Partial')");
        $summary['total_receivables'] = $stmt->fetch()['total'] ?? 0;
        
        // Total Hutang (Belum lunas)
        $stmt = $db->query("SELECT SUM(remaining_amount) as total FROM payables WHERE status IN ('Unpaid', 'Partial')");
        $summary['total_payables'] = $stmt->fetch()['total'] ?? 0;
        
        echo json_encode($summary);
        exit;
    }

    throw new Exception("Invalid action");

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
