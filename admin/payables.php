<?php
/**
 * Kasir Ibtidaiyah - Hutang (Payables)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Hutang';
$breadcrumbs = [['label' => 'Hutang']];

// AJAX Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_details') {
        header('Content-Type: application/json');
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT p.*, pu.po_number 
            FROM payables p 
            JOIN purchases pu ON p.purchase_id = pu.id 
            WHERE p.supplier_id = ? 
            ORDER BY p.id DESC
        ");
        $stmt->execute([$supplier_id]);
        $data = $stmt->fetchAll();
        $stmtSup = $db->prepare("SELECT name FROM suppliers WHERE id = ?");
        $stmtSup->execute([$supplier_id]);
        $supName = $stmtSup->fetchColumn();
        echo json_encode(['success' => true, 'supplier_name' => $supName, 'data' => $data]);
        exit;
    }
    
    if ($action === 'pay') {
        header('Content-Type: application/json');
        $id = (int)($_POST['payable_id'] ?? 0);
        $amount = (float)str_replace(['Rp', '.', ' '], '', $_POST['amount'] ?? '');
        $method = sanitize($_POST['payment_method'] ?? 'Transfer Bank');
        
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT * FROM payables WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $pay = $stmt->fetch();
            
            if (!$pay) throw new Exception("Data hutang tidak ditemukan.");
            $remaining = $pay['total_debt'] - $pay['paid_amount'];
            if ($amount <= 0 || $amount > $remaining) throw new Exception("Jumlah pembayaran tidak valid.");
            
            $newPaid = $pay['paid_amount'] + $amount;
            $newStatus = ($newPaid >= $pay['total_debt']) ? 'Paid' : 'Partial';
            
            $db->prepare("UPDATE payables SET paid_amount = ?, status = ? WHERE id = ?")
               ->execute([$newPaid, $newStatus, $id]);
               
            $db->prepare("INSERT INTO payments (purchase_id, payable_id, payment_type, payment_method, amount, created_by) VALUES (?,?,'Outgoing',?,?,?)")
               ->execute([$pay['purchase_id'], $id, $method, $amount, $_SESSION['user_id']]);
               
            logActivity('Bayar', 'Hutang', "Hutang ID: $id, Jumlah: " . formatRupiah($amount));
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    $supplier_id = (int)$_POST['supplier_id'];
    $purchase_id = (int)$_POST['purchase_id'];
    $total_debt = (float)str_replace(['.', ','], ['', '.'], $_POST['total_debt'] ?? '0');
    $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
    
    if ($supplier_id && $purchase_id && $total_debt > 0) {
        $db->prepare("INSERT INTO payables (supplier_id, purchase_id, total_debt, due_date) VALUES (?,?,?,?)")
           ->execute([$supplier_id, $purchase_id, $total_debt, $due_date]);
        flashMessage('success', 'Hutang berhasil dicatat.');
    }
    redirect(BASE_URL . '/admin/payables.php');
}

$status = $_GET['status'] ?? '';
$search = sanitize($_GET['search'] ?? '');

$where = "WHERE 1=1";
$params = [];
if ($status) {
    $where .= " AND p.status = ?";
    $params[] = $status;
}

if ($search) {
    $where .= " AND (s.name LIKE ?)";
    $params[] = "%$search%";
}

$stmt = $db->prepare("
    SELECT s.id as supplier_id, s.name as supplier_name,
           COUNT(p.id) as total_po,
           SUM(p.total_debt) as total_debt,
           SUM(p.paid_amount) as paid_amount,
           (SUM(p.total_debt) - SUM(p.paid_amount)) as remaining_debt
    FROM payables p 
    JOIN suppliers s ON p.supplier_id = s.id 
    $where 
    GROUP BY s.id, s.name
    ORDER BY remaining_debt DESC
");
$stmt->execute($params);
$payables = $stmt->fetchAll();

$totalUnpaid = 0;
$totalPaid = 0;
foreach ($payables as $p) {
    $totalPaid += $p['paid_amount'];
    $totalUnpaid += $p['remaining_debt'];
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

<div class="toolbar">
    <form method="GET" class="search-box" style="flex:1; max-width:100%;">
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
        <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" name="search" class="form-control" placeholder="Cari PO atau Supplier..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
    </form>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:nowrap;">
        <div class="filter-group" style="flex-wrap:nowrap;">
            <a href="?status=" class="btn btn-sm <?= !$status ? 'btn-primary' : 'btn-outline' ?>">Semua</a>
            <a href="?status=Unpaid" class="btn btn-sm <?= $status==='Unpaid' ? 'btn-primary' : 'btn-outline' ?>">Belum Bayar</a>
            <a href="?status=Paid" class="btn btn-sm <?= $status==='Paid' ? 'btn-primary' : 'btn-outline' ?>">Lunas</a>
        </div>
        <?php 
        $importType = 'payables';
        $hasImport = false;
        $hasExport = true;
        $hasTemplate = false;
        include INCLUDES_PATH . '/import_export_toolbar.php'; 
        ?>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Supplier</th><th>Jumlah PO</th><th>Total Hutang</th><th>Dibayar</th><th>Sisa</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php if (empty($payables)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Tidak ada data hutang</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payables as $p): ?>
                        <?php 
                        $rem = (float)$p['remaining_debt'];
                        ?>
                        <tr>
                            <td class="text-bold"><?= htmlspecialchars($p['supplier_name']) ?></td>
                            <td><?= $p['total_po'] ?> Transaksi</td>
                            <td><?= formatRupiah($p['total_debt']) ?></td>
                            <td class="text-success"><?= formatRupiah($p['paid_amount']) ?></td>
                            <td class="text-bold">
                                <?php if ($rem > 0): ?>
                                    <span style="color:#dc2626; font-weight: bold;">- <?= formatRupiah($rem) ?></span>
                                <?php elseif ($rem < 0): ?>
                                    <span style="color:#d97706; font-weight: bold;">+ <?= formatRupiah(abs($rem)) ?></span>
                                <?php else: ?>
                                    <span style="color:#16a34a;">✓ Lunas</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rem > 0): ?>
                                    <span class="badge badge-danger">Kurang</span>
                                <?php elseif ($rem < 0): ?>
                                    <span class="badge" style="background:#fef3c7; color:#d97706;">Lebih</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Lunas</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="openPayableModal(<?= $p['supplier_id'] ?>, '<?= htmlspecialchars(addslashes($p['supplier_name'])) ?>')">Lihat Detail</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Detail Hutang -->
<div class="modal-overlay" id="modalPayableDetails">
    <div class="modal" style="max-width:800px;">
        <div class="modal-header" style="background:#fffaf0; border-bottom: 1px solid var(--gray-200);">
            <h3 class="modal-title" id="payableModalTitle" style="color:var(--gray-900);">Riwayat Hutang</h3>
            <button class="modal-close" onclick="closeModal('modalPayableDetails')">&times;</button>
        </div>
        <div class="modal-body" style="padding:0; max-height:70vh; overflow-y:auto;">
            <div id="payableLoading" style="padding:40px; text-align:center; color:var(--gray-500); display:none;">
                <div class="spinner" style="margin: 0 auto 10px;"></div>
                Memuat data...
            </div>
            <div class="table-responsive" style="margin:0;">
                <table class="table" id="payableTable" style="margin:0;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th style="font-size:0.75rem; color:var(--gray-600);">INVOICE (PO)</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">JATUH TEMPO</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">TOTAL HUTANG</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">DIBAYAR</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">SISA</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">STATUS</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">AKSI</th>
                        </tr>
                    </thead>
                    <tbody id="payableTbody">
                        <!-- Dimuat via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Bayar Hutang -->
<div class="modal-overlay" id="modalPayPayable">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h3 class="modal-title">Pembayaran Hutang</h3>
            <button class="modal-close" onclick="closeModal('modalPayPayable')">&times;</button>
        </div>
        <form id="payPayableForm" onsubmit="submitPayPayable(event)">
            <input type="hidden" id="pay_payable_id" name="payable_id">
            <input type="hidden" id="pay_supplier_id" name="supplier_id">
            <input type="hidden" id="pay_supplier_name" name="supplier_name">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Sisa Hutang</label>
                    <div id="pay_payable_remaining_text" style="font-size:1.5rem; font-weight:bold; color:var(--danger);">Rp 0</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Metode Pembayaran</label>
                    <select id="pay_payable_method" name="payment_method" class="form-control" required>
                        <option value="Transfer Bank">Transfer Bank</option>
                        <option value="Tunai">Tunai</option>
                        <option value="Emaal">Emaal</option>
                        <option value="BSI">BSI</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Jumlah Bayar (Rp)</label>
                    <input type="text" id="pay_payable_amount" name="amount" class="form-control" required oninput="validatePayAmount(this)" onclick="this.select()">
                    <div id="payAmountFeedback" style="margin-top:6px; font-size:0.82rem; display:none;"></div>
                    <div style="margin-top:8px; display:flex; gap:8px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="setPayPayableAmountFull()">Bayar Penuh</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalPayPayable')">Batal</button>
                <button type="submit" class="btn btn-success" id="btnSubmitPayPayable">Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentPayableMaxAmount = 0;

function formatRupiahJs(angka, prefix) {
    angka = Math.round(parseFloat(angka) || 0);
    var number_string = angka.toString().replace(/[^,\d]/g, ''),
    split   = number_string.split(','),
    sisa    = split[0].length % 3,
    rupiah  = split[0].substr(0, sisa),
    ribuan  = split[0].substr(sisa).match(/\d{3}/gi);
    if(ribuan){
        separator = sisa ? '.' : '';
        rupiah += separator + ribuan.join('.');
    }
    rupiah = split[1] != undefined ? rupiah + ',' + split[1] : rupiah;
    return prefix == undefined ? rupiah : (rupiah ? 'Rp ' + rupiah : '');
}

function formatDateJs(dateStr) {
    if(!dateStr) return '-';
    const d = new Date(dateStr);
    return d.getDate() + '/' + (d.getMonth()+1) + '/' + d.getFullYear();
}

async function openPayableModal(supplierId, supplierName) {
    document.getElementById('payableModalTitle').textContent = 'Riwayat Hutang: ' + supplierName;
    document.getElementById('payableTbody').innerHTML = '';
    document.getElementById('payableTable').style.display = 'none';
    document.getElementById('payableLoading').style.display = 'block';
    openModal('modalPayableDetails');
    
    try {
        const fd = new FormData();
        fd.append('action', 'get_details');
        fd.append('supplier_id', supplierId);
        
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        
        document.getElementById('payableLoading').style.display = 'none';
        document.getElementById('payableTable').style.display = 'table';
        
        if (data.success && data.data.length > 0) {
            let html = '';
            const now = new Date().getTime();
            data.data.forEach(r => {
                const remaining = parseFloat(r.total_debt) - parseFloat(r.paid_amount);
                const isOverdue = (r.status !== 'Paid' && new Date(r.due_date).getTime() < now);
                const bg = r.status === 'Paid' ? '' : '#fffaf0';
                
                let badgeClass = 'badge-danger';
                let badgeText = 'Kurang';
                let badgeStyle = 'background:#fee2e2; color:#b91c1c;';

                if (remaining < 0) {
                    badgeClass = 'badge-warning';
                    badgeText = 'Lebih';
                    badgeStyle = 'background:#fef3c7; color:#b45309;';
                } else if (remaining === 0) {
                    badgeClass = 'badge-success';
                    badgeText = 'Lunas';
                    badgeStyle = 'background:#dcfce7; color:#166534;';
                }
                
                let actionBtn = `<div style="display:flex; gap:4px;">`;
                if (remaining > 0) {
                    actionBtn += `<button class="btn btn-sm" style="background:#d97706; border-color:#d97706; color:#fff; padding:4px 8px;" onclick="openPayPayableModal(${r.id}, ${remaining}, ${supplierId}, '${supplierName.replace(/'/g, "\\'")}')">Bayar</button>`;
                }
                actionBtn += `</div>`;
                
                let sisaStr = '';
                if (remaining > 0) sisaStr = '- ' + formatRupiahJs(remaining, 'Rp ');
                else if (remaining < 0) sisaStr = '+ ' + formatRupiahJs(Math.abs(remaining), 'Rp ');
                else sisaStr = 'Rp 0';
                
                html += `
                    <tr style="background:${bg}; border-bottom:1px solid #f1f5f9;">
                        <td><code style="color:var(--gray-600); background:none; padding:0;">${r.po_number}</code></td>
                        <td style="color:var(--gray-600);">${formatDateJs(r.due_date)}</td>
                        <td style="color:var(--gray-600);">${formatRupiahJs(r.total_debt, 'Rp ')}</td>
                        <td style="color:var(--gray-600);">${formatRupiahJs(r.paid_amount, 'Rp ')}</td>
                        <td style="font-weight:bold; color:var(--gray-800);">${sisaStr}</td>
                        <td><span class="badge ${badgeClass}" style="${badgeStyle}; border-radius:12px; padding:4px 10px;">${badgeText}</span></td>
                        <td>${actionBtn}</td>
                    </tr>
                `;
            });
            document.getElementById('payableTbody').innerHTML = html;
        } else {
            document.getElementById('payableTbody').innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:30px;">Tidak ada tagihan hutang</td></tr>';
        }
    } catch (err) {
        document.getElementById('payableLoading').style.display = 'none';
        alert('Gagal mengambil data tagihan.');
    }
}

function openPayPayableModal(payId, remaining, supplierId, supplierName) {
    currentPayableMaxAmount = remaining;
    document.getElementById('pay_payable_id').value = payId;
    document.getElementById('pay_supplier_id').value = supplierId;
    document.getElementById('pay_supplier_name').value = supplierName;
    document.getElementById('pay_payable_remaining_text').textContent = formatRupiahJs(remaining, 'Rp ');
    document.getElementById('pay_payable_amount').value = formatRupiahJs(remaining, '');
    openModal('modalPayPayable');
}

function setPayPayableAmountFull() {
    document.getElementById('pay_payable_amount').value = formatRupiahJs(currentPayableMaxAmount, '');
    validatePayAmount(document.getElementById('pay_payable_amount'));
}

function validatePayAmount(input) {
    formatRupiahInput(input);
    const rawVal = input.value.replace(/[^0-9]/g, '');
    const paid = parseInt(rawVal) || 0;
    const feedbackEl = document.getElementById('payAmountFeedback');
    const submitBtn = document.getElementById('btnSubmitPayPayable');
    
    if (!feedbackEl) return;
    
    if (paid > currentPayableMaxAmount) {
        const lebih = paid - currentPayableMaxAmount;
        feedbackEl.style.display = 'block';
        feedbackEl.style.color = '#dc2626';
        feedbackEl.textContent = '⚠️ Kelebihan Rp ' + formatRupiahJs(lebih) + ' dari sisa hutang! Maksimum: Rp ' + formatRupiahJs(currentPayableMaxAmount);
        if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; }
    } else if (paid < currentPayableMaxAmount && paid > 0) {
        const sisa = currentPayableMaxAmount - paid;
        feedbackEl.style.display = 'block';
        feedbackEl.style.color = '#d97706';
        feedbackEl.textContent = 'DP / Sebagian — Sisa Rp ' + formatRupiahJs(sisa) + ' masih tercatat sebagai hutang.';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
    } else if (paid === currentPayableMaxAmount) {
        feedbackEl.style.display = 'block';
        feedbackEl.style.color = '#16a34a';
        feedbackEl.textContent = '✓ Pembayaran penuh — hutang akan lunas.';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
    } else {
        feedbackEl.style.display = 'none';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
    }
}

async function submitPayPayable(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitPayPayable');
    const oldTxt = btn.textContent;
    btn.textContent = 'Menyimpan...';
    btn.disabled = true;
    
    try {
        const fd = new FormData(e.target);
        fd.append('action', 'pay');
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('modalPayPayable');
            // Refresh modal list
            openPayableModal(document.getElementById('pay_supplier_id').value, document.getElementById('pay_supplier_name').value);
        } else {
            alert(data.message || 'Gagal menyimpan pembayaran.');
        }
    } catch (err) {
        alert('Gagal menghubungi server.');
    } finally {
        btn.textContent = oldTxt;
        btn.disabled = false;
    }
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
