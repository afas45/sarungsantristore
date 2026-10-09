<?php
/**
 * Kasir Ibtidaiyah - Manajemen Supplier
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Supplier';
$breadcrumbs = [['label' => 'Supplier']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $name = sanitize($_POST['name'] ?? '');
        $contact_person = sanitize($_POST['contact_person'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        
        if (empty($name)) {
            flashMessage('error', 'Nama supplier harus diisi.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("SELECT COUNT(*) FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))" . ($action === 'edit' ? " AND id != ?" : ""));
            $params = $action === 'edit' ? [$name, $id] : [$name];
            $stmt->execute($params);
            
            if ($stmt->fetchColumn() > 0) {
                flashMessage('error', 'Gagal: Nama supplier sudah terdaftar.');
            } elseif ($action === 'add') {
                $stmt = $db->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address, city) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$name, $contact_person, $phone, $email, $address, $city]);
                flashMessage('success', 'Supplier berhasil ditambahkan.');
                logActivity('Tambah', 'Supplier', 'Supplier: ' . $name);
            } else {
                $stmt = $db->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, city=? WHERE id=?");
                $stmt->execute([$name, $contact_person, $phone, $email, $address, $city, $id]);
                flashMessage('success', 'Supplier berhasil diperbarui.');
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                flashMessage('error', 'Supplier tidak bisa dihapus karena memiliki riwayat pembelian.');
            } else {
                $db->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
                flashMessage('success', 'Supplier berhasil dihapus.');
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                flashMessage('error', 'Supplier tidak bisa dihapus karena datanya sedang digunakan pada transaksi terkait.');
            } else {
                flashMessage('error', 'Gagal menghapus supplier: ' . $e->getMessage());
            }
        }
    }
    
    redirect(BASE_URL . '/admin/suppliers.php');
}

$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = $search ? "WHERE s.name LIKE ? OR s.contact_person LIKE ?" : "WHERE 1=1";
$params = $search ? ["%$search%", "%$search%"] : [];

$stmt = $db->prepare("SELECT COUNT(*) FROM suppliers s $where");
$stmt->execute($params);
$pagination = getPagination($stmt->fetchColumn(), $page);

$stmt = $db->prepare("
    SELECT s.*, 
    (SELECT COUNT(*) FROM purchases WHERE supplier_id = s.id) as total_po,
    COALESCE((SELECT SUM(total_debt - paid_amount) FROM payables WHERE supplier_id = s.id AND status != 'Paid'), 0) as outstanding_payable
    FROM suppliers s $where ORDER BY s.name 
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

include INCLUDES_PATH . '/header.php';
?>

<div class="toolbar">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex:1;">
        <div class="search-box" style="flex:1; max-width:400px; margin-right:8px;">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="search" class="form-control" placeholder="Cari supplier (Tekan Enter)..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
            <noscript><button type="submit" style="display:none;">Cari</button></noscript>
        </div>
    </form>
    <div class="filter-group">
        <button class="btn btn-primary" onclick="openModal('modalSupplier'); resetForm()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Supplier
        </button>
        
        <?php 
        $importType = 'suppliers';
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
                        <th>Nama Supplier</th>
                        <th>Contact Person</th>
                        <th>Telepon</th>
                        <th>Kota</th>
                        <th class="text-center" style="text-align: center;">Total PO</th>
                        <th>Hutang</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Belum ada supplier</td></tr>
                    <?php endif; ?>
                    <?php foreach ($suppliers as $sup): ?>
                        <tr>
                            <td class="text-bold"><?= htmlspecialchars($sup['name']) ?></td>
                            <td><?= htmlspecialchars($sup['contact_person'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($sup['phone'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($sup['city'] ?: '-') ?></td>
                            <td class="text-center"><span style="display:inline-block; background:#E5F5EF; color:#02897A; padding:4px 12px; border-radius:16px; font-weight:600; font-size:13px;"><?= $sup['total_po'] ?> kali</span></td>
                            <td>
                                <?php if ($sup['outstanding_payable'] > 0): ?>
                                    <span class="text-danger text-bold"><?= formatRupiah($sup['outstanding_payable']) ?></span>
                                <?php else: ?>
                                    <span class="text-success">Lunas</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <button class="btn btn-sm btn-outline btn-icon" title="Edit" onclick='editSupplier(<?= json_encode($sup) ?>)'>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Yakin hapus supplier ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $sup['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline btn-icon" title="Hapus" style="color:var(--danger);">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
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
    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div class="text-sm text-muted">
            Menampilkan <strong><?= count($suppliers) ?></strong> dari <strong><?= number_format($pagination['total_items']) ?></strong> supplier
        </div>
        <?= renderPagination($pagination, BASE_URL . '/admin/suppliers.php') ?>
    </div>
</div>

<div class="modal-overlay" id="modalSupplier">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Tambah Supplier</h3>
            <button class="modal-close" onclick="closeModal('modalSupplier')">&times;</button>
        </div>
        <form method="POST" id="supForm">
            <input type="hidden" name="action" id="supAction" value="add">
            <input type="hidden" name="id" id="supId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Supplier <span class="required">*</span></label>
                    <input type="text" name="name" id="supName" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" id="supCP" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="phone" id="supPhone" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="supEmail" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kota</label>
                        <input type="text" name="city" id="supCity" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Alamat</label>
                    <textarea name="address" id="supAddress" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalSupplier')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('modalTitle').textContent = 'Tambah Supplier';
    document.getElementById('supAction').value = 'add';
    document.getElementById('supForm').reset();
}
function editSupplier(s) {
    document.getElementById('modalTitle').textContent = 'Edit Supplier';
    document.getElementById('supAction').value = 'edit';
    document.getElementById('supId').value = s.id;
    document.getElementById('supName').value = s.name;
    document.getElementById('supCP').value = s.contact_person || '';
    document.getElementById('supPhone').value = s.phone || '';
    document.getElementById('supEmail').value = s.email || '';
    document.getElementById('supCity').value = s.city || '';
    document.getElementById('supAddress').value = s.address || '';
    openModal('modalSupplier');
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
