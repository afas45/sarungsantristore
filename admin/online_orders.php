<?php
/**
 * Kasir Ibtidaiyah - Konfirmasi Pesanan Online
 * Memantau dan memproses pesanan dari E-Commerce
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);

$db = Database::conn();
$pageTitle = 'Pesanan Online';
$breadcrumbs = [['label' => 'Pesanan Online']];

// Handle status update via regular POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isAjax()) {
    $action = $_POST['action'] ?? '';
    $saleId = (int)($_POST['sale_id'] ?? 0);
    
    if ($action === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        $rejectReason = sanitize($_POST['reject_reason'] ?? '');
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT * FROM sales WHERE id = ?");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            
            if ($sale) {
                // If confirming: check shift and insert payment
                if ($newStatus === 'Confirmed' && $sale['status'] === 'Pending') {
                    $userId = $_SESSION['user_id'] ?? 0;
                    
                    // Check Shift
                    $stmt = $db->prepare("SELECT id FROM cash_settlements WHERE cashier_id = ? AND status = 'Open'");
                    $stmt->execute([$userId]);
                    if (!$stmt->fetch()) {
                        throw new Exception('Aksi ditolak: Anda harus membuka Shift Kasir terlebih dahulu sebelum mengkonfirmasi pesanan online.');
                    }
                    
                    // Assign cashier
                    $db->prepare("UPDATE sales SET cashier_id = ? WHERE id = ?")->execute([$userId, $saleId]);
                    
                    // Record payment as Transfer Bank so it doesn't affect actual cash drawer
                    $stmt = $db->prepare("SELECT id FROM payments WHERE sale_id = ?");
                    $stmt->execute([$saleId]);
                    if (!$stmt->fetch()) {
                        $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, created_by) VALUES (?, 'Incoming', 'Transfer Bank', ?, ?)")
                           ->execute([$saleId, $sale['grand_total'], $userId]);
                    }
                    // Note: Stock was already reduced at checkout, so no need to reduce it again here.
                    
                    // Auto-fill customer city from shipping_details if not yet set
                    if (!empty($sale['customer_id'])) {
                        $stmtCustCity = $db->prepare("SELECT city FROM customers WHERE id = ?");
                        $stmtCustCity->execute([$sale['customer_id']]);
                        $custCity = $stmtCustCity->fetchColumn();
                        
                        if (empty($custCity)) {
                            $stmtShip = $db->prepare("SELECT city_name FROM shipping_details WHERE sale_id = ? LIMIT 1");
                            $stmtShip->execute([$saleId]);
                            $shipCity = $stmtShip->fetchColumn();
                            
                            if (!empty($shipCity)) {
                                $db->prepare("UPDATE customers SET city = ? WHERE id = ? AND (city IS NULL OR city = '')")
                                   ->execute([$shipCity, $sale['customer_id']]);
                            }
                        }
                    }
                }
                
                // If cancelling confirmed: restore stock
                if ($newStatus === 'Cancelled' && in_array($sale['status'], ['Pending', 'Confirmed', 'Processing', 'Ready', 'Shipped'])) {
                    $stmt = $db->prepare("SELECT product_variation_id, qty FROM sale_details WHERE sale_id = ?");
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
                
                $db->prepare("UPDATE sales SET status = ?, notes = ? WHERE id = ?")->execute([$newStatus, $notes, $saleId]);
                $db->commit();
                flashMessage('success', "Status pesanan #{$sale['invoice_number']} diperbarui menjadi $newStatus.");
                logActivity('Update Status Online', 'Sales', "Sale #{$saleId}: {$sale['status']} → $newStatus");
            }
        } catch (Exception $e) {
            $db->rollBack();
            flashMessage('error', $e->getMessage());
        }
    } elseif ($action === 'delete_order') {
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT * FROM sales WHERE id = ?");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            
            if ($sale) {
                // If stock was reduced (Confirmed, Processing, Ready, Shipped, Completed), restore it
                if (in_array($sale['status'], ['Confirmed', 'Processing', 'Ready', 'Shipped', 'Completed'])) {
                    $stmt = $db->prepare("SELECT product_variation_id, qty FROM sale_details WHERE sale_id = ?");
                    $stmt->execute([$saleId]);
                    $items = $stmt->fetchAll();
                    foreach ($items as $item) {
                        $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ? WHERE id = ?")
                           ->execute([$item['qty'], $item['product_variation_id']]);
                    }
                }
                
                // Delete related records
                $db->prepare("DELETE FROM payments WHERE sale_id = ?")->execute([$saleId]);
                $db->prepare("DELETE FROM receivables WHERE sale_id = ?")->execute([$saleId]);
                $db->prepare("DELETE FROM sale_details WHERE sale_id = ?")->execute([$saleId]);
                $db->prepare("DELETE FROM shipping_details WHERE sale_id = ?")->execute([$saleId]);
                $db->prepare("DELETE FROM sales WHERE id = ?")->execute([$saleId]);
                
                $db->commit();
                flashMessage('success', "Pesanan #{$sale['invoice_number']} berhasil dihapus.");
                logActivity('Hapus Pesanan Online', 'Sales', "Hapus pesanan online ID: $saleId");
            }
        } catch (Exception $e) {
            $db->rollBack();
            flashMessage('error', 'Gagal menghapus pesanan: ' . $e->getMessage());
        }
    }
    
    redirect(BASE_URL . '/admin/online_orders.php');
}

// Fetch orders
$filterStatus = $_GET['status'] ?? '';
$where = "WHERE s.sale_source IN ('E-Commerce', 'Online')";
$params = [];

if ($filterStatus) {
    $where .= " AND s.status = ?";
    $params[] = $filterStatus;
}

$stmt = $db->prepare("
    SELECT s.*, u.full_name as cashier_name, c.name as cust_name, c.phone as cust_phone,
           sh.recipient_name, sh.phone as ship_phone, sh.shipping_address,
           sh.province_name, sh.city_name, sh.district_name,
           (SELECT COUNT(*) FROM sale_details WHERE sale_id = s.id) as item_count,
           (SELECT SUM(sd.qty * COALESCE(p.weight, 0)) 
            FROM sale_details sd 
            LEFT JOIN product_variations pv ON sd.product_variation_id = pv.id 
            LEFT JOIN products p ON pv.product_id = p.id 
            WHERE sd.sale_id = s.id) as total_weight
    FROM sales s
    LEFT JOIN users u ON s.cashier_id = u.id
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN shipping_details sh ON sh.sale_id = s.id
    $where
    ORDER BY 
        CASE s.status 
            WHEN 'Pending' THEN 1
            WHEN 'Confirmed' THEN 2 
            WHEN 'Processing' THEN 3 
            WHEN 'Ready' THEN 4 
            WHEN 'Shipped' THEN 5 
            ELSE 6 
        END,
        s.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Count per status
$statusCounts = [];
$stmtCount = $db->query("SELECT status, COUNT(*) as cnt FROM sales WHERE sale_source IN ('E-Commerce', 'Online') AND status NOT IN ('Paid','Debt') GROUP BY status");
foreach ($stmtCount->fetchAll() as $r) {
    $statusCounts[$r['status']] = $r['cnt'];
}

// Get store WA number for messaging
$storePhone = getSetting('store_phone', '08123456789');
// Convert phone to WA format (remove leading 0, add +62)
$storeWa = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $storePhone));

include INCLUDES_PATH . '/header.php';
?>

<!-- Toolbar -->
<div class="toolbar toolbar-inline-mobile">
    <div class="search-box">
        <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" class="form-control" placeholder="Cari pesanan..." id="searchOrder" oninput="filterOrderTable(this.value)">
    </div>
    
    <div class="filter-group">
        <?php 
        $importType = 'online_orders';
        $hasImport = false;
        $hasExport = true;
        $hasTemplate = false;
        include INCLUDES_PATH . '/import_export_toolbar.php'; 
        ?>
    </div>
</div>

<!-- Status Filter Tabs -->
<div class="online-status-tabs" style="display:flex; gap:8px; margin-bottom:20px; overflow-x:auto; flex-wrap:nowrap; padding-bottom:4px; -webkit-overflow-scrolling:touch;">
    <a href="?status=" class="btn btn-sm <?= !$filterStatus ? 'btn-primary' : 'btn-outline' ?>" style="flex-shrink:0;">
        Semua
    </a>
    <?php 
    $statuses = [
        'Pending' => ['label' => 'Menunggu', 'color' => 'warning'],
        'Confirmed' => ['label' => 'Dikonfirmasi', 'color' => 'info'],
        'Processing' => ['label' => 'Diproses', 'color' => 'primary'],
        'Ready' => ['label' => 'Siap Kirim', 'color' => 'success'],
        'Shipped' => ['label' => 'Dikirim', 'color' => 'gold'],
        'Completed' => ['label' => 'Selesai', 'color' => 'success'],
        'Cancelled' => ['label' => 'Dibatalkan', 'color' => 'danger'],
    ];
    foreach ($statuses as $sKey => $sInfo): ?>
        <a href="?status=<?= $sKey ?>" class="btn btn-sm <?= $filterStatus === $sKey ? 'btn-primary' : 'btn-outline' ?>" style="flex-shrink:0;">
            <?= $sInfo['label'] ?>
            <?php if (isset($statusCounts[$sKey]) && $statusCounts[$sKey] > 0): ?>
                <span class="badge badge-<?= $sInfo['color'] ?>" style="margin-left:4px;"><?= $statusCounts[$sKey] ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Orders Table -->
<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table" id="ordersTable" style="width: 100%; min-width: 900px;">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Pelanggan</th>
                        <th>Item</th>
                        <th>Berat</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Tanggal</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Tidak ada pesanan online</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): 
                            // Prepare WA link to customer
                            $custPhone = $order['cust_phone'] ?: $order['ship_phone'] ?? '';
                            $custWaNum = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $custPhone));
                            $custWaNum = preg_replace('/^\+/', '', $custWaNum);
                            $custDisplayName = $order['recipient_name'] ?: ($order['cust_name'] ?: ($order['customer_name'] ?: 'Pelanggan'));
                            $waOrderMsg = urlencode(
                                "Halo *" . $custDisplayName . "*,\n\n" .
                                "Kami dari *" . getSetting('store_name', 'Toko') . "* ingin menginformasikan update pesanan Anda:\n\n" .
                                "📦 No. Invoice: *" . $order['invoice_number'] . "*\n" .
                                "💰 Total: *" . formatRupiah($order['grand_total']) . "*\n" .
                                "📊 Status: *" . $order['status'] . "*\n\n" .
                                "Terima kasih! 🙏"
                            );
                        ?>
                            <tr data-search="<?= strtolower($order['invoice_number'] . ' ' . ($order['cust_name'] ?? '') . ' ' . ($order['customer_name'] ?? '')) ?>">
                                <td>
                                    <span class="text-bold"><?= htmlspecialchars($order['invoice_number']) ?></span>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($custDisplayName) ?></div>
                                    <?php if ($order['cust_phone']): ?>
                                        <div class="text-xs text-muted" style="display:flex; align-items:center; gap:6px;">
                                             <?php if ($custWaNum): ?>
                                                <a href="https://wa.me/<?= $custWaNum ?>?text=<?= $waOrderMsg ?>" target="_blank" title="Hubungi Pelanggan via WhatsApp" style="color:#25D366; display:inline-flex; align-items:center; opacity: 0.8; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                                                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.5.5 0 00.611.611l4.458-1.495A11.945 11.945 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-2.33 0-4.512-.67-6.36-1.827l-.356-.212-3.692 1.237 1.237-3.692-.212-.356A9.953 9.953 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                                                    </svg>
                                                </a>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($order['cust_phone']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                    // Tampilkan wilayah pengiriman (provinsi / kota / kecamatan)
                                    $wilayahParts = array_filter([
                                        $order['district_name'] ?? '',
                                        $order['city_name']     ?? '',
                                        $order['province_name'] ?? '',
                                    ]);
                                    if ($wilayahParts): ?>
                                        <div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:3px;">
                                            <?php foreach ($wilayahParts as $wp): ?>
                                                <span style="display:inline-block; font-size:0.68rem; background:var(--gray-100); color:var(--gray-600); border:1px solid var(--border-color); border-radius:4px; padding:1px 6px; white-space:nowrap;"><?= htmlspecialchars($wp) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php elseif (!empty($order['shipping_address'])): ?>
                                        <div class="text-xs text-muted" style="margin-top:3px; max-width:220px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($order['shipping_address']) ?>">
                                            📍 <?= htmlspecialchars(mb_strimwidth($order['shipping_address'], 0, 40, '…')) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $order['item_count'] ?> item</td>
                                <td>
                                    <?php 
                                    $w = $order['total_weight'] ?? 0;
                                    echo str_replace('.', ',', (float)($w / 1000)) . ' kg';
                                    ?>
                                </td>
                                <td class="text-bold">
                                    <?= formatRupiah($order['grand_total']) ?>
                                    <?php if (!empty($order['payment_method'])): ?>
                                        <div style="font-size:0.75rem; color:var(--gray-500); font-weight:normal; margin-top:4px;">
                                            💳 <?= htmlspecialchars($order['payment_method']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $badgeMap = [
                                        'Pending' => 'badge-warning',
                                        'Confirmed' => 'badge-info',
                                        'Processing' => 'badge-primary',
                                        'Ready' => 'badge-success',
                                        'Shipped' => 'badge-gold',
                                        'Completed' => 'badge-success',
                                        'Cancelled' => 'badge-danger',
                                    ];
                                    $badgeClass = $badgeMap[$order['status']] ?? 'badge-primary';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= $order['status'] ?></span>
                                </td>
                                <td>
                                    <div class="text-sm"><?= formatTanggal($order['created_at'], true) ?></div>
                                </td>
                                <td>
                                    <div class="actions" style="gap:4px;">
                                        <!-- View Details -->
                                        <button class="btn btn-sm btn-outline btn-icon" title="Detail" 
                                                onclick="viewOrderDetail(<?= $order['id'] ?>)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        
                                        <?php if ($order['status'] === 'Pending'): ?>
                                            <!-- Accept -->
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
                                                <input type="hidden" name="status" value="Confirmed">
                                                <button type="submit" class="btn btn-sm btn-primary" title="Terima" onclick="return confirm('Terima pesanan ini?')">
                                                    ✓ Terima
                                                </button>
                                            </form>
                                            <!-- Reject -->
                                            <button class="btn btn-sm btn-outline" style="color:var(--danger);" title="Tolak" 
                                                    onclick="rejectOrder(<?= $order['id'] ?>)">
                                                ✕ Tolak
                                            </button>
                                        <?php elseif ($order['status'] === 'Confirmed'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
                                                <input type="hidden" name="status" value="Processing">
                                                <button type="submit" class="btn btn-sm btn-primary">Proses</button>
                                            </form>
                                            <button class="btn btn-sm btn-outline" style="color:var(--danger);" title="Batal" onclick="rejectOrder(<?= $order['id'] ?>)">✕ Batal</button>
                                        <?php elseif ($order['status'] === 'Processing'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
                                                <input type="hidden" name="status" value="Ready">
                                                <button type="submit" class="btn btn-sm btn-primary">Siap Kirim</button>
                                            </form>
                                            <button class="btn btn-sm btn-outline" style="color:var(--danger);" title="Batal" onclick="rejectOrder(<?= $order['id'] ?>)">✕ Batal</button>
                                        <?php elseif ($order['status'] === 'Ready'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
                                                <input type="hidden" name="status" value="Shipped">
                                                <button type="submit" class="btn btn-sm btn-gold">Kirim</button>
                                            </form>
                                            <button class="btn btn-sm btn-outline" style="color:var(--danger);" title="Batal" onclick="rejectOrder(<?= $order['id'] ?>)">✕ Batal</button>
                                        <?php elseif ($order['status'] === 'Shipped'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="sale_id" value="<?= $order['id'] ?>">
                                                <input type="hidden" name="status" value="Completed">
                                                <button type="submit" class="btn btn-sm btn-primary">Selesai</button>
                                            </form>
                                            <button class="btn btn-sm btn-outline" style="color:var(--danger);" title="Batal" onclick="rejectOrder(<?= $order['id'] ?>)">✕ Batal</button>
                                        <?php endif; ?>
                                        
                                        <!-- Print -->
                                        <a href="<?= BASE_URL ?>/admin/print_invoice.php?id=<?= $order['id'] ?>&type=thermal" target="_blank" 
                                           class="btn btn-sm btn-outline btn-icon" title="Print Invoice (Thermal 58mm)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                        </a>
                                        
                                        <!-- Print Shipping Label -->
                                        <a href="<?= BASE_URL ?>/admin/print_shipping_label.php?id=<?= $order['id'] ?>" target="_blank" 
                                           class="btn btn-sm btn-outline btn-icon" title="Cetak Alamat Pengiriman">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
                                        </a>
                                        
                                        <!-- Return -->
                                        <button type="button" class="btn btn-sm btn-outline btn-icon" style="color:var(--warning); border-color:var(--warning);" title="Retur Produk" onclick="openReportReturnModal('<?= htmlspecialchars($order['invoice_number']) ?>')">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="modalReject">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <h3 class="modal-title">Tolak / Batal Pesanan</h3>
            <button class="modal-close" onclick="closeModal('modalReject')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="sale_id" id="rejectSaleId">
            <input type="hidden" name="status" value="Cancelled">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Alasan Penolakan / Pembatalan <span class="required">*</span></label>
                    <textarea name="reject_reason" class="form-control" rows="3" placeholder="Stok habis / pesanan dibatalkan / dll" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalReject')">Kembali</button>
                <button type="submit" class="btn btn-primary" style="background:var(--danger); border-color:var(--danger);">Konfirmasi Batal</button>
            </div>
        </form>
    </div>
</div>

<script>
function filterOrderTable(query) {
    const rows = document.querySelectorAll('#ordersTable tbody tr[data-search]');
    query = query.toLowerCase();
    rows.forEach(row => {
        const search = row.getAttribute('data-search');
        row.style.display = search.includes(query) ? '' : 'none';
    });
}

function rejectOrder(saleId) {
    document.getElementById('rejectSaleId').value = saleId;
    openModal('modalReject');
}

function viewOrderDetail(saleId) {
    window.open(`${BASE_URL}/admin/print_invoice.php?id=${saleId}`, '_blank', 'width=400,height=600');
}

// Auto-refresh every 30 seconds
let BASE_URL = '<?= BASE_URL ?>';
setInterval(() => {
    // Simple page reload for auto-refresh
    // A more sophisticated approach would use AJAX
}, 30000);

// Notification sound for new orders
<?php if (isset($statusCounts['Pending']) && $statusCounts['Pending'] > 0): ?>
try {
    // Play a subtle notification sound
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = audioCtx.createOscillator();
    const gainNode = audioCtx.createGain();
    oscillator.connect(gainNode);
    gainNode.connect(audioCtx.destination);
    oscillator.frequency.value = 800;
    oscillator.type = 'sine';
    gainNode.gain.value = 0.1;
    oscillator.start();
    setTimeout(() => { oscillator.stop(); }, 200);
} catch(e) {}
<?php endif; ?>
</script>

<!-- Modal: Report Return Product -->
<div class="modal-overlay" id="modalReportReturn">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header" style="background:linear-gradient(135deg, #d97706, #f59e0b); color:#fff; border-radius:var(--border-radius-lg) var(--border-radius-lg) 0 0;">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                <h3 class="modal-title" style="color:#fff;">Retur Produk</h3>
            </div>
            <button class="modal-close" onclick="closeModal('modalReportReturn')" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body" style="max-height:65vh; overflow-y:auto; padding:16px;">
            <div class="form-group" style="display:flex; gap:10px;">
                <input type="text" id="reportReturnInvoiceSearch" class="form-control" placeholder="Masukkan Nomor Invoice (Misal: INV-240815...)" style="flex:1;">
                <button class="btn btn-primary" onclick="searchInvoiceForReportReturn()">Cari</button>
            </div>
            
            <div id="reportReturnLoading" style="text-align:center; padding:30px; color:var(--gray-400); display:none;">
                <div class="spinner" style="margin: 0 auto 12px;"></div>
                Mencari invoice...
            </div>
            
            <div id="reportReturnContent" style="display:none;">
                <div id="reportReturnInvoiceInfo"></div>
                
                <div id="reportReturnItemsList" style="margin-bottom:16px;"></div>
                
                <div class="form-group">
                    <label class="form-label">Alasan Retur <span class="required">*</span></label>
                    <textarea id="reportReturnReason" class="form-control" rows="2" placeholder="Barang cacat / salah ukuran / dll"></textarea>
                </div>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Jenis Pengembalian</label>
                        <select id="reportReturnType" class="form-control">
                            <option value="Refund">Refund (Uang Kembali)</option>
                            <option value="Exchange">Tukar Barang</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metode Refund</label>
                        <select id="reportReturnPaymentMethod" class="form-control">
                            <option value="Tunai">Tunai</option>
                            <option value="Transfer Bank">Transfer Bank</option>
                            <option value="Emaal">Emaal</option>
                            <option value="BSI">BSI</option>
                        </select>
                    </div>
                </div>
                
                <div style="background:var(--gray-50); padding:12px 16px; border-radius:var(--border-radius-sm); display:flex; justify-content:space-between; align-items:center; margin-top:8px; border:1px solid var(--gray-200);">
                    <span style="font-weight:600; color:var(--gray-600);">Total Refund:</span>
                    <strong id="reportReturnTotalAmount" style="font-size:1.1rem; color:var(--danger);">Rp 0</strong>
                </div>
            </div>
        </div>
        <div class="modal-footer" id="reportReturnFooter" style="display:none;">
            <button class="btn btn-outline" onclick="closeModal('modalReportReturn')">Batal</button>
            <button class="btn btn-primary" id="btnReportSubmitReturn" onclick="submitReportReturn()" style="background:var(--warning); border-color:var(--warning); color:#fff;">Proses Retur</button>
        </div>
    </div>
</div>

<script>
let currentReportReturnSaleId = null;

function openReportReturnModal(invoiceNo = '') {
    currentReportReturnSaleId = null;
    document.getElementById('reportReturnInvoiceSearch').value = invoiceNo;
    document.getElementById('reportReturnContent').style.display = 'none';
    document.getElementById('reportReturnFooter').style.display = 'none';
    document.getElementById('reportReturnLoading').style.display = 'none';
    openModal('modalReportReturn');
    
    if (invoiceNo) {
        searchInvoiceForReportReturn();
    } else {
        setTimeout(() => document.getElementById('reportReturnInvoiceSearch').focus(), 200);
    }
}

async function searchInvoiceForReportReturn() {
    const invoiceNo = document.getElementById('reportReturnInvoiceSearch').value.trim();
    if (!invoiceNo) {
        alert('Masukkan nomor invoice!');
        return;
    }
    
    document.getElementById('reportReturnContent').style.display = 'none';
    document.getElementById('reportReturnFooter').style.display = 'none';
    document.getElementById('reportReturnLoading').style.display = 'block';
    
    try {
        const res = await fetch(`../api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'search_invoice', invoice_number: invoiceNo }),
        });
        const data = await res.json();
        
        document.getElementById('reportReturnLoading').style.display = 'none';
        
        if (data.success) {
            currentReportReturnSaleId = data.data.sale.id;
            const sale = data.data.sale;
            const items = data.data.items;
            
            document.getElementById('reportReturnInvoiceInfo').innerHTML = `
                <div style="background:#f8fafc; padding:12px 16px; border-radius:8px; display:flex; gap:16px; margin-bottom:20px; align-items:center; flex-wrap:wrap; font-size:0.95rem;">
                    <div><strong>Invoice:</strong> ${sale.invoice_number}</div>
                    <div><strong>Tgl:</strong> ${new Date(sale.created_at).toLocaleDateString('id-ID')}</div>
                    <div><strong>Total Beli:</strong> Rp ${Math.round(sale.grand_total).toLocaleString('id-ID')}</div>
                </div>
            `;
            
            let html = '<div style="font-weight:600; color:var(--gray-800); margin-bottom:12px;">Pilih item yang diretur:</div>';
            
            items.forEach(item => {
                html += `
                    <div style="background:#fff; border:1px solid var(--gray-200); border-radius:8px; padding:12px 16px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div>
                            <div style="font-weight:600; color:var(--gray-800); font-size:0.95rem; margin-bottom:4px;">${item.product_name} ${item.variation_name ? `(${item.variation_name})` : ''}</div>
                            <div style="font-size:0.85rem; color:var(--gray-500);">Harga: Rp ${Math.round(item.unit_price).toLocaleString('id-ID')} | Maks Retur: ${item.qty_returnable}</div>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="number" class="form-control report-return-qty-input" min="0" max="${item.qty_returnable}" value="0" 
                                   style="width:70px; text-align:center;"
                                   data-detail-id="${item.sale_detail_id}" data-price="${item.unit_price}" 
                                   onchange="updateReportReturnTotal()" ${item.qty_returnable <= 0 ? 'disabled' : ''}>
                            <select class="form-control report-return-condition-select" style="width:110px;" data-detail-id="${item.sale_detail_id}" ${item.qty_returnable <= 0 ? 'disabled' : ''}>
                                <option value="Bagus">Bagus</option>
                                <option value="Rusak">Rusak</option>
                            </select>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('reportReturnItemsList').innerHTML = html;
            document.getElementById('reportReturnReason').value = '';
            document.getElementById('reportReturnContent').style.display = 'block';
            document.getElementById('reportReturnFooter').style.display = 'flex';
            updateReportReturnTotal();
        } else {
            alert(data.message || 'Invoice tidak ditemukan atau tidak valid untuk diretur.');
        }
    } catch (err) {
        document.getElementById('reportReturnLoading').style.display = 'none';
        console.error(err);
        alert('Terjadi kesalahan jaringan.');
    }
}

function updateReportReturnTotal() {
    const inputs = document.querySelectorAll('.report-return-qty-input');
    let total = 0;
    inputs.forEach(input => {
        const qty = parseInt(input.value) || 0;
        const price = parseFloat(input.dataset.price) || 0;
        total += qty * price;
    });
    document.getElementById('reportReturnTotalAmount').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
}

async function submitReportReturn() {
    const reason = document.getElementById('reportReturnReason').value.trim();
    if (!reason) {
        alert('Alasan retur harus diisi!');
        return;
    }
    
    const items = [];
    document.querySelectorAll('.report-return-qty-input').forEach(input => {
        const qty = parseInt(input.value) || 0;
        if (qty > 0) {
            const detailId = input.dataset.detailId;
            const cond = document.querySelector(`.report-return-condition-select[data-detail-id="${detailId}"]`).value;
            items.push({ sale_detail_id: parseInt(detailId), qty: qty, condition: cond });
        }
    });
    
    if (items.length === 0) {
        alert('Pilih minimal satu item dan masukkan jumlah yang diretur!');
        return;
    }
    
    const btn = document.getElementById('btnReportSubmitReturn');
    const oldText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    
    try {
        const res = await fetch(`../api/sales.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                action: 'create_return',
                sale_id: currentReportReturnSaleId,
                reason: reason,
                return_type: document.getElementById('reportReturnType').value,
                payment_method: document.getElementById('reportReturnPaymentMethod').value,
                items: items,
            }),
        });
        const data = await res.json();
        
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Gagal memproses retur');
            btn.disabled = false;
            btn.textContent = oldText;
        }
    } catch (err) {
        console.error(err);
        alert('Terjadi kesalahan jaringan.');
        btn.disabled = false;
        btn.textContent = oldText;
    }
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
