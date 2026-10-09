<?php
/**
 * Kasir Ibtidaiyah - Piutang (Receivables)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Piutang';
$breadcrumbs = [['label' => 'Piutang']];

// AJAX Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_details') {
        header('Content-Type: application/json');
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT r.*, s.invoice_number 
            FROM receivables r 
            JOIN sales s ON r.sale_id = s.id 
            WHERE r.customer_id = ? 
            ORDER BY r.id DESC
        ");
        $stmt->execute([$customer_id]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }
    
    if ($action === 'pay') {
        header('Content-Type: application/json');
        $id = (int)($_POST['receivable_id'] ?? 0);
        $amount = (float)str_replace(['Rp', '.', ' '], '', $_POST['amount'] ?? '');
        $method = sanitize($_POST['payment_method'] ?? 'Tunai');
        
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT * FROM receivables WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $rec = $stmt->fetch();
            
            if (!$rec) throw new Exception("Data piutang tidak ditemukan.");
            $remaining = $rec['total_debt'] - $rec['paid_amount'];
            if ($amount <= 0 || $amount > $remaining) throw new Exception("Jumlah pembayaran tidak valid.");
            
            $newPaid = $rec['paid_amount'] + $amount;
            $newStatus = ($newPaid >= $rec['total_debt']) ? 'Paid' : 'Partial';
            
            $db->prepare("UPDATE receivables SET paid_amount = ?, status = ? WHERE id = ?")
               ->execute([$newPaid, $newStatus, $id]);
               
            $db->prepare("INSERT INTO payments (sale_id, receivable_id, payment_type, payment_method, amount, created_by) VALUES (?,?,'Incoming',?,?,?)")
               ->execute([$rec['sale_id'], $id, $method, $amount, $_SESSION['user_id']]);
               
            logActivity('Bayar', 'Piutang', "Piutang ID: $id, Jumlah: " . formatRupiah($amount));
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Filter
$status = $_GET['status'] ?? '';
$search = sanitize($_GET['search'] ?? '');

$where = "WHERE 1=1";
$params = [];
if ($status) {
    if ($status === 'Unpaid') {
        $where .= " AND r.status != 'Paid'";
    } else {
        $where .= " AND r.status = 'Paid'";
    }
}

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $db->prepare("
    SELECT r.customer_id, c.name as customer_name, c.phone,
           SUM(r.total_debt) as total_debt,
           SUM(r.paid_amount) as paid_amount,
           SUM(r.total_debt - r.paid_amount) as remaining_debt,
           COUNT(r.id) as total_invoices,
           SUM(CASE WHEN r.status != 'Paid' THEN 1 ELSE 0 END) as unpaid_invoices
    FROM receivables r 
    JOIN customers c ON r.customer_id = c.id 
    $where 
    GROUP BY r.customer_id, c.name, c.phone
    ORDER BY SUM(r.total_debt - r.paid_amount) DESC
");
$stmt->execute($params);
$receivables = $stmt->fetchAll();

// Total summary
$totalUnpaid = 0;
$totalOverdue = 0;
$totalPaid = 0;
foreach ($receivables as $r) {
    $totalPaid += $r['paid_amount'];
    $totalUnpaid += $r['remaining_debt'];
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
            <?php if ($totalOverdue): ?>
                <div class="stat-change down"><?= $totalOverdue ?> jatuh tempo</div>
            <?php endif; ?>
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

<div class="toolbar">
    <form method="GET" class="search-box" style="flex:1; max-width:100%;">
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
        <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" name="search" class="form-control" placeholder="Cari Invoice atau Pelanggan..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
    </form>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:nowrap;">
        <div class="filter-group" style="flex-wrap:nowrap;">
            <a href="?status=" class="btn btn-sm <?= !$status ? 'btn-primary' : 'btn-outline' ?>">Semua</a>
            <a href="?status=Unpaid" class="btn btn-sm <?= $status==='Unpaid' ? 'btn-primary' : 'btn-outline' ?>">Belum Bayar</a>
            <a href="?status=Paid" class="btn btn-sm <?= $status==='Paid' ? 'btn-primary' : 'btn-outline' ?>">Lunas</a>
        </div>
        <?php 
        $importType = 'receivables';
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
                <thead><tr><th>Pelanggan</th><th>Total Piutang</th><th>Dibayar</th><th>Sisa Piutang</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php if (empty($receivables)): ?>
                        <tr><td colspan="6" class="text-center text-muted" style="padding:40px;">Tidak ada data piutang</td></tr>
                    <?php endif; ?>
                    <?php foreach ($receivables as $r): ?>
                        <?php $rem = (float)$r['remaining_debt']; ?>
                        <tr>
                            <td>
                                <div class="text-bold"><?= htmlspecialchars($r['customer_name']) ?></div>
                                <div class="text-xs text-muted"><?= $r['phone'] ?></div>
                            </td>
                            <td><?= formatRupiah($r['total_debt']) ?></td>
                            <td class="text-success"><?= formatRupiah($r['paid_amount']) ?></td>
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
                                <button type="button" class="btn btn-sm btn-primary" onclick="openReceivableModal(<?= $r['customer_id'] ?>, '<?= htmlspecialchars(addslashes($r['customer_name'])) ?>')">Lihat Detail</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<!-- Modal: Detail Piutang -->
<div class="modal-overlay" id="modalReceivableDetails">
    <div class="modal" style="max-width:800px;">
        <div class="modal-header" style="background:#fffaf0; border-bottom: 1px solid var(--gray-200);">
            <h3 class="modal-title" id="receivableModalTitle" style="color:var(--gray-900);">Riwayat Piutang</h3>
            <button class="modal-close" onclick="closeModal('modalReceivableDetails')">&times;</button>
        </div>
        <div class="modal-body" style="padding:0; max-height:70vh; overflow-y:auto;">
            <div id="receivableLoading" style="padding:40px; text-align:center; color:var(--gray-500); display:none;">
                <div class="spinner" style="margin: 0 auto 10px;"></div>
                Memuat data...
            </div>
            <div class="table-responsive" style="margin:0;">
                <table class="table" id="receivableTable" style="margin:0;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th style="font-size:0.75rem; color:var(--gray-600);">INVOICE</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">JATUH TEMPO</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">TOTAL PIUTANG</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">DIBAYAR</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">SISA</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">STATUS</th>
                            <th style="font-size:0.75rem; color:var(--gray-600);">AKSI</th>
                        </tr>
                    </thead>
                    <tbody id="receivableTbody">
                        <!-- Dimuat via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Bayar Piutang -->
<div class="modal-overlay" id="modalPayReceivable">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h3 class="modal-title">Pembayaran Piutang</h3>
            <button class="modal-close" onclick="closeModal('modalPayReceivable')">&times;</button>
        </div>
        <form id="payReceivableForm" onsubmit="submitPayReceivable(event)">
            <input type="hidden" id="pay_receivable_id" name="receivable_id">
            <input type="hidden" id="pay_customer_id" name="customer_id">
            <input type="hidden" id="pay_customer_name" name="customer_name">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Sisa Piutang</label>
                    <div id="pay_remaining_text" style="font-size:1.5rem; font-weight:bold; color:var(--danger);">Rp 0</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Metode Pembayaran</label>
                    <select id="pay_method" name="payment_method" class="form-control" required>
                        <option value="Tunai">Tunai</option>
                        <option value="Transfer Bank">Transfer Bank</option>
                        <option value="Emaal">Emaal</option>
                        <option value="BSI">BSI</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Jumlah Bayar (Rp)</label>
                    <input type="text" id="pay_amount" name="amount" class="form-control" required oninput="validateRecPayAmount(this)" onclick="this.select()">
                    <div id="recPayAmountFeedback" style="margin-top:6px; font-size:0.82rem; display:none;"></div>
                    <div style="margin-top:8px; display:flex; gap:8px;">
                        <button type="button" class="btn btn-sm btn-outline" onclick="setPayAmountFull()">Bayar Penuh</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalPayReceivable')">Batal</button>
                <button type="submit" class="btn btn-success" id="btnSubmitPayRec">Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentMaxAmount = 0;

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

async function openReceivableModal(customerId, customerName) {
    document.getElementById('receivableModalTitle').textContent = 'Riwayat Piutang: ' + customerName;
    document.getElementById('receivableTbody').innerHTML = '';
    document.getElementById('receivableTable').style.display = 'none';
    document.getElementById('receivableLoading').style.display = 'block';
    openModal('modalReceivableDetails');
    
    try {
        const fd = new FormData();
        fd.append('action', 'get_details');
        fd.append('customer_id', customerId);
        
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        
        document.getElementById('receivableLoading').style.display = 'none';
        document.getElementById('receivableTable').style.display = 'table';
        
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
                    actionBtn += `<button class="btn btn-sm" style="background:#d97706; border-color:#d97706; color:#fff; padding:4px 8px;" onclick="openPayModal(${r.id}, ${remaining}, ${customerId}, '${customerName.replace(/'/g, "\\'")}')">Bayar</button>`;
                }
                actionBtn += `</div>`;
                
                let sisaStr = '';
                if (remaining > 0) sisaStr = '- ' + formatRupiahJs(remaining, 'Rp ');
                else if (remaining < 0) sisaStr = '+ ' + formatRupiahJs(Math.abs(remaining), 'Rp ');
                else sisaStr = 'Rp 0';
                
                html += `
                    <tr style="background:${bg}; border-bottom:1px solid #f1f5f9;">
                        <td><code style="color:var(--gray-600); background:none; padding:0;">${r.invoice_number}</code></td>
                        <td style="color:var(--gray-600);">${formatDateJs(r.due_date)}</td>
                        <td style="color:var(--gray-600);">${formatRupiahJs(r.total_debt, 'Rp ')}</td>
                        <td style="color:var(--gray-600);">${formatRupiahJs(r.paid_amount, 'Rp ')}</td>
                        <td style="font-weight:bold; color:var(--gray-800);">${sisaStr}</td>
                        <td><span class="badge ${badgeClass}" style="${badgeStyle}; border-radius:12px; padding:4px 10px;">${badgeText}</span></td>
                        <td>${actionBtn}</td>
                    </tr>
                `;
            });
            document.getElementById('receivableTbody').innerHTML = html;
        } else {
            document.getElementById('receivableTbody').innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:30px;">Tidak ada tagihan</td></tr>';
        }
    } catch (err) {
        document.getElementById('receivableLoading').style.display = 'none';
        alert('Gagal mengambil data tagihan.');
    }
}

function openPayModal(recId, remaining, customerId, customerName) {
    currentMaxAmount = remaining;
    document.getElementById('pay_receivable_id').value = recId;
    document.getElementById('pay_customer_id').value = customerId;
    document.getElementById('pay_customer_name').value = customerName;
    document.getElementById('pay_remaining_text').textContent = formatRupiahJs(remaining, 'Rp ');
    document.getElementById('pay_amount').value = formatRupiahJs(remaining, '');
    openModal('modalPayReceivable');
}

function setPayAmountFull() {
    document.getElementById('pay_amount').value = formatRupiahJs(currentMaxAmount, '');
    validateRecPayAmount(document.getElementById('pay_amount'));
}

function validateRecPayAmount(input) {
    formatRupiahInput(input);
    const rawVal = input.value.replace(/[^0-9]/g, '');
    const paid = parseInt(rawVal) || 0;
    const feedbackEl = document.getElementById('recPayAmountFeedback');
    const submitBtn = document.getElementById('btnSubmitPayRec');
    
    if (!feedbackEl) return;
    
    if (paid > currentMaxAmount) {
        const lebih = paid - currentMaxAmount;
        feedbackEl.style.display = 'block';
        feedbackEl.style.color = '#dc2626';
        feedbackEl.textContent = '⚠️ Kelebihan Rp ' + formatRupiahJs(lebih) + ' dari sisa piutang! Maksimum: Rp ' + formatRupiahJs(currentMaxAmount);
        if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; }
    } else if (paid < currentMaxAmount && paid > 0) {
        const sisa = currentMaxAmount - paid;
        feedbackEl.style.display = 'block';
        feedbackEl.style.color = '#d97706';
        feedbackEl.textContent = 'DP / Sebagian — Sisa Rp ' + formatRupiahJs(sisa) + ' masih tercatat sebagai piutang.';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
    } else if (paid === currentMaxAmount) {
        feedbackEl.style.display = 'block';
        feedbackEl.style.color = '#16a34a';
        feedbackEl.textContent = '✓ Pembayaran penuh — piutang akan lunas.';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
    } else {
        feedbackEl.style.display = 'none';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
    }
}

async function submitPayReceivable(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitPayRec');
    const oldTxt = btn.textContent;
    btn.textContent = 'Menyimpan...';
    btn.disabled = true;
    
    try {
        const fd = new FormData(e.target);
        fd.append('action', 'pay');
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('modalPayReceivable');
            // Refresh modal list
            openReceivableModal(document.getElementById('pay_customer_id').value, document.getElementById('pay_customer_name').value);
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
