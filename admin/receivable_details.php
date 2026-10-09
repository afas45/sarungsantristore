<?php
/**
 * Kasir Ibtidaiyah - Detail Piutang (Receivable Details)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$customer_id = (int)($_GET['customer_id'] ?? 0);

if (!$customer_id) {
    flashMessage('error', 'Pelanggan tidak valid.');
    redirect(BASE_URL . '/admin/receivables.php');
}

$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    flashMessage('error', 'Pelanggan tidak ditemukan.');
    redirect(BASE_URL . '/admin/receivables.php');
}

$pageTitle = 'Detail Piutang: ' . htmlspecialchars($customer['name']);
$breadcrumbs = [
    ['label' => 'Piutang', 'url' => BASE_URL . '/admin/receivables.php'],
    ['label' => htmlspecialchars($customer['name'])]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'pay') {
    $id = (int)$_POST['receivable_id'];
    $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['pay_amount'] ?? '0');
    $method = $_POST['payment_method'] ?? 'Tunai';
    
    if ($amount <= 0) {
        flashMessage('error', 'Jumlah bayar harus lebih dari 0.');
    } else {
        $stmt = $db->prepare("SELECT * FROM receivables WHERE id = ? AND customer_id = ?");
        $stmt->execute([$id, $customer_id]);
        $recv = $stmt->fetch();
        
        if ($recv) {
            $newPaid = min($recv['paid_amount'] + $amount, $recv['total_debt']);
            $newStatus = $newPaid >= $recv['total_debt'] ? 'Paid' : 'Partial';
            
            $db->prepare("UPDATE receivables SET paid_amount = ?, status = ? WHERE id = ?")
               ->execute([$newPaid, $newStatus, $id]);
            
            $db->prepare("INSERT INTO payments (sale_id, receivable_id, payment_type, payment_method, amount, created_by) VALUES (?,?,'Incoming',?,?,?)")
               ->execute([$recv['sale_id'], $id, $method, $amount, $_SESSION['user_id']]);
            
            if ($newStatus === 'Paid') {
                $db->prepare("UPDATE sales SET status = 'Paid' WHERE id = ?")->execute([$recv['sale_id']]);
            }
            
            flashMessage('success', 'Pembayaran piutang berhasil dicatat.');
            logActivity('Bayar', 'Piutang', "Piutang ID: $id, Jumlah: " . formatRupiah($amount));
        }
    }
    redirect(BASE_URL . '/admin/receivable_details.php?customer_id=' . $customer_id);
}

// Fetch all receivables for this customer
$stmt = $db->prepare("
    SELECT r.*, s.invoice_number
    FROM receivables r 
    JOIN sales s ON r.sale_id = s.id 
    WHERE r.customer_id = ?
    ORDER BY r.id DESC
");
$stmt->execute([$customer_id]);
$receivables = $stmt->fetchAll();

// Fetch all payments for this customer's receivables
$stmt = $db->prepare("
    SELECT pm.*, s.invoice_number, u.full_name as admin_name
    FROM payments pm
    JOIN receivables r ON pm.receivable_id = r.id
    JOIN sales s ON r.sale_id = s.id
    JOIN users u ON pm.created_by = u.id
    WHERE r.customer_id = ? AND pm.payment_type = 'Incoming'
    ORDER BY pm.payment_date DESC
");
$stmt->execute([$customer_id]);
$payments = $stmt->fetchAll();

$totalUnpaid = 0;
$totalPaid = 0;
foreach ($receivables as $r) {
    $totalPaid += $r['paid_amount'];
    if ($r['status'] !== 'Paid') $totalUnpaid += ($r['total_debt'] - $r['paid_amount']);
}

include INCLUDES_PATH . '/header.php';
?>

<div class="stats-grid mb-16" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
    <div class="stat-card danger">
        <div class="stat-icon danger">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Piutang Belum Lunas</div>
            <div class="stat-value"><?= formatRupiah($totalUnpaid) ?></div>
        </div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-icon success">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="stat-info">
            <div class="stat-label">Piutang Sudah Dibayar</div>
            <div class="stat-value"><?= formatRupiah($totalPaid) ?></div>
        </div>
    </div>
</div>

<div class="card mb-24">
    <div class="card-header">
        <h3 class="card-title">Daftar Tagihan (Invoice)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Invoice</th><th>Total Piutang</th><th>Dibayar</th><th>Sisa</th><th>Jatuh Tempo</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php if (empty($receivables)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Belum ada tagihan</td></tr>
                    <?php endif; ?>
                    <?php foreach ($receivables as $r): ?>
                        <?php 
                        $remaining = $r['total_debt'] - $r['paid_amount'];
                        $isOverdue = $r['status'] !== 'Paid' && strtotime($r['due_date']) < time();
                        ?>
                        <tr style="<?= $isOverdue ? 'background:var(--danger-bg);' : '' ?>">
                            <td><code class="text-sm"><?= htmlspecialchars($r['invoice_number']) ?></code></td>
                            <td><?= formatRupiah($r['total_debt']) ?></td>
                            <td class="text-success"><?= formatRupiah($r['paid_amount']) ?></td>
                            <td class="text-bold text-danger"><?= formatRupiah($remaining) ?></td>
                            <td>
                                <?= formatTanggal($r['due_date']) ?>
                                <?php if ($isOverdue): ?><span class="badge badge-danger">Lewat!</span><?php endif; ?>
                            </td>
                            <td>
                                <?php $sb = ['Unpaid'=>'badge-danger','Partial'=>'badge-warning','Paid'=>'badge-success']; ?>
                                <?php $sl = ['Unpaid'=>'Belum Bayar','Partial'=>'Sebagian','Paid'=>'Lunas']; ?>
                                <span class="badge <?= $sb[$r['status']] ?>"><?= $sl[$r['status']] ?></span>
                            </td>
                            <td>
                                <?php if ($r['status'] !== 'Paid'): ?>
                                    <button class="btn btn-sm btn-primary" onclick="openPayModal(<?= $r['id'] ?>, <?= $remaining ?>)">Bayar</button>
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
                <thead><tr><th>Waktu</th><th>Invoice</th><th>Nominal</th><th>Metode</th><th>Pencatat</th></tr></thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="5" class="text-center text-muted" style="padding:40px;">Belum ada riwayat pembayaran</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $pm): ?>
                        <tr>
                            <td><?= date('d M Y H:i', strtotime($pm['created_at'])) ?></td>
                            <td><code><?= htmlspecialchars($pm['invoice_number']) ?></code></td>
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
            <h3 class="modal-title">Catat Pembayaran Piutang</h3>
            <button class="modal-close" onclick="closeModal('modalPay')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="pay">
            <input type="hidden" name="receivable_id" id="payRecvId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Sisa Piutang</label>
                    <div style="font-size:1.25rem; font-weight:800; color:var(--danger);" id="payRemaining">Rp 0</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Jumlah Bayar <span class="required">*</span></label>
                    <input type="text" name="pay_amount" id="payAmount" class="form-control rupiah-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Metode Pembayaran</label>
                    <select name="payment_method" class="form-control">
                        <option value="Tunai">Tunai</option>
                        <option value="Transfer Bank">Transfer Bank</option>
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
    document.getElementById('payRecvId').value = id;
    document.getElementById('payRemaining').textContent = formatRupiah(remaining);
    document.getElementById('payAmount').value = new Intl.NumberFormat('id-ID').format(remaining);
    openModal('modalPay');
}
initRupiahInput('.rupiah-input');
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
