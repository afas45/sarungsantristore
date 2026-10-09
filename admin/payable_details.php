<?php
/**
 * Kasir Ibtidaiyah - Detail Hutang (Payable Details)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$supplier_id = (int)($_GET['supplier_id'] ?? 0);

if (!$supplier_id) {
    flashMessage('error', 'Supplier tidak valid.');
    redirect(BASE_URL . '/admin/payables.php');
}

$stmt = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch();

if (!$supplier) {
    flashMessage('error', 'Supplier tidak ditemukan.');
    redirect(BASE_URL . '/admin/payables.php');
}

$pageTitle = 'Detail Hutang: ' . htmlspecialchars($supplier['name']);
$breadcrumbs = [
    ['label' => 'Hutang', 'url' => BASE_URL . '/admin/payables.php'],
    ['label' => htmlspecialchars($supplier['name'])]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'pay') {
    $id = (int)$_POST['payable_id'];
    $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['pay_amount'] ?? '0');
    $method = $_POST['payment_method'] ?? 'Transfer Bank';
    
    if ($amount <= 0) {
        flashMessage('error', 'Jumlah bayar harus lebih dari 0.');
    } else {
        $stmt = $db->prepare("SELECT * FROM payables WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $supplier_id]);
        $pay = $stmt->fetch();
        
        if ($pay) {
            $newPaid = min($pay['paid_amount'] + $amount, $pay['total_debt']);
            $newStatus = $newPaid >= $pay['total_debt'] ? 'Paid' : 'Partial';
            
            $db->prepare("UPDATE payables SET paid_amount = ?, status = ? WHERE id = ?")
               ->execute([$newPaid, $newStatus, $id]);
            
            $db->prepare("INSERT INTO payments (purchase_id, payable_id, payment_type, payment_method, amount, created_by) VALUES (?,?,'Outgoing',?,?,?)")
               ->execute([$pay['purchase_id'], $id, $method, $amount, $_SESSION['user_id']]);
            
            flashMessage('success', 'Pembayaran hutang berhasil dicatat.');
            logActivity('Bayar', 'Hutang', "Hutang ID: $id, Jumlah: " . formatRupiah($amount));
        }
    }
    redirect(BASE_URL . '/admin/payable_details.php?supplier_id=' . $supplier_id);
}

// Fetch all payables for this supplier
$stmt = $db->prepare("
    SELECT p.*, pu.po_number
    FROM payables p 
    JOIN purchases pu ON p.purchase_id = pu.id 
    WHERE p.supplier_id = ?
    ORDER BY p.id DESC
");
$stmt->execute([$supplier_id]);
$payables = $stmt->fetchAll();

// Fetch all payments for this supplier's payables
$stmt = $db->prepare("
    SELECT pm.*, pu.po_number, u.full_name as admin_name
    FROM payments pm
    JOIN payables p ON pm.payable_id = p.id
    JOIN purchases pu ON p.purchase_id = pu.id
    JOIN users u ON pm.created_by = u.id
    WHERE p.supplier_id = ? AND pm.payment_type = 'Outgoing'
    ORDER BY pm.payment_date DESC
");
$stmt->execute([$supplier_id]);
$payments = $stmt->fetchAll();

$totalUnpaid = 0;
$totalPaid = 0;
foreach ($payables as $p) {
    $totalPaid += $p['paid_amount'];
    if ($p['status'] !== 'Paid') $totalUnpaid += ($p['total_debt'] - $p['paid_amount']);
}

include INCLUDES_PATH . '/header.php';
?>

<div class="stats-grid mb-16" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
    <div class="stat-card warning">
        <div class="stat-icon warning">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Hutang Belum Lunas</div>
            <div class="stat-value"><?= formatRupiah($totalUnpaid) ?></div>
        </div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-icon success">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Hutang Sudah Dibayar</div>
            <div class="stat-value"><?= formatRupiah($totalPaid) ?></div>
        </div>
    </div>
</div>

<div class="card mb-24">
    <div class="card-header">
        <h3 class="card-title">Daftar Tagihan (PO)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>No. PO</th><th>Total Hutang</th><th>Dibayar</th><th>Sisa</th><th>Jatuh Tempo</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php if (empty($payables)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Belum ada tagihan</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payables as $p): ?>
                        <?php 
                        $remaining = $p['total_debt'] - $p['paid_amount'];
                        $isOverdue = $p['status'] !== 'Paid' && strtotime($p['due_date']) < time();
                        ?>
                        <tr style="<?= $isOverdue ? 'background:var(--warning-bg);' : '' ?>">
                            <td><code><?= htmlspecialchars($p['po_number']) ?></code></td>
                            <td><?= formatRupiah($p['total_debt']) ?></td>
                            <td class="text-success"><?= formatRupiah($p['paid_amount']) ?></td>
                            <td class="text-bold text-warning"><?= formatRupiah($remaining) ?></td>
                            <td>
                                <?= formatTanggal($p['due_date']) ?>
                                <?php if ($isOverdue): ?><span class="badge badge-danger">Lewat!</span><?php endif; ?>
                            </td>
                            <td>
                                <?php $sb = ['Unpaid'=>'badge-danger','Partial'=>'badge-warning','Paid'=>'badge-success']; ?>
                                <?php $sl = ['Unpaid'=>'Belum Bayar','Partial'=>'Sebagian','Paid'=>'Lunas']; ?>
                                <span class="badge <?= $sb[$p['status']] ?>"><?= $sl[$p['status']] ?></span>
                            </td>
                            <td>
                                <?php if ($p['status'] !== 'Paid'): ?>
                                    <button class="btn btn-sm btn-primary" onclick="openPayModal(<?= $p['id'] ?>, <?= $remaining ?>)">Bayar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Riwayat Pembayaran</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Waktu</th><th>No. PO</th><th>Nominal</th><th>Metode</th><th>Pencatat</th></tr></thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="5" class="text-center text-muted" style="padding:40px;">Belum ada riwayat pembayaran</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $pm): ?>
                        <tr>
                            <td><?= date('d M Y H:i', strtotime($pm['created_at'])) ?></td>
                            <td><code><?= htmlspecialchars($pm['po_number']) ?></code></td>
                            <td class="text-bold text-success"><?= formatRupiah($pm['amount']) ?></td>
                            <td><?= htmlspecialchars($pm['payment_method']) ?></td>
                            <td><?= htmlspecialchars($pm['admin_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalPay">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Catat Pembayaran Hutang</h3>
            <button class="modal-close" onclick="closeModal('modalPay')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="pay">
            <input type="hidden" name="payable_id" id="payId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Sisa Hutang</label>
                    <div style="font-size:1.25rem; font-weight:800; color:var(--warning);" id="payRemaining">Rp 0</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Jumlah Bayar <span class="required">*</span></label>
                    <input type="text" name="pay_amount" id="payAmount" class="form-control rupiah-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Metode Pembayaran</label>
                    <select name="payment_method" class="form-control">
                        <option value="Transfer Bank">Transfer Bank</option>
                        <option value="Tunai">Tunai</option>
                        <option value="Emaal">Emaal</option>
                        <option value="BSI">BSI</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalPay')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayModal(id, remaining) {
    document.getElementById('payId').value = id;
    document.getElementById('payRemaining').textContent = formatRupiah(remaining);
    document.getElementById('payAmount').value = new Intl.NumberFormat('id-ID').format(remaining);
    openModal('modalPay');
}
initRupiahInput('.rupiah-input');
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
