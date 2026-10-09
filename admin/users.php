<?php
/**
 * Kasir Ibtidaiyah - Manajemen Pengguna (Owner Only)
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Kelola Pengguna';
$breadcrumbs = [['label' => 'Kelola Pengguna']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $username = sanitize($_POST['username'] ?? '');
        $full_name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $role_id = (int)$_POST['role_id'];
        $password = $_POST['password'] ?? '';
        
        // Batasan Admin tidak boleh membuat role Pemilik
        if ($role_id == ROLE_PEMILIK && !hasRoleId(ROLE_PEMILIK)) {
            flashMessage('error', 'Anda tidak diizinkan membuat akun Pemilik.');
            redirect(BASE_URL . '/admin/users.php');
        }
        
        if (empty($username) || empty($full_name) || empty($password)) {
            flashMessage('error', 'Username, nama, dan password harus diisi.');
        } else {
            // Check unique username
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                flashMessage('error', 'Username sudah digunakan.');
            } else {
                $stmt = $db->prepare("INSERT INTO users (role_id, username, password_hash, full_name, email, phone) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$role_id, $username, hashPassword($password), $full_name, $email, $phone]);
                flashMessage('success', 'Pengguna berhasil ditambahkan.');
                logActivity('Tambah', 'User', 'User: ' . $username);
            }
        }
    }
    
    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $full_name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $role_id = (int)$_POST['role_id'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        
        // Batasan Admin tidak boleh mengedit akun Pemilik
        $stmt_check = $db->prepare("SELECT role_id FROM users WHERE id = ?");
        $stmt_check->execute([$id]);
        $target_role_id = $stmt_check->fetchColumn();
        
        if (($target_role_id == ROLE_PEMILIK || $role_id == ROLE_PEMILIK) && !hasRoleId(ROLE_PEMILIK)) {
            flashMessage('error', 'Anda tidak diizinkan mengedit akun Pemilik.');
            redirect(BASE_URL . '/admin/users.php');
        }
        
        $stmt = $db->prepare("UPDATE users SET full_name=?, email=?, phone=?, role_id=?, is_active=? WHERE id=?");
        $stmt->execute([$full_name, $email, $phone, $role_id, $is_active, $id]);
        
        if (!empty($password)) {
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([hashPassword($password), $id]);
        }
        
        flashMessage('success', 'Pengguna berhasil diperbarui.');
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id == $_SESSION['user_id']) {
            flashMessage('error', 'Tidak bisa menghapus akun sendiri.');
        } else {
            // Batasan Admin tidak boleh menonaktifkan akun Pemilik
            $stmt_check = $db->prepare("SELECT role_id FROM users WHERE id = ?");
            $stmt_check->execute([$id]);
            $target_role_id = $stmt_check->fetchColumn();
            
            if ($target_role_id == ROLE_PEMILIK && !hasRoleId(ROLE_PEMILIK)) {
                flashMessage('error', 'Anda tidak diizinkan menghapus akun Pemilik.');
                redirect(BASE_URL . '/admin/users.php');
            }
            
            try {
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                flashMessage('success', 'Pengguna berhasil dihapus permanen.');
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') {
                    $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
                    flashMessage('success', 'Pengguna sedang digunakan dalam transaksi, sehingga hanya dinonaktifkan.');
                } else {
                    flashMessage('error', 'Gagal menghapus pengguna.');
                }
            }
        }
    }
    
    redirect(BASE_URL . '/admin/users.php');
}

// Fetch users
$search = sanitize($_GET['search'] ?? '');
if ($search) {
    $stmt = $db->prepare("
        SELECT u.*, r.role_name, pt.name AS price_type_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        LEFT JOIN customers c ON c.user_id = u.id 
        LEFT JOIN price_types pt ON c.price_type_id = pt.id 
        WHERE u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? 
        ORDER BY u.role_id, u.full_name
    ");
    $stmt->execute(["%$search%", "%$search%", "%$search%"]);
} else {
    $stmt = $db->query("
        SELECT u.*, r.role_name, pt.name AS price_type_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        LEFT JOIN customers c ON c.user_id = u.id 
        LEFT JOIN price_types pt ON c.price_type_id = pt.id 
        ORDER BY u.role_id, u.full_name
    ");
}
$users = $stmt->fetchAll();

if (hasRoleId(ROLE_PEMILIK)) {
    $stmt = $db->query("SELECT * FROM roles ORDER BY id");
} else {
    $stmt = $db->query("SELECT * FROM roles WHERE id != " . ROLE_PEMILIK . " ORDER BY id");
}
$roles = $stmt->fetchAll();

include INCLUDES_PATH . '/header.php';
?>

<style>
    .users-toolbar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: nowrap;
    }
    .users-toolbar > form {
        flex: 1 1 auto;
        min-width: 0;
    }
    .users-toolbar .search-box {
        max-width: none;
        width: 100%;
        margin-right: 0;
    }
    .users-toolbar-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
        flex-shrink: 0;
    }
    @media (max-width: 768px) {
        .users-toolbar {
            flex-wrap: nowrap;
            align-items: center;
            gap: 8px;
        }
        .users-toolbar > form {
            flex: 1 1 auto;
        }
        .users-toolbar-actions {
            flex-shrink: 0;
        }
        .users-toolbar .btn,
        .users-toolbar .btn-icon,
        .users-toolbar button,
        .users-toolbar form {
            min-height: 40px;
        }
    }
</style>

<div class="toolbar users-toolbar">
    <form method="GET" style="display:flex; gap:8px; align-items:center; flex:1;">
        <div class="search-box" style="flex:1;">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="search" class="form-control" placeholder="Cari pengguna (Tekan Enter)..." value="<?= htmlspecialchars($search) ?>" onchange="this.form.submit()">
            <noscript><button type="submit" style="display:none;">Cari</button></noscript>
        </div>
    </form>
    <div class="users-toolbar-actions">
        <?php 
            $importType = 'users';
            $hasImport = true;
            $hasExport = true;
            $hasTemplate = true;
            include INCLUDES_PATH . '/import_export_toolbar.php'; 
        ?>
        <button class="btn btn-primary" onclick="openModal('modalUser'); resetForm()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Pengguna
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Tipe Harga</th>
                        <th>Email</th>
                        <th>Login Terakhir</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="text-bold"><?= htmlspecialchars($u['full_name']) ?></td>
                            <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                            <td>
                                <?php
                                $roleBadges = [
                                    'Pemilik' => 'badge-gold',
                                    'Admin' => 'badge-info',
                                    'Kasir' => 'badge-primary',
                                    'Pelanggan' => 'badge-gray',
                                ];
                                ?>
                                <span class="badge <?= $roleBadges[$u['role_name']] ?? 'badge-gray' ?>"><?= $u['role_name'] ?></span>
                            </td>
                            <td><?= $u['price_type_name'] ? htmlspecialchars($u['price_type_name']) : '-' ?></td>
                            <td class="text-sm"><?= htmlspecialchars($u['email'] ?: '-') ?></td>
                            <td class="text-sm text-muted"><?= $u['last_login'] ? timeAgo($u['last_login']) : 'Belum pernah' ?></td>
                            <td>
                                <?php if ($u['is_active']): ?>
                                    <span class="badge badge-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($u['role_name'] !== 'Pemilik' || hasRoleId(ROLE_PEMILIK)): ?>
                                    <button class="btn btn-sm btn-outline btn-icon" title="Edit" onclick='editUser(<?= json_encode($u) ?>)'>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($u['id'] != $_SESSION['user_id'] && ($u['role_name'] !== 'Pemilik' || hasRoleId(ROLE_PEMILIK))): ?>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Hapus permanen pengguna ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline btn-icon" title="Hapus" style="color:var(--danger);">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalUser">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Tambah Pengguna</h3>
            <button class="modal-close" onclick="closeModal('modalUser')">&times;</button>
        </div>
        <form method="POST" id="userForm">
            <input type="hidden" name="action" id="userAction" value="add">
            <input type="hidden" name="id" id="userId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username <span class="required">*</span></label>
                        <input type="text" name="username" id="userUsername" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role <span class="required">*</span></label>
                        <select name="role_id" id="userRole" class="form-control" required>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= $r['role_name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Lengkap <span class="required">*</span></label>
                    <input type="text" name="full_name" id="userFullName" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="userEmail" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="phone" id="userPhone" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Password <span class="required" id="pwRequired">*</span></label>
                    <input type="password" name="password" id="userPassword" class="form-control">
                    <span class="form-hint" id="pwHint">Minimal 6 karakter</span>
                </div>
                <div class="form-group" id="activeField" style="display:none;">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" id="userActive" checked>
                        <span>Akun Aktif</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalUser')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('modalTitle').textContent = 'Tambah Pengguna';
    document.getElementById('userAction').value = 'add';
    document.getElementById('userForm').reset();
    document.getElementById('userUsername').readOnly = false;
    document.getElementById('userPassword').required = true;
    document.getElementById('pwRequired').style.display = '';
    document.getElementById('pwHint').textContent = 'Minimal 6 karakter';
    document.getElementById('activeField').style.display = 'none';
}
function editUser(u) {
    document.getElementById('modalTitle').textContent = 'Edit Pengguna';
    document.getElementById('userAction').value = 'edit';
    document.getElementById('userId').value = u.id;
    document.getElementById('userUsername').value = u.username;
    document.getElementById('userUsername').readOnly = true;
    document.getElementById('userFullName').value = u.full_name;
    document.getElementById('userRole').value = u.role_id;
    document.getElementById('userEmail').value = u.email || '';
    document.getElementById('userPhone').value = u.phone || '';
    document.getElementById('userPassword').value = '';
    document.getElementById('userPassword').required = false;
    document.getElementById('pwRequired').style.display = 'none';
    document.getElementById('pwHint').textContent = 'Kosongkan jika tidak ingin mengubah password';
    document.getElementById('activeField').style.display = 'block';
    document.getElementById('userActive').checked = u.is_active == 1;
    openModal('modalUser');
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
