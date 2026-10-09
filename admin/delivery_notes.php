<?php
/**
 * Kasir Ibtidaiyah - Surat Jalan
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Surat Jalan';
$breadcrumbs = [['label' => 'Pembelian', 'url' => BASE_URL . '/admin/purchases.php'], ['label' => 'Surat Jalan']];

$purchaseId = (int)($_GET['purchase_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_dn') {
        $purchase_id = (int)$_POST['purchase_id'];
        $sj_number = sanitize($_POST['sj_number'] ?? '');
        $received_date = $_POST['received_date'] ?? date('Y-m-d');
        $items = $_POST['items'] ?? [];
        
        if (empty($sj_number) || empty($items)) {
            flashMessage('error', 'Nomor surat jalan dan item harus diisi.');
        } else {
            try {
                $db->beginTransaction();
                
                $stmt = $db->prepare("INSERT INTO delivery_notes (purchase_id, surat_jalan_number, received_date, receiver_id) VALUES (?,?,?,?)");
                $stmt->execute([$purchase_id, $sj_number, $received_date, $_SESSION['user_id']]);
                $dnId = $db->lastInsertId();
                
                foreach ($items as $item) {
                    $prodId = (int)$item['product_id'];
                    $qtyReceived = (int)$item['qty_received'];
                    
                    if ($qtyReceived <= 0) continue;
                    
                    $stmt = $db->prepare("INSERT INTO delivery_note_items (delivery_note_id, product_id, qty_received) VALUES (?,?,?)");
                    $stmt->execute([$dnId, $prodId, $qtyReceived]);
                    
                    // --- UPDATE STOCK (Variation Random/Default) ---
                    $varStmt = $db->prepare("SELECT id, stock_qty FROM product_variations WHERE product_id = ? ORDER BY id ASC LIMIT 1");
                    $varStmt->execute([$prodId]);
                    $variation = $varStmt->fetch();
                    
                    if ($variation) {
                        $oldStock = (int)$variation['stock_qty'];
                        $newStock = $oldStock + $qtyReceived;
                        $db->prepare("UPDATE product_variations SET stock_qty = ? WHERE id = ?")
                           ->execute([$newStock, $variation['id']]);
                        
                        // Insert to stock_logs
                        $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, 'restok', ?, ?)")
                           ->execute([$variation['id'], $qtyReceived, $oldStock, $newStock, "Penerimaan Surat Jalan: $sj_number", $_SESSION['user_id']]);
                    } else {
                        $prodStmt = $db->prepare("SELECT sku FROM products WHERE id = ?");
                        $prodStmt->execute([$prodId]);
                        $prod = $prodStmt->fetch();
                        $defSku = ($prod['sku'] ? $prod['sku'] : 'PRD-' . $prodId) . '-DEF';
                        
                        $db->prepare("INSERT INTO product_variations (product_id, sku, variation_name, stock_qty, base_price) VALUES (?, ?, 'Default', ?, 0)")
                           ->execute([$prodId, $defSku, $qtyReceived]);
                        $newVarId = $db->lastInsertId();
                        
                        // Insert to stock_logs
                        $db->prepare("INSERT INTO stock_logs (product_variation_id, qty_change, qty_before, qty_after, change_type, note, created_by) VALUES (?, ?, ?, ?, 'restok', ?, ?)")
                           ->execute([$newVarId, $qtyReceived, 0, $qtyReceived, "Penerimaan Surat Jalan: $sj_number", $_SESSION['user_id']]);
                    }
                    
                    // --- UPDATE HPP (Modal) ---
                    $stmtCost = $db->prepare("SELECT unit_cost FROM purchase_details WHERE purchase_id = ? AND product_id = ? LIMIT 1");
                    $stmtCost->execute([$purchase_id, $prodId]);
                    $unitCost = (float)$stmtCost->fetchColumn();
                    if ($unitCost > 0) {
                        $stmtCurr = $db->prepare("SELECT base_price FROM products WHERE id = ?");
                        $stmtCurr->execute([$prodId]);
                        $currBasePrice = (float)$stmtCurr->fetchColumn();
                        if ($currBasePrice != $unitCost) {
                            $lastBase = $currBasePrice > 0 ? $currBasePrice : $unitCost;
                            $db->prepare("UPDATE products SET last_base_price = ?, base_price = ? WHERE id = ?")->execute([$lastBase, $unitCost, $prodId]);
                        } else {
                            $db->prepare("UPDATE products SET last_base_price = ? WHERE id = ?")->execute([$currBasePrice, $prodId]);
                        }
                    }
                }
                
                // Update PO status
                $db->prepare("UPDATE purchases SET status = 'Completed' WHERE id = ?")->execute([$purchase_id]);
                
                // Hitung total nilai barang yang sudah diterima untuk PO ini
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(dni.qty_received * pd.unit_cost), 0)
                    FROM delivery_note_items dni
                    JOIN delivery_notes dn ON dni.delivery_note_id = dn.id
                    JOIN purchase_details pd ON pd.purchase_id = dn.purchase_id AND pd.product_id = dni.product_id
                    WHERE dn.purchase_id = ?
                ");
                $stmt->execute([$purchase_id]);
                $totalReceivedValue = (float)$stmt->fetchColumn();
                
                if ($totalReceivedValue > 0) {
                    $poData = $db->prepare("SELECT supplier_id, date, paid_amount FROM purchases WHERE id = ?");
                    $poData->execute([$purchase_id]);
                    $poInfo = $poData->fetch();
                    
                    if ($poInfo) {
                        $checkPay = $db->prepare("SELECT id, paid_amount FROM payables WHERE purchase_id = ?");
                        $checkPay->execute([$purchase_id]);
                        $payable = $checkPay->fetch();
                        
                        if ($payable) {
                            $paid = (float)$payable['paid_amount'];
                            $new_status = ($paid >= $totalReceivedValue) ? 'Paid' : (($paid > 0) ? 'Partial' : 'Unpaid');
                            $db->prepare("UPDATE payables SET total_debt = ?, status = ? WHERE purchase_id = ?")->execute([$totalReceivedValue, $new_status, $purchase_id]);
                        } else {
                            $due_date = date('Y-m-d', strtotime($poInfo['date'] . ' + 30 days'));
                            $po_paid = (float)$poInfo['paid_amount'];
                            $new_status = ($po_paid >= $totalReceivedValue) ? 'Paid' : (($po_paid > 0) ? 'Partial' : 'Unpaid');
                            $db->prepare("INSERT INTO payables (supplier_id, purchase_id, total_debt, paid_amount, due_date, status) VALUES (?,?,?,?,?,?)")
                               ->execute([$poInfo['supplier_id'], $purchase_id, $totalReceivedValue, $po_paid, $due_date, $new_status]);
                        }
                    }
                }
                
                $db->commit();
                flashMessage('success', 'Surat Jalan berhasil dicatat. Stok telah diperbarui.');
                logActivity('Tambah', 'Surat Jalan', "SJ: $sj_number, PO ID: $purchase_id");
            } catch (Exception $e) {
                $db->rollBack();
                flashMessage('error', 'Gagal: ' . $e->getMessage());
            }
        }
        
        redirect(BASE_URL . '/admin/delivery_notes.php');
    }
    
    if ($action === 'get_dn_details') {
        header('Content-Type: application/json');
        $dn_id = (int)($_POST['dn_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT dni.*, p.name as product_name, p.sku
            FROM delivery_note_items dni
            JOIN products p ON dni.product_id = p.id
            WHERE dni.delivery_note_id = ?
        ");
        $stmt->execute([$dn_id]);
        echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
        exit;
    }
}

$search = sanitize($_GET['search'] ?? '');
$where = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND (dn.surat_jalan_number LIKE ? OR p.po_number LIKE ? OR s.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Fetch delivery notes
$stmt = $db->prepare("
    SELECT dn.*, p.po_number, s.name as supplier_name, u.full_name as receiver_name,
           (SELECT COALESCE(SUM(qty), 0) FROM purchase_details WHERE purchase_id = dn.purchase_id) as total_po_qty,
           (SELECT COALESCE(SUM(qty_received), 0) FROM delivery_note_items WHERE delivery_note_id = dn.id) as total_received_qty
    FROM delivery_notes dn 
    JOIN purchases p ON dn.purchase_id = p.id 
    JOIN suppliers s ON p.supplier_id = s.id
    JOIN users u ON dn.receiver_id = u.id
    $where
    ORDER BY dn.created_at DESC LIMIT 100
");
$stmt->execute($params);
$dnList = $stmt->fetchAll();

// PO details if creating new DN
$poDetails = null;
if ($purchaseId) {
    $stmt = $db->prepare("
        SELECT p.*, s.name as supplier_name 
        FROM purchases p JOIN suppliers s ON p.supplier_id = s.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$purchaseId]);
    $poDetails = $stmt->fetch();
    
    if ($poDetails) {
        $stmt = $db->prepare("
            SELECT pd.*, pr.sku, pr.name as product_name, pr.id as product_id
            FROM purchase_details pd
            JOIN products pr ON pd.product_id = pr.id
            WHERE pd.purchase_id = ?
        ");
        $stmt->execute([$purchaseId]);
        $poDetails['items'] = $stmt->fetchAll();
    }
}

include INCLUDES_PATH . '/header.php';
?>

<?php if ($poDetails): ?>
<!-- Form Buat Surat Jalan -->
<div class="card mb-24">
    <div class="card-header">
        <h3 class="card-title">Buat Surat Jalan — PO: <?= htmlspecialchars($poDetails['po_number']) ?></h3>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="create_dn">
        <input type="hidden" name="purchase_id" value="<?= $purchaseId ?>">
        <div class="card-body">
            <div class="form-row mb-16">
                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($poDetails['supplier_name']) ?>" disabled>
                </div>
                <div class="form-group">
                    <label class="form-label">No. Surat Jalan <span class="required">*</span></label>
                    <input type="text" name="sj_number" class="form-control" placeholder="SJ-XXXX" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Terima</label>
                    <input type="date" name="received_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            
            <h4 class="mb-8">Item Diterima</h4>
            <div class="alert alert-info mb-16" style="padding: 12px; border-radius: 4px; background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 8px; margin-top:-2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                <b>Informasi:</b> Qty yang diterima akan dicatat sebagai stok masuk dan <b>Total Nilai Diterima</b> menjadi dasar perhitungan hutang ke supplier. Peringatan jika qty melebihi qty PO.
            </div>

            <div class="dn-form-table-responsive" style="width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch;">
            <table class="table" id="dnFormTable" style="min-width:680px;">
                <thead>
                    <tr>
                        <th>Produk (SKU Induk)</th>
                        <th>Qty Dipesan (PO)</th>
                        <th>Harga Satuan</th>
                        <th>Qty Diterima</th>
                        <th style="text-align:right;">Nilai Diterima</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($poDetails['items'] as $i => $item): ?>
                        <tr>
                            <td>
                                <b><?= htmlspecialchars($item['product_name']) ?></b><br>
                                <small class="text-muted"><?= htmlspecialchars($item['sku']) ?></small>
                            </td>
                            <td><b><?= $item['qty'] ?></b></td>
                            <td><?= formatRupiah($item['unit_cost']) ?></td>
                            <td>
                                <input type="hidden" name="items[<?= $i ?>][product_id]" value="<?= $item['product_id'] ?>">
                                <input type="number" name="items[<?= $i ?>][qty_received]" 
                                    class="form-control dn-qty-input" 
                                    value="<?= $item['qty'] ?>" 
                                    min="0" 
                                    data-po-qty="<?= $item['qty'] ?>"
                                    data-unit-cost="<?= $item['unit_cost'] ?>"
                                    data-row-index="<?= $i ?>"
                                    oninput="updateDNRow(this)"
                                    style="width:100px;">
                                <div class="dn-qty-warning" id="dnWarn<?= $i ?>" style="font-size:0.75rem; color:#dc2626; display:none;">⚠️ Melebihi qty PO!</div>
                            </td>
                            <td style="text-align:right; font-weight:600;" id="dnRowValue<?= $i ?>"><?= formatRupiah($item['qty'] * $item['unit_cost']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="background:#f8f9fa; font-weight:700;">
                    <tr>
                        <td colspan="4" style="text-align:right; padding-right: 12px;">Total Nilai Barang Diterima:</td>
                        <td style="text-align:right; color: var(--primary-700); font-size: 1.05rem;" id="dnTotalValue">
                            <?php
                            $initTotal = 0;
                            foreach ($poDetails['items'] as $item) {
                                $initTotal += $item['qty'] * $item['unit_cost'];
                            }
                            echo formatRupiah($initTotal);
                            ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        <div class="card-footer d-flex justify-end gap-8">
            <a href="<?= BASE_URL ?>/admin/purchases.php" class="btn btn-outline">Batal</a>
            <button type="submit" class="btn btn-primary" onclick="return confirmDNSubmit()">Simpan Surat Jalan</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Riwayat Surat Jalan -->
<div class="toolbar toolbar-inline-mobile" style="justify-content: flex-end;">
    <form method="GET" class="search-box" style="margin-right: auto;">
        <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" name="search" class="form-control" placeholder="Cari SJ / PO / Supplier..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
    </form>
    <div class="filter-group">
        <?php 
        $importType = 'delivery_notes';
        $hasImport = false;
        $hasExport = true;
        $hasTemplate = false;
        include INCLUDES_PATH . '/import_export_toolbar.php'; 
        ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Riwayat Surat Jalan</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>No. SJ</th><th>No. PO</th><th>Supplier</th><th>Total PO (Qty)</th><th>Qty Diterima</th><th>Selisih Qty</th><th>Nilai Diterima</th><th>Tanggal Terima</th><th>Penerima</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php if (empty($dnList)): ?>
                        <tr><td colspan="9" class="text-center text-muted" style="padding:40px;">Belum ada surat jalan</td></tr>
                    <?php endif; ?>
                    <?php foreach ($dnList as $dn): ?>
                        <?php 
                            $poQty = (int)$dn['total_po_qty'];
                            $sjQty = (int)$dn['total_received_qty'];
                            $selisih = $sjQty - $poQty;
                            $selisihBadge = '';
                            if ($selisih < 0) {
                                $selisihBadge = "<span class='badge' style='background:var(--warning);'>Kurang " . abs($selisih) . "</span>";
                            } elseif ($selisih > 0) {
                                $selisihBadge = "<span class='badge' style='background:var(--success);'>Lebih $selisih</span>";
                            } else {
                                $selisihBadge = "<span class='badge' style='background:var(--gray-500);'>Pas (0)</span>";
                            }
                        ?>
                        <tr>
                            <td><code class="text-bold"><?= htmlspecialchars($dn['surat_jalan_number']) ?></code></td>
                            <td><code><?= htmlspecialchars($dn['po_number']) ?></code></td>
                            <td><?= htmlspecialchars($dn['supplier_name']) ?></td>
                            <td><b><?= $poQty ?></b> item</td>
                            <td><b><?= $sjQty ?></b> item</td>
                            <td><?= $selisihBadge ?></td>
                            <td>
                                <?php
                                // Hitung nilai total surat jalan ini
                                $stmtVal = $db->prepare("
                                    SELECT COALESCE(SUM(dni.qty_received * pd.unit_cost), 0)
                                    FROM delivery_note_items dni
                                    JOIN purchase_details pd ON pd.purchase_id = ? AND pd.product_id = dni.product_id
                                    WHERE dni.delivery_note_id = ?
                                ");
                                $stmtVal->execute([$dn['purchase_id'], $dn['id']]);
                                $nilaiSJ = (float)$stmtVal->fetchColumn();
                                ?>
                                <b><?= formatRupiah($nilaiSJ) ?></b>
                            </td>
                            <td><?= formatTanggal($dn['received_date']) ?></td>
                            <td><?= htmlspecialchars($dn['receiver_name']) ?></td>
                            <td>
                                <div style="display:flex; gap:4px;">
                                    <button class="btn btn-sm btn-outline" style="padding:4px 8px;" title="Detail Barang" onclick="openDNDetails(<?= $dn['id'] ?>, '<?= htmlspecialchars($dn['surat_jalan_number']) ?>')">📄</button>
                                    <a href="<?= BASE_URL ?>/admin/print_dn.php?id=<?= $dn['id'] ?>" class="btn btn-sm btn-outline" target="_blank" style="padding:4px 8px;" title="Print PDF">🖨️</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Detail Surat Jalan -->
<div class="modal-overlay" id="modalDNDetails">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Detail Surat Jalan: <span id="dnDetailNumber"></span></h3>
            <button class="modal-close" onclick="closeModal('modalDNDetails')">&times;</button>
        </div>
        <div class="modal-body" style="padding: 0;">
            <div style="padding: 16px; text-align: center; display: none;" id="dnDetailLoading">
                <span class="text-muted">Memuat data...</span>
            </div>
            <table class="table" id="dnDetailTable" style="margin: 0; display: none;">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>SKU</th>
                        <th style="text-align:right;">Qty Diterima</th>
                    </tr>
                </thead>
                <tbody id="dnDetailTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
async function openDNDetails(dnId, sjNumber) {
    document.getElementById('dnDetailNumber').textContent = sjNumber;
    document.getElementById('dnDetailTable').style.display = 'none';
    document.getElementById('dnDetailLoading').style.display = 'block';
    document.getElementById('dnDetailTbody').innerHTML = '';
    openModal('modalDNDetails');
    
    try {
        const fd = new FormData();
        fd.append('action', 'get_dn_details');
        fd.append('dn_id', dnId);
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        
        document.getElementById('dnDetailLoading').style.display = 'none';
        document.getElementById('dnDetailTable').style.display = 'table';
        
        if (data.success && data.items.length > 0) {
            let html = '';
            data.items.forEach(item => {
                html += `
                    <tr>
                        <td>${item.product_name}</td>
                        <td><code>${item.sku}</code></td>
                        <td style="text-align:right;"><b>${item.qty_received}</b></td>
                    </tr>
                `;
            });
            document.getElementById('dnDetailTbody').innerHTML = html;
        } else {
            document.getElementById('dnDetailTbody').innerHTML = '<tr><td colspan="3" class="text-center text-muted" style="padding:30px;">Tidak ada item</td></tr>';
        }
    } catch (e) {
        console.error(e);
        document.getElementById('dnDetailLoading').innerHTML = '<span style="color:red;">Gagal memuat data.</span>';
    }
}

// Realtime validation for DN form
function formatRupiahSimple(num) {
    return 'Rp ' + Math.round(num).toLocaleString('id-ID');
}

function updateDNRow(input) {
    const qtyReceived = parseInt(input.value) || 0;
    const poQty = parseInt(input.dataset.poQty) || 0;
    const unitCost = parseFloat(input.dataset.unitCost) || 0;
    const rowIdx = input.dataset.rowIndex;
    
    // Show warning if qty exceeded
    const warnEl = document.getElementById('dnWarn' + rowIdx);
    if (warnEl) {
        warnEl.style.display = (qtyReceived > poQty) ? 'block' : 'none';
    }
    
    // Update row value
    const valueEl = document.getElementById('dnRowValue' + rowIdx);
    if (valueEl) {
        const rowValue = qtyReceived * unitCost;
        valueEl.textContent = formatRupiahSimple(rowValue);
    }
    
    // Update total
    let total = 0;
    document.querySelectorAll('.dn-qty-input').forEach(inp => {
        const qty = parseInt(inp.value) || 0;
        const cost = parseFloat(inp.dataset.unitCost) || 0;
        total += qty * cost;
    });
    const totalEl = document.getElementById('dnTotalValue');
    if (totalEl) totalEl.textContent = formatRupiahSimple(total);
}

function confirmDNSubmit() {
    const warnings = document.querySelectorAll('.dn-qty-warning');
    let hasOverQty = false;
    warnings.forEach(w => {
        if (w.style.display !== 'none') hasOverQty = true;
    });
    if (hasOverQty) {
        return confirm('⚠️ Perhatian: Ada item yang qty diterimanya melebihi qty PO. Lanjutkan penyimpanan?');
    }
    return true;
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
