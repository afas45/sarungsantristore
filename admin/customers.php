<?php
/**
 * Kasir Ibtidaiyah - Manajemen Pelanggan
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Pelanggan';
$breadcrumbs = [['label' => 'Pelanggan']];

// =============================================
// HANDLE ACTIONS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $name = sanitize($_POST['name'] ?? '');
        $phone = formatPhoneNumber(sanitize($_POST['phone'] ?? ''));
        $email = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        $price_type_id = (int)($_POST['price_type_id'] ?? 1);
        $password = $_POST['password'] ?? '';
        
        if (empty($name)) {
            flashMessage('error', 'Nama pelanggan harus diisi.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))" . ($action === 'edit' ? " AND id != ?" : ""));
            $params = $action === 'edit' ? [$name, $id] : [$name];
            $stmt->execute($params);
            
            if ($stmt->fetchColumn() > 0) {
                flashMessage('error', 'Gagal: Nama pelanggan sudah terdaftar.');
            } elseif ($action === 'add') {
                $hashed_pw = !empty($password) ? hashPassword($password) : null;
                $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address, city, price_type_id, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $phone, $email, $address, $city, $price_type_id, $hashed_pw]);
                $newCustomerId = $db->lastInsertId();
                
                $isUserCreated= false;
                $newUsername = '';
                
                $roleId = $db->query("SELECT id FROM roles WHERE role_name = 'Pelanggan'")->fetchColumn();
                if ($roleId) {
                    $baseUsername = strtolower(explode(' ', trim($name))[0]);
                    $newUsername = $baseUsername;
                    $counter = 1;
                    
                    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmtCheck->execute([$newUsername]);
                    while ($stmtCheck->fetchColumn() > 0) {
                        $newUsername = $baseUsername . $counter;
                        $counter++;
                        $stmtCheck->execute([$newUsername]);
                    }
                    
                    $defaultPw = password_hash('123456', PASSWORD_DEFAULT);
                    $stmtUser = $db->prepare("INSERT INTO users (role_id, username, password_hash, full_name, email, phone, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                    $stmtUser->execute([$roleId, $newUsername, $defaultPw, $name, $email, $phone]);
                    
                    $newUserId = $db->lastInsertId();
                    $db->prepare("UPDATE customers SET user_id = ? WHERE id = ?")->execute([$newUserId, $newCustomerId]);
                    
                    $isUserCreated = true;
                }
                
                if ($isUserCreated) {
                    flashMessage('success', "Pelanggan berhasil ditambahkan. Akun pengguna otomatis dibuat (Username: $newUsername, Pass: 123456).");
                } else {
                    flashMessage('success', 'Pelanggan berhasil ditambahkan.');
                }
                
                logActivity('Tambah', 'Pelanggan', 'Pelanggan: ' . $name);
            } else {
                $stmt = $db->prepare("UPDATE customers SET name=?, phone=?, email=?, address=?, city=?, price_type_id=? WHERE id=?");
                $stmt->execute([$name, $phone, $email, $address, $city, $price_type_id, $id]);
                
                if (!empty($password)) {
                    $stmt_pw = $db->prepare("UPDATE customers SET password = ? WHERE id = ?");
                    $stmt_pw->execute([hashPassword($password), $id]);
                }
                
                flashMessage('success', 'Pelanggan berhasil diperbarui.');
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM sales WHERE customer_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                flashMessage('error', 'Pelanggan tidak bisa dihapus karena memiliki riwayat transaksi penjualan.');
            } else {
                $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
                flashMessage('success', 'Pelanggan berhasil dihapus.');
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                flashMessage('error', 'Pelanggan tidak bisa dihapus karena datanya sedang digunakan pada transaksi terkait.');
            } else {
                flashMessage('error', 'Gagal menghapus pelanggan: ' . $e->getMessage());
            }
        }
    }
    
    redirect(BASE_URL . '/admin/customers.php');
}

// =============================================
// FETCH DATA
// =============================================
$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$where = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $db->prepare("SELECT COUNT(*) FROM customers c $where");
$stmt->execute($params);
$pagination = getPagination($stmt->fetchColumn(), $page);

$stmt = $db->prepare("
    SELECT c.*, pt.name as price_type_name,
    COALESCE((SELECT SUM(r.total_debt - r.paid_amount) FROM receivables r WHERE r.customer_id = c.id AND r.status != 'Paid'), 0) as outstanding_debt,
    (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) as total_transactions
    FROM customers c 
    LEFT JOIN price_types pt ON c.price_type_id = pt.id
    $where 
    ORDER BY c.name 
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$customers = $stmt->fetchAll();

$priceTypes = $db->query("SELECT * FROM price_types ORDER BY id")->fetchAll();

include INCLUDES_PATH . '/header.php';
?>

<div class="toolbar">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex:1;">
        <div class="search-box" style="flex:1; max-width:400px; margin-right:8px;">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="search" class="form-control" placeholder="Cari pelanggan (Tekan Enter)..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
            <noscript><button type="submit" style="display:none;">Cari</button></noscript>
        </div>
    </form>
    <div class="filter-group">
        <button class="btn btn-primary" onclick="openModal('modalCustomer'); resetForm()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Tambah Pelanggan
        </button>
        
        <?php 
        $importType = 'customers';
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
                        <th>Nama</th>
                        <th>Telepon</th>
                        <th>Tipe Harga</th>
                        <th>Kota</th>
                        <th>Sisa Piutang</th>
                        <th style="text-align:center;">Transaksi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Belum ada pelanggan</td></tr>
                    <?php endif; ?>
                    <?php foreach ($customers as $cust): ?>
                        <tr>
                            <td class="text-bold"><?= htmlspecialchars($cust['name']) ?></td>
                            <td><?= htmlspecialchars($cust['phone'] ?: '-') ?></td>
                            <td>
                                <span class="badge badge-info"><?= htmlspecialchars($cust['price_type_name'] ?: 'Umum') ?></span>
                            </td>
                            <td><?= htmlspecialchars($cust['city'] ?: '-') ?></td>
                            <td>
                                <?php if ($cust['outstanding_debt'] > 0): ?>
                                    <span class="text-danger text-bold"><?= formatRupiah($cust['outstanding_debt']) ?></span>
                                <?php else: ?>
                                    <span class="text-success">Lunas</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <a href="#" onclick="viewTransactions(<?= $cust['id'] ?>, '<?= htmlspecialchars(addslashes($cust['name'])) ?>'); return false;" class="badge badge-success" style="text-decoration:none; cursor:pointer;">
                                    <?= $cust['total_transactions'] ?> kali
                                </a>
                            </td>
                            <td>
                                <div class="actions">
                                    <button class="btn btn-sm btn-outline btn-icon" title="Edit" onclick='editCustomer(<?= json_encode($cust) ?>)'>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Yakin hapus pelanggan ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $cust['id'] ?>">
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
    <div class="card-footer"><?= renderPagination($pagination, BASE_URL . '/admin/customers.php') ?></div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalCustomer">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Tambah Pelanggan</h3>
            <button class="modal-close" onclick="closeModal('modalCustomer')">&times;</button>
        </div>
        <form method="POST" id="custForm">
            <input type="hidden" name="action" id="custAction" value="add">
            <input type="hidden" name="id" id="custId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nama <span class="required">*</span></label>
                        <input type="text" name="name" id="custName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipe Harga <span class="required">*</span></label>
                        <select name="price_type_id" id="custPriceTypeId" class="form-control" required>
                            <?php foreach ($priceTypes as $pt): ?>
                                <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="phone" id="custPhone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kota</label>
                        <input type="text" name="city" id="custCity" class="form-control" list="cityList" autocomplete="off" placeholder="Mulai ketik nama kota...">
                        <datalist id="cityList">
                            <?php 
                            $cities = $db->query("SELECT DISTINCT city FROM customers WHERE city IS NOT NULL AND city != '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($cities as $c) {
                                echo '<option value="' . htmlspecialchars($c) . '">';
                            }
                            ?>
                        </datalist>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Alamat Lengkap</label>
                    <textarea name="address" id="custAddress" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalCustomer')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('modalTitle').textContent = 'Tambah Pelanggan';
    document.getElementById('custAction').value = 'add';
    document.getElementById('custForm').reset();
}

function editCustomer(c) {
    document.getElementById('modalTitle').textContent = 'Edit Pelanggan';
    document.getElementById('custAction').value = 'edit';
    document.getElementById('custId').value = c.id;
    document.getElementById('custName').value = c.name;
    document.getElementById('custPriceTypeId').value = c.price_type_id || 1;
    document.getElementById('custPhone').value = c.phone || '';
    document.getElementById('custCity').value = c.city || '';
    document.getElementById('custAddress').value = c.address || '';
    openModal('modalCustomer');
}

async function viewTransactions(customerId, customerName) {
    document.getElementById('transModalTitle').textContent = 'Transaksi: ' + customerName;
    const tbody = document.getElementById('transTableBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center">Memuat...</td></tr>';
    openModal('modalTransactions');
    
    try {
        const res = await fetch(`<?= BASE_URL ?>/api/customer_transactions.php?customer_id=${customerId}`);
        const data = await res.json();
        
        if (data.success) {
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Belum ada transaksi.</td></tr>';
            } else {
                tbody.innerHTML = data.data.map(t => {
                    let badge = '';
                    if (t.status === 'Paid') badge = '<span class="badge badge-success">Lunas</span>';
                    else if (t.status === 'Debt') badge = '<span class="badge badge-warning">Kasbon</span>';
                    else badge = `<span class="badge badge-gray">${t.status}</span>`;
                    
                    return `
                    <tr>
                        <td>${t.date}</td>
                        <td>${t.invoice_number}</td>
                        <td>${t.total}</td>
                        <td>${badge}</td>
                        <td>
                            <div class="actions" style="gap:4px; display:flex;">
                                <a href="javascript:void(0)" onclick="previewInvoice(${t.id})" class="btn btn-sm btn-outline btn-icon" title="Preview">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                                <a href="<?= BASE_URL ?>/admin/print_invoice.php?id=${t.id}" target="_blank" class="btn btn-sm btn-outline btn-icon" title="Print">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                </a>
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            }
        } else {
            tbody.innerHTML = `<tr><td colspan="5" class="text-danger">${data.message}</td></tr>`;
        }
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-danger">Gagal memuat data.</td></tr>';
    }
}

function previewInvoice(id) {
    const width = 800;
    const height = 600;
    const left = (window.innerWidth - width) / 2;
    const top = (window.innerHeight - height) / 2;
    window.open(`<?= BASE_URL ?>/admin/print_invoice.php?id=${id}&preview=1`, 'Preview Invoice', `width=${width},height=${height},left=${left},top=${top}`);
}
</script>

<!-- Modal Transactions -->
<div class="modal-overlay" id="modalTransactions">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title" id="transModalTitle">Transaksi Pelanggan</h3>
            <button class="modal-close" onclick="closeModal('modalTransactions')">&times;</button>
        </div>
        <div class="modal-body" style="padding:0; max-height:400px; overflow-y:auto;">
            <table class="table">
                <thead style="position:sticky; top:0; background:white; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                    <tr>
                        <th>Tanggal</th>
                        <th>Invoice</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th style="width:70px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="transTableBody">
                </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modalTransactions')">Tutup</button>
        </div>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
