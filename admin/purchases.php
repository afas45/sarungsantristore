<?php
/**
 * Kasir Ibtidaiyah - Manajemen Pembelian (PO)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Pembelian (PO)';
$breadcrumbs = [['label' => 'Pembelian']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_po') {
        $supplier_id = (int)$_POST['supplier_id'];
        $date = $_POST['date'] ?? date('Y-m-d');
        $notes = sanitize($_POST['notes'] ?? '');
        $items = $_POST['items'] ?? [];
        
        if (!$supplier_id || empty($items)) {
            flashMessage('error', 'Supplier dan item harus diisi.');
        } else {
            try {
                $db->beginTransaction();
                
                $poNumber = generatePONumber();
                $totalAmount = 0;
                
                foreach ($items as $item) {
                    $totalAmount += (float)$item['qty'] * (float)str_replace(['.', ','], ['', '.'], $item['unit_cost']);
                }
                
                $paid_amount = (float)str_replace(['.', ','], ['', '.'], $_POST['paid_amount'] ?? '0');
                $payment_method = sanitize($_POST['payment_method'] ?? 'Tunai');
                if ($paid_amount > $totalAmount) $paid_amount = $totalAmount;
                
                $payment_status = 'Unpaid';
                if ($paid_amount > 0 && $paid_amount < $totalAmount) $payment_status = 'Partial';
                elseif ($paid_amount >= $totalAmount) $payment_status = 'Paid';
                
                $stmt = $db->prepare("INSERT INTO purchases (supplier_id, admin_id, po_number, date, notes, total_amount, paid_amount, payment_method, payment_status) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$supplier_id, $_SESSION['user_id'], $poNumber, $date, $notes, $totalAmount, $paid_amount, $payment_method, $payment_status]);
                $poId = $db->lastInsertId();
                
                foreach ($items as $item) {
                    $unitCost = (float)str_replace(['.', ','], ['', '.'], $item['unit_cost']);
                    $stmt = $db->prepare("INSERT INTO purchase_details (purchase_id, product_id, qty, unit_cost) VALUES (?,?,?,?)");
                    $stmt->execute([$poId, (int)$item['product_id'], (int)$item['qty'], $unitCost]);
                }
                
                // Add to payables (always create to track debt/payment lifecycle of PO)
                $due_date = date('Y-m-d', strtotime('+30 days'));
                $payable_status = ($paid_amount >= $totalAmount) ? 'Paid' : (($paid_amount > 0) ? 'Partial' : 'Unpaid');
                $stmt = $db->prepare("INSERT INTO payables (supplier_id, purchase_id, total_debt, paid_amount, due_date, status) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$supplier_id, $poId, $totalAmount, $paid_amount, $due_date, $payable_status]);
                $payableId = $db->lastInsertId();
                
                // Add to payments if paid_amount > 0
                if ($paid_amount > 0) {
                    $stmt = $db->prepare("INSERT INTO payments (purchase_id, payable_id, payment_type, payment_method, amount, created_by) VALUES (?,?,'Outgoing',?,?,?)");
                    $stmt->execute([$poId, $payableId, $payment_method, $paid_amount, $_SESSION['user_id']]);
                }
                
                $db->commit();
                flashMessage('success', "PO berhasil dibuat: $poNumber");
                logActivity('Tambah', 'Pembelian', "PO: $poNumber");
            } catch (Exception $e) {
                $db->rollBack();
                flashMessage('error', 'Gagal membuat PO: ' . $e->getMessage());
            }
        }
    }
    
    if ($action === 'edit_po') {
        $poId = (int)$_POST['po_id'];
        $supplier_id = (int)$_POST['supplier_id'];
        $date = $_POST['date'] ?? date('Y-m-d');
        $notes = sanitize($_POST['notes'] ?? '');
        $items = $_POST['items'] ?? [];
        
        if (!$poId || !$supplier_id || empty($items)) {
            flashMessage('error', 'Supplier dan item harus diisi.');
        } else {
            try {
                $db->beginTransaction();
                
                $totalAmount = 0;
                foreach ($items as $item) {
                    $totalAmount += (float)$item['qty'] * (float)str_replace(['.', ','], ['', '.'], $item['unit_cost']);
                }
                
                // Update purchase
                $stmt = $db->prepare("UPDATE purchases SET supplier_id = ?, date = ?, notes = ?, total_amount = ? WHERE id = ?");
                $stmt->execute([$supplier_id, $date, $notes, $totalAmount, $poId]);
                
                // Replace details
                $db->prepare("DELETE FROM purchase_details WHERE purchase_id = ?")->execute([$poId]);
                
                foreach ($items as $item) {
                    $unitCost = (float)str_replace(['.', ','], ['', '.'], $item['unit_cost']);
                    $stmt = $db->prepare("INSERT INTO purchase_details (purchase_id, product_id, qty, unit_cost) VALUES (?,?,?,?)");
                    $stmt->execute([$poId, (int)$item['product_id'], (int)$item['qty'], $unitCost]);
                }
                
                // Update payables if exists
                $stmt = $db->prepare("SELECT id, paid_amount FROM payables WHERE purchase_id = ?");
                $stmt->execute([$poId]);
                $payable = $stmt->fetch();
                if ($payable) {
                    $new_status = ($payable['paid_amount'] >= $totalAmount) ? 'Paid' : (($payable['paid_amount'] > 0) ? 'Partial' : 'Unpaid');
                    $db->prepare("UPDATE payables SET total_debt = ?, status = ? WHERE purchase_id = ?")->execute([$totalAmount, $new_status, $poId]);
                } else {
                    $due_date = date('Y-m-d', strtotime($date . ' + 30 days'));
                    $payable_status = (0 >= $totalAmount) ? 'Paid' : 'Unpaid';
                    $db->prepare("INSERT INTO payables (supplier_id, purchase_id, total_debt, paid_amount, due_date, status) VALUES (?,?,?,?,?,?)")
                       ->execute([$supplier_id, $poId, $totalAmount, 0, $due_date, $payable_status]);
                }
                
                $db->commit();
                flashMessage('success', "PO berhasil diperbarui.");
                logActivity('Edit', 'Pembelian', "Update PO ID: $poId");
            } catch (Exception $e) {
                $db->rollBack();
                flashMessage('error', 'Gagal memperbarui PO: ' . $e->getMessage());
            }
        }
    }
    
    if ($action === 'update_status') {
        $id = (int)$_POST['id'];
        $status = $_POST['status'];
        $db->prepare("UPDATE purchases SET status = ? WHERE id = ?")->execute([$status, $id]);
        
        flashMessage('success', 'Status PO diperbarui.');
    }
    
    if ($action === 'delete_po') {
        $id = (int)$_POST['id'];
        try {
            $dnCheck = $db->prepare("SELECT COUNT(*) FROM delivery_notes WHERE purchase_id = ?");
            $dnCheck->execute([$id]);
            if ($dnCheck->fetchColumn() > 0) {
                flashMessage('error', 'Tidak dapat menghapus PO karena sudah memiliki Surat Jalan.');
            } else {
                $db->beginTransaction();
                $db->prepare("DELETE FROM purchase_details WHERE purchase_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM payments WHERE purchase_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM payables WHERE purchase_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM purchases WHERE id = ?")->execute([$id]);
                $db->commit();
                flashMessage('success', 'PO berhasil dihapus.');
                logActivity('Hapus', 'Pembelian', "PO ID: $id");
            }
        } catch (Exception $e) {
            $db->rollBack();
            flashMessage('error', 'Gagal menghapus PO: ' . $e->getMessage());
        }
    }
    
    redirect(BASE_URL . '/admin/purchases.php');
}

$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND (p.po_number LIKE ? OR s.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $db->prepare("SELECT COUNT(*) FROM purchases p JOIN suppliers s ON p.supplier_id = s.id $where");
$stmt->execute($params);
$pagination = getPagination($stmt->fetchColumn(), $page);

$stmt = $db->prepare("
    SELECT p.*, s.name as supplier_name, u.full_name as admin_name
    FROM purchases p 
    JOIN suppliers s ON p.supplier_id = s.id 
    JOIN users u ON p.admin_id = u.id 
    $where
    ORDER BY p.created_at DESC 
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$suppliers = $db->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
$productsList = $db->query("SELECT id, name, sku FROM products ORDER BY name")->fetchAll();

include INCLUDES_PATH . '/header.php';
?>

<!-- Tom Select CSS/JS for searchable dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<div class="toolbar">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex:1;">
        <div class="search-box" style="flex:1; max-width:400px; margin-right:8px;">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="search" class="form-control" placeholder="Cari PO atau Supplier..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
        </div>
    </form>
    <div class="filter-group">
        <button class="btn btn-primary" onclick="openModal('modalPO'); initPO();">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Buat PO Baru
        </button>
        
        <?php 
        $importType = 'purchases';
        $hasImport = true;
        $hasExport = true;
        $hasTemplate = true;
        include INCLUDES_PATH . '/import_export_toolbar.php'; 
        ?>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No. PO</th>
                        <th>Supplier</th>
                        <th>Tanggal</th>
                        <th>Total PO</th>
                        <th>Sudah Bayar</th>
                        <th>Sisa Hutang</th>
                        <th>Status</th>
                        <th>Dibuat Oleh</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchases)): ?>
                        <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">Belum ada Purchase Order</td></tr>
                    <?php endif; ?>
                    <?php foreach ($purchases as $po): ?>
                        <tr>
                            <td><code class="text-bold"><?= htmlspecialchars($po['po_number']) ?></code></td>
                            <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                            <td><?= formatTanggal($po['date']) ?></td>
                            <td class="text-bold"><?= formatRupiah($po['total_amount']) ?></td>
                            <td class="text-bold text-success"><?= formatRupiah($po['paid_amount'] ?? 0) ?></td>
                            <td>
                                <?php
                                $sisaHutang = $po['total_amount'] - ($po['paid_amount'] ?? 0);
                                if ($sisaHutang > 0):
                                ?>
                                    <span class="text-bold" style="color:var(--danger);"><?= formatRupiah($sisaHutang) ?></span>
                                <?php elseif ($sisaHutang < 0): ?>
                                    <span class="text-bold" style="color:var(--success);">Lebih <?= formatRupiah(abs($sisaHutang)) ?></span>
                                <?php else: ?>
                                    <span class="text-success text-bold">Lunas</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $sBadge = ['Pending'=>'badge-warning','Partial'=>'badge-info','Completed'=>'badge-success','Cancelled'=>'badge-danger'];
                                $sLabel = ['Pending'=>'Menunggu','Partial'=>'Sebagian','Completed'=>'Selesai','Cancelled'=>'Batal'];
                                ?>
                                <span class="badge <?= $sBadge[$po['status']] ?>" style="margin-bottom:4px;display:inline-block;"><?= $sLabel[$po['status']] ?></span>
                                <?php if (isset($po['payment_status'])): ?>
                                    <?php
                                    $pBadge = ['Unpaid'=>'badge-danger','Partial'=>'badge-warning','Paid'=>'badge-success'];
                                    $pLabel = ['Unpaid'=>'Belum Bayar','Partial'=>'DP/Sebagian','Paid'=>'Lunas'];
                                    ?>
                                    <span class="badge <?= $pBadge[$po['payment_status']] ?>"><?= $pLabel[$po['payment_status']] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm"><?= htmlspecialchars($po['admin_name']) ?></td>
                            <td>
                                <div class="actions">
                                    <button class="btn btn-sm btn-outline btn-icon" style="color:var(--gray-700);" onclick="openPODetails(<?= $po['id'] ?>, '<?= htmlspecialchars($po['po_number']) ?>')" title="Surat Pembelian Barang">📄</button>
                                    <?php if (($po['paid_amount'] ?? 0) > 0): ?>
                                        <button class="btn btn-sm btn-outline btn-icon" style="color:var(--primary-600);" onclick="openPOReceiptModal(<?= $po['id'] ?>, '<?= $po['po_number'] ?>')" title="Cetak Struk Pembayaran">🧾</button>
                                    <?php endif; ?>
                                    <?php if ($po['status'] !== 'Completed' && $po['status'] !== 'Cancelled'): ?>
                                        <a href="<?= BASE_URL ?>/admin/delivery_notes.php?purchase_id=<?= $po['id'] ?>" class="btn btn-sm btn-primary">Surat Jalan</a>
                                        <a href="#" class="btn btn-sm btn-outline btn-icon" title="Edit" onclick="editPO(<?= $po['id'] ?>); return false;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Yakin ingin menghapus PO ini?');">
                                        <input type="hidden" name="action" value="delete_po">
                                        <input type="hidden" name="id" value="<?= $po['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline btn-icon" style="color:var(--danger);" title="Hapus">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer"><?= renderPagination($pagination, BASE_URL . '/admin/purchases.php') ?></div>
</div>

<!-- Modal Buat PO -->
<div class="modal-overlay" id="modalPO">
    <div class="modal modal-xl">
        <div class="modal-header">
            <h3 class="modal-title">Buat Purchase Order</h3>
            <button class="modal-close" onclick="closeModal('modalPO')">&times;</button>
        </div>
        <form method="POST" id="formPO">
            <input type="hidden" name="action" value="create_po">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supplier <span class="required">*</span></label>
                        <select name="supplier_id" class="form-control" required>
                            <option value="">Pilih Supplier</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                
                <h4 style="margin: 20px 0 12px;">Item Pembelian</h4>
                <div id="poItemsContainer">
                    <!-- Items will be added here -->
                </div>
                <button type="button" class="btn btn-sm btn-outline mt-8" onclick="addPOItem()">+ Tambah Item</button>

                <div class="form-row mt-16" style="border-top: 1px dashed var(--border-color); padding-top: 16px; margin-top: 16px;">
                    <div class="form-group">
                        <label class="form-label">Metode Pembayaran</label>
                        <select name="payment_method" class="form-control">
                            <option value="Tunai">Tunai</option>
                            <option value="Bank BSI">Bank BSI</option>
                            <option value="Emaal">Emaal</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jumlah Bayar (DP / Lunas)</label>
                        <input type="text" name="paid_amount" class="form-control rupiah-input" id="poPaidAmount" value="0" oninput="updatePoGrandTotal()">
                    </div>
                </div>
                <div class="form-group mt-16">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-weight: 600; font-size: 1.1rem; color: var(--primary-700);">Total Seluruh: <span class="po-grand-total">Rp 0</span></div>
                    <div id="poPaymentStatus" style="font-weight: 600; font-size: 0.95rem; margin-top: 4px;"><span style="color:var(--danger);">Sisa Hutang: <span id="poRemainingDebt">Rp 0</span></span></div>
                </div>
                <div>
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalPO')">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitPO" onclick="return validateAndCleanPO('formPO')">Buat PO</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit PO -->
<div class="modal-overlay" id="modalEditPO">
    <div class="modal modal-xl">
        <div class="modal-header">
            <h3 class="modal-title">Edit Purchase Order</h3>
            <button class="modal-close" onclick="closeModal('modalEditPO')">&times;</button>
        </div>
        <form method="POST" id="formEditPO">
            <input type="hidden" name="action" value="edit_po">
            <input type="hidden" name="po_id" id="edit_po_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supplier <span class="required">*</span></label>
                        <select name="supplier_id" id="edit_supplier_id" class="form-control" required>
                            <option value="">Pilih Supplier</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="date" id="edit_date" class="form-control" required>
                    </div>
                </div>
                
                <h4 style="margin: 20px 0 12px;">Item Pembelian</h4>
                <div class="alert alert-warning mb-16" style="padding: 12px; border-radius: 4px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;">
                    <b>Perhatian:</b> Karena perubahan format struktur input, mengedit PO akan memformat ulang tampilan item. Jika PO ini sudah terhubung dengan Surat Jalan, pertimbangkan untuk tidak mengubah SKU secara sembarangan.
                </div>
                <div id="editPoItemsContainer">
                    <!-- Items will be added here -->
                </div>
                <button type="button" class="btn btn-sm btn-outline mt-8" onclick="addEditPOItem()">+ Tambah Item</button>
                <div class="form-group mt-16" style="border-top: 1px dashed var(--border-color); padding-top: 16px;">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                <div style="font-weight: 600; font-size: 1.1rem; color: var(--primary-700);">Total Seluruh: <span class="po-grand-total">Rp 0</span></div>
                <div>
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalEditPO')">Batal</button>
                    <button type="submit" class="btn btn-primary" onclick="cleanupEmptyItems('formEditPO')">Simpan PO</button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
@media (max-width: 768px) {
    #modalPO .modal-footer,
    #modalEditPO .modal-footer {
        align-items: stretch !important;
        gap: 10px;
    }
    #modalPO .modal-footer > div,
    #modalEditPO .modal-footer > div {
        width: 100%;
    }
    #modalPO .modal-footer > div:last-child,
    #modalEditPO .modal-footer > div:last-child {
        display: flex;
        gap: 8px;
    }
    #modalPO .modal-footer .btn,
    #modalEditPO .modal-footer .btn {
        flex: 1;
    }
    .po-item-row { flex-wrap: wrap !important; padding-bottom: 12px; border-bottom: 1px dashed var(--gray-300); }
    .po-prod-col { flex: 1 1 calc(100% - 130px) !important; min-width: 0 !important; }
    .po-price-col, .po-total-col { flex: 1 1 45% !important; }
    .po-label-mobile { display: block !important; }
}
/* TomSelect Custom Styling for Neater Layout */
.ts-wrapper.form-control {
    padding: 0 !important;
    border: none !important;
    box-shadow: none !important;
    background: transparent !important;
    height: auto !important;
}
.ts-wrapper.form-control.focus .ts-control {
    border-color: var(--primary-500) !important;
    box-shadow: 0 0 0 3px rgba(188, 57, 8, 0.12) !important;
}
.ts-control {
    min-height: 38px !important;
    padding: 8px 12px !important;
    border-radius: var(--border-radius-sm, 8px) !important;
    border: 1.5px solid var(--border-color, #e8e2e0) !important;
    display: flex;
    align-items: center;
    box-shadow: none !important;
    background-color: var(--card-bg, #ffffff) !important;
}
.ts-control > input {
    font-size: 0.8125rem !important;
    line-height: 1.6;
}
.ts-wrapper.single .ts-control::after {
    border-color: var(--gray-500) transparent transparent transparent !important;
}
.po-item {
    transition: all 0.2s ease;
}
.po-item:hover {
    background: #f8fafc;
    border-radius: 6px;
}
</style>

<script>
const productsList = <?= json_encode($productsList) ?>;
let itemGlobalIndex = 0;
let editPoItemIndex = 0;

function initPO() {
    document.getElementById('poItemsContainer').innerHTML = '';
    itemGlobalIndex = 0;
    addPOItem();
}

function addPOItem() {
    let options = '<option value="">-- Pilih Produk Utama (SKU Induk) --</option>';
    productsList.forEach(p => {
        options += `<option value="${p.id}">${p.name} (${p.sku})</option>`;
    });
    
    const selectId = `po-select-${itemGlobalIndex}`;
    const html = `
        <div class="po-item po-item-row" style="display: flex; gap: 8px; align-items: flex-end; margin-bottom: 12px; width: 100%;">
            <div class="form-group po-prod-col" style="flex: 3; margin-bottom: 0; min-width:200px;">
                <label class="form-label po-label-mobile" style="font-size:0.8rem; margin-bottom:4px; ${itemGlobalIndex > 0 ? 'display:none;' : ''}">Produk</label>
                <select id="${selectId}" name="items[${itemGlobalIndex}][product_id]" class="form-control" required>${options}</select>
            </div>
            <div class="form-group po-qty-col" style="flex: 0 0 70px; margin-bottom: 0;">
                <label class="form-label po-label-mobile" style="font-size:0.8rem; margin-bottom:4px; ${itemGlobalIndex > 0 ? 'display:none;' : ''}">Qty</label>
                <input type="number" name="items[${itemGlobalIndex}][qty]" class="form-control item-qty" min="1" value="1" oninput="calcPoRow(this)" required>
            </div>
            <div class="form-group po-price-col" style="flex: 2; margin-bottom: 0;">
                <label class="form-label po-label-mobile" style="font-size:0.8rem; margin-bottom:4px; ${itemGlobalIndex > 0 ? 'display:none;' : ''}">Harga Beli</label>
                <input type="text" name="items[${itemGlobalIndex}][unit_cost]" class="form-control rupiah-input item-cost" oninput="calcPoRow(this)" required>
            </div>
            <div class="form-group po-total-col" style="flex: 2; margin-bottom: 0;">
                <label class="form-label po-label-mobile" style="font-size:0.8rem; margin-bottom:4px; ${itemGlobalIndex > 0 ? 'display:none;' : ''}">Total Pembelian</label>
                <input type="text" class="form-control rupiah-input item-total" oninput="calcPoRowFromTotal(this)">
            </div>
            <div class="form-group po-del-col" style="flex: 0 0 36px; margin-bottom: 0; display: flex; align-items: flex-end;">
                <button type="button" class="btn btn-sm btn-outline btn-icon" style="color:var(--danger); width: 36px; height: 36px; padding: 0; border-color:var(--danger);" onclick="this.closest('.po-item').remove(); updatePoGrandTotal();" title="Hapus">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
            </div>
        </div>
    `;
    document.getElementById('poItemsContainer').insertAdjacentHTML('beforeend', html);
    initRupiahInput('.rupiah-input');
    
    // Initialize TomSelect
    setTimeout(() => {
        new TomSelect(`#${selectId}`, { create: false, placeholder: '-- Pilih Produk Utama --' });
    }, 10);
    
    itemGlobalIndex++;
}

function addEditPOItem(productId = '', qty = 1, unitCost = '') {
    let options = '<option value="">-- Pilih SKU Induk --</option>';
    productsList.forEach(p => {
        const selected = (p.id == productId) ? 'selected' : '';
        options += `<option value="${p.id}" ${selected}>${p.name} (${p.sku})</option>`;
    });
    
    const selectId = `edit-po-select-${editPoItemIndex}`;
    const html = `
        <div class="po-item po-item-row" style="display: flex; gap: 8px; align-items: flex-end; margin-bottom: 12px; width: 100%;">
            <div class="form-group po-prod-col" style="flex: 3; margin-bottom: 0; min-width:200px;">
                <label class="form-label po-label-mobile" style="font-size:0.8rem; margin-bottom:4px; ${editPoItemIndex > 0 ? 'display:none;' : ''}">Produk</label>
                <select id="${selectId}" name="items[${editPoItemIndex}][product_id]" class="form-control" required>${options}</select>
            </div>
            <div class="form-group po-qty-col" style="flex: 0 0 70px; margin-bottom: 0;">
                <label class="form-label po-label-mobile" style="font-size:0.8rem; margin-bottom:4px; ${editPoItemIndex > 0 ? 'display:none;' : ''}">Qty</label>
                <input type="number" name="items[${editPoItemIndex}][qty]" class="form-control item-qty" min="1" value="${qty}" oninput="calcPoRow(this)" required>
            </div>
            <div class="form-group po-price-col" style="flex: 2; margin-bottom: 0;">
                <label class="form-label po-label-mobile" style="font-size:0.8rem; margin-bottom:4px; ${editPoItemIndex > 0 ? 'display:none;' : ''}">Harga Beli</label>
                <input type="text" name="items[${editPoItemIndex}][unit_cost]" class="form-control rupiah-input item-cost" value="${unitCost}" oninput="calcPoRow(this)" required>
            </div>
            <div class="form-group po-total-col" style="flex: 2; margin-bottom: 0;">
                <label class="form-label po-label-mobile" style="font-size:0.8rem; margin-bottom:4px; ${editPoItemIndex > 0 ? 'display:none;' : ''}">Total Pembelian</label>
                <input type="text" class="form-control rupiah-input item-total" oninput="calcPoRowFromTotal(this)">
            </div>
            <div class="form-group po-del-col" style="flex: 0 0 36px; margin-bottom: 0; display: flex; align-items: flex-end;">
                <button type="button" class="btn btn-sm btn-outline btn-icon" style="color:var(--danger); width: 36px; height: 36px; padding: 0; border-color:var(--danger);" onclick="this.closest('.po-item').remove(); updatePoGrandTotal();" title="Hapus">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
            </div>
        </div>
    `;
    document.getElementById('editPoItemsContainer').insertAdjacentHTML('beforeend', html);
    initRupiahInput('.rupiah-input');
    
    // Initialize TomSelect
    setTimeout(() => {
        new TomSelect(`#${selectId}`, { create: false, placeholder: '-- Pilih Produk Utama --' });
    }, 10);
    
    editPoItemIndex++;
}

function cleanupEmptyItems(formId) {
    const form = document.getElementById(formId);
    const rows = form.querySelectorAll('.po-item');
    rows.forEach(row => {
        const qtyInput = row.querySelector('.item-qty');
        if (qtyInput && (qtyInput.value === '' || parseInt(qtyInput.value) <= 0)) {
            row.remove();
        }
    });
}

async function editPO(id) {
    try {
        const res = await fetch('<?= BASE_URL ?>/admin/api_purchase_details.php?id=' + id);
        const data = await res.json();
        
        if (data.error) {
            alert(data.error);
            return;
        }
        
        document.getElementById('edit_po_id').value = data.purchase.id;
        document.getElementById('edit_supplier_id').value = data.purchase.supplier_id;
        document.getElementById('edit_date').value = data.purchase.date;
        document.getElementById('edit_notes').value = data.purchase.notes;
        
        const container = document.getElementById('editPoItemsContainer');
        container.innerHTML = '';
        editPoItemIndex = 0;
        
        if (data.details && data.details.length > 0) {
            data.details.forEach(item => {
                const formattedPrice = parseInt(item.unit_cost).toLocaleString('id-ID');
                addEditPOItem(item.product_id || item.product_variation_id, item.qty, formattedPrice);
            });
            setTimeout(updatePoGrandTotal, 200);
        } else {
            addEditPOItem();
        }
        
        openModal('modalEditPO');
    } catch (e) {
        alert('Gagal mengambil data PO.');
        console.error(e);
    }
}

initRupiahInput('.rupiah-input');

function unformatRupiahJs(str) {
    if (!str) return 0;
    return parseInt(str.toString().replace(/[^0-9]/g, ''), 10) || 0;
}

function formatRupiahJs(angka) {
    if (angka === 0) return '0';
    let reverse = Math.round(angka).toString().split('').reverse().join('');
    let ribuan = reverse.match(/\d{1,3}/g);
    return ribuan.join('.').split('').reverse().join('');
}

function calcPoRow(el) {
    let row = el.closest('.po-item');
    let qty = parseInt(row.querySelector('.item-qty').value) || 0;
    let cost = unformatRupiahJs(row.querySelector('.item-cost').value);
    let total = qty * cost;
    row.querySelector('.item-total').value = formatRupiahJs(total);
    updatePoGrandTotal();
}

function calcPoRowFromTotal(el) {
    let row = el.closest('.po-item');
    let qty = parseInt(row.querySelector('.item-qty').value) || 1;
    let total = unformatRupiahJs(row.querySelector('.item-total').value);
    let cost = Math.round(total / qty);
    row.querySelector('.item-cost').value = formatRupiahJs(cost);
    updatePoGrandTotal();
}

function updatePoGrandTotal() {
    ['#formPO', '#formEditPO'].forEach(formId => {
        let form = document.querySelector(formId);
        if (!form) return;
        let totals = 0;
        form.querySelectorAll('.item-total').forEach(input => {
            totals += unformatRupiahJs(input.value);
        });
        let gt = form.querySelector('.po-grand-total');
        if (gt) gt.innerText = 'Rp ' + formatRupiahJs(totals);

        let paidInput = form.querySelector('input[name="paid_amount"]');
        if (paidInput) {
            let paid = unformatRupiahJs(paidInput.value);
            let selisih = totals - paid;
            let statusEl = form.querySelector('#poPaymentStatus');
            let rd = form.querySelector('#poRemainingDebt');
            let submitBtn = form.querySelector('#btnSubmitPO');
            
            if (selisih > 0) {
                // Kurang bayar
                if (statusEl) statusEl.innerHTML = '<span style="color:var(--danger);">Sisa Hutang: <span id="poRemainingDebt">Rp ' + formatRupiahJs(selisih) + '</span></span>';
                if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
            } else if (selisih < 0) {
                // Kelebihan bayar
                if (statusEl) statusEl.innerHTML = '<span style="color:#dc2626; font-weight:700;">⚠️ Kelebihan Bayar: Rp ' + formatRupiahJs(Math.abs(selisih)) + ' — Pembayaran melebihi total PO!</span>';
                if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; }
            } else {
                // Lunas
                if (statusEl) statusEl.innerHTML = '<span style="color:var(--success);">✓ Lunas (Tidak ada sisa hutang)</span>';
                if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
            }
        }
    });
}

function validateAndCleanPO(formId) {
    cleanupEmptyItems(formId);
    const form = document.getElementById(formId);
    const gt = form.querySelector('.po-grand-total');
    const paidInput = form.querySelector('input[name="paid_amount"]');
    if (gt && paidInput) {
        const total = unformatRupiahJs(gt.innerText);
        const paid = unformatRupiahJs(paidInput.value);
        if (paid > total) {
            alert('Pembayaran tidak bisa melebihi total PO! Harap perbaiki jumlah bayar.');
            return false;
        }
    }
    return true;
}

// Hook into openModal to recalculate
const originalOpenModal = window.openModal;
if (typeof originalOpenModal === 'function') {
    window.openModal = function(id) {
        originalOpenModal(id);
        setTimeout(updatePoGrandTotal, 100);
    };
}
</script>

<!-- Modal Detail PO -->
<div class="modal-overlay" id="modalPODetails">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Detail PO: <span id="poDetailNumber"></span></h3>
            <button class="modal-close" onclick="closeModal('modalPODetails')">&times;</button>
        </div>
        <div class="modal-body" style="padding: 0;">
            <div style="padding: 16px; text-align: center; display: none;" id="poDetailLoading">
                <span class="text-muted">Memuat data...</span>
            </div>
            <table class="table" id="poDetailTable" style="margin: 0; display: none;">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>SKU</th>
                        <th style="text-align:right;">Qty</th>
                        <th style="text-align:right;">Harga Satuan</th>
                        <th style="text-align:right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="poDetailTbody"></tbody>
                <tfoot id="poDetailTfoot" style="display: none; background: #f8f9fa;">
                    <tr>
                        <td colspan="4" style="text-align: right; font-weight: bold; padding-right: 16px;">Total Keseluruhan:</td>
                        <td style="text-align: right; font-weight: bold; font-size: 1.1rem; color: var(--primary-700);" id="poDetailTotal"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
async function openPODetails(poId, poNumber) {
    document.getElementById('poDetailNumber').textContent = poNumber;
    document.getElementById('poDetailTable').style.display = 'none';
    document.getElementById('poDetailLoading').style.display = 'block';
    document.getElementById('poDetailTbody').innerHTML = '';
    openModal('modalPODetails');
    
    try {
        const res = await fetch('<?= BASE_URL ?>/admin/api_purchase_details.php?id=' + poId);
        const data = await res.json();
        
        document.getElementById('poDetailLoading').style.display = 'none';
        document.getElementById('poDetailTable').style.display = 'table';
        
        if (data.details && data.details.length > 0) {
            let html = '';
            let grandTotal = 0;
            data.details.forEach(item => {
                const subtotal = item.qty * item.unit_cost;
                grandTotal += subtotal;
                html += `
                    <tr>
                        <td>${item.product_name}</td>
                        <td><code>${item.sku}</code></td>
                        <td style="text-align:right;"><b>${item.qty}</b></td>
                        <td style="text-align:right;">${formatRupiahJs(item.unit_cost, 'Rp ')}</td>
                        <td style="text-align:right; font-weight:bold;">${formatRupiahJs(subtotal, 'Rp ')}</td>
                    </tr>
                `;
            });
            document.getElementById('poDetailTbody').innerHTML = html;
            document.getElementById('poDetailTotal').textContent = formatRupiahJs(grandTotal, 'Rp ');
            document.getElementById('poDetailTfoot').style.display = 'table-footer-group';
        } else {
            document.getElementById('poDetailTbody').innerHTML = '<tr><td colspan="5" class="text-center text-muted" style="padding:30px;">Tidak ada item</td></tr>';
            document.getElementById('poDetailTfoot').style.display = 'none';
        }
    } catch (e) {
        console.error(e);
        document.getElementById('poDetailLoading').innerHTML = '<span style="color:red;">Gagal memuat data.</span>';
    }
}
</script>

<!-- Modal: Cetak Struk PO (Popup) -->
<div class="modal-overlay" id="modalPOReceipt">
    <div class="modal" style="max-width:400px; padding:0;">
        <div class="modal-header" style="border-bottom: 1px solid var(--border-color); padding: 16px;">
            <h3 class="modal-title" style="display:flex; align-items:center; gap:8px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--success);"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                Struk Pembayaran!
            </h3>
            <button class="modal-close" onclick="closeModal('modalPOReceipt')">&times;</button>
        </div>
        <div class="modal-body" id="poReceiptModalBody" style="padding: 24px; text-align: center; background: #fafafa; max-height: 60vh; overflow-y: auto;">
            <!-- Receipt loaded via AJAX -->
            <div id="poReceiptLoading" style="color:var(--gray-500); padding:20px;">Memuat struk...</div>
            <div id="poReceiptContentArea"></div>
        </div>
        <div class="modal-footer" style="justify-content: center; background: #fff; padding: 16px;">
            <button class="btn btn-primary" onclick="printPOReceiptFromModal()" style="background: #b45309; border-color: #b45309;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Cetak Struk
            </button>
            <button class="btn" onclick="sharePOReceiptFromModal()" style="background: #f59e0b; border-color: #f59e0b; color: #fff;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                Share JPG
            </button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
let currentPOReceiptNumber = '';

async function openPOReceiptModal(poId, poNumber) {
    currentPOReceiptNumber = poNumber;
    document.getElementById('poReceiptContentArea').innerHTML = '';
    document.getElementById('poReceiptLoading').style.display = 'block';
    openModal('modalPOReceipt');
    
    try {
        const res = await fetch('<?= BASE_URL ?>/admin/print_po_receipt.php?id=' + poId + '&partial=1');
        const html = await res.text();
        document.getElementById('poReceiptLoading').style.display = 'none';
        document.getElementById('poReceiptContentArea').innerHTML = html;
    } catch (e) {
        console.error(e);
        document.getElementById('poReceiptLoading').innerHTML = '<span style="color:red;">Gagal memuat struk.</span>';
    }
}

function printPOReceiptFromModal() {
    // We can just print the content by opening a small window or using an iframe
    const receiptHtml = document.getElementById('poReceiptContentArea').innerHTML;
    const printWindow = window.open('', '_blank', 'width=400,height=600');
    printWindow.document.write(`
        <html>
        <head>
            <title>Cetak Struk PO</title>
            <style>
                body { 
                    margin: 0; 
                    padding: 20px 0; 
                    background: #f1f5f9; 
                    text-align: center;
                }
                .receipt-container {
                    margin: 0 auto !important;
                    text-align: left;
                }
                @media print { 
                    body { 
                        background: #fff; 
                        padding: 0;
                    }
                    @page { margin: 0; }
                }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            ${receiptHtml}
        </body>
        </html>
    `);
    printWindow.document.close();
}

async function sharePOReceiptFromModal() {
    const btn = event.currentTarget;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span style="font-size:12px;">Memproses...</span>';
    btn.disabled = true;

    try {
        const element = document.getElementById('poReceiptContentArea');
        // Ensure text rendering and white background
        const canvas = await html2canvas(element, {
            scale: 2,
            backgroundColor: '#ffffff',
            useCORS: true
        });

        canvas.toBlob(async (blob) => {
            const file = new File([blob], 'Struk_PO_' + currentPOReceiptNumber + '.jpg', { type: 'image/jpeg' });
            
            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                try {
                    await navigator.share({
                        title: 'Struk Pembayaran PO',
                        text: 'Struk Pembayaran PO #' + currentPOReceiptNumber,
                        files: [file]
                    });
                } catch (e) {
                    console.error('Share failed', e);
                    if (e.name !== 'AbortError') {
                        downloadBlob(blob, file.name);
                    }
                }
            } else {
                // Fallback to download if Web Share API is not supported
                downloadBlob(blob, file.name);
            }
            
            btn.innerHTML = originalText;
            btn.disabled = false;
        }, 'image/jpeg', 0.9);

    } catch (error) {
        console.error("Error generating receipt image:", error);
        alert("Gagal membuat gambar struk.");
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
